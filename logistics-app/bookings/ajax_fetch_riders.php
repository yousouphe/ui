<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mapbox.php';

header('Content-Type: application/json');

$bookingId = (int)($_GET['booking_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, sender_user_id, pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude, vehicle_type, agreed_cost, updated_at FROM bookings WHERE id = ? LIMIT 1');
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
$vehicleType = (string) ($booking['vehicle_type'] ?? '');

// A booking created while Mapbox was unreachable has no price yet (see bookings/index.php's
// $pricingPending path) - every poll retries pricing here rather than leaving the sender
// stuck, so the moment Mapbox recovers, matching picks up automatically with no action
// needed from the sender or an admin. A genuinely unroutable address pair (NoRouteFoundException)
// is left alone here too - deliberately not surfaced as a hard error on every single poll,
// since that would flood the sender with retries of a call that will never succeed; an admin
// can already see the awaiting-price state and step in.
if ($booking['agreed_cost'] === null && $vehicleType !== '') {
    try {
        $metrics = pricing_route_metrics($pickupLat, $pickupLng, (float) $booking['delivery_latitude'], (float) $booking['delivery_longitude']);
        $newCost = calculate_delivery_price($pdo, $metrics['distance_km'], $vehicleType)['total'];
        $newPlannedMinutes = (int) round($metrics['duration_min']);
        $stmt = $pdo->prepare('UPDATE bookings SET agreed_cost = ?, planned_duration_minutes = ? WHERE id = ?');
        $stmt->execute([$newCost, $newPlannedMinutes, $bookingId]);
        $booking['agreed_cost'] = $newCost;
    } catch (RuntimeException $e) {
        echo json_encode(['pricing_pending' => true, 'riders' => []]);
        exit;
    }
}

$distanceSql = haversine_sql('rp.last_latitude', 'rp.last_longitude', $pickupLat, $pickupLng);

// Only riders of the vehicle type the sender already chose and priced against, with room
// for one more job (RIDER_MAX_CONCURRENT_ORDERS, currently 3 - not zero other active jobs).
$sql = "SELECT u.id, u.full_name, rp.vehicle_type, rp.rating,
               rp.last_latitude, rp.last_longitude, $distanceSql AS distance_km
        FROM users u
        INNER JOIN rider_profiles rp ON rp.user_id = u.id
        WHERE u.role = 'rider'
          AND u.status = 'active'
          AND rp.vehicle_type = ?
          AND rp.availability_status = 'available'
          AND rp.kyc_status = 'approved'
          AND rp.last_location_updated_at > NOW() - INTERVAL 90 MINUTE
          AND (
              SELECT COUNT(*) FROM bookings b
              WHERE b.selected_rider_user_id = u.id
              AND b.booking_status IN ('" . implode("','", RIDER_ACTIVE_BOOKING_STATUSES) . "')
          ) < " . RIDER_MAX_CONCURRENT_ORDERS . "
        ORDER BY distance_km ASC LIMIT 20";

$stmt = $pdo->prepare($sql);
$stmt->execute([$vehicleType]);
$riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Price is already locked in on the booking (set once, at creation, from the sender's
// chosen vehicle type) - every matching rider is offered that same fixed fee, so there's
// nothing left to compute here.
foreach ($riders as &$r) {
    $r['suggested_fee'] = $booking['agreed_cost'];
}
unset($r);

// The ETag must reflect the actual result set, not just the booking's static fields - a rider
// coming online/offline changes this list without ever touching the booking row, and caching
// against a key that can't detect that froze the sender's rider list until a hard refresh.
$etag = sha1(json_encode(array_map(fn($r) => [$r['id'], $r['last_latitude'], $r['last_longitude']], $riders)));
response_cache_headers($etag, 5);

echo json_encode(['pricing_pending' => false, 'riders' => $riders]);
