<?php
// menu.php - Unified menu for all pages
?>
<style>
.menu {
    color: #000000; /* Sets font color to black for the nav container */
}

.menu-items li a {
    color: #000000; /* Sets font color to black for menu links */
    text-decoration: none; /* Optional: removes underline from links */
}

.menu-toggle span {
    background-color: #000000; /* Sets the hamburger menu bars to black */
}
</style>
<nav class="menu">
    <div class="menu-toggle" id="mobile-menu">
        <span class="bar"></span>
        <span class="bar"></span>
        <span class="bar"></span>
    </div>
    <ul class="menu-items">
        <?php if (isset($_SESSION['user_id'])): ?>
            <li><a href="index.php">Home</a></li>
            <li><a href="weather.php">Weather</a></li>
            <li><a href="calendar.php">Calendar</a></li>
            <li><a href="settings.php">Settings</a></li>
			<li><a href="settings.php?logout=1">Logout</a></li>
        <?php else: ?>
            <li><a href="index.php">Home</a></li>
            <li><a href="offers.php#crontab">CronTab</a></li>
            <li><a href="offers.php#moonlight">Moonlight</a></li>
            <li><a href="login.php">Login</a></li>
            <li><a href="register.php">Register</a></li>
        <?php endif; ?>
    </ul>
</nav>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const mobileMenu = document.getElementById('mobile-menu');
    const menuItems = document.querySelector('.menu-items');
    if (mobileMenu && menuItems) {
        mobileMenu.addEventListener('click', () => {
            menuItems.classList.toggle('active');
        });
    }
});
</script>