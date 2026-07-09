<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('rider/dashboard.php');
}

require_csrf();

$bookingId = (int)($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    flash('error', 'Invalid booking.');
    redirect_to('rider/dashboard.php');
}

$stmt = $pdo->prepare("
    SELECT * FROM bookings
    WHERE id = ? AND selected_rider_user_id = ?
    LIMIT 1
");
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch();

if (!$booking) {
    flash('error', 'Booking not found.');
    redirect_to('rider/dashboard.php');
}

$proofPath = null;
if (!empty($_FILES['delivery_proof']['name'])) {
    $proofPath = save_item_image($_FILES['delivery_proof']);
}

$stmt = $pdo->prepare("
    UPDATE bookings
    SET booking_status = 'delivered',
        delivery_proof_image = ?,
        delivered_at = NOW()
    WHERE id = ?
");
$stmt->execute([$proofPath, $bookingId]);

flash('success', 'Delivery completed successfully.');
redirect_to('rider/dashboard.php');