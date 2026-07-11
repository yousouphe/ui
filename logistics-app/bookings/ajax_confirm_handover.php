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
    SELECT id, sender_user_id, booking_status, selected_rider_user_id, sender_handover_confirmed
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

if (($booking['booking_status'] ?? '') !== 'arrived_at_pickup') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Item can only be issued when rider has arrived.']);
    exit;
}

if (empty($booking['selected_rider_user_id'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No rider assigned to this booking.']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE bookings
    SET sender_handover_confirmed = 1,
        sender_handover_confirmed_at = NOW()
    WHERE id = ?
");
$stmt->execute([$bookingId]);

echo json_encode(['success' => true, 'message' => 'Item issued to rider successfully.']);