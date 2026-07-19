<?php
// Aike mobile API front controller — stateless JSON, bearer-token auth, /api/v1/**.
//
// Thin transport wrapper: every endpoint delegates trusted decisions (auth, pricing, eligibility,
// booking state) to the same config/*.php helpers the web app uses. No business logic duplicated,
// no secrets exposed. AIKE_STATELESS tells config/functions.php to skip the PHP session.
define('AIKE_STATELESS', true);

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/mapbox.php';   // pricing_route_metrics + NoRouteFoundException
require_once __DIR__ . '/../config/push.php';     // send_web_push for status notifications
require_once __DIR__ . '/../config/paystack.php'; // payments init/verify + banks (secrets stay server-side)
require_once __DIR__ . '/../config/emails.php';   // password reset + withdrawal emails
require_once __DIR__ . '/routes_v1.php';

// ---- Route parsing --------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$apiPos = strpos($path, '/api/');
$route = $apiPos !== false ? substr($path, $apiPos + 5) : ltrim($path, '/');
$route = preg_replace('#^v1/?#', '', $route);      // drop the version segment
$route = trim((string) $route, '/');

// ---- Route table: [METHOD, regex, handler(pdo, ...captures)] --------------------------------
$routes = [
    ['GET',  '#^health$#',                              fn() => api_health()],
    ['POST', '#^auth/login$#',                          fn() => api_login($pdo)],
    ['POST', '#^auth/register$#',                       fn() => api_register($pdo)],
    ['POST', '#^auth/refresh$#',                        fn() => api_auth_refresh($pdo)],
    ['POST', '#^auth/logout$#',                         fn() => api_auth_logout($pdo)],
    ['GET',  '#^profile$#',                             fn() => api_ok(api_user_public(api_require($pdo)))],
    ['POST', '#^pricing/estimate$#',                    fn() => api_pricing_estimate($pdo)],
    ['POST', '#^geo/route$#',                           fn() => api_geo_route($pdo)],
    ['GET',  '#^bookings$#',                            fn() => api_list_bookings($pdo)],
    ['POST', '#^bookings$#',                            fn() => api_booking_create($pdo)],
    ['GET',  '#^bookings/(\d+)$#',                      fn($id) => api_booking_get($pdo, (int) $id)],
    ['PATCH','#^bookings/(\d+)$#',                      fn($id) => api_booking_update($pdo, (int) $id)],
    ['POST', '#^bookings/(\d+)/cancel$#',               fn($id) => api_booking_cancel($pdo, (int) $id)],
    ['POST', '#^bookings/(\d+)/rebook$#',               fn($id) => api_booking_rebook($pdo, (int) $id)],
    ['GET',  '#^bookings/(\d+)/track$#',                fn($id) => api_booking_track($pdo, (int) $id)],
    ['GET',  '#^bookings/(\d+)/contact$#',              fn($id) => api_booking_contact($pdo, (int) $id)],
    ['GET',  '#^bookings/(\d+)/messages$#',             fn($id) => api_messages_list($pdo, (int) $id)],
    ['POST', '#^bookings/(\d+)/messages$#',             fn($id) => api_messages_send($pdo, (int) $id)],
    ['GET',  '#^rider/profile$#',                       fn() => api_rider_profile($pdo)],
    ['PATCH','#^rider/profile$#',                       fn() => api_rider_profile_update($pdo)],
    ['POST', '#^rider/status$#',                        fn() => api_rider_status($pdo)],
    ['POST', '#^rider/location$#',                      fn() => api_rider_location($pdo)],
    ['GET',  '#^rider/offers$#',                        fn() => api_rider_offers($pdo)],
    ['POST', '#^rider/offers/(\d+)/accept$#',           fn($id) => api_rider_offer_respond($pdo, (int) $id, 'accepted')],
    ['POST', '#^rider/offers/(\d+)/reject$#',           fn($id) => api_rider_offer_respond($pdo, (int) $id, 'rejected')],
    ['GET',  '#^rider/bookings$#',                      fn() => api_rider_bookings($pdo)],
    ['POST', '#^rider/bookings/(\d+)/transition$#',     fn($id) => api_rider_transition($pdo, (int) $id)],
    ['POST', '#^rider/bookings/(\d+)/confirm-payment$#', fn($id) => api_rider_confirm_payment($pdo, (int) $id)],
    ['GET',  '#^rider/wallet$#',                        fn() => api_rider_wallet($pdo)],
    ['POST', '#^notifications/device$#',                fn() => api_notif_device($pdo)],
    ['GET',  '#^notifications$#',                       fn() => api_notif_list($pdo)],
    ['POST', '#^notifications/(\d+)/read$#',            fn($id) => api_notif_read($pdo, (int) $id)],
    // --- Phase 3 remaining ---
    ['PATCH','#^profile$#',                             fn() => api_profile_update($pdo)],
    ['POST', '#^auth/forgot$#',                         fn() => api_auth_forgot($pdo)],
    ['POST', '#^auth/reset$#',                          fn() => api_auth_reset($pdo)],
    ['POST', '#^complaints$#',                          fn() => api_complaint_create($pdo)],
    ['POST', '#^bookings/(\d+)/rating$#',               fn($id) => api_booking_rating($pdo, (int) $id)],
    ['GET',  '#^bookings/(\d+)/riders$#',               fn($id) => api_riders_discover($pdo, (int) $id)],
    ['POST', '#^bookings/(\d+)/request$#',              fn($id) => api_booking_request_rider($pdo, (int) $id)],
    ['GET',  '#^rider/banks$#',                         fn() => api_banks_list($pdo)],
    ['GET',  '#^rider/bank$#',                          fn() => api_rider_bank_get($pdo)],
    ['POST', '#^rider/bank$#',                          fn() => api_rider_bank_save($pdo)],
    ['POST', '#^rider/bank/verify$#',                   fn() => api_rider_bank_verify($pdo)],
    ['GET',  '#^rider/withdrawals$#',                   fn() => api_rider_withdrawals($pdo)],
    ['POST', '#^rider/withdrawals$#',                   fn() => api_rider_withdraw($pdo)],
    ['POST', '#^payments/init$#',                       fn() => api_payment_init($pdo)],
    ['POST', '#^payments/verify$#',                     fn() => api_payment_verify($pdo)],
    ['GET',  '#^payments$#',                            fn() => api_payments_list($pdo)],
];

$matchedPathButNotMethod = false;
foreach ($routes as [$m, $pattern, $handler]) {
    if (preg_match($pattern, $route, $caps)) {
        if ($m !== $method) {
            $matchedPathButNotMethod = true;
            continue;
        }
        array_shift($caps);
        $handler(...$caps);
        exit; // handlers send their own response
    }
}
if ($matchedPathButNotMethod) {
    api_fail(405, 'METHOD_NOT_ALLOWED', 'That method is not supported on this endpoint.');
}
api_fail(404, 'NOT_FOUND', 'Unknown endpoint.');

// ---- Inline handlers kept here (auth core + pricing + list) ----------------------------------

function api_health(): void {
    if (!headers_sent()) {
        http_response_code(204);
        header('Cache-Control: no-store');
    }
    exit;
}

function api_auth_refresh(PDO $pdo): void {
    $refresh = (string) (api_body()['refreshToken'] ?? '');
    $tokens = $refresh !== '' ? api_refresh_tokens($pdo, $refresh) : null;
    if (!$tokens) {
        api_fail(401, 'INVALID_REFRESH', 'Your session has expired. Please sign in again.');
    }
    api_ok($tokens);
}

function api_auth_logout(PDO $pdo): void {
    api_require($pdo);
    $token = api_bearer_token();
    if ($token) {
        api_revoke_by_access($pdo, $token);
    }
    if (!headers_sent()) {
        http_response_code(204);
    }
    exit;
}

function api_login(PDO $pdo): void {
    $body = api_body();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $ip = client_ip();

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
    ]);
}

function api_pricing_estimate(PDO $pdo): void {
    $user = api_require($pdo, ['sender']);
    $body = api_body();
    $vehicleType = (string) ($body['vehicleType'] ?? '');
    if (!in_array($vehicleType, ['bike', 'car', 'van'], true)) {
        api_fail(400, 'VALIDATION', 'A valid vehicle type is required.', ['vehicleType' => 'bike, car or van']);
    }
    $coords = api_valid_coords($body['pickup'] ?? null, $body['dropoff'] ?? null);
    if ($coords === null) {
        api_fail(400, 'VALIDATION', 'Valid pickup and drop-off coordinates are required.');
    }
    [$plat, $plng, $dlat, $dlng] = $coords;
    try {
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

    if ($filter === 'unpaid') {
        $where = "booking_status = 'delivered' AND payment_status IN ('unpaid','pending','failed')";
    } elseif ($filter === 'history') {
        $where = "booking_status IN ('delivered','cancelled')";
    } else {
        $where = "booking_status NOT IN ('delivered','cancelled','draft')";
    }

    $sql = "SELECT * FROM bookings WHERE sender_user_id = ? AND $where" . ($beforeId > 0 ? ' AND id < ?' : '') . " ORDER BY id DESC LIMIT $limit";
    $params = [$user['id']];
    if ($beforeId > 0) {
        $params[] = $beforeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $bookings = array_map('api_booking_public', $rows);
    $nextCursor = count($rows) === $limit ? (string) $rows[count($rows) - 1]['id'] : null;
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
    if ($plat < 3 || $plat > 15 || $dlat < 3 || $dlat > 15 || $plng < 2 || $plng > 15 || $dlng < 2 || $dlng > 15) {
        return null;
    }
    return [$plat, $plng, $dlat, $dlng];
}
