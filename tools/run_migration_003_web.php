<?php
/**
 * Web-based migration runner for 003
 * Access via browser: http://yourserver/tools/run_migration_003_web.php
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Migration 003 - Desk Rest Days</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f9f9f9; padding: 10px; border-left: 3px solid #4CAF50; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Migration 003: Add Desk Default Rest Days</h1>
        <p>This migration creates the <code>desk_default_rest_days</code> table.</p>
        <hr>

<?php
try {
    echo "<h2>Running migration...</h2>\n";

    $sql = file_get_contents(__DIR__ . '/../database/migrations/003_add_desk_rest_days.sql');

    echo "<pre>" . htmlspecialchars($sql) . "</pre>\n";

    dbBeginTransaction();

    $pdo = getDbConnection();
    $pdo->exec($sql);

    dbCommit();

    echo "<h2 class='success'>✓ Migration completed successfully!</h2>\n";
    echo "<p class='success'>Table 'desk_default_rest_days' has been created.</p>\n";
    echo "<p><a href='../index.php'>← Back to NOC Scheduler</a></p>\n";

} catch (Exception $e) {
    if (function_exists('dbRollback')) {
        dbRollback();
    }
    echo "<h2 class='error'>✗ Migration failed</h2>\n";
    echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>\n";
    exit(1);
}
?>
    </div>
</body>
</html>
