<?php
/**
 * Authentication Configuration
 *
 * Supports database-backed user authentication with roles (admin/read_only)
 * Integrates audit logging for login/logout events
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/AuditLog.php';

// Legacy password hash (bcrypt hash of "unclepetecheesefactory") - for backwards compatibility
define('AUTH_PASSWORD_HASH', '$2y$10$eH8qJ5XZN5xZJ5XZN5xZNO5xZN5xZN5xZN5xZN5xZN5xZN5xZN5x.');

// Cookie settings
define('AUTH_COOKIE_NAME', 'noc_auth_token');
define('AUTH_COOKIE_LIFETIME', 30 * 24 * 60 * 60); // 30 days

// Session timeout (2 hours of inactivity)
define('AUTH_SESSION_TIMEOUT', 2 * 60 * 60);

/**
 * Start session if not already started
 */
function ensureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Generate authentication token
 */
function generateAuthToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Get user by username
 */
function getUserByUsername($username) {
    $sql = "SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1";
    return dbQueryOne($sql, [$username]);
}

/**
 * Get user by ID
 */
function getUserById($userId) {
    $sql = "SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1";
    return dbQueryOne($sql, [$userId]);
}

/**
 * Verify password against hash
 */
function verifyPassword($password, $hash = null) {
    // If hash provided, verify against it
    if ($hash !== null) {
        return password_verify($password, $hash);
    }

    // Legacy: For initial setup, allow plain password comparison
    return $password === 'unclepetecheesefactory';
}

/**
 * Set authentication cookie
 */
function setAuthCookie($token) {
    $expiry = time() + AUTH_COOKIE_LIFETIME;

    // Set cookie
    setcookie(
        AUTH_COOKIE_NAME,
        $token,
        $expiry,
        '/',
        '',
        false, // Set to true if using HTTPS
        true   // HttpOnly flag
    );

    return $token;
}

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    ensureSession();

    // Check if session is authenticated
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }

    // Check if user_id exists in session
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // Check session timeout (inactive for too long)
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > AUTH_SESSION_TIMEOUT) {
            // Session expired
            logoutUser();
            return false;
        }
    }

    // Update last activity time
    $_SESSION['last_activity'] = time();

    return true;
}

/**
 * Check if current user is admin
 */
function isAdmin() {
    ensureSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Check if current user is read-only
 */
function isReadOnly() {
    ensureSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'read_only';
}

/**
 * Require authentication (redirect to login if not authenticated)
 */
function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require admin role (return error if not admin)
 */
function requireAdmin() {
    requireAuth();

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied. Admin privileges required.']);
        exit;
    }
}

/**
 * Login user with username and password
 *
 * @param string $username Username
 * @param string $password Password
 * @param bool $rememberMe Whether to set persistent cookie
 * @return array|bool User data on success, false on failure
 */
function loginUser($username, $password, $rememberMe = true) {
    // Try database authentication first
    $user = getUserByUsername($username);

    if ($user && verifyPassword($password, $user['password'])) {
        return authenticateUser($user, $rememberMe);
    }

    // Legacy: Fall back to simple password check if no users in database
    $userCount = dbQueryOne("SELECT COUNT(*) as count FROM users");
    if ($userCount['count'] == 0 && verifyPassword($password)) {
        // Create default admin user on first login
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')";
        $userId = dbInsert($sql, ['admin', $hashedPassword]);

        $user = getUserById($userId);
        return authenticateUser($user, $rememberMe);
    }

    return false;
}

/**
 * Authenticate user and set session
 */
function authenticateUser($user, $rememberMe = true) {
    ensureSession();

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    // Generate and set auth token
    $token = generateAuthToken();
    $_SESSION['auth_token'] = $token;

    // Set persistent cookie if remember me
    if ($rememberMe) {
        setAuthCookie($token);

        // Store session in database
        $expiry = date('Y-m-d H:i:s', time() + AUTH_COOKIE_LIFETIME);
        $sql = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?)";

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent && strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 255);
        }

        dbInsert($sql, [$user['id'], $token, $ipAddress, $userAgent, $expiry]);
    }

    // Update last login time
    $sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
    dbExecute($sql, [$user['id']]);

    // Log login event
    AuditLog::login($user['id'], $user['username'], "Login successful");

    return $user;
}

/**
 * Logout user
 */
function logoutUser() {
    ensureSession();

    // Log logout event before clearing session
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        AuditLog::logout($_SESSION['user_id'], $_SESSION['username'], "Logout");
    }

    // Remove session from database
    if (isset($_SESSION['auth_token'])) {
        $sql = "DELETE FROM user_sessions WHERE session_token = ?";
        dbExecute($sql, [$_SESSION['auth_token']]);
    }

    // Clear session
    $_SESSION = array();
    session_destroy();

    // Clear cookie
    setcookie(
        AUTH_COOKIE_NAME,
        '',
        time() - 3600,
        '/',
        '',
        false,
        true
    );
}

/**
 * Get current logged-in user info
 */
function getCurrentUser() {
    ensureSession();

    if (!isAuthenticated()) {
        return null;
    }

    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role']
    ];
}
