<?php
include 'config.php';

$error = '';
$done = false;
$token = trim($_GET['token'] ?? '');
$hash = hash('sha256', $token);

$db->exec("CREATE TABLE IF NOT EXISTS password_resets (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
)");

$stmt = $db->prepare('SELECT user_id, expires_at FROM password_resets WHERE token_hash = ?');
$stmt->execute([$hash]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || (int)$row['expires_at'] < time()) {
    $error = 'This password reset link is invalid or has expired.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pwd  = $_POST['password'] ?? '';
    $pwd2 = $_POST['password2'] ?? '';
    if (strlen($pwd) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pwd !== $pwd2) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $db->prepare('UPDATE users SET password = ? WHERE user_id = ?');
        $stmt->execute([password_hash($pwd, PASSWORD_DEFAULT), $row['user_id']]);
        $db->prepare('DELETE FROM password_resets WHERE token_hash = ?')->execute([$hash]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password - CronTech</title>
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
    <h2 style="text-align:center; margin-top:0;">Set New Password</h2>
    <?php if ($done): ?>
        <p class="msg ok">Your password has been updated.</p>
        <p style="text-align:center;"><a href="login.php">Go to login</a></p>
    <?php elseif ($error !== ''): ?>
        <p class="msg err"><?php echo htmlspecialchars($error); ?></p>
        <p style="text-align:center;"><a href="forgot_password.php">Request a new link</a></p>
    <?php else: ?>
        <form method="POST">
            <input type="password" name="password" placeholder="New password (min 8 chars)" required class="field">
            <input type="password" name="password2" placeholder="Repeat new password" required class="field">
            <button type="submit" class="btn">Update Password</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
