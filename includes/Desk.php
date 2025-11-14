<?php
require_once __DIR__ . '/../config/database.php';

class Desk {

    /**
     * Get all desks
     */
    public static function getAll($activeOnly = true) {
        $sql = "SELECT d.*, div.name as division_name
                FROM desks d
                JOIN divisions div ON d.division_id = div.id";
        if ($activeOnly) {
            $sql .= " WHERE d.active = 1";
        }
        $sql .= " ORDER BY div.name, d.name";
        return dbQueryAll($sql);
    }

    /**
     * Get desk by ID
     */
    public static function getById($id) {
        $sql = "SELECT d.*, div.name as division_name
                FROM desks d
                JOIN divisions div ON d.division_id = div.id
                WHERE d.id = ?";
        return dbQueryOne($sql, [$id]);
    }

    /**
     * Create a new desk
     */
    public static function create($divisionId, $name, $code, $description = '') {
        $sql = "INSERT INTO desks (division_id, name, code, description) VALUES (?, ?, ?, ?)";
        return dbInsert($sql, [$divisionId, $name, $code, $description]);
    }

    /**
     * Update desk
     */
    public static function update($id, $divisionId, $name, $code, $description = '', $active = true) {
        $sql = "UPDATE desks SET division_id = ?, name = ?, code = ?, description = ?, active = ? WHERE id = ?";
        return dbExecute($sql, [$divisionId, $name, $code, $description, $active ? 1 : 0, $id]);
    }

    /**
     * Delete desk (soft delete - set inactive)
     */
    public static function delete($id) {
        $sql = "UPDATE desks SET active = 0 WHERE id = ?";
        return dbExecute($sql, [$id]);
    }

    /**
     * Get current job assignments for a desk
     */
    public static function getJobAssignments($deskId) {
        $sql = "SELECT ja.*,
                       d.employee_number, d.first_name, d.last_name, d.seniority_rank,
                       CONCAT(d.first_name, ' ', d.last_name) as dispatcher_name
                FROM job_assignments ja
                JOIN dispatchers d ON ja.dispatcher_id = d.id
                WHERE ja.desk_id = ? AND ja.end_date IS NULL
                ORDER BY
                    FIELD(ja.shift, 'first', 'second', 'third', 'relief', 'atw')";
        return dbQueryAll($sql, [$deskId]);
    }

    /**
     * Get relief schedule for a desk
     */
    public static function getReliefSchedule($deskId) {
        $sql = "SELECT rs.*,
                       CONCAT(d.first_name, ' ', d.last_name) as relief_dispatcher_name
                FROM relief_schedules rs
                LEFT JOIN dispatchers d ON rs.relief_dispatcher_id = d.id
                WHERE rs.desk_id = ? AND rs.active = 1
                ORDER BY rs.day_of_week,
                         FIELD(rs.shift, 'first', 'second', 'third')";
        return dbQueryAll($sql, [$deskId]);
    }

    /**
     * Get ATW rotation entry for a desk
     */
    public static function getAtwRotation($deskId) {
        $sql = "SELECT ar.*,
                       CONCAT(d.first_name, ' ', d.last_name) as atw_dispatcher_name
                FROM atw_rotation ar
                LEFT JOIN dispatchers d ON ar.atw_dispatcher_id = d.id
                WHERE ar.desk_id = ? AND ar.active = 1
                ORDER BY ar.rotation_order";
        return dbQueryAll($sql, [$deskId]);
    }
}
