<?php
/**
 * Validate CSV Format
 *
 * This script validates the data.csv file format without importing to database.
 * Use this to check if your CSV will import correctly.
 */

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
        return 'ROTATING';
    }

    if (empty($offDaysStr)) {
        return 'NONE';
    }

    $restDays = [];
    foreach ($dayMap as $dayName => $dayNum) {
        if (strpos($offDaysStr, $dayName) !== false) {
            if (!in_array($dayNum, $restDays)) {
                $restDays[] = $dayNum;
            }
        }
    }

    return empty($restDays) ? 'NONE' : implode(',', $restDays);
}

function parseShiftFromDeskCode($deskCode) {
    if (preg_match('/-(\d+)$/', $deskCode, $matches)) {
        $shiftNum = intval($matches[1]);
        switch ($shiftNum) {
            case 1: return 'First';
            case 2: return 'Second';
            case 3: return 'Third';
            default: return 'First';
        }
    }
    return 'First';
}

// Main validation
$csvFile = __DIR__ . '/../data.csv';

if (!file_exists($csvFile)) {
    echo "ERROR: CSV file not found: $csvFile\n";
    exit(1);
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    echo "ERROR: Failed to open CSV file\n";
    exit(1);
}

// Read header
$header = fgetcsv($handle);
echo "CSV Header: " . implode(' | ', $header) . "\n";
echo str_repeat('=', 100) . "\n\n";

$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'divisions' => [],
    'desks' => [],
    'shifts' => ['First' => 0, 'Second' => 0, 'Third' => 0],
    'rest_days' => ['ROTATING' => 0, 'CUSTOM' => 0, 'NONE' => 0],
];

echo "Sample Records:\n";
echo str_repeat('-', 100) . "\n";

$sampleCount = 0;
while (($row = fgetcsv($handle)) !== false) {
    $stats['total']++;

    if (count($row) < 9) {
        echo "WARNING: Row {$stats['total']} has incomplete data\n";
        continue;
    }

    $name = trim($row[0]);
    $status = trim($row[1]);
    $seniorityDateStr = trim($row[2]);
    $divisionName = trim($row[5]);
    $deskName = trim($row[6]);
    $deskCode = trim($row[7]);
    $offDays = trim($row[8]);

    // Parse name
    $nameParts = array_map('trim', explode(',', $name));
    $lastName = $nameParts[0] ?? '';
    $firstName = $nameParts[1] ?? '';

    // Parse dates
    $seniorityDate = parseSeniorityDate($seniorityDateStr);
    $shift = parseShiftFromDeskCode($deskCode);
    $restDays = parseRestDays($offDays);

    // Stats
    if (strpos(strtoupper($status), 'INACTIVE') !== false) {
        $stats['inactive']++;
    } else {
        $stats['active']++;
    }

    $stats['divisions'][$divisionName] = ($stats['divisions'][$divisionName] ?? 0) + 1;
    $stats['desks'][$deskCode] = ($stats['desks'][$deskCode] ?? 0) + 1;
    $stats['shifts'][$shift]++;

    if ($restDays === 'ROTATING') {
        $stats['rest_days']['ROTATING']++;
    } elseif ($restDays === 'NONE') {
        $stats['rest_days']['NONE']++;
    } else {
        $stats['rest_days']['CUSTOM']++;
    }

    // Show first 10 active records as samples
    if ($sampleCount < 10 && strpos(strtoupper($status), 'ACTIVE') !== false) {
        echo sprintf(
            "%-25s  %-15s  %-20s  %-10s  %s Shift  Rest: %-15s\n",
            "$firstName $lastName",
            $seniorityDate,
            $divisionName,
            $deskCode,
            $shift,
            $offDays
        );
        $sampleCount++;
    }
}

fclose($handle);

echo "\n" . str_repeat('=', 100) . "\n";
echo "VALIDATION SUMMARY\n";
echo str_repeat('=', 100) . "\n\n";

echo "Total Records: {$stats['total']}\n";
echo "  Active: {$stats['active']}\n";
echo "  Inactive (will be skipped): {$stats['inactive']}\n\n";

echo "Divisions (" . count($stats['divisions']) . "):\n";
foreach ($stats['divisions'] as $div => $count) {
    echo "  - $div: $count dispatchers\n";
}
echo "\n";

echo "Unique Desks: " . count($stats['desks']) . "\n";
echo "  Sample: " . implode(', ', array_slice(array_keys($stats['desks']), 0, 10)) . "...\n\n";

echo "Shift Distribution:\n";
foreach ($stats['shifts'] as $shift => $count) {
    echo "  - $shift: $count\n";
}
echo "\n";

echo "Rest Day Configurations:\n";
echo "  - Custom rest days: {$stats['rest_days']['CUSTOM']}\n";
echo "  - Rotating schedules: {$stats['rest_days']['ROTATING']} (will need manual config)\n";
echo "  - No custom rest days: {$stats['rest_days']['NONE']}\n\n";

echo str_repeat('=', 100) . "\n";
echo "✓ CSV validation complete!\n";
echo "✓ Ready to import {$stats['active']} active dispatchers\n";
echo "\nTo import, run: php tools/import_dispatchers.php\n";
