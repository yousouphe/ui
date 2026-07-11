<?php
require_once __DIR__ . '/functions.php';

// Web Push notifications, implemented with no external dependency (consistent with the
// rest of this app - the SMTP mailer and Paystack integration are both hand-rolled too).
// PHP's openssl extension has no ECDH primitive, which is what you'd need to encrypt a
// push payload correctly - so instead of encrypting a payload, every push we send is an
// empty "wake up" signal (VAPID-authenticated, no body). The service worker (sw.js)
// reacts to that signal by fetching the actual notification content from
// notifications/ajax_fetch_pending.php over an authenticated same-origin request. This is
// a standard, well-supported pattern and avoids needing Composer/a vendor directory in an
// app that has deliberately had none until now.

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function vapid_private_key_pem(): string {
    return trim((string) (config_app()['vapid_private_key_pem'] ?? ''));
}

function vapid_configured(): bool {
    $pem = vapid_private_key_pem();
    return $pem !== '' && !str_starts_with($pem, 'REDACTED') && str_contains($pem, 'PRIVATE KEY');
}

// Raw uncompressed EC point (0x04 || X || Y) - this is exactly the format the browser's
// PushManager.subscribe({applicationServerKey: ...}) call expects, and doubles as the "k"
// parameter of the VAPID Authorization header.
function vapid_public_key_raw(): ?string {
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    if (!vapid_configured()) {
        return $cached = null;
    }
    $key = openssl_pkey_get_private(vapid_private_key_pem());
    if ($key === false) {
        return $cached = null;
    }
    $details = openssl_pkey_get_details($key);
    $x = $details['ec']['x'] ?? null;
    $y = $details['ec']['y'] ?? null;
    if ($x === null || $y === null) {
        return $cached = null;
    }
    $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);
    return $cached = "\x04" . $x . $y;
}

function vapid_public_key_b64url(): ?string {
    $raw = vapid_public_key_raw();
    return $raw !== null ? base64url_encode($raw) : null;
}

function vapid_public_key_meta_tag(): string {
    $key = vapid_public_key_b64url();
    return $key !== null ? '<meta name="vapid-public-key" content="' . e($key) . '">' : '';
}

// Converts an OpenSSL DER-encoded ECDSA signature (SEQUENCE of two INTEGERs) into the raw
// r||s format (64 bytes for P-256) that JWS/ES256 requires. DER INTEGERs are variable
// length and may carry a leading 0x00 padding byte - strip that, then left-pad each half
// back to the fixed 32-byte width.
function vapid_der_to_jose_signature(string $der): ?string {
    $offset = 0;
    if ($offset >= strlen($der) || ord($der[$offset]) !== 0x30) {
        return null;
    }
    $offset++;
    $seqLen = ord($der[$offset]);
    $offset++;
    if ($seqLen & 0x80) {
        $offset += $seqLen & 0x7f;
    }

    foreach (['r', 's'] as $part) {
        if ($offset >= strlen($der) || ord($der[$offset]) !== 0x02) {
            return null;
        }
        $offset++;
        $len = ord($der[$offset]);
        $offset++;
        ${$part} = substr($der, $offset, $len);
        $offset += $len;
    }

    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function build_vapid_jwt(string $audienceOrigin): ?string {
    if (!vapid_configured()) {
        return null;
    }
    $key = openssl_pkey_get_private(vapid_private_key_pem());
    if ($key === false) {
        return null;
    }

    $header = base64url_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = base64url_encode((string) json_encode([
        'aud' => $audienceOrigin,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:' . (string) (config_app()['smtp_from_email'] ?? 'admin@example.com'),
    ]));
    $signingInput = $header . '.' . $claims;

    $derSignature = '';
    if (!openssl_sign($signingInput, $derSignature, $key, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $joseSignature = vapid_der_to_jose_signature($derSignature);
    if ($joseSignature === null) {
        return null;
    }
    return $signingInput . '.' . base64url_encode($joseSignature);
}

// Fire-and-forget, same principle as log_event()/mailer_dispatch() - notification delivery
// must never break the caller's flow. Records the notification content first so the
// service worker's "fetch the pending one" call has something to show even if the push
// itself is slow or the browser's push service is temporarily unreachable.
function send_web_push(PDO $pdo, int $userId, string $title, string $body, ?string $url = null): void {
    try {
        if (!vapid_configured()) {
            return;
        }

        $stmt = $pdo->prepare('SELECT id, endpoint FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$subscriptions) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO push_notifications (user_id, title, body, url) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $title, $body, $url]);

        $publicKey = vapid_public_key_b64url();
        if ($publicKey === null) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $endpoint = (string) $subscription['endpoint'];
            $host = parse_url($endpoint, PHP_URL_HOST);
            $scheme = parse_url($endpoint, PHP_URL_SCHEME);
            if (!$host || !$scheme) {
                continue;
            }
            $jwt = build_vapid_jwt($scheme . '://' . $host);
            if ($jwt === null) {
                continue;
            }

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '',
                CURLOPT_HTTPHEADER => [
                    'Authorization: vapid t=' . $jwt . ', k=' . $publicKey,
                    'TTL: 86400',
                    'Content-Length: 0',
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 404/410 means the browser revoked this subscription (uninstalled, permission
            // withdrawn, etc.) - stop sending to it.
            if (in_array($httpCode, [404, 410], true)) {
                $del = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?');
                $del->execute([$subscription['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('send_web_push failed: ' . $e->getMessage());
    }
}
