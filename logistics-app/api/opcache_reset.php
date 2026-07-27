<?php
// One-time cache-buster for PHP OPcache staleness after a git-pull deploy on shared hosting with
// no PHP-FPM restart access (opcache.validate_timestamps=0 means updated files on disk don't get
// recompiled until the cache is cleared some other way). Visit this URL once after pulling if the
// app still behaves like the old code despite the files on disk being correct.
//
// Gated behind a shared secret (opcache_reset_key in config/env.php) rather than left open, since
// an unauthenticated cache-reset endpoint is otherwise a free, repeatable perf-degradation lever
// for anyone who finds the URL.
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: text/plain');

$configuredKey = trim((string) (config_app()['opcache_reset_key'] ?? ''));
$suppliedKey = (string) ($_GET['key'] ?? '');

if ($configuredKey === '' || str_starts_with($configuredKey, 'REDACTED')) {
    http_response_code(500);
    echo "opcache_reset_key is not configured in config/env.php.";
    exit;
}
if (!hash_equals($configuredKey, $suppliedKey)) {
    http_response_code(403);
    echo "Invalid or missing key.";
    exit;
}
if (!function_exists('opcache_reset')) {
    http_response_code(500);
    echo "OPcache is not available in this PHP configuration - nothing to reset.";
    exit;
}

echo opcache_reset()
    ? "OPcache cleared. Reload the app to confirm the fix took effect."
    : "opcache_reset() returned false - OPcache may be disabled or restricted by this host.";
