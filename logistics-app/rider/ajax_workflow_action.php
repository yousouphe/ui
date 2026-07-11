<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/push.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($input) ? $input : null);
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
    SELECT id, booking_status, sender_user_id, booking_code
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

switch ($action) {
    case 'arrived_at_pickup':
        $message = 'Arrival confirmed. You can now confirm package receipt.';
        $pushTitle = 'Your rider has arrived';
        $pushBody = 'Your rider is at the pickup location for booking ' . $booking['booking_code'] . '.';
        break;
    case 'package_received':
        $message = 'Package received. Route switched to delivery point.';
        $pushTitle = 'Package picked up';
        $pushBody = 'Your rider has your package and is heading to the delivery address for booking ' . $booking['booking_code'] . '.';
        break;
    case 'delivered':
        $message = 'Delivery completed successfully.';
        $pushTitle = 'Delivered';
        $pushBody = 'Booking ' . $booking['booking_code'] . ' has been delivered.';
        break;
    default:
        $message = 'Status updated.';
        $pushTitle = null;
        $pushBody = null;
        break;
}

if ($pushTitle !== null) {
    send_web_push($pdo, (int) $booking['sender_user_id'], $pushTitle, $pushBody, url_path('bookings/index.php?booking_id=' . $bookingId));
}

echo json_encode([
    'success' => true,
    'new_status' => $action,
    'message' => $message
]);