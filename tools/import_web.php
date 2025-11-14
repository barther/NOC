<?php
/**
 * Web-based CSV Import Tool
 *
 * Accessible via browser to import dispatcher data from CSV
 */

// Set execution time limit for large imports
set_time_limit(300); // 5 minutes

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Division.php';
require_once __DIR__ . '/../includes/Desk.php';
require_once __DIR__ . '/../includes/Dispatcher.php';
require_once __DIR__ . '/../includes/Schedule.php';

header('Content-Type: application/json');

// Helper functions
function parseSeniorityDate($dateStr) {
    $parts = explode('/', $dateStr);
    if (count($parts) !== 3) return null;

    $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
    $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
    $year = $parts[2];

    if (strlen($year) === 2) {
        $year = (intval($year) > 50) ? "19$year" : "20$year";
    }

    return "$year-$month-$day";
}

function parseRestDays($offDaysStr) {
    $dayMap = [
        'SUN' => 0, 'SUNDAY' => 0,
        'MON' => 1, 'MONDAY' => 1,
        'TUE' => 2, 'TUESDAY' => 2,
        'WED' => 3, 'WEDNESDAY' => 3,
        'THU' => 4, 'THURSDAY' => 4,
        'FRI' => 5, 'FRIDAY' => 5,
        'SAT' => 6, 'SATURDAY' => 6,
    ];

    $offDaysStr = trim(strtoupper($offDaysStr));

    if (strpos($offDaysStr, 'ROTATING') !== false) {
        return null;
    }

    if (empty($offDaysStr)) {
        return null;
    }

    $restDays = [];
    foreach ($dayMap as $dayName => $dayNum) {
        if (strpos($offDaysStr, $dayName) !== false) {
            if (!in_array($dayNum, $restDays)) {
                $restDays[] = $dayNum;
            }
        }
    }

    return empty($restDays) ? null : $restDays;
}

function parseShiftFromDeskCode($deskCode) {
    if (preg_match('/-(\d+)$/', $deskCode, $matches)) {
        $shiftNum = intval($matches[1]);
        switch ($shiftNum) {
            case 1: return 'first';
            case 2: return 'second';
            case 3: return 'third';
            default: return 'first';
        }
    }
    return 'first';
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'validate':
            // Validate CSV and return statistics
            $csvFile = __DIR__ . '/../data.csv';

            if (!file_exists($csvFile)) {
                echo json_encode(['success' => false, 'error' => 'CSV file not found at: data.csv']);
                exit;
            }

            $handle = fopen($csvFile, 'r');
            if (!$handle) {
                echo json_encode(['success' => false, 'error' => 'Failed to open CSV file']);
                exit;
            }

            $header = fgetcsv($handle);

            $stats = [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'divisions' => [],
                'desks' => 0,
            ];

            $deskSet = [];

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 9) continue;

                $stats['total']++;
                $status = trim($row[1]);
                $divisionName = trim($row[5]);
                $deskCode = trim($row[7]);

                if (strpos(strtoupper($status), 'INACTIVE') !== false) {
                    $stats['inactive']++;
                } else {
                    $stats['active']++;
                }

                if (!isset($stats['divisions'][$divisionName])) {
                    $stats['divisions'][$divisionName] = 0;
                }
                $stats['divisions'][$divisionName]++;

                $deskSet[$deskCode] = true;
            }

            $stats['desks'] = count($deskSet);
            $stats['division_count'] = count($stats['divisions']);

            fclose($handle);

            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        case 'import':
            // Perform the actual import
            $csvFile = __DIR__ . '/../data.csv';

            if (!file_exists($csvFile)) {
                echo json_encode(['success' => false, 'error' => 'CSV file not found']);
                exit;
            }

            $handle = fopen($csvFile, 'r');
            if (!$handle) {
                echo json_encode(['success' => false, 'error' => 'Failed to open CSV file']);
                exit;
            }

            $header = fgetcsv($handle);

            $divisions = [];
            $desks = [];
            $importedCount = 0;
            $skippedCount = 0;
            $messages = [];

            dbBeginTransaction();

            try {
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 9) {
                        $skippedCount++;
                        continue;
                    }

                    $name = trim($row[0]);
                    $status = trim($row[1]);
                    $seniorityDateStr = trim($row[2]);
                    $divisionName = trim($row[5]);
                    $deskName = trim($row[6]);
                    $deskCode = trim($row[7]);
                    $offDays = trim($row[8]);

                    if (strpos(strtoupper($status), 'INACTIVE') !== false) {
                        $skippedCount++;
                        continue;
                    }

                    $nameParts = array_map('trim', explode(',', $name));
                    $lastName = $nameParts[0] ?? '';
                    $firstName = $nameParts[1] ?? '';

                    if (empty($lastName) || empty($firstName)) {
                        $skippedCount++;
                        continue;
                    }

                    $seniorityDate = parseSeniorityDate($seniorityDateStr);
                    if (!$seniorityDate) {
                        $skippedCount++;
                        continue;
                    }

                    // Create division if needed
                    if (!isset($divisions[$divisionName])) {
                        $existingDiv = Division::getAll();
                        $found = false;
                        foreach ($existingDiv as $div) {
                            if (strtoupper($div['name']) === strtoupper($divisionName)) {
                                $divisions[$divisionName] = $div['id'];
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $code = strtoupper(substr($divisionName, 0, 3));
                            $divId = Division::create($divisionName, $code, '');
                            $divisions[$divisionName] = $divId;
                        }
                    }

                    $divisionId = $divisions[$divisionName];

                    // Create desk if needed
                    $deskKey = $divisionName . '|' . $deskCode;
                    if (!isset($desks[$deskKey])) {
                        $existingDesks = Desk::getAll();
                        $found = false;
                        foreach ($existingDesks as $desk) {
                            if ($desk['code'] === $deskCode && $desk['division_id'] == $divisionId) {
                                $desks[$deskKey] = $desk['id'];
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $deskId = Desk::create($divisionId, $deskName, $deskCode, '');
                            $desks[$deskKey] = $deskId;
                        }
                    }

                    $deskId = $desks[$deskKey];

                    // Generate employee number
                    $empNumber = date('y', strtotime($seniorityDate)) . sprintf('%03d', $importedCount + 1);

                    // Create dispatcher
                    $dispatcherId = Dispatcher::create(
                        $empNumber,
                        $firstName,
                        $lastName,
                        $seniorityDate,
                        'job_holder'
                    );

                    // Qualify for desk
                    Dispatcher::setQualification($dispatcherId, $deskId, true, null, date('Y-m-d'));

                    // Determine shift
                    $shift = parseShiftFromDeskCode($deskCode);

                    // Assign to job
                    $assignmentId = Schedule::assignJob($dispatcherId, $deskId, $shift, 'regular');

                    // Set rest days
                    $restDays = parseRestDays($offDays);
                    if ($restDays !== null && !empty($restDays)) {
                        foreach ($restDays as $dayOfWeek) {
                            $sql = "INSERT INTO job_rest_days (job_assignment_id, day_of_week) VALUES (?, ?)";
                            dbInsert($sql, [$assignmentId, $dayOfWeek]);
                        }
                    }

                    $importedCount++;
                }

                // Recalculate seniority
                Dispatcher::recalculateSeniorityRanks();

                dbCommit();

                echo json_encode([
                    'success' => true,
                    'imported' => $importedCount,
                    'skipped' => $skippedCount,
                    'divisions' => count($divisions),
                    'desks' => count($desks)
                ]);

            } catch (Exception $e) {
                dbRollback();
                echo json_encode(['success' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
            }

            fclose($handle);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
