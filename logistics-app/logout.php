<?php
require_once __DIR__ . '/config/functions.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
session_start();
session_regenerate_id(true);
flash('success', 'Logged out successfully.');
redirect_to('login');
