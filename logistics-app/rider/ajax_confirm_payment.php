<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';

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
    SELECT b.id, b.booking_code, b.booking_status, b.payment_status, b.rider_payment_confirmed, b.agreed_cost,
           b.item_name, b.sender_user_id, s.full_name AS sender_full_name, s.email AS sender_email
    FROM bookings b
    INNER JOIN users s ON s.id = b.sender_user_id
    WHERE b.id = ? AND b.selected_rider_user_id = ?
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

$payoutAmount = rider_payout_amount((float) $booking['agreed_cost']);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('UPDATE bookings SET rider_payment_confirmed = 1, rider_payment_confirmed_at = NOW() WHERE id = ?');
    $stmt->execute([$bookingId]);

    $stmt = $pdo->prepare('
        INSERT INTO wallet_transactions (rider_user_id, booking_id, type, amount, description)
        VALUES (?, ?, "earning", ?, ?)
    ');
    $stmt->execute([
        $user['id'],
        $bookingId,
        $payoutAmount,
        sprintf('Delivery %s', $booking['booking_code']),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to confirm payment: ' . $e->getMessage()]);
    exit;
}

send_order_completion_email($booking['sender_email'], $booking['sender_full_name'], [
    'booking_code' => $booking['booking_code'],
    'item_name' => $booking['item_name'],
    'agreed_cost' => $booking['agreed_cost'],
]);

echo json_encode(['success' => true, 'message' => 'Payment confirmed. Job closed out.']);
// Send the response now; the deferred email dispatch above runs afterward via a
// shutdown function, so a slow/unreachable mail server can never delay this reply.
mailer_flush_response();
