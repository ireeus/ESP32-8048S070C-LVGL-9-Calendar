// set_location.php
<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['csrf_token']) || $input['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    if (isset($input['lat']) && isset($input['lon'])) {
        $_SESSION['device_lat'] = filter_var($input['lat'], FILTER_VALIDATE_FLOAT);
        $_SESSION['device_lon'] = filter_var($input['lon'], FILTER_VALIDATE_FLOAT);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
?>