<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$bookingId = (int)($_GET['booking_id'] ?? 0);

if ($bookingId <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $pdo->prepare('SELECT id, booking_status, sender_user_id FROM bookings WHERE id = ? LIMIT 1');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking || ((int)$booking['sender_user_id'] !== (int)$user['id'] && ($user['role'] ?? '') !== 'admin')) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $pdo->prepare('
    SELECT rr.id, rr.rider_user_id, rr.request_status, rr.proposed_cost, u.full_name, rp.vehicle_type
    FROM rider_requests rr
    INNER JOIN users u ON u.id = rr.rider_user_id
    LEFT JOIN rider_profiles rp ON rp.user_id = rr.rider_user_id
    WHERE rr.booking_id = ?
    ORDER BY rr.id DESC
    LIMIT 1
');
$stmt->execute([$bookingId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

echo json_encode([
    'success' => true,
    'booking_status' => $booking['booking_status'],
    'request' => $request,
]);
