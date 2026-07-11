<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$data = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($data) ? $data : null);

$status = in_array(($data['status'] ?? ''), ['available', 'busy', 'offline'], true)
    ? $data['status']
    : null;

if ($status === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
    exit;
}

if ($status === 'available') {
    $stmt = $pdo->prepare('SELECT kyc_status FROM rider_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $kycStatus = $stmt->fetchColumn();
    if ($kycStatus !== 'approved') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => t('rider.kyc_not_approved')]);
        exit;
    }
}

$stmt = $pdo->prepare('UPDATE rider_profiles SET availability_status = ? WHERE user_id = ?');
$stmt->execute([$status, $user['id']]);
echo json_encode(['success' => true]);