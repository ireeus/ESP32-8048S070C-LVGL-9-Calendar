<?php
session_start();

$id = $_GET['id'] ?? '';
$secret = $_GET['secret'] ?? '';

$bookings_file = __DIR__ . '/../bookings.json';
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

$is_admin = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$is_valid_token = isset($booking['secret']) && $booking['secret'] === $secret && !empty($secret);

if (!$is_admin && !$is_valid_token) {
    http_response_code(403);
    die("Unauthorized access to invoice.");
}

$total = (float)($booking['total'] ?? 0);
$deposit = (float)($booking['deposit'] ?? 0);
$final_balance = isset($booking['final_balance']) ? (float)$booking['final_balance'] : ($total - $deposit);
$grand_total = $deposit + $final_balance;

$service_type = $booking['service_type'] ?? 'both';

$eicr_desc = $booking['eicr_desc'] ?? 'N/A';
$pat_desc = $booking['pat_desc'] ?? 'N/A';
$extra_items = $booking['extra_items'] ?? [];

// ---------- Use stored breakdown if available ----------
if (isset($booking['price_breakdown']) && is_array($booking['price_breakdown'])) {
    $eicr_cost = (float)($booking['price_breakdown']['eicr_cost'] ?? 0);
    $pat_cost = (float)($booking['price_breakdown']['pat_cost'] ?? 0);
    $discount = (float)($booking['price_breakdown']['discount'] ?? 0);
    $base_cost = $eicr_cost + $pat_cost;
} else {
    // Fallback to recalculation for older bookings
	$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
    $eicr_cost = 0;
    $pat_cost = 0;

    if ($service_type === 'eicr' || $service_type === 'both') {
        if (!empty($booking['prop_type'])) {
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
            $base = $price_map[$booking['prop_type']] ?? 0;
            $inc = (int)($booking['circuits'] ?? 0);
            $base_circ = $base_circuits_map[$booking['prop_type']] ?? 0;
            $extra_circ = max(0, $inc - $base_circ) * ($config['price_extra_circuit'] ?? 15);
            $eicr_cost = $base + $extra_circ;
        }
    }

    if ($service_type === 'pat' || ($service_type === 'both' && !empty($booking['pat_included']))) {
        $pat_base = $config['price_pat_base'] ?? 50;
        $pat_base_items = $config['pat_base_items'] ?? 15;
        $pat_items = (int)($booking['pat_items'] ?? 0);
        $pat_extra = $config['price_pat_extra'] ?? 2;
        $pat_cost = $pat_base + max(0, $pat_items - $pat_base_items) * $pat_extra;
    }

    $base_cost = $eicr_cost + $pat_cost;
    $discount = max(0, $base_cost - $total);
}

// Total due (base after discount + extra items)
$extra_sum = array_sum(array_column($extra_items, 'amount'));
$total_due = $total + $extra_sum; // total already includes discount, extra items added on top
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
            position: relative;
            overflow: hidden;
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
        
        .totals-table { width: 300px; margin-left: auto; }
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

        .status-Refunded {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Large diagonal stamp overlay */
        .refunded-stamp {
            position: absolute;
            top: 400px;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-28deg);
            font-size: 72px;
            font-weight: 800;
            padding: 18px 50px;
            text-transform: uppercase;
            letter-spacing: 12px;
            pointer-events: none;
            z-index: 100;
            white-space: nowrap;
            border-radius: 8px;
            user-select: none;
        }

        /* Colors for the successful refund (light green) */
        .stamp-green {
            color: rgba(34, 197, 94, 0.35); /* Tailwind Green-500 */
            border: 10px solid rgba(34, 197, 94, 0.35);
        }

        /* Colors for the rejected/refund in progress (red) */
        .stamp-red {
            color: rgba(185, 28, 28, 0.25);
            border: 10px solid rgba(185, 28, 28, 0.25);
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

    <!-- Itemised breakdown -->
    <table>
        <thead>
            <tr>
                <th>Service Description</th>
                <th style="text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($service_type === 'eicr' || $service_type === 'both'): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($eicr_desc); ?></strong></td>
                <td style="text-align: right;">£<?php echo number_format($eicr_cost, 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($service_type === 'pat' || ($service_type === 'both' && !empty($booking['pat_included']))): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($pat_desc); ?></strong></td>
                <td style="text-align: right;">£<?php echo number_format($pat_cost, 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php foreach ($extra_items as $item): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['description']); ?></strong></td>
                <td style="text-align: right;">£<?php echo number_format($item['amount'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($discount > 0): ?>
            <tr>
                <td><strong>Discount Offer</strong></td>
                <td style="text-align: right; color:#ef4444;">-£<?php echo number_format($discount, 2); ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
 
    <!-- Totals table -->
    <table class="totals-table">
        <tr>
            <td>Subtotal (before discount):</td>
            <td style="text-align: right;">£<?php echo number_format($base_cost + $extra_sum, 2); ?></td>
        </tr>
        <?php if ($discount > 0): ?>
        <tr>
            <td>Discount:</td>
            <td style="text-align: right; color:#ef4444;">-£<?php echo number_format($discount, 2); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td>Total (after discount):</td>
            <td style="text-align: right;">£<?php echo number_format($total_due, 2); ?></td>
        </tr>
        <tr>
            <td>Deposit Paid (10%):</td>
            <td style="text-align: right; color:#10b981;">-£<?php echo number_format($deposit, 2); ?></td>
        </tr>
        <tr>
            <td>Remaining Balance:</td>
            <td style="text-align: right;">£<?php echo number_format($final_balance, 2); ?></td>
        </tr>
        <tr class="grand-total">
            <td>Total Adjusted Amount:</td>
            <td style="text-align: right;">£<?php echo number_format($grand_total, 2); ?></td>
        </tr>
    </table>
    
    <?php if (stripos($booking['status'] ?? '', 'refunded') !== false): ?>
        <div class="refunded-stamp stamp-green">REFUNDED</div>
    <?php elseif (stripos($booking['status'] ?? '', 'refund reqd') !== false): ?>
        <div class="refunded-stamp stamp-red">Rejected</div>
    <?php endif; ?>
</div>

</body>
</html>