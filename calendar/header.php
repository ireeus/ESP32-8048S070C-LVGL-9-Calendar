<?php
// header.php - Unified header and navigation
include 'config.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$is_logged_in = isset($_SESSION['user_id']);
$page = basename($_SERVER['PHP_SELF'], '.php');
?>

    <nav class="nav-bar flex justify-center space-x-6">
        <?php if ($is_logged_in): ?>
            <a href="dashboard.php" class="nav-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="calendar.php" class="nav-link <?php echo $page === 'calendar' ? 'active' : ''; ?>">Calendar</a>
            <a href="weather.php" class="nav-link <?php echo $page === 'weather' ? 'active' : ''; ?>">Weather</a>
            <a href="settings.php" class="nav-link <?php echo $page === 'settings' ? 'active' : ''; ?>">Settings</a>
            <a href="?logout=1" class="nav-link">Logout</a>
        <?php endif; ?>
    </nav>
    <div class="card">