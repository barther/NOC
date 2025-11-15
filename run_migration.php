<?php
/**
 * Run database migration
 */

require_once __DIR__ . '/config/database.php';

$migrationFile = $argv[1] ?? null;

if (!$migrationFile) {
    die("Usage: php run_migration.php <migration_file>\n");
}

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

echo "Running migration: $migrationFile\n";

$sql = file_get_contents($migrationFile);

// Split by semicolon and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));

$pdo = getDbConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach ($statements as $statement) {
    // Skip comments and empty statements
    if (empty($statement) || strpos($statement, '--') === 0) {
        continue;
    }

    try {
        echo "Executing: " . substr($statement, 0, 100) . "...\n";
        $pdo->exec($statement);
        echo "✓ Success\n";
    } catch (PDOException $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        // Continue with other statements
    }
}

echo "\nMigration complete!\n";
