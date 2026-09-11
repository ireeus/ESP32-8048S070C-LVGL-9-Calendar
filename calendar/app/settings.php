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
    die("Database Error: Unable to connect to the database.");
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
// Fetch saved ICS calendars
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
    $parcel = $stmt->fetch(PDO::FETCH_ASSOC);
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
    <link rel="manifest" href="/manifest.json">
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
        .hero, .form-section {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            padding: 4rem 2rem;
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
            width: 100%;
            padding: 2rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
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
        .sync-btn {
            background: #3B82F6;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s, transform 0.3s;
            border: none;
            cursor: pointer;
        }
        .sync-btn:hover {
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
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
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
            .form-input, .cta-btn, .login-btn, .sync-btn, .remove-btn {
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
            .form-input, .cta-btn, .login-btn, .sync-btn, .remove-btn {
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
    <?php if (isset($_SESSION['user_id'])): ?>
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
                <p class="link-text mt-6"><a href="calendar.php" class="link-text">Back to Calendar</a></p>
            </div>
        </section>
    <?php else: ?>
        <section class="form-section">
            <div class="form-content">
                <h1 class="hero-title">Custom Calendar App</h1>
                <?php if (isset($register_error) && $register_error): ?>
                    <p class="error-msg"><?php echo htmlspecialchars($register_error); ?></p>
                <?php endif; ?>
                <h2 class="text-2xl font-bold mb-4">Register</h2>
                <form method="POST" class="form mb-8">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="text" name="username" placeholder="Username" required class="form-input">
                    <input type="password" name="password" placeholder="Password" required class="form-input">
                    <button type="submit" name="register" class="cta-btn">Register</button>
                </form>
                <?php if (isset($login_error) && $login_error): ?>
                    <p class="error-msg"><?php echo htmlspecialchars($login_error); ?></p>
                <?php endif; ?>
                <h2 class="text-2xl font-bold mb-4">Login</h2>
                <form method="POST" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
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