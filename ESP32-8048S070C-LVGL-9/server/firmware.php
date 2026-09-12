<?php
include 'config.php';
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Access control: only the administrator may use this portal ---
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

// Preload current version from update/version.json
$currentVersion = '1.0.0'; // Default version
$versionJsonPath = 'update/version.json';
if (file_exists($versionJsonPath)) {
    $jsonContent = file_get_contents($versionJsonPath);
    $versionData = json_decode($jsonContent, true);
    if ($versionData && isset($versionData['version']) && preg_match('/^\d+\.\d+\.\d+$/', $versionData['version'])) {
        $currentVersion = $versionData['version'];
    }
}
// Increment patch version
$versionParts = explode('.', $currentVersion);
$versionParts[2] = (int)$versionParts[2] + 1; // Increment the patch version (z)
$nextVersion = implode('.', $versionParts);

// Handle logout
if (isset($_GET['logout'])) {
    $stmt = $db->prepare("DELETE FROM persistent_sessions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    setcookie('session_lock', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle firmware upload
if (isset($_POST['upload_firmware']) && isset($_SESSION['user_id'])) {
    $version = trim($_POST['version']);
    $file = $_FILES['firmware_file'];
   
    // Validate version format (x.y.z) and filename
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $error_message = "Invalid version format. Use x.y.z (e.g., 1.1.0).";
    } elseif ($file['name'] !== 'firmware.bin') {
        $error_message = "File must be named exactly 'firmware.bin'.";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error_message = "File upload error: " . $file['error'];
    } elseif ($file['size'] > 50 * 1024 * 1024) { // 50MB limit
        $error_message = "File size exceeds 50MB.";
    } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'bin') {
        $error_message = "Only .bin files are allowed.";
    } else {
        $uploadDir = 'update/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
       
        // Rename existing firmware.bin to firmware-<currentVersion>.bin
        $currentFirmwarePath = $uploadDir . 'firmware.bin';
        if (file_exists($currentFirmwarePath)) {
            $newFirmwarePath = $uploadDir . 'firmware-' . $currentVersion . '.bin';
            if (!rename($currentFirmwarePath, $newFirmwarePath)) {
                $error_message = "Failed to rename existing firmware file.";
            }
        }
       
        // Set fixed filename for new firmware
        $uploadPath = $uploadDir . 'firmware.bin';
       
        if (!isset($error_message) && move_uploaded_file($file['tmp_name'], $uploadPath)) {
            // Set permissions for the uploaded firmware file to be accessible via HTTPS
            chmod($uploadPath, 0644);
           
            // Update version.json
            $versionData = [
                'version' => $version,
                'url' => "https://crontech.uk/update/firmware.bin"
            ];
           
            // Write to temporary file and atomically rename to avoid corruption
            $tempFile = tempnam(sys_get_temp_dir(), 'version_');
            file_put_contents($tempFile, json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (rename($tempFile, $versionJsonPath)) {
                // Set permissions for version.json to be accessible via HTTPS
                chmod($versionJsonPath, 0644);
                $success_message = "Firmware uploaded, existing firmware renamed, and version.json updated successfully.";
                // Reload the page to update the version number
                header("Location: firmware.php");
                exit;
            } else {
                unlink($uploadPath); // Rollback upload if JSON update fails
                $error_message = "Failed to update version.json.";
            }
        } else {
            $error_message = "Failed to upload firmware file.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#ffb703">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Custom Calendar">
    <link rel="apple-touch-icon" href="icon-192x192.png">
    <link rel="manifest" href="manifest.json">
    <title>Firmware Upload - Custom Calendar Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary:#ffb703; --primary-hover:#fb8500; --bg-body:#f4f7f9; --bg-card:#ffffff; --text-main:#111827; --text-muted:#4b5563; --border-light:#e5e7eb; }
        * { box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); margin:0; padding:0; line-height:1.6; padding-top:72px; }

        /* Same as the rest of the site. menu.php emits its own rule after this
           block, so the menu colours come from there, exactly as on other pages. */
        .menu { position:fixed; top:0; left:0; width:100%; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.05); display:flex; align-items:center; justify-content:center; gap:1.5rem; padding:1rem 1.25rem; z-index:100; }
        .menu-items { display:flex; justify-content:center; gap:2rem; list-style:none; margin:0; padding:0; }
        .menu-items li a { color:var(--text-main); text-decoration:none; font-weight:700; font-size:.82rem; text-transform:uppercase; letter-spacing:.5px; transition:color .2s; }
        .menu-items li a:hover { color:var(--primary-hover); }
        .menu-toggle { display:none; }
        .bar { height:3px; width:25px; background:#111827; margin:4px 0; }

        .page-section { display:flex; justify-content:center; padding:2rem 1rem; }
        .page-content { width:100%; max-width:820px; }
        .card { background:var(--bg-card); border:1px solid var(--border-light); border-radius:16px; box-shadow:0 15px 35px rgba(0,0,0,.10); padding:2rem; }
        .card + .card { margin-top:1.5rem; }
        .page-title { font-size:1.75rem; font-weight:800; color:var(--text-main); margin:0 0 .25rem; }
        .page-sub { color:var(--text-muted); font-size:.9rem; margin:0 0 1.5rem; }
        .section-title { font-size:1.15rem; font-weight:700; color:var(--text-main); margin:0 0 .35rem; }
        .field-label { display:block; font-size:.85rem; font-weight:600; color:var(--text-main); margin-bottom:.35rem; }

        .btn { display:inline-block; transition:background-color .2s, transform .2s; padding:.75rem 1.5rem; font-size:1rem; font-weight:700; border-radius:.5rem; border:0; cursor:pointer; text-decoration:none; text-align:center; }
        .btn:hover { transform:translateY(-2px); }
        .btn-primary { background:var(--primary); color:#111827; }
        .btn-primary:hover { background:var(--primary-hover); }
        .btn-dark { background:#111827; color:#fff; }
        .btn-danger { background:#dc2626; color:#fff; }
        .btn-block { width:100%; }

        input, select { font-size:1rem !important; padding:.75rem !important; border-radius:.5rem; border:1px solid var(--border-light); width:100%; font-family:'Montserrat', sans-serif; background:#fff; color:var(--text-main); }
        input:focus, select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(255,183,3,.25); }
        input[type="range"] { padding:0 !important; border:0; }
        input[type="checkbox"] { width:auto; }

        .alert { padding:1rem; border-radius:.5rem; margin-bottom:1rem; font-size:.9rem; }
        .alert-success { background-color:#D1FAE5; color:#065F46; }
        .alert-error { background-color:#FEE2E2; color:#991B1B; }

        .preview-box { border-radius:.75rem; border:1px solid var(--border-light); height:5rem; display:flex; align-items:center; justify-content:center; font-weight:700; }
        .swatch { display:inline-block; width:2.25rem; height:2.25rem; border-radius:9999px; margin-right:.75rem; vertical-align:middle; }
        pre { background:#f3f4f6; border:1px solid var(--border-light); border-radius:.5rem; padding:.75rem; overflow-x:auto; font-size:.75rem; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

        footer { padding:2rem; text-align:center; background:#111827; color:#9ca3af; font-size:.8rem; margin-top:2rem; }

        @media(max-width:768px){
            .menu{flex-direction:column;align-items:flex-start;}
            .menu-toggle{display:flex;flex-direction:column;cursor:pointer;position:absolute;right:20px;top:18px;}
            .menu-items{display:none;flex-direction:column;width:100%;text-align:left;gap:.8rem;}
            .menu-items.active{display:flex;}
            .card{padding:1.5rem;}
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>

    <section class="page-section">
        <div class="page-content">
            <div class="card">
                <h1 class="page-title">Firmware Upload Portal</h1>
                <p class="page-sub">Signed in as <?php echo htmlspecialchars($admin_email); ?></p>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <h2 class="section-title">Upload Firmware</h2>
                <p class="page-sub">
                    Publishes <code>update/version.json</code> and replaces <code>update/firmware.bin</code>.
                    The firmware currently on the server is kept as <code>firmware-&lt;version&gt;.bin</code>.
                </p>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <div>
                        <label for="version" class="field-label">New firmware version (x.y.z)</label>
                        <input type="text" name="version" id="version"
                               value="<?php echo htmlspecialchars($nextVersion); ?>"
                               required pattern="\d+\.\d+\.\d+"
                               title="Version must be in x.y.z format (e.g., 1.1.0)">
                    </div>
                    <div>
                        <label for="firmware_file" class="field-label">Firmware file (must be named firmware.bin)</label>
                        <input type="file" name="firmware_file" id="firmware_file" accept=".bin" required>
                    </div>
                    <button type="submit" name="upload_firmware" class="btn btn-primary btn-block">Upload firmware</button>
                </form>

                <div class="mt-6 flex flex-wrap gap-3 justify-center">
                    <a href="settings.php?tab=themes" class="btn btn-dark">Device Portal</a>
                    <a href="?logout=1" class="btn btn-danger">Logout</a>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <p>&copy; 2023 Custom Calendar Service. All rights reserved.</p>
    </footer>
</body>
</html>
