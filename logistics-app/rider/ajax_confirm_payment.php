<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($input) ? $input : null);
$bookingId = (int)($input['booking_id'] ?? 0);

if ($bookingId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$user = current_user();

$stmt = $pdo->prepare('
    SELECT id, booking_status, payment_status, rider_payment_confirmed
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

if ($booking['booking_status'] !== 'delivered') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Booking has not been delivered yet.']);
    exit;
}

if ((int) $booking['rider_payment_confirmed'] === 1) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Payment has already been confirmed for this booking.']);
    exit;
}

if (($booking['payment_status'] ?? 'unpaid') !== 'paid') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Sender has not paid for this booking yet.']);
    exit;
}

$stmt = $pdo->prepare('UPDATE bookings SET rider_payment_confirmed = 1, rider_payment_confirmed_at = NOW() WHERE id = ?');
$stmt->execute([$bookingId]);

echo json_encode(['success' => true, 'message' => 'Payment confirmed. Job closed out.']);
