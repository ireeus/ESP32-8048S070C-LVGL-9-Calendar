<?php
session_start();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
$members_file = __DIR__ . '/ctta_members.json';

function getMembers() {
    global $members_file;
    if (!file_exists($members_file)) return [];
    $data = file_get_contents($members_file);
    $decoded = json_decode($data, true);
    return is_array($decoded) ? $decoded : [];
}

function saveMembers($members) {
    global $members_file;
    file_put_contents($members_file, json_encode($members, JSON_PRETTY_PRINT));
}

// PUBLIC API: VERIFY MEMBER
if ($action === 'verify') {
    $query = strtolower(trim($_GET['query'] ?? ''));
    if (empty($query)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a search term.']);
        exit;
    }

    $members = getMembers();
    $results = [];

    foreach ($members as $m) {
        $id_match = strtolower($m['id']) === $query;
        $name_match = strpos(strtolower($m['name']), $query) !== false;
        
        if ($id_match || $name_match) {
            $is_expired = (strtotime($m['insurance_expiry']) < time()) || (strtotime($m['calibration_expiry']) < time());
            $display_status = ($m['status'] === 'active' && !$is_expired) ? 'ACTIVE & VERIFIED' : 'EXPIRED / INACTIVE';
            
            $results[] = [
                'id' => $m['id'],
                'name' => $m['name'],
                'company' => $m['company'],
                'qualifications' => $m['qualifications'],
                'status' => $display_status,
                'valid_until' => min($m['insurance_expiry'], $m['calibration_expiry'])
            ];
        }
    }

    echo json_encode(['success' => true, 'data' => $results]);
    exit;
}

// ADMIN APIs (Require Login)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($action === 'add') {
    $members = getMembers();
    
    // Auto-generate ID: CTTA-00X
    $num = count($members) + 1;
    $new_id = "CTTA-" . str_pad($num, 3, "0", STR_PAD_LEFT);
    
    $new_member = [
        'id' => $new_id,
        'name' => $input['name'] ?? '',
        'company' => $input['company'] ?? '',
        'qualifications' => $input['qualifications'] ?? '',
        'insurance_expiry' => $input['insurance_expiry'] ?? '',
        'calibration_expiry' => $input['calibration_expiry'] ?? '',
        'status' => $input['status'] ?? 'active',
        'joined_date' => date('Y-m-d')
    ];
    
    array_unshift($members, $new_member);
    saveMembers($members);
    
    echo json_encode(['success' => true, 'message' => 'Member added successfully!']);
    exit;
}

if ($action === 'delete') {
    $members = getMembers();
    $id_to_delete = $input['id'] ?? '';
    
    $members = array_filter($members, function($m) use ($id_to_delete) {
        return $m['id'] !== $id_to_delete;
    });
    
    saveMembers(array_values($members)); 
    echo json_encode(['success' => true, 'message' => 'Member removed.']);
    exit;
}
