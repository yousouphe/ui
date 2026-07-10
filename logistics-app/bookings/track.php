<?php
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    http_response_code(404);
    exit('Tracking link not found.');
}

$stmt = $pdo->prepare('
    SELECT b.*, u.full_name AS rider_name, u.phone AS rider_phone
    FROM bookings b
    LEFT JOIN users u ON u.id = b.selected_rider_user_id
    WHERE b.sender_tracking_token = ?
    LIMIT 1
');
$stmt->execute([$token]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    exit('Tracking link not found or has expired.');
}

$hasRider = !empty($booking['selected_rider_user_id']);
$hasRouteCoords = $booking['pickup_latitude'] !== null
    && $booking['pickup_longitude'] !== null
    && $booking['delivery_latitude'] !== null
    && $booking['delivery_longitude'] !== null;
$statusLabel = booking_status_label((string) $booking['booking_status']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tracking <?= e($booking['booking_code']) ?> | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if ($hasRider && $hasRouteCoords): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .info-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:rgba(15,42,68,.06);border:1px solid rgba(15,42,68,.10);font-size:.9rem}
        .leaflet-container{height:100%;width:100%}
        #tracking_map{height:320px;border-radius:1rem;border:2px solid rgba(110,168,254,.2);overflow:hidden}
        .rider-contact-card{display:flex;align-items:center;gap:12px;padding:.9rem;border-radius:1rem;background:rgba(56,189,248,.08);border:1px solid rgba(56,189,248,.2)}
        .rider-avatar{width:48px;height:48px;border-radius:50%;background:rgba(56,189,248,.16);border:1px solid rgba(56,189,248,.3);display:flex;align-items:center;justify-content:center;color:#0ea5e9;font-size:1.3rem;flex-shrink:0}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <span class="navbar-brand fw-bold">SwiftDrop</span>
        <span class="text-soft small">Live Tracking</span>
    </div>
</nav>

<div class="container py-4" style="max-width:720px">
    <div class="cardx p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-1"><?= e($booking['booking_code']) ?></h1>
                <p class="text-soft mb-0"><?= e($booking['item_name']) ?> &middot; <?= e($booking['item_category']) ?></p>
            </div>
            <span class="info-pill"><i class="fa-solid fa-circle-info text-info"></i> <span id="booking_status_text"><?= e($statusLabel) ?></span></span>
        </div>

        <?php if ($booking['booking_status'] === 'cancelled'): ?>
            <div class="alert alert-danger mb-0">This delivery was cancelled.</div>
        <?php else: ?>

        <?php if ($hasRider): ?>
        <div class="rider-contact-card mb-3">
            <div class="rider-avatar"><i class="fa-solid fa-motorcycle"></i></div>
            <div class="flex-grow-1">
                <div class="fw-bold"><?= e((string) $booking['rider_name']) ?></div>
                <div class="text-soft small">Your delivery rider</div>
            </div>
            <?php if (!empty($booking['rider_phone'])): ?>
            <a class="btn btn-sm btn-outline-info" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $booking['rider_phone'])) ?>">
                <i class="fa-solid fa-phone me-1"></i>Call
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-info mb-3">A rider hasn't been assigned yet. This page will update automatically once one is on the way.</div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="small text-soft mb-2"><strong>Pickup:</strong> <?= e($booking['pickup_address']) ?></div>
                <div class="small text-soft mb-2"><strong>Delivery:</strong> <?= e($booking['delivery_address']) ?></div>
                <?php if (trim((string) ($booking['item_description'] ?? '')) !== ''): ?>
                    <div class="small text-soft mb-2"><strong>Package:</strong> <?= e($booking['item_description']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <?php if ($hasRider): ?>
                    <div class="small text-soft mb-2"><strong>Distance / ETA:</strong> <span id="eta_text">--</span></div>
                <?php endif; ?>
                <?php if (!empty($booking['estimated_value'])): ?>
                    <div class="small text-soft mb-2"><strong>Est. Value:</strong> &#8358;<?= number_format((float) $booking['estimated_value'], 2) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($booking['item_image_path'])): ?>
            <div class="mb-3">
                <img src="<?= e(url_path($booking['item_image_path'])) ?>" class="img-fluid rounded" style="max-height:160px" alt="Package photo">
            </div>
        <?php endif; ?>

        <?php if ($hasRider && $hasRouteCoords): ?>
            <div id="tracking_map"></div>
        <?php endif; ?>

        <?php if (!empty($booking['delivery_proof_image'])): ?>
            <div class="mt-3">
                <div class="small fw-bold mb-2">Proof of Delivery</div>
                <img src="<?= e(url_path($booking['delivery_proof_image'])) ?>" class="img-fluid rounded" alt="Proof">
            </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <p class="text-soft small text-center">Sent via SwiftDrop Logistics</p>
</div>

<?php if ($booking['booking_status'] !== 'cancelled'): ?>
<script>
    const TRACK_TOKEN = <?= json_encode($token) ?>;

    const bookingStatusLabels = {
        draft: 'Draft',
        submitted: 'Finding Rider',
        matched: 'Rider Assigned',
        accepted: 'Rider Heading to Pickup',
        arrived_at_pickup: 'Rider at Pickup',
        package_received: 'In Transit',
        in_transit: 'In Transit',
        delivered: 'Delivered',
        cancelled: 'Cancelled'
    };

    function formatBookingStatus(status) {
        return bookingStatusLabels[status] || String(status || '').replace(/_/g, ' ');
    }

    function vehicleIconClass(type) {
        return type === 'car' ? 'fa-car-side' : 'fa-motorcycle';
    }
</script>

<?php if ($hasRider && $hasRouteCoords): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const MAPBOX_TOKEN = <?= json_encode(mapbox_token()) ?>;
    const pickupCoords = <?= json_encode([(float) $booking['pickup_latitude'], (float) $booking['pickup_longitude']]) ?>;
    const deliveryCoords = <?= json_encode([(float) $booking['delivery_latitude'], (float) $booking['delivery_longitude']]) ?>;

    async function fetchMapboxRoute(points) {
        try {
            const coordsParam = points.map(p => `${p[1]},${p[0]}`).join(';');
            const url = `https://api.mapbox.com/directions/v5/mapbox/driving/${coordsParam}?geometries=geojson&overview=full&access_token=${MAPBOX_TOKEN}`;
            const res = await fetch(url);
            const data = await res.json();
            const route = data.routes && data.routes[0];
            if (!route) return null;
            return {
                durationSec: route.duration,
                distanceMeters: route.distance,
                latlngs: route.geometry.coordinates.map(c => [c[1], c[0]])
            };
        } catch (err) {
            console.error('Mapbox directions request failed:', err);
            return null;
        }
    }

    const map = L.map('tracking_map').setView(pickupCoords, 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);

    const pickupIcon = L.divIcon({
        html: '<div style="color:#f59e0b;font-size:22px;text-shadow:0 0 4px #fff;"><i class="fa-solid fa-box"></i></div>',
        className: '', iconSize: [26, 26], iconAnchor: [13, 13]
    });
    const deliveryIcon = L.divIcon({
        html: '<div style="color:#22c55e;font-size:22px;text-shadow:0 0 4px #fff;"><i class="fa-solid fa-box-open"></i></div>',
        className: '', iconSize: [26, 26], iconAnchor: [13, 13]
    });
    L.marker(pickupCoords, { icon: pickupIcon }).addTo(map).bindPopup('Pickup');
    L.marker(deliveryCoords, { icon: deliveryIcon }).addTo(map).bindPopup('Delivery');

    // Draw the full pickup-to-delivery trip once as a fixed reference line - the rider marker
    // then just moves along/near it as their live position updates, rather than redrawing a
    // fresh line on every poll.
    (async () => {
        const tripRoute = await fetchMapboxRoute([pickupCoords, deliveryCoords]);
        if (tripRoute) {
            const line = L.polyline(tripRoute.latlngs, { color: '#38bdf8', weight: 5, opacity: 0.85 }).addTo(map);
            map.fitBounds(line.getBounds(), { padding: [40, 40] });
        }
    })();

    let riderMarker = null;
    let currentTrackTarget = null;

    async function pollTracking() {
        try {
            const res = await fetch(`bookings/ajax_public_track.php?token=${encodeURIComponent(TRACK_TOKEN)}`, { cache: 'no-store' });
            const json = await res.json();
            if (!json.status) return;

            const d = json.data;
            const statusText = document.getElementById('booking_status_text');
            if (statusText) statusText.textContent = formatBookingStatus(d.booking_status);

            if (!d.rider_lat || !d.rider_lng) return;

            const riderLatLng = [parseFloat(d.rider_lat), parseFloat(d.rider_lng)];
            const riderIcon = L.divIcon({
                html: `<div style="color:#0ea5e9;font-size:24px;text-shadow:0 0 5px #fff;"><i class="fa-solid ${vehicleIconClass(d.vehicle_type)}"></i></div>`,
                className: '', iconSize: [30, 30], iconAnchor: [15, 15]
            });
            if (!riderMarker) {
                riderMarker = L.marker(riderLatLng, { icon: riderIcon }).addTo(map).bindPopup('Rider');
            } else {
                riderMarker.setLatLng(riderLatLng);
                riderMarker.setIcon(riderIcon);
            }

            let target;
            if (d.booking_status === 'matched' || d.booking_status === 'accepted') {
                target = [parseFloat(d.pickup_lat), parseFloat(d.pickup_lng)];
            } else {
                target = [parseFloat(d.delivery_lat), parseFloat(d.delivery_lng)];
            }

            const targetKey = JSON.stringify([target, riderLatLng]);
            if (targetKey === currentTrackTarget) return;
            currentTrackTarget = targetKey;

            const etaText = document.getElementById('eta_text');
            if (etaText) etaText.textContent = 'Calculating...';

            const leg = await fetchMapboxRoute([riderLatLng, target]);
            if (leg) {
                const distKm = (leg.distanceMeters / 1000).toFixed(2);
                const etaMin = Math.round(leg.durationSec / 60);
                if (etaText) etaText.textContent = `${etaMin} min · ${distKm} km`;
            } else if (etaText) {
                etaText.textContent = '--';
            }
        } catch (e) {
            console.error('Tracking error', e);
        }
    }

    pollTracking();
    setInterval(() => { if (!document.hidden) pollTracking(); }, 10000);
</script>
<?php else: ?>
<script>
    async function pollPublicStatus() {
        try {
            const res = await fetch(`bookings/ajax_public_track.php?token=${encodeURIComponent(TRACK_TOKEN)}`, { cache: 'no-store' });
            const json = await res.json();
            if (!json.status) return;

            const statusText = document.getElementById('booking_status_text');
            if (statusText) statusText.textContent = formatBookingStatus(json.data.booking_status);

            <?php if (!$hasRider): ?>
            if (json.data.rider_lat) {
                window.location.reload();
            }
            <?php endif; ?>
        } catch (e) {
            console.error('Tracking error', e);
        }
    }

    setInterval(() => { if (!document.hidden) pollPublicStatus(); }, 15000);
</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
