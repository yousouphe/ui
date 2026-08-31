<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$bookingId = (int) ($_GET['booking_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, sender_user_id, pickup_latitude, pickup_longitude, vehicle_type, agreed_cost FROM bookings WHERE id = ? LIMIT 1');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();
$user = current_user();

if (!$booking) {
    http_response_code(404);
    echo json_encode(['error' => 'Booking not found']);
    exit;
}

if ((int) ($booking['sender_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pickupLat = (float) $booking['pickup_latitude'];
$pickupLng = (float) $booking['pickup_longitude'];
$vehicleType = (string) ($booking['vehicle_type'] ?? '');
$distanceSql = haversine_sql('rp.last_latitude', 'rp.last_longitude', $pickupLat, $pickupLng);

// Deliberately no availability_status/recency filter here - this is the fallback shown once
// live geolocation-based matching hasn't found anyone in 15s, so it surfaces every rider who
// could still take the job (capacity permitting) even if they haven't toggled themselves
// "available" or their last location ping is stale, and lets the sender pick manually using
// the order-count/distance/ETA/performance numbers below instead.
$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, rp.vehicle_type, rp.rating, rp.last_latitude, rp.last_longitude,
           CASE WHEN rp.last_latitude IS NOT NULL AND rp.last_longitude IS NOT NULL THEN $distanceSql ELSE NULL END AS distance_km,
           (
               SELECT COUNT(*) FROM bookings b
               WHERE b.selected_rider_user_id = u.id
               AND b.booking_status IN ('" . implode("','", RIDER_ACTIVE_BOOKING_STATUSES) . "')
           ) AS active_order_count
    FROM users u
    INNER JOIN rider_profiles rp ON rp.user_id = u.id
    WHERE u.role = 'rider' AND u.status = 'active' AND rp.kyc_status = 'approved' AND rp.vehicle_type = ?
    HAVING active_order_count < " . RIDER_MAX_CONCURRENT_ORDERS . "
    ORDER BY (distance_km IS NULL) ASC, distance_km ASC, rp.rating DESC
    LIMIT 30
");
$stmt->execute([$vehicleType]);
$riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($riders as &$r) {
    $r['suggested_fee'] = $booking['agreed_cost'];
    // ETA is a rough distance/speed estimate from the rider's last known location, not a
    // live route - there's no distance to estimate from once that's unknown.
    $r['eta_minutes'] = $r['distance_km'] !== null ? estimated_eta_minutes((float) $r['distance_km'], (string) $r['vehicle_type']) : null;
    $stats = rider_delivery_stats($pdo, (int) $r['id']);
    $r['avg_delivery_minutes'] = $stats['avg_actual_minutes'];
    $r['performance_ratio'] = $stats['ratio'];
}
unset($r);

echo json_encode(['success' => true, 'riders' => $riders, 'max_orders' => RIDER_MAX_CONCURRENT_ORDERS]);
