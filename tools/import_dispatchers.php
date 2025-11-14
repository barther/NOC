<?php
/**
 * Import Dispatcher Data from CSV
 *
 * This script imports dispatcher data from data.csv including:
 * - Divisions
 * - Desks
 * - Dispatchers with seniority
 * - Job assignments
 * - Custom rest days
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Division.php';
require_once __DIR__ . '/../includes/Desk.php';
require_once __DIR__ . '/../includes/Dispatcher.php';
require_once __DIR__ . '/../includes/Schedule.php';

// Color output for terminal
function success($msg) {
    echo "\033[32m✓\033[0m $msg\n";
}

function error($msg) {
    echo "\033[31m✗\033[0m $msg\n";
}

function info($msg) {
    echo "\033[34mℹ\033[0m $msg\n";
}

function parseSeniorityDate($dateStr) {
    // Parse MM/DD/YY format
    $parts = explode('/', $dateStr);
    if (count($parts) !== 3) return null;

    $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
    $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
    $year = $parts[2];

    // Convert 2-digit year to 4-digit
    if (strlen($year) === 2) {
        $year = (intval($year) > 50) ? "19$year" : "20$year";
    }

    return "$year-$month-$day";
}

function parseRestDays($offDaysStr) {
    // Map day names to day numbers (0=Sunday, 6=Saturday)
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

    // Handle special cases
    if (strpos($offDaysStr, 'ROTATING') !== false) {
        return null; // Skip rotating schedules for now
    }

    if (empty($offDaysStr)) {
        return null; // No custom rest days
    }

    // Parse day names
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
    // Extract shift number from codes like "NR-1", "KC-3", "PO-1", etc.
    if (preg_match('/-(\d+)$/', $deskCode, $matches)) {
        $shiftNum = intval($matches[1]);
        switch ($shiftNum) {
            case 1: return 'first';
            case 2: return 'second';
            case 3: return 'third';
            default: return 'first';
        }
    }
    return 'first'; // Default to first shift
}

// Main import logic
try {
    info("Starting dispatcher data import...");

    $csvFile = __DIR__ . '/../data.csv';
    if (!file_exists($csvFile)) {
        error("CSV file not found: $csvFile");
        exit(1);
    }

    // Open CSV
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        error("Failed to open CSV file");
        exit(1);
    }

    // Read header
    $header = fgetcsv($handle);
    if (!$header) {
        error("Failed to read CSV header");
        exit(1);
    }

    info("CSV Header: " . implode(', ', $header));

    // Tracking
    $divisions = [];
    $desks = [];
    $importedCount = 0;
    $skippedCount = 0;

    dbBeginTransaction();

    try {
        // Read each row
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 9) {
                info("Skipping incomplete row");
                continue;
            }

            $name = trim($row[0]);
            $status = trim($row[1]);
            $seniorityDateStr = trim($row[2]);
            $divisionName = trim($row[5]);
            $deskName = trim($row[6]);
            $deskCode = trim($row[7]);
            $offDays = trim($row[8]);

            // Skip if inactive
            if (strpos(strtoupper($status), 'INACTIVE') !== false) {
                info("Skipping inactive dispatcher: $name");
                $skippedCount++;
                continue;
            }

            // Parse name (Last, First format)
            $nameParts = array_map('trim', explode(',', $name));
            $lastName = $nameParts[0] ?? '';
            $firstName = $nameParts[1] ?? '';

            if (empty($lastName) || empty($firstName)) {
                error("Invalid name format: $name");
                $skippedCount++;
                continue;
            }

            // Parse seniority date
            $seniorityDate = parseSeniorityDate($seniorityDateStr);
            if (!$seniorityDate) {
                error("Invalid seniority date for $name: $seniorityDateStr");
                $skippedCount++;
                continue;
            }

            // Create division if needed
            if (!isset($divisions[$divisionName])) {
                try {
                    // Check if division exists
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
                        success("Created division: $divisionName (ID: $divId)");
                    }
                } catch (Exception $e) {
                    error("Failed to create division $divisionName: " . $e->getMessage());
                    $skippedCount++;
                    continue;
                }
            }

            $divisionId = $divisions[$divisionName];

            // Create desk if needed
            $deskKey = $divisionName . '|' . $deskCode;
            if (!isset($desks[$deskKey])) {
                try {
                    // Check if desk exists
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
                        success("Created desk: $deskName ($deskCode) in $divisionName");
                    }
                } catch (Exception $e) {
                    error("Failed to create desk $deskCode: " . $e->getMessage());
                    $skippedCount++;
                    continue;
                }
            }

            $deskId = $desks[$deskKey];

            // Generate employee number (using last 4 digits of seniority year + counter)
            $empNumber = date('y', strtotime($seniorityDate)) . sprintf('%03d', $importedCount + 1);

            // Create dispatcher
            try {
                $dispatcherId = Dispatcher::create(
                    $empNumber,
                    $firstName,
                    $lastName,
                    $seniorityDate,
                    'job_holder' // Most are job holders
                );

                success("Created dispatcher: $empNumber - $firstName $lastName (Seniority: $seniorityDate)");

                // Qualify for this desk
                Dispatcher::setQualification($dispatcherId, $deskId, true, null, date('Y-m-d'));

                // Determine shift from desk code
                $shift = parseShiftFromDeskCode($deskCode);

                // Assign to job
                $assignmentId = Schedule::assignJob($dispatcherId, $deskId, $shift, 'regular');

                // Parse and set rest days
                $restDays = parseRestDays($offDays);
                if ($restDays !== null && !empty($restDays)) {
                    foreach ($restDays as $dayOfWeek) {
                        $sql = "INSERT INTO job_rest_days (job_assignment_id, day_of_week) VALUES (?, ?)";
                        dbInsert($sql, [$assignmentId, $dayOfWeek]);
                    }
                    $daysStr = implode(',', $restDays);
                    info("  → Assigned to $deskName $shift shift with rest days: $offDays (days: $daysStr)");
                } else {
                    info("  → Assigned to $deskName $shift shift (standard schedule)");
                }

                $importedCount++;
            } catch (Exception $e) {
                error("Failed to create dispatcher $firstName $lastName: " . $e->getMessage());
                $skippedCount++;
                continue;
            }
        }

        // Recalculate seniority ranks
        info("Recalculating seniority ranks...");
        Dispatcher::recalculateSeniorityRanks();

        dbCommit();

        echo "\n";
        success("Import completed successfully!");
        success("Imported: $importedCount dispatchers");
        success("Created: " . count($divisions) . " divisions");
        success("Created: " . count($desks) . " desks");
        if ($skippedCount > 0) {
            info("Skipped: $skippedCount records");
        }

    } catch (Exception $e) {
        dbRollback();
        error("Import failed: " . $e->getMessage());
        error("Stack trace: " . $e->getTraceAsString());
        exit(1);
    }

    fclose($handle);

} catch (Exception $e) {
    error("Fatal error: " . $e->getMessage());
    exit(1);
}
