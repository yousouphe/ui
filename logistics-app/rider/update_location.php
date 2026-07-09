<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();

// Fetch the active booking for this rider (if any) - covers every stage from being
// matched through to in_transit, matching the status list used across the rest of the app.
$stmt = $pdo->prepare('
    SELECT b.* FROM bookings b
    WHERE b.selected_rider_user_id = ?
    AND b.booking_status IN ("matched", "accepted", "arrived_at_pickup", "package_received", "in_transit")
    LIMIT 1
');
$stmt->execute([$user['id']]);
$activeBooking = $stmt->fetch();

// Target is pickup while matched/accepted (not yet picked up), delivery from
// arrived_at_pickup onward - matching the target logic used on every other map in the app.
$targetType = null;
$targetLat = null;
$targetLng = null;
$targetAddress = null;
if ($activeBooking) {
    if (in_array($activeBooking['booking_status'], ['matched', 'accepted'], true)) {
        $targetType = 'Pickup';
        $targetLat = $activeBooking['pickup_latitude'] !== null ? (float)$activeBooking['pickup_latitude'] : null;
        $targetLng = $activeBooking['pickup_longitude'] !== null ? (float)$activeBooking['pickup_longitude'] : null;
        $targetAddress = $activeBooking['pickup_address'];
    } else {
        $targetType = 'Delivery';
        $targetLat = $activeBooking['delivery_latitude'] !== null ? (float)$activeBooking['delivery_latitude'] : null;
        $targetLng = $activeBooking['delivery_longitude'] !== null ? (float)$activeBooking['delivery_longitude'] : null;
        $targetAddress = $activeBooking['delivery_address'];
    }
}



?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title>Rider Navigation</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        body{background:#09101d; min-height:100vh; color:#eef4ff; overflow-x:hidden;}
        .cardx{background:rgba(17,27,51,.95); border-radius:1.25rem; border:1px solid rgba(255,255,255,.08);}
        #nav_map { height: 350px; width: 100%; border-radius: 1rem; margin-bottom: 1.5rem; border: 1px solid rgba(255,255,255,0.1); }
        .log-box { background: #0b1430; height: 80px; overflow-y: auto; font-family: monospace; font-size: 0.75rem; padding: 8px; border-radius: 8px; color: #9fb0d6; }
        .btn-arrival { transition: all 0.3s ease; }
        .pulse-green { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); animation: pulse-green 2s infinite; }
        @keyframes pulse-green { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="cardx p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 fw-bold mb-0">Navigation</h1>
            <span class="badge bg-success" id="distance_display">Calculating...</span>
        </div>

        <div id="nav_map"></div>

        <?php if ($activeBooking): ?>
            <div class="mb-3">
                <p class="small text-soft mb-1"><?= e($targetType) ?>:</p>
                <p class="fw-bold mb-0 text-truncate"><?= e((string)$targetAddress) ?></p>
            </div>

            <form id="arrival_form" method="post" action="rider/mark_arrived.php">
                <?= csrf_field() ?>
                <input type="hidden" name="booking_id" value="<?= $activeBooking['id'] ?>">
                <button type="submit" id="btn_arrived" class="btn btn-secondary w-100 py-3 fw-bold btn-arrival" disabled>
                    Not Yet at <?= e($targetType) ?>
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-info py-2 small">No active delivery to track.</div>
        <?php endif; ?>

        <hr class="my-3 opacity-25">

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label class="small text-soft">Status</label>
                <select class="form-select form-select-sm bg-dark text-white border-secondary" id="availability_status">
                    <option value="available">Available</option>
                    <option value="busy" selected>Busy (Tracking)</option>
                    <option value="offline">Offline</option>
                </select>
            </div>
            <div class="col-6 text-end">
                <label class="small text-soft d-block">Last Sync</label>
                <span id="sync_time" class="small">--:--:--</span>
            </div>
        </div>

        <div class="log-box" id="tracking_log">
            <div>[System] Map & GPS Ready.</div>
        </div>
        
        <div class="mt-3">
            <a href="rider/dashboard.php" class="btn btn-sm btn-outline-light w-100">Exit Navigation</a>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const logBox = document.getElementById('tracking_log');
    const syncTime = document.getElementById('sync_time');
    const btnArrived = document.getElementById('btn_arrived');
    const distDisplay = document.getElementById('distance_display');
    
    // Current target (pickup or delivery, from PHP based on booking status)
    const dest = {
        lat: <?= $targetLat ?? 'null' ?>,
        lng: <?= $targetLng ?? 'null' ?>,
        label: <?= json_encode((string)$targetType) ?>
    };
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    // Nigeria's geographic centroid - used only until a real GPS fix or target arrives.
    const NIGERIA_CENTER = [9.0820, 8.6753];

    let map, riderMarker, destMarker;

    function explainGeoError(err) {
        if (!err) return 'Unable to fetch current location.';
        if (err.code === 1) return 'Location permission was denied.';
        if (err.code === 2) return 'Position unavailable. The device could not get a reliable fix.';
        if (err.code === 3) return 'Location request timed out.';
        return 'Unable to fetch current location.';
    }

    function logEntry(text) {
        const entry = document.createElement('div');
        entry.textContent = text;
        logBox.prepend(entry);
    }

    function initMap() {
        map = L.map('nav_map').setView(dest.lat ? [dest.lat, dest.lng] : NIGERIA_CENTER, dest.lat ? 14 : 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OSM'
        }).addTo(map);

        if (dest.lat) {
            destMarker = L.marker([dest.lat, dest.lng], {
                icon: L.divIcon({html: '🚩', className: 'fs-3', iconSize: [30, 30]})
            }).addTo(map).bindPopup(dest.label + ' Location');
        }
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371e3; // metres
        const φ1 = lat1 * Math.PI/180;
        const φ2 = lat2 * Math.PI/180;
        const Δφ = (lat2-lat1) * Math.PI/180;
        const Δλ = (lon2-lon1) * Math.PI/180;
        const a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                  Math.cos(φ1) * Math.cos(φ2) *
                  Math.sin(Δλ/2) * Math.sin(Δλ/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c; // in metres
    }

    async function updateLocation() {
        if (!navigator.geolocation) return;

        navigator.geolocation.getCurrentPosition(async function(pos) {
            const riderLat = pos.coords.latitude;
            const riderLng = pos.coords.longitude;
            
            // 1. Update Map
            if (!riderMarker) {
                riderMarker = L.marker([riderLat, riderLng]).addTo(map).bindPopup("You");
                map.setView([riderLat, riderLng], 16);
            } else {
                riderMarker.setLatLng([riderLat, riderLng]);
            }

            // 2. Proximity Check (if delivery active)
            if (dest.lat) {
                const distance = calculateDistance(riderLat, riderLng, dest.lat, dest.lng);
                distDisplay.textContent = distance > 1000 ? (distance/1000).toFixed(2) + ' km' : Math.round(distance) + ' m';
                
                // This page uses straight-line distance (no road routing), which usually
                // underestimates real road distance - so use a tighter radius than the
                // 300m road-distance threshold used on the full rider dashboards, to reduce
                // false "arrived" triggers.
                if (distance <= 150) {
                    btnArrived.disabled = false;
                    btnArrived.textContent = "MARK AS ARRIVED";
                    btnArrived.classList.replace('btn-secondary', 'btn-success');
                    btnArrived.classList.add('pulse-green');
                } else {
                    btnArrived.disabled = true;
                    btnArrived.textContent = "Too far to mark arrival";
                }
            }

            // 3. Sync with Server
            try {
                const response = await fetch('rider/ajax_update_location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        latitude: riderLat,
                        longitude: riderLng,
                        status: document.getElementById('availability_status').value,
                        csrf_token: CSRF_TOKEN
                    })
                });
                const res = await response.json();
                if (res.success) {
                    syncTime.textContent = new Date().toLocaleTimeString();
                    logEntry(res.skipped
                        ? `Synced @ ${riderLat.toFixed(4)}, ${riderLng.toFixed(4)} (unchanged, skipped write)`
                        : `Synced @ ${riderLat.toFixed(4)}, ${riderLng.toFixed(4)}`);
                } else {
                    logEntry(`Sync rejected: ${res.message || 'unknown error'}`);
                }
            } catch (e) {
                logEntry('Sync failed: could not reach server.');
            }

        }, function (err) {
            logEntry(explainGeoError(err));
            distDisplay.textContent = 'GPS issue';
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 });
    }

    initMap();
    updateLocation();
    setInterval(updateLocation, 30000);
</script>

</body>
</html>