<?php
/**
 * Database Configuration
 *
 * Update these settings for your LAMP environment
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'noc_scheduler');
define('DB_USER', 'noc_user');
define('DB_PASS', 'change_this_password');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get database connection
 */
function getDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed. Please check configuration.");
        }
    }

    return $pdo;
}

/**
 * Execute a query and return results
 */
function dbQuery($sql, $params = []) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Execute a query and return all results
 */
function dbQueryAll($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Execute a query and return a single row
 */
function dbQueryOne($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt->fetch();
}

/**
 * Execute an insert and return the last insert ID
 */
function dbInsert($sql, $params = []) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $pdo->lastInsertId();
}

/**
 * Execute an update/delete and return affected rows
 */
function dbExecute($sql, $params = []) {
    $stmt = dbQuery($sql, $params);
    return $stmt->rowCount();
}

/**
 * Start a transaction
 */
function dbBeginTransaction() {
    return getDbConnection()->beginTransaction();
}

/**
 * Commit a transaction
 */
function dbCommit() {
    return getDbConnection()->commit();
}

/**
 * Rollback a transaction
 */
function dbRollback() {
    return getDbConnection()->rollBack();
}
