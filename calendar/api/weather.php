<?php
// weather.php - Weather functionality
include 'config.php'; // Assuming config.php sets up $db and sessions

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$weather = get_weather_data(); // Assuming this function is defined elsewhere
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
    <title>Weather - Custom Calendar Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Weather icons CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/css/weather-icons.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            color: #fff;
            overflow-x: hidden;
        }
        .hero, .weather-section {
            position: relative;
            min-height: 50vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            padding: 2rem;
        }
        .hero::before, .weather-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        .hero-content, .weather-content {
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
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .features, .forecast-section {
            padding: 4rem 2rem;
            background: rgba(255, 255, 255, 0.1);
        }
        .feature-grid, .forecast-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .feature-item, .forecast-day {
            background: rgba(255, 255, 255, 0.2);
            padding: 2rem;
            border-radius: 1rem;
            text-align: center;
            transition: transform 0.3s;
        }
        .feature-item:hover, .forecast-day:hover {
            transform: translateY(-10px);
        }
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
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
        .weather-card {
            background: rgba(255, 255, 255, 0.2);
            padding: 2rem;
            border-radius: 1rem;
            text-align: center;
            max-width: 400px;
            margin: 0 auto;
            animation: fadeInUp 1s ease-out;
        }
        .weather-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
        }
        .weather-temp {
            font-size: 3rem;
            font-weight: bold;
        }
        .weather-desc {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .weather-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .weather-detail-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 0.5rem;
        }
        .weather-detail-label {
            display: block;
            font-size: 1rem;
            opacity: 0.8;
        }
        .weather-detail-value {
            font-size: 1.2rem;
            font-weight: bold;
        }
        .update-time {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        .forecast h2 {
            text-align: center;
            font-size: 2rem;
            margin-bottom: 2rem;
        }
        .forecast-day .day {
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .forecast-day i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .forecast-day .temps {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }
        .forecast-day .desc {
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }
        .forecast-day .precip {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        /* Mobile-specific adjustments */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            .menu {
                flex-wrap: wrap;
                gap: 1rem;
                justify-content: center;
            }
            .menu a {
                font-size: 0.9rem;
            }
            .feature-grid, .forecast-grid {
                grid-template-columns: 1fr;
            }
            .weather-icon {
                font-size: 4rem;
            }
            .weather-temp {
                font-size: 2.5rem;
            }
            .weather-desc {
                font-size: 1.2rem;
            }
            .forecast h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <nav class="menu">
        <a href="dashboard.php">Dashboard</a>
        <a href="weather.php">Weather</a>
        <!-- Add other links as needed, e.g., <a href="calendar.php">Calendar</a> -->
        <a href="logout.php">Logout</a> <!-- Assuming logout.php exists -->
    </nav>
   
    <section class="weather-section">
        <div class="weather-content">
            <h1 class="hero-title">Weather in Rochdale</h1>
            <div class="weather-card">
                <div class="location">Rochdale, UK</div>
                <i class="wi <?php echo $weather['icon_class']; ?> weather-icon"></i>
                <div class="weather-temp"><?php echo round($weather['current']['temperature_2m'] ?? 0); ?>°C</div>
                <div class="weather-desc"><?php echo $weather['description']; ?></div>
                <div class="weather-details">
                    <div class="weather-detail-item feels-like">
                        <span class="weather-detail-label">Feels like</span>
                        <span class="weather-detail-value"><?php echo round($weather['current']['apparent_temperature'] ?? 0); ?>°C</span>
                    </div>
                    <div class="weather-detail-item humidity">
                        <span class="weather-detail-label">Humidity</span>
                        <span class="weather-detail-value"><?php echo $weather['current']['relative_humidity_2m'] ?? 0; ?>%</span>
                    </div>
                    <div class="weather-detail-item wind">
                        <span class="weather-detail-label">Wind</span>
                        <span class="weather-detail-value"><?php echo round($weather['current']['wind_speed_10m'] ?? 0); ?> km/h</span>
                    </div>
                    <div class="weather-detail-item precip">
                        <span class="weather-detail-label">Precip</span>
                        <span class="weather-detail-value"><?php echo $weather['current']['precipitation'] ?? 0; ?> mm</span>
                    </div>
                </div>
                <div class="update-time">Updated: <?php echo date('H:i'); ?></div>
            </div>
        </div>
    </section>
   
    <section class="forecast-section features">
        <h2 class="text-3xl text-center font-bold mb-6">7-Day Forecast</h2>
        <div class="forecast-grid feature-grid">
            <?php foreach ($weather['daily']['time'] as $i => $time): ?>
                <div class="forecast-day feature-item">
                    <div class="day"><?php echo date('D, M j', strtotime($time)); ?></div>
                    <i class="wi <?php echo get_icon_class($weather['daily']['weather_code'][$i]); ?> feature-icon"></i>
                    <div class="temps"><?php echo round($weather['daily']['temperature_2m_max'][$i]); ?>° / <?php echo round($weather['daily']['temperature_2m_min'][$i]); ?>°</div>
                    <div class="desc"><?php echo $weather['weather_codes'][$weather['daily']['weather_code'][$i]] ?? 'Unknown'; ?></div>
                    <div class="precip">Precip: <?php echo $weather['daily']['precipitation_sum'][$i] ?? 0; ?> mm</div>
                </div>
            <?php endforeach; ?>
        </div>
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