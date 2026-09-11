<?php
session_start();
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Set default timezone
date_default_timezone_set('UTC');
// Database connection
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
        google_event_id TEXT
    )");
    // Create ics_calendars table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS ics_calendars (
        calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        ics_url TEXT NOT NULL,
        skip_outdated BOOLEAN DEFAULT 0,
        UNIQUE(user_id, ics_url)
    )");
    // Create parcel_box table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS parcel_box (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        username TEXT NOT NULL,
        parcel_box_id TEXT NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
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
    // Create persistent_sessions table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS persistent_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL,
        user_id INTEGER NOT NULL UNIQUE,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME
    )");
    // Check and add missing columns to events
    $columns = $db->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC);
    $hasAllDayColumn = false;
    $hasSourceColumn = false;
    $hasGoogleEventIdColumn = false;
    $hasIcsCalendarIdColumn = false;
    $hasRemindBefore = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'all_day') $hasAllDayColumn = true;
        if ($column['name'] === 'source') $hasSourceColumn = true;
        if ($column['name'] === 'google_event_id') $hasGoogleEventIdColumn = true;
        if ($column['name'] === 'ics_calendar_id') $hasIcsCalendarIdColumn = true;
        if ($column['name'] === 'remind_before') $hasRemindBefore = true;
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
    if (!$hasRemindBefore) {
        $db->exec("ALTER TABLE events ADD COLUMN remind_before TEXT");
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    die("Database Error: Unable to connect to the database.");
}
// Auto-login with session lock
if (!isset($_SESSION['user_id']) && isset($_COOKIE['session_lock'])) {
    $session_id = $_COOKIE['session_lock'];
    try {
        $stmt = $db->prepare("SELECT user_id FROM persistent_sessions WHERE session_id = ? AND (expires_at > ? OR expires_at IS NULL)");
        $stmt->execute([$session_id, date('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['user_id'] = $row['user_id'];
        } else {
            $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            setcookie('session_lock', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
    } catch (PDOException $e) {
        error_log("Auto-login error: " . $e->getMessage());
    }
}
// Redirect to login.php if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
// Helper functions for parsing .ics
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
// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['user_id'])) {
        // Clear persistent session
        $stmt = $db->prepare("DELETE FROM persistent_sessions WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        setcookie('session_lock', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    }
    session_destroy();
    header("Location: index.php");
    exit;
}
// Handle POST requests with CSRF validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Handle add ICS calendar
$calendar_message = '';
$calendar_error_flag = false;
if (isset($_POST['add_ics_calendar']) && isset($_SESSION['user_id'])) {
    $ics_url = trim($_POST['ics_url']);
    $skip_outdated = isset($_POST['skip_outdated']) ? 1 : 0;
    if (filter_var($ics_url, FILTER_VALIDATE_URL)) {
        try {
            $stmt = $db->prepare("INSERT INTO ics_calendars (user_id, ics_url, skip_outdated) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $ics_url, $skip_outdated]);
            $calendar_message = "ICS calendar added successfully.";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                $calendar_message = "ICS URL already added.";
            } else {
                $calendar_message = "Failed to add ICS calendar: " . $e->getMessage();
            }
            $calendar_error_flag = true;
        }
    } else {
        $calendar_message = "Invalid ICS URL.";
        $calendar_error_flag = true;
    }
}
// Handle sync calendar
if (isset($_POST['sync_calendar']) && isset($_POST['calendar_id']) && isset($_SESSION['user_id'])) {
    $cal_id = $_POST['calendar_id'];
    $stmt = $db->prepare("SELECT * FROM ics_calendars WHERE calendar_id = ? AND user_id = ?");
    $stmt->execute([$cal_id, $_SESSION['user_id']]);
    $calendar = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($calendar) {
        try {
            $ics_content = @file_get_contents($calendar['ics_url']);
            if ($ics_content === false) {
                $calendar_message = "Failed to fetch ICS data.";
                $calendar_error_flag = true;
            } else {
                $parsed_events = parse_ics($ics_content);
                $stmt = $db->prepare("DELETE FROM events WHERE user_id = ? AND ics_calendar_id = ?");
                $stmt->execute([$_SESSION['user_id'], $cal_id]);
                $insert_stmt = $db->prepare("INSERT INTO events (user_id, summary, start_time, end_time, description, all_day, source, google_event_id, ics_calendar_id) VALUES (?, ?, ?, ?, ?, ?, 'ics', ?, ?)");
                foreach ($parsed_events as $event) {
                    if ($calendar['skip_outdated'] && strtotime($event['end_time']) < time()) {
                        continue;
                    }
                    $insert_stmt->execute([
                        $_SESSION['user_id'],
                        $event['summary'],
                        $event['start_time'],
                        $event['end_time'],
                        $event['description'],
                        $event['all_day'],
                        $event['google_event_id'],
                        $cal_id
                    ]);
                }
                $calendar_message = "Calendar synced successfully.";
            }
        } catch (Exception $e) {
            $calendar_message = "Error syncing calendar: " . $e->getMessage();
            $calendar_error_flag = true;
        }
    } else {
        $calendar_message = "Invalid calendar ID.";
        $calendar_error_flag = true;
    }
}
// Handle remove calendar
if (isset($_POST['remove_calendar']) && isset($_POST['calendar_id']) && isset($_SESSION['user_id'])) {
    $cal_id = $_POST['calendar_id'];
    try {
        $stmt = $db->prepare("DELETE FROM ics_calendars WHERE calendar_id = ? AND user_id = ?");
        $stmt->execute([$cal_id, $_SESSION['user_id']]);
        $stmt = $db->prepare("DELETE FROM events WHERE user_id = ? AND ics_calendar_id = ?");
        $stmt->execute([$_SESSION['user_id'], $cal_id]);
        $calendar_message = "Calendar removed successfully.";
    } catch (PDOException $e) {
        $calendar_message = "Failed to remove calendar: " . $e->getMessage();
        $calendar_error_flag = true;
    }
}
// Handle parcel box settings
$parcel_message = '';
$parcel_error_flag = false;
if (isset($_POST['save_parcel_box']) && isset($_SESSION['user_id'])) {
    $username = trim($_POST['username']);
    $parcel_box_id = trim($_POST['parcel_box_id']);
    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO parcel_box (user_id, username, parcel_box_id) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $username, $parcel_box_id]);
        $parcel_message = "Parcel box settings saved successfully.";
    } catch (PDOException $e) {
        $parcel_message = "Failed to save parcel box settings: " . $e->getMessage();
        $parcel_error_flag = true;
    }
}
// Handle weather location settings
$weather_message = '';
$weather_error_flag = false;
if (isset($_POST['save_weather_location']) && isset($_SESSION['user_id'])) {
    $location_type = trim($_POST['location_type']);
    $city_name = $location_type === 'city' ? trim($_POST['city_name']) : '';
    $latitude = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    if ($location_type === 'city' && (empty($city_name) || $latitude === null || $longitude === null)) {
        $weather_message = "City name and valid coordinates are required for city-based location.";
        $weather_error_flag = true;
    } elseif ($location_type === 'geolocation' && ($latitude === null || $longitude === null)) {
        $weather_message = "Valid coordinates are required for device location.";
        $weather_error_flag = true;
    } else {
        try {
            $stmt = $db->prepare("INSERT OR REPLACE INTO weather_preferences (user_id, location_type, city_name, latitude, longitude) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $location_type, $city_name, $latitude, $longitude]);
            $weather_message = "Weather location settings saved successfully.";
        } catch (PDOException $e) {
            $weather_message = "Failed to save weather location settings: " . $e->getMessage();
            $weather_error_flag = true;
            error_log("Weather preferences save error for user_id {$_SESSION['user_id']}: " . $e->getMessage());
        }
    }
}
// Handle session lock
$session_message = '';
$session_error_flag = false;
if (isset($_POST['enable_session_lock']) && isset($_SESSION['user_id'])) {
    try {
        // Get username
        $stmt = $db->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $username = $stmt->fetchColumn();
        if (!$username) {
            throw new Exception("User not found");
        }
        // Delete any existing session lock for this user
        $stmt = $db->prepare("DELETE FROM persistent_sessions WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        // Create new session lock
        $session_id = hash('sha256', random_bytes(32)); // Improved randomness
        $expires_at = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
        $stmt = $db->prepare("INSERT INTO persistent_sessions (session_id, user_id, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$session_id, $_SESSION['user_id'], $expires_at]);
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        setcookie('session_lock', $session_id, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        $session_message = "Session lock enabled successfully.";
    } catch (Exception $e) {
        $session_message = "Failed to enable session lock: " . $e->getMessage();
        $session_error_flag = true;
        error_log("Enable session lock error: " . $e->getMessage());
    }
}
// Handle disable session lock
if (isset($_POST['disable_session_lock']) && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("DELETE FROM persistent_sessions WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        setcookie('session_lock', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        $session_message = "Session lock disabled successfully.";
    } catch (PDOException $e) {
        $session_message = "Failed to disable session lock: " . $e->getMessage();
        $session_error_flag = true;
        error_log("Disable session lock error: " . $e->getMessage());
    }
}
// Fetch saved calendars
$calendars = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM ics_calendars WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Fetch parcel box settings
$parcel = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM parcel_box WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $parcel = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
// Fetch weather preferences
$weather_prefs = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM weather_preferences WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $weather_prefs = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
// Check if session lock is enabled
$session_lock_enabled = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM persistent_sessions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $session_lock_enabled = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
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
    <meta name="apple-mobile-web-app-title" content="Custom Calendar">
    <link rel="apple-touch-icon" href="icon-192x192.png">
    <link rel="manifest" href="manifest.json">
    <title>Settings - Custom Calendar Service</title>
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
        .form-section {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            min-height: calc(100vh - 60px - 80px);
            overflow: hidden;
        }
        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        .form-content {
            z-index: 2;
            max-width: 600px;
            width: 100%;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 1rem;
            animation: fadeInUp 1s ease-out;
        }
        .hero-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        .hero-desc {
            font-size: 1.5rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }
        .cta-btn, .login-btn, .sync-btn {
            background: #3B82F6;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            border: none;
            cursor: pointer;
            display: inline-block;
            text-align: center;
        }
        .cta-btn:hover, .login-btn:hover, .sync-btn:hover {
            background: #2563EB;
            transform: scale(1.05);
        }
        .remove-btn {
            background: #ff6b6b;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            border: none;
            cursor: pointer;
        }
        .remove-btn:hover {
            background: #e55a5a;
            transform: scale(1.05);
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
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
        .form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-width: 400px;
            margin: 0 auto;
        }
        .form-input, .form-select {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.8);
            color: #333;
            border: none;
            font-size: 1rem;
        }
        .error-msg {
            color: #ff6b6b;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }
        .calendar-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .calendar-table th, .calendar-table td {
            padding: 0.75rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .calendar-table th {
            background: rgba(255, 255, 255, 0.2);
            font-weight: 600;
        }
        .calendar-table td {
            background: rgba(255, 255, 255, 0.1);
        }
        .calendar-table .actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }
        .link-text {
            margin-top: 2rem;
            font-size: 1.2rem;
        }
        .link-text a {
            color: #34D399;
            text-decoration: none;
            transition: color 0.3s;
        }
        .link-text a:hover {
            color: #2FB988;
            text-decoration: underline;
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
        #cityInputSection {
            display: none;
        }
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
            .form {
                max-width: 100%;
            }
            .form-input, .form-select, .cta-btn, .login-btn, .sync-btn, .remove-btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
            }
            .error-msg, .link-text {
                font-size: 1rem;
            }
            .calendar-table th, .calendar-table td {
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
            .form-input, .form-select, .cta-btn, .login-btn, .sync-btn, .remove-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
            .calendar-table th, .calendar-table td {
                padding: 0.3rem;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'menu.php'; ?>
    <section class="form-section">
        <div class="form-content">
            <h1 class="hero-title">Settings</h1>
            <?php if ($calendar_message): ?>
                <p class="<?php echo $calendar_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4"><?php echo htmlspecialchars($calendar_message); ?></p>
            <?php endif; ?>
            <h2 class="text-2xl font-bold mb-4">Add ICS Calendar</h2>
            <form method="POST" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="url" name="ics_url" placeholder="ICS URL (e.g., Google Calendar, Outlook, iCloud)" required class="form-input">
                <div class="flex items-center justify-center">
                    <input type="checkbox" name="skip_outdated" id="skipOutdated" class="mr-2 h-5 w-5">
                    <label for="skipOutdated" class="text-base">Skip outdated events</label>
                </div>
                <button type="submit" name="add_ics_calendar" class="cta-btn">Add Calendar</button>
            </form>
            <h2 class="text-2xl font-bold mt-6 mb-4">Saved ICS Calendars</h2>
            <?php if (empty($calendars)): ?>
                <p class="text-center">No saved ICS calendars.</p>
            <?php else: ?>
                <table class="calendar-table">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Skip Outdated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calendars as $cal): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(parse_url($cal['ics_url'], PHP_URL_HOST) ?: 'Unknown'); ?></td>
                                <td><?php echo $cal['skip_outdated'] ? 'Yes' : 'No'; ?></td>
                                <td class="actions">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="calendar_id" value="<?php echo htmlspecialchars($cal['calendar_id']); ?>">
                                        <button type="submit" name="sync_calendar" class="sync-btn">Sync Now</button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to remove this calendar?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="calendar_id" value="<?php echo htmlspecialchars($cal['calendar_id']); ?>">
                                        <button type="submit" name="remove_calendar" class="remove-btn">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if ($parcel_message): ?>
                <p class="<?php echo $parcel_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4 mt-6"><?php echo htmlspecialchars($parcel_message); ?></p>
            <?php endif; ?>
            <h2 class="text-2xl font-bold mt-6 mb-4">Parcel Box Settings</h2>
            <form method="POST" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="text" name="username" placeholder="Username" required class="form-input" value="<?php echo htmlspecialchars($parcel['username'] ?? ''); ?>">
                <input type="text" name="parcel_box_id" placeholder="Parcel Box ID" required class="form-input" value="<?php echo htmlspecialchars($parcel['parcel_box_id'] ?? ''); ?>">
                <button type="submit" name="save_parcel_box" class="cta-btn">Save</button>
            </form>
            <?php if ($weather_message): ?>
                <p class="<?php echo $weather_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4 mt-6"><?php echo htmlspecialchars($weather_message); ?></p>
            <?php endif; ?>
            <h2 class="text-2xl font-bold mt-6 mb-4">Weather Location Settings</h2>
            <form method="POST" class="form" id="weatherForm" onsubmit="return validateWeatherForm()">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <select name="location_type" id="locationType" class="form-select" onchange="toggleCityInput()">
                    <option value="geolocation" <?php echo ($weather_prefs['location_type'] ?? '') === 'geolocation' ? 'selected' : ''; ?>>Use Device Location</option>
                    <option value="city" <?php echo ($weather_prefs['location_type'] ?? '') === 'city' ? 'selected' : ''; ?>>Enter City</option>
                </select>
                <div id="cityInputSection">
                    <input type="text" name="city_name" id="cityName" placeholder="City Name (e.g., London)" class="form-input" value="<?php echo htmlspecialchars($weather_prefs['city_name'] ?? ''); ?>">
                    <input type="hidden" name="latitude" id="latitude" value="<?php echo htmlspecialchars($weather_prefs['latitude'] ?? ''); ?>">
                    <input type="hidden" name="longitude" id="longitude" value="<?php echo htmlspecialchars($weather_prefs['longitude'] ?? ''); ?>">
                </div>
                <button type="submit" name="save_weather_location" class="cta-btn">Save Weather Location</button>
            </form>
            <?php if ($session_message): ?>
                <p class="<?php echo $session_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4 mt-6"><?php echo htmlspecialchars($session_message); ?></p>
            <?php endif; ?>
            <h2 class="text-2xl font-bold mt-6 mb-4">Session Lock Settings</h2>
            <form method="POST" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <?php if ($session_lock_enabled): ?>
                    <p class="text-base mb-4">Session lock is currently enabled.</p>
                    <button type="submit" name="disable_session_lock" class="remove-btn">Disable Session Lock</button>
                <?php else: ?>
                    <p class="text-base mb-4">Enable session lock to stay logged in after closing the app.</p>
                    <button type="submit" name="enable_session_lock" class="cta-btn">Enable Session Lock</button>
                <?php endif; ?>
            </form>
            <p class="link-text mt-6"><a href="calendar.php" class="link-text">Back to Calendar</a></p>
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
        function toggleCityInput() {
            const locationType = document.getElementById('locationType').value;
            const cityInputSection = document.getElementById('cityInputSection');
            cityInputSection.style.display = locationType === 'city' ? 'block' : 'none';
            if (locationType === 'geolocation') {
                fetchGeolocation();
            } else {
                // Clear lat/lon for city selection until geocoding
                document.getElementById('latitude').value = '';
                document.getElementById('longitude').value = '';
            }
        }
        function fetchGeolocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    position => {
                        document.getElementById('latitude').value = position.coords.latitude;
                        document.getElementById('longitude').value = position.coords.longitude;
                        console.log('Geolocation fetched: lat=' + position.coords.latitude + ', lon=' + position.coords.longitude);
                    },
                    error => {
                        console.error('Geolocation error:', error);
                        alert('Unable to fetch device location. Please enable location access or select a city manually. Using default London location.');
                        // Fallback to London
                        document.getElementById('latitude').value = 51.5074;
                        document.getElementById('longitude').value = -0.1278;
                    }
                );
            } else {
                alert('Geolocation is not supported by this browser. Please select a city manually. Using default London location.');
                // Fallback to London
                document.getElementById('latitude').value = 51.5074;
                document.getElementById('longitude').value = -0.1278;
            }
        }
        function validateWeatherForm() {
            const locationType = document.getElementById('locationType').value;
            const latitude = document.getElementById('latitude').value;
            const longitude = document.getElementById('longitude').value;
            const cityName = document.getElementById('cityName').value.trim();
            if (locationType === 'city' && (!cityName || !latitude || !longitude)) {
                alert('Please enter a valid city name and ensure coordinates are fetched.');
                return false;
            }
            if ((locationType === 'geolocation' || locationType === 'city') && 
                (!latitude || !longitude || isNaN(latitude) || isNaN(longitude))) {
                alert('Valid coordinates are required. Please try fetching location again.');
                return false;
            }
            return true;
        }
        // Initialize city input visibility on page load
        toggleCityInput();
        // Geocoding API call for city name to coordinates
        document.getElementById('cityName').addEventListener('blur', async function() {
            const cityName = this.value.trim();
            if (cityName) {
                try {
                    const response = await fetch(`https://geocoding-api.open-meteo.com/v1/search?name=${encodeURIComponent(cityName)}&count=1&language=en&format=json`);
                    if (!response.ok) {
                        throw new Error(`HTTP error! Status: ${response.status}`);
                    }
                    const data = await response.json();
                    if (data.results && data.results.length > 0) {
                        const { latitude, longitude } = data.results[0];
                        document.getElementById('latitude').value = latitude;
                        document.getElementById('longitude').value = longitude;
                        console.log('Geocoded city: ' + cityName + ', lat=' + latitude + ', lon=' + longitude);
                    } else {
                        alert('City not found. Please enter a valid city name. Using default London location.');
                        document.getElementById('latitude').value = 51.5074;
                        document.getElementById('longitude').value = -0.1278;
                    }
                } catch (error) {
                    console.error('Geocoding error:', error);
                    alert('Error fetching city coordinates. Please try again or use device location. Using default London location.');
                    // Fallback to London
                    document.getElementById('latitude').value = 51.5074;
                    document.getElementById('longitude').value = -0.1278;
                }
            } else {
                // Clear coordinates if city name is empty
                document.getElementById('latitude').value = '';
                document.getElementById('longitude').value = '';
            }
        });
    </script>
</body>
</html>