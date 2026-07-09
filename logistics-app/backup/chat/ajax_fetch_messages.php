<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$bookingId = (int)($_GET['booking_id'] ?? 0);

if ($bookingId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
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

// mark incoming unread messages as read
$stmt = $pdo->prepare("
    UPDATE booking_chat_messages
    SET is_read = 1,
        read_at = NOW()
    WHERE booking_id = ?
      AND receiver_user_id = ?
      AND is_read = 0
");
$stmt->execute([$bookingId, $user['id']]);

$stmt = $pdo->prepare("
    SELECT id, booking_id, sender_user_id, receiver_user_id, message, is_read, delivered_at, read_at, created_at
    FROM booking_chat_messages
    WHERE booking_id = ?
    ORDER BY id ASC
");
$stmt->execute([$bookingId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatted = array_map(function ($m) use ($user) {
    $m['is_me'] = ((int)$m['sender_user_id'] === (int)$user['id']);
    $m['created_at_formatted'] = date('d M, H:i', strtotime((string)$m['created_at']));
    $m['delivered_at_formatted'] = !empty($m['delivered_at']) ? date('d M, H:i', strtotime((string)$m['delivered_at'])) : null;
    $m['read_at_formatted'] = !empty($m['read_at']) ? date('d M, H:i', strtotime((string)$m['read_at'])) : null;
    return $m;
}, $messages);

echo json_encode([
    'success' => true,
    'messages' => $formatted
]);