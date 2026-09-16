<?php
/**
 * image_converter.php - the device background page.
 *
 * Upload a picture, crop it to the panel's 5:3 shape, and it is stored as the
 * exact blob the ESP32 blits: raw RGB565, little-endian, downscaled to
 * BG_STORE_W x BG_STORE_H and upscaled again on the device (see bg_common.php
 * for why that format).
 *
 * Three sorts of background exist:
 *   custom  - the pictures uploaded here; several per account, walked on the
 *             rotation interval set on this page
 *   weather - one of the generated defaults in uploads/weather/, chosen on the
 *             device from its current conditions
 *   none    - nothing usable, and the device keeps whatever it already has
 *
 * The mode is stored per user; background.php hands the device whichever applies,
 * along with how long to wait before asking again (refresh_s).
 */
session_start();
date_default_timezone_set('Europe/London');
require __DIR__ . '/bg_common.php';

$message = '';
$error = '';

try {
    $db = new PDO('sqlite:access.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // background_mode arrived after the users table existed, so it is added in
    // place with the same PRAGMA-then-ALTER pattern the rest of the site uses.
    $cols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $have = ['background_image' => false, 'background_mode' => false];
    foreach ($cols as $c) {
        if ($c['name'] === 'background_image') $have['background_image'] = true;
        if ($c['name'] === 'background_mode')  $have['background_mode']  = true;
    }
    if (!$have['background_image']) $db->exec("ALTER TABLE users ADD COLUMN background_image TEXT");
    if (!$have['background_mode'])  $db->exec("ALTER TABLE users ADD COLUMN background_mode TEXT NOT NULL DEFAULT 'custom'");

    // The gallery of the owner's own pictures, plus the rotation interval column.
    // Several uploads replace the single background_image column, which is kept
    // and imported (see below) so nothing an account already uploaded is lost.
    bg_gallery_ensure($db);

    // Last known weather at a location, so marking "which picture is in use right
    // now" costs one upstream request per ten minutes rather than one per page
    // view. Keyed on the coordinates, so accounts in the same place share a row.
    // bg_current_weather() creates it if this has not run yet.
    bg_ensure_wx_cache($db);
} catch (PDOException $e) {
    die('Database Error: ' . htmlspecialchars($e->getMessage()));
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$userId = (int) $_SESSION['user_id'];

/** The original single background is stored under a name derived from the access
 *  code. It is still used as the import source for accounts that uploaded before
 *  the gallery existed. */
function bg_custom_base(string $accessCode): string
{
    return 'uploads/bg_' . preg_replace('/[^A-Za-z0-9_-]/', '', $accessCode);
}

/** A fresh name for one picture in the gallery. Each upload gets its own file -
 *  the point of the gallery is that adding a picture does not replace the last
 *  one - so the name carries a short random token. Returns a base path, i.e.
 *  what bg_write_pair() appends .bin and .png to. */
function bg_custom_new_base(string $accessCode): string
{
    $stem = 'uploads/bg_' . preg_replace('/[^A-Za-z0-9_-]/', '', $accessCode) . '_';
    for ($i = 0; $i < 8; $i++) {
        $cand = $stem . bin2hex(random_bytes(4));
        if (!is_file($cand . '.bin') && !is_file($cand . '.png')) {
            return $cand;
        }
    }
    // Vanishingly unlikely; a timestamp keeps the page working rather than
    // overwriting a picture the owner still has in the gallery.
    return $stem . time();
}

function bg_load_user(PDO $db, int $userId): array
{
    $st = $db->prepare("SELECT access_code, background_image, background_mode FROM users WHERE user_id = ?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'access_code'    => (string) ($row['access_code'] ?? ''),
        'background'     => (string) ($row['background_image'] ?? ''),
        'mode'           => ($row['background_mode'] ?? 'custom') === 'weather' ? 'weather' : 'custom',
        'rotate'         => bg_rotate_seconds($db, $userId),
    ];
}

$user = bg_load_user($db, $userId);

// An account from before the gallery: its one picture becomes the first entry,
// so it keeps rotating (as a set of one) instead of being stranded in the old
// column. Runs once - after this the gallery is non-empty.
bg_gallery_import_legacy($db, $userId, $user['background']);
$gallery = bg_gallery_list($db, $userId);

// Where this account's device is, and what the sky is doing there. This is the
// same location the device itself fetches (api.php?weatherLocation=...), so the
// "in use right now" marker below matches the panel rather than guessing.
$wxPrefs = [];
$stmt = $db->prepare("SELECT city_name, latitude, longitude FROM weather_preferences WHERE user_id = ?");
$stmt->execute([$userId]);
$wxPrefs = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$wxNow = null;
$hasLocation = isset($wxPrefs['latitude'], $wxPrefs['longitude'])
    && is_numeric($wxPrefs['latitude']) && is_numeric($wxPrefs['longitude']);
if ($hasLocation) {
    $wxNow = bg_current_weather($db, (float) $wxPrefs['latitude'], (float) $wxPrefs['longitude']);
}
$currentGroup = $wxNow['group'] ?? null;
$wxPlace = trim((string) ($wxPrefs['city_name'] ?? ''));

// ---------------------------------------------------------------- actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Your session expired. Please try again.';
    } else {
        $base = bg_custom_base($user['access_code']);

        if (isset($_POST['set_mode'])) {
            $mode = ($_POST['set_mode'] === 'weather') ? 'weather' : 'custom';
            $st = $db->prepare("UPDATE users SET background_mode = ? WHERE user_id = ?");
            $st->execute([$mode, $userId]);
            $message = $mode === 'weather'
                ? 'Background set to the weather pictures - the device picks one from its current conditions.'
                : 'Background set to your own picture.';
            $user = bg_load_user($db, $userId);

        } elseif (isset($_POST['set_rotate'])) {
            // How often the device swaps between the pictures below. Deliberately
            // set here and nowhere else: the device is only told how long to wait
            // (see bg_custom_choice), so whoever holds the upload password is the
            // one who decides the cadence.
            $want = (int) $_POST['set_rotate'];
            if (!bg_rotate_valid($want)) {
                $error = 'Unknown rotation interval.';
            } else {
                $st = $db->prepare("UPDATE users SET bg_rotate_s = ? WHERE user_id = ?");
                $st->execute([$want, $userId]);
                $opts = bg_rotate_options();
                $message = $want === 0
                    ? 'Rotation off - the most recently added picture stays up.'
                    : 'Pictures now rotate ' . strtolower($opts[$want]) . '.';
                $user = bg_load_user($db, $userId);
            }

        } elseif (isset($_POST['del_bg'])) {
            // One picture out of the gallery. Files are unlinked with it, so the
            // next upload reuses the space on the device rather than the server.
            $id = (int) $_POST['del_bg'];
            $file = bg_gallery_remove($db, $userId, $id);
            if ($file === null) {
                $error = 'That picture is no longer there.';
            } else {
                bg_delete_pair('uploads', $file);
                if ($gallery = bg_gallery_list($db, $userId)) {
                    // Keep background_image pointing at something real, so an
                    // older background.php still finds a picture.
                    $st = $db->prepare("UPDATE users SET background_image = ? WHERE user_id = ?");
                    $st->execute([(string) $gallery[count($gallery) - 1]['filename'], $userId]);
                    $message = 'Picture deleted.';
                } else {
                    $st = $db->prepare("UPDATE users SET background_image = NULL, background_mode = 'weather' WHERE user_id = ?");
                    $st->execute([$userId]);
                    $message = 'Last picture deleted; the device is back on the weather pictures.';
                }
                $user = bg_load_user($db, $userId);
            }

        } elseif (isset($_POST['remove_bg'])) {
            // Everything: the gallery and the pre-gallery file, in case this
            // account still has one. The device falls back to the weather set.
            $n = 0;
            foreach (bg_gallery_list($db, $userId) as $g) {
                bg_delete_pair('uploads', (string) $g['filename']);
                $n++;
            }
            $st = $db->prepare("DELETE FROM user_backgrounds WHERE user_id = ?");
            $st->execute([$userId]);
            @unlink($base . '.bin');
            @unlink($base . '.png');
            $st = $db->prepare("UPDATE users SET background_image = NULL, background_mode = 'weather' WHERE user_id = ?");
            $st->execute([$userId]);
            $message = $n > 1
                ? ($n . ' pictures removed; the device is back on the weather pictures.')
                : 'Your background picture was removed; the device is back on the weather pictures.';
            $user = bg_load_user($db, $userId);

        } elseif (isset($_POST['save_bg'])) {
            // Where it goes: "custom" is the owner's own background, and
            // "weather:<group>" replaces ONE of the weather pictures for this
            // account only. The cropper sends exactly 800x480 either way; it is
            // re-encoded here rather than trusted, because the size is what the
            // firmware insists on and the wrong length is rejected on the device.
            $target = (string) ($_POST['target'] ?? 'custom');
            $group = '';
            if (strpos($target, 'weather:') === 0) {
                $group = substr($target, 8);
                if (!bg_weather_group_valid($group)) {
                    $error = 'Unknown weather picture.';
                }
            }
            $data = (string) ($_POST['cropped'] ?? '');
            // The gallery is capped well below what the device can cache (see
            // BG_MAX_CUSTOM), so refuse before spending time decoding a picture
            // that could not be stored.
            if ($error === '' && $group === '' && bg_gallery_count($db, $userId) >= BG_MAX_CUSTOM) {
                $error = 'You already have the maximum of ' . BG_MAX_CUSTOM . ' pictures. Delete one first.';
            }
            if ($error === '' && !preg_match('#^data:image/(jpeg|png);base64,#', $data, $m)) {
                $error = 'No cropped image arrived. Choose a picture and crop it first.';
            } elseif ($error === '' && strlen($data) > 6 * 1024 * 1024) {
                $error = 'That image is too large to process. Try a smaller file.';
            } elseif ($error === '') {
                $raw = base64_decode(substr($data, strlen($m[0])), true);
                $img = $raw !== false ? @imagecreatefromstring($raw) : false;
                if ($img === false) {
                    $error = 'That image could not be read. Try a JPEG or PNG.';
                } else {
                    $w = imagesx($img);
                    $h = imagesy($img);
                    if (!is_dir('uploads')) {
                        @mkdir('uploads', 0755, true);
                    }
                    if ($group !== '') {
                        if (!is_dir(BG_WEATHER_DIR)) {
                            @mkdir(BG_WEATHER_DIR, 0755, true);
                        }
                        if (bg_write_pair(bg_weather_override_base($userId, $group), $img, $w, $h)) {
                            $message = 'Your picture is now the "' . bg_weather_label($group) . '" weather background. '
                                     . 'It is used whenever the conditions call for it'
                                     . ($user['mode'] === 'weather'
                                            ? '.'
                                            : ' - switch to "Weather pictures (automatic)" above to use it.');
                        } else {
                            $error = 'Could not write that picture. Check that uploads/weather/ is writable.';
                        }
                    } else {
                        // A brand new file each time: adding a picture must not
                        // replace the ones already in the gallery.
                        $newBase = bg_custom_new_base($user['access_code']);
                        if (bg_write_pair($newBase, $img, $w, $h) && bg_gallery_add($db, $userId, basename($newBase) . '.bin')) {
                            $st = $db->prepare("UPDATE users SET background_image = ?, background_mode = 'custom' WHERE user_id = ?");
                            $st->execute([basename($newBase) . '.bin', $userId]);
                            $count = bg_gallery_count($db, $userId);
                            $message = 'Picture added - you now have ' . $count
                                     . ' (' . number_format(BG_BYTES) . ' bytes each on the device). '
                                     . ($user['rotate'] > 0
                                            ? 'It joins the rotation on the device\'s next background refresh.'
                                            : 'The newest picture is the one shown; set a rotation interval below to cycle through them.');
                            $user = bg_load_user($db, $userId);
                        } else {
                            // Either the write or the insert failed; do not leave a
                            // file behind that nothing points at.
                            bg_delete_pair('uploads', basename($newBase) . '.bin');
                            $error = 'Could not store that picture. Check that uploads/ is writable.';
                        }
                    }
                    imagedestroy($img);
                }
            }
        } elseif (isset($_POST['reset_weather'])) {
            // Puts one generated default back by deleting this account's copy.
            $group = (string) $_POST['reset_weather'];
            if (!bg_weather_group_valid($group)) {
                $error = 'Unknown weather picture.';
            } else {
                $wbase = bg_weather_override_base($userId, $group);
                @unlink($wbase . '.bin');
                @unlink($wbase . '.png');
                $message = 'The "' . bg_weather_label($group) . '" picture is back to the default.';
            }
        } elseif (isset($_POST['reset_all_weather'])) {
            $n = 0;
            foreach (bg_weather_groups() as $g) {
                $wbase = bg_weather_override_base($userId, $g);
                if (is_file($wbase . '.bin')) {
                    @unlink($wbase . '.bin');
                    @unlink($wbase . '.png');
                    $n++;
                }
            }
            $message = $n
                ? ($n . ' weather picture' . ($n === 1 ? '' : 's') . ' restored to the default.')
                : 'Nothing to reset - you are already using the default pictures.';
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Re-read after the actions above so the page always shows the state that was
// just saved, whichever branch ran.
$user = bg_load_user($db, $userId);
$gallery = bg_gallery_list($db, $userId);

// Which of the owner's pictures the device is being shown at this moment.
// bg_custom_choice() is the very function background.php answers with, so the
// tile marked "now" here is the one the panel really has, rather than a second
// guess at the same clock arithmetic.
$choice = bg_custom_choice($db, $userId, $user['background']);
$activeFile = $choice !== null ? (string) $choice['file'] : '';

$customBin = 'uploads/' . $user['background'];
$hasCustom = $activeFile !== '' && is_file('uploads/' . $activeFile);
$customPreview = 'uploads/' . pathinfo($activeFile, PATHINFO_FILENAME) . '.png';
$hasPreview = $hasCustom && is_file($customPreview);
$galleryFull = count($gallery) >= BG_MAX_CUSTOM;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#3B82F6">
    <link rel="manifest" href="manifest.json">
    <title>Device background</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <style>
        body{font-family:'Poppins',sans-serif;background:linear-gradient(135deg,#6B7280,#3B82F6);color:#fff;overflow-x:hidden;}
        .menu{position:fixed;top:0;left:0;width:100%;background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);padding:1rem;display:flex;justify-content:center;gap:2rem;z-index:10;}
        .menu a{color:white;text-decoration:none;font-weight:600;transition:color .3s;}
        .menu a:hover{color:#34D399;}
        .wrap{padding:6rem 1.5rem 3rem;max-width:1000px;margin:0 auto;}
        .card{background:rgba(255,255,255,0.16);border-radius:1rem;box-shadow:0 16px 32px rgba(0,0,0,.28);padding:1.5rem;margin-bottom:1.5rem;}
        .card h2{font-size:1.4rem;font-weight:700;margin-bottom:.35rem;}
        .card p.hint{font-size:.9rem;opacity:.85;margin-bottom:1rem;}
        .btn{background:#34D399;color:#fff;padding:.7rem 1.4rem;border-radius:50px;font-weight:600;border:none;cursor:pointer;transition:background .3s,transform .3s;}
        .btn:hover{background:#2FB988;transform:scale(1.03);}
        .btn[disabled]{opacity:.5;cursor:not-allowed;transform:none;}
        .btn-quiet{background:rgba(255,255,255,.22);}
        .btn-quiet:hover{background:rgba(255,255,255,.32);}
        .msg-success{color:#A7F3D0;font-weight:600;margin-bottom:1rem;}
        .msg-error{color:#FECACA;font-weight:600;margin-bottom:1rem;}
        #cropImage{max-width:100%;display:block;}
        .crop-stage{background:rgba(0,0,0,.25);border-radius:.75rem;overflow:hidden;}
        .gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.8rem;}
        .gallery figure{background:rgba(0,0,0,.22);border-radius:.6rem;overflow:hidden;margin:0;}
        .gallery img{width:100%;display:block;aspect-ratio:5/3;object-fit:cover;}
        .gallery figcaption{font-size:.78rem;padding:.4rem .5rem;opacity:.95;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
        /* Clicking a tile points the uploader at that weather picture. */
        .tile{cursor:pointer;border:2px solid transparent;transition:border-color .2s,transform .2s;}
        .tile:hover{border-color:#34D399;transform:translateY(-2px);}
        .tile-own{border-color:#34D399;}
        /* The picture the conditions are selecting at this moment. Declared after
           .tile-own so a picture that is both yours and current keeps the amber
           "now" outline, with the green owned border still visible behind it. */
        .tile-current{border-color:#FBBF24;box-shadow:0 0 0 3px rgba(251,191,36,.35);}
        .now-line{background:rgba(0,0,0,.22);border-left:4px solid #FBBF24;border-radius:.5rem;
                  padding:.6rem .8rem;font-size:.86rem;margin:.1rem 0 1rem;line-height:1.5;}
        .now-line a{color:#FBBF24;font-weight:600;}
        .tile-label{font-weight:600;}
        .badge{background:#34D399;color:#064e3b;border-radius:50px;padding:.05rem .4rem;font-size:.62rem;
               font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
        .badge-now{background:#FBBF24;color:#3b2600;}
        .tile-actions{margin-left:auto;display:flex;gap:.3rem;align-items:center;}
        .tile-actions form{margin:0;display:inline;}
        .tile-btn{background:rgba(255,255,255,.22);border:none;color:#fff;font:inherit;font-size:.72rem;
                  font-weight:600;padding:.2rem .55rem;border-radius:50px;cursor:pointer;}
        .tile-btn:hover{background:rgba(255,255,255,.36);}
        .tile-btn-reset{background:rgba(0,0,0,.32);}
        .modes{display:flex;gap:.6rem;flex-wrap:wrap;}
        .modes label{display:flex;gap:.45rem;align-items:center;background:rgba(0,0,0,.2);padding:.55rem .9rem;border-radius:50px;cursor:pointer;}
        .current{display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap;}
        .current img{width:280px;aspect-ratio:5/3;object-fit:cover;border-radius:.6rem;border:2px solid rgba(255,255,255,.5);}
        @media (max-width:768px){.menu{flex-wrap:wrap;gap:1rem;}.wrap{padding-top:7.5rem;}}
    </style>
</head>
<body>
    <nav class="menu">
        <a href="index.php">Home</a>
        <a href="calendar.php">Calendar</a>
        <a href="weather.php">Weather</a>
        <a href="settings.php">Settings</a>
        <a href="?logout=1">Logout</a>
    </nav>

    <div class="wrap">
        <h1 class="text-4xl font-bold mb-2">Device background</h1>
        <p class="mb-6 opacity-90">
            The picture behind the calendar on your Cron-Tab. Upload as many as you like &mdash; the device
            caches them on its own flash and swaps between them on the interval you set below. Each one is
            stored as raw RGB565 (<?php echo number_format(BG_BYTES); ?> bytes, <?php echo BG_STORE_W; ?>&times;<?php echo BG_STORE_H; ?>,
            scaled up to the panel) &mdash; exactly the format the device blits.
        </p>

        <?php if ($message !== ''): ?>
            <p class="msg-success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="msg-error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <!-- ------------------------------------------------ what is showing -->
        <div class="card">
            <h2>Currently showing</h2>
            <p class="hint">
                Mode: <strong><?php echo $user['mode'] === 'weather' ? 'Weather pictures' : 'My own pictures'; ?></strong>
                <?php if ($user['mode'] === 'custom' && count($gallery) > 0): ?>
                    &mdash; <?php echo count($gallery); ?> picture<?php echo count($gallery) === 1 ? '' : 's'; ?>,
                    <?php if ($user['rotate'] > 0): ?>
                        rotating <?php echo strtolower(bg_rotate_options()[$user['rotate']]); ?>.
                    <?php else: ?>
                        showing the newest one (rotation off).
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <div class="current">
                <?php if ($user['mode'] === 'custom' && $hasCustom): ?>
                    <?php if ($hasPreview): ?>
                        <img src="<?php echo htmlspecialchars($customPreview); ?>?v=<?php echo filemtime($customPreview); ?>" alt="Current background">
                    <?php endif; ?>
                    <div>
                        <p class="opacity-90 mb-3">This is the one the device has right now.</p>
                        <form method="POST" onsubmit="return confirm('Remove ALL your background pictures and go back to the weather pictures?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <button type="submit" name="remove_bg" value="1" class="btn btn-quiet">Remove all my pictures</button>
                        </form>
                    </div>
                <?php elseif ($user['mode'] === 'custom'): ?>
                    <p class="opacity-90">No picture uploaded yet &mdash; the device falls back to the weather pictures until you add one.</p>
                <?php else: ?>
                    <p class="opacity-90">The device is choosing a weather picture from its current conditions. The set it picks from is below.</p>
                <?php endif; ?>
            </div>

            <form method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modes">
                    <label><input type="radio" name="set_mode" value="custom" <?php echo $user['mode'] === 'custom' ? 'checked' : ''; ?> onchange="this.form.submit();"> My own pictures</label>
                    <label><input type="radio" name="set_mode" value="weather" <?php echo $user['mode'] === 'weather' ? 'checked' : ''; ?> onchange="this.form.submit();"> Weather pictures (automatic)</label>
                </div>
            </form>
        </div>

        <!-- ------------------------------------------------------- upload -->
        <div class="card">
            <h2>Upload a picture</h2>
            <p class="hint">
                Any JPEG, PNG or BMP up to 5&nbsp;MB. Crop it to the panel's 5:3 shape &mdash; drag to move,
                scroll or pinch to zoom &mdash; then save. Each upload is <strong>added</strong> to your pictures;
                it does not replace the last one.
            </p>
            <?php if ($galleryFull): ?>
                <p class="msg-error">You have the maximum of <?php echo BG_MAX_CUSTOM; ?> pictures. Delete one below to free a slot.</p>
            <?php endif; ?>
            <form id="uploadForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="save_bg" value="1">
                <input type="hidden" name="cropped" id="cropped">
                <!-- Which picture this upload replaces: "custom", or "weather:<group>"
                     set by clicking a tile in the Weather pictures card below. -->
                <input type="hidden" name="target" id="target" value="custom">
                <p class="text-sm mb-2">
                    Saving as: <strong id="targetLabel">a new picture of mine</strong>
                    <button type="button" id="useCustom" class="tile-btn" style="display:none;"
                            onclick="pickCustom()">use as one of my own pictures instead</button>
                </p>
                <input type="file" id="image" accept="image/jpeg,image/png,image/bmp"
                       class="mb-3 block w-full text-sm text-white/90">
                <div class="crop-stage mb-3">
                    <img id="cropImage" alt="Crop preview" style="display:none;">
                </div>
                <button type="submit" id="saveBtn" class="btn" disabled>Add picture</button>
            </form>
        </div>

        <!-- ------------------------------------------------- my pictures -->
        <div class="card" id="myPictures">
            <h2>My pictures</h2>
            <p class="hint">
                The device downloads and caches these, then swaps between them on the interval below. The
                interval is kept here rather than on the device, so it stays with whoever can log in.
            </p>
            <?php if (!$gallery): ?>
                <p class="opacity-90">Nothing uploaded yet. The first picture you add appears here.</p>
            <?php else: ?>
                <form method="POST" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <label class="block mb-2 text-sm opacity-95" for="rotateSel">
                        <strong>Rotate between them:</strong>
                    </label>
                    <select id="rotateSel" name="set_rotate" onchange="this.form.submit();"
                            style="background:rgba(0,0,0,.28);color:#fff;border:none;border-radius:.5rem;padding:.55rem .9rem;font:inherit;">
                        <?php foreach (bg_rotate_options() as $secs => $label): ?>
                            <option value="<?php echo (int) $secs; ?>" <?php echo $user['rotate'] === (int) $secs ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint mt-2" style="margin-bottom:0;">
                        The device asks for the next picture when the interval is up. Short intervals mean it
                        polls more often; "every minute" is the busiest and may make the panel hitch briefly.
                    </p>
                </form>
                <div class="gallery">
                    <?php foreach ($gallery as $g): ?>
                        <?php
                        $gFile  = (string) $g['filename'];
                        $gPng   = 'uploads/' . pathinfo($gFile, PATHINFO_FILENAME) . '.png';
                        $isNow  = ($gFile === $activeFile && $user['mode'] === 'custom');
                        ?>
                        <figure class="tile tile-own<?php echo $isNow ? ' tile-current' : ''; ?>"
                                title="<?php echo $isNow ? 'In use on the device right now' : 'Stored on the server'; ?>">
                            <?php if (is_file($gPng)): ?>
                                <img src="<?php echo htmlspecialchars($gPng); ?>?v=<?php echo (int) @filemtime($gPng); ?>" alt="Your picture">
                            <?php else: ?>
                                <div style="aspect-ratio:5/3;display:flex;align-items:center;justify-content:center;font-size:.8rem;opacity:.8;">preview missing</div>
                            <?php endif; ?>
                            <figcaption>
                                <span class="tile-label"><?php echo htmlspecialchars($gFile); ?></span>
                                <?php if ($isNow): ?><span class="badge badge-now">now</span><?php endif; ?>
                                <span class="tile-actions">
                                    <form method="POST" onsubmit="return confirm('Delete this picture?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <button type="submit" name="del_bg" value="<?php echo (int) $g['id']; ?>" class="tile-btn tile-btn-reset">Delete</button>
                                    </form>
                                </span>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ---------------------------------------------- weather defaults -->
        <div class="card" id="weatherCard">
            <h2>Weather pictures</h2>
            <p class="hint">
                What the device shows when it is in weather mode. Click any picture to replace it with
                your own &mdash; yours is then used whenever the conditions call for it. Reset puts the
                default back.
            </p>
            <?php if ($currentGroup !== null): ?>
                <p class="now-line">
                    <span class="badge badge-now">in use now</span>
                    <?php echo htmlspecialchars($wxPlace !== '' ? $wxPlace : 'Your location'); ?>:
                    <strong><?php echo htmlspecialchars($wxNow['description']); ?></strong>
                    &mdash; so the <strong><?php echo htmlspecialchars(bg_weather_label($currentGroup)); ?></strong>
                    picture is the one selected<?php
                        echo $user['mode'] === 'weather'
                            ? '.'
                            : ', but you are showing your own background at the moment.'; ?>
                </p>
            <?php else: ?>
                <p class="now-line hint">
                    <?php if (!$hasLocation): ?>
                        Add a <a href="settings.php?tab=other">weather location</a> to see which picture is in use right now.
                    <?php else: ?>
                        Could not check the weather just now &mdash; which picture is in use will be marked next time this page loads.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <div class="gallery">
                <?php foreach (bg_weather_groups() as $g): ?>
                    <?php
                    $hasOvr  = bg_weather_override_exists($userId, $g);
                    $ovrPng  = bg_weather_override_base($userId, $g) . '.png';
                    $defPng  = BG_WEATHER_DIR . '/' . $g . '.png';
                    $prevPng = ($hasOvr && is_file($ovrPng)) ? $ovrPng : $defPng;
                    ?>
                    <?php $isCurrent = ($currentGroup !== null && $g === $currentGroup); ?>
                    <figure class="tile<?php echo $hasOvr ? ' tile-own' : ''; ?><?php echo $isCurrent ? ' tile-current' : ''; ?>"
                            onclick="pickWeather('<?php echo $g; ?>', '<?php echo htmlspecialchars(bg_weather_label($g), ENT_QUOTES); ?>')"
                            title="Click to use your own picture for <?php echo htmlspecialchars(bg_weather_label($g)); ?><?php echo $isCurrent ? ' (in use right now)' : ''; ?>">
                        <?php if (is_file($prevPng)): ?>
                            <img src="<?php echo htmlspecialchars($prevPng); ?>?v=<?php echo (int) @filemtime($prevPng); ?>"
                                 alt="<?php echo htmlspecialchars(bg_weather_label($g)); ?>">
                        <?php else: ?>
                            <div style="aspect-ratio:5/3;display:flex;align-items:center;justify-content:center;font-size:.8rem;opacity:.8;">not generated yet</div>
                        <?php endif; ?>
                        <figcaption>
                            <span class="tile-label"><?php echo htmlspecialchars(bg_weather_label($g)); ?></span>
                            <?php if ($isCurrent): ?><span class="badge badge-now">now</span><?php endif; ?>
                            <?php if ($hasOvr): ?><span class="badge">your picture</span><?php endif; ?>
                            <span class="tile-actions">
                                <span class="tile-btn">Replace</span>
                                <?php if ($hasOvr): ?>
                                    <form method="POST" onclick="event.stopPropagation();"
                                          onsubmit="return confirm('Put the default <?php echo htmlspecialchars(bg_weather_label($g), ENT_QUOTES); ?> picture back?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <button type="submit" name="reset_weather" value="<?php echo $g; ?>" class="tile-btn tile-btn-reset">Reset</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
            <?php
            $anyOverride = false;
            foreach (bg_weather_groups() as $g) {
                if (bg_weather_override_exists($userId, $g)) { $anyOverride = true; break; }
            }
            ?>
            <?php if ($anyOverride): ?>
                <form method="POST" class="mt-4"
                      onsubmit="return confirm('Restore ALL the weather pictures to the defaults?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" name="reset_all_weather" value="1" class="btn btn-quiet">Reset all to defaults</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const fileInput = document.getElementById('image');
        const cropImage = document.getElementById('cropImage');
        const saveBtn   = document.getElementById('saveBtn');
        const form      = document.getElementById('uploadForm');
        const hidden    = document.getElementById('cropped');
        const targetInput = document.getElementById('target');
        const targetLabel = document.getElementById('targetLabel');
        const useCustomBtn = document.getElementById('useCustom');
        let cropper = null;

        // Point the uploader at one weather picture, or back at the owner's own
        // gallery. The hidden "target" field is what the server branches on.
        function pickWeather(group, label) {
            targetInput.value = 'weather:' + group;
            targetLabel.textContent = 'the ' + label + ' weather picture';
            useCustomBtn.style.display = 'inline-block';
            saveBtn.textContent = 'Save as the ' + label + ' picture';
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function pickCustom() {
            targetInput.value = 'custom';
            targetLabel.textContent = 'a new picture of mine';
            useCustomBtn.style.display = 'none';
            saveBtn.textContent = 'Add picture';
        }

        fileInput.addEventListener('change', function (e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                alert('That file is larger than 5 MB. Please pick a smaller one.');
                fileInput.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function (ev) {
                cropImage.src = ev.target.result;
                cropImage.style.display = 'block';
                if (cropper) cropper.destroy();
                cropper = new Cropper(cropImage, {
                    aspectRatio: 800 / 480,   // the panel's shape, locked
                    viewMode: 1,              // cannot drag the picture out of the frame
                    autoCropArea: 1,
                    background: false,
                    rotatable: false,
                    scalable: false,
                    responsive: true,
                    guides: true,
                    zoomOnWheel: true
                });
                saveBtn.disabled = false;
            };
            reader.readAsDataURL(file);
        });

        // The cropper is asked for exactly the size the firmware expects, so the
        // server only has to re-encode, never re-crop.
        form.addEventListener('submit', function (e) {
            if (!cropper) { e.preventDefault(); return; }
            const canvas = cropper.getCroppedCanvas({
                width: 800, height: 480, imageSmoothingEnabled: true, imageSmoothingQuality: 'high'
            });
            if (!canvas) { e.preventDefault(); alert('Could not read that image. Try a JPEG or PNG.'); return; }
            hidden.value = canvas.toDataURL('image/jpeg', 0.92);
        });
    </script>
</body>
</html>
