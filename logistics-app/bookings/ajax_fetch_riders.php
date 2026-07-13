<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mapbox.php';

header('Content-Type: application/json');

$bookingId = (int)($_GET['booking_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id,
            sender_user_id,
            pickup_latitude,
            pickup_longitude,
            delivery_latitude,
            delivery_longitude,
            vehicle_type,
            agreed_cost,
            updated_at
     FROM bookings
     WHERE id = ?
     LIMIT 1'
);

$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
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
$deliveryLat = (float)$booking['delivery_latitude'];
$deliveryLng = (float)$booking['delivery_longitude'];
$bookingVehicleType = (string)($booking['vehicle_type'] ?? '');

$routeDistanceKm = null;
$routeDurationMinutes = null;

/*
 * Obtain route metrics once.
 *
 * These route metrics are shared by all riders because the delivery
 * pickup and destination remain the same. Only pricing changes based
 * on each rider's vehicle type.
 */
try {
    $metrics = pricing_route_metrics(
        $pickupLat,
        $pickupLng,
        $deliveryLat,
        $deliveryLng
    );

    $routeDistanceKm = (float)$metrics['distance_km'];
    $routeDurationMinutes = (float)$metrics['duration_min'];
} catch (RuntimeException $e) {
    echo json_encode([
        'pricing_pending' => true,
        'riders' => []
    ]);
    exit;
}

/*
 * Preserve the booking's original agreed cost using the vehicle type
 * selected by the sender when the booking was created.
 *
 * Rider-specific suggested fees will be calculated separately below.
 */
if ($booking['agreed_cost'] === null && $bookingVehicleType !== '') {
    try {
        $bookingPrice = calculate_delivery_price(
            $pdo,
            $routeDistanceKm,
            $bookingVehicleType
        );

        $bookingAgreedCost = (float)$bookingPrice['total'];
        $plannedDurationMinutes = (int)round($routeDurationMinutes);

        $stmt = $pdo->prepare(
            'UPDATE bookings
             SET agreed_cost = ?,
                 planned_duration_minutes = ?
             WHERE id = ?'
        );

        $stmt->execute([
            $bookingAgreedCost,
            $plannedDurationMinutes,
            $bookingId
        ]);

        $booking['agreed_cost'] = $bookingAgreedCost;
    } catch (RuntimeException $e) {
        echo json_encode([
            'pricing_pending' => true,
            'riders' => []
        ]);
        exit;
    }
}

$distanceSql = haversine_sql(
    'rp.last_latitude',
    'rp.last_longitude',
    $pickupLat,
    $pickupLng
);

$activeBookingStatuses = implode(
    "','",
    array_map(
        static fn($status) => str_replace("'", "''", $status),
        RIDER_ACTIVE_BOOKING_STATUSES
    )
);

/*
 * Vehicle type is deliberately not included in the WHERE clause.
 *
 * This allows approved riders using motorcycles, bicycles, cars,
 * vans, or other configured vehicle types to be considered.
 */
$sql = "
    SELECT
        u.id,
        u.full_name,
        rp.vehicle_type,
        rp.rating,
        rp.last_latitude,
        rp.last_longitude,
        rp.last_location_updated_at,

        CASE
            WHEN rp.last_latitude IS NOT NULL
             AND rp.last_longitude IS NOT NULL
            THEN {$distanceSql}
            ELSE NULL
        END AS distance_km,

        (
            SELECT COUNT(*)
            FROM bookings b
            WHERE b.selected_rider_user_id = u.id
              AND b.booking_status IN ('{$activeBookingStatuses}')
        ) AS active_order_count

    FROM users u

    INNER JOIN rider_profiles rp
        ON rp.user_id = u.id

    WHERE u.role = 'rider'
      AND u.status = 'active'
      AND rp.kyc_status = 'approved'
      AND rp.vehicle_type IS NOT NULL
      AND rp.vehicle_type <> ''

    HAVING active_order_count < " . (int)RIDER_MAX_CONCURRENT_ORDERS . "

    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

$riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($riders as &$rider) {
    $riderVehicleType = trim((string)($rider['vehicle_type'] ?? ''));

    /*
     * Calculate the suggested fee using the rider's actual vehicle type.
     */
    try {
        $riderPrice = calculate_delivery_price(
            $pdo,
            $routeDistanceKm,
            $riderVehicleType
        );

        $rider['suggested_fee'] = (float)$riderPrice['total'];
        $rider['pricing_available'] = true;
    } catch (Throwable $e) {
        /*
         * Do not silently assign the booking's original fee to another
         * vehicle type. That could show an incorrect commercial price.
         */
        $rider['suggested_fee'] = null;
        $rider['pricing_available'] = false;
    }

    $rider['eta_minutes'] = $rider['distance_km'] !== null
        ? estimated_eta_minutes(
            (float)$rider['distance_km'],
            $riderVehicleType
        )
        : null;

    $stats = rider_delivery_stats(
        $pdo,
        (int)$rider['id']
    );

    $rider['avg_delivery_minutes'] = $stats['avg_actual_minutes'];
    $rider['performance_ratio'] = $stats['ratio'];

    $rider['score'] = rider_match_score(
        $rider['rating'] !== null
            ? (float)$rider['rating']
            : null,
        $stats['ratio']
    );

    $rider['last_seen_seconds_ago'] =
        !empty($rider['last_location_updated_at'])
            ? max(
                0,
                time() - strtotime(
                    (string)$rider['last_location_updated_at']
                )
            )
            : null;

    unset($rider['last_location_updated_at']);
}

unset($rider);

// Check if haversine fallback was used for this route metric
$haversineUsed = false;
if (file_exists(__DIR__ . '/../assets/pricing_fallback.json')) {
    try {
        $fallbackData = json_decode(file_get_contents(__DIR__ . '/../assets/pricing_fallback.json'), true);
        if (is_array($fallbackData) && isset($fallbackData['timestamp'])) {
            $haversineUsed = true;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/*
 * Sort primarily by matching score.
 *
 * When two riders have the same score:
 * 1. Prefer riders with available pricing.
 * 2. Prefer the rider closer to the pickup point.
 */
usort($riders, static function (array $a, array $b): int {
    $scoreComparison = ((float)$b['score']) <=> ((float)$a['score']);

    if ($scoreComparison !== 0) {
        return $scoreComparison;
    }

    $pricingComparison =
        ((int)$b['pricing_available'])
        <=>
        ((int)$a['pricing_available']);

    if ($pricingComparison !== 0) {
        return $pricingComparison;
    }

    $aDistance = $a['distance_km'] !== null
        ? (float)$a['distance_km']
        : PHP_FLOAT_MAX;

    $bDistance = $b['distance_km'] !== null
        ? (float)$b['distance_km']
        : PHP_FLOAT_MAX;

    return $aDistance <=> $bDistance;
});

/*
 * Return a maximum of 10 riders.
 */
$riders = array_slice($riders, 0, 10);

$etagData = array_map(
    static fn(array $rider): array => [
        $rider['id'],
        $rider['vehicle_type'],
        $rider['active_order_count'],
        $rider['rating'],
        $rider['suggested_fee'],
        $rider['score']
    ],
    $riders
);

$etag = sha1(json_encode($etagData));

response_cache_headers($etag, 5);

echo json_encode([
    'pricing_pending' => false,
    'haversine_used' => $haversineUsed,
    'riders' => $riders
]);
