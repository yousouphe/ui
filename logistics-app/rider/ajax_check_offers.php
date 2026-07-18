<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
$user = current_user();

$stmt = $pdo->prepare('
    SELECT rr.*, b.pickup_address 
    FROM rider_requests rr 
    JOIN bookings b ON rr.booking_id = b.id 
    WHERE rr.rider_user_id = ? AND rr.request_status = "pending"
    LIMIT 3
');
$stmt->execute([$user['id']]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));