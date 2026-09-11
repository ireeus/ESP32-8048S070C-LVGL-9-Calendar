<?php
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
$bookings_file = 'bookings.json';

function getConfig() { return json_decode(file_get_contents('config.json'), true); }

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
        'client_id' => ($c['environment'] === 'sandbox') ? ($c['paypal_sandbox_client_id'] ?: 'sb') : ($c['paypal_production_client_id'] ?: 'sb')
    ]);
    exit;
}

if ($action === 'get_dates') {
    $bookings = json_decode(file_get_contents($bookings_file), true) ?: [];
    $booked_dates = [];
    foreach($bookings as $b) {
        if (strpos($b['status'], 'Rejected') === false) { 
            $booked_dates[] = $b['date'];
        }
    }
    echo json_encode($booked_dates);
    exit;
}

if ($action === 'get_my_bookings') {
    $requests = $input['bookings'] ?? [];
    $bookings = json_decode(file_get_contents($bookings_file), true) ?: [];
    $results = [];
    foreach ($requests as $req) {
        foreach ($bookings as $b) {
            if ($b['id'] === $req['id'] && isset($b['secret']) && $b['secret'] === $req['secret']) {
                $pat_str = (!empty($b['pat_included']) && $b['pat_included'] == true) ? " + PAT" : "";
                $results[] = [
                    'id' => $b['id'],
                    'date' => $b['date'],
                    'address' => $b['address'],
                    'prop_type' => ucfirst($b['prop_type']) . " ({$b['circuits']} circ)" . $pat_str,
                    'total' => number_format($b['total'], 2),
                    'status' => $b['status']
                ];
                break;
            }
        }
    }
    echo json_encode(['success' => true, 'data' => $results]);
    exit;
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

if ($action === 'create') {
    $bookings = json_decode(file_get_contents($bookings_file), true) ?: [];
    $secret = bin2hex(random_bytes(16));
    
    $new_booking = [
        'id' => uniqid('CRON_'), 
        'secret' => $secret,
        'date' => $input['date'], 'name' => $input['name'], 'email' => $input['email'], 
        'phone' => $input['phone'], 'address' => $input['address'], 'prop_type' => $input['prop_type'], 
        'circuits' => $input['circuits'], 
        'pat_included' => $input['pat_included'] ?? false,
        'pat_items' => $input['pat_items'] ?? 0,
        'total' => $input['total'], 'deposit' => $input['deposit'], 
        'transaction_id' => $input['transaction_id'], 'capture_id' => $input['capture_id'], 
        'status' => 'Pending', 'created_at' => date('Y-m-d H:i:s')
    ];
    array_unshift($bookings, $new_booking);
    file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
    
    $pat_text = (!empty($input['pat_included']) && $input['pat_included']) ? "<br><strong>PAT Testing:</strong> Yes ({$input['pat_items']} appliances)" : "";

    $content = "<p>Hello <strong>{$input['name']}</strong>,</p><p>Thank you for initiating your booking with CronTech. We have successfully received your 10% deposit (£{$input['deposit']}).</p><div style='background:#f9fafb; border: 1px solid #e5e7eb; padding:20px; border-radius:8px; margin:20px 0;'><strong>Requested Date:</strong> {$input['date']}<br><strong>Property Address:</strong> {$input['address']}{$pat_text}<br><strong>Total Price (inc. discounts):</strong> £{$input['total']}</div><p>Our team will review your request and send a final confirmation shortly.</p>";
    sendHtmlEmail($input['email'], "EICR & PAT Booking Request - Pending", "Booking Received", $content);
    
    echo json_encode(['success' => true, 'id' => $new_booking['id'], 'secret' => $secret]);
    exit;
}

if ($action === 'pay_balance') {
    $bookings = json_decode(file_get_contents($bookings_file), true);
    foreach($bookings as &$b) {
        if($b['id'] === $input['id'] && $b['secret'] === $input['secret']) {
            $b['status'] = 'Paid in Full';
            $b['final_transaction_id'] = $input['transaction_id'];
            
            $content = "<p>Hello <strong>{$b['name']}</strong>,</p><p>We have successfully received your final payment of £" . number_format($b['final_balance'], 2) . ".</p><p>Your certificates will be released and sent to you shortly.</p>";
            sendHtmlEmail($b['email'], "Payment Received - Thank You", "Payment Successful", $content);
            
            file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false]);
    exit;
}

session_start();
if (!isset($_SESSION['logged_in'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

if ($action === 'accept') {
    $bookings = json_decode(file_get_contents($bookings_file), true);
    $found = false;
    foreach($bookings as &$b) {
        if($b['id'] === $input['id']) {
            $found = true;
            $b['final_balance'] = (float)$input['final_balance'];
            
            if ($b['final_balance'] > 0) {
                $b['status'] = 'Awaiting Balance';
                
                // Determine base URL dynamically
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
                $dir = dirname($_SERVER['REQUEST_URI']);
                $dir = ($dir === '\' || $dir === '/') ? '' : $dir; // Fix for root level
                $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $dir;
                
                $payment_link = $base_url . "/pay.php?id=" . $b['id'] . "&secret=" . $b['secret'];
                
                $content = "<p>Hello <strong>{$b['name']}</strong>,</p>
                <p>Your inspection on <strong>{$b['date']}</strong> has been processed by CronTech.</p>
                <p>Based on the final testing requirements at your property, your remaining balance to pay is <strong>£" . number_format($b['final_balance'], 2) . "</strong>.</p>
                <p>Please click the secure link below to complete your payment and release your certificates:</p>
                <p style='text-align:center; margin: 30px 0;'><a href='{$payment_link}' style='background-color: #ffb703; color: #000000; padding: 14px 28px; text-decoration: none; font-weight: bold; border-radius: 6px; display: inline-block;'>Pay Final Balance (£" . number_format($b['final_balance'], 2) . ")</a></p>
                <p>If you have any questions, simply reply to this email.</p>";
                
                sendHtmlEmail($b['email'], "CronTech Invoice - Payment Required", "Invoice / Final Balance", $content);
            } else {
                $b['status'] = 'Paid in Full'; // Balance was 0 or less
                $content = "<p>Hello <strong>{$b['name']}</strong>,</p>
                <p>Your inspection on <strong>{$b['date']}</strong> has been processed by CronTech.</p>
                <p>Your balance is fully settled. Your certificates will be sent to you shortly.</p>";
                sendHtmlEmail($b['email'], "CronTech Inspection Completed", "Inspection Completed", $content);
            }
            break;
        }
    }
    if($found) {
        file_put_contents($bookings_file, json_encode($bookings, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'Booking updated and client notified.']);
    }
    exit;
}

if ($action === 'reject') {
    $bookings = json_decode(file_get_contents($bookings_file), true);
    $config = getConfig();
    $found = false; $refund_success = false;
    foreach($bookings as &$b) {
        if($b['id'] === $input['id']) {
            $found = true;
            $capture_id = $b['capture_id'];
            $is_sandbox = ($config['environment'] === 'sandbox');
            $client_id = $is_sandbox ? $config['paypal_sandbox_client_id'] : $config['paypal_production_client_id'];
            $secret_api = $is_sandbox ? $config['paypal_sandbox_secret'] : $config['paypal_production_secret'];
            $base_url = $is_sandbox ? "https://api-m.sandbox.paypal.com" : "https://api-m.paypal.com";
            if (!empty($client_id) && !empty($secret_api) && !empty($capture_id)) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $base_url . "/v1/oauth2/token");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
                curl_setopt($ch, CURLOPT_USERPWD, $client_id . ":" . $secret_api);
                $token_result = curl_exec($ch);
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                    $token_json = json_decode($token_result, true);
                    $ch2 = curl_init();
                    curl_setopt($ch2, CURLOPT_URL, $base_url . "/v2/payments/captures/" . $capture_id . "/refund");
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1); curl_setopt($ch2, CURLOPT_POST, 1);
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
            if ($refund_success) { $content .= "<p style='color:#ef4444; font-weight:bold;'>We have processed a full refund of your £{$b['deposit']} deposit back to your original payment method. It may take 3-5 working days to appear.</p>"; } 
            else { $content .= "<p>Your £{$b['deposit']} deposit is currently being processed manually by our team for a full refund.</p>"; }
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
// CTTA REGISTER APIs
// ---------------------------------------------------------
$members_file = 'ctta_members.json';

function getMembers() {
    global $members_file;
    if (!file_exists($members_file)) return [];
    $data = file_get_contents($members_file);
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function saveMembers($members) {
    global $members_file;
    file_put_contents($members_file, json_encode($members, JSON_PRETTY_PRINT));
}

if ($action === 'verify_member') {
    $query = strtolower(trim($_GET['query'] ?? ''));
    if (empty($query)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a search term.']);
        exit;
    }

    $members = getMembers();
    $results = [];

    foreach ($members as $m) {
        $id_match = strtolower($m['id']) === $query;
        $name_match = strpos(strtolower($m['name']), $query) !== false;
        
        if ($id_match || $name_match) {
            $is_expired = (strtotime($m['insurance_expiry']) < time()) || (strtotime($m['calibration_expiry']) < time());
            $display_status = ($m['status'] === 'active' && !$is_expired) ? 'ACTIVE & VERIFIED' : 'EXPIRED / INACTIVE';
            
            $results[] = [
                'id' => $m['id'],
                'name' => $m['name'],
                'company' => $m['company'],
                'qualifications' => $m['qualifications'],
                'status' => $display_status,
                'valid_until' => min($m['insurance_expiry'], $m['calibration_expiry'])
            ];
        }
    }
    echo json_encode(['success' => true, 'data' => $results]);
    exit;
}

if ($action === 'add_member' && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $members = getMembers();
    $num = count($members) + 1;
    $new_id = "CTTA-" . str_pad($num, 3, "0", STR_PAD_LEFT);
    
    $new_member = [
        'id' => $new_id,
        'name' => $input['name'] ?? '',
        'company' => $input['company'] ?? '',
        'qualifications' => $input['qualifications'] ?? '',
        'insurance_expiry' => $input['insurance_expiry'] ?? '',
        'calibration_expiry' => $input['calibration_expiry'] ?? '',
        'status' => $input['status'] ?? 'active',
        'joined_date' => date('Y-m-d')
    ];
    
    array_unshift($members, $new_member);
    saveMembers($members);
    echo json_encode(['success' => true, 'message' => 'Member added successfully!']);
    exit;
}

if ($action === 'delete_member' && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $members = getMembers();
    $id_to_delete = $input['id'] ?? '';
    
    $members = array_filter($members, function($m) use ($id_to_delete) {
        return $m['id'] !== $id_to_delete;
    });
    
    saveMembers(array_values($members)); 
    echo json_encode(['success' => true, 'message' => 'Member removed.']);
    exit;
}
