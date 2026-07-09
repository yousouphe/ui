<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$token = trim($_GET['token'] ?? '');

if ($token === '') exit('Tracking token is required.');

$stmt = $pdo->prepare("
    SELECT 
        b.*,
        r.full_name AS rider_name,
        r.phone AS rider_phone,
        rp.last_latitude,
        rp.last_longitude
    FROM bookings b
    LEFT JOIN users r ON r.id = b.selected_rider_user_id
    LEFT JOIN rider_profiles rp ON rp.user_id = r.id
    WHERE b.sender_tracking_token = ?
      AND b.sender_user_id = ?
    LIMIT 1
");
$stmt->execute([$token, $user['id']]);
$booking = $stmt->fetch();

if (!$booking) exit('Tracking link not found.');

$canPay = $booking['booking_status'] === 'delivered' && $booking['payment_status'] !== 'paid';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <?= csrf_meta_tag() ?>
  <title>Track Delivery</title>
  <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <style>
    body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);color:#eef4ff;min-height:100vh}
    .cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
    .text-soft{color:#9fb0d6}
    #tracking_map{height:420px;border-radius:1rem;overflow:hidden}
  </style>
</head>
<body>
<div class="container py-5">
  <div class="cardx p-4 mb-4">
    <h1 class="h3 fw-bold mb-1">Track Booking <?= e($booking['booking_code']) ?></h1>
    <p class="text-soft mb-0">Rider: <?= e((string)$booking['rider_name']) ?> · <?= e((string)$booking['rider_phone']) ?></p>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="cardx p-4">
        <div id="tracking_map"></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="cardx p-4 mb-4">
        <h2 class="h5">Delivery Status</h2>
        <div class="mb-2"><strong>Status:</strong> <?= e($booking['booking_status']) ?></div>
        <div class="mb-2"><strong>Payment:</strong> <?= e($booking['payment_status']) ?></div>
        <div class="mb-2"><strong>Pickup:</strong> <span class="text-soft"><?= e($booking['pickup_address']) ?></span></div>
        <div class="mb-2"><strong>Delivery:</strong> <span class="text-soft"><?= e($booking['delivery_address']) ?></span></div>
        <div class="mb-2"><strong>Agreed Amount:</strong> ₦<?= number_format((float)$booking['agreed_cost'], 2) ?></div>
      </div>

      <?php if (!empty($booking['delivery_proof_image'])): ?>
      <div class="cardx p-4 mb-4">
        <h2 class="h5">Proof of Delivery</h2>
        <img src="<?= e(url_path($booking['delivery_proof_image'])) ?>" alt="Proof" class="img-fluid rounded">
      </div>
      <?php endif; ?>

      <?php if ($canPay): ?>
      <div class="cardx p-4">
        <h2 class="h5">Payment Required</h2>
        <p class="text-soft">Your item has been delivered. Complete payment to close this booking.</p>
        <button class="btn btn-success w-100" id="pay-now-btn">Pay ₦<?= number_format((float)$booking['agreed_cost'], 2) ?></button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php if ($canPay): ?>
<script src="https://js.paystack.co/v1/inline.js"></script>
<?php endif; ?>


<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>

<script>
let map, riderMarker, routingControl;
let currentRouteTarget = null;

const bookingId = <?= (int)$booking['id'] ?>;
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

// INIT MAP
map = L.map('tracking_map').setView(
    [<?= $booking['pickup_latitude'] ?>, <?= $booking['pickup_longitude'] ?>],
    13
);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

// MARKERS
const pickupMarker = L.marker([<?= $booking['pickup_latitude'] ?>, <?= $booking['pickup_longitude'] ?>])
    .addTo(map).bindPopup("Pickup");

const deliveryMarker = L.marker([<?= $booking['delivery_latitude'] ?>, <?= $booking['delivery_longitude'] ?>])
    .addTo(map).bindPopup("Delivery");

// ROUTE + TRACKING FUNCTION
async function updateTracking() {
    try {
        const res = await fetch(`bookings/ajax_track_status.php?booking_id=${bookingId}`);
        const json = await res.json();

        if (!json.status) return;

        const d = json.data;

        if (!d.rider_lat || !d.rider_lng) return;

        const riderLatLng = [parseFloat(d.rider_lat), parseFloat(d.rider_lng)];

        // CREATE / UPDATE RIDER MARKER
        if (!riderMarker) {
            riderMarker = L.marker(riderLatLng, {
                icon: L.divIcon({
                    html: '<div style="background:#38bdf8;width:14px;height:14px;border-radius:50%;box-shadow:0 0 10px #38bdf8;"></div>',
                    className:''
                })
            }).addTo(map).bindPopup("Rider");
        } else {
            riderMarker.setLatLng(riderLatLng);
        }

        // DETERMINE TARGET BASED ON STATUS
        let target;

        if (d.booking_status === 'accepted') {
            target = [d.pickup_lat, d.pickup_lng];
        } else {
            target = [d.delivery_lat, d.delivery_lng];
        }

        // UPDATE ROUTE ONLY IF TARGET CHANGED OR FIRST LOAD
        if (!routingControl || JSON.stringify(target) !== JSON.stringify(currentRouteTarget)) {
            if (routingControl) map.removeControl(routingControl);

            routingControl = L.Routing.control({
                waypoints: [
                    L.latLng(riderLatLng),
                    L.latLng(target)
                ],
                routeWhileDragging: false,
                addWaypoints: false,
                draggableWaypoints: false,
                createMarker: () => null,
                lineOptions: {
                    styles: [{ color: '#38bdf8', weight: 6 }]
                }
            }).addTo(map);

            routingControl.on('routesfound', function(e) {
                const route = e.routes[0];
                const distKm = (route.summary.totalDistance / 1000).toFixed(2);
                const etaMin = Math.round(route.summary.totalTime / 60);

                document.getElementById('distance_display').innerText = distKm + ' km';
                
                if (!document.getElementById('eta_display')) {
                    const el = document.createElement('div');
                    el.id = 'eta_display';
                    el.className = 'stat-value text-warning';
                    document.querySelector('.stats-bar').appendChild(el);
                }

                document.getElementById('eta_display').innerText = etaMin + ' min ETA';
            });

            currentRouteTarget = target;
        }

    } catch (e) {
        console.log("Tracking error", e);
    }
}

// AUTO REFRESH (LIVE)
setInterval(updateTracking, 5000);

// FIRST LOAD
updateTracking();
</script>

<script>

<?php if ($canPay): ?>
document.getElementById('pay-now-btn').addEventListener('click', async function () {
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Initializing payment...';

    try {
        const res = await fetch('<?= e(url_path('payments/initialize.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: <?= (int)$booking['id'] ?>, csrf_token: CSRF_TOKEN })
        });

        const data = await res.json();

        if (!res.ok || !data.status) {
            throw new Error(data.message || 'Unable to initialize payment');
        }

        const handler = PaystackPop.setup({
            key: data.data.public_key,
            email: data.data.email,
            amount: data.data.amount,
            ref: data.data.reference,
            callback: function(response) {
                window.location.href = data.data.callback_url + '?reference=' + encodeURIComponent(response.reference);
            },
            onClose: function() {
                btn.disabled = false;
                btn.textContent = 'Pay ₦<?= number_format((float)$booking['agreed_cost'], 2) ?>';
            }
        });

        handler.openIframe();
    } catch (err) {
        alert(err.message);
        btn.disabled = false;
        btn.textContent = 'Pay ₦<?= number_format((float)$booking['agreed_cost'], 2) ?>';
    }
});
<?php endif; ?>
</script>
</body>
</html>