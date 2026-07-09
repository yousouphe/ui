<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

$errors = [];
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $errors = validate_required([
        'recipient_name' => 'Recipient name',
        'recipient_phone' => 'Recipient phone',
        'pickup_address' => 'Pickup address',
        'delivery_address' => 'Delivery address',
        'item_name' => 'Item name',
        'item_category' => 'Item category',
    ], $_POST);

    $saveAsDraft = isset($_POST['save_draft']);
    if (!$saveAsDraft && empty($_FILES['item_image']['name'])) {
        $errors['item_image'] = 'Item image is required when submitting a booking.';
    }

    $payload = [
        'sender_user_id' => $user['id'],
        'booking_code' => 'BK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
        'recipient_name' => trim($_POST['recipient_name'] ?? ''),
        'recipient_phone' => trim($_POST['recipient_phone'] ?? ''),
        'pickup_address' => trim($_POST['pickup_address'] ?? ''),
        'pickup_latitude' => ($_POST['pickup_latitude'] ?? '') !== '' ? (float)$_POST['pickup_latitude'] : null,
        'pickup_longitude' => ($_POST['pickup_longitude'] ?? '') !== '' ? (float)$_POST['pickup_longitude'] : null,
        'delivery_address' => trim($_POST['delivery_address'] ?? ''),
        'delivery_latitude' => ($_POST['delivery_latitude'] ?? '') !== '' ? (float)$_POST['delivery_latitude'] : null,
        'delivery_longitude' => ($_POST['delivery_longitude'] ?? '') !== '' ? (float)$_POST['delivery_longitude'] : null,
        'item_name' => trim($_POST['item_name'] ?? ''),
        'item_category' => trim($_POST['item_category'] ?? ''),
        'item_description' => trim($_POST['item_description'] ?? ''),
        'estimated_value' => ($_POST['estimated_value'] ?? '') !== '' ? (float)$_POST['estimated_value'] : null,
        'special_instructions' => trim($_POST['special_instructions'] ?? ''),
        'booking_status' => $saveAsDraft ? 'draft' : 'submitted',
    ];

    if (!$errors) {
        try {
            $payload['item_image_path'] = save_item_image($_FILES['item_image'] ?? []);
            $stmt = $pdo->prepare('
                INSERT INTO bookings (
                    sender_user_id, booking_code, recipient_name, recipient_phone,
                    pickup_address, pickup_latitude, pickup_longitude,
                    delivery_address, delivery_latitude, delivery_longitude,
                    item_name, item_category, item_description, item_image_path,
                    estimated_value, special_instructions, booking_status
                ) VALUES (
                    :sender_user_id, :booking_code, :recipient_name, :recipient_phone,
                    :pickup_address, :pickup_latitude, :pickup_longitude,
                    :delivery_address, :delivery_latitude, :delivery_longitude,
                    :item_name, :item_category, :item_description, :item_image_path,
                    :estimated_value, :special_instructions, :booking_status
                )
            ');
            $stmt->execute($payload);
            flash('success', $saveAsDraft ? 'Booking saved as draft.' : 'Booking submitted successfully.');
            redirect_to('bookings/list.php');
        } catch (Throwable $e) {
            $errors['general'] = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Create Booking</title>
  <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
    .navx{background:rgba(8,17,33,.88);border-bottom:1px solid rgba(255,255,255,.08)}
    .cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
    .form-control,.form-select{background:#0b1430;color:#eef4ff;border-color:rgba(255,255,255,.1)}
    .form-control:focus,.form-select:focus{background:#0b1430;color:#eef4ff;border-color:#6ea8fe;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
    .text-soft{color:#9fb0d6}
    .map-wrap{height:380px;border-radius:1rem;overflow:hidden;border:1px solid rgba(255,255,255,.08)}

    #booking_map {
    height: 380px !important;
    width: 100% !important;
    }

    .leaflet-container{height:100%;width:100%}
    .address-search{position:relative}
    .address-suggestions{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:1500;background:#0b1430;border:1px solid rgba(255,255,255,.14);border-radius:.75rem;box-shadow:0 12px 30px rgba(0,0,0,.35);max-height:260px;overflow-y:auto;display:none}
    .address-suggestions.show{display:block}
    .address-suggestion-item{padding:.6rem .9rem;cursor:pointer;border-bottom:1px solid rgba(255,255,255,.06);display:flex;gap:.6rem;align-items:flex-start}
    .address-suggestion-item:last-child{border-bottom:none}
    .address-suggestion-item:hover,.address-suggestion-item.active{background:rgba(56,189,248,.14)}
    .address-suggestion-item .main-text{font-weight:600;color:#eef4ff;font-size:.9rem}
    .address-suggestion-item .sub-text{color:#9fb0d6;font-size:.78rem}
    .address-suggestion-item i{color:#38bdf8;margin-top:3px}
    .address-suggestion-empty{padding:.75rem .9rem;color:#9fb0d6;font-size:.85rem}
    .location-confirmed{border-color:#22c55e!important}
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navx">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(url_path('index.php')) ?>">SwiftDrop</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="<?= e(url_path('dashboard.php')) ?>">Dashboard</a>
      <a class="nav-link" href="<?= e(url_path('bookings/list.php')) ?>">My Bookings</a>
      <a class="nav-link" href="<?= e(url_path('logout.php')) ?>">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-5">
  <div class="cardx p-4 p-lg-5">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
      <div>
        <h1 class="h3 fw-bold mb-1">Create booking</h1>
        <p class="text-soft mb-0">Choose pickup and destination using GPS or map selection.</p>
      </div>
      <a class="btn btn-outline-light" href="<?= e(url_path('bookings/list.php')) ?>">Back to bookings</a>
    </div>

    <?php if (!empty($errors['general'])): ?><div class="alert alert-danger"><?= e($errors['general']) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="row g-4">
        <div class="col-md-6">
          <label class="form-label">Recipient name</label>
          <input class="form-control" name="recipient_name" value="<?= e(old('recipient_name')) ?>">
          <?php if (!empty($errors['recipient_name'])): ?><div class="small text-danger mt-1"><?= e($errors['recipient_name']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
          <label class="form-label">Recipient phone</label>
          <input class="form-control" name="recipient_phone" value="<?= e(old('recipient_phone')) ?>">
          <?php if (!empty($errors['recipient_phone'])): ?><div class="small text-danger mt-1"><?= e($errors['recipient_phone']) ?></div><?php endif; ?>
        </div>

        <div class="col-12">
          <div class="cardx p-3">
            <h2 class="h5">Pickup location</h2>
            <label class="form-label">Pickup address</label>
            <div class="address-search">
              <div class="input-group">
                <input class="form-control" id="pickup_address" name="pickup_address" value="<?= e(old('pickup_address')) ?>" autocomplete="off" placeholder="Search address, estate, market, landmark...">
                <button class="btn btn-outline-light" type="button" id="use_current_pickup" title="Use current location"><i class="fa-solid fa-location-crosshairs"></i></button>
              </div>
              <div class="address-suggestions" id="pickup_suggestions"></div>
            </div>
            <?php if (!empty($errors['pickup_address'])): ?><div class="small text-danger mt-1"><?= e($errors['pickup_address']) ?></div><?php endif; ?>
            <div class="small mt-2">
              <a href="#" class="link-info text-decoration-none map-pick-link" data-target="pickup"><i class="fa-solid fa-map-location-dot me-1"></i>Can't find it? Pick on map</a>
            </div>
            <input type="hidden" id="pickup_latitude" name="pickup_latitude" value="<?= e(old('pickup_latitude')) ?>">
            <input type="hidden" id="pickup_longitude" name="pickup_longitude" value="<?= e(old('pickup_longitude')) ?>">
          </div>
        </div>

        <div class="col-12">
          <div class="cardx p-3">
            <h2 class="h5">Delivery location</h2>
            <label class="form-label">Delivery address</label>
            <div class="address-search">
              <div class="input-group">
                <input class="form-control" id="delivery_address" name="delivery_address" value="<?= e(old('delivery_address')) ?>" autocomplete="off" placeholder="Search address, estate, market, landmark...">
                <button class="btn btn-outline-light" type="button" id="use_current_delivery" title="Use current location"><i class="fa-solid fa-location-crosshairs"></i></button>
              </div>
              <div class="address-suggestions" id="delivery_suggestions"></div>
            </div>
            <?php if (!empty($errors['delivery_address'])): ?><div class="small text-danger mt-1"><?= e($errors['delivery_address']) ?></div><?php endif; ?>
            <div class="small mt-2">
              <a href="#" class="link-info text-decoration-none map-pick-link" data-target="delivery"><i class="fa-solid fa-map-location-dot me-1"></i>Can't find it? Pick on map</a>
            </div>
            <input type="hidden" id="delivery_latitude" name="delivery_latitude" value="<?= e(old('delivery_latitude')) ?>">
            <input type="hidden" id="delivery_longitude" name="delivery_longitude" value="<?= e(old('delivery_longitude')) ?>">
          </div>
        </div>

        <div class="col-12" id="route_map_card" style="display:none;">
          <div class="cardx p-3">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
              <h2 class="h5 mb-0">Confirm route</h2>
              <span class="badge text-bg-warning" id="map_mode_label" style="display:none;">Mode: none</span>
              <span class="small text-soft" id="route_summary"></span>
            </div>
            <div id="booking_map" class="map-wrap"></div>
          </div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Item name</label>
          <input class="form-control" name="item_name" value="<?= e(old('item_name')) ?>">
          <?php if (!empty($errors['item_name'])): ?><div class="small text-danger mt-1"><?= e($errors['item_name']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6">
          <label class="form-label">Item category</label>
          <?php $selectedCategory = old('item_category'); ?>
          <select class="form-select" name="item_category">
            <option value="">Select category</option>
            <option value="document" <?= $selectedCategory === 'document' ? 'selected' : '' ?>>Document</option>
            <option value="food" <?= $selectedCategory === 'food' ? 'selected' : '' ?>>Food</option>
            <option value="parcel" <?= $selectedCategory === 'parcel' ? 'selected' : '' ?>>Parcel</option>
            <option value="fragile" <?= $selectedCategory === 'fragile' ? 'selected' : '' ?>>Fragile</option>
          </select>
          <?php if (!empty($errors['item_category'])): ?><div class="small text-danger mt-1"><?= e($errors['item_category']) ?></div><?php endif; ?>
        </div>
        <div class="col-12">
          <label class="form-label">Item description</label>
          <textarea class="form-control" name="item_description" rows="4"><?= e(old('item_description')) ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">Estimated value</label>
          <input class="form-control" name="estimated_value" value="<?= e(old('estimated_value')) ?>" placeholder="e.g. 25000">
        </div>
        <div class="col-md-6">
          <label class="form-label">Item image</label>
          <input class="form-control" type="file" name="item_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
          <?php if (!empty($errors['item_image'])): ?><div class="small text-danger mt-1"><?= e($errors['item_image']) ?></div><?php endif; ?>
        </div>
        <div class="col-12">
          <label class="form-label">Special instructions</label>
          <textarea class="form-control" name="special_instructions" rows="3"><?= e(old('special_instructions')) ?></textarea>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap mt-4">
        <button class="btn btn-primary" type="submit" name="submit_booking">Submit Booking</button>
        <button class="btn btn-outline-light" type="submit" name="save_draft">Save as Draft</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const MAPBOX_TOKEN = <?= json_encode(mapbox_token()) ?>;
    const NIGERIA_BBOX = '2.6,4.2,14.7,14.0';

    const pickupAddress = document.getElementById('pickup_address');
    const pickupLat = document.getElementById('pickup_latitude');
    const pickupLng = document.getElementById('pickup_longitude');
    const pickupSuggestions = document.getElementById('pickup_suggestions');
    const deliveryAddress = document.getElementById('delivery_address');
    const deliveryLat = document.getElementById('delivery_latitude');
    const deliveryLng = document.getElementById('delivery_longitude');
    const deliverySuggestions = document.getElementById('delivery_suggestions');
    const modeLabel = document.getElementById('map_mode_label');
    const bookingMapEl = document.getElementById('booking_map');
    const routeMapCard = document.getElementById('route_map_card');
    const routeSummary = document.getElementById('route_summary');

    let mapMode = null;
    let pickupMarker = null;
    let deliveryMarker = null;
    let map = null;
    let routingControl = null;

    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    });

    function ensureMap() {
        if (map) return map;
        map = L.map(bookingMapEl, { tap: false }).setView([
            parseFloat(pickupLat.value) || 9.0820,
            parseFloat(pickupLng.value) || 8.6753
        ], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);
        map.on('click', async function (e) {
            if (!mapMode) return;
            const { lat, lng } = e.latlng;
            updateMarker(mapMode, lat, lng);
            await reverseGeocode(lat, lng, mapMode === 'pickup' ? pickupAddress : deliveryAddress);
        });
        return map;
    }

    function revealMap() {
        routeMapCard.style.display = '';
        const m = ensureMap();
        setTimeout(() => m.invalidateSize(), 150);
        return m;
    }

    function setMode(mode) {
        mapMode = mode;
        revealMap();
        modeLabel.style.display = '';
        modeLabel.textContent = 'Tap the map to set ' + mode.toUpperCase();
        modeLabel.className = 'badge ' + (mode === 'pickup' ? 'text-bg-warning' : 'text-bg-info');
        bookingMapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function maybeRevealForBothPoints() {
        if (pickupLat.value && pickupLng.value && deliveryLat.value && deliveryLng.value) {
            revealMap();
            drawRoutePreview();
        }
    }

    function updateMarker(type, lat, lng) {
        const m = ensureMap();
        if (type === 'pickup') {
            if (pickupMarker) m.removeLayer(pickupMarker);
            pickupMarker = L.marker([lat, lng], { draggable: true }).addTo(m).bindPopup('Pickup').openPopup();
            pickupLat.value = lat.toFixed(7);
            pickupLng.value = lng.toFixed(7);
            pickupMarker.on('dragend', (e) => handleManualMove('pickup', e.target.getLatLng()));
        } else {
            if (deliveryMarker) m.removeLayer(deliveryMarker);
            deliveryMarker = L.marker([lat, lng], { draggable: true }).addTo(m).bindPopup('Delivery').openPopup();
            deliveryLat.value = lat.toFixed(7);
            deliveryLng.value = lng.toFixed(7);
            deliveryMarker.on('dragend', (e) => handleManualMove('delivery', e.target.getLatLng()));
        }
        maybeRevealForBothPoints();
    }

    async function handleManualMove(type, latlng) {
        updateMarker(type, latlng.lat, latlng.lng);
        await reverseGeocode(latlng.lat, latlng.lng, type === 'pickup' ? pickupAddress : deliveryAddress);
    }

    async function reverseGeocode(lat, lng, targetInput) {
        targetInput.value = 'Locating address...';
        try {
            const res = await fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${lng},${lat}.json?access_token=${MAPBOX_TOKEN}&country=ng&language=en`);
            const data = await res.json();
            const place = data.features && data.features[0];
            targetInput.value = place ? place.place_name : `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        } catch (e) {
            targetInput.value = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        }
        targetInput.classList.add('location-confirmed');
    }

    function drawRoutePreview() {
        if (!map || !pickupLat.value || !deliveryLat.value) return;
        const from = [parseFloat(pickupLat.value), parseFloat(pickupLng.value)];
        const to = [parseFloat(deliveryLat.value), parseFloat(deliveryLng.value)];
        if (routingControl) {
            map.removeControl(routingControl);
            routingControl = null;
        }
        routingControl = L.Routing.control({
            waypoints: [L.latLng(from), L.latLng(to)],
            router: L.Routing.osrmv1({ serviceUrl: 'https://router.project-osrm.org/route/v1' }),
            addWaypoints: false,
            draggableWaypoints: false,
            routeWhileDragging: false,
            fitSelectedRoutes: true,
            show: false,
            createMarker: () => null,
            lineOptions: { styles: [{ color: '#38bdf8', opacity: 0.85, weight: 5 }] }
        }).addTo(map);
        routingControl.on('routesfound', function (e) {
            const summary = e.routes[0].summary;
            const km = (summary.totalDistance / 1000).toFixed(1);
            const mins = Math.round(summary.totalTime / 60);
            routeSummary.textContent = `${km} km · ~${mins} min drive`;
        });
        routingControl.on('routingerror', function () {
            routeSummary.textContent = '';
        });
    }

    function renderSuggestions(container, items, onPick) {
        if (!items.length) {
            container.innerHTML = '<div class="address-suggestion-empty">No matching Nigerian address found.</div>';
            container.classList.add('show');
            return;
        }
        container.innerHTML = items.map((item, idx) => `
            <div class="address-suggestion-item" data-index="${idx}">
                <i class="fa-solid fa-location-dot"></i>
                <div>
                    <div class="main-text">${item.text}</div>
                    <div class="sub-text">${item.place_name}</div>
                </div>
            </div>
        `).join('');
        container.classList.add('show');
        container.querySelectorAll('.address-suggestion-item').forEach((el, idx) => {
            el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                onPick(items[idx]);
                container.classList.remove('show');
            });
        });
    }

    function attachAutocomplete(inputEl, suggestionsEl, targetType) {
        let debounceTimer = null;
        let abortController = null;

        inputEl.addEventListener('input', function () {
            inputEl.classList.remove('location-confirmed');
            if (targetType === 'pickup') { pickupLat.value = ''; pickupLng.value = ''; }
            else { deliveryLat.value = ''; deliveryLng.value = ''; }

            const query = inputEl.value.trim();
            if (debounceTimer) clearTimeout(debounceTimer);
            if (query.length < 3) {
                suggestionsEl.classList.remove('show');
                return;
            }
            debounceTimer = setTimeout(async () => {
                if (abortController) abortController.abort();
                abortController = new AbortController();
                try {
                    const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json?access_token=${MAPBOX_TOKEN}&country=ng&autocomplete=true&limit=6&language=en&bbox=${NIGERIA_BBOX}`;
                    const res = await fetch(url, { signal: abortController.signal });
                    const data = await res.json();
                    const items = (data.features || []).map(f => ({
                        text: f.text,
                        place_name: f.place_name,
                        lat: f.center[1],
                        lng: f.center[0]
                    }));
                    renderSuggestions(suggestionsEl, items, (item) => {
                        inputEl.value = item.place_name;
                        inputEl.classList.add('location-confirmed');
                        updateMarker(targetType, item.lat, item.lng);
                    });
                } catch (e) {
                    if (e.name !== 'AbortError') suggestionsEl.classList.remove('show');
                }
            }, 300);
        });

        inputEl.addEventListener('blur', function () {
            setTimeout(() => suggestionsEl.classList.remove('show'), 150);
        });

        inputEl.addEventListener('keydown', function (e) {
            const items = Array.from(suggestionsEl.querySelectorAll('.address-suggestion-item'));
            if (!items.length || !suggestionsEl.classList.contains('show')) return;
            let activeIdx = items.findIndex(i => i.classList.contains('active'));
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = (activeIdx + 1) % items.length;
                items.forEach(i => i.classList.remove('active'));
                items[activeIdx].classList.add('active');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = activeIdx <= 0 ? items.length - 1 : activeIdx - 1;
                items.forEach(i => i.classList.remove('active'));
                items[activeIdx].classList.add('active');
            } else if (e.key === 'Enter') {
                if (activeIdx >= 0) {
                    e.preventDefault();
                    items[activeIdx].dispatchEvent(new Event('mousedown'));
                }
            } else if (e.key === 'Escape') {
                suggestionsEl.classList.remove('show');
            }
        });
    }

    attachAutocomplete(pickupAddress, pickupSuggestions, 'pickup');
    attachAutocomplete(deliveryAddress, deliverySuggestions, 'delivery');

    document.querySelectorAll('.map-pick-link').forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            setMode(this.dataset.target);
        });
    });

    async function useCurrentLocation(target, btn) {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        btn.disabled = true;

        if (!navigator.geolocation) {
            alert('Geolocation not supported');
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            return;
        }

        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                const { latitude, longitude } = pos.coords;
                revealMap().setView([latitude, longitude], 16);
                updateMarker(target, latitude, longitude);
                await reverseGeocode(latitude, longitude, target === 'pickup' ? pickupAddress : deliveryAddress);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            },
            (err) => {
                alert(`Error (${err.code}): ${err.message}. Ensure HTTPS is enabled.`);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            },
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 0 }
        );
    }

    document.getElementById('use_current_pickup').addEventListener('click', function () { useCurrentLocation('pickup', this); });
    document.getElementById('use_current_delivery').addEventListener('click', function () { useCurrentLocation('delivery', this); });

    if (pickupLat.value && pickupLng.value) updateMarker('pickup', parseFloat(pickupLat.value), parseFloat(pickupLng.value));
    if (deliveryLat.value && deliveryLng.value) updateMarker('delivery', parseFloat(deliveryLat.value), parseFloat(deliveryLng.value));
});
</script>
</body>
</html>
