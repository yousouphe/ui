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
function send_expo_push(PDO $pdo, int $userId, string $title, string $body, ?string $url = null, array $data = []): void {
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
            'data' => array_merge(['url' => $url], $data),
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

// ---- Native FCM (HTTP v1 API) for the native Android app ------------------------------------
// The native Kotlin app registers a raw FCM registration token (POST /api/v1/notifications/device),
// not an Expo push token - Expo tokens only exist for apps built through Expo's own
// infrastructure, which this app isn't. send_expo_push() above correctly ignores anything that
// isn't Expo-shaped rather than misdirecting it, which meant native tokens were silently never
// sent to at all. This sends to those tokens directly via Google's FCM HTTP v1 API instead.
//
// The legacy "server key" FCM API (Authorization: key=...) was fully retired by Google in June
// 2024 - HTTP v1 is now the only option, and it authenticates with a short-lived OAuth2 access
// token minted from a Firebase service account (Firebase Console -> Project Settings -> Service
// accounts -> Generate new private key), not a static secret. Hand-rolled here (RS256 JWT +
// token exchange) rather than pulling in the Firebase Admin SDK, consistent with this app having
// no Composer/vendor directory - the same reasoning behind send_web_push()'s hand-rolled VAPID.

function firebase_service_account(): ?array {
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $json = trim((string) (config_app()['firebase_service_account_json'] ?? ''));
    if ($json === '' || str_starts_with($json, 'REDACTED')) {
        return $cached = null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || empty($decoded['private_key']) || empty($decoded['client_email']) || empty($decoded['project_id'])) {
        error_log('FCM: firebase_service_account_json is set but is not a valid service-account key (missing private_key/client_email/project_id).');
        return $cached = null;
    }
    return $cached = $decoded;
}

function firebase_configured(): bool {
    return firebase_service_account() !== null;
}

// Mints a fresh OAuth2 access token via the JWT Bearer Token flow (RFC 7523). Not cached beyond
// this request - PHP-FPM processes are short-lived and this only runs when there's an actual
// native token to notify, so re-minting per request is simple and correct here rather than a
// premature optimisation needing a shared cache.
function firebase_access_token(): ?string {
    $account = firebase_service_account();
    if ($account === null) {
        return null;
    }
    $now = time();
    $header = base64url_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64url_encode((string) json_encode([
        'iss' => $account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $signingInput = $header . '.' . $claims;

    $key = openssl_pkey_get_private($account['private_key']);
    if ($key === false) {
        error_log('FCM: failed to parse the service account private key - ' . (openssl_error_string() ?: 'no OpenSSL error detail available'));
        return null;
    }
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
        error_log('FCM: openssl_sign() failed while building the OAuth2 assertion JWT - ' . (openssl_error_string() ?: 'no OpenSSL error detail available'));
        return null;
    }
    $assertion = $signingInput . '.' . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300 || $resp === false) {
        error_log('FCM: OAuth2 token exchange failed, http ' . $httpCode);
        return null;
    }
    $decoded = json_decode((string) $resp, true);
    return is_array($decoded) ? (($decoded['access_token'] ?? null) ?: null) : null;
}

// Sends to every raw (non-Expo) FCM token on file for a user via the HTTP v1 API. Mirrors
// send_expo_push()'s shape: fire-and-forget, prunes tokens FCM reports as gone.
function send_fcm_push(PDO $pdo, int $userId, string $title, string $body, ?string $url = null, array $data = []): void {
    if (!firebase_configured()) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id, token FROM device_tokens WHERE user_id = ?');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }
    $rawTokenRows = array_values(array_filter($rows, static fn(array $r): bool =>
        !preg_match('/^ExponentPushToken\[.+\]$|^ExpoPushToken\[.+\]$/', (string) $r['token'])));
    if (!$rawTokenRows) {
        return;
    }

    $accessToken = firebase_access_token();
    if ($accessToken === null) {
        return;
    }
    $projectId = (string) firebase_service_account()['project_id'];

    // Data-only (no top-level "notification" key) so onMessageReceived() in the Android app fires
    // every time - foreground, background, or killed - and our own code always builds the
    // notification (right channel/sound/vibration/tap target). A "notification" key here would
    // make the FCM SDK auto-post a bare default-look notification itself whenever the app is
    // backgrounded, bypassing onMessageReceived() (and everything in it) entirely until tapped.
    $dataPayload = array_merge(
        ['title' => $title, 'body' => $body, 'url' => (string) ($url ?? '')],
        array_map('strval', $data)
    );
    foreach ($rawTokenRows as $row) {
        $token = (string) $row['token'];
        $payload = [
            'message' => [
                'token' => $token,
                'data' => $dataPayload,
                'android' => ['priority' => 'high'],
            ],
        ];
        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300) {
            continue;
        }
        // UNREGISTERED (404) = app uninstalled / token rotated - stop sending to it.
        $decodedErr = json_decode((string) $resp, true);
        $status = is_array($decodedErr) ? (string) ($decodedErr['error']['status'] ?? '') : '';
        if ($httpCode === 404 || $status === 'UNREGISTERED') {
            try { $pdo->prepare('DELETE FROM device_tokens WHERE id = ?')->execute([(int) $row['id']]); } catch (Throwable $e) {}
        } else {
            error_log('FCM push http ' . $httpCode . ' status=' . $status);
        }
    }
}

// Unified per-user notification dispatch. Despite the historical name it now feeds web (VAPID)
// and BOTH mobile push transports (Expo and native FCM), and always records the notification.
// Every existing call site therefore reaches a user's mobile devices too, with no change needed
// at the call sites.
function send_web_push(PDO $pdo, int $userId, string $title, string $body, ?string $url = null, array $data = []): void {
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
        send_expo_push($pdo, $userId, $title, $body, $url, $data);
    } catch (Throwable $e) {
        error_log('send_expo_push failed: ' . $e->getMessage());
    }

    // Native FCM (HTTP v1) for the native Android app's raw registration tokens - see above.
    try {
        send_fcm_push($pdo, $userId, $title, $body, $url, $data);
    } catch (Throwable $e) {
        error_log('send_fcm_push failed: ' . $e->getMessage());
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
            // Web Push endpoints are https by spec, but this is browser-supplied data stored in
            // our DB - enforce it explicitly rather than trusting the stored value blindly.
            if (!$host || $scheme !== 'https') {
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
