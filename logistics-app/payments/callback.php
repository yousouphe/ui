<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';

$config = require __DIR__ . '/../config/env.php';

$appUrl = rtrim((string)($config['app_url'] ?? ''), '/');

if ($appUrl === '') {
    die('App URL is not configured.');
}

function go_to(string $path): void
{
    global $appUrl;
    header('Location: ' . $appUrl . $path);
    exit;
}

$reference = trim((string)($_GET['reference'] ?? $_GET['trxref'] ?? ''));

if ($reference === '') {
    flash('error', 'Missing payment reference.');
    go_to('/bookings/index.php');
}

if (!paystack_configured()) {
    flash('error', 'Payment configuration is incomplete.');
    go_to('/bookings/index.php');
}

// The webhook (payments/webhook.php) may already have confirmed this payment by the time
// the sender's browser makes it back here - finalize_booking_payment() is idempotent either
// way, so whichever of the two gets there first does the work and the other is a no-op.
// paystack_reconcile_reference() also recovers a reference with no booking_payments row yet
// (a mobile-initiated payment from before that fix, or an earlier attempt that succeeded but
// got superseded by a later retry before ever being reconciled) - this is the manual recovery
// path for exactly that: visiting this URL with the reference found in the Paystack dashboard.
$result = paystack_reconcile_reference($pdo, $reference);

if (!$result['ok'] && !$result['already_paid']) {
    flash('error', $result['message']);
    go_to($result['booking_id'] ? '/bookings/index.php?booking_id=' . $result['booking_id'] : '/bookings/index.php');
}

flash('success', $result['already_paid'] ? 'Payment has already been confirmed.' : 'Payment verified successfully.');
// Land on the generated receipt so the sender can view / print / download it immediately.
go_to('/payments/receipt.php?booking_id=' . (int)$result['booking_id']);