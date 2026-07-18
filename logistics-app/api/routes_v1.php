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
