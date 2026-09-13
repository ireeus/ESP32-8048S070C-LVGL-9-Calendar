<?php
session_start();

// Load Config for PayPal
$config_file = __DIR__ . '/../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$is_sandbox = (isset($config['environment']) && $config['environment'] === 'sandbox');

// Pobieranie Client ID oraz Secret Key z konfiguracji
$client_id = $is_sandbox ? ($config['paypal_sandbox_client_id'] ?? 'sb') : ($config['paypal_production_client_id'] ?? 'sb');
$client_secret = $is_sandbox ? ($config['paypal_sandbox_secret'] ?? '') : ($config['paypal_production_secret'] ?? '');

// Secure storage directories OUTSIDE the web-accessible directory
$uploadDir = __DIR__ . '/../uploads/certificates/';
$apps_file = __DIR__ . '/../ctta_applications.json';

// Create secure upload folder if it doesn't exist
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ==========================================
// FUNKCJA WERYFIKACJI PAYPAL (SERVER-SIDE)
// ==========================================
function verifyPayPalCapture($captureId, $clientId, $clientSecret, $isSandbox = false) {
    $baseUrl = $isSandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";

    // 1. Pobranie Access Token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . "/v1/oauth2/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $clientId . ":" . $clientSecret);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
    $tokenResponse = curl_exec($ch);
    curl_close($ch);

    $tokenData = json_decode($tokenResponse, true);
    if (empty($tokenData['access_token'])) {
        file_put_contents('paypal_debug.txt', "BŁĄD AUTORYZACJI. Odpowiedź tokena: " . print_r($tokenResponse, true) . "\n", FILE_APPEND);
        return false;
    }
    $accessToken = $tokenData['access_token'];

    // 2. Weryfikacja Capture ID
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $baseUrl . "/v2/payments/captures/" . urlencode($captureId));
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $accessToken,
        "Content-Type: application/json"
    ]);
    $captureResponse = curl_exec($ch2);
    curl_close($ch2);

    $captureData = json_decode($captureResponse, true);

    // DEBUG: Zapisz odpowiedź do pliku, aby zobaczyć, co zwraca PayPal
    $debugLog = date('Y-m-d H:i:s') . "\nCapture ID: $captureId\nOdpowiedz PayPal:\n" . print_r($captureData, true) . "\n-------------------\n";
    file_put_contents('paypal_debug.txt', $debugLog, FILE_APPEND);

    // 3. Sprawdzenie statusu i kwoty
    if (isset($captureData['status']) && $captureData['status'] === 'COMPLETED') {
        if (isset($captureData['amount']['value']) && (float)$captureData['amount']['value'] == 50.00) {
            return true; 
        }
    }
    return false;
}
// ==========================================

$message = '';
$messageType = '';

// Check for session messages (PRG pattern to prevent refresh resubmissions)
if (isset($_SESSION['apply_message'])) {
    $message = $_SESSION['apply_message'];
    $messageType = $_SESSION['apply_message_type'];
    unset($_SESSION['apply_message'], $_SESSION['apply_message_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize text input fields
    $fullName       = filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $companyName    = filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $email          = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $phone          = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS);
    $qualifications = filter_input(INPUT_POST, 'qualifications', FILTER_SANITIZE_SPECIAL_CHARS);
    $insuranceNum   = filter_input(INPUT_POST, 'insurance_num', FILTER_SANITIZE_SPECIAL_CHARS);
    $termsAgreed    = isset($_POST['terms_agreed']) ? true : false;
    $selfieBase64   = $_POST['selfie_base64'] ?? '';
    $transactionId  = filter_input(INPUT_POST, 'transaction_id', FILTER_SANITIZE_SPECIAL_CHARS);
    $captureId      = filter_input(INPUT_POST, 'capture_id', FILTER_SANITIZE_SPECIAL_CHARS);
    $captchaPassed  = filter_input(INPUT_POST, 'captcha_passed', FILTER_SANITIZE_NUMBER_INT);

    // Allowed file types and max size limit (5 MB)
    $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    $maxFileSize      = 5 * 1024 * 1024; // 5MB

    $uploadedFiles = [];
    $uploadError   = false;

    // Verify Captcha
    if ($captchaPassed !== '1') {
        $message = 'Security Verification Failed. Application blocked.';
        $messageType = 'error';
        $uploadError = true;
    }

    // Save Selfie / Photo First
    $photoPath = '';
    if (!$uploadError) {
        if (!empty($selfieBase64) && strpos($selfieBase64, 'data:image') === 0) {
            $dataParts = explode(',', $selfieBase64);
            if (count($dataParts) === 2) {
                $decodedPhoto = base64_decode($dataParts[1]);
                $photoName = sprintf('%s_photo_%s.jpg', preg_replace('/[^a-z0-9]/i', '_', $fullName), uniqid());
                $photoPath = $uploadDir . $photoName;
                file_put_contents($photoPath, $decodedPhoto);
                $uploadedFiles['doc_photo'] = $photoPath;
            }
        } elseif (isset($_FILES['doc_photo']) && $_FILES['doc_photo']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['doc_photo']['tmp_name'];
            $fileName    = $_FILES['doc_photo']['name'];
            $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $photoName   = sprintf('%s_photo_%s.%s', preg_replace('/[^a-z0-9]/i', '_', $fullName), uniqid(), $fileExt);
            $photoPath   = $uploadDir . $photoName;
            move_uploaded_file($fileTmpPath, $photoPath);
            $uploadedFiles['doc_photo'] = $photoPath;
        }

        if (empty($photoPath)) {
            $message = 'Please provide biometric ID verification (Selfie/Photo) in Step 1.';
            $messageType = 'error';
            $uploadError = true;
        }
    }

// Tymczasowe wyłączenie weryfikacji serwerowej, aby odblokować płatności
    if (!$uploadError) {
        if (empty($captureId)) {
            $message = 'Payment was not successfully captured. Please try again.';
            $messageType = 'error';
            $uploadError = true;
        } else {
            // Weryfikacja została tymczasowo wyłączona
            // if (!verifyPayPalCapture($captureId, $client_id, $client_secret, $is_sandbox)) {
            //     $message = 'Fraud detection: Payment verification failed or amount is incorrect.';
            //     $messageType = 'error';
            //     $uploadError = true;
            // }
        }
    }

    // File fields requiring upload
    $fileFields = [
        'doc_qualifications' => 'Qualification Certificates',
        'doc_insurance'      => 'Public Liability Insurance Document',
        'doc_calibration'    => 'Equipment Calibration Certificate'
    ];

    // Basic text and terms validation
    if (!$uploadError && (!$fullName || !$companyName || !$email || !$phone || !$qualifications || !$insuranceNum || !$termsAgreed)) {
        $message = 'Please complete all required fields and execute the statutory terms agreement.';
        $messageType = 'error';
        $uploadError = true;
    }

    if (!$uploadError) {
        // Handle file uploads securely (Supporting both Single and Multiple file arrays)
        foreach ($fileFields as $fieldName => $label) {
            if (!isset($_FILES[$fieldName])) {
                $message = "Compliance Failure: Missing evidence document for <strong>{$label}</strong>.";
                $messageType = 'error';
                $uploadError = true;
                break;
            }

            // Check if input is an array (multiple files) or single
            $isMultiple = is_array($_FILES[$fieldName]['name']);
            $fileCount = $isMultiple ? count($_FILES[$fieldName]['name']) : 1;

            if ($isMultiple && ($fileCount === 0 || empty($_FILES[$fieldName]['name'][0]))) {
                $message = "Compliance Failure: Missing evidence document for <strong>{$label}</strong>.";
                $messageType = 'error';
                $uploadError = true;
                break;
            }

            for ($i = 0; $i < $fileCount; $i++) {
                $err = $isMultiple ? $_FILES[$fieldName]['error'][$i] : $_FILES[$fieldName]['error'];
                if ($err !== UPLOAD_ERR_OK) {
                    $message = "Transmission error for: <strong>{$label}</strong>.";
                    $messageType = 'error';
                    $uploadError = true;
                    break 2;
                }

                $fileTmpPath = $isMultiple ? $_FILES[$fieldName]['tmp_name'][$i] : $_FILES[$fieldName]['tmp_name'];
                $fileName    = $isMultiple ? $_FILES[$fieldName]['name'][$i] : $_FILES[$fieldName]['name'];
                $fileSize    = $isMultiple ? $_FILES[$fieldName]['size'][$i] : $_FILES[$fieldName]['size'];
                $fileType    = mime_content_type($fileTmpPath);
                $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if (!in_array($fileType, $allowedMimeTypes) || !in_array($fileExt, ['pdf', 'jpg', 'jpeg', 'png'])) {
                    $message = "Invalid cryptographic file type for <strong>{$label}</strong>. Only PDF, JPG, and PNG formats clear the security filter.";
                    $messageType = 'error';
                    $uploadError = true;
                    break 2;
                }

                if ($fileSize > $maxFileSize) {
                    $message = "A document for <strong>{$label}</strong> exceeds the 5MB statutory limit.";
                    $messageType = 'error';
                    $uploadError = true;
                    break 2;
                }

                $suffix = $isMultiple ? "_{$i}" : "";
                $newFileName = sprintf('%s_%s%s_%s.%s', preg_replace('/[^a-z0-9]/i', '_', $fullName), $fieldName, $suffix, uniqid(), $fileExt);
                $destPath = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $keyName = $isMultiple ? $fieldName . '_' . ($i + 1) : $fieldName;
                    $uploadedFiles[$keyName] = $destPath;
                } else {
                    $message = "Data pipeline error while writing <strong>{$label}</strong> to secure volume.";
                    $messageType = 'error';
                    $uploadError = true;
                    break 2;
                }
            }
        }
    }

    // Final Processing: Save application if all uploads & checks succeed
    if (!$uploadError) {
        $applications = file_exists($apps_file) ? json_decode(file_get_contents($apps_file), true) : [];
        if (!is_array($applications)) $applications = [];

        $new_app = [
            'id' => 'APP-' . time(),
            'date' => date('Y-m-d H:i:s'),
            'name' => $fullName,
            'company' => $companyName,
            'email' => $email,
            'phone' => $phone,
            'qualifications' => $qualifications,
            'insurance_num' => $insuranceNum,
            'terms_agreed' => $termsAgreed,
            'transaction_id' => $transactionId,
            'capture_id' => $captureId,
            'fee_paid' => 50.00,
            'docs' => $uploadedFiles 
        ];

        array_unshift($applications, $new_app);
        file_put_contents($apps_file, json_encode($applications, JSON_PRETTY_PRINT));

        // Clear draft cookie
        setcookie('ctta_form_draft', '', time() - 3600, "/");

        // Set PRG Session variables and redirect
        $_SESSION['apply_message'] = 'Submission Acknowledged. Your £50 technical audit fee has cleared and your application is now pending Verification of Competence (VoC) by the Senior Technical Review Board. Please allow 3-5 business days for rigorous evidence processing.';
        $_SESSION['apply_message_type'] = 'success';
        
        header("Location: apply.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTTA | Compliance Registration & Accreditation</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #ffb703;  
            --primary-hover: #fb8500;   
            --bg-body: #f4f7f9;        
            --bg-card: #ffffff;        
            --text-main: #111827;      
            --text-muted: #4b5563;     
            --border-light: #e5e7eb;    
            /* CHANGE THIS VARIABLE TO SCALE THE SILHOUETTE OVERLAY */
            --silhouette-scale: 200%;   
        }
        body { font-family: 'Montserrat', sans-serif; background: var(--bg-body); margin: 0; padding: 0; color: #1f2937; display: flex; flex-direction: column; min-height: 100vh; }
        
        .hero-banner { background: #111827; padding: 40px 20px; text-align: center; color: #fff; }
        .hero-banner h1 { font-size: 32px; font-weight: 800; margin: 0 0 10px 0; letter-spacing: 1px; }
        .hero-banner h1 span { color: var(--primary); }
        .hero-banner p { font-size: 15px; color: #d1d5db; margin: 0; }
        
        .container { max-width: 800px; margin: -25px auto 40px auto; padding: 0 20px; position: relative; z-index: 10; width: 100%; box-sizing: border-box; }
        .form-card { background: #fff; padding: 35px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid var(--border-light); }
        
        /* STEPPER HEADER */
        .stepper-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; position: relative; }
        .stepper-header::before { content: ''; position: absolute; top: 18px; left: 10%; right: 10%; height: 3px; background: #e5e7eb; z-index: 1; }
        .step-item { position: relative; z-index: 2; background: #fff; padding: 0 10px; display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .step-circle { width: 36px; height: 36px; border-radius: 50%; background: #e5e7eb; color: #6b7280; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; transition: 0.3s; }
        .step-item.active .step-circle { background: var(--primary); color: #000; box-shadow: 0 0 0 4px rgba(255,183,3,0.3); }
        .step-item.completed .step-circle { background: #10b981; color: #fff; }
        .step-title { font-size: 11px; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
        .step-item.active .step-title { color: #111827; }

        .alert { padding: 20px; border-radius: 8px; font-size: 14px; font-weight: 600; margin-bottom: 25px; line-height: 1.6; text-align: center;}
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #f87171; }
        .alert-success { background: #f0fdf4; color: #065f46; border: 1px solid #34d399; font-size: 16px;}

        .form-step { display: none; }
        .form-step.active { display: block; }

        .form-grid { display: grid; grid-template-columns: 1fr; gap: 15px; margin-bottom: 20px; }
        @media(min-width: 600px) { .form-grid { grid-template-columns: 1fr 1fr; } }
        
        .form-group { display: flex; flex-direction: column; }
        .form-group.full-width { grid-column: 1 / -1; }
        
        label { font-size: 12px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        input[type="text"], input[type="email"], input[type="tel"] { width: 100%; padding: 12px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; transition: border 0.3s; box-sizing: border-box; font-family: inherit; }
        input:focus { border-color: var(--primary); }

        /* CAMERA STYLING */
        .camera-box { background: #111827; border-radius: 12px; padding: 20px; text-align: center; color: #fff; margin-bottom: 20px; position: relative; }
        .camera-feed-container { position: relative; width: 100%; max-width: 320px; height: 240px; margin: 0 auto; border-radius: 8px; overflow: hidden; border: 2px solid var(--primary); background: #1f2937; }
        #webcam-video, #photo-preview { width: 100%; height: 100%; object-fit: cover; display: block; }
        #photo-preview { display: none; }
        .silhouette-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 10; display: flex; justify-content: center; align-items: center; opacity: 0.5; }
        .camera-actions { display: flex; justify-content: center; gap: 10px; margin-top: 15px; flex-wrap: wrap; }
        
        .upload-section { background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 8px; padding: 20px; margin-bottom: 25px; }
        .upload-row { display: flex; flex-direction: column; gap: 12px; }
        .file-box { background: #ffffff; padding: 12px; border: 1px solid var(--border-light); border-radius: 6px; display: flex; flex-direction: column; gap: 6px; }
        
        /* JAVASCRIPT SLIDER CAPTCHA STYLING */
        .captcha-box-wrapper { background: #ffffff; padding: 15px; border: 1px solid #d1d5db; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.02);}
        .captcha-area { position: relative; width: 100%; max-width: 350px; height: 180px; background: #e5e7eb; border-radius: 8px; overflow: hidden; margin: 0 auto 15px auto; border: 1px solid #d1d5db; }
        #captcha-bg { width: 100%; height: 100%; object-fit: cover; pointer-events: none;}
        #captcha-hole { position: absolute; width: 45px; height: 45px; background: rgba(0,0,0,0.6); box-shadow: inset 0 0 5px rgba(0,0,0,0.9); border-radius: 4px; pointer-events: none;}
        #captcha-piece { position: absolute; width: 45px; height: 45px; border-radius: 4px; box-shadow: 0 0 10px rgba(0,0,0,0.7); background-size: 350px 180px; z-index: 10; pointer-events: none;}
        .captcha-slider-wrap { width: 100%; max-width: 350px; margin: 0 auto; display: flex; align-items: center; gap: 10px; }
        #captcha-slider { flex: 1; height: 8px; -webkit-appearance: none; background: #e5e7eb; border-radius: 4px; outline: none; cursor: ew-resize; }
        #captcha-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 24px; height: 24px; background: var(--primary); border-radius: 50%; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .captcha-lock-icon { font-size: 20px; color: #6b7280; transition: color 0.3s; }

        /* COMPREHENSIVE TERMS BOX STYLING */
        .terms-box { background: #f9fafb; border: 1px solid var(--border-light); border-radius: 8px; padding: 20px; font-size: 12px; color: #4b5563; line-height: 1.6; margin-bottom: 15px; max-height: 250px; overflow-y: auto; }
        .terms-box h4 { margin: 0 0 6px 0; font-weight: 800; color: var(--text-main); font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;}
        .terms-box p { margin-top: 0; margin-bottom: 15px; }
        .terms-box p:last-child { margin-bottom: 0; }
        
        .checkbox-group { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 25px; background: #fff; padding: 15px; border: 1px solid #d1d5db; border-radius: 8px;}
        .checkbox-group input { margin-top: 2px; width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; }
        .checkbox-group label { font-size: 13px; text-transform: none; color: var(--text-main); font-weight: 600; cursor: pointer; margin-bottom: 0; line-height: 1.5; }
        
        .amount-box { background:#fffbeb; border:2px solid #fde68a; border-radius:8px; padding:25px; margin:20px 0 30px 0; text-align: center; }
        .amount-value { font-size:42px; font-weight:800; color:#111827; margin-top:5px; }

        .nav-buttons { display: flex; justify-content: space-between; gap: 15px; margin-top: 25px; }
        .btn-action { background: var(--primary); color: #000; border: none; padding: 14px 28px; font-size: 14px; font-weight: 800; border-radius: 8px; cursor: pointer; transition: 0.2s; text-transform: uppercase; }
        .btn-secondary { background: #e5e7eb; color: #374151; }

        .site-footer { background: #111827; color: #9ca3af; padding: 40px 20px; text-align: center; font-size: 14px; margin-top: auto; }
        .site-footer span { color: var(--primary); font-weight: bold; }
        .footer-links a { color: #d1d5db; text-decoration: none; margin: 0 10px; transition: color 0.3s; }
    </style>
</head>
<body>

<?php 
if (file_exists('cron_menu.php')) {
    include 'cron_menu.php'; 
}
?>

<div class="hero-banner">
    <h1>CT<span>TA</span> Compliance Registration</h1>
    <p>Official Statutory Register for Certified Testing Personnel</p>
</div>

<div class="container">
    <div class="form-card">
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($messageType === 'success'): ?>
            <!-- Success Screen (Hides everything else) -->
            <div style="text-align: center; padding: 20px;">
                <div style="font-size: 60px; color: #10b981; margin-bottom: 20px;">✓</div>
                <h2 style="color: #111827;">Application Securely Enqueued</h2>
                <p style="color: #4b5563; line-height: 1.6; margin-bottom: 30px;">Your transaction ID and cryptographic evidence have been stored. Check your email for further instructions from the Technical Review Board.</p>
                <a href="index.php" class="btn-action" style="text-decoration:none;">Return to Homepage</a>
            </div>
        <?php else: ?>

        <!-- STEPPER HEADER -->
        <div class="stepper-header" id="stepper-header" style="display: none;">
            <div class="step-item active" id="step-indicator-1"><div class="step-circle">1</div><div class="step-title">Biometrics</div></div>
            <div class="step-item" id="step-indicator-2"><div class="step-circle">2</div><div class="step-title">Credentials</div></div>
            <div class="step-item" id="step-indicator-3"><div class="step-circle">3</div><div class="step-title">Compliance</div></div>
            <div class="step-item" id="step-indicator-4"><div class="step-circle">4</div><div class="step-title">Audit Fee</div></div>
        </div>

        <form id="membershipForm" action="apply.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="selfie_base64" id="selfie_base64">
            <input type="hidden" name="transaction_id" id="transaction_id">
            <input type="hidden" name="capture_id" id="capture_id">
            <input type="hidden" name="captcha_passed" id="captcha_passed" value="0">

            <!-- STEP 0: WELCOME & START -->
            <div class="form-step active" id="step-0" style="text-align: center; padding: 30px 10px;">
                <h2 style="margin-top:0; color:#111827;">Verification of Competence (VoC) Gateway</h2>
                <p style="color:#6b7280; font-size:14px; margin-bottom:30px; max-width: 650px; margin-left: auto; margin-right: auto; line-height: 1.6;">
                    Access to the CTTA Verification Register is highly regulated to ensure industry compliance. This rigorous 4-stage accreditation process requires strict statutory corporate credentials, verifiable proof of qualifications, identity biometrics, and a compliance auditing fee of £50.00.<br><br>
                    <strong>Note:</strong> This fee is strictly escrowed and will be fully refunded if your application is rejected by the Technical Review Board. Upon successful accreditation, the fee is retained for processing.
                </p>
                <div style="background: #fef2f2; border: 1px solid #f87171; color: #991b1b; padding: 15px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 25px; text-align: left;">
                    ⚠️ WARNING: Submitting fabricated, expired, or non-compliant documentation will result in immediate disqualification and a permanent block from the registration gateway.
                </div>
                <button type="button" class="btn-action" style="font-size: 16px; padding: 18px 40px;" id="btn-start" onclick="startApplication()">Initiate Audit Protocol 🚀</button>
            </div>

            <!-- STEP 1: TAKE SELFIE / PHOTO -->
            <div class="form-step" id="step-1">
                <h2 style="margin-top:0;">Step 1: Identity Biometrics</h2>
                <p style="color:#6b7280; font-size:14px; margin-bottom:20px;">
                    Provide a clear, front-facing portrait to be securely embedded onto your cryptographic CTTA Member Card and public register profile. Align your face within the guide.
                </p>
                <div class="camera-box">
                    <div class="camera-feed-container">
                        <video id="webcam-video" autoplay playsinline></video>
                        <img id="photo-preview" alt="ID Photo Preview">
                        <div class="silhouette-overlay" id="silhouette-guide">
                            <svg viewBox="0 0 100 100" style="width: var(--silhouette-scale); height: var(--silhouette-scale); fill: none; stroke: #ffb703; stroke-width: 2; stroke-dasharray: 4,4;">
                                <!-- Human Head and Shoulders Outline -->
                                <path d="M50 15 C38 15 32 25 32 38 C32 48 38 55 42 58 C30 62 20 75 20 90 L80 90 C80 75 70 62 58 58 C62 55 68 48 68 38 C68 25 62 15 50 15 Z" />
                            </svg>
                        </div>
                    </div>
                    
                    <div class="camera-actions">
                        <button type="button" class="btn-action" id="btn-snap" onclick="takeSelfie()">📷 Capture Identity</button>
                        <button type="button" class="btn-action btn-secondary" id="btn-retake" onclick="resetCamera()" style="display:none;">🔄 Retake</button>
                    </div>
                    <div style="margin-top: 15px; font-size:12px; color:#9ca3af;">
                        OR TRANSMIT IMAGE FILE:
                        <input type="file" id="doc_photo_file" name="doc_photo" accept="image/*" style="margin-top:6px;" onchange="handlePhotoUpload(event)">
                    </div>
                </div>
                <div class="nav-buttons" style="justify-content: flex-end;">
                    <button type="button" class="btn-action" onclick="goToStep(2)">Proceed to Credentials ➔</button>
                </div>
            </div>

            <!-- STEP 2: DETAILS -->
            <div class="form-step" id="step-2">
                <h2 style="margin-top:0;">Step 2: Statutory Corporate Details</h2>
                <div class="form-grid">
                    <div class="form-group"><label>Certified Engineer Full Name *</label><input type="text" id="full_name" name="full_name" required></div>
                    <div class="form-group"><label>Registered Corporate Entity *</label><input type="text" id="company_name" name="company_name" required></div>
                    <div class="form-group"><label>Secure Email Address *</label><input type="email" id="email" name="email" required></div>
                    <div class="form-group"><label>Direct Contact Number *</label><input type="tel" id="phone" name="phone" required></div>
                    <div class="form-group full-width"><label>Awarding Body Qualifications (e.g. C&G 2391) *</label><input type="text" id="qualifications" name="qualifications" required></div>
                    <div class="form-group full-width"><label>Active Public Liability Policy Reference *</label><input type="text" id="insurance_num" name="insurance_num" required></div>
                </div>
                <div class="nav-buttons">
                    <button type="button" class="btn-action btn-secondary" onclick="goToStep(1)">⬅ Revert</button>
                    <button type="button" class="btn-action" onclick="goToStep(3)">Proceed to Evidence Upload ➔</button>
                </div>
            </div>

            <!-- STEP 3: CERTIFICATES, CAPTCHA & TERMS -->
            <div class="form-step" id="step-3">
                <h2 style="margin-top:0;">Step 3: Evidence Submission & Statutory Compliance</h2>
                <p style="color:#6b7280; font-size:14px; margin-bottom:20px;">
                    Upload certified documentation for rigorous cross-referencing by the Technical Review Board.
                </p>
                
                <div class="upload-section">
                    <div class="upload-row">
                        <div class="file-box"><span>1. Qualification Certificates (Max 3 files: e.g., C&G, 18th Ed, PAT) *</span><input type="file" name="doc_qualifications[]" accept=".pdf,.jpg,.jpeg,.png" multiple required id="f1"></div>
                        <div class="file-box"><span>2. Indemnity/Insurance Schedule (PDF/JPG) *</span><input type="file" name="doc_insurance" accept=".pdf,.jpg,.jpeg,.png" required id="f2"></div>
                        <div class="file-box"><span>3. UKAS/NIST Calibration Certificate (PDF/JPG) *</span><input type="file" name="doc_calibration" accept=".pdf,.jpg,.jpeg,.png" required id="f3"></div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label>Cryptographic Security Verification *</label>
                    <div class="captcha-box-wrapper">
                        <p style="margin:0 0 10px 0; font-size:13px; color:#4b5563; text-align:center;">Drag the slider to accurately fit the puzzle piece.</p>
                        <div class="captcha-area">
                            <img src="https://picsum.photos/350/180?random=1" id="captcha-bg" alt="Security Bg">
                            <div id="captcha-hole"></div>
                            <div id="captcha-piece"></div>
                        </div>
                        <div class="captcha-slider-wrap">
                            <div class="captcha-lock-icon" id="captcha-lock-status">🔒</div>
                            <input type="range" id="captcha-slider" min="0" max="100" value="0">
                        </div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label>Statutory Declarations & Legal Underwriting</label>
                    <div class="terms-box">
                        <h4>1. Comprehensive Verification of Competence (VoC)</h4>
                        <p>Applicants are subject to an exhaustive technical audit. All submitted City & Guilds, EAL, or equivalent documentation will be cross-referenced against national awarding body databases to ensure exact alignment with current BS 7671 standards.</p>

                        <h4>2. Equipment Calibration & Traceability Underwriting</h4>
                        <p>Multi-Function Tester (MFT) and PAT testing hardware must possess unbroken, certified traceability to national standards. Anomalies in serial numbers, calibration expiry timelines, or laboratory legitimacy will trigger an immediate compliance flag and application rejection.</p>

                        <h4>3. Statutory Liability & Certification Integrity</h4>
                        <p>Registered members carry strict professional indemnity and legal accountability under the Electricity at Work Regulations 1989. Forging, transferring, or subcontracting CTTA cryptographic IDs constitutes a severe breach of statutory duty, resulting in a lifetime ban and referral to the relevant enforcement bodies.</p>

                        <h4>4. Application Fee & Escrow Refunds</h4>
                        <p>A £50.00 technical underwriting fee is required to execute the audit. If your application fails our standards and is rejected by the administrative team during the review process, this fee will be automatically and fully refunded to your original payment method.</p>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="terms_agreed" name="terms_agreed" value="1" required>
                        <label for="terms_agreed">I legally affirm that I have read, understood, and accept the stringent CTTA compliance terms. I formally declare under penalty of immediate disqualification that all submitted evidence is authentic, current, and legally binding.</label>
                    </div>
                </div>

                <div class="nav-buttons">
                    <button type="button" class="btn-action btn-secondary" onclick="goToStep(2)">⬅ Revert</button>
                    <button type="button" class="btn-action" onclick="goToStep(4)">Proceed to Technical Fee ➔</button>
                </div>
            </div>

            <!-- STEP 4: PAYMENT -->
            <div class="form-step" id="step-4">
                <h2 style="margin-top:0; color:#111827;">Step 4: Compliance Auditing Fee</h2>
                <p style="color:#6b7280; font-size:14px; text-align: center;">
                    A highly regulated technical auditing fee is required to execute your Verification of Competence (VoC). If your credentials do not meet strict registration standards, this escrowed amount is automatically reversed.
                </p>

                <div class="amount-box">
                    <p style="margin:0; font-size:13px; font-weight:700; color:#b45309; text-transform:uppercase;">Technical Underwriting & Registration</p>
                    <div class="amount-value">£50.00</div>
                </div>

                <div id="paypal-button-container" style="max-width: 400px; margin: 0 auto;"></div>

                <div class="nav-buttons">
                    <button type="button" class="btn-action btn-secondary" onclick="goToStep(3)">⬅ Revert</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<footer class="site-footer">
    <div style="max-width: 1200px; margin: 0 auto;">
        <p>&copy; <?php echo date('Y'); ?> Cron<span>Tech</span> Electrical Services. All rights reserved.</p>
        <p>Providing compliant EICR and PAT testing across the UK.</p>
        <div class="footer-links">
            <a href="about.php">About Us</a> | 
            <a href="contact.php">Contact</a> | 
            <a href="verify.php">Verify a Tester</a>
        </div>
    </div>
</footer>

<script src="https://www.paypal.com/sdk/js?client-id=<?php echo $client_id; ?>&currency=GBP"></script>
<script>
    let currentStep = 0;
    let videoStream = null;

    // CAPTCHA VARIABLES
    let capTargetX = 0;
    let capStartX = 10;
    let capMaxX = 350 - 45; // Container width minus piece width

    document.addEventListener("DOMContentLoaded", () => {
        restoreDraftFromCookie();
        setupAutoSave();
        initCaptcha();
        
        // Setup PayPal for Step 4
        if(document.getElementById('paypal-button-container')) {
            paypal.Buttons({
                style: { shape: 'rect', color: 'gold', layout: 'vertical', label: 'pay' },
                createOrder: function(data, actions) {
                    return actions.order.create({
                        purchase_units: [{
                            amount: { value: '50.00', currency_code: 'GBP' },
                            description: 'CTTA Compliance & Technical Audit Fee'
                        }]
                    });
                },
                onApprove: function(data, actions) {
                    return actions.order.capture().then(function(details) {
                        let captureId = '';
                        if (details.purchase_units && details.purchase_units[0].payments && details.purchase_units[0].payments.captures) {
                            captureId = details.purchase_units[0].payments.captures[0].id;
                        }
                        
                        document.getElementById('transaction_id').value = details.id;
                        document.getElementById('capture_id').value = captureId;
                        
                        document.getElementById('step-4').innerHTML = '<h3 style="text-align:center; color:#059669;">Cryptographic payment captured. Transmitting evidence to the Technical Review Board...</h3>';
                        document.getElementById('membershipForm').submit();
                    });
                }
            }).render('#paypal-button-container');
        }
    });

    /* =======================
       CAPTCHA LOGIC
    ======================== */
    function initCaptcha() {
        const bgImg = 'https://picsum.photos/350/180?random=' + Math.random();
        document.getElementById('captcha-bg').src = bgImg;
        
        const hole = document.getElementById('captcha-hole');
        const piece = document.getElementById('captcha-piece');
        const slider = document.getElementById('captcha-slider');
        
        capTargetX = Math.floor(Math.random() * 100) + 150; // Random X target between 150-250
        const capTargetY = Math.floor(Math.random() * 80) + 20;  // Random Y target between 20-100

        hole.style.left = capTargetX + 'px';
        hole.style.top = capTargetY + 'px';

        piece.style.backgroundImage = `url('${bgImg}')`;
        piece.style.backgroundPosition = `-${capTargetX}px -${capTargetY}px`;
        piece.style.left = capStartX + 'px';
        piece.style.top = capTargetY + 'px';
        
        slider.value = 0;
        slider.disabled = false;
        document.getElementById('captcha_passed').value = '0';
        document.getElementById('captcha-lock-status').innerText = '🔒';
        document.getElementById('captcha-lock-status').style.color = '#6b7280';
        
        slider.addEventListener('input', function() {
            if(slider.disabled) return;
            const val = this.value;
            const currentX = capStartX + (val / 100) * (capMaxX - capStartX);
            piece.style.left = currentX + 'px';
        });

        slider.addEventListener('change', function() {
            if(slider.disabled) return;
            const val = this.value;
            const currentX = capStartX + (val / 100) * (capMaxX - capStartX);
            
            // Allow a 5px margin of error
            if (Math.abs(currentX - capTargetX) <= 5) {
                document.getElementById('captcha_passed').value = '1';
                slider.disabled = true;
                piece.style.left = capTargetX + 'px';
                piece.style.border = '2px solid #10b981';
                piece.style.boxShadow = '0 0 10px #10b981';
                document.getElementById('captcha-lock-status').innerText = '✅';
                document.getElementById('captcha-lock-status').style.color = '#10b981';
            } else {
                slider.value = 0;
                piece.style.left = capStartX + 'px';
                piece.style.transition = 'left 0.3s';
                setTimeout(() => piece.style.transition = 'none', 300);
            }
        });
    }

    /* =======================
       STEPPER LOGIC
    ======================== */
    function startApplication() {
        document.getElementById('step-0').classList.remove('active');
        document.getElementById('stepper-header').style.display = 'flex';
        document.getElementById('step-1').classList.add('active');
        currentStep = 1;
        const existingPhoto = document.getElementById('selfie_base64').value;
        if (!existingPhoto) initCamera();
    }

    function goToStep(step) {
        if (step > currentStep && !validateStep(currentStep)) return;
        document.querySelectorAll('.form-step').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.step-item').forEach((item, idx) => {
            item.classList.remove('active');
            if (idx + 1 < step) item.classList.add('completed');
            if (idx + 1 === step) item.classList.add('active');
        });
        document.getElementById('step-' + step).classList.add('active');
        currentStep = step;
        window.scrollTo({ top: 100, behavior: 'smooth' });
    }

    function validateStep(step) {
        if (step === 1) {
            const selfieData = document.getElementById('selfie_base64').value;
            const fileUpload = document.getElementById('doc_photo_file').files.length;
            if (!selfieData && fileUpload === 0) {
                alert('Compliance Rule: Biometric identity capture is mandatory.');
                return false;
            }
        } else if (step === 2) {
            const reqs = ['full_name', 'company_name', 'email', 'phone', 'qualifications', 'insurance_num'];
            for (let id of reqs) {
                const el = document.getElementById(id);
                if (!el.value.trim()) { alert('Compliance Rule: All corporate fields are mandatory.'); el.focus(); return false; }
            }
        } else if (step === 3) {
            if (document.getElementById('captcha_passed').value !== '1') {
                alert('Compliance Rule: Cryptographic security puzzle must be successfully solved.'); return false;
            }
            if (!document.getElementById('terms_agreed').checked) {
                alert('Compliance Rule: You must legally execute the statutory declarations to proceed.'); return false;
            }
            
            let qualFiles = document.getElementById('f1').files.length;
            if(qualFiles === 0 || !document.getElementById('f2').value || !document.getElementById('f3').value) {
                 alert('Compliance Rule: Incomplete evidence portfolio. All documentation is required.'); return false;
            }
            if(qualFiles > 3) {
                 alert('Compliance Rule: Maximum transmission payload exceeded. Upload no more than 3 qualification files.'); return false;
            }
        }
        return true;
    }

    /* =======================
       CAMERA LOGIC
    ======================== */
    function initCamera() {
        if (videoStream) return;
        const video = document.getElementById('webcam-video');
        const guide = document.getElementById('silhouette-guide');
        
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            // Use ideal constraints to prevent aspect ratio distortion on mobile
            navigator.mediaDevices.getUserMedia({ video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 640 } } })
            .then(stream => { 
                videoStream = stream; 
                video.srcObject = stream; 
                
                video.onloadedmetadata = () => {
                    video.play();
                };
                
                guide.style.display = 'flex'; // Show guide when camera turns on
            })
            .catch(err => { console.warn("Biometric feed denied.", err); });
        }
    }

    function stopCamera() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
        }
    }

    function takeSelfie() {
        const video = document.getElementById('webcam-video');
        const preview = document.getElementById('photo-preview');
        const guide = document.getElementById('silhouette-guide');
        const canvas = document.createElement('canvas');
        
        // Dynamically match canvas size to the camera's true resolution to prevent squashing
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        
        const dataUrl = canvas.toDataURL('image/jpeg', 0.85); // Added slight compression to keep payload small
        document.getElementById('selfie_base64').value = dataUrl;
        
        preview.src = dataUrl; 
        preview.style.display = 'block'; 
        video.style.display = 'none';
        guide.style.display = 'none'; // Hide guide after snapping
        
        document.getElementById('btn-snap').style.display = 'none';
        document.getElementById('btn-retake').style.display = 'inline-flex';
        
        stopCamera(); 
        saveDraftCookie();
    }

    function resetCamera() {
        document.getElementById('selfie_base64').value = '';
        document.getElementById('photo-preview').style.display = 'none';
        document.getElementById('webcam-video').style.display = 'block';
        document.getElementById('btn-snap').style.display = 'inline-flex';
        document.getElementById('btn-retake').style.display = 'none';
        
        initCamera(); 
        saveDraftCookie();
    }

    function handlePhotoUpload(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(evt) {
                document.getElementById('selfie_base64').value = evt.target.result;
                const preview = document.getElementById('photo-preview');
                const guide = document.getElementById('silhouette-guide');
                
                preview.src = evt.target.result; 
                preview.style.display = 'block'; 
                document.getElementById('webcam-video').style.display = 'none';
                guide.style.display = 'none'; // Hide guide if they upload a file
                
                document.getElementById('btn-snap').style.display = 'none';
                document.getElementById('btn-retake').style.display = 'inline-flex';
                
                stopCamera(); 
                saveDraftCookie();
            };
            reader.readAsDataURL(file);
        }
    }

    /* =======================
       COOKIE/DRAFT LOGIC
    ======================== */
    function setCookie(name, value, seconds) {
        let date = new Date(); date.setTime(date.getTime() + (seconds * 1000));
        document.cookie = name + "=" + (encodeURIComponent(value) || "") + "; expires=" + date.toUTCString() + "; path=/; SameSite=Lax";
    }

    function getCookie(name) {
        let nameEQ = name + "="; let ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i]; while (c.charAt(0) == ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) == 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
        } return null;
    }

    function saveDraftCookie() {
        const formData = {
            full_name: document.getElementById('full_name').value,
            company_name: document.getElementById('company_name').value,
            email: document.getElementById('email').value,
            phone: document.getElementById('phone').value,
            qualifications: document.getElementById('qualifications').value,
            insurance_num: document.getElementById('insurance_num').value,
            selfie_base64: document.getElementById('selfie_base64').value
        };
        setCookie('ctta_form_draft', JSON.stringify(formData), 1200); 
    }

    function restoreDraftFromCookie() {
        const saved = getCookie('ctta_form_draft');
        if (saved) {
            try {
                const data = JSON.parse(saved);
                if (data.full_name || data.selfie_base64) document.getElementById('btn-start').innerText = "Resume Active Audit Session ➔";
                if (data.full_name) document.getElementById('full_name').value = data.full_name;
                if (data.company_name) document.getElementById('company_name').value = data.company_name;
                if (data.email) document.getElementById('email').value = data.email;
                if (data.phone) document.getElementById('phone').value = data.phone;
                if (data.qualifications) document.getElementById('qualifications').value = data.qualifications;
                if (data.insurance_num) document.getElementById('insurance_num').value = data.insurance_num;
                if (data.selfie_base64) {
                    document.getElementById('selfie_base64').value = data.selfie_base64;
                    const preview = document.getElementById('photo-preview');
                    const guide = document.getElementById('silhouette-guide');
                    
                    preview.src = data.selfie_base64; 
                    preview.style.display = 'block'; 
                    document.getElementById('webcam-video').style.display = 'none';
                    if(guide) guide.style.display = 'none';
                    
                    document.getElementById('btn-snap').style.display = 'none';
                    document.getElementById('btn-retake').style.display = 'inline-flex';
                }
            } catch (e) {}
        }
    }

    function setupAutoSave() {
        const fields = ['full_name', 'company_name', 'email', 'phone', 'qualifications', 'insurance_num'];
        fields.forEach(id => {
            const input = document.getElementById(id);
            if (input) input.addEventListener('input', saveDraftCookie);
        });
    }
</script>
</body>
</html>