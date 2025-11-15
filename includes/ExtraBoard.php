<?php
/**
 * Extra Board Management
 *
 * Handles rotating rest day assignments for extra board dispatchers.
 * Three classes ensure staggered rest days for coverage.
 *
 * Rest Day Rotation (6-week cycle):
 * - Week 1: Sat/Sun
 * - Week 2: Sun/Mon
 * - Week 3: Mon/Tue
 * - Week 4: Tue/Wed
 * - Week 5: Wed/Thu
 * - Week 6: Thu/Fri
 * - Week 7: Sat/Sun (cycle repeats, creating natural 4-day weekend)
 *
 * Class Offsets (ensure no overlap):
 * - Class 1: Starts at pair 0 (Sat/Sun)
 * - Class 2: Starts at pair 2 (Tue/Wed)
 * - Class 3: Starts at pair 4 (Thu/Fri)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/FRAHours.php';

class ExtraBoard {

    // Rest day pairs (index 0-5, repeats every 6 weeks)
    private static $restDayPairs = [
        0 => [6, 0], // Sat/Sun
        1 => [0, 1], // Sun/Mon
        2 => [1, 2], // Mon/Tue
        3 => [2, 3], // Tue/Wed
        4 => [3, 4], // Wed/Thu
        5 => [4, 5], // Thu/Fri
    ];

    // Class starting offsets (in pairs)
    private static $classOffsets = [
        1 => 0, // Class 1 starts at Sat/Sun
        2 => 2, // Class 2 starts at Tue/Wed
        3 => 4, // Class 3 starts at Thu/Fri
    ];

    /**
     * Assign dispatcher to extra board
     */
    public static function assign($dispatcherId, $boardClass, $startDate, $cycleStartDate = null) {
        // End any existing extra board assignment
        $sql = "UPDATE extra_board_assignments
                SET end_date = ?
                WHERE dispatcher_id = ?
                  AND end_date IS NULL";
        dbExecute($sql, [date('Y-m-d', strtotime('-1 day', strtotime($startDate))), $dispatcherId]);

        // If no cycle start date provided, use the assignment start date
        if (!$cycleStartDate) {
            $cycleStartDate = $startDate;
        }

        // Create new assignment
        $sql = "INSERT INTO extra_board_assignments
                (dispatcher_id, board_class, cycle_start_date, start_date)
                VALUES (?, ?, ?, ?)";
        return dbInsert($sql, [$dispatcherId, $boardClass, $cycleStartDate, $startDate]);
    }

    /**
     * End extra board assignment
     */
    public static function end($dispatcherId, $endDate) {
        $sql = "UPDATE extra_board_assignments
                SET end_date = ?
                WHERE dispatcher_id = ?
                  AND end_date IS NULL";
        return dbExecute($sql, [$endDate, $dispatcherId]);
    }

    /**
     * Get active extra board assignments
     */
    public static function getActive($date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }

        $sql = "SELECT eb.*,
                       d.first_name, d.last_name, d.employee_number
                FROM extra_board_assignments eb
                INNER JOIN dispatchers d ON eb.dispatcher_id = d.id
                WHERE eb.start_date <= ?
                  AND (eb.end_date IS NULL OR eb.end_date >= ?)
                  AND d.active = 1
                ORDER BY eb.board_class, d.seniority_rank";

        return dbQueryAll($sql, [$date, $date]);
    }

    /**
     * Get dispatcher's current extra board assignment
     */
    public static function getAssignment($dispatcherId, $date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }

        $sql = "SELECT *
                FROM extra_board_assignments
                WHERE dispatcher_id = ?
                  AND start_date <= ?
                  AND (end_date IS NULL OR end_date >= ?)
                LIMIT 1";

        return dbQueryOne($sql, [$dispatcherId, $date, $date]);
    }

    /**
     * Calculate which rest day pair is active for a board class on a given date
     */
    public static function getRestDayPair($boardClass, $date, $cycleStartDate) {
        // Calculate days since cycle start
        $daysSinceCycleStart = floor((strtotime($date) - strtotime($cycleStartDate)) / 86400);

        // Each pair lasts 7 days, cycle repeats every 6 weeks (42 days)
        $weeksInCycle = floor($daysSinceCycleStart / 7) % 6;

        // Apply class offset
        $classOffset = self::$classOffsets[$boardClass];
        $pairIndex = ($weeksInCycle + $classOffset) % 6;

        return self::$restDayPairs[$pairIndex];
    }

    /**
     * Check if dispatcher is on rest day for a given date
     */
    public static function isRestDay($dispatcherId, $date) {
        $assignment = self::getAssignment($dispatcherId, $date);

        if (!$assignment) {
            return false; // Not on extra board
        }

        $restDayPair = self::getRestDayPair(
            $assignment['board_class'],
            $date,
            $assignment['cycle_start_date']
        );

        $dayOfWeek = date('w', strtotime($date)); // 0 (Sun) to 6 (Sat)

        return in_array($dayOfWeek, $restDayPair);
    }

    /**
     * Get available extra board dispatchers for a date
     * (not on rest day and FRA hours compliant)
     */
    public static function getAvailable($date, $shift = null) {
        $activeBoard = self::getActive($date);
        $available = [];

        foreach ($activeBoard as $assignment) {
            // Check if on rest day
            if (self::isRestDay($assignment['dispatcher_id'], $date)) {
                continue;
            }

            // Check FRA hours if shift specified
            if ($shift && !FRAHours::isAvailableForShift($assignment['dispatcher_id'], $date, $shift)) {
                continue;
            }

            $available[] = $assignment;
        }

        return $available;
    }

    /**
     * Get rest day schedule for a dispatcher over a date range
     */
    public static function getRestDaySchedule($dispatcherId, $startDate, $endDate) {
        $assignment = self::getAssignment($dispatcherId, $startDate);

        if (!$assignment) {
            return [];
        }

        $schedule = [];
        $currentDate = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);

        while ($currentDate <= $endDateTime) {
            $dateStr = $currentDate->format('Y-m-d');

            $restDayPair = self::getRestDayPair(
                $assignment['board_class'],
                $dateStr,
                $assignment['cycle_start_date']
            );

            $dayOfWeek = (int)$currentDate->format('w');

            $schedule[] = [
                'date' => $dateStr,
                'day_of_week' => $dayOfWeek,
                'is_rest_day' => in_array($dayOfWeek, $restDayPair),
                'rest_day_pair' => $restDayPair,
                'week_in_cycle' => floor((strtotime($dateStr) - strtotime($assignment['cycle_start_date'])) / 604800) % 6
            ];

            $currentDate->modify('+1 day');
        }

        return $schedule;
    }

    /**
     * Get all extra board assignments (for management UI)
     */
    public static function getAll() {
        $sql = "SELECT eb.*,
                       d.first_name, d.last_name, d.employee_number, d.seniority_rank
                FROM extra_board_assignments eb
                INNER JOIN dispatchers d ON eb.dispatcher_id = d.id
                ORDER BY eb.end_date IS NULL DESC, eb.board_class, d.seniority_rank";

        return dbQueryAll($sql);
    }

    /**
     * Get rest day pair label for display
     */
    public static function getRestDayPairLabel($pairIndex) {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $pair = self::$restDayPairs[$pairIndex];
        return $days[$pair[0]] . '/' . $days[$pair[1]];
    }
}
