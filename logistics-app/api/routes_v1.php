<?php
// Aike /api/v1 handlers (lifecycle core). Included by api/index.php. Every handler delegates
// trusted rules to the same config/*.php helpers the web endpoints use and mirrors their exact
// authorisation/validation (rider transition map, cancel rules, KYC gate, location bounds, etc.)
// — the source of truth stays the backend. No secrets are exposed and no pricing/eligibility is
// computed on the client.

// ---- Auth: register -------------------------------------------------------------------------

function api_register(PDO $pdo): void {
    $b = api_body();
    $fullName = trim((string) ($b['fullName'] ?? ''));
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    $phone = trim((string) ($b['phone'] ?? ''));
    $password = (string) ($b['password'] ?? '');
    $role = (string) ($b['role'] ?? 'sender');
    $vehicleType = (string) ($b['vehicleType'] ?? '');

    $fields = [];
    if ($fullName === '') { $fields['fullName'] = 'Required'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $fields['email'] = 'A valid email is required'; }
    if ($phone === '') { $fields['phone'] = 'Required'; }
    if (strlen($password) < 8) { $fields['password'] = 'At least 8 characters'; }
    if (!in_array($role, ['sender', 'rider'], true)) { $fields['role'] = 'sender or rider'; }
    if ($role === 'rider' && !in_array($vehicleType, ['bike', 'car', 'van'], true)) {
        $fields['vehicleType'] = 'bike, car or van';
    }
    if ($fields) {
        api_fail(400, 'VALIDATION', 'Please check the form and try again.', $fields);
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        api_fail(409, 'EMAIL_TAKEN', 'An account with this email already exists. Please sign in instead.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role, status, profile_completed)
             VALUES (?, ?, ?, ?, ?, "active", 1)'
        );
        $stmt->execute([$fullName, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $role]);
        $userId = (int) $pdo->lastInsertId();
        if ($role === 'rider') {
            // Riders start with a profile pending KYC approval (cannot go online until approved),
            // exactly like the web registration flow.
            $pdo->prepare('INSERT INTO rider_profiles (user_id, vehicle_type, availability_status, kyc_status) VALUES (?, ?, "offline", "pending")')
                ->execute([$userId, $vehicleType]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('api register failed: ' . $e->getMessage());
        api_fail(503, 'REGISTER_FAILED', 'We could not create your account right now. Please try again.');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $platform = isset($b['platform']) ? substr((string) $b['platform'], 0, 20) : null;
    $tokens = api_issue_tokens($pdo, $userId, $platform, isset($b['deviceLabel']) ? substr((string) $b['deviceLabel'], 0, 120) : null);
    api_ok([
        'accessToken' => $tokens['accessToken'],
        'refreshToken' => $tokens['refreshToken'],
        'expiresInSeconds' => $tokens['expiresInSeconds'],
        'user' => api_user_public($u),
    ], [], 201);
}

// ---- Geo: route (backend Mapbox; secret token never leaves the server) -----------------------

function api_geo_route(PDO $pdo): void {
    api_require($pdo); // any authenticated user
    $b = api_body();
    $coords = api_valid_coords($b['pickup'] ?? null, $b['dropoff'] ?? null);
    if ($coords === null) {
        api_fail(400, 'VALIDATION', 'Valid pickup and drop-off coordinates are required.');
    }
    [$plat, $plng, $dlat, $dlng] = $coords;
    try {
        $m = cached_route_metrics($pdo, $plat, $plng, $dlat, $dlng);
    } catch (Throwable $e) {
        api_fail(422, 'NO_ROUTE', 'We could not calculate a route for those locations.');
    }
    api_ok([
        'distanceKm' => round((float) $m['distance_km'], 2),
        'durationMinutes' => (int) round((float) ($m['duration_min'] ?? 0)),
    ]);
}

// ---- Sender: bookings -----------------------------------------------------------------------

function api_booking_create(PDO $pdo): void {
    $user = api_require($pdo, ['sender']);
    api_idempotency_replay($pdo, (int) $user['id'], 'POST bookings');

    $b = api_body();
    $fields = [];
    $recipientName = trim((string) ($b['recipientName'] ?? ''));
    $recipientPhone = trim((string) ($b['recipientPhone'] ?? ''));
    $vehicleType = (string) ($b['vehicleType'] ?? '');
    $pickup = $b['pickup'] ?? [];
    $dropoff = $b['dropoff'] ?? [];
    $itemName = trim((string) ($b['itemName'] ?? ''));
    $itemCategory = trim((string) ($b['itemCategory'] ?? 'general'));

    if ($recipientName === '') { $fields['recipientName'] = 'Required'; }
    if ($recipientPhone === '') { $fields['recipientPhone'] = 'Required'; }
    if ($itemName === '') { $fields['itemName'] = 'Required'; }
    if (!in_array($vehicleType, ['bike', 'car', 'van'], true)) { $fields['vehicleType'] = 'bike, car or van'; }
    $coords = api_valid_coords($pickup, $dropoff);
    if ($coords === null) { $fields['coordinates'] = 'Valid pickup and drop-off coordinates required'; }
    if (trim((string) ($pickup['address'] ?? '')) === '') { $fields['pickup.address'] = 'Required'; }
    if (trim((string) ($dropoff['address'] ?? '')) === '') { $fields['dropoff.address'] = 'Required'; }
    if ($fields) {
        api_fail(400, 'VALIDATION', 'Please complete the delivery details.', $fields);
    }
    [$plat, $plng, $dlat, $dlng] = $coords;

    // Price is computed by the backend (never trusts a client price), same rule as the web
    // create flow: an unroutable pair blocks; a transient failure creates the booking unpriced.
    $agreedCost = null;
    $plannedMinutes = null;
    $pricingPending = false;
    try {
        $m = cached_route_metrics($pdo, $plat, $plng, $dlat, $dlng);
        $agreedCost = (float) calculate_delivery_price($pdo, (float) $m['distance_km'], $vehicleType)['total'];
        $plannedMinutes = (int) round((float) ($m['duration_min'] ?? 0));
    } catch (NoRouteFoundException $e) {
        api_fail(422, 'NO_ROUTE', 'No route could be found between these locations. Please check the addresses.');
    } catch (Throwable $e) {
        $pricingPending = true; // our problem, not the sender's — create anyway, admins notified
    }

    $code = 'BK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare(
        'INSERT INTO bookings
            (sender_user_id, booking_code, recipient_name, recipient_phone,
             pickup_address, pickup_latitude, pickup_longitude,
             delivery_address, delivery_latitude, delivery_longitude,
             item_name, item_category, item_description, special_instructions,
             booking_status, sender_tracking_token, vehicle_type, agreed_cost, planned_duration_minutes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "submitted", ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user['id'], $code, $recipientName, $recipientPhone,
        trim((string) $pickup['address']), $plat, $plng,
        trim((string) $dropoff['address']), $dlat, $dlng,
        $itemName, $itemCategory, trim((string) ($b['itemDescription'] ?? '')), trim((string) ($b['notes'] ?? '')),
        bin2hex(random_bytes(16)), $vehicleType, $agreedCost, $plannedMinutes,
    ]);
    $id = (int) $pdo->lastInsertId();
    log_event($pdo, 'booking_created', 'Booking ' . $code . ' submitted (mobile)', (int) $user['id'], (string) $user['role'], 'booking', $id);

    if ($pricingPending) {
        notify_admins($pdo, 'Booking needs manual pricing - ' . $code,
            '<p>Booking <strong>' . e($code) . '</strong> was submitted from mobile but automatic pricing failed. Please assign/price from the admin bookings page.</p>');
    }

    $booking = api_fetch_booking($pdo, $id);
    $env = ['ok' => true, 'data' => ['booking' => api_booking_public($booking), 'pricingPending' => $pricingPending],
            'error' => null, 'meta' => ['requestId' => bin2hex(random_bytes(8))]];
    $body = json_encode($env);
    api_idempotency_store($pdo, (int) $user['id'], 'POST bookings', 201, $body);
    if (!headers_sent()) {
        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo $body;
    exit;
}

function api_booking_get(PDO $pdo, int $id): void {
    $user = api_require($pdo, ['sender']);
    $booking = api_fetch_booking($pdo, $id);
    // IDOR guard: a sender may only read their own booking.
    if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    api_ok(['booking' => api_booking_public($booking)]);
}

function api_booking_cancel(PDO $pdo, int $id): void {
    $user = api_require($pdo, ['sender']);
    $reason = trim((string) (api_body()['reason'] ?? ''));
    if ($reason === '') {
        api_fail(400, 'VALIDATION', 'A cancellation reason is required.', ['reason' => 'Required']);
    }
    $stmt = $pdo->prepare('SELECT id, sender_user_id, booking_status, payment_status, sender_handover_confirmed FROM bookings WHERE id = ? AND sender_user_id = ? LIMIT 1');
    $stmt->execute([$id, $user['id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    // Same rules as bookings/ajax_cancel_booking.php.
    if (($booking['payment_status'] ?? 'unpaid') === 'paid') {
        api_fail(422, 'CANNOT_CANCEL', 'A paid booking cannot be cancelled.');
    }
    if ((int) ($booking['sender_handover_confirmed'] ?? 0) === 1) {
        api_fail(422, 'CANNOT_CANCEL', 'This booking cannot be cancelled once the item has been handed to the rider.');
    }
    if (!in_array(($booking['booking_status'] ?? ''), ['matched', 'accepted', 'arrived_at_pickup'], true)) {
        api_fail(422, 'CANNOT_CANCEL', 'This booking cannot be cancelled at its current stage.');
    }
    $pdo->prepare("UPDATE bookings SET booking_status = 'cancelled', cancellation_reason = ?, cancelled_by = 'sender' WHERE id = ?")
        ->execute([$reason, $id]);
    log_event($pdo, 'booking_cancelled', 'Booking #' . $id . ' cancelled by sender (mobile)', (int) $user['id'], (string) $user['role'], 'booking', $id, ['reason' => $reason]);
    api_ok(['booking' => api_booking_public(api_fetch_booking($pdo, $id))]);
}

function api_booking_update(PDO $pdo, int $id): void {
    // Edit booking details and/or change the delivery address (with a backend reprice). Mirrors
    // bookings/ajax_update_details.php + ajax_update_delivery.php: only editable before a rider has
    // accepted (draft/submitted/matched), and a repriced address is only recomputed when a rider is
    // already selected AND the new destination is farther — a closer destination keeps the agreed
    // price. Price is always a fresh absolute fare from the shared engine (never a multiplier).
    $user = api_require($pdo, ['sender']);
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND sender_user_id = ? LIMIT 1');
    $stmt->execute([$id, $user['id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    if (!in_array($booking['booking_status'], ['draft', 'submitted', 'matched'], true)) {
        api_fail(422, 'NOT_EDITABLE', 'This booking can no longer be edited.');
    }

    $b = api_body();
    $sets = [];
    $params = [];

    // Detail fields (all optional; only provided keys are updated).
    $detailMap = [
        'recipientName' => ['recipient_name', true],
        'recipientPhone' => ['recipient_phone', true],
        'itemName' => ['item_name', true],
        'itemCategory' => ['item_category', false],
        'itemDescription' => ['item_description', false],
        'notes' => ['special_instructions', false],
    ];
    foreach ($detailMap as $key => [$col, $requiredNonEmpty]) {
        if (array_key_exists($key, $b)) {
            $val = trim((string) $b[$key]);
            if ($requiredNonEmpty && $val === '') {
                api_fail(400, 'VALIDATION', 'This field cannot be empty.', [$key => 'Required']);
            }
            $sets[] = "$col = ?";
            $params[] = $val;
        }
    }

    // Delivery address change (+ conditional reprice).
    $priceChanged = false;
    if (isset($b['dropoff']) && is_array($b['dropoff'])) {
        $d = $b['dropoff'];
        $addr = trim((string) ($d['address'] ?? ''));
        $lat = isset($d['lat']) && is_numeric($d['lat']) ? (float) $d['lat'] : null;
        $lng = isset($d['lng']) && is_numeric($d['lng']) ? (float) $d['lng'] : null;
        if ($addr === '' || $lat === null || $lng === null
            || $lat < 3 || $lat > 15 || $lng < 2 || $lng > 15) {
            api_fail(400, 'VALIDATION', 'A valid delivery address is required.');
        }
        $sets[] = 'delivery_address = ?';
        $params[] = $addr;
        $sets[] = 'delivery_latitude = ?';
        $params[] = $lat;
        $sets[] = 'delivery_longitude = ?';
        $params[] = $lng;

        if (!empty($booking['selected_rider_user_id']) && $booking['pickup_latitude'] !== null && $booking['pickup_longitude'] !== null) {
            $plat = (float) $booking['pickup_latitude'];
            $plng = (float) $booking['pickup_longitude'];
            try {
                $newDistance = (float) cached_route_metrics($pdo, $plat, $plng, $lat, $lng)['distance_km'];
                $oldDistance = null;
                if ($booking['delivery_latitude'] !== null && $booking['delivery_longitude'] !== null) {
                    $oldDistance = (float) cached_route_metrics($pdo, $plat, $plng, (float) $booking['delivery_latitude'], (float) $booking['delivery_longitude'])['distance_km'];
                }
            } catch (NoRouteFoundException $e) {
                api_fail(422, 'NO_ROUTE', 'No route could be found between these locations. Please check the delivery address.');
            } catch (Throwable $e) {
                api_fail(503, 'ROUTE_UNAVAILABLE', 'Unable to calculate route distance right now. Please try again shortly.');
            }
            $newAgreed = $booking['agreed_cost'];
            if ($booking['agreed_cost'] === null || $oldDistance === null || $newDistance > $oldDistance) {
                $vt = (string) ($booking['vehicle_type'] ?? 'bike');
                $newAgreed = (float) calculate_delivery_price($pdo, $newDistance, $vt)['total'];
            }
            $priceChanged = (float) $newAgreed !== (float) $booking['agreed_cost'];
            $sets[] = 'agreed_cost = ?';
            $params[] = $newAgreed;
        }
    }

    if (!$sets) {
        api_fail(400, 'VALIDATION', 'Nothing to update.');
    }
    $params[] = $id;
    $pdo->prepare('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    log_event($pdo, 'booking_updated', 'Booking #' . $id . ' edited by sender (mobile)', (int) $user['id'], (string) $user['role'], 'booking', $id);
    api_ok(['booking' => api_booking_public(api_fetch_booking($pdo, $id)), 'priceChanged' => $priceChanged]);
}

function api_booking_rebook(PDO $pdo, int $id): void {
    // Reopen a cancelled booking for rider matching. Mirrors bookings/ajax_rebook.php: only a
    // cancelled booking can be rebooked; it returns to 'submitted' with the rider cleared.
    $user = api_require($pdo, ['sender']);
    $stmt = $pdo->prepare('SELECT id, booking_status FROM bookings WHERE id = ? AND sender_user_id = ? LIMIT 1');
    $stmt->execute([$id, $user['id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    if (($booking['booking_status'] ?? '') !== 'cancelled') {
        api_fail(422, 'NOT_CANCELLED', 'Only cancelled bookings can be rebooked.');
    }
    $pdo->prepare("UPDATE bookings
                   SET booking_status = 'submitted', selected_rider_user_id = NULL,
                       cancellation_reason = NULL, cancelled_by = NULL,
                       sender_handover_confirmed = 0, sender_handover_confirmed_at = NULL
                   WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE rider_requests SET request_status = 'rejected' WHERE booking_id = ? AND request_status = 'accepted'")->execute([$id]);
    log_event($pdo, 'booking_rebooked', 'Booking #' . $id . ' reopened for matching (mobile)', (int) $user['id'], (string) $user['role'], 'booking', $id);
    api_ok(['booking' => api_booking_public(api_fetch_booking($pdo, $id))]);
}

function api_payments_list(PDO $pdo): void {
    // Sender's payment receipts: every booking they've paid for. There is no separate payments
    // table for sender charges — a paid booking carries its Paystack reference and amount.
    $user = api_require($pdo, ['sender']);
    $stmt = $pdo->prepare("SELECT id, booking_code, agreed_cost, paystack_reference, updated_at
                           FROM bookings WHERE sender_user_id = ? AND payment_status = 'paid'
                           ORDER BY updated_at DESC LIMIT 50");
    $stmt->execute([$user['id']]);
    $items = array_map(static fn(array $r): array => [
        'bookingId' => (int) $r['id'],
        'bookingCode' => (string) $r['booking_code'],
        'amount' => $r['agreed_cost'] !== null ? (float) $r['agreed_cost'] : null,
        'reference' => $r['paystack_reference'] !== null ? (string) $r['paystack_reference'] : null,
        'paidAt' => (string) $r['updated_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    api_ok(['payments' => $items]);
}

function api_booking_contact(PDO $pdo, int $id): void {
    // Returns the counterpart's phone so the app can open the DEVICE DIALLER. In-app calling is
    // deliberately not offered on mobile (no WebRTC infra there — the web PeerJS path does not
    // port), so the reliable path is a normal phone call. Only the two parties on the booking can
    // read it: a sender gets the assigned rider's number, an assigned rider gets the sender's.
    $user = api_require($pdo, ['sender', 'rider']);
    $stmt = $pdo->prepare('SELECT sender_user_id, selected_rider_user_id FROM bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    $isSender = (int) $booking['sender_user_id'] === (int) $user['id'];
    $isRider = (int) ($booking['selected_rider_user_id'] ?? 0) === (int) $user['id'];
    if (!$isSender && !$isRider) {
        api_fail(403, 'FORBIDDEN', 'You are not part of this delivery.');
    }
    $counterpartId = $isSender ? (int) ($booking['selected_rider_user_id'] ?? 0) : (int) $booking['sender_user_id'];
    if ($counterpartId <= 0) {
        api_fail(409, 'NO_COUNTERPART', 'No rider has been assigned to this delivery yet.');
    }
    $stmt = $pdo->prepare('SELECT full_name, phone FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$counterpartId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || empty($u['phone'])) {
        api_fail(409, 'NO_PHONE', 'A phone number is not available for this contact.');
    }
    api_ok([
        'role' => $isSender ? 'rider' : 'sender',
        'fullName' => (string) $u['full_name'],
        'phone' => (string) $u['phone'], // dial via the device dialler; in-app calling not offered
        'canCallInApp' => false,
    ]);
}

// ---- Chat (booking_chat_messages) -----------------------------------------------------------

/** Authorise the caller for a booking's chat and return [bookingRow, counterpartUserId]. */
function api_chat_authorize(PDO $pdo, int $bookingId, array $user): array {
    $stmt = $pdo->prepare('SELECT id, sender_user_id, selected_rider_user_id FROM bookings
                           WHERE id = ? AND (sender_user_id = ? OR selected_rider_user_id = ?) LIMIT 1');
    $stmt->execute([$bookingId, $user['id'], $user['id']]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        api_fail(403, 'FORBIDDEN', 'You are not part of this delivery chat.');
    }
    $isSender = (int) $b['sender_user_id'] === (int) $user['id'];
    $counterpart = $isSender ? (int) ($b['selected_rider_user_id'] ?? 0) : (int) $b['sender_user_id'];
    return [$b, $counterpart];
}

function api_messages_list(PDO $pdo, int $bookingId): void {
    // Chat history for the booking's two parties. Marks the caller's incoming messages read (so the
    // other side sees the read tick), then returns messages after `since`. Mirrors the auth + read
    // semantics of chat/ajax_fetch_messages.php. delivered_at/read_at drive the sent/read ticks.
    $user = api_require($pdo, ['sender', 'rider']);
    api_chat_authorize($pdo, $bookingId, $user);
    if (!db_table_exists($pdo, 'booking_chat_messages')) {
        api_ok(['messages' => [], 'lastId' => 0]);
    }
    $sinceId = isset($_GET['since']) ? max(0, (int) $_GET['since']) : 0;

    $pdo->prepare('UPDATE booking_chat_messages SET is_read = 1, read_at = NOW()
                   WHERE booking_id = ? AND receiver_user_id = ? AND is_read = 0')
        ->execute([$bookingId, $user['id']]);

    $sql = 'SELECT id, sender_user_id, message, delivered_at, read_at, created_at
            FROM booking_chat_messages WHERE booking_id = ?'
        . ($sinceId > 0 ? ' AND id > ?' : '') . ' ORDER BY id ASC LIMIT 100';
    $params = [$bookingId];
    if ($sinceId > 0) { $params[] = $sinceId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = array_map(static fn(array $m): array => [
        'id' => (int) $m['id'],
        'mine' => (int) $m['sender_user_id'] === (int) $user['id'],
        'message' => (string) $m['message'],
        'deliveredAt' => $m['delivered_at'] !== null ? (string) $m['delivered_at'] : null,
        'readAt' => $m['read_at'] !== null ? (string) $m['read_at'] : null,
        'createdAt' => (string) $m['created_at'],
    ], $rows);
    $lastId = $rows ? (int) $rows[count($rows) - 1]['id'] : $sinceId;
    api_ok(['messages' => $items, 'lastId' => $lastId]);
}

function api_messages_send(PDO $pdo, int $bookingId): void {
    // Send a chat message. The receiver is derived server-side (the booking's other party), so the
    // client can't target anyone else. Mirrors chat/ajax_send_message.php. Notifies the counterpart.
    $user = api_require($pdo, ['sender', 'rider']);
    [, $counterpart] = api_chat_authorize($pdo, $bookingId, $user);
    if (!db_table_exists($pdo, 'booking_chat_messages')) {
        api_fail(503, 'CHAT_UNAVAILABLE', 'Chat is temporarily unavailable.');
    }
    $message = trim((string) (api_body()['message'] ?? ''));
    if ($message === '') {
        api_fail(400, 'VALIDATION', 'Enter a message.', ['message' => 'Required']);
    }
    if (mb_strlen($message) > 2000) {
        $message = mb_substr($message, 0, 2000);
    }
    if ($counterpart <= 0) {
        api_fail(409, 'NO_COUNTERPART', 'No rider is assigned to this delivery yet.');
    }
    $pdo->prepare('INSERT INTO booking_chat_messages (booking_id, sender_user_id, receiver_user_id, message, delivered_at)
                   VALUES (?, ?, ?, ?, NOW())')
        ->execute([$bookingId, $user['id'], $counterpart, $message]);
    $id = (int) $pdo->lastInsertId();
    if (function_exists('send_web_push')) {
        try { send_web_push($pdo, $counterpart, (string) $user['full_name'], $message, url_path('bookings/index.php?booking_id=' . $bookingId)); } catch (Throwable $e) {}
    }
    $stmt = $pdo->prepare('SELECT delivered_at, created_at FROM booking_chat_messages WHERE id = ?');
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    api_ok(['message' => [
        'id' => $id,
        'mine' => true,
        'message' => $message,
        'deliveredAt' => isset($m['delivered_at']) ? (string) $m['delivered_at'] : null,
        'readAt' => null,
        'createdAt' => isset($m['created_at']) ? (string) $m['created_at'] : null,
    ]], [], 201);
}

function api_booking_request_rider(PDO $pdo, int $id): void {
    // Sender sends a delivery request to a chosen rider. Mirrors bookings/send_request.php:
    // row-locked booking, capacity cap, no duplicate pending, other pending requests rejected.
    $user = api_require($pdo, ['sender']);
    $b = api_body();
    $riderUserId = (int) ($b['riderUserId'] ?? 0);
    $proposedCost = (float) ($b['proposedCost'] ?? 0);
    if ($riderUserId <= 0) {
        api_fail(400, 'VALIDATION', 'Choose a rider.');
    }
    if ($proposedCost <= 0) {
        api_fail(400, 'VALIDATION', 'A valid fee is required.', ['proposedCost' => 'Must be greater than zero']);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND sender_user_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id, $user['id']]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            $pdo->rollBack();
            api_fail(404, 'NOT_FOUND', 'Booking not found.');
        }
        $blocked = ['matched', 'accepted', 'arrived_at_pickup', 'package_received', 'in_transit', 'delivered', 'cancelled'];
        if (in_array(($booking['booking_status'] ?? ''), $blocked, true)) {
            $pdo->rollBack();
            api_fail(409, 'BOOKING_LOCKED', 'This booking can no longer receive rider requests.');
        }
        $stmt = $pdo->prepare('SELECT u.id, u.role FROM users u INNER JOIN rider_profiles rp ON rp.user_id = u.id WHERE u.id = ? LIMIT 1');
        $stmt->execute([$riderUserId]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rider || ($rider['role'] ?? '') !== 'rider') {
            $pdo->rollBack();
            api_fail(404, 'RIDER_NOT_FOUND', 'Selected rider was not found.');
        }
        if (rider_active_order_count($pdo, $riderUserId) >= RIDER_MAX_CONCURRENT_ORDERS) {
            $pdo->rollBack();
            api_fail(409, 'RIDER_AT_CAPACITY', 'That rider already has the maximum number of active deliveries.');
        }
        $stmt = $pdo->prepare("SELECT id FROM rider_requests WHERE booking_id = ? AND rider_user_id = ? AND request_status = 'pending' LIMIT 1");
        $stmt->execute([$id, $riderUserId]);
        if ($stmt->fetchColumn()) {
            $pdo->rollBack();
            api_fail(409, 'DUPLICATE_REQUEST', 'A pending request has already been sent to this rider.');
        }
        // Supersede any other pending requests on this booking, then create this one.
        $pdo->prepare("UPDATE rider_requests SET request_status = 'rejected' WHERE booking_id = ? AND request_status = 'pending' AND rider_user_id <> ?")
            ->execute([$id, $riderUserId]);
        $pdo->prepare("INSERT INTO rider_requests (booking_id, sender_user_id, rider_user_id, proposed_cost, request_status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())")
            ->execute([$id, (int) $booking['sender_user_id'], $riderUserId, $proposedCost]);
        $requestId = (int) $pdo->lastInsertId();
        $newStatus = ($booking['booking_status'] ?? 'submitted') === 'draft' ? 'submitted' : ($booking['booking_status'] ?? 'submitted');
        $pdo->prepare('UPDATE bookings SET agreed_cost = ?, booking_status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$proposedCost, $newStatus, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('api send_request failed: ' . $e->getMessage());
        api_fail(503, 'REQUEST_FAILED', 'Could not send the request right now. Please try again.');
    }

    if (function_exists('send_web_push')) {
        try { send_web_push($pdo, $riderUserId, 'New delivery request', 'You have a new delivery request for booking ' . ($booking['booking_code'] ?? '') . '.', url_path('rider/')); } catch (Throwable $e) {}
    }
    api_ok(['requestId' => $requestId, 'bookingId' => $id], [], 201);
}

function api_booking_track(PDO $pdo, int $id): void {
    $user = api_require($pdo, ['sender']);
    $booking = api_fetch_booking($pdo, $id);
    if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    $rider = null;
    if (!empty($booking['selected_rider_user_id'])) {
        $stmt = $pdo->prepare('SELECT u.full_name, rp.last_latitude, rp.last_longitude, rp.last_location_updated_at, rp.vehicle_type
                               FROM rider_profiles rp JOIN users u ON u.id = rp.user_id WHERE rp.user_id = ? LIMIT 1');
        $stmt->execute([$booking['selected_rider_user_id']]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $rider = [
                'fullName' => (string) $r['full_name'],
                'vehicleType' => $r['vehicle_type'] !== null ? (string) $r['vehicle_type'] : null,
                'lat' => $r['last_latitude'] !== null ? (float) $r['last_latitude'] : null,
                'lng' => $r['last_longitude'] !== null ? (float) $r['last_longitude'] : null,
                // Freshness so the client never presents a stale fix as live.
                'lastSeenSecondsAgo' => !empty($r['last_location_updated_at'])
                    ? max(0, time() - strtotime((string) $r['last_location_updated_at'])) : null,
            ];
        }
    }
    api_ok([
        'status' => (string) $booking['booking_status'],
        'paymentStatus' => (string) ($booking['payment_status'] ?? 'unpaid'),
        'rider' => $rider,
    ]);
}

// ---- Rider ----------------------------------------------------------------------------------

function api_rider_profile(PDO $pdo): void {
    $user = api_require($pdo, ['rider']);
    $stmt = $pdo->prepare('SELECT vehicle_type, rating, availability_status, kyc_status, last_location_updated_at FROM rider_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $rp = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    api_ok([
        'user' => api_user_public($user),
        'vehicleType' => $rp['vehicle_type'] ?? null,
        'rating' => isset($rp['rating']) && $rp['rating'] !== null ? (float) $rp['rating'] : null,
        'availabilityStatus' => $rp['availability_status'] ?? 'offline',
        'kycStatus' => $rp['kyc_status'] ?? 'pending',
    ]);
}

function api_rider_status(PDO $pdo): void {
    $user = api_require($pdo, ['rider']);
    $status = (string) (api_body()['status'] ?? '');
    if (!in_array($status, ['available', 'busy', 'offline'], true)) {
        api_fail(400, 'VALIDATION', 'Invalid status.', ['status' => 'available, busy or offline']);
    }
    // Going online (available) is gated on KYC approval — mirrors ajax_update_status.php.
    if ($status === 'available') {
        $stmt = $pdo->prepare('SELECT kyc_status FROM rider_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        if ($stmt->fetchColumn() !== 'approved') {
            api_fail(403, 'KYC_NOT_APPROVED', 'Your account is still being verified. You can go online once approved.');
        }
    }
    $pdo->prepare('UPDATE rider_profiles SET availability_status = ? WHERE user_id = ?')->execute([$status, $user['id']]);
    api_ok(['availabilityStatus' => $status]);
}

function api_rider_location(PDO $pdo): void {
    $user = api_require($pdo, ['rider']);
    $b = api_body();
    $lat = isset($b['lat']) ? (float) $b['lat'] : null;
    $lng = isset($b['lng']) ? (float) $b['lng'] : null;
    if ($lat === null || $lng === null) {
        api_fail(400, 'VALIDATION', 'lat and lng are required.');
    }
    // Reject implausible fixes (Nigeria bounds), same as ajax_update_location.php.
    if (!($lat >= 4.0 && $lat <= 14.0 && $lng >= 2.5 && $lng <= 15.0)) {
        api_fail(422, 'BAD_LOCATION', 'Location reading ignored: outside expected range.');
    }
    // Coalesce FIRST, then validate — otherwise an omitted status writes NULL (the same latent
    // bug exists in the web ajax_update_location.php; we avoid it here).
    $status = (string) ($b['status'] ?? 'available');
    if (!in_array($status, ['available', 'busy', 'offline'], true)) {
        $status = 'available';
    }

    // Dedup: write on status change, ≥55m movement, or ≥15s heartbeat (mirrors the web rule).
    $stmt = $pdo->prepare('SELECT last_latitude, last_longitude, last_location_updated_at, availability_status FROM rider_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $cur = $stmt->fetch(PDO::FETCH_ASSOC);
    $write = true;
    if ($cur) {
        $moved = true;
        if ($cur['last_latitude'] !== null && $cur['last_longitude'] !== null) {
            $moved = api_haversine_m((float) $cur['last_latitude'], (float) $cur['last_longitude'], $lat, $lng) >= 55;
        }
        $stale = empty($cur['last_location_updated_at']) || (time() - strtotime((string) $cur['last_location_updated_at'])) >= 15;
        $changed = ($cur['availability_status'] ?? '') !== $status;
        $write = $changed || $moved || $stale;
    }
    if ($write) {
        $pdo->prepare('UPDATE rider_profiles SET last_latitude = ?, last_longitude = ?, availability_status = ?, last_location_updated_at = NOW() WHERE user_id = ?')
            ->execute([$lat, $lng, $status, $user['id']]);
    }
    if (!headers_sent()) {
        http_response_code(204);
    }
    exit;
}

function api_rider_offers(PDO $pdo): void {
    $user = api_require($pdo, ['rider']);
    $stmt = $pdo->prepare(
        'SELECT rr.id AS request_id, rr.booking_id, rr.proposed_cost, rr.request_status,
                b.pickup_address, b.delivery_address, b.vehicle_type, b.item_name, b.booking_code
         FROM rider_requests rr JOIN bookings b ON b.id = rr.booking_id
         WHERE rr.rider_user_id = ? AND rr.request_status = "pending"
         ORDER BY rr.id DESC LIMIT 10'
    );
    $stmt->execute([$user['id']]);
    $offers = array_map(static function (array $o): array {
        return [
            'requestId' => (int) $o['request_id'],
            'bookingId' => (int) $o['booking_id'],
            'bookingCode' => (string) $o['booking_code'],
            'pickupAddress' => (string) $o['pickup_address'],
            'dropoffAddress' => (string) $o['delivery_address'],
            'vehicleType' => $o['vehicle_type'] !== null ? (string) $o['vehicle_type'] : null,
            'itemName' => (string) $o['item_name'],
            'proposedCost' => $o['proposed_cost'] !== null ? (float) $o['proposed_cost'] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    api_ok(['offers' => $offers]);
}

function api_rider_offer_respond(PDO $pdo, int $requestId, string $action): void {
    // Rider accepts or rejects a pending offer. Mirrors rider/index.php: capacity cap on accept,
    // accepting supersedes other pending requests on the booking and assigns the rider (status
    // submitted -> matched), rejecting just marks the request. Notifies the sender.
    $user = api_require($pdo, ['rider']);
    $stmt = $pdo->prepare(
        'SELECT rr.*, b.id AS booking_id, b.booking_code, b.booking_status, b.sender_user_id
         FROM rider_requests rr INNER JOIN bookings b ON b.id = rr.booking_id
         WHERE rr.id = ? AND rr.rider_user_id = ? LIMIT 1'
    );
    $stmt->execute([$requestId, $user['id']]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        api_fail(404, 'NOT_FOUND', 'Request not found.');
    }
    if (($req['request_status'] ?? '') !== 'pending') {
        api_fail(409, 'ALREADY_PROCESSED', 'This request has already been processed.');
    }
    if ($action === 'accepted' && rider_active_order_count($pdo, (int) $user['id'], (int) $req['booking_id']) >= RIDER_MAX_CONCURRENT_ORDERS) {
        api_fail(409, 'AT_CAPACITY', 'You already have the maximum number of active deliveries. Complete one first.');
    }
    try {
        $pdo->beginTransaction();
        if ($action === 'accepted') {
            $pdo->prepare('UPDATE rider_requests SET request_status = "accepted" WHERE id = ?')->execute([$requestId]);
            $pdo->prepare('UPDATE rider_requests SET request_status = "rejected" WHERE booking_id = ? AND id <> ? AND request_status = "pending"')
                ->execute([(int) $req['booking_id'], $requestId]);
            $pdo->prepare('UPDATE bookings
                    SET selected_rider_user_id = ?,
                        agreed_cost = CASE WHEN agreed_cost IS NULL OR agreed_cost = 0 THEN ? ELSE agreed_cost END,
                        booking_status = CASE WHEN booking_status = "submitted" THEN "matched" ELSE booking_status END,
                        matched_at = COALESCE(matched_at, NOW())
                    WHERE id = ?')
                ->execute([$user['id'], (float) ($req['proposed_cost'] ?? 0), (int) $req['booking_id']]);
        } else {
            $pdo->prepare('UPDATE rider_requests SET request_status = "rejected" WHERE id = ?')->execute([$requestId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('api offer respond failed: ' . $e->getMessage());
        api_fail(503, 'OFFER_FAILED', 'Could not update the offer right now. Please try again.');
    }
    if (function_exists('send_web_push')) {
        try {
            if ($action === 'accepted') {
                send_web_push($pdo, (int) $req['sender_user_id'], (string) $user['full_name'] . ' accepted your delivery', 'Booking ' . $req['booking_code'] . ' is on its way to pickup.', url_path('bookings/index.php?booking_id=' . (int) $req['booking_id']));
            } else {
                send_web_push($pdo, (int) $req['sender_user_id'], 'Rider declined your request', 'Booking ' . $req['booking_code'] . ' - try another rider.', url_path('bookings/index.php?booking_id=' . (int) $req['booking_id']));
            }
        } catch (Throwable $e) {}
    }
    log_event($pdo, 'booking_' . $action, 'Rider ' . $action . ' offer for booking ' . ($req['booking_code'] ?? '') . ' (mobile)', (int) $user['id'], (string) $user['role'], 'booking', (int) $req['booking_id']);
    api_ok(['bookingId' => (int) $req['booking_id'], 'requestStatus' => $action]);
}

function api_rider_confirm_payment(PDO $pdo, int $id): void {
    // Rider confirms they received payment for a delivered, paid booking -> credits their wallet
    // with the 85% payout. Mirrors rider/ajax_confirm_payment.php. Guards: owned, delivered,
    // not already confirmed, sender has paid.
    $user = api_require($pdo, ['rider']);
    $stmt = $pdo->prepare('SELECT id, booking_code, booking_status, payment_status, rider_payment_confirmed, agreed_cost FROM bookings WHERE id = ? AND selected_rider_user_id = ? LIMIT 1');
    $stmt->execute([$id, $user['id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    if ($booking['booking_status'] !== 'delivered') {
        api_fail(409, 'NOT_DELIVERED', 'Booking has not been delivered yet.');
    }
    if ((int) $booking['rider_payment_confirmed'] === 1) {
        api_fail(409, 'ALREADY_CONFIRMED', 'Payment has already been confirmed for this booking.');
    }
    if (($booking['payment_status'] ?? 'unpaid') !== 'paid') {
        api_fail(409, 'NOT_PAID', 'The sender has not paid for this booking yet.');
    }
    $payout = rider_payout_amount((float) $booking['agreed_cost']);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE bookings SET rider_payment_confirmed = 1, rider_payment_confirmed_at = NOW() WHERE id = ?')->execute([$id]);
        $pdo->prepare('INSERT INTO wallet_transactions (rider_user_id, booking_id, type, amount, description) VALUES (?, ?, "earning", ?, ?)')
            ->execute([$user['id'], $id, $payout, sprintf('Delivery %s', $booking['booking_code'])]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('api confirm payment failed: ' . $e->getMessage());
        api_fail(503, 'CONFIRM_FAILED', 'Unable to confirm payment right now. Please try again.');
    }
    log_event($pdo, 'booking_concluded', 'Booking ' . $booking['booking_code'] . ' concluded - rider confirmed payment (mobile)', (int) $user['id'], (string) $user['role'], 'booking', $id, ['payout' => $payout]);
    api_ok(['payout' => (float) $payout]);
}

function api_rider_transition(PDO $pdo, int $id): void {
    $user = api_require($pdo, ['rider']);
    $to = (string) (api_body()['to'] ?? '');
    // Canonical transition map (source of truth: rider/ajax_workflow_action.php).
    $map = [
        'arrived_at_pickup' => ['matched', 'accepted'],
        'package_received'  => ['arrived_at_pickup'],
        'delivered'         => ['package_received', 'in_transit'],
    ];
    if (!isset($map[$to])) {
        api_fail(400, 'INVALID_ACTION', 'Unknown transition.');
    }
    $stmt = $pdo->prepare('SELECT id, booking_status, sender_user_id, booking_code FROM bookings WHERE id = ? AND selected_rider_user_id = ? LIMIT 1');
    $stmt->execute([$id, $user['id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    if (!in_array($booking['booking_status'], $map[$to], true)) {
        api_fail(422, 'INVALID_TRANSITION', 'Booking is not in the expected state for this action.');
    }
    if ($to === 'delivered') {
        $pdo->prepare('UPDATE bookings SET booking_status = ?, updated_at = NOW(),
                        actual_duration_minutes = CASE WHEN matched_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, matched_at, NOW()) ELSE actual_duration_minutes END
                       WHERE id = ?')->execute([$to, $id]);
    } else {
        $pdo->prepare('UPDATE bookings SET booking_status = ?, updated_at = NOW() WHERE id = ?')->execute([$to, $id]);
    }
    // Notify the sender exactly as the web flow does (best-effort).
    if (function_exists('send_web_push')) {
        $titles = [
            'arrived_at_pickup' => ['Your rider has arrived', 'Your rider is at the pickup location for booking ' . $booking['booking_code'] . '.'],
            'package_received' => ['Package picked up', 'Your rider has your package for booking ' . $booking['booking_code'] . '.'],
            'delivered' => ['Delivered', 'Booking ' . $booking['booking_code'] . ' has been delivered.'],
        ];
        if (isset($titles[$to])) {
            try { send_web_push($pdo, (int) $booking['sender_user_id'], $titles[$to][0], $titles[$to][1], url_path('bookings/index.php?booking_id=' . $id)); } catch (Throwable $e) {}
        }
    }
    api_ok(['booking' => api_booking_public(api_fetch_booking($pdo, $id))]);
}

function api_rider_bookings(PDO $pdo): void {
    $user = api_require($pdo, ['rider']);
    $filter = (string) ($_GET['filter'] ?? 'active');
    if ($filter === 'completed') {
        $where = "booking_status = 'delivered'";
    } elseif ($filter === 'cancelled') {
        $where = "booking_status = 'cancelled'";
    } elseif ($filter === 'pending') {
        $where = "booking_status = 'matched'";
    } else { // active
        $where = "booking_status IN ('accepted','arrived_at_pickup','package_received','in_transit')";
    }
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE selected_rider_user_id = ? AND $where ORDER BY id DESC LIMIT 30");
    $stmt->execute([$user['id']]);
    api_ok(['bookings' => array_map('api_booking_public', $stmt->fetchAll(PDO::FETCH_ASSOC))]);
}

function api_rider_wallet(PDO $pdo): void {
    $user = api_require($pdo, ['rider']);
    $available = rider_available_balance($pdo, (int) $user['id']);
    $balance = rider_wallet_balance($pdo, (int) $user['id']);
    $stmt = $pdo->prepare('SELECT type, amount, description, created_at FROM wallet_transactions WHERE rider_user_id = ? ORDER BY id DESC LIMIT 50');
    $stmt->execute([$user['id']]);
    $ledger = array_map(static fn(array $t): array => [
        'type' => (string) $t['type'],
        'amount' => (float) $t['amount'],
        'description' => (string) ($t['description'] ?? ''),
        'createdAt' => (string) $t['created_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    api_ok(['balance' => (float) $balance, 'availableBalance' => (float) $available, 'ledger' => $ledger]);
}

// ---- Notifications --------------------------------------------------------------------------

function api_notif_device(PDO $pdo): void {
    $user = api_require($pdo);
    $b = api_body();
    $platform = (string) ($b['platform'] ?? '');
    $token = trim((string) ($b['token'] ?? ''));
    if (!in_array($platform, ['android', 'ios'], true) || $token === '') {
        api_fail(400, 'VALIDATION', 'platform (android|ios) and token are required.');
    }
    $pdo->prepare('INSERT INTO device_tokens (user_id, platform, token) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), last_seen_at = NOW()')
        ->execute([$user['id'], $platform, $token]);
    if (!headers_sent()) {
        http_response_code(204);
    }
    exit;
}

function api_notif_list(PDO $pdo): void {
    $user = api_require($pdo);
    $before = isset($_GET['before']) ? (int) $_GET['before'] : 0;
    $sql = 'SELECT id, title, body, url, created_at, delivered_at FROM push_notifications WHERE user_id = ?'
        . ($before > 0 ? ' AND id < ?' : '') . ' ORDER BY id DESC LIMIT 30';
    $params = [$user['id']];
    if ($before > 0) { $params[] = $before; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = array_map(static fn(array $n): array => [
        'id' => (int) $n['id'],
        'title' => (string) $n['title'],
        'body' => (string) $n['body'],
        'url' => $n['url'] !== null ? (string) $n['url'] : null,
        'read' => $n['delivered_at'] !== null,
        'createdAt' => (string) $n['created_at'],
    ], $rows);
    $cursor = count($rows) === 30 ? (string) $rows[count($rows) - 1]['id'] : null;
    api_ok(['notifications' => $items], ['cursor' => $cursor]);
}

function api_notif_read(PDO $pdo, int $id): void {
    $user = api_require($pdo);
    // Ownership enforced in the WHERE clause (a user can only mark their own read).
    $pdo->prepare('UPDATE push_notifications SET delivered_at = NOW() WHERE id = ? AND user_id = ? AND delivered_at IS NULL')
        ->execute([$id, $user['id']]);
    if (!headers_sent()) {
        http_response_code(204);
    }
    exit;
}

// ---- Shared helpers -------------------------------------------------------------------------

function api_fetch_booking(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function api_haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earth = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

// ---- Profile update -------------------------------------------------------------------------

function api_profile_update(PDO $pdo): void {
    $user = api_require($pdo);
    $b = api_body();
    $fullName = isset($b['fullName']) ? trim((string) $b['fullName']) : null;
    $phone = isset($b['phone']) ? trim((string) $b['phone']) : null;
    $sets = [];
    $params = [];
    if ($fullName !== null) {
        if ($fullName === '') { api_fail(400, 'VALIDATION', 'Name cannot be empty.', ['fullName' => 'Required']); }
        $sets[] = 'full_name = ?'; $params[] = $fullName;
    }
    if ($phone !== null) {
        if ($phone === '') { api_fail(400, 'VALIDATION', 'Phone cannot be empty.', ['phone' => 'Required']); }
        $sets[] = 'phone = ?'; $params[] = $phone;
    }
    if (!$sets) {
        api_fail(400, 'VALIDATION', 'Nothing to update.');
    }
    $params[] = $user['id'];
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    api_ok(api_user_public($stmt->fetch(PDO::FETCH_ASSOC)));
}

// ---- Password reset -------------------------------------------------------------------------

function api_auth_forgot(PDO $pdo): void {
    // Mirrors forgot-password.php: rate-limited, always returns the same generic result so it
    // can't be used to enumerate registered emails.
    $email = strtolower(trim((string) (api_body()['email'] ?? '')));
    $ip = client_ip();
    $limited = is_rate_limited($pdo, 'forgot_password_ip', $ip, 5, 60)
        || ($email !== '' && is_rate_limited($pdo, 'forgot_password_email', $email, 3, 60));
    if (!$limited) {
        record_rate_limit_attempt($pdo, 'forgot_password_ip', $ip);
        if ($email !== '') { record_rate_limit_attempt($pdo, 'forgot_password_email', $email); }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
                    ->execute([$u['id'], hash('sha256', $token)]);
                if (function_exists('send_password_reset_email')) {
                    $resetUrl = rtrim((string) (config_app()['app_url'] ?? ''), '/') . '/reset-password?token=' . $token;
                    try { send_password_reset_email($u['email'], $u['full_name'], $resetUrl); } catch (Throwable $e) {}
                }
            }
        }
    }
    api_ok(['message' => 'If that email is registered, a reset link has been sent.']);
}

function api_auth_reset(PDO $pdo): void {
    $b = api_body();
    $token = (string) ($b['token'] ?? '');
    $password = (string) ($b['password'] ?? '');
    if ($token === '' || strlen($password) < 8) {
        api_fail(400, 'VALIDATION', 'A valid token and a password of at least 8 characters are required.',
            ['password' => strlen($password) < 8 ? 'At least 8 characters' : '']);
    }
    $stmt = $pdo->prepare('SELECT id, user_id, expires_at, used_at FROM password_reset_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec || $rec['used_at'] !== null || strtotime((string) $rec['expires_at']) <= time()) {
        api_fail(400, 'INVALID_TOKEN', 'This reset link is invalid or has expired. Please request a new one.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $rec['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([$rec['id']]);
        // Revoke existing mobile sessions on a password change.
        $pdo->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([$rec['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        api_fail(503, 'RESET_FAILED', 'We could not reset your password right now. Please try again.');
    }
    api_ok(['message' => 'Your password has been reset. Please sign in.']);
}

// ---- Rating & complaint (post-delivery) -----------------------------------------------------

function api_booking_rating(PDO $pdo, int $id): void {
    $user = api_require($pdo, ['sender']);
    $b = api_body();
    $rating = (int) ($b['rating'] ?? 0);
    $reviewText = trim((string) ($b['review'] ?? ''));
    if ($rating < 1 || $rating > 5) {
        api_fail(400, 'VALIDATION', 'Rating must be between 1 and 5 stars.', ['rating' => '1-5']);
    }
    $stmt = $pdo->prepare('SELECT id, sender_user_id, selected_rider_user_id, booking_status FROM bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    if ($booking['booking_status'] !== 'delivered') {
        api_fail(409, 'NOT_DELIVERED', 'You can only rate a booking after it has been delivered.');
    }
    $riderUserId = (int) ($booking['selected_rider_user_id'] ?? 0);
    if ($riderUserId <= 0) {
        api_fail(422, 'NO_RIDER', 'No rider is associated with this booking.');
    }
    $exists = $pdo->prepare('SELECT id FROM booking_ratings WHERE booking_id = ? LIMIT 1');
    $exists->execute([$id]);
    if ($exists->fetchColumn()) {
        api_fail(409, 'ALREADY_RATED', 'You have already rated this delivery.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO booking_ratings (booking_id, sender_user_id, rider_user_id, rating, review_text, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$id, (int) $user['id'], $riderUserId, $rating, $reviewText !== '' ? $reviewText : null]);
        $pdo->prepare('UPDATE rider_profiles SET rating = (SELECT ROUND(AVG(rating), 2) FROM booking_ratings WHERE rider_user_id = ?) WHERE user_id = ?')
            ->execute([$riderUserId, $riderUserId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        api_fail(503, 'RATING_FAILED', 'Unable to save your rating.');
    }
    api_ok(['message' => 'Thanks for rating your rider!']);
}

function api_complaint_create(PDO $pdo): void {
    $user = api_require($pdo, ['sender']);
    $b = api_body();
    $bookingId = (int) ($b['bookingId'] ?? 0);
    $category = trim((string) ($b['category'] ?? ''));
    $message = trim((string) ($b['message'] ?? ''));
    $allowed = ['damaged_item', 'late_delivery', 'wrong_item', 'rider_behavior', 'other'];
    if ($bookingId <= 0) { api_fail(400, 'VALIDATION', 'A booking is required.'); }
    if (!in_array($category, $allowed, true)) { api_fail(400, 'VALIDATION', 'Please choose a valid complaint category.', ['category' => implode('|', $allowed)]); }
    if ($message === '') { api_fail(400, 'VALIDATION', 'Please describe the issue.', ['message' => 'Required']); }
    $stmt = $pdo->prepare('SELECT id, sender_user_id, booking_status, booking_code FROM bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    if ($booking['booking_status'] !== 'delivered') {
        api_fail(409, 'NOT_DELIVERED', 'You can only report a problem after this booking has been delivered.');
    }
    $pdo->prepare('INSERT INTO booking_complaints (booking_id, sender_user_id, category, message, status, created_at) VALUES (?, ?, ?, ?, "open", NOW())')
        ->execute([$bookingId, (int) $user['id'], $category, $message]);
    if (function_exists('notify_admins')) {
        try { notify_admins($pdo, 'New complaint reported - ' . $booking['booking_code'],
            '<p><strong>' . e((string) $user['full_name']) . '</strong> reported an issue with booking <strong>' . e((string) $booking['booking_code']) . '</strong> (mobile).</p><p><strong>Category:</strong> ' . e($category) . '</p><p>' . nl2br(e($message)) . '</p>'); } catch (Throwable $e) {}
    }
    api_ok(['message' => 'Your report has been submitted. Our team will follow up.'], [], 201);
}

// ---- Rider discovery (mirrors bookings/ajax_fetch_riders.php ranking) ------------------------

function api_riders_discover(PDO $pdo, int $bookingId): void {
    $user = api_require($pdo, ['sender']);
    $stmt = $pdo->prepare('SELECT id, sender_user_id, pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude FROM bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    $plat = (float) $booking['pickup_latitude'];
    $plng = (float) $booking['pickup_longitude'];
    $dlat = (float) $booking['delivery_latitude'];
    $dlng = (float) $booking['delivery_longitude'];

    try {
        $metrics = cached_route_metrics($pdo, $plat, $plng, $dlat, $dlng);
    } catch (Throwable $e) {
        api_ok(['pricingPending' => true, 'riders' => []]);
    }
    $routeKm = (float) $metrics['distance_km'];

    $distanceSql = haversine_sql('rp.last_latitude', 'rp.last_longitude', $plat, $plng);
    $activeStatuses = implode("','", array_map(static fn($s) => str_replace("'", "''", $s), RIDER_ACTIVE_BOOKING_STATUSES));
    $sql = "SELECT u.id, u.full_name, rp.vehicle_type, rp.rating, rp.last_latitude, rp.last_longitude, rp.last_location_updated_at,
                   CASE WHEN rp.last_latitude IS NOT NULL AND rp.last_longitude IS NOT NULL THEN {$distanceSql} ELSE NULL END AS distance_km,
                   (SELECT COUNT(*) FROM bookings b WHERE b.selected_rider_user_id = u.id AND b.booking_status IN ('{$activeStatuses}')) AS active_order_count
            FROM users u INNER JOIN rider_profiles rp ON rp.user_id = u.id
            WHERE u.role = 'rider' AND u.status = 'active' AND rp.kyc_status = 'approved'
              AND rp.vehicle_type IS NOT NULL AND rp.vehicle_type <> ''
            HAVING active_order_count < " . (int) RIDER_MAX_CONCURRENT_ORDERS . " LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($riders as &$r) {
        $vt = trim((string) ($r['vehicle_type'] ?? ''));
        try {
            $r['suggested_fee'] = (float) calculate_delivery_price($pdo, $routeKm, $vt)['total'];
            $r['pricing_available'] = true;
        } catch (Throwable $e) {
            $r['suggested_fee'] = null;
            $r['pricing_available'] = false;
        }
        $r['eta_minutes'] = $r['distance_km'] !== null ? estimated_eta_minutes((float) $r['distance_km'], $vt) : null;
        $stats = rider_delivery_stats($pdo, (int) $r['id']);
        $r['score'] = rider_match_score($r['rating'] !== null ? (float) $r['rating'] : null, $stats['ratio']);
        $r['last_seen_seconds_ago'] = !empty($r['last_location_updated_at'])
            ? max(0, time() - strtotime((string) $r['last_location_updated_at'])) : null;
    }
    unset($r);

    usort($riders, static fn(array $a, array $b): int => ((float) $b['score']) <=> ((float) $a['score']));
    $riders = array_slice($riders, 0, (int) MAX_RIDERS_RETURNED_API());

    $out = array_map(static fn(array $r): array => [
        'userId' => (int) $r['id'],
        'fullName' => (string) $r['full_name'],
        'vehicleType' => $r['vehicle_type'] !== null ? (string) $r['vehicle_type'] : null,
        'rating' => $r['rating'] !== null ? (float) $r['rating'] : null,
        'distanceKm' => $r['distance_km'] !== null ? round((float) $r['distance_km'], 2) : null,
        'etaMinutes' => $r['eta_minutes'],
        'suggestedFee' => $r['suggested_fee'],
        'pricingAvailable' => (bool) $r['pricing_available'],
        'lastSeenSecondsAgo' => $r['last_seen_seconds_ago'],
    ], $riders);
    api_ok(['pricingPending' => false, 'riders' => $out]);
}

function MAX_RIDERS_RETURNED_API(): int { return 10; }

// ---- Banks & withdrawals --------------------------------------------------------------------

function api_banks_list(PDO $pdo): void {
    api_require($pdo, ['rider']);
    $banks = function_exists('paystack_banks_list') ? paystack_banks_list($pdo) : [];
    api_ok(['banks' => array_map(static fn(array $b): array => [
        'code' => (string) $b['code'],
        'name' => (string) $b['name'],
    ], $banks)]);
}

function api_rider_profile_update(PDO $pdo): void {
    // Change the rider's vehicle type. Mirrors the vehicle field the web KYC/profile flow manages;
    // pricing/ETA per vehicle stay server-side, so this only records the rider's own vehicle.
    $user = api_require($pdo, ['rider']);
    $vehicleType = (string) (api_body()['vehicleType'] ?? '');
    if (!in_array($vehicleType, ['bike', 'car', 'van'], true)) {
        api_fail(400, 'VALIDATION', 'Choose a valid vehicle type.', ['vehicleType' => 'bike, car or van']);
    }
    $pdo->prepare('UPDATE rider_profiles SET vehicle_type = ? WHERE user_id = ?')->execute([$vehicleType, $user['id']]);
    api_ok(['vehicleType' => $vehicleType]);
}

function api_rider_bank_get(PDO $pdo): void {
    // The rider's saved payout account (if any). The account number is returned masked — only the
    // last 4 digits — since the full number isn't needed on the client once it's verified.
    $user = api_require($pdo, ['rider']);
    $stmt = $pdo->prepare('SELECT bank_name, bank_code, account_number, account_name, verified_at FROM rider_bank_accounts WHERE rider_user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $bank = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bank) {
        api_ok(['bank' => null]);
    }
    $acct = (string) $bank['account_number'];
    api_ok(['bank' => [
        'bankName' => (string) $bank['bank_name'],
        'bankCode' => (string) ($bank['bank_code'] ?? ''),
        'accountNumberMasked' => strlen($acct) > 4 ? str_repeat('*', strlen($acct) - 4) . substr($acct, -4) : $acct,
        'accountName' => (string) $bank['account_name'],
        'verified' => !empty($bank['verified_at']),
    ]]);
}

function api_rider_bank_verify(PDO $pdo): void {
    // Resolve an account number + bank to the account holder's name via Paystack (read-only; no
    // secret leaves the server). Mirrors rider/ajax_verify_bank_account.php. Lets the app preview
    // the resolved name before saving.
    $user = api_require($pdo, ['rider']);
    $b = api_body();
    $accountNumber = trim((string) ($b['accountNumber'] ?? ''));
    $bankCode = trim((string) ($b['bankCode'] ?? ''));
    if ($bankCode === '' || $accountNumber === '' || !ctype_digit($accountNumber)) {
        api_fail(422, 'VALIDATION', 'A valid account number and bank are required.');
    }
    if (!function_exists('paystack_resolve_account')) {
        api_fail(503, 'BANK_UNAVAILABLE', 'Bank verification is temporarily unavailable. Please try again shortly.');
    }
    $result = paystack_resolve_account($accountNumber, $bankCode);
    if (!($result['ok'] ?? false)) {
        api_fail(422, 'VERIFY_FAILED', $result['message'] ?: 'We could not verify that account. Check the number and bank.');
    }
    api_ok(['accountName' => (string) $result['account_name']]);
}

function api_rider_bank_save(PDO $pdo): void {
    // Save/replace the rider's payout account. Mirrors rider/wallet.php save_bank_account: the name
    // is always the one Paystack resolves (never client-supplied), and the row is upserted with a
    // fresh verified_at and a cleared recipient code (recreated at transfer time).
    $user = api_require($pdo, ['rider']);
    $b = api_body();
    $accountNumber = trim((string) ($b['accountNumber'] ?? ''));
    $bankCode = trim((string) ($b['bankCode'] ?? ''));
    if ($bankCode === '' || $accountNumber === '' || !ctype_digit($accountNumber)) {
        api_fail(422, 'VALIDATION', 'A valid account number and bank are required.');
    }
    $stmt = $pdo->prepare('SELECT name FROM paystack_banks WHERE code = ? LIMIT 1');
    $stmt->execute([$bankCode]);
    $bankName = (string) ($stmt->fetchColumn() ?: '');
    if ($bankName === '') {
        api_fail(422, 'INVALID_BANK', 'Please choose a valid bank.');
    }
    if (!function_exists('paystack_resolve_account')) {
        api_fail(503, 'BANK_UNAVAILABLE', 'Bank verification is temporarily unavailable. Please try again shortly.');
    }
    $result = paystack_resolve_account($accountNumber, $bankCode);
    if (!($result['ok'] ?? false)) {
        api_fail(422, 'VERIFY_FAILED', $result['message'] ?: 'We could not verify that account. Check the number and bank.');
    }
    $pdo->prepare('INSERT INTO rider_bank_accounts (rider_user_id, bank_name, bank_code, account_number, account_name, verified_at, paystack_recipient_code)
                   VALUES (?, ?, ?, ?, ?, NOW(), NULL)
                   ON DUPLICATE KEY UPDATE bank_name = VALUES(bank_name), bank_code = VALUES(bank_code),
                       account_number = VALUES(account_number), account_name = VALUES(account_name),
                       verified_at = VALUES(verified_at), paystack_recipient_code = NULL')
        ->execute([$user['id'], $bankName, $bankCode, $accountNumber, $result['account_name']]);
    api_ok(['bankName' => $bankName, 'accountName' => (string) $result['account_name']], [], 201);
}

function api_rider_withdrawals(PDO $pdo): void {
    // The rider's withdrawal history + live status (webhook-driven on the transfer side). Account
    // number is masked to its last 4 digits.
    $user = api_require($pdo, ['rider']);
    $stmt = $pdo->prepare('SELECT amount, status, bank_name, account_number, requested_at, processed_at, admin_note
                           FROM withdrawal_requests WHERE rider_user_id = ? ORDER BY id DESC LIMIT 50');
    $stmt->execute([$user['id']]);
    $items = array_map(static function (array $w): array {
        $acct = (string) $w['account_number'];
        return [
            'amount' => (float) $w['amount'],
            'status' => (string) $w['status'],
            'bankName' => (string) $w['bank_name'],
            'accountNumberMasked' => strlen($acct) > 4 ? '****' . substr($acct, -4) : $acct,
            'requestedAt' => (string) $w['requested_at'],
            'processedAt' => $w['processed_at'] !== null ? (string) $w['processed_at'] : null,
            'note' => $w['admin_note'] !== null ? (string) $w['admin_note'] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    api_ok(['withdrawals' => $items]);
}

function api_rider_withdraw(PDO $pdo): void {
    $user = api_require($pdo, ['rider']);
    api_idempotency_replay($pdo, (int) $user['id'], 'POST rider/withdrawals');
    $amount = (float) (api_body()['amount'] ?? 0);
    if ($amount <= 0) {
        api_fail(400, 'VALIDATION', 'Enter a valid withdrawal amount.', ['amount' => 'Must be greater than zero']);
    }
    // Bank details must exist and be verified (mirrors rider/wallet.php).
    $stmt = $pdo->prepare('SELECT bank_name, bank_code, account_number, account_name, verified_at FROM rider_bank_accounts WHERE rider_user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $bank = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bank || empty($bank['bank_code']) || empty($bank['verified_at'])) {
        api_fail(422, 'NO_BANK', 'Add and verify your bank account before requesting a withdrawal.');
    }
    // Transactional balance check (reuses the row-locked helper — same double-spend protection
    // as the web withdrawal path).
    $created = false;
    try {
        $pdo->beginTransaction();
        $available = rider_available_balance_locked($pdo, (int) $user['id']);
        if ($amount > $available) {
            $pdo->rollBack();
            api_fail(422, 'INSUFFICIENT_FUNDS', 'That amount exceeds your available balance.');
        }
        $pdo->prepare('INSERT INTO withdrawal_requests (rider_user_id, amount, bank_name, bank_code, account_number, account_name, status) VALUES (?, ?, ?, ?, ?, ?, "pending")')
            ->execute([$user['id'], $amount, $bank['bank_name'], $bank['bank_code'], $bank['account_number'], $bank['account_name']]);
        $pdo->commit();
        $created = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($created === false && strpos($e->getMessage(), 'INSUFFICIENT') === false) {
            error_log('api withdrawal failed: ' . $e->getMessage());
        }
        api_fail(503, 'WITHDRAW_FAILED', 'We could not submit your withdrawal right now. Please try again.');
    }
    $env = ['ok' => true, 'data' => ['message' => 'Withdrawal request submitted.'], 'error' => null, 'meta' => ['requestId' => bin2hex(random_bytes(8))]];
    $body = json_encode($env);
    api_idempotency_store($pdo, (int) $user['id'], 'POST rider/withdrawals', 201, $body);
    if (function_exists('send_withdrawal_requested_email')) {
        try { send_withdrawal_requested_email((string) $user['email'], (string) $user['full_name'], $amount); } catch (Throwable $e) {}
    }
    if (!headers_sent()) { http_response_code(201); header('Content-Type: application/json; charset=utf-8'); }
    echo $body;
    exit;
}

// ---- Payments (Paystack — secrets stay server-side; init/verify wrap existing helpers) -------

function api_payment_init(PDO $pdo): void {
    $user = api_require($pdo, ['sender']);
    api_idempotency_replay($pdo, (int) $user['id'], 'POST payments/init');
    $bookingId = (int) (api_body()['bookingId'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, sender_user_id, agreed_cost, payment_status, booking_code, booking_status FROM bookings WHERE id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
        api_fail(404, 'NOT_FOUND', 'Booking not found.');
    }
    if (($booking['payment_status'] ?? '') === 'paid') {
        api_fail(409, 'ALREADY_PAID', 'This booking is already paid.');
    }
    if ($booking['agreed_cost'] === null || (float) $booking['agreed_cost'] <= 0) {
        api_fail(422, 'NO_PRICE', 'This booking does not have a confirmed price yet.');
    }
    if (!function_exists('paystack_configured') || !paystack_configured()) {
        api_fail(503, 'PAYMENTS_UNAVAILABLE', 'Payments are temporarily unavailable. Please try again shortly.');
    }
    // Initialise a Paystack transaction server-side (amount in kobo). The secret key never
    // leaves the server; the app receives only the reference + access code / authorization URL.
    $reference = 'AIKE-' . $booking['booking_code'] . '-' . bin2hex(random_bytes(4));
    $res = paystack_request('POST', '/transaction/initialize', [
        'email' => (string) $user['email'],
        'amount' => (int) round(((float) $booking['agreed_cost']) * 100),
        'reference' => $reference,
        'metadata' => ['booking_id' => (int) $booking['id'], 'channel' => 'mobile'],
    ]);
    if (!($res['status'] ?? false)) {
        api_fail(502, 'PAYMENT_INIT_FAILED', 'Could not start the payment. Please try again.');
    }
    $data = $res['data'] ?? [];
    $pdo->prepare('UPDATE bookings SET paystack_reference = ?, paystack_access_code = ?, payment_status = "pending" WHERE id = ?')
        ->execute([$reference, $data['access_code'] ?? null, $booking['id']]);
    $env = ['ok' => true, 'data' => [
        'reference' => $reference,
        'accessCode' => $data['access_code'] ?? null,
        'authorizationUrl' => $data['authorization_url'] ?? null,
    ], 'error' => null, 'meta' => ['requestId' => bin2hex(random_bytes(8))]];
    $body = json_encode($env);
    api_idempotency_store($pdo, (int) $user['id'], 'POST payments/init', 200, $body);
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo $body;
    exit;
}

function api_payment_verify(PDO $pdo): void {
    $user = api_require($pdo, ['sender']);
    $reference = trim((string) (api_body()['reference'] ?? ''));
    if ($reference === '') {
        api_fail(400, 'VALIDATION', 'A payment reference is required.');
    }
    // Verify server-side and let the shared finalize routine reconcile (idempotent; the webhook
    // remains the authoritative path). Ownership: the reference must belong to the caller.
    $stmt = $pdo->prepare('SELECT id, sender_user_id, payment_status FROM bookings WHERE paystack_reference = ? LIMIT 1');
    $stmt->execute([$reference]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking || (int) $booking['sender_user_id'] !== (int) $user['id']) {
        api_fail(404, 'NOT_FOUND', 'Payment not found.');
    }
    if (function_exists('finalize_booking_payment')) {
        try { finalize_booking_payment($pdo, $reference); } catch (Throwable $e) { error_log('api verify: ' . $e->getMessage()); }
    }
    $stmt->execute([$reference]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    api_ok(['paymentStatus' => (string) ($fresh['payment_status'] ?? 'pending')]);
}
