<?php
/**
 * bg_common.php - shared helpers for the device background picture.
 *
 * The device blits one fixed thing: an 800x480 image in raw RGB565, little-endian,
 * with no header. LVGL is handed a pointer straight at it, and the ESP32-S3 is
 * little-endian, so the file on the server is byte-for-byte what the panel shows.
 *
 * The file is STORED and SENT at a fraction of the panel resolution. About a
 * seventh of the bytes means a seventh of the download, a seventh of the flash
 * write on the device (the slow part, which has to happen behind a blacked-out
 * screen), and room for a hundred cached pictures instead of thirteen. The firmware
 * stretches it back to 800x480 once on arrival, so nothing else changes. Keep
 * BG_STORE_W/H in step with the same constants in main.cpp: a mismatch means the
 * device refuses every picture as the wrong length.
 *
 * Both the upload page and the device-facing background.php include this, so the
 * size and the weather mapping live in exactly one place.
 */

// A library, not a page. Requesting it directly (rather than including it) does
// nothing useful, so say so instead of answering 200 with an empty body.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('This file is included by other pages; it is not meant to be opened directly.');
}

define('BG_W', 800);                          // the panel, and the preview size
define('BG_H', 480);
define('BG_STORE_W', 300);                    // what is downloaded and cached
define('BG_STORE_H', 180);
define('BG_BYTES', BG_STORE_W * BG_STORE_H * 2);  // 108000
define('BG_WEATHER_DIR', 'uploads/weather');  // holds <group>.bin and <group>.png

/* ===========================================================================
 * Diagnostics: one log file, outside the web root
 * ===========================================================================
 *
 * The device is a black box on the other end of a TLS connection: when it stops
 * doing what the site says, the only evidence is what it asked for and when. This
 * writes that down, plus anything PHP or the page itself trips over, to
 * logs/error.log - one level up from public_html, so it cannot be fetched over
 * HTTP, and readable over FTP for exactly the sort of "why is it not rotating?"
 * question that is otherwise guesswork.
 *
 * Every line is "timestamp  TAG  message", so `grep REQ` gives the poll history,
 * `grep -E 'ERROR|FATAL|PHP'` gives the problems, and the gaps between REQ lines
 * are the device's real polling interval - which is how you tell whether the
 * firmware you flashed is the one honouring refresh_s.
 *
 * Nothing here may ever break the endpoint: a log that cannot be written is
 * silence, not an error.
 */
define('BG_LOG_FILE', dirname(__DIR__) . '/logs/bg-error.log');
define('BG_LOG_MAX_BYTES', 512 * 1024);

function bg_log(string $tag, string $message): void
{
    $line = sprintf("%s  %-6s %s\n", date('Y-m-d H:i:s'), $tag, $message);
    clearstatcache(true, BG_LOG_FILE);
    $size = @filesize(BG_LOG_FILE);
    if ($size !== false && $size > BG_LOG_MAX_BYTES) {
        // One generation is enough: the previous 512KB stays as .1 while the new
        // file starts empty, so the tail is never lost to the cap.
        @rename(BG_LOG_FILE, BG_LOG_FILE . '.1');
    }
    @file_put_contents(BG_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/** Who is asking. The access code is the device's API key, so only enough of it
 *  to tell two devices apart is ever written down. */
function bg_log_who(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($ua) > 70) $ua = substr($ua, 0, 70) . '...';
    return sprintf('ip=%s ua="%s"', $ip, $ua);
}

function bg_log_code(string $code): string
{
    if ($code === '') return '(none)';
    return strlen($code) > 4 ? substr($code, 0, 4) . '...' : $code;
}

/**
 * PHP's own complaints, into the same file. Returning true keeps them out of the
 * response body as well: a warning printed before the JSON would otherwise make
 * the device fail to parse it, which looks exactly like "the picture did not
 * change" from the panel's side.
 */
function bg_log_install_handlers(): void
{
    set_error_handler(function ($no, $str, $file, $line) {
        if (!(error_reporting() & $no)) return true;    // respect @suppression
        bg_log('PHP', sprintf('%s in %s:%d', $str, basename((string) $file), (int) $line));
        return true;
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            bg_log('FATAL', sprintf('%s in %s:%d', $e['message'],
                                    basename((string) $e['file']), (int) $e['line']));
        }
    });
}

/**
 * WMO weather code -> background group.
 *
 * The device sends its current code; the mapping lives here rather than in the
 * firmware so the artwork can be re-grouped without a reflash. The codes are the
 * ones Open-Meteo returns, which is also what the device already stores.
 */
function bg_weather_group($code): string
{
    $code = (int) $code;
    if ($code === 0)                          return 'clear';   // clear sky
    if ($code === 1 || $code === 2)           return 'partly';  // mainly clear, partly cloudy
    if ($code === 3)                          return 'cloudy';  // overcast
    if ($code === 45 || $code === 48)         return 'fog';
    if ($code >= 51 && $code <= 67)           return 'rain';    // drizzle, rain, freezing
    if ($code >= 71 && $code <= 77)           return 'snow';
    if ($code >= 80 && $code <= 82)           return 'rain';    // rain showers
    if ($code === 85 || $code === 86)         return 'snow';    // snow showers
    if ($code >= 95 && $code <= 99)           return 'storm';   // thunderstorm, with hail
    return 'unknown';
}

/**
 * The weather file as the DEVICE should ask for it: relative to uploads/, which
 * is the base the firmware prepends. Leading "uploads/" here would be doubled on
 * the device into uploads/uploads/...
 */
function bg_weather_filename(string $group): string
{
    return 'weather/' . $group . '.bin';
}

/** Groups that make-weather-backgrounds.php generates, in gallery order. */
function bg_weather_groups(): array
{
    return ['clear', 'partly', 'cloudy', 'fog', 'rain', 'snow', 'storm', 'unknown'];
}

/** Human label for a group, for the preview gallery. */
function bg_weather_label(string $group): string
{
    $labels = [
        'clear' => 'Clear', 'partly' => 'Partly cloudy', 'cloudy' => 'Overcast',
        'fog' => 'Fog', 'rain' => 'Rain', 'snow' => 'Snow',
        'storm' => 'Thunderstorm', 'unknown' => 'Unknown',
    ];
    return $labels[$group] ?? ucfirst($group);
}

/**
 * A GD image as the device's exact byte stream: resampled to 800x480, then
 * packed as RGB565 little-endian. Resampling here rather than trusting the
 * caller means a blob of the wrong length can never be written.
 *
 * @return string BG_BYTES bytes
 */
function bg_rgb565_bytes($img, int $srcW, int $srcH): string
{
    $canvas = imagecreatetruecolor(BG_W, BG_H);
    // A PNG with transparency would otherwise composite onto black; the panel
    // has no alpha, so flatten onto black deliberately rather than by accident.
    imagealphablending($canvas, false);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 0, 0, 0));
    // Down to the stored size, with resampling (not nearest) so the reduction is
    // anti-aliased rather than picking every other pixel.
    imagecopyresampled($canvas, $img, 0, 0, 0, 0, BG_STORE_W, BG_STORE_H, $srcW, $srcH);

    $out = '';
    for ($y = 0; $y < BG_STORE_H; $y++) {
        $row = '';
        for ($x = 0; $x < BG_STORE_W; $x++) {
            $c = imagecolorat($canvas, $x, $y);
            $v = ((($c >> 16) & 0xF8) << 8) | ((($c >> 8) & 0xFC) << 3) | (($c & 0xFF) >> 3);
            $row .= chr($v & 0xFF) . chr(($v >> 8) & 0xFF);   // low byte first
        }
        $out .= $row;
    }
    imagedestroy($canvas);
    return $out;
}

/**
 * Write <base>.bin (the device blob) and <base>.png (the web preview).
 * Returns false if either write fails, so callers can report it honestly rather
 * than leaving the database pointing at a file that is not there.
 *
 * The preview is kept at the FULL panel size: it is only ever shown in a browser,
 * and it is the master the .bin can be rebuilt from if the stored size ever
 * changes again (see bg_heal_bin).
 */
function bg_write_pair(string $base, $img, int $srcW, int $srcH): bool
{
    $bin = bg_rgb565_bytes($img, $srcW, $srcH);
    if (strlen($bin) !== BG_BYTES) {
        return false;
    }
    if (file_put_contents($base . '.bin', $bin) === false) {
        return false;
    }
    $preview = imagecreatetruecolor(BG_W, BG_H);
    imagecopyresampled($preview, $img, 0, 0, 0, 0, BG_W, BG_H, $srcW, $srcH);
    $ok = imagepng($preview, $base . '.png');
    imagedestroy($preview);
    return (bool) $ok;
}

/**
 * Make sure a .bin exists at the CURRENT size, rebuilding it from its preview PNG
 * if not.
 *
 * The stored size has changed once already (full panel -> half), and a stale .bin
 * is worse than useless: the device would reject it and show nothing. The preview
 * beside it is the full-quality master, so the fix is a re-encode rather than
 * asking the owner to upload again.
 *
 * @param string $base  path WITHOUT the extension, relative to this directory.
 */
function bg_heal_bin(string $base): bool
{
    $abs = __DIR__ . '/' . $base;
    if (is_file($abs . '.bin') && filesize($abs . '.bin') === BG_BYTES) {
        return true;
    }
    if (!is_file($abs . '.png')) {
        return false;
    }
    $img = @imagecreatefrompng($abs . '.png');
    if ($img === false) {
        return false;
    }
    $ok = bg_write_pair($abs, $img, imagesx($img), imagesy($img));
    imagedestroy($img);
    return $ok && is_file($abs . '.bin') && filesize($abs . '.bin') === BG_BYTES;
}

/** True when the group's generated default exists on disk. Anchored on __DIR__
 *  so it holds even if a caller changes the working directory. */
function bg_weather_file_exists(string $group): bool
{
    return is_file(__DIR__ . '/' . BG_WEATHER_DIR . '/' . $group . '.bin');
}

/**
 * Per-user replacement for one weather group:
 * uploads/weather/u<user_id>_<group>.bin
 *
 * Keyed on the user id rather than the access code on purpose: the finished file
 * is fetched from a public URL, and the access code is the device's API key.
 *
 * It is a REPLACEMENT of that group only. Deleting it puts the generated default
 * back, which is what the Reset button does.
 */
function bg_weather_override_base(int $userId, string $group): string
{
    return BG_WEATHER_DIR . '/u' . $userId . '_' . $group;
}

function bg_weather_override_exists(int $userId, string $group): bool
{
    return is_file(__DIR__ . '/' . bg_weather_override_base($userId, $group) . '.bin');
}

/** True for a group name the generator actually produces, so a posted value can
 *  never be used to build a path. */
function bg_weather_group_valid(string $group): bool
{
    return in_array($group, bg_weather_groups(), true);
}

/**
 * The filename to hand the device, with a ?v=<mtime> cache-buster on the end.
 *
 * The firmware caches ONE picture and decides whether it has to download again by
 * comparing the filename it is handed now against the one it cached. Replacing a
 * picture writes to the SAME path, so without this the device would keep showing
 * the old one from its flash cache and a new upload would appear to do nothing.
 * The query string changes whenever the file does, and a static file ignores it.
 */
function bg_versioned(string $relative, string $absolutePath): string
{
    $mtime = @filemtime($absolutePath);
    return $mtime ? ($relative . '?v=' . $mtime) : $relative;
}

/** WMO weather code -> short description, matching the wording the device uses. */
function bg_weather_description(int $code): string
{
    static $c = [
        0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
        45 => 'Fog', 48 => 'Depositing rime fog',
        51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
        56 => 'Light freezing drizzle', 57 => 'Dense freezing drizzle',
        61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
        66 => 'Light freezing rain', 67 => 'Heavy freezing rain',
        71 => 'Slight snow fall', 73 => 'Moderate snow fall', 75 => 'Heavy snow fall',
        77 => 'Snow grains',
        80 => 'Slight rain showers', 81 => 'Moderate rain showers', 82 => 'Violent rain showers',
        85 => 'Slight snow showers', 86 => 'Heavy snow showers',
        95 => 'Thunderstorm', 96 => 'Thunderstorm with slight hail',
        99 => 'Thunderstorm with heavy hail',
    ];
    return $c[$code] ?? 'Unknown';
}

/**
 * Make sure the little weather cache exists.
 *
 * Called by bg_current_weather() itself rather than left to one page's migration:
 * settings.php shows the same "what is on the panel right now" preview and never
 * runs the background page's setup, so the table has to follow the lookup.
 */
function bg_ensure_wx_cache(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS wx_cache (
            cache_key TEXT PRIMARY KEY,
            code INTEGER NOT NULL,
            fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $done = true;
    } catch (PDOException $e) {
        // Read-only database: the lookup below still works, it just is not cached.
        $done = true;
    }
}

/**
 * The weather group in force RIGHT NOW at a location, for the "showing now"
 * marker on the background page.
 *
 * It asks the same Open-Meteo endpoint for the same `current=weather_code` that
 * the firmware requests, so the marker agrees with what the panel is showing
 * rather than with a guess. Cached for ten minutes in wx_cache: opening the page
 * should not mean an upstream request every time.
 *
 * @return array{group:string,code:int,description:string}|null  null when the
 *         coordinates are unusable or the lookup fails.
 */
function bg_current_weather(PDO $db, float $lat, float $lon): ?array
{
    bg_ensure_wx_cache($db);

    $finish = function (int $code): array {
        return [
            'group'       => bg_weather_group($code),
            'code'        => $code,
            'description' => bg_weather_description($code),
        ];
    };

    $key = round($lat, 4) . '|' . round($lon, 4);
    try {
        $stmt = $db->prepare("SELECT code, fetched_at FROM wx_cache WHERE cache_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (time() - (int) strtotime((string) $row['fetched_at'] . ' UTC')) < 600) {
            return $finish((int) $row['code']);
        }
    } catch (PDOException $e) {
        // No cache table yet, or the read failed: fall through and just fetch.
    }

    $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
         . '&current=weather_code&timezone=auto';
    $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['current']['weather_code'])) {
        return null;   // offline, rate limited, or no location: the page copes
    }
    $code = (int) $data['current']['weather_code'];

    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO wx_cache (cache_key, code, fetched_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$key, $code]);
    } catch (PDOException $e) {
        // Caching is best effort; the answer above is still good.
    }
    return $finish($code);
}

/* ===========================================================================
 * The owner's own pictures, and how often the device swaps between them
 * ===========================================================================
 *
 * Several pictures per account, kept in uploads/ beside the weather ones and
 * listed in user_backgrounds. Which one the device gets is decided by the wall
 * clock, not by stored state: the set is walked in order on a timer, so every
 * device on the account agrees and nothing has to be updated when one is served.
 *
 * The interval lives ONLY here on the website. The device is told how long to
 * wait before asking again (see bg_custom_choice) and obeys, so anyone with the
 * upload page can set the cadence without needing the panel.
 */

/** How many of the owner's own pictures are kept. The device caches roughly 50
 *  pictures in the 9.875MB flash partition and the weather set shares that space,
 *  so this leaves room for both. */
define('BG_MAX_CUSTOM', 32);

/** What the device is told to wait when there is nothing to rotate (the weather
 *  set, a single picture): the standard hourly poll. */
define('BG_REFRESH_DEFAULT', 3600);

/** The slowest the device is ever asked to ask again, and the fastest. A daily
 *  rotation still refreshes hourly so a replacement is noticed the same day. */
define('BG_REFRESH_MAX', 3600);
define('BG_REFRESH_MIN', 60);

/** How often the device is asked to look when NOTHING is rotating. Short enough
 *  that a change made here - a fresh upload, or switching the panel back to "My
 *  own pictures" - is picked up in a few minutes rather than in an hour, and long
 *  enough that an idle account is not chatting to the server all day. */
define('BG_REFRESH_QUIET', 600);

/**
 * How long the device should wait before asking again.
 *
 * This follows the account's ROTATION SETTING, deliberately not the mode. That
 * distinction is the whole point: the mode lives on the server and only the device
 * can act on it, so if the interval collapsed to the hourly default whenever the
 * account was on the weather set, then going weather-and-back would leave the
 * panel parked for an hour with no way for the site to reach it - and "the
 * pictures stopped changing, whatever interval I pick" is exactly that. The mode
 * now changes what the device is sent, never how often it asks.
 */
function bg_poll_interval(PDO $db, int $userId): int
{
    $rot = bg_rotate_seconds($db, $userId);
    if ($rot > 0) {
        return max(BG_REFRESH_MIN, min($rot, BG_REFRESH_MAX));
    }
    return BG_REFRESH_QUIET;
}

/** The rotation choices, in seconds. 0 means "always the same picture". Keys are
 *  what gets stored and what the device is told to wait for. */
function bg_rotate_options(): array
{
    return [
        0     => 'Off - always the same picture',
        60    => 'Every minute',
        300   => 'Every 5 minutes',
        600   => 'Every 10 minutes',
        3600  => 'Every hour',
        86400 => 'Every day',
    ];
}

function bg_rotate_valid(int $seconds): bool
{
    return array_key_exists($seconds, bg_rotate_options());
}

/** Create the gallery table and the rotation column if the site has never had
 *  them. Called by the upload page; background.php reads them defensively
 *  instead, because it runs on every device poll. */
function bg_gallery_ensure(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS user_backgrounds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        filename TEXT NOT NULL,
        added DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $cols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'bg_rotate_s') $has = true;
    }
    if (!$has) {
        $db->exec("ALTER TABLE users ADD COLUMN bg_rotate_s INTEGER NOT NULL DEFAULT 0");
    }
}

/** This account's pictures, oldest first. Empty when the table is missing, so a
 *  site that has never opened the upload page still works. */
function bg_gallery_list(PDO $db, int $userId): array
{
    try {
        $stmt = $db->prepare("SELECT id, filename FROM user_backgrounds WHERE user_id = ? ORDER BY id ASC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function bg_gallery_count(PDO $db, int $userId): int
{
    return count(bg_gallery_list($db, $userId));
}

/** Adds a picture to the gallery. Returns false when the gallery is full. */
function bg_gallery_add(PDO $db, int $userId, string $filename): bool
{
    if (bg_gallery_count($db, $userId) >= BG_MAX_CUSTOM) {
        return false;
    }
    $stmt = $db->prepare("INSERT INTO user_backgrounds (user_id, filename) VALUES (?, ?)");
    $stmt->execute([$userId, $filename]);
    return true;
}

/** Removes one entry and returns its filename so the caller can unlink it, or
 *  null when the id is not this account's. */
function bg_gallery_remove(PDO $db, int $userId, int $id): ?string
{
    $stmt = $db->prepare("SELECT filename FROM user_backgrounds WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $file = $stmt->fetchColumn();
    if ($file === false) {
        return null;
    }
    $stmt = $db->prepare("DELETE FROM user_backgrounds WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    return (string) $file;
}

/** Deletes one stored picture - the .bin the device downloads and the .png the
 *  page previews - given the filename stored in the gallery. */
function bg_delete_pair(string $dir, string $filename): void
{
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    if ($stem === '') {
        return;
    }
    @unlink($dir . '/' . $stem . '.bin');
    @unlink($dir . '/' . $stem . '.png');
}

/** Moves the pre-gallery single picture into the gallery, once. The record of
 *  what an account uploaded before there was a gallery is just a filename in
 *  users.background_image; this promotes it to an entry so it rotates with the
 *  rest instead of being stranded. Safe to call on every page load: it returns
 *  immediately unless the gallery is empty and the file is really there. */
function bg_gallery_import_legacy(PDO $db, int $userId, string $legacyFile): void
{
    $legacyFile = basename(trim($legacyFile));
    if ($legacyFile === '' || bg_gallery_count($db, $userId) > 0) {
        return;
    }
    if (!is_file(__DIR__ . '/uploads/' . $legacyFile)) {
        return;
    }
    bg_gallery_add($db, $userId, $legacyFile);
}

/** The stored rotation interval in seconds; 0 when unset or unreadable. */
function bg_rotate_seconds(PDO $db, int $userId): int
{
    try {
        $stmt = $db->prepare("SELECT bg_rotate_s FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $s = (int) $stmt->fetchColumn();
        return bg_rotate_valid($s) ? $s : 0;
    } catch (PDOException $e) {
        return 0;   // column not there yet: rotation simply is off
    }
}

/**
 * Which picture the device should be shown, and how long it should wait before
 * asking again.
 *
 * The rotation is a function of the clock - floor(now / interval) picks the slot -
 * so no state is stored, every device on the account agrees, and restarting
 * nothing changes. $legacyFile is the old single-picture column, used as a
 * fallback for an account that has not opened the upload page since the gallery
 * arrived.
 *
 * @return array{file:string,refresh:int}|null
 */
function bg_custom_choice(PDO $db, int $userId, string $legacyFile = '', ?int $now = null): ?array
{
    if ($now === null) $now = time();
    $rows = bg_gallery_list($db, $userId);

    if (!$rows) {
        $safe = basename($legacyFile);
        if ($safe !== '' && is_file(__DIR__ . '/uploads/' . $safe)) {
            // Pre-gallery account: its one picture behaves as a set of one.
            return ['file' => $safe, 'refresh' => bg_poll_interval($db, $userId)];
        }
        return null;
    }

    $count = count($rows);
    $interval = bg_rotate_seconds($db, $userId);
    if ($interval <= 0) {
        $file = (string) $rows[$count - 1]['filename'];   // the most recent
    } else {
        $slot = intdiv($now, $interval);
        $file = (string) $rows[$slot % $count]['filename'];
    }
    // The cadence comes from the rotation setting alone, whatever the count, so
    // that the device's polling rate never depends on which picture is due.
    return ['file' => basename($file), 'refresh' => bg_poll_interval($db, $userId)];
}
