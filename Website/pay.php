<?php
$id = $_GET['id'] ?? '';
$secret = $_GET['secret'] ?? '';

$config = json_decode(file_get_contents('config.json'), true);
$bookings = json_decode(file_get_contents('bookings.json'), true);
$booking = null;
foreach($bookings as $b) {
    if($b['id'] === $id && isset($b['secret']) && $b['secret'] === $secret) {
        $booking = $b;
        break;
    }
}

if(!$booking) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; color:#ef4444;'><h2>Error</h2><p>Invalid or expired payment link.</p></div>");
}

$is_sandbox = ($config['environment'] === 'sandbox');
$client_id = $is_sandbox ? $config['paypal_sandbox_client_id'] : $config['paypal_production_client_id'];
if(empty($client_id)) $client_id = 'sb';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CronTech | Pay Invoice</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { background:#f4f7f9; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; font-family:'Montserrat', sans-serif; padding: 20px;}
        .card { background:#fff; padding:40px; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.08); text-align:center; max-width:450px; width:100%; border: 1px solid #e5e7eb;}
        h1 { color:#1f2937; margin-top:0; font-weight: 800; text-transform: uppercase; font-size: 28px;}
        h1 span { color:#ffb703; }
        .details { color:#4b5563; font-weight:500; font-size: 14px; margin-bottom: 25px; line-height: 1.6;}
        .amount-box { background:#fffbeb; border:2px solid #fde68a; border-radius:8px; padding:25px; margin:30px 0; }
        .amount-label { margin:0; font-size:13px; font-weight:700; color:#b45309; text-transform:uppercase; letter-spacing: 0.5px;}
        .amount-value { font-size:42px; font-weight:800; color:#111827; margin-top:5px; }
        #paypal-button-container { min-height: 150px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Cron<span>Tech</span></h1>
        
        <?php if($booking['status'] === 'Paid in Full'): ?>
            <div style="font-size:60px; color:#10b981; margin:20px 0;">✓</div>
            <h2 style="color:#111827;">Invoice Paid</h2>
            <p style="color:#6b7280; font-weight: 500;">Thank you! This invoice has already been successfully settled. Your certificates are on the way.</p>
        <?php else: ?>
            <div class="details">
                <strong>Booking ID:</strong> <?php echo $booking['id']; ?><br>
                <strong>Service Address:</strong> <?php echo htmlspecialchars($booking['address']); ?>
            </div>
            
            <div class="amount-box">
                <p class="amount-label">Final Balance Due</p>
                <div class="amount-value">£<?php echo number_format($booking['final_balance'], 2); ?></div>
            </div>

            <div id="paypal-button-container"></div>
            
            <script src="https://www.paypal.com/sdk/js?client-id=<?php echo $client_id; ?>&currency=GBP"></script>
            <script>
                paypal.Buttons({
                    style: { shape: 'rect', color: 'gold', layout: 'vertical', label: 'pay' },
                    createOrder: function(data, actions) {
                        return actions.order.create({
                            purchase_units: [{
                                amount: { value: '<?php echo number_format($booking['final_balance'], 2, '.', ''); ?>', currency_code: 'GBP' },
                                description: 'Final Balance for <?php echo $booking['id']; ?>'
                            }]
                        });
                    },
                    onApprove: function(data, actions) {
                        return actions.order.capture().then(function(details) {
                            fetch('api.php?action=pay_balance', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    id: '<?php echo $booking['id']; ?>',
                                    secret: '<?php echo $booking['secret']; ?>',
                                    transaction_id: details.id
                                })
                            }).then(r => r.json()).then(res => {
                                if(res.success) {
                                    location.reload();
                                } else {
                                    alert("There was an error updating your payment status. Please contact support.");
                                }
                            });
                        });
                    }
                }).render('#paypal-button-container');
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
