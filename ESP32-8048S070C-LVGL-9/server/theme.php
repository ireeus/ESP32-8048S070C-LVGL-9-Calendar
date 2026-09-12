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

// Atomic write: temp file then rename, so a device polling mid-write can never
// read a half-written file. Same pattern firmware.php uses for version.json.
function publish_json(string $path, array $data): bool {
    $tempFile = tempnam(sys_get_temp_dir(), 'cfg_');
    if ($tempFile === false) {
        return false;
    }
    if (file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        @unlink($tempFile);
        return false;
    }
    if (!rename($tempFile, $path)) {
        @unlink($tempFile);
        return false;
    }
    @chmod($path, 0644);
    return true;
}

function read_json(string $path, array $default): array {
    if (!file_exists($path)) {
        return $default;
    }
    $loaded = json_decode((string)file_get_contents($path), true);
    return is_array($loaded) ? array_merge($default, $loaded) : $default;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$themePath  = 'update/theme.json';
$policyPath = 'update/policy.json';

$theme  = read_json($themePath, ['revision' => 1, 'scheme' => 'Blue', 'darkness' => 0]);
$policy = read_json($policyPath, [
    'revision'             => 1,
    'auto_firmware_update' => false,
    'quiet_start'          => 2,
    'quiet_end'            => 5,
]);

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token_ok = !empty($_POST['csrf']) && !empty($_SESSION['dev_csrf'])
                && hash_equals($_SESSION['dev_csrf'], (string)$_POST['csrf']);
    if (!$token_ok) {
        $error_message = 'Security token expired. Please reload the page and try again.';

    } elseif (isset($_POST['save_theme'])) {
        $scheme   = isset($_POST['scheme']) ? (string)$_POST['scheme'] : '';
        $darkness = isset($_POST['darkness']) ? (int)$_POST['darkness'] : 0;

        if (!array_key_exists($scheme, $SCHEMES)) {
            $error_message = 'Unknown colour scheme.';
        } elseif ($darkness < 0 || $darkness > 100) {
            $error_message = 'Brightness must be between 0 and 100.';
        } elseif ((string)$theme['scheme'] === $scheme && (int)$theme['darkness'] === $darkness) {
            $success_message = 'No change - nothing published.';
        } else {
            // Bump the revision only on a real change, so devices do not
            // re-apply (and override a local pick) for nothing.
            $newTheme = [
                'revision' => ((int)$theme['revision']) + 1,
                'scheme'   => $scheme,
                'darkness' => $darkness,
                'updated'  => gmdate('c'),
            ];
            if (publish_json($themePath, $newTheme)) {
                $theme           = $newTheme;
                $success_message = 'Appearance saved. Devices pick it up within about a minute.';
            } else {
                $error_message = 'Could not write update/theme.json. Check the update/ directory permissions.';
            }
        }

    } elseif (isset($_POST['save_policy'])) {
        $autoUpdate = isset($_POST['auto_firmware_update']) && $_POST['auto_firmware_update'] === '1';
        $qStart     = isset($_POST['quiet_start']) ? (int)$_POST['quiet_start'] : 2;
        $qEnd       = isset($_POST['quiet_end']) ? (int)$_POST['quiet_end'] : 5;

        if ($qStart < 0 || $qStart > 23 || $qEnd < 0 || $qEnd > 23) {
            $error_message = 'Quiet-window hours must be between 0 and 23.';
        } elseif ((bool)$policy['auto_firmware_update'] === $autoUpdate
                  && (int)$policy['quiet_start'] === $qStart
                  && (int)$policy['quiet_end'] === $qEnd) {
            $success_message = 'No change - nothing published.';
        } else {
            $newPolicy = [
                'revision'             => ((int)$policy['revision']) + 1,
                'auto_firmware_update' => $autoUpdate,
                'quiet_start'          => $qStart,
                'quiet_end'            => $qEnd,
                'updated'              => gmdate('c'),
            ];
            if (publish_json($policyPath, $newPolicy)) {
                $policy          = $newPolicy;
                $success_message = 'Update policy saved. Devices check it within about 100 seconds.';
            } else {
                $error_message = 'Could not write update/policy.json. Check the update/ directory permissions.';
            }
        }
    }
}

if (empty($_SESSION['dev_csrf'])) {
    $_SESSION['dev_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['dev_csrf'];

$activeScheme   = array_key_exists((string)$theme['scheme'], $SCHEMES) ? (string)$theme['scheme'] : 'Blue';
$activeDarkness = max(0, min(100, (int)$theme['darkness']));
$previewHex     = $SCHEMES[$activeScheme];
$previewGray    = 255 - (int)round($activeDarkness * 255 / 100);
$textOnGray     = $activeDarkness > 50 ? '#FFFFFF' : '#000000';

$autoUpdate  = (bool)$policy['auto_firmware_update'];
$qStart      = max(0, min(23, (int)$policy['quiet_start']));
$qEnd        = max(0, min(23, (int)$policy['quiet_end']));
// The device treats start == end as "never".
$windowNever = ($qStart === $qEnd);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Device Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-900 to-blue-500 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Device Portal</h1>
            <div class="flex gap-3 text-sm">
                <a href="firmware.php" class="text-blue-600 hover:underline">Firmware upload</a>
                <a href="?logout=1" class="text-red-600 hover:underline">Logout</a>
            </div>
        </div>

        <?php if ($success_message !== ''): ?>
            <div class="mb-4 rounded-lg bg-green-100 border border-green-300 text-green-800 px-4 py-3 text-sm">
                <?= h($success_message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error_message !== ''): ?>
            <div class="mb-4 rounded-lg bg-red-100 border border-red-300 text-red-800 px-4 py-3 text-sm">
                <?= h($error_message) ?>
            </div>
        <?php endif; ?>

        <h2 class="text-lg font-semibold text-gray-800 mb-1">Appearance</h2>
        <p class="text-gray-600 text-sm mb-4">
            Sets the default colour scheme. A colour chosen on the device itself wins until you
            change the value here, then this is re-applied.
        </p>

        <form method="post" class="space-y-5 mb-8">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

            <div>
                <label for="scheme" class="block text-sm font-semibold text-gray-700 mb-2">Colour scheme</label>
                <select id="scheme" name="scheme"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($SCHEMES as $name => $hex): ?>
                        <option value="<?= h($name) ?>" <?= $name === $activeScheme ? 'selected' : '' ?>>
                            <?= h($name) ?>
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
                <div id="screenPreview" class="rounded-lg border border-gray-300 h-20 flex items-center justify-center"
                     style="background: rgb(<?= $previewGray ?>,<?= $previewGray ?>,<?= $previewGray ?>); color: <?= $textOnGray ?>;">
                    <span class="inline-block w-9 h-9 rounded-full mr-3"
                          style="background: <?= h($previewHex) ?>;"></span>
                    <span class="font-semibold">Accent + screen</span>
                </div>
            </div>

            <button type="submit" name="save_theme" value="1"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition">
                Save appearance
            </button>
        </form>

        <h2 class="text-lg font-semibold text-gray-800 mb-1">Updates</h2>
        <p class="text-gray-600 text-sm mb-4">
            Upload a new firmware on the <a href="firmware.php" class="text-blue-600 hover:underline">Firmware upload</a>
            page. By default the device only shows an update button and waits to be pressed. Switch this on
            and it installs a newer firmware by itself &mdash; but only inside the quiet window, so a
            wall-mounted calendar is not rebooted while somebody is using it.
        </p>

        <form method="post" class="space-y-5">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="auto_firmware_update" value="1" <?= $autoUpdate ? 'checked' : '' ?>
                       class="mt-1 w-4 h-4">
                <span>
                    <span class="font-semibold text-gray-800">Install firmware updates automatically</span>
                    <span class="block text-sm text-gray-600">
                        The device updates on its next check and reboots itself. It retries at most every
                        15 minutes, and a failed attempt leaves a Retry prompt on screen.
                    </span>
                </span>
            </label>

            <div class="flex flex-wrap items-center gap-4">
                <div>
                    <label for="quiet_start" class="block text-sm font-semibold text-gray-700 mb-2">Quiet window from</label>
                    <select id="quiet_start" name="quiet_start"
                            class="border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <?php for ($i = 0; $i < 24; $i++): ?>
                            <option value="<?= $i ?>" <?= $i === $qStart ? 'selected' : '' ?>><?= sprintf('%02d:00', $i) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label for="quiet_end" class="block text-sm font-semibold text-gray-700 mb-2">until</label>
                    <select id="quiet_end" name="quiet_end"
                            class="border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <?php for ($i = 0; $i < 24; $i++): ?>
                            <option value="<?= $i ?>" <?= $i === $qEnd ? 'selected' : '' ?>><?= sprintf('%02d:00', $i) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <p class="text-sm <?= $windowNever ? 'text-amber-700 font-semibold' : 'text-gray-500' ?>">
                <?php if ($windowNever): ?>
                    Start and end are the same, so the window is empty and automatic updates will
                    never run &mdash; set different hours to enable them.
                <?php else: ?>
                    Local device time. A window that runs past midnight is fine (e.g. 23:00 to 04:00).
                <?php endif; ?>
            </p>

            <button type="submit" name="save_policy" value="1"
                    class="w-full bg-gray-800 hover:bg-gray-900 text-white font-semibold py-3 rounded-lg transition">
                Save update policy
            </button>
        </form>

        <div class="mt-8 pt-4 border-t border-gray-200 text-xs text-gray-500 grid md:grid-cols-2 gap-4">
            <div>
                <p class="font-semibold text-gray-600 mb-1">update/theme.json (revision <?= (int)$theme['revision'] ?>)</p>
                <pre class="bg-gray-50 rounded p-3 overflow-x-auto"><?= h(json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            </div>
            <div>
                <p class="font-semibold text-gray-600 mb-1">update/policy.json (revision <?= (int)$policy['revision'] ?>)</p>
                <pre class="bg-gray-50 rounded p-3 overflow-x-auto"><?= h(json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            </div>
        </div>
    </div>
</body>
</html>
