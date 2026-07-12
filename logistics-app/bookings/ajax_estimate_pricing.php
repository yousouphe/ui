<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mapbox.php';

header('Content-Type: application/json');

$pickupLat = ($_GET['pickup_latitude'] ?? '') !== '' ? (float) $_GET['pickup_latitude'] : null;
$pickupLng = ($_GET['pickup_longitude'] ?? '') !== '' ? (float) $_GET['pickup_longitude'] : null;
$deliveryLat = ($_GET['delivery_latitude'] ?? '') !== '' ? (float) $_GET['delivery_latitude'] : null;
$deliveryLng = ($_GET['delivery_longitude'] ?? '') !== '' ? (float) $_GET['delivery_longitude'] : null;
$distanceKm = ($_GET['distance_km'] ?? '') !== '' ? (float) $_GET['distance_km'] : null;
$durationMinutes = ($_GET['duration_minutes'] ?? '') !== '' ? (float) $_GET['duration_minutes'] : null;

if ($pickupLat === null || $pickupLng === null || $deliveryLat === null || $deliveryLng === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Both pickup and delivery coordinates are required.']);
    exit;
}

// The wizard map already drew a route (and shows it to the sender) before this step is
// reached - reuse that distance/duration instead of firing a second Mapbox Directions call
// that can fail independently of the one the sender already saw succeed. Only falls back to
// a fresh server-side call if the client didn't send usable numbers. No haversine fallback
// either way: an approximate distance would mean an approximate (and potentially wrong)
// locked-in price.
if ($distanceKm === null || $durationMinutes === null || $distanceKm <= 0 || $durationMinutes <= 0) {
    try {
        $metrics = pricing_route_metrics($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        $distanceKm = (float) $metrics['distance_km'];
        $durationMinutes = (float) $metrics['duration_min'];
    } catch (NoRouteFoundException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No route could be found between these locations. Please check the pickup and delivery addresses.']);
        exit;
    } catch (RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Unable to calculate pricing right now. Please try again shortly.']);
        exit;
    }
}

$plannedDurationMinutes = (int) round($durationMinutes);

$options = [];
foreach (['bike', 'car', 'van'] as $type) {
    $priced = calculate_delivery_price($pdo, $distanceKm, $type);
    $options[] = [
        'vehicle_type' => $type,
        'total' => $priced['total'],
        'distance_km' => $priced['distance_km'],
        'planned_duration_minutes' => $plannedDurationMinutes,
    ];
}

echo json_encode(['success' => true, 'options' => $options]);
