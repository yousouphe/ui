<?php
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';

require_csrf();

$bookingId = $_POST['booking_id'];
$status = $_POST['status'];

if ($status === 'completed') {
    $stmt = $pdo->prepare('UPDATE bookings SET booking_status = "delivered", completed_at = NOW() WHERE id = ?');
} else {
    $stmt = $pdo->prepare('UPDATE bookings SET booking_status = ? WHERE id = ?');
}

if ($stmt->execute([$status, $bookingId])) {
    echo "success";
}