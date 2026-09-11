<?php
// config.php - Added ob_start() after session_start to buffer any potential output
session_start();
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('UTC');

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $db = new PDO('sqlite:access.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  
    // Create users table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        access_code TEXT NOT NULL
    )");
  
    // Create events table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS events (
        event_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        summary TEXT NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        description TEXT,
        all_day BOOLEAN DEFAULT 0,
        timestmp DATETIME DEFAULT CURRENT_TIMESTAMP,
        source TEXT DEFAULT 'local',
        google_event_id TEXT,
        ics_calendar_id INTEGER
    )");
    // Create ics_calendars table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS ics_calendars (
        calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        ics_url TEXT NOT NULL,
        skip_outdated BOOLEAN DEFAULT 0,
        UNIQUE(user_id, ics_url)
    )");
    // Create weather_preferences table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS weather_preferences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        location_type TEXT NOT NULL, -- 'geolocation' or 'city'
        city_name TEXT,
        latitude REAL,
        longitude REAL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Check and add missing columns to events
    $columns = $db->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC);
    $hasAllDayColumn = false;
    $hasSourceColumn = false;
    $hasGoogleEventIdColumn = false;
    $hasIcsCalendarIdColumn = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'all_day') $hasAllDayColumn = true;
        if ($column['name'] === 'source') $hasSourceColumn = true;
        if ($column['name'] === 'google_event_id') $hasGoogleEventIdColumn = true;
        if ($column['name'] === 'ics_calendar_id') $hasIcsCalendarIdColumn = true;
    }
    if (!$hasAllDayColumn) {
        $db->exec("ALTER TABLE events ADD COLUMN all_day BOOLEAN DEFAULT 0");
    }
    if (!$hasSourceColumn) {
        $db->exec("ALTER TABLE events ADD COLUMN source TEXT DEFAULT 'local'");
    }
    if (!$hasGoogleEventIdColumn) {
        $db->exec("ALTER TABLE events ADD COLUMN google_event_id TEXT");
    }
    if (!$hasIcsCalendarIdColumn) {
        $db->exec("ALTER TABLE events ADD COLUMN ics_calendar_id INTEGER");
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    die("Database Error: Unable to connect to the database.");
}

// ICS Helper functions
function normalize_ics_lines(string $ics): array {
    $ics = preg_replace("/\r\n[ \t]/", '', $ics);
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $ics = str_replace('\,', ',', $ics);
    $ics = str_replace('\n', ' ', $ics);
    return array_filter(array_map('trim', explode("\n", $ics)));
}
function parse_ics_events(array $lines): array {
    $events = [];
    $inEvent = false;
    $current = [];
    foreach ($lines as $line) {
        if (strpos($line, 'BEGIN:VEVENT') === 0) {
            $inEvent = true;
            $current = [];
            continue;
        }
        if (strpos($line, 'END:VEVENT') === 0) {
            if (!empty($current['DTSTART'])) {
                $events[] = $current;
            }
            $inEvent = false;
            continue;
        }
        if ($inEvent && strpos($line, ':') !== false) {
            [$keyPart, $value] = explode(':', $line, 2);
            $keyParts = explode(';', $keyPart);
            $key = $keyParts[0];
            $params = [];
            foreach (array_slice($keyParts, 1) as $param) {
                if (strpos($param, '=') !== false) {
                    [$pKey, $pValue] = explode('=', $param, 2);
                    $params[$pKey] = $pValue;
                }
            }
            $current[$key] = $value;
            $current[$key . '_params'] = $params;
        }
    }
    return $events;
}
function map_timezone(string $tzid): string {
    $tzMap = [
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'GMT Standard Time' => 'Europe/London',
        'UTC' => 'UTC',
    ];
    return $tzMap[$tzid] ?? $tzid;
}
function parse_ical_date(string $dateStr, array $params = []): ?DateTime {
    $tz = isset($params['TZID']) ? map_timezone($params['TZID']) : 'UTC';
    try {
        $dateTimeZone = new DateTimeZone($tz);
    } catch (Exception $e) {
        $dateTimeZone = new DateTimeZone('UTC');
    }
    if (isset($params['VALUE']) && $params['VALUE'] === 'DATE') {
        return DateTime::createFromFormat('Ymd', $dateStr, $dateTimeZone);
    } else {
        $format = 'Ymd\THis';
        if (substr($dateStr, -1) === 'Z') {
            $dateStr = substr($dateStr, 0, -1);
            $dateTimeZone = new DateTimeZone('UTC');
        }
        $dateTime = DateTime::createFromFormat($format, $dateStr, $dateTimeZone);
        if ($dateTime === false && strlen($dateStr) === 8) {
            return DateTime::createFromFormat('Ymd', $dateStr, $dateTimeZone);
        }
        return $dateTime;
    }
}
function parse_ics(string $icsContent): array {
    $lines = normalize_ics_lines($icsContent);
    $rawEvents = parse_ics_events($lines);
    $parsedEvents = [];
    foreach ($rawEvents as $event) {
        $start = parse_ical_date($event['DTSTART'], $event['DTSTART_params'] ?? []);
        $end = isset($event['DTEND']) ? parse_ical_date($event['DTEND'], $event['DTEND_params'] ?? []) : null;
        if (!$start) continue;
        if (!$end) {
            $end = clone $start;
            $end->modify('+1 day');
        }
        $serverTz = new DateTimeZone(date_default_timezone_get());
        $start->setTimezone($serverTz);
        $end->setTimezone($serverTz);
        $allDay = (isset($event['DTSTART_params']['VALUE']) && $event['DTSTART_params']['VALUE'] === 'DATE') || (strlen($event['DTSTART']) === 8) ? 1 : 0;
        if ($allDay) {
            $end->setTime(23, 59, 59);
        }
        $summary = $event['SUMMARY'] ?? $event['SUBJECT'] ?? 'No title';
        $parsedEvents[] = [
            'summary' => $summary,
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time' => $end->format('Y-m-d H:i:s'),
            'description' => $event['DESCRIPTION'] ?? '',
            'all_day' => $allDay,
            'google_event_id' => $event['UID'] ?? uniqid()
        ];
    }
    return $parsedEvents;
}

// Weather helper functions
function get_icon_class($code) {
	$icons='icons_e';

    return match($code) {
        0 => 'img/'.$icons.'/Clear_sky.png',
        1 => 'img/'.$icons.'/Mainly_clear.png',
        2 => 'img/'.$icons.'/Partly_cloudy.png',
        3 => 'img/'.$icons.'/Overcast.png',
        45 => 'img/'.$icons.'/Fog.png',
        48 => 'img/'.$icons.'/Depositing_rime_fog.png',
        51 => 'img/'.$icons.'/Light_drizzle.png',
        53 => 'img/'.$icons.'/Moderate_drizzle.png',
        55 => 'img/'.$icons.'/Dense_drizzle.png',
        61 => 'img/'.$icons.'/Slight_rain.png',
        63 => 'img/'.$icons.'/Moderate_rain.png',
        65 => 'img/'.$icons.'/Heavy_rain.png',
        71 => 'img/'.$icons.'/Slight_snow_fall.png',
        73 => 'img/'.$icons.'/Moderate_snow_fall.png',
        75 => 'img/'.$icons.'/Heavy_snow_fall.png',
        80 => 'img/'.$icons.'/Slight_rain_showers.png',
        81 => 'img/'.$icons.'/Moderate_rain_showers.png',
        82 => 'img/'.$icons.'/Violent_rain_showers.png',
        95 => 'img/'.$icons.'/Thunderstorm.png',
        96 => 'img/'.$icons.'/Thunderstorm_with_slight_hail.png',
        99 => 'img/'.$icons.'/Thunderstorm_with_heavy_hail.png',
        default => 'img/'.$icons.'/Unknown.png'
    };
}
function get_weather_data() {
    global $db;
    // Default coordinates (London)
    $lat = 51.5074;
    $lon = -0.1278;
    $location_name = 'London, UK';
    
    // Fetch user weather preferences
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $db->prepare("SELECT location_type, city_name, latitude, longitude FROM weather_preferences WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prefs) {
                // Validate latitude and longitude
                if (is_numeric($prefs['latitude']) && is_numeric($prefs['longitude']) && 
                    $prefs['latitude'] >= -90 && $prefs['latitude'] <= 90 && 
                    $prefs['longitude'] >= -180 && $prefs['longitude'] <= 180) {
                    $lat = floatval($prefs['latitude']);
                    $lon = floatval($prefs['longitude']);
                    $location_name = ($prefs['location_type'] === 'city' && !empty($prefs['city_name'])) 
                        ? $prefs['city_name'] : 'Current Location';
                } else {
                    error_log("Invalid coordinates for user_id {$_SESSION['user_id']}: lat={$prefs['latitude']}, lon={$prefs['longitude']}");
                }
            } else {
                error_log("No weather preferences found for user_id {$_SESSION['user_id']}");
            }
        } catch (PDOException $e) {
            error_log("Database error in get_weather_data: " . $e->getMessage());
        }
    } else {
        error_log("No user_id in session for get_weather_data");
    }
    
    $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_direction_10m&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum&timezone=auto&forecast_days=7";
    $weather_data = @json_decode(file_get_contents($url), true);
    if ($weather_data === null) {
        error_log("Failed to fetch weather data from API for lat={$lat}, lon={$lon}");
    }
    $current = $weather_data['current'] ?? [];
    $daily = $weather_data['daily'] ?? [];
    $weather_codes = [
        0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
        45 => 'Fog', 48 => 'Depositing rime fog', 51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
        61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
        71 => 'Slight snow fall', 73 => 'Moderate snow fall', 75 => 'Heavy snow fall',
        80 => 'Slight rain showers', 81 => 'Moderate rain showers', 82 => 'Violent rain showers',
        95 => 'Thunderstorm', 96 => 'Thunderstorm with slight hail', 99 => 'Thunderstorm with heavy hail'
    ];
    $code = $current['weather_code'] ?? 0;
    $description = $weather_codes[$code] ?? 'Unknown';
    $icon_class = get_icon_class($code);
    return [
        'current' => $current,
        'daily' => $daily,
        'description' => $description,
        'icon_class' => $icon_class,
        'weather_codes' => $weather_codes,
        'location_name' => $location_name
    ];
}
?>