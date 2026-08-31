<?php
// Real credentials were redacted before this app was pushed to git.
// Fill these in locally (do not commit real secrets) or load from environment variables.
return [
    'db_host' => 'REDACTED_DB_HOST',
    'db_name' => 'REDACTED_DB_NAME',
    'db_user' => 'REDACTED_DB_USER',
    'db_pass' => 'REDACTED_DB_PASSWORD',
    // Absolute path to a CA certificate file, only needed if db_host is a remote/managed MySQL
    // instance rather than localhost - enables TLS on the app-server-to-database connection.
    // Leave REDACTED (or blank) for a same-host DB, where there's no network hop to encrypt.
    'db_ssl_ca' => 'REDACTED_DB_SSL_CA_PATH',
    'app_name' => 'Aike Logistics',
    'base_url' => '',
    'app_url' => 'https://entrepoints.ng',
    'paystack_public_key' => 'REDACTED_PAYSTACK_PUBLIC_KEY',
    'paystack_secret_key' => 'REDACTED_PAYSTACK_SECRET_KEY',
    // Public Mapbox token (pk.*) - safe to expose client-side by design. Used for address
    // autocomplete, the location picker, and the live-tracking route/rider-position map.
    // Restrict it to your domain(s) in the Mapbox account dashboard for defense in depth.
    'mapbox_token' => 'REDACTED_MAPBOX_PUBLIC_TOKEN',
    // SECRET Mapbox token (sk.*) - server-side use only, never send this to the browser.
    // Wired into config/mapbox.php's pricing_distance_km() for pricing (see
    // sql/module13_pricing_settings_migration.sql) - required for pricing to work at all;
    // if this is left REDACTED or the Directions API is unreachable, pricing throws instead
    // of falling back to a straight-line guess (no haversine fallback in the billing path).
    'mapbox_secret_token' => 'REDACTED_MAPBOX_SECRET_TOKEN',
    // Optional: conservative average speed (km/h) used for the haversine fallback when
    // Mapbox is unreachable. Set to 0 or omit to use the built-in default (25 km/h).
    'mapbox_haversine_speed_kmh' => 25.0,
    // SMTP credentials for transactional email (registration, receipts, password reset).
    'smtp_host' => 'REDACTED_SMTP_HOST',
    'smtp_port' => 587,
    'smtp_secure' => 'tls',
    'smtp_user' => 'REDACTED_SMTP_USER',
    'smtp_pass' => 'REDACTED_SMTP_PASSWORD',
    'smtp_from_email' => 'REDACTED_SMTP_FROM_EMAIL',
    'smtp_from_name' => 'Aike Logistics',
    // Google OAuth 2.0 (sign in / sign up with Google). Create credentials at
    // https://console.cloud.google.com/apis/credentials with the redirect URI
    // set to {app_url}/auth/google_callback.php
    'google_client_id' => 'REDACTED_GOOGLE_CLIENT_ID',
    'google_client_secret' => 'REDACTED_GOOGLE_CLIENT_SECRET',
    // Mobile Google OAuth client IDs (iOS / Android / Expo web), comma-separated. The mobile
    // /api/v1/auth/google endpoint verifies the ID token's `aud` against this list (plus the web
    // client id above). Leave blank to skip the aud check (any valid Google ID token accepted).
    'google_mobile_client_ids' => 'REDACTED_GOOGLE_MOBILE_CLIENT_IDS',
    // Web Push (browser notifications for senders/riders). Generate once with
    // `php scripts/generate_vapid_keys.php` and paste the PEM below - the public key is
    // derived from it automatically, there is nothing else to configure.
    'vapid_private_key_pem' => 'REDACTED_VAPID_PRIVATE_KEY_PEM',
    // Native push for the native Android app (Firebase Cloud Messaging, HTTP v1 API) - distinct
    // from the Expo-based mobile push above. Generate at Firebase Console -> Project Settings ->
    // Service accounts -> Generate new private key, then paste the ENTIRE downloaded JSON file's
    // contents here as a single-line string. Leave REDACTED to leave native push disabled (the
    // app still records in-app notifications either way; only the OS push notification is gated
    // on this).
    'firebase_service_account_json' => 'REDACTED_FIREBASE_SERVICE_ACCOUNT_JSON',
    // Shared secret for api/opcache_reset.php - visit that URL with ?key=<this value> once after
    // a deploy if PHP still runs old code despite updated files on disk (opcache.validate_timestamps=0
    // on hosts with no PHP-FPM restart access). Generate any long random string, e.g.
    // `php -r "echo bin2hex(random_bytes(24));"`.
    'opcache_reset_key' => 'REDACTED_OPCACHE_RESET_KEY',
    // EbulkSMS (ebulksms.com) - sends both SMS and WhatsApp order-summary messages to the
    // delivery recipient when a booking is created. username is your EbulkSMS login email;
    // apikey is generated from their dashboard (Get API Key). Leave REDACTED to disable both
    // channels (booking creation still succeeds either way - this is best-effort, non-blocking).
    'ebulksms_username' => 'REDACTED_EBULKSMS_USERNAME',
    'ebulksms_apikey' => 'REDACTED_EBULKSMS_APIKEY',
    // Alphanumeric SMS sender name shown as the "from", max 11 characters. Not used for
    // WhatsApp, which sends from the WhatsApp number connected to your EbulkSMS account.
    'ebulksms_sender_name' => 'Aike',
    // Trusted reverse-proxy IPs/CIDR ranges (e.g. your Cloudflare or load-balancer ranges).
    // client_ip() only honours the CF-Connecting-IP / X-Forwarded-For headers when the direct
    // peer (REMOTE_ADDR) is listed here; otherwise it uses REMOTE_ADDR directly. This stops a
    // client from spoofing its IP to evade rate limits/bans. Leave empty when the app is
    // reached directly (no proxy in front). Accepts exact IPs and CIDR (IPv4 and IPv6), e.g.
    // ['173.245.48.0/20', '103.21.244.0/22', '2400:cb00::/32'].
    'trusted_proxies' => [],
];
