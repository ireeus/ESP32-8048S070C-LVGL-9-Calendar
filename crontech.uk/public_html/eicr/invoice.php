<?php
session_start();

$id = $_GET['id'] ?? '';
$secret = $_GET['secret'] ?? '';

$bookings_file = __DIR__ . '/bookings.json';
$bookings = file_exists($bookings_file) ? json_decode(file_get_contents($bookings_file), true) : [];
$booking = null;

if (is_array($bookings)) {
    foreach ($bookings as $b) {
        if (isset($b['id']) && $b['id'] === $id) {
            $booking = $b;
            break;
        }
    }
}

if (!$booking) {
    die("Invoice not found.");
}

// Security Check: Authorized if Admin Session OR Valid Cryptographic Secret Token
$is_admin = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$is_valid_token = isset($booking['secret']) && $booking['secret'] === $secret && !empty($secret);

if (!$is_admin && !$is_valid_token) {
    http_response_code(403);
    die("Unauthorized access to invoice.");
}

// Data Prep
$deposit = (float)($booking['deposit'] ?? 0);
$items = $booking['invoice_items'] ?? [];

// Fallback for older bookings before dynamic items existed
if(empty($items)) {
    $desc = 'EICR Inspection (' . ucfirst($booking['prop_type'] ?? '') . ' - ' . ($booking['circuits'] ?? 0) . ' Circuits)';
    if (!empty($booking['pat_included'])) {
        $desc .= ' + PAT Testing (' . ($booking['pat_items'] ?? 0) . ' Appliances)';
    }
    $items[] = ['desc' => $desc, 'price' => (float)($booking['total'] ?? 0)];
}

$invoice_total = isset($booking['invoice_total']) ? (float)$booking['invoice_total'] : (float)($booking['total'] ?? 0);
$final_balance = isset($booking['final_balance']) ? (float)$booking['final_balance'] : ($invoice_total - $deposit);
$grand_total = $deposit + $final_balance;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo htmlspecialchars($booking['id']); ?> - CronTech</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
        body { background: #f4f7f9; margin: 0; padding: 30px 15px; color: #1f2937; }
        .invoice-box {
            max-width: 800px; margin: 0 auto; background: #ffffff; padding: 40px;
            border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }
        .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #ffb703; padding-bottom: 20px; margin-bottom: 30px; }
        .brand { font-size: 28px; font-weight: 800; text-transform: uppercase; color: #111827; }
        .brand span { color: #ffb703; }
        .invoice-title { text-align: right; }
        .invoice-title h2 { margin: 0; font-size: 24px; font-weight: 800; color: #1f2937; text-transform: uppercase; }
        .invoice-title p { margin: 5px 0 0 0; color: #6b7280; font-size: 13px; font-weight: 600; }
        
        .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px; }
        .details-block h4 { margin: 0 0 8px 0; font-size: 12px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; }
        .details-block p { margin: 0; font-size: 14px; font-weight: 600; color: #1f2937; line-height: 1.5; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th { background: #f9fafb; border-bottom: 2px solid #e5e7eb; padding: 12px 15px; text-align: left; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #4b5563; }
        td { padding: 15px; border-bottom: 1px solid #e5e7eb; font-size: 14px; font-weight: 500; }
        
        .totals-table { width: 350px; margin-left: auto; }
        .totals-table td { padding: 8px 15px; border: none; }
        .totals-table tr.grand-total td { font-size: 18px; font-weight: 800; border-top: 2px solid #111827; color: #111827; }
        
        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-weight: 800; font-size: 12px; text-transform: uppercase; }
        .status-Paid { background: #d1fae5; color: #065f46; }
        .status-Awaiting { background: #fef3c7; color: #b45309; }
        .status-Pending { background: #ffedd5; color: #9a3412; }

        .print-actions { max-width: 800px; margin: 20px auto 0 auto; display: flex; justify-content: space-between; }
        .btn-print { background: #ffb703; color: #000; border: none; padding: 12px 24px; font-weight: 800; border-radius: 8px; cursor: pointer; text-transform: uppercase; font-size: 13px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn-print:hover { background: #fb8500; }

        @media print {
            body { background: #ffffff; padding: 0; }
            .invoice-box { border: none; box-shadow: none; padding: 0; }
            .print-actions { display: none; }
        }
    </style>
</head>
<body>

<div class="print-actions">
    <button class="btn-print" onclick="window.print()">🖨️ Download PDF / Print Invoice</button>
</div>

<div class="invoice-box">
    <div class="invoice-header">
        <div class="brand">Cron<span>Tech</span></div>
        <div class="invoice-title">
            <h2>INVOICE</h2>
            <p>Ref: #<?php echo htmlspecialchars($booking['id']); ?></p>
            <p>Date: <?php echo date('d/m/Y', strtotime($booking['created_at'] ?? 'now')); ?></p>
        </div>
    </div>

    <div class="details-grid">
        <div class="details-block">
            <h4>Billed To:</h4>
            <p><?php echo htmlspecialchars($booking['name'] ?? ''); ?></p>
            <p><?php echo htmlspecialchars($booking['email'] ?? ''); ?></p>
            <p><?php echo htmlspecialchars($booking['phone'] ?? ''); ?></p>
        </div>
        <div class="details-block">
            <h4>Inspection Address:</h4>
            <p><?php echo nl2br(htmlspecialchars($booking['address'] ?? '')); ?></p>
            <p style="margin-top:10px;">
                <strong>Status:</strong> 
                <span class="status-badge status-<?php echo explode(' ', $booking['status'])[0]; ?>">
                    <?php echo htmlspecialchars($booking['status']); ?>
                </span>
            </p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Service Description</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($items as $item): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['desc']); ?></strong></td>
                <td style="text-align: right;">£<?php echo number_format($item['price'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td>Total Services Amount:</td>
            <td style="text-align: right;">£<?php echo number_format($invoice_total, 2); ?></td>
        </tr>
        <tr>
            <td>Deposit Paid:</td>
            <td style="text-align: right; color:#10b981;">-£<?php echo number_format($deposit, 2); ?></td>
        </tr>
        <tr>
            <td>Final Balance Due:</td>
            <td style="text-align: right;">£<?php echo number_format($final_balance, 2); ?></td>
        </tr>
        <tr class="grand-total">
            <td>Total Invoiced:</td>
            <td style="text-align: right;">£<?php echo number_format($grand_total, 2); ?></td>
        </tr>
    </table>
</div>

</body>
</html>
