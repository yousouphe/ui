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

function paystack_verify_transaction(string $reference): array {
    $result = paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
    if (!$result['ok'] || !is_array($result['data'])) {
        return ['ok' => false, 'status' => null, 'amount_kobo' => 0, 'currency' => null, 'message' => $result['message']];
    }
    $data = $result['data'];
    return [
        'ok' => true,
        'status' => strtolower(trim((string)($data['status'] ?? ''))),
        'amount_kobo' => (int)($data['amount'] ?? 0),
        'currency' => strtoupper(trim((string)($data['currency'] ?? 'NGN'))),
        'message' => 'Verified.',
    ];
}

// Shared by the browser-redirect callback (payments/callback.php) and the server-to-server
// webhook (payments/webhook.php) so a payment only ever gets marked paid once, no matter
// which path finds out about it first or whether the sender's browser ever makes it back
// to the callback URL. Always re-verifies directly with Paystack rather than trusting the
// caller's data, and is a safe no-op to call repeatedly once the booking is already paid.
function finalize_booking_payment(PDO $pdo, string $reference): array {
    require_once __DIR__ . '/emails.php';

    $stmt = $pdo->prepare("
        SELECT
            bp.*,
            b.id AS booking_id,
            b.sender_user_id,
            b.booking_code,
            b.item_name,
            b.payment_status AS booking_payment_status,
            b.agreed_cost,
            u.full_name AS sender_full_name,
            u.email AS sender_email
        FROM booking_payments bp
        INNER JOIN bookings b ON b.id = bp.booking_id
        INNER JOIN users u ON u.id = b.sender_user_id
        WHERE bp.reference = ?
        LIMIT 1
    ");
    $stmt->execute([$reference]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        return ['ok' => false, 'already_paid' => false, 'booking_id' => null, 'message' => 'Payment record not found.'];
    }

    if (($payment['booking_payment_status'] ?? '') === 'paid') {
        return ['ok' => true, 'already_paid' => true, 'booking_id' => (int)$payment['booking_id'], 'message' => 'Payment has already been confirmed.'];
    }

    $verified = paystack_verify_transaction($reference);
    if (!$verified['ok']) {
        return ['ok' => false, 'already_paid' => false, 'booking_id' => (int)$payment['booking_id'], 'message' => $verified['message']];
    }
    if ($verified['status'] !== 'success') {
        return ['ok' => false, 'already_paid' => false, 'booking_id' => (int)$payment['booking_id'], 'message' => 'Payment was not successful.'];
    }

    $expectedAmountKobo = (int) round(((float)$payment['agreed_cost']) * 100);
    if ($verified['amount_kobo'] < $expectedAmountKobo) {
        return ['ok' => false, 'already_paid' => false, 'booking_id' => (int)$payment['booking_id'], 'message' => 'Verified payment amount is less than expected.'];
    }
    if ($verified['currency'] !== 'NGN') {
        return ['ok' => false, 'already_paid' => false, 'booking_id' => (int)$payment['booking_id'], 'message' => 'Unexpected payment currency returned from gateway.'];
    }

    $wasInTransaction = $pdo->inTransaction();
    if (!$wasInTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("UPDATE booking_payments SET status = 'success', paid_at = NOW() WHERE id = ?");
        $stmt->execute([$payment['id']]);

        $stmt = $pdo->prepare("UPDATE bookings SET payment_status = 'paid', paystack_reference = ? WHERE id = ?");
        $stmt->execute([$reference, $payment['booking_id']]);

        if (!$wasInTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$wasInTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'already_paid' => false, 'booking_id' => (int)$payment['booking_id'], 'message' => 'Database update failed: ' . $e->getMessage()];
    }

    send_transaction_receipt_email((string)$payment['sender_email'], (string)$payment['sender_full_name'], [
        'booking_code' => $payment['booking_code'],
        'item_name' => $payment['item_name'],
        'agreed_cost' => $payment['agreed_cost'],
    ], $reference);

    log_event($pdo, 'payment_confirmed', 'Payment confirmed for booking ' . $payment['booking_code'], (int)$payment['sender_user_id'], 'sender', 'booking', (int)$payment['booking_id'], ['reference' => $reference, 'amount' => (float)$payment['agreed_cost']]);

    return ['ok' => true, 'already_paid' => false, 'booking_id' => (int)$payment['booking_id'], 'message' => 'Payment verified successfully.'];
}

// Full or partial refund of an already-paid transaction. Paystack processes refunds
// asynchronously - a successful call here means the refund was accepted, not that funds
// have landed back with the customer yet.
function paystack_initiate_refund(string $reference, ?int $amountKobo = null): array {
    $body = ['transaction' => $reference];
    if ($amountKobo !== null) {
        $body['amount'] = $amountKobo;
    }
    $result = paystack_request('POST', '/refund', $body);
    if (!$result['ok']) {
        return ['ok' => false, 'status' => null, 'message' => $result['message']];
    }
    return ['ok' => true, 'status' => (string)($result['data']['status'] ?? 'pending'), 'message' => $result['message']];
}

// Shared by admin/index.php's "Approve & Pay" success path, its manual "Mark as Paid"
// fallback, and the transfer.success webhook below, so a withdrawal only ever gets marked
// paid and ledgered once no matter which of those three finds out about it first.
// $adminUserId is null when this is called from the webhook (no admin session involved).
function finalize_withdrawal_paid(PDO $pdo, int $requestId, ?int $adminUserId, ?string $transferCode, ?string $reference): array {
    $stmt = $pdo->prepare('
        SELECT wr.*, u.full_name AS rider_full_name, u.email AS rider_email
        FROM withdrawal_requests wr
        INNER JOIN users u ON u.id = wr.rider_user_id
        WHERE wr.id = ?
        LIMIT 1
    ');
    $stmt->execute([$requestId]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$withdrawal) {
        return ['ok' => false, 'already_paid' => false, 'message' => 'Withdrawal request not found.'];
    }
    if ($withdrawal['status'] === 'paid') {
        return ['ok' => true, 'already_paid' => true, 'message' => 'Withdrawal was already paid.'];
    }
    if (!in_array($withdrawal['status'], ['pending', 'processing'], true)) {
        return ['ok' => false, 'already_paid' => false, 'message' => 'Withdrawal is not pending or processing.'];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            UPDATE withdrawal_requests
            SET status = "paid", admin_user_id = COALESCE(?, admin_user_id), processed_at = NOW(),
                paystack_transfer_code = COALESCE(?, paystack_transfer_code),
                paystack_transfer_reference = COALESCE(?, paystack_transfer_reference)
            WHERE id = ? AND status IN ("pending", "processing")
        ');
        $stmt->execute([$adminUserId, $transferCode, $reference, $requestId]);
        if ($stmt->rowCount() === 0) {
            // Lost a race with another path finalizing this same request - not an error.
            $pdo->rollBack();
            return ['ok' => true, 'already_paid' => true, 'message' => 'Withdrawal was already processed.'];
        }

        $stmt = $pdo->prepare('
            INSERT INTO wallet_transactions (rider_user_id, withdrawal_request_id, type, amount, description)
            VALUES (?, ?, "withdrawal", ?, ?)
        ');
        $description = $transferCode
            ? sprintf('Withdrawal to %s (%s) via Paystack', $withdrawal['bank_name'], $withdrawal['account_number'])
            : sprintf('Withdrawal to %s (%s)', $withdrawal['bank_name'], $withdrawal['account_number']);
        $stmt->execute([
            (int) $withdrawal['rider_user_id'],
            $requestId,
            -1 * (float) $withdrawal['amount'],
            $description,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'already_paid' => false, 'message' => 'Database update failed: ' . $e->getMessage()];
    }

    require_once __DIR__ . '/emails.php';
    require_once __DIR__ . '/push.php';
    send_withdrawal_status_email((string) $withdrawal['rider_email'], (string) $withdrawal['rider_full_name'], (float) $withdrawal['amount'], 'paid');
    send_web_push($pdo, (int) $withdrawal['rider_user_id'], 'Withdrawal paid', '₦' . number_format((float) $withdrawal['amount'], 2) . ' has been sent to your bank account.', url_path('rider/wallet'));
    log_event(
        $pdo,
        'withdrawal_paid',
        'Withdrawal #' . $requestId . ' paid' . ($transferCode ? ' via Paystack (' . $transferCode . ')' : ' (manual)'),
        $adminUserId,
        $adminUserId ? 'admin' : 'system',
        'withdrawal',
        $requestId,
        ['amount' => (float) $withdrawal['amount'], 'transfer_code' => $transferCode, 'reference' => $reference]
    );

    return ['ok' => true, 'already_paid' => false, 'message' => 'Withdrawal marked paid.'];
}

// Handles transfer.success / transfer.failed / transfer.reversed webhook events for rider
// withdrawal payouts (as opposed to finalize_booking_payment(), which handles charge.success
// for sender payments). Matches purely on the Paystack transfer reference we generated and
// stored when the transfer was initiated, so it's a safe no-op for references we don't
// recognize (e.g. transfers made directly from the Paystack dashboard, outside this app).
function finalize_withdrawal_transfer_event(PDO $pdo, string $eventType, string $reference): array {
    if ($reference === '') {
        return ['ok' => false, 'message' => 'No reference in event.'];
    }

    $stmt = $pdo->prepare('
        SELECT wr.*, u.full_name AS rider_full_name, u.email AS rider_email
        FROM withdrawal_requests wr
        INNER JOIN users u ON u.id = wr.rider_user_id
        WHERE wr.paystack_transfer_reference = ?
        LIMIT 1
    ');
    $stmt->execute([$reference]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$withdrawal) {
        return ['ok' => true, 'message' => 'No withdrawal matches this reference - ignoring.'];
    }

    if ($eventType === 'transfer.success') {
        $transferCode = trim((string) ($withdrawal['paystack_transfer_code'] ?? ''));
        return finalize_withdrawal_paid($pdo, (int) $withdrawal['id'], null, $transferCode !== '' ? $transferCode : 'webhook', $reference);
    }

    if (in_array($eventType, ['transfer.failed', 'transfer.reversed'], true)) {
        if ($withdrawal['status'] !== 'processing') {
            // Already resolved (paid/rejected) or never left pending - nothing to revert.
            return ['ok' => true, 'message' => 'Withdrawal not in processing state - ignoring.'];
        }

        $reason = $eventType === 'transfer.failed' ? 'Paystack transfer failed.' : 'Paystack transfer was reversed.';
        $stmt = $pdo->prepare('
            UPDATE withdrawal_requests
            SET status = "pending", admin_note = ?, paystack_transfer_attempted_at = NULL
            WHERE id = ? AND status = "processing"
        ');
        $stmt->execute([$reason, (int) $withdrawal['id']]);

        if ($stmt->rowCount() > 0) {
            require_once __DIR__ . '/emails.php';
            notify_admins($pdo, 'Rider withdrawal transfer failed', '<p>' . e($reason) . ' Withdrawal #' . (int) $withdrawal['id'] . ' for ' . e((string) $withdrawal['rider_full_name']) . ' (₦' . number_format((float) $withdrawal['amount'], 2) . ') has been reset to pending and needs to be retried.</p>');
            log_event($pdo, 'withdrawal_transfer_failed', $reason . ' Withdrawal #' . (int) $withdrawal['id'] . ' reset to pending.', null, 'system', 'withdrawal', (int) $withdrawal['id'], ['reference' => $reference, 'event' => $eventType]);
        }

        return ['ok' => true, 'message' => $reason];
    }

    return ['ok' => true, 'message' => 'Unhandled transfer event type: ' . $eventType];
}
