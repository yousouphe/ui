<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    $lat = isset($data['latitude']) ? (float)$data['latitude'] : null;
    $lng = isset($data['longitude']) ? (float)$data['longitude'] : null;
    $status = $data['status'] ?? 'available';

    $stmt = $pdo->prepare('UPDATE rider_profiles SET last_latitude = ?, last_longitude = ?, availability_status = ?, last_location_updated_at = NOW() WHERE user_id = ?');
    $stmt->execute([$lat, $lng, $status, $user['id']]);
    
    echo json_encode(['success' => true, 'time' => date('H:i:s')]);
    exit;
}

echo json_encode(['success' => false]);