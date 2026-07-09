<?php
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';

$bookingId = (int)($_GET['booking_id'] ?? 0);
$stmt = $pdo->prepare('SELECT pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude FROM bookings WHERE id = ? LIMIT 1');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    echo json_encode(['error' => 'Booking not found']);
    exit;
}

$pickupLat = (float)$booking['pickup_latitude'];
$pickupLng = (float)$booking['pickup_longitude'];

// Distance SQL for Radar
$distanceSql = haversine_sql('rp.last_latitude', 'rp.last_longitude', $pickupLat, $pickupLng);

/*$sql = "SELECT u.id, u.full_name, rp.vehicle_type, rp.rating, 
               rp.last_latitude, rp.last_longitude, $distanceSql AS distance_km
        FROM users u
        INNER JOIN rider_profiles rp ON rp.user_id = u.id
        WHERE u.role = 'rider' AND u.status = 'active' AND rp.availability_status = 'available'
          AND rp.last_location_updated_at > NOW() - INTERVAL 1 MINUTE
        ORDER BY distance_km ASC LIMIT 20"; */
        
        
        
        $sql = "SELECT u.id, u.full_name, rp.vehicle_type, rp.rating, 
               rp.last_latitude, rp.last_longitude, $distanceSql AS distance_km
        FROM users u
        INNER JOIN rider_profiles rp ON rp.user_id = u.id
        WHERE u.role = 'rider' 
          AND u.status = 'active' 
          AND rp.availability_status = 'available'
         AND rp.last_location_updated_at > NOW() - INTERVAL 90 MINUTE
          
        /*    CRITICAL: Exclude riders with active missions   */
          AND NOT EXISTS (
              SELECT 1 FROM bookings b 
              WHERE b.selected_rider_user_id = u.id 
              AND b.booking_status IN ('accepted', 'arrived_at_pickup', 'package_received', 'in_transit')
          )
         
        ORDER BY distance_km ASC LIMIT 20";

$riders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Calculate price for each rider server-side
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

header('Content-Type: application/json');
echo json_encode($riders);