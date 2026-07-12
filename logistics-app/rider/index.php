<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';
require_once __DIR__ . '/../config/push.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

// Riders only ever see their post-cut earnings (85% of what the sender pays), never the
// underlying full price - it's presented to them simply as "the price" for the job.
function sum_amount(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += rider_payout_amount((float)($row['agreed_cost'] ?? $row['proposed_cost'] ?? 0));
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

// Renders every delivered-but-unconfirmed booking as its own card, independent of whatever
// job currently occupies the "active booking" slot - a rider working a new job shouldn't lose
// sight of an older delivery that's still waiting on a payment confirmation from them.
function render_awaiting_confirmation_html(array $bookings): string
{
    if (empty($bookings)) {
        return '';
    }

    ob_start();
    ?>
    <div class="mb-4">
        <?php foreach ($bookings as $b): ?>
            <?php $isPaid = ($b['payment_status'] ?? 'unpaid') === 'paid'; ?>
            <div class="req-card p-3 border-warning mb-3" data-awaiting-booking-id="<?= (int) $b['id'] ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <div>
                        <div class="fw-bold"><?= e($b['booking_code'] ?? '') ?></div>
                        <div class="small text-soft"><?= e($b['sender_name'] ?? '') ?></div>
                    </div>
                    <span class="price-tag">₦<?= number_format(rider_payout_amount((float) ($b['agreed_cost'] ?? 0)), 2) ?></span>
                </div>
                <div class="system-msg mb-2">
                    <?php if (!$isPaid): ?>
                        <i class="fa-solid fa-hourglass-half text-warning me-2"></i><?= e(t('rider.waiting_for_sender_payment', ['amount' => '₦' . number_format(rider_payout_amount((float) ($b['agreed_cost'] ?? 0)), 2)])) ?>
                    <?php else: ?>
                        <i class="fa-solid fa-circle-check text-success me-2"></i><?= e(t('rider.payment_received_notice')) ?>
                    <?php endif; ?>
                </div>
                <button
                    type="button"
                    class="btn <?= $isPaid ? 'btn-success' : 'btn-secondary' ?> w-100 py-2 fw-bold confirm-payment-btn"
                    <?= $isPaid ? '' : 'disabled' ?>
                    data-booking-id="<?= (int) $b['id'] ?>"
                >
                    <i class="fa-solid fa-hand-holding-dollar me-2"></i><?= e(t('rider.confirm_payment_received')) ?>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
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
if (in_array($realtimeAction, ['call_create', 'call_poll', 'call_accept', 'call_end', 'voice_upload', 'presence_ping', 'presence_check'], true)) {
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
        $presenceDir = $assetsRoot . '/realtime_presence';
        realtime_ensure_dir($callsDir);
        realtime_ensure_dir($voiceDir);
        realtime_ensure_dir($presenceDir);

        $callFile = $callsDir . '/booking_' . $bookingIdForRealtime . '.json';
        $currentUserId = (int)($user['id'] ?? 0);
        $counterpartId = (int)($ctx['counterpart_user_id'] ?? 0);

        if (in_array($realtimeAction, ['call_create', 'call_accept', 'call_end', 'voice_upload', 'presence_ping'], true)) {
            require_csrf();
        }

        if ($realtimeAction === 'presence_ping') {
            file_put_contents($presenceDir . '/user_' . $currentUserId . '.json', json_encode(['ts' => time()]));
            respond_json(['success' => true]);
        }

        if ($realtimeAction === 'presence_check') {
            $presenceFile = $presenceDir . '/user_' . $counterpartId . '.json';
            $online = false;
            if (is_file($presenceFile)) {
                $presenceData = json_decode((string)file_get_contents($presenceFile), true) ?: [];
                $online = (time() - (int)($presenceData['ts'] ?? 0)) <= 20;
            }
            respond_json(['success' => true, 'online' => $online]);
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
                'video/webm' => 'webm', // libmagic sometimes tags an audio-only WebM container this way
                'audio/ogg' => 'ogg',
                'audio/mpeg' => 'mp3',
                'audio/mp4' => 'm4a',
                'video/mp4' => 'm4a', // Safari/iOS MediaRecorder output - MP4 ftyp doesn't imply video
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
                b.booking_code,
                b.booking_status,
                b.selected_rider_user_id,
                b.agreed_cost,
                b.payment_status,
                s.full_name AS sender_full_name,
                s.email AS sender_email
            FROM rider_requests rr
            INNER JOIN bookings b ON b.id = rr.booking_id
            INNER JOIN users s ON s.id = b.sender_user_id
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

        if ($action === 'accepted') {
            send_rider_matched_email(
                (string) $requestRow['sender_email'],
                (string) $requestRow['sender_full_name'],
                (string) $user['full_name'],
                (string) $requestRow['booking_code']
            );
            send_web_push($pdo, (int) $requestRow['sender_user_id'], (string) $user['full_name'] . ' accepted your delivery', 'Booking ' . $requestRow['booking_code'] . ' is on its way to pickup.', url_path('bookings/index.php?booking_id=' . (int) $requestRow['booking_id']));
        } else {
            send_web_push($pdo, (int) $requestRow['sender_user_id'], 'Rider declined your request', 'Booking ' . $requestRow['booking_code'] . ' - try another rider from your dashboard.', url_path('bookings/index.php?booking_id=' . (int) $requestRow['booking_id']));
        }

        log_event($pdo, 'booking_' . $action, 'Rider ' . $action . ' offer for booking ' . $requestRow['booking_code'], (int) $user['id'], (string) $user['role'], 'booking', (int) $requestRow['booking_id']);

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

// Delivered bookings still waiting on this rider's payment-received confirmation - kept
// separate from $activeBooking so one never hides the other from view.
$stmt = $pdo->prepare('
    SELECT
        b.*,
        s.full_name AS sender_name,
        s.phone AS sender_phone
    FROM bookings b
    INNER JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ?
      AND b.booking_status = "delivered" AND b.rider_payment_confirmed = 0
    ORDER BY b.id ASC
');
$stmt->execute([$user['id']]);
$awaitingConfirmationBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pickupLat = ($activeBooking && $activeBooking['pickup_latitude'] !== null) ? (float)$activeBooking['pickup_latitude'] : null;
$pickupLng = ($activeBooking && $activeBooking['pickup_longitude'] !== null) ? (float)$activeBooking['pickup_longitude'] : null;
$destLat   = ($activeBooking && $activeBooking['delivery_latitude'] !== null) ? (float)$activeBooking['delivery_latitude'] : null;
$destLng   = ($activeBooking && $activeBooking['delivery_longitude'] !== null) ? (float)$activeBooking['delivery_longitude'] : null;

$bookingAmount = $activeBooking ? rider_payout_amount((float)($activeBooking['agreed_cost'] ?? 0)) : 0;
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
$targetLabel = t('map.delivery_pin');
$targetAddress = '';

if ($activeBooking) {
    if (in_array($currentStatus, ['matched', 'accepted'], true)) {
        $targetLat = $pickupLat;
        $targetLng = $pickupLng;
        $targetLabel = t('map.pickup_pin');
        $targetAddress = (string)($activeBooking['pickup_address'] ?? '');
    } else {
        $targetLat = $destLat;
        $targetLng = $destLng;
        $targetLabel = t('map.delivery_pin');
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
    SELECT availability_status, last_latitude, last_longitude, kyc_status, kyc_note
    FROM rider_profiles
    WHERE user_id = ?
    LIMIT 1
');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

$isOnline = ($profile['availability_status'] ?? 'offline') === 'available';
$kycStatus = $profile['kyc_status'] ?? 'approved';
$kycApproved = $kycStatus === 'approved';
// Fall back to Nigeria's geographic centroid (not any specific city) when a rider has no saved fix yet.
$initialLat = isset($profile['last_latitude']) ? (float)$profile['last_latitude'] : 9.0820;
$initialLng = isset($profile['last_longitude']) ? (float)$profile['last_longitude'] : 8.6753;

$ajaxUpdateLocationUrl = url_path('rider/ajax_update_location.php');
$ajaxUpdateStatusUrl = url_path('rider/ajax_update_status.php');
$ajaxWorkflowUrl = url_path('rider/ajax_workflow_action.php');
$ajaxConfirmPaymentUrl = url_path('rider/ajax_confirm_payment.php');
$logoutUrl = url_path('logout');

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
            'proposed_cost' => rider_payout_amount((float)($firstPending['proposed_cost'] ?? 0)),
        ];
    }

    ob_start();
    ?>
    <?php if (empty($pendingOffers)): ?>
        <div class="text-center py-5 text-soft">
            <i class="fa-solid fa-satellite-dish fa-3x mb-3 opacity-25"></i>
            <p class="mb-0"><?= e(t('rider.scanning_for_orders')) ?></p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($pendingOffers as $req): ?>
                <div class="col-lg-6">
                    <div class="req-card p-3 border-warning h-100">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="price-tag">₦<?= number_format(rider_payout_amount((float)$req['proposed_cost']), 2) ?></span>
                            <span class="small text-soft">#<?= htmlspecialchars($req['booking_code']) ?></span>
                        </div>
                        <div class="small text-soft mb-2"><?= e(t('rider.sender_prefix')) ?> <?= htmlspecialchars($req['sender_name'] ?? 'Unknown') ?></div>
                        <p class="small mb-2"><i class="fa-solid fa-map-pin me-2 text-warning"></i><?= htmlspecialchars($req['pickup_address']) ?></p>
                        <p class="small mb-3"><i class="fa-solid fa-location-dot me-2 text-info"></i><?= htmlspecialchars($req['delivery_address']) ?></p>
                        <form class="offer-action-form d-flex gap-2" method="post" action="#">
                            <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                            <button class="btn btn-success flex-grow-1 fw-bold" type="submit" name="action" value="accepted"><?= e(t('rider.accept_offer')) ?></button>
                            <button class="btn btn-outline-danger" type="submit" name="action" value="rejected"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
    $offersHtml = ob_get_clean();
    $awaitingConfirmationHtml = render_awaiting_confirmation_html($awaitingConfirmationBookings);
    $awaitingConfirmationSignature = sha1(json_encode(array_map(
        fn($b) => [$b['id'], $b['payment_status']],
        $awaitingConfirmationBookings
    )));

    respond_json([
        'success' => true,
        'pending_offers_count' => count($pendingOffers),
        'pending_offer_ids' => $pendingIds,
        'popup_request' => $popupRequest,
        'offers_html' => $offersHtml,
        'awaiting_confirmation_html' => $awaitingConfirmationHtml,
        'awaiting_confirmation_signature' => $awaitingConfirmationSignature,
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
            'pickup_address' => (string)($activeBooking['pickup_address'] ?? ''),
            'pickup_latitude' => $activeBooking['pickup_latitude'] !== null ? (float)$activeBooking['pickup_latitude'] : null,
            'pickup_longitude' => $activeBooking['pickup_longitude'] !== null ? (float)$activeBooking['pickup_longitude'] : null,
            'delivery_address' => (string)($activeBooking['delivery_address'] ?? ''),
            'delivery_latitude' => $activeBooking['delivery_latitude'] !== null ? (float)$activeBooking['delivery_latitude'] : null,
            'delivery_longitude' => $activeBooking['delivery_longitude'] !== null ? (float)$activeBooking['delivery_longitude'] : null,
        ] : null,
    ]);
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <?= vapid_public_key_meta_tag() ?>
    <title><?= e(t('rider.dashboard_heading')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css">
    <style>
        body { background:#eaf5ff; min-height:100vh; color:#0f2c44; font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .navx { background:rgba(255,255,255,.85); border-bottom:1px solid rgba(15,42,68,.10); }
        .cardx { background:rgba(255,255,255,.95); border-radius:1.5rem; border:1px solid rgba(15,42,68,.10); box-shadow:0 10px 40px rgba(0,0,0,0.4); }
        #nav_map { height:260px; width:100%; border-radius:1.25rem; border:1px solid rgba(15,42,68,.12); margin-bottom:1rem; overflow:hidden; }
        #nav_map_wrap.collapsed #nav_map { display:none; }
        #map-toggle-btn i { transition:.2s transform; }
        #nav_map_wrap.collapsed #map-toggle-btn i { transform:rotate(-90deg); }
        #route_details { background:rgba(15,42,68,.06); border:1px solid rgba(15,42,68,.10); border-radius:1rem; padding:14px; color:#0f2c44; font-size:.92rem; margin-bottom:1.5rem; max-height:220px; overflow-y:auto; }
        #route_details .route-title { font-weight:700; margin-bottom:8px; color:#fff; }
        #route_details .route-step { padding:6px 0; border-bottom:1px solid rgba(15,42,68,.08); }
        #route_details .route-step:last-child { border-bottom:none; }
        .stats-bar { background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.2); border-radius:1rem; padding:12px; margin-bottom:1.5rem; }
        .summary-card { background:rgba(15,42,68,.06); border:1px solid rgba(15,42,68,.10); border-radius:1rem; padding:16px; height:100%; }
        .stat-label { font-size:.65rem; color:#5c7a91; text-transform:uppercase; letter-spacing:1px; }
        .stat-value { font-size:1rem; font-weight:800; color:#0f2c44; }
        .money-big { font-size:1.4rem; font-weight:800; color:#0284c7; }
        .online-toggle-row { display:flex; align-items:center; gap:14px; }
        .online-toggle { position:relative; display:inline-block; width:52px; height:30px; flex-shrink:0; }
        .online-toggle input { opacity:0; width:0; height:0; }
        .online-toggle-slider { position:absolute; inset:0; background:#16203a; border:2px solid rgba(15,42,68,.18); border-radius:999px; cursor:pointer; transition:.3s; }
        .online-toggle-slider::before { content:""; position:absolute; width:20px; height:20px; left:3px; top:2px; background:#5c7a91; border-radius:50%; transition:.3s; }
        .online-toggle input:checked + .online-toggle-slider { background:#10b981; border-color:#10b981; }
        .online-toggle input:checked + .online-toggle-slider::before { transform:translateX(22px); background:#fff; }
        #map-toggle-btn { white-space:nowrap; }
        .req-card { background:rgba(15,42,68,.05); border-radius:1rem; border:1px solid rgba(15,42,68,.10); margin-bottom:1rem; }
        .price-tag { color:#0284c7; font-weight:800; font-size:1.1rem; }
        .pulse-btn { animation:pulse-green 2s infinite; border-radius:12px; }
        @keyframes pulse-green { 0% { box-shadow:0 0 0 0 rgba(16,185,129,0.7);} 70% { box-shadow:0 0 0 15px rgba(16,185,129,0);} 100% { box-shadow:0 0 0 0 rgba(16,185,129,0);} }
        .nav-tabs { border:none; gap:8px; }
        .nav-link { color:#5c7a91; border:none !important; border-radius:10px !important; font-weight:600; padding:10px 20px; }
        .nav-link.active { background:#38bdf8 !important; color:#09101d !important; }
        .text-soft { color:#5c7a91; }
        .map-legend { position:absolute; left:12px; bottom:12px; background:rgba(255,255,255,.82); border:1px solid rgba(15,42,68,.10); border-radius:.75rem; padding:.6rem .8rem; color:#0f2c44; font-size:.82rem; z-index:3; }
        .system-msg { background:rgba(15,42,68,.06); border:1px solid rgba(15,42,68,.10); border-radius:1rem; padding:12px 14px; font-size:.9rem; color:#0f2c44; margin-bottom:1rem; }
        .mini-row { border-bottom:1px solid rgba(15,42,68,.08); padding:10px 0; }
        .mini-row:last-child { border-bottom:none; }
        .glance-row { cursor:pointer; transition:.15s ease; border-radius:.5rem; padding-left:8px; padding-right:8px; margin:0 -8px; }
        .glance-row:hover { background:rgba(56,189,248,.08); }
        .order-search-wrap { position:relative; max-width:320px; }
        .order-search-wrap input { padding-left:2.25rem; }
        .order-search-wrap i { position:absolute; left:.8rem; top:50%; transform:translateY(-50%); color:#5c7a91; }
        .pill { display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:999px; background:rgba(15,42,68,.06); border:1px solid rgba(15,42,68,.10); font-size:.85rem; }
        .sticky-chat-btn { position:fixed; right:20px; bottom:20px; z-index:99999; width:60px; height:60px; border-radius:50%; border:none; background:linear-gradient(135deg,#38bdf8,#0ea5e9); color:#09101d; box-shadow:0 12px 24px rgba(0,0,0,.35); font-size:1.25rem; display:flex; align-items:center; justify-content:center; }
        .chat-unread-badge{position:absolute;top:-4px;right:-4px;min-width:22px;height:22px;border-radius:999px;background:#ef4444;color:#fff;font-size:.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;box-shadow:0 0 0 2px #fff}
        .chat-panel { position:fixed; right:20px; bottom:90px; width:380px; max-width:calc(100vw - 24px); height:520px; max-height:72vh; z-index:100000; border-radius:1.25rem; background:rgba(255,255,255,.97); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border:1px solid rgba(15,42,68,.12); box-shadow:0 20px 40px rgba(0,0,0,.35); display:none; flex-direction:column; overflow:hidden; }
        .chat-header { padding:14px 16px; border-bottom:1px solid rgba(15,42,68,.10); display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .chat-header-info{display:flex;align-items:center;gap:10px}
        .chat-avatar{width:38px;height:38px;border-radius:50%;background:rgba(56,189,248,.16);border:1px solid rgba(56,189,248,.3);display:flex;align-items:center;justify-content:center;color:#38bdf8;font-size:1rem;flex-shrink:0}
        .presence-dot{position:absolute;right:-1px;bottom:-1px;width:11px;height:11px;border-radius:50%;background:#9ca3af;border:2px solid #ffffff}
        .presence-dot.online{background:#22c55e}
        .chat-header-actions{display:flex;align-items:center;gap:4px}
        .chat-icon-btn{width:36px;height:36px;border-radius:50%;border:1px solid rgba(15,42,68,.14);background:rgba(15,42,68,.06);color:#0f2c44;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .12s ease,background .15s ease,border-color .15s ease,box-shadow .15s ease;text-decoration:none;cursor:pointer}
        .chat-icon-btn:hover{background:rgba(56,189,248,.16);border-color:rgba(56,189,248,.4);color:#0f2c44;transform:translateY(-1px)}
        .chat-icon-btn:active{transform:scale(.92)}
        .chat-icon-btn.recording-live{background:#ef4444;border-color:#ef4444;color:#fff}
        .chat-messages { flex:1; min-height:0; overflow-y:auto; padding:14px; display:flex; flex-direction:column; gap:2px; background:rgba(0,0,0,.12); }
        .chat-bubble { max-width:78%; padding:8px 12px; border-radius:16px; font-size:.9rem; line-height:1.35; word-wrap:break-word; margin:3px 0; box-shadow:0 1px 2px rgba(0,0,0,.15); }
        .chat-bubble.me { align-self:flex-end; background:linear-gradient(135deg,#38bdf8,#0ea5e9); color:#062334; border-bottom-right-radius:4px; }
        .chat-bubble.them { align-self:flex-start; background:rgba(15,42,68,.10); color:#0f2c44; border-bottom-left-radius:4px; }
        .chat-time { display:block; font-size:.68rem; color:inherit; opacity:.65; margin-top:4px; }
        .chat-status { display:block; font-size:.68rem; color:inherit; opacity:.65; margin-top:2px; text-align:right; }
        .chat-tick{font-size:.72rem}
        .chat-tick-sent,.chat-tick-delivered{color:rgba(6,35,52,.5)}
        .chat-tick-read{color:#0369a1}
        .chat-input-row{display:flex;align-items:flex-end;gap:8px;padding:10px 12px;border-top:1px solid rgba(15,42,68,.10);flex-shrink:0}
        .chat-text-input{flex:1;resize:none;min-height:38px;max-height:100px;border-radius:20px;padding:9px 14px;background:#ffffff;color:#0f2c44;border:1px solid rgba(15,42,68,.12);font-size:.9rem}
        .chat-text-input:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 .15rem rgba(56,189,248,.18)}
        .chat-send-btn{width:38px;height:38px;border-radius:50%;border:none;background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#09101d;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .visually-hidden{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
        .voice-note-wrap{display:flex;align-items:center;gap:8px;min-width:220px;max-width:100%}
        .voice-note-wrap audio{width:220px;max-width:100%}
        .recording-live{box-shadow:0 0 0 0 rgba(248,113,113,.7);animation:recordPulse 1.2s infinite}
        .call-panel{position:fixed;right:20px;bottom:620px;width:380px;max-width:calc(100vw - 24px);z-index:100001;border-radius:1.25rem;background:rgba(255,255,255,.97);backdrop-filter:blur(14px);border:1px solid rgba(15,42,68,.14);box-shadow:0 20px 40px rgba(0,0,0,.35);display:none;padding:20px;text-align:center}
        .call-panel-avatar{width:64px;height:64px;border-radius:50%;background:rgba(56,189,248,.16);border:2px solid rgba(56,189,248,.35);display:flex;align-items:center;justify-content:center;color:#0ea5e9;font-size:1.6rem;margin:0 auto 10px}
        .call-panel .call-actions{display:flex;gap:20px;margin-top:16px;justify-content:center}
        .call-action-btn{width:56px;height:56px;border-radius:50%;border:none;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease;box-shadow:0 8px 20px rgba(0,0,0,.25)}
        .call-action-btn:hover{transform:translateY(-2px) scale(1.06)}
        .call-action-btn:active{transform:scale(.94)}
        .call-accept-btn{background:linear-gradient(135deg,#22c55e,#16a34a)}
        .call-accept-btn.ringing{animation:ringPulse 1.4s infinite}
        .call-end-btn{background:linear-gradient(135deg,#ef4444,#dc2626)}
        @keyframes ringPulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.55)}70%{box-shadow:0 0 0 14px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
        @keyframes recordPulse{0%{box-shadow:0 0 0 0 rgba(248,113,113,.55)}70%{box-shadow:0 0 0 12px rgba(248,113,113,0)}100%{box-shadow:0 0 0 0 rgba(248,113,113,0)}}
        .request-indicator { min-width:22px; height:22px; border-radius:999px; background:#ef4444; color:#fff; font-size:.72rem; font-weight:700; display:none; align-items:center; justify-content:center; padding:0 6px; }
        .toast-container-custom { position:fixed; top:16px; right:16px; z-index:110000; width:min(360px, calc(100vw - 24px)); }
        @media (max-width:576px){ .sticky-chat-btn{right:14px;bottom:14px} .chat-panel{right:0;left:0;bottom:0;width:auto;max-width:100%;height:88vh;max-height:88vh;border-radius:1.25rem 1.25rem 0 0} .call-panel{right:16px;left:16px;width:auto;bottom:16px} }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('')) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('rider/dashboard')) ?>"><i class="fa-solid fa-list-ul me-1"></i><?= e(t('nav.my_deliveries')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/wallet')) ?>"><i class="fa-solid fa-wallet me-1"></i><?= e(t('wallet.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/kyc.php')) ?>"><i class="fa-solid fa-id-card me-1"></i><?= e(t('kyc.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/training.php')) ?>"><i class="fa-solid fa-graduation-cap me-1"></i><?= e(t('training.nav_label')) ?></a>
            <button type="button" id="notif-enable-btn" class="btn btn-sm btn-outline-primary d-none" title="<?= e(t('push.enable_button')) ?>"><i class="fa-solid fa-bell me-1"></i><?= e(t('push.enable_button')) ?></button>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e($logoutUrl) ?>"><i class="fa-solid fa-right-from-bracket me-1"></i><?= e(t('common.logout')) ?></a>
            <div class="small">
                <a href="<?= e(url_path('set_locale?locale=en&redirect=rider/')) ?>" class="<?= current_locale() === 'en' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">EN</a>
                &middot;
                <a href="<?= e(url_path('set_locale?locale=ha&redirect=rider/')) ?>" class="<?= current_locale() === 'ha' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">HA</a>
            </div>
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
            <h1 class="h3 fw-bold mb-1"><?= e(t('rider.dashboard_heading')) ?></h1>
            <p class="text-soft mb-0"><?= e(t('rider.dashboard_subheading')) ?></p>
        </div>
    </div>

    <div id="awaiting-confirmation-wrap"><?= render_awaiting_confirmation_html($awaitingConfirmationBookings) ?></div>

    <?php if (!$activeBooking): ?>
    <div class="cardx p-4 mb-4" id="offers">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h5 fw-bold mb-0"><?= e(t('rider.new_offers')) ?></h2>
            <span class="request-indicator" id="new-request-indicator" style="<?= empty($pendingOffers) ? '' : 'display:inline-flex;' ?>"><?= count($pendingOffers) ?></span>
        </div>
        <div id="offers-list-wrap">
            <?php if (empty($pendingOffers)): ?>
                <div class="text-center py-5 text-soft">
                    <i class="fa-solid fa-satellite-dish fa-3x mb-3 opacity-25"></i>
                    <p class="mb-0"><?= e(t('rider.scanning_for_orders')) ?></p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($pendingOffers as $req): ?>
                        <div class="col-lg-6">
                            <div class="req-card p-3 border-warning h-100">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="price-tag">₦<?= number_format(rider_payout_amount((float)$req['proposed_cost']), 2) ?></span>
                                    <span class="small text-soft">#<?= e($req['booking_code']) ?></span>
                                </div>
                                <div class="small text-soft mb-2"><?= e(t('rider.sender_prefix')) ?> <?= e($req['sender_name'] ?? 'Unknown') ?></div>
                                <p class="small mb-2"><i class="fa-solid fa-map-pin me-2 text-warning"></i><?= e($req['pickup_address']) ?></p>
                                <p class="small mb-3"><i class="fa-solid fa-location-dot me-2 text-info"></i><?= e($req['delivery_address']) ?></p>
                                <form class="offer-action-form d-flex gap-2" method="post" action="#">
                                    <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                    <button class="btn btn-success flex-grow-1 fw-bold" type="submit" name="action" value="accepted"><?= e(t('rider.accept_offer')) ?></button>
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

    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="cardx p-3 p-md-4">
                <?php if ($activeBooking): ?>
                    <div class="stats-bar d-flex justify-content-between align-items-center flex-wrap">
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                            <div class="stat-label"><?= e(t('rider.stat.current_job_value')) ?></div>
                            <div class="stat-value text-info">₦<?= number_format($bookingAmount, 2) ?></div>
                        </div>
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                            <div class="stat-label"><?= e(t('rider.stat.distance')) ?></div>
                            <div class="stat-value" id="distance_display">--</div>
                        </div>
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                            <div class="stat-label"><?= e(t('rider.stat.eta')) ?></div>
                            <div class="stat-value" id="eta_display">--</div>
                        </div>
                        <div class="text-center flex-fill px-2">
                            <div class="stat-label"><?= e(t('rider.stat.system')) ?></div>
                            <div id="sync_status" class="stat-value small text-success"><?= e(t('rider.sync.ready')) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 fw-bold mb-0"><?= e(t('rider.rider_radar')) ?></h2>
                        <span id="sync_status" class="badge bg-dark border border-secondary text-info"><?= e(t('rider.sync.offline')) ?></span>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="map-toggle-btn">
                        <i class="fa-solid fa-chevron-down me-1"></i><span id="map-toggle-label"><?= e(t('booking.show_map')) ?></span>
                    </button>
                </div>

                <div id="nav_map_wrap" class="collapsed">
                    <div id="nav_map">
                        <div class="map-legend">
                            <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#38bdf8;margin-right:6px"></span><?= e(t('map.rider_pin')) ?></div>
                            <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;margin-right:6px"></span><span id="target_label"><?= e($targetLabel) ?></span></div>
                        </div>
                    </div>
                </div>

                <div class="system-msg" id="geo_message">
                    <?php if ($activeBooking): ?>
                        <?= e(t('rider.current_target_prefix')) ?> <strong><?= e($targetLabel) ?></strong> — <?= e($targetAddress) ?>
                    <?php else: ?>
                        <?= e(t('rider.toggle_online_hint')) ?>
                    <?php endif; ?>
                </div>

                <?php if ($kycStatus === 'pending'): ?>
                    <div class="alert alert-warning border-0 mb-3"><?= e(t('rider.kyc_pending_banner')) ?></div>
                <?php elseif ($kycStatus === 'rejected'): ?>
                    <div class="alert alert-danger border-0 mb-3">
                        <?= e(t('rider.kyc_rejected_banner')) ?>
                        <?php if (!empty($profile['kyc_note'])): ?><div class="small mt-1"><?= e($profile['kyc_note']) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="online-toggle-row mb-4">
                    <label class="online-toggle">
                        <input type="checkbox" id="online-toggle-input" <?= $isOnline ? 'checked' : '' ?> <?= $kycApproved ? '' : 'disabled' ?> onchange="toggleStatus()">
                        <span class="online-toggle-slider"></span>
                    </label>
                    <div>
                        <div class="fw-bold" id="online-toggle-label"><?= e($isOnline ? t('rider.you_are_online') : t('rider.you_are_offline')) ?></div>
                        <div class="small text-soft"><?= e($kycApproved ? t('rider.go_online_hint') : t('rider.kyc_required_hint')) ?></div>
                    </div>
                </div>

                <?php if ($activeBooking): ?>
                    <div class="req-card p-3 border-info shadow-sm">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <span class="badge <?= e(badge_class($currentStatus)) ?>" id="active-booking-status-badge"><?= e(mb_strtoupper(booking_status_label((string)$currentStatus))) ?></span>
                            <div class="d-flex gap-2">
                                <a href="tel:<?= e($activeBooking['sender_phone']) ?>" class="btn btn-sm btn-dark border-secondary rounded-pill px-3">
                                    <i class="fa-solid fa-phone"></i>
                                </a>
                                <?php if ($targetLat !== null && $targetLng !== null): ?>
                                    <a href="<?= e($mapLink) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">
                                        <i class="fa-solid fa-diamond-turn-right me-1"></i> <?= e(t('rider.navigate')) ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="small text-soft"><?= e(t('rider.booking_label')) ?></div>
                                <div class="fw-bold"><?= e($activeBooking['booking_code'] ?? '') ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="small text-soft"><?= e(t('rider.sender_label')) ?></div>
                                <div class="fw-bold"><?= e($activeBooking['sender_name'] ?? '') ?></div>
                            </div>
                        </div>

                        <p class="fw-bold mb-1 small text-truncate">
                            <i class="fa-solid fa-location-dot me-2 text-danger"></i>
                            <span id="target_address_text"><?= e($targetAddress) ?></span>
                        </p>
                        <p class="small text-soft mb-3"><?= e(t('rider.item_prefix')) ?> <?= e($activeBooking['item_name'] ?? '') ?></p>

                        <?php if ($currentStatus === 'arrived_at_pickup'): ?>
                            <div class="system-msg mb-3" id="sender_handover_notice">
                                <?php if ($senderConfirmedHandover): ?>
                                    <i class="fa-solid fa-circle-check text-success me-2"></i>
                                    <?= e(t('rider.handover_confirmed_notice')) ?>
                                <?php else: ?>
                                    <i class="fa-solid fa-handshake-angle text-warning me-2"></i>
                                    <?= t('rider.handover_waiting_notice', ['action' => '<strong>' . e(t('rider.received_package_label')) . '</strong>']) ?>
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
                            <?= e(t('rider.checking_location')) ?>
                        </button>
                    </div>
                <?php else: ?>
                    <div class="system-msg mb-0">
                        <i class="fa-solid fa-satellite-dish me-2 text-info"></i>
                        <?= e(t('rider.no_active_delivery')) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<div class="modal fade" id="newRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-white text-dark border-0 shadow-lg">
            <div class="modal-header border-bottom">
                <h5 class="modal-title"><?= e(t('modal.new_delivery_request_title')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="new-request-modal-body">
                <?= e(t('modal.waiting_for_request_details')) ?>
            </div>
            <div class="modal-footer border-top">
                <form id="new-request-reject-form" class="me-2">
                    <input type="hidden" name="request_id" id="modal-request-id-reject">
                    <button class="btn btn-outline-danger" type="submit"><?= e(t('common.reject')) ?></button>
                </form>
                <form id="new-request-accept-form">
                    <input type="hidden" name="request_id" id="modal-request-id-accept">
                    <button class="btn btn-success" type="submit"><?= e(t('common.accept')) ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($canChat): ?>
<button type="button" class="sticky-chat-btn" id="open-chat-btn" title="<?= e(t('chat.open_chat_title')) ?>">
    <i class="fa-solid fa-comments"></i>
    <span class="chat-unread-badge" id="chat-unread-badge" style="display:none">0</span>
</button>

<div class="chat-panel" id="chat-panel">
    <div class="chat-header">
        <div class="chat-header-info">
            <div class="chat-avatar position-relative"><i class="fa-solid fa-user"></i><span class="presence-dot" id="chat-presence-dot"></span></div>
            <div>
                <div class="fw-bold"><?= e((string)($activeBooking['sender_name'] ?? t('chat.default_sender_name'))) ?></div>
                <div class="small text-soft" id="chat-presence-label"><?= e(t('chat.your_sender')) ?></div>
            </div>
        </div>
        <div class="chat-header-actions">
            <?php if (!empty($activeBooking['sender_phone'])): ?>
            <a class="chat-icon-btn" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $activeBooking['sender_phone'])) ?>" title="<?= e(t('chat.call_sender_phone_title')) ?>">
                <i class="fa-solid fa-phone"></i>
            </a>
            <?php endif; ?>
            <button type="button" class="chat-icon-btn" id="chat-call-btn" title="<?= e(t('chat.internet_call_title')) ?>">
                <i class="fa-solid fa-phone-volume"></i>
            </button>
            <button type="button" class="chat-icon-btn" id="close-chat-btn" title="<?= e(t('chat.close_chat_title')) ?>">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>

    <div class="chat-messages" id="chat-messages"></div>

    <form id="chat-form" class="chat-input-row">
        <input type="hidden" id="chat-booking-id" value="<?= (int)$activeBooking['id'] ?>">
        <input type="hidden" id="chat-receiver-id" value="<?= (int)$chatReceiverId ?>">
        <button type="button" class="chat-icon-btn chat-mic-btn" id="chat-voice-btn" title="<?= e(t('chat.record_voice_note')) ?>">
            <i class="fa-solid fa-microphone"></i>
            <span class="voice-btn-label visually-hidden"><?= e(t('chat.record_voice_label')) ?></span>
        </button>
        <textarea id="chat-message-input" class="chat-text-input" placeholder="<?= e(t('chat.message_placeholder')) ?>" rows="1"></textarea>
        <button type="submit" class="chat-send-btn" title="<?= e(t('chat.send_title')) ?>">
            <i class="fa-solid fa-paper-plane"></i>
        </button>
    </form>
</div>
<div class="call-panel" id="call-panel">
    <div class="call-panel-avatar"><i class="fa-solid fa-user"></i></div>
    <div class="fw-bold mb-1"><?= e(t('call.internet_call')) ?></div>
    <div class="small text-soft" id="call-status-text"><?= e(t('call.ready_to_connect')) ?></div>
    <div class="small fw-bold" id="call-timer"></div>
    <audio id="remote-audio" autoplay playsinline></audio>
    <div class="call-actions">
        <button type="button" class="call-action-btn call-accept-btn" id="accept-call-btn" style="display:none" title="<?= e(t('call.accept_call_title')) ?>" aria-label="<?= e(t('call.accept_call_title')) ?>"><i class="fa-solid fa-phone"></i></button>
        <button type="button" class="call-action-btn call-end-btn" id="end-call-btn" title="<?= e(t('call.end_call_title')) ?>" aria-label="<?= e(t('call.end_call_title')) ?>"><i class="fa-solid fa-phone-slash"></i></button>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
<script src="https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js"></script>
<script>
const dashboardRoot = document.getElementById('rider-dashboard-root');
const onlineToggleInput = document.getElementById('online-toggle-input');
const onlineToggleLabel = document.getElementById('online-toggle-label');
const btnWorkflow = document.getElementById('btn_workflow');
const distDisplay = document.getElementById('distance_display');
const etaDisplay = document.getElementById('eta_display');
const syncStatus = document.getElementById('sync_status');
const geoMessage = document.getElementById('geo_message');
const routeDetailsBody = document.getElementById('route_details_body');

const ajaxUpdateLocationUrl = <?= json_encode($ajaxUpdateLocationUrl) ?>;
const ajaxUpdateStatusUrl = <?= json_encode($ajaxUpdateStatusUrl) ?>;
const ajaxWorkflowUrl = <?= json_encode($ajaxWorkflowUrl) ?>;
const ajaxConfirmPaymentUrl = <?= json_encode($ajaxConfirmPaymentUrl) ?>;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

const STATUS_LABELS = <?= json_encode([
    'matched' => t('status.matched'),
    'accepted' => t('status.accepted'),
    'arrived_at_pickup' => t('status.arrived_at_pickup'),
    'package_received' => t('status.package_received'),
    'in_transit' => t('status.in_transit'),
    'delivered' => t('status.delivered'),
    'cancelled' => t('status.cancelled'),
], JSON_UNESCAPED_UNICODE) ?>;

function statusBadgeText(status) {
    const key = String(status || '');
    return (STATUS_LABELS[key] || key.replaceAll('_', ' ')).toUpperCase();
}

const I18N = <?= json_encode([
    'showMap' => t('booking.show_map'),
    'hideMap' => t('booking.hide_map'),
    'noRouteDetails' => t('rider.route.no_details'),
    'routeTargetLabel' => t('rider.route.target_label'),
    'routeAddressLabel' => t('rider.route.address_label'),
    'routeDistanceLabel' => t('rider.route.distance_label'),
    'routeTimeLabel' => t('rider.route.time_label'),
    'noTurnByTurn' => t('rider.route.no_turn_by_turn'),
    'routeFetchError' => t('rider.route.fetch_error'),
    'routeServiceUnavailable' => t('rider.route.service_unavailable'),
    'geoUnableFetch' => t('rider.geo.unable_fetch'),
    'geoPermissionDenied' => t('rider.geo.permission_denied'),
    'geoPositionUnavailable' => t('rider.geo.position_unavailable'),
    'geoTimeout' => t('rider.geo.timeout'),
    'geoHttpsRequired' => t('rider.geo.https_required'),
    'geoNotSupported' => t('rider.geo.not_supported'),
    'geoTrackingStarted' => t('rider.geo.tracking_started'),
    'geoTrackingStopped' => t('rider.geo.tracking_stopped'),
    'geoSyncFailed' => t('rider.geo.sync_failed'),
    'geoCannotMarkReceived' => t('rider.geo.cannot_mark_received'),
    'youAreOnline' => t('rider.you_are_online'),
    'youAreOffline' => t('rider.you_are_offline'),
    'workflowFailed' => t('rider.workflow_failed'),
    'statusUpdated' => t('rider.status_updated'),
    'actionFailed' => t('rider.action_failed'),
    'requestTimedOut' => t('rider.request_timed_out'),
    'syncLive' => t('rider.sync.live'),
    'syncGpsIssue' => t('rider.sync.gps_issue'),
    'syncOffline' => t('rider.sync.offline'),
    'btnIHaveArrived' => t('rider.btn.i_have_arrived'),
    'btnHeadingToPickup' => t('rider.btn.heading_to_pickup'),
    'btnConfirmPackageReceived' => t('rider.btn.confirm_package_received'),
    'btnWaitingForSenderConfirmation' => t('rider.btn.waiting_for_sender_confirmation'),
    'btnCompleteDelivery' => t('rider.btn.complete_delivery'),
    'btnHeadingToDelivery' => t('rider.btn.heading_to_delivery'),
    'btnNoActiveStep' => t('rider.btn.no_active_step'),
    'pickupPin' => t('map.pickup_pin'),
    'deliveryPin' => t('map.delivery_pin'),
    'riderPin' => t('map.rider_pin'),
    'requestFailed' => t('rider.request_failed'),
    'requestUpdated' => t('rider.request_updated'),
    'newRequestNotificationTitle' => t('rider.new_request_notification_title'),
    'handoverConfirmedNotice' => t('rider.handover_confirmed_notice'),
    'handoverWaitingNotice' => t('rider.handover_waiting_notice', ['action' => '<strong>' . t('rider.received_package_label') . '</strong>']),
    'bookingLabel' => t('rider.booking_label'),
    'senderLabel' => t('rider.sender_label'),
    'itemPrefix' => t('rider.item_prefix'),
    'itemLabel' => t('rider.item_label'),
    'pickupLabel' => t('booking.pickup_label'),
    'deliveryLabel' => t('booking.delivery_label'),
    'callIncomingRinging' => t('call.incoming_ringing'),
    'callServiceUnavailable' => t('call.service_unavailable'),
    'callServiceUnavailableRetry' => t('call.service_unavailable_retry'),
    'callNoAnswer' => t('call.no_answer'),
    'callConnected' => t('call.connected'),
    'callConnectionFailed' => t('call.connection_failed'),
    'callEnded' => t('call.ended'),
    'callFailed' => t('call.failed'),
    'callSenderOfflinePhone' => t('call.sender_offline_calling_phone'),
    'callSenderOfflineNoPhone' => t('call.sender_offline_no_phone'),
    'callConnecting' => t('call.connecting'),
    'callRinging' => t('call.ringing'),
    'presenceOnline' => t('chat.presence_online'),
    'presenceOffline' => t('chat.presence_offline'),
    'recordVoice' => t('chat.record_voice_label'),
    'stopRecording' => t('chat.stop_recording'),
    'tickRead' => t('chat.tick_read'),
    'tickDelivered' => t('chat.tick_delivered'),
    'tickSent' => t('chat.tick_sent'),
    'noMessagesYet' => t('chat.no_messages_yet'),
    'voiceUploadFailed' => t('rider.voice_note_upload_failed'),
    'recordVoiceFailed' => t('rider.record_voice_failed'),
    'messageSendFailed' => t('rider.message_send_failed'),
    'sendingEllipsis' => t('rider.sending_ellipsis'),
], JSON_UNESCAPED_UNICODE) ?>;

// STUN alone only works when both sides can find a direct path (same network, lenient
// NAT). Most real phones on mobile data sit behind carrier-grade/symmetric NAT, so a TURN
// relay is required or calls silently fail to carry audio and time out after ICE gives up
// (~15-30s). Free/shared TURN via the Open Relay Project - fine for testing/moderate use;
// swap in dedicated TURN credentials (Twilio, Xirsys, Metered paid tier, self-hosted coturn)
// for production traffic.
const PEER_ICE_CONFIG = {
    iceServers: [
        { urls: 'stun:stun.relay.metered.ca:80' },
        { urls: 'turn:global.relay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turn:global.relay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' },
        { urls: 'turn:global.relay.metered.ca:443?transport=tcp', username: 'openrelayproject', credential: 'openrelayproject' }
    ]
};

let watchId = null;
let lastKnownPosition = null;
let currentStatus = <?= json_encode($currentStatus) ?>;
const bookingId = <?= $activeBooking ? (int)$activeBooking['id'] : 'null' ?>;
let senderHandoverConfirmed = <?= json_encode($senderConfirmedHandover) ?>;
let awaitingConfirmationSignature = <?= json_encode(sha1(json_encode(array_map(
    fn($b) => [$b['id'], $b['payment_status']],
    $awaitingConfirmationBookings
)))) ?>;

const pickup = {
    lat: <?= $pickupLat !== null ? json_encode($pickupLat) : 'null' ?>,
    lng: <?= $pickupLng !== null ? json_encode($pickupLng) : 'null' ?>
};

const dest = {
    lat: <?= $destLat !== null ? json_encode($destLat) : 'null' ?>,
    lng: <?= $destLng !== null ? json_encode($destLng) : 'null' ?>
};

let pickupAddress = <?= json_encode((string)($activeBooking['pickup_address'] ?? '')) ?>;
let deliveryAddress = <?= json_encode((string)($activeBooking['delivery_address'] ?? '')) ?>;

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
    presenceInterval: null,
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

function unlockAudio() {
    if (state.audioUnlocked) return;
    state.audioUnlocked = true;

    try {
        state.requestAudio = new Audio(safeAbsoluteUrl('<?= e(url_path('assets/sounds/request-alert.wav')) ?>'));
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
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
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
            label: I18N.pickupPin,
            address: pickupAddress
        };
    }

    if (currentStatus === 'arrived_at_pickup' || currentStatus === 'package_received' || currentStatus === 'in_transit') {
        return {
            type: 'delivery',
            lat: dest.lat,
            lng: dest.lng,
            label: I18N.deliveryPin,
            address: deliveryAddress
        };
    }

    return null;
}

function explainGeoError(err) {
    if (!err) return I18N.geoUnableFetch;
    if (err.code === 1) return I18N.geoPermissionDenied;
    if (err.code === 2) return I18N.geoPositionUnavailable;
    if (err.code === 3) return I18N.geoTimeout;
    return I18N.geoUnableFetch;
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

    state.riderMarker = L.marker([initialRider.lat, initialRider.lng], { title: I18N.riderPin }).addTo(state.map);

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
        if (routeDetailsBody) routeDetailsBody.textContent = I18N.noRouteDetails;
        return;
    }

    const summary = `
        <div class="mb-2"><strong>${I18N.routeTargetLabel}</strong> ${escapeHtml(target.label)}</div>
        <div class="mb-2"><strong>${I18N.routeAddressLabel}</strong> ${escapeHtml(target.address || '-')}</div>
        <div class="mb-2"><strong>${I18N.routeDistanceLabel}</strong> ${escapeHtml(formatDistance(route.summary.totalDistance))}</div>
        <div class="mb-3"><strong>${I18N.routeTimeLabel}</strong> ${escapeHtml(formatDuration(route.summary.totalTime))}</div>
    `;

    const instructions = (route.instructions || []).slice(0, 8).map(step => {
        return `<div class="route-step">${escapeHtml(step.text)} <span class="text-soft">(${escapeHtml(formatDistance(step.distance))})</span></div>`;
    }).join('');

    if (routeDetailsBody) {
        routeDetailsBody.innerHTML = summary + (instructions || `<div>${I18N.noTurnByTurn}</div>`);
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
                state.riderMarker = L.marker(wp.latLng, { title: I18N.riderPin });
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
        if (routeDetailsBody) routeDetailsBody.textContent = I18N.routeFetchError;
        if (geoMessage) geoMessage.textContent = I18N.routeServiceUnavailable;
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
            btnWorkflow.innerHTML = '<i class="fa-solid fa-location-crosshairs me-2"></i>' + I18N.btnIHaveArrived;
        } else {
            btnWorkflow.disabled = true;
            btnWorkflow.classList.add('btn-secondary');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-route me-2"></i>' + I18N.btnHeadingToPickup;
        }
    } else if (currentStatus === 'arrived_at_pickup') {
        if (senderHandoverConfirmed) {
            btnWorkflow.disabled = false;
            btnWorkflow.classList.add('btn-warning');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-box-open me-2"></i>' + I18N.btnConfirmPackageReceived;
        } else {
            btnWorkflow.disabled = true;
            btnWorkflow.classList.add('btn-danger');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-handshake-angle me-2"></i>' + I18N.btnWaitingForSenderConfirmation;
        }
    } else if (currentStatus === 'package_received' || currentStatus === 'in_transit') {
        if (distance !== null && distance <= 300) {
            btnWorkflow.disabled = false;
            btnWorkflow.classList.add('btn-success');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>' + I18N.btnCompleteDelivery;
        } else {
            btnWorkflow.disabled = true;
            btnWorkflow.classList.add('btn-secondary');
            btnWorkflow.innerHTML = '<i class="fa-solid fa-truck-fast me-2"></i>' + I18N.btnHeadingToDelivery;
        }
    } else {
        btnWorkflow.disabled = true;
        btnWorkflow.classList.add('btn-secondary');
        btnWorkflow.innerHTML = I18N.btnNoActiveStep;
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
                geoMessage.textContent = I18N.geoCannotMarkReceived;
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
            throw new Error(data.message || I18N.workflowFailed);
        }

        currentStatus = data.new_status;
        if (geoMessage) geoMessage.textContent = data.message || I18N.statusUpdated;

        const activeBadge = document.getElementById('active-booking-status-badge');
        if (activeBadge) {
            activeBadge.textContent = statusBadgeText(currentStatus);
        }

        if (currentStatus === 'delivered') {
            window.location.reload();
            return;
        }

        const lat = lastKnownPosition ? lastKnownPosition.lat : initialRider.lat;
        const lng = lastKnownPosition ? lastKnownPosition.lng : initialRider.lng;
        updateMapAndTargetUI(lat, lng);
    } catch (err) {
        if (geoMessage) geoMessage.textContent = err.message || I18N.actionFailed;
        btnWorkflow.disabled = false;
    }
}

async function runConfirmPaymentAction(btn) {
    const targetBookingId = btn.dataset.bookingId;
    if (!targetBookingId || btn.disabled) return;

    btn.disabled = true;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    // Hard cap so the button can never spin forever, regardless of what's slow server-side
    // (e.g. a stalled outbound SMTP handshake) - it always recovers to a retryable state.
    const abortController = new AbortController();
    const abortTimer = setTimeout(() => abortController.abort(), 20000);

    try {
        const response = await fetch(safeAbsoluteUrl(ajaxConfirmPaymentUrl), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ booking_id: targetBookingId, csrf_token: CSRF_TOKEN }),
            signal: abortController.signal
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || I18N.workflowFailed);
        }

        window.location.reload();
    } catch (err) {
        const timedOut = err && err.name === 'AbortError';
        if (geoMessage) geoMessage.textContent = timedOut ? I18N.requestTimedOut : (err.message || I18N.actionFailed);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    } finally {
        clearTimeout(abortTimer);
    }
}

async function toggleStatus() {
    if (!onlineToggleInput) return;

    const isActivating = onlineToggleInput.checked;

    if (isActivating) {
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            if (geoMessage) geoMessage.textContent = I18N.geoHttpsRequired;
        }

        if (!navigator.geolocation) {
            if (geoMessage) geoMessage.textContent = I18N.geoNotSupported;
            onlineToggleInput.checked = false;
            return;
        }

        navigator.geolocation.getCurrentPosition(
            () => {
                if (onlineToggleLabel) onlineToggleLabel.innerText = I18N.youAreOnline;
                startTracking();
                updateServerStatus('available');
            },
            (err) => {
                onlineToggleInput.checked = false;
                if (geoMessage) geoMessage.textContent = explainGeoError(err);
            },
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
        );
    } else {
        if (onlineToggleLabel) onlineToggleLabel.innerText = I18N.youAreOffline;
        stopTracking();
        updateServerStatus('offline');
        if (geoMessage) geoMessage.textContent = I18N.geoTrackingStopped;
    }
}

function startTracking() {
    if (!navigator.geolocation) return;

    if (watchId) {
        navigator.geolocation.clearWatch(watchId);
    }

    if (syncStatus) syncStatus.innerText = I18N.syncLive;
    if (geoMessage) geoMessage.textContent = I18N.geoTrackingStarted;

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
                if (geoMessage) geoMessage.textContent = I18N.geoSyncFailed;
            }
        },
        (err) => {
            if (geoMessage) geoMessage.textContent = explainGeoError(err);
            if (syncStatus) syncStatus.innerText = I18N.syncGpsIssue;

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
    if (syncStatus) syncStatus.innerText = I18N.syncOffline;
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
            throw new Error(result.message || I18N.requestFailed);
        }

        if (state.requestModal) {
            state.requestModal.hide();
        }

        showToast(result.message || I18N.requestUpdated, 'success');
        await refreshSnapshot();

        if (action === 'accepted') {
            window.location.reload();
        }
    } catch (err) {
        showToast(err.message || I18N.requestFailed, 'danger');
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

function applyActiveBookingUpdate(activeBookingData) {
    const incomingId = activeBookingData ? Number(activeBookingData.id) : null;
    const currentId = bookingId === null ? null : Number(bookingId);

    // A booking becoming active/inactive, or switching to a different booking, changes the
    // whole page layout (New Offers vs active-job card, swipe toggle position, etc.) which is
    // rendered server-side - simplest and safest to just reload for those structural changes.
    if (incomingId !== currentId) {
        window.location.reload();
        return;
    }

    if (!activeBookingData) return;

    const pickupChanged = pickup.lat !== activeBookingData.pickup_latitude || pickup.lng !== activeBookingData.pickup_longitude;
    const destChanged = dest.lat !== activeBookingData.delivery_latitude || dest.lng !== activeBookingData.delivery_longitude;
    const statusChanged = currentStatus !== activeBookingData.status;
    const pickupAddressChanged = pickupAddress !== activeBookingData.pickup_address;
    const deliveryAddressChanged = deliveryAddress !== activeBookingData.delivery_address;

    if (!pickupChanged && !destChanged && !statusChanged && !pickupAddressChanged && !deliveryAddressChanged) {
        return;
    }

    pickup.lat = activeBookingData.pickup_latitude;
    pickup.lng = activeBookingData.pickup_longitude;
    dest.lat = activeBookingData.delivery_latitude;
    dest.lng = activeBookingData.delivery_longitude;
    pickupAddress = activeBookingData.pickup_address;
    deliveryAddress = activeBookingData.delivery_address;
    currentStatus = activeBookingData.status;
    senderHandoverConfirmed = !!activeBookingData.sender_handover_confirmed;

    const lat = lastKnownPosition ? lastKnownPosition.lat : initialRider.lat;
    const lng = lastKnownPosition ? lastKnownPosition.lng : initialRider.lng;
    updateMapAndTargetUI(lat, lng);
}

function updateSummaryUI(data) {
    if (!data) return;

    applyActiveBookingUpdate(data.active_booking || null);

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

    const awaitingWrap = document.getElementById('awaiting-confirmation-wrap');
    if (awaitingWrap && typeof data.awaiting_confirmation_html === 'string'
        && data.awaiting_confirmation_signature !== awaitingConfirmationSignature) {
        awaitingConfirmationSignature = data.awaiting_confirmation_signature;
        awaitingWrap.innerHTML = data.awaiting_confirmation_html;
    }

    if (data.active_booking && bookingId && data.active_booking.id === Number(bookingId)) {
        currentStatus = data.active_booking.status || currentStatus;
        senderHandoverConfirmed = !!data.active_booking.sender_handover_confirmed;

        const activeBadge = document.getElementById('active-booking-status-badge');
        if (activeBadge && currentStatus) {
            activeBadge.textContent = statusBadgeText(currentStatus);
        }

        const handoverNotice = document.getElementById('sender_handover_notice');
        if (handoverNotice && currentStatus === 'arrived_at_pickup') {
            handoverNotice.innerHTML = senderHandoverConfirmed
                ? '<i class="fa-solid fa-circle-check text-success me-2"></i>' + I18N.handoverConfirmedNotice
                : '<i class="fa-solid fa-handshake-angle text-warning me-2"></i>' + I18N.handoverWaitingNotice;
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
            <div class="mb-2"><strong>${I18N.bookingLabel}:</strong> #${escapeHtml(req.booking_code)}</div>
            <div class="mb-2"><strong>${I18N.senderLabel}:</strong> ${escapeHtml(req.sender_name)}</div>
            <div class="mb-2"><strong>${I18N.itemLabel}:</strong> ${escapeHtml(req.item_name)}</div>
            <div class="mb-2"><strong>${I18N.pickupPin}:</strong> ${escapeHtml(req.pickup_address)}</div>
            <div class="mb-3"><strong>${I18N.deliveryPin}:</strong> ${escapeHtml(req.delivery_address)}</div>
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
                    I18N.newRequestNotificationTitle,
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

    if (state.snapshotInterval) {
        clearInterval(state.snapshotInterval);
    }

    refreshSnapshot();
    state.snapshotInterval = setInterval(() => { if (!document.hidden) refreshSnapshot(); }, 4000);
}

function initChat() {
    if (!chatEnabled) return;

    const openChatBtn = document.getElementById('open-chat-btn');
    const chatUnreadBadge = document.getElementById('chat-unread-badge');
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
    const callTimerEl = document.getElementById('call-timer');
    const acceptCallBtn = document.getElementById('accept-call-btn');
    const endCallBtn = document.getElementById('end-call-btn');
    const remoteAudio = document.getElementById('remote-audio');
    const presenceDot = document.getElementById('chat-presence-dot');
    const presenceLabel = document.getElementById('chat-presence-label');
    const phoneCallLink = document.querySelector('.chat-header-actions a[href^="tel:"]');
    const realtimeBaseUrl = <?= json_encode(url_path('rider/index.php')) ?>;
    const currentUserId = <?= (int)$user['id'] ?>;
    let counterpartOnline = false;

    function buildStatusTicks(msg) {
        if (!msg.is_me) return '';
        if (msg.read_at_formatted) {
            return `<i class="fa-solid fa-check-double chat-tick chat-tick-read" title="${I18N.tickRead} ${escapeHtml(msg.read_at_formatted)}"></i>`;
        }
        if (msg.delivered_at_formatted) {
            return `<i class="fa-solid fa-check-double chat-tick chat-tick-delivered" title="${I18N.tickDelivered} ${escapeHtml(msg.delivered_at_formatted)}"></i>`;
        }
        return `<i class="fa-solid fa-check chat-tick chat-tick-sent" title="${I18N.tickSent}"></i>`;
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
    let chatUnreadCount = 0;
    let mediaRecorder = null;
    let mediaChunks = [];
    let pendingIncomingCall = null;
    let lastCallSignalHash = '';

    function isChatPanelOpen() {
        return !!chatPanel && chatPanel.style.display !== 'none' && chatPanel.style.display !== '';
    }

    function updateChatUnreadBadge() {
        if (!chatUnreadBadge) return;
        if (chatUnreadCount > 0) {
            chatUnreadBadge.textContent = chatUnreadCount > 99 ? '99+' : String(chatUnreadCount);
            chatUnreadBadge.style.display = 'flex';
        } else {
            chatUnreadBadge.style.display = 'none';
        }
    }

    function playChatNotificationSound() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const oscillator = ctx.createOscillator();
            const gainNode = ctx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(660, ctx.currentTime);
            oscillator.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
            gainNode.gain.setValueAtTime(0.0001, ctx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02);
            gainNode.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4);
            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);
            oscillator.start();
            oscillator.stop(ctx.currentTime + 0.4);
        } catch (e) { /* Web Audio unavailable */ }
    }

    function appendChatMessages(messages, replaceAll) {
        if (!chatMessages) return;

        if (replaceAll) {
            if (!messages || !messages.length) {
                chatMessages.innerHTML = '<div class="text-soft small text-center py-4">' + I18N.noMessagesYet + '</div>';
                chatHasRenderedOnce = true;
                return;
            }
            chatMessages.innerHTML = '';
        }

        const html = (messages || []).map(msg => `
            <div class="chat-bubble ${msg.is_me ? 'me' : 'them'}" data-message-id="${Number(msg.id || 0)}">
                ${renderChatContent(msg.message)}
                <span class="chat-time">${escapeHtml(msg.created_at_formatted || msg.created_at || '')}</span>
                ${msg.is_me ? `<span class="chat-status">${buildStatusTicks(msg)}</span>` : ''}
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

    async function fetchChatMessages(forceFull = false, markRead = true) {
        if (!chatBookingId || !chatFetchUrl || document.hidden) return;

        try {
            const sinceId = forceFull ? 0 : chatLastMessageId;
            const response = await fetch(
                `${safeAbsoluteUrl(chatFetchUrl)}?booking_id=${encodeURIComponent(chatBookingId)}&since_id=${encodeURIComponent(sinceId)}&limit=50&mark_read=${markRead ? 1 : 0}`,
                { headers: { 'Accept': 'application/json' }, cache: 'no-store' }
            );

            if (response.status === 304) return;
            const result = await response.json();
            if (response.ok && result.success) {
                const messages = result.messages || [];

                if (!markRead) {
                    const incoming = messages.filter(msg => !msg.is_me);
                    if (incoming.length) {
                        chatUnreadCount += incoming.length;
                        updateChatUnreadBadge();
                        playChatNotificationSound();
                    }
                } else if (isChatPanelOpen()) {
                    chatUnreadCount = 0;
                    updateChatUnreadBadge();
                }

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

    async function pingPresence() {
        if (!chatBookingId) return;
        try {
            await fetch(`${realtimeBaseUrl}?action=presence_ping`, {
                method: 'POST',
                body: new URLSearchParams({ booking_id: chatBookingId, csrf_token: CSRF_TOKEN })
            });
        } catch (err) { /* ignore */ }
    }

    async function checkPresence() {
        if (!chatBookingId) return;
        try {
            const res = await fetch(`${realtimeBaseUrl}?action=presence_check&booking_id=${encodeURIComponent(chatBookingId)}`, { cache: 'no-store' });
            const result = await res.json();
            counterpartOnline = !!(result.success && result.online);
            if (presenceDot) presenceDot.classList.toggle('online', counterpartOnline);
            if (presenceLabel) presenceLabel.textContent = counterpartOnline ? I18N.presenceOnline : I18N.presenceOffline;
        } catch (err) { /* ignore */ }
    }

    let ringAudioCtx = null;
    let ringInterval = null;

    function startRingback() {
        stopRingback();
        try {
            ringAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const playTone = () => {
                if (!ringAudioCtx) return;
                const osc = ringAudioCtx.createOscillator();
                const gain = ringAudioCtx.createGain();
                osc.frequency.value = 425;
                gain.gain.value = 0.08;
                osc.connect(gain).connect(ringAudioCtx.destination);
                osc.start();
                setTimeout(() => { try { osc.stop(); } catch (e) {} }, 1000);
            };
            playTone();
            ringInterval = setInterval(playTone, 3000);
        } catch (err) { /* Web Audio unavailable */ }
    }

    function stopRingback() {
        if (ringInterval) { clearInterval(ringInterval); ringInterval = null; }
        if (ringAudioCtx) { ringAudioCtx.close().catch(() => {}); ringAudioCtx = null; }
    }

    let callTimerInterval = null;

    function startCallTimer() {
        stopCallTimer();
        const startedAt = Date.now();
        if (callTimerEl) callTimerEl.textContent = '00:00';
        callTimerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - startedAt) / 1000);
            const mm = String(Math.floor(elapsed / 60)).padStart(2, '0');
            const ss = String(elapsed % 60).padStart(2, '0');
            if (callTimerEl) callTimerEl.textContent = `${mm}:${ss}`;
        }, 1000);
    }

    function stopCallTimer() {
        if (callTimerInterval) { clearInterval(callTimerInterval); callTimerInterval = null; }
        if (callTimerEl) callTimerEl.textContent = '';
    }

    function peerIdFor(userId) {
        return `booking-${chatBookingId}-user-${userId}`;
    }

    function ensurePeerReady() {
        if (state.peerReadyPromise) return state.peerReadyPromise;
        if (!chatBookingId) return Promise.resolve(null);
        state.peerReadyPromise = new Promise((resolve) => {
            const peer = new Peer(peerIdFor(currentUserId), { config: PEER_ICE_CONFIG });
            state.peer = peer;
            peer.on('open', function () {
                resolve(peer);
            });
            peer.on('call', function (incomingCall) {
                pendingIncomingCall = incomingCall;
                if (callPanelHideTimer) { clearTimeout(callPanelHideTimer); callPanelHideTimer = null; }
                if (callPanel) callPanel.style.display = 'block';
                if (callStatusText) callStatusText.textContent = I18N.callIncomingRinging;
                if (acceptCallBtn) { acceptCallBtn.style.display = 'block'; acceptCallBtn.classList.add('ringing'); }
                if (endCallBtn) endCallBtn.style.display = '';
                startRingback();
            });
            peer.on('disconnected', function () {
                peer.reconnect();
            });
            peer.on('error', function (err) {
                console.error('Peer error:', err);
                if (callStatusText) callStatusText.textContent = I18N.callServiceUnavailable;
                state.peerReadyPromise = null;
                resolve(null);
            });
        });
        return state.peerReadyPromise;
    }

    async function ensureLocalAudioStream() {
        if (state.localCallStream) return state.localCallStream;
        state.localCallStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        return state.localCallStream;
    }

    let callPanelHideTimer = null;
    let manualHangup = false;
    let redialAttempted = false;

    function finishCallUI(statusMessage) {
        stopRingback();
        stopCallTimer();
        if (remoteAudio) remoteAudio.srcObject = null;
        if (callStatusText) callStatusText.textContent = statusMessage;
        if (acceptCallBtn) { acceptCallBtn.style.display = 'none'; acceptCallBtn.classList.remove('ringing'); }
        if (endCallBtn) endCallBtn.style.display = 'none';
        if (callPanelHideTimer) clearTimeout(callPanelHideTimer);
        callPanelHideTimer = setTimeout(() => {
            if (callPanel) callPanel.style.display = 'none';
            if (endCallBtn) endCallBtn.style.display = '';
        }, 1500);
    }

    // Diagnostic only - logs which candidate pair WebRTC actually picked (host/srflx/relay)
    // so a dropped call can be told apart from a call that never used the TURN relay at all.
    async function logActiveCandidatePair(call, label) {
        try {
            const stats = await call.peerConnection.getStats();
            stats.forEach(report => {
                if (report.type === 'candidate-pair' && report.state === 'succeeded') {
                    const local = stats.get(report.localCandidateId);
                    const remote = stats.get(report.remoteCandidateId);
                    console.log(`Call candidate pair (${label}):`, {
                        local: local && local.candidateType,
                        remote: remote && remote.candidateType,
                        bytesSent: report.bytesSent,
                        bytesReceived: report.bytesReceived
                    });
                }
            });
        } catch (e) { /* getStats unsupported or call already closed */ }
    }

    function bindActiveCall(call, isOutgoing) {
        if (!call) return;
        state.currentCall = call;
        manualHangup = false;
        if (callPanelHideTimer) { clearTimeout(callPanelHideTimer); callPanelHideTimer = null; }
        if (endCallBtn) endCallBtn.style.display = '';
        let connected = false;
        const noAnswerTimer = setTimeout(() => {
            if (!connected && state.currentCall === call) {
                call.close();
                if (callStatusText) callStatusText.textContent = I18N.callNoAnswer;
            }
        }, 30000);
        call.on('stream', function (remoteStream) {
            connected = true;
            // Deliberately not resetting redialAttempted here - caps auto-redial at one
            // attempt per manually-placed call, even if the retry itself later drops too.
            stopRingback();
            clearTimeout(noAnswerTimer);
            if (remoteAudio) remoteAudio.srcObject = remoteStream;
            if (callPanel) callPanel.style.display = 'block';
            if (callStatusText) callStatusText.textContent = I18N.callConnected;
            if (acceptCallBtn) { acceptCallBtn.style.display = 'none'; acceptCallBtn.classList.remove('ringing'); }
            startCallTimer();
            setTimeout(() => logActiveCandidatePair(call, 'connected'), 1000);
        });
        if (call.peerConnection) {
            call.peerConnection.addEventListener('iceconnectionstatechange', function () {
                const iceState = call.peerConnection.iceConnectionState;
                console.log('Call ICE connection state:', iceState);
                if (iceState === 'disconnected' || iceState === 'failed') {
                    logActiveCandidatePair(call, iceState);
                    // Chrome briefly reports "disconnected" during normal renegotiation/NAT
                    // rebinding too - try an ICE restart before assuming the path is dead.
                    if (typeof call.peerConnection.restartIce === 'function') {
                        try { call.peerConnection.restartIce(); } catch (e) { /* not supported mid-call by this browser */ }
                    }
                }
                if (iceState === 'failed' && callStatusText) {
                    callStatusText.textContent = I18N.callConnectionFailed;
                }
            });
        }
        call.on('close', function () {
            clearTimeout(noAnswerTimer);
            pendingIncomingCall = null;
            state.currentCall = null;

            // PeerJS closes the MediaConnection itself once ICE gives up. If audio was
            // already flowing and this side placed the call, silently redial once instead
            // of just hanging up on the user - masks a transient NAT/TURN hiccup.
            if (connected && isOutgoing && !manualHangup && !redialAttempted) {
                redialAttempted = true;
                if (callStatusText) callStatusText.textContent = I18N.callConnecting;
                setTimeout(() => { startInternetCall(true); }, 1200);
                return;
            }

            finishCallUI(callStatusText && callStatusText.textContent === I18N.callNoAnswer ? I18N.callNoAnswer : I18N.callEnded);
        });
        call.on('error', function () {
            clearTimeout(noAnswerTimer);
            finishCallUI(I18N.callFailed);
        });
    }

    async function startInternetCall(isRedial) {
        if (!chatBookingId || !chatReceiverId) return;
        if (isRedial !== true) redialAttempted = false;
        if (!counterpartOnline) {
            if (phoneCallLink) {
                if (callStatusText) callStatusText.textContent = I18N.callSenderOfflinePhone;
                phoneCallLink.click();
            } else if (callStatusText) {
                callStatusText.textContent = I18N.callSenderOfflineNoPhone;
            }
            return;
        }
        try {
            if (callPanel) callPanel.style.display = 'block';
            if (callStatusText) callStatusText.textContent = I18N.callConnecting;
            const peer = await ensurePeerReady();
            if (!peer) {
                if (callStatusText) callStatusText.textContent = I18N.callServiceUnavailableRetry;
                return;
            }
            await fetch(`${realtimeBaseUrl}?action=call_create`, {
                method: 'POST',
                body: new URLSearchParams({ booking_id: chatBookingId, csrf_token: CSRF_TOKEN })
            });
            const localStream = await ensureLocalAudioStream();
            if (callStatusText) callStatusText.textContent = I18N.callRinging;
            startRingback();
            const call = peer.call(peerIdFor(chatReceiverId), localStream);
            bindActiveCall(call, true);
        } catch (err) {
            console.error(err);
            stopRingback();
            if (callStatusText) callStatusText.textContent = err.message || I18N.callFailed;
        }
    }

    async function acceptInternetCall() {
        try {
            if (!pendingIncomingCall) return;
            stopRingback();
            const localStream = await ensureLocalAudioStream();
            pendingIncomingCall.answer(localStream);
            bindActiveCall(pendingIncomingCall, false);
            await fetch(`${realtimeBaseUrl}?action=call_accept`, {
                method: 'POST',
                body: new URLSearchParams({ booking_id: chatBookingId, csrf_token: CSRF_TOKEN })
            });
            pendingIncomingCall = null;
        } catch (err) {
            console.error(err);
            if (callStatusText) callStatusText.textContent = err.message || I18N.callFailed;
        }
    }

    async function endInternetCall() {
        manualHangup = true;
        try {
            if (state.currentCall) {
                state.currentCall.close();
            }
            if (state.localCallStream) {
                state.localCallStream.getTracks().forEach(track => track.stop());
                state.localCallStream = null;
            }
            state.currentCall = null;
            await fetch(`${realtimeBaseUrl}?action=call_end`, {
                method: 'POST',
                body: new URLSearchParams({ booking_id: chatBookingId, csrf_token: CSRF_TOKEN })
            });
        } catch (err) {
            console.error(err);
        } finally {
            finishCallUI(I18N.callEnded);
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
                if (callStatusText) callStatusText.textContent = I18N.callIncomingRinging;
                if (acceptCallBtn) { acceptCallBtn.style.display = 'block'; acceptCallBtn.classList.add('ringing'); }
                startRingback();
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
            throw new Error(result.message || I18N.voiceUploadFailed);
        }
        await fetchChatMessages(true);
    }

    function pickRecorderMimeType() {
        // new MediaRecorder(stream) with no explicit mimeType is unreliable across
        // browsers (notably Safari/iOS, which doesn't support audio/webm at all) - probe
        // for the first type the browser actually supports instead of hoping for a default.
        const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/wav'];
        if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
        return candidates.find(type => MediaRecorder.isTypeSupported(type)) || '';
    }

    async function toggleVoiceRecording() {
        try {
            if (mediaRecorder && mediaRecorder.state === 'recording') {
                mediaRecorder.stop();
                return;
            }
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
            mediaChunks = [];
            const mimeType = pickRecorderMimeType();
            mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
            mediaRecorder.ondataavailable = event => {
                if (event.data && event.data.size > 0) mediaChunks.push(event.data);
            };
            mediaRecorder.onstop = async () => {
                stream.getTracks().forEach(track => track.stop());
                const blob = new Blob(mediaChunks, { type: mediaRecorder.mimeType || mimeType || 'audio/webm' });
                if (voiceBtn) voiceBtn.classList.remove('recording-live');
                if (voiceBtnLabel) voiceBtnLabel.textContent = I18N.recordVoice;
                await uploadVoiceNote(blob);
            };
            mediaRecorder.start();
            if (voiceBtn) voiceBtn.classList.add('recording-live');
            if (voiceBtnLabel) voiceBtnLabel.textContent = I18N.stopRecording;
        } catch (err) {
            console.error(err);
            showToast(err.message || I18N.recordVoiceFailed, 'danger');
        }
    }

    // Presence (and the ability to receive an incoming call) shouldn't depend on the chat
    // panel being open - the counterpart should show online as soon as this page is loaded
    // and logged in, and an incoming call should still ring even with the panel closed.
    // The same goes for new-message notifications: poll for messages continuously so an
    // unread badge + sound can fire even while the panel is closed (without marking those
    // messages read until the recipient actually opens the panel and sees them).
    ensurePeerReady();
    pollCallState();
    pingPresence();
    checkPresence();
    fetchChatMessages(true, isChatPanelOpen());
    if (!state.callPollInterval) {
        state.callPollInterval = setInterval(() => pollCallState(), 4000);
    }
    if (!state.presenceInterval) {
        state.presenceInterval = setInterval(() => { pingPresence(); checkPresence(); }, 8000);
    }
    if (!state.chatInterval) {
        state.chatInterval = setInterval(() => fetchChatMessages(false, isChatPanelOpen()), 8000);
    }

    openChatBtn?.addEventListener('click', function () {
        if (!chatPanel) return;
        chatPanel.style.display = 'flex';
        chatUnreadCount = 0;
        updateChatUnreadBadge();
        fetchChatMessages(true, true);
    });

    closeChatBtn?.addEventListener('click', function () {
        if (!chatPanel) return;
        chatPanel.style.display = 'none';
    });

    callBtn?.addEventListener('click', function () { startInternetCall(false); });
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
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + I18N.sendingEllipsis;

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
                throw new Error(result.message || I18N.messageSendFailed);
            }

            chatMessageInput.value = '';
            await fetchChatMessages(true);
        } catch (err) {
            showToast(err.message || I18N.messageSendFailed, 'danger');
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

    if (onlineToggleInput && onlineToggleInput.checked) {
        startTracking();
    }

    const mapToggleBtn = document.getElementById('map-toggle-btn');
    const mapToggleLabel = document.getElementById('map-toggle-label');
    const navMapWrap = document.getElementById('nav_map_wrap');
    mapToggleBtn?.addEventListener('click', function () {
        const collapsed = navMapWrap.classList.toggle('collapsed');
        if (mapToggleLabel) mapToggleLabel.textContent = collapsed ? I18N.showMap : I18N.hideMap;
        this.querySelector('i')?.classList.toggle('fa-chevron-down', collapsed);
        this.querySelector('i')?.classList.toggle('fa-chevron-up', !collapsed);
        if (!collapsed && state.map) {
            setTimeout(() => state.map.invalidateSize(), 200);
        }
    });

    const indicator = document.getElementById('new-request-indicator');
    if (indicator) {
        indicator.style.display = Number(indicator.textContent || 0) > 0 ? 'inline-flex' : 'none';
    }
}

if (btnWorkflow) {
    btnWorkflow.addEventListener('click', runWorkflowAction);
}

document.body.addEventListener('click', function (e) {
    const btn = e.target.closest('.confirm-payment-btn');
    if (btn) runConfirmPaymentAction(btn);
});

initPage();

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
}

function initPushNotifications() {
    const vapidKey = document.querySelector('meta[name="vapid-public-key"]');
    const btn = document.getElementById('notif-enable-btn');
    if (!vapidKey || !btn || !('serviceWorker' in navigator) || !('PushManager' in window)) return;

    navigator.serviceWorker.register('<?= e(url_path('sw.js')) ?>').then(function (registration) {
        if (Notification.permission === 'granted') {
            subscribeToPush(registration, vapidKey.content);
        } else if (Notification.permission === 'default') {
            btn.classList.remove('d-none');
            btn.addEventListener('click', function () {
                Notification.requestPermission().then(function (permission) {
                    if (permission === 'granted') {
                        subscribeToPush(registration, vapidKey.content);
                        btn.classList.add('d-none');
                    }
                });
            });
        }
    }).catch(function () {});
}

function subscribeToPush(registration, vapidKeyB64) {
    registration.pushManager.getSubscription().then(function (existing) {
        if (existing) return existing;
        return registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidKeyB64),
        });
    }).then(function (subscription) {
        if (!subscription) return;
        fetch('<?= e(url_path('notifications/ajax_save_subscription.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ endpoint: subscription.endpoint, csrf_token: document.querySelector('meta[name="csrf-token"]').content }),
        }).catch(function () {});
    }).catch(function () {});
}

initPushNotifications();

// Each page load registers a PeerJS connection under a deterministic id
// (booking-<id>-user-<id>). Leaving it dangling on the signaling server when the page
// unloads/reloads means the next load's registration can collide with it (rejected as
// "unavailable-id") until the stale one times out - starving calls right when they're needed.
// Destroying it here frees the id immediately instead of waiting on the server's timeout.
window.addEventListener('pagehide', function () {
    if (state.peer && !state.peer.destroyed) {
        state.peer.destroy();
    }
});

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