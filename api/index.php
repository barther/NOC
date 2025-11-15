<?php
/**
 * NOC Scheduler API
 * Unified REST API endpoint for all operations
 */

require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check authentication
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Division.php';
require_once __DIR__ . '/../includes/Desk.php';
require_once __DIR__ . '/../includes/Dispatcher.php';
require_once __DIR__ . '/../includes/Schedule.php';
require_once __DIR__ . '/../includes/VacancyEngine.php';
require_once __DIR__ . '/../includes/Holddown.php';
require_once __DIR__ . '/../includes/ATW.php';

// Initialize ATW tables if they don't exist
ATW::initializeTables();

// Get request data
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $response = ['success' => false, 'data' => null, 'error' => null];

    switch ($action) {

        // ============================================================
        // DIVISIONS
        // ============================================================
        case 'divisions_list':
            $response['data'] = Division::getAll();
            $response['success'] = true;
            break;

        case 'division_create':
            $id = Division::create($input['name'], $input['code']);
            $response['data'] = ['id' => $id];
            $response['success'] = true;
            break;

        case 'division_update':
            Division::update($input['id'], $input['name'], $input['code'], $input['active'] ?? true);
            $response['success'] = true;
            break;

        case 'division_delete':
            Division::delete($input['id']);
            $response['success'] = true;
            break;

        // ============================================================
        // DESKS
        // ============================================================
        case 'desks_list':
            $response['data'] = Desk::getAll();
            $response['success'] = true;
            break;

        case 'desk_get':
            $response['data'] = Desk::getById($input['id']);
            $response['success'] = true;
            break;

        case 'desk_create':
            $id = Desk::create(
                $input['division_id'],
                $input['name'],
                $input['code'],
                $input['description'] ?? ''
            );
            $response['data'] = ['id' => $id];
            $response['success'] = true;
            break;

        case 'desk_update':
            Desk::update(
                $input['id'],
                $input['division_id'],
                $input['name'],
                $input['code'],
                $input['description'] ?? '',
                $input['active'] ?? true
            );
            $response['success'] = true;
            break;

        case 'desk_delete':
            Desk::delete($input['id']);
            $response['success'] = true;
            break;

        case 'desk_assignments':
            $response['data'] = Desk::getJobAssignments($input['desk_id']);
            $response['success'] = true;
            break;

        // ============================================================
        // DISPATCHERS
        // ============================================================
        case 'dispatchers_list':
            $response['data'] = Dispatcher::getAll();
            $response['success'] = true;
            break;

        case 'dispatcher_get':
            $response['data'] = Dispatcher::getById($input['id']);
            $response['success'] = true;
            break;

        case 'dispatcher_get_all_assignments':
            $sql = "SELECT
                        ja.dispatcher_id,
                        d.name as desk_name,
                        ja.shift
                    FROM job_assignments ja
                    JOIN desks d ON ja.desk_id = d.id
                    WHERE ja.end_date IS NULL
                        AND ja.assignment_type = 'regular'

                    UNION

                    SELECT DISTINCT
                        rs.relief_dispatcher_id as dispatcher_id,
                        d.name as desk_name,
                        'relief' as shift
                    FROM relief_schedules rs
                    JOIN desks d ON rs.desk_id = d.id
                    WHERE rs.active = 1

                    UNION

                    SELECT DISTINCT
                        ja.dispatcher_id,
                        aj.name as desk_name,
                        'ATW' as shift
                    FROM job_assignments ja
                    JOIN atw_jobs aj ON ja.atw_job_id = aj.id
                    WHERE ja.end_date IS NULL
                        AND ja.assignment_type = 'atw'
                        AND aj.active = 1

                    ORDER BY dispatcher_id";
            $response['data'] = dbQueryAll($sql);
            $response['success'] = true;
            break;

        case 'dispatcher_create':
            $id = Dispatcher::create(
                $input['employee_number'],
                $input['first_name'],
                $input['last_name'],
                $input['seniority_date'],
                $input['classification'] ?? 'extra_board',
                $input['seniority_sequence'] ?? 1
            );
            error_log("dispatcher_create: Created dispatcher ID $id, now recalculating ranks...");
            // Recalculate all ranks to handle out-of-order additions
            Dispatcher::recalculateSeniorityRanks(true);
            error_log("dispatcher_create: Recalculate completed");
            $response['data'] = ['id' => $id];
            $response['success'] = true;
            break;

        case 'dispatcher_update':
            Dispatcher::update(
                $input['id'],
                $input['employee_number'],
                $input['first_name'],
                $input['last_name'],
                $input['seniority_date'],
                $input['classification'],
                $input['active'] ?? true,
                $input['seniority_sequence'] ?? null
            );
            // Recalculate all ranks in case date/sequence changed
            Dispatcher::recalculateSeniorityRanks(true);
            $response['success'] = true;
            break;

        case 'dispatcher_qualifications':
            $response['data'] = Dispatcher::getQualifications($input['dispatcher_id']);
            $response['success'] = true;
            break;

        case 'dispatcher_set_qualification':
            Dispatcher::setQualification(
                $input['dispatcher_id'],
                $input['desk_id'],
                $input['qualified'] ?? false,
                $input['qualifying_started'] ?? null,
                $input['qualified_date'] ?? null
            );
            $response['success'] = true;
            break;

        case 'dispatcher_set_qualifications':
            // Batch update qualifications for a dispatcher
            $dispatcherId = $input['dispatcher_id'];
            $qualifications = $input['qualifications']; // Array of {desk_id, qualified}

            foreach ($qualifications as $qual) {
                Dispatcher::setQualification(
                    $dispatcherId,
                    $qual['desk_id'],
                    $qual['qualified'] ?? false,
                    null,  // qualifying_started
                    $qual['qualified'] ? date('Y-m-d') : null  // qualified_date
                );
            }
            $response['success'] = true;
            break;

        case 'dispatcher_current_assignment':
            $response['data'] = Dispatcher::getCurrentAssignment($input['dispatcher_id']);
            $response['success'] = true;
            break;

        case 'dispatchers_recalculate_seniority':
            Dispatcher::recalculateSeniorityRanks();
            $response['success'] = true;
            break;

        // ============================================================
        // SCHEDULE & ASSIGNMENTS
        // ============================================================
        case 'schedule_assign_job':
            $id = Schedule::assignJob(
                $input['dispatcher_id'],
                $input['desk_id'],
                $input['shift'],
                $input['assignment_type'] ?? 'regular',
                $input['start_date'] ?? null
            );
            $response['data'] = ['id' => $id];
            $response['success'] = true;
            break;

        case 'schedule_end_assignment':
            Schedule::endJobAssignment($input['assignment_id'], $input['end_date'] ?? null);
            $response['success'] = true;
            break;

        case 'schedule_set_relief':
            Schedule::setReliefSchedule(
                $input['desk_id'],
                $input['relief_dispatcher_id'],
                $input['day_of_week'],
                $input['shift']
            );
            $response['success'] = true;
            break;

        case 'schedule_generate_standard_relief':
            Schedule::generateStandardReliefSchedule(
                $input['desk_id'],
                $input['relief_dispatcher_id']
            );
            $response['success'] = true;
            break;

        case 'relief_get_schedule':
            $deskId = $input['desk_id'];
            $sql = "SELECT day_of_week, shift FROM relief_schedules WHERE desk_id = ? AND active = 1 ORDER BY day_of_week, shift";
            $response['data'] = dbQueryAll($sql, [$deskId]);
            $response['success'] = true;
            break;

        case 'relief_update_schedule':
            $deskId = $input['desk_id'];
            $dispatcherId = $input['relief_dispatcher_id'];
            $schedule = $input['schedule']; // Array of {day: 0-6, shift: 'first'|'second'|'third'}

            dbBeginTransaction();
            try {
                // Clear existing relief schedule for this desk
                $sql = "DELETE FROM relief_schedules WHERE desk_id = ?";
                dbExecute($sql, [$deskId]);

                // Insert new schedule (one shift per day)
                foreach ($schedule as $entry) {
                    Schedule::setReliefSchedule($deskId, $dispatcherId, $entry['day'], $entry['shift']);
                }

                dbCommit();
                $response['success'] = true;
            } catch (Exception $e) {
                dbRollback();
                throw $e;
            }
            break;

        case 'relief_get_dispatcher_schedule':
            // Get relief schedule for a specific dispatcher (cross-desk)
            $response['data'] = Schedule::getReliefScheduleForDispatcher($input['dispatcher_id']);
            $response['success'] = true;
            break;

        case 'relief_set_dispatcher_schedule':
            // Set full relief schedule for a dispatcher (cross-desk capable)
            // Input: dispatcher_id, schedule array [{day: 0-6, desk_id: X, shift: 'first'/'second'/'third'}]
            Schedule::setReliefScheduleForDispatcher(
                $input['dispatcher_id'],
                $input['schedule']
            );
            $response['success'] = true;
            break;

        case 'schedule_set_atw':
            Schedule::setAtwRotation(
                $input['desk_id'],
                $input['day_of_week'],
                $input['rotation_order'],
                $input['atw_dispatcher_id'] ?? null
            );
            $response['success'] = true;
            break;

        case 'schedule_generate_atw':
            Schedule::generateAtwRotation($input['atw_dispatcher_id'] ?? null);
            $response['success'] = true;
            break;

        case 'schedule_get_date':
            $response['data'] = Schedule::getScheduleForDate($input['date']);
            $response['success'] = true;
            break;

        case 'schedule_get_range':
            $response['data'] = Schedule::getScheduleForDateRange(
                $input['start_date'],
                $input['end_date']
            );
            $response['success'] = true;
            break;

        case 'schedule_dispatcher_schedule':
            $response['data'] = Schedule::getDispatcherSchedule(
                $input['dispatcher_id'],
                $input['start_date'],
                $input['end_date']
            );
            $response['success'] = true;
            break;

        case 'job_set_rest_days':
            $assignmentId = $input['job_assignment_id'];
            $restDays = $input['rest_days'];

            // Delete existing rest days for this assignment
            $sql = "DELETE FROM job_rest_days WHERE job_assignment_id = ?";
            dbExecute($sql, [$assignmentId]);

            // Insert new rest days
            foreach ($restDays as $dayOfWeek) {
                $sql = "INSERT INTO job_rest_days (job_assignment_id, day_of_week) VALUES (?, ?)";
                dbInsert($sql, [$assignmentId, $dayOfWeek]);
            }

            $response['success'] = true;
            break;

        case 'job_get_rest_days':
            $assignmentId = $input['job_assignment_id'];
            $sql = "SELECT day_of_week FROM job_rest_days WHERE job_assignment_id = ? ORDER BY day_of_week";
            $response['data'] = dbQueryAll($sql, [$assignmentId]);
            $response['success'] = true;
            break;

        case 'desk_get_assignments':
            $deskId = $input['desk_id'];

            // Get regular assignments
            $sql = "SELECT
                        ja.id as assignment_id,
                        ja.shift,
                        ja.start_date,
                        d.id as dispatcher_id,
                        d.employee_number,
                        d.first_name,
                        d.last_name,
                        d.classification,
                        GROUP_CONCAT(jrd.day_of_week ORDER BY jrd.day_of_week) as rest_days,
                        'regular' as assignment_type,
                        NULL as schedule_summary
                    FROM job_assignments ja
                    JOIN dispatchers d ON ja.dispatcher_id = d.id
                    LEFT JOIN job_rest_days jrd ON jrd.job_assignment_id = ja.id
                    WHERE ja.desk_id = ?
                        AND ja.end_date IS NULL
                        AND ja.assignment_type = 'regular'
                    GROUP BY ja.id, ja.shift, ja.start_date, d.id, d.employee_number, d.first_name, d.last_name, d.classification
                    ORDER BY FIELD(ja.shift, 'first', 'second', 'third')";
            $assignments = dbQueryAll($sql, [$deskId]);

            // Get relief assignment if exists
            $reliefSql = "SELECT DISTINCT
                            d.id as dispatcher_id,
                            d.employee_number,
                            d.first_name,
                            d.last_name,
                            d.classification
                        FROM relief_schedules rs
                        JOIN dispatchers d ON rs.relief_dispatcher_id = d.id
                        WHERE rs.desk_id = ? AND rs.active = 1";
            $reliefDispatchers = dbQueryAll($reliefSql, [$deskId]);

            // For each relief dispatcher, build their schedule summary
            foreach ($reliefDispatchers as $reliefDispatcher) {
                $scheduleSql = "SELECT day_of_week, shift
                               FROM relief_schedules
                               WHERE desk_id = ? AND relief_dispatcher_id = ? AND active = 1
                               ORDER BY day_of_week, FIELD(shift, 'first', 'second', 'third')";
                $schedule = dbQueryAll($scheduleSql, [$deskId, $reliefDispatcher['dispatcher_id']]);

                // Build summary like "Sun/Mon: 1st, Tue/Wed: 2nd, Thu: 3rd"
                $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $shiftNames = ['first' => '1st', 'second' => '2nd', 'third' => '3rd'];
                $byShift = [];
                foreach ($schedule as $entry) {
                    $shift = $entry['shift'];
                    if (!isset($byShift[$shift])) {
                        $byShift[$shift] = [];
                    }
                    $byShift[$shift][] = $dayNames[$entry['day_of_week']];
                }

                $summaryParts = [];
                foreach ($byShift as $shift => $days) {
                    $summaryParts[] = implode('/', $days) . ': ' . $shiftNames[$shift];
                }
                $summary = implode(', ', $summaryParts);

                // Add relief assignment to results
                $assignments[] = [
                    'assignment_id' => null,
                    'shift' => 'relief',
                    'start_date' => null,
                    'dispatcher_id' => $reliefDispatcher['dispatcher_id'],
                    'employee_number' => $reliefDispatcher['employee_number'],
                    'first_name' => $reliefDispatcher['first_name'],
                    'last_name' => $reliefDispatcher['last_name'],
                    'classification' => $reliefDispatcher['classification'],
                    'rest_days' => null,
                    'assignment_type' => 'relief',
                    'schedule_summary' => $summary
                ];
            }

            $response['data'] = $assignments;
            $response['success'] = true;
            break;

        // ============================================================
        // VACANCIES
        // ============================================================
        case 'vacancy_create':
            $dispatcherId = $input['dispatcher_id'];
            $absenceType = $input['absence_type']; // 'single_day', 'date_range', 'open_ended'
            $vacancyType = $input['vacancy_type']; // 'vacation', 'sick', 'loa', etc.
            $startDate = $input['start_date'];
            $endDate = $input['end_date'] ?? null;
            $notes = $input['notes'] ?? '';

            // Ensure is_planned is a proper boolean/integer
            $isPlanned = ($vacancyType !== 'sick' && $vacancyType !== 'other') ? 1 : 0;

            // Get dispatcher's current assignment to determine desk and shift
            $assignment = dbQueryOne(
                "SELECT ja.desk_id, ja.shift
                 FROM job_assignments ja
                 WHERE ja.dispatcher_id = ?
                   AND ja.end_date IS NULL
                   AND ja.assignment_type IN ('regular', 'relief')
                 LIMIT 1",
                [$dispatcherId]
            );

            if (!$assignment) {
                throw new Exception("Dispatcher has no current assignment");
            }

            $deskId = $assignment['desk_id'];
            $shift = $assignment['shift'];
            $createdIds = [];

            // Create vacancies based on absence type
            if ($absenceType === 'single_day') {
                // Single day absence
                $sql = "INSERT INTO vacancies
                        (desk_id, shift, vacancy_date, start_date, end_date, vacancy_type, absence_type, incumbent_dispatcher_id, is_planned, notes, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'single_day', ?, ?, ?, 'pending')";
                $id = dbInsert($sql, [$deskId, $shift, $startDate, $startDate, $startDate, $vacancyType, $dispatcherId, $isPlanned, $notes]);
                $createdIds[] = $id;

            } elseif ($absenceType === 'date_range') {
                // Multi-day absence with end date
                if (!$endDate) {
                    throw new Exception("End date required for date_range absence");
                }

                // Create one vacancy per day in the range
                $currentDate = new DateTime($startDate);
                $endDateTime = new DateTime($endDate);

                while ($currentDate <= $endDateTime) {
                    $dateStr = $currentDate->format('Y-m-d');
                    $sql = "INSERT INTO vacancies
                            (desk_id, shift, vacancy_date, start_date, end_date, vacancy_type, absence_type, incumbent_dispatcher_id, is_planned, notes, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'date_range', ?, ?, ?, 'pending')";
                    $id = dbInsert($sql, [$deskId, $shift, $dateStr, $startDate, $endDate, $vacancyType, $dispatcherId, $isPlanned, $notes]);
                    $createdIds[] = $id;
                    $currentDate->modify('+1 day');
                }

            } elseif ($absenceType === 'open_ended') {
                // Open-ended absence (no end date)
                $sql = "INSERT INTO vacancies
                        (desk_id, shift, vacancy_date, start_date, end_date, vacancy_type, absence_type, incumbent_dispatcher_id, is_planned, notes, status)
                        VALUES (?, ?, ?, ?, NULL, ?, 'open_ended', ?, ?, ?, 'pending')";
                $id = dbInsert($sql, [$deskId, $shift, $startDate, $startDate, $vacancyType, $dispatcherId, $isPlanned, $notes]);
                $createdIds[] = $id;
            }

            $response['data'] = [
                'created_ids' => $createdIds,
                'count' => count($createdIds),
                'desk_id' => $deskId,
                'shift' => $shift
            ];
            $response['success'] = true;
            break;

        case 'vacancy_fill':
            $engine = new VacancyEngine();
            $result = $engine->fillVacancy($input['vacancy_id']);
            $response['data'] = $result;
            $response['success'] = true;
            break;

        case 'vacancy_close_open_ended':
            // Close an open-ended absence by setting an end date
            $dispatcherId = $input['dispatcher_id'];
            $endDate = $input['end_date'];

            // Update the open-ended absence to have an end date
            $sql = "UPDATE vacancies
                    SET end_date = ?,
                        absence_type = 'date_range',
                        updated_at = NOW()
                    WHERE incumbent_dispatcher_id = ?
                      AND absence_type = 'open_ended'
                      AND status IN ('pending', 'open')
                      AND start_date <= ?";
            dbExecute($sql, [$endDate, $dispatcherId, $endDate]);

            // Create vacancy records for any missing days between start and end
            $openEndedVacancies = dbQueryAll(
                "SELECT * FROM vacancies
                 WHERE incumbent_dispatcher_id = ?
                   AND absence_type = 'date_range'
                   AND start_date IS NOT NULL
                   AND end_date = ?
                 ORDER BY start_date",
                [$dispatcherId, $endDate]
            );

            $createdCount = 0;
            foreach ($openEndedVacancies as $vacancy) {
                // Create vacancies for all days in the range
                $currentDate = new DateTime($vacancy['start_date']);
                $endDateTime = new DateTime($endDate);
                $firstDate = $currentDate->format('Y-m-d');

                $currentDate->modify('+1 day'); // Skip first day (already exists)

                while ($currentDate <= $endDateTime) {
                    $dateStr = $currentDate->format('Y-m-d');

                    // Check if vacancy already exists for this date
                    $exists = dbQueryOne(
                        "SELECT id FROM vacancies
                         WHERE incumbent_dispatcher_id = ?
                           AND vacancy_date = ?
                           AND desk_id = ?
                           AND shift = ?",
                        [$dispatcherId, $dateStr, $vacancy['desk_id'], $vacancy['shift']]
                    );

                    if (!$exists) {
                        $sql = "INSERT INTO vacancies
                                (desk_id, shift, vacancy_date, start_date, end_date, vacancy_type, absence_type, incumbent_dispatcher_id, is_planned, notes, status)
                                VALUES (?, ?, ?, ?, ?, ?, 'date_range', ?, ?, ?, 'pending')";
                        dbInsert($sql, [
                            $vacancy['desk_id'],
                            $vacancy['shift'],
                            $dateStr,
                            $vacancy['start_date'],
                            $endDate,
                            $vacancy['vacancy_type'],
                            $dispatcherId,
                            $vacancy['is_planned'],
                            $vacancy['notes']
                        ]);
                        $createdCount++;
                    }

                    $currentDate->modify('+1 day');
                }
            }

            $response['data'] = ['created_count' => $createdCount];
            $response['success'] = true;
            break;

        case 'vacancies_list':
            $sql = "SELECT v.*,
                           d.name as desk_name,
                           division.name as division_name,
                           CONCAT(disp.first_name, ' ', disp.last_name) as incumbent_name,
                           CONCAT(filled.first_name, ' ', filled.last_name) as filled_by_name,
                           vf.fill_method,
                           vf.pay_type
                    FROM vacancies v
                    JOIN desks d ON v.desk_id = d.id
                    JOIN divisions division ON d.division_id = division.id
                    LEFT JOIN dispatchers disp ON v.incumbent_dispatcher_id = disp.id
                    LEFT JOIN vacancy_fills vf ON v.id = vf.vacancy_id
                    LEFT JOIN dispatchers filled ON vf.filled_by_dispatcher_id = filled.id
                    WHERE 1=1";

            $params = [];
            if (isset($input['status'])) {
                $sql .= " AND v.status = ?";
                $params[] = $input['status'];
            }
            if (isset($input['start_date']) && isset($input['end_date'])) {
                $sql .= " AND v.vacancy_date BETWEEN ? AND ?";
                $params[] = $input['start_date'];
                $params[] = $input['end_date'];
            }

            $sql .= " ORDER BY v.vacancy_date DESC, division.name, d.name";
            $response['data'] = dbQueryAll($sql, $params);
            $response['success'] = true;
            break;

        // ============================================================
        // HOLDDOWNS
        // ============================================================
        case 'holddown_post':
            $id = Holddown::post(
                $input['desk_id'],
                $input['shift'],
                $input['start_date'],
                $input['end_date'],
                $input['incumbent_dispatcher_id'],
                $input['notes'] ?? ''
            );
            $response['data'] = ['id' => $id];
            $response['success'] = true;
            break;

        case 'holddown_bid':
            $id = Holddown::submitBid(
                $input['holddown_id'],
                $input['dispatcher_id'],
                $input['notes'] ?? ''
            );
            $response['data'] = ['id' => $id];
            $response['success'] = true;
            break;

        case 'holddown_award':
            $result = Holddown::award($input['holddown_id']);
            $response['data'] = $result;
            $response['success'] = true;
            break;

        case 'holddown_cancel':
            Holddown::cancel($input['holddown_id'], $input['reason'] ?? '');
            $response['success'] = true;
            break;

        case 'holddowns_list':
            $response['data'] = Holddown::getAll($input['status'] ?? null);
            $response['success'] = true;
            break;

        case 'holddown_bids':
            $response['data'] = Holddown::getBids($input['holddown_id']);
            $response['success'] = true;
            break;

        // ============================================================
        // ATW (AROUND-THE-WORLD) JOBS
        // ============================================================
        case 'atw_list':
            $response['data'] = ATW::getAll();
            $response['success'] = true;
            break;

        case 'atw_get':
            $response['data'] = ATW::getById($input['id']);
            $response['success'] = true;
            break;

        case 'atw_create':
            $id = ATW::create($input['name'], $input['description'] ?? '');
            $response['data'] = ['id' => $id];
            $response['success'] = true;
            break;

        case 'atw_update':
            ATW::update($input['id'], $input['name'], $input['description'] ?? '');
            $response['success'] = true;
            break;

        case 'atw_delete':
            ATW::delete($input['id']);
            $response['success'] = true;
            break;

        case 'atw_get_schedule':
            $response['data'] = ATW::getSchedule($input['atw_job_id']);
            $response['success'] = true;
            break;

        case 'atw_set_schedule':
            // Input: atw_job_id, schedule array [{day: 0-6, desk_id: X}]
            $atwJobId = $input['atw_job_id'];
            $schedule = $input['schedule'];

            // Clear existing schedule
            ATW::clearSchedule($atwJobId);

            // Set new schedule
            foreach ($schedule as $entry) {
                if (isset($entry['desk_id']) && $entry['desk_id']) {
                    ATW::setSchedule(
                        $atwJobId,
                        $entry['day'],
                        $entry['desk_id'],
                        $entry['shift'] ?? 'third'
                    );
                }
            }

            $response['success'] = true;
            break;

        case 'atw_assign_dispatcher':
            ATW::assignDispatcher(
                $input['atw_job_id'],
                $input['dispatcher_id'],
                $input['start_date'] ?? null
            );
            $response['success'] = true;
            break;

        case 'atw_get_assigned_dispatcher':
            $response['data'] = ATW::getAssignedDispatcher($input['atw_job_id']);
            $response['success'] = true;
            break;

        case 'atw_get_all_coverage':
            $response['data'] = ATW::getAllCoverage();
            $response['success'] = true;
            break;

        // ============================================================
        // SYSTEM CONFIG
        // ============================================================
        case 'config_get':
            $sql = "SELECT * FROM system_config ORDER BY config_key";
            $response['data'] = dbQueryAll($sql);
            $response['success'] = true;
            break;

        case 'config_update':
            $sql = "UPDATE system_config SET config_value = ? WHERE config_key = ?";
            dbExecute($sql, [$input['value'], $input['key']]);
            $response['success'] = true;
            break;

        default:
            http_response_code(400);
            $response['error'] = "Unknown action: $action";
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    error_log("API Error: " . $e->getMessage());
}

echo json_encode($response);
