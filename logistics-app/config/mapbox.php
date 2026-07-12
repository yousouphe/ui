<?php
require_once __DIR__ . '/functions.php';

// Server-side Mapbox Directions calls, using the SECRET token (never sent to the browser -
// the public token in config/functions.php's mapbox_token() is what the client-side map/
// geocoding widgets use). Reserved for exactly this since module7; now wired up so pricing
// is based on real road distance instead of a straight-line haversine guess.
function mapbox_secret_token(): string {
    return trim((string) (config_app()['mapbox_secret_token'] ?? ''));
}

function mapbox_road_routing_configured(): bool {
    $token = mapbox_secret_token();
    return $token !== '' && !str_starts_with($token, 'REDACTED');
}

// Returns null (rather than throwing) on any failure - pricing_distance_km() below is the
// one that turns that into a hard error, so this stays a low-level "couldn't get it" signal
// that other, non-pricing callers could use permissively if they ever need to.
function road_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): ?float {
    if (!mapbox_road_routing_configured()) {
        return null;
    }

    $coords = "{$lng1},{$lat1};{$lng2},{$lat2}";
    $url = 'https://api.mapbox.com/directions/v5/mapbox/driving/' . $coords
        . '?overview=false&access_token=' . urlencode(mapbox_secret_token());

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    $meters = $data['routes'][0]['distance'] ?? null;
    return is_numeric($meters) ? ((float) $meters) / 1000 : null;
}

// The one call site every pricing-relevant distance calculation must go through. Deliberately
// has no straight-line fallback - a haversine guess can be significantly shorter than the
// real road route (bridges, one-ways, river crossings), which would under- or over-charge
// against the distance actually driven. Callers must catch this and surface a retry rather
// than ever price off an approximation.
function pricing_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $km = road_distance_km($lat1, $lng1, $lat2, $lng2);
    if ($km === null) {
        throw new RuntimeException('Unable to determine road distance for pricing.');
    }
    return $km;
}
