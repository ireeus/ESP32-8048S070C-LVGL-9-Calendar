<?php
session_start();
include 'config.php'; // Assuming config.php sets up $db

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: calendar.php");
    exit;
}

$register_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate CSRF token

    if (isset($_POST['register'])) {
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        
        // Validate email and password
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($password) >= 8) {
            try {
                // Check if email already exists
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->execute([$email]);
                if ($stmt->fetchColumn() > 0) {
                    $register_error = "Email already registered.";
                } else {
                    // Generate 12-character hexadecimal access code
                    $access_code = bin2hex(random_bytes(6));
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert new user
                    $stmt = $db->prepare("INSERT INTO users (username, password, access_code) VALUES (?, ?, ?)");
                    $stmt->execute([$email, $hashedPassword, $access_code]);
                    
                    // Auto-login after registration
                    $user_id = $db->lastInsertId();
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['access_code'] = $access_code;
                    session_regenerate_id(true);
                    header("Location: calendar.php");
                    exit;
                }
            } catch (PDOException $e) {
                $register_error = "Registration failed.";
            }
        } else {
            $register_error = "Invalid email or password (minimum 8 characters).";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#3B82F6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Custom Calendar">
    <link rel="apple-touch-icon" href="icon-192x192.png">
    <link rel="manifest" href="/manifest.json">
    <title>Register - Custom Calendar Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ffb703;
            --primary-hover: #fb8500;
            --bg-body: #f4f7f9;
            --bg-card: #ffffff;
            --text-main: #111827;
            --text-muted: #4b5563;
            --border-light: #e5e7eb;
            --error-red: #ef4444;
        }
        * { box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
        html { scroll-behavior: smooth; }
        body { background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 0; line-height: 1.6; padding-top: 72px; }

        .menu { position: fixed; top: 0; left: 0; width: 100%; background: #ffffff; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: center; gap: 1.5rem; padding: 1rem 1.25rem; z-index: 100; }
        .menu-items { display: flex; justify-content: center; gap: 2rem; list-style: none; margin: 0; padding: 0; }
        .menu-items li a { color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; transition: color 0.2s; }
        .menu-items li a:hover { color: var(--primary-hover); }
        .menu-toggle { display: none; }
        .bar { height: 3px; width: 25px; background-color: #111827; margin: 4px 0; }

        .form-section { display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 72px); padding: 2rem 1rem; }
        .form-content {
            background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.10); padding: 2.5rem; width: 100%; max-width: 430px;
        }
        .hero-title { font-size: 24px; font-weight: 800; color: var(--text-main); text-align: center; text-transform: uppercase; margin: 0 0 1.25rem; }
        .error-msg { color: #b91c1c; background: #fee2e2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; font-size: 0.88rem; font-weight: 600; margin-bottom: 1rem; }
        .form { display: flex; flex-direction: column; gap: 0.9rem; }
        .form-input {
            width: 100%; padding: 13px 16px; border: 1px solid var(--border-light); border-radius: 8px;
            font-size: 0.95rem; font-weight: 500; color: var(--text-main); background: #fff; outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(255, 183, 3, 0.15); }
        .cta-btn {
            width: 100%; padding: 14px; border: none; border-radius: 8px;
            background: var(--primary); color: #111; font-size: 0.95rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: 1px; cursor: pointer; transition: all 0.2s;
        }
        .cta-btn:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 12px rgba(255, 183, 3, 0.3); }
        .link-text { text-align: center; font-size: 0.85rem; color: var(--text-muted); margin-top: 0.4rem; }
        .link-text a { color: var(--primary-hover); font-weight: 700; text-decoration: none; }
        .link-text a:hover { text-decoration: underline; }
        footer { padding: 2rem; text-align: center; background: #111827; color: #9ca3af; font-size: 0.8rem; }

        @media (max-width: 768px) {
            .menu { flex-direction: column; align-items: flex-start; }
            .menu-toggle { display: flex; flex-direction: column; cursor: pointer; position: absolute; right: 20px; top: 18px; }
            .menu-items { display: none; flex-direction: column; width: 100%; text-align: left; gap: 0.8rem; }
            .menu-items.active { display: flex; }
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>
    <section class="form-section">
        <div class="form-content">
            <h1 class="hero-title">Register</h1>
            <?php if ($register_error): ?>
                <p class="error-msg"><?php echo htmlspecialchars($register_error); ?></p>
            <?php endif; ?>
            <form method="POST" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="email" name="email" placeholder="Email" required class="form-input">
                <input type="password" name="password" placeholder="Password (min 8 characters)" required class="form-input">
                <button type="submit" name="register" class="cta-btn">Register</button>
            </form>
            <p class="link-text">Already have an account? <a href="login.php" class="link-text">Login here</a></p>
        </div>
    </section>
    <footer>
        <p>&copy; 2023 Custom Calendar Service. All rights reserved.</p>
    </footer>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/service-worker.js')
                    .then(registration => {
                        console.log('Service Worker registered with scope:', registration.scope);
                    })
                    .catch(error => {
                        console.error('Service Worker registration failed:', error);
                    });
            });
        }
    </script>
</body>
</html>