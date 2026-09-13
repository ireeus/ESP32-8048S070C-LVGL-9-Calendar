<?php
// Rochdale coordinates
$lat = 53.6177;
$lon = -2.1552;

// Function to get icon class
function get_icon_class($code) {
    return match(true) {
        $code >= 0 && $code <= 2 => 'wi-day-sunny',
        $code == 3 => 'wi-cloudy',
        $code >= 45 && $code <= 48 => 'wi-fog',
        $code >= 51 && $code <= 55 || $code >= 61 && $code <= 65 || $code >= 80 && $code <= 82 => 'wi-rain',
        $code >= 71 && $code <= 75 => 'wi-snow',
        $code >= 95 => 'wi-thunderstorm',
        default => 'wi-day-sunny'
    };
}

// Current weather and 7-day forecast API endpoint
$url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_direction_10m&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum&timezone=auto&forecast_days=7";

// Fetch weather data
$weather_data = json_decode(file_get_contents($url), true);

// Extract current weather
$current = $weather_data['current'] ?? [];

// Extract daily forecast
$daily = $weather_data['daily'] ?? [];

// Weather code mapping (simplified WMO codes to descriptions)
$weather_codes = [
    0 => 'Clear sky',
    1 => 'Mainly clear',
    2 => 'Partly cloudy',
    3 => 'Overcast',
    45 => 'Fog',
    48 => 'Depositing rime fog',
    51 => 'Light drizzle',
    53 => 'Moderate drizzle',
    55 => 'Dense drizzle',
    61 => 'Slight rain',
    63 => 'Moderate rain',
    65 => 'Heavy rain',
    71 => 'Slight snow fall',
    73 => 'Moderate snow fall',
    75 => 'Heavy snow fall',
    80 => 'Slight rain showers',
    81 => 'Moderate rain showers',
    82 => 'Violent rain showers',
    95 => 'Thunderstorm',
    96 => 'Thunderstorm with slight hail',
    99 => 'Thunderstorm with heavy hail'
];

// Get weather description for current
$code = $current['weather_code'] ?? 0;
$description = $weather_codes[$code] ?? 'Unknown';
$icon_class = get_icon_class($code);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fancy Weather for Rochdale</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.12/css/weather-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: #333;
        }
        .weather-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 100%;
            max-width: none;
            animation: fadeInUp 1s ease-out;
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .location {
            font-size: 1.2rem;
            color: #636e72;
            margin-bottom: 0.5rem;
        }
        .icon {
            font-size: 5rem;
            color: #74b9ff;
            margin-bottom: 1rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .temperature {
            font-size: 4rem;
            font-weight: 700;
            color: #2d3436;
            margin-bottom: 0.5rem;
        }
        .description {
            font-size: 1.5rem;
            color: #636e72;
            margin-bottom: 1.5rem;
            text-transform: capitalize;
        }
        .details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .detail-item {
            background: linear-gradient(135deg, #fdcb6e 0%, #e17055 100%);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            font-weight: 600;
        }
        .detail-label {
            font-size: 0.8rem;
            opacity: 0.9;
            display: block;
        }
        .detail-value {
            font-size: 1.2rem;
        }
        .feels-like {
            background: linear-gradient(135deg, #a29bfe 0%, #6c5ce7 100%);
        }
        .humidity {
            background: linear-gradient(135deg, #00b894 0%, #00a085 100%);
        }
        .wind {
            background: linear-gradient(135deg, #fd79a8 0%, #e84393 100%);
        }
        .precip {
            background: linear-gradient(135deg, #55a3ff 0%, #007acc 100%);
        }
        .update-time {
            font-size: 0.8rem;
            color: #b2bec3;
            margin-top: 1rem;
        }
        .forecast {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 100%;
            max-width: none;
            margin-top: 2rem;
        }
        .forecast h2 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: #2d3436;
        }
        .forecast-grid {
            display: flex;
            overflow-x: auto;
            gap: 1rem;
            justify-content: flex-start;
        }
        .forecast-day {
            background: linear-gradient(135deg, #dfe6e9 0%, #b2bec3 100%);
            border-radius: 10px;
            padding: 1rem;
            min-width: 120px;
            text-align: center;
        }
        .forecast-day .day {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .forecast-day i {
            font-size: 2rem;
            color: #74b9ff;
            margin-bottom: 0.5rem;
        }
        .forecast-day .temps {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .forecast-day .desc {
            font-size: 0.9rem;
            text-transform: capitalize;
            margin-bottom: 0.5rem;
        }
        .forecast-day .precip {
            font-size: 0.8rem;
            color: #636e72;
        }
        @media (max-width: 480px) {
            .weather-card {
                padding: 1.5rem;
            }
            .temperature {
                font-size: 3rem;
            }
            .icon {
                font-size: 4rem;
            }
            .forecast {
                padding: 1.5rem;
            }
            .forecast h2 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="weather-card">
        <div class="location">Rochdale, UK</div>
        <i class="wi <?php echo $icon_class; ?> icon"></i>
        <div class="temperature"><?php echo round($current['temperature_2m'] ?? 0); ?>°C</div>
        <div class="description"><?php echo $description; ?></div>
        <div class="details">
            <div class="detail-item feels-like">
                <span class="detail-label">Feels like</span>
                <span class="detail-value"><?php echo round($current['apparent_temperature'] ?? 0); ?>°C</span>
            </div>
            <div class="detail-item humidity">
                <span class="detail-label">Humidity</span>
                <span class="detail-value"><?php echo $current['relative_humidity_2m'] ?? 0; ?>%</span>
            </div>
            <div class="detail-item wind">
                <span class="detail-label">Wind</span>
                <span class="detail-value"><?php echo round($current['wind_speed_10m'] ?? 0); ?> km/h</span>
            </div>
            <div class="detail-item precip">
                <span class="detail-label">Precip</span>
                <span class="detail-value"><?php echo $current['precipitation'] ?? 0; ?> mm</span>
            </div>
        </div>
        <div class="update-time">Updated: <?php echo date('H:i'); ?></div>
    </div>

    <div class="forecast">
        <h2>7-Day Forecast</h2>
        <div class="forecast-grid">
            <?php foreach ($daily['time'] as $i => $time): ?>
                <div class="forecast-day">
                    <div class="day"><?php echo date('D, M j', strtotime($time)); ?></div>
                    <i class="wi <?php echo get_icon_class($daily['weather_code'][$i]); ?>"></i>
                    <div class="temps"><?php echo round($daily['temperature_2m_max'][$i]); ?>° / <?php echo round($daily['temperature_2m_min'][$i]); ?>°</div>
                    <div class="desc"><?php echo $weather_codes[$daily['weather_code'][$i]] ?? 'Unknown'; ?></div>
                    <div class="precip">Precip: <?php echo $daily['precipitation_sum'][$i] ?? 0; ?> mm</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // Simple JavaScript for auto-refresh every 10 minutes
        setTimeout(function() {
            location.reload();
        }, 600000); // 10 minutes

        // Add some interactive hover effect
        document.querySelector('.weather-card').addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.transition = 'transform 0.3s ease';
        });
        document.querySelector('.weather-card').addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    </script>
</body>
</html>