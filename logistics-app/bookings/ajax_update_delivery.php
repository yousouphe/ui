<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';

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

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

$newAgreedCost = $booking['agreed_cost'];
$priceChanged = false;

if (!empty($booking['selected_rider_user_id']) && $booking['pickup_latitude'] !== null && $booking['pickup_longitude'] !== null) {
    $pickupLat = (float) $booking['pickup_latitude'];
    $pickupLng = (float) $booking['pickup_longitude'];
    $newDistance = haversine_km($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);

    $oldDistance = null;
    if ($booking['delivery_latitude'] !== null && $booking['delivery_longitude'] !== null) {
        $oldDistance = haversine_km($pickupLat, $pickupLng, (float) $booking['delivery_latitude'], (float) $booking['delivery_longitude']);
    }

    if ($oldDistance && $oldDistance > 0.05 && $booking['agreed_cost'] !== null) {
        $newAgreedCost = round(((float) $booking['agreed_cost']) * ($newDistance / $oldDistance), 2);
    } else {
        $base = ($newDistance * 400) + 1500;
        if (($booking['vehicle_type'] ?? '') === 'car') {
            $base *= 1.5;
        }
        $newAgreedCost = max(1500, round($base, -2));
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
