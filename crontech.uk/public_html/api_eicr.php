<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

$bookings_file = __DIR__ . '/../bookings.json';
$config_file   = __DIR__ . '/../config.json';
$members_file  = __DIR__ . '/../ctta_members.json';
$upload_dir    = __DIR__ . '/../uploads_ctta/';

function getConfig() { 
    global $config_file;
    if (!file_exists($config_file)) return [];
    $data = file_get_contents($config_file);
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function getBookings() {
    global $bookings_file;
    if (!file_exists($bookings_file)) return [];
    $data = file_get_contents($bookings_file);
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function sendHtmlEmail($to, $subject, $headline, $content) {
    $html = "
    <html>
    <body style='font-family: Arial, sans-serif; background-color: #f4f7f9; margin: 0; padding: 20px; color: #1f2937;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;'>
            <div style='background: #ffffff; padding: 25px; text-align: center; border-bottom: 4px solid #ffb703;'>
                <h1 style='color: #1f2937; margin: 0; font-size: 26px; text-transform: uppercase; font-weight: 800;'>Cron<span style='color: #ffb703;'>Tech</span></h1>
            </div>
            <div style='padding: 30px; line-height: 1.6;'>
                <h2 style='margin-top: 0; font-size: 20px;'>{$headline}</h2>
                {$content}
            </div>
        </div>
    </body>
    </html>";
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: CronTech Bookings <no-reply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    @mail($to, $subject, $html, $headers);
}

// Masking helper function for suspended/expired accounts
function maskText($str) {
    if (empty($str)) return '***';
    $words = explode(' ', trim($str));
    $masked = [];
    foreach ($words as $w) {
        $len = mb_strlen($w);
        if ($len <= 1) {
            $masked[] = '*';
        } else {
            $masked[] = mb_substr($w, 0, 1) . str_repeat('*', max(1, $len - 1));
        }
    }
    return implode(' ', $masked);
}

// ---------------------------------------------------------
// PUBLIC ENDPOINTS (No Login Required)
// ---------------------------------------------------------

if ($action === 'get_config') {
    $c = getConfig();
    echo json_encode([
        'prices' => [
            'studio' => $c['price_studio'] ?? 100,
            '2bed' => $c['price_2bed'] ?? 130,
            '3bed' => $c['price_3bed'] ?? 160,
            '4bed' => $c['price_4bed'] ?? 195,
            'hmo' => $c['price_hmo'] ?? 240,
            'extra' => $c['price_extra_circuit'] ?? 15,
            'pat_base' => $c['price_pat_base'] ?? 50,
            'pat_base_items' => $c['pat_base_items'] ?? 15,
            'pat_extra' => $c['price_pat_extra'] ?? 2
        ],
        'discount' => [
            't1_pct' => $c['discount_tier1_pct'] ?? 10,
            't1_mins' => $c['discount_tier1_mins'] ?? 60,
            't2_pct' => $c['discount_tier2_pct'] ?? 5,
            't2_mins' => $c['discount_tier2_mins'] ?? 60
        ],
        'client_id' => (isset($c['environment']) && $c['environment'] === 'sandbox') 
                       ? (!empty($c['paypal_sandbox_client_id']) ? $c['paypal_sandbox_client_id'] : 'sb') 
                       : (!empty($c['paypal_production_client_id']) ? $c['paypal_production_client_id'] : 'sb')
    ]);
    exit;
}

if ($action === 'get_dates') {
    $bookings = getBookings();
    $booked_dates = [];
    foreach($bookings as $b) {
        if (isset($b['status']) && strpos($b['status'], 'Rejected') === false && isset($b['date'])) { 
            $booked_dates[] = $b['date'];
        }
    }
    echo json_encode($booked_dates);
    exit;
}

if ($action === 'get_my_bookings') {
    $requests = $input['bookings'] ?? [];
    $bookings = getBookings();
    $results = [];
    
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $dir = dirname($_SERVER['REQUEST_URI']);
    $dir = ($dir === '\\' || $dir === '/') ? '' : $dir; 
    $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $dir;

    foreach ($requests as $req) {
        foreach ($bookings as $b) {
            if (isset($b['id']) && $b['id'] === $req['id'] && isset($b['secret']) && $b['secret'] === $req['secret']) {
                $service_str = '';
                if (isset($b['service_type'])) {
                    if ($b['service_type'] === 'pat') $service_str = "PAT Only";
                    elseif ($b['service_type'] === 'eicr') $service_str = "EICR Only";
                    elseif ($b['service_type'] === 'both') $service_str = "EICR + PAT";
                } else {
                    $service_str = ucfirst($b['prop_type'] ?? '') . " (" . ($b['circuits'] ?? 0) . " circ)" . (!empty($b['pat_included']) ? " + PAT" : "");
                }
                
                $invoice_link = $base_url . "/invoice.php?id=" . $b['id'] . "&secret=" . $b['secret'];
                
                $results[] = [
                    'id' => $b['id'],
                    'date' => $b['date'] ?? '',
                    'address' => $b['address'] ?? '',
                    'prop_type' => $service_str,
                    'total' => number_format($b['total'] ?? 0, 2),
                    'status' => $b['status'] ?? 'Pending',
                    'invoice_link' => $invoice_link
                ];
                break;
            }
        }
    }
    echo json_encode(['success' => true, 'data' => $results]);
    exit;
}

if ($action === 'create') {
    global $bookings_file;
    $bookings = getBookings();
    $secret = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : md5(uniqid(rand(), true));
    
    $service_type = $input['service_type'] ?? 'both';
    $pat_included = ($service_type === 'pat' || $service_type === 'both') ? true : false;
    $pat_items = $input['pat_items'] ?? 0;
    
    if ($service_type === 'pat') {
        $prop_type = ''; 
        $circuits = 0;
    } else {
        $prop_type = $input['prop_type'] ?? '';
        $circuits = $input['circuits'] ?? 0;
    }
    
    $eicr_desc = 'N/A';
    if ($service_type === 'eicr' || $service_type === 'both') {
        $eicr_desc = 'EICR Inspection (' . ucfirst($prop_type) . ' - ' . $circuits . ' Circuits)';
    }
    
    $pat_desc = 'N/A';
    if ($pat_included) {
        $pat_desc = 'PAT Testing (' . $pat_items . ' Appliances)';
    }

    $config = getConfig();
    $eicr_cost = 0;
    $pat_cost = 0;

    if ($service_type === 'eicr' || $service_type === 'both') {
        if (!empty($prop_type)) {
            $price_map = [
                'studio' => $config['price_studio'] ?? 100,
                '2bed'   => $config['price_2bed'] ?? 130,
                '3bed'   => $config['price_3bed'] ?? 160,
                '4bed'   => $config['price_4bed'] ?? 195,
                'hmo'    => $config['price_hmo'] ?? 240,
            ];
            $base_circuits_map = [
                'studio' => 5,
                '2bed'   => 6,
                '3bed'   => 8,
                '4bed'   => 10,
                'hmo'    => 12,
            ];
            $base = $price_map[$prop_type] ?? 0;
            $inc = (int)$circuits;
            $base_circ = $base_circuits_map[$prop_type] ?? 0;
            $extra_circ = max(0, $inc - $base_circ) * ($config['price_extra_circuit'] ?? 15);
            $eicr_cost = $base + $extra_circ;
        }
    }

    if ($pat_included) {
        $pat_base = $config['price_pat_base'] ?? 50;
        $pat_base_items = $config['pat_base_items'] ?? 15;
        $pat_extra = $config['price_pat_extra'] ?? 2;
        $pat_cost = $pat_base + max(0, (int)$pat_items - $pat_base_items) * $pat_extra;
    }

    $original_total = $eicr_cost + $pat_cost;
    $discount = max(0, $original_total - ($input['total'] ?? 0));
    $price_breakdown = [
        'eicr_cost' => $eicr_cost,
        'pat_cost' => $pat_cost,
        'discount' => $discount,
        'original_total' => $original_total
    ];
    
    $new_booking = [
        'id' => uniqid('CRON_'), 
        'secret' => $secret,
        'service_type' => $service_type,
        'date' => $input['date'] ?? '', 
        'name' => $input['name'] ?? '', 
        'email' => $input['email'] ?? '', 
        'phone' => $input['phone'] ?? '', 
        'address' => $input['address'] ?? '', 
        'prop_type' => $prop_type, 
        'circuits' => $circuits, 
        'eicr_desc' => $eicr_desc,
        'pat_included' => $pat_included,
        'pat_items' => $pat_items,
        'pat_desc' => $pat_desc,
        'total' => $input['total'] ?? 0, 
        'deposit' => $input['deposit'] ?? 0, 
        'final_balance' => ($input['total'] ?? 0) - ($input['deposit'] ?? 0),
        'transaction_id' => $input['transaction_id'] ?? '', 
        'capture_id' => $input['capture_id'] ?? '', 
        'status' => 'Pending', 
        'extra_items' => [],
        'price_breakdown' => $price_breakdown, 
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    array_unshift($bookings, $new_booking);
    file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
    
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $dir = dirname($_SERVER['REQUEST_URI']);
    $dir = ($dir === '\\' || $dir === '/') ? '' : $dir; 
    $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $dir;
    
    $invoice_link = $base_url . "/invoice.php?id=" . $new_booking['id'] . "&secret=" . $secret;
    
    $service_desc = '';
    if ($service_type === 'eicr') $service_desc = "EICR Inspection";
    elseif ($service_type === 'pat') $service_desc = "PAT Testing";
    else $service_desc = "EICR Inspection and PAT Testing";
    
    $content = "<p>Hello <strong>{$new_booking['name']}</strong>,</p>
    <p>Thank you for initiating your booking with CronTech. We have successfully received your 10% deposit (£{$new_booking['deposit']}).</p>
    <div style='background:#f9fafb; border: 1px solid #e5e7eb; padding:20px; border-radius:8px; margin:20px 0;'>
        <strong>Requested Service:</strong> {$service_desc}<br>
        <strong>Requested Date:</strong> {$new_booking['date']}<br>
        <strong>Property Address:</strong> {$new_booking['address']}<br>
        <strong>Estimated Total:</strong> £{$new_booking['total']}
    </div>
    <p><a href='{$invoice_link}' style='color:#fb8500; font-weight:bold;'>Click here to view/download your PDF Invoice Deposit Receipt</a></p>
    <p>Our team will review your request and send a confirmation shortly.</p>";
    
    sendHtmlEmail($new_booking['email'], "EICR & PAT Booking Request - Pending", "Booking Received", $content);
    
    $admin_email = "ireeus@gmail.com";
    $admin_subject = "New Booking Alert: " . $new_booking['id'];
    $admin_content = "<p>A new booking deposit has been paid successfully.</p>
    <div style='background:#fef3c7; border: 1px solid #fde68a; padding:20px; border-radius:8px; margin:20px 0;'>
        <strong>Client:</strong> {$new_booking['name']}<br>
        <strong>Email:</strong> {$new_booking['email']}<br>
        <strong>Phone:</strong> {$new_booking['phone']}<br>
        <strong>Requested Date:</strong> {$new_booking['date']}<br>
        <strong>Address:</strong> {$new_booking['address']}<br>
        <strong>Service:</strong> {$service_desc}<br>
        <strong>Total Quote:</strong> £{$new_booking['total']} (Deposit: £{$new_booking['deposit']} paid)
    </div>
    <p>Log in to your admin dashboard to review, accept, or reject this booking.</p>";
    
    sendHtmlEmail($admin_email, $admin_subject, "New Booking Received", $admin_content);
    
    echo json_encode(['success' => true, 'id' => $new_booking['id'], 'secret' => $secret]);
    exit;
}

if ($action === 'pay_balance') {
    global $bookings_file;
    $bookings = getBookings();
    
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $dir = dirname($_SERVER['REQUEST_URI']);
    $dir = ($dir === '\\' || $dir === '/') ? '' : $dir; 
    $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $dir;

    foreach($bookings as &$b) {
        if(isset($b['id']) && $b['id'] === $input['id'] && isset($b['secret']) && $b['secret'] === $input['secret']) {
            $b['status'] = 'Paid';
            $b['final_transaction_id'] = $input['transaction_id'] ?? '';
            
            $invoice_link = $base_url . "/invoice.php?id=" . $b['id'] . "&secret=" . $b['secret'];
            
            $content = "<p>Hello <strong>{$b['name']}</strong>,</p><p>We have successfully received your final payment of £" . number_format($b['final_balance'], 2) . ".</p><p><a href='{$invoice_link}' style='color:#fb8500; font-weight:bold;'>Click here to view/download your Full Paid Invoice (PDF)</a></p><p>Your certificates will be released and sent to you shortly.</p>";
            sendHtmlEmail($b['email'], "Payment Received - Thank You", "Payment Successful", $content);
            
            file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false]);
    exit;
}

// ---------------------------------------------------------
// PUBLIC CTTA REGISTER API
// ---------------------------------------------------------
function getCttaMembers() {
    global $members_file;
    if (!file_exists($members_file)) return [];
    $data = file_get_contents($members_file);
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function getBase64Image($filepath) {
    if (!empty($filepath) && file_exists($filepath)) {
        $type = pathinfo($filepath, PATHINFO_EXTENSION);
        $data = file_get_contents($filepath);
        return 'data:image/' . $type . ';base64,' . base64_encode($data);
    }
    return '';
}

if ($action === 'verify_member') {
    $raw_query = $_GET['query'] ?? '';
    
    // Strip everything except letters and numbers for the ID search
    $numeric_alpha_query = preg_replace('/[^a-z0-9]/i', '', strtolower(trim($raw_query)));
    
    // Keep spaces for the Name search
    $name_query = preg_replace('/[^a-z0-9\s]/i', '', strtolower(trim($raw_query)));
    $query_words = array_filter(explode(' ', $name_query));

    if (empty($numeric_alpha_query)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid search term.']);
        exit;
    }

    $members = getCttaMembers();
    $results = [];

    foreach ($members as $m) {
        $stored_id_clean = preg_replace('/[^a-z0-9]/i', '', strtolower($m['id'] ?? ''));
        $stored_name_clean = preg_replace('/[^a-z0-9\s]/i', '', strtolower($m['name'] ?? ''));

        // Prevent User Typos: Treat letter 'o' / 'O' as zero '0'
        $id_q_norm = str_replace('o', '0', $numeric_alpha_query);
        $id_s_norm = str_replace('o', '0', $stored_id_clean);

        // Does the query string perfectly match the ID, or exist inside it?
        $id_match = ($id_s_norm === $id_q_norm || (!empty($id_q_norm) && strpos($id_s_norm, $id_q_norm) !== false));

        // Name match logic
        $name_match = false;
        if (!empty($query_words)) {
            $name_match = true;
            foreach ($query_words as $word) {
                if (strpos($stored_name_clean, $word) === false) {
                    $name_match = false;
                    break;
                }
            }
        }
        
        if ($id_match || $name_match) {
            $is_expired = (strtotime($m['insurance_expiry']) < time()) || (strtotime($m['calibration_expiry']) < time());
            $is_suspended = ($m['status'] !== 'active');
            
            $is_valid = (!$is_suspended && !$is_expired);
            
            $display_status = 'ACTIVE & VERIFIED';
            if ($is_suspended) {
                $display_status = 'SUSPENDED';
            } elseif ($is_expired) {
                $display_status = 'EXPIRED';
            }
            
            // IF NOT VALID (SUSPENDED / EXPIRED): MASK PERSONAL DETAILS WITH ASTERISKS (*) AND REDACT PHOTO
            $out_name = $is_valid ? $m['name'] : maskText($m['name']);
            $out_company = $is_valid ? $m['company'] : maskText($m['company']);
            $out_qualifications = $is_valid ? $m['qualifications'] : '*** REDACTED / SUSPENDED ***';
            $photo_base64 = $is_valid ? getBase64Image($m['photo'] ?? '') : ''; // Empty photo forces masked avatar
            
            $results[] = [
                'id' => $m['id'],
                'name' => $out_name,
                'company' => $out_company,
                'qualifications' => $out_qualifications,
                'status' => $display_status,
                'is_valid' => $is_valid, 
                'photo' => $photo_base64,
                'valid_until' => min($m['insurance_expiry'], $m['calibration_expiry'])
            ];
        }
    }
    
    // Prioritize perfect ID matches at the very top of the list
    usort($results, function($a, $b) use ($numeric_alpha_query) {
        $a_id = str_replace('o', '0', preg_replace('/[^a-z0-9]/i', '', strtolower($a['id'])));
        $b_id = str_replace('o', '0', preg_replace('/[^a-z0-9]/i', '', strtolower($b['id'])));
        $q_id = str_replace('o', '0', $numeric_alpha_query);
        
        $a_match = ($a_id === $q_id);
        $b_match = ($b_id === $q_id);
        if ($a_match && !$b_match) return -1;
        if (!$a_match && $b_match) return 1;
        return strcmp($a['name'], $b['name']);
    });

    echo json_encode(['success' => true, 'data' => $results]);
    exit;
}


// =========================================================
// ADMIN AUTHENTICATION BARRIER 
// =========================================================
session_start();
if (!isset($_SESSION['logged_in'])) { 
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); 
    exit; 
}


// ---------------------------------------------------------
// ADMIN BOOKING ACTIONS
// ---------------------------------------------------------

if ($action === 'accept') {
    global $bookings_file;
    $bookings = getBookings();
    $found = false;
    
    foreach($bookings as &$b) {
        if(isset($b['id']) && $b['id'] === $input['id']) {
            $found = true;
            $b['status'] = 'Accepted (In Progress)';
            
            $content = "<p>Hello <strong>{$b['name']}</strong>,</p>
            <p>Great news! Your inspection on <strong>{$b['date']}</strong> has been officially confirmed by CronTech.</p>
            <p>Our engineer will arrive at: <br><em>{$b['address']}</em></p>
            <p><strong>Note on Final Payment:</strong> Once testing is fully completed on-site, the final balance will be updated and posted to your account page for payment.</p>";
            
            sendHtmlEmail($b['email'], "CronTech Inspection Confirmed", "Inspection Confirmed", $content);
            break;
        }
    }
    
    if($found) {
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'Booking accepted. Client notified (Payment locked).']);
    }
    exit;
}

if ($action === 'complete') {
    global $bookings_file;
    $bookings = getBookings();
    $found = false;
    
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $dir = dirname($_SERVER['REQUEST_URI']);
    $dir = ($dir === '\\' || $dir === '/') ? '' : $dir; 
    $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $dir;

    foreach($bookings as &$b) {
        if(isset($b['id']) && $b['id'] === $input['id']) {
            $found = true;
            $b['final_balance'] = (float)($input['final_balance'] ?? 0);
            
            if(isset($input['eicr_desc'])) $b['eicr_desc'] = $input['eicr_desc'];
            if(isset($input['pat_desc'])) $b['pat_desc'] = $input['pat_desc'];
            
            if(isset($input['extra_items']) && is_array($input['extra_items'])) {
                $b['extra_items'] = $input['extra_items'];
            }

            $invoice_link = $base_url . "/invoice.php?id=" . $b['id'] . "&secret=" . $b['secret'];

            if ($b['final_balance'] > 0) {
                $b['status'] = 'Awaiting Balance';
                $payment_link = $base_url . "/pay.php?id=" . $b['id'] . "&secret=" . $b['secret'];
                
                $content = "<p>Hello <strong>{$b['name']}</strong>,</p>
                <p>Your inspection for <strong>{$b['address']}</strong> has been completed.</p>
                <p>Based on the final testing, your remaining balance is <strong>£" . number_format($b['final_balance'], 2) . "</strong>.</p>
                <p>Please click the button below to view the updated invoice and complete your final payment:</p>
                <p style='text-align:center; margin: 25px 0;'><a href='{$payment_link}' style='background-color: #ffb703; color: #000000; padding: 14px 28px; text-decoration: none; font-weight: bold; border-radius: 6px; display: inline-block;'>View & Pay Final Balance (£" . number_format($b['final_balance'], 2) . ")</a></p>
                <p><a href='{$invoice_link}' style='color:#fb8500; font-weight:bold;'>Download PDF Itemized Invoice</a></p>";
                
                sendHtmlEmail($b['email'], "CronTech Invoice - Payment Unlocked", "Inspection Complete - Payment Due", $content);
            } else {
                $b['status'] = 'Paid'; 
                $content = "<p>Hello <strong>{$b['name']}</strong>,</p>
                <p>Your inspection on <strong>{$b['date']}</strong> is complete and your balance is fully settled.</p>
                <p><a href='{$invoice_link}' style='color:#fb8500; font-weight:bold;'>Download PDF Paid Invoice</a></p>";
                sendHtmlEmail($b['email'], "CronTech Inspection Completed", "Inspection Completed", $content);
            }
            break;
        }
    }
    
    if($found) {
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'Inspection completed and payment link unlocked.']);
    }
    exit;
}

if ($action === 'manual_refund') {
    global $bookings_file;
    $bookings = getBookings(); 
    $found = false; 
    
    foreach($bookings as &$b) {
        if(isset($b['id']) && $b['id'] === $input['id']) {
            $found = true;
            $b['status'] = 'Rejected (Refunded)';
            break;
        }
    }
    
    if($found) {
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'Status updated to manually refunded.']);
    }
    exit;
}

if ($action === 'reject') {
    global $bookings_file;
    $bookings = getBookings(); 
    $config = getConfig();
    $found = false; 
    $refund_success = false;
    
    foreach($bookings as &$b) {
        if(isset($b['id']) && $b['id'] === $input['id']) {
            $found = true;
            $capture_id = $b['capture_id'] ?? '';
            $is_sandbox = (isset($config['environment']) && $config['environment'] === 'sandbox');
            $client_id = $is_sandbox ? ($config['paypal_sandbox_client_id'] ?? '') : ($config['paypal_production_client_id'] ?? '');
            $secret_api = $is_sandbox ? ($config['paypal_sandbox_secret'] ?? '') : ($config['paypal_production_secret'] ?? '');
            $base_url = $is_sandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
            
            if (!empty($client_id) && !empty($secret_api) && !empty($capture_id)) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $base_url . "/v1/oauth2/token");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
                curl_setopt($ch, CURLOPT_USERPWD, $client_id . ":" . $secret_api);
                $token_result = curl_exec($ch);
                
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                    $token_json = json_decode($token_result, true);
                    $ch2 = curl_init();
                    curl_setopt($ch2, CURLOPT_URL, $base_url . "/v2/payments/captures/" . $capture_id . "/refund");
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1); 
                    curl_setopt($ch2, CURLOPT_POST, 1);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $token_json['access_token']]);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, "{}");
                    $refund_result = curl_exec($ch2);
                    $refund_res_json = json_decode($refund_result, true);
                    if (curl_getinfo($ch2, CURLINFO_HTTP_CODE) == 201 || (isset($refund_res_json['status']) && $refund_res_json['status'] == 'COMPLETED')) {
                        $refund_success = true;
                    }
                    curl_close($ch2);
                }
                curl_close($ch);
            }
            
            $b['status'] = 'Rejected (' . ($refund_success ? 'Refunded' : 'Refund Reqd') . ')';
            $content = "<p>Hello <strong>{$b['name']}</strong>,</p><p>Unfortunately, we are unable to fulfill your requested booking date on <strong>{$b['date']}</strong>.</p>";
            if ($refund_success) { 
                $content .= "<p style='color:#ef4444; font-weight:bold;'>We have processed a full refund of your £{$b['deposit']} deposit back to your original payment method.</p>"; 
            } else { 
                $content .= "<p>Your £{$b['deposit']} deposit is currently being processed manually by our team for a full refund.</p>"; 
            }
            sendHtmlEmail($b['email'], "Important Update: Your CronTech Booking", "Booking Update", $content);
            break;
        }
    }
    
    if($found) {
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'Booking Rejected. ' . ($refund_success ? 'Refund successful.' : 'Refund failed/manual.')]);
    }
    exit;
}

// ---------------------------------------------------------
// ADMIN CTTA REGISTER ACTIONS
// ---------------------------------------------------------

function saveCttaMembers($members) {
    global $members_file;
    file_put_contents($members_file, json_encode($members, JSON_PRETTY_PRINT));
}

if ($action === 'add_member' && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $members = getCttaMembers();
    $num = count($members) + 1;
    $new_id = "CTTA-" . str_pad($num, 4, "0", STR_PAD_LEFT);
    
    $photo_path = '';
    
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $filename = $new_id . '_' . time() . '.' . $ext;
            $full_target = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $full_target)) {
                $photo_path = $full_target;
            }
        }
    }
    
    $new_member = [
        'id' => $new_id,
        'name' => $_POST['name'] ?? '',
        'company' => $_POST['company'] ?? '',
        'qualifications' => $_POST['qualifications'] ?? '',
        'insurance_expiry' => $_POST['insurance_expiry'] ?? '',
        'calibration_expiry' => $_POST['calibration_expiry'] ?? '',
        'status' => $_POST['status'] ?? 'active',
        'photo' => $photo_path,
        'joined_date' => date('Y-m-d')
    ];
    
    array_unshift($members, $new_member);
    saveCttaMembers($members);
    echo json_encode(['success' => true, 'message' => 'Member added successfully!']);
    exit;
}

if ($action === 'edit_member' && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $members = getCttaMembers();
    $id_to_edit = $_POST['id'] ?? '';
    $found = false;

    foreach ($members as &$m) {
        if ($m['id'] === $id_to_edit) {
            $found = true;
            $m['name'] = $_POST['name'] ?? $m['name'];
            $m['company'] = $_POST['company'] ?? $m['company'];
            $m['qualifications'] = $_POST['qualifications'] ?? $m['qualifications'];
            $m['insurance_expiry'] = $_POST['insurance_expiry'] ?? $m['insurance_expiry'];
            $m['calibration_expiry'] = $_POST['calibration_expiry'] ?? $m['calibration_expiry'];
            $m['status'] = $_POST['status'] ?? $m['status'];

            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                if (!empty($m['photo']) && file_exists($m['photo'])) {
                    @unlink($m['photo']);
                }

                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $filename = $m['id'] . '_' . time() . '.' . $ext;
                    $full_target = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $full_target)) {
                        $m['photo'] = $full_target;
                    }
                }
            }
            break;
        }
    }

    if ($found) {
        saveCttaMembers($members);
        echo json_encode(['success' => true, 'message' => 'Member updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
    }
    exit;
}

if ($action === 'delete_member' && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $members = getCttaMembers();
    $id_to_delete = $input['id'] ?? '';
    
    foreach ($members as $m) {
        if ($m['id'] === $id_to_delete && !empty($m['photo']) && file_exists($m['photo'])) {
            @unlink($m['photo']);
        }
    }
    
    $members = array_filter($members, function($m) use ($id_to_delete) {
        return $m['id'] !== $id_to_delete;
    });
    
    saveCttaMembers(array_values($members)); 
    echo json_encode(['success' => true, 'message' => 'Member removed.']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'approve_application') {
    $data = json_decode(file_get_contents('php://input'), true);
    $app_id = $data['id'] ?? '';
    
    $apps_file = __DIR__ . '/../ctta_applications.json';
    $members_file = __DIR__ . '/../ctta_members.json';
    
    $applications = file_exists($apps_file) ? json_decode(file_get_contents($apps_file), true) : [];
    $members = file_exists($members_file) ? json_decode(file_get_contents($members_file), true) : [];
    
    $app_index = array_search($app_id, array_column($applications, 'id'));
    
    if ($app_index !== false) {
        $app = $applications[$app_index];
        
        $photo_path = isset($app['docs']['doc_photo']) ? $app['docs']['doc_photo'] : '';
        $docs_array = isset($app['docs']) ? $app['docs'] : [];
        
        $num = count($members) + 1;
        $new_ctta_id = 'CTTA-' . str_pad($num, 4, "0", STR_PAD_LEFT);
        
        $members[] = [
            'id' => $new_ctta_id,
            'name' => $app['name'],
            'company' => $app['company'],
            'qualifications' => $app['qualifications'],
            'insurance_expiry' => date('Y-m-d', strtotime('+1 year')),
            'calibration_expiry' => date('Y-m-d', strtotime('+1 year')),
            'status' => 'active',
            'photo' => $photo_path,
            'docs' => $docs_array
        ];
        
        array_splice($applications, $app_index, 1);
        
        file_put_contents($members_file, json_encode($members, JSON_PRETTY_PRINT));
        file_put_contents($apps_file, json_encode($applications, JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true, 'message' => "Application approved. Member registered as $new_ctta_id"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Application not found.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'reject_application') {
    $data = json_decode(file_get_contents('php://input'), true);
    $app_id = $data['id'] ?? '';
    
    $apps_file = __DIR__ . '/../ctta_applications.json';
    $applications = file_exists($apps_file) ? json_decode(file_get_contents($apps_file), true) : [];
    
    $app_index = array_search($app_id, array_column($applications, 'id'));
    
    if ($app_index !== false) {
        $app = $applications[$app_index];
        $refund_success = false;

        $capture_id = $app['capture_id'] ?? '';
        if (!empty($capture_id)) {
            $config_file = __DIR__ . '/../config.json';
            $config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
            
            $is_sandbox = (isset($config['environment']) && $config['environment'] === 'sandbox');
            $client_id = $is_sandbox ? ($config['paypal_sandbox_client_id'] ?? '') : ($config['paypal_production_client_id'] ?? '');
            $secret_api = $is_sandbox ? ($config['paypal_sandbox_secret'] ?? '') : ($config['paypal_production_secret'] ?? '');
            $base_url = $is_sandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
            
            if (!empty($client_id) && !empty($secret_api)) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $base_url . "/v1/oauth2/token");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
                curl_setopt($ch, CURLOPT_USERPWD, $client_id . ":" . $secret_api);
                $token_result = curl_exec($ch);
                
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                    $token_json = json_decode($token_result, true);
                    
                    $ch2 = curl_init();
                    curl_setopt($ch2, CURLOPT_URL, $base_url . "/v2/payments/captures/" . $capture_id . "/refund");
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1); 
                    curl_setopt($ch2, CURLOPT_POST, 1);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $token_json['access_token']]);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, "{}");
                    
                    $refund_result = curl_exec($ch2);
                    $refund_res_json = json_decode($refund_result, true);
                    
                    if (curl_getinfo($ch2, CURLINFO_HTTP_CODE) == 201 || (isset($refund_res_json['status']) && $refund_res_json['status'] == 'COMPLETED')) {
                        $refund_success = true;
                    }
                    curl_close($ch2);
                }
                curl_close($ch);
            }
        }
        
        if ($refund_success) {
            $applications[$app_index]['status'] = 'Rejected (Refunded)';
        } else {
            $applications[$app_index]['status'] = 'Rejected (Refund Failed)';
        }
        
        file_put_contents($apps_file, json_encode($applications, JSON_PRETTY_PRINT));
        
        $msg = 'Application rejected. ';
        $msg .= $refund_success ? 'The £50 fee was successfully refunded via PayPal.' : 'Automatic PayPal refund failed. Manual refund required.';
        
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Application not found.']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'delete_application') {
    $data = json_decode(file_get_contents('php://input'), true);
    $app_id = $data['id'] ?? '';
    
    $apps_file = __DIR__ . '/../ctta_applications.json';
    $applications = file_exists($apps_file) ? json_decode(file_get_contents($apps_file), true) : [];
    
    $app_index = array_search($app_id, array_column($applications, 'id'));
    
    if ($app_index !== false) {
        $app = $applications[$app_index];
        
        foreach ($app['docs'] as $doc_path) {
            if (file_exists($doc_path)) {
                unlink($doc_path);
            }
        }
        
        array_splice($applications, $app_index, 1);
        file_put_contents($apps_file, json_encode($applications, JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true, 'message' => 'Application and all associated documents permanently deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Application not found.']);
    }
    exit;
}