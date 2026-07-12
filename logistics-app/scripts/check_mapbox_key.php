<?php
// Run once from the app root on the server where config/env.php holds the real
// mapbox_secret_token: php scripts/check_mapbox_key.php
// Reports exactly why road-distance pricing is failing - "not configured", "rejected by
// Mapbox", "unreachable", or genuinely fine - instead of guessing from a vague "Unable to
// calculate pricing right now" error in the app itself. Uses two real Lagos coordinates
// (Mapbox's Directions API doesn't offer a lighter-weight "just check my token" endpoint),
// so this makes one real, billable-tier-eligible API call - Mapbox's free tier is generous
// enough that running this a few times while troubleshooting is not a concern.

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/mapbox.php';

if (!mapbox_road_routing_configured()) {
    fwrite(STDERR, "FAIL: mapbox_secret_token is not set (still REDACTED or blank) in config/env.php.\n");
    fwrite(STDERR, "This must be a SECRET token (starts with sk.*) from https://account.mapbox.com/access-tokens/ -\n");
    fwrite(STDERR, "not the public token (pk.*) used for the map/autocomplete widgets in the browser.\n");
    exit(1);
}

echo "mapbox_secret_token is set.\n";
echo "Calling the Directions API with two known Lagos coordinates...\n";

// Ikeja City Mall -> Lekki Phase 1, Lagos - both real, road-connected locations, just to
// prove the token/network path works end-to-end. Not used for any pricing decision.
try {
    $metrics = pricing_route_metrics(6.6018, 3.3515, 6.4698, 3.5852);
    echo "OK: got a real route back - " . round($metrics['distance_km'], 1) . "km, ~" . round($metrics['duration_min']) . " min drive.\n";
    echo "\nMapbox road-distance pricing is working correctly.\n";
    echo "If the app still shows pricing errors, the issue is specific to those pickup/delivery\n";
    echo "coordinates (check the PHP error log - config/mapbox.php now logs the failure reason\n";
    echo "for every call) rather than the token/configuration itself.\n";
} catch (NoRouteFoundException $e) {
    fwrite(STDERR, "UNEXPECTED: Mapbox reached and authenticated correctly, but found no route between\n");
    fwrite(STDERR, "two real, well-connected Lagos coordinates. This would be very unusual - double-check\n");
    fwrite(STDERR, "the token has Directions API access enabled on your Mapbox account.\n");
    exit(1);
} catch (RuntimeException $e) {
    fwrite(STDERR, "FAIL: the Directions API call did not succeed. Check the PHP error log written\n");
    fwrite(STDERR, "just now by config/mapbox.php's road_route_metrics() - it records the exact HTTP\n");
    fwrite(STDERR, "code and curl error, which will say whether this is:\n");
    fwrite(STDERR, "  - an auth failure (401/403 - the token is invalid, revoked, or lacks Directions API\n");
    fwrite(STDERR, "    access - check https://account.mapbox.com/access-tokens/)\n");
    fwrite(STDERR, "  - a network failure (this server can't reach api.mapbox.com - check outbound\n");
    fwrite(STDERR, "    firewall/proxy rules)\n");
    fwrite(STDERR, "  - a rate limit (429 - unlikely on a fresh account, but possible)\n");
    exit(1);
}
