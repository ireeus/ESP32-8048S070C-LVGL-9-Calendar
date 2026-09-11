<?php
// weather.php - Weather functionality
include 'config.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$weather = get_weather_data();
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
    <link rel="apple-touch-icon" href="icon-192x192.png">
    <link rel="manifest" href="manifest.json">
    <title>Weather - Custom Calendar Service</title>
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
        .weather-section {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            padding: 0; /* Removed padding to maximize width */
            width: 100%; /* Full screen width */
        }
        .weather-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        .weather-content {
            z-index: 2;
            width: 95%; /* Full width */
            padding: 0; /* Removed padding to maximize width */
            height: 100%; /* Match section height */
        }
        .weather-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: rgba(255, 255, 255, 0.2);
            padding-left: 2rem;
            padding-right: 2rem;
            border-radius: 1rem;
            text-align: center;
            width: 100%; /* Full width within content */
            height: 100%; /* Match section height */
            margin: 10px;
            animation: fadeInUp 1s ease-out;
            overflow-y: auto; /* Enable scrolling if content exceeds */
        }
        .weather-icon {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100px;
            height: 100px;
        }
        .weather-icon img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .feature-icon {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 50px;
            height: 50px;
        }
        .feature-icon img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .weather-temp {
            font-size: 3rem;
            font-weight: bold;
        }
        .weather-desc {
            font-size: 1.5rem;
        }
        .weather-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
            width: 100%;
        }
        .weather-detail-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 0.5rem;
            text-align: center;
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
        .forecast-section {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.1);
        }
        .forecast-content {
            z-index: 2;
        }
        .forecast-row {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: nowrap;
            overflow-x: auto;
			min-width: 100%;

        }
        .forecast-day {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: rgba(255, 255, 255, 0.2);
            padding: 1rem;
            border-radius: 0.5rem;
            text-align: center;
            min-width: 12%;
            transition: transform 0.3s;
        }
        .forecast-day:hover {
            transform: translateY(-10px);
        }
        .forecast-day .day {
            font-weight: bold;
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
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
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
            .weather-card {
                max-width: 100%;
                max-height: none;
                overflow-y: visible;
            }
            .weather-icon {
                width: 80px;
                height: 80px;
            }
            .weather-icon img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }
            .weather-temp {
                font-size: 2.5rem;
            }
            .weather-desc {
                font-size: 1.2rem;
            }
            .forecast-row {
                flex-wrap: wrap;
                justify-content: center;
            }
            .forecast-day {
                min-width: 300px;
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
            .feature-icon {
                width: 40px;
                height: 40px;
            }
            .feature-icon img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
            }
            .weather-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>
    <section class="weather-section">
        <div class="weather-content">
            <div class="weather-card">
                <div class="location"><?php echo htmlspecialchars($weather['location_name']); ?>                <i class="update-time">Updated: <?php echo date('H:i'); ?></i>
</div>
                <div class="weather-icon"><img src="<?php echo $weather['icon_class']; ?>" alt="<?php echo $weather['description']; ?>"></div>
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
            </div>
        </div>
    </section>
    <section class="forecast-section">
            <div class="forecast-row">
                <?php foreach ($weather['daily']['time'] as $i => $time): ?>
                    <div class="forecast-day">
                        <div class="day"><?php echo date('D, M j', strtotime($time)); ?></div>
                        <div class="feature-icon"><img src="<?php echo get_icon_class($weather['daily']['weather_code'][$i]); ?>" alt="<?php echo $weather['weather_codes'][$weather['daily']['weather_code'][$i]] ?? 'Unknown'; ?>"></div>
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