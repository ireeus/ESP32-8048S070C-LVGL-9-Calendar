<?php
// config.php — shared bootstrap: session + database + weather helper.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/access.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed.');
}

// Auto-login via "session lock" (stay logged in after closing the app)
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['session_lock'])) {
    try {
        $stmt = $db->prepare("SELECT user_id FROM persistent_sessions WHERE session_id = ? AND (expires_at > ? OR expires_at IS NULL)");
        $stmt->execute([$_COOKIE['session_lock'], date('Y-m-d H:i:s')]);
        $uid = $stmt->fetchColumn();
        if ($uid) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$uid;
        } else {
            $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            setcookie('session_lock', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
    } catch (PDOException $e) {
        error_log('session_lock auto-login error: ' . $e->getMessage());
    }
}

function get_icon_class($code)
{
    $map = [
        0 => '01d', 1 => '01d', 2 => '02d', 3 => '04d',
        45 => '50d', 48 => '50d',
        51 => '09d', 53 => '09d', 55 => '09d', 56 => '09d', 57 => '09d',
        61 => '10d', 63 => '10d', 65 => '10d', 66 => '13d', 67 => '13d',
        71 => '13d', 73 => '13d', 75 => '13d', 77 => '13d',
        80 => '09d', 81 => '09d', 82 => '09d',
        85 => '13d', 86 => '13d',
        95 => '11d', 96 => '11d', 99 => '11d',
    ];
    $icon = $map[(int)$code] ?? '01d';
    return 'https://openweathermap.org/img/wn/' . $icon . '@2x.png';
}

function get_weather_data()
{
    $lat = 53.6177;
    $lon = -2.1552; // Rochdale
    $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
        . '&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m'
        . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum&timezone=auto&forecast_days=7';
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;

    $codes = [
        0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
        45 => 'Fog', 48 => 'Depositing rime fog',
        51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
        56 => 'Freezing drizzle', 57 => 'Freezing drizzle',
        61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
        66 => 'Freezing rain', 67 => 'Freezing rain',
        71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow', 77 => 'Snow grains',
        80 => 'Slight rain showers', 81 => 'Moderate rain showers', 82 => 'Violent rain showers',
        85 => 'Snow showers', 86 => 'Snow showers',
        95 => 'Thunderstorm', 96 => 'Thunderstorm with hail', 99 => 'Thunderstorm with heavy hail',
    ];

    $current = $data['current'] ?? [];
    $daily = $data['daily'] ?? [];
    $code = (int) ($current['weather_code'] ?? 0);

    return [
        'location_name' => 'Rochdale',
        'current'       => $current,
        'daily'         => $daily,
        'weather_codes' => $codes,
        'description'   => $codes[$code] ?? 'Unknown',
        'icon_class'    => get_icon_class($code),
    ];
}
