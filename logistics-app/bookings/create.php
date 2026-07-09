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
            <div class="row g-3">
              <div class="col-lg-6">
                <label class="form-label">Pickup address</label>
                <input class="form-control" id="pickup_address" name="pickup_address" value="<?= e(old('pickup_address')) ?>" placeholder="Auto-filled from GPS or map, editable">
                <?php if (!empty($errors['pickup_address'])): ?><div class="small text-danger mt-1"><?= e($errors['pickup_address']) ?></div><?php endif; ?>
              </div>
              <div class="col-lg-6 d-flex align-items-end gap-2 flex-wrap">
                <button class="btn btn-outline-light" type="button" id="use_current_pickup">Use Current Location</button>
                <button class="btn btn-outline-info" type="button" id="select_pickup_mode">Pick From Map</button>
              </div>
              <div class="col-md-6">
                <label class="form-label">Pickup latitude</label>
                <input class="form-control" id="pickup_latitude" name="pickup_latitude" value="<?= e(old('pickup_latitude')) ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label">Pickup longitude</label>
                <input class="form-control" id="pickup_longitude" name="pickup_longitude" value="<?= e(old('pickup_longitude')) ?>" readonly>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="cardx p-3">
            <h2 class="h5">Delivery location</h2>
            <div class="row g-3">
              <div class="col-lg-6">
                <label class="form-label">Delivery address</label>
                <input class="form-control" id="delivery_address" name="delivery_address" value="<?= e(old('delivery_address')) ?>" placeholder="Auto-filled from GPS or map, editable">
                <?php if (!empty($errors['delivery_address'])): ?><div class="small text-danger mt-1"><?= e($errors['delivery_address']) ?></div><?php endif; ?>
              </div>
              <div class="col-lg-6 d-flex align-items-end gap-2 flex-wrap">
                <button class="btn btn-outline-light" type="button" id="use_current_delivery">Use Current Location</button>
                <button class="btn btn-outline-info" type="button" id="select_delivery_mode">Pick From Map</button>
              </div>
              <div class="col-md-6">
                <label class="form-label">Delivery latitude</label>
                <input class="form-control" id="delivery_latitude" name="delivery_latitude" value="<?= e(old('delivery_latitude')) ?>" readonly>
              </div>
              <div class="col-md-6">
                <label class="form-label">Delivery longitude</label>
                <input class="form-control" id="delivery_longitude" name="delivery_longitude" value="<?= e(old('delivery_longitude')) ?>" readonly>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="cardx p-3">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
              <h2 class="h5 mb-0">Map selection</h2>
              <span class="badge text-bg-primary" id="map_mode_label">Mode: none</span>
            </div>
            <div id="booking_map" class="map-wrap"></div>
            <div class="small text-soft mt-2">If the map cannot load, confirm internet access to Leaflet CDN and OpenStreetMap tiles.</div>
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
<script>

document.addEventListener('DOMContentLoaded', function () {
    const pickupAddress = document.getElementById('pickup_address');
    const pickupLat = document.getElementById('pickup_latitude');
    const pickupLng = document.getElementById('pickup_longitude');
    const deliveryAddress = document.getElementById('delivery_address');
    const deliveryLat = document.getElementById('delivery_latitude');
    const deliveryLng = document.getElementById('delivery_longitude');
    const modeLabel = document.getElementById('map_mode_label');

    let mapMode = null;
    let pickupMarker = null;
    let deliveryMarker = null;

    // Force Marker Icons to load from CDN
    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    });

    const defaultLat = parseFloat(pickupLat.value) || 6.5244;
    const defaultLng = parseFloat(pickupLng.value) || 3.3792;

    const map = L.map('booking_map', { tap: false }).setView([defaultLat, defaultLng], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(map);

    // Critical fix for visible rendering
    setTimeout(() => { 
        map.invalidateSize(); 
    }, 600);

     function setMode(mode, btn) {
    mapMode = mode;
    modeLabel.textContent = 'Mode: Selecting ' + mode.toUpperCase();
    modeLabel.className = mode === 'pickup' ? 'badge text-bg-warning' : 'badge text-bg-info';
    
    // Smooth scroll to map so user knows to click
    document.getElementById('booking_map').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function updateMarker(type, lat, lng) {
    if (type === 'pickup') {
      if (pickupMarker) map.removeLayer(pickupMarker);
      pickupMarker = L.marker([lat, lng], { draggable: true }).addTo(map).bindPopup('Pickup').openPopup();
      pickupLat.value = lat.toFixed(7);
      pickupLng.value = lng.toFixed(7);
      pickupMarker.on('dragend', (e) => handleManualMove('pickup', e.target.getLatLng()));
    } else {
      if (deliveryMarker) map.removeLayer(deliveryMarker);
      deliveryMarker = L.marker([lat, lng], { draggable: true }).addTo(map).bindPopup('Delivery').openPopup();
      deliveryLat.value = lat.toFixed(7);
      deliveryLng.value = lng.toFixed(7);
      deliveryMarker.on('dragend', (e) => handleManualMove('delivery', e.target.getLatLng()));
    }
  }

  async function handleManualMove(type, latlng) {
      updateMarker(type, latlng.lat, latlng.lng);
      await reverseGeocode(latlng.lat, latlng.lng, type === 'pickup' ? pickupAddress : deliveryAddress);
  }

  async function reverseGeocode(lat, lng, targetInput) {
    targetInput.value = "Locating address...";
    try {
      const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
          headers: { 'Accept-Language': 'en' }
      });
      const data = await res.json();
      targetInput.value = data.display_name || "Unknown Location";
    } catch (e) {
      targetInput.value = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
      console.error("Geocoding failed", e);
    }
  }

  // Click on Map Logic
  map.on('click', async function(e) {
    if (!mapMode) {
        alert("Please click 'Pick From Map' for Pickup or Delivery first.");
        return;
    }
    const { lat, lng } = e.latlng;
    updateMarker(mapMode, lat, lng);
    await reverseGeocode(lat, lng, mapMode === 'pickup' ? pickupAddress : deliveryAddress);
  });

  // GPS Geolocation Logic
  async function useCurrentLocation(target, btn) {
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Locating...';
    btn.disabled = true;

    if (!navigator.geolocation) {
      alert('Geolocation not supported');
      btn.innerHTML = originalText;
      btn.disabled = false;
      return;
    }

    navigator.geolocation.getCurrentPosition(
      async (pos) => {
        const { latitude, longitude } = pos.coords;
        map.setView([latitude, longitude], 16);
        updateMarker(target, latitude, longitude);
        await reverseGeocode(latitude, longitude, target === 'pickup' ? pickupAddress : deliveryAddress);
        btn.innerHTML = originalText;
        btn.disabled = false;
      },
      (err) => {
        alert(`Error (${err.code}): ${err.message}. Ensure HTTPS is enabled.`);
        btn.innerHTML = originalText;
        btn.disabled = false;
      },
      { enableHighAccuracy: false, timeout: 15000,  maximumAge: 0    }
    );
  }
  
    
    // Ensure initial markers show up if values exist
    if (pickupLat.value && pickupLng.value) {
        updateMarker('pickup', parseFloat(pickupLat.value), parseFloat(pickupLng.value));
    }
    if (deliveryLat.value && deliveryLng.value) {
        updateMarker('delivery', parseFloat(deliveryLat.value), parseFloat(deliveryLng.value));
    }




  

  // Event Listeners
  document.getElementById('select_pickup_mode').addEventListener('click', function() { setMode('pickup', this); });
  document.getElementById('select_delivery_mode').addEventListener('click', function() { setMode('delivery', this); });
  
  document.getElementById('use_current_pickup').addEventListener('click', function() { useCurrentLocation('pickup', this); });
  document.getElementById('use_current_delivery').addEventListener('click', function() { useCurrentLocation('delivery', this); });

  // Initial markers if editing/redirecting back
  if (pickupLat.value && pickupLng.value) updateMarker('pickup', parseFloat(pickupLat.value), parseFloat(pickupLng.value));
  if (deliveryLat.value && deliveryLng.value) updateMarker('delivery', parseFloat(deliveryLat.value), parseFloat(deliveryLng.value));
})();
</script>
</body>
</html>
