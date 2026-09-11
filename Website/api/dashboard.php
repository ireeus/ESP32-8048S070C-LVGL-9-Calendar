<?php
// dashboard.php - Refactored: process before output
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$show_access_code = isset($_SESSION['access_code']);

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

header("Location: calendar.php");
exit;
?>

<?php include 'header.php'; ?>

<?php if ($show_access_code): ?>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-4 text-center">Welcome to Your Dashboard</h1>
    <p class="text-gray-600 mb-4 text-center">Your access code is:</p>
    <p class="code-box text-lg sm:text-2xl text-blue-600 p-4 rounded text-center"><?php echo htmlspecialchars($_SESSION['access_code']); ?></p>
    <?php unset($_SESSION['access_code']); ?>
<?php endif; ?>

<?php include 'footer.php'; ?>