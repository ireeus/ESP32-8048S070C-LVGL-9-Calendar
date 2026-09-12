<?php
include 'config.php';

// --- Access control: same rule as firmware.php ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$stmt = $db->prepare("SELECT username FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$admin_email = $stmt->fetchColumn();
if (strtolower((string)$admin_email) !== 'ireeus@gmail.com') {
    http_response_code(403);
    exit('Access denied. This area is restricted to the administrator.');
}

// These names are looked up by the device and must match color_schemes[] in
// src/main.cpp exactly, including the space in "Blue Grey".
$SCHEMES = [
    'Blue'      => '#2196F3',
    'Green'     => '#4CAF50',
    'Blue Grey' => '#607D8B',
    'Orange'    => '#FF9800',
    'Red'       => '#F44336',
    'Purple'    => '#9C27B0',
    'Teal'      => '#009688',
    'Indigo'    => '#3F51B5',
];

// Handle logout the same way firmware.php does
if (isset($_GET['logout'])) {
    $stmt = $db->prepare("DELETE FROM persistent_sessions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    setcookie('session_lock', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$themePath = 'update/theme.json';
$theme     = ['revision' => 1, 'scheme' => 'Blue', 'darkness' => 0];
if (file_exists($themePath)) {
    $loaded = json_decode((string)file_get_contents($themePath), true);
    if (is_array($loaded)) {
        $theme = array_merge($theme, $loaded);
    }
}

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_theme'])) {
    $token_ok = !empty($_POST['csrf']) && !empty($_SESSION['theme_csrf'])
                && hash_equals($_SESSION['theme_csrf'], (string)$_POST['csrf']);
    if (!$token_ok) {
        $error_message = 'Security token expired. Please reload the page and try again.';
    } else {
        $scheme   = isset($_POST['scheme']) ? (string)$_POST['scheme'] : '';
        $darkness = isset($_POST['darkness']) ? (int)$_POST['darkness'] : 0;

        if (!array_key_exists($scheme, $SCHEMES)) {
            $error_message = 'Unknown colour scheme.';
        } elseif ($darkness < 0 || $darkness > 100) {
            $error_message = 'Brightness must be between 0 and 100.';
        } else {
            $newTheme = [
                'revision' => ((int)$theme['revision']) + 1,
                'scheme'   => $scheme,
                'darkness' => $darkness,
                'updated'  => gmdate('c'),
            ];
            // Same atomic write pattern firmware.php uses for version.json:
            // write a temp file, then rename it into place so a device polling
            // mid-write can never read a half-written file.
            $tempFile = tempnam(sys_get_temp_dir(), 'theme_');
            if ($tempFile === false) {
                $error_message = 'Could not create a temporary file.';
            } else {
                file_put_contents($tempFile, json_encode($newTheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                if (rename($tempFile, $themePath)) {
                    @chmod($themePath, 0644);
                    $theme           = $newTheme;
                    $success_message = 'Saved. Devices pick this up within about 5 minutes.';
                } else {
                    @unlink($tempFile);
                    $error_message = 'Could not write update/theme.json. Check the update/ directory permissions.';
                }
            }
        }
    }
}

if (empty($_SESSION['theme_csrf'])) {
    $_SESSION['theme_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['theme_csrf'];

$activeScheme   = array_key_exists((string)$theme['scheme'], $SCHEMES) ? (string)$theme['scheme'] : 'Blue';
$activeDarkness = max(0, min(100, (int)$theme['darkness']));
$previewHex     = $SCHEMES[$activeScheme];
// The device renders brightness as a grey screen: gray = 255 - (darkness * 255 / 100)
// and flips its text to white above 50.
$previewGray  = 255 - (int)round($activeDarkness * 255 / 100);
$textOnGray   = $activeDarkness > 50 ? '#FFFFFF' : '#000000';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-900 to-blue-500 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Theme Portal</h1>
            <div class="flex gap-3 text-sm">
                <a href="firmware.php" class="text-blue-600 hover:underline">Firmware</a>
                <a href="?logout=1" class="text-red-600 hover:underline">Logout</a>
            </div>
        </div>

        <p class="text-gray-600 text-sm mb-6">
            Sets the default calendar colour scheme. A colour chosen on the device itself
            overrides this until you change the value here, then it is re-applied.
        </p>

        <?php if ($success_message !== ''): ?>
            <div class="mb-4 rounded-lg bg-green-100 border border-green-300 text-green-800 px-4 py-3 text-sm">
                <?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($error_message !== ''): ?>
            <div class="mb-4 rounded-lg bg-red-100 border border-red-300 text-red-800 px-4 py-3 text-sm">
                <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-6">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label for="scheme" class="block text-sm font-semibold text-gray-700 mb-2">Colour scheme</label>
                <select id="scheme" name="scheme"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($SCHEMES as $name => $hex): ?>
                        <option value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $name === $activeScheme ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="darkness" class="block text-sm font-semibold text-gray-700 mb-2">
                    UI brightness <span class="font-normal text-gray-500">(0 = light, 100 = dark)</span>
                </label>
                <div class="flex items-center gap-4">
                    <input type="range" id="darkness" name="darkness" min="0" max="100"
                           value="<?= $activeDarkness ?>" class="flex-1"
                           oninput="document.getElementById('darknessValue').textContent = this.value;
                                    var g = 255 - Math.round(this.value * 255 / 100);
                                    var box = document.getElementById('screenPreview');
                                    box.style.background = 'rgb(' + g + ',' + g + ',' + g + ')';
                                    box.style.color = this.value > 50 ? '#FFFFFF' : '#000000';">
                    <span id="darknessValue" class="w-10 text-right font-mono text-gray-700"><?= $activeDarkness ?></span>
                </div>
            </div>

            <div>
                <span class="block text-sm font-semibold text-gray-700 mb-2">Preview</span>
                <div id="screenPreview" class="rounded-lg border border-gray-300 h-24 flex items-center justify-center"
                     style="background: rgb(<?= $previewGray ?>,<?= $previewGray ?>,<?= $previewGray ?>); color: <?= $textOnGray ?>;">
                    <span class="inline-block w-10 h-10 rounded-full mr-3"
                          style="background: <?= htmlspecialchars($previewHex, ENT_QUOTES, 'UTF-8') ?>;"></span>
                    <span class="font-semibold">Accent + screen</span>
                </div>
            </div>

            <button type="submit" name="save_theme" value="1"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition">
                Save theme
            </button>
        </form>

        <div class="mt-6 pt-4 border-t border-gray-200 text-xs text-gray-500">
            <p>Currently published as <code>update/theme.json</code> (revision <?= (int)$theme['revision'] ?>):</p>
            <pre class="mt-2 bg-gray-50 rounded p-3 overflow-x-auto"><?= htmlspecialchars(json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
        </div>
    </div>
</body>
</html>
