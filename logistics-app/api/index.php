<?php
// Aike mobile API front controller — stateless JSON, bearer-token auth, /api/v1/**.
define('AIKE_STATELESS', true);

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../config/mapbox.php';
require_once __DIR__ . '/../config/push.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../config/emails.php';
require_once __DIR__ . '/routes_v1.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$apiPos = strpos($path, '/api/');
$route = $apiPos !== false ? substr($path, $apiPos + 5) : ltrim($path, '/');
$route = preg_replace('#^v1/?#', '', $route);
$route = trim((string) $route, '/');

$routes = [
    ['GET',  '#^health$#',                              fn() => api_health()],
    ['POST', '#^auth/login$#',                          fn() => api_login($pdo)],
    ['POST', '#^auth/register$#',                       fn() => api_register($pdo)],
    ['POST', '#^auth/refresh$#',                        fn() => api_auth_refresh($pdo)],
    ['POST', '#^auth/logout$#',                         fn() => api_auth_logout($pdo)],
    ['POST', '#^auth/google$#',                         fn() => api_auth_google($pdo)],
    ['POST', '#^profile/complete$#',                    fn() => api_profile_complete($pdo)],
    ['GET',  '#^profile$#',                             fn() => api_ok(api_user_public(api_require($pdo)))],
    ['PATCH','#^profile$#',                             fn() => api_profile_update($pdo)],
    ['POST', '#^auth/forgot$#',                         fn() => api_auth_forgot($pdo)],
    ['POST', '#^auth/reset$#',                          fn() => api_auth_reset($pdo)],

    // Sender
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
    ['POST', '#^bookings/(\d+)/rating$#',               fn($id) => api_booking_rating($pdo, (int) $id)],
    ['GET',  '#^bookings/(\d+)/riders$#',               fn($id) => api_riders_discover($pdo, (int) $id)],
    ['POST', '#^bookings/(\d+)/request$#',              fn($id) => api_booking_request_rider($pdo, (int) $id)],

    // Rider
    ['GET',  '#^rider/profile$#',                       fn() => api_rider_profile($pdo)],
    ['PATCH','#^rider/profile$#',                       fn() => api_rider_profile_update($pdo)],
    ['GET',  '#^rider/kyc$#',                           fn() => api_rider_kyc_get($pdo)],
    ['POST', '#^rider/kyc$#',                           fn() => api_rider_kyc_submit($pdo)],
    ['POST', '#^rider/status$#',                        fn() => api_rider_status($pdo)],
    ['POST', '#^rider/location$#',                      fn() => api_rider_location($pdo)],
    ['GET',  '#^rider/offers$#',                        fn() => api_rider_offers($pdo)],
    ['POST', '#^rider/offers/(\d+)/accept$#',           fn($id) => api_rider_offer_respond($pdo, (int) $id, 'accepted')],
    ['POST', '#^rider/offers/(\d+)/reject$#',           fn($id) => api_rider_offer_respond($pdo, (int) $id, 'rejected')],
    ['GET',  '#^rider/bookings$#',                      fn() => api_rider_bookings($pdo)],
    ['POST', '#^rider/bookings/(\d+)/transition$#',     fn($id) => api_rider_transition($pdo, (int) $id)],
    ['POST', '#^rider/bookings/(\d+)/confirm-payment$#', fn($id) => api_rider_confirm_payment($pdo, (int) $id)],
    ['GET',  '#^rider/wallet$#',                        fn() => api_rider_wallet($pdo)],
    ['GET',  '#^rider/banks$#',                         fn() => api_banks_list($pdo)],
    ['GET',  '#^rider/bank$#',                          fn() => api_rider_bank_get($pdo)],
    ['POST', '#^rider/bank$#',                          fn() => api_rider_bank_save($pdo)],
    ['POST', '#^rider/bank/verify$#',                   fn() => api_rider_bank_verify($pdo)],
    ['GET',  '#^rider/withdrawals$#',                   fn() => api_rider_withdrawals($pdo)],
    ['POST', '#^rider/withdrawals$#',                   fn() => api_rider_withdraw($pdo)],

    // Admin
    ['GET',  '#^admin/stats$#',                         fn() => api_admin_stats($pdo)],
    ['GET',  '#^admin/users$#',                         fn() => api_admin_users($pdo)],
    ['GET',  '#^admin/riders$#',                        fn() => api_admin_riders($pdo)],
    ['GET',  '#^admin/bookings$#',                      fn() => api_admin_bookings($pdo)],
    ['POST', '#^admin/riders/(\d+)/verify$#',           fn($id) => api_admin_rider_verify($pdo, (int) $id)],
    ['POST', '#^admin/users/(\d+)/suspend$#',           fn($id) => api_admin_user_suspend($pdo, (int) $id)],
    ['POST', '#^admin/users/(\d+)/activate$#',          fn($id) => api_admin_user_activate($pdo, (int) $id)],
    ['PATCH','#^admin/users/(\d+)/role$#',              fn($id) => api_admin_user_role($pdo, (int) $id)],

    // Shared
    ['POST', '#^payments/init$#',                       fn() => api_payment_init($pdo)],
    ['POST', '#^payments/verify$#',                     fn() => api_payment_verify($pdo)],
    ['GET',  '#^payments$#',                            fn() => api_payments_list($pdo)],
    ['GET',  '#^transactions$#',                        fn() => api_transactions_list($pdo)],
    ['GET',  '#^bookings/(\d+)/receipt$#',              fn($id) => api_booking_receipt($pdo, (int) $id)],
    ['POST', '#^bookings/(\d+)/receipt/resend$#',       fn($id) => api_booking_receipt_resend($pdo, (int) $id)],
    ['POST', '#^notifications/device$#',                fn() => api_notif_device($pdo)],
    ['GET',  '#^notifications$#',                       fn() => api_notif_list($pdo)],
    ['POST', '#^notifications/(\d+)/read$#',            fn($id) => api_notif_read($pdo, (int) $id)],
    ['POST', '#^complaints$#',                          fn() => api_complaint_create($pdo)],
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
        exit;
    }
}
if ($matchedPathButNotMethod) { api_fail(405, 'METHOD_NOT_ALLOWED', 'Method not supported.'); }
api_fail(404, 'NOT_FOUND', 'Unknown endpoint.');

function api_health(): void { http_response_code(204); exit; }
