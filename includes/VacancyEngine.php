<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Dispatcher.php';

class VacancyEngine {

    private $decisionLog = [];
    private $ebBaseline = 0;

    public function __construct() {
        // Load EB baseline from config
        $sql = "SELECT config_value FROM system_config WHERE config_key = 'eb_baseline_count'";
        $result = dbQueryOne($sql);
        $this->ebBaseline = (int)$result['config_value'];
    }

    /**
     * Fill a vacancy using the order of call procedure
     * Returns: array with fill details or null if cannot fill
     */
    public function fillVacancy($vacancyId) {
        $vacancy = $this->getVacancy($vacancyId);
        if (!$vacancy) {
            throw new Exception("Vacancy not found");
        }

        $this->decisionLog = [];
        $this->log("Starting order of call for Vacancy #{$vacancyId}");
        $this->log("Desk: {$vacancy['desk_name']}, Shift: {$vacancy['shift']}, Date: {$vacancy['vacancy_date']}");

        $shiftStartTime = $this->getShiftStartTime($vacancy['vacancy_date'], $vacancy['shift']);

        // 4.1 - Check GAD/Extra Board
        $result = $this->checkExtraBoard($vacancy, $shiftStartTime);
        if ($result) {
            return $this->recordFill($vacancyId, $result);
        }

        // 4.2 - Regular incumbent on rest day (overtime)
        $result = $this->checkIncumbentRestDay($vacancy, $shiftStartTime);
        if ($result) {
            return $this->recordFill($vacancyId, $result);
        }

        // 4.3 - Senior available dispatcher on rest day (overtime)
        $result = $this->checkSeniorRestDay($vacancy, $shiftStartTime);
        if ($result) {
            return $this->recordFill($vacancyId, $result);
        }

        // 4.4 - Junior diversion (same shift, with EB backfill)
        $result = $this->checkJuniorDiversionWithEB($vacancy, $shiftStartTime);
        if ($result) {
            return $this->recordFill($vacancyId, $result);
        }

        // 4.5 - Junior diversion (same shift, no EB backfill - cascading)
        $result = $this->checkJuniorDiversionNoEB($vacancy, $shiftStartTime);
        if ($result) {
            return $this->recordFill($vacancyId, $result);
        }

        // 4.6 - Senior diversion (off shift, with EB backfill, overtime)
        $result = $this->checkSeniorDiversionOffShift($vacancy, $shiftStartTime);
        if ($result) {
            return $this->recordFill($vacancyId, $result);
        }

        // 4.7 - Fallback: least overtime cost
        $result = $this->fallbackLeastCost($vacancy, $shiftStartTime);
        if ($result) {
            return $this->recordFill($vacancyId, $result);
        }

        $this->log("CRITICAL: Unable to fill vacancy - no options available");
        return null;
    }

    /**
     * 4.1 - Check GAD/Extra Board
     */
    private function checkExtraBoard($vacancy, $shiftStartTime) {
        $this->log("4.1 - Checking Extra Board...");

        // Get all EB dispatchers qualified for this desk
        $sql = "SELECT d.*
                FROM dispatchers d
                JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                WHERE d.classification = 'extra_board'
                    AND d.active = 1
                    AND dq.desk_id = ?
                    AND dq.qualified = 1
                ORDER BY d.seniority_rank";
        $ebList = dbQueryAll($sql, [$vacancy['desk_id']]);

        foreach ($ebList as $eb) {
            // Check FRA availability
            if (!Dispatcher::canWorkShift($eb['id'], $shiftStartTime)) {
                $this->log("  - EB {$eb['first_name']} {$eb['last_name']} not available (FRA rest)");
                continue;
            }

            // Check if they're already scheduled
            if ($this->isDispatcherScheduled($eb['id'], $vacancy['vacancy_date'])) {
                $this->log("  - EB {$eb['first_name']} {$eb['last_name']} already scheduled");
                continue;
            }

            $this->log("  ✓ Found qualified EB: {$eb['first_name']} {$eb['last_name']}");
            return [
                'dispatcher_id' => $eb['id'],
                'fill_method' => 'eb_qualified',
                'pay_type' => 'straight',
                'created_cascade_vacancy' => false
            ];
        }

        // Check if we can use a qualifying EB (carrier's option)
        $sql = "SELECT d.*
                FROM dispatchers d
                JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                WHERE d.classification = 'qualifying'
                    AND d.active = 1
                    AND dq.desk_id = ?
                ORDER BY d.seniority_rank";
        $qualifyingList = dbQueryAll($sql, [$vacancy['desk_id']]);

        foreach ($qualifyingList as $qualifier) {
            if (!Dispatcher::canWorkShift($qualifier['id'], $shiftStartTime)) {
                continue;
            }
            if ($this->isDispatcherScheduled($qualifier['id'], $vacancy['vacancy_date'])) {
                continue;
            }

            $this->log("  ⚠ Option: Use qualifying dispatcher {$qualifier['first_name']} {$qualifier['last_name']} (carrier discretion)");
            // Note: This requires manual approval - return as option but don't auto-fill
            // For now, we'll skip and continue to next step
        }

        $this->log("  ✗ No qualified EB available");
        return null;
    }

    /**
     * 4.2 - Regular incumbent on rest day (overtime)
     */
    private function checkIncumbentRestDay($vacancy, $shiftStartTime) {
        $this->log("4.2 - Checking incumbent on rest day...");

        // Get the regular incumbent for this desk+shift
        $sql = "SELECT d.*
                FROM dispatchers d
                JOIN job_assignments ja ON d.id = ja.dispatcher_id
                WHERE ja.desk_id = ?
                    AND ja.shift = ?
                    AND ja.assignment_type = 'regular'
                    AND ja.end_date IS NULL
                LIMIT 1";
        $incumbent = dbQueryOne($sql, [$vacancy['desk_id'], $vacancy['shift']]);

        if (!$incumbent) {
            $this->log("  ✗ No incumbent assigned to this position");
            return null;
        }

        // Check if this is their rest day
        if ($this->isDispatcherScheduled($incumbent['id'], $vacancy['vacancy_date'])) {
            $this->log("  ✗ Incumbent {$incumbent['first_name']} {$incumbent['last_name']} is working this day");
            return null;
        }

        // Check FRA availability
        if (!Dispatcher::canWorkShift($incumbent['id'], $shiftStartTime)) {
            $this->log("  ✗ Incumbent not available (FRA rest)");
            return null;
        }

        $this->log("  ✓ Incumbent {$incumbent['first_name']} {$incumbent['last_name']} available on rest day (OT)");
        return [
            'dispatcher_id' => $incumbent['id'],
            'fill_method' => 'incumbent_overtime',
            'pay_type' => 'overtime',
            'created_cascade_vacancy' => false
        ];
    }

    /**
     * 4.3 - Senior available dispatcher on rest day (overtime)
     */
    private function checkSeniorRestDay($vacancy, $shiftStartTime) {
        $this->log("4.3 - Checking senior dispatchers on rest day...");

        // Get all qualified dispatchers, ordered by seniority (most senior first)
        $sql = "SELECT d.*
                FROM dispatchers d
                JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                WHERE d.active = 1
                    AND d.classification != 'qualifying'
                    AND dq.desk_id = ?
                    AND dq.qualified = 1
                ORDER BY d.seniority_rank ASC";
        $qualified = dbQueryAll($sql, [$vacancy['desk_id']]);

        foreach ($qualified as $dispatcher) {
            // Skip if they're scheduled to work this day
            if ($this->isDispatcherScheduled($dispatcher['id'], $vacancy['vacancy_date'])) {
                continue;
            }

            // Check FRA availability
            if (!Dispatcher::canWorkShift($dispatcher['id'], $shiftStartTime)) {
                continue;
            }

            $this->log("  ✓ Senior dispatcher {$dispatcher['first_name']} {$dispatcher['last_name']} available on rest day (OT)");
            return [
                'dispatcher_id' => $dispatcher['id'],
                'fill_method' => 'senior_restday_overtime',
                'pay_type' => 'overtime',
                'created_cascade_vacancy' => false
            ];
        }

        $this->log("  ✗ No senior dispatchers available on rest day");
        return null;
    }

    /**
     * 4.4 - Junior diversion (same shift, with EB backfill)
     */
    private function checkJuniorDiversionWithEB($vacancy, $shiftStartTime) {
        $this->log("4.4 - Checking junior diversion (same shift, with EB backfill)...");

        // Get dispatchers working the same shift on this date, ordered by seniority (junior first)
        $sql = "SELECT d.*, ja.desk_id as working_desk_id
                FROM dispatchers d
                JOIN job_assignments ja ON d.id = ja.dispatcher_id
                WHERE ja.shift = ?
                    AND ja.end_date IS NULL
                    AND d.active = 1
                    AND d.classification != 'qualifying'
                ORDER BY d.seniority_rank DESC";
        $workingDispatchers = dbQueryAll($sql, [$vacancy['shift']]);

        foreach ($workingDispatchers as $dispatcher) {
            // Check if qualified for the vacancy desk
            if (!Dispatcher::isQualified($dispatcher['id'], $vacancy['desk_id'])) {
                continue;
            }

            // Check if they're scheduled to work (not on rest day)
            if (!$this->isDispatcherScheduled($dispatcher['id'], $vacancy['vacancy_date'])) {
                continue;
            }

            // Check if an EB can backfill their position
            $canBackfill = $this->canEBBackfill($dispatcher['working_desk_id'], $vacancy['shift'], $vacancy['vacancy_date'], $shiftStartTime);

            if ($canBackfill) {
                $ebCount = $this->getCurrentEBCount();
                $payType = ($ebCount < $this->ebBaseline) ? 'overtime' : 'straight';

                $this->log("  ✓ Junior dispatcher {$dispatcher['first_name']} {$dispatcher['last_name']} can be diverted, EB can backfill ({$payType})");
                return [
                    'dispatcher_id' => $dispatcher['id'],
                    'fill_method' => 'junior_diversion_same_shift_with_eb',
                    'pay_type' => $payType,
                    'created_cascade_vacancy' => true,
                    'cascade_desk_id' => $dispatcher['working_desk_id'],
                    'cascade_shift' => $vacancy['shift']
                ];
            }
        }

        $this->log("  ✗ No junior diversions possible with EB backfill");
        return null;
    }

    /**
     * 4.5 - Junior diversion (same shift, no EB backfill - cascading)
     */
    private function checkJuniorDiversionNoEB($vacancy, $shiftStartTime) {
        $this->log("4.5 - Checking junior diversion (same shift, cascading)...");

        // Get dispatchers working the same shift on this date, ordered by seniority (junior first)
        $sql = "SELECT d.*, ja.desk_id as working_desk_id
                FROM dispatchers d
                JOIN job_assignments ja ON d.id = ja.dispatcher_id
                WHERE ja.shift = ?
                    AND ja.end_date IS NULL
                    AND d.active = 1
                    AND d.classification != 'qualifying'
                ORDER BY d.seniority_rank DESC";
        $workingDispatchers = dbQueryAll($sql, [$vacancy['shift']]);

        foreach ($workingDispatchers as $dispatcher) {
            // Check if qualified for the vacancy desk
            if (!Dispatcher::isQualified($dispatcher['id'], $vacancy['desk_id'])) {
                continue;
            }

            // Check if they're scheduled to work (not on rest day)
            if (!$this->isDispatcherScheduled($dispatcher['id'], $vacancy['vacancy_date'])) {
                continue;
            }

            $this->log("  ✓ Junior dispatcher {$dispatcher['first_name']} {$dispatcher['last_name']} can be diverted (creates cascading vacancy)");
            return [
                'dispatcher_id' => $dispatcher['id'],
                'fill_method' => 'junior_diversion_same_shift_no_eb',
                'pay_type' => 'straight',
                'created_cascade_vacancy' => true,
                'cascade_desk_id' => $dispatcher['working_desk_id'],
                'cascade_shift' => $vacancy['shift']
            ];
        }

        $this->log("  ✗ No junior diversions possible");
        return null;
    }

    /**
     * 4.6 - Senior diversion (off shift, with EB backfill, overtime)
     */
    private function checkSeniorDiversionOffShift($vacancy, $shiftStartTime) {
        $this->log("4.6 - Checking senior diversion (off shift, with EB backfill, OT)...");

        // Get dispatchers working OTHER shifts on this date, ordered by seniority (senior first)
        $sql = "SELECT d.*, ja.desk_id as working_desk_id, ja.shift as working_shift
                FROM dispatchers d
                JOIN job_assignments ja ON d.id = ja.dispatcher_id
                WHERE ja.shift != ?
                    AND ja.end_date IS NULL
                    AND d.active = 1
                    AND d.classification != 'qualifying'
                ORDER BY d.seniority_rank ASC";
        $workingDispatchers = dbQueryAll($sql, [$vacancy['shift']]);

        foreach ($workingDispatchers as $dispatcher) {
            // Check if qualified for the vacancy desk
            if (!Dispatcher::isQualified($dispatcher['id'], $vacancy['desk_id'])) {
                continue;
            }

            // Check if they're scheduled to work (not on rest day)
            if (!$this->isDispatcherScheduled($dispatcher['id'], $vacancy['vacancy_date'])) {
                continue;
            }

            // Check FRA - can they work both shifts in same day? (No - 9hr max)
            // Off-shift diversion means they work their regular + the vacancy = not possible same day
            // So this would need to be their only shift that day
            $this->log("  ⚠ Off-shift diversion logic: Would need dispatcher to work different shift than assigned");
            // For now, skip this - it's complex and may violate FRA
            continue;

            // Check if an EB can backfill their position
            $workingShiftStart = $this->getShiftStartTime($vacancy['vacancy_date'], $dispatcher['working_shift']);
            $canBackfill = $this->canEBBackfill($dispatcher['working_desk_id'], $dispatcher['working_shift'], $vacancy['vacancy_date'], $workingShiftStart);

            if ($canBackfill) {
                $this->log("  ✓ Senior dispatcher {$dispatcher['first_name']} {$dispatcher['last_name']} can be diverted off-shift (OT)");
                return [
                    'dispatcher_id' => $dispatcher['id'],
                    'fill_method' => 'senior_diversion_off_shift_overtime',
                    'pay_type' => 'overtime',
                    'created_cascade_vacancy' => true,
                    'cascade_desk_id' => $dispatcher['working_desk_id'],
                    'cascade_shift' => $dispatcher['working_shift']
                ];
            }
        }

        $this->log("  ✗ No senior off-shift diversions possible");
        return null;
    }

    /**
     * 4.7 - Fallback: least overtime cost
     */
    private function fallbackLeastCost($vacancy, $shiftStartTime) {
        $this->log("4.7 - Fallback: Finding least overtime cost...");

        // Get all qualified dispatchers not scheduled this day
        $sql = "SELECT d.*
                FROM dispatchers d
                JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                WHERE d.active = 1
                    AND dq.desk_id = ?
                    AND dq.qualified = 1
                ORDER BY d.seniority_rank ASC";
        $qualified = dbQueryAll($sql, [$vacancy['desk_id']]);

        foreach ($qualified as $dispatcher) {
            if ($this->isDispatcherScheduled($dispatcher['id'], $vacancy['vacancy_date'])) {
                continue;
            }

            if (!Dispatcher::canWorkShift($dispatcher['id'], $shiftStartTime)) {
                continue;
            }

            $this->log("  ✓ Fallback: {$dispatcher['first_name']} {$dispatcher['last_name']} (OT)");
            return [
                'dispatcher_id' => $dispatcher['id'],
                'fill_method' => 'fallback_least_cost',
                'pay_type' => 'overtime',
                'created_cascade_vacancy' => false
            ];
        }

        return null;
    }

    /**
     * Check if EB can backfill a position
     */
    private function canEBBackfill($deskId, $shift, $date, $shiftStartTime) {
        $sql = "SELECT d.*
                FROM dispatchers d
                JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                WHERE d.classification = 'extra_board'
                    AND d.active = 1
                    AND dq.desk_id = ?
                    AND dq.qualified = 1
                ORDER BY d.seniority_rank";
        $ebList = dbQueryAll($sql, [$deskId]);

        foreach ($ebList as $eb) {
            if (!Dispatcher::canWorkShift($eb['id'], $shiftStartTime)) {
                continue;
            }
            if ($this->isDispatcherScheduled($eb['id'], $date)) {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * Check if dispatcher is scheduled to work on a given date
     */
    private function isDispatcherScheduled($dispatcherId, $date) {
        $sql = "SELECT COUNT(*) as count
                FROM assignment_log
                WHERE dispatcher_id = ? AND work_date = ?";
        $result = dbQueryOne($sql, [$dispatcherId, $date]);
        return $result['count'] > 0;
    }

    /**
     * Get current Extra Board count
     */
    private function getCurrentEBCount() {
        $sql = "SELECT COUNT(*) as count
                FROM dispatchers
                WHERE classification = 'extra_board' AND active = 1";
        $result = dbQueryOne($sql);
        return $result['count'];
    }

    /**
     * Get shift start time for a given date and shift
     */
    private function getShiftStartTime($date, $shift) {
        $times = [
            'first' => '06:00:00',
            'second' => '14:00:00',
            'third' => '22:00:00'
        ];
        return $date . ' ' . $times[$shift];
    }

    /**
     * Get vacancy details
     */
    private function getVacancy($vacancyId) {
        $sql = "SELECT v.*, d.name as desk_name
                FROM vacancies v
                JOIN desks d ON v.desk_id = d.id
                WHERE v.id = ?";
        return dbQueryOne($sql, [$vacancyId]);
    }

    /**
     * Record the fill in the database
     */
    private function recordFill($vacancyId, $fillDetails) {
        dbBeginTransaction();
        try {
            // Insert vacancy fill record
            $sql = "INSERT INTO vacancy_fills
                    (vacancy_id, filled_by_dispatcher_id, fill_method, pay_type, created_cascade_vacancy, decision_log, filled_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $fillId = dbInsert($sql, [
                $vacancyId,
                $fillDetails['dispatcher_id'],
                $fillDetails['fill_method'],
                $fillDetails['pay_type'],
                $fillDetails['created_cascade_vacancy'] ? 1 : 0,
                json_encode($this->decisionLog)
            ]);

            // Update vacancy status
            $sql = "UPDATE vacancies SET status = 'filled' WHERE id = ?";
            dbExecute($sql, [$vacancyId]);

            // If cascading vacancy created, create it
            if ($fillDetails['created_cascade_vacancy']) {
                $vacancy = $this->getVacancy($vacancyId);
                $sql = "INSERT INTO vacancies (desk_id, shift, vacancy_date, vacancy_type, status, is_planned)
                        VALUES (?, ?, ?, 'other', 'pending', 0)";
                $cascadeId = dbInsert($sql, [
                    $fillDetails['cascade_desk_id'],
                    $fillDetails['cascade_shift'],
                    $vacancy['vacancy_date']
                ]);

                // Link the cascade
                $sql = "UPDATE vacancy_fills SET cascade_vacancy_id = ? WHERE id = ?";
                dbExecute($sql, [$cascadeId, $fillId]);

                $fillDetails['cascade_vacancy_id'] = $cascadeId;
            }

            dbCommit();
            $fillDetails['fill_id'] = $fillId;
            $fillDetails['decision_log'] = $this->decisionLog;
            return $fillDetails;
        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Log a decision step
     */
    private function log($message) {
        $this->decisionLog[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message
        ];
    }

    /**
     * Get decision log
     */
    public function getDecisionLog() {
        return $this->decisionLog;
    }
}
