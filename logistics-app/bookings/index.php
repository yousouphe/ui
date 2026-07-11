<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$errors = [];
$success = flash('success');
$error = flash('error');

$requestedBookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$selectedBookingId = $requestedBookingId;
$forceWizard = isset($_GET['new']) && $_GET['new'] === '1';

// A delivered booking whose rider hasn't yet confirmed receiving payment blocks the sender
// from starting a new order - keeps the sender pointed at the one job that still needs closing.
$stmt = $pdo->prepare("
    SELECT id FROM bookings
    WHERE sender_user_id = ? AND booking_status = 'delivered' AND rider_payment_confirmed = 0
    ORDER BY id DESC LIMIT 1
");
$stmt->execute([$user['id']]);
$blockingBookingRow = $stmt->fetch(PDO::FETCH_ASSOC);
$blockingBookingId = $blockingBookingRow ? (int) $blockingBookingRow['id'] : 0;



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

// ---------------- CREATE BOOKING ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if ($blockingBookingId > 0) {
        flash('error', t('booking.payment_confirmation_pending_block'));
        redirect_to('bookings/index.php?booking_id=' . $blockingBookingId);
    }

    $errors = validate_required([
        'recipient_name'   => 'Recipient name',
        'recipient_phone'  => 'Recipient phone',
        'pickup_address'   => 'Pickup address',
        'delivery_address' => 'Delivery address',
        'item_name'        => 'Item name',
        'item_category'    => 'Item category',
    ], $_POST);

    $saveAsDraft = isset($_POST['save_draft']);

    $trackingToken = bin2hex(random_bytes(16));

    $payload = [
        'sender_user_id'        => $user['id'],
        'booking_code'          => 'BK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
        'recipient_name'        => trim($_POST['recipient_name'] ?? ''),
        'recipient_phone'       => trim($_POST['recipient_phone'] ?? ''),
        'pickup_address'        => trim($_POST['pickup_address'] ?? ''),
        'pickup_latitude'       => ($_POST['pickup_latitude'] ?? '') !== '' ? (float) $_POST['pickup_latitude'] : null,
        'pickup_longitude'      => ($_POST['pickup_longitude'] ?? '') !== '' ? (float) $_POST['pickup_longitude'] : null,
        'delivery_address'      => trim($_POST['delivery_address'] ?? ''),
        'delivery_latitude'     => ($_POST['delivery_latitude'] ?? '') !== '' ? (float) $_POST['delivery_latitude'] : null,
        'delivery_longitude'    => ($_POST['delivery_longitude'] ?? '') !== '' ? (float) $_POST['delivery_longitude'] : null,
        'item_name'             => trim($_POST['item_name'] ?? ''),
        'item_category'         => trim($_POST['item_category'] ?? ''),
        'item_description'      => trim($_POST['item_description'] ?? ''),
        'estimated_value'       => ($_POST['estimated_value'] ?? '') !== '' ? (float) $_POST['estimated_value'] : null,
        'special_instructions'  => trim($_POST['special_instructions'] ?? ''),
        'booking_status'        => $saveAsDraft ? 'draft' : 'submitted',
        'sender_tracking_token' => $trackingToken,
    ];

    if (!$errors) {
        try {
            $payload['item_image_path'] = save_item_image($_FILES['item_image'] ?? []);

            $stmt = $pdo->prepare('
                INSERT INTO bookings (
                    sender_user_id, booking_code, recipient_name, recipient_phone,
                    pickup_address, pickup_latitude, pickup_longitude,
                    delivery_address, delivery_latitude, delivery_longitude,
                    item_name, item_category, item_description, item_image_path,
                    estimated_value, special_instructions, booking_status, sender_tracking_token
                ) VALUES (
                    :sender_user_id, :booking_code, :recipient_name, :recipient_phone,
                    :pickup_address, :pickup_latitude, :pickup_longitude,
                    :delivery_address, :delivery_latitude, :delivery_longitude,
                    :item_name, :item_category, :item_description, :item_image_path,
                    :estimated_value, :special_instructions, :booking_status, :sender_tracking_token
                )
            ');
            $stmt->execute($payload);

            $selectedBookingId = (int) $pdo->lastInsertId();

            if ($saveAsDraft) {
                flash('success', 'Booking saved as draft.');
                redirect_to('bookings/index.php');
            }

            flash('success', 'Booking submitted successfully. Choose a rider below.');
            redirect_to('bookings/index.php?booking_id=' . $selectedBookingId);
        } catch (Throwable $e) {
            $errors['general'] = $e->getMessage();
            $forceWizard = true;
        }
    } else {
        $forceWizard = true;
    }
}

// ---------------- LOAD BOOKINGS ----------------
$senderBookings = load_sender_bookings($pdo, (int) $user['id']);
$allBookings = $senderBookings['all'];
$activeBookings = $senderBookings['active'];
$pendingBookings = $senderBookings['pending'];
$unpaidBookings = $senderBookings['unpaid'];
$cancelledBookings = $senderBookings['cancelled'];
$historyBookings = $senderBookings['history'];

$selectedBooking = null;
if ($selectedBookingId > 0) {
    foreach ($allBookings as $b) {
        if ((int) $b['id'] === $selectedBookingId) {
            $selectedBooking = $b;
            break;
        }
    }
}
if (!$forceWizard) {
    if (!$selectedBooking && !empty($activeBookings)) {
        $selectedBooking = $activeBookings[0];
        $selectedBookingId = (int) $selectedBooking['id'];
    } elseif (!$selectedBooking && !empty($unpaidBookings)) {
        $selectedBooking = $unpaidBookings[0];
        $selectedBookingId = (int) $selectedBooking['id'];
    }
}

// If they explicitly tried to start a new order (or landed with nothing selected) while a
// delivered booking still needs the rider's payment confirmation, point them at that booking
// with an explanation instead of the wizard. Viewing some other specific past booking is fine.
if ($blockingBookingId > 0 && ($forceWizard || !$selectedBooking)) {
    foreach ($allBookings as $b) {
        if ((int) $b['id'] === $blockingBookingId) {
            $selectedBooking = $b;
            $selectedBookingId = $blockingBookingId;
            break;
        }
    }
}

$showWizard = ($forceWizard || !$selectedBooking) && $blockingBookingId === 0;

function haversine_distance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function booking_exists_in_list(array $list, int $bookingId): bool
{
    foreach ($list as $item) {
        if ((int)($item['id'] ?? 0) === $bookingId) {
            return true;
        }
    }
    return false;
}

$selectedDistanceKm = null;
if (
    $selectedBooking &&
    $selectedBooking['pickup_latitude'] !== null &&
    $selectedBooking['pickup_longitude'] !== null &&
    $selectedBooking['delivery_latitude'] !== null &&
    $selectedBooking['delivery_longitude'] !== null
) {
    $selectedDistanceKm = haversine_distance(
        (float) $selectedBooking['pickup_latitude'],
        (float) $selectedBooking['pickup_longitude'],
        (float) $selectedBooking['delivery_latitude'],
        (float) $selectedBooking['delivery_longitude']
    );
}

$canTrack = $selectedBooking && !empty($selectedBooking['selected_rider_user_id']);
$needsRider = $selectedBooking
    && empty($selectedBooking['selected_rider_user_id'])
    && in_array($selectedBooking['booking_status'], ['submitted'], true);
$canPay = $selectedBooking && $selectedBooking['booking_status'] === 'delivered' && ($selectedBooking['payment_status'] ?? 'unpaid') !== 'paid';

$canCancel = $selectedBooking
    && !empty($selectedBooking['selected_rider_user_id'])
    && in_array($selectedBooking['booking_status'], ['matched', 'accepted', 'arrived_at_pickup'], true)
    && (int)($selectedBooking['sender_handover_confirmed'] ?? 0) === 0
    && ($selectedBooking['payment_status'] ?? 'unpaid') !== 'paid';

$canEditDetails = $selectedBooking
    && !in_array($selectedBooking['booking_status'], ['delivered', 'cancelled'], true)
    && (int)($selectedBooking['sender_handover_confirmed'] ?? 0) === 0
    && ($selectedBooking['payment_status'] ?? 'unpaid') !== 'paid';

$canChangeDelivery = $selectedBooking
    && !in_array($selectedBooking['booking_status'], ['delivered', 'cancelled'], true)
    && ($selectedBooking['payment_status'] ?? 'unpaid') !== 'paid';

$canRebook = $selectedBooking
    && ($selectedBooking['booking_status'] ?? '') === 'cancelled'
    && ($selectedBooking['payment_status'] ?? 'unpaid') !== 'paid';

$canIssueItem = $selectedBooking
    && ($selectedBooking['booking_status'] ?? '') === 'arrived_at_pickup'
    && !empty($selectedBooking['selected_rider_user_id'])
    && (int)($selectedBooking['sender_handover_confirmed'] ?? 0) === 0;

$canChat = $selectedBooking && (int)($selectedBooking['selected_rider_user_id'] ?? 0) > 0;
$chatReceiverId = $canChat ? (int)$selectedBooking['selected_rider_user_id'] : 0;
$cancellationReason = trim((string)($selectedBooking['cancellation_reason'] ?? ''));

$isDelivered = $selectedBooking && ($selectedBooking['booking_status'] ?? '') === 'delivered';
$existingRating = null;
if ($isDelivered) {
    $stmt = $pdo->prepare('SELECT rating, review_text FROM booking_ratings WHERE booking_id = ? LIMIT 1');
    $stmt->execute([(int) $selectedBooking['id']]);
    $existingRating = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$selectedBookingIsActive = $selectedBooking ? booking_exists_in_list($activeBookings, (int) $selectedBooking['id']) : false;
$selectedBookingIsUnpaid = $selectedBooking ? booking_exists_in_list($unpaidBookings, (int) $selectedBooking['id']) : false;
$selectedBookingIsHistory = $selectedBooking
    ? (booking_exists_in_list($historyBookings, (int) $selectedBooking['id']) || booking_exists_in_list($cancelledBookings, (int) $selectedBooking['id']))
    : false;

$selectedPickupLat = $selectedBooking['pickup_latitude'] ?? '';
$selectedPickupLng = $selectedBooking['pickup_longitude'] ?? '';
$selectedDeliveryLat = $selectedBooking['delivery_latitude'] ?? '';
$selectedDeliveryLng = $selectedBooking['delivery_longitude'] ?? '';
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('sender.page_title')) ?></title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .form-control,.form-select{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .form-control:focus,.form-select:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
        .leaflet-container{height:100%;width:100%}
        .map-wrap{height:380px;border-radius:1rem;overflow:hidden;border:1px solid rgba(15,42,68,.10)}
        #booking_map{height:380px !important;width:100% !important}
        #detail_map{height:480px;border-radius:1.25rem;border:2px solid rgba(110,168,254,.2)}
        .detail-map-small #detail_map{height:280px}
        #detail_map_wrap.collapsed #detail_map{display:none}
        .rider-card{background:#ffffff;border:1px solid rgba(15,42,68,.10);border-radius:1rem}
        .order-card{cursor:pointer;transition:.2s ease}
        .order-card:hover{transform:translateY(-2px);border-color:rgba(110,168,254,.4)}
        .order-card.active{border-color:#38bdf8;box-shadow:0 0 0 1px rgba(56,189,248,.35)}
        .badge-soft{background:rgba(56,189,248,.12);color:#0369a1;border:1px solid rgba(56,189,248,.3)}
        .info-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:rgba(15,42,68,.06);border:1px solid rgba(15,42,68,.10);font-size:.9rem}
        .sticky-chat-btn{position:fixed;right:20px;bottom:20px;z-index:99999;width:60px;height:60px;border-radius:50%;border:none;background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#09101d;box-shadow:0 12px 24px rgba(0,0,0,.35);font-size:1.25rem;display:flex;align-items:center;justify-content:center}
        .chat-unread-badge{position:absolute;top:-4px;right:-4px;min-width:22px;height:22px;border-radius:999px;background:#ef4444;color:#fff;font-size:.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;box-shadow:0 0 0 2px #fff}
        .chat-panel{position:fixed;right:20px;bottom:90px;width:380px;max-width:calc(100vw - 24px);height:520px;max-height:72vh;z-index:100000;border-radius:1.25rem;background:rgba(255,255,255,.97);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border:1px solid rgba(15,42,68,.12);box-shadow:0 20px 40px rgba(0,0,0,.35);display:none;flex-direction:column;overflow:hidden}
        .chat-header{padding:14px 16px;border-bottom:1px solid rgba(15,42,68,.10);display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
        .chat-header-info{display:flex;align-items:center;gap:10px}
        .chat-avatar{width:38px;height:38px;border-radius:50%;background:rgba(56,189,248,.16);border:1px solid rgba(56,189,248,.3);display:flex;align-items:center;justify-content:center;color:#38bdf8;font-size:1rem;flex-shrink:0}
        .presence-dot{position:absolute;right:-1px;bottom:-1px;width:11px;height:11px;border-radius:50%;background:#9ca3af;border:2px solid #ffffff}
        .presence-dot.online{background:#22c55e}
        .chat-header-actions{display:flex;align-items:center;gap:4px}
        .chat-icon-btn{width:36px;height:36px;border-radius:50%;border:1px solid rgba(15,42,68,.14);background:rgba(15,42,68,.06);color:#0f2c44;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .12s ease,background .15s ease,border-color .15s ease,box-shadow .15s ease;text-decoration:none;cursor:pointer}
        .chat-icon-btn:hover{background:rgba(56,189,248,.16);border-color:rgba(56,189,248,.4);color:#0f2c44;transform:translateY(-1px)}
        .chat-icon-btn:active{transform:scale(.92)}
        .chat-icon-btn.recording-live{background:#ef4444;border-color:#ef4444;color:#fff}
        .chat-messages{flex:1;min-height:0;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:2px;background:rgba(0,0,0,.12)}
        .chat-bubble{max-width:78%;padding:8px 12px;border-radius:16px;font-size:.9rem;line-height:1.35;word-wrap:break-word;margin:3px 0;box-shadow:0 1px 2px rgba(0,0,0,.15)}
        .chat-bubble.me{align-self:flex-end;background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#062334;border-bottom-right-radius:4px}
        .chat-bubble.them{align-self:flex-start;background:rgba(15,42,68,.10);color:#0f2c44;border-bottom-left-radius:4px}
        .chat-time{display:block;font-size:.68rem;color:inherit;opacity:.65;margin-top:4px}
        .chat-status{display:block;font-size:.68rem;color:inherit;opacity:.65;margin-top:2px;text-align:right}
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
        .cancel-reason-box{margin-top:12px;padding:12px;border-radius:12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#991b1b}
        .delivery-feedback-card{padding:14px;border-radius:1rem;background:rgba(56,189,248,.06);border:1px solid rgba(56,189,248,.18)}
        .star-rating-input i,.star-rating-display i{font-size:1.4rem;color:#f59e0b;margin-right:4px;cursor:pointer}
        .star-rating-display i{cursor:default}
        .star-rating-input i.star-empty,.star-rating-display i.star-empty{color:rgba(15,42,68,.2)}
        .action-stack{display:flex;flex-direction:column;gap:10px}
        .address-search{position:relative}
        .address-suggestions{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:1500;background:#ffffff;border:1px solid rgba(15,42,68,.16);border-radius:.75rem;box-shadow:0 12px 30px rgba(0,0,0,.35);max-height:260px;overflow-y:auto;display:none}
        .address-suggestions.show{display:block}
        .address-suggestion-item{padding:.6rem .9rem;cursor:pointer;border-bottom:1px solid rgba(15,42,68,.08);display:flex;gap:.6rem;align-items:flex-start}
        .address-suggestion-item:last-child{border-bottom:none}
        .address-suggestion-item:hover,.address-suggestion-item.active{background:rgba(56,189,248,.14)}
        .address-suggestion-item .main-text{font-weight:600;color:#0f2c44;font-size:.9rem}
        .address-suggestion-item .sub-text{color:#5c7a91;font-size:.78rem}
        .address-suggestion-item i{color:#38bdf8;margin-top:3px}
        .address-suggestion-empty{padding:.75rem .9rem;color:#5c7a91;font-size:.85rem}
        .location-confirmed{border-color:#22c55e!important}
        .wizard-steps{display:flex;align-items:center;gap:8px;margin-bottom:1.75rem}
        .wizard-step-dot{display:flex;align-items:center;gap:8px;color:#5c7a91;font-size:.85rem}
        .wizard-step-dot .num{width:28px;height:28px;border-radius:50%;background:rgba(15,42,68,.08);border:1px solid rgba(15,42,68,.14);display:flex;align-items:center;justify-content:center;font-weight:700;color:#5c7a91}
        .wizard-step-dot.current .num{background:#38bdf8;border-color:#38bdf8;color:#09101d}
        .wizard-step-dot.current{color:#0f2c44}
        .wizard-step-dot.done .num{background:rgba(34,197,94,.2);border-color:#22c55e;color:#22c55e}
        .wizard-step-sep{flex:1;height:1px;background:rgba(15,42,68,.12);min-width:16px;max-width:60px}
        .wizard-pane{display:none}
        .wizard-pane.active{display:block}
        .rider-float-bar{position:fixed;left:0;right:0;bottom:0;z-index:1040;background:rgba(255,255,255,.97);backdrop-filter:blur(10px);border-top:1px solid rgba(56,189,248,.25);box-shadow:0 -12px 30px rgba(0,0,0,.35)}
        .rider-float-header{display:flex;justify-content:space-between;align-items:center;padding:.6rem 1rem;border-bottom:1px solid rgba(15,42,68,.10)}
        .rider-float-list{max-height:180px;overflow-y:auto;padding:.4rem .75rem}
        .vehicle-option-card{display:flex;align-items:center;gap:12px;padding:.75rem;border-radius:.9rem;border:1px solid rgba(15,42,68,.10);background:#ffffff;cursor:pointer;transition:.15s ease;margin-bottom:.5rem}
        .vehicle-option-card:last-child{margin-bottom:0}
        .vehicle-option-card:hover{border-color:#38bdf8;box-shadow:0 6px 16px rgba(56,189,248,.18);transform:translateY(-1px)}
        .vehicle-option-icon{width:44px;height:44px;border-radius:50%;background:rgba(56,189,248,.14);border:1px solid rgba(56,189,248,.3);color:#0ea5e9;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
        .vehicle-option-info{flex:1;min-width:0}
        .vehicle-option-price{font-weight:700;color:#0369a1;white-space:nowrap}
        body.has-rider-float-bar{padding-bottom:220px}
        @media (max-width:576px){
            .sticky-chat-btn{right:14px;bottom:20px}
            .chat-panel{right:0;left:0;bottom:0;width:auto;max-width:100%;height:88vh;max-height:88vh;border-radius:1.25rem 1.25rem 0 0}
            .call-panel{right:16px;left:16px;width:auto;bottom:16px}
            #detail_map{height:260px}
            #booking_map{height:260px !important}
            .map-wrap{height:260px}
            .rider-float-list{max-height:35vh}
            body.has-rider-float-bar{padding-bottom:180px}
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('bookings/')) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('dashboard')) ?>"><i class="fa-solid fa-list-ul me-1"></i><?= e(t('nav.my_orders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('bookings/?new=1')) ?>"><i class="fa-solid fa-plus me-1"></i><?= e(t('nav.new_order')) ?></a>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
            <div class="small">
                <a href="<?= e(url_path('set_locale?locale=en&redirect=bookings/')) ?>" class="<?= current_locale() === 'en' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">EN</a>
                &middot;
                <a href="<?= e(url_path('set_locale?locale=ha&redirect=bookings/')) ?>" class="<?= current_locale() === 'ha' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">HA</a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5">
    <div
        id="sender-workspace-content"
        data-selected-booking-id="<?= (int) $selectedBookingId ?>"
        data-selected-booking-status="<?= e((string) ($selectedBooking['booking_status'] ?? '')) ?>"
        data-selected-payment-status="<?= e((string) ($selectedBooking['payment_status'] ?? 'unpaid')) ?>"
        data-selected-has-rider="<?= !empty($selectedBooking['selected_rider_user_id']) ? '1' : '0' ?>"
        data-needs-rider="<?= $needsRider ? '1' : '0' ?>"
        data-can-track="<?= $canTrack ? '1' : '0' ?>"
        data-can-pay="<?= $canPay ? '1' : '0' ?>"
        data-can-chat="<?= $canChat ? '1' : '0' ?>"
        data-chat-receiver-id="<?= (int)$chatReceiverId ?>"
        data-pickup-lat="<?= e((string) $selectedPickupLat) ?>"
        data-pickup-lng="<?= e((string) $selectedPickupLng) ?>"
        data-delivery-lat="<?= e((string) $selectedDeliveryLat) ?>"
        data-delivery-lng="<?= e((string) $selectedDeliveryLng) ?>"
    >
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if (!empty($errors['general'])): ?><div class="alert alert-danger"><?= e($errors['general']) ?></div><?php endif; ?>
        <?php if ($blockingBookingId > 0): ?>
            <div class="alert alert-warning"><i class="fa-solid fa-circle-info me-2"></i><?= e(t('booking.payment_confirmation_pending_block')) ?></div>
        <?php endif; ?>
        <div id="alert-container"></div>

        <?php if ($showWizard): ?>
        <div class="cardx p-4 p-lg-5 mb-5" id="new-order-panel">
            <div class="wizard-steps">
                <div class="wizard-step-dot current" data-step-dot="1"><span class="num">1</span> <?= e(t('wizard.step1.label')) ?></div>
                <div class="wizard-step-sep"></div>
                <div class="wizard-step-dot" data-step-dot="2"><span class="num">2</span> <?= e(t('wizard.step2.label')) ?></div>
                <div class="wizard-step-sep"></div>
                <div class="wizard-step-dot" data-step-dot="3"><span class="num">3</span> <?= e(t('wizard.step3.label')) ?></div>
            </div>

            <form method="post" enctype="multipart/form-data" id="booking-form">
                <?= csrf_field() ?>

                <div class="wizard-pane active" data-step="1">
                    <h2 class="h4 fw-bold mb-1"><?= e(t('wizard.step1.heading')) ?></h2>
                    <p class="text-soft mb-4"><?= e(t('wizard.step1.subheading')) ?></p>

                    <div class="cardx p-3 mb-4">
                        <h3 class="h5"><?= e(t('wizard.pickup_location')) ?></h3>
                        <label class="form-label"><?= e(t('wizard.pickup_address_label')) ?></label>
                        <div class="address-search">
                            <div class="input-group">
                                <input class="form-control" id="pickup_address" name="pickup_address" value="<?= e(old('pickup_address')) ?>" autocomplete="off" placeholder="<?= e(t('wizard.address_placeholder')) ?>">
                                <button class="btn btn-outline-secondary" type="button" id="use_current_pickup" title="<?= e(t('wizard.use_current_location')) ?>"><i class="fa-solid fa-location-crosshairs"></i></button>
                            </div>
                            <div class="address-suggestions" id="pickup_suggestions"></div>
                        </div>
                        <div class="small mt-2">
                            <a href="#" class="link-info text-decoration-none map-pick-link" data-target="pickup"><i class="fa-solid fa-map-location-dot me-1"></i><?= e(t('wizard.pick_on_map')) ?></a>
                        </div>
                        <input type="hidden" id="pickup_latitude" name="pickup_latitude" value="<?= e(old('pickup_latitude')) ?>">
                        <input type="hidden" id="pickup_longitude" name="pickup_longitude" value="<?= e(old('pickup_longitude')) ?>">
                    </div>

                    <div class="cardx p-3 mb-4">
                        <h3 class="h5"><?= e(t('wizard.destination')) ?></h3>
                        <label class="form-label"><?= e(t('wizard.delivery_address_label')) ?></label>
                        <div class="address-search">
                            <div class="input-group">
                                <input class="form-control" id="delivery_address" name="delivery_address" value="<?= e(old('delivery_address')) ?>" autocomplete="off" placeholder="<?= e(t('wizard.address_placeholder')) ?>">
                                <button class="btn btn-outline-secondary" type="button" id="use_current_delivery" title="<?= e(t('wizard.use_current_location')) ?>"><i class="fa-solid fa-location-crosshairs"></i></button>
                            </div>
                            <div class="address-suggestions" id="delivery_suggestions"></div>
                        </div>
                        <div class="small mt-2">
                            <a href="#" class="link-info text-decoration-none map-pick-link" data-target="delivery"><i class="fa-solid fa-map-location-dot me-1"></i><?= e(t('wizard.pick_on_map')) ?></a>
                        </div>
                        <input type="hidden" id="delivery_latitude" name="delivery_latitude" value="<?= e(old('delivery_latitude')) ?>">
                        <input type="hidden" id="delivery_longitude" name="delivery_longitude" value="<?= e(old('delivery_longitude')) ?>">
                    </div>

                    <div class="small text-danger mb-3 d-none" id="step1-error"><?= e(t('wizard.step1.error')) ?></div>
                    <div class="d-flex justify-content-end">
                        <button class="btn btn-primary" type="button" data-wizard-next="2"><?= e(t('wizard.next')) ?><i class="fa-solid fa-arrow-right ms-2"></i></button>
                    </div>
                </div>

                <div id="route_map_card" style="display:none;">
                    <div class="cardx p-3 mb-4">
                        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                            <h3 class="h5 mb-0"><?= e(t('wizard.route_preview')) ?></h3>
                            <span class="badge text-bg-warning" id="map_mode_label" style="display:none;"><?= e(t('wizard.mode_none')) ?></span>
                            <span class="small text-soft" id="route_summary"></span>
                        </div>
                        <div id="booking_map" class="map-wrap"></div>
                    </div>
                </div>

                <div class="wizard-pane" data-step="2">
                    <h2 class="h4 fw-bold mb-1"><?= e(t('wizard.step2.heading')) ?></h2>
                    <p class="text-soft mb-4"><?= e(t('wizard.step2.subheading')) ?></p>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label"><?= e(t('wizard.recipient_name_label')) ?></label>
                            <input class="form-control" name="recipient_name" value="<?= e(old('recipient_name')) ?>">
                            <?php if (!empty($errors['recipient_name'])): ?><div class="small text-danger mt-1"><?= e($errors['recipient_name']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(t('wizard.recipient_phone_label')) ?></label>
                            <input class="form-control" name="recipient_phone" value="<?= e(old('recipient_phone')) ?>">
                            <?php if (!empty($errors['recipient_phone'])): ?><div class="small text-danger mt-1"><?= e($errors['recipient_phone']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(t('wizard.item_name_label')) ?></label>
                            <input class="form-control" name="item_name" value="<?= e(old('item_name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(t('wizard.item_category_label')) ?></label>
                            <?php $selectedCategory = old('item_category'); ?>
                            <select class="form-select" name="item_category">
                                <option value=""><?= e(t('wizard.select_category')) ?></option>
                                <option value="document" <?= $selectedCategory === 'document' ? 'selected' : '' ?>><?= e(t('wizard.category.document')) ?></option>
                                <option value="food" <?= $selectedCategory === 'food' ? 'selected' : '' ?>><?= e(t('wizard.category.food')) ?></option>
                                <option value="parcel" <?= $selectedCategory === 'parcel' ? 'selected' : '' ?>><?= e(t('wizard.category.parcel')) ?></option>
                                <option value="fragile" <?= $selectedCategory === 'fragile' ? 'selected' : '' ?>><?= e(t('wizard.category.fragile')) ?></option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= e(t('wizard.item_description_label')) ?></label>
                            <textarea class="form-control" name="item_description" rows="3"><?= e(old('item_description')) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(t('wizard.estimated_value_label')) ?></label>
                            <input class="form-control" name="estimated_value" value="<?= e(old('estimated_value')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= e(t('wizard.special_instructions_label')) ?></label>
                            <textarea class="form-control" name="special_instructions" rows="2"><?= e(old('special_instructions')) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <button class="btn btn-outline-secondary" type="button" data-wizard-back="1"><i class="fa-solid fa-arrow-left me-2"></i><?= e(t('wizard.back')) ?></button>
                        <button class="btn btn-primary" type="button" data-wizard-next="3"><?= e(t('wizard.next')) ?><i class="fa-solid fa-arrow-right ms-2"></i></button>
                    </div>
                </div>

                <div class="wizard-pane" data-step="3">
                    <h2 class="h4 fw-bold mb-1"><?= e(t('wizard.step3.heading')) ?></h2>
                    <p class="text-soft mb-4"><?= e(t('wizard.step3.subheading')) ?></p>

                    <label class="form-label"><?= e(t('wizard.item_image_label')) ?></label>
                    <input class="form-control" type="file" name="item_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <?php if (!empty($errors['item_image'])): ?><div class="small text-danger mt-1"><?= e($errors['item_image']) ?></div><?php endif; ?>

                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mt-4">
                        <button class="btn btn-outline-secondary" type="button" data-wizard-back="2"><i class="fa-solid fa-arrow-left me-2"></i><?= e(t('wizard.back')) ?></button>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary flex-fill" type="submit" name="save_draft"><?= e(t('wizard.save_draft')) ?></button>
                            <button class="btn btn-primary flex-fill" type="submit" name="submit_booking"><?= e(t('wizard.send_package')) ?></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!$showWizard && $selectedBooking): ?>
            <?php if ($needsRider): ?>
                <div class="cardx p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h2 class="h4 fw-bold mb-1"><?= e(t('booking.sending_from')) ?> <?= e($selectedBooking['pickup_address']) ?></h2>
                            <p class="text-soft mb-0"><?= e(t('dashboard.to_prefix')) ?> <?= e($selectedBooking['delivery_address']) ?></p>
                        </div>
                        <span class="text-soft small"><?= e($selectedBooking['booking_code']) ?></span>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-2 action-stack-inline">
                        <?php if ($canEditDetails): ?>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#editDetailsModal">
                                <i class="fa-solid fa-pen me-2"></i><?= e(t('booking.edit_details')) ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($canChangeDelivery): ?>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#changeDeliveryModal">
                                <i class="fa-solid fa-location-dot me-2"></i><?= e(t('booking.change_delivery_address')) ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($canCancel): ?>
                            <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#cancelBookingModal">
                                <i class="fa-solid fa-ban me-2"></i><?= e(t('booking.cancel_order')) ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
            <div class="cardx p-4 mb-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                    <div>
                        <h2 class="h4 fw-bold mb-1"><?= e($selectedBooking['booking_code']) ?></h2>
                        <p class="text-soft mb-0"><?= e($selectedBooking['item_name']) ?> &middot; <?= e($selectedBooking['item_category']) ?></p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="info-pill"><i class="fa-solid fa-circle-info text-info"></i> <span id="booking_status_text"><?= e(booking_status_label((string) $selectedBooking['booking_status'])) ?></span></span>
                        <span class="info-pill"><i class="fa-solid fa-naira-sign text-warning"></i> &#8358;<?= number_format((float) ($selectedBooking['agreed_cost'] ?? 0), 2) ?></span>
                        <span class="info-pill"><i class="fa-solid fa-wallet text-success"></i> <span id="payment_status_text"><?= e(booking_status_label((string) ($selectedBooking['payment_status'] ?? 'unpaid'))) ?></span></span>
                        <span class="info-pill"><i class="fa-regular fa-clock text-info"></i> <span id="eta_text">--</span></span>
                    </div>
                </div>

                <hr class="my-3">

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="small text-soft mb-2"><strong><?= e(t('booking.recipient_label')) ?></strong> <?= e($selectedBooking['recipient_name']) ?> &middot; <?= e($selectedBooking['recipient_phone']) ?></div>
                        <div class="small text-soft mb-2"><strong><?= e(t('booking.pickup_label')) ?></strong> <?= e($selectedBooking['pickup_address']) ?></div>
                        <div class="small text-soft mb-2"><strong><?= e(t('booking.delivery_label')) ?></strong> <?= e($selectedBooking['delivery_address']) ?></div>
                        <?php if (trim((string) ($selectedBooking['item_description'] ?? '')) !== ''): ?>
                            <div class="small text-soft mb-2"><strong><?= e(t('booking.package_label')) ?></strong> <?= e($selectedBooking['item_description']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($selectedBooking['estimated_value'])): ?>
                            <div class="small text-soft mb-2"><strong><?= e(t('booking.est_value_label')) ?></strong> &#8358;<?= number_format((float) $selectedBooking['estimated_value'], 2) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-soft mb-2"><strong><?= e(t('booking.distance_label')) ?></strong> <?= $selectedDistanceKm !== null ? number_format($selectedDistanceKm, 2) . ' km' : '--' ?></div>
                        <div class="small text-soft mb-2"><strong><?= e(t('booking.rider_label')) ?></strong> <?= e((string) ($selectedBooking['rider_name'] ?? t('booking.not_assigned_yet'))) ?></div>
                        <div class="small text-soft mb-2"><strong><?= e(t('booking.rider_phone_label')) ?></strong> <?= e((string) ($selectedBooking['rider_phone'] ?? '--')) ?></div>
                        <?php if (trim((string) ($selectedBooking['special_instructions'] ?? '')) !== ''): ?>
                            <div class="small text-soft mb-2"><strong><?= e(t('booking.instructions_label')) ?></strong> <?= e($selectedBooking['special_instructions']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($selectedBooking['item_image_path'])): ?>
                    <div class="mt-2">
                        <img src="<?= e(url_path($selectedBooking['item_image_path'])) ?>" class="img-fluid rounded" style="max-height:160px" alt="<?= e(t('booking.package_photo_alt')) ?>">
                    </div>
                <?php endif; ?>

                <div class="mt-3 d-flex justify-content-between flex-wrap gap-2">
                    <?php if ($canTrack && !empty($selectedBooking['sender_tracking_token'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-info" id="share-tracking-btn" data-tracking-url="<?= e(url_path('bookings/track.php?token=' . urlencode($selectedBooking['sender_tracking_token']))) ?>">
                            <i class="fa-solid fa-share-nodes me-1"></i><?= e(t('booking.share_tracking_link')) ?>
                        </button>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="detail-map-toggle-btn">
                        <i class="fa-solid fa-chevron-down me-1"></i><span id="detail-map-toggle-label"><?= e(t('booking.show_map')) ?></span>
                    </button>
                </div>
                <div id="detail_map_wrap" class="collapsed mb-3">
                    <div id="detail_map"></div>
                </div>

                <?php if (!empty($selectedBooking['delivery_proof_image'])): ?>
                    <div class="mb-3">
                        <div class="small fw-bold mb-2"><?= e(t('booking.proof_of_delivery')) ?></div>
                        <img src="<?= e(url_path($selectedBooking['delivery_proof_image'])) ?>" class="img-fluid rounded" alt="<?= e(t('booking.proof_of_delivery')) ?>">
                    </div>
                <?php endif; ?>

                <?php if ($isDelivered): ?>
                    <div class="delivery-feedback-card mb-3">
                        <?php if ($existingRating): ?>
                            <div class="small fw-bold mb-1"><?= e(t('rating.your_rating')) ?></div>
                            <div class="star-rating-display mb-1">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fa-solid fa-star<?= $i <= (int) $existingRating['rating'] ? '' : ' star-empty' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <?php if (!empty($existingRating['review_text'])): ?>
                                <div class="text-soft small">"<?= e($existingRating['review_text']) ?>"</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="small fw-bold mb-2"><?= e(t('rating.rate_your_rider')) ?></div>
                            <form id="rate-rider-form">
                                <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking['id'] ?>">
                                <div class="star-rating-input mb-2" id="star-rating-input">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fa-solid fa-star star-empty" data-star="<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="rating" id="rate-rider-value" value="0">
                                <textarea class="form-control form-control-sm mb-2" name="review_text" rows="2" placeholder="<?= e(t('rating.optional_review')) ?>"></textarea>
                                <button class="btn btn-sm btn-primary" type="submit"><?= e(t('rating.submit')) ?></button>
                                <div class="small text-danger mt-2 d-none" id="rate-rider-error"></div>
                            </form>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-danger mt-2" type="button" data-bs-toggle="modal" data-bs-target="#reportProblemModal">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i><?= e(t('complaint.report_problem')) ?>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="action-stack">
                    <?php if ($canPay): ?>
                        <button class="btn btn-success w-100" type="button" id="pay-now-btn" data-booking-id="<?= (int) $selectedBooking['id'] ?>">
                            <?= e(t('booking.pay_prefix')) ?> &#8358;<?= number_format((float) $selectedBooking['agreed_cost'], 2) ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($canEditDetails): ?>
                        <button class="btn btn-outline-secondary w-100" type="button" data-bs-toggle="modal" data-bs-target="#editDetailsModal">
                            <i class="fa-solid fa-pen me-2"></i><?= e(t('booking.edit_details')) ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($canChangeDelivery): ?>
                        <button class="btn btn-outline-secondary w-100" type="button" data-bs-toggle="modal" data-bs-target="#changeDeliveryModal">
                            <i class="fa-solid fa-location-dot me-2"></i><?= e(t('booking.change_delivery_address')) ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($canIssueItem): ?>
                        <button class="btn btn-info w-100 fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#issueItemModal">
                            <i class="fa-solid fa-box-open me-2"></i><?= e(t('booking.issue_item_to_rider')) ?>
                        </button>
                    <?php elseif ($selectedBooking && (int)($selectedBooking['sender_handover_confirmed'] ?? 0) === 1): ?>
                        <div class="alert alert-info mb-0">
                            <i class="fa-solid fa-circle-check me-2"></i><?= e(t('booking.item_already_issued')) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canCancel): ?>
                        <button class="btn btn-outline-danger w-100" type="button" data-bs-toggle="modal" data-bs-target="#cancelBookingModal">
                            <i class="fa-solid fa-ban me-2"></i><?= e(t('booking.cancel_order')) ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($canRebook): ?>
                        <button class="btn btn-primary w-100" type="button" id="rebook-rider-btn" data-booking-id="<?= (int)$selectedBooking['id'] ?>">
                            <i class="fa-solid fa-rotate-right me-2"></i><?= e(t('booking.book_another_rider')) ?>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($cancellationReason !== ''): ?>
                    <div class="cancel-reason-box">
                        <div class="fw-bold mb-1"><?= e(t('booking.cancellation_reason')) ?></div>
                        <div class="small"><?= e($cancellationReason) ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($needsRider): ?>
                <div class="rider-float-bar" id="rider-float-bar">
                    <div class="rider-float-header">
                        <div>
                            <strong id="rider-float-title"><?= e(t('match.finding_rider')) ?></strong>
                            <span class="text-soft small ms-2" id="rider-float-subtitle"><?= e(t('match.scanning_nearby')) ?></span>
                        </div>
                    </div>
                    <div class="rider-float-list" id="rider-list-container">
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                            <span class="ms-2 text-soft small"><?= e(t('match.scanning_for_riders')) ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($selectedBooking): ?>
        <div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-white text-dark border-0 shadow-lg">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title"><?= e(t('booking.cancel_order')) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-soft"><?= e(t('modal.cancel_confirm_text')) ?></p>
                        <input type="hidden" id="cancel-booking-id" value="<?= (int)$selectedBooking['id'] ?>">
                        <label class="form-label"><?= e(t('modal.cancellation_reason_label')) ?></label>
                        <textarea id="cancel-reason" class="form-control" rows="4" placeholder="<?= e(t('modal.cancellation_reason_placeholder')) ?>"></textarea>
                        <div class="small text-danger mt-2 d-none" id="cancel-reason-error"><?= e(t('modal.cancellation_reason_required')) ?></div>
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.close')) ?></button>
                        <button type="button" class="btn btn-danger" id="confirm-cancel-booking-btn"><?= e(t('modal.confirm_cancel')) ?></button>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isDelivered): ?>
        <div class="modal fade" id="reportProblemModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-white text-dark border-0 shadow-lg">
                    <form id="report-problem-form">
                        <div class="modal-header border-bottom">
                            <h5 class="modal-title"><?= e(t('complaint.report_problem')) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking['id'] ?>">
                            <label class="form-label"><?= e(t('complaint.what_went_wrong')) ?></label>
                            <select class="form-select mb-3" name="category" required>
                                <option value=""><?= e(t('complaint.choose_category')) ?></option>
                                <option value="damaged_item"><?= e(t('complaint.category.damaged_item')) ?></option>
                                <option value="late_delivery"><?= e(t('complaint.category.late_delivery')) ?></option>
                                <option value="wrong_item"><?= e(t('complaint.category.wrong_item')) ?></option>
                                <option value="rider_behavior"><?= e(t('complaint.category.rider_behavior')) ?></option>
                                <option value="other"><?= e(t('complaint.category.other')) ?></option>
                            </select>
                            <label class="form-label"><?= e(t('complaint.details_label')) ?></label>
                            <textarea class="form-control" name="message" rows="4" placeholder="<?= e(t('complaint.details_placeholder')) ?>" required></textarea>
                            <div class="small text-danger mt-2 d-none" id="report-problem-error"></div>
                        </div>
                        <div class="modal-footer border-top">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                            <button type="submit" class="btn btn-danger"><?= e(t('complaint.submit_report')) ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="modal fade" id="issueItemModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-white text-dark border-0 shadow-lg">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title"><?= e(t('booking.issue_item_to_rider')) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0 text-soft"><?= e(t('modal.issue_item_confirm_text')) ?></p>
                        <input type="hidden" id="issue-booking-id" value="<?= (int)$selectedBooking['id'] ?>">
                    </div>
                    <div class="modal-footer border-top">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.no')) ?></button>
                        <button type="button" class="btn btn-info fw-bold" id="confirm-issue-item-btn"><?= e(t('modal.yes_issue_item')) ?></button>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($canEditDetails): ?>
        <div class="modal fade" id="editDetailsModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content bg-white text-dark border-0 shadow-lg">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title"><?= e(t('modal.edit_booking_details_title')) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="edit-details-form">
                        <div class="modal-body">
                            <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking['id'] ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?= e(t('wizard.recipient_name_label')) ?></label>
                                    <input class="form-control" name="recipient_name" value="<?= e($selectedBooking['recipient_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?= e(t('wizard.recipient_phone_label')) ?></label>
                                    <input class="form-control" name="recipient_phone" value="<?= e($selectedBooking['recipient_phone']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?= e(t('wizard.item_name_label')) ?></label>
                                    <input class="form-control" name="item_name" value="<?= e($selectedBooking['item_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?= e(t('wizard.item_category_label')) ?></label>
                                    <select class="form-select" name="item_category" required>
                                        <?php foreach (['document' => t('wizard.category.document'), 'food' => t('wizard.category.food'), 'parcel' => t('wizard.category.parcel'), 'fragile' => t('wizard.category.fragile')] as $val => $label): ?>
                                            <option value="<?= $val ?>" <?= $selectedBooking['item_category'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?= e(t('wizard.item_description_label')) ?></label>
                                    <textarea class="form-control" name="item_description" rows="3"><?= e((string) ($selectedBooking['item_description'] ?? '')) ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?= e(t('wizard.estimated_value_label')) ?></label>
                                    <input class="form-control" name="estimated_value" value="<?= e((string) ($selectedBooking['estimated_value'] ?? '')) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?= e(t('modal.replace_photo_label')) ?></label>
                                    <input class="form-control" type="file" name="item_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?= e(t('wizard.special_instructions_label')) ?></label>
                                    <textarea class="form-control" name="special_instructions" rows="2"><?= e((string) ($selectedBooking['special_instructions'] ?? '')) ?></textarea>
                                </div>
                            </div>
                            <div class="small text-danger mt-2 d-none" id="edit-details-error"></div>
                        </div>
                        <div class="modal-footer border-top">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                            <button type="submit" class="btn btn-primary fw-bold"><?= e(t('modal.save_changes')) ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canChangeDelivery): ?>
        <div class="modal fade" id="changeDeliveryModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-white text-dark border-0 shadow-lg">
                    <div class="modal-header border-bottom">
                        <h5 class="modal-title"><?= e(t('booking.change_delivery_address')) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="change-delivery-form">
                        <div class="modal-body">
                            <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking['id'] ?>">
                            <p class="text-soft small"><?= e(t('modal.recalc_price_note')) ?></p>
                            <label class="form-label"><?= e(t('modal.new_delivery_address_label')) ?></label>
                            <div class="address-search">
                                <div class="input-group">
                                    <input class="form-control" id="edit_delivery_address" name="delivery_address" autocomplete="off" value="<?= e($selectedBooking['delivery_address']) ?>" placeholder="<?= e(t('wizard.address_placeholder')) ?>">
                                    <button class="btn btn-outline-secondary" type="button" id="use_current_edit_delivery" title="<?= e(t('wizard.use_current_location')) ?>"><i class="fa-solid fa-location-crosshairs"></i></button>
                                </div>
                                <div class="address-suggestions" id="edit_delivery_suggestions"></div>
                            </div>
                            <input type="hidden" id="edit_delivery_latitude" name="delivery_latitude" value="<?= e((string) $selectedDeliveryLat) ?>">
                            <input type="hidden" id="edit_delivery_longitude" name="delivery_longitude" value="<?= e((string) $selectedDeliveryLng) ?>">
                            <div class="small text-danger mt-2 d-none" id="change-delivery-error"></div>
                        </div>
                        <div class="modal-footer border-top">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
                            <button type="submit" class="btn btn-primary fw-bold"><?= e(t('modal.update_address')) ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($canChat): ?>
        <button type="button" class="sticky-chat-btn" id="open-chat-btn" title="<?= e(t('chat.open_chat_title')) ?>">
            <i class="fa-solid fa-comments"></i>
            <span class="chat-unread-badge" id="chat-unread-badge" style="display:none">0</span>
        </button>

        <div class="chat-panel" id="chat-panel">
            <div class="chat-header">
                <div class="chat-header-info">
                    <div class="chat-avatar position-relative"><i class="fa-solid fa-motorcycle"></i><span class="presence-dot" id="chat-presence-dot"></span></div>
                    <div>
                        <div class="fw-bold"><?= e((string)($selectedBooking['rider_name'] ?? t('chat.default_rider_name'))) ?></div>
                        <div class="small text-soft" id="chat-presence-label"><?= e(t('chat.your_rider')) ?></div>
                    </div>
                </div>
                <div class="chat-header-actions">
                    <?php if (!empty($selectedBooking['rider_phone'])): ?>
                    <a class="chat-icon-btn" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $selectedBooking['rider_phone'])) ?>" title="<?= e(t('chat.call_rider_phone_title')) ?>">
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
                <input type="hidden" id="chat-booking-id" value="<?= (int)$selectedBooking['id'] ?>">
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
            <div class="call-panel-avatar"><i class="fa-solid fa-motorcycle"></i></div>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>
<script src="https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js"></script>

<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const MAPBOX_TOKEN = <?= json_encode(mapbox_token()) ?>;

const STATUS_LABELS = <?= json_encode([
    'draft' => t('status.draft'),
    'submitted' => t('status.submitted'),
    'matched' => t('status.matched'),
    'accepted' => t('status.accepted'),
    'arrived_at_pickup' => t('status.arrived_at_pickup'),
    'package_received' => t('status.package_received'),
    'in_transit' => t('status.in_transit'),
    'delivered' => t('status.delivered'),
    'cancelled' => t('status.cancelled'),
], JSON_UNESCAPED_UNICODE) ?>;

const PAYMENT_STATUS_LABELS = <?= json_encode([
    'unpaid' => t('status.unpaid'),
    'paid' => t('status.paid'),
    'pending' => t('status.pending'),
    'failed' => t('status.failed'),
], JSON_UNESCAPED_UNICODE) ?>;

function statusBadgeText(status) {
    const key = String(status || '');
    return STATUS_LABELS[key] || key.replaceAll('_', ' ');
}

function paymentBadgeText(status) {
    const key = String(status || '');
    return PAYMENT_STATUS_LABELS[key] || key.replaceAll('_', ' ');
}

const I18N = <?= json_encode([
    'voiceUploadFailed' => t('rider.voice_note_upload_failed'),
    'recordVoiceFailed' => t('rider.record_voice_failed'),
    'messageSendFailed' => t('rider.message_send_failed'),
    'tapMapToSet' => t('map.tap_to_set'),
    'calculatingRoute' => t('map.calculating_route'),
    'showMap' => t('booking.show_map'),
    'hideMap' => t('booking.hide_map'),
    'findingRider' => t('match.finding_rider'),
    'scanningNearby' => t('match.scanning_nearby'),
    'waitingForRider' => t('match.waiting_for_rider'),
    'requestingRider' => t('match.requesting_rider'),
    'chooseVehicleType' => t('match.choose_vehicle_type'),
    'riderFound' => t('match.rider_found'),
    'pickOneToMatch' => t('match.pick_one_to_match'),
    'matchingYouNow' => t('match.matching_you_now'),
    'noMoreNearbyShort' => t('match.no_more_nearby_short'),
    'ratingStarRequired' => t('rating.star_required'),
    'addressPickFromSuggestions' => t('address.pick_from_suggestions'),
    'callIncomingRinging' => t('call.incoming_ringing'),
    'callServiceUnavailable' => t('call.service_unavailable'),
    'callServiceUnavailableRetry' => t('call.service_unavailable_retry'),
    'callNoAnswer' => t('call.no_answer'),
    'callConnected' => t('call.connected'),
    'callConnectionFailed' => t('call.connection_failed'),
    'callEnded' => t('call.ended'),
    'callFailed' => t('call.failed'),
    'callRiderOfflinePhone' => t('call.rider_offline_calling_phone'),
    'callRiderOfflineNoPhone' => t('call.rider_offline_no_phone'),
    'callConnecting' => t('call.connecting'),
    'callRinging' => t('call.ringing'),
    'presenceOnline' => t('chat.presence_online'),
    'presenceOffline' => t('chat.presence_offline'),
    'recordVoice' => t('chat.record_voice_label'),
    'stopRecording' => t('chat.stop_recording'),
    'tickRead' => t('chat.tick_read'),
    'tickDelivered' => t('chat.tick_delivered'),
    'tickSent' => t('chat.tick_sent'),
    'waitingForThemToRespond' => t('match.waiting_for_them'),
    'scanningForRiders' => t('match.scanning_for_riders'),
    'noMatchingAddress' => t('address.no_match'),
    'noMessagesYet' => t('chat.no_messages_yet'),
    'routeSummary' => t('map.route_summary'),
    'pickupPin' => t('map.pickup_pin'),
    'deliveryPin' => t('map.delivery_pin'),
    'riderPin' => t('map.rider_pin'),
    'locatingAddress' => t('map.locating_address'),
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

async function fetchMapboxRoute(points) {
    try {
        const coordsParam = points.map(p => `${p[1]},${p[0]}`).join(';');
        const url = `https://api.mapbox.com/directions/v5/mapbox/driving/${coordsParam}?geometries=geojson&overview=full&access_token=${MAPBOX_TOKEN}`;
        const res = await fetch(url);
        const data = await res.json();
        const route = data.routes && data.routes[0];
        if (!route) return null;
        return {
            durationSec: route.duration,
            distanceMeters: route.distance,
            latlngs: route.geometry.coordinates.map(c => [c[1], c[0]])
        };
    } catch (err) {
        console.error('Mapbox directions request failed:', err);
        return null;
    }
}

const NIGERIA_BBOX = '2.6,4.2,14.7,14.0';
const ADDRESS_SEARCH_RADIUS_KM = 30;
let cachedSearchOrigin;

function getSearchOrigin() {
    if (cachedSearchOrigin !== undefined) return Promise.resolve(cachedSearchOrigin);
    if (!navigator.geolocation) {
        cachedSearchOrigin = null;
        return Promise.resolve(null);
    }
    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                cachedSearchOrigin = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                resolve(cachedSearchOrigin);
            },
            () => {
                cachedSearchOrigin = null;
                resolve(null);
            },
            { enableHighAccuracy: false, timeout: 6000, maximumAge: 300000 }
        );
    });
}

function bboxFromCenter(lat, lng, radiusKm) {
    const latDelta = radiusKm / 111;
    const lngDelta = radiusKm / (111 * Math.cos(lat * Math.PI / 180));
    return `${(lng - lngDelta).toFixed(5)},${(lat - latDelta).toFixed(5)},${(lng + lngDelta).toFixed(5)},${(lat + latDelta).toFixed(5)}`;
}

async function buildGeocodeSearchUrl(query) {
    const origin = await getSearchOrigin();
    const bbox = origin ? bboxFromCenter(origin.lat, origin.lng, ADDRESS_SEARCH_RADIUS_KM) : NIGERIA_BBOX;
    const proximityParam = origin ? `&proximity=${origin.lng},${origin.lat}` : '';
    const types = 'address,place,poi,neighborhood,locality,district,region';
    return `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json?access_token=${MAPBOX_TOKEN}&country=ng&autocomplete=true&limit=10&language=en&types=${types}&bbox=${bbox}${proximityParam}`;
}
const workspaceState = {
    bookingMap: null,
    detailMap: null,
    routingControl: null,
    trackingMarker: null,
    riderMarkers: {},
    knownRiderIds: new Set(),
    pingSound: null,
    ridersInterval: null,
    requestStatusInterval: null,
    autoMatchTimer: null,
    matchExcludedRiderIds: new Set(),
    matchPhase: 'searching',
    trackingInterval: null,
    chatInterval: null,
    callPollInterval: null,
    presenceInterval: null,
    peer: null,
    currentCall: null,
    localCallStream: null
};

function cleanupWorkspace() {
    if (workspaceState.ridersInterval) {
        clearInterval(workspaceState.ridersInterval);
        workspaceState.ridersInterval = null;
    }
    if (workspaceState.requestStatusInterval) {
        clearInterval(workspaceState.requestStatusInterval);
        workspaceState.requestStatusInterval = null;
    }
    if (workspaceState.autoMatchTimer) {
        clearTimeout(workspaceState.autoMatchTimer);
        workspaceState.autoMatchTimer = null;
    }
    workspaceState.matchExcludedRiderIds = new Set();
    workspaceState.matchPhase = 'searching';
    if (workspaceState.trackingInterval) {
        clearInterval(workspaceState.trackingInterval);
        workspaceState.trackingInterval = null;
    }
    if (workspaceState.chatInterval) {
        clearInterval(workspaceState.chatInterval);
        workspaceState.chatInterval = null;
    }
    if (workspaceState.callPollInterval) {
        clearInterval(workspaceState.callPollInterval);
        workspaceState.callPollInterval = null;
    }
    if (workspaceState.presenceInterval) {
        clearInterval(workspaceState.presenceInterval);
        workspaceState.presenceInterval = null;
    }
    if (workspaceState.currentCall) {
        try { workspaceState.currentCall.close(); } catch (e) {}
        workspaceState.currentCall = null;
    }
    if (workspaceState.localCallStream) {
        workspaceState.localCallStream.getTracks().forEach(track => track.stop());
        workspaceState.localCallStream = null;
    }
    if (workspaceState.peer) {
        try { workspaceState.peer.destroy(); } catch (e) {}
        workspaceState.peer = null;
    }
    if (workspaceState.routingControl) {
        try { workspaceState.routingControl.remove(); } catch (e) {}
        workspaceState.routingControl = null;
    }
    if (workspaceState.bookingMap) {
        try { workspaceState.bookingMap.remove(); } catch (e) {}
        workspaceState.bookingMap = null;
    }
    if (workspaceState.detailMap) {
        try { workspaceState.detailMap.remove(); } catch (e) {}
        workspaceState.detailMap = null;
    }
    workspaceState.trackingMarker = null;
    workspaceState.riderMarkers = {};
    workspaceState.knownRiderIds = new Set();
    workspaceState.pingSound = null;
}

async function ajaxLoadWorkspace(url, pushToHistory = true) {
    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });

        if (!response.ok) {
            window.location.href = url;
            return;
        }

        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const incoming = doc.getElementById('sender-workspace-content');
        const current = document.getElementById('sender-workspace-content');

        if (!incoming || !current) {
            window.location.href = url;
            return;
        }

        cleanupWorkspace();
        current.replaceWith(incoming);

        if (pushToHistory) {
            window.history.pushState({ url }, '', url);
        }

        initSenderWorkspace();
    } catch (err) {
        console.error('AJAX workspace load failed:', err);
        window.location.href = url;
    }
}

function initSenderWorkspace() {
    const root = document.getElementById('sender-workspace-content');
    if (!root) return;

    // Kick off (and cache) the geolocation lookup used to scope address search results as soon
    // as the workspace loads, rather than waiting for the user's first keystroke - avoids the
    // search feeling laggy while the browser's permission prompt is pending.
    if (root.querySelector('#pickup_address') || root.querySelector('#edit_delivery_address')) {
        getSearchOrigin();
    }

    const selectedBookingId = parseInt(root.dataset.selectedBookingId || '0', 10);
    const selectedBookingStatus = root.dataset.selectedBookingStatus || '';
    const selectedPaymentStatus = root.dataset.selectedPaymentStatus || 'unpaid';
    const selectedHasRider = root.dataset.selectedHasRider === '1';
    const shouldSearchRiders = root.dataset.needsRider === '1';
    const canTrack = root.dataset.canTrack === '1';
    const canPay = root.dataset.canPay === '1';
    const canChat = root.dataset.canChat === '1';
    const chatReceiverIdFromRoot = parseInt(root.dataset.chatReceiverId || '0', 10);

    document.body.classList.toggle('has-rider-float-bar', shouldSearchRiders);

    // ---------------- WIZARD STEP NAVIGATION ----------------
    const wizardForm = root.querySelector('#booking-form');
    if (wizardForm) {
        const panes = Array.from(wizardForm.querySelectorAll('.wizard-pane'));
        const dots = Array.from(root.querySelectorAll('.wizard-step-dot'));

        function goToStep(step) {
            panes.forEach(pane => pane.classList.toggle('active', pane.dataset.step === String(step)));
            dots.forEach(dot => {
                const dotStep = parseInt(dot.dataset.stepDot, 10);
                dot.classList.toggle('current', dotStep === step);
                dot.classList.toggle('done', dotStep < step);
            });
            wizardForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        wizardForm.querySelectorAll('[data-wizard-next]').forEach(btn => {
            btn.addEventListener('click', function () {
                const currentPane = this.closest('.wizard-pane');
                if (currentPane && currentPane.dataset.step === '1') {
                    const pickupSet = wizardForm.querySelector('#pickup_latitude')?.value;
                    const deliverySet = wizardForm.querySelector('#delivery_latitude')?.value;
                    const step1Error = root.querySelector('#step1-error');
                    if (!pickupSet || !deliverySet) {
                        step1Error?.classList.remove('d-none');
                        return;
                    }
                    step1Error?.classList.add('d-none');
                }
                goToStep(parseInt(this.dataset.wizardNext, 10));
            });
        });

        wizardForm.querySelectorAll('[data-wizard-back]').forEach(btn => {
            btn.addEventListener('click', function () {
                goToStep(parseInt(this.dataset.wizardBack, 10));
            });
        });
    }

    const pickupAddress = root.querySelector('#pickup_address');
    const pickupLat = root.querySelector('#pickup_latitude');
    const pickupLng = root.querySelector('#pickup_longitude');
    const pickupSuggestions = root.querySelector('#pickup_suggestions');
    const deliveryAddress = root.querySelector('#delivery_address');
    const deliveryLat = root.querySelector('#delivery_latitude');
    const deliveryLng = root.querySelector('#delivery_longitude');
    const deliverySuggestions = root.querySelector('#delivery_suggestions');
    const modeLabel = root.querySelector('#map_mode_label');
    const bookingMapEl = root.querySelector('#booking_map');
    const routeMapCard = root.querySelector('#route_map_card');
    const routeSummary = root.querySelector('#route_summary');

    let mapMode = null;
    let pickupMarker = null;
    let deliveryMarker = null;
    let bookingRoutingControl = null;

    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    });

    if (bookingMapEl) {
        function ensureBookingMap() {
            if (workspaceState.bookingMap) return workspaceState.bookingMap;
            workspaceState.bookingMap = L.map(bookingMapEl, { tap: false }).setView([
                parseFloat(pickupLat?.value) || 9.0820,
                parseFloat(pickupLng?.value) || 8.6753
            ], 6);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap'
            }).addTo(workspaceState.bookingMap);
            workspaceState.bookingMap.on('click', async function (e) {
                if (!mapMode) return;
                const { lat, lng } = e.latlng;
                updateFormMarker(mapMode, lat, lng);
                await reverseGeocode(lat, lng, mapMode === 'pickup' ? pickupAddress : deliveryAddress);
            });
            return workspaceState.bookingMap;
        }

        function revealMap() {
            if (routeMapCard) routeMapCard.style.display = '';
            const map = ensureBookingMap();
            setTimeout(() => map.invalidateSize(), 150);
            return map;
        }

        function setMode(mode) {
            mapMode = mode;
            revealMap();
            if (modeLabel) {
                modeLabel.style.display = '';
                modeLabel.textContent = I18N.tapMapToSet + ' ' + mode.toUpperCase();
                modeLabel.className = 'badge ' + (mode === 'pickup' ? 'text-bg-warning' : 'text-bg-info');
            }
            bookingMapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        async function reverseGeocode(lat, lng, targetInput) {
            if (!targetInput) return;
            targetInput.value = I18N.locatingAddress;
            try {
                const res = await fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${lng},${lat}.json?access_token=${MAPBOX_TOKEN}&country=ng&language=en`);
                const data = await res.json();
                const place = data.features && data.features[0];
                targetInput.value = place ? place.place_name : `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
            } catch (e) {
                targetInput.value = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
            }
            targetInput.classList.add('location-confirmed');
        }

        function maybeRevealForBothPoints() {
            if (pickupLat?.value && pickupLng?.value && deliveryLat?.value && deliveryLng?.value) {
                revealMap();
                drawRoutePreview();
            }
        }

        function updateFormMarker(type, lat, lng) {
            const map = ensureBookingMap();
            if (type === 'pickup') {
                if (pickupMarker) map.removeLayer(pickupMarker);
                pickupMarker = L.marker([lat, lng], { draggable: true }).addTo(map).bindPopup(I18N.pickupPin).openPopup();
                if (pickupLat) pickupLat.value = lat.toFixed(7);
                if (pickupLng) pickupLng.value = lng.toFixed(7);
                pickupMarker.on('dragend', async (e) => {
                    const p = e.target.getLatLng();
                    updateFormMarker('pickup', p.lat, p.lng);
                    await reverseGeocode(p.lat, p.lng, pickupAddress);
                });
            } else {
                if (deliveryMarker) map.removeLayer(deliveryMarker);
                deliveryMarker = L.marker([lat, lng], { draggable: true }).addTo(map).bindPopup(I18N.deliveryPin).openPopup();
                if (deliveryLat) deliveryLat.value = lat.toFixed(7);
                if (deliveryLng) deliveryLng.value = lng.toFixed(7);
                deliveryMarker.on('dragend', async (e) => {
                    const p = e.target.getLatLng();
                    updateFormMarker('delivery', p.lat, p.lng);
                    await reverseGeocode(p.lat, p.lng, deliveryAddress);
                });
            }
            maybeRevealForBothPoints();
        }

        async function drawRoutePreview() {
            const map = workspaceState.bookingMap;
            if (!map || !pickupLat?.value || !deliveryLat?.value) return;
            const from = [parseFloat(pickupLat.value), parseFloat(pickupLng.value)];
            const to = [parseFloat(deliveryLat.value), parseFloat(deliveryLng.value)];
            if (bookingRoutingControl) {
                bookingRoutingControl.remove();
                bookingRoutingControl = null;
            }
            if (routeSummary) routeSummary.textContent = I18N.calculatingRoute;

            const route = await fetchMapboxRoute([from, to]);
            if (!route) {
                if (routeSummary) routeSummary.textContent = '';
                return;
            }

            bookingRoutingControl = L.polyline(route.latlngs, {
                color: '#38bdf8', opacity: 0.85, weight: 5
            }).addTo(map);
            map.fitBounds(bookingRoutingControl.getBounds(), { padding: [40, 40] });

            const km = (route.distanceMeters / 1000).toFixed(1);
            const mins = Math.round(route.durationSec / 60);
            if (routeSummary) routeSummary.textContent = I18N.routeSummary.replace(':km', km).replace(':mins', mins);
        }

        function renderSuggestions(container, items, onPick) {
            if (!container) return;
            if (!items.length) {
                container.innerHTML = '<div class="address-suggestion-empty">' + I18N.noMatchingAddress + '</div>';
                container.classList.add('show');
                return;
            }
            container.innerHTML = items.map((item, idx) => `
                <div class="address-suggestion-item" data-index="${idx}">
                    <i class="fa-solid fa-location-dot"></i>
                    <div>
                        <div class="main-text">${item.text}</div>
                        <div class="sub-text">${item.place_name}</div>
                    </div>
                </div>
            `).join('');
            container.classList.add('show');
            container.querySelectorAll('.address-suggestion-item').forEach((el, idx) => {
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    onPick(items[idx]);
                    container.classList.remove('show');
                });
            });
        }

        function attachAutocomplete(inputEl, suggestionsEl, targetType) {
            if (!inputEl || !suggestionsEl) return;
            let debounceTimer = null;
            let abortController = null;

            inputEl.addEventListener('input', function () {
                inputEl.classList.remove('location-confirmed');
                if (targetType === 'pickup') { if (pickupLat) pickupLat.value = ''; if (pickupLng) pickupLng.value = ''; }
                else { if (deliveryLat) deliveryLat.value = ''; if (deliveryLng) deliveryLng.value = ''; }

                const query = inputEl.value.trim();
                if (debounceTimer) clearTimeout(debounceTimer);
                if (query.length < 3) {
                    suggestionsEl.classList.remove('show');
                    return;
                }
                debounceTimer = setTimeout(async () => {
                    if (abortController) abortController.abort();
                    abortController = new AbortController();
                    try {
                        const url = await buildGeocodeSearchUrl(query);
                        const res = await fetch(url, { signal: abortController.signal });
                        const data = await res.json();
                        const items = (data.features || []).map(f => ({
                            text: f.text,
                            place_name: f.place_name,
                            lat: f.center[1],
                            lng: f.center[0]
                        }));
                        renderSuggestions(suggestionsEl, items, (item) => {
                            inputEl.value = item.place_name;
                            inputEl.classList.add('location-confirmed');
                            updateFormMarker(targetType, item.lat, item.lng);
                        });
                    } catch (e) {
                        if (e.name !== 'AbortError') suggestionsEl.classList.remove('show');
                    }
                }, 300);
            });

            inputEl.addEventListener('blur', function () {
                setTimeout(() => suggestionsEl.classList.remove('show'), 150);
            });

            inputEl.addEventListener('keydown', function (e) {
                const items = Array.from(suggestionsEl.querySelectorAll('.address-suggestion-item'));
                if (!items.length || !suggestionsEl.classList.contains('show')) return;
                let activeIdx = items.findIndex(i => i.classList.contains('active'));
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = (activeIdx + 1) % items.length;
                    items.forEach(i => i.classList.remove('active'));
                    items[activeIdx].classList.add('active');
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = activeIdx <= 0 ? items.length - 1 : activeIdx - 1;
                    items.forEach(i => i.classList.remove('active'));
                    items[activeIdx].classList.add('active');
                } else if (e.key === 'Enter') {
                    if (activeIdx >= 0) {
                        e.preventDefault();
                        items[activeIdx].dispatchEvent(new Event('mousedown'));
                    }
                } else if (e.key === 'Escape') {
                    suggestionsEl.classList.remove('show');
                }
            });
        }

        attachAutocomplete(pickupAddress, pickupSuggestions, 'pickup');
        attachAutocomplete(deliveryAddress, deliverySuggestions, 'delivery');

        root.querySelectorAll('.map-pick-link').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                setMode(this.dataset.target);
            });
        });

        async function useCurrentLocation(target, btn) {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            btn.disabled = true;

            if (!navigator.geolocation) {
                alert('Geolocation not supported');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                return;
            }

            navigator.geolocation.getCurrentPosition(
                async (pos) => {
                    const { latitude, longitude } = pos.coords;
                    revealMap();
                    workspaceState.bookingMap.setView([latitude, longitude], 16);
                    updateFormMarker(target, latitude, longitude);
                    await reverseGeocode(latitude, longitude, target === 'pickup' ? pickupAddress : deliveryAddress);
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                },
                (err) => {
                    alert(`Error (${err.code}): ${err.message}. Ensure HTTPS is enabled.`);
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                },
                { enableHighAccuracy: false, timeout: 15000, maximumAge: 0 }
            );
        }

        root.querySelector('#use_current_pickup')?.addEventListener('click', function () { useCurrentLocation('pickup', this); });
        root.querySelector('#use_current_delivery')?.addEventListener('click', function () { useCurrentLocation('delivery', this); });

        if (pickupLat?.value && pickupLng?.value) updateFormMarker('pickup', parseFloat(pickupLat.value), parseFloat(pickupLng.value));
        if (deliveryLat?.value && deliveryLng?.value) updateFormMarker('delivery', parseFloat(deliveryLat.value), parseFloat(deliveryLng.value));
    }

    const detailMapToggleBtn = root.querySelector('#detail-map-toggle-btn');
    const detailMapToggleLabel = root.querySelector('#detail-map-toggle-label');
    const detailMapWrap = root.querySelector('#detail_map_wrap');
    detailMapToggleBtn?.addEventListener('click', function () {
        const collapsed = detailMapWrap.classList.toggle('collapsed');
        if (detailMapToggleLabel) detailMapToggleLabel.textContent = collapsed ? I18N.showMap : I18N.hideMap;
        this.querySelector('i')?.classList.toggle('fa-chevron-down', collapsed);
        this.querySelector('i')?.classList.toggle('fa-chevron-up', !collapsed);
        if (!collapsed && workspaceState.detailMap) {
            setTimeout(() => workspaceState.detailMap.invalidateSize(), 200);
        }
    });

    const shareTrackingBtn = root.querySelector('#share-tracking-btn');
    shareTrackingBtn?.addEventListener('click', async function () {
        const url = this.dataset.trackingUrl;
        const shareData = {
            title: 'Track my delivery',
            text: 'Track your delivery in real time on SwiftDrop:',
            url
        };
        if (navigator.share) {
            try {
                await navigator.share(shareData);
                return;
            } catch (err) {
                if (err && err.name === 'AbortError') return;
            }
        }
        try {
            await navigator.clipboard.writeText(url);
            const original = this.innerHTML;
            this.innerHTML = '<i class="fa-solid fa-check me-1"></i>Link Copied';
            setTimeout(() => { this.innerHTML = original; }, 2000);
        } catch (err) {
            window.prompt('Copy this tracking link:', url);
        }
    });

    const detailMapEl = root.querySelector('#detail_map');
    const pickupLatVal = parseFloat(root.dataset.pickupLat || '');
    const pickupLngVal = parseFloat(root.dataset.pickupLng || '');
    const deliveryLatVal = parseFloat(root.dataset.deliveryLat || '');
    const deliveryLngVal = parseFloat(root.dataset.deliveryLng || '');

    if (
        detailMapEl &&
        !Number.isNaN(pickupLatVal) &&
        !Number.isNaN(pickupLngVal) &&
        !Number.isNaN(deliveryLatVal) &&
        !Number.isNaN(deliveryLngVal)
    ) {
        const pickupCoords = [pickupLatVal, pickupLngVal];
        const deliveryCoords = [deliveryLatVal, deliveryLngVal];

        workspaceState.detailMap = L.map(detailMapEl).setView(pickupCoords, 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        }).addTo(workspaceState.detailMap);

        const pickupIcon = L.divIcon({
            html: '<div style="color:#f59e0b;font-size:22px;text-shadow:0 0 4px #fff;"><i class="fa-solid fa-box"></i></div>',
            className: '', iconSize: [26, 26], iconAnchor: [13, 13]
        });
        const deliveryIcon = L.divIcon({
            html: '<div style="color:#22c55e;font-size:22px;text-shadow:0 0 4px #fff;"><i class="fa-solid fa-box-open"></i></div>',
            className: '', iconSize: [26, 26], iconAnchor: [13, 13]
        });
        L.marker(pickupCoords, { icon: pickupIcon }).addTo(workspaceState.detailMap).bindPopup(I18N.pickupPin);
        L.marker(deliveryCoords, { icon: deliveryIcon }).addTo(workspaceState.detailMap).bindPopup(I18N.deliveryPin);

        setTimeout(() => workspaceState.detailMap && workspaceState.detailMap.invalidateSize(), 400);

        // Draw the full pickup-to-delivery trip once as a fixed reference line - the rider
        // marker then just moves along/near it as their live position updates, instead of
        // redrawing a fresh line to a shifting target on every poll.
        (async () => {
            const tripRoute = await fetchMapboxRoute([pickupCoords, deliveryCoords]);
            if (tripRoute) {
                workspaceState.routingControl = L.polyline(tripRoute.latlngs, {
                    color: '#38bdf8', weight: 5, opacity: 0.85
                }).addTo(workspaceState.detailMap);
            }
        })();

        function vehicleIconClass(type) {
            return type === 'car' ? 'fa-car-side' : 'fa-motorcycle';
        }

        if (canTrack) {
            let currentTrackTarget = null;
            let trackedBookingStatus = selectedBookingStatus;
            let trackedPaymentStatus = selectedPaymentStatus;

            async function pollTracking() {
                try {
                    const res = await fetch(`bookings/ajax_track_status.php?booking_id=${selectedBookingId}`);
                    const json = await res.json();
                    if (!json.status) return;

                    const d = json.data;

                    // A status/payment change can flip which action buttons, rating widget, or
                    // payment prompt should be visible - those are server-rendered, so refresh
                    // the whole workspace fragment rather than just patching the status text.
                    if (d.booking_status !== trackedBookingStatus || d.payment_status !== trackedPaymentStatus) {
                        trackedBookingStatus = d.booking_status;
                        trackedPaymentStatus = d.payment_status;
                        ajaxLoadWorkspace(`<?= e(url_path('bookings/')) ?>?booking_id=${selectedBookingId}`, false);
                        return;
                    }

                    const bookingStatusText = root.querySelector('#booking_status_text');
                    const paymentStatusText = root.querySelector('#payment_status_text');

                    if (bookingStatusText) bookingStatusText.innerText = statusBadgeText(d.booking_status);
                    if (paymentStatusText) paymentStatusText.innerText = paymentBadgeText(d.payment_status);

                    if (!d.rider_lat || !d.rider_lng) return;

                    const riderLatLng = [parseFloat(d.rider_lat), parseFloat(d.rider_lng)];
                    const riderIcon = L.divIcon({
                        html: `<div style="color:#0ea5e9;font-size:24px;text-shadow:0 0 5px #fff;"><i class="fa-solid ${vehicleIconClass(d.vehicle_type)}"></i></div>`,
                        className: '', iconSize: [30, 30], iconAnchor: [15, 15]
                    });

                    if (!workspaceState.trackingMarker) {
                        workspaceState.trackingMarker = L.marker(riderLatLng, { icon: riderIcon }).addTo(workspaceState.detailMap).bindPopup(I18N.riderPin);
                    } else {
                        workspaceState.trackingMarker.setLatLng(riderLatLng);
                        workspaceState.trackingMarker.setIcon(riderIcon);
                    }

                    // Match the rider-side target logic: pickup while matched/accepted (not yet
                    // picked up), delivery from arrived_at_pickup onward (package is en route).
                    let target;
                    if (d.booking_status === 'matched' || d.booking_status === 'accepted') {
                        target = [parseFloat(d.pickup_lat), parseFloat(d.pickup_lng)];
                    } else {
                        target = [parseFloat(d.delivery_lat), parseFloat(d.delivery_lng)];
                    }

                    // Only re-fetch the ETA/distance figure when the target actually changes
                    // (pickup vs delivery) or the rider has moved meaningfully. The fixed
                    // pickup-to-delivery line stays as-is; this just recomputes the numbers.
                    const targetKey = JSON.stringify([target, riderLatLng]);
                    if (targetKey === currentTrackTarget) {
                        return;
                    }
                    currentTrackTarget = targetKey;

                    const etaText = root.querySelector('#eta_text');
                    if (etaText) etaText.innerText = 'Calculating...';

                    const leg = await fetchMapboxRoute([riderLatLng, target]);
                    if (leg) {
                        const distKm = (leg.distanceMeters / 1000).toFixed(2);
                        const etaMin = Math.round(leg.durationSec / 60);
                        if (etaText) etaText.innerText = `${etaMin} min · ${distKm} km`;
                    } else if (etaText) {
                        etaText.innerText = '--';
                    }

                } catch (e) {
                    console.log('Tracking error', e);
                }
            }

            pollTracking();
            workspaceState.trackingInterval = setInterval(() => { if (!document.hidden) pollTracking(); }, 4000);
        }
    }

        function stopRiderSearch() {
            if (workspaceState.ridersInterval) {
                clearInterval(workspaceState.ridersInterval);
                workspaceState.ridersInterval = null;
            }
            if (workspaceState.requestStatusInterval) {
                clearInterval(workspaceState.requestStatusInterval);
                workspaceState.requestStatusInterval = null;
            }
            if (workspaceState.autoMatchTimer) {
                clearTimeout(workspaceState.autoMatchTimer);
                workspaceState.autoMatchTimer = null;
            }
        }

        if (shouldSearchRiders) {
            workspaceState.pingSound = new Audio('assets/sounds/notification.mp3');
            workspaceState.matchExcludedRiderIds = workspaceState.matchExcludedRiderIds || new Set();
            workspaceState.matchPhase = workspaceState.matchPhase || 'searching';

            const floatTitle = root.querySelector('#rider-float-title');
            const floatSubtitle = root.querySelector('#rider-float-subtitle');

            function escapeForRiderCard(str) {
                return String(str || '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;');
            }

            function vehicleLabel(type) {
                return type === 'car' ? 'Car' : 'Bike';
            }

            function vehicleIcon(type) {
                return type === 'car' ? 'fa-car-side' : 'fa-motorcycle';
            }

            function renderRiderMarkers(riders) {
                const activeIds = new Set();
                riders.forEach(rider => {
                    activeIds.add(rider.id);
                    if (!workspaceState.detailMap) return;
                    const latlng = [parseFloat(rider.last_latitude), parseFloat(rider.last_longitude)];
                    if (workspaceState.riderMarkers[rider.id]) {
                        workspaceState.riderMarkers[rider.id].setLatLng(latlng);
                    } else {
                        const icon = L.divIcon({
                            html: `<div style="color:${rider.vehicle_type === 'car' ? '#38bdf8' : '#fbbf24'};font-size:24px;text-shadow:0 0 5px #000;"><i class="fa-solid ${vehicleIcon(rider.vehicle_type)}"></i></div>`,
                            className: '',
                            iconSize: [30, 30],
                            iconAnchor: [15, 15]
                        });
                        workspaceState.riderMarkers[rider.id] = L.marker(latlng, { icon }).addTo(workspaceState.detailMap).bindPopup(`<b>${escapeForRiderCard(rider.full_name)}</b>`);
                    }
                });
                workspaceState.knownRiderIds = activeIds;
            }

            function stopRequestStatusPoll() {
                if (workspaceState.requestStatusInterval) {
                    clearInterval(workspaceState.requestStatusInterval);
                    workspaceState.requestStatusInterval = null;
                }
            }

            async function pollRequestStatus() {
                if (workspaceState.matchPhase !== 'waiting') return;
                try {
                    const res = await fetch(`bookings/ajax_request_status.php?booking_id=${selectedBookingId}`, { cache: 'no-store' });
                    const result = await res.json();
                    if (!result.success) return;

                    if (result.booking_status && result.booking_status !== 'submitted') {
                        stopRequestStatusPoll();
                        ajaxLoadWorkspace(`<?= e(url_path('bookings/')) ?>?booking_id=${selectedBookingId}`, false);
                        return;
                    }

                    const req = result.request;
                    if (req && req.request_status === 'rejected') {
                        workspaceState.matchExcludedRiderIds.add(Number(req.rider_user_id));
                        workspaceState.matchPhase = 'searching';
                        stopRequestStatusPoll();
                        const listContainer = root.querySelector('#rider-list-container');
                        if (listContainer) {
                            listContainer.innerHTML = `<div class="text-center text-soft small py-3">${escapeForRiderCard(req.full_name)} wasn't able to take this delivery. Looking for another rider...</div>`;
                        }
                        updateRiders();
                    }
                } catch (err) {
                    console.error('Request status poll failed:', err);
                }
            }

            function startWaitingForResponse(rider) {
                workspaceState.matchPhase = 'waiting';
                if (floatTitle) floatTitle.textContent = I18N.waitingForRider;
                if (floatSubtitle) floatSubtitle.textContent = `${escapeForRiderCard(rider.full_name)} · ${vehicleLabel(rider.vehicle_type)}`;
                const listContainer = root.querySelector('#rider-list-container');
                if (listContainer) {
                    listContainer.innerHTML = `
                        <div class="text-center py-4">
                            <div class="spinner-border spinner-border-sm text-info mb-2" role="status"></div>
                            <div class="fw-bold">${escapeForRiderCard(rider.full_name)}</div>
                            <div class="text-soft small">${vehicleLabel(rider.vehicle_type)} &middot; ₦${Number(rider.suggested_fee).toLocaleString()}</div>
                            <div class="text-soft small mt-1">${I18N.waitingForThemToRespond}</div>
                        </div>`;
                }
                stopRequestStatusPoll();
                workspaceState.requestStatusInterval = setInterval(() => pollRequestStatus(), 3000);
            }

            async function sendRiderRequest(rider) {
                workspaceState.matchPhase = 'matching';
                if (floatTitle) floatTitle.textContent = I18N.requestingRider;
                if (floatSubtitle) floatSubtitle.textContent = `${escapeForRiderCard(rider.full_name)} · ${vehicleLabel(rider.vehicle_type)}`;
                const listContainer = root.querySelector('#rider-list-container');
                if (listContainer) {
                    listContainer.innerHTML = `
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                            <div class="mt-2 small">Sending request to ${escapeForRiderCard(rider.full_name)}...</div>
                        </div>`;
                }
                try {
                    const response = await fetch('<?= e(url_path('bookings/send_request.php')) ?>', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new URLSearchParams({
                            booking_id: String(selectedBookingId),
                            rider_user_id: String(rider.id),
                            proposed_cost: String(rider.suggested_fee),
                            csrf_token: CSRF_TOKEN
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Failed to send request.');
                    }
                    startWaitingForResponse(rider);
                } catch (err) {
                    console.error('send_request error:', err);
                    workspaceState.matchPhase = 'searching';
                    if (listContainer) {
                        listContainer.innerHTML = `<div class="text-center text-danger small py-3">${escapeForRiderCard(err.message || 'Failed to send request.')}</div>`;
                    }
                    setTimeout(() => updateRiders(), 2500);
                }
            }

            function renderGroupPicker(groups) {
                if (floatTitle) floatTitle.textContent = groups.length > 1 ? I18N.chooseVehicleType : I18N.riderFound;
                if (floatSubtitle) floatSubtitle.textContent = groups.length > 1 ? I18N.pickOneToMatch : I18N.matchingYouNow;

                const listContainer = root.querySelector('#rider-list-container');
                if (!listContainer) return;

                listContainer.innerHTML = groups.map(g => `
                    <div class="vehicle-option-card" data-vehicle-type="${g.vehicle_type}">
                        <div class="vehicle-option-icon"><i class="fa-solid ${vehicleIcon(g.vehicle_type)}"></i></div>
                        <div class="vehicle-option-info">
                            <div class="fw-bold">${vehicleLabel(g.vehicle_type)}</div>
                            <div class="text-soft small">${parseFloat(g.nearestRider.distance_km).toFixed(1)}km away &middot; ${g.count} available</div>
                        </div>
                        <div class="vehicle-option-price">₦${Number(g.nearestRider.suggested_fee).toLocaleString()}</div>
                    </div>
                `).join('');

                listContainer.querySelectorAll('.vehicle-option-card').forEach(card => {
                    card.addEventListener('click', function () {
                        const type = this.dataset.vehicleType;
                        const group = groups.find(g => g.vehicle_type === type);
                        if (!group) return;
                        sendRiderRequest(group.nearestRider);
                    });
                });
            }

            async function updateRiders() {
                if (!shouldSearchRiders || selectedHasRider || ['matched', 'accepted', 'picked_up', 'in_transit', 'delivered', 'cancelled', 'arrived_at_pickup', 'package_received'].includes(String(selectedBookingStatus))) {
                    stopRiderSearch();
                    return;
                }
                if (workspaceState.matchPhase === 'waiting' || workspaceState.matchPhase === 'matching') {
                    return;
                }

                try {
                    const response = await fetch(`bookings/ajax_fetch_riders.php?booking_id=${selectedBookingId}`);
                    const riders = await response.json();

                    const listContainer = root.querySelector('#rider-list-container');
                    if (!listContainer) {
                        stopRiderSearch();
                        return;
                    }

                    const newRiderFound = workspaceState.knownRiderIds.size > 0 && riders.some(r => !workspaceState.knownRiderIds.has(r.id));
                    renderRiderMarkers(riders);
                    if (newRiderFound && workspaceState.pingSound) {
                        workspaceState.pingSound.play().catch(() => {});
                    }

                    const availableRiders = riders.filter(r => !workspaceState.matchExcludedRiderIds.has(r.id));

                    if (availableRiders.length === 0) {
                        if (workspaceState.autoMatchTimer) { clearTimeout(workspaceState.autoMatchTimer); workspaceState.autoMatchTimer = null; }
                        if (riders.length > 0 && workspaceState.matchExcludedRiderIds.size > 0) {
                            if (floatTitle) floatTitle.textContent = I18N.findingRider;
                            if (floatSubtitle) floatSubtitle.textContent = I18N.noMoreNearbyShort;
                            listContainer.innerHTML = '<div class="text-center text-soft small py-3">No more nearby riders responded. We will keep scanning.</div>';
                            workspaceState.matchExcludedRiderIds.clear();
                        } else {
                            if (floatTitle) floatTitle.textContent = I18N.findingRider;
                            if (floatSubtitle) floatSubtitle.textContent = I18N.scanningNearby;
                            listContainer.innerHTML = `
                                <div class="text-center py-3">
                                    <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                                    <span class="ms-2 text-soft small">${I18N.scanningForRiders}</span>
                                </div>`;
                        }
                        return;
                    }

                    const groups = [];
                    const seenTypes = new Set();
                    availableRiders.forEach(r => {
                        if (seenTypes.has(r.vehicle_type)) return;
                        seenTypes.add(r.vehicle_type);
                        groups.push({
                            vehicle_type: r.vehicle_type,
                            nearestRider: r,
                            count: availableRiders.filter(x => x.vehicle_type === r.vehicle_type).length
                        });
                    });

                    if (groups.length === 1) {
                        const group = groups[0];
                        if (floatTitle) floatTitle.textContent = I18N.riderFound;
                        if (floatSubtitle) floatSubtitle.textContent = I18N.matchingYouNow;
                        if (!workspaceState.autoMatchTimer) {
                            listContainer.innerHTML = `
                                <div class="text-center py-4">
                                    <div class="vehicle-option-icon mx-auto mb-2"><i class="fa-solid ${vehicleIcon(group.vehicle_type)}"></i></div>
                                    <div class="fw-bold">${vehicleLabel(group.vehicle_type)} &middot; ₦${Number(group.nearestRider.suggested_fee).toLocaleString()}</div>
                                    <div class="text-soft small">${group.count} available nearby</div>
                                </div>`;
                            workspaceState.autoMatchTimer = setTimeout(() => {
                                workspaceState.autoMatchTimer = null;
                                if (workspaceState.matchPhase === 'searching') {
                                    sendRiderRequest(group.nearestRider);
                                }
                            }, 1400);
                        }
                        return;
                    }

                    if (workspaceState.autoMatchTimer) { clearTimeout(workspaceState.autoMatchTimer); workspaceState.autoMatchTimer = null; }
                    renderGroupPicker(groups);
                } catch (err) {
                    console.error('Update Error:', err);
                }
            }

            updateRiders();
            workspaceState.ridersInterval = setInterval(() => { if (!document.hidden) updateRiders(); }, 15000);
        }

    if (canPay) {
        const payNowBtn = root.querySelector('#pay-now-btn');

        payNowBtn?.addEventListener('click', async function () {
            const btn = this;
            const originalHtml = btn.innerHTML;
            const bookingId = btn.dataset.bookingId;
            const alertContainer = root.querySelector('#alert-container');

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Initializing payment...';

            if (alertContainer) alertContainer.innerHTML = '';

            try {
                const response = await fetch('<?= e(url_path('payments/initialize.php')) ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    cache: 'no-store',
                    body: JSON.stringify({ booking_id: bookingId, csrf_token: CSRF_TOKEN })
                });

                const rawText = await response.text();
                let result;

                try {
                    result = JSON.parse(rawText);
                } catch (e) {
                    console.error('Invalid JSON from initialize:', rawText);
                    throw new Error('Payment initialization returned an invalid response.');
                }

                if (!response.ok) {
                    throw new Error(result?.message || `Payment initialization failed with HTTP ${response.status}`);
                }

                if (!result || result.status !== true || !result.data) {
                    throw new Error(result?.message || 'Unable to initialize payment.');
                }

                const payload = result.data;

                if (!payload.public_key || !payload.email || !payload.amount || !payload.reference) {
                    throw new Error('Payment initialization payload is incomplete.');
                }

                const handler = PaystackPop.setup({
                    key: payload.public_key,
                    email: payload.email,
                    amount: parseInt(payload.amount, 10),
                    ref: payload.reference,
                    currency: payload.currency || 'NGN',
                    metadata: payload.metadata || {},
                    callback: function (transaction) {
                        let verifyUrl;

                        if (payload.callback_url) {
                            verifyUrl = payload.callback_url + (payload.callback_url.includes('?') ? '&' : '?') + 'reference=' + encodeURIComponent(transaction.reference);
                        } else {
                            verifyUrl = '<?= e(url_path('payments/callback.php')) ?>?reference=' + encodeURIComponent(transaction.reference);
                        }

                        window.location.href = verifyUrl;
                    },
                    onClose: function () {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;

                        if (alertContainer) {
                            alertContainer.innerHTML = `
                                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                    Payment popup was closed before completion.
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            `;
                        }
                    }
                });

                handler.openIframe();
                btn.disabled = false;
                btn.innerHTML = originalHtml;

            } catch (err) {
                console.error('Payment initialization error:', err);
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                if (alertContainer) {
                    alertContainer.innerHTML = `
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            ${String(err.message || 'Payment initialization failed.')}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    `;
                } else {
                    alert(err.message || 'Payment initialization failed.');
                }
            }
        });
    }

    const confirmCancelBookingBtn = root.querySelector('#confirm-cancel-booking-btn');
    confirmCancelBookingBtn?.addEventListener('click', async function () {
        const bookingId = root.querySelector('#cancel-booking-id')?.value;
        const reasonInput = root.querySelector('#cancel-reason');
        const reasonError = root.querySelector('#cancel-reason-error');
        const reason = (reasonInput?.value || '').trim();

        if (!reason) {
            reasonError?.classList.remove('d-none');
            return;
        } else {
            reasonError?.classList.add('d-none');
        }

        const btn = this;
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Cancelling...';

        try {
            const response = await fetch('<?= e(url_path('bookings/ajax_cancel_booking.php')) ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ booking_id: bookingId, reason, csrf_token: CSRF_TOKEN })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to cancel booking.');
            }

            window.location.reload();
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            alert(err.message || 'Unable to cancel booking.');
        }
    });

    const rebookRiderBtn = root.querySelector('#rebook-rider-btn');
    rebookRiderBtn?.addEventListener('click', async function () {
        const bookingId = this.dataset.bookingId;
        const btn = this;
        const originalHtml = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Rebooking...';

        try {
            const response = await fetch('<?= e(url_path('bookings/ajax_rebook.php')) ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ booking_id: bookingId, csrf_token: CSRF_TOKEN })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to book another rider.');
            }

            ajaxLoadWorkspace('<?= e(url_path('bookings/')) ?>?booking_id=' + encodeURIComponent(bookingId), true);
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            alert(err.message || 'Unable to book another rider.');
        }
    });

    const confirmIssueItemBtn = root.querySelector('#confirm-issue-item-btn');
    confirmIssueItemBtn?.addEventListener('click', async function () {
        const bookingId = root.querySelector('#issue-booking-id')?.value;
        const btn = this;
        const originalHtml = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Confirming...';

        try {
            const response = await fetch('<?= e(url_path('bookings/ajax_confirm_handover.php')) ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ booking_id: bookingId, csrf_token: CSRF_TOKEN })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to issue item to rider.');
            }

            window.location.reload();
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            alert(err.message || 'Unable to issue item to rider.');
        }
    });

    const starRatingInput = root.querySelector('#star-rating-input');
    const rateRiderValue = root.querySelector('#rate-rider-value');
    starRatingInput?.querySelectorAll('i').forEach((star) => {
        star.addEventListener('click', function () {
            const value = parseInt(this.dataset.star, 10);
            if (rateRiderValue) rateRiderValue.value = String(value);
            starRatingInput.querySelectorAll('i').forEach((s) => {
                s.classList.toggle('star-empty', parseInt(s.dataset.star, 10) > value);
            });
        });
    });

    const rateRiderForm = root.querySelector('#rate-rider-form');
    rateRiderForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const errorBox = root.querySelector('#rate-rider-error');
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalHtml = submitBtn.innerHTML;

        if (errorBox) errorBox.classList.add('d-none');

        if (!rateRiderValue || Number(rateRiderValue.value) < 1) {
            if (errorBox) {
                errorBox.textContent = I18N.ratingStarRequired;
                errorBox.classList.remove('d-none');
            }
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        try {
            const formData = new FormData(this);
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('<?= e(url_path('bookings/ajax_submit_rating.php')) ?>', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to submit rating.');
            }

            ajaxLoadWorkspace(`<?= e(url_path('bookings/')) ?>?booking_id=${selectedBookingId}`, false);
        } catch (err) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
            if (errorBox) {
                errorBox.textContent = err.message || 'Unable to submit rating.';
                errorBox.classList.remove('d-none');
            }
        }
    });

    const reportProblemForm = root.querySelector('#report-problem-form');
    reportProblemForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const errorBox = root.querySelector('#report-problem-error');
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalHtml = submitBtn.innerHTML;

        if (errorBox) errorBox.classList.add('d-none');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        try {
            const formData = new FormData(this);
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('<?= e(url_path('bookings/ajax_submit_complaint.php')) ?>', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to submit report.');
            }

            const modalEl = root.querySelector('#reportProblemModal');
            const modalInstance = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
            if (modalInstance) modalInstance.hide();
            alert(result.message || 'Your report has been submitted.');
            this.reset();
        } catch (err) {
            if (errorBox) {
                errorBox.textContent = err.message || 'Unable to submit report.';
                errorBox.classList.remove('d-none');
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    });

    const editDetailsForm = root.querySelector('#edit-details-form');
    editDetailsForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const errorBox = root.querySelector('#edit-details-error');
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalHtml = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
        errorBox?.classList.add('d-none');

        try {
            const formData = new FormData(this);
            formData.append('csrf_token', CSRF_TOKEN);
            const response = await fetch('<?= e(url_path('bookings/ajax_update_details.php')) ?>', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to update booking details.');
            }
            window.location.reload();
        } catch (err) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
            if (errorBox) {
                errorBox.textContent = err.message || 'Unable to update booking details.';
                errorBox.classList.remove('d-none');
            }
        }
    });

    const changeDeliveryForm = root.querySelector('#change-delivery-form');
    if (changeDeliveryForm) {
        const editDeliveryAddress = root.querySelector('#edit_delivery_address');
        const editDeliveryLat = root.querySelector('#edit_delivery_latitude');
        const editDeliveryLng = root.querySelector('#edit_delivery_longitude');
        const editDeliverySuggestions = root.querySelector('#edit_delivery_suggestions');
        const useCurrentEditDeliveryBtn = root.querySelector('#use_current_edit_delivery');

        function renderEditDeliverySuggestions(items, onPick) {
            if (!items.length) {
                editDeliverySuggestions.innerHTML = '<div class="address-suggestion-empty">' + I18N.noMatchingAddress + '</div>';
                editDeliverySuggestions.classList.add('show');
                return;
            }
            editDeliverySuggestions.innerHTML = items.map((item, idx) => `
                <div class="address-suggestion-item" data-index="${idx}">
                    <i class="fa-solid fa-location-dot"></i>
                    <div>
                        <div class="main-text">${item.text}</div>
                        <div class="sub-text">${item.place_name}</div>
                    </div>
                </div>
            `).join('');
            editDeliverySuggestions.classList.add('show');
            editDeliverySuggestions.querySelectorAll('.address-suggestion-item').forEach((el, idx) => {
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    onPick(items[idx]);
                    editDeliverySuggestions.classList.remove('show');
                });
            });
        }

        if (editDeliveryAddress && editDeliverySuggestions) {
            let editDebounce = null;

            editDeliveryAddress.addEventListener('input', function () {
                if (editDeliveryLat) editDeliveryLat.value = '';
                if (editDeliveryLng) editDeliveryLng.value = '';
                const query = this.value.trim();
                if (editDebounce) clearTimeout(editDebounce);
                if (query.length < 3) {
                    editDeliverySuggestions.classList.remove('show');
                    return;
                }
                editDebounce = setTimeout(async () => {
                    try {
                        const url = await buildGeocodeSearchUrl(query);
                        const res = await fetch(url);
                        const data = await res.json();
                        const items = (data.features || []).map(f => ({ text: f.text, place_name: f.place_name, lat: f.center[1], lng: f.center[0] }));
                        renderEditDeliverySuggestions(items, (item) => {
                            editDeliveryAddress.value = item.place_name;
                            editDeliveryAddress.classList.add('location-confirmed');
                            if (editDeliveryLat) editDeliveryLat.value = item.lat;
                            if (editDeliveryLng) editDeliveryLng.value = item.lng;
                        });
                    } catch (err) {
                        editDeliverySuggestions.classList.remove('show');
                    }
                }, 300);
            });

            editDeliveryAddress.addEventListener('blur', function () {
                setTimeout(() => editDeliverySuggestions.classList.remove('show'), 150);
            });
        }

        if (useCurrentEditDeliveryBtn) {
            useCurrentEditDeliveryBtn.addEventListener('click', function () {
                if (!navigator.geolocation) {
                    alert('Geolocation not supported');
                    return;
                }
                const originalHtml = useCurrentEditDeliveryBtn.innerHTML;
                useCurrentEditDeliveryBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                useCurrentEditDeliveryBtn.disabled = true;

                navigator.geolocation.getCurrentPosition(
                    async (pos) => {
                        const { latitude, longitude } = pos.coords;
                        if (editDeliveryAddress) editDeliveryAddress.value = I18N.locatingAddress;
                        try {
                            const res = await fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${longitude},${latitude}.json?access_token=${MAPBOX_TOKEN}&country=ng&language=en`);
                            const data = await res.json();
                            const place = data.features && data.features[0];
                            if (editDeliveryAddress) {
                                editDeliveryAddress.value = place ? place.place_name : `${latitude.toFixed(4)}, ${longitude.toFixed(4)}`;
                                editDeliveryAddress.classList.add('location-confirmed');
                            }
                        } catch (err) {
                            if (editDeliveryAddress) editDeliveryAddress.value = `${latitude.toFixed(4)}, ${longitude.toFixed(4)}`;
                        }
                        if (editDeliveryLat) editDeliveryLat.value = latitude;
                        if (editDeliveryLng) editDeliveryLng.value = longitude;
                        useCurrentEditDeliveryBtn.innerHTML = originalHtml;
                        useCurrentEditDeliveryBtn.disabled = false;
                    },
                    (err) => {
                        alert(`Error (${err.code}): ${err.message}. Ensure HTTPS is enabled.`);
                        useCurrentEditDeliveryBtn.innerHTML = originalHtml;
                        useCurrentEditDeliveryBtn.disabled = false;
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            });
        }

        changeDeliveryForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const errorBox = root.querySelector('#change-delivery-error');
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalHtml = submitBtn.innerHTML;

            if (!editDeliveryLat?.value || !editDeliveryLng?.value) {
                if (errorBox) {
                    errorBox.textContent = I18N.addressPickFromSuggestions;
                    errorBox.classList.remove('d-none');
                }
                return;
            }
            errorBox?.classList.add('d-none');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';

            try {
                const formData = new FormData(this);
                formData.append('csrf_token', CSRF_TOKEN);
                const response = await fetch('<?= e(url_path('bookings/ajax_update_delivery.php')) ?>', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to update delivery address.');
                }
                window.location.reload();
            } catch (err) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHtml;
                if (errorBox) {
                    errorBox.textContent = err.message || 'Unable to update delivery address.';
                    errorBox.classList.remove('d-none');
                }
            }
        });
    }

    if (canChat) {
        const openChatBtn = root.querySelector('#open-chat-btn');
        const chatUnreadBadge = root.querySelector('#chat-unread-badge');
        const closeChatBtn = root.querySelector('#close-chat-btn');
        const chatPanel = root.querySelector('#chat-panel');
        const chatMessages = root.querySelector('#chat-messages');
        const chatForm = root.querySelector('#chat-form');
        const chatBookingId = root.querySelector('#chat-booking-id')?.value;
        const chatReceiverId = root.querySelector('#chat-receiver-id')?.value || chatReceiverIdFromRoot;
        const chatMessageInput = root.querySelector('#chat-message-input');
        const voiceBtn = root.querySelector('#chat-voice-btn');
        const voiceBtnLabel = root.querySelector('.voice-btn-label');
        const callBtn = root.querySelector('#chat-call-btn');
        const callPanel = root.querySelector('#call-panel');
        const callStatusText = root.querySelector('#call-status-text');
        const callTimerEl = root.querySelector('#call-timer');
        const acceptCallBtn = root.querySelector('#accept-call-btn');
        const endCallBtn = root.querySelector('#end-call-btn');
        const remoteAudio = root.querySelector('#remote-audio');
        const presenceDot = root.querySelector('#chat-presence-dot');
        const presenceLabel = root.querySelector('#chat-presence-label');
        const phoneCallLink = root.querySelector('.chat-header-actions a[href^="tel:"]');
        const currentUserId = <?= (int)$user['id'] ?>;
        const realtimeBaseUrl = '<?= e(url_path('bookings/')) ?>';
        let counterpartOnline = false;

        function escapeHtml(str) {
            return String(str)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

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

            const fragments = (messages || []).map(msg => `
                <div class="chat-bubble ${msg.is_me ? 'me' : 'them'}" data-message-id="${Number(msg.id || 0)}">
                    ${renderChatContent(msg.message)}
                    <span class="chat-time">${escapeHtml(msg.created_at_formatted || msg.created_at || '')}</span>
                    ${msg.is_me ? `<span class="chat-status">${buildStatusTicks(msg)}</span>` : ''}
                </div>
            `).join('');

            if (replaceAll) {
                chatMessages.innerHTML = fragments;
            } else if (fragments) {
                const placeholder = chatMessages.querySelector('.text-soft.small.text-center.py-4');
                if (placeholder) placeholder.remove();
                chatMessages.insertAdjacentHTML('beforeend', fragments);
            }

            chatMessages.scrollTop = chatMessages.scrollHeight;
            chatHasRenderedOnce = true;
        }

        async function fetchChatMessages(forceFull = false, markRead = true) {
            if (!chatBookingId || !chatMessages || document.hidden) return;

            try {
                const sinceId = forceFull ? 0 : chatLastMessageId;
                const response = await fetch(`<?= e(url_path('chat/ajax_fetch_messages.php')) ?>?booking_id=${encodeURIComponent(chatBookingId)}&since_id=${encodeURIComponent(sinceId)}&limit=50&mark_read=${markRead ? 1 : 0}`, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });

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
            if (workspaceState.peerReadyPromise) return workspaceState.peerReadyPromise;
            if (!chatBookingId) return Promise.resolve(null);
            workspaceState.peerReadyPromise = new Promise((resolve) => {
                const peer = new Peer(peerIdFor(currentUserId), { config: PEER_ICE_CONFIG });
                workspaceState.peer = peer;
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
                    workspaceState.peerReadyPromise = null;
                    resolve(null);
                });
            });
            return workspaceState.peerReadyPromise;
        }

        async function ensureLocalAudioStream() {
            if (workspaceState.localCallStream) return workspaceState.localCallStream;
            workspaceState.localCallStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
            return workspaceState.localCallStream;
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
            workspaceState.currentCall = call;
            manualHangup = false;
            if (callPanelHideTimer) { clearTimeout(callPanelHideTimer); callPanelHideTimer = null; }
            if (endCallBtn) endCallBtn.style.display = '';
            let connected = false;
            const noAnswerTimer = setTimeout(() => {
                if (!connected && workspaceState.currentCall === call) {
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
                    const state = call.peerConnection.iceConnectionState;
                    console.log('Call ICE connection state:', state);
                    if (state === 'disconnected' || state === 'failed') {
                        logActiveCandidatePair(call, state);
                        // Chrome briefly reports "disconnected" during normal renegotiation/NAT
                        // rebinding too - try an ICE restart before assuming the path is dead.
                        if (typeof call.peerConnection.restartIce === 'function') {
                            try { call.peerConnection.restartIce(); } catch (e) { /* not supported mid-call by this browser */ }
                        }
                    }
                    if (state === 'failed' && callStatusText) {
                        callStatusText.textContent = I18N.callConnectionFailed;
                    }
                });
            }
            call.on('close', function () {
                clearTimeout(noAnswerTimer);
                pendingIncomingCall = null;
                workspaceState.currentCall = null;

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
                    if (callStatusText) callStatusText.textContent = I18N.callRiderOfflinePhone;
                    phoneCallLink.click();
                } else if (callStatusText) {
                    callStatusText.textContent = I18N.callRiderOfflineNoPhone;
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
                if (workspaceState.currentCall) {
                    workspaceState.currentCall.close();
                }
                if (workspaceState.localCallStream) {
                    workspaceState.localCallStream.getTracks().forEach(track => track.stop());
                    workspaceState.localCallStream = null;
                }
                workspaceState.currentCall = null;
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
                alert(err.message || I18N.recordVoiceFailed);
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
        if (!workspaceState.callPollInterval) {
            workspaceState.callPollInterval = setInterval(() => pollCallState(), 4000);
        }
        if (!workspaceState.presenceInterval) {
            workspaceState.presenceInterval = setInterval(() => { pingPresence(); checkPresence(); }, 8000);
        }
        if (!workspaceState.chatInterval) {
            workspaceState.chatInterval = setInterval(() => fetchChatMessages(false, isChatPanelOpen()), 8000);
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
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
            try {
                const response = await fetch('<?= e(url_path('chat/ajax_send_message.php')) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ booking_id: chatBookingId, receiver_user_id: chatReceiverId, message: message, csrf_token: CSRF_TOKEN })
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || I18N.messageSendFailed);
                }
                chatMessageInput.value = '';
                await fetchChatMessages(true);
            } catch (err) {
                alert(err.message || I18N.messageSendFailed);
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHtml;
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    initSenderWorkspace();

    window.addEventListener('popstate', function () {
        ajaxLoadWorkspace(window.location.href, false);
    });
});

// Each page load registers a PeerJS connection under a deterministic id
// (booking-<id>-user-<id>). Leaving it dangling on the signaling server when the page
// unloads/reloads means the next load's registration can collide with it (rejected as
// "unavailable-id") until the stale one times out - starving calls right when they're needed.
// Destroying it here frees the id immediately instead of waiting on the server's timeout.
window.addEventListener('pagehide', function () {
    if (workspaceState.peer && !workspaceState.peer.destroyed) {
        workspaceState.peer.destroy();
    }
});
</script>
</body>
</html>