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
     * GAD Rest Day Classes (Article 3(f))
     * 3 classes with rotating rest day pairs (6-week cycle)
     * Same system as Extra Board to avoid Fri/Sat spanning pay period
     */
    const REST_CLASSES = [1, 2, 3];

    /**
     * Rest day pairs (6-week rotation, no Fri/Sat pair)
     */
    private static $restDayPairs = [
        0 => [6, 0], // Sat/Sun
        1 => [0, 1], // Sun/Mon
        2 => [1, 2], // Mon/Tue
        3 => [2, 3], // Tue/Wed
        4 => [3, 4], // Wed/Thu
        5 => [4, 5], // Thu/Fri
    ];

    /**
     * Class starting offsets (in pairs)
     */
    private static $classOffsets = [
        1 => 0, // Class 1 starts at Sat/Sun
        2 => 2, // Class 2 starts at Tue/Wed
        3 => 4, // Class 3 starts at Thu/Fri
    ];

    /**
     * Assign dispatcher to GAD pool with rest day class and cycle start date
     */
    public static function assignToGAD($dispatcherId, $restClass, $cycleStartDate = null) {
        if (!in_array($restClass, self::REST_CLASSES)) {
            throw new Exception("Invalid GAD rest class. Must be 1-3.");
        }

        if (!$cycleStartDate) {
            $cycleStartDate = date('Y-m-d');
        }

        $sql = "UPDATE dispatchers
                SET classification = 'gad',
                    gad_rest_class = ?,
                    gad_cycle_start_date = ?
                WHERE id = ?";
        return dbExecute($sql, [$restClass, $cycleStartDate, $dispatcherId]);
    }

    /**
     * Remove dispatcher from GAD pool
     */
    public static function removeFromGAD($dispatcherId) {
        $sql = "UPDATE dispatchers
                SET classification = 'extra_board',
                    gad_rest_class = NULL,
                    gad_cycle_start_date = NULL
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
     * Get GAD dispatchers by rest class
     */
    public static function getGADByRestClass($restClass) {
        if (!in_array($restClass, self::REST_CLASSES)) {
            throw new Exception("Invalid GAD rest class");
        }

        $sql = "SELECT * FROM dispatchers
                WHERE classification = 'gad'
                  AND gad_rest_class = ?
                  AND active = 1
                ORDER BY seniority_rank";
        return dbQueryAll($sql, [$restClass]);
    }

    /**
     * Check if GAD has rest day on given date
     */
    public static function isRestDay($dispatcherId, $date) {
        $dispatcher = self::getGADInfo($dispatcherId);
        if (!$dispatcher || !$dispatcher['gad_rest_class'] || !$dispatcher['gad_cycle_start_date']) {
            return false;
        }

        $restDayPair = self::getRestDayPair(
            $dispatcher['gad_rest_class'],
            $date,
            $dispatcher['gad_cycle_start_date']
        );

        $dayOfWeek = (int)date('w', strtotime($date));

        return in_array($dayOfWeek, $restDayPair);
    }

    /**
     * Calculate which rest day pair is active for a GAD class on a given date
     */
    public static function getRestDayPair($restClass, $date, $cycleStartDate) {
        // Calculate days since cycle start
        $daysSinceCycleStart = floor((strtotime($date) - strtotime($cycleStartDate)) / 86400);

        // Each pair lasts 7 days, cycle repeats every 6 weeks (42 days)
        $weeksInCycle = floor($daysSinceCycleStart / 7) % 6;

        // Apply class offset
        $classOffset = self::$classOffsets[$restClass];
        $pairIndex = ($weeksInCycle + $classOffset) % 6;

        return self::$restDayPairs[$pairIndex];
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
     * Get GAD rest day pairs for display
     */
    public static function getRestDayPairs() {
        return self::$restDayPairs;
    }

    /**
     * Get dispatchers on rest for a given date
     */
    public static function getDispatchersOnRest($date) {
        $dayOfWeek = (int)date('w', strtotime($date));

        $sql = "SELECT * FROM dispatchers
                WHERE classification = 'gad'
                  AND active = 1
                  AND gad_rest_class IS NOT NULL
                  AND gad_cycle_start_date IS NOT NULL
                ORDER BY seniority_rank";

        $allGADs = dbQueryAll($sql);
        $onRest = [];

        foreach ($allGADs as $gad) {
            if (self::isRestDay($gad['id'], $date)) {
                $onRest[] = $gad;
            }
        }

        return $onRest;
    }

    /**
     * Get rest day pair label for display
     */
    public static function getRestDayPairLabel($pairIndex) {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $pair = self::$restDayPairs[$pairIndex];
        return $days[$pair[0]] . '/' . $days[$pair[1]];
    }

    /**
     * Get rest day schedule for a dispatcher over a date range
     */
    public static function getRestDaySchedule($dispatcherId, $startDate, $endDate) {
        $dispatcher = self::getGADInfo($dispatcherId);

        if (!$dispatcher || !$dispatcher['gad_rest_class'] || !$dispatcher['gad_cycle_start_date']) {
            return [];
        }

        $schedule = [];
        $currentDate = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);

        while ($currentDate <= $endDateTime) {
            $dateStr = $currentDate->format('Y-m-d');

            $restDayPair = self::getRestDayPair(
                $dispatcher['gad_rest_class'],
                $dateStr,
                $dispatcher['gad_cycle_start_date']
            );

            $dayOfWeek = (int)$currentDate->format('w');

            $schedule[] = [
                'date' => $dateStr,
                'day_of_week' => $dayOfWeek,
                'is_rest_day' => in_array($dayOfWeek, $restDayPair),
                'rest_day_pair' => $restDayPair,
                'week_in_cycle' => floor((strtotime($dateStr) - strtotime($dispatcher['gad_cycle_start_date'])) / 604800) % 6
            ];

            $currentDate->modify('+1 day');
        }

        return $schedule;
    }
}
