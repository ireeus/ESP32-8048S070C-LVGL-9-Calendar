<?php
// login.php - Login system
include 'config.php'; // Assuming config.php sets up $db and sessions
if (isset($_SESSION['user_id'])) {
    header("Location: calendar.php");
    exit;
}
$login_error = '';
$info_msg = '';

function send_otp_email($to, $pin) {
    $subject = 'CronTech - Login verification code';
    $message = "<html><body style='font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:24px;color:#333'>"
        . "<div style='max-width:450px;margin:0 auto;background:#ffffff;border-radius:10px;padding:28px;border:1px solid #ddd'>"
        . "<h2 style='color:#3B82F6;margin-top:0'>CronTech</h2>"
        . "<p>Use the following 6-digit code to complete your login:</p>"
        . "<p style='text-align:center;margin:24px 0'><span style='font-size:30px;font-weight:bold;letter-spacing:6px;color:#222;background:#f0f4f8;padding:12px 24px;border-radius:8px;border:1px dashed #3B82F6;display:inline-block'>" . $pin . "</span></p>"
        . "<p style='font-size:12px;color:#777'>This code expires in 10 minutes. If you did not request it, ignore this email.</p>"
        . "</div></body></html>";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: CronTech <no-reply@crontech.uk>\r\n";
    return @mail($to, $subject, $message, $headers);
}

if (isset($_GET['cancel_otp'])) {
    unset($_SESSION['otp_user_id'], $_SESSION['otp_access_code'], $_SESSION['otp_pin'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts']);
    header("Location: login.php");
    exit;
}

if (isset($_POST['verify_otp'])) {
    $pin = trim($_POST['pin'] ?? '');
    if (empty($_SESSION['otp_pin']) || time() > (int)($_SESSION['otp_expiry'] ?? 0)) {
        $login_error = 'The code has expired. Please log in again.';
        unset($_SESSION['otp_user_id'], $_SESSION['otp_access_code'], $_SESSION['otp_pin'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts']);
    } elseif ((int)($_SESSION['otp_attempts'] ?? 0) >= 5) {
        $login_error = 'Too many wrong codes. Please log in again.';
        unset($_SESSION['otp_user_id'], $_SESSION['otp_access_code'], $_SESSION['otp_pin'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts']);
    } elseif (hash_equals((string)$_SESSION['otp_pin'], $pin)) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $_SESSION['otp_user_id'];
        $_SESSION['access_code'] = $_SESSION['otp_access_code'];
        unset($_SESSION['otp_user_id'], $_SESSION['otp_access_code'], $_SESSION['otp_pin'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts']);
        header("Location: calendar.php");
        exit;
    } else {
        $_SESSION['otp_attempts'] = (int)($_SESSION['otp_attempts'] ?? 0) + 1;
        $login_error = 'Wrong code. Please try again.';
    }
}

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify(trim($_POST['password']), $user['password'])) {
        $pin = (string) random_int(100000, 999999);
        $_SESSION['otp_user_id'] = $user['user_id'];
        $_SESSION['otp_access_code'] = $user['access_code'];
        $_SESSION['otp_pin'] = $pin;
        $_SESSION['otp_expiry'] = time() + 600;
        $_SESSION['otp_attempts'] = 0;
        if (send_otp_email($user['username'], $pin)) {
            $info_msg = 'A 6-digit code has been sent to your email.';
        } else {
            $login_error = 'Could not send the code. Please try again or contact support.';
            unset($_SESSION['otp_user_id'], $_SESSION['otp_access_code'], $_SESSION['otp_pin'], $_SESSION['otp_expiry'], $_SESSION['otp_attempts']);
        }
    } else {
        $login_error = 'Invalid username or password.';
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
    <link rel="manifest" href="manifest.json">
    <title>Login - Custom Calendar Service</title>
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
            --success-green: #10b981;
        }
        * { box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
        html { scroll-behavior: smooth; }
        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            line-height: 1.6;
            padding-top: 72px;
        }

        .menu {
            position: fixed; top: 0; left: 0; width: 100%;
            background: #ffffff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex; align-items: center; justify-content: center;
            gap: 1.5rem; padding: 1rem 1.25rem; z-index: 100;
        }
        .menu-items {
            display: flex; justify-content: center; gap: 2rem;
            list-style: none; margin: 0; padding: 0;
        }
        .menu-items li a {
            color: var(--text-main); text-decoration: none;
            font-weight: 700; font-size: 0.82rem; text-transform: uppercase;
            letter-spacing: 0.5px; transition: color 0.2s;
        }
        .menu-items li a:hover { color: var(--primary-hover); }
        .menu-toggle { display: none; }
        .bar { height: 3px; width: 25px; background-color: #111827; margin: 4px 0; }

        .form-section {
            display: flex; align-items: center; justify-content: center;
            min-height: calc(100vh - 72px); padding: 2rem 1rem;
        }
        .form-content, .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.10);
            padding: 2.5rem;
            width: 100%; max-width: 430px;
        }
        .hero-title {
            font-size: 24px; font-weight: 800; color: var(--text-main);
            text-align: center; text-transform: uppercase; margin: 0 0 1.25rem;
        }
        .error-msg {
            color: #b91c1c; background: #fee2e2; border: 1px solid #fecaca;
            border-radius: 8px; padding: 10px 14px; font-size: 0.88rem;
            font-weight: 600; margin-bottom: 1rem;
        }
        .info-msg {
            color: #065f46; background: #ecfdf5; border: 1px solid #a7f3d0;
            border-radius: 8px; padding: 10px 14px; font-size: 0.88rem;
            font-weight: 600; margin-bottom: 1rem;
        }
        .form { display: flex; flex-direction: column; gap: 0.9rem; }
        .form-input {
            width: 100%; padding: 13px 16px;
            border: 1px solid var(--border-light); border-radius: 8px;
            font-size: 0.95rem; font-weight: 500; color: var(--text-main);
            background: #fff; outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 183, 3, 0.15);
        }
        .login-btn {
            width: 100%; padding: 14px; border: none; border-radius: 8px;
            background: var(--primary); color: #111; font-size: 0.95rem;
            font-weight: 800; text-transform: uppercase; letter-spacing: 1px;
            cursor: pointer; transition: all 0.2s;
        }
        .login-btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(255, 183, 3, 0.3);
        }
        .link-text {
            text-align: center; font-size: 0.85rem;
            color: var(--text-muted); margin-top: 0.4rem;
        }
        .link { color: var(--primary-hover); font-weight: 700; text-decoration: none; }
        .link:hover { text-decoration: underline; }
        .otp-input {
            text-align: center; letter-spacing: 0.5em;
            font-size: 1.4rem; font-weight: 800;
        }
        footer {
            padding: 2rem; text-align: center;
            background: #111827; color: #9ca3af; font-size: 0.8rem;
        }

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
        <div class="form-content login-card">
            <h1 class="hero-title">Sign In</h1>
            <?php if ($login_error): ?>
                <p class="error-msg"><?php echo htmlspecialchars($login_error); ?></p>
            <?php endif; ?>
            <?php if ($info_msg): ?>
                <p class="info-msg"><?php echo htmlspecialchars($info_msg); ?></p>
            <?php endif; ?>

            <?php if (!empty($_SESSION['otp_pin'])): ?>
                <form method="POST" class="form">
                    <input type="hidden" name="verify_otp" value="1">
                    <p style="color:#555;margin:0;">Enter the 6-digit code sent to your email.</p>
                    <input type="text" id="otp-pin" name="pin" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="------" required class="form-input otp-input" autofocus>
                    <button type="submit" name="verify_otp" class="login-btn">Verify &amp; Sign In</button>
                    <p class="link-text"><a href="login.php?cancel_otp=1" class="link">Back to login</a></p>
                </form>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var f = document.getElementById('otp-pin');
                    if (!f) return;
                    var done = false;
                    function submitOtp() {
                        if (done) return;
                        var v = f.value.replace(/\D/g, '');
                        if (v.length === 6) { done = true; f.value = v; f.closest('form').submit(); }
                    }
                    f.addEventListener('input', submitOtp);
                    f.addEventListener('paste', function () { setTimeout(submitOtp, 0); });
                });
                </script>
            <?php else: ?>
                <form method="POST" class="form">
                    <input type="email" name="username" placeholder="Email" required class="form-input">
                    <input type="password" name="password" placeholder="Password" required class="form-input">
                    <button type="submit" name="login" class="login-btn">Login</button>
                </form>
                <p class="link-text"><a href="forgot_password.php" class="link">Forgot password?</a></p>
                <p class="link-text">Don't have an account? <a href="register.php" class="link">Register here</a></p>
            <?php endif; ?>
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