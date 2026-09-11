<?php
session_start();

// 1. Check if the admin is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die('Unauthorized access.');
}

// 2. Get the requested filename safely
$filename = isset($_GET['file']) ? basename($_GET['file']) : '';

if (empty($filename)) {
    die('No file specified.');
}

// 3. Define the secure upload directory (outside public access)
$secure_dir = __DIR__ . '/../uploads/certificates/';
$filepath = $secure_dir . $filename;

// 4. Verify the file exists
if (!file_exists($filepath)) {
    die('Document not found or has been deleted.');
}

// 5. Determine the file type to display it correctly in the browser
$mime_type = mime_content_type($filepath);
$file_ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

// Force PDF and images to show directly in the browser instead of downloading
$inline_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
$disposition = in_array($mime_type, $inline_types) ? 'inline' : 'attachment';

// 6. Output the file headers and contents
header('Content-Type: ' . $mime_type);
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($filepath);
exit;
?>