<?php
/**
 * Audit Log System
 *
 * Provides comprehensive audit trail for all system modifications.
 * Tracks who did what, when, and from where.
 *
 * Usage:
 *   AuditLog::create('dispatchers', $dispatcherId, 'John Doe', $newData);
 *   AuditLog::update('desks', $deskId, 'Desk 5', $oldData, $newData);
 *   AuditLog::delete('vacancies', $vacancyId, 'Vacation 2025-01-15', $oldData);
 */

require_once __DIR__ . '/../config/database.php';

class AuditLog {

    /**
     * Log a CREATE action
     */
    public static function create($tableName, $recordId, $recordDescription, $newValues, $notes = null) {
        return self::log([
            'action' => 'create',
            'table_name' => $tableName,
            'record_id' => $recordId,
            'record_description' => $recordDescription,
            'new_values' => $newValues,
            'notes' => $notes
        ]);
    }

    /**
     * Log an UPDATE action
     */
    public static function update($tableName, $recordId, $recordDescription, $oldValues, $newValues, $notes = null) {
        return self::log([
            'action' => 'update',
            'table_name' => $tableName,
            'record_id' => $recordId,
            'record_description' => $recordDescription,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'notes' => $notes
        ]);
    }

    /**
     * Log a DELETE action
     */
    public static function delete($tableName, $recordId, $recordDescription, $oldValues, $notes = null) {
        return self::log([
            'action' => 'delete',
            'table_name' => $tableName,
            'record_id' => $recordId,
            'record_description' => $recordDescription,
            'old_values' => $oldValues,
            'notes' => $notes
        ]);
    }

    /**
     * Log a LOGIN action
     */
    public static function login($userId, $username, $notes = null) {
        return self::log([
            'action' => 'login',
            'notes' => $notes
        ], $userId, $username);
    }

    /**
     * Log a LOGOUT action
     */
    public static function logout($userId, $username, $notes = null) {
        return self::log([
            'action' => 'logout',
            'notes' => $notes
        ], $userId, $username);
    }

    /**
     * Log an IMPORT action (CSV import, bulk operations, etc.)
     */
    public static function import($tableName, $recordCount, $notes = null) {
        return self::log([
            'action' => 'import',
            'table_name' => $tableName,
            'notes' => $notes,
            'new_values' => json_encode(['record_count' => $recordCount])
        ]);
    }

    /**
     * Core logging method
     *
     * @param array $data Audit log data (action, table_name, record_id, etc.)
     * @param int|null $userId Override user ID (default: current session user)
     * @param string|null $username Override username (default: current session user)
     * @return int|bool Insert ID on success, false on failure
     */
    private static function log($data, $userId = null, $username = null) {
        // Get current user from session if not provided
        if ($userId === null) {
            session_start();
            if (!isset($_SESSION['user_id'])) {
                // No user in session - can't log
                error_log("AuditLog: Cannot log action - no user in session");
                return false;
            }
            $userId = $_SESSION['user_id'];
            $username = $_SESSION['username'] ?? 'Unknown';
        }

        // Get client IP address
        $ipAddress = self::getClientIP();

        // Get user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent && strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 255);
        }

        // Convert arrays/objects to JSON for storage
        $oldValuesJson = null;
        $newValuesJson = null;

        if (isset($data['old_values'])) {
            $oldValuesJson = is_string($data['old_values'])
                ? $data['old_values']
                : json_encode($data['old_values']);
        }

        if (isset($data['new_values'])) {
            $newValuesJson = is_string($data['new_values'])
                ? $data['new_values']
                : json_encode($data['new_values']);
        }

        // Insert audit log entry
        $sql = "INSERT INTO audit_log
                (user_id, username, action, table_name, record_id, record_description,
                 old_values, new_values, ip_address, user_agent, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $userId,
            $username,
            $data['action'],
            $data['table_name'] ?? null,
            $data['record_id'] ?? null,
            $data['record_description'] ?? null,
            $oldValuesJson,
            $newValuesJson,
            $ipAddress,
            $userAgent,
            $data['notes'] ?? null
        ];

        try {
            return dbInsert($sql, $params);
        } catch (Exception $e) {
            error_log("AuditLog: Failed to log action - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get client IP address (handles proxies and load balancers)
     */
    private static function getClientIP() {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Proxy
            'HTTP_X_REAL_IP',        // Nginx
            'REMOTE_ADDR'            // Direct connection
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];

                // Handle comma-separated list (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Get audit log entries
     *
     * @param array $filters Optional filters (user_id, action, table_name, date_from, date_to)
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return array Audit log entries
     */
    public static function getEntries($filters = [], $limit = 100, $offset = 0) {
        $sql = "SELECT al.*, u.username as current_username
                FROM audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE 1=1";

        $params = [];

        // Apply filters
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['table_name'])) {
            $sql .= " AND al.table_name = ?";
            $params[] = $filters['table_name'];
        }

        if (!empty($filters['record_id'])) {
            $sql .= " AND al.record_id = ?";
            $params[] = $filters['record_id'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return dbQueryAll($sql, $params);
    }

    /**
     * Get count of audit log entries (for pagination)
     */
    public static function getCount($filters = []) {
        $sql = "SELECT COUNT(*) as count FROM audit_log WHERE 1=1";
        $params = [];

        // Apply same filters as getEntries
        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['table_name'])) {
            $sql .= " AND table_name = ?";
            $params[] = $filters['table_name'];
        }

        if (!empty($filters['record_id'])) {
            $sql .= " AND record_id = ?";
            $params[] = $filters['record_id'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $result = dbQueryOne($sql, $params);
        return $result['count'];
    }

    /**
     * Get audit history for a specific record
     */
    public static function getRecordHistory($tableName, $recordId) {
        return self::getEntries([
            'table_name' => $tableName,
            'record_id' => $recordId
        ], 1000, 0); // Get up to 1000 entries for this record
    }

    /**
     * Get recent activity for a user
     */
    public static function getUserActivity($userId, $limit = 50) {
        return self::getEntries(['user_id' => $userId], $limit, 0);
    }

    /**
     * Get system-wide recent activity
     */
    public static function getRecentActivity($limit = 100) {
        return self::getEntries([], $limit, 0);
    }

    /**
     * Clean up old audit log entries (use carefully!)
     * Only removes entries older than specified days
     *
     * @param int $daysOld Number of days to keep (default: 730 = 2 years)
     * @return int Number of entries deleted
     */
    public static function cleanup($daysOld = 730) {
        $cutoffDate = date('Y-m-d', strtotime("-{$daysOld} days"));

        $sql = "DELETE FROM audit_log WHERE created_at < ?";

        try {
            $result = dbExecute($sql, [$cutoffDate]);
            return $result; // Returns number of affected rows
        } catch (Exception $e) {
            error_log("AuditLog: Cleanup failed - " . $e->getMessage());
            return 0;
        }
    }
}
