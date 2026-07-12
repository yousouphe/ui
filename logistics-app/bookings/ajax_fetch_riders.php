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

// Every rider of the sender's chosen vehicle type who is KYC-approved, active, and has room
// for one more job (RIDER_MAX_CONCURRENT_ORDERS, currently 3) is a candidate - deliberately
// not filtered by "online"/availability_status or how recently their location last updated.
// Whether a rider happens to be online right now says nothing about whether they're good at
// the job or would actually respond, and this app has no reliable way to confirm "online"
// means "reachable" anyway - ranking by quality (rating_match_score()) and letting the
// sender pick is more useful than silently hiding anyone who hasn't toggled a switch.
$sql = "SELECT u.id, u.full_name, rp.vehicle_type, rp.rating,
               rp.last_latitude, rp.last_longitude,
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
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute([$vehicleType]);
$riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Price is already locked in on the booking (set once, at creation, from the sender's
// chosen vehicle type) - every matching rider is offered that same fixed fee. Score each
// candidate, keep only the top 10, and drop the working fields the frontend doesn't need.
foreach ($riders as &$r) {
    $r['suggested_fee'] = $booking['agreed_cost'];
    $r['eta_minutes'] = $r['distance_km'] !== null ? estimated_eta_minutes((float) $r['distance_km'], (string) $r['vehicle_type']) : null;
    $stats = rider_delivery_stats($pdo, (int) $r['id']);
    $r['avg_delivery_minutes'] = $stats['avg_actual_minutes'];
    $r['performance_ratio'] = $stats['ratio'];
    $r['score'] = rider_match_score($r['rating'] !== null ? (float) $r['rating'] : null, $stats['ratio']);
}
unset($r);

usort($riders, fn($a, $b) => $b['score'] <=> $a['score']);
$riders = array_slice($riders, 0, 10);

// The ETag must reflect the actual result set, not just the booking's static fields - a
// rider's rating/order count changing this list without ever touching the booking row, and
// caching against a key that can't detect that froze the sender's rider list until a hard
// refresh.
$etag = sha1(json_encode(array_map(fn($r) => [$r['id'], $r['active_order_count'], $r['rating']], $riders)));
response_cache_headers($etag, 5);

echo json_encode(['pricing_pending' => false, 'riders' => $riders]);
