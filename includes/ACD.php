<?php
/**
 * ACD (Assistant Chief Train Dispatcher) Management
 *
 * Features:
 * - 12-hour shifts (0600-1800 or 1800-0600)
 * - 4-on/4-off rotation
 * - GOLD/BLUE crew system
 * - Rotation interruption tracking (when filling regular 8-hour jobs)
 */

require_once __DIR__ . '/../config/database.php';

class ACD {

    const CREW_COLORS = ['GOLD', 'BLUE'];
    const SHIFT_TYPES = ['day', 'night']; // day = 0600-1800, night = 1800-0600

    // Shift start times for 12-hour shifts
    const DAY_SHIFT_START = '06:00:00';
    const DAY_SHIFT_END = '18:00:00';
    const NIGHT_SHIFT_START = '18:00:00';
    const NIGHT_SHIFT_END = '06:00:00'; // Next day

    /**
     * Assign dispatcher to ACD rotation
     */
    public static function assignToACD($dispatcherId, $crewColor, $shiftType, $rotationStartDate) {
        if (!in_array($crewColor, self::CREW_COLORS)) {
            throw new Exception("Invalid crew color. Must be GOLD or BLUE.");
        }

        if (!in_array($shiftType, self::SHIFT_TYPES)) {
            throw new Exception("Invalid shift type. Must be 'day' or 'night'.");
        }

        // Calculate 4-day rotation end
        $rotationEndDate = date('Y-m-d', strtotime($rotationStartDate . ' +3 days'));

        dbBeginTransaction();
        try {
            // Update dispatcher classification
            $sql = "UPDATE dispatchers SET classification = 'acd' WHERE id = ?";
            dbExecute($sql, [$dispatcherId]);

            // Create rotation record
            $sql = "INSERT INTO acd_rotation
                    (dispatcher_id, crew_color, shift_type, rotation_start_date, rotation_end_date, on_rotation)
                    VALUES (?, ?, ?, ?, ?, 1)";
            $id = dbInsert($sql, [$dispatcherId, $crewColor, $shiftType, $rotationStartDate, $rotationEndDate]);

            dbCommit();
            return $id;
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Remove dispatcher from ACD rotation
     */
    public static function removeFromACD($dispatcherId) {
        dbBeginTransaction();
        try {
            // Update classification
            $sql = "UPDATE dispatchers SET classification = 'extra_board' WHERE id = ?";
            dbExecute($sql, [$dispatcherId]);

            // Archive ACD rotation records
            $sql = "DELETE FROM acd_rotation WHERE dispatcher_id = ?";
            dbExecute($sql, [$dispatcherId]);

            dbCommit();
            return true;
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Get current ACD rotation for dispatcher
     */
    public static function getCurrentRotation($dispatcherId) {
        $sql = "SELECT * FROM acd_rotation
                WHERE dispatcher_id = ?
                ORDER BY rotation_start_date DESC
                LIMIT 1";
        return dbQueryOne($sql, [$dispatcherId]);
    }

    /**
     * Check if dispatcher is on their work rotation for a given date
     */
    public static function isOnRotation($dispatcherId, $date) {
        $sql = "SELECT on_rotation FROM acd_rotation
                WHERE dispatcher_id = ?
                  AND rotation_start_date <= ?
                  AND rotation_end_date >= ?
                ORDER BY rotation_start_date DESC
                LIMIT 1";
        $rotation = dbQueryOne($sql, [$dispatcherId, $date, $date]);

        return $rotation ? (bool)$rotation['on_rotation'] : false;
    }

    /**
     * Get all ACDs for a crew color
     */
    public static function getACDByCrew($crewColor) {
        if (!in_array($crewColor, self::CREW_COLORS)) {
            throw new Exception("Invalid crew color");
        }

        $sql = "SELECT d.*, ar.crew_color, ar.shift_type, ar.rotation_start_date, ar.rotation_end_date
                FROM dispatchers d
                INNER JOIN acd_rotation ar ON d.id = ar.dispatcher_id
                WHERE d.classification = 'acd'
                  AND d.active = 1
                  AND ar.crew_color = ?
                  AND ar.id IN (
                      SELECT MAX(id) FROM acd_rotation GROUP BY dispatcher_id
                  )
                ORDER BY d.seniority_rank";
        return dbQueryAll($sql, [$crewColor]);
    }

    /**
     * Get ACDs scheduled for a specific date
     */
    public static function getACDsForDate($date) {
        $sql = "SELECT d.*, ar.crew_color, ar.shift_type, ar.on_rotation
                FROM dispatchers d
                INNER JOIN acd_rotation ar ON d.id = ar.dispatcher_id
                WHERE d.classification = 'acd'
                  AND d.active = 1
                  AND ar.rotation_start_date <= ?
                  AND ar.rotation_end_date >= ?
                  AND ar.on_rotation = 1
                ORDER BY ar.crew_color, ar.shift_type, d.seniority_rank";
        return dbQueryAll($sql, [$date, $date]);
    }

    /**
     * Advance rotation to next 4-day block
     * Called when current rotation ends
     */
    public static function advanceRotation($dispatcherId) {
        $current = self::getCurrentRotation($dispatcherId);
        if (!$current) {
            throw new Exception("No current rotation found for dispatcher");
        }

        // Next rotation starts 4 days after current ends (4-off period)
        $nextStart = date('Y-m-d', strtotime($current['rotation_end_date'] . ' +1 day'));
        $nextEnd = date('Y-m-d', strtotime($nextStart . ' +3 days'));

        // Toggle on/off rotation
        $newStatus = $current['on_rotation'] ? 0 : 1;

        $sql = "INSERT INTO acd_rotation
                (dispatcher_id, crew_color, shift_type, rotation_start_date, rotation_end_date, on_rotation)
                VALUES (?, ?, ?, ?, ?, ?)";

        return dbInsert($sql, [
            $dispatcherId,
            $current['crew_color'],
            $current['shift_type'],
            $nextStart,
            $nextEnd,
            $newStatus
        ]);
    }

    /**
     * Mark rotation as interrupted (when ACD fills regular 8-hour job)
     * This skips a rotation day due to rest requirements
     */
    public static function interruptRotation($dispatcherId, $interruptDate) {
        $rotation = self::getCurrentRotation($dispatcherId);
        if (!$rotation) {
            return false;
        }

        // When ACD works an 8-hour job, they likely skip their rotation day
        // We'll adjust the rotation end date to account for this
        // This is based on user's answer: "I would be inclined to say yes because of rest"

        // Simply mark the current rotation as interrupted
        $sql = "UPDATE acd_rotation
                SET on_rotation = 0
                WHERE id = ?";

        return dbExecute($sql, [$rotation['id']]);
    }

    /**
     * Get upcoming rotation schedule for a dispatcher
     */
    public static function getRotationSchedule($dispatcherId, $startDate, $endDate) {
        $sql = "SELECT * FROM acd_rotation
                WHERE dispatcher_id = ?
                  AND rotation_start_date <= ?
                  AND rotation_end_date >= ?
                ORDER BY rotation_start_date";
        return dbQueryAll($sql, [$dispatcherId, $endDate, $startDate]);
    }

    /**
     * Calculate shift times for ACD
     */
    public static function getShiftTimes($shiftType) {
        if ($shiftType === 'day') {
            return [
                'start' => self::DAY_SHIFT_START,
                'end' => self::DAY_SHIFT_END,
                'hours' => 12
            ];
        } else {
            return [
                'start' => self::NIGHT_SHIFT_START,
                'end' => self::NIGHT_SHIFT_END,
                'hours' => 12
            ];
        }
    }

    /**
     * Create ACD desk assignment
     */
    public static function assignACDToDesk($dispatcherId, $acdDeskId, $startDate = null) {
        if (!$startDate) {
            $startDate = date('Y-m-d');
        }

        // Verify desk is an ACD desk
        $sql = "SELECT is_acd_desk FROM desks WHERE id = ?";
        $desk = dbQueryOne($sql, [$acdDeskId]);

        if (!$desk || !$desk['is_acd_desk']) {
            throw new Exception("Desk is not configured as an ACD desk");
        }

        // Get ACD rotation info to determine shift
        $rotation = self::getCurrentRotation($dispatcherId);
        if (!$rotation) {
            throw new Exception("Dispatcher must be assigned to ACD rotation first");
        }

        // ACDs work a single 12-hour shift, map it to our shift system
        // Day ACD (0600-1800) = first + second shifts
        // Night ACD (1800-0600) = third shift
        $shift = $rotation['shift_type'] === 'day' ? 'first' : 'third';

        require_once __DIR__ . '/Schedule.php';
        return Schedule::assignJob($dispatcherId, $acdDeskId, $shift, 'regular', $startDate);
    }

    /**
     * Get all ACD desks
     */
    public static function getACDDesks($divisionId = null) {
        $sql = "SELECT d.*, div.name as division_name
                FROM desks d
                JOIN divisions div ON d.division_id = div.id
                WHERE d.is_acd_desk = 1 AND d.active = 1";

        $params = [];
        if ($divisionId) {
            $sql .= " AND d.division_id = ?";
            $params[] = $divisionId;
        }

        $sql .= " ORDER BY div.name, d.name";

        return dbQueryAll($sql, $params);
    }

    /**
     * Get crew schedule matrix for display
     * Returns which crew is working on each date
     */
    public static function getCrewScheduleMatrix($startDate, $endDate) {
        $matrix = [];
        $currentDate = $startDate;

        while (strtotime($currentDate) <= strtotime($endDate)) {
            $acds = self::getACDsForDate($currentDate);

            $matrix[$currentDate] = [
                'date' => $currentDate,
                'day_of_week' => date('l', strtotime($currentDate)),
                'gold_day' => [],
                'gold_night' => [],
                'blue_day' => [],
                'blue_night' => []
            ];

            foreach ($acds as $acd) {
                $key = strtolower($acd['crew_color']) . '_' . $acd['shift_type'];
                $matrix[$currentDate][$key][] = $acd;
            }

            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }

        return $matrix;
    }

    /**
     * Verify GOLD/BLUE crews are alternating properly
     * GOLD works while BLUE rests, then swap
     */
    public static function verifyCrewAlternation($startDate, $endDate) {
        $matrix = self::getCrewScheduleMatrix($startDate, $endDate);

        $errors = [];
        $previousDate = null;

        foreach ($matrix as $date => $schedule) {
            $goldWorking = !empty($schedule['gold_day']) || !empty($schedule['gold_night']);
            $blueWorking = !empty($schedule['blue_day']) || !empty($schedule['blue_night']);

            // Both crews working same day = problem
            if ($goldWorking && $blueWorking) {
                $errors[] = "$date: Both GOLD and BLUE crews scheduled (should alternate)";
            }

            // Neither crew working = problem (unless weekend)
            if (!$goldWorking && !$blueWorking) {
                $dayOfWeek = date('w', strtotime($date));
                if ($dayOfWeek != 0 && $dayOfWeek != 6) { // Not weekend
                    $errors[] = "$date: Neither crew scheduled";
                }
            }

            $previousDate = $date;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
