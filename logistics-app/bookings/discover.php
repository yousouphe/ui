<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) exit('Booking ID is required.');

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND sender_user_id = ? LIMIT 1');
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch();
if (!$booking) exit('Booking not found.');

if ($booking['pickup_latitude'] === null || $booking['delivery_latitude'] === null) {
    exit('Booking must have coordinates for pricing calculation.');
}

$pickupLat = (float)$booking['pickup_latitude'];
$pickupLng = (float)$booking['pickup_longitude'];
$deliveryLat = (float)$booking['delivery_latitude'];
$deliveryLng = (float)$booking['delivery_longitude'];

function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2)**2;
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

$deliveryDistanceKm = haversine_distance($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
$success = flash('success');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title>Discover Riders | Aike</title>
    <base href="<?= e(base_url() . '/') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
        .navx{background:rgba(8,17,33,.88);border-bottom:1px solid rgba(255,255,255,.08)}
        .cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#9fb0d6}
        .rider-card{background:#0b1430;border:1px solid rgba(255,255,255,.08);border-radius:1rem}
        #radar_map { height: 500px; border-radius: 1.25rem; border: 2px solid rgba(110, 168, 254, 0.2); }
        .form-control { background:#0b1430; color:#fff; border-color: rgba(255,255,255,0.1); }
        .live-dot { height: 10px; width: 10px; background-color: #22c55e; border-radius: 50%; display: inline-block; margin-right: 5px; box-shadow: 0 0 8px #22c55e; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
        
        /* Routing Details Custom Styling */
        #routing-directions { background: rgba(11, 20, 48, 0.95); border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 1rem; color: #fff; display: none; }
        .routing-instructions-list { background: transparent !important; color: #fff !important; border: none !important; }
        .leaflet-routing-alt { background: transparent !important; color: #fff !important; max-height: 250px !important; overflow-y: auto; }
        .leaflet-routing-alt table { color: #fff !important; width: 100%; }
        .leaflet-routing-alt tr:hover { background: rgba(255,255,255,0.05); }
        .leaflet-routing-container { width: 100% !important; background: transparent !important; border: none !important; box-shadow: none !important; }
        
        .eta-badge { font-weight: bold; letter-spacing: 0.5px; animation: fadeIn 0.5s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navx">
  <div class="container">
    <a class="navbar-brand fw-bold" href="dashboard.php">Aike</a>
  </div>
</nav>

<div class="container py-5">
    <div id="alert-container"></div>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="cardx p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 fw-bold mb-1">Rider Radar</h1>
                <p class="text-soft mb-0 small">Pickup: <strong><?= e($booking['pickup_address']) ?></strong></p>
                <p class="text-info small mb-0">Direct Distance: <?= number_format($deliveryDistanceKm, 2) ?> km</p>
            </div>
            <button id="clear-route-btn" class="btn btn-sm btn-outline-warning" style="display:none;" onclick="clearActiveRoute()">
                <i class="fa-solid fa-xmark me-1"></i> Clear Route
            </button>
        </div>
    </div>

    <div id="radar_map" class="mb-3"></div>
    
    <div id="routing-directions" class="p-3 mb-4"></div>

    <div class="row g-4" id="rider-list-container">
        <div class="col-12 text-center py-5">
            <div class="spinner-border text-info" role="status"></div>
            <p class="mt-2 text-soft">Scanning for nearby riders...</p>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>

<script>
let map, routingControl, pingSound;
let riderMarkers = {};
let knownRiderIds = new Set();
const pickupCoords = [<?= $pickupLat ?>, <?= $pickupLng ?>];
const deliveryCoords = [<?= $deliveryLat ?>, <?= $deliveryLng ?>];
const bookingId = <?= $bookingId ?>;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

document.addEventListener('DOMContentLoaded', function() {
    map = L.map('radar_map').setView(pickupCoords, 14);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
    
    // Google Traffic Layer
    L.tileLayer('https://mt1.google.com/vt?lyrs=h@159000000,traffic|seconds_into_week:-1&style=3&x={x}&y={y}&z={z}', {
        opacity: 0.65
    }).addTo(map);

    pingSound = new Audio('assets/sounds/notification.mp3');

    L.marker(pickupCoords, {
        icon: L.divIcon({html: '<div style="background:#0d6efd; width:16px; height:16px; border-radius:50%; border:3px solid white; box-shadow: 0 0 10px #0d6efd;"></div>', className:''})
    }).addTo(map).bindPopup("<b>Pickup Point</b>");

    L.marker(deliveryCoords, {
        icon: L.divIcon({html: '<div style="background:#ff4757; width:16px; height:16px; border-radius:3px; border:3px solid white; box-shadow: 0 0 10px #ff4757;"></div>', className:''})
    }).addTo(map).bindPopup("<b>Destination</b>");

    updateRiders(); 
    setInterval(updateRiders, 10000);
});

async function updateRiders() {
    try {
        const response = await fetch(`bookings/ajax_fetch_riders.php?booking_id=${bookingId}`);
        const riders = await response.json();
        const listContainer = document.getElementById('rider-list-container');
        let html = '';
        let activeIds = new Set();
        let newRiderFound = false;

        riders.forEach(rider => {
            activeIds.add(rider.id);
            const latlng = [parseFloat(rider.last_latitude), parseFloat(rider.last_longitude)];

            if (!knownRiderIds.has(rider.id) && knownRiderIds.size > 0) newRiderFound = true;

            if (riderMarkers[rider.id]) {
                riderMarkers[rider.id].setLatLng(latlng);
            } else {
                const icon = L.divIcon({
                    html: `<div style="color: ${rider.vehicle_type === 'car' ? '#38bdf8' : '#fbbf24'}; font-size: 24px; text-shadow: 0 0 5px #000;"><i class="fa-solid ${rider.vehicle_type === 'car' ? 'fa-car-side' : 'fa-motorcycle'}"></i></div>`,
                    className: '', iconSize: [30, 30], iconAnchor: [15, 15]
                });
                riderMarkers[rider.id] = L.marker(latlng, { icon: icon }).addTo(map).bindPopup(`<b>${rider.full_name}</b>`);
            }

            html += `
                <div class="col-lg-6" id="rider-card-${rider.id}">
                    <div class="cardx p-4 h-100">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h2 class="h5 mb-1">${rider.full_name}</h2>
                                <div class="text-soft small">
                                    <span>${rider.vehicle_type === 'car' ? '�0�7' : '�9�3�1�5'} ${rider.vehicle_type.toUpperCase()}</span> | �8�2 ${parseFloat(rider.rating).toFixed(1)}
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <span class="badge bg-info">${parseFloat(rider.distance_km).toFixed(2)} km</span>
                                    <span class="badge bg-dark border border-info text-info eta-badge" id="eta-${rider.id}" style="display:none;"></span>
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-info" onclick="showRiderRoute(${rider.last_latitude}, ${rider.last_longitude}, ${rider.id})">
                                <i class="fa-solid fa-route me-1"></i> Route
                            </button>
                        </div>
                        <div class="rider-card p-3 mt-3">
                            <form onsubmit="sendRiderRequest(event, this)">
                                <input type="hidden" name="booking_id" value="${bookingId}">
                                <input type="hidden" name="rider_user_id" value="${rider.id}">
                                <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                                <div class="row g-2 align-items-end">
                                    <div class="col">
                                        <label class="form-label small text-soft">Proposed Fee (�6�6)</label>
                                        <input class="form-control fw-bold text-info" type="number" name="proposed_cost" value="${rider.suggested_fee}">
                                    </div>
                                    <div class="col-auto">
                                        <button class="btn btn-primary" type="submit">Request</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>`;
        });

        if (newRiderFound) pingSound.play().catch(() => {});
        knownRiderIds = activeIds;
        listContainer.innerHTML = html || '<div class="col-12 text-center text-soft py-5">No active riders found in range.</div>';
    } catch (err) { console.error("Update Error:", err); }
}

window.showRiderRoute = (rLat, rLng, rId) => {
    if (routingControl) map.removeControl(routingControl);
    
    const directionsDiv = document.getElementById('routing-directions');
    directionsDiv.style.display = 'block';
    directionsDiv.innerHTML = '<div class="text-center p-3 text-info"><i class="fa-solid fa-spinner fa-spin me-2"></i>Analyzing Traffic & Route...</div>';
    document.getElementById('clear-route-btn').style.display = 'block';

    routingControl = L.Routing.control({
        waypoints: [L.latLng(rLat, rLng), L.latLng(pickupCoords[0], pickupCoords[1]), L.latLng(deliveryCoords[0], deliveryCoords[1])],
        lineOptions: { styles: [{ color: '#38bdf8', opacity: 0.7, weight: 9 }] },
        createMarker: () => null,
        addWaypoints: false,
        itineraryClassName: 'routing-instructions-list',
        show: true
    }).addTo(map);

    routingControl.on('routesfound', function(e) {
        const summary = e.routes[0].summary;
        const mins = Math.round(summary.totalTime / 60);
        
        const badge = document.getElementById(`eta-${rId}`);
        if (badge) {
            badge.style.display = 'inline-block';
            badge.innerHTML = `<i class="fa-regular fa-clock me-1"></i> ${mins}m ETA`;
        }

        const container = routingControl.getItinerary().getContainer();
        directionsDiv.innerHTML = '<h6 class="text-info fw-bold mb-3"><i class="fa-solid fa-diamond-turn-right me-2"></i>Turn-by-Turn Navigation</h6>';
        directionsDiv.appendChild(container);
    });

    map.fitBounds([[rLat, rLng], pickupCoords, deliveryCoords], { padding: [50, 50] });
};

window.clearActiveRoute = () => {
    if (routingControl) map.removeControl(routingControl);
    document.getElementById('routing-directions').style.display = 'none';
    document.getElementById('clear-route-btn').style.display = 'none';
    document.querySelectorAll('.eta-badge').forEach(el => el.style.display = 'none');
    map.flyTo(pickupCoords, 14);
};

// AJAX Request Handler
async function sendRiderRequest(event, form) {
    event.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const formData = new FormData(form);
        const response = await fetch('bookings/send_request.php', {
            method: 'POST',
            body: formData
        });
        
        // Assuming send_request.php redirects or returns JSON. 
        // If it redirects, we manually check success.
        if (response.ok) {
            btn.classList.replace('btn-primary', 'btn-success');
            btn.innerText = 'Sent!';
            showGlobalAlert('Request sent successfully!', 'success');
        } else {
            throw new Error();
        }
    } catch (err) {
        btn.disabled = false;
        btn.innerText = originalText;
        showGlobalAlert('Failed to send request. Try again.', 'danger');
    }
}

function showGlobalAlert(msg, type) {
    const container = document.getElementById('alert-container');
    container.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>