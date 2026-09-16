<?php
/**
 * image_converter.php - the device background page.
 *
 * Upload a picture, crop it to the panel's 5:3 shape, and it is stored as the
 * exact blob the ESP32 blits: 800x480 raw RGB565, little-endian, 768000 bytes
 * (see bg_common.php for why that format).
 *
 * Two sorts of background exist:
 *   custom  - the picture uploaded here
 *   weather - one of the generated defaults in uploads/weather/, chosen on the
 *             device from its current conditions
 *
 * The mode is stored per user; background.php hands the device whichever applies.
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

/** The custom background is stored under a name derived from the access code, so
 *  re-uploading replaces the old file instead of leaving orphans behind. */
function bg_custom_base(string $accessCode): string
{
    return 'uploads/bg_' . preg_replace('/[^A-Za-z0-9_-]/', '', $accessCode);
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
    ];
}

$user = bg_load_user($db, $userId);

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

        } elseif (isset($_POST['remove_bg'])) {
            @unlink($base . '.bin');
            @unlink($base . '.png');
            $st = $db->prepare("UPDATE users SET background_image = NULL, background_mode = 'weather' WHERE user_id = ?");
            $st->execute([$userId]);
            $message = 'Your background picture was removed; the device is back on the weather pictures.';
            $user = bg_load_user($db, $userId);

        } elseif (isset($_POST['save_bg'])) {
            // The cropper sends exactly 800x480 as a data URL. It is re-encoded
            // here rather than trusted: the size is what the firmware insists on,
            // and a blob of the wrong length would be rejected on the device.
            $data = (string) ($_POST['cropped'] ?? '');
            if (!preg_match('#^data:image/(jpeg|png);base64,#', $data, $m)) {
                $error = 'No cropped image arrived. Choose a picture and crop it first.';
            } elseif (strlen($data) > 6 * 1024 * 1024) {
                $error = 'That image is too large to process. Try a smaller file.';
            } else {
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
                    if (bg_write_pair($base, $img, $w, $h)) {
                        $st = $db->prepare("UPDATE users SET background_image = ?, background_mode = 'custom' WHERE user_id = ?");
                        $st->execute([basename($base) . '.bin', $userId]);
                        $message = 'Background saved (' . number_format(BG_BYTES) . ' bytes, 800x480 RGB565). '
                                 . 'The device picks it up on its next background refresh.';
                        $user = bg_load_user($db, $userId);
                    } else {
                        $error = 'Could not write the background file. Check that uploads/ is writable.';
                    }
                    imagedestroy($img);
                }
            }
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

$customBin = 'uploads/' . $user['background'];
$hasCustom = $user['background'] !== '' && is_file($customBin);
$customPreview = 'uploads/' . pathinfo($user['background'], PATHINFO_FILENAME) . '.png';
$hasPreview = $hasCustom && is_file($customPreview);
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
        .gallery figcaption{font-size:.78rem;padding:.4rem .5rem;opacity:.9;}
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
            The picture behind the calendar on your Cron-Tab. It is stored as 800&times;480 raw RGB565
            (<?php echo number_format(BG_BYTES); ?> bytes) &mdash; exactly what the panel displays, so the device does no conversion.
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
                Mode: <strong><?php echo $user['mode'] === 'weather' ? 'Weather pictures' : 'My own picture'; ?></strong>
            </p>
            <div class="current">
                <?php if ($user['mode'] === 'custom' && $hasCustom): ?>
                    <?php if ($hasPreview): ?>
                        <img src="<?php echo htmlspecialchars($customPreview); ?>?v=<?php echo filemtime($customPreview); ?>" alt="Current background">
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Remove your background picture and go back to the weather pictures?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button type="submit" name="remove_bg" value="1" class="btn btn-quiet">Remove my picture</button>
                    </form>
                <?php elseif ($user['mode'] === 'custom'): ?>
                    <p class="opacity-90">No picture uploaded yet &mdash; the device falls back to the weather pictures until you add one.</p>
                <?php else: ?>
                    <p class="opacity-90">The device is choosing a weather picture from its current conditions. The set it picks from is below.</p>
                <?php endif; ?>
            </div>

            <form method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="modes">
                    <label><input type="radio" name="set_mode" value="custom" <?php echo $user['mode'] === 'custom' ? 'checked' : ''; ?> onchange="this.form.submit();"> My own picture</label>
                    <label><input type="radio" name="set_mode" value="weather" <?php echo $user['mode'] === 'weather' ? 'checked' : ''; ?> onchange="this.form.submit();"> Weather pictures (automatic)</label>
                </div>
            </form>
        </div>

        <!-- ------------------------------------------------------- upload -->
        <div class="card">
            <h2>Upload a picture</h2>
            <p class="hint">
                Any JPEG, PNG or BMP up to 5&nbsp;MB. Crop it to the panel's 5:3 shape &mdash; drag to move,
                scroll or pinch to zoom &mdash; then save. It is resized to 800&times;480 for you.
            </p>
            <form id="uploadForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="save_bg" value="1">
                <input type="hidden" name="cropped" id="cropped">
                <input type="file" id="image" accept="image/jpeg,image/png,image/bmp"
                       class="mb-3 block w-full text-sm text-white/90">
                <div class="crop-stage mb-3">
                    <img id="cropImage" alt="Crop preview" style="display:none;">
                </div>
                <button type="submit" id="saveBtn" class="btn" disabled>Save background</button>
            </form>
        </div>

        <!-- ---------------------------------------------- weather defaults -->
        <div class="card">
            <h2>Weather pictures</h2>
            <p class="hint">The defaults the device chooses from when it is in weather mode.</p>
            <div class="gallery">
                <?php foreach (bg_weather_groups() as $g): ?>
                    <?php $png = BG_WEATHER_DIR . '/' . $g . '.png'; ?>
                    <figure>
                        <?php if (is_file($png)): ?>
                            <img src="<?php echo htmlspecialchars($png); ?>?v=<?php echo filemtime($png); ?>" alt="<?php echo htmlspecialchars(bg_weather_label($g)); ?>">
                        <?php else: ?>
                            <div style="aspect-ratio:5/3;display:flex;align-items:center;justify-content:center;font-size:.8rem;opacity:.8;">not generated yet</div>
                        <?php endif; ?>
                        <figcaption><?php echo htmlspecialchars(bg_weather_label($g)); ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        const fileInput = document.getElementById('image');
        const cropImage = document.getElementById('cropImage');
        const saveBtn   = document.getElementById('saveBtn');
        const form      = document.getElementById('uploadForm');
        const hidden    = document.getElementById('cropped');
        let cropper = null;

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
