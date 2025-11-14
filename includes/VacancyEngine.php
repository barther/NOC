<?php
/**
 * Vacancy Engine - Contract-Compliant Order-of-Call
 *
 * Implements Article 3(g) - Order of Call for Filling Vacancies
 *
 * CORRECT ORDER-OF-CALL:
 * 1. GAD (Guaranteed Assigned Dispatcher) - if available
 * 2. If no GAD available, then:
 *    a. Incumbent overtime (same desk, regular holder accepts OT)
 *    b. Senior rest day overtime (senior dispatcher on rest day accepts OT)
 *    c. Junior same-shift diversion requiring GAD backfill
 *    d. Junior same-shift diversion (no backfill)
 *    e. Senior off-shift diversion requiring GAD backfill
 *    f. Least overtime cost fallback (actual cost calculation)
 *
 * Also handles:
 * - GAD baseline rules (Appendix 9)
 * - Training protection
 * - FRA hours of service
 * - Cost tracking
 * - Improper diversion penalties
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/GAD.php';
require_once __DIR__ . '/FRAHours.php';
require_once __DIR__ . '/Dispatcher.php';
require_once __DIR__ . '/Desk.php';

class VacancyEngine {

    private $decisionLog = [];

    /**
     * Fill a vacancy using contract-compliant order-of-call
     */
    public function fillVacancy($vacancyId) {
        $vacancy = self::getVacancy($vacancyId);
        if (!$vacancy) {
            throw new Exception("Vacancy not found");
        }

        if ($vacancy['status'] !== 'open') {
            throw new Exception("Vacancy is not open");
        }

        $this->decisionLog = [];
        $this->log("Starting order-of-call for Vacancy #{$vacancyId}");
        $this->log("Desk: {$vacancy['desk_name']}, Shift: {$vacancy['shift']}, Date: {$vacancy['vacancy_date']}");

        dbBeginTransaction();
        try {
            // Step 1: Try GAD first
            $gadOption = $this->tryGAD($vacancy);
            if ($gadOption['available']) {
                $fill = $this->executeFill($vacancy, $gadOption, 1, 'gad');
                dbCommit();
                return $fill;
            }

            // No GAD available, proceed with order-of-call steps 2-6
            // Step 2: Incumbent overtime
            $incumbentOption = $this->tryIncumbentOT($vacancy);
            if ($incumbentOption['available']) {
                $fill = $this->executeFill($vacancy, $incumbentOption, 2, 'incumbent_ot');
                dbCommit();
                return $fill;
            }

            // Step 3: Senior rest day overtime
            $seniorRestOption = $this->trySeniorRestOT($vacancy);
            if ($seniorRestOption['available']) {
                $fill = $this->executeFill($vacancy, $seniorRestOption, 3, 'senior_rest_ot');
                dbCommit();
                return $fill;
            }

            // Step 4: Junior same-shift diversion requiring GAD backfill
            $juniorDiversionGAD = $this->tryJuniorDiversionWithGAD($vacancy);
            if ($juniorDiversionGAD['available']) {
                $fill = $this->executeFill($vacancy, $juniorDiversionGAD, 4, 'junior_diversion_gad');
                dbCommit();
                return $fill;
            }

            // Step 5: Junior same-shift diversion (no GAD backfill)
            $juniorDiversion = $this->tryJuniorDiversion($vacancy);
            if ($juniorDiversion['available']) {
                $fill = $this->executeFill($vacancy, $juniorDiversion, 5, 'junior_diversion');
                dbCommit();
                return $fill;
            }

            // Step 6: Senior off-shift diversion requiring GAD backfill
            $seniorOffShiftGAD = $this->trySeniorOffShiftWithGAD($vacancy);
            if ($seniorOffShiftGAD['available']) {
                $fill = $this->executeFill($vacancy, $seniorOffShiftGAD, 6, 'senior_offshift_gad');
                dbCommit();
                return $fill;
            }

            // Step 7: Least cost fallback (calculate actual costs)
            $leastCostOption = $this->findLeastCostOption($vacancy);
            if ($leastCostOption['available']) {
                $fill = $this->executeFill($vacancy, $leastCostOption, 7, 'least_cost');
                dbCommit();
                return $fill;
            }

            dbRollback();
            $this->log("CRITICAL: Unable to fill vacancy - no options available");
            throw new Exception("No available dispatchers to fill vacancy");

        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }
    }

    /**
     * Step 1: Try to fill with GAD
     */
    private function tryGAD($vacancy) {
        $this->log("Step 1: Checking GAD (Guaranteed Assigned Dispatchers)...");

        // Get available GADs for this desk/date/shift
        $availableGADs = GAD::getAvailableGAD(
            $vacancy['vacancy_date'],
            $vacancy['shift'],
            $vacancy['desk_id']
        );

        if (empty($availableGADs)) {
            $this->log("  ✗ No GAD available");
            return ['available' => false, 'reason' => 'No GAD available'];
        }

        // Use most senior available GAD
        $gad = $availableGADs[0];

        $this->log("  ✓ Found available GAD: {$gad['first_name']} {$gad['last_name']} (Seniority #{$gad['seniority_rank']})");

        return [
            'available' => true,
            'dispatcher_id' => $gad['id'],
            'dispatcher_name' => $gad['first_name'] . ' ' . $gad['last_name'],
            'pay_type' => 'straight',
            'hours' => 8,
            'cost' => $this->calculateCost($gad, 'straight', 8),
            'requires_backfill' => false
        ];
    }

    /**
     * Step 2: Incumbent overtime
     * Offer OT to the regular job holder for this desk/shift
     */
    private function tryIncumbentOT($vacancy) {
        $this->log("Step 2: Checking incumbent overtime...");

        // Get regular assignment for this desk/shift
        $sql = "SELECT d.*, ja.id as assignment_id,
                       dpr.hourly_rate, dpr.overtime_rate
                FROM job_assignments ja
                INNER JOIN dispatchers d ON ja.dispatcher_id = d.id
                LEFT JOIN dispatcher_pay_rates dpr ON d.id = dpr.dispatcher_id
                    AND dpr.effective_date <= ?
                    AND (dpr.end_date IS NULL OR dpr.end_date >= ?)
                WHERE ja.desk_id = ?
                  AND ja.shift = ?
                  AND ja.assignment_type = 'regular'
                  AND ja.end_date IS NULL
                  AND d.active = 1";

        $incumbent = dbQueryOne($sql, [
            $vacancy['vacancy_date'],
            $vacancy['vacancy_date'],
            $vacancy['desk_id'],
            $vacancy['shift']
        ]);

        if (!$incumbent) {
            $this->log("  ✗ No incumbent for this job");
            return ['available' => false, 'reason' => 'No incumbent for this job'];
        }

        // Check if incumbent is available (FRA HOS)
        if (!FRAHours::isAvailableForShift($incumbent['id'], $vacancy['vacancy_date'], $vacancy['shift'])) {
            $this->log("  ✗ Incumbent {$incumbent['first_name']} {$incumbent['last_name']} not available (HOS violation)");
            return ['available' => false, 'reason' => 'Incumbent HOS violation'];
        }

        $this->log("  ✓ Incumbent {$incumbent['first_name']} {$incumbent['last_name']} available for OT");

        return [
            'available' => true,
            'dispatcher_id' => $incumbent['id'],
            'dispatcher_name' => $incumbent['first_name'] . ' ' . $incumbent['last_name'],
            'pay_type' => 'overtime',
            'hours' => 8,
            'cost' => $this->calculateCost($incumbent, 'overtime', 8),
            'requires_backfill' => false
        ];
    }

    /**
     * Step 3: Senior rest day overtime
     * Offer OT to most senior dispatcher who's on rest day
     */
    private function trySeniorRestOT($vacancy) {
        $this->log("Step 3: Checking senior rest day overtime...");

        // Get dispatchers on rest day, qualified for this desk, ordered by seniority
        $sql = "SELECT DISTINCT d.*,
                       dpr.hourly_rate, dpr.overtime_rate
                FROM dispatchers d
                INNER JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                INNER JOIN job_assignments ja ON d.id = ja.dispatcher_id
                INNER JOIN job_rest_days jrd ON ja.id = jrd.job_assignment_id
                LEFT JOIN dispatcher_pay_rates dpr ON d.id = dpr.dispatcher_id
                    AND dpr.effective_date <= ?
                    AND (dpr.end_date IS NULL OR dpr.end_date >= ?)
                WHERE dq.desk_id = ?
                  AND jrd.day_of_week = ?
                  AND ja.end_date IS NULL
                  AND d.active = 1
                  AND d.training_protected = 0
                ORDER BY d.seniority_rank ASC
                LIMIT 1";

        $dayOfWeek = date('w', strtotime($vacancy['vacancy_date']));

        $senior = dbQueryOne($sql, [
            $vacancy['vacancy_date'],
            $vacancy['vacancy_date'],
            $vacancy['desk_id'],
            $dayOfWeek
        ]);

        if (!$senior) {
            $this->log("  ✗ No senior dispatcher on rest day");
            return ['available' => false, 'reason' => 'No senior dispatcher on rest day'];
        }

        // Check FRA HOS
        if (!FRAHours::isAvailableForShift($senior['id'], $vacancy['vacancy_date'], $vacancy['shift'])) {
            $this->log("  ✗ Senior {$senior['first_name']} {$senior['last_name']} not available (HOS violation)");
            return ['available' => false, 'reason' => 'Senior HOS violation'];
        }

        $this->log("  ✓ Senior {$senior['first_name']} {$senior['last_name']} available for rest day OT");

        return [
            'available' => true,
            'dispatcher_id' => $senior['id'],
            'dispatcher_name' => $senior['first_name'] . ' ' . $senior['last_name'],
            'pay_type' => 'overtime',
            'hours' => 8,
            'cost' => $this->calculateCost($senior, 'overtime', 8),
            'requires_backfill' => false
        ];
    }

    /**
     * Step 4: Junior same-shift diversion requiring GAD backfill
     * Move junior dispatcher from another desk on SAME shift, GAD fills their spot
     */
    private function tryJuniorDiversionWithGAD($vacancy) {
        $this->log("Step 4: Checking junior same-shift diversion (with GAD backfill)...");

        // Get most junior dispatcher on same shift at different desk
        $sql = "SELECT DISTINCT d.*, ja.desk_id as current_desk_id, ja.shift as current_shift,
                       dpr.hourly_rate, dpr.overtime_rate
                FROM dispatchers d
                INNER JOIN job_assignments ja ON d.id = ja.dispatcher_id
                INNER JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                LEFT JOIN dispatcher_pay_rates dpr ON d.id = dpr.dispatcher_id
                    AND dpr.effective_date <= ?
                    AND (dpr.end_date IS NULL OR dpr.end_date >= ?)
                WHERE ja.shift = ?
                  AND ja.desk_id != ?
                  AND ja.end_date IS NULL
                  AND ja.assignment_type = 'regular'
                  AND dq.desk_id = ?
                  AND d.active = 1
                  AND d.training_protected = 0
                ORDER BY d.seniority_rank DESC
                LIMIT 1";

        $junior = dbQueryOne($sql, [
            $vacancy['vacancy_date'],
            $vacancy['vacancy_date'],
            $vacancy['shift'],
            $vacancy['desk_id'],
            $vacancy['desk_id']
        ]);

        if (!$junior) {
            $this->log("  ✗ No junior dispatcher on same shift");
            return ['available' => false, 'reason' => 'No junior dispatcher on same shift'];
        }

        // Check if GAD is available to backfill their spot
        $backfillGADs = GAD::getAvailableGAD(
            $vacancy['vacancy_date'],
            $junior['current_shift'],
            $junior['current_desk_id']
        );

        if (empty($backfillGADs)) {
            $this->log("  ✗ No GAD available for backfill");
            return ['available' => false, 'reason' => 'No GAD available for backfill'];
        }

        // Determine pay type based on GAD baseline
        $desk = Desk::getById($vacancy['desk_id']);
        $payType = GAD::getDiversionPayType($desk['division_id'], $vacancy['vacancy_date']);

        $this->log("  ✓ Junior {$junior['first_name']} {$junior['last_name']} can be diverted, GAD backfills ({$payType})");

        return [
            'available' => true,
            'dispatcher_id' => $junior['id'],
            'dispatcher_name' => $junior['first_name'] . ' ' . $junior['last_name'],
            'pay_type' => $payType,
            'hours' => 8,
            'cost' => $this->calculateCost($junior, $payType, 8),
            'requires_backfill' => true,
            'backfill_dispatcher_id' => $backfillGADs[0]['id'],
            'backfill_desk_id' => $junior['current_desk_id'],
            'backfill_shift' => $junior['current_shift']
        ];
    }

    /**
     * Step 5: Junior same-shift diversion (no GAD backfill available)
     */
    private function tryJuniorDiversion($vacancy) {
        $this->log("Step 5: Checking junior same-shift diversion (no GAD backfill)...");

        // Same as step 4 but without GAD backfill requirement
        $sql = "SELECT DISTINCT d.*, ja.desk_id as current_desk_id, ja.shift as current_shift,
                       dpr.hourly_rate, dpr.overtime_rate
                FROM dispatchers d
                INNER JOIN job_assignments ja ON d.id = ja.dispatcher_id
                INNER JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                LEFT JOIN dispatcher_pay_rates dpr ON d.id = dpr.dispatcher_id
                    AND dpr.effective_date <= ?
                    AND (dpr.end_date IS NULL OR dpr.end_date >= ?)
                WHERE ja.shift = ?
                  AND ja.desk_id != ?
                  AND ja.end_date IS NULL
                  AND ja.assignment_type = 'regular'
                  AND dq.desk_id = ?
                  AND d.active = 1
                  AND d.training_protected = 0
                ORDER BY d.seniority_rank DESC
                LIMIT 1";

        $junior = dbQueryOne($sql, [
            $vacancy['vacancy_date'],
            $vacancy['vacancy_date'],
            $vacancy['shift'],
            $vacancy['desk_id'],
            $vacancy['desk_id']
        ]);

        if (!$junior) {
            $this->log("  ✗ No junior dispatcher available");
            return ['available' => false, 'reason' => 'No junior dispatcher available'];
        }

        // Diversion creates a new vacancy at their current desk
        $desk = Desk::getById($vacancy['desk_id']);
        $payType = GAD::getDiversionPayType($desk['division_id'], $vacancy['vacancy_date']);

        $this->log("  ✓ Junior {$junior['first_name']} {$junior['last_name']} can be diverted (creates cascade vacancy)");

        return [
            'available' => true,
            'dispatcher_id' => $junior['id'],
            'dispatcher_name' => $junior['first_name'] . ' ' . $junior['last_name'],
            'pay_type' => $payType,
            'hours' => 8,
            'cost' => $this->calculateCost($junior, $payType, 8),
            'requires_backfill' => false,
            'creates_vacancy' => true,
            'new_vacancy_desk_id' => $junior['current_desk_id'],
            'new_vacancy_shift' => $junior['current_shift']
        ];
    }

    /**
     * Step 6: Senior off-shift diversion with GAD backfill
     */
    private function trySeniorOffShiftWithGAD($vacancy) {
        $this->log("Step 6: Checking senior off-shift diversion (with GAD backfill)...");

        // Get most senior dispatcher on DIFFERENT shift
        $sql = "SELECT DISTINCT d.*, ja.desk_id as current_desk_id, ja.shift as current_shift,
                       dpr.hourly_rate, dpr.overtime_rate
                FROM dispatchers d
                INNER JOIN job_assignments ja ON d.id = ja.dispatcher_id
                INNER JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                LEFT JOIN dispatcher_pay_rates dpr ON d.id = dpr.dispatcher_id
                    AND dpr.effective_date <= ?
                    AND (dpr.end_date IS NULL OR dpr.end_date >= ?)
                WHERE ja.shift != ?
                  AND ja.end_date IS NULL
                  AND ja.assignment_type = 'regular'
                  AND dq.desk_id = ?
                  AND d.active = 1
                  AND d.training_protected = 0
                ORDER BY d.seniority_rank ASC
                LIMIT 1";

        $senior = dbQueryOne($sql, [
            $vacancy['vacancy_date'],
            $vacancy['vacancy_date'],
            $vacancy['shift'],
            $vacancy['desk_id']
        ]);

        if (!$senior) {
            $this->log("  ✗ No senior off-shift dispatcher");
            return ['available' => false, 'reason' => 'No senior off-shift dispatcher'];
        }

        // Check FRA HOS (different shift may violate rest)
        if (!FRAHours::isAvailableForShift($senior['id'], $vacancy['vacancy_date'], $vacancy['shift'])) {
            $this->log("  ✗ Senior {$senior['first_name']} {$senior['last_name']} not available (HOS violation)");
            return ['available' => false, 'reason' => 'Senior HOS violation'];
        }

        // Check if GAD available to backfill
        $backfillGADs = GAD::getAvailableGAD(
            $vacancy['vacancy_date'],
            $senior['current_shift'],
            $senior['current_desk_id']
        );

        if (empty($backfillGADs)) {
            $this->log("  ✗ No GAD available for backfill");
            return ['available' => false, 'reason' => 'No GAD available for backfill'];
        }

        $desk = Desk::getById($vacancy['desk_id']);
        $payType = GAD::getDiversionPayType($desk['division_id'], $vacancy['vacancy_date']);

        $this->log("  ✓ Senior {$senior['first_name']} {$senior['last_name']} can be diverted off-shift, GAD backfills");

        return [
            'available' => true,
            'dispatcher_id' => $senior['id'],
            'dispatcher_name' => $senior['first_name'] . ' ' . $senior['last_name'],
            'pay_type' => $payType,
            'hours' => 8,
            'cost' => $this->calculateCost($senior, $payType, 8),
            'requires_backfill' => true,
            'backfill_dispatcher_id' => $backfillGADs[0]['id'],
            'backfill_desk_id' => $senior['current_desk_id'],
            'backfill_shift' => $senior['current_shift']
        ];
    }

    /**
     * Step 7: Least cost option - Actually calculate costs and pick cheapest
     */
    private function findLeastCostOption($vacancy) {
        $this->log("Step 7: Least cost fallback - calculating actual costs...");

        $options = [];

        // Try all available dispatchers and calculate actual costs
        $sql = "SELECT DISTINCT d.*,
                       dpr.hourly_rate, dpr.overtime_rate
                FROM dispatchers d
                INNER JOIN dispatcher_qualifications dq ON d.id = dq.dispatcher_id
                LEFT JOIN dispatcher_pay_rates dpr ON d.id = dpr.dispatcher_id
                    AND dpr.effective_date <= ?
                    AND (dpr.end_date IS NULL OR dpr.end_date >= ?)
                WHERE dq.desk_id = ?
                  AND d.active = 1
                  AND d.training_protected = 0
                ORDER BY d.seniority_rank";

        $dispatchers = dbQueryAll($sql, [
            $vacancy['vacancy_date'],
            $vacancy['vacancy_date'],
            $vacancy['desk_id']
        ]);

        foreach ($dispatchers as $dispatcher) {
            if (!FRAHours::isAvailableForShift($dispatcher['id'], $vacancy['vacancy_date'], $vacancy['shift'])) {
                continue;
            }

            // Calculate cost for straight time
            $straightCost = $this->calculateCost($dispatcher, 'straight', 8);
            $options[] = [
                'dispatcher_id' => $dispatcher['id'],
                'dispatcher_name' => $dispatcher['first_name'] . ' ' . $dispatcher['last_name'],
                'pay_type' => 'straight',
                'hours' => 8,
                'cost' => $straightCost
            ];

            // Calculate cost for overtime
            $otCost = $this->calculateCost($dispatcher, 'overtime', 8);
            $options[] = [
                'dispatcher_id' => $dispatcher['id'],
                'dispatcher_name' => $dispatcher['first_name'] . ' ' . $dispatcher['last_name'],
                'pay_type' => 'overtime',
                'hours' => 8,
                'cost' => $otCost
            ];
        }

        if (empty($options)) {
            $this->log("  ✗ No dispatchers available");
            return ['available' => false, 'reason' => 'No dispatchers available'];
        }

        // Sort by cost, pick cheapest
        usort($options, function($a, $b) {
            return $a['cost'] <=> $b['cost'];
        });

        $cheapest = $options[0];
        $this->log("  ✓ Least cost option: {$cheapest['dispatcher_name']} (\${$cheapest['cost']} {$cheapest['pay_type']})");

        $cheapest['available'] = true;
        $cheapest['requires_backfill'] = false;

        return $cheapest;
    }

    /**
     * Calculate cost for a dispatcher/shift
     */
    private function calculateCost($dispatcher, $payType, $hours) {
        $rate = $payType === 'overtime' ? $dispatcher['overtime_rate'] : $dispatcher['hourly_rate'];

        // If no rate on record, use default
        if (!$rate) {
            $rate = $payType === 'overtime' ? 45.00 : 30.00; // Default rates
        }

        return round($rate * $hours, 2);
    }

    /**
     * Execute the fill
     */
    private function executeFill($vacancy, $option, $rank, $type) {
        // Record the fill
        $sql = "INSERT INTO vacancy_fills
                (vacancy_id, dispatcher_id, filled_date, pay_type, hours_worked, calculated_cost)
                VALUES (?, ?, NOW(), ?, ?, ?)";

        $fillId = dbInsert($sql, [
            $vacancy['id'],
            $option['dispatcher_id'],
            $option['pay_type'],
            $option['hours'],
            $option['cost']
        ]);

        // Update vacancy status
        $sql = "UPDATE vacancies
                SET status = 'filled',
                    filled_by = ?,
                    filled_at = NOW(),
                    filled_by_option_rank = ?,
                    filled_by_option_type = ?,
                    total_cost = ?
                WHERE id = ?";

        dbExecute($sql, [
            $option['dispatcher_id'],
            $rank,
            $type,
            $option['cost'],
            $vacancy['id']
        ]);

        // If requires backfill, create assignment for backfill GAD
        if (!empty($option['requires_backfill'])) {
            $this->createBackfill(
                $option['backfill_dispatcher_id'],
                $option['backfill_desk_id'],
                $option['backfill_shift'],
                $vacancy['vacancy_date']
            );
        }

        // If creates new vacancy, record it
        if (!empty($option['creates_vacancy'])) {
            $this->createVacancy(
                $option['new_vacancy_desk_id'],
                $option['new_vacancy_shift'],
                $vacancy['vacancy_date'],
                'Created by diversion'
            );
        }

        // Record in assignment log
        $this->logAssignment($vacancy, $option, $rank, $type);

        $this->log("✓ Vacancy filled successfully using {$type}");

        $option['fill_id'] = $fillId;
        $option['option_rank'] = $rank;
        $option['option_type'] = $type;
        $option['decision_log'] = $this->decisionLog;

        return $option;
    }

    /**
     * Create backfill assignment
     */
    private function createBackfill($dispatcherId, $deskId, $shift, $date) {
        $sql = "INSERT INTO assignment_log
                (dispatcher_id, desk_id, shift, work_date, assignment_source, pay_type)
                VALUES (?, ?, ?, ?, 'backfill', 'straight')";
        return dbInsert($sql, [$dispatcherId, $deskId, $shift, $date]);
    }

    /**
     * Create new vacancy
     */
    private function createVacancy($deskId, $shift, $date, $reason) {
        $sql = "INSERT INTO vacancies
                (desk_id, shift, vacancy_date, reason, status)
                VALUES (?, ?, ?, ?, 'open')";
        return dbInsert($sql, [$deskId, $shift, $date, $reason]);
    }

    /**
     * Log assignment
     */
    private function logAssignment($vacancy, $option, $rank, $type) {
        $sql = "INSERT INTO assignment_log
                (dispatcher_id, desk_id, shift, work_date, assignment_source, pay_type, calculated_cost)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        return dbInsert($sql, [
            $option['dispatcher_id'],
            $vacancy['desk_id'],
            $vacancy['shift'],
            $vacancy['vacancy_date'],
            $type,
            $option['pay_type'],
            $option['cost']
        ]);
    }

    /**
     * Get vacancy details
     */
    private static function getVacancy($vacancyId) {
        $sql = "SELECT v.*, d.name as desk_name, div.name as division_name, div.id as division_id
                FROM vacancies v
                INNER JOIN desks d ON v.desk_id = d.id
                INNER JOIN divisions div ON d.division_id = div.id
                WHERE v.id = ?";
        return dbQueryOne($sql, [$vacancyId]);
    }

    /**
     * Get all options that were considered (for display)
     */
    public static function getVacancyOptions($vacancyId) {
        $sql = "SELECT * FROM vacancy_fill_options
                WHERE vacancy_id = ?
                ORDER BY option_rank";
        return dbQueryAll($sql, [$vacancyId]);
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
