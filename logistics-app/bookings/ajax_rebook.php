<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$input = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($input) ? $input : null);

$bookingId = (int)($input['booking_id'] ?? 0);

if ($bookingId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
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

if (($booking['booking_status'] ?? '') !== 'cancelled') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Only cancelled bookings can be rebooked.']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE bookings
    SET booking_status = 'submitted',
        selected_rider_user_id = NULL,
        cancellation_reason = NULL,
        cancelled_by = NULL,
        sender_handover_confirmed = 0,
        sender_handover_confirmed_at = NULL
    WHERE id = ?
");
$stmt->execute([$bookingId]);

$stmt = $pdo->prepare("
    UPDATE rider_requests
    SET request_status = 'rejected'
    WHERE booking_id = ? AND request_status = 'accepted'
");
$stmt->execute([$bookingId]);

echo json_encode(['success' => true, 'message' => 'Booking reopened for rider matching.']);