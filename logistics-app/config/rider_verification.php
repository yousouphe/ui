<?php
// Gates a rider's withdrawal request or bank-account change behind a 6-digit code (SMS) and an
// email confirmation link, sent together - either one confirms the pending action. Modeled
// directly on config/otp.php (admin balance-view OTP), but rider-keyed and dual-channel: the
// pending action's parameters are stored here rather than written to their real table until
// confirmed (see execute_rider_verified_action()), so nothing is created/changed until the rider
// proves it's actually them.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/receipts.php'; // audit_financial_event()
require_once __DIR__ . '/emails.php';
require_once __DIR__ . '/ebulksms.php';

const RIDER_VERIFICATION_TTL_SECONDS = 600;   // 10 minutes
const RIDER_VERIFICATION_MAX_PER_WINDOW = 5;  // starts per rider per TTL window
const RIDER_VERIFICATION_MAX_ATTEMPTS = 5;    // code attempts before it's burned

function rider_verification_rate_limited(PDO $pdo, int $riderUserId): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rider_action_verifications
        WHERE rider_user_id = ? AND created_at >= (NOW() - INTERVAL ? SECOND)');
    $stmt->execute([$riderUserId, RIDER_VERIFICATION_TTL_SECONDS]);
    return (int) $stmt->fetchColumn() >= RIDER_VERIFICATION_MAX_PER_WINDOW;
}

/**
 * Starts a verification for $actionType ('withdrawal'|'bank_change') with the given
 * JSON-encodable $payload. Sends both an SMS code and an email confirmation link.
 * Returns ['ok'=>bool, 'verificationId'=>?int, 'message'=>string].
 */
function rider_verification_start(PDO $pdo, array $rider, string $actionType, array $payload): array {
    $riderId = (int) $rider['id'];
    if (rider_verification_rate_limited($pdo, $riderId)) {
        audit_financial_event($pdo, 'rider_verification_failed', 'Rider verification rate-limited', $riderId, (string) $rider['role'], null, null, ['action_type' => $actionType, 'reason' => 'rate_limited']);
        return ['ok' => false, 'verificationId' => null, 'message' => 'Too many attempts. Please wait a few minutes and try again.'];
    }

    // Burn any prior unused verification of the same type for this rider - only the newest works.
    $pdo->prepare('UPDATE rider_action_verifications SET used_at = NOW()
        WHERE rider_user_id = ? AND action_type = ? AND used_at IS NULL')
        ->execute([$riderId, $actionType]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $linkToken = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare('INSERT INTO rider_action_verifications
        (rider_user_id, action_type, payload_json, code_hash, link_token_hash, expires_at)
        VALUES (?, ?, ?, ?, ?, (NOW() + INTERVAL ? SECOND))');
    $stmt->execute([
        $riderId, $actionType, json_encode($payload),
        hash('sha256', $code), hash('sha256', $linkToken),
        RIDER_VERIFICATION_TTL_SECONDS,
    ]);
    $verificationId = (int) $pdo->lastInsertId();

    $actionLabel = $actionType === 'withdrawal' ? 'withdrawal request' : 'bank account change';
    $confirmUrl = rtrim((string) (config_app()['app_url'] ?? ''), '/') . '/confirm_rider_action.php?token=' . $linkToken;

    $smsOk = false;
    $emailOk = false;
    $phone = trim((string) ($rider['phone'] ?? ''));
    if ($phone !== '' && ebulksms_configured()) {
        $smsResult = ebulksms_send_sms($phone, "Your Aike code to confirm your $actionLabel is $code. It expires in 10 minutes. If you didn't request this, ignore this message.");
        $smsOk = (bool) ($smsResult['ok'] ?? false);
    }
    try {
        send_rider_action_confirmation_email((string) $rider['email'], (string) $rider['full_name'], $actionLabel, $code, $confirmUrl);
        $emailOk = true;
    } catch (Throwable $e) {
        error_log('Rider verification email failed: ' . $e->getMessage());
    }

    if (!$smsOk && !$emailOk) {
        // Unlike other notification emails in this codebase, total delivery failure here must
        // surface rather than being silently swallowed - the rider would otherwise have no way to
        // ever receive a code for an action that's now sitting unconfirmed.
        $pdo->prepare('UPDATE rider_action_verifications SET used_at = NOW() WHERE id = ?')->execute([$verificationId]);
        audit_financial_event($pdo, 'rider_verification_failed', 'Could not deliver verification code via SMS or email for ' . $actionType, $riderId, (string) $rider['role'], null, null, ['action_type' => $actionType]);
        return ['ok' => false, 'verificationId' => null, 'message' => 'We could not send your verification code. Please check your phone number and email, then try again.'];
    }

    audit_financial_event($pdo, 'rider_verification_started', 'Verification code sent for ' . $actionType, $riderId, (string) $rider['role'], null, null, ['action_type' => $actionType, 'sms_sent' => $smsOk, 'email_sent' => $emailOk]);
    return ['ok' => true, 'verificationId' => $verificationId, 'message' => 'A verification code was sent to your phone and email.'];
}

/** Confirms a pending verification by its 6-digit code, from a logged-in rider session. */
function rider_verification_confirm_code(PDO $pdo, array $rider, int $verificationId, string $code): array {
    $riderId = (int) $rider['id'];
    $code = trim($code);

    $stmt = $pdo->prepare('SELECT * FROM rider_action_verifications
        WHERE id = ? AND rider_user_id = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1');
    $stmt->execute([$verificationId, $riderId]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verification) {
        audit_financial_event($pdo, 'rider_verification_failed', 'Verification code check failed (expired or missing)', $riderId, (string) $rider['role'], null, null, ['verification_id' => $verificationId, 'reason' => 'expired_or_missing']);
        return ['ok' => false, 'actionType' => null, 'payload' => null, 'message' => 'This verification code has expired or is invalid. Please start again.'];
    }

    $attempts = (int) $verification['attempts'] + 1;
    if ($attempts > RIDER_VERIFICATION_MAX_ATTEMPTS) {
        $pdo->prepare('UPDATE rider_action_verifications SET used_at = NOW() WHERE id = ?')->execute([$verificationId]);
        audit_financial_event($pdo, 'rider_verification_failed', 'Verification blocked (too many attempts)', $riderId, (string) $rider['role'], null, null, ['verification_id' => $verificationId, 'reason' => 'too_many_attempts']);
        return ['ok' => false, 'actionType' => null, 'payload' => null, 'message' => 'Too many incorrect attempts. Please start again.'];
    }
    $pdo->prepare('UPDATE rider_action_verifications SET attempts = ? WHERE id = ?')->execute([$attempts, $verificationId]);

    if (!hash_equals((string) $verification['code_hash'], hash('sha256', $code))) {
        audit_financial_event($pdo, 'rider_verification_failed', 'Verification code mismatch', $riderId, (string) $rider['role'], null, null, ['verification_id' => $verificationId, 'attempt' => $attempts]);
        return ['ok' => false, 'actionType' => null, 'payload' => null, 'message' => 'Incorrect code. Please try again.'];
    }

    $pdo->prepare('UPDATE rider_action_verifications SET used_at = NOW() WHERE id = ?')->execute([$verificationId]);
    $payload = json_decode((string) $verification['payload_json'], true) ?: [];
    audit_financial_event($pdo, 'rider_verification_confirmed', 'Verification confirmed via SMS code for ' . $verification['action_type'], $riderId, (string) $rider['role'], null, null, ['verification_id' => $verificationId, 'action_type' => $verification['action_type']]);
    return ['ok' => true, 'actionType' => (string) $verification['action_type'], 'payload' => $payload, 'message' => 'Verified.'];
}

/**
 * Confirms a pending verification by its emailed link token - no rider session, since this is
 * called from the public confirm_rider_action.php page a rider reaches straight from their inbox.
 */
function rider_verification_confirm_token(PDO $pdo, string $token): array {
    $stmt = $pdo->prepare('SELECT * FROM rider_action_verifications
        WHERE link_token_hash = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verification) {
        return ['ok' => false, 'riderUserId' => null, 'actionType' => null, 'payload' => null, 'message' => 'This confirmation link has expired or was already used.'];
    }

    $pdo->prepare('UPDATE rider_action_verifications SET used_at = NOW() WHERE id = ?')->execute([(int) $verification['id']]);
    $payload = json_decode((string) $verification['payload_json'], true) ?: [];
    $riderId = (int) $verification['rider_user_id'];
    audit_financial_event($pdo, 'rider_verification_confirmed', 'Verification confirmed via email link for ' . $verification['action_type'], $riderId, 'rider', null, null, ['verification_id' => (int) $verification['id'], 'action_type' => $verification['action_type']]);
    return ['ok' => true, 'riderUserId' => $riderId, 'actionType' => (string) $verification['action_type'], 'payload' => $payload, 'message' => 'Verified.'];
}

/**
 * Executes the actual pending action (the real write to rider_bank_accounts or
 * withdrawal_requests) once a verification has been confirmed by either channel - this is the
 * single place that write logic lives, called from both the code-confirm API endpoint and the
 * email-link landing page, so it can never drift between the two paths.
 */
function execute_rider_verified_action(PDO $pdo, int $riderUserId, string $actionType, array $payload): array {
    if ($actionType === 'bank_change') {
        $pdo->prepare('INSERT INTO rider_bank_accounts (rider_user_id, bank_name, bank_code, account_number, account_name, verified_at, paystack_recipient_code)
                       VALUES (?, ?, ?, ?, ?, NOW(), NULL)
                       ON DUPLICATE KEY UPDATE bank_name = VALUES(bank_name), bank_code = VALUES(bank_code),
                           account_number = VALUES(account_number), account_name = VALUES(account_name),
                           verified_at = VALUES(verified_at), paystack_recipient_code = NULL')
            ->execute([$riderUserId, $payload['bankName'] ?? '', $payload['bankCode'] ?? '', $payload['accountNumber'] ?? '', $payload['accountName'] ?? '']);
        log_event($pdo, 'rider_bank_changed', 'Rider bank account updated (verified)', $riderUserId, 'rider', 'user', $riderUserId);
        return ['ok' => true, 'message' => 'Your bank account has been saved.'];
    }

    if ($actionType === 'withdrawal') {
        $amount = (float) ($payload['amount'] ?? 0);
        $stmt = $pdo->prepare('SELECT bank_name, bank_code, account_number, account_name, verified_at FROM rider_bank_accounts WHERE rider_user_id = ? LIMIT 1');
        $stmt->execute([$riderUserId]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bank || empty($bank['bank_code']) || empty($bank['verified_at'])) {
            return ['ok' => false, 'message' => 'Add and verify your bank account before requesting a withdrawal.'];
        }
        try {
            $pdo->beginTransaction();
            // Authoritative, row-locked balance check happens here (not at verification-start
            // time) so a rider can't start two verifications against the same balance and confirm
            // both before either is debited.
            $available = rider_available_balance_locked($pdo, $riderUserId);
            if ($amount > $available) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'That amount exceeds your available balance.'];
            }
            $pdo->prepare('INSERT INTO withdrawal_requests (rider_user_id, amount, bank_name, bank_code, account_number, account_name, status) VALUES (?, ?, ?, ?, ?, ?, "pending")')
                ->execute([$riderUserId, $amount, $bank['bank_name'], $bank['bank_code'], $bank['account_number'], $bank['account_name']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('execute_rider_verified_action withdrawal failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'We could not submit your withdrawal right now. Please try again.'];
        }
        $stmt = $pdo->prepare('SELECT email, full_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$riderUserId]);
        $riderInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($riderInfo) {
            try { send_withdrawal_requested_email((string) $riderInfo['email'], (string) $riderInfo['full_name'], $amount); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'message' => 'Withdrawal request submitted.'];
    }

    return ['ok' => false, 'message' => 'Unknown action type.'];
}
