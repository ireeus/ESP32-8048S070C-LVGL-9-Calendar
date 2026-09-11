<?php
// index.php - Advanced and stylish landing page for the service, now mobile-friendly and PWA compatible
include 'config.php'; // Assuming config.php is needed for any shared logic, but no session checks for redirect
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#3B82F6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Custom Calendar">
    <link rel="apple-touch-icon" href="icon-192x192.png"> <!-- Replace with actual icon path -->
    <link rel="manifest" href="manifest.json">
    <title>Custom Calendar Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            color: #fff;
            overflow-x: hidden;
        }
        .hero {
            position: relative;
            height: 100vh;
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
        }
        .cta-btn:hover {
            background: #2FB988;
            transform: scale(1.05);
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
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
        /* Mobile-specific adjustments */
        @media (max-width: 768px) {
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
        }
    </style>
</head>
<body>
    <nav class="menu">
        <a href="#home">Home</a>
        <a href="#features">Features</a>
        <a href="#screenshot">Screenshot</a>
        <a href="#purchase">Purchase</a>
        <a href="login.php">Login</a>
    </nav>
   
    <section id="home" class="hero">
        <div class="hero-content">
            <h1 class="hero-title">Revolutionize Your Scheduling with Custom Calendar Service</h1>
            <p class="hero-desc">Experience seamless integration of calendar events, real-time weather updates, and ICS support in one powerful platform.</p>
            <a href="#purchase" class="cta-btn">Get Started Today</a>
        </div>
    </section>
   
    <section id="features" class="features">
        <h2 class="text-3xl text-center font-bold mb-6">Key Features</h2> <!-- Corrected class from text-3rem to text-3xl -->
        <div class="feature-grid">
            <div class="feature-item">
                <i class="feature-icon">📅</i>
                <h3 class="text-xl font-bold mb-2">Advanced Calendar</h3>
                <p>Manage events with full-day support, descriptions, and intuitive UI.</p>
            </div>
            <div class="feature-item">
                <i class="feature-icon">🌤️</i>
                <h3 class="text-xl font-bold mb-2">Real-Time Weather</h3>
                <p>Get daily forecasts and current conditions integrated seamlessly.</p>
            </div>
            <div class="feature-item">
                <i class="feature-icon">🔗</i>
                <h3 class="text-xl font-bold mb-2">ICS Integration</h3>
                <p>Sync with external calendars like Google, Outlook, and iCloud.</p>
            </div>
            <div class="feature-item">
                <i class="feature-icon">⚙️</i>
                <h3 class="text-xl font-bold mb-2">Custom Settings</h3>
                <p>Personalize your experience with advanced configuration options.</p>
            </div>
        </div>
    </section>
   
    <section id="screenshot" class="screenshot-section">
        <h2 class="text-3xl font-bold mb-4">See It in Action</h2> <!-- Corrected class from text-3rem to text-3xl -->
        <img src="1.png" alt="Calendar Service Screenshot" class="screenshot"> <!-- Replace with actual image -->
    </section>
   
    <section id="purchase" class="purchase-section">
        <h2 class="purchase-title">Purchase Now</h2>
        <p class="purchase-desc">Unlock full access for just £43.99. Lifetime updates included.</p>
        <a href="purchase.php" class="purchase-btn">Buy Now</a> <!-- Link to purchase processing page -->
    </section>
   
    <footer>
        <p>&copy; 2023 Custom Calendar Service. All rights reserved.</p>
    </footer>

    <script>
        // Register service worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/service-worker.js')
                    .then(registration => {
                        console.log('Service Worker registered with scope:', registration.scope);
                    })
                    .catch(error => {
                        console.error('Service Worker registration failed:', error);
                    });
            });
        }
    </script>
</body>
</html>