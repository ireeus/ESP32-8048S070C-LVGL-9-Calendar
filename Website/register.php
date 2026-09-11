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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { padding-top: 60px;
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            color: #fff;
            overflow-x: hidden;
        }
        .hero, .form-section {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            padding: 4rem 2rem;
        }
        .hero::before, .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        .hero-content, .form-content {
            z-index: 2;
            max-width: 800px;
            width: 100%;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            animation: fadeInDown 1s ease-out;
        }
        .hero-desc {
            font-size: 1.5rem;
            margin-bottom: 2rem;
            animation: fadeInUp 1s ease-out 0.5s;
            animation-fill-mode: backwards;
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .cta-btn {
            background: #34D399;
            color: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            animation: pulse 2s infinite;
            border: none;
            cursor: pointer;
        }
        .cta-btn:hover {
            background: #2FB988;
            transform: scale(1.05);
        }
        .login-btn {
            background: #3B82F6;
            color: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            animation: pulse 2s infinite;
            border: none;
            cursor: pointer;
        }
        .login-btn:hover {
            background: #2563EB;
            transform: scale(1.05);
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .menu {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 1rem;
            display: flex;
            justify-content: center;
            gap: 2rem;
            z-index: 10;
        }
        .menu a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        .menu a:hover {
            color: #34D399;
        }
        footer {
            padding: 2rem;
            text-align: center;
            background: rgba(0, 0, 0, 0.2);
        }
        .form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-width: 400px;
            margin: 0 auto;
        }
        .form-input {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.8);
            color: #333;
            border: none;
            font-size: 1rem;
        }
        .error-msg {
            color: #ff6b6b;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }
        .link-text {
            margin-top: 2rem;
            font-size: 1.2rem;
        }
        .link-text a {
            color: #34D399;
            text-decoration: none;
            transition: color 0.3s;
        }
        .link-text a:hover {
            color: #2FB988;
            text-decoration: underline;
        }
        /* Mobile adjustments */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            .hero-desc {
                font-size: 1.2rem;
            }
            .menu {
                flex-wrap: wrap;
                gap: 1rem;
                justify-content: center;
            }
            .menu a {
                font-size: 0.9rem;
            }
            .form {
                max-width: 100%;
            }
            .form-input, .cta-btn, .login-btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
            }
            .error-msg, .link-text {
                font-size: 1rem;
            }
        }
        @media (max-width: 480px) {
            .hero-title {
                font-size: 1.5rem;
            }
            .hero-desc {
                font-size: 0.9rem;
            }
            .menu {
                flex-direction: column;
                align-items: center;
                gap: 0.3rem;
            }
            .form-input, .cta-btn, .login-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
        }
.menu-toggle {
    display: none;
    flex-direction: column;
    cursor: pointer;
}
.bar {
    height: 3px;
    width: 25px;
    background-color: white;
    margin: 4px 0;
}
.menu-items {
    display: flex;
    justify-content: center;
    gap: 2rem;
    list-style: none;
    margin: 0;
    padding: 0;
}
.menu-items li {
    margin: 0;
}
.menu-items a {
    color: white;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s;
}
.menu-items a:hover {
    color: #34D399;
}
@media (max-width: 768px) {
    .menu-toggle {
        display: flex;
        position: absolute;
        right: 20px;
        top: 15px;
    }
    .menu-items {
        display: none;
        flex-direction: column;
        width: 100%;
        text-align: center;
    }
    .menu-items.active {
        display: flex;
    }
    .menu {
        flex-direction: column;
        align-items: center;
        padding: 1rem 0;
    }
    .menu-items li {
        margin: 10px 0;
    }
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