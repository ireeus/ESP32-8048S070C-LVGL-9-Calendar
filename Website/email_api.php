<?php
/**
 * MoonLight Email Delivery API
 * 
 * PURPOSE: Send transactional emails (2FA codes, password reset links) from a separate dedicated website.
 * This improves security, deliverability, and allows you to manage SPF/DKIM/DMARC independently.
 *
 * UPLOAD INSTRUCTIONS:
 * - Upload this file to your SEPARATE website (e.g. https://mail.yourdomain.com/email_api.php)
 * - Protect the directory with .htaccess (allow only your main server's IP if possible) or use a strong API key.
 * - For production: Replace the basic mail() function with PHPMailer + authenticated SMTP + proper DKIM signing.
 * - Monitor logs for failures.
 *
 * SECURITY NOTES:
 * - The API key must match the one in your main site's config.php
 * - All requests must be POST
 * - Consider adding IP whitelist or additional rate limiting in production.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// === SET YOUR SECRET API KEY HERE (must be identical to EMAIL_API_KEY in config.php) ===
$valid_api_key = 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-KEY-AT-LEAST-32-CHARS';

// Validate API key
$api_key = trim($_POST['api_key'] ?? '');
if (!hash_equals($valid_api_key, $api_key)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: Invalid API key']);
    exit;
}

// Validate and sanitize input
$to         = filter_var(trim($_POST['to'] ?? ''), FILTER_VALIDATE_EMAIL);
$subject    = trim($_POST['subject'] ?? '');
$html_body  = $_POST['message'] ?? '';
$from       = filter_var(trim($_POST['from'] ?? 'no-reply@yourdomain.example.com'), FILTER_VALIDATE_EMAIL) ?: 'no-reply@yourdomain.example.com';
$from_name  = trim($_POST['from_name'] ?? 'MoonLight');

if (!$to || !$subject || empty($html_body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request: Missing to, subject or message']);
    exit;
}

// === EMAIL SENDING IMPLEMENTATION ===
// For production use PHPMailer (recommended). Example with mail() below (may land in spam folder).

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: {$from_name} <{$from}>\r\n";
$headers .= "Reply-To: {$from}\r\n";
$headers .= "X-Mailer: MoonLight-Cloud/1.0\r\n";

$mail_sent = @mail($to, $subject, $html_body, $headers);

if ($mail_sent) {
    // Optional: log success
    echo json_encode(['success' => true]);
} else {
    error_log("[MoonLight Email API] Failed to send email to {$to} | Subject: {$subject}");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal Server Error: Unable to send email. Check mail server configuration.']);
}
?>
