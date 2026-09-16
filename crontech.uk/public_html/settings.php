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
    $db->exec("CREATE TABLE IF NOT EXISTS user_theme (
    user_id INTEGER PRIMARY KEY,
    scheme TEXT NOT NULL DEFAULT 'Blue',
    darkness INTEGER NOT NULL DEFAULT 0,
    updated DATETIME DEFAULT CURRENT_TIMESTAMP
)");
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
    // user_theme.brightness_auto: 1 when the device should follow daylight instead
    // of a fixed level. Arrived after the table already existed on the live site, so
    // it is added in place with the same PRAGMA-then-ALTER pattern as the events
    // columns above. DEFAULT 0 = off, so nobody's device changes behaviour by
    // itself. SQLite allows NOT NULL here only because a default is supplied.
    $themeColumns = $db->query("PRAGMA table_info(user_theme)")->fetchAll(PDO::FETCH_ASSOC);
    $hasBrightnessAutoColumn = false;
    $hasPanelOpaColumn = false;
    foreach ($themeColumns as $column) {
        if ($column['name'] === 'brightness_auto') $hasBrightnessAutoColumn = true;
        if ($column['name'] === 'panel_opa')       $hasPanelOpaColumn = true;
    }
    if (!$hasBrightnessAutoColumn) {
        $db->exec("ALTER TABLE user_theme ADD COLUMN brightness_auto INTEGER NOT NULL DEFAULT 0");
    }
    // user_theme.panel_opa: how see-through the device's panels are, as a
    // percentage of full opacity. 100 is the solid look the device has always
    // had, so the default leaves every existing account exactly as it was.
    if (!$hasPanelOpaColumn) {
        $db->exec("ALTER TABLE user_theme ADD COLUMN panel_opa INTEGER NOT NULL DEFAULT 100");
    }
    // Today's sunrise/sunset, used to show which step Auto is currently on. The
    // device computes its level from those two, so the website has to ask the same
    // source for the same numbers or the two would disagree about which step is in
    // force. One row per location per day, so opening this page costs at most one
    // upstream request; a failed lookup is cached too (with the times left at -1)
    // so an Open-Meteo outage does not stall every page view.
    $db->exec("CREATE TABLE IF NOT EXISTS sun_cache (
        cache_key TEXT PRIMARY KEY,
        sunrise_min INTEGER NOT NULL DEFAULT -1,
        sunset_min INTEGER NOT NULL DEFAULT -1,
        utc_offset INTEGER NOT NULL DEFAULT 0,
        updated DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    die("Database Error: Unable to connect to the database.");
}
// Shared with the background page: the weather-code grouping, this account's
// replacement pictures, and the right-now weather lookup the preview below needs.
require_once __DIR__ . '/bg_common.php';
// Auto-login with session lock
if (!isset($_SESSION['user_id']) && isset($_COOKIE['session_lock'])) {
    $session_id = $_COOKIE['session_lock'];
    try {
        $stmt = $db->prepare("SELECT user_id FROM persistent_sessions WHERE session_id = ? AND (expires_at > ? OR expires_at IS NULL)");
        $stmt->execute([$session_id, date('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $row['user_id'];
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
            'samesite' => 'Lax'
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
            'samesite' => 'Lax'
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
// ---------------------------------------------------------------------------
// Per-user calendar theme. Every account controls its own; changing it never
// affects anybody else's calendar.
// ---------------------------------------------------------------------------
$THEME_SCHEMES = [
    'Blue'      => ['#2196F3', '#1976D2'],
    'Green'     => ['#4CAF50', '#388E3C'],
    'Blue Grey' => ['#607D8B', '#455A64'],
    'Orange'    => ['#FF9800', '#F57C00'],
    'Red'       => ['#F44336', '#D32F2F'],
    'Purple'    => ['#9C27B0', '#7B1FA2'],
    'Teal'      => ['#009688', '#00796B'],
    'Indigo'    => ['#3F51B5', '#303F9F'],
];

$current_user = '';
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT username FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = (string)$stmt->fetchColumn();
}
$is_admin = (strtolower($current_user) === 'ireeus@gmail.com');

$theme_message = '';
$theme_error_flag = false;
if (isset($_POST['save_theme']) && isset($_SESSION['user_id'])) {
    $scheme = isset($_POST['scheme']) ? (string)$_POST['scheme'] : '';
    // One radio group, "brightness", whose values are "auto" or a percentage, so
    // Auto and the presets are mutually exclusive in the browser without any JS.
    // A page cached by the service worker may still post the older "darkness"
    // field on its own, so that is accepted too - and if neither arrives, both
    // stored values are left exactly as they are.
    $brightness_raw = isset($_POST['brightness']) ? (string)$_POST['brightness'] : null;
    $stored = null;
    $stmtPrev = $db->prepare("SELECT darkness, brightness_auto, panel_opa FROM user_theme WHERE user_id = ?");
    $stmtPrev->execute([$_SESSION['user_id']]);
    $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
    if ($prev) { $stored = $prev; }

    $brightness_auto = $stored ? (int)$stored['brightness_auto'] : 0;
    $darkness = $stored ? (int)$stored['darkness'] : 0;
    // Panel opacity: five steps posted by its own autosave radio group. Absent -
    // a page cached by the service worker, or a post that does not carry the
    // field - leaves the stored value alone rather than resetting it to solid.
    $panel_opa = $stored ? (int)$stored['panel_opa'] : 100;
    $panel_error = '';
    if (isset($_POST['panel_opa'])) {
        $po = (int)$_POST['panel_opa'];
        if ($po < 0 || $po > 100) {
            $panel_error = 'Panel opacity must be between 0 and 100.';
        } else {
            $panel_opa = $po;
        }
    }
    $brightness_error = '';

    if ($brightness_raw !== null) {
        if ($brightness_raw === 'auto') {
            $brightness_auto = 1;   // darkness keeps whatever was last chosen by hand
        } elseif (preg_match('/^\d+$/', $brightness_raw) && (int)$brightness_raw <= 100) {
            $brightness_auto = 0;
            $darkness = 100 - (int)$brightness_raw;   // shown as brightness, stored as darkness
        } else {
            $brightness_error = 'Unknown brightness option.';
        }
    } elseif (isset($_POST['darkness'])) {
        $d = (int)$_POST['darkness'];
        if ($d < 0 || $d > 100) {
            $brightness_error = 'Brightness must be between 0 and 100.';
        } else {
            $brightness_auto = 0;
            $darkness = $d;
        }
    }

    if (!array_key_exists($scheme, $THEME_SCHEMES)) {
        $theme_message = 'Unknown colour scheme.'; $theme_error_flag = true;
    } elseif ($brightness_error !== '') {
        $theme_message = $brightness_error; $theme_error_flag = true;
    } elseif ($panel_error !== '') {
        $theme_message = $panel_error; $theme_error_flag = true;
    } else {
        $stmt = $db->prepare("INSERT OR REPLACE INTO user_theme (user_id, scheme, darkness, brightness_auto, panel_opa, updated) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$_SESSION['user_id'], $scheme, $darkness, $brightness_auto, $panel_opa]);
        $theme_message = 'Theme saved - it applies to your Cron-Tab device only.';
    }
}

$user_theme = ['scheme' => 'Blue', 'darkness' => 0, 'brightness_auto' => 0, 'panel_opa' => 100];
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT scheme, darkness, brightness_auto, panel_opa FROM user_theme WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $user_theme = $row; }
}
if (!array_key_exists((string)$user_theme['scheme'], $THEME_SCHEMES)) { $user_theme['scheme'] = 'Blue'; }
$user_theme['darkness'] = max(0, min(100, (int)$user_theme['darkness']));
$user_theme['brightness_auto'] = ((int)$user_theme['brightness_auto']) ? 1 : 0;
$user_theme['panel_opa'] = max(0, min(100, (int)($user_theme['panel_opa'] ?? 100)));

// ---- Which step Auto is on -------------------------------------------------
// The device tells the site THAT Auto is on and what the MANUAL level underneath
// it is, not the level the sun has picked - pushThemeToServer() in main.cpp sends
// the stored value deliberately, because the live one moves all day. So the level
// is worked out here, exactly as the firmware does it: solar noon is the midpoint
// of today's sunrise and sunset, and brightness is a cosine from solar midnight to
// solar noon, rounded to the same 5% steps. The ten lines are duplicated rather
// than pushed by the device so a wall calendar needs no network for it.
function cron_sun_times(PDO $db, float $lat, float $lon): ?array
{
    // Returns [sunrise, sunset, utc_offset]: the first two in minutes after LOCAL
    // midnight, the third in seconds. Null when the lookup has nothing usable.
    $key = gmdate('Y-m-d') . '|' . round($lat, 4) . '|' . round($lon, 4);
    $stmt = $db->prepare("SELECT sunrise_min, sunset_min, utc_offset, updated FROM sun_cache WHERE cache_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $ok = (int)$row['sunrise_min'] >= 0 && (int)$row['sunset_min'] > (int)$row['sunrise_min'];
        $age = time() - (int)strtotime((string)$row['updated'] . ' UTC');
        // A cached FAILURE is retried every ten minutes rather than on every page
        // view, so an outage costs one 5s stall per ten minutes at worst instead
        // of one per refresh.
        if ($ok || $age < 600) {
            return $ok ? [(int)$row['sunrise_min'], (int)$row['sunset_min'], (int)$row['utc_offset']] : null;
        }
    }

    $sunrise = -1;
    $sunset  = -1;
    $offset  = 0;
    $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
         . '&daily=sunrise,sunset&timezone=auto&forecast_days=1';
    $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    $data = $raw ? json_decode($raw, true) : null;
    $sr = $data['daily']['sunrise'][0] ?? null;
    $ss = $data['daily']['sunset'][0] ?? null;
    // Open-Meteo returns "2026-09-14T06:42" in the location's own timezone, which
    // is why the offset comes back with it: the comparison below happens in UTC.
    if (is_string($sr) && is_string($ss)
        && preg_match('/T(\d{2}):(\d{2})/', $sr, $m1)
        && preg_match('/T(\d{2}):(\d{2})/', $ss, $m2)) {
        $sunrise = ((int)$m1[1]) * 60 + (int)$m1[2];
        $sunset  = ((int)$m2[1]) * 60 + (int)$m2[2];
        $offset  = (int)($data['utc_offset_seconds'] ?? 0);
    }
    $stmt = $db->prepare("INSERT OR REPLACE INTO sun_cache (cache_key, sunrise_min, sunset_min, utc_offset, updated) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([$key, $sunrise, $sunset, $offset]);

    return ($sunrise >= 0 && $sunset > $sunrise) ? [$sunrise, $sunset, $offset] : null;
}

$auto_brightness_now   = null;   // 0-100, the level the sun has it at right now
$auto_brightness_step  = null;   // nearest of the five steps in the row below
$auto_sun_note         = '';     // sunrise/sunset, for the tooltip
$auto_brightness_estimated = false;
if (isset($_SESSION['user_id']) && $user_theme['brightness_auto'] === 1) {
    $auto_lat = (isset($weather_prefs['latitude']) && is_numeric($weather_prefs['latitude'])) ? (float)$weather_prefs['latitude'] : null;
    $auto_lon = (isset($weather_prefs['longitude']) && is_numeric($weather_prefs['longitude'])) ? (float)$weather_prefs['longitude'] : null;
    // No coordinates means no way to place the sun, so the readout is left off and
    // the page says so rather than inventing a number. The device gets its location
    // from this very page (api.php?weatherLocation=...), so if it is empty here the
    // device has none either and is running its own 07:00-19:00 placeholder.
    if ($auto_lat !== null && $auto_lon !== null) {
        $auto_sun = cron_sun_times($db, $auto_lat, $auto_lon);
        if ($auto_sun !== null) {
            [$auto_sunrise, $auto_sunset, $auto_offset] = $auto_sun;
            $auto_sun_note = sprintf('sunrise %02d:%02d, sunset %02d:%02d',
                intdiv($auto_sunrise, 60), $auto_sunrise % 60,
                intdiv($auto_sunset, 60), $auto_sunset % 60);
        } else {
            // The firmware's own fallback until its first weather fetch lands, so a
            // failed lookup still shows a sane value instead of a blank.
            $auto_sunrise = 7 * 60;
            $auto_sunset  = 19 * 60;
            // The device's clock is Europe/London (see initTime() in main.cpp), so
            // the offset has to be today's London offset - BST matters.
            $auto_offset = (new DateTime('now', new DateTimeZone('Europe/London')))->getOffset();
            $auto_brightness_estimated = true;
            $auto_sun_note = 'sun times unavailable - using the device fallback 07:00-19:00';
        }
        // Solar noon is in LOCAL minutes, so shift it to UTC before comparing with
        // gmdate(). Mixing the two would shift the answer by an hour through BST.
        $auto_noon_utc = (((intdiv($auto_sunrise + $auto_sunset, 2) - intdiv($auto_offset, 60)) % 1440) + 1440) % 1440;
        $auto_delta = (((int)gmdate('G')) * 60 + (int)gmdate('i')) - $auto_noon_utc;
        if ($auto_delta < -720) $auto_delta += 1440;
        if ($auto_delta >  720) $auto_delta -= 1440;
        $auto_raw = 50.0 * (1.0 + cos(($auto_delta / 720.0) * M_PI));
        // TWO steps, matching the device exactly: daylight_brightness() rounds the
        // curve to a whole percent first, and only then does updateAutoBrightness()
        // step THAT integer to 5%. Quantising the raw float in one go lands one
        // step out whenever rounding crosses a boundary - a real off-by-5 that a
        // cross-check against the compiled C++ caught.
        $auto_percent = max(0, min(100, (int)round($auto_raw)));
        $auto_brightness_now = max(0, min(100, intdiv($auto_percent + 2, 5) * 5));
        $auto_brightness_step = 0;
        $auto_best = PHP_INT_MAX;
        foreach ([0, 25, 50, 75, 100] as $auto_step) {
            $auto_dist = abs($auto_brightness_now - $auto_step);
            if ($auto_dist < $auto_best) { $auto_best = $auto_dist; $auto_brightness_step = $auto_step; }
        }
    }
}

// ---- Background picture state (Themes tab) ---------------------------------
// The device's background is either the picture its owner uploaded or one of the
// weather defaults. background_mode is created by the background page itself, so
// a site where that page has never been opened has no such column yet - hence the
// two-step query instead of letting the PDOException blank the whole page.
$user_background = ['mode' => 'custom', 'file' => ''];
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT background_image, background_mode FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $user_background['mode'] = (($row['background_mode'] ?? 'custom') === 'weather') ? 'weather' : 'custom';
            $user_background['file'] = (string)($row['background_image'] ?? '');
        }
    } catch (PDOException $e) {
        try {
            $stmt = $db->prepare("SELECT background_image FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $user_background['file'] = (string)($row['background_image'] ?? '');
        } catch (PDOException $e2) {
            // Leave the defaults: the block below then just says "nothing yet".
        }
    }
}
// Preview: the PNG the background page writes beside the .bin. When the device is
// following the weather there is no single answer - it depends on the conditions -
// so ask what is in force right now and show THAT picture, including this
// account's own replacement for it. The neutral overcast default is only used when
// the weather cannot be worked out at all.
$bg_preview = '';
$bg_has_custom = false;
$bg_now_group = null;
$bg_now_note = '';
if ($user_background['mode'] === 'custom' && $user_background['file'] !== '') {
    $candidate = 'uploads/' . pathinfo($user_background['file'], PATHINFO_FILENAME) . '.png';
    if (is_file($candidate)) {
        $bg_preview = $candidate;
        $bg_has_custom = true;
    }
}
if (!$bg_has_custom) {
    $bg_now = null;
    $bg_place = trim((string) ($weather_prefs['city_name'] ?? ''));
    if (isset($weather_prefs['latitude'], $weather_prefs['longitude'])
        && is_numeric($weather_prefs['latitude']) && is_numeric($weather_prefs['longitude'])) {
        $bg_now = bg_current_weather($db, (float) $weather_prefs['latitude'], (float) $weather_prefs['longitude']);
    }
    if ($bg_now !== null) {
        $bg_now_group = $bg_now['group'];
        // This account's own replacement for that group wins, exactly as
        // background.php decides it for the device - so the thumbnail shows the
        // picture the panel is actually displaying, not the shipped default.
        $ovr = bg_weather_override_base((int) $_SESSION['user_id'], $bg_now_group) . '.png';
        if (is_file($ovr)) {
            $bg_preview = $ovr;
        } elseif (is_file(BG_WEATHER_DIR . '/' . $bg_now_group . '.png')) {
            $bg_preview = BG_WEATHER_DIR . '/' . $bg_now_group . '.png';
        }
        $bg_now_note = ($bg_place !== '' ? $bg_place . ': ' : '') . $bg_now['description'];
    }
    if ($bg_preview === '' && is_file(BG_WEATHER_DIR . '/cloudy.png')) {
        $bg_preview = BG_WEATHER_DIR . '/cloudy.png';
    }
}

// Device fleet update policy. Everyone can SEE it; only the administrator can
// change it, because one value drives every device.
$policyPath = 'update/policy.json';
$policy = ['revision' => 1, 'auto_firmware_update' => false, 'quiet_start' => 2, 'quiet_end' => 5];
if (file_exists($policyPath)) {
    $lp = json_decode((string)file_get_contents($policyPath), true);
    if (is_array($lp)) { $policy = array_merge($policy, $lp); }
}
$policy_message = '';
$policy_error_flag = false;
if (isset($_POST['save_update_policy']) && isset($_SESSION['user_id'])) {
    if (!$is_admin) {
        $policy_message = 'Only the administrator can change the device update policy.';
        $policy_error_flag = true;
    } else {
        $auto = isset($_POST['auto_firmware_update']) && $_POST['auto_firmware_update'] === '1';
        $qs = isset($_POST['quiet_start']) ? (int)$_POST['quiet_start'] : 2;
        $qe = isset($_POST['quiet_end']) ? (int)$_POST['quiet_end'] : 5;
        if ($qs < 0 || $qs > 23 || $qe < 0 || $qe > 23) {
            $policy_message = 'Quiet-window hours must be between 0 and 23.'; $policy_error_flag = true;
        } else {
            $np = ['revision' => ((int)$policy['revision']) + 1, 'auto_firmware_update' => $auto,
                   'quiet_start' => $qs, 'quiet_end' => $qe, 'updated' => gmdate('c')];
            $t = tempnam(sys_get_temp_dir(), 'policy_');
            file_put_contents($t, json_encode($np, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($t !== false && rename($t, $policyPath)) {
                @chmod($policyPath, 0644); $policy = $np; $policy_message = 'Update policy saved.';
            } else { $policy_message = 'Could not write update/policy.json.'; $policy_error_flag = true; }
        }
    }
}

// Which tab is showing. Carried through POSTs and via ?tab=, so a redirect from
// the old theme.php lands in the right place.
// Themes first: it is where the device is actually configured, and it is the tab
// people come here for. The page also OPENS on it - a tab strip whose first entry
// is not the selected one reads as a bug.
$TABS = ['themes' => 'Themes', 'calendar' => 'Calendar', 'other' => 'Other'];
$active_tab = isset($_POST['tab']) ? (string)$_POST['tab'] : (isset($_GET['tab']) ? (string)$_GET['tab'] : 'themes');
if (!array_key_exists($active_tab, $TABS)) { $active_tab = 'themes'; }

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="<?php echo $THEME_SCHEMES[(string)$user_theme['scheme']][0]; ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Custom Calendar">
    <link rel="apple-touch-icon" href="icon-192x192.png">
    <link rel="manifest" href="manifest.json">
    <title>Settings - Custom Calendar Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="app.css">
<?php
    // Only the ACCENT follows the device's scheme.
    //
    // The page's text and neutral colours used to be derived from the brightness
    // setting as well - the same grey ramp the firmware paints, with the text
    // flipping from dark to light past the halfway mark. That made every label on
    // the page change colour as the brightness moved, and around the middle of the
    // range the body was mid-grey with pure white or near-black cards beside it,
    // which is the part that read badly (about 4.4:1 for body text at 50%).
    //
    // This page is where the brightness is CHOSEN, so it now keeps one legible
    // appearance at every value: the selected preset is the feedback, not the
    // page's own contrast. Nothing here affects the device - the Cron-Tab still
    // paints the full ramp, and calendar.php is untouched.
    $__scheme = (string)$user_theme['scheme'];
?>
<style>
    /* Must come after app.css: same specificity, so the later declaration wins.
       Everything not listed here keeps app.css's own readable palette. */
    :root {
        --primary:       <?php echo $THEME_SCHEMES[$__scheme][0]; ?>;
        --primary-hover: <?php echo $THEME_SCHEMES[$__scheme][1]; ?>;
        /* app.css defines no footer colours at all, so these two have to live
           somewhere. They are the light-mode values the page used before, now
           fixed: without them the footer rule below resolves to nothing and the
           footer loses its bar entirely. */
        --footer-bg:     #111827;
        --footer-text:   #9ca3af;
    }
</style>
    <style>
        body {
            padding-top: 60px;
            font-family: 'Montserrat', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
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
            background: transparent;
            z-index: 1;
        }
        .form-content {
            z-index: 2;
            max-width: 1400px;
            width: 100%;
            padding: 2rem;
            background: #ffffff;
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
            background: var(--primary);
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            border: none;
            cursor: pointer;
            display: inline-block;
            text-align: center;
        }
        .cta-btn:hover, .login-btn:hover, .sync-btn:hover {
            background: var(--primary);
            transform: scale(1.05);
        }
        .remove-btn {
            background: #ff6b6b;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 10px;
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
            background: #f3f4f6;
            backdrop-filter: blur(10px);
            padding: 1rem;
            display: flex;
            justify-content: center;
            gap: 2rem;
            z-index: 10;
        }
        .menu a {
            color: var(--text-main);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        .menu a:hover {
            color: var(--primary);
        }
        footer {
            padding: 2rem;
            text-align: center;
            background: transparent;
        }
        .form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-width: 900px;
            margin: 0 auto;
        }
        .form-input, .form-select {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            background: #ffffff;
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
            border: 1px solid var(--border-light);
        }
        .calendar-table th {
            background: #eef2f6;
            font-weight: 600;
        }
        .calendar-table td {
            background: #ffffff;
        }
        .calendar-table .actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
              flex-wrap: wrap;
        }
        .link-text {
            margin-top: 2rem;
            font-size: 1.2rem;
        }
        .link-text a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.3s;
        }
        .link-text a:hover {
            color: var(--primary-hover);
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
            background-color: #111827;
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
            color: var(--text-main);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        .menu-items a:hover {
            color: var(--primary);
        }
        #cityInputSection {
            display: none;
        }
        /* Two-column settings: calendar management on the left, everything
           else on the right. Falls under into a single column on narrow
           screens so the fields stay usable. */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            align-items: start;
        }
        .settings-col {
            background: #f9fafb;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 1.5rem;
            min-width: 0; /* lets the table shrink instead of overflowing */
        }
        .settings-col > h2:first-of-type { margin-top: 0; }
        @media (max-width: 1024px) {
            .settings-grid { grid-template-columns: 1fr; gap: 1.25rem; }
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

<style>
    /* These used to hard-code #f4f7f9 / #ffb703 / #fb8500 with !important, which is
       exactly why changing the scheme did nothing to this page: an !important
       literal beats a variable every time. They read the tokens now, and the tokens
       above are set from the device's own theme. */
    body { background:var(--bg-body) !important; color:var(--text-main) !important; font-family:'Montserrat',sans-serif !important; }
    .menu { background:var(--bg-card) !important; }
    .menu a, .menu-items a, .menu-items li a { color:var(--text-main) !important; }
    .menu a:hover, .menu-items a:hover, .menu-items li a:hover { color:var(--primary-hover) !important; }
    h1, h2, h3, h4, h5, .hero-title { color:var(--text-main) !important; }
    .cta-btn, .btn-primary { background:var(--primary) !important; color:#111 !important; }
    .cta-btn:hover, .btn-primary:hover { background:var(--primary-hover) !important; }
    footer { background:var(--footer-bg) !important; color:var(--footer-text) !important; }

        /* ---- settings tabs ---- */
        .settings-tabs { display:flex; gap:.5rem; flex-wrap:wrap; border-bottom:2px solid var(--border-light); margin:0 0 1.5rem; }
        .settings-tab { appearance:none; border:0; background:transparent; padding:.7rem 1.25rem; font-family:inherit;
            font-size:1rem; font-weight:700; color:var(--text-muted); cursor:pointer; border-bottom:3px solid transparent;
            margin-bottom:-2px; border-radius:8px 8px 0 0; transition:color .2s, background-color .2s, border-color .2s; }
        .settings-tab:hover { color:var(--text-main); background:rgba(128,128,128,.15); }
        .settings-tab.active { color:var(--text-main); border-bottom-color:var(--primary); }
        .settings-panel[hidden] { display:none; }

        /* ---- colour scheme swatches ---- */
        .theme-swatches { display:flex; flex-wrap:wrap; gap:.9rem; margin:.5rem 0 1.25rem; }
        .theme-swatch { position:relative; display:flex; flex-direction:column; align-items:center;
            gap:.35rem; cursor:pointer; }
        /* Visually hidden but still focusable, so keyboard and screen readers work. */
        .theme-swatch input { position:absolute; opacity:0; width:1px; height:1px; margin:0; }
        .theme-swatch-dot { display:block; width:2.6rem; height:2.6rem; border-radius:9999px;
            box-shadow:0 0 0 2px var(--border-light);
            transition:transform .15s ease, box-shadow .15s ease; }
        .theme-swatch:hover .theme-swatch-dot { transform:scale(1.08); }
        /* Ring in the card colour, then an outer ring in the text colour, so the
           selected dot reads against both a light and a dark page. */
        .theme-swatch input:checked + .theme-swatch-dot {
            box-shadow:0 0 0 2px var(--bg-card), 0 0 0 5px var(--text-main); }
        .theme-swatch input:focus-visible + .theme-swatch-dot {
            outline:2px solid var(--primary); outline-offset:3px; }
        .theme-swatch-name { font-size:.72rem; color:var(--text-muted); }
        .theme-swatch input:checked ~ .theme-swatch-name { color:var(--text-main); font-weight:700; }
        /* ---- five-step choices (UI brightness) ---- */
        /* Same trick as .theme-swatch: the radio itself is visually hidden but
           still focusable, so keyboard and screen readers keep working. One click
           is one autosave - no drag, so nothing fires mid-gesture. */
        .step-choices { display:flex; gap:.5rem; margin:.6rem 0 .25rem; }
        .step-choice { flex:1; position:relative; }
        /* "Auto" needs more room than a two- or three-digit number. */
        .step-choice-wide { flex:1.6; }
        .step-choice input { position:absolute; opacity:0; width:1px; height:1px; margin:0; }
        .step-choice-box { display:block; text-align:center; padding:.6rem 0; border-radius:8px;
            border:1px solid var(--border-light); background:var(--bg-card); color:var(--text-main);
            font-weight:700; cursor:pointer;
            transition:background-color .15s ease, border-color .15s ease, color .15s ease; }
        .step-choice:hover .step-choice-box { border-color:var(--primary); }
        .step-choice input:checked + .step-choice-box {
            background:var(--primary); border-color:var(--primary); color:#111; }
        .step-choice input:focus-visible + .step-choice-box {
            outline:2px solid var(--primary); outline-offset:2px; }
        /* Auto is on: the step the sun is currently nearest to takes the accent as
           its TEXT colour and an underline, the same way the device's own row shows
           it. The radio stays unchecked - Auto is the setting that is actually in
           force, and checking two would break the "one choice" rule the group
           relies on. An inset shadow rather than an extra line of text, so the
           marked box keeps exactly the same height as its neighbours. */
        .step-choice-auto .step-choice-box {
            border-color:var(--primary); color:var(--primary);
            box-shadow:inset 0 -3px 0 var(--primary); }
        /* Controls on these two panels save themselves, so the hint replaces the old button. */
        .autosave-hint { font-size:.82rem; color:var(--text-muted); text-align:center; margin:.9rem 0 .2rem; }
</style>
</head>
<body>

    <?php include 'menu.php'; ?>
    <section class="form-section">
        <div class="form-content">
            <h1 class="hero-title">Settings</h1>

            <div class="settings-tabs" role="tablist">
                <?php foreach ($TABS as $key => $label): ?>
                    <button type="button" class="settings-tab <?php echo $active_tab === $key ? 'active' : ''; ?>"
                            data-tab="<?php echo $key; ?>" role="tab"><?php echo $label; ?></button>
                <?php endforeach; ?>
            </div>

            <!-- ========================= CALENDAR ========================= -->
            <div class="settings-panel" id="panel-calendar" <?php echo $active_tab !== 'calendar' ? 'hidden' : ''; ?>>
                <div class="settings-grid">
                    <div class="settings-col">
                        <?php if ($calendar_message): ?>
                            <p class="<?php echo $calendar_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4"><?php echo htmlspecialchars($calendar_message); ?></p>
                        <?php endif; ?>
            <h2 class="text-2xl font-bold mb-4">Add ICS Calendar</h2>
            <form method="POST" class="form">
                <input type="hidden" name="tab" value="calendar">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="url" name="ics_url" placeholder="ICS URL (e.g., Google Calendar, Outlook, iCloud)" required class="form-input">
                <div class="flex items-center justify-center">
                    <input type="checkbox" name="skip_outdated" id="skipOutdated" class="mr-2 h-5 w-5">
                    <label for="skipOutdated" class="text-base">Skip outdated events</label>
                </div>
                <button type="submit" name="add_ics_calendar" class="cta-btn">Add Calendar</button>
            </form>
                    </div>
                    <div class="settings-col">
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
                                        <input type="hidden" name="tab" value="calendar">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="calendar_id" value="<?php echo htmlspecialchars($cal['calendar_id']); ?>">
                                        <button type="submit" name="sync_calendar" class="sync-btn">Sync Now</button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to remove this calendar?');">
                                        <input type="hidden" name="tab" value="calendar">
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
                    </div>
                </div>
            </div>

            <!-- ========================== THEMES ========================== -->
            <div class="settings-panel" id="panel-themes" <?php echo $active_tab !== 'themes' ? 'hidden' : ''; ?>>
                <div class="settings-grid">
                    <div class="settings-col">
                        <h2 class="text-2xl font-bold mb-4">My Cron-Tab theme</h2>
                        <p class="text-base mb-4">Changes your <strong>Cron-Tab device</strong> only. Your website calendar keeps its standard look, and other accounts are not affected.</p>
                        <?php if ($theme_message): ?>
                            <p class="<?php echo $theme_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4"><?php echo htmlspecialchars($theme_message); ?></p>
                        <?php endif; ?>
                        <form method="POST" class="form">
                            <input type="hidden" name="tab" value="themes">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <!-- form.submit() omits the clicked button's name, so the controls below
                                 need this to reach the save_theme handler at all. -->
                            <input type="hidden" name="save_theme" value="1">
                            <label class="text-base">Colour scheme</label>
                            <div class="theme-swatches">
                                <?php foreach ($THEME_SCHEMES as $name => $cols): ?>
                                    <label class="theme-swatch" title="<?php echo htmlspecialchars($name); ?>">
                                        <input type="radio" name="scheme"
                                               value="<?php echo htmlspecialchars($name); ?>"
                                               <?php echo $name === (string)$user_theme['scheme'] ? 'checked' : ''; ?>
                                               onchange="ctAutosave(this);">
                                        <span class="theme-swatch-dot" style="background:<?php echo $cols[0]; ?>;"></span>
                                        <span class="theme-swatch-name"><?php echo htmlspecialchars($name); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php
                            // Six choices matching the device's own row: Auto, then
                            // five presets. A slider gives a value nobody needs to
                            // that precision, and on the device itself a drag was
                            // unreliable; one click is also one autosave.
                            //
                            // All six share ONE radio group (name="brightness") so
                            // Auto and a preset cannot both look selected. The value
                            // posted is the brightness the user sees ("auto" or a
                            // percentage); the stored column stays "darkness"
                            // (0 = light) because the device reads that, so only the
                            // submitted number is flipped: 100 - brightness.
                            $brightness_steps = [0, 25, 50, 75, 100];
                            $brightness_auto = (int)$user_theme['brightness_auto'] === 1;
                            $brightness_now = 100 - (int)$user_theme['darkness'];
                            $brightness_sel = $brightness_steps[0];
                            $brightness_best = PHP_INT_MAX;
                            foreach ($brightness_steps as $__step) {
                                $__d = abs($brightness_now - $__step);
                                if ($__d < $brightness_best) { $brightness_best = $__d; $brightness_sel = $__step; }
                            }
                            // With Auto on, $brightness_sel is only the manual level
                            // stored underneath it - not what the device is showing.
                            // $auto_brightness_step is the step the sun has actually
                            // picked, and that is the one worth marking.
                            $auto_match_step = $brightness_auto ? $auto_brightness_step : null;
                            ?>
                            <label class="text-base">UI brightness (0 = dark, 100 = bright)</label>
                            <div class="step-choices">
                                <label class="step-choice step-choice-wide" title="Follow daylight: brightest at midday, darkest at midnight">
                                    <input type="radio" name="brightness" value="auto"
                                           <?php echo $brightness_auto ? 'checked' : ''; ?>
                                           onchange="ctAutosave(this);">
                                    <span class="step-choice-box">Auto</span>
                                </label>
                                <?php foreach ($brightness_steps as $__step): ?>
                                    <?php $__is_auto_match = ($auto_match_step !== null && $__step === $auto_match_step); ?>
                                    <label class="step-choice<?php echo $__is_auto_match ? ' step-choice-auto' : ''; ?>"
                                           title="<?php echo $__step; ?>%<?php echo $__is_auto_match ? ' - the step Auto is on now' : ''; ?>">
                                        <input type="radio" name="brightness"
                                               value="<?php echo $__step; ?>"
                                               <?php echo (!$brightness_auto && $__step === $brightness_sel) ? 'checked' : ''; ?>
                                               onchange="ctAutosave(this);">
                                        <span class="step-choice-box"><?php echo $__step; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($brightness_auto): ?>
                                <p class="autosave-hint" title="<?php echo htmlspecialchars($auto_sun_note); ?>">
                                    <?php if ($auto_brightness_step !== null): ?>
                                        Auto is following the sun: <strong><?php echo $auto_brightness_now; ?>%</strong>
                                        right now, so the <strong><?php echo $auto_brightness_step; ?></strong> step is the
                                        one in use<?php echo $auto_brightness_estimated ? ' (estimated)' : ''; ?>.
                                    <?php else: ?>
                                        Auto is following the sun. Add a weather location (Other tab) to see the level it is using.
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <?php
                            // How see-through the device's panels are. 100 is the solid
                            // look the device has always had, so it is the default and
                            // the safe end of the range.
                            $panel_steps = [100, 85, 70, 55, 40];
                            ?>
                            <label class="text-base mt-6">Panel opacity on the device (100 = solid, lower shows more of the background picture)</label>
                            <div class="step-choices">
                                <?php foreach ($panel_steps as $__po): ?>
                                    <label class="step-choice" title="<?php echo $__po; ?>% opaque">
                                        <input type="radio" name="panel_opa"
                                               value="<?php echo $__po; ?>"
                                               <?php echo (int)$user_theme['panel_opa'] === $__po ? 'checked' : ''; ?>
                                               onchange="ctAutosave(this);">
                                        <span class="step-choice-box"><?php echo $__po; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="autosave-hint">Only matters while a background picture is showing.</p>
                            <p class="autosave-hint">Saves automatically.</p>
                        </form>
                    </div>

                    <div class="settings-col">
                        <h2 class="text-2xl font-bold mb-4">Background picture</h2>
                        <p class="text-base mb-4">
                            Sits behind the calendar on your <strong>Cron-Tab device</strong>. Upload your own
                            picture, or let the weather choose one for you.
                        </p>
                        <p class="text-base mb-3">
                            Currently: <strong><?php echo $user_background['mode'] === 'weather' ? 'Weather pictures (automatic)' : 'My own picture'; ?></strong><?php
                                if ($bg_now_note !== '' && $bg_now_group !== null): ?> &mdash; <?php echo htmlspecialchars($bg_now_note); ?>, so the <?php echo htmlspecialchars(bg_weather_label($bg_now_group)); ?> picture is showing.<?php
                                elseif ($user_background['mode'] === 'custom' && !$bg_has_custom): ?> &mdash; nothing uploaded yet, so the device is using the weather pictures.<?php
                                endif; ?>
                        </p>
                        <?php if ($bg_preview !== ''): ?>
                            <img src="<?php echo htmlspecialchars($bg_preview); ?>?v=<?php echo (int) @filemtime($bg_preview); ?>"
                                 alt="Current device background"
                                 style="display:block;width:100%;max-width:300px;aspect-ratio:5/3;object-fit:cover;border-radius:10px;border:2px solid var(--border-light);margin-bottom:1rem;">
                        <?php endif; ?>
                        <a href="image_converter.php" class="cta-btn">Upload / change background picture</a>
                    </div>
                </div>
            </div>

            <!-- =========================== OTHER =========================== -->
            <div class="settings-panel" id="panel-other" <?php echo $active_tab !== 'other' ? 'hidden' : ''; ?>>
                <div class="settings-grid">
                    <div class="settings-col">
            <?php if ($parcel_message): ?>
                <p class="<?php echo $parcel_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4 mt-6"><?php echo htmlspecialchars($parcel_message); ?></p>
            <?php endif; ?>
            <h2 class="text-2xl font-bold mt-6 mb-4">Parcel Box Settings</h2>
            <form method="POST" class="form">
                <input type="hidden" name="tab" value="other">
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
                <input type="hidden" name="tab" value="other">
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
                    </div>
                    <div class="settings-col">
            <?php if ($session_message): ?>
                <p class="<?php echo $session_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4 mt-6"><?php echo htmlspecialchars($session_message); ?></p>
            <?php endif; ?>
            <h2 class="text-2xl font-bold mt-6 mb-4">Session Lock Settings</h2>
            <form method="POST" class="form">
                <input type="hidden" name="tab" value="other">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <?php if ($session_lock_enabled): ?>
                    <p class="text-base mb-4">Session lock is currently enabled.</p>
                    <button type="submit" name="disable_session_lock" class="remove-btn">Disable Session Lock</button>
                <?php else: ?>
                    <p class="text-base mb-4">Enable session lock to stay logged in after closing the app.</p>
                    <button type="submit" name="enable_session_lock" class="cta-btn">Enable Session Lock</button>
                <?php endif; ?>
            </form>
            <h2 class="text-2xl font-bold mt-6 mb-4">Device updates</h2>
            <p class="text-base mb-4">Applies to the CronTab device, not to this website.</p>
            <?php if ($policy_message): ?>
                <p class="<?php echo $policy_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4"><?php echo htmlspecialchars($policy_message); ?></p>
            <?php endif; ?>
            <form method="POST" class="form">
                <!-- Saves under the Other tab: this section moved here from Themes, and
                     the hidden tab decides which tab comes back after the autosave. -->
                <input type="hidden" name="tab" value="other">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <?php if ($is_admin): ?>
                    <!-- form.submit() omits the clicked button's name, so the controls below
                         need this to reach the save_update_policy handler at all. -->
                    <input type="hidden" name="save_update_policy" value="1">
                <?php endif; ?>
                <div class="flex items-center justify-center">
                    <input type="checkbox" name="auto_firmware_update" id="autoUpd" value="1" class="mr-2 h-5 w-5"
                           <?php echo $policy['auto_firmware_update'] ? 'checked' : ''; ?>
                           <?php echo $is_admin ? 'onchange="ctAutosave(this);"' : 'disabled'; ?>>
                    <label for="autoUpd" class="text-base">Install firmware updates automatically</label>
                </div>
                <label class="text-base">Quiet window</label>
                <div class="flex items-center justify-center gap-3">
                    <select name="quiet_start" class="form-select" <?php echo $is_admin ? 'onchange="ctAutosave(this);"' : 'disabled'; ?>>
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <option value="<?php echo $h; ?>" <?php echo (int)$policy['quiet_start'] === $h ? 'selected' : ''; ?>><?php printf('%02d:00', $h); ?></option>
                        <?php endfor; ?>
                    </select>
                    <span>to</span>
                    <select name="quiet_end" class="form-select" <?php echo $is_admin ? 'onchange="ctAutosave(this);"' : 'disabled'; ?>>
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <option value="<?php echo $h; ?>" <?php echo (int)$policy['quiet_end'] === $h ? 'selected' : ''; ?>><?php printf('%02d:00', $h); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php if ($is_admin): ?>
                    <p class="autosave-hint">Saves automatically.</p>
                    <a href="firmware.php" class="cta-btn" style="background:#111827;">Upload firmware</a>
                <?php else: ?>
                    <p class="text-base">Only the administrator can change these.</p>
                <?php endif; ?>
            </form>
                        <p class="link-text mt-6"><a href="calendar.php" class="link-text">Back to Calendar</a></p>
                    </div>
                </div>
            </div>
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
        // Theme and update-policy controls save on change, so the pages have no Save
        // button. Debounced so dragging the brightness slider posts once, not per pixel.
        let ctAutosaveTimer = null;
        function ctAutosave(el) {
            if (!el || !el.form) return;
            const form = el.form;
            clearTimeout(ctAutosaveTimer);
            ctAutosaveTimer = setTimeout(() => form.submit(), 250);
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
    
        // Settings tabs. The active panel is rendered server-side, so the page
        // works before this runs and a POST returns to the tab it came from.
        document.querySelectorAll('.settings-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.settings-tab').forEach(function (b) {
                    b.classList.toggle('active', b === btn);
                });
                document.querySelectorAll('.settings-panel').forEach(function (p) {
                    p.hidden = (p.id !== 'panel-' + btn.dataset.tab);
                });
                if (history.replaceState) { history.replaceState(null, '', '?tab=' + btn.dataset.tab); }
            });
        });
    </script>
</body>
</html>