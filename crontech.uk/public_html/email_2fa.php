<?php
header('Content-Type: application/json; charset=utf-8');

$shared_secret = 'CHANGE_THIS_SHARED_SECRET_KEY_123';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$token      = $_POST['token'] ?? '';
$email      = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$type       = $_POST['type'] ?? '2fa'; // '2fa' or 'invite'
$username   = htmlspecialchars(trim($_POST['username'] ?? 'User'));
$pin        = trim($_POST['pin'] ?? '');
$invite_url = filter_var($_POST['invite_url'] ?? '', FILTER_VALIDATE_URL);

if ($token !== $shared_secret) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email address']);
    exit;
}

if ($type === 'invite') {
    if (!$invite_url) {
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid invitation link']);
        exit;
    }

    $subject = "You have been invited to create an account";
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Account Invitation</title>
    </head>
    <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; color: #333;'>
        <div style='max-width: 500px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #ddd;'>
            <h2 style='color: #007bb5; text-align: center; margin-top: 0;'>Welcome to TestFlow</h2>
            <p>Hello,</p>
            <p>You have been invited to create an account. Click the button below to complete your registration:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$invite_url}' style='background-color: #007bb5; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; font-size: 16px;'>Complete Registration</a>
            </div>
            <p style='font-size: 12px; color: #666; line-height: 1.5;'>
                If the button above does not work, copy and paste this URL into your browser:<br>
                <a href='{$invite_url}' style='color: #007bb5; word-break: break-all;'>{$invite_url}</a>
            </p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='font-size: 11px; color: #999; text-align: center; margin-bottom: 0;'>This invitation link is single-use only.</p>
        </div>
    </body>
    </html>
    ";
} else {
    // Standard 2FA Code Email
    if (empty($pin)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing PIN']);
        exit;
    }

    $subject = "Your Login Verification PIN: $pin";
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>2FA Verification Code</title>
    </head>
    <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; color: #333;'>
        <div style='max-width: 450px; margin: 0 auto; background: #ffffff; padding: 25px; border-radius: 8px; border: 1px solid #ddd;'>
            <h2 style='color: #007bb5; text-align: center; margin-top: 0;'>Security Verification</h2>
            <p>Hello <strong>{$username}</strong>,</p>
            <p>Use the following 6-digit PIN code to complete your login:</p>
            <div style='text-align: center; margin: 25px 0;'>
                <span style='font-size: 30px; font-weight: bold; letter-spacing: 6px; color: #222; background-color: #f0f4f8; padding: 12px 24px; border-radius: 6px; border: 1px dashed #007bb5; display: inline-block;'>{$pin}</span>
            </div>
            <p style='font-size: 12px; color: #777;'>This code will expire in 10 minutes. If you did not initiate this request, please change your password immediately.</p>
        </div>
    </body>
    </html>
    ";
}

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-type: text/html; charset=utf-8\r\n";
$headers .= "From: TestFlow Security <no-reply@crontech.uk>\r\n";
$headers .= "Reply-To: no-reply@crontech.uk\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

if (@mail($email, $subject, $message, $headers)) {
    echo json_encode(['status' => 'success', 'message' => 'Email dispatched successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to dispatch email']);
}