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
    <title>Cronetab Calendar & Moonlight</title>
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
            display: inline-block;
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
            width: 50%; /* Reduces width to 50% of the parent container */
            height: auto; /* Maintains aspect ratio */
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
            display: inline-block;
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
        
        /* NEW MOONLIGHT SECTION STYLES */
        .moonlight-section {
            padding: 6rem 2rem;
            background: linear-gradient(135deg, #1e1b4b, #4c1d95);
            text-align: center;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .moonlight-btn {
            background: #8B5CF6;
            color: white;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            border: none;
            cursor: pointer;
            display: inline-block;
        }
        .moonlight-btn:hover {
            background: #7C3AED;
            transform: scale(1.05);
        }
        .moonlight-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            animation: fadeInUp 1s ease-out 0.3s;
            animation-fill-mode: backwards;
        }

        /* Mobile adjustments */
        @media (max-width: 768px) {
            body {
                padding-top: 80px; 
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
            .screenshot {
                width: 90%; 
            }
        }
        @media (max-width: 480px) {
            .hero-title {
                font-size: 1.5rem;
            }
            .hero-desc {
                font-size: 0.9rem;
            }
            .cta-btn, .purchase-btn, .moonlight-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
            .moonlight-icon {
                font-size: 4rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>
    


    <section id="crontab" class="screenshot-section">
            <h2 class="hero-title">Meet  Cron<font color="#870d04">Tab</font></h2>
        <video controls autoplay muted loop class="screenshot">
            <source src="Intro.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </section>


    <section id="purchase" class="purchase-section">
        <h2 class="purchase-title">Purchase Now</h2>
        <p class="purchase-desc">Unlock full access for just £43.99. Lifetime updates included.</p>
        <a href="purchase.php" class="purchase-btn">Buy Now</a>
    </section>
    
    <!-- NEW MOONLIGHT SECTION -->
    <section id="moonlight" class="moonlight-section">
        <div class="hero-content">
            <h2 class="hero-title">Meet Moonlight</h2>
            <!-- Updated Image Tag -->
            <img src="https://moonlight.onthewifi.com/moon.jpeg" alt="Moonlight Ambient System" width="650px" class="hero-image" style="border-radius: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <p class="hero-desc">Transform your environment with seamless connectivity. Experience ambient control and intuitive smart integrations on your network - the perfect addition to your smart home alongside your Cronetab Calendar.</p>
            <a href="https://moonlight.onthewifi.com/" target="_blank" class="moonlight-btn">Discover Moonlight</a>
        </div>
    </section>

    <footer>
        <p>&copy; 2023 Cronetab Calendar & Moonlight. All rights reserved.</p>
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