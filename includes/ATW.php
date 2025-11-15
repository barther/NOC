<?php
/**
 * ATW (Around-the-World) Management Class
 *
 * Handles ATW job definitions and their rotating desk schedules
 */

class ATW {
    /**
     * Initialize ATW tables if they don't exist
     */
    public static function initializeTables() {
        $pdo = getDbConnection();

        // Create atw_jobs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS atw_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_atw_name (name, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create atw_schedules table
        $pdo->exec("CREATE TABLE IF NOT EXISTS atw_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            atw_job_id INT NOT NULL,
            day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
            desk_id INT NOT NULL,
            shift VARCHAR(20) DEFAULT 'third' COMMENT 'Always third for ATW',
            active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (atw_job_id) REFERENCES atw_jobs(id) ON DELETE CASCADE,
            FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
            UNIQUE KEY unique_atw_day (atw_job_id, day_of_week, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Add atw_job_id to job_assignments if it doesn't exist
        try {
            $pdo->exec("ALTER TABLE job_assignments
                       ADD COLUMN atw_job_id INT NULL AFTER desk_id,
                       ADD FOREIGN KEY (atw_job_id) REFERENCES atw_jobs(id) ON DELETE SET NULL");
        } catch (PDOException $e) {
            // Column might already exist, that's okay
        }

        // Create indexes
        try {
            $pdo->exec("CREATE INDEX idx_atw_schedules_lookup ON atw_schedules(atw_job_id, day_of_week, active)");
        } catch (PDOException $e) {
            // Index might already exist
        }

        try {
            $pdo->exec("CREATE INDEX idx_job_assignments_atw ON job_assignments(atw_job_id, assignment_type)");
        } catch (PDOException $e) {
            // Index might already exist
        }
    }

    /**
     * Get all ATW jobs
     */
    public static function getAll() {
        $sql = "SELECT * FROM atw_jobs WHERE active = 1 ORDER BY name";
        return dbQueryAll($sql);
    }

    /**
     * Get ATW job by ID
     */
    public static function getById($id) {
        $sql = "SELECT * FROM atw_jobs WHERE id = ? AND active = 1";
        return dbQueryOne($sql, [$id]);
    }

    /**
     * Create new ATW job
     */
    public static function create($name, $description = '') {
        $sql = "INSERT INTO atw_jobs (name, description) VALUES (?, ?)";
        return dbInsert($sql, [$name, $description]);
    }

    /**
     * Update ATW job
     */
    public static function update($id, $name, $description = '') {
        $sql = "UPDATE atw_jobs SET name = ?, description = ? WHERE id = ?";
        return dbExecute($sql, [$name, $description, $id]);
    }

    /**
     * Delete ATW job (soft delete)
     */
    public static function delete($id) {
        $sql = "UPDATE atw_jobs SET active = 0 WHERE id = ?";
        return dbExecute($sql, [$id]);
    }

    /**
     * Get ATW schedule for a job
     */
    public static function getSchedule($atwJobId) {
        $sql = "SELECT
                    s.*,
                    d.name as desk_name,
                    d.code as desk_code
                FROM atw_schedules s
                JOIN desks d ON s.desk_id = d.id
                WHERE s.atw_job_id = ? AND s.active = 1
                ORDER BY s.day_of_week";
        return dbQueryAll($sql, [$atwJobId]);
    }

    /**
     * Set ATW schedule for a specific day
     */
    public static function setSchedule($atwJobId, $dayOfWeek, $deskId, $shift = 'third') {
        // First, deactivate any existing schedule for this day
        $sql = "UPDATE atw_schedules
                SET active = 0
                WHERE atw_job_id = ? AND day_of_week = ?";
        dbExecute($sql, [$atwJobId, $dayOfWeek]);

        // Insert new schedule
        $sql = "INSERT INTO atw_schedules (atw_job_id, day_of_week, desk_id, shift)
                VALUES (?, ?, ?, ?)";
        return dbInsert($sql, [$atwJobId, $dayOfWeek, $deskId, $shift]);
    }

    /**
     * Clear all schedules for an ATW job
     */
    public static function clearSchedule($atwJobId) {
        $sql = "UPDATE atw_schedules SET active = 0 WHERE atw_job_id = ?";
        return dbExecute($sql, [$atwJobId]);
    }

    /**
     * Get dispatcher assigned to an ATW job
     */
    public static function getAssignedDispatcher($atwJobId) {
        $sql = "SELECT
                    ja.*,
                    d.employee_number,
                    d.first_name,
                    d.last_name
                FROM job_assignments ja
                JOIN dispatchers d ON ja.dispatcher_id = d.id
                WHERE ja.atw_job_id = ?
                    AND ja.assignment_type = 'atw'
                    AND ja.end_date IS NULL
                    AND d.active = 1";
        return dbQueryOne($sql, [$atwJobId]);
    }

    /**
     * Assign dispatcher to ATW job
     */
    public static function assignDispatcher($atwJobId, $dispatcherId, $startDate = null) {
        if (!$startDate) {
            $startDate = date('Y-m-d');
        }

        // End any existing ATW assignment for this job
        $sql = "UPDATE job_assignments
                SET end_date = ?
                WHERE atw_job_id = ? AND end_date IS NULL";
        dbExecute($sql, [date('Y-m-d', strtotime('-1 day', strtotime($startDate))), $atwJobId]);

        // End any existing ATW assignment for this dispatcher
        $sql = "UPDATE job_assignments
                SET end_date = ?
                WHERE dispatcher_id = ?
                    AND assignment_type = 'atw'
                    AND end_date IS NULL";
        dbExecute($sql, [date('Y-m-d', strtotime('-1 day', strtotime($startDate))), $dispatcherId]);

        // Create new assignment
        $sql = "INSERT INTO job_assignments
                (dispatcher_id, desk_id, atw_job_id, shift, assignment_type, start_date)
                VALUES (?, NULL, ?, 'third', 'atw', ?)";
        return dbInsert($sql, [$dispatcherId, $atwJobId, $startDate]);
    }

    /**
     * Get ATW coverage for a specific desk/day
     */
    public static function getCoverageForDesk($deskId, $dayOfWeek, $shift = 'third') {
        $sql = "SELECT
                    s.*,
                    j.name as atw_job_name,
                    ja.dispatcher_id,
                    d.employee_number,
                    d.first_name,
                    d.last_name
                FROM atw_schedules s
                JOIN atw_jobs j ON s.atw_job_id = j.id
                LEFT JOIN job_assignments ja ON s.atw_job_id = ja.atw_job_id
                    AND ja.assignment_type = 'atw'
                    AND ja.end_date IS NULL
                LEFT JOIN dispatchers d ON ja.dispatcher_id = d.id AND d.active = 1
                WHERE s.desk_id = ?
                    AND s.day_of_week = ?
                    AND s.shift = ?
                    AND s.active = 1
                    AND j.active = 1";
        return dbQueryOne($sql, [$deskId, $dayOfWeek, $shift]);
    }

    /**
     * Get all ATW coverage for the week (for schedule display)
     */
    public static function getAllCoverage() {
        $sql = "SELECT
                    s.*,
                    j.name as atw_job_name,
                    d.name as desk_name,
                    d.code as desk_code,
                    ja.dispatcher_id,
                    disp.employee_number,
                    disp.first_name,
                    disp.last_name
                FROM atw_schedules s
                JOIN atw_jobs j ON s.atw_job_id = j.id
                JOIN desks d ON s.desk_id = d.id
                LEFT JOIN job_assignments ja ON s.atw_job_id = ja.atw_job_id
                    AND ja.assignment_type = 'atw'
                    AND ja.end_date IS NULL
                LEFT JOIN dispatchers disp ON ja.dispatcher_id = disp.id AND disp.active = 1
                WHERE s.active = 1 AND j.active = 1
                ORDER BY s.day_of_week, d.name";
        return dbQueryAll($sql);
    }
}
