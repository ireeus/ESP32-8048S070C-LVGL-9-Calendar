<?php
session_start();

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
    // Store customer info in session
    $_SESSION['customer'] = [
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'address' => $_POST['address'] ?? '',
        'phone' => $_POST['phone'] ?? ''
    ];
    // Redirect to same page with flag to show PayPal form
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
    <title>Purchase - Cronetab Calendar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            color: #fff;
            padding-top: 60px;
            overflow-x: hidden;
        }
        .purchase-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 4rem 2rem;
        }
        .product-card, .cart-summary, .checkout-form {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
        }
        .product-card {
            text-align: center;
        }
        .cart-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s;
        }
        .cart-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        .btn {
            background: #34D399;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #2FB988;
            transform: scale(1.05);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .success-message {
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid #34D399;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        input, textarea {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #34D399;
        }
        @media (max-width: 768px) {
            body { padding-top: 80px; }
            .cart-item { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .purchase-container { padding: 2rem 1rem; }
        }
  
        body {
            padding-top: 60px;
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            color: #fff;
            overflow-x: hidden;
        }
        .hero {
            position: relative;
            min-height: calc(100vh - 60px);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        .hero-content {
            z-index: 2;
            max-width: 800px;
            padding: 2rem;
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            animation: fadeInDown 1s ease-out;
        }
        .hero-image {
            max-width: 100%;
            height: auto;
            margin: 1rem auto;
            display: block;
            border-radius: 0.5rem;
            animation: fadeInUp 1s ease-out 0.3s;
            animation-fill-mode: backwards;
        }
        .hero-desc {
            font-size: 1.5rem;
            margin-bottom: 2rem;
            animation: fadeInUp 1s ease-out 0.5s;
            animation-fill-mode: backwards;
        }
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .cta-btn {
            background: #34D399;
            color: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            animation: pulse 2s infinite;
            border: none;
            cursor: pointer;
        }
        .cta-btn:hover {
            background: #2FB988;
            transform: scale(1.05);
        }
        .features {
            padding: 4rem 2rem;
            background: rgba(255, 255, 255, 0.1);
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .feature-item {
            background: rgba(255, 255, 255, 0.2);
            padding: 2rem;
            border-radius: 1rem;
            text-align: center;
            transition: transform 0.3s;
        }
        .feature-item:hover {
            transform: translateY(-10px);
        }
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .screenshot-section {
            padding: 4rem 2rem;
            text-align: center;
        }
        .screenshot {
            max-width: 100%;
            margin: 0 auto;
            border: 4px solid white;
            border-radius: 1rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: fadeIn 1s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .purchase-section {
            padding: 4rem 2rem;
            background: linear-gradient(135deg, #34D399, #2FB988);
            text-align: center;
        }
        .purchase-title {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        .purchase-desc {
            font-size: 1.2rem;
            margin-bottom: 2rem;
        }
        .purchase-btn {
            background: white;
            color: #34D399;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, color 0.3s;
        }
        .purchase-btn:hover {
            background: #2FB988;
            color: white;
        }
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
            z-index: 10;
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
        footer {
            padding: 2rem;
            text-align: center;
            background: rgba(0, 0, 0, 0.2);
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
        .menu-items a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        .menu-items a:hover {
            color: #34D399;
        }
        /* Mobile adjustments */
        @media (max-width: 768px) {
            body {
                padding-top: 80px; /* Adjusted for taller mobile menu */
            }
            .hero {
                min-height: calc(100vh - 80px);
            }
            .hero-title {
                font-size: 2.5rem;
            }
            .hero-desc {
                font-size: 1.2rem;
            }
            .menu {
                flex-wrap: wrap;
                gap: 1rem;
                justify-content: center;
            }
            .menu a {
                font-size: 0.9rem;
            }
            .feature-grid {
                grid-template-columns: 1fr;
            }
            .purchase-title {
                font-size: 2rem;
            }
            .purchase-desc {
                font-size: 1rem;
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
            .menu {
                flex-direction: column;
                align-items: center;
                padding: 1rem 0;
            }
            .menu-items li {
                margin: 10px 0;
            }
        }
        @media (max-width: 480px) {
            .hero-title {
                font-size: 1.5rem;
            }
            .hero-desc {
                font-size: 0.9rem;
            }
            .cta-btn, .purchase-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
        }
        .screenshot {
            max-width: 100%;
            margin: 0 auto;
            border: 4px solid white;
            border-radius: 1rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: fadeIn 1s ease-out;
            width: 50%; /* Reduces width to 50% of the parent container */
            height: auto; /* Maintains aspect ratio */
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>

    <div class="purchase-container">
        <h1 class="text-4xl font-bold text-center mb-8">Complete Your Purchase</h1>

        <!-- Product Details -->
        <div class="product-card">
            <h2 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($product['name']); ?></h2>
            <p class="text-lg mb-4"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
            <p class="text-3xl font-bold text-green-400 mb-4">£<?php echo number_format($product['price'], 2); ?></p>
            
            <?php if (empty($_SESSION['cart'])): ?>
                <form method="POST" class="flex justify-center items-center gap-4">
                    <label for="quantity" class="block mb-2">Quantity:</label>
                    <input type="number" id="quantity" name="quantity" value="1" min="1" class="w-20 text-center">
                    <button type="submit" name="add_to_cart" class="btn">Add to Cart</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Cart Summary -->
        <?php if (!empty($_SESSION['cart'])): ?>
            <div class="cart-summary">
                <h3 class="text-xl font-bold mb-4 text-center">Your Cart</h3>
                <?php foreach ($_SESSION['cart'] as $id => $item): ?>
                    <div class="cart-item">
                        <div>
                            <h4 class="font-bold"><?php echo htmlspecialchars($item['name']); ?></h4>
                            <p>£<?php echo number_format($item['price'], 2); ?> x 
                                <input type="number" name="quantity_<?php echo $id; ?>" value="<?php echo $item['quantity']; ?>" min="1" class="w-16 text-center ml-2">
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" class="inline">
                                <input type="hidden" name="remove_id" value="<?php echo $id; ?>">
                                <button type="submit" name="remove_from_cart" class="btn btn-secondary text-sm px-3 py-1">Remove</button>
                            </form>
                            <form method="POST" class="inline">
                                <button type="submit" name="update_cart" class="btn text-sm px-3 py-1">Update</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="text-right text-2xl font-bold mt-4">
                    Total: £<?php echo number_format($cartTotal, 2); ?>
                </div>
            </div>

            <!-- Checkout Form -->
            <?php if (!isset($_GET['paypal'])): ?>
                <div class="checkout-form">
                    <h3 class="text-xl font-bold mb-4 text-center">Enter Your Details</h3>
                    <form method="POST">
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="name" class="block mb-1">Full Name *</label>
                                <input type="text" id="name" name="name" required class="w-full">
                            </div>
                            <div>
                                <label for="email" class="block mb-1">Email *</label>
                                <input type="email" id="email" name="email" required class="w-full">
                            </div>
                            <div>
                                <label for="phone" class="block mb-1">Phone</label>
                                <input type="tel" id="phone" name="phone" class="w-full">
                            </div>
                            <div class="md:col-span-2">
                                <label for="address" class="block mb-1">Shipping Address *</label>
                                <textarea id="address" name="address" required rows="3" class="w-full"></textarea>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" name="prepare_paypal" class="btn text-lg px-8 py-3">Proceed to PayPal</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- PayPal Form -->
                <div class="checkout-form">
                    <h3 class="text-xl font-bold mb-4 text-center">Confirm and Pay with PayPal</h3>
                    <?php
                    // Assuming only one item in cart
                    $item = reset($_SESSION['cart']);
                    ?>
                    <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
                        <input type="hidden" name="cmd" value="_xclick">
                        <input type="hidden" name="business" value="YOUR_PAYPAL_BUSINESS_EMAIL"> <!-- Replace with your PayPal email -->
                        <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($item['name']); ?>">
                        <input type="hidden" name="amount" value="<?php echo number_format($item['price'], 2); ?>">
                        <input type="hidden" name="currency_code" value="GBP">
                        <input type="hidden" name="quantity" value="<?php echo $item['quantity']; ?>">
                        <input type="hidden" name="no_shipping" value="0"> <!-- 0 for physical goods requiring shipping -->
                        <input type="hidden" name="return" value="http://yourwebsite.com/purchase.php?success=1"> <!-- Replace with your success URL -->
                        <input type="hidden" name="cancel_return" value="http://yourwebsite.com/purchase.php"> <!-- Replace with your cancel URL -->
                        <!-- Optional: Pass customer info to prefill on PayPal -->
                        <input type="hidden" name="first_name" value="<?php echo explode(' ', $_SESSION['customer']['name'])[0] ?? ''; ?>">
                        <input type="hidden" name="last_name" value="<?php echo end(explode(' ', $_SESSION['customer']['name'])) ?? ''; ?>">
                        <input type="hidden" name="email" value="<?php echo $_SESSION['customer']['email']; ?>">
                        <input type="hidden" name="address1" value="<?php echo $_SESSION['customer']['address']; ?>">
                        <input type="hidden" name="night_phone_a" value="<?php echo $_SESSION['customer']['phone']; ?>">
                        <div class="text-center">
                            <button type="submit" class="btn text-lg px-8 py-3">Pay with PayPal - £<?php echo number_format($cartTotal, 2); ?></button>
                        </div>
                    </form>
                    <p class="text-center mt-4 text-sm">You will be redirected to PayPal to complete the payment. After payment, you will return here.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($_SESSION['cart'])): ?>
            <div class="text-center">
                <a href="index.php" class="btn">Back to Home</a>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">
                <h2 class="text-2xl font-bold mb-2">Thank you for your purchase!</h2>
                <p>Order confirmed for Cronetab Calendar. You will receive a confirmation email shortly with shipping details.</p>
                <p><strong>Total Paid: £<?php echo number_format($cartTotal, 2); ?></strong></p>
                <a href="index.php" class="btn mt-4 inline-block">Back to Home</a>
            </div>
            <?php unset($_SESSION['cart'], $_SESSION['customer']); ?>
        <?php endif; ?>
    </div>

    <footer class="mt-8 p-4 text-center bg-black bg-opacity-20">
        <p>&copy; 2023 Cronetab Calendar. All rights reserved.</p>
    </footer>
</body>
</html>