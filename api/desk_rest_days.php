<?php
/**
 * Desk Rest Days API
 * Manage default rest days for each shift at a desk
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Get rest days for a desk
        $deskId = $_GET['desk_id'] ?? null;

        if (!$deskId) {
            echo json_encode(['success' => false, 'error' => 'desk_id required']);
            exit;
        }

        $sql = "SELECT shift, day_of_week
                FROM desk_default_rest_days
                WHERE desk_id = ?
                ORDER BY shift, day_of_week";

        $restDays = dbQueryAll($sql, [$deskId]);

        // Group by shift
        $grouped = [
            'first' => [],
            'second' => [],
            'third' => [],
            'relief' => []
        ];

        foreach ($restDays as $row) {
            $grouped[$row['shift']][] = (int)$row['day_of_week'];
        }

        echo json_encode(['success' => true, 'rest_days' => $grouped]);

    } elseif ($method === 'POST') {
        // Set rest days for a desk
        $input = json_decode(file_get_contents('php://input'), true);
        $deskId = $input['desk_id'] ?? null;
        $restDays = $input['rest_days'] ?? null;

        if (!$deskId || !$restDays) {
            echo json_encode(['success' => false, 'error' => 'desk_id and rest_days required']);
            exit;
        }

        dbBeginTransaction();

        try {
            // Clear existing rest days for this desk
            $sql = "DELETE FROM desk_default_rest_days WHERE desk_id = ?";
            dbExecute($sql, [$deskId]);

            // Insert new rest days
            foreach ($restDays as $shift => $days) {
                if (empty($days)) continue;

                foreach ($days as $dayOfWeek) {
                    $sql = "INSERT INTO desk_default_rest_days (desk_id, shift, day_of_week)
                            VALUES (?, ?, ?)";
                    dbInsert($sql, [$deskId, $shift, $dayOfWeek]);
                }
            }

            dbCommit();
            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            dbRollback();
            throw $e;
        }

    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid method']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
