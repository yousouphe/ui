<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
require_csrf();

$bookingId = (int) ($_POST['booking_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? 0);
$reviewText = trim((string) ($_POST['review_text'] ?? ''));

if ($bookingId <= 0) {
    respond_json(['success' => false, 'message' => 'Invalid booking.'], 422);
}

if ($rating < 1 || $rating > 5) {
    respond_json(['success' => false, 'message' => 'Rating must be between 1 and 5 stars.'], 422);
}

$stmt = $pdo->prepare('
    SELECT id, sender_user_id, selected_rider_user_id, booking_status
    FROM bookings
    WHERE id = ?
    LIMIT 1
');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
    respond_json(['success' => false, 'message' => 'Booking not found or access denied.'], 404);
}

if ($booking['booking_status'] !== 'delivered') {
    respond_json(['success' => false, 'message' => 'You can only rate a booking after it has been delivered.'], 409);
}

$riderUserId = (int) ($booking['selected_rider_user_id'] ?? 0);
if ($riderUserId <= 0) {
    respond_json(['success' => false, 'message' => 'No rider is associated with this booking.'], 422);
}

$stmt = $pdo->prepare('SELECT id FROM booking_ratings WHERE booking_id = ? LIMIT 1');
$stmt->execute([$bookingId]);
if ($stmt->fetchColumn()) {
    respond_json(['success' => false, 'message' => 'You have already rated this delivery.'], 409);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        INSERT INTO booking_ratings (booking_id, sender_user_id, rider_user_id, rating, review_text, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([$bookingId, (int) $user['id'], $riderUserId, $rating, $reviewText !== '' ? $reviewText : null]);

    $stmt = $pdo->prepare('
        UPDATE rider_profiles
        SET rating = (SELECT ROUND(AVG(rating), 2) FROM booking_ratings WHERE rider_user_id = ?)
        WHERE user_id = ?
    ');
    $stmt->execute([$riderUserId, $riderUserId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond_json(['success' => false, 'message' => 'Unable to save your rating.'], 500);
}

respond_json(['success' => true, 'message' => 'Thanks for rating your rider!']);
