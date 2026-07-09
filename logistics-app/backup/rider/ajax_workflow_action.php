<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = (int)($input['booking_id'] ?? 0);
$action = (string)($input['action'] ?? '');

if ($bookingId <= 0 || $action === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$map = [
    'arrived_at_pickup' => ['matched', 'accepted'],
    'package_received'  => ['arrived_at_pickup'],
    'delivered'         => ['package_received', 'in_transit'],
];

if (!isset($map[$action])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$user = current_user();

$stmt = $pdo->prepare('
    SELECT id, booking_status
    FROM bookings
    WHERE id = ? AND selected_rider_user_id = ?
    LIMIT 1
');
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit;
}

if (!in_array($booking['booking_status'], $map[$action], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Booking is not in the expected state.']);
    exit;
}

$stmt = $pdo->prepare('UPDATE bookings SET booking_status = ?, updated_at = NOW() WHERE id = ?');
$stmt->execute([$action, $bookingId]);

$message = match ($action) {
    'arrived_at_pickup' => 'Arrival confirmed. You can now confirm package receipt.',
    'package_received'  => 'Package received. Route switched to delivery point.',
    'delivered'         => 'Delivery completed successfully.',
    default             => 'Status updated.'
};

echo json_encode([
    'success' => true,
    'new_status' => $action,
    'message' => $message
]);