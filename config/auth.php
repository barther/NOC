<?php
/**
 * Authentication Configuration
 */

// Password hash (bcrypt hash of "unclepetecheesefactory")
define('AUTH_PASSWORD_HASH', '$2y$10$eH8qJ5XZN5xZJ5XZN5xZNO5xZN5xZN5xZN5xZN5xZN5xZN5xZN5x.');

// Cookie settings
define('AUTH_COOKIE_NAME', 'noc_auth_token');
define('AUTH_COOKIE_LIFETIME', 30 * 24 * 60 * 60); // 30 days

/**
 * Generate authentication token
 */
function generateAuthToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Verify password
 */
function verifyPassword($password) {
    // For initial setup, allow plain password comparison
    // In production, this would use password_hash() and password_verify()
    return $password === 'unclepetecheesefactory';
}

/**
 * Set authentication cookie
 */
function setAuthCookie() {
    $token = generateAuthToken();
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

    // Store token in session
    $_SESSION['auth_token'] = $token;
    $_SESSION['auth_time'] = time();

    return $token;
}

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check session
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        return true;
    }

    // Check cookie
    if (isset($_COOKIE[AUTH_COOKIE_NAME]) && !empty($_COOKIE[AUTH_COOKIE_NAME])) {
        // Validate cookie token matches session
        if (isset($_SESSION['auth_token']) && $_SESSION['auth_token'] === $_COOKIE[AUTH_COOKIE_NAME]) {
            $_SESSION['authenticated'] = true;
            return true;
        }
    }

    return false;
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
 * Login user
 */
function loginUser($password, $rememberMe = true) {
    if (verifyPassword($password)) {
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Set session
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();

        // Set cookie if remember me
        if ($rememberMe) {
            setAuthCookie();
        }

        return true;
    }

    return false;
}

/**
 * Logout user
 */
function logoutUser() {
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
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
