<?php
/* Fetch events
 * URL request: api.php?code={access_code}
 * Description: Retrieves events for a user identified by access_code, not older than 1 month. Syncs ICS calendars if needed.
 */
/* Save new event
 * URL request: api.php?unique_code={unique_code}&title={title}&message_description={description}&from={start_time}&to={end_time}&remind_before={remind_before}
 * Description: Saves a new event for a user identified by unique_code with provided title, description, start time, end time, and reminder settings.
 */
/* Fetch or update parcel box
 * URL request (fetch): api.php?parcelBox={access_code}
 * URL request (update): api.php?parcelBox={access_code}&parcel_box_username={username}&parcel_box_id={id}
 * Description: Fetches parcel box details for a user if only access_code is provided; updates or inserts parcel box details if username and parcel_box_id are also provided.
 */
/* Fetch or update weather location
 * URL request (fetch): api.php?weatherLocation={access_code}
 * URL request (update): api.php?weatherLocation={access_code}&city_name={city}
 * Description: Fetches weather location details for a user if only access_code is provided; updates or inserts weather location details with city_name, fetching latitude and longitude, if city_name is provided.
 */
 date_default_timezone_set('Europe/London'); // Change this to your specific timezone
// Log the exact URL requested
file_put_contents('log.txt', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
// Database connection
try {
    $db = new PDO('sqlite:access.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
        location_type TEXT NOT NULL,
        city_name TEXT NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Check and add last_sync column to ics_calendars
    $columns_ics = $db->query("PRAGMA table_info(ics_calendars)")->fetchAll(PDO::FETCH_ASSOC);
    $hasLastSync = false;
    foreach ($columns_ics as $col) {
        if ($col['name'] === 'last_sync') $hasLastSync = true;
    }
    if (!$hasLastSync) {
        $db->exec("ALTER TABLE ics_calendars ADD COLUMN last_sync DATETIME");
    }
    // Check and add remind_before column to events
    $columns_events = $db->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC);
    $hasRemindBefore = false;
    foreach ($columns_events as $col) {
        if ($col['name'] === 'remind_before') $hasRemindBefore = true;
    }
    if (!$hasRemindBefore) {
        $db->exec("ALTER TABLE events ADD COLUMN remind_before TEXT");
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error: ' . $e->getMessage()]);
    exit;
}
// Helper function to fetch latitude and longitude from city name using Nominatim API
function get_coordinates_from_city(string $city_name): ?array {
    $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($city_name) . "&limit=1";
    $opts = [
        'http' => [
            'header' => "User-Agent: ParcelBoxApp/1.0\r\n",
            'timeout' => 5
        ]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    $data = json_decode($response, true);
    if (empty($data) || !isset($data[0]['lat']) || !isset($data[0]['lon'])) {
        return null;
    }
    return [
        'latitude' => (float) $data[0]['lat'],
        'longitude' => (float) $data[0]['lon']
    ];
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
// Check which parameter is provided
if (isset($_GET['code'])) {
    // Retrieve events not older than 1 month
    $access_code = urldecode($_GET['code']);
    if (empty($access_code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Access code required']);
        exit;
    }
    // Find user_id from access_code
    $stmt = $db->prepare("SELECT user_id FROM users WHERE access_code = ?");
    $stmt->execute([$access_code]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid access code']);
        exit;
    }
    $user_id = $user['user_id'];
    // Sync ICS calendars if needed
    $stmt = $db->prepare("SELECT * FROM ics_calendars WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $current_time = time();
    foreach ($calendars as $cal) {
        $last_sync = $cal['last_sync'] ? strtotime($cal['last_sync']) : 0;
        if ($current_time - $last_sync >= 600) {
            // Perform sync
            $ics_url = $cal['ics_url'];
            $skip_outdated = $cal['skip_outdated'];
            // Fetch ICS content
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
            if ($icsContent !== false) {
                $parsedEvents = parse_ics($icsContent);
                foreach ($parsedEvents as $event) {
                    if ($skip_outdated && strtotime($event['end_time']) < $current_time) {
                        continue;
                    }
                    $uid = $event['google_event_id'];
                    $stmt_check = $db->prepare("SELECT event_id FROM events WHERE google_event_id = ? AND user_id = ? AND source = 'ics' AND ics_calendar_id = ?");
                    $stmt_check->execute([$uid, $user_id, $cal['calendar_id']]);
                    if ($stmt_check->fetch()) {
                        // Update
                        $stmt_update = $db->prepare("UPDATE events SET summary = ?, start_time = ?, end_time = ?, description = ?, all_day = ?, remind_before = '' WHERE google_event_id = ? AND user_id = ? AND source = 'ics' AND ics_calendar_id = ?");
                        $stmt_update->execute([
                            $event['summary'], $event['start_time'], $event['end_time'], $event['description'], $event['all_day'],
                            $uid, $user_id, $cal['calendar_id']
                        ]);
                    } else {
                        // Insert
                        $stmt_insert = $db->prepare("INSERT INTO events (user_id, summary, start_time, end_time, description, all_day, source, google_event_id, ics_calendar_id, remind_before) VALUES (?, ?, ?, ?, ?, ?, 'ics', ?, ?, '')");
                        $stmt_insert->execute([
                            $user_id, $event['summary'], $event['start_time'], $event['end_time'], $event['description'], $event['all_day'],
                            $uid, $cal['calendar_id']
                        ]);
                    }
                }
                // Remove deleted
                if (!empty($parsedEvents)) {
                    $current_uids = array_column($parsedEvents, 'google_event_id');
                    $placeholders = implode(',', array_fill(0, count($current_uids), '?'));
                    $stmt_delete = $db->prepare("DELETE FROM events WHERE user_id = ? AND source = 'ics' AND ics_calendar_id = ? AND google_event_id NOT IN ($placeholders)");
                    $params = array_merge([$user_id, $cal['calendar_id']], $current_uids);
                    $stmt_delete->execute($params);
                } else {
                    $stmt_delete = $db->prepare("DELETE FROM events WHERE user_id = ? AND source = 'ics' AND ics_calendar_id = ?");
                    $stmt_delete->execute([$user_id, $cal['calendar_id']]);
                }
                // Update last_sync
                $stmt_update_sync = $db->prepare("UPDATE ics_calendars SET last_sync = CURRENT_TIMESTAMP WHERE calendar_id = ?");
                $stmt_update_sync->execute([$cal['calendar_id']]);
            }
        }
    }
    // Calculate date 1 month ago from current date
    $oneMonthAgo = date('Y-m-d H:i:s', strtotime('-1 month'));
    // Fetch events not older than 1 month
    $stmt = $db->prepare("SELECT summary, start_time AS start, end_time AS end, description, remind_before
                         FROM events
                         WHERE user_id = ? AND start_time >= ?
                         ORDER BY start_time ASC");
    $stmt->execute([$user_id, $oneMonthAgo]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['events' => $events]);
} elseif (isset($_GET['unique_code'])) {
    // Save new event
    $unique_code = urldecode($_GET['unique_code']);
    $title = urldecode($_GET['title'] ?? '');
    $message_description = urldecode($_GET['message_description'] ?? '');
    $from = urldecode($_GET['from'] ?? '');
    $to = urldecode($_GET['to'] ?? '');
    $remind_before = urldecode($_GET['remind_before'] ?? '');
    // Validate required parameters
    if (empty($unique_code) || empty($title) || empty($message_description) || empty($from) || empty($to) || empty($remind_before)) {
        http_response_code(400);
        echo json_encode(['error' => 'All parameters (unique_code, title, message_description, from, to, remind_before) are required']);
        exit;
    }
    // Replace %20 with space and clean any stray quotes or invalid characters
    $from = str_replace('%20', ' ', trim($from, '"\''));
    $to = str_replace('%20', ' ', trim($to, '"\''));
    // Validate date format
    $start_date = DateTime::createFromFormat('Y-m-d H:i:s', $from);
    $end_date = DateTime::createFromFormat('Y-m-d H:i:s', $to);
    // Debugging: Log the parsed dates
    if (!$start_date || !$end_date) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid date format. Use YYYY-MM-DD HH:MM:SS',
            'debug' => [
                'from_received' => $from,
                'to_received' => $to,
                'start_date_valid' => $start_date !== false,
                'end_date_valid' => $end_date !== false
            ]
        ]);
        exit;
    }
    // Find user_id from unique_code
    $stmt = $db->prepare("SELECT user_id FROM users WHERE access_code = ?");
    $stmt->execute([$unique_code]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid unique code']);
        exit;
    }
    $user_id = $user['user_id'];
    // Insert new event
    try {
        $stmt = $db->prepare("INSERT INTO events (user_id, summary, description, start_time, end_time, remind_before)
                             VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $message_description, $from, $to, $remind_before]);
   
        header('Content-Type: application/json');
        echo json_encode(['message' => 'Event saved successfully']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save event: ' . $e->getMessage()]);
        exit;
    }
} elseif (isset($_GET['parcelBox'])) {
    $access_code = urldecode($_GET['parcelBox']);
    $username = urldecode($_GET['parcel_box_username'] ?? '');
    $parcel_box_id = urldecode($_GET['parcel_box_id'] ?? '');
    if (empty($access_code)) {
        http_response_code(400);
        echo json_encode(['error' => 'parcelBox parameter is required']);
        exit;
    }
    // Find user_id from access_code
    $stmt = $db->prepare("SELECT user_id FROM users WHERE access_code = ?");
    $stmt->execute([$access_code]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid access code']);
        exit;
    }
    $user_id = $user['user_id'];
    // Check if update or fetch
    if (!empty($username) && !empty($parcel_box_id)) {
        // Update/Insert
        try {
            $stmt = $db->prepare("SELECT id FROM parcel_box WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                // Update
                $stmt = $db->prepare("UPDATE parcel_box SET username = ?, parcel_box_id = ?, timestamp = CURRENT_TIMESTAMP WHERE user_id = ?");
                $stmt->execute([$username, $parcel_box_id, $user_id]);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO parcel_box (user_id, username, parcel_box_id) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $username, $parcel_box_id]);
            }
            header('Content-Type: application/json');
            echo json_encode(['username' => $username, 'parcel_box_id' => $parcel_box_id]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save parcel box: ' . $e->getMessage()]);
            exit;
        }
    } elseif (empty($username) && empty($parcel_box_id)) {
        // Fetch
        try {
            $stmt = $db->prepare("SELECT username, parcel_box_id FROM parcel_box WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                header('Content-Type: application/json');
                echo json_encode(['username' => $row['username'], 'parcel_box_id' => $row['parcel_box_id']]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'No parcel box details found']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch parcel box: ' . $e->getMessage()]);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Both parcel_box_username and parcel_box_id must be provided for update, or neither for fetch']);
        exit;
    }
} elseif (isset($_GET['weatherLocation'])) {
    $access_code = urldecode($_GET['weatherLocation']);
    $city_name = urldecode($_GET['city_name'] ?? '');
    if (empty($access_code)) {
        http_response_code(400);
        echo json_encode(['error' => 'weatherLocation parameter is required']);
        exit;
    }
    // Find user_id from access_code
    $stmt = $db->prepare("SELECT user_id FROM users WHERE access_code = ?");
    $stmt->execute([$access_code]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid access code']);
        exit;
    }
    $user_id = $user['user_id'];
    // Check if update or fetch
    if (!empty($city_name)) {
        // Update/Insert
        try {
            // Fetch coordinates for the city
            $coordinates = get_coordinates_from_city($city_name);
            if ($coordinates === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Could not fetch coordinates for city: ' . $city_name]);
                exit;
            }
            $latitude = $coordinates['latitude'];
            $longitude = $coordinates['longitude'];
            $location_type = 'city'; // Default location type
            $stmt = $db->prepare("SELECT id FROM weather_preferences WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                // Update
                $stmt = $db->prepare("UPDATE weather_preferences SET location_type = ?, city_name = ?, latitude = ?, longitude = ?, timestamp = CURRENT_TIMESTAMP WHERE user_id = ?");
                $stmt->execute([$location_type, $city_name, $latitude, $longitude, $user_id]);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO weather_preferences (user_id, location_type, city_name, latitude, longitude) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $location_type, $city_name, $latitude, $longitude]);
            }
            header('Content-Type: application/json');
            echo json_encode(['city_name' => $city_name, 'latitude' => $latitude, 'longitude' => $longitude]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save weather location: ' . $e->getMessage()]);
            exit;
        }
    } else {
        // Fetch
        try {
            $stmt = $db->prepare("SELECT location_type, city_name, latitude, longitude FROM weather_preferences WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                header('Content-Type: application/json');
                echo json_encode([
                    'location_type' => $row['location_type'],
                    'city_name' => $row['city_name'],
                    'latitude' => (float) $row['latitude'],
                    'longitude' => (float) $row['longitude']
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'No weather location details found']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch weather location: ' . $e->getMessage()]);
            exit;
        }
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Either code, unique_code, parcelBox, or weatherLocation parameter is required']);
    exit;
}
?>