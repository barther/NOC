<?php
/**
 * Diagnostic Script for Sunday Scheduling Issue
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Schedule.php';

echo "Sunday Scheduling Diagnostic\n";
echo str_repeat('=', 60) . "\n\n";

// Test 1: Check if we can get schedule for a specific Sunday
$testSunday = '2025-01-19'; // This is a Sunday
echo "Test 1: Getting schedule for Sunday $testSunday\n";
echo "Day of week check: " . date('w', strtotime($testSunday)) . " (should be 0 for Sunday)\n";
echo "Day name: " . date('l', strtotime($testSunday)) . "\n\n";

try {
    $sundaySchedule = Schedule::getScheduleForDate($testSunday);
    echo "Records returned for Sunday: " . count($sundaySchedule) . "\n";

    if (count($sundaySchedule) > 0) {
        echo "Sample Sunday assignments:\n";
        $sample = array_slice($sundaySchedule, 0, 5);
        foreach ($sample as $assignment) {
            echo sprintf(
                "  - %s %s shift: %s (type: %s)\n",
                $assignment['desk_name'],
                $assignment['shift'],
                $assignment['dispatcher_name'] ?? 'VACANT',
                $assignment['assignment_type']
            );
        }
    } else {
        echo "WARNING: No schedule records returned for Sunday!\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 60) . "\n\n";

// Test 2: Check date range including Sunday
$monday = '2025-01-13';
$sunday = '2025-01-19';
echo "Test 2: Getting schedule range Monday $monday to Sunday $sunday\n";

try {
    $weekSchedule = Schedule::getScheduleForDateRange($monday, $sunday);
    echo "Dates returned: " . count($weekSchedule) . " (should be 7)\n";
    echo "Dates in range:\n";
    foreach ($weekSchedule as $date => $daySchedule) {
        $dayName = date('l', strtotime($date));
        $dayNum = date('w', strtotime($date));
        echo sprintf(
            "  - %s (%s, day %d): %d assignments\n",
            $date,
            $dayName,
            $dayNum,
            count($daySchedule)
        );
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 60) . "\n\n";

// Test 3: Check for relief schedules on Sunday
echo "Test 3: Checking relief schedules for Sunday (day_of_week = 0)\n";
$sql = "SELECT COUNT(*) as count FROM relief_schedules WHERE day_of_week = 0 AND active = 1";
$result = dbQueryOne($sql);
echo "Relief schedules configured for Sunday: " . $result['count'] . "\n\n";

// Test 4: Check for rest days on Sunday
echo "Test 4: Checking custom rest days for Sunday (day_of_week = 0)\n";
$sql = "SELECT COUNT(*) as count FROM job_rest_days WHERE day_of_week = 0";
$result = dbQueryOne($sql);
echo "Custom rest days configured for Sunday: " . $result['count'] . "\n\n";

// Test 5: Check for active desks
echo "Test 5: Checking active desks\n";
$sql = "SELECT COUNT(*) as count FROM desks WHERE active = 1";
$result = dbQueryOne($sql);
echo "Active desks: " . $result['count'] . "\n\n";

// Test 6: Check for active job assignments
echo "Test 6: Checking active job assignments\n";
$sql = "SELECT COUNT(*) as count FROM job_assignments WHERE end_date IS NULL AND assignment_type = 'regular'";
$result = dbQueryOne($sql);
echo "Active regular job assignments: " . $result['count'] . "\n\n";

echo str_repeat('=', 60) . "\n";
echo "Diagnostic complete\n";
