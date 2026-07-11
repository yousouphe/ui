<?php
require_once __DIR__ . '/../config/functions.php';

$config = config_app();
$clientId = trim((string)($config['google_client_id'] ?? ''));

if ($clientId === '' || str_starts_with($clientId, 'REDACTED')) {
    flash('error', t('auth.google_not_configured'));
    redirect_to('login');
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

// app_url already includes any subdirectory the app is deployed under (see payments/start.php
// for the same convention) - do not also route this through url_path(), which independently
// re-detects that subdirectory from SCRIPT_NAME and would double it.
$redirectUri = rtrim((string)($config['app_url'] ?? ''), '/') . '/auth/google_callback.php';

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
];

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
