<?php
// Run once: php scripts/generate_vapid_keys.php
// Paste the printed PEM into config/env.php's 'vapid_private_key_pem' value. The public
// key is derived from it automatically at runtime (see config/push.php) - there is
// nothing else to configure.
$key = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if ($key === false) {
    fwrite(STDERR, "Failed to generate an EC key pair - is the openssl extension enabled?\n");
    exit(1);
}

if (!openssl_pkey_export($key, $pem)) {
    fwrite(STDERR, "Failed to export the generated key.\n");
    exit(1);
}

echo "Paste this as 'vapid_private_key_pem' in config/env.php:\n\n";
echo $pem . "\n";
