<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mapbox.php';

header('Content-Type: application/json');
require_csrf();

$user = current_user();
$bookingId = (int) ($_POST['booking_id'] ?? 0);
$deliveryAddress = trim((string) ($_POST['delivery_address'] ?? ''));
$deliveryLat = ($_POST['delivery_latitude'] ?? '') !== '' ? (float) $_POST['delivery_latitude'] : null;
$deliveryLng = ($_POST['delivery_longitude'] ?? '') !== '' ? (float) $_POST['delivery_longitude'] : null;

if ($bookingId <= 0 || $deliveryAddress === '' || $deliveryLat === null || $deliveryLng === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid delivery address is required.']);
    exit;
}

$stmt = $pdo->prepare('
    SELECT b.*, rp.vehicle_type
    FROM bookings b
    LEFT JOIN rider_profiles rp ON rp.user_id = b.selected_rider_user_id
    WHERE b.id = ? AND b.sender_user_id = ?
    LIMIT 1
');
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit;
}

if (in_array($booking['booking_status'], ['delivered', 'cancelled'], true) || ($booking['payment_status'] ?? 'unpaid') === 'paid') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This booking can no longer be edited.']);
    exit;
}

$newAgreedCost = $booking['agreed_cost'];
$priceChanged = false;

// Only repriced once a rider is already selected - before that, agreed_cost stays null
// and each candidate rider's estimated fare is computed fresh from the current address by
// ajax_fetch_riders.php, so there's nothing "agreed" yet to recalculate.
if (!empty($booking['selected_rider_user_id']) && $booking['pickup_latitude'] !== null && $booking['pickup_longitude'] !== null) {
    $pickupLat = (float) $booking['pickup_latitude'];
    $pickupLng = (float) $booking['pickup_longitude'];

    // No haversine fallback: if road distance can't be determined, reject the whole address
    // change rather than risk storing a price based on an approximate distance.
    try {
        $newDistance = pricing_distance_km($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);

        $oldDistance = null;
        if ($booking['delivery_latitude'] !== null && $booking['delivery_longitude'] !== null) {
            $oldDistance = pricing_distance_km($pickupLat, $pickupLng, (float) $booking['delivery_latitude'], (float) $booking['delivery_longitude']);
        }
    } catch (NoRouteFoundException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No route could be found between these locations. Please check the delivery address.']);
        exit;
    } catch (RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Unable to calculate route distance right now. Please try again shortly.']);
        exit;
    }

    // Only reprice when the new destination is farther away. A closer destination keeps
    // the already-agreed price - the rider doesn't lose out on a shorter trip just because
    // the sender changed their mind, and it avoids re-negotiating a price the rider
    // already committed to. Repricing always recomputes a fresh absolute fare from the
    // shared pricing engine (never a multiplier applied to the old price - that's what
    // caused prices to blow up when the original trip was short).
    if ($booking['agreed_cost'] === null || $oldDistance === null || $newDistance > $oldDistance) {
        $vehicleType = (string) ($booking['vehicle_type'] ?? 'bike');
        $newAgreedCost = calculate_delivery_price($pdo, $newDistance, $vehicleType)['total'];
    }
    $priceChanged = (float) $newAgreedCost !== (float) $booking['agreed_cost'];
}

$stmt = $pdo->prepare('
    UPDATE bookings
    SET delivery_address = ?, delivery_latitude = ?, delivery_longitude = ?, agreed_cost = ?
    WHERE id = ?
');
$stmt->execute([$deliveryAddress, $deliveryLat, $deliveryLng, $newAgreedCost, $bookingId]);

echo json_encode([
    'success' => true,
    'message' => $priceChanged ? 'Delivery address updated and price recalculated.' : 'Delivery address updated.',
    'agreed_cost' => $newAgreedCost,
    'price_changed' => $priceChanged,
]);
