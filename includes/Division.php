<?php
require_once __DIR__ . '/../config/database.php';

class Division {

    /**
     * Get all divisions
     */
    public static function getAll($activeOnly = true) {
        $sql = "SELECT * FROM divisions";
        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY name";
        return dbQueryAll($sql);
    }

    /**
     * Get division by ID
     */
    public static function getById($id) {
        $sql = "SELECT * FROM divisions WHERE id = ?";
        return dbQueryOne($sql, [$id]);
    }

    /**
     * Create a new division
     */
    public static function create($name, $code) {
        $sql = "INSERT INTO divisions (name, code) VALUES (?, ?)";
        return dbInsert($sql, [$name, $code]);
    }

    /**
     * Update division
     */
    public static function update($id, $name, $code, $active = true) {
        $sql = "UPDATE divisions SET name = ?, code = ?, active = ? WHERE id = ?";
        return dbExecute($sql, [$name, $code, $active ? 1 : 0, $id]);
    }

    /**
     * Delete division (soft delete - set inactive)
     */
    public static function delete($id) {
        $sql = "UPDATE divisions SET active = 0 WHERE id = ?";
        return dbExecute($sql, [$id]);
    }

    /**
     * Get desks for a division
     */
    public static function getDesks($divisionId, $activeOnly = true) {
        $sql = "SELECT * FROM desks WHERE division_id = ?";
        if ($activeOnly) {
            $sql .= " AND active = 1";
        }
        $sql .= " ORDER BY name";
        return dbQueryAll($sql, [$divisionId]);
    }
}
