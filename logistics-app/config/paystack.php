<?php
require_once __DIR__ . '/functions.php';

// Only the SECRET key is used here. Transfers/bank-resolve/bank-list are server-to-server
// Paystack endpoints that always authenticate with the secret key - the public key is only
// ever used client-side, by the Paystack Inline widget in payments/ that collects sender
// payments. There is no "public key" role in the payout side of the API.
function paystack_secret_key(): string {
    return trim((string)(config_app()['paystack_secret_key'] ?? ''));
}

function paystack_configured(): bool {
    $key = paystack_secret_key();
    return $key !== '' && !str_starts_with($key, 'REDACTED');
}

function paystack_request(string $method, string $endpoint, ?array $body = null): array {
    if (!paystack_configured()) {
        return ['ok' => false, 'http_code' => 0, 'data' => null, 'message' => 'Paystack is not configured.'];
    }

    $ch = curl_init('https://api.paystack.co' . $endpoint);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . paystack_secret_key(),
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http_code' => 0, 'data' => null, 'message' => 'Could not reach Paystack: ' . $curlError];
    }

    $decoded = json_decode($response, true);
    $message = trim((string)($decoded['message'] ?? ''));
    $ok = $httpCode >= 200 && $httpCode < 300 && !empty($decoded['status']);

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'data' => $decoded['data'] ?? null,
        'message' => $message !== '' ? $message : ($ok ? 'OK' : 'Paystack request failed.'),
    ];
}

// Paystack's bank list barely changes - refresh the cache at most weekly rather than
// hitting the API on every wallet page load.
function paystack_sync_banks(PDO $pdo): bool {
    $result = paystack_request('GET', '/bank?country=nigeria&currency=NGN&type=nuban');
    if (!$result['ok'] || !is_array($result['data'])) {
        return false;
    }
    $stmt = $pdo->prepare('INSERT INTO paystack_banks (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
    foreach ($result['data'] as $bank) {
        $code = trim((string)($bank['code'] ?? ''));
        $name = trim((string)($bank['name'] ?? ''));
        if ($code === '' || $name === '') {
            continue;
        }
        $stmt->execute([$code, $name]);
    }
    return true;
}

function paystack_banks_list(PDO $pdo): array {
    $meta = $pdo->query('SELECT COUNT(*) AS c, MAX(updated_at) AS latest FROM paystack_banks')->fetch(PDO::FETCH_ASSOC);
    $count = (int)($meta['c'] ?? 0);
    $latest = $meta['latest'] ?? null;
    $stale = $count === 0 || $latest === null || strtotime((string) $latest) < strtotime('-7 days');

    if ($stale) {
        paystack_sync_banks($pdo);
    }

    return $pdo->query('SELECT code, name FROM paystack_banks ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function paystack_resolve_account(string $accountNumber, string $bankCode): array {
    $result = paystack_request('GET', '/bank/resolve?account_number=' . urlencode($accountNumber) . '&bank_code=' . urlencode($bankCode));
    if (!$result['ok'] || !is_array($result['data'])) {
        return ['ok' => false, 'account_name' => null, 'message' => $result['message']];
    }
    $accountName = trim((string)($result['data']['account_name'] ?? ''));
    if ($accountName === '') {
        return ['ok' => false, 'account_name' => null, 'message' => 'Could not verify this account.'];
    }
    return ['ok' => true, 'account_name' => $accountName, 'message' => 'Verified.'];
}

function paystack_create_transfer_recipient(string $accountName, string $accountNumber, string $bankCode): array {
    $result = paystack_request('POST', '/transferrecipient', [
        'type' => 'nuban',
        'name' => $accountName,
        'account_number' => $accountNumber,
        'bank_code' => $bankCode,
        'currency' => 'NGN',
    ]);
    $recipientCode = trim((string)($result['data']['recipient_code'] ?? ''));
    if (!$result['ok'] || $recipientCode === '') {
        return ['ok' => false, 'recipient_code' => null, 'message' => $result['message']];
    }
    return ['ok' => true, 'recipient_code' => $recipientCode, 'message' => 'Recipient created.'];
}

function paystack_initiate_transfer(string $recipientCode, float $amount, string $reason, string $reference): array {
    $result = paystack_request('POST', '/transfer', [
        'source' => 'balance',
        'amount' => (int) round($amount * 100),
        'recipient' => $recipientCode,
        'reason' => $reason,
        'reference' => $reference,
    ]);
    if (!$result['ok'] || !is_array($result['data'])) {
        return ['ok' => false, 'status' => null, 'transfer_code' => null, 'message' => $result['message']];
    }
    $transferCode = trim((string)($result['data']['transfer_code'] ?? ''));
    return [
        'ok' => true,
        'status' => (string)($result['data']['status'] ?? ''),
        'transfer_code' => $transferCode !== '' ? $transferCode : null,
        'message' => $result['message'],
    ];
}
