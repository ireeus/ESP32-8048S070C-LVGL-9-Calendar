<?php
// Database connection
try {
    $db = new PDO('sqlite:access.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database Error: ' . $e->getMessage()]);
    exit;
}

if (isset($_GET['background_img'])) {
    $access_code = $_GET['background_img'];
    $stmt = $db->prepare("SELECT background_image FROM users WHERE access_code = ?");
    $stmt->execute([$access_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    if ($row && $row['background_image']) {
        echo json_encode(['filename' => $row['background_image']]);
    } else {
        echo json_encode(['filename' => null]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing access_code']);
}
?>