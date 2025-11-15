<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/version.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already authenticated, redirect to main page
if (isAuthenticated()) {
    header('Location: /index.php');
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);

    if (loginUser($username, $password, $rememberMe)) {
        header('Location: /index.php');
        exit;
    } else {
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - NOC Scheduler</title>
    <link rel="stylesheet" href="public/css/style.css?v=<?php echo CACHE_VERSION; ?>">
    <style>
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 0;
        }

        .login-card {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .login-header .subtitle {
            color: var(--text-light);
            font-size: 0.9em;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 1em;
            box-sizing: border-box;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="password"]:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 25px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .login-btn {
            width: 100%;
            padding: 14px;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .login-btn:hover {
            background: #2980b9;
        }

        .error-message {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }

        .version-info {
            text-align: center;
            margin-top: 20px;
            color: var(--text-light);
            font-size: 0.85em;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 50px 20px;
            }

            .login-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>NOC Scheduler</h1>
                <p class="subtitle">Network Operations Center</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="remember_me" name="remember_me" checked>
                    <label for="remember_me">Keep me logged in</label>
                </div>

                <button type="submit" class="login-btn">Login</button>
            </form>

            <div class="version-info">
                Version <?php echo APP_VERSION; ?>
            </div>
        </div>
    </div>
</body>
</html>
