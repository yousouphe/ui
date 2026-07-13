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

// Distinct from the generic RuntimeException pricing_route_metrics() throws for a transient
// problem (network hiccup, misconfigured token) - this means Mapbox was reached successfully
// and definitively found no drivable route between the two points, which a retry won't fix.
// Callers should catch this separately and tell the user to check the addresses instead of
// "try again shortly".
class NoRouteFoundException extends RuntimeException {}

// Returns null (rather than throwing) for a transient/config failure - pricing_route_metrics()
// below turns that into a generic retryable error, so this stays a low-level "couldn't get
// it" signal that other, non-pricing callers could use permissively if they ever need to.
// Throws NoRouteFoundException (not returns null) when Mapbox responds successfully but
// genuinely has no route - that's not transient, so it shouldn't look like one to a caller
// deciding what to tell the user. Returns both distance and drive-time duration from the same
// Directions call on success - the duration is the "planned time" a rider's actual delivery
// time is later compared against.
function road_route_metrics(float $lat1, float $lng1, float $lat2, float $lng2): ?array {
    if (!mapbox_road_routing_configured()) {
        error_log('Mapbox pricing: mapbox_secret_token is not configured (still REDACTED or blank).');
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
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("Mapbox Directions call failed: httpCode=$httpCode curlError=$curlError");
        return null;
    }

    $data = json_decode($response, true);
    $apiCode = (string) ($data['code'] ?? '');
    if ($apiCode !== '' && $apiCode !== 'Ok') {
        // Mapbox's own status for "no route" is NoRoute; NoSegment/InvalidInput mean one of
        // the coordinates isn't on/near a road it can route from - same user-facing outcome.
        throw new NoRouteFoundException('No route could be found between these locations.');
    }

    $meters = $data['routes'][0]['distance'] ?? null;
    $seconds = $data['routes'][0]['duration'] ?? null;
    if (!is_numeric($meters) || !is_numeric($seconds)) {
        throw new NoRouteFoundException('No route could be found between these locations.');
    }
    return ['distance_km' => ((float) $meters) / 1000, 'duration_min' => ((float) $seconds) / 60];
}

// Thin wrapper for the many existing callers that only ever wanted the distance.
function road_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): ?float {
    return road_route_metrics($lat1, $lng1, $lat2, $lng2)['distance_km'] ?? null;
}

// The one call site every pricing-relevant distance calculation must go through. Deliberately
// has no straight-line fallback - a haversine guess can be significantly shorter than the
// real road route (bridges, one-ways, river crossings), which would under- or over-charge
// against the distance actually driven. Callers must catch this and surface a retry rather
// than ever price off an approximation.
function pricing_distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    return pricing_route_metrics($lat1, $lng1, $lat2, $lng2)['distance_km'];
}

// Same contract as pricing_distance_km() (throws instead of an approximate fallback) but
// also returns the route's planned drive time, so callers that need to record
// bookings.planned_duration_minutes don't have to make a second Directions call. May throw
// NoRouteFoundException (see road_route_metrics()) - callers that want to distinguish "no
// route exists" from "transient failure, try again" should catch that first.
function pricing_route_metrics(float $lat1, float $lng1, float $lat2, float $lng2): array {
    // Try the accurate road routing first. If Mapbox explicitly reports "no route"
    // re-throw so callers can surface an address-specific error. For transient
    // failures (network, auth, rate-limit) fall back to a straight-line haversine
    // approximation so bookings can still be created/priced temporarily.
    try {
        $metrics = road_route_metrics($lat1, $lng1, $lat2, $lng2);
    } catch (NoRouteFoundException $e) {
        // A true 'no route' is user-fixable (bad addresses) - preserve behaviour.
        throw $e;
    } catch (Throwable $e) {
        // Transient/config failure - log and fall back to haversine approximation.
        error_log('Mapbox pricing: transient failure, falling back to haversine: ' . $e->getMessage());
        $metrics = null;
    }

    if ($metrics === null) {
        // Haversine fallback (straight-line) - temporary and approximate.
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2)**2;
        $distance_km = $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));

        // Estimate duration using a configurable average speed (km/h). Adjust via
        // config/env.php with the key 'mapbox_haversine_speed_kmh' (optional).
        $configuredSpeed = (float) (config_app()['mapbox_haversine_speed_kmh'] ?? 0.0);
        $average_speed_kmh = $configuredSpeed > 0 ? $configuredSpeed : 25.0;
        $duration_min = ($distance_km / $average_speed_kmh) * 60.0;

        error_log(sprintf('Mapbox pricing: using haversine fallback (approx). distance_km=%.3f duration_min=%.1f speed_kmh=%.1f', $distance_km, $duration_min, $average_speed_kmh));

        // Persist a small flag file so the UI (and admins) can surface that pricing is
        // currently approximate. Non-fatal if this fails.
        try {
            $appRoot = dirname(__DIR__);
            $assetsDir = $appRoot . '/assets';
            if (!is_dir($assetsDir)) {
                @mkdir($assetsDir, 0775, true);
            }
            $flag = [
                'ts' => time(),
                'distance_km' => $distance_km,
                'duration_min' => $duration_min,
                'speed_kmh' => $average_speed_kmh,
                'note' => 'haversine fallback used due to Mapbox transient failure'
            ];
            @file_put_contents($assetsDir . '/pricing_fallback.json', json_encode($flag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // ignore
        }

        return ['distance_km' => $distance_km, 'duration_min' => $duration_min];
    }

    return $metrics;
}
