<?php
// Real credentials were redacted before this app was pushed to git.
// Fill these in locally (do not commit real secrets) or load from environment variables.
return [
    'db_host' => 'REDACTED_DB_HOST',
    'db_name' => 'REDACTED_DB_NAME',
    'db_user' => 'REDACTED_DB_USER',
    'db_pass' => 'REDACTED_DB_PASSWORD',
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
    // Trusted reverse-proxy IPs/CIDR ranges (e.g. your Cloudflare or load-balancer ranges).
    // client_ip() only honours the CF-Connecting-IP / X-Forwarded-For headers when the direct
    // peer (REMOTE_ADDR) is listed here; otherwise it uses REMOTE_ADDR directly. This stops a
    // client from spoofing its IP to evade rate limits/bans. Leave empty when the app is
    // reached directly (no proxy in front). Accepts exact IPs and CIDR (IPv4 and IPv6), e.g.
    // ['173.245.48.0/20', '103.21.244.0/22', '2400:cb00::/32'].
    'trusted_proxies' => [],
];
