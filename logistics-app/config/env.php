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
    // Public Mapbox token (pk.*) - safe to expose client-side by design. Used only for the
    // live-tracking route/rider-position map now that address entry uses Google Maps.
    // Restrict it to your domain(s) in the Mapbox account dashboard for defense in depth.
    'mapbox_token' => 'REDACTED_MAPBOX_PUBLIC_TOKEN',
    // SECRET Mapbox token (sk.*) - server-side use only, never send this to the browser.
    // Wired into config/mapbox.php's road_distance_km() for pricing (see
    // sql/module13_pricing_settings_migration.sql) - falls back to straight-line distance
    // if this is left REDACTED or the Directions API is unreachable.
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
    // Google Maps JavaScript API key (Maps JavaScript API + Places API enabled) used for
    // pickup/delivery address autocomplete and the map location picker in the booking
    // wizard and the change-delivery-address modal. This key is designed to be used
    // client-side - restrict it to your domain(s) under "Application restrictions" in the
    // Google Cloud Console credential settings for defense in depth. Create one at
    // https://console.cloud.google.com/google/maps-apis/credentials
    'google_maps_api_key' => 'REDACTED_GOOGLE_MAPS_API_KEY',
];
