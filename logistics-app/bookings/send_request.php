<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();

function is_ajax_request(): bool
{
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');

    return $requestedWith === 'xmlhttprequest'
        || strpos($accept, 'application/json') !== false;
}

function fail_request(string $message, int $statusCode = 422, bool $ajax = false, ?int $bookingId = null, ?string $debug = null): void
{
    if ($ajax) {
        respond_json([
            'success' => false,
            'status' => false,
            'message' => $message,
            'debug'   => $debug,
        ], $statusCode);
    }

    flash('error', $message);

    if ($bookingId) {
        redirect_to('bookings/index.php?booking_id=' . $bookingId);
    }

    redirect_to('bookings/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('bookings/index.php');
}

$ajax = is_ajax_request();

$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$rawInput = file_get_contents('php://input');
$jsonInput = [];

if (strpos($contentType, 'application/json') !== false && $rawInput !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonInput = $decoded;
    }
}

$data = array_merge($jsonInput, $_POST);

require_csrf($data);

$bookingId = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
$riderUserId = isset($data['rider_user_id']) ? (int)$data['rider_user_id'] : 0;
$proposedCostRaw = trim((string)($data['proposed_cost'] ?? ''));
$proposedCost = is_numeric($proposedCostRaw) ? (float)$proposedCostRaw : 0;

if ($bookingId <= 0) {
    fail_request('Invalid booking selected.', 422, $ajax, null, 'booking_id missing or invalid');
}

if ($riderUserId <= 0) {
    fail_request('Invalid rider selected.', 422, $ajax, $bookingId, 'rider_user_id missing or invalid');
}

if ($proposedCost <= 0) {
    fail_request('Enter a valid proposed fee.', 422, $ajax, $bookingId, 'proposed_cost missing or invalid');
}

try {
    $pdo->beginTransaction();

    // Lock booking row
    if (in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM bookings
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$bookingId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT *
            FROM bookings
            WHERE id = ?
              AND sender_user_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$bookingId, (int)$user['id']]);
    }

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        fail_request('Booking not found or access denied.', 404, $ajax, $bookingId, 'booking lookup failed');
    }

    $blockedStatuses = [
        'matched',
        'accepted',
        'picked_up',
        'in_transit',
        'delivered',
        'cancelled',
        'arrived_at_pickup',
        'package_received'
    ];

    if (in_array(($booking['booking_status'] ?? ''), $blockedStatuses, true)) {
        $pdo->rollBack();
        fail_request('This booking can no longer receive rider requests.', 409, $ajax, $bookingId, 'booking status disallows request');
    }

    // Get rider
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.full_name,
            u.role,
            u.status,
            rp.availability_status
        FROM users u
        INNER JOIN rider_profiles rp ON rp.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$riderUserId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        $pdo->rollBack();
        fail_request('Selected rider was not found.', 404, $ajax, $bookingId, 'rider lookup failed');
    }

    if (($rider['role'] ?? '') !== 'rider') {
        $pdo->rollBack();
        fail_request('Selected user is not a rider.', 422, $ajax, $bookingId, 'user role is not rider');
    }

    if (($rider['availability_status'] ?? '') !== 'available') {
        $pdo->rollBack();
        fail_request('Selected rider is currently unavailable.', 409, $ajax, $bookingId, 'rider unavailable');
    }

    // Only block duplicate pending request
    $stmt = $pdo->prepare("
        SELECT id
        FROM rider_requests
        WHERE booking_id = ?
          AND rider_user_id = ?
          AND request_status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$bookingId, $riderUserId]);
    $existingPending = $stmt->fetchColumn();

    if ($existingPending) {
        $pdo->rollBack();
        fail_request('A pending request has already been sent to this rider.', 409, $ajax, $bookingId, 'duplicate pending request');
    }

    // Generate tracking token if missing
    $trackingToken = trim((string)($booking['sender_tracking_token'] ?? ''));
    if ($trackingToken === '') {
        $trackingToken = bin2hex(random_bytes(16));

        $stmt = $pdo->prepare("
            UPDATE bookings
            SET sender_tracking_token = ?
            WHERE id = ?
        ");
        $stmt->execute([$trackingToken, $bookingId]);
    }

    // Optional: reject other pending requests for same booking
    $stmt = $pdo->prepare("
        UPDATE rider_requests
        SET request_status = 'rejected'
        WHERE booking_id = ?
          AND request_status = 'pending'
          AND rider_user_id <> ?
    ");
    $stmt->execute([$bookingId, $riderUserId]);

    // Always insert new request
    $stmt = $pdo->prepare("
        INSERT INTO rider_requests (
            booking_id,
            sender_user_id,
            rider_user_id,
            proposed_cost,
            request_status,
            created_at
        ) VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
        $bookingId,
        (int)$booking['sender_user_id'],
        $riderUserId,
        $proposedCost
    ]);

    $newRequestId = (int)$pdo->lastInsertId();

    $newBookingStatus = $booking['booking_status'] ?? 'submitted';
    if ($newBookingStatus === 'draft') {
        $newBookingStatus = 'submitted';
    }

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET agreed_cost = ?,
            booking_status = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$proposedCost, $newBookingStatus, $bookingId]);

    $pdo->commit();

    $successMessage = 'Rider request sent successfully.';

    if ($ajax) {
        respond_json([
            'success' => true,
            'status'  => true,
            'message' => $successMessage,
            'data'    => [
                'request_id'    => $newRequestId,
                'booking_id'    => $bookingId,
                'rider_user_id' => $riderUserId,
                'tracking_token'=> $trackingToken,
                'redirect_url'  => url_path('bookings/index.php?booking_id=' . $bookingId),
                'tracking_url'  => url_path('bookings/track.php?token=' . urlencode($trackingToken)),
            ],
        ]);
    }

    flash('success', $successMessage);
    redirect_to('bookings/index.php?booking_id=' . $bookingId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($ajax) {
        respond_json([
            'success' => false,
            'status'  => false,
            'message' => 'Failed to send rider request.',
            'error'   => $e->getMessage(),
        ], 500);
    }

    flash('error', 'Failed to send rider request: ' . $e->getMessage());
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}