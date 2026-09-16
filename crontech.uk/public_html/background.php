<?php
/**
 * background.php
 *
 * Tells the device which background picture to show.
 *
 *   GET background.php?background_img=<access_code>&wx=<weather_code>
 *   -> { "filename": "weather/rain.bin", "mode": "weather", "group": "rain" }
 *   -> { "filename": "bg_ABC123.bin",    "mode": "custom",  "group": null }
 *   -> { "filename": null,               "mode": "none",    "group": null }
 *
 * `filename` is relative to uploads/, which is where the device looks. `mode`
 * lets the firmware tell "you are on your own picture" from "you are on the
 * weather set", which is what decides whether a change in the weather should
 * pull a new picture down. `wx` is the device's current WMO weather code; the
 * code-to-group mapping lives in bg_common.php so the artwork can be re-grouped
 * without reflashing anything.
 *
 * Read-only, so the device can poll it safely, and it never 500s over a missing
 * picture: an absent file reports filename:null and the device keeps whatever it
 * already has.
 */

require __DIR__ . '/bg_common.php';

header('Content-Type: application/json');

if (!isset($_GET['background_img'])) {
    http_response_code(400);
    echo json_encode(['error' => 'background_img parameter is required']);
    exit;
}

$accessCode = trim((string) $_GET['background_img']);
$wx = isset($_GET['wx']) && $_GET['wx'] !== '' ? (int) $_GET['wx'] : null;

try {
    $db = new PDO('sqlite:access.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'database error']);
    exit;
}

// background_mode is added by image_converter.php and may not exist on a site
// that has never opened that page, so fall back to the two-column select rather
// than letting the PDOException turn into "no background at all".
$row = null;
try {
    $stmt = $db->prepare("SELECT background_image, background_mode FROM users WHERE access_code = ?");
    $stmt->execute([$accessCode]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    try {
        $stmt = $db->prepare("SELECT background_image FROM users WHERE access_code = ?");
        $stmt->execute([$accessCode]);
        $row = $stmt->fetch();
        if ($row) $row['background_mode'] = 'custom';
    } catch (PDOException $e2) {
        $row = null;
    }
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'unknown access code']);
    exit;
}

$mode = ($row['background_mode'] ?? 'custom') === 'weather' ? 'weather' : 'custom';
$custom = trim((string) ($row['background_image'] ?? ''));

// The owner's own picture, when that is the mode and the file is really there.
if ($mode === 'custom' && $custom !== '') {
    $safe = basename($custom);
    $path = __DIR__ . '/uploads/' . $safe;
    // The size is checked, not just existence. Anything left over from the old
    // converter is a 200x90 RGB565 file of about 36KB, which this firmware cannot
    // read at all; serving it would just make the device log "Invalid background
    // size" and show nothing. Falling through to the weather set instead means an
    // old account keeps a working background until its owner uploads a new one.
    if (is_file($path) && filesize($path) === BG_BYTES) {
        echo json_encode(['filename' => $safe, 'mode' => 'custom', 'group' => null]);
        exit;
    }
    // Pointed at a file that is gone or is the wrong format: fall through to the
    // weather set rather than leaving the device with no background.
}

// Weather set. No wx (an older firmware) means the neutral cloud picture.
$group = $wx === null ? 'cloudy' : bg_weather_group($wx);
if (!bg_weather_file_exists($group)) {
    $group = bg_weather_file_exists('unknown') ? 'unknown' : null;
}

if ($group === null) {
    echo json_encode(['filename' => null, 'mode' => 'none', 'group' => null]);
} else {
    echo json_encode([
        'filename' => bg_weather_filename($group),
        'mode'     => 'weather',
        'group'    => $group,
    ]);
}
