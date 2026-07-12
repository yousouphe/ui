<?php
// Server-to-server webhook Paystack calls directly - no session, no CSRF, no login.
// Configure this URL (https://yourdomain/payments/webhook.php) in the Paystack dashboard
// under Settings > API Keys & Webhooks so payments get confirmed even if the sender's
// browser never makes it back to payments/callback.php after paying.
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$rawBody = file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
$secretKey = paystack_secret_key();

if ($secretKey === '' || $signature === '' || !hash_equals(hash_hmac('sha512', $rawBody, $secretKey), $signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$event = json_decode($rawBody, true);
$eventType = (string) ($event['event'] ?? '');
$reference = trim((string) ($event['data']['reference'] ?? ''));

if ($eventType === 'charge.success' && $reference !== '') {
    // finalize_booking_payment() re-verifies with Paystack directly rather than trusting
    // this payload, and is a safe no-op if payments/callback.php already confirmed it.
    finalize_booking_payment($pdo, $reference);
}

// Always 200 once the signature checks out - Paystack retries on non-2xx, and there's
// nothing to retry for event types we don't act on or references we don't recognize.
http_response_code(200);
echo json_encode(['ok' => true]);
