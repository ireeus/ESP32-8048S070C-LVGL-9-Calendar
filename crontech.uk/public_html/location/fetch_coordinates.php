<?php
// Connect to SQLite database
try {
    $db = new SQLite3('locations.db');
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get userId and date from GET parameters
$userId = isset($_GET['userId']) ? filter_input(INPUT_GET, 'userId', FILTER_SANITIZE_SPECIAL_CHARS) : null;
$date = isset($_GET['date']) ? filter_input(INPUT_GET, 'date', FILTER_SANITIZE_SPECIAL_CHARS) : date('Y-m-d');

// Validate inputs
if (!$userId) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No userId provided']);
    exit;
}

// Build query for coordinates table
$coordinates = [];
try {
    $query = 'SELECT latitude, longitude, timestamp, user_id FROM coordinates';
    $where = [];
    $params = [];

    $where[] = 'user_id = :userId';
    $params[':userId'] = $userId;

    if ($date) {
        $where[] = "strftime('%Y-%m-%d', timestamp) = :date";
        $params[':date'] = $date;
    }

    if (!empty($where)) {
        $query .= ' WHERE ' . implode(' AND ', $where);
    }

    $query .= ' ORDER BY timestamp DESC';

    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $db->lastErrorMsg());
    }

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, $key === ':userId' ? SQLITE3_TEXT : SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    if (!$result) {
        throw new Exception('Query execution failed: ' . $db->lastErrorMsg());
    }

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $coordinates[] = $row;
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
    exit;
}

// Set JSON header and output coordinates
header('Content-Type: application/json');
echo json_encode($coordinates);
exit;
?>