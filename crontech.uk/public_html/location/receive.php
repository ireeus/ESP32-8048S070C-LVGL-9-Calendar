<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log all errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Check if SQLite3 extension is loaded
if (!class_exists('SQLite3')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'SQLite3 extension not available']);
    exit;
}

// Initialize SQLite database
try {
    $db = new SQLite3(__DIR__ . '/locations.db');
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    error_log('SQLite3 init error: ' . $e->getMessage());
    exit;
}

// Check if user_id column exists and add it if not
try {
    $result = $db->query("PRAGMA table_info(coordinates)");
    $hasUserId = false;
    while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($column['name'] === 'user_id') {
            $hasUserId = true;
            break;
        }
    }
    if (!$hasUserId) {
        $db->exec('ALTER TABLE coordinates ADD COLUMN user_id TEXT NOT NULL DEFAULT "unknown"');
        error_log('Added user_id column to coordinates table');
    }
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Failed to check or modify table schema: ' . $e->getMessage()]);
    error_log('Schema check/modify error: ' . $e->getMessage());
    exit;
}

// Create table if it doesn't exist (for new databases)
try {
    $db->exec('CREATE TABLE IF NOT EXISTS coordinates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Table creation failed: ' . $e->getMessage()]);
    error_log('Table creation error: ' . $e->getMessage());
    exit;
}

// Handle GET request for GPS coordinates
header('Content-Type: application/json');

$latitude = filter_input(INPUT_GET, 'latitude', FILTER_VALIDATE_FLOAT);
$longitude = filter_input(INPUT_GET, 'longitude', FILTER_VALIDATE_FLOAT);
$userId = filter_input(INPUT_GET, 'userId', FILTER_SANITIZE_SPECIAL_CHARS);

// Log incoming request for debugging
error_log("Received request: latitude=$latitude, longitude=$longitude, userId=$userId");

if ($latitude === false || $longitude === false) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing coordinates']);
    exit;
}

if ($userId === false || $userId === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing user ID']);
    exit;
}

try {
    $stmt = $db->prepare('INSERT INTO coordinates (user_id, latitude, longitude) VALUES (:userId, :lat, :lon)');
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $db->lastErrorMsg());
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_TEXT);
    $stmt->bindValue(':lat', $latitude, SQLITE3_FLOAT);
    $stmt->bindValue(':lon', $longitude, SQLITE3_FLOAT);
    $result = $stmt->execute();
    if (!$result) {
        throw new Exception('Execute failed: ' . $db->lastErrorMsg());
    }
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database insert failed: ' . $e->getMessage()]);
    error_log('Insert error: ' . $e->getMessage());
}

// Close database connection
$db->close();
?>