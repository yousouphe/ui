<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
require_csrf();

$user = current_user();
$bookingId = (int) ($_POST['booking_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND sender_user_id = ? LIMIT 1');
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit;
}

if (
    in_array($booking['booking_status'], ['delivered', 'cancelled'], true)
    || (int) ($booking['sender_handover_confirmed'] ?? 0) === 1
    || ($booking['payment_status'] ?? 'unpaid') === 'paid'
) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This booking can no longer be edited.']);
    exit;
}

$errors = validate_required([
    'recipient_name' => 'Recipient name',
    'recipient_phone' => 'Recipient phone',
    'item_name' => 'Item name',
    'item_category' => 'Item category',
], $_POST);

if ($errors) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => reset($errors)]);
    exit;
}

try {
    $payload = [
        'recipient_name' => trim($_POST['recipient_name']),
        'recipient_phone' => trim($_POST['recipient_phone']),
        'item_name' => trim($_POST['item_name']),
        'item_category' => trim($_POST['item_category']),
        'item_description' => trim($_POST['item_description'] ?? ''),
        'estimated_value' => ($_POST['estimated_value'] ?? '') !== '' ? (float) $_POST['estimated_value'] : null,
        'special_instructions' => trim($_POST['special_instructions'] ?? ''),
    ];

    $newImagePath = save_item_image($_FILES['item_image'] ?? []);
    if ($newImagePath !== null) {
        $payload['item_image_path'] = $newImagePath;
    }

    $setSql = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($payload)));
    $payload['id'] = $bookingId;
    $stmt = $pdo->prepare("UPDATE bookings SET $setSql WHERE id = :id");
    $stmt->execute($payload);

    echo json_encode(['success' => true, 'message' => 'Booking details updated.']);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
