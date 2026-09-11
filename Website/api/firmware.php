<?php
session_start();

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
try {
    $db = new PDO('sqlite:users.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create users table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL
    )");
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
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

// Handle registration (for initial setup, can be removed after creating an admin user)
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    
    try {
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$username, $password]);
        $success_message = "Registration successful! Please log in.";
    } catch (PDOException $e) {
        $error_message = "Registration failed: " . $e->getMessage();
    }
}

// Handle login
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify(trim($_POST['password']), $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
    } else {
        $error_message = "Invalid username or password.";
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: firmware.php");
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
        
        // Set fixed filename
        $uploadPath = $uploadDir . 'firmware.bin';
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            // Set permissions for the uploaded firmware file to be accessible via HTTPS
            chmod($uploadPath, 0644);
            
            // Update version.json
            $versionData = [
                'version' => $version,
                'url' => "https://breezbee.co.uk/api/update/firmware.bin"
            ];
            
            // Write to temporary file and atomically rename to avoid corruption
            $tempFile = tempnam(sys_get_temp_dir(), 'version_');
            file_put_contents($tempFile, json_encode($versionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (rename($tempFile, $versionJsonPath)) {
                // Set permissions for version.json to be accessible via HTTPS
                chmod($versionJsonPath, 0644);
                $success_message = "Firmware uploaded and version.json updated successfully.";
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
    <title>Firmware Upload Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(135deg, #1E3A8A, #3B82F6);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            padding: 1rem;
        }
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-radius: 1rem;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            padding: 2rem;
            max-width: 90vw;
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        .btn {
            transition: background-color 0.3s ease, transform 0.2s ease;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 500;
            border-radius: 0.5rem;
            touch-action: manipulation;
        }
        .btn:hover {
            transform: scale(1.05);
        }
        input, select {
            font-size: 1rem !important;
            padding: 0.75rem !important;
            border-radius: 0.5rem;
            border: 1px solid #D1D5DB;
            width: 100%;
            transition: border-color 0.3s ease;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .alert-success {
            background-color: #D1FAE5;
            color: #065F46;
        }
        .alert-error {
            background-color: #FEE2E2;
            color: #991B1B;
        }
        @media (max-width: 640px) {
            .card {
                padding: 1.5rem;
            }
            h1 {
                font-size: 1.75rem;
            }
            h2 {
                font-size: 1.25rem;
            }
            .btn {
                width: 100%;
                padding: 0.75rem;
            }
            input, select {
                font-size: 0.9rem !important;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if (isset($_SESSION['user_id'])): ?>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 text-center">Firmware Upload Portal</h1>
            
            <!-- Display messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <!-- Firmware Upload Form -->
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-4">Upload Firmware</h2>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label for="version" class="block text-sm font-medium text-gray-700 mb-1">New Firmware Version (x.y.z)</label>
                    <input type="text" name="version" id="version" value="<?php echo htmlspecialchars($nextVersion); ?>" required pattern="\d+\.\d+\.\d+" title="Version must be in x.y.z format (e.g., 1.1.0)" class="block w-full">
                </div>
                <div>
                    <label for="firmware_file" class="block text-sm font-medium text-gray-700 mb-1">Firmware File (firmware.bin)</label>
                    <input type="file" name="firmware_file" id="firmware_file" accept=".bin" required class="block w-full">
                </div>
                <button type="submit" name="upload_firmware" class="btn bg-blue-500 text-white hover:bg-blue-600 w-full">Upload Firmware</button>
            </form>
            
            <a href="?logout=1" class="btn mt-6 block text-center bg-red-500 text-white hover:bg-red-600">Logout</a>
        <?php else: ?>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 text-center">Firmware Upload Portal</h1>
            
            <!-- Registration Form (for initial setup) -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-4">Register</h2>
            <form method="POST" class="space-y-4 mb-6">
                <div>
                    <label for="reg_username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username" id="reg_username" placeholder="Username" required class="block w-full">
                </div>
                <div>
                    <label for="reg_password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" id="reg_password" placeholder="Password" required class="block w-full">
                </div>
                <button type="submit" name="register" class="btn bg-green-500 text-white hover:bg-green-600 w-full">Register</button>
            </form>
            
            <!-- Login Form -->
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 mb-4">Login</h2>
            <form method="POST" class="space-y-4">
                <div>
                    <label for="login_username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username" id="login_username" placeholder="Username" required class="block w-full">
                </div>
                <div>
                    <label for="login_password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" id="login_password" placeholder="Password" required class="block w-full">
                </div>
                <button type="submit" name="login" class="btn bg-blue-500 text-white hover:bg-blue-600 w-full">Login</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>