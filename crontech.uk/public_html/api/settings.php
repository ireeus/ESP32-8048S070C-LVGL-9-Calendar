<?php
session_start();
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Set default timezone (adjust as needed)
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
// Handle save parcel box
$parcel_message = '';
$parcel_error_flag = false;
$parcel = false;
if (isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT * FROM parcel_box WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $parcel = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (isset($_POST['save_parcel_box']) && isset($_SESSION['user_id'])) {
    $username = trim($_POST['username']);
    $parcel_box_id = trim($_POST['parcel_box_id']);
    if (!empty($username) && !empty($parcel_box_id)) {
        try {
            if ($parcel) {
                // Update
                $stmt = $db->prepare("UPDATE parcel_box SET username = ?, parcel_box_id = ?, timestamp = CURRENT_TIMESTAMP WHERE user_id = ?");
                $stmt->execute([$username, $parcel_box_id, $_SESSION['user_id']]);
                $parcel_message = "Parcel box updated successfully.";
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO parcel_box (user_id, username, parcel_box_id) VALUES (?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $username, $parcel_box_id]);
                $parcel_message = "Parcel box added successfully.";
            }
        } catch (PDOException $e) {
            $parcel_message = "Failed to save parcel box: " . $e->getMessage();
            $parcel_error_flag = true;
        }
    } else {
        $parcel_message = "Username and Parcel Box ID are required.";
        $parcel_error_flag = true;
    }
    // Refetch parcel
    $stmt = $db->prepare("SELECT * FROM parcel_box WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $parcel = $stmt->fetch(PDO::FETCH_ASSOC);
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
        $register_error = "Registration failed: " . $e->getMessage();
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
        $login_error = "Invalid username or password.";
    }
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
    <title>Settings - Custom Calendar Service</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #6B7280, #3B82F6);
            color: #fff;
            overflow-x: hidden;
            margin: 0;
        }
        .hero, .form-section {
            position: relative;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            text-align: center;
            min-height: calc(100vh - 80px); /* Adjusted for nav height */
            padding-top: 80px; /* Space for fixed nav */
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
            padding: 1.5rem;
            width: 100%;
            box-sizing: border-box;
        }
        .hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            animation: fadeInDown 1s ease-out;
        }
        .hero-desc {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
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
        .cta-btn, .login-btn, .sync-btn, .remove-btn {
            background: #34D399;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            border: none;
            cursor: pointer;
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
        }
        .cta-btn:hover, .login-btn:hover, .sync-btn:hover, .remove-btn:hover {
            background: #2FB988;
            transform: scale(1.05);
        }
        .login-btn {
            background: #3B82F6;
        }
        .login-btn:hover {
            background: #2563EB;
        }
        .sync-btn {
            background: #3B82F6;
            padding: 0.5rem 1rem;
        }
        .sync-btn:hover {
            background: #2563EB;
        }
        .remove-btn {
            background: #EF4444;
            padding: 0.5rem 1rem;
        }
        .remove-btn:hover {
            background: #DC2626;
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
            gap: 1rem;
            z-index: 10;
            flex-wrap: wrap;
        }
        .menu a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
            font-size: 0.9rem;
        }
        .menu a:hover {
            color: #34D399;
        }
        footer {
            padding: 1.5rem;
            text-align: center;
            background: rgba(0, 0, 0, 0.2);
        }
        .form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }
        .form-input {
            padding: 0.75rem 1rem;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.8);
            color: #333;
            border: none;
            font-size: 0.9rem;
            width: 100%;
            box-sizing: border-box;
        }
        .error-msg {
            color: #ff6b6b;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        .link-text {
            margin-top: 1.5rem;
            font-size: 0.9rem;
        }
        .link, .back-link {
            color: #34D399;
            text-decoration: none;
            transition: color 0.3s;
        }
        .link:hover, .back-link:hover {
            color: #2FB988;
            text-decoration: underline;
        }
        .calendar-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5rem;
            font-size: 0.9rem;
        }
        .calendar-table th, .calendar-table td {
            padding: 0.5rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-align: left;
            word-break: break-all;
        }
        .calendar-table th {
            background: rgba(255, 255, 255, 0.1);
        }
        .calendar-table .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-start;
        }
        /* Mobile-specific adjustments */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 1.8rem;
            }
            .hero-desc {
                font-size: 1rem;
            }
            .menu {
                gap: 0.5rem;
                padding: 0.5rem;
            }
            .menu a {
                font-size: 0.8rem;
            }
            .form-content {
                padding: 1rem;
            }
            .form-section {
                padding-top: 60px; /* Adjusted for smaller nav */
                min-height: calc(100vh - 60px);
            }
            .cta-btn, .login-btn, .sync-btn, .remove-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
            .form-input {
                font-size: 0.8rem;
                padding: 0.5rem 1rem;
            }
            .error-msg, .link-text {
                font-size: 0.8rem;
            }
            .calendar-table {
                font-size: 0.8rem;
            }
            .calendar-table th, .calendar-table td {
                padding: 0.3rem;
            }
            h2 {
                font-size: 1.2rem;
            }
        }
        @media (max-width: 480px) {
            .hero-title {
                font-size: 1.5rem;
            }
            .hero-desc {
                font-size: 0.9rem;
            }
            .menu {
                flex-direction: column;
                align-items: center;
                gap: 0.3rem;
            }
            .form {
                max-width: 100%;
            }
            .calendar-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            .calendar-table thead, .calendar-table tbody, .calendar-table tr {
                display: block;
            }
            .calendar-table th, .calendar-table td {
                display: block;
                width: 100%;
                box-sizing: border-box;
            }
            .calendar-table .actions {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <nav class="menu">
        <a href="index.php#home">Home</a>
        <a href="weather.php">Weather</a>
        <a href="calendar.php">Calendar</a>
        <a href="settings.php">Settings</a>
        <a href="?logout=1">Logout</a>
    </nav>
    <?php if (isset($_SESSION['user_id'])): ?>
        <section class="form-section">
            <div class="form-content">
                <h1 class="hero-title">Settings</h1>
                <?php if ($calendar_message): ?>
                    <p class="<?php echo $calendar_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4"><?php echo $calendar_message; ?></p>
                <?php endif; ?>
                <h2 class="text-2xl font-bold mb-4">Add ICS Calendar</h2>
                <form method="POST" class="form">
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
                                <th>URL</th>
                                <th>Skip Outdated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($calendars as $cal): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cal['ics_url']); ?></td>
                                    <td><?php echo $cal['skip_outdated'] ? 'Yes' : 'No'; ?></td>
                                    <td class="actions">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="calendar_id" value="<?php echo $cal['calendar_id']; ?>">
                                            <button type="submit" name="sync_calendar" class="sync-btn">Sync Now</button>
                                        </form>
                                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to remove this calendar?');">
                                            <input type="hidden" name="calendar_id" value="<?php echo $cal['calendar_id']; ?>">
                                            <button type="submit" name="remove_calendar" class="remove-btn">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($parcel_message): ?>
                    <p class="<?php echo $parcel_error_flag ? 'error-msg' : 'text-green-500'; ?> mb-4 mt-6"><?php echo $parcel_message; ?></p>
                <?php endif; ?>
                <h2 class="text-2xl font-bold mt-6 mb-4">Parcel Box Settings</h2>
                <form method="POST" class="form">
                    <input type="text" name="username" placeholder="Username" required class="form-input" value="<?php echo htmlspecialchars($parcel['username'] ?? ''); ?>">
                    <input type="text" name="parcel_box_id" placeholder="Parcel Box ID" required class="form-input" value="<?php echo htmlspecialchars($parcel['parcel_box_id'] ?? ''); ?>">
                    <button type="submit" name="save_parcel_box" class="cta-btn">Save</button>
                </form>
                <p class="link-text mt-6"><a href="calendar.php" class="back-link">Back to Calendar</a></p>
            </div>
        </section>
    <?php else: ?>
        <section class="form-section">
            <div class="form-content">
                <h1 class="hero-title">Custom Calendar App</h1>
                <!-- Registration Form -->
                <?php if (isset($register_error) && $register_error): ?>
                    <p class="error-msg"><?php echo $register_error; ?></p>
                <?php endif; ?>
                <h2 class="text-2xl font-bold mb-4">Register</h2>
                <form method="POST" class="form mb-8">
                    <input type="text" name="username" placeholder="Username" required class="form-input">
                    <input type="password" name="password" placeholder="Password" required class="form-input">
                    <button type="submit" name="register" class="cta-btn">Register</button>
                </form>
                <!-- Login Form -->
                <?php if (isset($login_error) && $login_error): ?>
                    <p class="error-msg"><?php echo $login_error; ?></p>
                <?php endif; ?>
                <h2 class="text-2xl font-bold mb-4">Login</h2>
                <form method="POST" class="form">
                    <input type="text" name="username" placeholder="Username" required class="form-input">
                    <input type="password" name="password" placeholder="Password" required class="form-input">
                    <button type="submit" name="login" class="login-btn">Login</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
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