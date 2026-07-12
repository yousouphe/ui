<?php
// Run once from the app root on the server where config/env.php holds the real
// vapid_private_key_pem: php scripts/check_vapid_key.php
// Reports whether the configured key will actually work for Web Push, without printing
// any key material - use this instead of triggering a real notification and guessing why
// nothing arrived.

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/push.php';

if (!vapid_configured()) {
    fwrite(STDERR, "FAIL: vapid_private_key_pem is not set (still REDACTED, blank, or missing 'PRIVATE KEY').\n");
    exit(1);
}

echo "vapid_private_key_pem is set and looks like a PEM.\n";

$key = openssl_pkey_get_private(vapid_private_key_pem());
if ($key === false) {
    fwrite(STDERR, "FAIL: openssl could not parse it as a private key - " . (openssl_error_string() ?: 'no further detail') . "\n");
    fwrite(STDERR, "This usually means the line breaks were lost/mangled when the PEM was pasted into config/env.php.\n");
    exit(1);
}
echo "openssl parsed it as a valid private key.\n";

$details = openssl_pkey_get_details($key);
if (!isset($details['ec']['curve_name'])) {
    fwrite(STDERR, "FAIL: this is not an EC key (Web Push requires EC P-256/prime256v1). Regenerate with php scripts/generate_vapid_keys.php.\n");
    exit(1);
}
$curve = $details['ec']['curve_name'];
echo "It is an EC key on curve: $curve\n";
if ($curve !== 'prime256v1') {
    fwrite(STDERR, "FAIL: wrong curve ($curve) - Web Push requires prime256v1 (P-256). Regenerate with php scripts/generate_vapid_keys.php.\n");
    exit(1);
}

$publicKey = vapid_public_key_b64url();
if ($publicKey === null) {
    fwrite(STDERR, "FAIL: the public key could not be derived (unexpected - please report this).\n");
    exit(1);
}
echo "Public key derived successfully (" . strlen($publicKey) . " base64url chars).\n";

$jwt = build_vapid_jwt('https://example.com');
if ($jwt === null) {
    fwrite(STDERR, "FAIL: could not build a VAPID auth JWT with this key.\n");
    exit(1);
}
echo "Test JWT signed successfully.\n";
echo "\nOK: this VAPID key is valid and ready for Web Push.\n";
echo "If notifications still aren't arriving, check your PHP error log after triggering one -\n";
echo "config/push.php now logs the exact HTTP response from the push service on any failure.\n";
