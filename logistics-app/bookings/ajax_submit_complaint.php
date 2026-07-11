<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';

header('Content-Type: application/json');

$user = current_user();
require_csrf();

$bookingId = (int) ($_POST['booking_id'] ?? 0);
$category = trim((string) ($_POST['category'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$allowedCategories = ['damaged_item', 'late_delivery', 'wrong_item', 'rider_behavior', 'other'];

if ($bookingId <= 0) {
    respond_json(['success' => false, 'message' => 'Invalid booking.'], 422);
}

if (!in_array($category, $allowedCategories, true)) {
    respond_json(['success' => false, 'message' => 'Please choose a valid complaint category.'], 422);
}

if ($message === '') {
    respond_json(['success' => false, 'message' => 'Please describe the issue.'], 422);
}

$stmt = $pdo->prepare('
    SELECT id, sender_user_id, booking_status, booking_code
    FROM bookings
    WHERE id = ?
    LIMIT 1
');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking || ((int) $booking['sender_user_id'] !== (int) $user['id'] && !in_array($user['role'] ?? '', ['admin', 'super_admin'], true))) {
    respond_json(['success' => false, 'message' => 'Booking not found or access denied.'], 404);
}

if ($booking['booking_status'] !== 'delivered') {
    respond_json(['success' => false, 'message' => 'You can only report a problem after this booking has been delivered.'], 409);
}

$stmt = $pdo->prepare('
    INSERT INTO booking_complaints (booking_id, sender_user_id, category, message, status, created_at)
    VALUES (?, ?, ?, ?, "open", NOW())
');
$stmt->execute([$bookingId, (int) $user['id'], $category, $message]);

notify_admins($pdo, 'New complaint reported - ' . $booking['booking_code'], '<p><strong>' . e((string) $user['full_name']) . '</strong> reported an issue with booking <strong>' . e((string) $booking['booking_code']) . '</strong>.</p><p><strong>Category:</strong> ' . e($category) . '</p><p>' . nl2br(e($message)) . '</p><p>Review it from the admin portal.</p>');

respond_json(['success' => true, 'message' => 'Your report has been submitted. Our team will follow up.']);
