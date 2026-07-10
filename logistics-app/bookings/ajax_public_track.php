<?php
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    echo json_encode(['status' => false]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        b.booking_status,
        b.pickup_latitude,
        b.pickup_longitude,
        b.delivery_latitude,
        b.delivery_longitude,
        rp.last_latitude,
        rp.last_longitude,
        rp.vehicle_type,
        UNIX_TIMESTAMP(COALESCE(rp.last_location_updated_at, b.updated_at, b.created_at)) AS state_version
    FROM bookings b
    LEFT JOIN rider_profiles rp ON rp.user_id = b.selected_rider_user_id
    WHERE b.sender_tracking_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo json_encode(['status' => false]);
    exit;
}

$etag = sha1(implode('|', [
    $token,
    $data['booking_status'] ?? '',
    $data['last_latitude'] ?? '',
    $data['last_longitude'] ?? '',
    $data['state_version'] ?? ''
]));
response_cache_headers($etag, 5);

echo json_encode([
    'status' => true,
    'data' => [
        'booking_status' => $data['booking_status'],
        'rider_lat' => $data['last_latitude'],
        'rider_lng' => $data['last_longitude'],
        'vehicle_type' => $data['vehicle_type'],
        'pickup_lat' => $data['pickup_latitude'],
        'pickup_lng' => $data['pickup_longitude'],
        'delivery_lat' => $data['delivery_latitude'],
        'delivery_lng' => $data['delivery_longitude'],
    ]
]);
