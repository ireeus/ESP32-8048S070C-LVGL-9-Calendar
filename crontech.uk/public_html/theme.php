<?php
// theme.php - merged into settings.php.
//
// The device theme, the per-user calendar theme and the automatic-update policy
// now live together on the Themes tab, so this page only redirects. Kept as a
// file because firmware.php and older bookmarks still point at it.
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

header('Location: settings.php?tab=themes');
exit;
