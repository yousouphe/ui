<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

$config = require __DIR__ . '/../config/env.php';
$user = current_user();

// 1. Capture Reference
$reference = $_GET['reference'] ?? $_GET['trxref'] ?? '';
if (empty($reference)) {
    die("Error: No transaction reference provided.");
}

$secretKey = $config['paystack_secret_key'] ?? '';

// 2. Verify with Paystack API
$url = "https://api.paystack.co/transaction/verify/" . rawurlencode($reference);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $secretKey",
    "Cache-Control: no-cache",
]);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    die("Curl Error: " . $err);
}

$tranx = json_decode($response, true);

// 3. Check if Paystack actually says "success"
if (!$tranx['status'] || $tranx['data']['status'] !== 'success') {
    echo "Paystack Verification Failed. Response: <pre>";
    print_r($tranx);
    die();
}

// 4. FIND THE PAYMENT RECORD
// Scope to the logged-in user's own booking so one user can't confirm another user's payment.
$stmt = $pdo->prepare("
    SELECT bp.id as payment_id, bp.booking_id, b.agreed_cost, b.payment_status
    FROM booking_payments bp
    JOIN bookings b ON b.id = bp.booking_id
    WHERE bp.reference = ? AND b.sender_user_id = ?
");
$stmt->execute([$reference, $user['id']]);
$payment = $stmt->fetch();

if (!$payment) {
    // If this fails, the reference in your DB doesn't match the one Paystack sent back
    die("Error: Reference $reference not found in database.");
}

// 5. COMPARE AMOUNTS (The most common point of failure)
$expectedKobo = (int)round($payment['agreed_cost'] * 100);
$paidKobo = (int)$tranx['data']['amount'];

// If there's a huge mismatch, stop. (Using a 5 kobo margin for safety)
if (abs($expectedKobo - $paidKobo) > 5) {
    die("Amount Mismatch! System expected $expectedKobo kobo, but user paid $paidKobo kobo.");
}

// 6. UPDATE DATABASE
try {
    $pdo->beginTransaction();

    // Update Payment Log
    $updatePay = $pdo->prepare("UPDATE booking_payments SET status = 'success', paid_at = NOW() WHERE id = ?");
    $updatePay->execute([$payment['payment_id']]);

    // Update Booking Record - FORCE 'paid' status
    $updateBooking = $pdo->prepare("UPDATE bookings SET payment_status = 'paid' WHERE id = ?");
    $updateBooking->execute([$payment['booking_id']]);

    $pdo->commit();

    flash('success', 'Payment successful! ');
    header("Location: " . base_url() . "bookings/index.php?id=" . $payment['booking_id']);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("Database Update Failed: " . $e->getMessage());
}