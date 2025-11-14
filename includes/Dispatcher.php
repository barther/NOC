<?php
require_once __DIR__ . '/../config/database.php';

class Dispatcher {

    /**
     * Get all dispatchers
     */
    public static function getAll($activeOnly = true) {
        $sql = "SELECT * FROM dispatchers";
        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY seniority_rank";
        return dbQueryAll($sql);
    }

    /**
     * Get dispatcher by ID
     */
    public static function getById($id) {
        $sql = "SELECT * FROM dispatchers WHERE id = ?";
        return dbQueryOne($sql, [$id]);
    }

    /**
     * Get dispatchers by classification
     */
    public static function getByClassification($classification, $activeOnly = true) {
        $sql = "SELECT * FROM dispatchers WHERE classification = ?";
        if ($activeOnly) {
            $sql .= " AND active = 1";
        }
        $sql .= " ORDER BY seniority_rank";
        return dbQueryAll($sql, [$classification]);
    }

    /**
     * Get Extra Board dispatchers
     */
    public static function getExtraBoard($activeOnly = true) {
        return self::getByClassification('extra_board', $activeOnly);
    }

    /**
     * Create a new dispatcher
     */
    public static function create($employeeNumber, $firstName, $lastName, $seniorityDate, $classification = 'extra_board') {
        // Calculate seniority rank
        $seniorityRank = self::calculateNextSeniorityRank($seniorityDate);

        $sql = "INSERT INTO dispatchers (employee_number, first_name, last_name, seniority_date, seniority_rank, classification)
                VALUES (?, ?, ?, ?, ?, ?)";
        return dbInsert($sql, [$employeeNumber, $firstName, $lastName, $seniorityDate, $seniorityRank, $classification]);
    }

    /**
     * Update dispatcher
     */
    public static function update($id, $employeeNumber, $firstName, $lastName, $seniorityDate, $classification, $active = true) {
        // Recalculate seniority rank if date changed
        $current = self::getById($id);
        if ($current['seniority_date'] !== $seniorityDate) {
            $seniorityRank = self::calculateNextSeniorityRank($seniorityDate);
        } else {
            $seniorityRank = $current['seniority_rank'];
        }

        $sql = "UPDATE dispatchers
                SET employee_number = ?, first_name = ?, last_name = ?, seniority_date = ?,
                    seniority_rank = ?, classification = ?, active = ?
                WHERE id = ?";
        return dbExecute($sql, [$employeeNumber, $firstName, $lastName, $seniorityDate, $seniorityRank, $classification, $active ? 1 : 0, $id]);
    }

    /**
     * Calculate seniority rank for a given date
     */
    private static function calculateNextSeniorityRank($seniorityDate) {
        // Count how many active dispatchers have an earlier seniority date
        $sql = "SELECT COUNT(*) as count FROM dispatchers
                WHERE active = 1 AND seniority_date < ?";
        $result = dbQueryOne($sql, [$seniorityDate]);
        return $result['count'] + 1;
    }

    /**
     * Recalculate all seniority ranks (use after bulk updates)
     */
    public static function recalculateSeniorityRanks() {
        $sql = "SELECT id, seniority_date FROM dispatchers WHERE active = 1 ORDER BY seniority_date, id";
        $dispatchers = dbQueryAll($sql);

        dbBeginTransaction();
        try {
            $rank = 1;
            foreach ($dispatchers as $dispatcher) {
                $updateSql = "UPDATE dispatchers SET seniority_rank = ? WHERE id = ?";
                dbExecute($updateSql, [$rank, $dispatcher['id']]);
                $rank++;
            }
            dbCommit();
            return true;
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Get dispatcher's current job assignment
     */
    public static function getCurrentAssignment($dispatcherId) {
        $sql = "SELECT ja.*, d.name as desk_name, div.name as division_name
                FROM job_assignments ja
                JOIN desks d ON ja.desk_id = d.id
                JOIN divisions div ON d.division_id = div.id
                WHERE ja.dispatcher_id = ? AND ja.end_date IS NULL
                ORDER BY ja.start_date DESC
                LIMIT 1";
        return dbQueryOne($sql, [$dispatcherId]);
    }

    /**
     * Get dispatcher qualifications
     */
    public static function getQualifications($dispatcherId) {
        $sql = "SELECT dq.*, d.name as desk_name, div.name as division_name
                FROM dispatcher_qualifications dq
                JOIN desks d ON dq.desk_id = d.id
                JOIN divisions div ON d.division_id = div.id
                WHERE dq.dispatcher_id = ?
                ORDER BY div.name, d.name";
        return dbQueryAll($sql, [$dispatcherId]);
    }

    /**
     * Check if dispatcher is qualified for a desk
     */
    public static function isQualified($dispatcherId, $deskId) {
        $sql = "SELECT qualified FROM dispatcher_qualifications
                WHERE dispatcher_id = ? AND desk_id = ? AND qualified = 1";
        $result = dbQueryOne($sql, [$dispatcherId, $deskId]);
        return $result !== false;
    }

    /**
     * Get qualified dispatchers for a desk
     */
    public static function getQualifiedForDesk($deskId, $excludeQualifying = true) {
        $sql = "SELECT d.*
                FROM dispatchers d
                JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                WHERE dq.desk_id = ? AND dq.qualified = 1 AND d.active = 1";
        if ($excludeQualifying) {
            $sql .= " AND d.classification != 'qualifying'";
        }
        $sql .= " ORDER BY d.seniority_rank";
        return dbQueryAll($sql, [$deskId]);
    }

    /**
     * Add or update qualification
     */
    public static function setQualification($dispatcherId, $deskId, $qualified = false, $qualifyingStarted = null, $qualifiedDate = null) {
        $sql = "INSERT INTO dispatcher_qualifications (dispatcher_id, desk_id, qualified, qualifying_started, qualified_date)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE qualified = ?, qualifying_started = ?, qualified_date = ?";
        return dbExecute($sql, [
            $dispatcherId, $deskId, $qualified ? 1 : 0, $qualifyingStarted, $qualifiedDate,
            $qualified ? 1 : 0, $qualifyingStarted, $qualifiedDate
        ]);
    }

    /**
     * Get dispatcher's FRA availability (next time they can work)
     */
    public static function getNextAvailableTime($dispatcherId, $asOfDateTime = null) {
        if ($asOfDateTime === null) {
            $asOfDateTime = date('Y-m-d H:i:s');
        }

        $sql = "SELECT next_available_time
                FROM fra_hours_tracking
                WHERE dispatcher_id = ? AND next_available_time > ?
                ORDER BY next_available_time DESC
                LIMIT 1";
        $result = dbQueryOne($sql, [$dispatcherId, $asOfDateTime]);

        if ($result) {
            return $result['next_available_time'];
        }

        return $asOfDateTime; // Available now
    }

    /**
     * Check if dispatcher can work a shift (FRA compliance)
     */
    public static function canWorkShift($dispatcherId, $shiftStartDateTime) {
        $nextAvailable = self::getNextAvailableTime($dispatcherId);
        return strtotime($nextAvailable) <= strtotime($shiftStartDateTime);
    }
}
