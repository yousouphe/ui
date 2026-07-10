<?php
require_once __DIR__ . '/config/functions.php';

$locale = (string) ($_GET['locale'] ?? $_POST['locale'] ?? 'en');
set_locale($locale);

$redirect = (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? 'dashboard');
if (!preg_match('#^[a-zA-Z0-9_\-/]*$#', $redirect) || $redirect === '') {
    $redirect = 'dashboard';
}

$token = (string) ($_GET['token'] ?? '');
if ($token !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
    $redirect .= '?token=' . urlencode($token);
}

redirect_to($redirect);
