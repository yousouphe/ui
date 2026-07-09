<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$input = json_decode(file_get_contents('php://input'), true);

$bookingId = (int)($input['booking_id'] ?? 0);
$reason = trim((string)($input['reason'] ?? ''));

if ($bookingId <= 0 || $reason === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Booking ID and cancellation reason are required.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, sender_user_id, booking_status, payment_status
    FROM bookings
    WHERE id = ? AND sender_user_id = ?
    LIMIT 1
");
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit;
}

if (($booking['payment_status'] ?? 'unpaid') === 'paid') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Paid booking cannot be cancelled.']);
    exit;
}

if (!in_array(($booking['booking_status'] ?? ''), ['matched', 'accepted', 'arrived_at_pickup', 'package_received', 'in_transit'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This booking cannot be cancelled at its current stage.']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE bookings
    SET booking_status = 'cancelled',
        cancellation_reason = ?,
        cancelled_by = 'sender'
    WHERE id = ?
");
$stmt->execute([$reason, $bookingId]);

echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully.']);