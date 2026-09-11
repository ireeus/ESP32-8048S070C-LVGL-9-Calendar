<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// Updated paths to secure location
$bookings_file = __DIR__ . '/../bookings.json';
$config_file = __DIR__ . '/../config.json';

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

    // ---------- Calculate breakdown based on current config ----------
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
    $discount = max(0, $original_total - ($input['total'] ?? 0)); // total is after discount
    $price_breakdown = [
        'eicr_cost' => $eicr_cost,
        'pat_cost' => $pat_cost,
        'discount' => $discount,
        'original_total' => $original_total
    ];

    // ---------- End breakdown calculation ----------
    
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
        'price_breakdown' => $price_breakdown, // stored breakdown
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
    
    // 1. Send email to the client
    sendHtmlEmail($new_booking['email'], "EICR & PAT Booking Request - Pending", "Booking Received", $content);
    
    // 2. Send email to the Admin
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
        if(isset($b['id']) && $b['id'] === $input['id']) {
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

session_start();
if (!isset($_SESSION['logged_in'])) { 
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); 
    exit; 
}

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
            
            // Update descriptions if provided
            if(isset($input['eicr_desc'])) $b['eicr_desc'] = $input['eicr_desc'];
            if(isset($input['pat_desc'])) $b['pat_desc'] = $input['pat_desc'];
            
            // Update extra items if provided
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
// CTTA REGISTER APIs
// ---------------------------------------------------------
$members_file = __DIR__ . '/ctta_members.json';

function getCttaMembers() {
    global $members_file;
    if (!file_exists($members_file)) return [];
    $data = file_get_contents($members_file);
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function saveCttaMembers($members) {
    global $members_file;
    file_put_contents($members_file, json_encode($members, JSON_PRETTY_PRINT));
}

if ($action === 'verify_member') {
    $query = strtolower(trim($_GET['query'] ?? ''));
    if (empty($query)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a search term.']);
        exit;
    }

    $members = getCttaMembers();
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
    $members = getCttaMembers();
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
    saveCttaMembers($members);
    echo json_encode(['success' => true, 'message' => 'Member added successfully!']);
    exit;
}

if ($action === 'delete_member' && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $members = getCttaMembers();
    $id_to_delete = $input['id'] ?? '';
    
    $members = array_filter($members, function($m) use ($id_to_delete) {
        return $m['id'] !== $id_to_delete;
    });
    
    saveCttaMembers(array_values($members)); 
    echo json_encode(['success' => true, 'message' => 'Member removed.']);
    exit;
}
