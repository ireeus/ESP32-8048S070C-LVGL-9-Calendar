<?php
/**
 * bg_common.php - shared helpers for the device background picture.
 *
 * The device blits one fixed thing: an 800x480 image in raw RGB565, little-endian,
 * with no header. LVGL is handed a pointer straight at it, and the ESP32-S3 is
 * little-endian, so the file on the server is byte-for-byte what the panel shows.
 *
 * The file is STORED and SENT at a fraction of the panel resolution. A sixteenth of
 * the bytes means a sixteenth of the download, a sixteenth of the flash write on the
 * device (the slow part, which has to happen behind a blacked-out screen), and room
 * for a few hundred cached pictures instead of thirteen. The firmware stretches it
 * back to 800x480 once on arrival, so nothing else changes. Keep BG_STORE_W/H in step
 * with the same constants in main.cpp: a mismatch means the device refuses every
 * picture as the wrong length.
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
define('BG_STORE_W', 200);                    // what is downloaded and cached
define('BG_STORE_H', 120);
define('BG_BYTES', BG_STORE_W * BG_STORE_H * 2);  // 48000
define('BG_WEATHER_DIR', 'uploads/weather');  // holds <group>.bin and <group>.png

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
