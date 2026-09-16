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
        // brightness_auto arrived after the table existed, and its column is added
        // by settings.php. Until the owner opens that page once the column may not
        // exist, so fall back to the two-column select instead of letting the
        // PDOException reach the outer catch - that would report "no theme" and
        // silently reset the device to the legacy default scheme.
        $row = null;
        // panel_opa arrived after brightness_auto, and both columns are created by
        // settings.php. Fall back one step at a time rather than letting the
        // PDOException reach the outer catch, which would report "no theme" and
        // silently reset the device to the legacy default scheme.
        $attempts = [
            "SELECT scheme, darkness, brightness_auto, panel_opa FROM user_theme WHERE user_id = ?",
            "SELECT scheme, darkness, brightness_auto FROM user_theme WHERE user_id = ?",
            "SELECT scheme, darkness FROM user_theme WHERE user_id = ?",
        ];
        foreach ($attempts as $sql) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute([$user_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                break;
            } catch (PDOException $e) {
                $row = null;
            }
        }
        if ($row) {
            $theme = [
                'scheme'   => (string)$row['scheme'],
                'darkness' => (int)$row['darkness'],
                // True when the owner asked the device to follow daylight instead of
                // a fixed level. Absent column means off.
                'auto'     => isset($row['brightness_auto']) && (int)$row['brightness_auto'] === 1,
                // How see-through the device's panels are, 0-100. 100 is the solid
                // look it has always had; absent column means solid.
                'panel_opa' => isset($row['panel_opa']) ? max(0, min(100, (int)$row['panel_opa'])) : 100,
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
            'auto'     => false,
            'panel_opa' => 100,   // solid, the look the device has always had
            'source'   => 'default',
        ];
    }

    echo json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'database error']);
}
