<?php
/**
 * Diagnostic script to check dispatcher ranks
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Check Dispatcher Ranks</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        table { border-collapse: collapse; background: white; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #4CAF50; color: white; }
    </style>
</head>
<body>
    <h1>Current Dispatcher Ranks</h1>

<?php
try {
    $sql = "SELECT id, employee_number, first_name, last_name, seniority_date, seniority_rank, seniority_sequence, active
            FROM dispatchers
            ORDER BY seniority_date, seniority_sequence";
    $dispatchers = dbQueryAll($sql);

    echo "<table>\n";
    echo "<tr><th>ID</th><th>Employee#</th><th>Name</th><th>Seniority Date</th><th>Rank</th><th>Sequence</th><th>Active</th></tr>\n";

    foreach ($dispatchers as $d) {
        $style = $d['seniority_rank'] > 900000 ? 'style="background: #ffcccc;"' : '';
        echo "<tr $style>";
        echo "<td>{$d['id']}</td>";
        echo "<td>{$d['employee_number']}</td>";
        echo "<td>{$d['first_name']} {$d['last_name']}</td>";
        echo "<td>{$d['seniority_date']}</td>";
        echo "<td><strong>{$d['seniority_rank']}</strong></td>";
        echo "<td>{$d['seniority_sequence']}</td>";
        echo "<td>{$d['active']}</td>";
        echo "</tr>\n";
    }

    echo "</table>\n";

    echo "<hr>\n";
    echo "<h2>Recalculate Ranks</h2>\n";

    if (isset($_GET['recalculate'])) {
        require_once __DIR__ . '/../includes/Dispatcher.php';

        echo "<p>Running recalculateSeniorityRanks()...</p>\n";

        try {
            Dispatcher::recalculateSeniorityRanks(true);
            echo "<p style='color: green;'>✓ Ranks recalculated successfully!</p>\n";
            echo "<p><a href='check_ranks.php'>Refresh to see updated ranks</a></p>\n";
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
        }
    } else {
        echo "<p><a href='check_ranks.php?recalculate=1'>Click here to manually recalculate ranks</a></p>\n";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>

    <p><a href="../index.php">← Back to NOC Scheduler</a></p>
</body>
</html>
