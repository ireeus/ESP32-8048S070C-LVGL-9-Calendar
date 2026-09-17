<?php
/**
 * background.php
 *
 * Tells the device which background picture to show.
 *
 *   GET background.php?background_img=<access_code>&wx=<weather_code>
 *   -> { "filename": "weather/rain.bin", "mode": "weather", "group": "rain", "refresh_s": 3600 }
 *   -> { "filename": "bg_ABC123.bin",    "mode": "custom",  "group": null,   "refresh_s": 300 }
 *   -> { "filename": null,               "mode": "none",    "group": null,   "refresh_s": 3600 }
 *
 * `filename` is relative to uploads/, which is where the device looks. `mode`
 * lets the firmware tell "you are on your own picture" from "you are on the
 * weather set", which is what decides whether a change in the weather should
 * pull a new picture down. `wx` is the device's current WMO weather code; the
 * code-to-group mapping lives in bg_common.php so the artwork can be re-grouped
 * without reflashing anything.
 *
 * `refresh_s` is how many seconds the device should wait before asking again.
 * It is set on the website (the rotation interval for the owner's own pictures)
 * and simply obeyed here, so the cadence is never under the control of whatever
 * has the upload password.
 *
 * Read-only, so the device can poll it safely, and it never 500s over a missing
 * picture: an absent file reports filename:null and the device keeps whatever it
 * already has.
 */

require __DIR__ . '/bg_common.php';

// Timestamps in the log are the site's local time, matching the rest of the site
// rather than whatever PHP defaults to, so they can be read next to the device's
// own serial output without mental arithmetic.
date_default_timezone_set('Europe/London');
bg_log_install_handlers();

/**
 * Answer the device and write that answer down in one step, so the log can never
 * drift from what actually went out. TAG is REQ for a normal answer and DENY for
 * a refusal, which makes `grep -v DENY` the device's real poll history.
 */
function bg_reply(string $ctx, array $payload, int $status = 200): void
{
    // Piggyback the firmware the site is offering on every good answer. The device
    // polls this once a minute anyway, so a new build reaches it in under a minute
    // instead of waiting for its own five-minute check, at no extra request. Purely
    // advisory: the fields are absent when the feed cannot be read, and the device
    // carries on with its own check.
    if ($status === 200 && !isset($payload['error'])) {
        $feed = bg_update_feed();
        if ($feed !== null) {
            $payload['fw_latest'] = $feed['version'];
            $payload['fw_url'] = $feed['url'];
        }
    }
    http_response_code($status);
    echo json_encode($payload);
    $out = [];
    foreach ($payload as $k => $v) {
        $out[] = $k . '=' . (is_null($v) ? 'null' : (string) $v);
    }
    bg_log($status === 200 ? 'REQ' : 'DENY', $ctx . ' -> ' . implode(' ', $out));
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['background_img'])) {
    bg_reply(bg_log_who(), ['error' => 'background_img parameter is required'], 400);
}

$accessCode = trim((string) $_GET['background_img']);
$wx = isset($_GET['wx']) && $_GET['wx'] !== '' ? (int) $_GET['wx'] : null;

/**
 * Optional fields the firmware sends to say who it is and how the LAST picture
 * went. They are what turns "the device stopped polling" into "the device stopped
 * polling, and the last thing it told us was that it was about to apply X". Not
 * trusted: newlines and control characters are stripped so nothing can forge log
 * lines, and the length is bounded.
 */
function bg_log_field(string $key, int $max = 64): string
{
    $v = isset($_GET[$key]) ? (string) $_GET[$key] : '';
    $v = preg_replace('/[^\x20-\x7E]/', '', $v);          // printable ASCII only
    if (strlen($v) > $max) $v = substr($v, 0, $max) . '..';
    return $v === '' ? '-' : $v;
}
$fw   = bg_log_field('fw', 24);        // firmware version, e.g. 2.3.9
$last = bg_log_field('last', 48);      // outcome of the previous picture
// What the device knows about firmware updates. "none:2.3.1" means the feed
// advertised 2.3.1 to a panel running something newer, so it correctly did
// nothing - the silent no-op that looks like updates being broken.
$upd  = bg_log_field('upd', 56);

$logCtx = sprintf('code=%s wx=%s fw=%s last=%s upd=%s %s', bg_log_code($accessCode),
                  $wx === null ? '-' : (string) $wx, $fw, $last, $upd, bg_log_who());

try {
    $db = new PDO('sqlite:access.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    bg_reply($logCtx, ['error' => 'database error: ' . $e->getMessage()], 500);
}

// background_mode is added by image_converter.php and may not exist on a site
// that has never opened that page, so fall back to the two-column select rather
// than letting the PDOException turn into "no background at all".
$row = null;
try {
    $stmt = $db->prepare("SELECT user_id, background_image, background_mode FROM users WHERE access_code = ?");
    $stmt->execute([$accessCode]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    try {
        // user_id is selected here too: it is what locates this account's own
        // weather pictures (uploads/weather/u<user_id>_<group>.bin).
        $stmt = $db->prepare("SELECT user_id, background_image FROM users WHERE access_code = ?");
        $stmt->execute([$accessCode]);
        $row = $stmt->fetch();
        if ($row) $row['background_mode'] = 'custom';
    } catch (PDOException $e2) {
        bg_log('ERROR', $logCtx . ' users lookup failed: ' . $e2->getMessage());
        $row = null;
    }
}

if (!$row) {
    bg_reply($logCtx, ['error' => 'unknown access code'], 404);
}

// The poll cadence is a property of the ACCOUNT's rotation setting, not of the
// mode, and it is worked out once here so that every answer below carries the same
// number. See bg_poll_interval() for why that matters: a mode change cannot reach
// a device that has been told to sleep for an hour.
$mode = ($row['background_mode'] ?? 'custom') === 'weather' ? 'weather' : 'custom';
$custom = trim((string) ($row['background_image'] ?? ''));
$userId = (int) ($row['user_id'] ?? 0);
$pollRefresh = bg_poll_interval($db, $userId);
$logCtx = sprintf('user=%d code=%s wx=%s fw=%s last=%s upd=%s mode=%s refresh=%d %s',
                  $userId, bg_log_code($accessCode), $wx === null ? '-' : (string) $wx,
                  $fw, $last, $upd, $mode, $pollRefresh, bg_log_who());

// The trap that made "the pictures never change, whatever interval I pick" look
// like a broken rotation: the rotation belongs to the owner's OWN pictures, so
// with the account on the weather set no interval can ever change what the device
// is sent - it follows the sky, and refresh_s is the plain hourly poll. The site
// cannot switch the mode on the user's behalf, but it can say so, with numbers.
if ($mode === 'weather') {
    $galleryCount = bg_gallery_count($db, $userId);
    $rotate = bg_rotate_seconds($db, $userId);
    if ($galleryCount > 0 && $rotate > 0) {
        bg_log('WARN', sprintf('%s | rotation=%ds and %d own picture(s) are set up, but the mode is "weather": '
                             . 'the device follows the weather and will NEVER rotate. Switch to "My own pictures" '
                             . 'on image_converter.php', $logCtx, $rotate, $galleryCount));
    }
}

// Every filename goes out with a ?v=<mtime> on it: the device caches one picture
// and re-downloads only when the NAME changes, and a replacement is written to
// the same path. See bg_versioned().
//
// The owner's own pictures. bg_custom_choice() picks one from the gallery for the
// current rotation slot - or falls back to the old single-picture column for an
// account that has not opened the upload page since the gallery arrived - and says
// how long the device should wait before asking again.
if ($mode === 'custom') {
    $choice = bg_custom_choice($db, $userId, $custom);
    if ($choice !== null) {
        $safe = (string) $choice['file'];
        // bg_heal_bin() re-encodes the .bin from its preview PNG when the stored one
        // is missing or was written at an older size - the stored size has changed
        // before now, and a stale file would simply be refused by the device. It is
        // a no-op when the .bin is already correct.
        if (bg_heal_bin('uploads/' . pathinfo($safe, PATHINFO_FILENAME))) {
            $path = __DIR__ . '/uploads/' . $safe;
            bg_reply($logCtx, [
                'filename'  => bg_versioned($safe, $path),
                'mode'      => 'custom',
                'group'     => null,
                // Obeyed by the device, so the cadence is set entirely from here.
                'refresh_s' => (int) $pollRefresh,
            ]);
        }
        bg_log('ERROR', $logCtx . ' | chose ' . $safe . ' but it could not be healed (missing preview PNG?) - '
                                  . 'falling back to the weather set');
    } else {
        bg_log('WARN', $logCtx . ' | mode is custom but there is no usable picture '
                               . '(no gallery rows and no legacy file) - falling back to the weather set');
    }
    // Nothing usable on the account: fall through to the weather set rather than
    // leaving the device with no background.
}

// Weather set. No wx (an older firmware) means the neutral cloud picture.
$group = $wx === null ? 'cloudy' : bg_weather_group($wx);
if (!bg_weather_file_exists($group)) {
    bg_log('WARN', $logCtx . ' | no picture for group "' . $group . '" - trying "unknown"');
    $group = bg_weather_file_exists('unknown') ? 'unknown' : null;
}

if ($group === null) {
    bg_reply($logCtx, ['filename' => null, 'mode' => 'none', 'group' => null, 'refresh_s' => (int) $pollRefresh]);
}

// This account's own replacement for the chosen group wins over the generated
// default. Same size check as the custom background, and the same repair: only a
// file of exactly the right size is ever handed to the device.
$overrideRel = 'weather/' . basename(bg_weather_override_base($userId, $group)) . '.bin';
// BG_WEATHER_DIR + the bare name: bg_heal_bin() wants a path without the extension,
// and taking pathinfo() of the "weather/..." relative name dropped the directory.
$overrideBase = BG_WEATHER_DIR . '/' . basename(bg_weather_override_base($userId, $group));
if ($userId > 0 && bg_heal_bin($overrideBase)) {
    $overrideAbs = __DIR__ . '/uploads/' . $overrideRel;
    bg_reply($logCtx, [
        'filename'  => bg_versioned($overrideRel, $overrideAbs),
        'mode'      => 'weather',
        'group'     => $group,
        // The picture does not rotate, but the DEVICE still polls on the account's
        // rotation interval - that is what lets a switch back to "My own pictures"
        // be noticed within a minute instead of an hour.
        'refresh_s' => (int) $pollRefresh,
    ]);
}

$defaultRel = bg_weather_filename($group);
// Same repair for the shipped default: if the deployed .bin predates the current
// stored size, rebuild it from the preview PNG beside it.
if (!bg_heal_bin(BG_WEATHER_DIR . '/' . $group)) {
    bg_log('ERROR', $logCtx . ' | shipped default ' . $group . '.bin could not be healed - '
                            . 'the device will likely refuse it as the wrong size');
}
bg_reply($logCtx, [
    'filename'  => bg_versioned($defaultRel, __DIR__ . '/uploads/' . $defaultRel),
    'mode'      => 'weather',
    'group'     => $group,
    'refresh_s' => (int) $pollRefresh,
]);
