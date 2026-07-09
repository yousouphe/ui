<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$bookingId = (int)($_GET['booking_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, sender_user_id, pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude, updated_at FROM bookings WHERE id = ? LIMIT 1');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();
$user = current_user();

if (!$booking) {
    http_response_code(404);
    echo json_encode(['error' => 'Booking not found']);
    exit;
}

if ((int)($booking['sender_user_id'] ?? 0) !== (int)($user['id'] ?? 0) && (($user['role'] ?? '') !== 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$etag = sha1(implode('|', [
    $booking['id'] ?? '',
    $booking['updated_at'] ?? '',
    $booking['pickup_latitude'] ?? '',
    $booking['pickup_longitude'] ?? '',
    $booking['delivery_latitude'] ?? '',
    $booking['delivery_longitude'] ?? ''
]));
response_cache_headers($etag, 10);

$pickupLat = (float)$booking['pickup_latitude'];
$pickupLng = (float)$booking['pickup_longitude'];
$distanceSql = haversine_sql('rp.last_latitude', 'rp.last_longitude', $pickupLat, $pickupLng);

$sql = "SELECT u.id, u.full_name, rp.vehicle_type, rp.rating, 
               rp.last_latitude, rp.last_longitude, $distanceSql AS distance_km
        FROM users u
        INNER JOIN rider_profiles rp ON rp.user_id = u.id
        WHERE u.role = 'rider' 
          AND u.status = 'active' 
          AND rp.availability_status = 'available'
          AND rp.last_location_updated_at > NOW() - INTERVAL 90 MINUTE
          AND NOT EXISTS (
              SELECT 1 FROM bookings b 
              WHERE b.selected_rider_user_id = u.id 
              AND b.booking_status IN ('matched', 'accepted', 'arrived_at_pickup', 'package_received', 'in_transit')
          )
        ORDER BY distance_km ASC LIMIT 20";

$riders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function haversine_php($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1-$a)));
}

$delDist = haversine_php($pickupLat, $pickupLng, (float)$booking['delivery_latitude'], (float)$booking['delivery_longitude']);

foreach($riders as &$r) {
    $base = ($delDist * 400) + 1500;
    if ($r['vehicle_type'] === 'car') $base *= 1.5;
    $r['suggested_fee'] = max(1500, round($base, -2));
}
unset($r);

echo json_encode($riders);
