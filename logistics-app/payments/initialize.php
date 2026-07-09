<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$config = require __DIR__ . '/../config/env.php';
$user = current_user();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'status' => false,
            'message' => 'Invalid request method.'
        ]);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    require_csrf($input);

    $bookingId = (int)($input['booking_id'] ?? 0);

    if ($bookingId <= 0) {
        http_response_code(422);
        echo json_encode([
            'status' => false,
            'message' => 'Invalid booking ID.'
        ]);
        exit;
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
        http_response_code(404);
        echo json_encode([
            'status' => false,
            'message' => 'Booking not found.'
        ]);
        exit;
    }

    if (($booking['booking_status'] ?? '') !== 'delivered') {
        http_response_code(422);
        echo json_encode([
            'status' => false,
            'message' => 'Payment is only available after delivery.'
        ]);
        exit;
    }

    if (($booking['payment_status'] ?? '') === 'paid') {
        http_response_code(409);
        echo json_encode([
            'status' => false,
            'message' => 'This booking has already been paid.'
        ]);
        exit;
    }

    $amount = (float)($booking['agreed_cost'] ?? 0);
    if ($amount <= 0) {
        http_response_code(422);
        echo json_encode([
            'status' => false,
            'message' => 'Invalid agreed amount.'
        ]);
        exit;
    }

    $email = trim((string)($booking['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode([
            'status' => false,
            'message' => 'Customer email is missing or invalid.'
        ]);
        exit;
    }

    $secretKey = trim((string)($config['paystack_secret_key'] ?? ''));
    $publicKey = trim((string)($config['paystack_public_key'] ?? ''));
    $appUrl = rtrim(trim((string)($config['app_url'] ?? '')), '/');

    if ($secretKey === '' || $publicKey === '' || $appUrl === '') {
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Payment configuration is incomplete.'
        ]);
        exit;
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
            'booking_id'   => (int)$booking['id'],
            'booking_code' => (string)$booking['booking_code'],
            'user_id'      => (int)$user['id']
        ]
    ];

    $ch = curl_init('https://api.paystack.co/transaction/initialize');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_HTTPHEADER      => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS      => json_encode($payload),
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_CONNECTTIMEOUT  => 15,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        http_response_code(502);
        echo json_encode([
            'status' => false,
            'message' => 'Could not reach Paystack: ' . $curlError
        ]);
        exit;
    }

    $data = json_decode($response, true);
    $accessCode = trim((string)($data['data']['access_code'] ?? ''));
    $message = trim((string)($data['message'] ?? ''));

    if (
        $httpCode !== 200 ||
        empty($data['status']) ||
        empty($data['data']) ||
        $accessCode === ''
    ) {
        http_response_code(502);
        echo json_encode([
            'status' => false,
            'message' => $message !== '' ? $message : 'Paystack initialization failed.'
        ]);
        exit;
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

        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Database update failed: ' . $e->getMessage()
        ]);
        exit;
    }

    echo json_encode([
        'status' => true,
        'message' => 'Payment initialized successfully.',
        'data' => [
            'public_key'   => $publicKey,
            'email'        => $email,
            'amount'       => $amountKobo,
            'reference'    => $reference,
            'access_code'  => $accessCode,
            'currency'     => 'NGN',
            'callback_url' => $callbackUrl,
            'metadata'     => [
                'booking_id'   => (int)$booking['id'],
                'booking_code' => (string)$booking['booking_code'],
                'user_id'      => (int)$user['id']
            ]
        ]
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'message' => 'Unexpected server error: ' . $e->getMessage()
    ]);
    exit;
}