<?php
session_start();
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Set default timezone (adjust as needed)
date_default_timezone_set('Europe/Warsaw');
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
    die("Database Error: " . $e->getMessage());
}
// Helper functions for parsing .ics (pure PHP, no dependencies)
function normalize_ics_lines(string $ics): array {
    $ics = preg_replace("/\r\n[ \t]/", '', $ics); // Unfold multi-line values
    $ics = str_replace(["\r\n", "\r"], "\n", $ics); // Normalize line endings
    $ics = str_replace('\,', ',', $ics); // Unescape commas
    $ics = str_replace('\n', ' ', $ics); // Replace literal \n with space
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
    // Map common TZID values to PHP DateTimeZone
    $tzMap = [
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'GMT Standard Time' => 'Europe/London',
        'UTC' => 'UTC',
        // Add more mappings as needed
    ];
    return $tzMap[$tzid] ?? $tzid; // Fallback to original TZID
}
function parse_ical_date(string $dateStr, array $params = []): ?DateTime {
    $tz = isset($params['TZID']) ? map_timezone($params['TZID']) : 'UTC';
    try {
        $dateTimeZone = new DateTimeZone($tz);
    } catch (Exception $e) {
        $dateTimeZone = new DateTimeZone('UTC'); // Fallback to UTC
    }
    if (isset($params['VALUE']) && $params['VALUE'] === 'DATE') {
        // All-day date format: YYYYMMDD
        return DateTime::createFromFormat('Ymd', $dateStr, $dateTimeZone);
    } else {
        // Datetime format: YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ
        $format = 'Ymd\THis';
        if (substr($dateStr, -1) === 'Z') {
            $dateStr = substr($dateStr, 0, -1);
            $dateTimeZone = new DateTimeZone('UTC');
        }
        $dateTime = DateTime::createFromFormat($format, $dateStr, $dateTimeZone);
        if ($dateTime === false && strlen($dateStr) === 8) {
            // Fallback for all-day events with no VALUE=DATE (e.g., iCloud)
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
        // Fallback for missing DTEND (e.g., iCloud all-day events)
        if (!$end) {
            $end = clone $start;
            $end->modify('+1 day');
        }
        // Convert to server timezone
        $serverTz = new DateTimeZone(date_default_timezone_get());
        $start->setTimezone($serverTz);
        $end->setTimezone($serverTz);
        // For all-day events, set end to 23:59:59 if needed
        $allDay = (isset($event['DTSTART_params']['VALUE']) && $event['DTSTART_params']['VALUE'] === 'DATE') || (strlen($event['DTSTART']) === 8) ? 1 : 0;
        if ($allDay) {
            $end->setTime(23, 59, 59);
        }
        // Handle Microsoft’s SUBJECT or standard SUMMARY
        $summary = $event['SUMMARY'] ?? $event['SUBJECT'] ?? 'No title';
        $parsedEvents[] = [
            'summary' => $summary,
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time' => $end->format('Y-m-d H:i:s'),
            'description' => $event['DESCRIPTION'] ?? '',
            'all_day' => $allDay,
            'google_event_id' => $event['UID'] ?? uniqid() // Use UID for uniqueness
        ];
    }
    return $parsedEvents;
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
    $cal = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cal) {
        $ics_url = $cal['ics_url'];
        $skip_outdated = $cal['skip_outdated'];
        $current_time = time();
        $icsContent = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($ics_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $icsContent = curl_exec($ch);
            curl_close($ch);
        } else {
            $icsContent = @file_get_contents($ics_url);
        }
        if ($icsContent === false) {
            $calendar_message = "Failed to fetch ICS from URL.";
            $calendar_error_flag = true;
        } else {
            try {
                $parsedEvents = parse_ics($icsContent);
                foreach ($parsedEvents as $event) {
                    if ($skip_outdated && strtotime($event['end_time']) < $current_time) {
                        continue; // Skip outdated events
                    }
                    $uid = $event['google_event_id'];
                    $stmt_check = $db->prepare("SELECT event_id FROM events WHERE google_event_id = ? AND user_id = ? AND source = 'ics' AND ics_calendar_id = ?");
                    $stmt_check->execute([$uid, $_SESSION['user_id'], $cal['calendar_id']]);
                    if ($stmt_check->fetch()) {
                        // Update existing event
                        $stmt_update = $db->prepare("UPDATE events SET summary = ?, start_time = ?, end_time = ?, description = ?, all_day = ? WHERE google_event_id = ? AND user_id = ? AND source = 'ics' AND ics_calendar_id = ?");
                        $stmt_update->execute([
                            $event['summary'],
                            $event['start_time'],
                            $event['end_time'],
                            $event['description'],
                            $event['all_day'],
                            $uid,
                            $_SESSION['user_id'],
                            $cal['calendar_id']
                        ]);
                    } else {
                        // Insert new event
                        $stmt_insert = $db->prepare("INSERT INTO events (user_id, summary, start_time, end_time, description, all_day, source, google_event_id, ics_calendar_id) VALUES (?, ?, ?, ?, ?, ?, 'ics', ?, ?)");
                        $stmt_insert->execute([
                            $_SESSION['user_id'],
                            $event['summary'],
                            $event['start_time'],
                            $event['end_time'],
                            $event['description'],
                            $event['all_day'],
                            $uid,
                            $cal['calendar_id']
                        ]);
                    }
                }
                // Remove deleted events
                if (!empty($parsedEvents)) {
                    $current_uids = array_column($parsedEvents, 'google_event_id');
                    $placeholders = implode(',', array_fill(0, count($current_uids), '?'));
                    $stmt_delete = $db->prepare("DELETE FROM events WHERE user_id = ? AND source = 'ics' AND ics_calendar_id = ? AND google_event_id NOT IN ($placeholders)");
                    $params = array_merge([$_SESSION['user_id'], $cal['calendar_id']], $current_uids);
                    $stmt_delete->execute($params);
                } else {
                    $stmt_delete = $db->prepare("DELETE FROM events WHERE user_id = ? AND source = 'ics' AND ics_calendar_id = ?");
                    $stmt_delete->execute([$_SESSION['user_id'], $cal['calendar_id']]);
                }
                $calendar_message = "Calendar synced successfully.";
            } catch (Exception $e) {
                $calendar_message = "Failed to parse/sync ICS: " . $e->getMessage();
                $calendar_error_flag = true;
            }
        }
    } else {
        $calendar_message = "Invalid calendar.";
        $calendar_error_flag = true;
    }
}
// Handle remove calendar
if (isset($_POST['remove_calendar']) && isset($_POST['calendar_id']) && isset($_SESSION['user_id'])) {
    $cal_id = $_POST['calendar_id'];
    // Delete associated events
    $stmt = $db->prepare("DELETE FROM events WHERE ics_calendar_id = ? AND user_id = ? AND source = 'ics'");
    $stmt->execute([$cal_id, $_SESSION['user_id']]);
    // Delete calendar
    $stmt = $db->prepare("DELETE FROM ics_calendars WHERE calendar_id = ? AND user_id = ?");
    $stmt->execute([$cal_id, $_SESSION['user_id']]);
    $calendar_message = "ICS calendar removed successfully.";
}
// Handle registration
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
    $access_code = substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 12);
    try {
        $stmt = $db->prepare("INSERT INTO users (username, password, access_code) VALUES (?, ?, ?)");
        $stmt->execute([$username, $password, $access_code]);
        $_SESSION['user_id'] = $db->lastInsertId();
        $_SESSION['access_code'] = $access_code;
    } catch (PDOException $e) {
        $_SESSION['register_error'] = "Registration failed: " . $e->getMessage();
    }
}
// Handle login
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify(trim($_POST['password']), $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['access_code'] = $user['access_code'];
    } else {
        $_SESSION['login_error'] = "Invalid username or password.";
    }
}
// Handle add event
$event_error = '';
if (isset($_POST['add_event']) && isset($_SESSION['user_id'])) {
    try {
        $summary = trim($_POST['summary']);
        $start_time = $_POST['start_time'] ?? $_POST['selected_date'] . ' 00:00:00';
        $end_time = $_POST['end_time'] ?? null;
        $description = trim($_POST['description'] ?? '');
        $all_day = isset($_POST['all_day']) ? 1 : 0;
        $remind_before = $_POST['remind_before'] ?? '';
        // Validate date format
        $start_time = date('Y-m-d H:i:s', strtotime($start_time));
        if ($all_day) {
            $start_time = date('Y-m-d 00:00:00', strtotime($start_time));
            $end_time = date('Y-m-d 23:59:59', strtotime($start_time));
        } else {
            $end_time = $end_time ? date('Y-m-d H:i:s', strtotime($end_time)) : date('Y-m-d H:i:s', strtotime($start_time . ' +15 minutes'));
        }
        if (empty($summary) || empty($start_time) || empty($end_time)) {
            throw new Exception("Missing required fields.");
        }
        $stmt = $db->prepare("INSERT INTO events (user_id, summary, start_time, end_time, description, all_day, remind_before) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $summary, $start_time, $end_time, $description, $all_day, $remind_before]);
    } catch (Exception $e) {
        $event_error = "Failed to add event: " . $e->getMessage();
    }
}
// Handle update event
if (isset($_POST['update_event']) && isset($_SESSION['user_id'])) {
    try {
        $event_id = $_POST['event_id'];
        $summary = trim($_POST['summary']);
        $start_time = $_POST['start_time'] ?? $_POST['selected_date'] . ' 00:00:00';
        $end_time = $_POST['end_time'] ?? null;
        $description = trim($_POST['description'] ?? '');
        $all_day = isset($_POST['all_day']) ? 1 : 0;
        $remind_before = $_POST['remind_before'] ?? '';
        // Validate date format
        $start_time = date('Y-m-d H:i:s', strtotime($start_time));
        if ($all_day) {
            $start_time = date('Y-m-d 00:00:00', strtotime($start_time));
            $end_time = date('Y-m-d 23:59:59', strtotime($start_time));
        } else {
            $end_time = $end_time ? date('Y-m-d H:i:s', strtotime($end_time)) : date('Y-m-d H:i:s', strtotime($start_time . ' +15 minutes'));
        }
        if (empty($summary) || empty($start_time) || empty($end_time)) {
            throw new Exception("Missing required fields.");
        }
        // Check if event is from ICS
        $stmt = $db->prepare("SELECT source FROM events WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$event_id, $_SESSION['user_id']]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event && $event['source'] === 'ics') {
            $event_error = "Cannot update ICS imported events.";
        } else {
            $stmt = $db->prepare("UPDATE events SET summary = ?, start_time = ?, end_time = ?, description = ?, all_day = ?, remind_before = ? WHERE event_id = ? AND user_id = ?");
            $stmt->execute([$summary, $start_time, $end_time, $description, $all_day, $remind_before, $event_id, $_SESSION['user_id']]);
        }
    } catch (Exception $e) {
        $event_error = "Failed to update event: " . $e->getMessage();
    }
}
// Handle delete event
if (isset($_POST['delete_event']) && isset($_SESSION['user_id'])) {
    try {
        $event_id = $_POST['event_id'];
        $stmt = $db->prepare("DELETE FROM events WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$event_id, $_SESSION['user_id']]);
    } catch (PDOException $e) {
        $event_error = "Failed to delete event: " . $e->getMessage();
    }
}
// Fetch events if logged in
$events = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM events WHERE user_id = ? ORDER BY start_time ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Fetch saved ICS calendars if logged in
$calendars = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM ics_calendars WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Redirect to login.php if no session
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
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
    <meta name="apple-mobile-web-app-title" content="Custom Calendar">
    <link rel="apple-touch-icon" href="icon-192x192.png"> <!-- Replace with actual icon path -->
    <link rel="manifest" href="manifest.json">
    <title>Custom Calendar App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            color: #fff;
            overflow-x: hidden;
        }
        .hero, .form-section {
            position: relative;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }
        .hero::before, .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1;
        }
        .hero-content, .form-content {
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
            border: none;
            cursor: pointer;
        }
        .cta-btn:hover {
            background: #2FB988;
            transform: scale(1.05);
        }
        .login-btn {
            background: #3B82F6;
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
        .login-btn:hover {
            background: #2563EB;
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
        .form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .form-input {
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
        .link-text {
            margin-top: 2rem;
            font-size: 1.2rem;
        }
        .link {
            color: #34D399;
            text-decoration: none;
            transition: color 0.3s;
        }
        .link:hover {
            color: #2FB988;
            text-decoration: underline;
        }
        /* Adapt for calendar */
        .calendar-section {
            padding: 4rem 2rem;
            text-align: center;
        }
        #calendar {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            color: #333;
        }
        .left-sidebar {
            width: 20%;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 1rem;
            margin-right: 2rem;
        }
        .sidebar-btn {
            display: block;
            background: #34D399;
            color: white;
            padding: 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            margin-bottom: 1rem;
            transition: background 0.3s;
        }
        .sidebar-btn:hover {
            background: #2FB988;
        }
        .app-content {
            display: flex;
            max-width: 1200px;
            margin: 0 auto;
        }
        .calendar-container {
            width: 80%;
        }
        #eventPopup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            color: #333;
            width: 90%;
            max-width: 500px;
        }
        #eventPopupOverlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        .code-box {
            font-family: 'Courier New', monospace;
            background: linear-gradient(45deg, #E5E7EB, #F3F4F6);
            border: 2px dashed #3B82F6;
            word-break: break-all;
            color: #3B82F6;
            text-align: center;
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
            .form-input, .cta-btn, .login-btn {
                padding: 0.75rem 1.5rem;
                font-size: 0.9rem;
            }
            .error-msg, .link-text {
                font-size: 1rem;
            }
            .app-content {
                flex-direction: column;
            }
            .left-sidebar {
                width: 100%;
                margin-right: 0;
                margin-bottom: 2rem;
            }
            .calendar-container {
                width: 100%;
            }
            #calendar {
                padding: 0.5rem;
            }
            #eventPopup {
                padding: 1rem;
                width: 95%;
            }
            .fc-header-toolbar {
                flex-direction: column !important;
                align-items: center !important;
            }
            .fc-toolbar-chunk {
                margin: 0.5rem 0 !important;
            }
            .fc-toolbar-chunk:nth-child(2) {
                order: 1 !important;
            }
            .fc-toolbar-chunk:nth-child(3) {
                order: 2 !important;
            }
            .fc-toolbar-chunk:nth-child(1) {
                order: 3 !important;
            }
        }
    </style>
</head>
<body>
    <nav class="menu">
        <a href="index.php">Home</a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="weather.php">Weather</a>
            <a href="settings.php">Settings</a>
            <a href="?logout=1">Logout</a>
        <?php endif; ?>
    </nav>
  
        <section class="calendar-section">
            <h1 class="text-3xl font-bold mb-4">Your Calendar</h1>
            <!-- Display event errors if any -->
            <?php if (isset($event_error)): ?>
                <p class="text-red-500 mb-4 text-center"><?php echo $event_error; ?></p>
            <?php endif; ?>
          
            <div class="app-content">
                <div class="left-sidebar">
                    <div class="code-box text-lg p-4 rounded"><?php echo htmlspecialchars($_SESSION['access_code'] ?? 'N/A'); ?></div>
                </div>
                <div class="calendar-container">
                    <div id="calendar"></div>
                </div>
            </div>
        </section>
      
        <!-- Event Popup -->
        <div id="eventPopupOverlay"></div>
        <div id="eventPopup" role="dialog" aria-labelledby="popupTitle">
            <h2 id="popupTitle" class="text-xl font-bold mb-4">Event Details</h2>
            <form method="POST" id="eventForm" class="space-y-4">
                <input type="hidden" name="selected_date" id="selectedDate">
                <input type="hidden" name="event_id" id="eventId">
                <input type="text" name="summary" id="summary" placeholder="Summary" required class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" aria-label="Event Summary">
                <div class="flex items-center">
                    <input type="checkbox" name="all_day" id="allDay" class="mr-2 h-5 w-5">
                    <label for="allDay" class="text-base">All-day event</label>
                </div>
                <input type="datetime-local" name="start_time" id="startTime" required class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" aria-label="Start Time">
                <input type="datetime-local" name="end_time" id="endTime" class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" aria-label="End Time">
                <textarea name="description" id="description" placeholder="Description" class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" aria-label="Event Description"></textarea>
                <input type="text" name="remind_before" id="remindBefore" min="0" placeholder="5m, 2h, 1d" class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" aria-label="Remind Before (minutes)">
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closePopup()" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600">Cancel</button>
                    <button type="submit" name="delete_event" class="bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600">Delete</button>
                    <button type="submit" name="update_event" id="saveButton" class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600">Save</button>
                </div>
            </form>
        </div>
  
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
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['user_id'])): ?>
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev today next',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: [
                    <?php foreach ($events as $event): ?>
                    {
                        id: '<?php echo $event['event_id']; ?>',
                        title: '<?php echo addslashes(htmlspecialchars($event['summary'])); ?>',
                        start: '<?php echo $event['start_time']; ?>',
                        end: '<?php echo $event['end_time']; ?>',
                        allDay: <?php echo $event['all_day'] ? 'true' : 'false'; ?>,
                        extendedProps: {
                            description: '<?php echo addslashes(htmlspecialchars($event['description'] ?? '')); ?>',
                            remind_before: '<?php echo addslashes(htmlspecialchars($event['remind_before'] ?? '')); ?>'
                        },
                        backgroundColor: '<?php echo ($event['source'] ?? 'local') === 'ics' ? '#34D399' : '#3B82F6'; ?>',
                        borderColor: '<?php echo ($event['source'] ?? 'local') === 'ics' ? '#34D399' : '#3B82F6'; ?>',
                        editable: <?php echo ($event['source'] ?? 'local') !== 'ics' ? 'true' : 'false'; ?>
                    },
                    <?php endforeach; ?>
                ],
                dateClick: function(info) {
                    if (calendar.view.type === 'dayGridMonth') {
                        calendar.changeView('timeGridDay', info.dateStr);
                    } else {
                        document.getElementById('eventPopup').style.display = 'block';
                        document.getElementById('eventPopupOverlay').style.display = 'block';
                        document.getElementById('popupTitle').textContent = 'Add Event';
                        document.getElementById('eventId').value = '';
                        document.getElementById('selectedDate').value = info.dateStr;
                        document.getElementById('summary').value = '';
                        // Use local time formatting
                        document.getElementById('startTime').value = formatLocalDateTime(info.date);
                        var endDate = new Date(info.date.getTime() + 15 * 60 * 1000);
                        document.getElementById('endTime').value = formatLocalDateTime(endDate);
                        document.getElementById('description').value = '';
                        document.getElementById('allDay').checked = false;
                        document.getElementById('startTime').disabled = false;
                        document.getElementById('endTime').disabled = false;
                        document.getElementById('remindBefore').value = '';
                        document.getElementById('saveButton').setAttribute('name', 'add_event');
                    }
                },
                eventClick: function(info) {
                    if (info.event.editable === false) {
                        alert('ICS imported events cannot be edited.');
                        return;
                    }
                    document.getElementById('eventPopup').style.display = 'block';
                    document.getElementById('eventPopupOverlay').style.display = 'block';
                    document.getElementById('popupTitle').textContent = 'Edit Event';
                    document.getElementById('eventId').value = info.event.id;
                    document.getElementById('summary').value = info.event.title;
                    // Use local time formatting
                    document.getElementById('startTime').value = formatLocalDateTime(info.event.start);
                    document.getElementById('endTime').value = info.event.end ? formatLocalDateTime(info.event.end) : formatLocalDateTime(new Date(info.event.start.getTime() + 15 * 60 * 1000));
                    document.getElementById('description').value = info.event.extendedProps.description || '';
                    document.getElementById('remindBefore').value = info.event.extendedProps.remind_before || '';
                    document.getElementById('allDay').checked = info.event.allDay;
                    document.getElementById('startTime').disabled = info.event.allDay;
                    document.getElementById('endTime').disabled = info.event.allDay;
                    document.getElementById('selectedDate').value = formatLocalDate(info.event.start);
                    document.getElementById('saveButton').setAttribute('name', 'update_event');
                }
            });
            calendar.render();
            // Helper function for local datetime string
            function formatLocalDateTime(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${year}-${month}-${day}T${hours}:${minutes}`;
            }
            // Helper for date only (used in selectedDate)
            function formatLocalDate(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }
            // Handle all-day checkbox
            document.getElementById('allDay').addEventListener('change', function() {
                var startTime = document.getElementById('startTime');
                var endTime = document.getElementById('endTime');
                var selectedDate = document.getElementById('selectedDate').value;
                if (this.checked) {
                    startTime.disabled = true;
                    endTime.disabled = true;
                    startTime.value = selectedDate + 'T00:00';
                    endTime.value = selectedDate + 'T23:59';
                } else {
                    startTime.disabled = false;
                    endTime.disabled = false;
                    // Revert to current start time with 15-minute duration
                    var startDate = new Date(startTime.value.replace('T', ' '));
                    if (isNaN(startDate)) {
                        startDate = new Date(selectedDate + 'T00:00');
                    }
                    startTime.value = formatLocalDateTime(startDate);
                    var endDate = new Date(startDate.getTime() + 15 * 60 * 1000);
                    endTime.value = formatLocalDateTime(endDate);
                }
            });
            <?php endif; ?>
        });
        function closePopup() {
            document.getElementById('eventPopup').style.display = 'none';
            document.getElementById('eventPopupOverlay').style.display = 'none';
            document.getElementById('eventForm').reset();
            document.getElementById('popupTitle').textContent = 'Add Event';
            document.getElementById('eventId').value = '';
            document.getElementById('allDay').checked = false;
            document.getElementById('startTime').disabled = false;
            document.getElementById('endTime').disabled = false;
            document.getElementById('saveButton').setAttribute('name', 'add_event');
        }
    </script>
</body>
</html>