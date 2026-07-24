<?php
// One-time passwords that gate an admin's "View Balance" action (module19). A code is emailed to the
// admin's registered address, expires in 5 minutes, is single-use, rate-limited, and every
// generate / verify / fail is written to the financial audit log (event_logs via audit_financial_event).
//
// The code is stored only as a sha256 hash; the plaintext lives only in the email.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/receipts.php'; // audit_financial_event()
require_once __DIR__ . '/emails.php';

const ADMIN_OTP_TTL_SECONDS = 300;      // 5 minutes
const ADMIN_OTP_MAX_PER_WINDOW = 5;     // generations per admin per TTL window
const ADMIN_OTP_MAX_ATTEMPTS = 5;       // verify attempts before a code is burned

/** True if this admin has generated too many OTPs in the last TTL window. */
function admin_otp_rate_limited(PDO $pdo, int $adminUserId): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_balance_otps
        WHERE admin_user_id = ? AND created_at >= (NOW() - INTERVAL ? SECOND)');
    $stmt->execute([$adminUserId, ADMIN_OTP_TTL_SECONDS]);
    return (int) $stmt->fetchColumn() >= ADMIN_OTP_MAX_PER_WINDOW;
}

/**
 * Generate + email an OTP for viewing $targetType/$targetId's balance. Returns [ok, message].
 * Invalidates any earlier unused codes for the same admin+target so only the newest works.
 */
function generate_admin_balance_otp(PDO $pdo, array $admin, string $targetType, int $targetId): array {
    $adminId = (int) $admin['id'];
    if (admin_otp_rate_limited($pdo, $adminId)) {
        audit_financial_event($pdo, 'otp_failed', 'OTP generation rate-limited', $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId, 'reason' => 'rate_limited']);
        return ['ok' => false, 'message' => t('admin.tx.otp_rate_limited')];
    }

    // Burn any prior unused codes for this admin+target.
    $pdo->prepare('UPDATE admin_balance_otps SET used_at = NOW()
        WHERE admin_user_id = ? AND target_type = ? AND target_id = ? AND used_at IS NULL')
        ->execute([$adminId, $targetType, $targetId]);

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO admin_balance_otps
        (admin_user_id, code_hash, target_type, target_id, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, (NOW() + INTERVAL ? SECOND))')
        ->execute([
            $adminId,
            hash('sha256', $code),
            $targetType,
            $targetId,
            client_ip(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ADMIN_OTP_TTL_SECONDS,
        ]);

    try {
        send_admin_balance_otp_email((string) $admin['email'], (string) $admin['full_name'], $code);
    } catch (Throwable $e) {
        error_log('OTP email failed: ' . $e->getMessage());
    }

    audit_financial_event($pdo, 'otp_generated', 'Balance-view OTP generated for ' . $targetType . ' #' . $targetId, $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId]);
    return ['ok' => true, 'message' => t('admin.tx.otp_sent')];
}

/**
 * Verify a code for viewing $targetType/$targetId's balance. Returns [ok, message]. On success the
 * code is consumed (single-use); on failure the attempt is counted and the code burned after the
 * attempt cap. Every outcome is audited (otp_verified / otp_failed + balance_viewed).
 */
function verify_admin_balance_otp(PDO $pdo, array $admin, string $targetType, int $targetId, string $code): array {
    $adminId = (int) $admin['id'];
    $code = trim($code);

    $stmt = $pdo->prepare('SELECT * FROM admin_balance_otps
        WHERE admin_user_id = ? AND target_type = ? AND target_id = ? AND used_at IS NULL
          AND expires_at >= NOW()
        ORDER BY id DESC LIMIT 1');
    $stmt->execute([$adminId, $targetType, $targetId]);
    $otp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otp) {
        audit_financial_event($pdo, 'balance_viewed', 'Balance-view verification failed (no valid code) for ' . $targetType . ' #' . $targetId, $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId, 'result' => 'failed', 'reason' => 'expired_or_missing']);
        return ['ok' => false, 'message' => t('admin.tx.otp_invalid')];
    }

    // Count the attempt; burn the code if it exceeds the cap.
    $attempts = (int) $otp['attempts'] + 1;
    if ($attempts > ADMIN_OTP_MAX_ATTEMPTS) {
        $pdo->prepare('UPDATE admin_balance_otps SET used_at = NOW() WHERE id = ?')->execute([(int) $otp['id']]);
        audit_financial_event($pdo, 'balance_viewed', 'Balance-view blocked (too many attempts) for ' . $targetType . ' #' . $targetId, $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId, 'result' => 'failed', 'reason' => 'too_many_attempts']);
        return ['ok' => false, 'message' => t('admin.tx.otp_too_many')];
    }
    $pdo->prepare('UPDATE admin_balance_otps SET attempts = ? WHERE id = ?')->execute([$attempts, (int) $otp['id']]);

    if (!hash_equals((string) $otp['code_hash'], hash('sha256', $code))) {
        audit_financial_event($pdo, 'otp_failed', 'Balance-view OTP mismatch for ' . $targetType . ' #' . $targetId, $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId, 'attempt' => $attempts]);
        audit_financial_event($pdo, 'balance_viewed', 'Balance-view verification failed for ' . $targetType . ' #' . $targetId, $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId, 'result' => 'failed', 'reason' => 'mismatch']);
        return ['ok' => false, 'message' => t('admin.tx.otp_invalid')];
    }

    // Success: consume the code.
    $pdo->prepare('UPDATE admin_balance_otps SET used_at = NOW() WHERE id = ?')->execute([(int) $otp['id']]);
    audit_financial_event($pdo, 'otp_verified', 'Balance-view OTP verified for ' . $targetType . ' #' . $targetId, $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId]);
    audit_financial_event($pdo, 'balance_viewed', 'Balance revealed for ' . $targetType . ' #' . $targetId, $adminId, (string) $admin['role'], null, null, ['target_type' => $targetType, 'target_id' => $targetId, 'result' => 'success']);
    return ['ok' => true, 'message' => t('admin.tx.balance_unlocked')];
}
