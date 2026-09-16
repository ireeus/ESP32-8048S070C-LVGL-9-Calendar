<?php
/**
 * make-weather-backgrounds.php
 *
 * Builds the default weather backgrounds for the Cron-Tab device.
 *
 * For each weather group it paints an 800x480 gradient, drops the matching
 * Meteocons artwork on top, darkens the edges for legibility, and writes:
 *
 *   uploads/weather/<group>.bin   raw RGB565, little-endian, 800*480*2 bytes
 *   uploads/weather/<group>.png   the same picture as a PNG, for the web preview
 *
 * The .bin is exactly what the firmware blits: no header, no compression, no
 * byte swapping. RGB565 is two bytes per pixel and LVGL reads it little-endian,
 * which is the native order on the ESP32-S3, so a straight byte copy of this
 * file is a valid LVGL image source.
 *
 * The artwork is vector (see img/weather-bg/src/*.svg, stripped of the SMIL
 * animation Meteocons ships with - a static render of the animated originals
 * shows only the cloud, because the rain and snow start at opacity 0) and the
 * PNGs beside them are 512px renders of it. Only GD is needed to run this, so it
 * works on ordinary shared hosting:
 *
 *   php tools/make-weather-backgrounds.php
 *
 * Re-run it after editing a palette or replacing an icon.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This generator is a command-line tool.\n");
}

// The stored size and the RGB565 packing live here, so this script cannot drift
// from what the upload page writes or what the firmware expects.
require __DIR__ . '/../bg_common.php';

$root = dirname(__DIR__);
$iconDir = $root . '/img/weather-bg';
$outDir = $root . '/uploads/weather';
$W = 800;
$H = 480;
$ICON = 300;   // drawn size of the icon, in pixels

if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    exit("Cannot create $outDir\n");
}

/**
 * Per group: the vertical gradient (top -> bottom) and the icon file.
 *
 * The top colour is deliberately the darker end. The calendar and panels are
 * drawn on top of this, and the background only shows in the gaps between them,
 * so a dark upper edge and a lighter lower edge keeps any text that sits over it
 * readable without the picture disappearing.
 */
$groups = [
    'clear'   => ['top' => [0x0E, 0x4C, 0x9A], 'bottom' => [0x6F, 0xB6, 0xEE], 'icon' => 'clear.png'],
    'partly'  => ['top' => [0x14, 0x53, 0x8F], 'bottom' => [0x7F, 0xB4, 0xDC], 'icon' => 'partly.png'],
    'cloudy'  => ['top' => [0x3C, 0x4A, 0x57], 'bottom' => [0x8A, 0x99, 0xA6], 'icon' => 'cloudy.png'],
    'fog'     => ['top' => [0x5D, 0x66, 0x70], 'bottom' => [0xB6, 0xBE, 0xC5], 'icon' => 'fog.png'],
    'rain'    => ['top' => [0x17, 0x32, 0x4A], 'bottom' => [0x4E, 0x72, 0x88], 'icon' => 'rain.png'],
    'snow'    => ['top' => [0x3E, 0x63, 0x86], 'bottom' => [0xCF, 0xE2, 0xF0], 'icon' => 'snow.png'],
    'storm'   => ['top' => [0x1C, 0x17, 0x34], 'bottom' => [0x4B, 0x3F, 0x6B], 'icon' => 'storm.png'],
    'unknown' => ['top' => [0x33, 0x38, 0x3D], 'bottom' => [0x7C, 0x84, 0x8B], 'icon' => 'unknown.png'],
];

/** One 0-255 channel, eased so the gradient spends longer near the middle. */
function lerp_channel(int $a, int $b, float $t): int
{
    return (int) round($a + ($b - $a) * $t);
}

foreach ($groups as $name => $cfg) {
    $iconPath = $iconDir . '/' . $cfg['icon'];
    if (!is_file($iconPath)) {
        echo "skip $name: missing $iconPath\n";
        continue;
    }

    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, true);

    // ---- vertical gradient -------------------------------------------------
    for ($y = 0; $y < $H; $y++) {
        $t = $y / ($H - 1);
        // Smoothstep: a linear ramp bands visibly on a 7" panel.
        $t = $t * $t * (3.0 - 2.0 * $t);
        $col = imagecolorallocate(
            $img,
            lerp_channel($cfg['top'][0], $cfg['bottom'][0], $t),
            lerp_channel($cfg['top'][1], $cfg['bottom'][1], $t),
            lerp_channel($cfg['top'][2], $cfg['bottom'][2], $t)
        );
        imageline($img, 0, $y, $W - 1, $y, $col);
    }

    // ---- soft backdrop behind the artwork ---------------------------------
    // The Meteocons are pale, so on the lighter groups they need something to
    // separate them from the sky. A gentle DARKENING, not a white glow: a glow
    // brightens the artwork and washes the whole picture out, which is exactly
    // what the first attempt did.
    $cx = $W / 2;
    $cy = $H / 2;
    $rx = $ICON * 0.78;
    $ry = $ICON * 0.60;
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $dx = ($x - $cx) / $rx;
            $dy = ($y - $cy) / $ry;
            $d = sqrt($dx * $dx + $dy * $dy);
            if ($d >= 1.0) {
                continue;
            }
            $shade = pow(1.0 - $d, 1.5) * 0.26;   // up to 26% black at the centre
            $c = imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            imagesetpixel($img, $x, $y, imagecolorallocate(
                $img,
                (int) ($r * (1 - $shade)),
                (int) ($g * (1 - $shade)),
                (int) ($b * (1 - $shade))
            ));
        }
    }

    // ---- the artwork ------------------------------------------------------
    $icon = imagecreatefrompng($iconPath);
    if ($icon !== false) {
        imagealphablending($img, true);
        imagesavealpha($img, true);
        imagecopyresampled(
            $img,
            $icon,
            (int) (($W - $ICON) / 2),
            (int) (($H - $ICON) / 2),
            0,
            0,
            $ICON,
            $ICON,
            imagesx($icon),
            imagesy($icon)
        );
        imagedestroy($icon);
    }

    // ---- vignette ---------------------------------------------------------
    // Darkens towards the corners so the picture never competes with the UI.
    $maxDim = sqrt(($W / 2) ** 2 + ($H / 2) ** 2);
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $dx = $x - $W / 2;
            $dy = $y - $H / 2;
            $d = sqrt($dx * $dx + $dy * $dy) / $maxDim;   // 0 centre, 1 corner
            if ($d <= 0.55) {
                continue;
            }
            $t = ($d - 0.55) / 0.45;                      // 0..1 over the outer band
            $alpha = (int) (0.34 * $t * $t * 127);        // up to ~34% black
            if ($alpha <= 0) {
                continue;
            }
            $c = imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $keep = ($alpha / 127);
            $nc = imagecolorallocate(
                $img,
                (int) ($r * (1 - $keep)),
                (int) ($g * (1 - $keep)),
                (int) ($b * (1 - $keep))
            );
            imagesetpixel($img, $x, $y, $nc);
        }
    }

    // ---- write the PNG preview -------------------------------------------
    // Full panel size: this is only ever shown in a browser, and it doubles as the
    // master the .bin can be rebuilt from (see bg_heal_bin).
    imagepng($img, $outDir . '/' . $name . '.png');

    // ---- write the device blob at the STORED size -------------------------
    // Through bg_common.php, so the stored resolution and the byte order are
    // defined in exactly one place and cannot drift from what the firmware wants.
    $bin = bg_rgb565_bytes($img, $W, $H);
    file_put_contents($outDir . '/' . $name . '.bin', $bin);

    printf(
        "%-8s %s.bin %d bytes (expected %d) %s\n",
        $name,
        $name,
        strlen($bin),
        BG_BYTES,
        strlen($bin) === BG_BYTES ? 'OK' : '*** WRONG SIZE ***'
    );

    imagedestroy($img);
}

echo "Done. Written to uploads/weather/\n";
