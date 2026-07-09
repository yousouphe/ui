<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$data = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($data) ? $data : null);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload.']);
    exit;
}

$lat = isset($data['latitude']) ? (float)$data['latitude'] : null;
$lng = isset($data['longitude']) ? (float)$data['longitude'] : null;
$status = in_array(($data['status'] ?? 'available'), ['available', 'busy', 'offline'], true)
    ? $data['status']
    : 'available';

if ($lat === null || $lng === null) {
    echo json_encode(['success' => false, 'message' => 'Latitude and longitude are required.']);
    exit;
}

$stmt = $pdo->prepare('SELECT last_latitude, last_longitude, last_location_updated_at, availability_status FROM rider_profiles WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$current = $stmt->fetch(PDO::FETCH_ASSOC);

$shouldWrite = true;
if ($current) {
    $oldLat = $current['last_latitude'] !== null ? (float)$current['last_latitude'] : null;
    $oldLng = $current['last_longitude'] !== null ? (float)$current['last_longitude'] : null;
    $secondsSince = !empty($current['last_location_updated_at']) ? (time() - strtotime((string)$current['last_location_updated_at'])) : PHP_INT_MAX;

    $distanceMeters = null;
    if ($oldLat !== null && $oldLng !== null) {
        $earth = 6371000;
        $dLat = deg2rad($lat - $oldLat);
        $dLng = deg2rad($lng - $oldLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($oldLat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
        $distanceMeters = $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    $statusChanged = (($current['availability_status'] ?? '') !== $status);
    $movedEnough = ($distanceMeters === null || $distanceMeters >= 55);
    $staleEnough = ($secondsSince >= 15);

    $shouldWrite = $statusChanged || ($movedEnough && $staleEnough);
}

if (!$shouldWrite) {
    echo json_encode(['success' => true, 'skipped' => true, 'time' => date('H:i:s')]);
    exit;
}

$stmt = $pdo->prepare('
    UPDATE rider_profiles
    SET last_latitude = ?, last_longitude = ?, availability_status = ?, last_location_updated_at = NOW()
    WHERE user_id = ?
');
$stmt->execute([$lat, $lng, $status, $user['id']]);

echo json_encode(['success' => true, 'time' => date('H:i:s')]);
