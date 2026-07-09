<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

require_csrf();

$user = current_user();
$bookingId = (int)($_POST['booking_id'] ?? 0);
$status = (string)($_POST['status'] ?? '');

$allowedStatuses = ['matched', 'accepted', 'arrived_at_pickup', 'package_received', 'in_transit', 'delivered', 'completed', 'cancelled'];

if ($bookingId <= 0 || !in_array($status, $allowedStatuses, true)) {
    http_response_code(422);
    echo 'invalid request';
    exit;
}

$stmt = $pdo->prepare('SELECT id FROM bookings WHERE id = ? AND selected_rider_user_id = ? LIMIT 1');
$stmt->execute([$bookingId, $user['id']]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo 'booking not found';
    exit;
}

if ($status === 'completed') {
    $stmt = $pdo->prepare('UPDATE bookings SET booking_status = "delivered", completed_at = NOW() WHERE id = ?');
} else {
    $stmt = $pdo->prepare('UPDATE bookings SET booking_status = ? WHERE id = ?');
}

if ($stmt->execute([$status, $bookingId])) {
    echo "success";
}