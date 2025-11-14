<?php
/**
 * FRA (Federal Railroad Administration) Hours of Service Tracking
 *
 * FRA Rules:
 * - Maximum duty period: 9 hours
 * - Minimum rest between shifts: 15 hours
 * - 12-hour shifts (ACDs) still require 15-hour rest
 *
 * Used for:
 * - Determining dispatcher availability for vacancy fills
 * - Preventing HOS violations
 * - Calculating when dispatcher becomes available
 */

require_once __DIR__ . '/../config/database.php';

class FRAHours {

    const MAX_DUTY_HOURS = 9;
    const MAX_DUTY_HOURS_ACD = 12;
    const MIN_REST_HOURS = 15;

    /**
     * Shift start times (in 24-hour format)
     */
    const SHIFT_TIMES = [
        'first' => ['start' => '07:00:00', 'end' => '15:00:00', 'hours' => 8],
        'second' => ['start' => '15:00:00', 'end' => '23:00:00', 'hours' => 8],
        'third' => ['start' => '23:00:00', 'end' => '07:00:00', 'hours' => 8], // Crosses midnight
    ];

    /**
     * Record hours worked for a dispatcher
     */
    public static function recordHours($dispatcherId, $workDate, $shift, $actualStartTime, $actualEndTime) {
        // Calculate hours worked
        $start = strtotime($workDate . ' ' . $actualStartTime);
        $end = strtotime($workDate . ' ' . $actualEndTime);

        // Handle shifts crossing midnight
        if ($end < $start) {
            $end += 86400; // Add 24 hours
        }

        $hoursWorked = ($end - $start) / 3600;

        $sql = "INSERT INTO fra_hours_tracking
                (dispatcher_id, work_date, shift, actual_start_time, actual_end_time, hours_worked)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    actual_start_time = VALUES(actual_start_time),
                    actual_end_time = VALUES(actual_end_time),
                    hours_worked = VALUES(hours_worked)";

        return dbExecute($sql, [$dispatcherId, $workDate, $shift, $actualStartTime, $actualEndTime, $hoursWorked]);
    }

    /**
     * Get last shift worked by dispatcher
     */
    public static function getLastShift($dispatcherId) {
        $sql = "SELECT * FROM fra_hours_tracking
                WHERE dispatcher_id = ?
                ORDER BY work_date DESC, actual_end_time DESC
                LIMIT 1";
        return dbQueryOne($sql, [$dispatcherId]);
    }

    /**
     * Calculate when dispatcher will be available after rest period
     */
    public static function getNextAvailableTime($dispatcherId) {
        $lastShift = self::getLastShift($dispatcherId);

        if (!$lastShift) {
            // No previous shifts, available now
            return date('Y-m-d H:i:s');
        }

        // Calculate end of last shift
        $lastEndDateTime = $lastShift['work_date'] . ' ' . $lastShift['actual_end_time'];
        $lastEnd = strtotime($lastEndDateTime);

        // Add 15-hour rest period
        $availableTime = $lastEnd + (self::MIN_REST_HOURS * 3600);

        return date('Y-m-d H:i:s', $availableTime);
    }

    /**
     * Check if dispatcher is available for a specific shift
     * A dispatcher is available if they can get 15 hours rest before the shift starts
     * AND can get 15 hours rest after the shift ends before their next regular shift
     */
    public static function isAvailableForShift($dispatcherId, $date, $shift) {
        $lastShift = self::getLastShift($dispatcherId);

        if (!$lastShift) {
            // No previous shifts, available
            return true;
        }

        // Get proposed shift times
        $shiftInfo = self::SHIFT_TIMES[$shift];
        $proposedStartDateTime = $date . ' ' . $shiftInfo['start'];
        $proposedStart = strtotime($proposedStartDateTime);

        // Get last shift end time
        $lastEndDateTime = $lastShift['work_date'] . ' ' . $lastShift['actual_end_time'];
        $lastEnd = strtotime($lastEndDateTime);

        // Calculate hours between last shift end and proposed shift start
        $hoursSinceLastShift = ($proposedStart - $lastEnd) / 3600;

        // Must have at least 15 hours rest
        if ($hoursSinceLastShift < self::MIN_REST_HOURS) {
            return false;
        }

        // Check if they can get 15 hours rest AFTER this shift before next regular assignment
        $nextRegularShift = self::getNextRegularShift($dispatcherId, $date);
        if ($nextRegularShift) {
            $proposedEndDateTime = $date . ' ' . $shiftInfo['end'];
            $proposedEnd = strtotime($proposedEndDateTime);

            // Handle shift crossing midnight
            if ($proposedEnd < $proposedStart) {
                $proposedEnd += 86400;
            }

            $nextStartDateTime = $nextRegularShift['work_date'] . ' ' . self::SHIFT_TIMES[$nextRegularShift['shift']]['start'];
            $nextStart = strtotime($nextStartDateTime);

            $hoursUntilNext = ($nextStart - $proposedEnd) / 3600;

            if ($hoursUntilNext < self::MIN_REST_HOURS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get next regular shift assignment for dispatcher after a given date
     */
    private static function getNextRegularShift($dispatcherId, $afterDate) {
        $sql = "SELECT ja.shift, ? as work_date
                FROM job_assignments ja
                WHERE ja.dispatcher_id = ?
                  AND ja.assignment_type = 'regular'
                  AND ja.end_date IS NULL
                LIMIT 1";

        $assignment = dbQueryOne($sql, [date('Y-m-d', strtotime($afterDate . ' +1 day')), $dispatcherId]);

        return $assignment;
    }

    /**
     * Get hours worked in a date range
     */
    public static function getHoursWorked($dispatcherId, $startDate, $endDate) {
        $sql = "SELECT
                    SUM(hours_worked) as total_hours,
                    COUNT(*) as shifts_worked
                FROM fra_hours_tracking
                WHERE dispatcher_id = ?
                  AND work_date BETWEEN ? AND ?";

        return dbQueryOne($sql, [$dispatcherId, $startDate, $endDate]);
    }

    /**
     * Get detailed hours breakdown
     */
    public static function getHoursBreakdown($dispatcherId, $startDate, $endDate) {
        $sql = "SELECT * FROM fra_hours_tracking
                WHERE dispatcher_id = ?
                  AND work_date BETWEEN ? AND ?
                ORDER BY work_date, actual_start_time";

        return dbQueryAll($sql, [$dispatcherId, $startDate, $endDate]);
    }

    /**
     * Check for HOS violations
     */
    public static function checkViolations($dispatcherId, $startDate, $endDate) {
        $hours = self::getHoursBreakdown($dispatcherId, $startDate, $endDate);

        $violations = [];
        $previousShift = null;

        foreach ($hours as $shift) {
            // Check if shift exceeds max duty hours
            if ($shift['hours_worked'] > self::MAX_DUTY_HOURS) {
                $violations[] = [
                    'date' => $shift['work_date'],
                    'type' => 'max_duty_exceeded',
                    'hours' => $shift['hours_worked'],
                    'max_allowed' => self::MAX_DUTY_HOURS,
                    'message' => "Worked {$shift['hours_worked']} hours, exceeds {self::MAX_DUTY_HOURS} hour maximum"
                ];
            }

            // Check rest period between shifts
            if ($previousShift) {
                $prevEnd = strtotime($previousShift['work_date'] . ' ' . $previousShift['actual_end_time']);
                $currStart = strtotime($shift['work_date'] . ' ' . $shift['actual_start_time']);

                $restHours = ($currStart - $prevEnd) / 3600;

                if ($restHours < self::MIN_REST_HOURS) {
                    $violations[] = [
                        'date' => $shift['work_date'],
                        'type' => 'insufficient_rest',
                        'rest_hours' => $restHours,
                        'min_required' => self::MIN_REST_HOURS,
                        'message' => "Only {$restHours} hours rest, requires {self::MIN_REST_HOURS} hours minimum"
                    ];
                }
            }

            $previousShift = $shift;
        }

        return $violations;
    }

    /**
     * Get availability status for display
     */
    public static function getAvailabilityStatus($dispatcherId) {
        $lastShift = self::getLastShift($dispatcherId);

        if (!$lastShift) {
            return [
                'available' => true,
                'status' => 'available',
                'message' => 'No recent shifts, available now',
                'available_at' => date('Y-m-d H:i:s')
            ];
        }

        $nextAvailable = self::getNextAvailableTime($dispatcherId);
        $now = time();
        $availableTime = strtotime($nextAvailable);

        $isAvailable = $availableTime <= $now;

        if ($isAvailable) {
            return [
                'available' => true,
                'status' => 'available',
                'message' => 'Available now',
                'available_at' => $nextAvailable,
                'last_shift_end' => $lastShift['work_date'] . ' ' . $lastShift['actual_end_time']
            ];
        } else {
            $hoursUntilAvailable = ($availableTime - $now) / 3600;
            return [
                'available' => false,
                'status' => 'on_rest',
                'message' => sprintf('On required rest, available in %.1f hours', $hoursUntilAvailable),
                'available_at' => $nextAvailable,
                'hours_until_available' => $hoursUntilAvailable,
                'last_shift_end' => $lastShift['work_date'] . ' ' . $lastShift['actual_end_time']
            ];
        }
    }

    /**
     * Simulate adding a shift to check if it would cause violations
     */
    public static function wouldCauseViolation($dispatcherId, $date, $shift) {
        return !self::isAvailableForShift($dispatcherId, $date, $shift);
    }

    /**
     * Get shift duration for different shift types
     */
    public static function getShiftDuration($shift, $isACDShift = false) {
        if ($isACDShift) {
            return self::MAX_DUTY_HOURS_ACD;
        }

        return self::SHIFT_TIMES[$shift]['hours'];
    }

    /**
     * Calculate theoretical shift end time
     */
    public static function calculateShiftEndTime($date, $shift, $isACDShift = false) {
        $shiftInfo = self::SHIFT_TIMES[$shift];
        $startTime = strtotime($date . ' ' . $shiftInfo['start']);

        $duration = $isACDShift ? self::MAX_DUTY_HOURS_ACD : $shiftInfo['hours'];
        $endTime = $startTime + ($duration * 3600);

        return date('Y-m-d H:i:s', $endTime);
    }
}
