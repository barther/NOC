<?php
/**
 * Web-based migration runner for 004
 * Access via browser: http://yourserver/tools/run_migration_004_web.php
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Migration 004 - Seniority Sequence</title>
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
        <h1>Migration 004: Add Seniority Sequence</h1>
        <p>This migration adds the <code>seniority_sequence</code> column to dispatchers table.</p>
        <p>This allows you to establish order when multiple dispatchers qualify on the same date.</p>
        <hr>

<?php
try {
    echo "<h2>Running migration...</h2>\n";

    $sql = file_get_contents(__DIR__ . '/../database/migrations/004_add_seniority_sequence.sql');

    echo "<pre>" . htmlspecialchars($sql) . "</pre>\n";

    dbBeginTransaction();

    $pdo = getDbConnection();
    $pdo->exec($sql);

    dbCommit();

    echo "<h2 class='success'>✓ Migration completed successfully!</h2>\n";
    echo "<p class='success'>Column 'seniority_sequence' has been added to dispatchers table.</p>\n";
    echo "<p class='success'>All existing dispatchers set to sequence 1.</p>\n";
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
