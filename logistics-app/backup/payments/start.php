<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

$config = require __DIR__ . '/../config/env.php';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('bookings/index.php');
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    flash('error', 'Invalid booking ID.');
    redirect_to('bookings/index.php');
}

$stmt = $pdo->prepare("
    SELECT b.*, u.email
    FROM bookings b
    INNER JOIN users u ON u.id = b.sender_user_id
    WHERE b.id = ? AND b.sender_user_id = ?
    LIMIT 1
");
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    flash('error', 'Booking not found.');
    redirect_to('bookings/index.php');
}

if (($booking['booking_status'] ?? '') !== 'delivered') {
    flash('error', 'Payment is only available after delivery.');
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

if (($booking['payment_status'] ?? '') === 'paid') {
    flash('success', 'This booking has already been paid.');
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

$amount = (float)($booking['agreed_cost'] ?? 0);
if ($amount <= 0) {
    flash('error', 'Invalid agreed amount.');
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

$email = trim((string)($booking['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Customer email is missing or invalid.');
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

$secretKey = trim((string)($config['paystack_secret_key'] ?? ''));
$appUrl = rtrim(trim((string)($config['app_url'] ?? '')), '/');

if ($secretKey === '' || $appUrl === '') {
    flash('error', 'Payment configuration is incomplete.');
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

$callbackUrl = $appUrl . '/payments/callback.php';
$reference = 'PAY-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string)$booking['booking_code']) . '-' . time();
$amountKobo = (int) round($amount * 100);

$payload = [
    'email' => $email,
    'amount' => $amountKobo,
    'reference' => $reference,
    'currency' => 'NGN',
    'callback_url' => $callbackUrl,
    'metadata' => [
        'booking_id' => (int)$booking['id'],
        'booking_code' => (string)$booking['booking_code'],
        'user_id' => (int)$user['id']
    ]
];

$ch = curl_init('https://api.paystack.co/transaction/initialize');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    flash('error', 'Could not reach Paystack: ' . $curlError);
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

$data = json_decode($response, true);
$authorizationUrl = trim((string)($data['data']['authorization_url'] ?? ''));
$accessCode = trim((string)($data['data']['access_code'] ?? ''));

if (
    $httpCode !== 200 ||
    empty($data['status']) ||
    empty($data['data']) ||
    $authorizationUrl === ''
) {
    $message = $data['message'] ?? 'Paystack initialization failed.';
    flash('error', $message);
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

try {
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    $stmt = $pdo->prepare("
        INSERT INTO booking_payments (
            booking_id, user_id, amount, currency, reference, access_code, status
        ) VALUES (?, ?, ?, 'NGN', ?, ?, 'initialized')
    ");
    $stmt->execute([
        $booking['id'],
        $user['id'],
        $amount,
        $reference,
        $accessCode !== '' ? $accessCode : null
    ]);

    $stmt = $pdo->prepare("
        UPDATE bookings
        SET payment_status = 'pending',
            paystack_reference = ?,
            paystack_access_code = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $reference,
        $accessCode !== '' ? $accessCode : null,
        $booking['id']
    ]);

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('error', 'Database update failed: ' . $e->getMessage());
    redirect_to('bookings/index.php?booking_id=' . $bookingId);
}

header('Location: ' . $authorizationUrl);
exit;