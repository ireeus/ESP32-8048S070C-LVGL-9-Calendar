<?php
/**
 * device-theme.php
 *
 * Returns the calendar theme for the user who owns the device identified by
 * ?code=<access_code>. Read-only, so the device can poll it safely.
 *
 * This is what makes a theme chosen in settings.php -> Themes reach the ESP32 as
 * well as the web calendar. The lookup mirrors api.php?code=..., which already
 * resolves an access code to a user, so the theme stays per-user: changing your
 * theme changes your own device and your own calendar, nobody else's.
 *
 *   GET device-theme.php?code=ABC123
 *   -> { "scheme": "Teal", "darkness": 30, "source": "user" }
 *
 * `source` is "user" when the owner has saved a theme, or "default" when they
 * have not and the legacy global update/theme.json is being served instead.
 */

include 'config.php';

header('Content-Type: application/json');

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'code required']);
    exit;
}

try {
    $stmt = $db->prepare("SELECT user_id FROM users WHERE access_code = ?");
    $stmt->execute([$code]);
    $user_id = $stmt->fetchColumn();

    if (!$user_id) {
        http_response_code(404);
        echo json_encode(['error' => 'unknown access code']);
        exit;
    }

    $theme = null;
    try {
        $stmt = $db->prepare("SELECT scheme, darkness FROM user_theme WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $theme = [
                'scheme'   => (string)$row['scheme'],
                'darkness' => (int)$row['darkness'],
                'source'   => 'user',
            ];
        }
    } catch (PDOException $e) {
        // user_theme does not exist yet (settings.php creates it) - fall through
        // to the default below.
    }

    if ($theme === null) {
        // Nobody has saved a theme for this account yet, so keep serving whatever
        // the old global file said. Devices therefore keep their current colours
        // until their owner chooses one.
        $legacy = @json_decode((string)@file_get_contents('update/theme.json'), true);
        $theme = [
            'scheme'   => (is_array($legacy) && !empty($legacy['scheme'])) ? (string)$legacy['scheme'] : 'Blue',
            'darkness' => (is_array($legacy) && isset($legacy['darkness'])) ? (int)$legacy['darkness'] : 0,
            'source'   => 'default',
        ];
    }

    echo json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'database error']);
}
