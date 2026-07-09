<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$bookingId = (int)($_GET['booking_id'] ?? 0);

if ($bookingId <= 0) {
    echo json_encode(['status' => false]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        b.booking_status,
        b.payment_status,
        b.delivery_latitude,
        b.delivery_longitude,
        b.pickup_latitude,
        b.pickup_longitude,
        rp.last_latitude,
        rp.last_longitude,
        rp.availability_status
    FROM bookings b
    LEFT JOIN rider_profiles rp 
        ON rp.user_id = b.selected_rider_user_id
    WHERE b.id = ?
      AND b.sender_user_id = ?
    LIMIT 1
");
$stmt->execute([$bookingId, $user['id']]);
$data = $stmt->fetch();

if (!$data) {
    echo json_encode(['status' => false]);
    exit;
}

echo json_encode([
    'status' => true,
    'data' => [
        'booking_status' => $data['booking_status'],
        'payment_status' => $data['payment_status'],
        'rider_lat' => $data['last_latitude'],
        'rider_lng' => $data['last_longitude'],
        'pickup_lat' => $data['pickup_latitude'],
        'pickup_lng' => $data['pickup_longitude'],
        'delivery_lat' => $data['delivery_latitude'],
        'delivery_lng' => $data['delivery_longitude'],
    ]
]);