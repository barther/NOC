<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/version.php';

// Require authentication
requireAuth();

// Get current user
$currentUser = getCurrentUser();
$isAdmin = isAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC Scheduler - Network Operations Center Scheduling System</title>
    <link rel="stylesheet" href="public/css/style.css?v=<?php echo CACHE_VERSION; ?>">
</head>
<body>
    <div class="container">
        <header>
            <h1>NOC Scheduler</h1>
            <p class="subtitle">Network Operations Center - 24/7 Coverage Management</p>
            <div class="version-stamp">v<?php echo APP_VERSION; ?></div>
            <div class="user-info">
                <span class="username"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                <span class="user-role">(<?php echo $isAdmin ? 'Admin' : 'Read-Only'; ?>)</span>
                <a href="/logout.php" class="logout-link">Logout</a>
            </div>
        </header>

        <nav class="main-nav">
            <button class="nav-btn active" data-view="schedule">Schedule</button>
            <button class="nav-btn" data-view="dispatchers">Dispatchers</button>
            <button class="nav-btn" data-view="desks">Desks</button>
            <button class="nav-btn" data-view="vacancies">Vacancies</button>
            <button class="nav-btn" data-view="holddowns">Hold-Downs</button>
            <button class="nav-btn" data-view="extraboard">Extra Board</button>
            <button class="nav-btn" data-view="config">Settings</button>
            <button class="nav-btn" data-view="help">Help</button>
        </nav>

        <main id="main-content">
            <!-- Content loaded dynamically -->
        </main>
    </div>

    <!-- Modals -->
    <div id="modal-container" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title">Modal Title</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div id="modal-body">
                <!-- Modal content loaded dynamically -->
            </div>
        </div>
    </div>

    <script>
        // Pass user role to JavaScript
        window.USER_ROLE = '<?php echo $currentUser['role']; ?>';
        window.USER_IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        window.CURRENT_USER = {
            id: <?php echo $currentUser['id']; ?>,
            username: '<?php echo addslashes($currentUser['username']); ?>',
            role: '<?php echo $currentUser['role']; ?>'
        };
    </script>
    <script src="public/js/app.js?v=<?php echo CACHE_VERSION; ?>"></script>
    <script>
        // Initialize the app
        document.addEventListener('DOMContentLoaded', function() {
            App.init();
        });
    </script>
</body>
</html>
