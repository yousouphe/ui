<?php
// Mobile API layer helpers: JSON envelope, stateless bearer-token auth, idempotency.
//
// This file holds NO business logic — endpoints call the same config/functions.php helpers the
// web app uses (pricing, eligibility, transitions), so nothing is duplicated. It only provides
// the transport (JSON in/out) and authentication (bearer tokens instead of the PHP session).
//
// Requires config/functions.php + config/db.php to be included first (for config_app(), $pdo,
// rate-limit helpers, etc.). The API front controller defines AIKE_STATELESS so no PHP session
// cookie is started.

const API_ACCESS_TTL = 900;         // 15 minutes
const API_REFRESH_TTL = 2592000;    // 30 days

// ---- JSON envelope --------------------------------------------------------------------------

function api_send(int $httpCode, bool $ok, $data = null, ?array $error = null, array $meta = []): void {
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $meta['requestId'] = $meta['requestId'] ?? bin2hex(random_bytes(8));
    echo json_encode(['ok' => $ok, 'data' => $data, 'error' => $error, 'meta' => $meta]);
    exit;
}

function api_ok($data = null, array $meta = [], int $httpCode = 200): void {
    api_send($httpCode, true, $data, null, $meta);
}

function api_fail(int $httpCode, string $code, string $message, array $fields = []): void {
    $err = ['code' => $code, 'message' => $message];
    if ($fields) {
        $err['fields'] = $fields;
    }
    api_send($httpCode, false, null, $err);
}

/** Decode a JSON request body into an array (empty array if none/invalid). */
function api_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// ---- Token auth -----------------------------------------------------------------------------

function api_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $header = $v; break; }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }
    return null;
}

function api_hash_token(string $token): string {
    return hash('sha256', $token);
}

/**
 * Issue a fresh access+refresh pair for a user/device, sharing a family so logout/refresh can
 * operate on the whole device session. Returns the PLAINTEXT tokens (only time they exist).
 */
function api_issue_tokens(PDO $pdo, int $userId, ?string $platform = null, ?string $deviceLabel = null): array {
    $family = api_uuid4();
    $access = bin2hex(random_bytes(32));
    $refresh = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (user_id, family, token_hash, type, platform, device_label, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    );
    $stmt->execute([$userId, $family, api_hash_token($access), 'access', $platform, $deviceLabel, API_ACCESS_TTL]);
    $stmt->execute([$userId, $family, api_hash_token($refresh), 'refresh', $platform, $deviceLabel, API_REFRESH_TTL]);
    return [
        'accessToken' => $access,
        'refreshToken' => $refresh,
        'expiresInSeconds' => API_ACCESS_TTL,
    ];
}

/** Resolve a bearer access token to an active user row, or null. Touches last_used_at. */
function api_user_from_access(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare(
        "SELECT t.id AS token_id, u.*
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.type = 'access'
           AND t.revoked_at IS NULL AND t.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([api_hash_token($token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    // A suspended/inactive account cannot use the API even with a live token.
    if (($row['status'] ?? '') !== 'active') {
        return null;
    }
    $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['token_id']]);
    unset($row['token_id'], $row['password_hash']);
    return $row;
}

/** Rotate the access token using a valid refresh token. Returns new tokens or null. */
function api_refresh_tokens(PDO $pdo, string $refreshToken): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM api_tokens
         WHERE token_hash = ? AND type = 'refresh' AND revoked_at IS NULL AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([api_hash_token($refreshToken)]);
    $refresh = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$refresh) {
        return null;
    }
    // Mint a new access token in the same family; leave the refresh token in place until logout
    // or expiry. Revoke any prior still-valid access tokens in the family (single active access).
    $pdo->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE family = ? AND type = 'access' AND revoked_at IS NULL")
        ->execute([$refresh['family']]);
    $access = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO api_tokens (user_id, family, token_hash, type, platform, device_label, expires_at)
         VALUES (?, ?, ?, "access", ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
    )->execute([$refresh['user_id'], $refresh['family'], api_hash_token($access), $refresh['platform'], $refresh['device_label'], API_ACCESS_TTL]);
    return ['accessToken' => $access, 'expiresInSeconds' => API_ACCESS_TTL];
}

/** Revoke every token in the family of the given access token (logout of that device). */
function api_revoke_by_access(PDO $pdo, string $accessToken): void {
    $stmt = $pdo->prepare("SELECT family FROM api_tokens WHERE token_hash = ? AND type = 'access' LIMIT 1");
    $stmt->execute([api_hash_token($accessToken)]);
    $family = $stmt->fetchColumn();
    if ($family) {
        $pdo->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE family = ? AND revoked_at IS NULL')
            ->execute([$family]);
    }
}

/** Return the authenticated user, or send 401/403 and exit. Optionally restrict by role. */
function api_require(PDO $pdo, array $roles = []): array {
    $token = api_bearer_token();
    $user = $token ? api_user_from_access($pdo, $token) : null;
    if (!$user) {
        api_fail(401, 'UNAUTHENTICATED', 'Sign in to continue.');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        api_fail(403, 'FORBIDDEN', 'You do not have access to this resource.');
    }
    return $user;
}

// ---- Idempotency (for unsafe writes; used from Phase 5 onward) -------------------------------

/**
 * If the caller already performed this (endpoint, Idempotency-Key), replay the stored response.
 * Returns true (and sends the replay) if a prior response exists; false to proceed.
 */
function api_idempotency_replay(PDO $pdo, int $userId, string $endpoint): bool {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if ($key === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT response_code, response_body FROM idempotency_keys WHERE user_id = ? AND key_hash = ? AND endpoint = ? LIMIT 1');
    $stmt->execute([$userId, api_hash_token($key), $endpoint]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if (!headers_sent()) {
        http_response_code((int) $row['response_code']);
        header('Content-Type: application/json; charset=utf-8');
        header('Idempotent-Replayed: true');
    }
    echo $row['response_body'];
    exit;
}

function api_idempotency_store(PDO $pdo, int $userId, string $endpoint, int $code, string $body): void {
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if ($key === '') {
        return;
    }
    try {
        $pdo->prepare('INSERT IGNORE INTO idempotency_keys (user_id, key_hash, endpoint, response_code, response_body) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, api_hash_token($key), $endpoint, $code, $body]);
    } catch (Throwable $e) {
        error_log('idempotency store failed: ' . $e->getMessage());
    }
}

function api_uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// ---- Serializers (DB row -> API contract shape) ---------------------------------------------

function api_user_public(array $u): array {
    return [
        'id' => (int) $u['id'],
        'fullName' => (string) $u['full_name'],
        'email' => (string) $u['email'],
        'phone' => $u['phone'] !== null ? (string) $u['phone'] : null,
        'role' => (string) $u['role'],
        'profileCompleted' => (int) ($u['profile_completed'] ?? 1) === 1,
        'avatarUrl' => !empty($u['avatar_path']) ? (string) $u['avatar_path'] : null,
    ];
}

function api_booking_public(array $b): array {
    return [
        'id' => (int) $b['id'],
        'status' => (string) $b['booking_status'],
        'paymentStatus' => (string) ($b['payment_status'] ?? 'unpaid'),
        'vehicleType' => $b['vehicle_type'] !== null ? (string) $b['vehicle_type'] : null,
        'pickup' => [
            'address' => (string) ($b['pickup_address'] ?? ''),
            'lat' => $b['pickup_latitude'] !== null ? (float) $b['pickup_latitude'] : null,
            'lng' => $b['pickup_longitude'] !== null ? (float) $b['pickup_longitude'] : null,
        ],
        'dropoff' => [
            'address' => (string) ($b['delivery_address'] ?? ''),
            'lat' => $b['delivery_latitude'] !== null ? (float) $b['delivery_latitude'] : null,
            'lng' => $b['delivery_longitude'] !== null ? (float) $b['delivery_longitude'] : null,
        ],
        'agreedCost' => $b['agreed_cost'] !== null ? (float) $b['agreed_cost'] : null,
        'selectedRiderUserId' => $b['selected_rider_user_id'] !== null ? (int) $b['selected_rider_user_id'] : null,
        'createdAt' => (string) $b['created_at'],
        'updatedAt' => (string) $b['updated_at'],
    ];
}
