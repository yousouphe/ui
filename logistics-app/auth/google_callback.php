<?php
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';

$config = config_app();
$clientId = trim((string)($config['google_client_id'] ?? ''));
$clientSecret = trim((string)($config['google_client_secret'] ?? ''));

$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
$expectedState = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']);

if ($clientId === '' || $clientSecret === '' || $code === '' || $state === '' || !$expectedState || !hash_equals($expectedState, $state)) {
    flash('error', t('auth.google_failed'));
    redirect_to('login');
}

// Must match auth/google_login.php exactly - Google rejects the token exchange otherwise.
$redirectUri = rtrim((string)($config['app_url'] ?? ''), '/') . '/auth/google_callback.php';

function google_http_post(string $url, array $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $response];
}

function google_http_get(string $url, string $bearerToken) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearerToken],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$httpCode, $response];
}

try {
    [$tokenHttpCode, $tokenResponse] = google_http_post('https://oauth2.googleapis.com/token', [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri,
    ]);

    $tokenData = json_decode((string)$tokenResponse, true);
    if ($tokenHttpCode !== 200 || !is_array($tokenData) || empty($tokenData['access_token'])) {
        throw new RuntimeException('Token exchange failed.');
    }

    [$userHttpCode, $userResponse] = google_http_get('https://www.googleapis.com/oauth2/v3/userinfo', (string)$tokenData['access_token']);
    $googleUser = json_decode((string)$userResponse, true);
    if ($userHttpCode !== 200 || !is_array($googleUser) || empty($googleUser['sub']) || empty($googleUser['email'])) {
        throw new RuntimeException('Could not fetch Google profile.');
    }

    $googleId = (string)$googleUser['sub'];
    $email = strtolower(trim((string)$googleUser['email']));
    $emailVerified = !empty($googleUser['email_verified']) && $googleUser['email_verified'] !== 'false';
    $name = trim((string)($googleUser['name'] ?? $email));

    if (!$emailVerified || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Google account email is not verified.');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->execute([$googleId]);
    $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
    $isNewUser = false;

    if (!$dbUser) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dbUser) {
            $stmt = $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?');
            $stmt->execute([$googleId, $dbUser['id']]);
            $dbUser['google_id'] = $googleId;
        }
    }

    if (!$dbUser) {
        $isNewUser = true;
        $stmt = $pdo->prepare('
            INSERT INTO users (full_name, email, phone, password_hash, role, status, google_id, profile_completed)
            VALUES (?, ?, "", ?, "sender", "active", ?, 0)
        ');
        $stmt->execute([$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $googleId]);
        $newId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$newId]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($dbUser['status'] !== 'active') {
        flash('error', t('login.error.inactive'));
        redirect_to('login');
    }

    $_SESSION['user'] = [
        'id' => (int)$dbUser['id'],
        'full_name' => $dbUser['full_name'],
        'email' => $dbUser['email'],
        'phone' => $dbUser['phone'],
        'role' => $dbUser['role'],
        'profile_completed' => (int)($dbUser['profile_completed'] ?? 1),
    ];

    if ($isNewUser) {
        send_welcome_email($dbUser['email'], $dbUser['full_name'], $dbUser['role']);
    }

    flash('success', t('login.welcome_back', ['name' => $dbUser['full_name']]));

    if ((int)($dbUser['profile_completed'] ?? 1) === 0) {
        redirect_to('complete-profile');
    }
    if ($dbUser['role'] === 'rider') redirect_to('rider/');
    if (in_array($dbUser['role'], ['admin', 'super_admin'], true)) redirect_to('admin/');
    redirect_to('/bookings');
} catch (Throwable $e) {
    error_log('Google OAuth callback failed: ' . $e->getMessage());
    flash('error', t('auth.google_failed'));
    redirect_to('login');
}
