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

if ($pickupLat === null || $pickupLng === null || $deliveryLat === null || $deliveryLng === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Both pickup and delivery coordinates are required.']);
    exit;
}

// One Directions call covers all three vehicle types - distance and drive time are the
// same route regardless of what the sender ends up picking, only the price multiplier
// differs. No haversine fallback: an approximate distance would mean an approximate (and
// potentially wrong) locked-in price.
try {
    $metrics = pricing_route_metrics($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Unable to calculate pricing right now. Please try again shortly.']);
    exit;
}

$plannedDurationMinutes = (int) round($metrics['duration_min']);

$options = [];
foreach (['bike', 'car', 'van'] as $type) {
    $priced = calculate_delivery_price($pdo, $metrics['distance_km'], $type);
    $options[] = [
        'vehicle_type' => $type,
        'total' => $priced['total'],
        'distance_km' => $priced['distance_km'],
        'planned_duration_minutes' => $plannedDurationMinutes,
    ];
}

echo json_encode(['success' => true, 'options' => $options]);
