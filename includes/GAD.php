<?php
/**
 * GAD (Guaranteed Assigned Dispatcher) Management
 *
 * Implements Article 3(f) - GAD Scheduling
 * Implements Appendix 9 - GAD Baseline Rules
 *
 * GAD Features:
 * - Rotating rest day groups (A-G)
 * - 5-day guarantee
 * - 1.0 GAD per desk baseline ratio
 * - Above baseline = straight time diversions
 * - Below baseline = overtime diversions
 */

require_once __DIR__ . '/../config/database.php';

class GAD {

    /**
     * GAD Rest Day Groups (Article 3(f))
     * 7 groups with rotating consecutive rest days
     */
    const REST_GROUPS = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /**
     * Rest day schedule for each group (day of week => groups with rest)
     * This rotates weekly
     */
    const REST_SCHEDULE = [
        // Week pattern - each group gets 2 consecutive rest days that rotate
        'A' => [0, 1], // Sunday-Monday
        'B' => [1, 2], // Monday-Tuesday
        'C' => [2, 3], // Tuesday-Wednesday
        'D' => [3, 4], // Wednesday-Thursday
        'E' => [4, 5], // Thursday-Friday
        'F' => [5, 6], // Friday-Saturday
        'G' => [6, 0], // Saturday-Sunday
    ];

    /**
     * Assign dispatcher to GAD pool with rest day group
     */
    public static function assignToGAD($dispatcherId, $restGroup) {
        if (!in_array($restGroup, self::REST_GROUPS)) {
            throw new Exception("Invalid GAD rest group. Must be A-G.");
        }

        $sql = "UPDATE dispatchers
                SET classification = 'gad', gad_rest_group = ?
                WHERE id = ?";
        return dbExecute($sql, [$restGroup, $dispatcherId]);
    }

    /**
     * Remove dispatcher from GAD pool
     */
    public static function removeFromGAD($dispatcherId) {
        $sql = "UPDATE dispatchers
                SET classification = 'extra_board', gad_rest_group = NULL
                WHERE id = ?";
        return dbExecute($sql, [$dispatcherId]);
    }

    /**
     * Get all GAD dispatchers
     */
    public static function getAllGAD($divisionId = null) {
        $sql = "SELECT d.*,
                       div.name as division_name,
                       COUNT(DISTINCT ja.id) as active_assignments
                FROM dispatchers d
                LEFT JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                LEFT JOIN desks desk ON dq.desk_id = desk.id
                LEFT JOIN divisions div ON desk.division_id = div.id
                LEFT JOIN job_assignments ja ON d.id = ja.dispatcher_id AND ja.end_date IS NULL
                WHERE d.classification = 'gad' AND d.active = 1";

        $params = [];
        if ($divisionId) {
            $sql .= " AND div.id = ?";
            $params[] = $divisionId;
        }

        $sql .= " GROUP BY d.id ORDER BY d.seniority_rank";

        return dbQueryAll($sql, $params);
    }

    /**
     * Get GAD dispatchers by rest group
     */
    public static function getGADByRestGroup($restGroup) {
        if (!in_array($restGroup, self::REST_GROUPS)) {
            throw new Exception("Invalid GAD rest group");
        }

        $sql = "SELECT * FROM dispatchers
                WHERE classification = 'gad'
                  AND gad_rest_group = ?
                  AND active = 1
                ORDER BY seniority_rank";
        return dbQueryAll($sql, [$restGroup]);
    }

    /**
     * Check if GAD has rest day on given date
     */
    public static function isRestDay($dispatcherId, $date) {
        $dispatcher = self::getGADInfo($dispatcherId);
        if (!$dispatcher || !$dispatcher['gad_rest_group']) {
            return false;
        }

        $dayOfWeek = (int)date('w', strtotime($date));
        $restDays = self::REST_SCHEDULE[$dispatcher['gad_rest_group']];

        return in_array($dayOfWeek, $restDays);
    }

    /**
     * Get GAD info for a dispatcher
     */
    public static function getGADInfo($dispatcherId) {
        $sql = "SELECT * FROM dispatchers WHERE id = ? AND classification = 'gad'";
        return dbQueryOne($sql, [$dispatcherId]);
    }

    /**
     * Get available GAD for a specific date/shift
     * Excludes: rest days, training protected, HOS violations, already assigned
     */
    public static function getAvailableGAD($date, $shift, $deskId) {
        $dayOfWeek = (int)date('w', strtotime($date));

        // Get all GAD dispatchers qualified for this desk
        $sql = "SELECT DISTINCT d.*,
                       dpr.hourly_rate,
                       dpr.overtime_rate
                FROM dispatchers d
                INNER JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                LEFT JOIN dispatcher_pay_rates dpr ON d.id = dpr.dispatcher_id
                    AND dpr.effective_date <= ?
                    AND (dpr.end_date IS NULL OR dpr.end_date >= ?)
                WHERE d.classification = 'gad'
                  AND d.active = 1
                  AND dq.desk_id = ?
                  AND d.training_protected = 0
                ORDER BY d.seniority_rank";

        $gads = dbQueryAll($sql, [$date, $date, $deskId]);

        $available = [];
        foreach ($gads as $gad) {
            $reasons = [];

            // Check rest day
            if ($gad['gad_rest_group'] && self::isRestDay($gad['id'], $date)) {
                $reasons[] = 'rest_day';
            }

            // Check if already assigned for this date/shift
            if (self::isAlreadyAssigned($gad['id'], $date, $shift)) {
                $reasons[] = 'already_assigned';
            }

            // Check FRA hours of service
            require_once __DIR__ . '/FRAHours.php';
            if (!FRAHours::isAvailableForShift($gad['id'], $date, $shift)) {
                $reasons[] = 'hos_violation';
            }

            if (empty($reasons)) {
                $available[] = $gad;
            } else {
                // Log unavailability
                self::logAvailability($gad['id'], $date, $shift, false, implode(',', $reasons));
            }
        }

        return $available;
    }

    /**
     * Check if dispatcher is already assigned for date/shift
     */
    private static function isAlreadyAssigned($dispatcherId, $date, $shift) {
        $sql = "SELECT COUNT(*) as count
                FROM assignment_log
                WHERE dispatcher_id = ?
                  AND work_date = ?
                  AND shift = ?";
        $result = dbQueryOne($sql, [$dispatcherId, $date, $shift]);
        return $result['count'] > 0;
    }

    /**
     * Log GAD availability check
     */
    private static function logAvailability($dispatcherId, $date, $shift, $available, $reason = null) {
        $sql = "INSERT INTO gad_availability_log
                (dispatcher_id, check_date, shift, available, unavailable_reason)
                VALUES (?, ?, ?, ?, ?)";
        return dbInsert($sql, [$dispatcherId, $date, $shift, $available ? 1 : 0, $reason]);
    }

    /**
     * Calculate GAD baseline for a division (Appendix 9)
     * Baseline = 1.0 GAD per desk (including ACD desks)
     */
    public static function calculateBaseline($divisionId) {
        // Count total desks (8-hour regular + 12-hour ACD)
        $sql = "SELECT
                    COUNT(*) as total_desks,
                    SUM(CASE WHEN is_acd_desk = 1 THEN 1 ELSE 0 END) as acd_desks
                FROM desks
                WHERE division_id = ? AND active = 1";
        $desks = dbQueryOne($sql, [$divisionId]);

        // Baseline is 1.0 per desk
        $baseline = (float)$desks['total_desks'];

        // Count current GAD dispatchers qualified for this division
        $sql = "SELECT COUNT(DISTINCT d.id) as gad_count
                FROM dispatchers d
                INNER JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                INNER JOIN desks desk ON dq.desk_id = desk.id
                WHERE d.classification = 'gad'
                  AND d.active = 1
                  AND desk.division_id = ?";
        $gadCount = dbQueryOne($sql, [$divisionId]);

        return [
            'division_id' => $divisionId,
            'total_desks' => $desks['total_desks'],
            'acd_desks' => $desks['acd_desks'],
            'baseline_gad_count' => $baseline,
            'current_gad_count' => $gadCount['gad_count'],
            'above_baseline' => $gadCount['gad_count'] > $baseline,
            'at_baseline' => $gadCount['gad_count'] == $baseline,
            'below_baseline' => $gadCount['gad_count'] < $baseline,
        ];
    }

    /**
     * Save GAD baseline snapshot
     */
    public static function saveBaseline($divisionId, $effectiveDate = null) {
        if (!$effectiveDate) {
            $effectiveDate = date('Y-m-d');
        }

        $baseline = self::calculateBaseline($divisionId);

        $sql = "INSERT INTO gad_baseline
                (division_id, total_desks, acd_desks, baseline_gad_count, current_gad_count, effective_date)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_desks = VALUES(total_desks),
                    acd_desks = VALUES(acd_desks),
                    baseline_gad_count = VALUES(baseline_gad_count),
                    current_gad_count = VALUES(current_gad_count)";

        return dbExecute($sql, [
            $divisionId,
            $baseline['total_desks'],
            $baseline['acd_desks'],
            $baseline['baseline_gad_count'],
            $baseline['current_gad_count'],
            $effectiveDate
        ]);
    }

    /**
     * Get current baseline status for division
     */
    public static function getBaselineStatus($divisionId, $date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }

        $sql = "SELECT * FROM gad_baseline
                WHERE division_id = ?
                  AND effective_date <= ?
                ORDER BY effective_date DESC
                LIMIT 1";

        $baseline = dbQueryOne($sql, [$divisionId, $date]);

        if (!$baseline) {
            // No baseline record, calculate it
            return self::calculateBaseline($divisionId);
        }

        return $baseline;
    }

    /**
     * Determine pay type for diversion based on GAD baseline (Appendix 9)
     * Above baseline = straight time
     * At or below baseline = overtime
     */
    public static function getDiversionPayType($divisionId, $date = null) {
        $baseline = self::getBaselineStatus($divisionId, $date);

        if ($baseline['above_baseline']) {
            return 'straight';
        } else {
            return 'overtime';
        }
    }

    /**
     * Get GAD rest schedule for display
     */
    public static function getRestSchedule() {
        return self::REST_SCHEDULE;
    }

    /**
     * Get dispatchers on rest for a given date
     */
    public static function getDispatchersOnRest($date) {
        $dayOfWeek = (int)date('w', strtotime($date));

        $onRest = [];
        foreach (self::REST_SCHEDULE as $group => $restDays) {
            if (in_array($dayOfWeek, $restDays)) {
                $dispatchers = self::getGADByRestGroup($group);
                $onRest[$group] = $dispatchers;
            }
        }

        return $onRest;
    }
}
