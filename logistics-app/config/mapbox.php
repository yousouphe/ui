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

// Returns null (rather than throwing) on any failure - callers must fall back to haversine
// so a Mapbox outage or missing token never blocks booking creation or repricing.
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

// Used only when Mapbox is unavailable - a same-purpose fallback to the haversine helper
// already in config/functions.php (haversine_sql is SQL-side; this is the plain-PHP form
// used at pricing call sites).
function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

// The one call site every pricing-relevant distance calculation should go through -
// real road distance when Mapbox is configured and reachable, straight-line as a fallback
// that keeps the app working rather than blocking bookings entirely.
function pricing_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    return road_distance_km($lat1, $lng1, $lat2, $lng2) ?? haversine_km($lat1, $lng1, $lat2, $lng2);
}
