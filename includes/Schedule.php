<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Dispatcher.php';

class Schedule {

    /**
     * Assign a dispatcher to a job (desk + shift)
     */
    public static function assignJob($dispatcherId, $deskId, $shift, $assignmentType = 'regular', $startDate = null) {
        if ($startDate === null) {
            $startDate = date('Y-m-d');
        }

        // Check if dispatcher is qualified
        if (!Dispatcher::isQualified($dispatcherId, $deskId)) {
            throw new Exception("Dispatcher is not qualified for this desk");
        }

        // End any current assignment for this dispatcher
        $current = Dispatcher::getCurrentAssignment($dispatcherId);
        if ($current) {
            self::endJobAssignment($current['id'], date('Y-m-d', strtotime($startDate . ' -1 day')));
        }

        // End any current assignment for this desk+shift combination
        $sql = "UPDATE job_assignments
                SET end_date = ?
                WHERE desk_id = ? AND shift = ? AND end_date IS NULL";
        dbExecute($sql, [date('Y-m-d', strtotime($startDate . ' -1 day')), $deskId, $shift]);

        // Create new assignment
        $sql = "INSERT INTO job_assignments (dispatcher_id, desk_id, shift, assignment_type, start_date)
                VALUES (?, ?, ?, ?, ?)";
        $id = dbInsert($sql, [$dispatcherId, $deskId, $shift, $assignmentType, $startDate]);

        // Update dispatcher classification if needed
        if ($assignmentType !== 'regular') {
            return $id;
        }

        // If assigning to regular job, make them a job holder
        $dispatcher = Dispatcher::getById($dispatcherId);
        if ($dispatcher['classification'] === 'extra_board') {
            $sql = "UPDATE dispatchers SET classification = 'job_holder' WHERE id = ?";
            dbExecute($sql, [$dispatcherId]);
        }

        return $id;
    }

    /**
     * End a job assignment
     */
    public static function endJobAssignment($assignmentId, $endDate = null) {
        if ($endDate === null) {
            $endDate = date('Y-m-d');
        }

        $sql = "UPDATE job_assignments SET end_date = ? WHERE id = ?";
        return dbExecute($sql, [$endDate, $assignmentId]);
    }

    /**
     * Set relief schedule for a desk
     */
    public static function setReliefSchedule($deskId, $reliefDispatcherId, $dayOfWeek, $shift) {
        // Validate day of week (0-6)
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new Exception("Invalid day of week");
        }

        $sql = "INSERT INTO relief_schedules (desk_id, relief_dispatcher_id, day_of_week, shift)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE relief_dispatcher_id = ?, active = 1";
        return dbExecute($sql, [$deskId, $reliefDispatcherId, $dayOfWeek, $shift, $reliefDispatcherId]);
    }

    /**
     * Generate standard relief schedule for a desk
     * Standard: 2 first shifts, 2 second shifts, 1 third shift (typically weekends)
     */
    public static function generateStandardReliefSchedule($deskId, $reliefDispatcherId) {
        dbBeginTransaction();
        try {
            // Clear existing relief schedule
            $sql = "DELETE FROM relief_schedules WHERE desk_id = ?";
            dbExecute($sql, [$deskId]);

            // Saturday and Sunday first shift
            self::setReliefSchedule($deskId, $reliefDispatcherId, 6, 'first');  // Saturday
            self::setReliefSchedule($deskId, $reliefDispatcherId, 0, 'first');  // Sunday

            // Saturday and Sunday second shift
            self::setReliefSchedule($deskId, $reliefDispatcherId, 6, 'second'); // Saturday
            self::setReliefSchedule($deskId, $reliefDispatcherId, 0, 'second'); // Sunday

            // Saturday third shift only (going into Sunday morning)
            self::setReliefSchedule($deskId, $reliefDispatcherId, 6, 'third');  // Saturday night

            dbCommit();
            return true;
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Set ATW rotation entry for a desk
     */
    public static function setAtwRotation($deskId, $dayOfWeek, $rotationOrder, $atwDispatcherId = null) {
        // Validate day of week (0-6) and rotation order (1-5 for Mon-Fri)
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new Exception("Invalid day of week");
        }
        if ($rotationOrder < 1 || $rotationOrder > 5) {
            throw new Exception("Invalid rotation order (must be 1-5)");
        }

        $sql = "INSERT INTO atw_rotation (desk_id, day_of_week, rotation_order, atw_dispatcher_id)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE rotation_order = ?, atw_dispatcher_id = ?, active = 1";
        return dbExecute($sql, [$deskId, $dayOfWeek, $rotationOrder, $atwDispatcherId, $rotationOrder, $atwDispatcherId]);
    }

    /**
     * Generate ATW rotation for all desks
     * Distributes the 7th third shift across the week
     */
    public static function generateAtwRotation($atwDispatcherId = null) {
        // Get all active desks
        require_once __DIR__ . '/Desk.php';
        $desks = Desk::getAll();

        dbBeginTransaction();
        try {
            // For each desk, we need to assign one third shift day
            // We'll use a round-robin approach across Monday-Friday
            $rotationOrder = 1;
            $dayOfWeek = 1; // Start with Monday

            foreach ($desks as $desk) {
                self::setAtwRotation($desk['id'], $dayOfWeek, $rotationOrder, $atwDispatcherId);

                // Move to next day, cycling through Mon-Fri
                $dayOfWeek++;
                if ($dayOfWeek > 5) {
                    $dayOfWeek = 1; // Reset to Monday
                }
                $rotationOrder++;
            }

            dbCommit();
            return true;
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Get the schedule for a specific date
     */
    public static function getScheduleForDate($date) {
        $dayOfWeek = date('w', strtotime($date)); // 0 (Sun) to 6 (Sat)

        $sql = "SELECT
                    d.id as desk_id,
                    d.name as desk_name,
                    division.name as division_name,
                    'first' as shift,
                    CASE
                        WHEN rs.id IS NOT NULL THEN rs.relief_dispatcher_id
                        WHEN rest_first.id IS NOT NULL THEN NULL
                        ELSE ja_first.dispatcher_id
                    END as assigned_dispatcher_id,
                    CASE
                        WHEN rs.id IS NOT NULL THEN CONCAT(disp_relief.first_name, ' ', disp_relief.last_name)
                        WHEN rest_first.id IS NOT NULL THEN NULL
                        ELSE CONCAT(disp_first.first_name, ' ', disp_first.last_name)
                    END as dispatcher_name,
                    CASE
                        WHEN rs.id IS NOT NULL THEN 'relief'
                        WHEN rest_first.id IS NOT NULL THEN 'vacancy'
                        ELSE 'regular'
                    END as assignment_type
                FROM desks d
                JOIN divisions division ON d.division_id = division.id
                LEFT JOIN job_assignments ja_first ON ja_first.desk_id = d.id
                    AND ja_first.shift = 'first'
                    AND ja_first.assignment_type = 'regular'
                    AND ja_first.end_date IS NULL
                LEFT JOIN dispatchers disp_first ON ja_first.dispatcher_id = disp_first.id
                LEFT JOIN job_rest_days rest_first ON rest_first.job_assignment_id = ja_first.id
                    AND rest_first.day_of_week = ?
                LEFT JOIN relief_schedules rs ON rs.desk_id = d.id
                    AND rs.shift = 'first'
                    AND rs.day_of_week = ?
                    AND rs.active = 1
                LEFT JOIN dispatchers disp_relief ON rs.relief_dispatcher_id = disp_relief.id
                WHERE d.active = 1

                UNION ALL

                SELECT
                    d.id as desk_id,
                    d.name as desk_name,
                    division.name as division_name,
                    'second' as shift,
                    CASE
                        WHEN rs.id IS NOT NULL THEN rs.relief_dispatcher_id
                        WHEN rest_second.id IS NOT NULL THEN NULL
                        ELSE ja_second.dispatcher_id
                    END as assigned_dispatcher_id,
                    CASE
                        WHEN rs.id IS NOT NULL THEN CONCAT(disp_relief.first_name, ' ', disp_relief.last_name)
                        WHEN rest_second.id IS NOT NULL THEN NULL
                        ELSE CONCAT(disp_second.first_name, ' ', disp_second.last_name)
                    END as dispatcher_name,
                    CASE
                        WHEN rs.id IS NOT NULL THEN 'relief'
                        WHEN rest_second.id IS NOT NULL THEN 'vacancy'
                        ELSE 'regular'
                    END as assignment_type
                FROM desks d
                JOIN divisions division ON d.division_id = division.id
                LEFT JOIN job_assignments ja_second ON ja_second.desk_id = d.id
                    AND ja_second.shift = 'second'
                    AND ja_second.assignment_type = 'regular'
                    AND ja_second.end_date IS NULL
                LEFT JOIN dispatchers disp_second ON ja_second.dispatcher_id = disp_second.id
                LEFT JOIN job_rest_days rest_second ON rest_second.job_assignment_id = ja_second.id
                    AND rest_second.day_of_week = ?
                LEFT JOIN relief_schedules rs ON rs.desk_id = d.id
                    AND rs.shift = 'second'
                    AND rs.day_of_week = ?
                    AND rs.active = 1
                LEFT JOIN dispatchers disp_relief ON rs.relief_dispatcher_id = disp_relief.id
                WHERE d.active = 1

                UNION ALL

                SELECT
                    d.id as desk_id,
                    d.name as desk_name,
                    division.name as division_name,
                    'third' as shift,
                    CASE
                        WHEN ar.id IS NOT NULL THEN ar.atw_dispatcher_id
                        WHEN rs.id IS NOT NULL THEN rs.relief_dispatcher_id
                        WHEN rest_third.id IS NOT NULL THEN NULL
                        ELSE ja_third.dispatcher_id
                    END as assigned_dispatcher_id,
                    CASE
                        WHEN ar.id IS NOT NULL THEN CONCAT(disp_atw.first_name, ' ', disp_atw.last_name)
                        WHEN rs.id IS NOT NULL THEN CONCAT(disp_relief.first_name, ' ', disp_relief.last_name)
                        WHEN rest_third.id IS NOT NULL THEN NULL
                        ELSE CONCAT(disp_third.first_name, ' ', disp_third.last_name)
                    END as dispatcher_name,
                    CASE
                        WHEN ar.id IS NOT NULL THEN 'atw'
                        WHEN rs.id IS NOT NULL THEN 'relief'
                        WHEN rest_third.id IS NOT NULL THEN 'vacancy'
                        ELSE 'regular'
                    END as assignment_type
                FROM desks d
                JOIN divisions division ON d.division_id = division.id
                LEFT JOIN job_assignments ja_third ON ja_third.desk_id = d.id
                    AND ja_third.shift = 'third'
                    AND ja_third.assignment_type = 'regular'
                    AND ja_third.end_date IS NULL
                LEFT JOIN dispatchers disp_third ON ja_third.dispatcher_id = disp_third.id
                LEFT JOIN job_rest_days rest_third ON rest_third.job_assignment_id = ja_third.id
                    AND rest_third.day_of_week = ?
                LEFT JOIN relief_schedules rs ON rs.desk_id = d.id
                    AND rs.shift = 'third'
                    AND rs.day_of_week = ?
                    AND rs.active = 1
                LEFT JOIN dispatchers disp_relief ON rs.relief_dispatcher_id = disp_relief.id
                LEFT JOIN atw_rotation ar ON ar.desk_id = d.id
                    AND ar.day_of_week = ?
                    AND ar.active = 1
                LEFT JOIN dispatchers disp_atw ON ar.atw_dispatcher_id = disp_atw.id
                WHERE d.active = 1

                ORDER BY division_name, desk_name, FIELD(shift, 'first', 'second', 'third')";

        return dbQueryAll($sql, [$dayOfWeek, $dayOfWeek, $dayOfWeek, $dayOfWeek, $dayOfWeek, $dayOfWeek, $dayOfWeek]);
    }

    /**
     * Get schedule for a date range
     */
    public static function getScheduleForDateRange($startDate, $endDate) {
        $schedules = [];
        $currentDate = $startDate;

        while (strtotime($currentDate) <= strtotime($endDate)) {
            $schedules[$currentDate] = self::getScheduleForDate($currentDate);
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }

        return $schedules;
    }

    /**
     * Get dispatcher's schedule for a date range
     */
    public static function getDispatcherSchedule($dispatcherId, $startDate, $endDate) {
        $sql = "SELECT
                    al.work_date,
                    al.shift,
                    d.name as desk_name,
                    division.name as division_name,
                    al.assignment_source,
                    al.pay_type,
                    al.actual_start_time,
                    al.actual_end_time
                FROM assignment_log al
                JOIN desks d ON al.desk_id = d.id
                JOIN divisions division ON d.division_id = division.id
                WHERE al.dispatcher_id = ?
                    AND al.work_date BETWEEN ? AND ?
                ORDER BY al.work_date, al.actual_start_time";

        return dbQueryAll($sql, [$dispatcherId, $startDate, $endDate]);
    }
}
