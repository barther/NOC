<?php
/**
 * Run migration 003 - Add desk default rest days
 */

require_once __DIR__ . '/../config/database.php';

try {
    echo "Running migration 003: Add desk default rest days...\n\n";

    $sql = file_get_contents(__DIR__ . '/../database/migrations/003_add_desk_rest_days.sql');

    dbBeginTransaction();

    // Execute the migration
    $pdo = getDbConnection();
    $pdo->exec($sql);

    dbCommit();

    echo "✓ Migration completed successfully!\n";
    echo "✓ Table 'desk_default_rest_days' created\n";

} catch (Exception $e) {
    if (function_exists('dbRollback')) {
        dbRollback();
    }
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
