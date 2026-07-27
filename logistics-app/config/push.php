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
        // The single most common cause: the PEM's line breaks got mangled (collapsed to
        // one line, or literal "\n" text instead of real newlines) when it was pasted into
        // config/env.php or copied through a hosting panel's env-var editor - openssl can't
        // parse a PEM without its real line structure. openssl_error_string() names the
        // exact parse failure without ever logging the key material itself.
        error_log('VAPID: openssl_pkey_get_private() failed to parse vapid_private_key_pem - ' . (openssl_error_string() ?: 'no OpenSSL error detail available') . '. Check that the PEM was pasted with real line breaks intact.');
        return $cached = null;
    }
    $details = openssl_pkey_get_details($key);
    $x = $details['ec']['x'] ?? null;
    $y = $details['ec']['y'] ?? null;
    if ($x === null || $y === null) {
        $keyType = $details['type'] ?? null;
        error_log('VAPID: private key parsed but is not an EC (P-256) key (openssl key type constant: ' . var_export($keyType, true) . '). Web Push requires an EC P-256 key - regenerate with php scripts/generate_vapid_keys.php.');
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
        error_log('VAPID: build_vapid_jwt() failed to load the private key - ' . (openssl_error_string() ?: 'no OpenSSL error detail available'));
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
        error_log('VAPID: openssl_sign() failed while building the push auth JWT - ' . (openssl_error_string() ?: 'no OpenSSL error detail available'));
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
// Mobile push via the Expo push service (which fans out to FCM on Android and APNs on iOS).
// Tokens are Expo push tokens stored in device_tokens (registered by the mobile app through
// POST /api/v1/notifications/device). No FCM/APNs server secret is needed for the basic Expo
// flow, so nothing sensitive lives here. Invalid tokens ("DeviceNotRegistered") are pruned.
function send_expo_push(PDO $pdo, int $userId, string $title, string $body, ?string $url = null): void {
    $stmt = $pdo->prepare('SELECT id, token FROM device_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }
    // Only send to well-formed Expo push tokens; ignore anything else defensively.
    $messages = [];
    $idByToken = [];
    foreach ($rows as $row) {
        $token = (string) $row['token'];
        if (!preg_match('/^ExponentPushToken\[.+\]$|^ExpoPushToken\[.+\]$/', $token)) {
            continue;
        }
        $idByToken[$token] = (int) $row['id'];
        $messages[] = [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'data' => ['url' => $url],
        ];
    }
    if (!$messages) {
        return;
    }

    $ch = curl_init('https://exp.host/--/api/v2/push/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($messages),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300 || $resp === false) {
        error_log('expo push http ' . $httpCode);
        return;
    }
    // Prune tokens the push service reports as no longer registered.
    $decoded = json_decode((string) $resp, true);
    $tickets = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
    $i = 0;
    foreach ($messages as $msg) {
        $ticket = $tickets[$i] ?? null;
        $i++;
        if (is_array($ticket) && ($ticket['status'] ?? '') === 'error'
            && (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered')) {
            $id = $idByToken[$msg['to']] ?? 0;
            if ($id) {
                try { $pdo->prepare('DELETE FROM device_tokens WHERE id = ?')->execute([$id]); } catch (Throwable $e) {}
            }
        }
    }
}

// Unified per-user notification dispatch. Despite the historical name it now feeds BOTH web
// (VAPID) and mobile (FCM/APNs via Expo), and always records the notification. Every existing
// call site therefore reaches a user's mobile devices too, with no change at the call sites.
function send_web_push(PDO $pdo, int $userId, string $title, string $body, ?string $url = null): void {
    // Record once - the source of truth for the web AND mobile in-app notification lists.
    // Recorded unconditionally, even if no transport is configured, so history is never lost.
    try {
        $pdo->prepare('INSERT INTO push_notifications (user_id, title, body, url) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $title, $body, $url]);
    } catch (Throwable $e) {
        error_log('record notification failed: ' . $e->getMessage());
    }

    // Mobile push (FCM on Android / APNs on iOS, via the Expo push service) to any device tokens.
    try {
        send_expo_push($pdo, $userId, $title, $body, $url);
    } catch (Throwable $e) {
        error_log('send_expo_push failed: ' . $e->getMessage());
    }

    // Web push (unchanged) - only when VAPID is configured and the user has web subscriptions.
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
            $responseBody = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // 404/410 means the browser revoked this subscription (uninstalled, permission
            // withdrawn, etc.) - stop sending to it. Anything else outside 2xx is a real
            // delivery failure worth knowing about (401/403 almost always means the VAPID
            // key/JWT the push service received doesn't match what the browser subscribed
            // with - e.g. the key was regenerated after some subscriptions were already
            // saved against the old one).
            if (in_array($httpCode, [404, 410], true)) {
                $del = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?');
                $del->execute([$subscription['id']]);
            } elseif ($httpCode < 200 || $httpCode >= 300) {
                error_log("Web push delivery to subscription {$subscription['id']} failed: httpCode=$httpCode curlError=" . ($curlError ?: 'none') . ' responseBody=' . substr((string) $responseBody, 0, 500));
            }
        }
    } catch (Throwable $e) {
        error_log('send_web_push failed: ' . $e->getMessage());
    }
}
