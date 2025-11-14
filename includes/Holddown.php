<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Dispatcher.php';

class Holddown {

    /**
     * Post a hold-down for bidding
     */
    public static function post($deskId, $shift, $startDate, $endDate, $incumbentDispatcherId, $notes = '') {
        $sql = "INSERT INTO holddowns (desk_id, shift, start_date, end_date, incumbent_dispatcher_id, posted_date, notes)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)";
        return dbInsert($sql, [$deskId, $shift, $startDate, $endDate, $incumbentDispatcherId, $notes]);
    }

    /**
     * Submit a bid for a hold-down
     */
    public static function submitBid($holddownId, $dispatcherId, $notes = '') {
        // Check if hold-down is still open for bidding
        $holddown = self::getById($holddownId);
        if (!$holddown || $holddown['status'] !== 'posted') {
            throw new Exception("Hold-down is not available for bidding");
        }

        // Check if dispatcher is qualified
        if (!Dispatcher::isQualified($dispatcherId, $holddown['desk_id'])) {
            throw new Exception("Dispatcher is not qualified for this desk");
        }

        // Check if dispatcher is a qualifier
        $dispatcher = Dispatcher::getById($dispatcherId);
        if ($dispatcher['classification'] === 'qualifying') {
            throw new Exception("Qualifying dispatchers cannot bid on hold-downs");
        }

        $sql = "INSERT INTO holddown_bids (holddown_id, dispatcher_id, bid_timestamp, is_qualified, notes)
                VALUES (?, ?, NOW(), 1, ?)";
        return dbInsert($sql, [$holddownId, $dispatcherId, $notes]);
    }

    /**
     * Award a hold-down to the most senior qualified bidder
     */
    public static function award($holddownId) {
        $holddown = self::getById($holddownId);
        if (!$holddown || $holddown['status'] !== 'posted') {
            throw new Exception("Hold-down cannot be awarded");
        }

        // Get most senior qualified bidder
        $sql = "SELECT hb.*, d.seniority_rank, d.first_name, d.last_name
                FROM holddown_bids hb
                JOIN dispatchers d ON hb.dispatcher_id = d.id
                WHERE hb.holddown_id = ? AND hb.is_qualified = 1
                ORDER BY d.seniority_rank ASC
                LIMIT 1";
        $winner = dbQueryOne($sql, [$holddownId]);

        if (!$winner) {
            throw new Exception("No qualified bidders found");
        }

        dbBeginTransaction();
        try {
            // Check if FRA requires a hold-off day
            $needsHoldoff = self::checkNeedsHoldoffDay($winner['dispatcher_id'], $holddown['start_date'], $holddown['shift']);

            $holdoffDate = null;
            if ($needsHoldoff) {
                // Insert hold-off day (typically the last day before hold-down starts)
                $holdoffDate = date('Y-m-d', strtotime($holddown['start_date'] . ' -1 day'));

                // Create a vacancy for the hold-off day
                $sql = "INSERT INTO vacancies (desk_id, shift, vacancy_date, vacancy_type, incumbent_dispatcher_id, status, is_planned)
                        VALUES (?, ?, ?, 'other', ?, 'pending', 0)";
                dbInsert($sql, [
                    $holddown['desk_id'],
                    $holddown['shift'],
                    $holdoffDate,
                    $winner['dispatcher_id']
                ]);
            }

            // Award the hold-down
            $sql = "UPDATE holddowns
                    SET awarded_dispatcher_id = ?, status = 'awarded', award_date = NOW(),
                        needs_holdoff_day = ?, holdoff_date = ?
                    WHERE id = ?";
            dbExecute($sql, [
                $winner['dispatcher_id'],
                $needsHoldoff ? 1 : 0,
                $holdoffDate,
                $holddownId
            ]);

            // Create vacancies for the incumbent's job during the hold-down period
            self::createIncumbentVacancies($holddownId);

            dbCommit();

            return [
                'awarded_to' => $winner['dispatcher_id'],
                'dispatcher_name' => $winner['first_name'] . ' ' . $winner['last_name'],
                'needs_holdoff' => $needsHoldoff,
                'holdoff_date' => $holdoffDate
            ];
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Check if a hold-off day is needed (FRA 9/15 compliance)
     */
    private static function checkNeedsHoldoffDay($dispatcherId, $startDate, $shift) {
        // Get dispatcher's last work before the hold-down start
        $sql = "SELECT actual_end_time
                FROM assignment_log
                WHERE dispatcher_id = ? AND work_date < ?
                ORDER BY work_date DESC, actual_end_time DESC
                LIMIT 1";
        $lastWork = dbQueryOne($sql, [$dispatcherId, $startDate]);

        if (!$lastWork) {
            return false; // No recent work, no hold-off needed
        }

        // Calculate shift start time
        $shiftTimes = [
            'first' => '06:00:00',
            'second' => '14:00:00',
            'third' => '22:00:00'
        ];
        $shiftStart = strtotime($startDate . ' ' . $shiftTimes[$shift]);
        $lastEndTime = strtotime($lastWork['actual_end_time']);

        // Check if at least 15 hours rest
        $hoursRest = ($shiftStart - $lastEndTime) / 3600;

        return $hoursRest < 15;
    }

    /**
     * Create vacancies for the incumbent's regular job during hold-down
     */
    private static function createIncumbentVacancies($holddownId) {
        $holddown = self::getById($holddownId);
        if (!$holddown) {
            throw new Exception("Hold-down not found");
        }

        // Get incumbent's regular assignment
        $sql = "SELECT ja.desk_id, ja.shift
                FROM job_assignments ja
                WHERE ja.dispatcher_id = ? AND ja.end_date IS NULL AND ja.assignment_type = 'regular'
                LIMIT 1";
        $assignment = dbQueryOne($sql, [$holddown['incumbent_dispatcher_id']]);

        if (!$assignment) {
            return; // Incumbent is EB, no vacancies to create
        }

        // Create vacancies for each day of the hold-down that incumbent would normally work
        $currentDate = $holddown['start_date'];
        $endDate = $holddown['end_date'];

        while (strtotime($currentDate) <= strtotime($endDate)) {
            // Check if incumbent would be working this day (not a rest day)
            $dayOfWeek = date('w', strtotime($currentDate));

            // Check if relief covers this day
            $sql = "SELECT id FROM relief_schedules
                    WHERE desk_id = ? AND shift = ? AND day_of_week = ? AND active = 1";
            $reliefCovers = dbQueryOne($sql, [$assignment['desk_id'], $assignment['shift'], $dayOfWeek]);

            if (!$reliefCovers) {
                // Regular incumbent would work this day, create vacancy
                $sql = "INSERT INTO vacancies (desk_id, shift, vacancy_date, vacancy_type, incumbent_dispatcher_id, status, is_planned, posted_as_holddown)
                        VALUES (?, ?, ?, 'other', ?, 'pending', 1, 1)";
                dbInsert($sql, [
                    $assignment['desk_id'],
                    $assignment['shift'],
                    $currentDate,
                    $holddown['awarded_dispatcher_id']
                ]);
            }

            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
    }

    /**
     * Get hold-down by ID
     */
    public static function getById($id) {
        $sql = "SELECT h.*,
                       d.name as desk_name,
                       div.name as division_name,
                       CONCAT(inc.first_name, ' ', inc.last_name) as incumbent_name,
                       CONCAT(awd.first_name, ' ', awd.last_name) as awarded_name
                FROM holddowns h
                JOIN desks d ON h.desk_id = d.id
                JOIN divisions div ON d.division_id = div.id
                JOIN dispatchers inc ON h.incumbent_dispatcher_id = inc.id
                LEFT JOIN dispatchers awd ON h.awarded_dispatcher_id = awd.id
                WHERE h.id = ?";
        return dbQueryOne($sql, [$id]);
    }

    /**
     * Get all hold-downs
     */
    public static function getAll($status = null) {
        $sql = "SELECT h.*,
                       d.name as desk_name,
                       div.name as division_name,
                       CONCAT(inc.first_name, ' ', inc.last_name) as incumbent_name,
                       CONCAT(awd.first_name, ' ', awd.last_name) as awarded_name,
                       (SELECT COUNT(*) FROM holddown_bids WHERE holddown_id = h.id) as bid_count
                FROM holddowns h
                JOIN desks d ON h.desk_id = d.id
                JOIN divisions div ON d.division_id = div.id
                JOIN dispatchers inc ON h.incumbent_dispatcher_id = inc.id
                LEFT JOIN dispatchers awd ON h.awarded_dispatcher_id = awd.id";

        if ($status) {
            $sql .= " WHERE h.status = ?";
            return dbQueryAll($sql, [$status]);
        }

        $sql .= " ORDER BY h.posted_date DESC";
        return dbQueryAll($sql);
    }

    /**
     * Get bids for a hold-down
     */
    public static function getBids($holddownId) {
        $sql = "SELECT hb.*,
                       d.employee_number,
                       CONCAT(d.first_name, ' ', d.last_name) as dispatcher_name,
                       d.seniority_rank,
                       d.classification
                FROM holddown_bids hb
                JOIN dispatchers d ON hb.dispatcher_id = d.id
                WHERE hb.holddown_id = ?
                ORDER BY d.seniority_rank ASC";
        return dbQueryAll($sql, [$holddownId]);
    }

    /**
     * Cancel a hold-down
     */
    public static function cancel($holddownId, $reason = '') {
        $sql = "UPDATE holddowns SET status = 'cancelled', notes = CONCAT(notes, '\nCancelled: ', ?) WHERE id = ?";
        return dbExecute($sql, [$reason, $holddownId]);
    }

    /**
     * Complete a hold-down (when it ends)
     */
    public static function complete($holddownId) {
        $sql = "UPDATE holddowns SET status = 'completed' WHERE id = ?";
        return dbExecute($sql, [$holddownId]);
    }

    /**
     * Get active hold-downs for a dispatcher
     */
    public static function getActiveForDispatcher($dispatcherId) {
        $sql = "SELECT h.*,
                       d.name as desk_name,
                       div.name as division_name
                FROM holddowns h
                JOIN desks d ON h.desk_id = d.id
                JOIN divisions div ON d.division_id = div.id
                WHERE h.awarded_dispatcher_id = ?
                    AND h.status IN ('awarded', 'active')
                    AND h.end_date >= CURDATE()
                ORDER BY h.start_date";
        return dbQueryAll($sql, [$dispatcherId]);
    }
}
