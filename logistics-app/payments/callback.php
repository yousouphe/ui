<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';

$config = require __DIR__ . '/../config/env.php';
$user = current_user();

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

$secretKey = trim((string)($config['paystack_secret_key'] ?? ''));
if ($secretKey === '') {
    flash('error', 'Payment configuration is incomplete.');
    go_to('/bookings/index.php');
}

try {
    $stmt = $pdo->prepare("
        SELECT
            bp.*,
            b.id AS booking_id,
            b.sender_user_id,
            b.booking_code,
            b.item_name,
            b.payment_status AS booking_payment_status,
            b.agreed_cost
        FROM booking_payments bp
        INNER JOIN bookings b ON b.id = bp.booking_id
        WHERE bp.reference = ?
          AND b.sender_user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$reference, $user['id']]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        flash('error', 'Payment record not found.');
        go_to('/bookings/index.php');
    }

    if (($payment['booking_payment_status'] ?? '') === 'paid') {
        flash('success', 'Payment has already been confirmed.');
        go_to('/bookings/index.php?booking_id=' . (int)$payment['booking_id']);
    }

    $verifyUrl = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);

    $ch = curl_init($verifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        flash('error', 'Could not verify payment: ' . $curlError);
        go_to('/bookings/index.php?booking_id=' . (int)$payment['booking_id']);
    }

    $result = json_decode($response, true);

    if (
        $httpCode !== 200 ||
        !is_array($result) ||
        empty($result['status']) ||
        empty($result['data'])
    ) {
        $message = $result['message'] ?? 'Unable to verify payment.';
        flash('error', $message);
        go_to('/bookings/index.php?booking_id=' . (int)$payment['booking_id']);
    }

    $trx = $result['data'];

    $gatewayStatus = strtolower(trim((string)($trx['status'] ?? '')));
    $paidAmountKobo = (int)($trx['amount'] ?? 0);
    $paidCurrency = strtoupper(trim((string)($trx['currency'] ?? 'NGN')));

    if ($gatewayStatus !== 'success') {
        flash('error', 'Payment was not successful.');
        go_to('/bookings/index.php?booking_id=' . (int)$payment['booking_id']);
    }

    $expectedAmountKobo = (int) round(((float)$payment['agreed_cost']) * 100);
    if ($paidAmountKobo < $expectedAmountKobo) {
        flash('error', 'Verified payment amount is less than expected.');
        go_to('/bookings/index.php?booking_id=' . (int)$payment['booking_id']);
    }

    if ($paidCurrency !== 'NGN') {
        flash('error', 'Unexpected payment currency returned from gateway.');
        go_to('/bookings/index.php?booking_id=' . (int)$payment['booking_id']);
    }

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    $stmt = $pdo->prepare("
        UPDATE booking_payments
        SET status = 'success'
        WHERE id = ?
    ");
    $stmt->execute([$payment['id']]);

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET payment_status = 'paid',
            paystack_reference = ?
        WHERE id = ?
    ");
    $stmt->execute([$reference, $payment['booking_id']]);

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    send_transaction_receipt_email($user['email'], $user['full_name'], [
        'booking_code' => $payment['booking_code'],
        'item_name' => $payment['item_name'],
        'agreed_cost' => $payment['agreed_cost'],
    ], $reference);

    log_event($pdo, 'payment_confirmed', 'Payment confirmed for booking ' . $payment['booking_code'], (int) $user['id'], (string) $user['role'], 'booking', (int) $payment['booking_id'], ['reference' => $reference, 'amount' => (float) $payment['agreed_cost']]);

    flash('success', 'Payment verified successfully.');
    go_to('/bookings/index.php?booking_id=' . (int)$payment['booking_id']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Payment verification failed: ' . $e->getMessage());
    go_to('/bookings/index.php');
}