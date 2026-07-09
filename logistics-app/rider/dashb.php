<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

// 1. Fetch the active booking
$stmt = $pdo->prepare('
    SELECT b.*, s.full_name as sender_name, s.phone as sender_phone 
    FROM bookings b 
    INNER JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ? 
    AND b.booking_status IN ("accepted", "in_transit") 
    LIMIT 1
');
$stmt->execute([$user['id']]);
$activeBooking = $stmt->fetch();

$destLat = $activeBooking ? (float)$activeBooking['delivery_latitude'] : null;
$destLng = $activeBooking ? (float)$activeBooking['delivery_longitude'] : null;
$bookingAmount = $activeBooking ? (float)$activeBooking['total_cost'] : 0;

// Generate External Map Link (Universal)
$mapLink = $activeBooking ? "https://www.google.com/maps/dir/?api=1&destination=" . $destLat . "," . $destLng . "&travelmode=driving" : "#";

// 2. Fetch Pending Offers & History
$stmt = $pdo->prepare('SELECT rr.*, b.booking_code, b.pickup_address, b.delivery_address, b.item_name
                       FROM rider_requests rr
                       INNER JOIN bookings b ON b.id = rr.booking_id
                       WHERE rr.rider_user_id = ?
                       ORDER BY FIELD(rr.request_status, "pending","accepted","rejected"), rr.id DESC');
$stmt->execute([$user['id']]);
$allRequests = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT availability_status FROM rider_profiles WHERE user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();
$isOnline = ($profile['availability_status'] ?? 'offline') === 'available';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Navigation Center | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:#09101d; min-height:100vh; color:#eef4ff; font-family: 'Inter', sans-serif;}
        .cardx{background:rgba(17,27,51,.95); border-radius:1.5rem; border:1px solid rgba(255,255,255,.08); box-shadow:0 10px 40px rgba(0,0,0,0.4);}
        #nav_map { height: 400px; width: 100%; border-radius: 1.25rem; border: 1px solid rgba(255,255,255,0.1); margin-bottom: 1.5rem; }
        
        .stats-bar { background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 1rem; padding: 12px; margin-bottom: 1.5rem; }
        .stat-label { font-size: 0.65rem; color: #9fb0d6; text-transform: uppercase; letter-spacing: 1px; }
        .stat-value { font-size: 1rem; font-weight: 800; color: #fff; }

        .swipe-container { width: 100%; height: 54px; background: #16203a; border-radius: 27px; position: relative; cursor: pointer; border: 2px solid rgba(255,255,255,0.1); transition: 0.4s; }
        .swipe-handle { width: 46px; height: 46px; background: #9fb0d6; border-radius: 50%; position: absolute; top: 2px; left: 3px; transition: 0.4s; display: flex; align-items: center; justify-content: center; color: #09101d; z-index: 2; }
        .swipe-text { position: absolute; width: 100%; text-align: center; line-height: 50px; font-size: 0.85rem; font-weight: 800; letter-spacing: 1px; z-index: 1; pointer-events: none; }
        .swipe-container.active { background: #10b981; border-color: #10b981; }
        .swipe-container.active .swipe-handle { left: calc(100% - 49px); background: #fff; color: #10b981; }

        .req-card { background: rgba(255,255,255,0.03); border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); margin-bottom: 1rem; }
        .price-tag { color: #38bdf8; font-weight: 800; font-size: 1.1rem; }
        
        .pulse-btn { animation: pulse-green 2s infinite; border-radius: 12px; }
        @keyframes pulse-green { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { box-shadow: 0 0 0 15px rgba(16, 185, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
        
        .nav-tabs { border: none; gap: 8px; }
        .nav-link { color: #9fb0d6; border: none !important; border-radius: 10px !important; font-weight: 600; padding: 10px 20px; }
        .nav-link.active { background: #38bdf8 !important; color: #09101d !important; }
    </style>
</head>
<body>

<div class="container py-4">
    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="cardx p-3 p-md-4">
                
                <?php if ($activeBooking): ?>
                    <div class="stats-bar d-flex justify-content-between align-items-center">
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25">
                            <div class="stat-label">Earnings</div>
                            <div class="stat-value text-info">₦<?= number_format($bookingAmount) ?></div>
                        </div>
                        <div class="text-center flex-fill border-end border-secondary border-opacity-25">
                            <div class="stat-label">Distance</div>
                            <div class="stat-value" id="distance_display">-- km</div>
                        </div>
                        <div class="text-center flex-fill">
                            <div class="stat-label">System</div>
                            <div id="sync_status" class="stat-value small text-success">LIVE</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h1 class="h5 fw-bold mb-0">Rider Radar</h1>
                        <span id="sync_status" class="badge bg-dark border border-secondary text-info">OFFLINE</span>
                    </div>
                <?php endif; ?>

                <div id="nav_map"></div>

                <div class="mb-4">
                    <div id="swipe-btn" class="swipe-container <?= $isOnline ? 'active' : '' ?>" onclick="toggleStatus()">
                        <div class="swipe-handle"><i class="fa-solid fa-motorcycle"></i></div>
                        <span class="swipe-text"><?= $isOnline ? 'TRACKING ONLINE' : 'SWIPE TO START WORKING' ?></span>
                    </div>
                </div>

                <?php if ($activeBooking): ?>
                    <div class="req-card p-3 border-info shadow-sm">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="badge bg-info text-dark">ON DELIVERY MISSION</span>
                            <div class="d-flex gap-2">
                                <a href="tel:<?= e($activeBooking['sender_phone']) ?>" class="btn btn-sm btn-dark border-secondary rounded-pill px-3">
                                    <i class="fa-solid fa-phone"></i>
                                </a>
                                <a href="<?= $mapLink ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">
                                    <i class="fa-solid fa-diamond-turn-right me-1"></i> NAVIGATE
                                </a>
                            </div>
                        </div>
                        <p class="fw-bold mb-1 small text-truncate"><i class="fa-solid fa-location-dot me-2 text-danger"></i><?= e($activeBooking['delivery_address']) ?></p>
                        <p class="small text-soft mb-3">Sender: <?= e($activeBooking['sender_name']) ?></p>
                        
                        <form method="post" action="rider/mark_arrived.php">
                            <input type="hidden" name="booking_id" value="<?= $activeBooking['id'] ?>">
                            <button type="submit" id="btn_arrived" class="btn btn-secondary w-100 py-3 fw-bold pulse-btn" disabled>
                                <i class="fa-solid fa-circle-check me-2"></i>NOT AT DESTINATION
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="cardx p-4 h-100">
                <ul class="nav nav-tabs mb-4" id="orderTabs">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#offers">New Offers</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#history">History</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="offers">
                        <?php 
                        $pendings = array_filter($allRequests, fn($r) => $r['request_status'] === 'pending');
                        if (empty($pendings)): ?>
                            <div class="text-center py-5 text-soft">
                                <i class="fa-solid fa-satellite-dish fa-3x mb-3 opacity-25"></i>
                                <p>Scanning for nearby orders...</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pendings as $req): ?>
                                <div class="req-card p-3 border-warning">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="price-tag">₦<?= number_format($req['proposed_cost']) ?></span>
                                        <span class="small text-soft">#<?= e($req['booking_code']) ?></span>
                                    </div>
                                    <p class="small mb-3"><i class="fa-solid fa-map-pin me-2 text-warning"></i><?= e($req['pickup_address']) ?></p>
                                    <form class="d-flex gap-2" method="post" action="rider/respond_request.php">
                                        <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                        <button class="btn btn-success flex-grow-1 fw-bold" type="submit" name="action" value="accepted">ACCEPT OFFER</button>
                                        <button class="btn btn-outline-danger" type="submit" name="action" value="rejected"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="tab-pane fade" id="history">
                        <?php foreach ($allRequests as $req): if ($req['request_status'] !== 'pending'): ?>
                            <div class="d-flex justify-content-between align-items-center p-2 mb-2 border-bottom border-secondary small">
                                <div><span class="d-block fw-bold"><?= e($req['booking_code']) ?></span><span class="text-soft">₦<?= number_format($req['proposed_cost']) ?></span></div>
                                <span class="badge bg-<?= $req['request_status'] === 'accepted' ? 'success' : 'secondary' ?> opacity-75"><?= e($req['request_status']) ?></span>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const swipeBtn = document.getElementById('swipe-btn');
    const btnArrived = document.getElementById('btn_arrived');
    const distDisplay = document.getElementById('distance_display');
    const syncStatus = document.getElementById('sync_status');

    let map, riderMarker, watchId = null;
    const dest = { lat: <?= $destLat ?? 'null' ?>, lng: <?= $destLng ?? 'null' ?> };

    function initMap() {
        map = L.map('nav_map', { zoomControl: false }).setView([0, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        if (dest.lat) {
            L.marker([dest.lat, dest.lng], {
                icon: L.divIcon({html: '<i class="fa-solid fa-flag-checkered text-danger fs-3"></i>', className: '', iconSize: [30, 30]})
            }).addTo(map);
        }
    }

    async function toggleStatus() {
        const isActivating = !swipeBtn.classList.contains('active');
        if (isActivating) {
            navigator.geolocation.getCurrentPosition(() => {
                swipeBtn.classList.add('active');
                swipeBtn.querySelector('.swipe-text').innerText = "TRACKING ONLINE";
                startTracking();
                updateServerStatus('available');
            }, () => alert("GPS required."));
        } else {
            swipeBtn.classList.remove('active');
            swipeBtn.querySelector('.swipe-text').innerText = "SWIPE TO GO ONLINE";
            stopTracking();
            updateServerStatus('offline');
        }
    }

    function startTracking() {
        if (watchId) navigator.geolocation.clearWatch(watchId);
        if(syncStatus) syncStatus.innerText = "LIVE";

        watchId = navigator.geolocation.watchPosition(async (pos) => {
            const { latitude, longitude } = pos.coords;
            if (!riderMarker) {
                riderMarker = L.marker([latitude, longitude]).addTo(map);
                map.setView([latitude, longitude], 16);
            } else { riderMarker.setLatLng([latitude, longitude]); }

            if (dest.lat) {
                const dist = calculateDistance(latitude, longitude, dest.lat, dest.lng);
                if(distDisplay) distDisplay.textContent = dist > 1000 ? (dist/1000).toFixed(1) + 'km' : Math.round(dist) + 'm';
                
                if (dist <= 200 && btnArrived) {
                    btnArrived.disabled = false;
                    btnArrived.classList.replace('btn-secondary', 'btn-success');
                    btnArrived.innerHTML = '<i class="fa-solid fa-check-circle me-2"></i>COMPLETE DELIVERY';
                }
            }

            try {
                await fetch('rider/ajax_update_location.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ latitude, longitude, status: 'available' })
                });
            } catch (e) {}
        }, null, { enableHighAccuracy: true });
    }

    function stopTracking() {
        if (watchId) navigator.geolocation.clearWatch(watchId);
        if(syncStatus) syncStatus.innerText = "OFFLINE";
    }

    async function updateServerStatus(status) {
        await fetch('rider/ajax_update_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status })
        });
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371e3;
        const φ1 = lat1 * Math.PI/180; const φ2 = lat2 * Math.PI/180;
        const Δφ = (lat2-lat1) * Math.PI/180; const Δλ = (lon2-lon1) * Math.PI/180;
        const a = Math.sin(Δφ/2)**2 + Math.cos(φ1) * Math.cos(φ2) * Math.sin(Δλ/2)**2;
        return R * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)));
    }

    initMap();
    if (swipeBtn.classList.contains('active')) startTracking();
</script>
</body>
</html>