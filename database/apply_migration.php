<?php
/**
 * Apply database migration
 */
require_once __DIR__ . '/../config/database.php';

try {
    $sql = file_get_contents(__DIR__ . '/add_rest_days.sql');
    $pdo = getDbConnection();
    $pdo->exec($sql);
    echo "Migration applied successfully!\n";
} catch (Exception $e) {
    echo "Error applying migration: " . $e->getMessage() . "\n";
    exit(1);
}
