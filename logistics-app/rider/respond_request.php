<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect_to('rider/dashboard.php');
require_csrf();

$user = current_user();
$requestId = (int)($_POST['request_id'] ?? 0);
$action = ($_POST['action'] ?? '') === 'accepted' ? 'accepted' : 'rejected';

$stmt = $pdo->prepare('SELECT rr.*, b.id AS booking_id FROM rider_requests rr INNER JOIN bookings b ON b.id = rr.booking_id WHERE rr.id = ? AND rr.rider_user_id = ? LIMIT 1');
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();
if (!$request) exit('Request not found.');

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE rider_requests SET request_status = ?, responded_at = NOW() WHERE id = ?');
    $stmt->execute([$action, $requestId]);

    if ($action === 'accepted') {
        $stmt = $pdo->prepare('UPDATE bookings SET booking_status = "matched", selected_rider_user_id = ? WHERE id = ?');
        $stmt->execute([$user['id'], $request['booking_id']]);

        $stmt = $pdo->prepare('UPDATE rider_profiles SET availability_status = "busy" WHERE user_id = ?');
        $stmt->execute([$user['id']]);
    } else {
        $stmt = $pdo->prepare('UPDATE bookings SET selected_rider_user_id = NULL WHERE id = ? AND booking_status <> "matched"');
        $stmt->execute([$request['booking_id']]);
    }

    $pdo->commit();
    flash('success', 'Request ' . $action . '.');
} catch (Throwable $e) {
    $pdo->rollBack();
    exit('Failed to update request: ' . htmlspecialchars($e->getMessage()));
}

redirect_to('rider/navigation.php');
