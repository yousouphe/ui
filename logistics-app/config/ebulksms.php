<?php
require_once __DIR__ . '/functions.php';

// EbulkSMS (ebulksms.com) - sends both regular SMS and WhatsApp text messages through the same
// account (username = your EbulkSMS login email, apikey = generated on their dashboard). Both
// send endpoints use the same auth pair, so one "configured" check covers both channels.
function ebulksms_username(): string {
    return trim((string) (config_app()['ebulksms_username'] ?? ''));
}

function ebulksms_apikey(): string {
    return trim((string) (config_app()['ebulksms_apikey'] ?? ''));
}

// Alphanumeric sender name shown as the "from" on SMS (max 11 chars) - not used for WhatsApp,
// which instead sends from the WhatsApp number connected to the EbulkSMS account.
function ebulksms_sender_name(): string {
    $name = trim((string) (config_app()['ebulksms_sender_name'] ?? ''));
    return $name !== '' ? substr($name, 0, 11) : 'Aike';
}

function ebulksms_configured(): bool {
    $user = ebulksms_username();
    $key = ebulksms_apikey();
    return $user !== '' && !str_starts_with($user, 'REDACTED')
        && $key !== '' && !str_starts_with($key, 'REDACTED');
}

// EbulkSMS expects full international format with no leading + or 0 (e.g. "2348012345678").
// Nigerian numbers are stored in a few different shapes depending on where they were entered
// (web registration form vs mobile app), so this normalizes all of them to the one format the
// API actually accepts.
function ebulksms_normalize_phone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (str_starts_with($digits, '234')) {
        return $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '234' . substr($digits, 1);
    }
    return '234' . $digits;
}

function ebulksms_send_sms(string $phone, string $message): array {
    if (!ebulksms_configured()) {
        return ['ok' => false, 'message' => 'EbulkSMS is not configured.'];
    }
    $body = [
        'SMS' => [
            'auth' => ['username' => ebulksms_username(), 'apikey' => ebulksms_apikey()],
            'message' => ['sender' => ebulksms_sender_name(), 'messagetext' => $message, 'flash' => '0'],
            'recipients' => ['gsm' => [['msidn' => ebulksms_normalize_phone($phone), 'msgid' => uniqid('sms_', true)]]],
            'dndsender' => 1,
        ],
    ];
    return ebulksms_post('https://api.ebulksms.com/sendsms.json', $body);
}

function ebulksms_send_whatsapp(string $phone, string $message, string $subject = 'Aike delivery update'): array {
    if (!ebulksms_configured()) {
        return ['ok' => false, 'message' => 'EbulkSMS is not configured.'];
    }
    $body = [
        'WA' => [
            'auth' => ['username' => ebulksms_username(), 'apikey' => ebulksms_apikey()],
            'message' => ['subject' => $subject, 'messagetext' => $message],
            'recipients' => [ebulksms_normalize_phone($phone)],
        ],
    ];
    return ebulksms_post('https://api.ebulksms.com/sendwhatsapp.json', $body);
}

function ebulksms_post(string $url, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => 'Could not reach EbulkSMS: ' . $curlError];
    }
    $decoded = json_decode($response, true);
    $status = strtoupper(trim((string) ($decoded['response']['status'] ?? '')));
    return ['ok' => $status === 'SUCCESS', 'status' => $status, 'message' => $status !== '' ? $status : 'Unrecognized EbulkSMS response.'];
}

// Order-summary message sent to the delivery recipient (not the sender), since they never opened
// the app or chose to expect a delivery - this is their only advance notice of what's coming.
// Sent once a rider is actually assigned (booking_status -> "matched"), not at booking creation -
// the recipient needs a real rider to identify/contact, which doesn't exist until this point.
// Self-contained (only needs the booking id) so every place a booking can become matched - sender
// force-match, rider accepting an offer, on both web and mobile - can call this the same way
// without each call site having to assemble the same joined data itself.
function ebulksms_notify_recipient_rider_assigned(PDO $pdo, int $bookingId): void {
    if (!ebulksms_configured()) {
        return;
    }
    $stmt = $pdo->prepare('
        SELECT b.recipient_name, b.recipient_phone, b.item_name, b.sender_tracking_token,
               s.full_name AS sender_full_name,
               r.full_name AS rider_full_name, r.phone AS rider_phone
        FROM bookings b
        INNER JOIN users s ON s.id = b.sender_user_id
        LEFT JOIN users r ON r.id = b.selected_rider_user_id
        WHERE b.id = ?
        LIMIT 1
    ');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $recipientPhone = trim((string) ($row['recipient_phone'] ?? ''));
    if ($recipientPhone === '') {
        return;
    }
    $trackingUrl = url_path('bookings/track.php?token=' . urlencode((string) $row['sender_tracking_token']));
    $message = sprintf(
        "Hi %s, %s has sent an item to you via Aike Logistics.\nItem: %s\n Rider: %s\n Contact: %s \nUse the below link for tracking: \n %s",
        $row['recipient_name'] ?: 'there',
        $row['sender_full_name'] ?: 'A sender',
        $row['item_name'] ?: 'a package',
        $row['rider_full_name'] ?: 'Not yet available',
        $row['rider_phone'] ?: 'Not yet available',
        $trackingUrl
    );
    try {
        ebulksms_send_sms($recipientPhone, $message);
        ebulksms_send_whatsapp($recipientPhone, $message);
    } catch (Throwable $e) {
        // Best-effort, same as email/push notifications elsewhere - never blocks the request.
    }
}
