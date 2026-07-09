<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$input = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($input) ? $input : null);

$bookingId = (int)($input['booking_id'] ?? 0);
$receiverUserId = (int)($input['receiver_user_id'] ?? 0);
$message = trim((string)($input['message'] ?? ''));

if ($bookingId <= 0 || $receiverUserId <= 0 || $message === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Booking, receiver and message are required.']);
    exit;
}

if (!db_table_exists($pdo, 'booking_chat_messages')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Chat table is missing. Run the performance migration SQL file first.']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, sender_user_id, selected_rider_user_id
    FROM bookings
    WHERE id = ?
      AND (sender_user_id = ? OR selected_rider_user_id = ?)
    LIMIT 1
");
$stmt->execute([$bookingId, $user['id'], $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized chat access.']);
    exit;
}

$allowedIds = array_values(array_filter([(int)$booking['sender_user_id'], (int)$booking['selected_rider_user_id']]));
if (!in_array($receiverUserId, $allowedIds, true) || $receiverUserId === (int)$user['id']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid receiver for this booking chat.']);
    exit;
}

$hasReceiver = db_column_exists($pdo, 'booking_chat_messages', 'receiver_user_id');
$hasDeliveredAt = db_column_exists($pdo, 'booking_chat_messages', 'delivered_at');

if ($hasReceiver && $hasDeliveredAt) {
    $stmt = $pdo->prepare("
        INSERT INTO booking_chat_messages (
            booking_id, sender_user_id, receiver_user_id, message, delivered_at
        ) VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$bookingId, $user['id'], $receiverUserId, $message]);
} elseif ($hasReceiver) {
    $stmt = $pdo->prepare("
        INSERT INTO booking_chat_messages (
            booking_id, sender_user_id, receiver_user_id, message
        ) VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$bookingId, $user['id'], $receiverUserId, $message]);
} elseif ($hasDeliveredAt) {
    $stmt = $pdo->prepare("
        INSERT INTO booking_chat_messages (
            booking_id, sender_user_id, message, delivered_at
        ) VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$bookingId, $user['id'], $message]);
} else {
    $stmt = $pdo->prepare("
        INSERT INTO booking_chat_messages (
            booking_id, sender_user_id, message
        ) VALUES (?, ?, ?)
    ");
    $stmt->execute([$bookingId, $user['id'], $message]);
}

echo json_encode([
    'success' => true,
    'message' => 'Message sent successfully.',
    'message_id' => (int)$pdo->lastInsertId()
]);
