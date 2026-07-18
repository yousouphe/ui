<?php
// Aike mobile API front controller — stateless JSON, bearer-token auth, /api/v1/**.
//
// This is a thin transport wrapper: every endpoint delegates trusted decisions (auth, pricing,
// eligibility, booking state) to the same config/*.php helpers the web app uses. No business
// logic is duplicated here, and no secrets are exposed.
//
// AIKE_STATELESS tells config/functions.php to skip starting a PHP session (we authenticate with
// bearer tokens, not cookies).
define('AIKE_STATELESS', true);

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api.php';

// ---- Route parsing --------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$apiPos = strpos($path, '/api/');
$route = $apiPos !== false ? substr($path, $apiPos + 5) : ltrim($path, '/');
$route = preg_replace('#^v1/?#', '', $route);      // drop the version segment
$route = trim((string) $route, '/');
$key = $method . ' ' . $route;

// ---- Dispatch -------------------------------------------------------------------------------
switch (true) {

    // Unauthenticated connectivity probe (mirrors ping.php). No body.
    case $key === 'GET health':
        if (!headers_sent()) {
            http_response_code(204);
            header('Cache-Control: no-store');
        }
        exit;

    case $key === 'POST auth/login':
        api_login($pdo);
        break;

    case $key === 'POST auth/refresh':
        $body = api_body();
        $refresh = (string) ($body['refreshToken'] ?? '');
        $tokens = $refresh !== '' ? api_refresh_tokens($pdo, $refresh) : null;
        if (!$tokens) {
            api_fail(401, 'INVALID_REFRESH', 'Your session has expired. Please sign in again.');
        }
        api_ok($tokens);
        break;

    case $key === 'POST auth/logout':
        api_require($pdo); // must be authenticated
        $token = api_bearer_token();
        if ($token) {
            api_revoke_by_access($pdo, $token);
        }
        if (!headers_sent()) {
            http_response_code(204);
        }
        exit;

    case $key === 'GET profile':
        $user = api_require($pdo);
        api_ok(api_user_public($user));
        break;

    case $key === 'POST pricing/estimate':
        api_pricing_estimate($pdo);
        break;

    case $key === 'GET bookings':
        api_list_bookings($pdo);
        break;

    default:
        api_fail(404, 'NOT_FOUND', 'Unknown endpoint.');
}

// ---- Handlers -------------------------------------------------------------------------------

function api_login(PDO $pdo): void {
    $body = api_body();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $ip = client_ip();

    // Same brute-force protection as the web login: by IP and by email.
    if (is_rate_limited($pdo, 'api_login_ip', $ip, 10, 15)
        || ($email !== '' && is_rate_limited($pdo, 'api_login_email', $email, 5, 15))) {
        api_fail(429, 'RATE_LIMITED', 'Too many attempts. Please wait a few minutes and try again.');
    }
    if ($email === '' || $password === '') {
        api_fail(400, 'VALIDATION', 'Email and password are required.', [
            'email' => $email === '' ? 'Required' : '',
            'password' => $password === '' ? 'Required' : '',
        ]);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u || !password_verify($password, (string) $u['password_hash'])) {
        record_rate_limit_attempt($pdo, 'api_login_ip', $ip);
        if ($email !== '') {
            record_rate_limit_attempt($pdo, 'api_login_email', $email);
        }
        api_fail(401, 'INVALID_CREDENTIALS', 'Invalid email or password.');
    }
    if (($u['status'] ?? '') !== 'active') {
        api_fail(403, 'ACCOUNT_INACTIVE', 'This account is not active. Please contact support.');
    }

    $platform = isset($body['platform']) ? substr((string) $body['platform'], 0, 20) : null;
    $device = isset($body['deviceLabel']) ? substr((string) $body['deviceLabel'], 0, 120) : null;
    $tokens = api_issue_tokens($pdo, (int) $u['id'], $platform, $device);

    api_ok([
        'accessToken' => $tokens['accessToken'],
        'refreshToken' => $tokens['refreshToken'],
        'expiresInSeconds' => $tokens['expiresInSeconds'],
        'user' => api_user_public($u),
    ], [], 200);
}

function api_pricing_estimate(PDO $pdo): void {
    $user = api_require($pdo, ['sender']);
    $body = api_body();
    $pickup = $body['pickup'] ?? null;
    $dropoff = $body['dropoff'] ?? null;
    $vehicleType = (string) ($body['vehicleType'] ?? '');

    if (!in_array($vehicleType, ['bike', 'car', 'van'], true)) {
        api_fail(400, 'VALIDATION', 'A valid vehicle type is required.', ['vehicleType' => 'bike, car or van']);
    }
    $coords = api_valid_coords($pickup, $dropoff);
    if ($coords === null) {
        api_fail(400, 'VALIDATION', 'Valid pickup and drop-off coordinates are required.');
    }
    [$plat, $plng, $dlat, $dlng] = $coords;

    try {
        // Reuses the shared, backend-owned route cache + Mapbox Directions. Pricing is NEVER
        // computed on the client.
        $metrics = cached_route_metrics($pdo, $plat, $plng, $dlat, $dlng);
    } catch (Throwable $e) {
        api_fail(422, 'NO_ROUTE', 'We could not calculate a route for those locations. Please check the addresses.');
    }

    $distanceKm = (float) $metrics['distance_km'];
    $price = calculate_delivery_price($pdo, $distanceKm, $vehicleType);
    $settings = pricing_settings($pdo);

    api_ok([
        'distanceKm' => round($distanceKm, 2),
        'durationMinutes' => (int) round((float) ($metrics['duration_min'] ?? 0)),
        'vehicleType' => $vehicleType,
        'breakdown' => [
            'minimumFee' => (float) $settings['minimum_fee'],
            'perKm' => (float) $settings['per_km_rate'],
            'multiplier' => (float) ($settings[$vehicleType . '_multiplier'] ?? $settings['bike_multiplier']),
            'tax' => (float) $price['tax_amount'],
        ],
        'total' => (float) $price['total'],
    ]);
}

function api_list_bookings(PDO $pdo): void {
    $user = api_require($pdo, ['sender']);
    $filter = (string) ($_GET['filter'] ?? 'active');
    $limit = 20;
    $beforeId = isset($_GET['before']) ? (int) $_GET['before'] : 0;

    // Map the mobile filter to booking/payment status sets (statuses owned by the backend enum).
    if ($filter === 'unpaid') {
        $where = "booking_status = 'delivered' AND payment_status IN ('unpaid','pending','failed')";
    } elseif ($filter === 'history') {
        $where = "booking_status IN ('delivered','cancelled')";
    } else { // active
        $where = "booking_status NOT IN ('delivered','cancelled','draft')";
    }

    $sql = "SELECT * FROM bookings
            WHERE sender_user_id = ? AND $where" . ($beforeId > 0 ? ' AND id < ?' : '') . "
            ORDER BY id DESC LIMIT $limit";
    $params = [$user['id']];
    if ($beforeId > 0) {
        $params[] = $beforeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bookings = array_map('api_booking_public', $rows);
    $nextCursor = null;
    if (count($rows) === $limit) {
        $last = $rows[count($rows) - 1];
        $nextCursor = (string) $last['id'];
    }
    api_ok(['bookings' => $bookings], ['cursor' => $nextCursor]);
}

/** Validate two coordinate pairs; returns [plat,plng,dlat,dlng] or null. */
function api_valid_coords($pickup, $dropoff): ?array {
    if (!is_array($pickup) || !is_array($dropoff)) {
        return null;
    }
    $vals = [$pickup['lat'] ?? null, $pickup['lng'] ?? null, $dropoff['lat'] ?? null, $dropoff['lng'] ?? null];
    foreach ($vals as $v) {
        if (!is_numeric($v)) {
            return null;
        }
    }
    [$plat, $plng, $dlat, $dlng] = array_map('floatval', $vals);
    // Sanity bounds (roughly Nigeria + margin) to reject impossible coordinates.
    if ($plat < 3 || $plat > 15 || $dlat < 3 || $dlat > 15 || $plng < 2 || $plng > 15 || $dlng < 2 || $dlng > 15) {
        return null;
    }
    return [$plat, $plng, $dlat, $dlng];
}
