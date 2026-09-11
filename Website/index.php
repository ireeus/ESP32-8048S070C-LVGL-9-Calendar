<?php
session_start();
// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#3B82F6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Cronetab Calendar">
    <link rel="apple-touch-icon" href="img/logo.png">
    <link rel="manifest" href="/manifest.json">
    <title>Cronetab Calendar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
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
    <section id="home" class="hero">
        <div class="hero-content">
            <h1 class="hero-title">Scheduling with Crone<font color="red">Tab</font> Calendar</h1>
            <img src="Cron-Tab.png" alt="Cron-Tab Calendar" width="350px"class="hero-image">
            <p class="hero-desc">Discover the 7-inch Wi-Fi connected calendar frame with integrated weather station, air quality index, UK bank holidays preview, task scheduling with notifications, and weather forecasts for scheduled dates up to 14 days ahead. Integrate seamlessly with SmartParcel Box notifications (additional hardware required).</p>
            <a href="#purchase" class="cta-btn">Get Started Today</a>
<br><br>
        </div>
    </section>
    <section id="features" class="features">
        <h2 class="text-3xl text-center font-bold mb-6">Key Features</h2>
        <div class="feature-grid">
            <div class="feature-item">
                <i class="feature-icon">📅</i>
                <h3 class="text-xl font-bold mb-2">Task Scheduling & Notifications</h3>
                <p>Schedule tasks with full-day support, descriptions, intuitive UI, and timely notifications.</p>
            </div>
            <div class="feature-item">
                <i class="feature-icon">🌤️</i>
                <h3 class="text-xl font-bold mb-2">Weather Station & Air Quality</h3>
                <p>Get real-time forecasts, current conditions, air quality index, and previews up to 14 days ahead for scheduled dates.</p>
            </div>
            <div class="feature-item">
                <i class="feature-icon">🔗</i>
                <h3 class="text-xl font-bold mb-2">ICS Integration & UK Holidays</h3>
                <p>Sync with external calendars like Google, Outlook, and iCloud; includes UK bank holidays preview.</p>
            </div>
            <div class="feature-item">
                <i class="feature-icon">⚙️</i>
                <h3 class="text-xl font-bold mb-2">SmartParcel Box Integration</h3>
                <p>Personalize settings and integrate with SmartParcel Box for notifications (additional hardware required).</p>
            </div>
        </div>
    </section>
<section id="screenshot" class="screenshot-section">
    <h2 class="text-3xl font-bold mb-4">See It in Action</h2>
    <video controls autoplay muted loop class="screenshot">
        <source src="Intro.mp4" type="video/mp4">
        Your browser does not support the video tag.
    </video>
</section>
    <section id="purchase" class="purchase-section">
        <h2 class="purchase-title">Purchase Now</h2>
        <p class="purchase-desc">Unlock full access for just £63.99. Lifetime updates included.</p>
        <a href="purchase.php" class="purchase-btn">Buy Now</a>
    </section>
    <footer>
        <p>&copy; 2023 Cronetab Calendar. All rights reserved.</p>
    </footer>
    <script>
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