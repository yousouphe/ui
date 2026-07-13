<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$bookingId = (int)($_GET['booking_id'] ?? 0);

if ($bookingId <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $pdo->prepare('SELECT id, booking_status, sender_user_id FROM bookings WHERE id = ? LIMIT 1');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking || (int)$booking['sender_user_id'] !== (int)$user['id']) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $pdo->prepare('
    SELECT rr.id, rr.rider_user_id, rr.request_status, rr.proposed_cost, rr.created_at, u.full_name, rp.vehicle_type
    FROM rider_requests rr
    INNER JOIN users u ON u.id = rr.rider_user_id
    LEFT JOIN rider_profiles rp ON rp.user_id = rr.rider_user_id
    WHERE rr.booking_id = ?
    ORDER BY rr.id DESC
    LIMIT 1
');
$stmt->execute([$bookingId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($request && $request['request_status'] === 'pending' && $request['created_at']) {
    $createdAt = strtotime($request['created_at']);
    if ($createdAt !== false && (time() - $createdAt) >= 60) {
        $stmt = $pdo->prepare('UPDATE rider_requests SET request_status = "rejected" WHERE id = ?');
        $stmt->execute([$request['id']]);
        $request['request_status'] = 'rejected';
    }
}

echo json_encode([
    'success' => true,
    'booking_status' => $booking['booking_status'],
    'request' => $request,
]);
