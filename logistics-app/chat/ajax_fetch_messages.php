<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$bookingId = (int)($_GET['booking_id'] ?? 0);
$sinceId = max(0, (int)($_GET['since_id'] ?? 0));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$markRead = ($_GET['mark_read'] ?? '1') !== '0';

if ($bookingId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit;
}

if (!db_table_exists($pdo, 'booking_chat_messages')) {
    echo json_encode([
        'success' => true,
        'messages' => [],
        'last_message_id' => 0,
        'has_more' => false
    ]);
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

$hasReceiver = db_column_exists($pdo, 'booking_chat_messages', 'receiver_user_id');
$hasIsRead = db_column_exists($pdo, 'booking_chat_messages', 'is_read');
$hasDeliveredAt = db_column_exists($pdo, 'booking_chat_messages', 'delivered_at');
$hasReadAt = db_column_exists($pdo, 'booking_chat_messages', 'read_at');

if ($hasReceiver && $hasIsRead && $markRead) {
    $stmt = $pdo->prepare("
        UPDATE booking_chat_messages
        SET is_read = 1" . ($hasReadAt ? ", read_at = NOW()" : "") . "
        WHERE booking_id = ?
          AND receiver_user_id = ?
          AND is_read = 0
    ");
    $stmt->execute([$bookingId, $user['id']]);
}

$selectFields = [
    'id',
    'booking_id',
    'sender_user_id',
    $hasReceiver ? 'receiver_user_id' : 'NULL AS receiver_user_id',
    'message',
    $hasIsRead ? 'is_read' : '1 AS is_read',
    $hasDeliveredAt ? 'delivered_at' : 'created_at AS delivered_at',
    $hasReadAt ? 'read_at' : 'NULL AS read_at',
    'created_at'
];

$params = [$bookingId];
$sql = "
    SELECT " . implode(', ', $selectFields) . "
    FROM booking_chat_messages
    WHERE booking_id = ?
";
if ($sinceId > 0) {
    $sql .= " AND id > ? ";
    $params[] = $sinceId;
}
$sql .= " ORDER BY id ASC LIMIT " . (int)$limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
$lastMessageId = 0;
foreach ($messages as $m) {
    $lastMessageId = max($lastMessageId, (int)($m['id'] ?? 0));
}

$formatted = array_map(function ($m) use ($user) {
    $m['is_me'] = ((int)$m['sender_user_id'] === (int)$user['id']);
    $m['created_at_formatted'] = !empty($m['created_at']) ? date('d M, H:i', strtotime((string)$m['created_at'])) : null;
    $m['delivered_at_formatted'] = !empty($m['delivered_at']) ? date('d M, H:i', strtotime((string)$m['delivered_at'])) : null;
    $m['read_at_formatted'] = !empty($m['read_at']) ? date('d M, H:i', strtotime((string)$m['read_at'])) : null;
    return $m;
}, $messages);

echo json_encode([
    'success' => true,
    'messages' => $formatted,
    'last_message_id' => $lastMessageId,
    'has_more' => count($formatted) >= $limit
]);
