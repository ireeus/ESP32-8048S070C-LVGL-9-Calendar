<?php
session_start();
$config_file = __DIR__ . '/../config.json';
$config = json_decode(file_get_contents($config_file), true);

if (isset($_POST['login'])) {
    if ($_POST['username'] === $config['admin_user'] && $_POST['password'] === $config['admin_pass']) {
        $_SESSION['logged_in'] = true;
    } else {
        $error = "Invalid credentials!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

if (isset($_POST['update_config']) && isset($_SESSION['logged_in'])) {
    $config['environment'] = $_POST['environment'];
    $config['paypal_sandbox_client_id'] = $_POST['paypal_sandbox_client_id'];
    $config['paypal_sandbox_secret'] = $_POST['paypal_sandbox_secret'];
    $config['paypal_production_client_id'] = $_POST['paypal_production_client_id'];
    $config['paypal_production_secret'] = $_POST['paypal_production_secret'];
    
    $config['price_studio'] = (int)$_POST['price_studio'];
    $config['price_2bed'] = (int)$_POST['price_2bed'];
    $config['price_3bed'] = (int)$_POST['price_3bed'];
    $config['price_4bed'] = (int)$_POST['price_4bed'];
    $config['price_hmo'] = (int)$_POST['price_hmo'];
    $config['price_extra_circuit'] = (int)$_POST['price_extra_circuit'];
    
    $config['price_pat_base'] = (int)$_POST['price_pat_base'];
    $config['pat_base_items'] = (int)$_POST['pat_base_items'];
    $config['price_pat_extra'] = (int)$_POST['price_pat_extra'];
    
    $config['discount_tier1_pct'] = (int)$_POST['discount_tier1_pct'];
    $config['discount_tier1_mins'] = (int)$_POST['discount_tier1_mins'];
    $config['discount_tier2_pct'] = (int)$_POST['discount_tier2_pct'];
    $config['discount_tier2_mins'] = (int)$_POST['discount_tier2_mins'];

    file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    $success = "Settings updated successfully.";
}

if (!isset($_SESSION['logged_in'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>CronTech Admin Login</title><style>body{font-family:'Segoe UI',sans-serif;background:#f4f7f9;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;} .box{background:#fff; border: 1px solid #e5e7eb; padding:40px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.08); width:90%; max-width:320px;} h2 {color:#1f2937; margin-top:0; text-align:center;} input{width:100%;padding:14px;margin-bottom:15px;box-sizing:border-box; border:1px solid #d1d5db; background:#fff; color:#1f2937; border-radius:8px;} button{width:100%;padding:14px;background:#ffb703;color:#000;border:none;cursor:pointer; border-radius:8px; font-weight:bold; text-transform:uppercase;} .err{color:#ef4444; background:#fef2f2; padding:10px; border-radius:6px; font-size:14px; text-align:center;}</style></head>
<body>
    <div class="box">
        <h2>Admin Login</h2>
        <?php if(isset($error)) echo "<p class='err'>$error</p>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="login">Login</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

$bookings_file = __DIR__ . '/../bookings.json';
$bookings = file_exists($bookings_file) ? json_decode(file_get_contents($bookings_file), true) : [];
if(is_array($bookings)) {
    usort($bookings, function($a, $b) { return strtotime($b['created_at']) < strtotime($a['created_at']) ? 1 : -1; });
} else { $bookings = []; }

function getTabCategory($status) {
    if ($status === 'Pending') return 'requested';
    if (strpos($status, 'Accepted') !== false) return 'in_progress';
    if ($status === 'Awaiting Balance') return 'pending_payment';
    if ($status === 'Paid') return 'completed';
    if (strpos($status, 'Rejected') !== false) return 'rejected';
    return 'all';
}

$counts = [
    'requested' => 0,
    'in_progress' => 0,
    'pending_payment' => 0,
    'completed' => 0,
    'rejected' => 0,
    'all' => count($bookings)
];

foreach ($bookings as $b) {
    $cat = getTabCategory($b['status']);
    if (isset($counts[$cat])) {
        $counts[$cat]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CronTech Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f9; margin: 0; padding: 20px; color:#1f2937;}
        .container { max-width: 1400px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; margin-bottom: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);}
        .header h1 { margin: 0; color: #1f2937; font-size: 22px; text-transform: uppercase; }
        .header h1 span { color: #ffb703; }
        .btn { padding: 10px 15px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; font-size:13px; font-weight:700;}
        .btn-logout { background: transparent; border: 1px solid #d1d5db; color: #4b5563; }
        .btn-logout:hover { color: #111827; background: #f3f4f6; }
        .btn-accept { background: #3b82f6; color: white; margin-bottom: 5px; width: 100%;}
        .btn-complete { background: #10b981; color: white; margin-bottom: 5px; width: 100%; box-shadow: 0 2px 4px rgba(16,185,129,0.3);}
        .btn-reject { background: #ef4444; color: white; width: 100%;}
        .btn-invoice { background: #ffb703; color: #000; text-decoration: none; display: inline-block; padding: 6px 12px; border-radius: 4px; font-weight: 700; font-size: 11px; margin-top: 5px; text-transform: uppercase; }
        
        .toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 15px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .tab-btn { background: #fff; border: 1px solid #e5e7eb; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; color: #4b5563; transition: all 0.2s; }
        .tab-btn:hover { background: #f3f4f6; color: #111827; }
        .tab-btn.active { background: #1f2937; color: #fff; border-color: #1f2937; }
        
        .tab-ctta-link { background: #1f2937; color: #fff; border: 1px solid #111827; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 13px; transition: all 0.2s; margin-left: 10px;}
        .tab-ctta-link:hover { background: #111827; }

        .search-box { position: relative; min-width: 280px; }
        .search-box input { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 600; outline: none; box-sizing: border-box; }
        .search-box input:focus { border-color: #ffb703; box-shadow: 0 0 0 3px rgba(255, 183, 3, 0.2); }

        .table-wrapper { overflow-x: auto; background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);}
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { background: #f9fafb; font-weight: 600; color: #4b5563; text-transform: uppercase; font-size: 13px;}
        tr:hover { background: #f9fafb; }
        
        tr.unread { font-weight: 800 !important; background-color: #fffcf0; }
        tr.unread td { color: #000000; }

        .status-Pending { color: #fb8500; font-weight: bold; }
        .status-Accepted { color: #2563eb; font-weight: bold; }
        .status-Awaiting { color: #d97706; font-weight: bold; }
        .status-Paid { color: #10b981; font-weight: bold; }
        .status-Rejected { color: #ef4444; font-weight: bold; }
        
        .edit-box { background:#f9fafb; padding:10px; border-radius:6px; margin-bottom:8px; border:1px solid #e5e7eb; }
        .edit-box label { font-size:11px; color:#4b5563; font-weight:700; display:block; margin-bottom:4px; margin-top:6px; }
        .edit-box input { width:100%; padding:6px 8px; border:1px solid #d1d5db; border-radius:4px; font-weight:bold; color:#1f2937; box-sizing:border-box; }
        .edit-box input[readonly] { background:#e5e7eb; cursor:not-allowed; }
        .extra-items-container { margin-top: 15px; border-top: 1px dashed #cbd5e1; padding-top: 10px; }
        .extra-item-row { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
        .extra-item-row input[type="text"] { flex: 2; }
        .extra-item-row input[type="number"] { flex: 1; }
        .extra-item-row button { background: #ef4444; color: white; border: none; border-radius: 4px; width: 30px; height: 30px; cursor: pointer; font-weight: bold; }
        .add-extra-btn { background: #3b82f6; color: white; border: none; padding: 8px; border-radius: 4px; cursor: pointer; font-weight: 700; width: 100%; margin-top: 5px; }
        
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media(max-width: 768px){ .settings-grid { grid-template-columns: 1fr; } }
        .settings-panel { background: #fff; border: 1px solid #e5e7eb; padding: 25px; border-radius: 12px; margin-top: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);}
        .settings-panel h2 { color: #1f2937; margin-top: 0; border-bottom: 2px solid #f4f7f9; padding-bottom: 10px;}
        .settings-panel label { font-size: 13px; font-weight: 600; color: #4b5563; display:block; margin-top:15px;}
        .settings-panel input, .settings-panel select { padding: 10px; margin: 5px 0 0 0; width: 100%; display:block; border:1px solid #d1d5db; background: #fff; color: #1f2937; border-radius:6px;}
        .settings-panel button { background: #ffb703; color: #000; padding: 15px 30px; font-weight: bold; text-transform: uppercase; margin-top: 25px; width: 100%; border:none; border-radius:8px; cursor:pointer;}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><a href="admin.php" style="text-decoration:none; color:inherit;">Cron<span>Tech</span> Dashboard</a></h1>
            <a href="?logout=1" class="btn btn-logout">Logout</a>
        </div>

        <div class="toolbar">
            <div class="tabs">
                <button class="tab-btn active" onclick="filterTab('requested', this)">Requested (<?php echo $counts['requested']; ?>)</button>
                <button class="tab-btn" onclick="filterTab('in_progress', this)">In Progress (<?php echo $counts['in_progress']; ?>)</button>
                <button class="tab-btn" onclick="filterTab('pending_payment', this)">Pending Payment (<?php echo $counts['pending_payment']; ?>)</button>
                <button class="tab-btn" onclick="filterTab('completed', this)">Completed (<?php echo $counts['completed']; ?>)</button>
                <button class="tab-btn" onclick="filterTab('rejected', this)">Rejected (<?php echo $counts['rejected']; ?>)</button>
                <button class="tab-btn" onclick="filterTab('all', this)">All (<?php echo $counts['all']; ?>)</button>
                <!-- Separate page link for CTTA Management -->
                <a href="ctta_management.php" class="tab-ctta-link">⚙️ CTTA Management</a>
            </div>
            <div class="search-box" id="main-search-box">
                <input type="text" id="search-input" onkeyup="filterSearch()" placeholder="🔍 Search name, address, email...">
            </div>
        </div>

        <!-- MAIN BOOKINGS VIEW -->
        <div id="view-bookings">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date & ID</th>
                            <th>Client Details</th>
                            <th>Invoice</th>
                            <th>Finances</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="booking-rows">
                        <?php foreach($bookings as $b): 
                            $tab_cat = getTabCategory($b['status']);
                            $is_unread = !isset($b['seen']) || $b['seen'] === false;
                            $search_text = strtolower(htmlspecialchars(($b['id'] ?? '') . ' ' . ($b['name'] ?? '') . ' ' . ($b['email'] ?? '') . ' ' . ($b['phone'] ?? '') . ' ' . ($b['address'] ?? '')));
                            
                            $total = (float)$b['total'];
                            $deposit = (float)$b['deposit'];
                            $extra_items = $b['extra_items'] ?? [];
                            $computed_final_balance = $total + array_sum(array_column($extra_items, 'amount')) - $deposit;
                            $final_balance = $computed_final_balance;
                        ?>
                        <tr class="booking-row <?php echo $is_unread ? 'unread' : ''; ?>" data-id="<?php echo $b['id']; ?>" data-tab="<?php echo $tab_cat; ?>" data-search="<?php echo $search_text; ?>">
                            <td>
                                <strong style="color:#1f2937;"><?php echo $b['date']; ?></strong><br>
                                <small style="color:#6b7280;"><?php echo $b['id']; ?></small>
                            </td>
                            <td>
                                <strong style="color:#1f2937;"><?php echo htmlspecialchars($b['name']); ?></strong><br>
                                <a href="mailto:<?php echo htmlspecialchars($b['email']); ?>" style="color:#fb8500; text-decoration:none; font-size:13px; font-weight:600;"><?php echo htmlspecialchars($b['email']); ?></a><br>
                                <small style="color:#6b7280;"><?php echo htmlspecialchars($b['phone']); ?></small>
                            </td>
                            <td>
                                <a href="invoice.php?id=<?php echo $b['id']; ?>" target="_blank" class="btn-invoice">📄 View PDF Invoice</a>
                            </td>
                            <td>
                                <span style="color:#10b981;font-weight:700;font-size:13px;">Deposit Paid: £<?php echo number_format($deposit, 2); ?></span><br>
                                <span style="color:#1f2937;font-weight:700;font-size:13px;">Total Quoted: £<?php echo number_format($total, 2); ?></span><br>
                                <span style="color:#d97706;font-weight:700;font-size:13px;">Remaining: £<?php echo number_format($final_balance, 2); ?></span><br>
                            </td>
                            <td class="status-<?php echo explode(' ', $b['status'])[0]; ?>"><?php echo $b['status']; ?></td>
                            <td>
                                <?php if($b['status'] === 'Pending'): ?>
                                    <button class="btn btn-accept" onclick="processBooking('<?php echo $b['id']; ?>', 'accept')">Accept Booking</button>
                                    <button class="btn btn-reject" onclick="processBooking('<?php echo $b['id']; ?>', 'reject')">Reject/Refund</button>
                                
                                <?php elseif(strpos($b['status'], 'Accepted') !== false || $b['status'] === 'Awaiting Balance'): ?>
                                    <div class="edit-box">
                                        <label>FINAL BALANCE DUE (£)</label>
                                        <input type="number" id="balance_<?php echo $b['id']; ?>" value="<?php echo number_format($final_balance, 2, '.', ''); ?>" step="0.01" data-base="<?php echo $total; ?>" data-deposit="<?php echo $deposit; ?>" readonly>
                                        
                                        <div class="extra-items-container" id="extra-items-container-<?php echo $b['id']; ?>" data-existing-items='<?php echo json_encode($extra_items); ?>'>
                                            <label style="margin-top:10px; font-size:13px; color:#1f2937;">Extra Items (Additional Work)</label>
                                            <div id="extra-items-list-<?php echo $b['id']; ?>"></div>
                                            <button type="button" class="add-extra-btn" onclick="addExtraItem('<?php echo $b['id']; ?>')">+ Add Extra Item</button>
                                        </div>
                                    </div>
                                    <button class="btn btn-complete" onclick="processBooking('<?php echo $b['id']; ?>', 'complete')">
                                        <?php echo ($b['status'] === 'Awaiting Balance') ? 'Update & Resend Link' : 'Complete & Unlock Invoice'; ?>
                                    </button>
                                    <button class="btn btn-reject" style="margin-top:5px;" onclick="processBooking('<?php echo $b['id']; ?>', 'reject')">Reject/Refund</button>
                                
                                <?php elseif($b['status'] === 'Paid'): ?> 
                                    <span style="color:#10b981; font-size:13px; font-weight:800;">✓ Transaction Complete</span><br>
                                    <small style="color:#6b7280; font-size:10px;">ID: <?php echo $b['final_transaction_id'] ?? 'Manual/Zero'; ?></small>
                                <?php else: ?>
                                    <span style="color:#9ca3af; font-size:13px; font-weight:600;">Closed</span>
                                    <?php if(strpos($b['status'], 'Refund Reqd') !== false): ?>
                                        <button class="btn btn-complete" style="margin-top:8px; font-size:11px;" onclick="processBooking('<?php echo $b['id']; ?>', 'manual_refund')">Mark Refunded</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($bookings)): ?>
                        <tr id="no-data-row"><td colspan="6" style="text-align:center; padding: 30px; color: #6b7280;">No bookings found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST">
                <?php if(isset($success)) echo "<div style='color:#10b981; margin:20px 0 0 0; font-weight:bold; text-align:center; font-size:18px;'>$success</div>"; ?>
                <div class="settings-grid">
                    <div class="settings-panel">
                        <h2>Pricing & Discount Config</h2>
                        <h3 style="font-size:15px; margin-bottom:0; color:#ffb703;">EICR Base Prices (£)</h3>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div><label>Studio/1-Bed (5 circ.)</label><input type="number" name="price_studio" value="<?php echo $config['price_studio'] ?? 100; ?>"></div>
                            <div><label>2-Bed Flat (6 circ.)</label><input type="number" name="price_2bed" value="<?php echo $config['price_2bed'] ?? 130; ?>"></div>
                            <div><label>3-Bed House (8 circ.)</label><input type="number" name="price_3bed" value="<?php echo $config['price_3bed'] ?? 160; ?>"></div>
                            <div><label>4-Bed House (10 circ.)</label><input type="number" name="price_4bed" value="<?php echo $config['price_4bed'] ?? 195; ?>"></div>
                            <div><label>HMO (12 circ.)</label><input type="number" name="price_hmo" value="<?php echo $config['price_hmo'] ?? 240; ?>"></div>
                            <div><label>Extra Circuit (£)</label><input type="number" name="price_extra_circuit" value="<?php echo $config['price_extra_circuit'] ?? 15; ?>"></div>
                        </div>

                        <h3 style="font-size:15px; margin-top:20px; margin-bottom:0; color:#059669;">PAT Testing Prices (£)</h3>
                        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px;">
                            <div><label>Base Price</label><input type="number" name="price_pat_base" value="<?php echo $config['price_pat_base'] ?? 50; ?>"></div>
                            <div><label>Included Items</label><input type="number" name="pat_base_items" value="<?php echo $config['pat_base_items'] ?? 15; ?>"></div>
                            <div><label>Extra Item (£)</label><input type="number" name="price_pat_extra" value="<?php echo $config['price_pat_extra'] ?? 2; ?>"></div>
                        </div>

                        <h3 style="font-size:15px; margin-top:20px; margin-bottom:0; color:#ffb703;">Urgency Discount Settings</h3>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div><label>Tier 1 Discount (%)</label><input type="number" name="discount_tier1_pct" value="<?php echo $config['discount_tier1_pct'] ?? 10; ?>"></div>
                            <div><label>Tier 1 Mins</label><input type="number" name="discount_tier1_mins" value="<?php echo $config['discount_tier1_mins'] ?? 60; ?>"></div>
                            <div><label>Tier 2 Discount (%)</label><input type="number" name="discount_tier2_pct" value="<?php echo $config['discount_tier2_pct'] ?? 5; ?>"></div>
                            <div><label>Tier 2 Mins</label><input type="number" name="discount_tier2_mins" value="<?php echo $config['discount_tier2_mins'] ?? 60; ?>"></div>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h2>PayPal & Environment</h2>
                        <label>System Environment</label>
                        <select name="environment">
                            <option value="sandbox" <?php echo $config['environment'] == 'sandbox' ? 'selected' : ''; ?>>Sandbox (Testing Mode)</option>
                            <option value="production" <?php echo $config['environment'] == 'production' ? 'selected' : ''; ?>>Production (Live Payments)</option>
                        </select>

                        <h3 style="margin-top:20px; font-size:15px; color:#1f2937;">Sandbox Credentials</h3>
                        <label>Client ID</label>
                        <input type="text" name="paypal_sandbox_client_id" value="<?php echo htmlspecialchars($config['paypal_sandbox_client_id'] ?? ''); ?>">
                        <label>Secret Key (for Refunds)</label>
                        <input type="password" name="paypal_sandbox_secret" value="<?php echo htmlspecialchars($config['paypal_sandbox_secret'] ?? ''); ?>">

                        <h3 style="margin-top:20px; font-size:15px; color:#1f2937;">Production Credentials</h3>
                        <label>Client ID</label>
                        <input type="text" name="paypal_production_client_id" value="<?php echo htmlspecialchars($config['paypal_production_client_id'] ?? ''); ?>">
                        <label>Secret Key (for Refunds)</label>
                        <input type="password" name="paypal_production_secret" value="<?php echo htmlspecialchars($config['paypal_production_secret'] ?? ''); ?>">
                        
                        <button type="submit" name="update_config">Save All Settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    let activeTab = 'requested';

    document.addEventListener("DOMContentLoaded", () => {
        applyFilters();
        initializeExtraItems();
    });

    function filterTab(category, btn) {
        activeTab = category;
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyFilters();
    }

    function filterSearch() {
        applyFilters();
    }

    function applyFilters() {
        let query = document.getElementById('search-input').value.toLowerCase().trim();
        let rows = document.querySelectorAll('.booking-row');
        
        rows.forEach(row => {
            let tabMatch = (activeTab === 'all' || row.getAttribute('data-tab') === activeTab);
            let searchMatch = (query === '' || row.getAttribute('data-search').includes(query));
            
            if (tabMatch && searchMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function initializeExtraItems() {
        document.querySelectorAll('.extra-items-container').forEach(container => {
            const bookingId = container.id.replace('extra-items-container-', '');
            const listDiv = document.getElementById('extra-items-list-' + bookingId);
            if (!listDiv) return;
            
            const dataAttr = container.getAttribute('data-existing-items');
            if (dataAttr) {
                const items = JSON.parse(dataAttr);
                items.forEach(item => {
                    addExtraItemRow(bookingId, item.description, item.amount);
                });
            }
        });
    }

    function addExtraItem(bookingId) {
        addExtraItemRow(bookingId, '', 0);
    }

    function addExtraItemRow(bookingId, description, amount) {
        const listDiv = document.getElementById('extra-items-list-' + bookingId);
        if (!listDiv) return;
        
        const row = document.createElement('div');
        row.className = 'extra-item-row';
        row.innerHTML = `
            <input type="text" placeholder="Description" value="${description}">
            <input type="number" placeholder="£" step="0.01" value="${amount}" oninput="recalculateBalance('${bookingId}')">
            <button type="button" onclick="removeExtraItemRow(this, '${bookingId}')">×</button>
        `;
        listDiv.appendChild(row);
        recalculateBalance(bookingId);
    }

    function removeExtraItemRow(btn, bookingId) {
        const row = btn.parentElement;
        row.remove();
        recalculateBalance(bookingId);
    }

    function recalculateBalance(bookingId) {
        const balanceInput = document.getElementById('balance_' + bookingId);
        if (!balanceInput) return;
        
        const baseTotal = parseFloat(balanceInput.getAttribute('data-base'));
        const deposit = parseFloat(balanceInput.getAttribute('data-deposit'));
        
        const listDiv = document.getElementById('extra-items-list-' + bookingId);
        let extraSum = 0;
        if (listDiv) {
            listDiv.querySelectorAll('.extra-item-row').forEach(row => {
                const amountInput = row.querySelector('input[type="number"]');
                if (amountInput) {
                    const val = parseFloat(amountInput.value);
                    if (!isNaN(val)) extraSum += val;
                }
            });
        }
        
        const newBalance = baseTotal + extraSum - deposit;
        balanceInput.value = newBalance.toFixed(2);
    }

    function processBooking(id, action) {
        let payload = {id: id};
        let msg = '';
        
        if(action === 'accept') {
            msg = 'Accept this booking and notify client? (Payment will remain locked until inspection completion)';
        } else if(action === 'complete') {
            let bal = document.getElementById('balance_' + id).value;
            
            const listDiv = document.getElementById('extra-items-list-' + id);
            let extra_items = [];
            if (listDiv) {
                listDiv.querySelectorAll('.extra-item-row').forEach(row => {
                    const descInput = row.querySelector('input[type="text"]');
                    const amountInput = row.querySelector('input[type="number"]');
                    if (descInput.value.trim() !== '' && !isNaN(parseFloat(amountInput.value))) {
                        extra_items.push({
                            description: descInput.value.trim(),
                            amount: parseFloat(amountInput.value)
                        });
                    }
                });
            }
            
            payload.final_balance = parseFloat(bal);
            payload.extra_items = extra_items;
            
            msg = 'Mark inspection completed, add extra items, and unlock payment link for £' + bal + '?';
        } else if(action === 'manual_refund') {
            msg = 'Mark this booking as manually refunded and clear the warning?';
        } else {
            msg = 'Reject this booking and automatically refund the deposit?';
        }
        
        if(!confirm(msg)) return;
        
        fetch('api_eicr.php?action=' + action, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => { alert(data.message); if(data.success) location.reload(); })
        .catch(err => alert('Network error.'));
    }
    </script>
</body>
</html>