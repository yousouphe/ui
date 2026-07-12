<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mapbox.php';

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

if ((int)($booking['sender_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

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
          AND rp.kyc_status = 'approved'
          AND rp.last_location_updated_at > NOW() - INTERVAL 90 MINUTE
          AND NOT EXISTS (
              SELECT 1 FROM bookings b 
              WHERE b.selected_rider_user_id = u.id 
              AND b.booking_status IN ('matched', 'accepted', 'arrived_at_pickup', 'package_received', 'in_transit')
          )
        ORDER BY distance_km ASC LIMIT 20";

$riders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Real road distance where available (falls back to haversine if Mapbox is unreachable/
// unconfigured) - this is what the per-rider suggested fare is based on, not the straight-
// line distance used just above for sorting nearby riders by proximity.
$delDist = pricing_distance_km($pickupLat, $pickupLng, (float)$booking['delivery_latitude'], (float)$booking['delivery_longitude']);

foreach($riders as &$r) {
    $r['suggested_fee'] = calculate_delivery_price($pdo, $delDist, (string) $r['vehicle_type'])['total'];
}
unset($r);

// The ETag must reflect the actual result set, not just the booking's static fields - a rider
// coming online/offline changes this list without ever touching the booking row, and caching
// against a key that can't detect that froze the sender's rider list until a hard refresh.
$etag = sha1(json_encode(array_map(fn($r) => [$r['id'], $r['last_latitude'], $r['last_longitude']], $riders)));
response_cache_headers($etag, 5);

echo json_encode($riders);
