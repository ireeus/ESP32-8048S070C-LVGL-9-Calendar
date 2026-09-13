<?php
include 'config.php';

$error = '';
$sent = false;

$db->exec("CREATE TABLE IF NOT EXISTS password_resets (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['user_id']]);
            $stmt = $db->prepare('INSERT INTO password_resets (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$hash, $user['user_id'], time(), time() + 1800]);

            $link = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'crontech.uk') . '/reset_password.php?token=' . urlencode($token);
            $subject = 'CronTech - Reset your password';
            $message = "<html><body style='font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:24px;color:#333'>"
                . "<div style='max-width:480px;margin:0 auto;background:#ffffff;border-radius:10px;padding:28px;border:1px solid #ddd'>"
                . "<h2 style='color:#3B82F6;margin-top:0'>CronTech</h2>"
                . "<p>We received a request to reset your password. Click the button below to choose a new one. This link is valid for 30 minutes.</p>"
                . "<p style='text-align:center;margin:26px 0'><a href='$link' style='background:#34D399;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block'>Reset password</a></p>"
                . "<p style='font-size:12px;color:#777'>If you did not request this, you can safely ignore this email.</p>"
                . "</div></body></html>";
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: CronTech <no-reply@crontech.uk>\r\n";
            @mail($email, $subject, $message, $headers);
        }
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - CronTech</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --primary:#ffb703; --primary-hover:#fb8500; --text-main:#111827; --text-muted:#4b5563; --border-light:#e5e7eb; }
    * { box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
    body { background-color: #f4f7f9; color: #111827; min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; padding: 1rem; }
    .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 2rem; width: 100%; max-width: 400px; box-shadow: 0 15px 35px rgba(0,0,0,0.10); }
    .field { width: 100%; padding: 12px 16px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.95rem; margin: 0.6rem 0; outline: none; }
    .field:focus { border-color: #ffb703; box-shadow: 0 0 0 3px rgba(255,183,3,0.15); }
    .btn { width: 100%; padding: 13px; border-radius: 8px; background: #ffb703; color: #111; font-weight: 800; border: none; cursor: pointer; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 1px; }
    .btn:hover { background: #fb8500; }
    .msg { font-size: 0.9rem; margin-bottom: 0.75rem; }
    .ok { color: #16a34a; } .err { color: #dc2626; }
    a { color: #fb8500; font-weight: 700; }
    h2 { color: #111827; }
</style>
</head>
<body>
<div class="card">
    <h2 style="text-align:center; margin-top:0;">Forgot Password</h2>
    <p style="text-align:center; color:#666;">Enter your account email and we'll send a reset link.</p>
    <?php if ($sent): ?>
        <p class="msg ok">If an account matches that email, a reset link has been sent. Check your inbox (and spam).</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="msg err"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <form method="POST">
        <input type="email" name="email" placeholder="Email" required class="field">
        <button type="submit" class="btn">Send Reset Link</button>
    </form>
    <p style="text-align:center; margin-top:1rem;"><a href="login.php">Back to login</a></p>
</div>
</body>
</html>
