<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

function sum_amount(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row['agreed_cost'] ?? $row['proposed_cost'] ?? 0);
    }
    return $total;
}

function badge_class(string $status): string
{
    return match ($status) {
        'matched' => 'bg-info text-dark',
        'accepted' => 'bg-primary',
        'arrived_at_pickup' => 'bg-warning text-dark',
        'package_received' => 'bg-secondary',
        'in_transit' => 'bg-info',
        'delivered' => 'bg-success',
        'cancelled' => 'bg-danger',
        'paid' => 'bg-success',
        'pending' => 'bg-warning text-dark',
        'rejected' => 'bg-danger',
        default => 'bg-dark border border-secondary'
    };
}



function realtime_base_dir(): string
{
    $dir = dirname(__DIR__) . '/assets';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function realtime_ensure_dir(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

function realtime_booking_context(PDO $pdo, array $user, int $bookingId): ?array
{
    $role = (string)($user['role'] ?? '');

    if ($role === 'rider') {
        $stmt = $pdo->prepare('SELECT id, sender_user_id, selected_rider_user_id FROM bookings WHERE id = ? AND selected_rider_user_id = ? LIMIT 1');
        $stmt->execute([$bookingId, (int)$user['id']]);
    } elseif ($role === 'admin') {
        $stmt = $pdo->prepare('SELECT id, sender_user_id, selected_rider_user_id FROM bookings WHERE id = ? LIMIT 1');
        $stmt->execute([$bookingId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, sender_user_id, selected_rider_user_id FROM bookings WHERE id = ? AND sender_user_id = ? LIMIT 1');
        $stmt->execute([$bookingId, (int)$user['id']]);
    }

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        return null;
    }

    $counterpartId = $role === 'rider'
        ? (int)($booking['sender_user_id'] ?? 0)
        : (int)($booking['selected_rider_user_id'] ?? 0);

    if ($counterpartId <= 0) {
        return null;
    }

    $booking['counterpart_user_id'] = $counterpartId;
    return $booking;
}

function realtime_insert_voice_message(PDO $pdo, int $bookingId, int $senderUserId, int $receiverUserId, string $message): void
{
    $attempts = [
        ['INSERT INTO booking_chat_messages (booking_id, sender_user_id, receiver_user_id, message, is_read, delivered_at, created_at) VALUES (?, ?, ?, ?, 0, NOW(), NOW())', [$bookingId, $senderUserId, $receiverUserId, $message]],
        ['INSERT INTO booking_chat_messages (booking_id, sender_user_id, receiver_user_id, message, created_at) VALUES (?, ?, ?, ?, NOW())', [$bookingId, $senderUserId, $receiverUserId, $message]],
        ['INSERT INTO booking_chat_messages (booking_id, sender_user_id, receiver_user_id, message) VALUES (?, ?, ?, ?)', [$bookingId, $senderUserId, $receiverUserId, $message]],
    ];

    $lastException = null;
    foreach ($attempts as [$sql, $params]) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return;
        } catch (Throwable $e) {
            $lastException = $e;
        }
    }

    if ($lastException) {
        throw $lastException;
    }
}

$realtimeAction = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
if (in_array($realtimeAction, ['call_create', 'call_poll', 'call_accept', 'call_end', 'voice_upload'], true)) {
    header('Content-Type: application/json');

    try {
        $bookingIdForRealtime = (int)($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);
        if ($bookingIdForRealtime <= 0) {
            respond_json(['success' => false, 'message' => 'Invalid booking supplied.'], 422);
        }

        $ctx = realtime_booking_context($pdo, $user, $bookingIdForRealtime);
        if (!$ctx) {
            respond_json(['success' => false, 'message' => 'Booking not found, access denied, or counterpart not assigned yet.'], 404);
        }

        $assetsRoot = realtime_base_dir();
        $callsDir = $assetsRoot . '/realtime_calls';
        $voiceDir = $assetsRoot . '/voice_notes';
        realtime_ensure_dir($callsDir);
        realtime_ensure_dir($voiceDir);

        $callFile = $callsDir . '/booking_' . $bookingIdForRealtime . '.json';
        $currentUserId = (int)($user['id'] ?? 0);
        $counterpartId = (int)($ctx['counterpart_user_id'] ?? 0);

        if (in_array($realtimeAction, ['call_create', 'call_accept', 'call_end', 'voice_upload'], true)) {
            require_csrf();
        }

        if ($realtimeAction === 'call_create') {
            $payload = [
                'booking_id' => $bookingIdForRealtime,
                'from_user_id' => $currentUserId,
                'to_user_id' => $counterpartId,
                'room_id' => 'booking-' . $bookingIdForRealtime,
                'status' => 'ringing',
                'created_at' => time(),
            ];
            file_put_contents($callFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            respond_json(['success' => true, 'call' => $payload]);
        }

        if ($realtimeAction === 'call_poll') {
            if (!is_file($callFile)) {
                respond_json(['success' => true, 'active' => false]);
            }

            $payload = json_decode((string)file_get_contents($callFile), true) ?: [];
            if (($payload['created_at'] ?? 0) > 0 && (time() - (int)$payload['created_at']) > 1800) {
                @unlink($callFile);
                respond_json(['success' => true, 'active' => false]);
            }

            $isParticipant = in_array($currentUserId, [(int)($payload['from_user_id'] ?? 0), (int)($payload['to_user_id'] ?? 0)], true);
            if (!$isParticipant) {
                respond_json(['success' => true, 'active' => false]);
            }

            respond_json(['success' => true, 'active' => true, 'call' => $payload]);
        }

        if ($realtimeAction === 'call_accept') {
            if (!is_file($callFile)) {
                respond_json(['success' => false, 'message' => 'No active call found.'], 404);
            }
            $payload = json_decode((string)file_get_contents($callFile), true) ?: [];
            if ((int)($payload['to_user_id'] ?? 0) !== $currentUserId) {
                respond_json(['success' => false, 'message' => 'Only the recipient can accept this call.'], 403);
            }
            $payload['status'] = 'accepted';
            $payload['accepted_at'] = time();
            file_put_contents($callFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            respond_json(['success' => true, 'call' => $payload]);
        }

        if ($realtimeAction === 'call_end') {
            if (is_file($callFile)) {
                @unlink($callFile);
            }
            respond_json(['success' => true]);
        }

        if ($realtimeAction === 'voice_upload') {
            if (empty($_FILES['voice_note']) || !is_uploaded_file($_FILES['voice_note']['tmp_name'])) {
                respond_json(['success' => false, 'message' => 'No voice note uploaded.'], 422);
            }

            $receiverUserId = (int)($_POST['receiver_user_id'] ?? 0);
            if ($receiverUserId <= 0 || $receiverUserId !== $counterpartId) {
                respond_json(['success' => false, 'message' => 'Invalid voice note recipient.'], 422);
            }

            $tmp = $_FILES['voice_note']['tmp_name'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'audio/webm';
            $extMap = [
                'audio/webm' => 'webm',
                'audio/ogg' => 'ogg',
                'audio/mpeg' => 'mp3',
                'audio/mp4' => 'm4a',
                'audio/x-m4a' => 'm4a',
                'audio/wav' => 'wav',
                'audio/x-wav' => 'wav',
            ];
            $ext = $extMap[$mime] ?? 'webm';
            $fileName = 'voice_' . $bookingIdForRealtime . '_' . $currentUserId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = $voiceDir . '/' . $fileName;
            if (!move_uploaded_file($tmp, $target)) {
                respond_json(['success' => false, 'message' => 'Unable to save voice note.'], 500);
            }

            $publicPath = url_path('assets/voice_notes/' . $fileName);
            $chatPayload = '[voice-note]' . $publicPath;
            realtime_insert_voice_message($pdo, $bookingIdForRealtime, $currentUserId, $receiverUserId, $chatPayload);

            respond_json(['success' => true, 'path' => $publicPath, 'message' => $chatPayload]);
        }
    } catch (Throwable $e) {
        respond_json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ---------------- AJAX: RESPOND TO REQUEST ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'respond_request') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['success' => false, 'message' => 'Invalid request method.'], 405);
    }

    if (($_POST['csrf_token'] ?? '') === '' || !hash_equals(csrf_token(), (string)$_POST['csrf_token'])) {
        respond_json(['success' => false, 'message' => 'Invalid or expired security token. Please refresh and try again.'], 419);
    }

    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));

    if ($requestId <= 0 || !in_array($action, ['accepted', 'rejected'], true)) {
        respond_json(['success' => false, 'message' => 'Invalid request details.'], 422);
    }

    try {
        $stmt = $pdo->prepare('
            SELECT 
                rr.*,
                b.id AS booking_id,
                b.booking_status,
                b.selected_rider_user_id,
                b.agreed_cost,
                b.payment_status
            FROM rider_requests rr
            INNER JOIN bookings b ON b.id = rr.booking_id
            WHERE rr.id = ?
              AND rr.rider_user_id = ?
            LIMIT 1
        ');
        $stmt->execute([$requestId, $user['id']]);
        $requestRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$requestRow) {
            respond_json(['success' => false, 'message' => 'Request not found.'], 404);
        }

        if (($requestRow['request_status'] ?? '') !== 'pending') {
            respond_json(['success' => false, 'message' => 'This request has already been processed.'], 409);
        }

        if ($action === 'accepted') {
            $stmt = $pdo->prepare('
                SELECT COUNT(*) 
                FROM bookings
                WHERE selected_rider_user_id = ?
                  AND booking_status IN ("matched", "accepted", "arrived_at_pickup", "package_received", "in_transit")
                  AND id <> ?
            ');
            $stmt->execute([$user['id'], (int)$requestRow['booking_id']]);
            $hasOtherActive = (int)$stmt->fetchColumn() > 0;

            if ($hasOtherActive) {
                respond_json(['success' => false, 'message' => 'You already have another active delivery. Complete it before accepting a new one.'], 409);
            }
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        if ($action === 'accepted') {
            $stmt = $pdo->prepare('
                UPDATE rider_requests
                SET request_status = "accepted"
                WHERE id = ?
            ');
            $stmt->execute([$requestId]);

            $stmt = $pdo->prepare('
                UPDATE rider_requests
                SET request_status = "rejected"
                WHERE booking_id = ?
                  AND id <> ?
                  AND request_status = "pending"
            ');
            $stmt->execute([(int)$requestRow['booking_id'], $requestId]);

            $stmt = $pdo->prepare('
                UPDATE bookings
                SET selected_rider_user_id = ?,
                    agreed_cost = CASE
                        WHEN agreed_cost IS NULL OR agreed_cost = 0 THEN ?
                        ELSE agreed_cost
                    END,
                    booking_status = CASE
                        WHEN booking_status = "submitted" THEN "matched"
                        ELSE booking_status
                    END
                WHERE id = ?
            ');
            $stmt->execute([
                $user['id'],
                (float)($requestRow['proposed_cost'] ?? 0),
                (int)$requestRow['booking_id']
            ]);

            $message = 'Offer accepted successfully.';
        } else {
            $stmt = $pdo->prepare('
                UPDATE rider_requests
                SET request_status = "rejected"
                WHERE id = ?
            ');
            $stmt->execute([$requestId]);
            $message = 'Offer rejected successfully.';
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        respond_json([
            'success' => true,
            'message' => $message,
            'action' => $action,
            'request_id' => $requestId,
            'booking_id' => (int)$requestRow['booking_id']
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond_json(['success' => false, 'message' => 'Unable to process request: ' . $e->getMessage()], 500);
    }
}

// ---------------- ACTIVE BOOKING ----------------
$stmt = $pdo->prepare('
    SELECT 
        b.*, 
        s.full_name AS sender_name, 
        s.phone AS sender_phone 
    FROM bookings b 
    INNER JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ? 
      AND b.booking_status IN ("matched", "accepted", "arrived_at_pickup", "package_received", "in_transit")
    ORDER BY b.id DESC
    LIMIT 1
');
$stmt->execute([$user['id']]);
$activeBooking = $stmt->fetch(PDO::FETCH_ASSOC);

$pickupLat = ($activeBooking && $activeBooking['pickup_latitude'] !== null) ? (float)$activeBooking['pickup_latitude'] : null;
$pickupLng = ($activeBooking && $activeBooking['pickup_longitude'] !== null) ? (float)$activeBooking['pickup_longitude'] : null;
$destLat   = ($activeBooking && $activeBooking['delivery_latitude'] !== null) ? (float)$activeBooking['delivery_latitude'] : null;
$destLng   = ($activeBooking && $activeBooking['delivery_longitude'] !== null) ? (float)$activeBooking['delivery_longitude'] : null;

$bookingAmount = $activeBooking ? (float)($activeBooking['agreed_cost'] ?? 0) : 0;
$currentStatus = $activeBooking['booking_status'] ?? null;

$senderConfirmedHandover =
    (bool)(
        $activeBooking['sender_handover_confirmed'] ??
        $activeBooking['package_handover_confirmed_by_sender'] ??
        $activeBooking['sender_package_confirmed'] ??
        false
    );

// ---------------- TARGET SWITCHING ----------------
$targetLat = null;
$targetLng = null;
$targetLabel = 'Destination';
$targetAddress = '';

if ($activeBooking) {
    if (in_array($currentStatus, ['matched', 'accepted'], true)) {
        $targetLat = $pickupLat;
        $targetLng = $pickupLng;
        $targetLabel = 'Pickup';
        $targetAddress = (string)($activeBooking['pickup_address'] ?? '');
    } else {
        $targetLat = $destLat;
        $targetLng = $destLng;
        $targetLabel = 'Destination';
        $targetAddress = (string)($activeBooking['delivery_address'] ?? '');
    }
}

$mapLink = ($targetLat !== null && $targetLng !== null)
    ? "https://www.google.com/maps/dir/?api=1&destination={$targetLat},{$targetLng}&travelmode=driving"
    : "#";

// ---------------- REQUESTS / ORDERS ----------------
$stmt = $pdo->prepare('
    SELECT 
        rr.*, 
        b.booking_code, 
        b.pickup_address, 
        b.delivery_address, 
        b.item_name,
        b.booking_status,
        b.agreed_cost,
        b.payment_status,
        b.sender_user_id,
        u.full_name AS sender_name
    FROM rider_requests rr
    INNER JOIN bookings b ON b.id = rr.booking_id
    LEFT JOIN users u ON u.id = b.sender_user_id
    WHERE rr.rider_user_id = ? 
    ORDER BY FIELD(rr.request_status, "pending","accepted","rejected"), rr.id DESC
');
$stmt->execute([$user['id']]);
$allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingOffers = array_values(array_filter($allRequests, fn($r) => ($r['request_status'] ?? '') === 'pending'));
$acceptedRequests = array_values(array_filter($allRequests, fn($r) => ($r['request_status'] ?? '') === 'accepted'));
$rejectedRequests = array_values(array_filter($allRequests, fn($r) => ($r['request_status'] ?? '') === 'rejected'));

// ---------------- ORDER SUMMARY DATA ----------------
$stmt = $pdo->prepare('
    SELECT 
        b.*,
        s.full_name AS sender_name,
        s.phone AS sender_phone
    FROM bookings b
    INNER JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ?
    ORDER BY b.id DESC
');
$stmt->execute([$user['id']]);
$allAssignedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$matchedBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'matched'));
$acceptedBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'accepted'));
$pickupBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'arrived_at_pickup'));
$packageReceivedBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'package_received'));
$inTransitBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'in_transit'));
$deliveredBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'delivered'));
$cancelledBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'cancelled'));

$ongoingBookings = array_values(array_filter(
    $allAssignedBookings,
    fn($b) => in_array(($b['booking_status'] ?? ''), ['matched', 'accepted', 'arrived_at_pickup', 'package_received', 'in_transit'], true)
));

// ---------------- EARNINGS / PAYMENTS ----------------
$stmt = $pdo->prepare('
    SELECT 
        b.id,
        b.booking_code,
        b.agreed_cost,
        b.payment_status,
        b.booking_status,
        b.updated_at,
        b.created_at,
        s.full_name AS sender_name
    FROM bookings b
    LEFT JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ?
      AND b.booking_status = "delivered"
    ORDER BY b.id DESC
');
$stmt->execute([$user['id']]);
$deliveredEarningRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$paidEarningRows = array_values(array_filter($deliveredEarningRows, fn($r) => ($r['payment_status'] ?? '') === 'paid'));
$unpaidEarningRows = array_values(array_filter($deliveredEarningRows, fn($r) => ($r['payment_status'] ?? '') !== 'paid'));

$todayStart = (new DateTime('today'))->format('Y-m-d H:i:s');
$weekStart = (new DateTime('monday this week'))->format('Y-m-d H:i:s');
$monthStart = (new DateTime('first day of this month'))->format('Y-m-d H:i:s');

$todayPaidRows = array_values(array_filter($paidEarningRows, function ($r) use ($todayStart) {
    $dt = $r['updated_at'] ?? $r['created_at'] ?? null;
    return $dt && $dt >= $todayStart;
}));

$weekPaidRows = array_values(array_filter($paidEarningRows, function ($r) use ($weekStart) {
    $dt = $r['updated_at'] ?? $r['created_at'] ?? null;
    return $dt && $dt >= $weekStart;
}));

$monthPaidRows = array_values(array_filter($paidEarningRows, function ($r) use ($monthStart) {
    $dt = $r['updated_at'] ?? $r['created_at'] ?? null;
    return $dt && $dt >= $monthStart;
}));

$totalPaidToday = sum_amount($todayPaidRows);
$totalPaidWeek = sum_amount($weekPaidRows);
$totalPaidMonth = sum_amount($monthPaidRows);
$totalPaidOverall = sum_amount($paidEarningRows);
$totalOutstanding = sum_amount($unpaidEarningRows);
$totalExpectedOverall = sum_amount($deliveredEarningRows);

// ---------------- PROFILE ----------------
$stmt = $pdo->prepare('
    SELECT availability_status, last_latitude, last_longitude
    FROM rider_profiles
    WHERE user_id = ?
    LIMIT 1
');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

$isOnline = ($profile['availability_status'] ?? 'offline') === 'available';
// Fall back to Nigeria's geographic centroid (not any specific city) when a rider has no saved fix yet.
$initialLat = isset($profile['last_latitude']) ? (float)$profile['last_latitude'] : 9.0820;
$initialLng = isset($profile['last_longitude']) ? (float)$profile['last_longitude'] : 8.6753;

$ajaxUpdateLocationUrl = url_path('rider/ajax_update_location.php');
$ajaxUpdateStatusUrl = url_path('rider/ajax_update_status.php');
$ajaxWorkflowUrl = url_path('rider/ajax_workflow_action.php');
$logoutUrl = url_path('logout.php');

$canChat = $activeBooking && (int)($activeBooking['sender_user_id'] ?? 0) > 0;
$chatReceiverId = $canChat ? (int)$activeBooking['sender_user_id'] : 0;

// ---------------- AJAX SNAPSHOT ----------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'snapshot') {
    $pendingIds = array_map(fn($r) => (int)$r['id'], $pendingOffers);

    $popupRequest = null;
    if (!empty($pendingOffers)) {
        $firstPending = $pendingOffers[0];
        $popupRequest = [
            'id' => (int)$firstPending['id'],
            'booking_id' => (int)$firstPending['booking_id'],
            'booking_code' => (string)$firstPending['booking_code'],
            'pickup_address' => (string)$firstPending['pickup_address'],
            'delivery_address' => (string)$firstPending['delivery_address'],
            'sender_name' => (string)($firstPending['sender_name'] ?? 'Unknown'),
            'item_name' => (string)($firstPending['item_name'] ?? 'Package'),
            'proposed_cost' => (float)($firstPending['proposed_cost'] ?? 0),
        ];
    }

    ob_start();
    ?>
    <?php if (empty($pendingOffers)): ?>
        <div class="text-center py-5 text-soft">
            <i class="fa-solid fa-satellite-dish fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">Scanning for nearby orders...</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($pendingOffers as $req): ?>
                <div class="col-lg-6">
                    <div class="req-card p-3 border-warning h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="price-tag">₦<?= number_format((float)$req['proposed_cost'], 2) ?></span>
                            <span class="small text-soft">#<?= htmlspecialchars($req['booking_code']) ?></span>
                        </div>
                        <div class="small text-soft mb-2">Sender: <?= htmlspecialchars($req['sender_name'] ?? 'Unknown') ?></div>
                        <p class="small mb-2"><i class="fa-solid fa-map-pin me-2 text-warning"></i><?= htmlspecialchars($req['pickup_address']) ?></p>
                        <p class="small mb-3"><i class="fa-solid fa-location-dot me-2 text-info"></i><?= htmlspecialchars($req['delivery_address']) ?></p>
                        <form class="offer-action-form d-flex gap-2" method="post" action="#">
                            <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                            <button class="btn btn-success flex-grow-1 fw-bold" type="submit" name="action" value="accepted">ACCEPT OFFER</button>
                            <button class="btn btn-outline-danger" type="submit" name="action" value="rejected"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
    $offersHtml = ob_get_clean();

    respond_json([
        'success' => true,
        'pending_offers_count' => count($pendingOffers),
        'pending_offer_ids' => $pendingIds,
        'popup_request' => $popupRequest,
        'offers_html' => $offersHtml,
        'summaries' => [
            'ongoing' => count($ongoingBookings),
            'delivered' => count($deliveredBookings),
            'cancelled' => count($cancelledBookings),
            'matched' => count($matchedBookings),
            'accepted' => count($acceptedBookings),
            'pickup' => count($pickupBookings),
            'package_received' => count($packageReceivedBookings),
            'in_transit' => count($inTransitBookings),
            'paid_today' => $totalPaidToday,
            'paid_week' => $totalPaidWeek,
            'paid_month' => $totalPaidMonth,
            'paid_overall' => $totalPaidOverall,
            'outstanding' => $totalOutstanding,
            'expected_overall' => $totalExpectedOverall,
        ],
        'active_booking' => $activeBooking ? [
            'id' => (int)$activeBooking['id'],
            'status' => (string)($activeBooking['booking_status'] ?? ''),
            'sender_handover_confirmed' => (bool)$senderConfirmedHandover,
            'payment_status' => (string)($activeBooking['payment_status'] ?? ''),
        ] : null,
    ]);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title>Rider Dashboard | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css">
    <style>
        body { background:#09101d; min-height:100vh; color:#eef4ff; font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .navx { background:rgba(8,17,33,.88); border-bottom:1px solid rgba(255,255,255,.08); }
        .cardx { background:rgba(17,27,51,.95); border-radius:1.5rem; border:1px solid rgba(255,255,255,.08); box-shadow:0 10px 40px rgba(0,0,0,0.4); }
        #nav_map { height:400px; width:100%; border-radius:1.25rem; border:1px solid rgba(255,255,255,0.1); margin-bottom:1rem; overflow:hidden; }
        #route_details { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,.08); border-radius:1rem; padding:14px; color:#cfe0ff; font-size:.92rem; margin-bottom:1.5rem; max-height:220px; overflow-y:auto; }
        #route_details .route-title { font-weight:700; margin-bottom:8px; color:#fff; }
        #route_details .route-step { padding:6px 0; border-bottom:1px solid rgba(255,255,255,.06); }
        #route_details .route-step:last-child { border-bottom:none; }
        .stats-bar { background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.2); border-radius:1rem; padding:12px; margin-bottom:1.5rem; }
        .summary-card { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,.08); border-radius:1rem; padding:16px; height:100%; }
        .stat-label { font-size:.65rem; color:#9fb0d6; text-transform:uppercase; letter-spacing:1px; }
        .stat-value { font-size:1rem; font-weight:800; color:#fff; }
        .money-big { font-size:1.4rem; font-weight:800; color:#38bdf8; }
        .swipe-container { width:100%; height:54px; background:#16203a; border-radius:27px; position:relative; cursor:pointer; border:2px solid rgba(255,255,255,0.1); transition:0.4s; user-select:none; }
        .swipe-handle { width:46px; height:46px; background:#9fb0d6; border-radius:50%; position:absolute; top:2px; left:3px; transition:0.4s; display:flex; align-items:center; justify-content:center; color:#09101d; z-index:2; }
        .swipe-text { position:absolute; width:100%; text-align:center; line-height:50px; font-size:.85rem; font-weight:800; letter-spacing:1px; z-index:1; pointer-events:none; }
        .swipe-container.active { background:#10b981; border-color:#10b981; }
        .swipe-container.active .swipe-handle { left:calc(100% - 49px); background:#fff; color:#10b981; }
        .req-card { background:rgba(255,255,255,0.03); border-radius:1rem; border:1px solid rgba(255,255,255,0.08); margin-bottom:1rem; }
        .price-tag { color:#38bdf8; font-weight:800; font-size:1.1rem; }
        .pulse-btn { animation:pulse-green 2s infinite; border-radius:12px; }
        @keyframes pulse-green { 0% { box-shadow:0 0 0 0 rgba(16,185,129,0.7);} 70% { box-shadow:0 0 0 15px rgba(16,185,129,0);} 100% { box-shadow:0 0 0 0 rgba(16,185,129,0);} }
        .nav-tabs { border:none; gap:8px; }
        .nav-link { color:#9fb0d6; border:none !important; border-radius:10px !important; font-weight:600; padding:10px 20px; }
        .nav-link.active { background:#38bdf8 !important; color:#09101d !important; }
        .text-soft { color:#9fb0d6; }
        .map-legend { position:absolute; left:12px; bottom:12px; background:rgba(8,17,33,.82); border:1px solid rgba(255,255,255,.08); border-radius:.75rem; padding:.6rem .8rem; color:#cfe0ff; font-size:.82rem; z-index:3; }
        .system-msg { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,.08); border-radius:1rem; padding:12px 14px; font-size:.9rem; color:#cfe0ff; margin-bottom:1rem; }
        .mini-row { border-bottom:1px solid rgba(255,255,255,.06); padding:10px 0; }
        .mini-row:last-child { border-bottom:none; }
        .glance-row { cursor:pointer; transition:.15s ease; border-radius:.5rem; padding-left:8px; padding-right:8px; margin:0 -8px; }
        .glance-row:hover { background:rgba(56,189,248,.08); }
        .order-search-wrap { position:relative; max-width:320px; }
        .order-search-wrap input { padding-left:2.25rem; }
        .order-search-wrap i { position:absolute; left:.8rem; top:50%; transform:translateY(-50%); color:#9fb0d6; }
        .pill { display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:999px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); font-size:.85rem; }
        .sticky-chat-btn { position:fixed; right:20px; bottom:20px; z-index:99999; width:60px; height:60px; border-radius:50%; border:none; background:linear-gradient(135deg,#38bdf8,#0ea5e9); color:#09101d; box-shadow:0 12px 24px rgba(0,0,0,.35); font-size:1.25rem; display:flex; align-items:center; justify-content:center; }
        .chat-panel { position:fixed; right:20px; bottom:90px; width:380px; max-width:calc(100vw - 24px); height:520px; max-height:72vh; z-index:100000; border-radius:1.25rem; background:rgba(8,17,33,.72); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border:1px solid rgba(255,255,255,.10); box-shadow:0 20px 40px rgba(0,0,0,.35); display:none; overflow:hidden; }
        .chat-header { padding:14px 16px; border-bottom:1px solid rgba(255,255,255,.08); display:flex; justify-content:space-between; align-items:center; }
        .chat-messages { height:360px; overflow-y:auto; padding:14px; display:flex; flex-direction:column; gap:10px; }
        .chat-bubble { max-width:80%; padding:10px 12px; border-radius:14px; font-size:.92rem; line-height:1.35; word-wrap:break-word; }
        .chat-bubble.me { align-self:flex-end; background:rgba(56,189,248,.18); border:1px solid rgba(56,189,248,.30); color:#eef4ff; }
        .chat-bubble.them { align-self:flex-start; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.10); color:#eef4ff; }
        .chat-time { display:block; font-size:.72rem; color:#9fb0d6; margin-top:6px; }
        .chat-status { display:block; font-size:.70rem; color:#7dd3fc; margin-top:4px; text-align:right; }
        .chat-footer { padding:12px; border-top:1px solid rgba(255,255,255,.08); }
        .chat-footer textarea { resize:none; min-height:54px; max-height:100px; }
        .chat-action-row{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
        .chat-action-row>*{min-width:105px}
        .voice-note-wrap{display:flex;align-items:center;gap:8px;min-width:220px;max-width:100%}
        .voice-note-wrap audio{width:220px;max-width:100%}
        .recording-live{box-shadow:0 0 0 0 rgba(248,113,113,.7);animation:recordPulse 1.2s infinite}
        .call-panel{position:fixed;right:20px;bottom:620px;width:380px;max-width:calc(100vw - 24px);z-index:100001;border-radius:1.25rem;background:rgba(8,17,33,.88);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.12);box-shadow:0 20px 40px rgba(0,0,0,.35);display:none;padding:16px}
        .call-panel .call-actions{display:flex;gap:8px;margin-top:12px}
        @keyframes recordPulse{0%{box-shadow:0 0 0 0 rgba(248,113,113,.55)}70%{box-shadow:0 0 0 12px rgba(248,113,113,0)}100%{box-shadow:0 0 0 0 rgba(248,113,113,0)}}
        .request-indicator { min-width:22px; height:22px; border-radius:999px; background:#ef4444; color:#fff; font-size:.72rem; font-weight:700; display:none; align-items:center; justify-content:center; padding:0 6px; }
        .toast-container-custom { position:fixed; top:16px; right:16px; z-index:110000; width:min(360px, calc(100vw - 24px)); }
        @media (max-width:576px){ .sticky-chat-btn{right:14px;bottom:14px} .chat-panel{right:12px;left:12px;width:auto;bottom:84px} .call-panel{right:12px;left:12px;width:auto;bottom:620px} }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('index.php')) ?>">SwiftDrop</a>
        <div class="navbar-nav ms-auto flex-row gap-3">
            <a class="nav-link" href="<?= e(url_path('rider/dashboard.php')) ?>"><i class="fa-solid fa-list-ul me-1"></i>My Deliveries</a>
            <a class="nav-link" href="<?= e($logoutUrl) ?>"><i class="fa-solid fa-right-from-bracket me-1"></i>Logout</a>
        </div>
    </div>
</nav>

<div class="toast-container-custom" id="toast-container"></div>

<div
    class="container py-4"
    id="rider-dashboard-root"
    data-user-id="<?= (int)$user['id'] ?>"
    data-booking-id="<?= $activeBooking ? (int)$activeBooking['id'] : 0 ?>"
    data-chat-enabled="<?= $canChat ? '1' : '0' ?>"
    data-chat-receiver-id="<?= (int)$chatReceiverId ?>"
    data-snapshot-url="<?= e(url_path('rider/index.php?ajax=snapshot')) ?>"
    data-respond-url="<?= e(url_path('rider/index.php?ajax=respond_request')) ?>"
    data-chat-fetch-url="<?= e(url_path('chat/ajax_fetch_messages.php')) ?>"
    data-chat-send-url="<?= e(url_path('chat/ajax_send_message.php')) ?>"
>
    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>
    <div id="alert-container"></div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Rider Dashboard</h1>
            <p class="text-soft mb-0">Track your current job, or stay online for new offers.</p>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="cardx p-3 p-md-4">
                <?php if ($activeBooking): ?>
                    <div class="stats-bar d-flex justify-content-between align-items-center flex-wrap">
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                            <div class="stat-label">Current Job Value</div>
                            <div class="stat-value text-info">₦<?= number_format($bookingAmount, 2) ?></div>
                        </div>
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                            <div class="stat-label">Distance</div>
                            <div class="stat-value" id="distance_display">--</div>
                        </div>
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                            <div class="stat-label">ETA</div>
                            <div class="stat-value" id="eta_display">--</div>
                        </div>
                        <div class="text-center flex-fill px-2">
                            <div class="stat-label">System</div>
                            <div id="sync_status" class="stat-value small text-success">READY</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 fw-bold mb-0">Rider Radar</h2>
                        <span id="sync_status" class="badge bg-dark border border-secondary text-info">OFFLINE</span>
                    </div>
                <?php endif; ?>

                <div id="nav_map">
                    <div class="map-legend">
                        <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#38bdf8;margin-right:6px"></span>Rider</div>
                        <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;margin-right:6px"></span><span id="target_label"><?= e($targetLabel) ?></span></div>
                    </div>
                </div>

                <div id="route_details">
                    <div class="route-title">Route Details</div>
                    <div id="route_details_body">Waiting for route...</div>
                </div>

                <div class="system-msg" id="geo_message">
                    <?php if ($activeBooking): ?>
                        Current target: <strong><?= e($targetLabel) ?></strong> — <?= e($targetAddress) ?>
                    <?php else: ?>
                        Tap the slider to go online. If GPS is unavailable, the map still shows your last known saved position.
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <div id="swipe-btn" class="swipe-container <?= $isOnline ? 'active' : '' ?>" onclick="toggleStatus()">
                        <div class="swipe-handle"><i class="fa-solid fa-motorcycle"></i></div>
                        <span class="swipe-text"><?= $isOnline ? 'TRACKING ONLINE' : 'SWIPE TO START WORKING' ?></span>
                    </div>
                </div>

                <?php if ($activeBooking): ?>
                    <div class="req-card p-3 border-info shadow-sm">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <span class="badge <?= e(badge_class($currentStatus)) ?>" id="active-booking-status-badge"><?= e(strtoupper(str_replace('_', ' ', (string)$currentStatus))) ?></span>
                            <div class="d-flex gap-2">
                                <a href="tel:<?= e($activeBooking['sender_phone']) ?>" class="btn btn-sm btn-dark border-secondary rounded-pill px-3">
                                    <i class="fa-solid fa-phone"></i>
                                </a>
                                <?php if ($targetLat !== null && $targetLng !== null): ?>
                                    <a href="<?= e($mapLink) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">
                                        <i class="fa-solid fa-diamond-turn-right me-1"></i> NAVIGATE
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="small text-soft">Booking</div>
                                <div class="fw-bold"><?= e($activeBooking['booking_code'] ?? '') ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-soft">Sender</div>
                                <div class="fw-bold"><?= e($activeBooking['sender_name'] ?? '') ?></div>
                            </div>
                        </div>

                        <p class="fw-bold mb-1 small text-truncate">
                            <i class="fa-solid fa-location-dot me-2 text-danger"></i>
                            <span id="target_address_text"><?= e($targetAddress) ?></span>
                        </p>
                        <p class="small text-soft mb-3">Item: <?= e($activeBooking['item_name'] ?? '') ?></p>

                        <?php if ($currentStatus === 'arrived_at_pickup'): ?>
                            <div class="system-msg mb-3" id="sender_handover_notice">
                                <?php if ($senderConfirmedHandover): ?>
                                    <i class="fa-solid fa-circle-check text-success me-2"></i>
                                    Sender has confirmed package handover. You can now mark package received.
                                <?php else: ?>
                                    <i class="fa-solid fa-handshake-angle text-warning me-2"></i>
                                    Waiting for sender confirmation before you can select <strong>Received Package</strong>.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <button
                            type="button"
                            id="btn_workflow"
                            class="btn btn-secondary w-100 py-3 fw-bold pulse-btn"
                            disabled
                            data-sender-handover-confirmed="<?= $senderConfirmedHandover ? '1' : '0' ?>"
                        >
                            CHECKING LOCATION...
                        </button>
                    </div>
                <?php else: ?>
                    <div class="system-msg mb-0">
                        <i class="fa-solid fa-satellite-dish me-2 text-info"></i>
                        No active delivery right now. Stay online to receive new offers.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <?php if (!$activeBooking): ?>
    <div class="cardx p-4 mb-4" id="offers">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h5 fw-bold mb-0">New Offers</h2>
            <span class="request-indicator" id="new-request-indicator" style="<?= empty($pendingOffers) ? '' : 'display:inline-flex;' ?>"><?= count($pendingOffers) ?></span>
        </div>
        <div id="offers-list-wrap">
            <?php if (empty($pendingOffers)): ?>
                <div class="text-center py-5 text-soft">
                    <i class="fa-solid fa-satellite-dish fa-3x mb-3 opacity-25"></i>
                    <p class="mb-0">Scanning for nearby orders...</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($pendingOffers as $req): ?>
                        <div class="col-lg-6">
                            <div class="req-card p-3 border-warning h-100">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="price-tag">₦<?= number_format((float)$req['proposed_cost'], 2) ?></span>
                                    <span class="small text-soft">#<?= e($req['booking_code']) ?></span>
                                </div>
                                <div class="small text-soft mb-2">Sender: <?= e($req['sender_name'] ?? 'Unknown') ?></div>
                                <p class="small mb-2"><i class="fa-solid fa-map-pin me-2 text-warning"></i><?= e($req['pickup_address']) ?></p>
                                <p class="small mb-3"><i class="fa-solid fa-location-dot me-2 text-info"></i><?= e($req['delivery_address']) ?></p>
                                <form class="offer-action-form d-flex gap-2" method="post" action="#">
                                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                    <button class="btn btn-success flex-grow-1 fw-bold" type="submit" name="action" value="accepted">ACCEPT OFFER</button>
                                    <button class="btn btn-outline-danger" type="submit" name="action" value="rejected"><i class="fa-solid fa-xmark"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<div class="modal fade" id="newRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">New Delivery Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="new-request-modal-body">
                Waiting for request details...
            </div>
            <div class="modal-footer border-secondary">
                <form id="new-request-reject-form" class="me-2">
                    <input type="hidden" name="request_id" id="modal-request-id-reject">
                    <button class="btn btn-outline-danger" type="submit">Reject</button>
                </form>
                <form id="new-request-accept-form">
                    <input type="hidden" name="request_id" id="modal-request-id-accept">
                    <button class="btn btn-success" type="submit">Accept</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($canChat): ?>
<button type="button" class="sticky-chat-btn" id="open-chat-btn" title="Open chat">
    <i class="fa-solid fa-comments"></i>
</button>

<div class="chat-panel" id="chat-panel">
    <div class="chat-header">
        <div>
            <div class="fw-bold">Chat with Sender</div>
            <div class="small text-soft"><?= e((string)($activeBooking['sender_name'] ?? 'Sender')) ?></div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-light" id="close-chat-btn">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="chat-messages" id="chat-messages"></div>

    <div class="chat-footer">
        <form id="chat-form">
            <input type="hidden" id="chat-booking-id" value="<?= (int)$activeBooking['id'] ?>">
            <input type="hidden" id="chat-receiver-id" value="<?= (int)$chatReceiverId ?>">
            <div class="chat-action-row">
                <?php if (!empty($activeBooking['sender_phone'])): ?>
                <a class="btn btn-outline-info flex-fill" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $activeBooking['sender_phone'])) ?>" title="Call the sender's phone number directly">
                    <i class="fa-solid fa-phone me-2"></i>Call Sender
                </a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-success flex-fill" id="chat-call-btn"><i class="fa-solid fa-phone-volume me-2"></i>Internet Call</button>
                <button type="button" class="btn btn-outline-warning flex-fill" id="chat-voice-btn"><i class="fa-solid fa-microphone me-2"></i><span class="voice-btn-label">Record Voice</span></button>
            </div>
            <textarea id="chat-message-input" class="form-control mb-2" placeholder="Type your message..."></textarea>
            <button type="submit" class="btn btn-info w-100 fw-bold">
                <i class="fa-solid fa-paper-plane me-2"></i>Send
            </button>
        </form>
    </div>
</div>
<div class="call-panel" id="call-panel">
    <div class="fw-bold mb-1">Internet Call</div>
    <div class="small text-soft" id="call-status-text">Ready to connect.</div>
    <audio id="remote-audio" autoplay playsinline></audio>
    <div class="call-actions">
        <button type="button" class="btn btn-success flex-fill" id="accept-call-btn" style="display:none"><i class="fa-solid fa-phone me-2"></i>Accept</button>
        <button type="button" class="btn btn-danger flex-fill" id="end-call-btn"><i class="fa-solid fa-phone-slash me-2"></i>End</button>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
<script src="https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js"></script>
<script>
const dashboardRoot = document.getElementById('rider-dashboard-root');
const swipeBtn = document.getElementById('swipe-btn');
const btnWorkflow = document.getElementById('btn_workflow');
const distDisplay = document.getElementById('distance_display');
const etaDisplay = document.getElementById('eta_display');
const syncStatus = document.getElementById('sync_status');
const geoMessage = document.getElementById('geo_message');
const routeDetailsBody = document.getElementById('route_details_body');

const ajaxUpdateLocationUrl = <?= json_encode($ajaxUpdateLocationUrl) ?>;
const ajaxUpdateStatusUrl = <?= json_encode($ajaxUpdateStatusUrl) ?>;
const ajaxWorkflowUrl = <?= json_encode($ajaxWorkflowUrl) ?>;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

let watchId = null;
let lastKnownPosition = null;
let currentStatus = <?= json_encode($currentStatus) ?>;
const bookingId = <?= $activeBooking ? (int)$activeBooking['id'] : 'null' ?>;
let senderHandoverConfirmed = <?= json_encode($senderConfirmedHandover) ?>;

const pickup = {
    lat: <?= $pickupLat !== null ? json_encode($pickupLat) : 'null' ?>,
    lng: <?= $pickupLng !== null ? json_encode($pickupLng) : 'null' ?>
};

const dest = {
    lat: <?= $destLat !== null ? json_encode($destLat) : 'null' ?>,
    lng: <?= $destLng !== null ? json_encode($destLng) : 'null' ?>
};

const initialRider = {
    lat: <?= json_encode($initialLat) ?>,
    lng: <?= json_encode($initialLng) ?>
};

const state = {
    map: null,
    riderMarker: null,
    targetMarker: null,
    routingControl: null,
    latestRouteDistanceMeters: null,
    latestRouteDurationSeconds: null,
    knownPendingIds: <?= json_encode(array_map(fn($r) => (int)$r['id'], $pendingOffers)) ?>,
    snapshotInterval: null,
    chatInterval: null,
    callPollInterval: null,
    peer: null,
    currentCall: null,
    localCallStream: null,
    requestModal: null,
    requestAudio: null,
    audioUnlocked: false
};

const snapshotUrl = dashboardRoot?.dataset.snapshotUrl || '';
const respondUrl = dashboardRoot?.dataset.respondUrl || '';
const chatEnabled = dashboardRoot?.dataset.chatEnabled === '1';
const chatFetchUrl = dashboardRoot?.dataset.chatFetchUrl || '';
const chatSendUrl = dashboardRoot?.dataset.chatSendUrl || '';

function safeAbsoluteUrl(url) {
    try {
        return new URL(url, window.location.origin).toString();
    } catch (e) {
        console.error('Invalid URL:', url, e);
        return '';
    }
}

function escapeHtml(str) {
    return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function requestNotificationPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
    }
}

function unlockAudio() {
    if (state.audioUnlocked) return;
    state.audioUnlocked = true;

    try {
        state.requestAudio = new Audio(safeAbsoluteUrl('assets/sounds/request-alert.wav'));
        state.requestAudio.preload = 'auto';
        state.requestAudio.volume = 1;
        state.requestAudio.muted = true;

        state.requestAudio.play().then(() => {
            state.requestAudio.pause();
            state.requestAudio.currentTime = 0;
            state.requestAudio.muted = false;
        }).catch(() => {});
    } catch (e) {
        console.warn('Audio init failed:', e);
    }
}

document.addEventListener('click', unlockAudio, { once: true });
document.addEventListener('touchstart', unlockAudio, { once: true });
document.addEventListener('keydown', unlockAudio, { once: true });

function playFallbackBeep() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;

        const ctx = new AudioCtx();
        const oscillator = ctx.createOscillator();
        const gainNode = ctx.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, ctx.currentTime);
        oscillator.frequency.setValueAtTime(988, ctx.currentTime + 0.12);

        gainNode.gain.setValueAtTime(0.0001, ctx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + 0.02);
        gainNode.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.45);

        oscillator.connect(gainNode);
        gainNode.connect(ctx.destination);
        oscillator.start();
        oscillator.stop(ctx.currentTime + 0.45);
    } catch (e) {
        console.warn('Fallback beep failed', e);
    }
}

function playNewRequestSound() {
    if (!state.requestAudio) {
        playFallbackBeep();
        return;
    }

    try {
        state.requestAudio.pause();
        state.requestAudio.currentTime = 0;
        state.requestAudio.play().catch(err => {
            console.warn('Uploaded sound failed, using fallback beep:', err);
            playFallbackBeep();
        });
    } catch (e) {
        playFallbackBeep();
    }
}

function showBrowserNotification(title, body) {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;

    try {
        new Notification(title, { body });
    } catch (e) {
        console.log('Browser notification failed', e);
    }
}

function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center text-bg-${type} border-0 mb-2`;
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    const toastBodyId = 'toast-body-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body" id="${toastBodyId}"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;

    container.appendChild(toastEl);
    const bodyEl = document.getElementById(toastBodyId);
    if (bodyEl) {
        bodyEl.textContent = String(message || '');
    }

    const toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3500 });
    toast.show();

    toastEl.addEventListener('hidden.bs.toast', () => {
        toastEl.remove();
    });
}

function getCurrentTarget() {
    if (!bookingId) return null;

    if (currentStatus === 'matched' || currentStatus === 'accepted') {
        return {
            type: 'pickup',
            lat: pickup.lat,
            lng: pickup.lng,
            label: 'Pickup',
            address: <?= json_encode((string)($activeBooking['pickup_address'] ?? '')) ?>
        };
    }

    if (currentStatus === 'arrived_at_pickup' || currentStatus === 'package_received' || currentStatus === 'in_transit') {
        return {
            type: 'delivery',
            lat: dest.lat,
            lng: dest.lng,
            label: 'Destination',
            address: <?= json_encode((string)($activeBooking['delivery_address'] ?? '')) ?>
        };
    }

    return null;
}

function explainGeoError(err) {
    if (!err) return 'Unable to fetch current location.';
    if (err.code === 1) return 'Location permission was denied.';
    if (err.code === 2) return 'Position unavailable. The device could not get a reliable fix.';
    if (err.code === 3) return 'Location request timed out.';
    return 'Unable to fetch current location.';
}

function formatDistance(meters) {
    if (meters === null || meters === undefined) return '--';
    return meters >= 1000 ? (meters / 1000).toFixed(1) + ' km' : Math.round(meters) + ' m';
}

function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return '--';
    const mins = Math.round(seconds / 60);
    if (mins < 60) return mins + ' min';
    const hrs = Math.floor(mins / 60);
    const rem = mins % 60;
    return hrs + 'h ' + rem + 'm';
}

function initMap() {
    const navMap = document.getElementById('nav_map');
    if (!navMap) return;

    state.map = L.map('nav_map', { zoomControl: true }).setView([initialRider.lat, initialRider.lng], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(state.map);

    state.riderMarker = L.marker([initialRider.lat, initialRider.lng], { title: 'Rider' }).addTo(state.map);

    const target = getCurrentTarget();
    if (target && target.lat !== null && target.lng !== null) {
        state.targetMarker = L.marker([target.lat, target.lng], { title: target.label }).addTo(state.map);
        buildRoute(initialRider.lat, initialRider.lng, target.lat, target.lng);
    }

    setTimeout(() => state.map && state.map.invalidateSize(), 250);
}

function clearRoute() {
    if (state.routingControl && state.map) {
        state.map.removeControl(state.routingControl);
        state.routingControl = null;
    }
}

function renderRouteDetails(route, target) {
    if (!route) {
        if (routeDetailsBody) routeDetailsBody.textContent = 'No route details available.';
        return;
    }

    const summary = `
        <div class="mb-2"><strong>Target:</strong> ${escapeHtml(target.label)}</div>
        <div class="mb-2"><strong>Address:</strong> ${escapeHtml(target.address || '-')}</div>
        <div class="mb-2"><strong>Road distance:</strong> ${escapeHtml(formatDistance(route.summary.totalDistance))}</div>
        <div class="mb-3"><strong>Estimated time:</strong> ${escapeHtml(formatDuration(route.summary.totalTime))}</div>
    `;

    const instructions = (route.instructions || []).slice(0, 8).map(step => {
        return `<div class="route-step">${escapeHtml(step.text)} <span class="text-soft">(${escapeHtml(formatDistance(step.distance))})</span></div>`;
    }).join('');

    if (routeDetailsBody) {
        routeDetailsBody.innerHTML = summary + (instructions || '<div>No turn-by-turn instructions.</div>');
    }
}

function updateStatsFromRoute(route) {
    if (!route) return;

    state.latestRouteDistanceMeters = route.summary.totalDistance;
    state.latestRouteDurationSeconds = route.summary.totalTime;

    if (distDisplay) distDisplay.textContent = formatDistance(state.latestRouteDistanceMeters);
    if (etaDisplay) etaDisplay.textContent = formatDuration(state.latestRouteDurationSeconds);

    updateWorkflowButton(state.latestRouteDistanceMeters);
}

function buildRoute(fromLat, fromLng, toLat, toLng) {
    const target = getCurrentTarget();
    if (!target || !state.map) return;

    clearRoute();

    state.routingControl = L.Routing.control({
        waypoints: [
            L.latLng(fromLat, fromLng),
            L.latLng(toLat, toLng)
        ],
        router: L.Routing.osrmv1({
            serviceUrl: 'https://router.project-osrm.org/route/v1'
        }),
        addWaypoints: false,
        draggableWaypoints: false,
        routeWhileDragging: false,
        fitSelectedRoutes: true,
        show: false,
        lineOptions: {
            styles: [{ color: '#38bdf8', opacity: 0.9, weight: 5 }]
        },
        createMarker: function(i, wp) {
            if (i === 0) {
                state.riderMarker = L.marker(wp.latLng, { title: 'Rider' });
                return state.riderMarker;
            } else {
                state.targetMarker = L.marker(wp.latLng, { title: target.label });
                return state.targetMarker;
            }
        }
    }).addTo(state.map);

    state.routingControl.on('routesfound', function(e) {
        const route = e.routes[0];
        updateStatsFromRoute(route);
        renderRouteDetails(route, target);

        const bounds = L.latLngBounds(route.coordinates);
        state.map.fitBounds(bounds, {
            padding: [40, 40],
            animate: true,
            duration: 0.75
        });
    });

    state.routingControl.on('routingerror', function() {
        if (routeDetailsBody) routeDetailsBody.textContent = 'Unable to fetch road route details right now.';
        if (geoMessage) geoMessage.textContent = 'Routing service is temporarily unavailable.';
    });
}

function updateMapAndTargetUI(lat, lng) {
    if (!state.map || !state.riderMarker) return;

    const target = getCurrentTarget();
    if (!target || target.lat === null || target.lng === null) return;

    const currentTargetLabel = document.getElementById('target_label');
    if (currentTargetLabel) currentTargetLabel.textContent = target.label;

    const targetText = document.getElementById('target_address_text');
    if (targetText) targetText.textContent = target.address;

    buildRoute(lat, lng, target.lat, target.lng);
}

function updateWorkflowButton(distance) {
    if (!btnWorkflow || !bookingId) return;

    btnWorkflow.classList.remove('btn-success', 'btn-warning', 'btn-primary', 'btn-secondary', 'btn-danger');

    if (currentStatus === 'matched' || currentStatus === 'accepted') {
        if (distance !== null && distance <= 300) {
            btnWorkflow.disabled = false;
            btnWorkflow.classList.add('btn-success');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-location-crosshairs me-2"></i>I HAVE ARRIVED';
        } else {
            btnWorkflow.disabled = true;
            btnWorkflow.classList.add('btn-secondary');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-route me-2"></i>HEADING TO PICKUP';
        }
    } else if (currentStatus === 'arrived_at_pickup') {
        if (senderHandoverConfirmed) {
            btnWorkflow.disabled = false;
            btnWorkflow.classList.add('btn-warning');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-box-open me-2"></i>CONFIRM PACKAGE RECEIVED';
        } else {
            btnWorkflow.disabled = true;
            btnWorkflow.classList.add('btn-danger');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-handshake-angle me-2"></i>WAITING FOR SENDER CONFIRMATION';
        }
    } else if (currentStatus === 'package_received' || currentStatus === 'in_transit') {
        if (distance !== null && distance <= 300) {
            btnWorkflow.disabled = false;
            btnWorkflow.classList.add('btn-success');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>COMPLETE DELIVERY';
        } else {
            btnWorkflow.disabled = true;
            btnWorkflow.classList.add('btn-secondary');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-truck-fast me-2"></i>HEADING TO DELIVERY';
        }
    } else {
        btnWorkflow.disabled = true;
        btnWorkflow.classList.add('btn-secondary');
        btnWorkflow.innerHTML = 'NO ACTIVE STEP';
    }
}

async function runWorkflowAction() {
    if (!bookingId || !btnWorkflow || btnWorkflow.disabled) return;

    let action = null;

    if (currentStatus === 'matched' || currentStatus === 'accepted') {
        action = 'arrived_at_pickup';
    } else if (currentStatus === 'arrived_at_pickup') {
        if (!senderHandoverConfirmed) {
            if (geoMessage) {
                geoMessage.textContent = 'You cannot mark package received until the sender confirms handover.';
            }
            return;
        }
        action = 'package_received';
    } else if (currentStatus === 'package_received' || currentStatus === 'in_transit') {
        action = 'delivered';
    }

    if (!action) return;

    btnWorkflow.disabled = true;

    try {
        const response = await fetch(safeAbsoluteUrl(ajaxWorkflowUrl), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                booking_id: bookingId,
                action: action,
                csrf_token: CSRF_TOKEN
            })
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Workflow action failed.');
        }

        currentStatus = data.new_status;
        if (geoMessage) geoMessage.textContent = data.message || 'Status updated.';

        const activeBadge = document.getElementById('active-booking-status-badge');
        if (activeBadge) {
            activeBadge.textContent = String(currentStatus || '').replaceAll('_', ' ').toUpperCase();
        }

        if (currentStatus === 'delivered') {
            window.location.reload();
            return;
        }

        const lat = lastKnownPosition ? lastKnownPosition.lat : initialRider.lat;
        const lng = lastKnownPosition ? lastKnownPosition.lng : initialRider.lng;
        updateMapAndTargetUI(lat, lng);
    } catch (err) {
        if (geoMessage) geoMessage.textContent = err.message || 'Action failed.';
        btnWorkflow.disabled = false;
    }
}

async function toggleStatus() {
    if (!swipeBtn) return;

    const isActivating = !swipeBtn.classList.contains('active');

    if (isActivating) {
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            if (geoMessage) geoMessage.textContent = 'GPS may fail because browser location usually requires HTTPS or localhost.';
        }

        if (!navigator.geolocation) {
            if (geoMessage) geoMessage.textContent = 'Geolocation is not supported on this browser.';
            return;
        }

        navigator.geolocation.getCurrentPosition(
            () => {
                swipeBtn.classList.add('active');
                const swipeText = swipeBtn.querySelector('.swipe-text');
                if (swipeText) swipeText.innerText = 'TRACKING ONLINE';
                startTracking();
                updateServerStatus('available');
            },
            (err) => {
                if (geoMessage) geoMessage.textContent = explainGeoError(err);
            },
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
        );
    } else {
        swipeBtn.classList.remove('active');
        const swipeText = swipeBtn.querySelector('.swipe-text');
        if (swipeText) swipeText.innerText = 'SWIPE TO START WORKING';
        stopTracking();
        updateServerStatus('offline');
        if (geoMessage) geoMessage.textContent = 'Tracking stopped.';
    }
}

function startTracking() {
    if (!navigator.geolocation) return;

    if (watchId) {
        navigator.geolocation.clearWatch(watchId);
    }

    if (syncStatus) syncStatus.innerText = 'LIVE';
    if (geoMessage) geoMessage.textContent = 'Tracking started...';

    watchId = navigator.geolocation.watchPosition(
        async (pos) => {
            const { latitude, longitude } = pos.coords;
            lastKnownPosition = { lat: latitude, lng: longitude };

            updateMapAndTargetUI(latitude, longitude);

            try {
                await fetch(safeAbsoluteUrl(ajaxUpdateLocationUrl), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ latitude, longitude, status: 'available', csrf_token: CSRF_TOKEN })
                });
            } catch (e) {
                if (geoMessage) geoMessage.textContent = 'Live location updated on screen, but server sync failed.';
            }
        },
        (err) => {
            if (geoMessage) geoMessage.textContent = explainGeoError(err);
            if (syncStatus) syncStatus.innerText = 'GPS ISSUE';

            const fallbackLat = lastKnownPosition ? lastKnownPosition.lat : initialRider.lat;
            const fallbackLng = lastKnownPosition ? lastKnownPosition.lng : initialRider.lng;
            updateMapAndTargetUI(fallbackLat, fallbackLng);
        },
        { enableHighAccuracy: true, timeout: 12000, maximumAge: 5000 }
    );
}

function stopTracking() {
    if (watchId) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
    if (syncStatus) syncStatus.innerText = 'OFFLINE';
}

async function updateServerStatus(status) {
    try {
        await fetch(safeAbsoluteUrl(ajaxUpdateStatusUrl), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status, csrf_token: CSRF_TOKEN })
        });
    } catch (e) {}
}

async function handleOfferAction(requestId, action, button) {
    const originalHtml = button ? button.innerHTML : '';

    if (button) {
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    }

    try {
        const formData = new FormData();
        formData.append('request_id', String(requestId));
        formData.append('action', action);
        formData.append('csrf_token', CSRF_TOKEN);

        const response = await fetch(safeAbsoluteUrl(respondUrl), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        });

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            throw new Error('Server did not return JSON for this async action.');
        }

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to process request.');
        }

        if (state.requestModal) {
            state.requestModal.hide();
        }

        showToast(result.message || 'Request updated successfully.', 'success');
        await refreshSnapshot();

        if (action === 'accepted') {
            window.location.reload();
        }
    } catch (err) {
        showToast(err.message || 'Unable to process request.', 'danger');
        if (button) {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }
}

function bindOfferForms() {
    document.querySelectorAll('.offer-action-form').forEach(form => {
        if (form.dataset.bound === '1') return;
        form.dataset.bound = '1';

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitter = e.submitter || form.querySelector('button[type="submit"]');
            const requestId = form.querySelector('input[name="request_id"]')?.value;
            const action = submitter?.value || 'accepted';
            if (!requestId) return;
            await handleOfferAction(requestId, action, submitter);
        });
    });
}

function updateSummaryUI(data) {
    if (!data) return;

    const summaries = data.summaries || {};

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    setText('pending-offers-count', data.pending_offers_count ?? 0);
    setText('quick-ongoing-count', summaries.ongoing ?? 0);
    setText('quick-delivered-count', summaries.delivered ?? 0);
    setText('quick-cancelled-count', summaries.cancelled ?? 0);
    setText('quick-paid-overall', '₦' + Number(summaries.paid_overall ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

    setText('top-ongoing-count', summaries.ongoing ?? 0);
    setText('top-delivered-count', summaries.delivered ?? 0);
    setText('top-outstanding-amount', Number(summaries.outstanding ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

    setText('matched-count', summaries.matched ?? 0);
    setText('accepted-count', summaries.accepted ?? 0);
    setText('pickup-count', summaries.pickup ?? 0);
    setText('package-received-count', summaries.package_received ?? 0);
    setText('in-transit-count', summaries.in_transit ?? 0);

    setText('paid-today-value', '₦' + Number(summaries.paid_today ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    setText('paid-week-value', '₦' + Number(summaries.paid_week ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    setText('paid-month-value', '₦' + Number(summaries.paid_month ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    setText('outstanding-value', '₦' + Number(summaries.outstanding ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

    const indicator = document.getElementById('new-request-indicator');
    const pendingCount = data.pending_offers_count ?? 0;
    if (indicator) {
        indicator.textContent = pendingCount;
        indicator.style.display = pendingCount > 0 ? 'inline-flex' : 'none';
    }

    const offersWrap = document.getElementById('offers-list-wrap');
    if (offersWrap && typeof data.offers_html === 'string') {
        offersWrap.innerHTML = data.offers_html;
        bindOfferForms();
    }

    if (data.active_booking && bookingId && data.active_booking.id === Number(bookingId)) {
        currentStatus = data.active_booking.status || currentStatus;
        senderHandoverConfirmed = !!data.active_booking.sender_handover_confirmed;

        const activeBadge = document.getElementById('active-booking-status-badge');
        if (activeBadge && currentStatus) {
            activeBadge.textContent = String(currentStatus).replaceAll('_', ' ').toUpperCase();
        }

        const handoverNotice = document.getElementById('sender_handover_notice');
        if (handoverNotice && currentStatus === 'arrived_at_pickup') {
            handoverNotice.innerHTML = senderHandoverConfirmed
                ? '<i class="fa-solid fa-circle-check text-success me-2"></i>Sender has confirmed package handover. You can now mark package received.'
                : '<i class="fa-solid fa-handshake-angle text-warning me-2"></i>Waiting for sender confirmation before you can select <strong>Received Package</strong>.';
        }

        updateWorkflowButton(state.latestRouteDistanceMeters);
    }
}

function showNewRequestPopup(req) {
    if (!req) return;

    const modalBody = document.getElementById('new-request-modal-body');
    const acceptInput = document.getElementById('modal-request-id-accept');
    const rejectInput = document.getElementById('modal-request-id-reject');

    if (modalBody) {
        modalBody.innerHTML = `
            <div class="mb-2"><strong>Booking:</strong> #${escapeHtml(req.booking_code)}</div>
            <div class="mb-2"><strong>Sender:</strong> ${escapeHtml(req.sender_name)}</div>
            <div class="mb-2"><strong>Item:</strong> ${escapeHtml(req.item_name)}</div>
            <div class="mb-2"><strong>Pickup:</strong> ${escapeHtml(req.pickup_address)}</div>
            <div class="mb-3"><strong>Delivery:</strong> ${escapeHtml(req.delivery_address)}</div>
            <div class="price-tag">₦${Number(req.proposed_cost || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
        `;
    }

    if (acceptInput) acceptInput.value = req.id;
    if (rejectInput) rejectInput.value = req.id;

    const modalEl = document.getElementById('newRequestModal');
    if (modalEl) {
        state.requestModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        state.requestModal.show();
    }
}

async function refreshSnapshot() {
    if (!snapshotUrl) return;

    try {
        const response = await fetch(safeAbsoluteUrl(snapshotUrl), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        });

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            console.warn('Snapshot returned non-JSON response.');
            return;
        }

        const data = await response.json();
        if (!response.ok || !data.success) return;

        const incomingIds = Array.isArray(data.pending_offer_ids) ? data.pending_offer_ids.map(Number) : [];
        const knownIds = Array.isArray(state.knownPendingIds) ? state.knownPendingIds.map(Number) : [];
        const newIds = incomingIds.filter(id => !knownIds.includes(id));

        updateSummaryUI(data);

        if (newIds.length > 0) {
            playNewRequestSound();
            if (data.popup_request) {
                showNewRequestPopup(data.popup_request);
                showBrowserNotification(
                    'New delivery request',
                    `Booking #${data.popup_request.booking_code} · ₦${Number(data.popup_request.proposed_cost || 0).toLocaleString()}`
                );
            }
        }

        state.knownPendingIds = incomingIds;
    } catch (err) {
        console.error('Snapshot refresh failed:', err);
    }
}

function initAsyncRequestUpdates() {
    bindOfferForms();
    requestNotificationPermission();

    if (state.snapshotInterval) {
        clearInterval(state.snapshotInterval);
    }

    refreshSnapshot();
    state.snapshotInterval = setInterval(() => { if (!document.hidden) refreshSnapshot(); }, 12000);
}

function initChat() {
    if (!chatEnabled) return;

    const openChatBtn = document.getElementById('open-chat-btn');
    const closeChatBtn = document.getElementById('close-chat-btn');
    const chatPanel = document.getElementById('chat-panel');
    const chatMessages = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    const chatBookingId = document.getElementById('chat-booking-id')?.value;
    const chatReceiverId = document.getElementById('chat-receiver-id')?.value;
    const chatMessageInput = document.getElementById('chat-message-input');
    const callBtn = document.getElementById('chat-call-btn');
    const voiceBtn = document.getElementById('chat-voice-btn');
    const voiceBtnLabel = document.querySelector('.voice-btn-label');
    const callPanel = document.getElementById('call-panel');
    const callStatusText = document.getElementById('call-status-text');
    const acceptCallBtn = document.getElementById('accept-call-btn');
    const endCallBtn = document.getElementById('end-call-btn');
    const remoteAudio = document.getElementById('remote-audio');
    const realtimeBaseUrl = <?= json_encode(url_path('rider/index.php')) ?>;
    const currentUserId = <?= (int)$user['id'] ?>;

    function buildStatusText(msg) {
        if (!msg.is_me) return '';
        if (msg.read_at_formatted) return `Read ${escapeHtml(msg.read_at_formatted)}`;
        if (msg.delivered_at_formatted) return `Delivered ${escapeHtml(msg.delivered_at_formatted)}`;
        return 'Sent';
    }

    function renderChatContent(rawMessage) {
        const value = String(rawMessage || '');
        if (value.startsWith('[voice-note]')) {
            const audioUrl = value.substring('[voice-note]'.length);
            return `<div class="voice-note-wrap"><i class="fa-solid fa-microphone-lines"></i><audio controls preload="metadata" src="${escapeHtml(audioUrl)}"></audio></div>`;
        }
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    let chatLastMessageId = 0;
    let chatHasRenderedOnce = false;
    let mediaRecorder = null;
    let mediaChunks = [];
    let pendingIncomingCall = null;
    let lastCallSignalHash = '';

    function appendChatMessages(messages, replaceAll) {
        if (!chatMessages) return;

        if (replaceAll) {
            if (!messages || !messages.length) {
                chatMessages.innerHTML = '<div class="text-soft small text-center py-4">No messages yet.</div>';
                chatHasRenderedOnce = true;
                return;
            }
            chatMessages.innerHTML = '';
        }

        const html = (messages || []).map(msg => `
            <div class="chat-bubble ${msg.is_me ? 'me' : 'them'}" data-message-id="${Number(msg.id || 0)}">
                ${renderChatContent(msg.message)}
                <span class="chat-time">${escapeHtml(msg.created_at_formatted || msg.created_at || '')}</span>
                ${msg.is_me ? `<span class="chat-status">${buildStatusText(msg)}</span>` : ''}
            </div>
        `).join('');

        if (replaceAll) {
            chatMessages.innerHTML = html;
        } else if (html) {
            const placeholder = chatMessages.querySelector('.text-soft.small.text-center.py-4');
            if (placeholder) placeholder.remove();
            chatMessages.insertAdjacentHTML('beforeend', html);
        }

        chatMessages.scrollTop = chatMessages.scrollHeight;
        chatHasRenderedOnce = true;
    }

    async function fetchChatMessages(forceFull = false) {
        if (!chatBookingId || !chatFetchUrl || document.hidden) return;

        try {
            const sinceId = forceFull ? 0 : chatLastMessageId;
            const response = await fetch(
                `${safeAbsoluteUrl(chatFetchUrl)}?booking_id=${encodeURIComponent(chatBookingId)}&since_id=${encodeURIComponent(sinceId)}&limit=50`,
                { headers: { 'Accept': 'application/json' }, cache: 'no-store' }
            );

            if (response.status === 304) return;
            const result = await response.json();
            if (response.ok && result.success) {
                const messages = result.messages || [];
                if (forceFull || !chatHasRenderedOnce) {
                    appendChatMessages(messages, true);
                } else if (messages.length) {
                    appendChatMessages(messages, false);
                }
                if (Number(result.last_message_id || 0) > chatLastMessageId) {
                    chatLastMessageId = Number(result.last_message_id || 0);
                } else if (messages.length) {
                    chatLastMessageId = Math.max(chatLastMessageId, ...messages.map(msg => Number(msg.id || 0)));
                }
            }
        } catch (err) {
            console.error('Chat fetch error:', err);
        }
    }

    function peerIdFor(userId) {
        return `booking-${chatBookingId}-user-${userId}`;
    }

    async function ensurePeerReady() {
        if (state.peer || !chatBookingId) return state.peer;
        state.peer = new Peer(peerIdFor(currentUserId));
        state.peer.on('call', function (incomingCall) {
            pendingIncomingCall = incomingCall;
            if (callPanel) callPanel.style.display = 'block';
            if (callStatusText) callStatusText.textContent = 'Incoming internet call…';
            if (acceptCallBtn) acceptCallBtn.style.display = 'block';
        });
        state.peer.on('error', function (err) {
            console.error('Peer error:', err);
            if (callStatusText) callStatusText.textContent = 'Call service unavailable.';
        });
        return state.peer;
    }

    async function ensureLocalAudioStream() {
        if (state.localCallStream) return state.localCallStream;
        state.localCallStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        return state.localCallStream;
    }

    function bindActiveCall(call) {
        if (!call) return;
        state.currentCall = call;
        call.on('stream', function (remoteStream) {
            if (remoteAudio) remoteAudio.srcObject = remoteStream;
            if (callPanel) callPanel.style.display = 'block';
            if (callStatusText) callStatusText.textContent = 'Connected over the internet.';
            if (acceptCallBtn) acceptCallBtn.style.display = 'none';
        });
        call.on('close', function () {
            if (remoteAudio) remoteAudio.srcObject = null;
            if (callStatusText) callStatusText.textContent = 'Call ended.';
            pendingIncomingCall = null;
            state.currentCall = null;
        });
        call.on('error', function () {
            if (callStatusText) callStatusText.textContent = 'Call failed.';
        });
    }

    async function startInternetCall() {
        if (!chatBookingId || !chatReceiverId) return;
        try {
            await ensurePeerReady();
            await fetch(`${realtimeBaseUrl}?action=call_create`, {
                method: 'POST',
                body: new URLSearchParams({ booking_id: chatBookingId, csrf_token: CSRF_TOKEN })
            });
            const localStream = await ensureLocalAudioStream();
            if (callPanel) callPanel.style.display = 'block';
            if (callStatusText) callStatusText.textContent = 'Calling sender over the internet…';
            const call = state.peer.call(peerIdFor(chatReceiverId), localStream);
            bindActiveCall(call);
        } catch (err) {
            console.error(err);
            showToast(err.message || 'Unable to start internet call.', 'danger');
        }
    }

    async function acceptInternetCall() {
        try {
            if (!pendingIncomingCall) return;
            const localStream = await ensureLocalAudioStream();
            pendingIncomingCall.answer(localStream);
            bindActiveCall(pendingIncomingCall);
            await fetch(`${realtimeBaseUrl}?action=call_accept`, {
                method: 'POST',
                body: new URLSearchParams({ booking_id: chatBookingId, csrf_token: CSRF_TOKEN })
            });
            pendingIncomingCall = null;
        } catch (err) {
            console.error(err);
            showToast(err.message || 'Unable to accept call.', 'danger');
        }
    }

    async function endInternetCall() {
        try {
            if (state.currentCall) {
                state.currentCall.close();
            }
            if (state.localCallStream) {
                state.localCallStream.getTracks().forEach(track => track.stop());
                state.localCallStream = null;
            }
            await fetch(`${realtimeBaseUrl}?action=call_end`, {
                method: 'POST',
                body: new URLSearchParams({ booking_id: chatBookingId, csrf_token: CSRF_TOKEN })
            });
        } catch (err) {
            console.error(err);
        } finally {
            if (remoteAudio) remoteAudio.srcObject = null;
            if (callStatusText) callStatusText.textContent = 'Call ended.';
            if (acceptCallBtn) acceptCallBtn.style.display = 'none';
        }
    }

    async function pollCallState() {
        if (!chatBookingId || document.hidden) return;
        try {
            const response = await fetch(`${realtimeBaseUrl}?action=call_poll&booking_id=${encodeURIComponent(chatBookingId)}`, { cache: 'no-store' });
            const result = await response.json();
            if (!result.success) return;
            if (!result.active || !result.call) {
                lastCallSignalHash = '';
                return;
            }
            const signalHash = JSON.stringify(result.call);
            if (signalHash === lastCallSignalHash) return;
            lastCallSignalHash = signalHash;
            const call = result.call || {};
            if (Number(call.to_user_id || 0) === currentUserId && call.status === 'ringing') {
                if (callPanel) callPanel.style.display = 'block';
                if (callStatusText) callStatusText.textContent = 'Incoming internet call…';
                if (acceptCallBtn) acceptCallBtn.style.display = 'block';
            }
        } catch (err) {
            console.error('Call polling failed:', err);
        }
    }

    async function uploadVoiceNote(blob) {
        const formData = new FormData();
        formData.append('booking_id', chatBookingId);
        formData.append('receiver_user_id', chatReceiverId);
        formData.append('voice_note', blob, `voice-${Date.now()}.webm`);
        formData.append('csrf_token', CSRF_TOKEN);
        const response = await fetch(`${realtimeBaseUrl}?action=voice_upload`, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to upload voice note.');
        }
        await fetchChatMessages(true);
    }

    async function toggleVoiceRecording() {
        try {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
                return;
            }
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
            mediaChunks = [];
            mediaRecorder = new MediaRecorder(stream);
            mediaRecorder.ondataavailable = event => {
                if (event.data && event.data.size > 0) mediaChunks.push(event.data);
            };
            mediaRecorder.onstop = async () => {
                stream.getTracks().forEach(track => track.stop());
                const blob = new Blob(mediaChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                if (voiceBtn) voiceBtn.classList.remove('recording-live');
                if (voiceBtnLabel) voiceBtnLabel.textContent = 'Record Voice';
                await uploadVoiceNote(blob);
            };
            mediaRecorder.start();
            if (voiceBtn) voiceBtn.classList.add('recording-live');
            if (voiceBtnLabel) voiceBtnLabel.textContent = 'Stop Recording';
        } catch (err) {
            console.error(err);
            showToast(err.message || 'Unable to record voice note.', 'danger');
        }
    }

    openChatBtn?.addEventListener('click', function () {
        if (!chatPanel) return;
        chatPanel.style.display = 'block';
        fetchChatMessages(true);
        ensurePeerReady();
        pollCallState();
        if (!state.chatInterval) {
            state.chatInterval = setInterval(() => fetchChatMessages(false), 8000);
        }
        if (!state.callPollInterval) {
            state.callPollInterval = setInterval(() => pollCallState(), 4000);
        }
    });

    closeChatBtn?.addEventListener('click', function () {
        if (!chatPanel) return;
        chatPanel.style.display = 'none';
        if (state.chatInterval) {
            clearInterval(state.chatInterval);
            state.chatInterval = null;
        }
    });

    callBtn?.addEventListener('click', startInternetCall);
    acceptCallBtn?.addEventListener('click', acceptInternetCall);
    endCallBtn?.addEventListener('click', endInternetCall);
    voiceBtn?.addEventListener('click', toggleVoiceRecording);

    chatMessageInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            chatForm?.requestSubmit();
        }
    });

    chatForm?.addEventListener('submit', async function (e) {
        e.preventDefault();

        const message = (chatMessageInput?.value || '').trim();
        if (!message) return;

        const submitBtn = chatForm.querySelector('button[type="submit"]');
        const originalHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

        try {
            const response = await fetch(safeAbsoluteUrl(chatSendUrl), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    booking_id: chatBookingId,
                    receiver_user_id: chatReceiverId,
                    message: message,
                    csrf_token: CSRF_TOKEN
                })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to send message.');
            }

            chatMessageInput.value = '';
            await fetchChatMessages(true);
        } catch (err) {
            showToast(err.message || 'Unable to send message.', 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    });
}

function initPage() {
    initMap();
    updateMapAndTargetUI(initialRider.lat, initialRider.lng);
    initAsyncRequestUpdates();
    initChat();

    const acceptModalForm = document.getElementById('new-request-accept-form');
    const rejectModalForm = document.getElementById('new-request-reject-form');

    acceptModalForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const requestId = document.getElementById('modal-request-id-accept')?.value;
        const btn = acceptModalForm.querySelector('button[type="submit"]');
        if (!requestId || !btn) return;
        await handleOfferAction(requestId, 'accepted', btn);
    });

    rejectModalForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const requestId = document.getElementById('modal-request-id-reject')?.value;
        const btn = rejectModalForm.querySelector('button[type="submit"]');
        if (!requestId || !btn) return;
        await handleOfferAction(requestId, 'rejected', btn);
    });

    if (swipeBtn && swipeBtn.classList.contains('active')) {
        startTracking();
    }

    const indicator = document.getElementById('new-request-indicator');
    if (indicator) {
        indicator.style.display = Number(indicator.textContent || 0) > 0 ? 'inline-flex' : 'none';
    }
}

if (btnWorkflow) {
    btnWorkflow.addEventListener('click', runWorkflowAction);
}

initPage();

window.addEventListener('resize', function () {
    if (state.map) {
        state.map.invalidateSize();
    }
});

// ---------------- QUICK SUMMARY: JUMP TO TAB ----------------
document.querySelectorAll('.glance-row').forEach(row => {
    row.addEventListener('click', function () {
        const tabButton = document.querySelector(`#riderDashboardTabs button[data-bs-target="#${this.dataset.gotoTab}"]`);
        if (tabButton && window.bootstrap) {
            window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
        }
    });
});

// ---------------- ASSIGNED ORDERS SEARCH ----------------
document.getElementById('assigned-orders-search')?.addEventListener('input', function () {
    const query = this.value.trim().toLowerCase();
    document.querySelectorAll('#assigned-orders-list > .req-card').forEach(card => {
        const matches = query === '' || card.textContent.toLowerCase().includes(query);
        card.style.display = matches ? '' : 'none';
    });
});
</script>
</body>
</html>