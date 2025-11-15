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
     * @param int $senioritySequence - Tiebreaker for same date (1=most senior, 2=next, etc)
     * NOTE: Caller should call recalculateSeniorityRanks() after this to set correct ranks
     */
    public static function create($employeeNumber, $firstName, $lastName, $seniorityDate, $classification = 'extra_board', $senioritySequence = 1) {
        // Use temporary high rank to avoid conflicts during creation
        // Each dispatcher gets a unique temp rank (999999, 999998, 999997, etc.)
        // Count existing active dispatchers to generate unique temp rank
        $countSql = "SELECT COUNT(*) as count FROM dispatchers WHERE active = 1";
        $result = dbQueryOne($countSql);
        $tempRank = 999999 - $result['count'];

        $sql = "INSERT INTO dispatchers (employee_number, first_name, last_name, seniority_date, seniority_rank, seniority_sequence, classification)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        return dbInsert($sql, [$employeeNumber, $firstName, $lastName, $seniorityDate, $tempRank, $senioritySequence, $classification]);
    }

    /**
     * Update dispatcher
     * @param int $senioritySequence - Tiebreaker for same date (1=most senior, 2=next, etc)
     * NOTE: Caller should call recalculateSeniorityRanks() after this if date/sequence changed
     */
    public static function update($id, $employeeNumber, $firstName, $lastName, $seniorityDate, $classification, $active = true, $senioritySequence = null) {
        $current = self::getById($id);

        // If sequence not provided, keep current
        if ($senioritySequence === null) {
            $senioritySequence = $current['seniority_sequence'] ?? 1;
        }

        // Keep current rank temporarily - caller will recalculate all ranks
        $seniorityRank = $current['seniority_rank'];

        $sql = "UPDATE dispatchers
                SET employee_number = ?, first_name = ?, last_name = ?, seniority_date = ?,
                    seniority_rank = ?, seniority_sequence = ?, classification = ?, active = ?
                WHERE id = ?";
        return dbExecute($sql, [$employeeNumber, $firstName, $lastName, $seniorityDate, $seniorityRank, $senioritySequence, $classification, $active ? 1 : 0, $id]);
    }

    /**
     * Calculate seniority rank for a given date and sequence
     * @param string $seniorityDate - Date of seniority
     * @param int $senioritySequence - Tiebreaker for same date (1=most senior)
     */
    private static function calculateNextSeniorityRank($seniorityDate, $senioritySequence = 1) {
        // Count how many active dispatchers are more senior
        // More senior = earlier date OR same date but lower sequence number
        $sql = "SELECT COUNT(*) as count FROM dispatchers
                WHERE active = 1
                  AND (seniority_date < ?
                       OR (seniority_date = ? AND seniority_sequence < ?))";
        $result = dbQueryOne($sql, [$seniorityDate, $seniorityDate, $senioritySequence]);
        return $result['count'] + 1;
    }

    /**
     * Recalculate all seniority ranks (use after bulk updates)
     * @param bool $useTransaction - If false, skip transaction (caller handles it)
     */
    public static function recalculateSeniorityRanks($useTransaction = true) {
        // Order by date first, then sequence within same date
        $sql = "SELECT id, seniority_date, seniority_sequence
                FROM dispatchers
                WHERE active = 1
                ORDER BY seniority_date, seniority_sequence, id";
        $dispatchers = dbQueryAll($sql);

        error_log("recalculateSeniorityRanks: Found " . count($dispatchers) . " active dispatchers");

        if ($useTransaction) {
            dbBeginTransaction();
        }

        try {
            // PASS 1: Set all to temporary negative ranks to avoid conflicts
            // This ensures no conflicts with the final ranks we're about to assign
            error_log("recalculateSeniorityRanks: Pass 1 - Setting temporary ranks");
            $tempRank = -1;
            foreach ($dispatchers as $dispatcher) {
                $updateSql = "UPDATE dispatchers SET seniority_rank = ? WHERE id = ?";
                dbExecute($updateSql, [$tempRank, $dispatcher['id']]);
                $tempRank--;
            }

            // PASS 2: Set final ranks in correct order
            error_log("recalculateSeniorityRanks: Pass 2 - Setting final ranks");
            $rank = 1;
            foreach ($dispatchers as $dispatcher) {
                $updateSql = "UPDATE dispatchers SET seniority_rank = ? WHERE id = ?";
                dbExecute($updateSql, [$rank, $dispatcher['id']]);
                error_log("recalculateSeniorityRanks: Set rank $rank for dispatcher ID {$dispatcher['id']}");
                $rank++;
            }

            if ($useTransaction) {
                dbCommit();
                error_log("recalculateSeniorityRanks: Transaction committed successfully");
            }
            return true;
        } catch (Exception $e) {
            error_log("recalculateSeniorityRanks: Error - " . $e->getMessage());
            if ($useTransaction) {
                dbRollback();
            }
            throw $e;
        }
    }

    /**
     * Get dispatcher's current job assignment
     */
    public static function getCurrentAssignment($dispatcherId) {
        $sql = "SELECT ja.*, d.name as desk_name, division.name as division_name
                FROM job_assignments ja
                JOIN desks d ON ja.desk_id = d.id
                JOIN divisions division ON d.division_id = division.id
                WHERE ja.dispatcher_id = ? AND ja.end_date IS NULL
                ORDER BY ja.start_date DESC
                LIMIT 1";
        return dbQueryOne($sql, [$dispatcherId]);
    }

    /**
     * Get dispatcher qualifications
     */
    public static function getQualifications($dispatcherId) {
        $sql = "SELECT dq.*, d.name as desk_name, division.name as division_name
                FROM dispatcher_qualifications dq
                JOIN desks d ON dq.desk_id = d.id
                JOIN divisions division ON d.division_id = division.id
                WHERE dq.dispatcher_id = ?
                ORDER BY division.name, d.name";
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
