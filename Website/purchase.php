<?php
session_start();

// Load config for PayPal (Reusing the logic from pay.php)
$config_file = __DIR__ . '/../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$is_sandbox = (isset($config['environment']) && $config['environment'] === 'sandbox');
$client_id = $is_sandbox ? ($config['paypal_sandbox_client_id'] ?? 'sb') : ($config['paypal_production_client_id'] ?? 'sb');
if(empty($client_id)) $client_id = 'sb';

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Product details
$product = [
    'id' => 1,
    'name' => 'Cronetab Calendar',
    'price' => 43.99,
    'description' => '7-inch Wi-Fi connected calendar frame with integrated weather station, air quality index, UK bank holidays preview, task scheduling with notifications, and weather forecasts for scheduled dates up to 14 days ahead. Integrate seamlessly with SmartParcel Box notifications (additional hardware required). Lifetime updates included.'
];

// Handle add to cart
if (isset($_POST['add_to_cart'])) {
    $quantity = intval($_POST['quantity'] ?? 1);
    if ($quantity > 0) {
        $_SESSION['cart'][$product['id']] = [
            'name' => $product['name'],
            'price' => $product['price'],
            'quantity' => $quantity
        ];
    }
}

// Handle update cart
if (isset($_POST['update_cart'])) {
    foreach ($_SESSION['cart'] as $id => &$item) {
        $newQty = intval($_POST['quantity_' . $id] ?? 0);
        if ($newQty <= 0) {
            unset($_SESSION['cart'][$id]);
        } else {
            $item['quantity'] = $newQty;
        }
    }
}

// Handle remove from cart
if (isset($_POST['remove_from_cart'])) {
    $removeId = intval($_POST['remove_id'] ?? 0);
    if (isset($_SESSION['cart'][$removeId])) {
        unset($_SESSION['cart'][$removeId]);
    }
}

// Handle prepare for PayPal (collect customer info)
if (isset($_POST['prepare_paypal'])) {
    $_SESSION['customer'] = [
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'address' => $_POST['address'] ?? '',
        'phone' => $_POST['phone'] ?? ''
    ];
    header('Location: purchase.php?paypal=1');
    exit;
}

// Calculate cart total
$cartTotal = 0;
foreach ($_SESSION['cart'] as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Cronetab</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #1f2937, #3B82F6);
            color: #fff;
            padding-top: 80px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* MENU STYLES RESTORED */
        .menu {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 1rem;
            display: flex;
            justify-content: center;
            gap: 2rem;
            z-index: 50;
        }
        .menu a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        .menu a:hover {
            color: #34D399;
        }
        .menu-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
        }
        .bar {
            height: 3px;
            width: 25px;
            background-color: white;
            margin: 4px 0;
        }
        .menu-items {
            display: flex;
            justify-content: center;
            gap: 2rem;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .menu-items li {
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .menu {
                flex-wrap: wrap;
                gap: 1rem;
                justify-content: center;
                flex-direction: column;
                align-items: center;
                padding: 1rem 0;
            }
            .menu a {
                font-size: 0.9rem;
            }
            .menu-toggle {
                display: flex;
                position: absolute;
                right: 20px;
                top: 15px;
            }
            .menu-items {
                display: none;
                flex-direction: column;
                width: 100%;
                text-align: center;
            }
            .menu-items.active {
                display: flex;
            }
            .menu-items li {
                margin: 10px 0;
            }
        }

        /* GLASSMORPHISM CARD STYLES */
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        .btn-primary {
            background: #34D399;
            color: #000;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: #2FB988;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(52, 211, 153, 0.4);
        }
        input, textarea {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            width: 100%;
            transition: border-color 0.3s;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #34D399;
            box-shadow: 0 0 0 2px rgba(52, 211, 153, 0.2);
        }
        #paypal-button-container {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            min-height: 150px;
        }
        /* Loader Animation */
        @keyframes pulse-fast {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }
        .loader-box { animation: pulse-fast 1.5s infinite; }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>

    <div class="flex-grow max-w-5xl mx-auto w-full px-4 py-8">
        
        <?php if (isset($_GET['success'])): ?>
            <div class="glass-card p-10 text-center border-green-500 border-2 bg-green-500/10 mt-8">
                <div class="text-6xl mb-4">🎉</div>
                <h2 class="text-3xl font-bold mb-4 text-green-400">Payment Successful!</h2>
                <p class="text-lg text-gray-200 mb-6">Thank you for your purchase. We are preparing your order for shipment and will send a confirmation email shortly.</p>
                <?php if(isset($_GET['tx'])): ?>
                    <p class="text-sm text-gray-400 mb-6">Transaction ID: <?php echo htmlspecialchars($_GET['tx']); ?></p>
                <?php endif; ?>
                <a href="index.php" class="btn-primary px-8 py-3 rounded-full inline-block">Return Home</a>
            </div>
            <?php unset($_SESSION['cart'], $_SESSION['customer']); ?>
        
        <?php else: ?>

            <div class="grid md:grid-cols-2 gap-8">
                
                <!-- Left Column: Product & Cart -->
                <div class="space-y-6">
                    <div class="glass-card p-8 text-center">
                        <img src="Cron-Tab.png" alt="Cronetab" class="h-32 mx-auto mb-4 drop-shadow-xl" onerror="this.style.display='none'">
                        <h2 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($product['name']); ?></h2>
                        <p class="text-gray-300 text-sm mb-6 leading-relaxed"><?php echo htmlspecialchars($product['description']); ?></p>
                        <p class="text-4xl font-bold text-green-400 mb-6">£<?php echo number_format($product['price'], 2); ?></p>
                        
                        <?php if (empty($_SESSION['cart'])): ?>
                            <form method="POST" class="flex justify-center items-center gap-4">
                                <label for="quantity" class="font-semibold text-gray-200">Qty:</label>
                                <input type="number" id="quantity" name="quantity" value="1" min="1" class="w-24 text-center">
                                <button type="submit" name="add_to_cart" class="btn-primary px-6 py-3 rounded-full">Add to Cart</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($_SESSION['cart'])): ?>
                        <div class="glass-card p-6">
                            <h3 class="text-xl font-bold mb-4 border-b border-white/10 pb-2">Order Summary</h3>
                            <?php foreach ($_SESSION['cart'] as $id => $item): ?>
                                <div class="flex flex-col sm:flex-row justify-between items-center gap-4 mb-4 bg-black/20 p-4 rounded-xl">
                                    <div class="text-center sm:text-left">
                                        <h4 class="font-bold"><?php echo htmlspecialchars($item['name']); ?></h4>
                                        <p class="text-green-400 font-semibold">£<?php echo number_format($item['price'], 2); ?></p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <form method="POST" class="flex items-center gap-2">
                                            <input type="number" name="quantity_<?php echo $id; ?>" value="<?php echo $item['quantity']; ?>" min="1" class="w-16 text-center py-1">
                                            <button type="submit" name="update_cart" class="bg-blue-500/50 hover:bg-blue-500 px-3 py-2 rounded-lg text-sm transition">Update</button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="remove_id" value="<?php echo $id; ?>">
                                            <button type="submit" name="remove_from_cart" class="bg-red-500/50 hover:bg-red-500 px-3 py-2 rounded-lg text-sm transition">X</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="flex justify-between items-center mt-6 text-2xl font-bold">
                                <span>Total:</span>
                                <span class="text-green-400">£<?php echo number_format($cartTotal, 2); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Checkout or PayPal -->
                <div>
                    <?php if (!empty($_SESSION['cart'])): ?>
                        <div class="glass-card p-8" id="checkout-panel">
                            
                            <?php if (!isset($_GET['paypal'])): ?>
                                <h3 class="text-2xl font-bold mb-6">Shipping Details</h3>
                                <form method="POST" class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="col-span-2 sm:col-span-1">
                                            <label class="block text-sm text-gray-300 mb-1">Full Name *</label>
                                            <input type="text" name="name" required placeholder="John Doe">
                                        </div>
                                        <div class="col-span-2 sm:col-span-1">
                                            <label class="block text-sm text-gray-300 mb-1">Email *</label>
                                            <input type="email" name="email" required placeholder="john@example.com">
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-sm text-gray-300 mb-1">Phone</label>
                                            <input type="tel" name="phone" placeholder="+44...">
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-sm text-gray-300 mb-1">Shipping Address *</label>
                                            <textarea name="address" required rows="3" placeholder="Full postal address..."></textarea>
                                        </div>
                                    </div>
                                    <button type="submit" name="prepare_paypal" class="btn-primary w-full py-4 mt-4 rounded-xl text-lg">Proceed to Payment</button>
                                </form>

                            <?php else: ?>
                                <!-- Integrated PayPal SDK based on pay.php -->
                                <h3 class="text-2xl font-bold mb-2">Secure Checkout</h3>
                                <p class="text-sm text-gray-300 mb-6 border-b border-white/10 pb-4">Select your preferred payment method below.</p>
                                
                                <div id="paypal-button-container"></div>
                                <div id="loading-screen" style="display:none;" class="text-center py-8 loader-box">
                                    <div class="text-5xl mb-4">⏳</div>
                                    <h3 class="text-xl font-bold">Processing...</h3>
                                    <p class="text-sm text-gray-300 mt-2">Please do not close this window.</p>
                                </div>

                                <?php $item = reset($_SESSION['cart']); ?>
                                <script src="https://www.paypal.com/sdk/js?client-id=<?php echo $client_id; ?>&currency=GBP"></script>
                                <script>
                                    paypal.Buttons({
                                        style: { shape: 'rect', color: 'gold', layout: 'vertical', label: 'pay' },
                                        createOrder: function(data, actions) {
                                            return actions.order.create({
                                                purchase_units: [{
                                                    amount: { value: '<?php echo number_format($cartTotal, 2, '.', ''); ?>', currency_code: 'GBP' },
                                                    description: '<?php echo htmlspecialchars($item['name']); ?>'
                                                }]
                                            });
                                        },
                                        onApprove: function(data, actions) {
                                            document.getElementById('paypal-button-container').style.display = 'none';
                                            document.getElementById('loading-screen').style.display = 'block';
                                            
                                            return actions.order.capture().then(function(details) {
                                                // Redirect to success page on capture
                                                window.location.href = 'purchase.php?success=1&tx=' + details.id;
                                            }).catch(function(err) {
                                                console.error("Capture Error: ", err);
                                                alert("Payment could not be processed. Please try again.");
                                                window.location.reload();
                                            });
                                        }
                                    }).render('#paypal-button-container');
                                </script>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="glass-card p-10 text-center h-full flex flex-col items-center justify-center">
                            <div class="text-6xl mb-4 text-gray-400">🛒</div>
                            <h3 class="text-xl font-bold text-gray-300">Your cart is empty</h3>
                            <p class="text-gray-400 mt-2">Add a product to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <footer class="py-6 text-center bg-black/30 w-full mt-auto">
        <p class="text-gray-400 text-sm">&copy; <?php echo date('Y'); ?> Cronetab Calendar. All rights reserved.</p>
    </footer>
</body>
</html>