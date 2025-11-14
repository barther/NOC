<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NOC Scheduler - Network Operations Center Scheduling System</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>NOC Scheduler</h1>
            <p class="subtitle">Network Operations Center - 24/7 Coverage Management</p>
        </header>

        <nav class="main-nav">
            <button class="nav-btn active" data-view="schedule">Schedule</button>
            <button class="nav-btn" data-view="dispatchers">Dispatchers</button>
            <button class="nav-btn" data-view="desks">Desks</button>
            <button class="nav-btn" data-view="vacancies">Vacancies</button>
            <button class="nav-btn" data-view="holddowns">Hold-Downs</button>
            <button class="nav-btn" data-view="config">Settings</button>
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

    <script src="public/js/app.js"></script>
    <script>
        // Initialize the app
        document.addEventListener('DOMContentLoaded', function() {
            App.init();
        });
    </script>
</body>
</html>
