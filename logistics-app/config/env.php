<?php
// Real credentials were redacted before this app was pushed to git.
// Fill these in locally (do not commit real secrets) or load from environment variables.
return [
    'db_host' => 'REDACTED_DB_HOST',
    'db_name' => 'REDACTED_DB_NAME',
    'db_user' => 'REDACTED_DB_USER',
    'db_pass' => 'REDACTED_DB_PASSWORD',
    'app_name' => 'SwiftDrop Logistics',
    'base_url' => '',
    'app_url' => 'https://entrepoints.ng',
    'paystack_public_key' => 'REDACTED_PAYSTACK_PUBLIC_KEY',
    'paystack_secret_key' => 'REDACTED_PAYSTACK_SECRET_KEY',
    // Public Mapbox token (pk.*) - safe to expose client-side by design.
    // Restrict it to your domain(s) in the Mapbox account dashboard for defense in depth.
    'mapbox_token' => 'REDACTED_MAPBOX_PUBLIC_TOKEN',
    // SECRET Mapbox token (sk.*) - server-side use only, never send this to the browser.
    // Not wired into any code path yet; reserved for future server-side Mapbox calls
    // (e.g. Directions/Optimization API during the routing-audit phase).
    'mapbox_secret_token' => 'REDACTED_MAPBOX_SECRET_TOKEN',
    // SMTP credentials for transactional email (registration, receipts, password reset).
    'smtp_host' => 'REDACTED_SMTP_HOST',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_user' => 'REDACTED_SMTP_USER',
    'smtp_pass' => 'REDACTED_SMTP_PASSWORD',
    'smtp_from_email' => 'REDACTED_SMTP_FROM_EMAIL',
    'smtp_from_name' => 'SwiftDrop Logistics',
    // Google OAuth 2.0 (sign in / sign up with Google). Create credentials at
    // https://console.cloud.google.com/apis/credentials with the redirect URI
    // set to {app_url}/auth/google_callback.php
    'google_client_id' => 'REDACTED_GOOGLE_CLIENT_ID',
    'google_client_secret' => 'REDACTED_GOOGLE_CLIENT_SECRET',
    // Web Push (browser notifications for senders/riders). Generate once with
    // `php scripts/generate_vapid_keys.php` and paste the PEM below - the public key is
    // derived from it automatically, there is nothing else to configure.
    'vapid_private_key_pem' => 'REDACTED_VAPID_PRIVATE_KEY_PEM',
];
