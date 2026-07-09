<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$errors = [];
$success = flash('success');
$error = flash('error');

$selectedBookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$showNewOrderForm = isset($_GET['new']) && $_GET['new'] === '1';

// ---------------- CREATE BOOKING ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = validate_required([
        'recipient_name'   => 'Recipient name',
        'recipient_phone'  => 'Recipient phone',
        'pickup_address'   => 'Pickup address',
        'delivery_address' => 'Delivery address',
        'item_name'        => 'Item name',
        'item_category'    => 'Item category',
    ], $_POST);

    $saveAsDraft = isset($_POST['save_draft']);
    if (!$saveAsDraft && empty($_FILES['item_image']['name'])) {
        $errors['item_image'] = 'Item image is required when submitting a booking.';
    }

    $trackingToken = bin2hex(random_bytes(16));

    $payload = [
        'sender_user_id'        => $user['id'],
        'booking_code'          => 'BK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
        'recipient_name'        => trim($_POST['recipient_name'] ?? ''),
        'recipient_phone'       => trim($_POST['recipient_phone'] ?? ''),
        'pickup_address'        => trim($_POST['pickup_address'] ?? ''),
        'pickup_latitude'       => ($_POST['pickup_latitude'] ?? '') !== '' ? (float)$_POST['pickup_latitude'] : null,
        'pickup_longitude'      => ($_POST['pickup_longitude'] ?? '') !== '' ? (float)$_POST['pickup_longitude'] : null,
        'delivery_address'      => trim($_POST['delivery_address'] ?? ''),
        'delivery_latitude'     => ($_POST['delivery_latitude'] ?? '') !== '' ? (float)$_POST['delivery_latitude'] : null,
        'delivery_longitude'    => ($_POST['delivery_longitude'] ?? '') !== '' ? (float)$_POST['delivery_longitude'] : null,
        'item_name'             => trim($_POST['item_name'] ?? ''),
        'item_category'         => trim($_POST['item_category'] ?? ''),
        'item_description'      => trim($_POST['item_description'] ?? ''),
        'estimated_value'       => ($_POST['estimated_value'] ?? '') !== '' ? (float)$_POST['estimated_value'] : null,
        'special_instructions'  => trim($_POST['special_instructions'] ?? ''),
        'booking_status'        => $saveAsDraft ? 'draft' : 'submitted',
        'sender_tracking_token' => $trackingToken,
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
                    estimated_value, special_instructions, booking_status, sender_tracking_token
                ) VALUES (
                    :sender_user_id, :booking_code, :recipient_name, :recipient_phone,
                    :pickup_address, :pickup_latitude, :pickup_longitude,
                    :delivery_address, :delivery_latitude, :delivery_longitude,
                    :item_name, :item_category, :item_description, :item_image_path,
                    :estimated_value, :special_instructions, :booking_status, :sender_tracking_token
                )
            ');
            $stmt->execute($payload);

            $selectedBookingId = (int)$pdo->lastInsertId();

            if ($saveAsDraft) {
                flash('success', 'Booking saved as draft.');
                redirect_to('bookings/index.php');
            }

            flash('success', 'Booking submitted successfully. Choose a rider below.');
            redirect_to('bookings/index.php?booking_id=' . $selectedBookingId);
        } catch (Throwable $e) {
            $errors['general'] = $e->getMessage();
            $showNewOrderForm = true;
        }
    } else {
        $showNewOrderForm = true;
    }
}

// ---------------- LOAD BOOKINGS ----------------
$stmt = $pdo->prepare("
    SELECT 
        b.*,
        r.full_name AS rider_name,
        r.phone AS rider_phone,
        rp.last_latitude,
        rp.last_longitude,
        rp.availability_status
    FROM bookings b
    LEFT JOIN users r ON r.id = b.selected_rider_user_id
    LEFT JOIN rider_profiles rp ON rp.user_id = b.selected_rider_user_id
    WHERE b.sender_user_id = ?
    ORDER BY b.id DESC
");
$stmt->execute([$user['id']]);
$allBookings = $stmt->fetchAll();

$activeBookings = [];
$unpaidBookings = [];
$historyBookings = [];

foreach ($allBookings as $b) {
    $paymentStatus = $b['payment_status'] ?? 'unpaid';
    $bookingStatus = $b['booking_status'] ?? '';

    if ($bookingStatus === 'draft') {
        continue;
    }

    if ($paymentStatus === 'paid') {
        $historyBookings[] = $b;
    } elseif (in_array($bookingStatus, ['delivered', 'cancelled'], true)) {
        $unpaidBookings[] = $b;
    } else {
        $activeBookings[] = $b;
    }
}
$selectedBooking = null;
if ($selectedBookingId > 0) {
    foreach ($allBookings as $b) {
        if ((int)$b['id'] === $selectedBookingId) {
            $selectedBooking = $b;
            break;
        }
    }
}
if (!$selectedBooking && !empty($activeBookings)) {
    $selectedBooking = $activeBookings[0];
    $selectedBookingId = (int)$selectedBooking['id'];
} elseif (!$selectedBooking && !empty($unpaidBookings)) {
    $selectedBooking = $unpaidBookings[0];
    $selectedBookingId = (int)$selectedBooking['id'];
} elseif (!$selectedBooking && !empty($historyBookings)) {
    $selectedBooking = $historyBookings[0];
    $selectedBookingId = (int)$selectedBooking['id'];
}


function haversine_distance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

$selectedDistanceKm = null;
if ($selectedBooking && $selectedBooking['pickup_latitude'] !== null && $selectedBooking['delivery_latitude'] !== null) {
    $selectedDistanceKm = haversine_distance(
        (float)$selectedBooking['pickup_latitude'],
        (float)$selectedBooking['pickup_longitude'],
        (float)$selectedBooking['delivery_latitude'],
        (float)$selectedBooking['delivery_longitude']
    );
}

$canTrack = $selectedBooking && !empty($selectedBooking['selected_rider_user_id']);
$needsRider = $selectedBooking && empty($selectedBooking['selected_rider_user_id']) && in_array($selectedBooking['booking_status'], ['submitted', 'matched'], true);
$canPay = $selectedBooking && $selectedBooking['booking_status'] === 'delivered' && ($selectedBooking['payment_status'] ?? 'unpaid') !== 'paid';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sender Hub | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
        .navx{background:rgba(8,17,33,.88);border-bottom:1px solid rgba(255,255,255,.08)}
        .cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#9fb0d6}
        .form-control,.form-select{background:#0b1430;color:#eef4ff;border-color:rgba(255,255,255,.1)}
        .form-control:focus,.form-select:focus{background:#0b1430;color:#eef4ff;border-color:#6ea8fe;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
        .leaflet-container{height:100%;width:100%}
        .map-wrap{height:380px;border-radius:1rem;overflow:hidden;border:1px solid rgba(255,255,255,.08)}
        #booking_map{height:380px !important;width:100% !important}
        #detail_map{height:480px;border-radius:1.25rem;border:2px solid rgba(110,168,254,.2)}
        .rider-card{background:#0b1430;border:1px solid rgba(255,255,255,.08);border-radius:1rem}
        .order-card{cursor:pointer;transition:.2s ease}
        .order-card:hover{transform:translateY(-2px);border-color:rgba(110,168,254,.4)}
        .order-card.active{border-color:#38bdf8;box-shadow:0 0 0 1px rgba(56,189,248,.35)}
        .badge-soft{background:rgba(56,189,248,.12);color:#9ddcff;border:1px solid rgba(56,189,248,.3)}
        #routing-directions{background:rgba(11,20,48,.95);border:1px solid rgba(56,189,248,.3);border-radius:1rem;color:#fff;display:none}
        .routing-instructions-list{background:transparent!important;color:#fff!important;border:none!important}
        .leaflet-routing-alt{background:transparent!important;color:#fff!important;max-height:260px!important;overflow-y:auto}
        .leaflet-routing-alt table{color:#fff!important;width:100%}
        .leaflet-routing-alt tr:hover{background:rgba(255,255,255,.05)}
        .leaflet-routing-container{width:100%!important;background:transparent!important;border:none!important;box-shadow:none!important}
        .info-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);font-size:.9rem}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('index.php')) ?>">SwiftDrop</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="<?= e(url_path('dashboard.php')) ?>">Dashboard</a>
            <a class="nav-link" href="<?= e(url_path('logout.php')) ?>">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if (!empty($errors['general'])): ?><div class="alert alert-danger"><?= e($errors['general']) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Sender Workspace</h1>
            <p class="text-soft mb-0">Manage active deliveries, view history, and place a new order only when needed.</p>
        </div>
        <button class="btn btn-primary" id="toggle-new-order">
            <i class="fa-solid fa-plus me-2"></i>New Order
        </button>
    </div>

    <div class="cardx p-4 p-lg-5 mb-5" id="new-order-panel" style="<?= $showNewOrderForm ? '' : 'display:none;' ?>">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
            <div>
                <h2 class="h4 fw-bold mb-1">Create Booking</h2>
                <p class="text-soft mb-0">Fill in parcel details, set pickup and delivery, then submit to start rider matching.</p>
            </div>
            <button class="btn btn-outline-light" type="button" id="close-new-order">
                <i class="fa-solid fa-xmark me-1"></i>Close
            </button>
        </div>

        <form method="post" enctype="multipart/form-data" id="booking-form">
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
                        <h3 class="h5">Pickup location</h3>
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <label class="form-label">Pickup address</label>
                                <input class="form-control" id="pickup_address" name="pickup_address" value="<?= e(old('pickup_address')) ?>">
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
                        <h3 class="h5">Delivery location</h3>
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <label class="form-label">Delivery address</label>
                                <input class="form-control" id="delivery_address" name="delivery_address" value="<?= e(old('delivery_address')) ?>">
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
                            <h3 class="h5 mb-0">Map selection</h3>
                            <span class="badge text-bg-primary" id="map_mode_label">Mode: none</span>
                        </div>
                        <div id="booking_map" class="map-wrap"></div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Item name</label>
                    <input class="form-control" name="item_name" value="<?= e(old('item_name')) ?>">
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
                </div>
                <div class="col-12">
                    <label class="form-label">Item description</label>
                    <textarea class="form-control" name="item_description" rows="4"><?= e(old('item_description')) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Estimated value</label>
                    <input class="form-control" name="estimated_value" value="<?= e(old('estimated_value')) ?>">
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
                <button class="btn btn-primary" type="submit" name="submit_booking">Submit Booking & Find Riders</button>
                <button class="btn btn-outline-light" type="submit" name="save_draft">Save as Draft</button>
            </div>
        </form>
    </div>

<ul class="nav nav-tabs mb-4" id="hubTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#active-orders" type="button">
            Active Orders
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#unpaid-orders" type="button">
            Unpaid
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#history-orders" type="button">
            History
        </button>
    </li>
</ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="active-orders">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="cardx p-4">
                        <h2 class="h5 mb-3">Active Orders</h2>
                        <?php if (empty($activeBookings)): ?>
                            <div class="text-soft">No active orders yet.</div>
                        <?php else: ?>
                            <div class="d-grid gap-3">
                                <?php foreach ($activeBookings as $b): ?>
                                    <a href="<?= e(url_path('bookings/index.php?booking_id=' . (int)$b['id'])) ?>" class="text-decoration-none">
                                        <div class="order-card cardx p-3 <?= ((int)$b['id'] === (int)$selectedBookingId) ? 'active' : '' ?>">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <div class="fw-bold"><?= e($b['booking_code']) ?></div>
                                                    <div class="small text-soft"><?= e($b['item_name']) ?></div>
                                                </div>
                                                <span class="badge badge-soft"><?= e($b['booking_status']) ?></span>
                                            </div>
                                            <div class="small text-soft mt-2"><?= e($b['pickup_address']) ?></div>
                                            <div class="small text-soft">to <?= e($b['delivery_address']) ?></div>
                                            <div class="small mt-2 text-info">
                                                <?= !empty($b['rider_name']) ? 'Rider: ' . e($b['rider_name']) : 'Awaiting rider selection' ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-8">
                    <?php if ($selectedBooking): ?>
                        <div class="cardx p-4 mb-4">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                <div>
                                    <h2 class="h4 fw-bold mb-1"><?= e($selectedBooking['booking_code']) ?></h2>
                                    <p class="text-soft mb-0"><?= e($selectedBooking['item_name']) ?> · <?= e($selectedBooking['item_category']) ?></p>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="info-pill"><i class="fa-solid fa-circle-info text-info"></i> <span id="booking_status_text"><?= e($selectedBooking['booking_status']) ?></span></span>
                                    <span class="info-pill"><i class="fa-solid fa-naira-sign text-warning"></i> ₦<?= number_format((float)($selectedBooking['agreed_cost'] ?? 0), 2) ?></span>
                                    <span class="info-pill"><i class="fa-solid fa-wallet text-success"></i> <span id="payment_status_text"><?= e($selectedBooking['payment_status'] ?? 'unpaid') ?></span></span>
                                    <span class="info-pill"><i class="fa-regular fa-clock text-info"></i> <span id="eta_text">--</span></span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-4 mb-4">
                            <div class="col-lg-8">
                                <div class="cardx p-4">
                                    <div id="detail_map"></div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="cardx p-4 h-100">
                                    <h3 class="h5 mb-3">Booking Info</h3>
                                    <div class="small text-soft mb-2"><strong class="text-white">Recipient:</strong> <?= e($selectedBooking['recipient_name']) ?> · <?= e($selectedBooking['recipient_phone']) ?></div>
                                    <div class="small text-soft mb-2"><strong class="text-white">Pickup:</strong> <?= e($selectedBooking['pickup_address']) ?></div>
                                    <div class="small text-soft mb-2"><strong class="text-white">Delivery:</strong> <?= e($selectedBooking['delivery_address']) ?></div>
                                    <div class="small text-soft mb-2"><strong class="text-white">Distance:</strong> <?= $selectedDistanceKm !== null ? number_format($selectedDistanceKm, 2) . ' km' : '--' ?></div>
                                    <div class="small text-soft mb-2"><strong class="text-white">Rider:</strong> <?= e((string)($selectedBooking['rider_name'] ?? 'Not assigned yet')) ?></div>
                                    <div class="small text-soft mb-2"><strong class="text-white">Rider Phone:</strong> <?= e((string)($selectedBooking['rider_phone'] ?? '--')) ?></div>
                                    <?php if (!empty($selectedBooking['delivery_proof_image'])): ?>
                                        <div class="mt-3">
                                            <div class="small text-white fw-bold mb-2">Proof of Delivery</div>
                                            <img src="<?= e(url_path($selectedBooking['delivery_proof_image'])) ?>" class="img-fluid rounded" alt="Proof">
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($canPay): ?>
                                    <!--    <div class="mt-4">
                                            <button class="btn btn-success w-100" id="pay-now-btn">
                                                Pay ₦<?= number_format((float)$selectedBooking['agreed_cost'], 2) ?>
                                            </button>
                                        </div> -->
                                        <div class="mt-4">
                                            <form method="post" action="<?= e(url_path('payments/start.php')) ?>">
                                                <input type="hidden" name="booking_id" value="<?= (int)$selectedBooking['id'] ?>">
                                                <button class="btn btn-success w-100" type="submit">
                                                    Pay ₦<?= number_format((float)$selectedBooking['agreed_cost'], 2) ?>
                                                </button>
                                            </form>
                                        </div>
                                        
                                        
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div id="routing-directions" class="p-3 mb-4"></div>

                        <?php if ($needsRider): ?>
                            <div class="cardx p-4 mb-4">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <h3 class="h5 mb-1">Nearby Riders</h3>
                                        <p class="text-soft mb-0">Select a rider and send a request for this booking.</p>
                                    </div>
                                    <button id="clear-route-btn" class="btn btn-sm btn-outline-warning" style="display:none;" onclick="clearActiveRoute()">
                                        <i class="fa-solid fa-xmark me-1"></i> Clear Route
                                    </button>
                                </div>
                            </div>

                            <div class="row g-4" id="rider-list-container">
                                <div class="col-12 text-center py-5">
                                    <div class="spinner-border text-info" role="status"></div>
                                    <p class="mt-2 text-soft">Scanning for nearby riders...</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="cardx p-5 text-center text-soft">Select an active order to view details.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

<div class="tab-pane fade" id="unpaid-orders">
    <div class="cardx p-4">
        <h2 class="h5 mb-3">Unpaid Orders</h2>
        <?php if (empty($unpaidBookings)): ?>
            <div class="text-soft">No unpaid orders.</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($unpaidBookings as $b): ?>
                    <div class="col-lg-6">
                        <a href="<?= e(url_path('bookings/index.php?booking_id=' . (int)$b['id'])) ?>" class="text-decoration-none">
                            <div class="cardx p-3 order-card <?= ((int)$b['id'] === (int)$selectedBookingId) ? 'active' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?= e($b['booking_code']) ?></div>
                                        <div class="small text-soft"><?= e($b['item_name']) ?></div>
                                    </div>
                                    <span class="badge bg-warning text-dark"><?= e($b['payment_status'] ?? 'unpaid') ?></span>
                                </div>
                                <div class="small text-soft mt-2"><?= e($b['pickup_address']) ?></div>
                                <div class="small text-soft">to <?= e($b['delivery_address']) ?></div>
                                <div class="small mt-2 text-info">
                                    Status: <?= e($b['booking_status']) ?>
                                </div>
                                <div class="small mt-1 text-warning">
                                    Amount: ₦<?= number_format((float)($b['agreed_cost'] ?? 0), 2) ?>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

        <div class="tab-pane fade" id="history-orders">
            <div class="cardx p-4">
                <h2 class="h5 mb-3">Completed / Historical Orders</h2>
                <?php if (empty($historyBookings)): ?>
                    <div class="text-soft">No completed bookings yet.</div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($historyBookings as $b): ?>
                            <div class="col-lg-6">
                                <div class="cardx p-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-bold"><?= e($b['booking_code']) ?></div>
                                            <div class="small text-soft"><?= e($b['item_name']) ?></div>
                                        </div>
                                        <span class="badge badge-soft"><?= e($b['booking_status']) ?></span>
                                    </div>
                                    <div class="small text-soft mt-2"><?= e($b['pickup_address']) ?></div>
                                    <div class="small text-soft">to <?= e($b['delivery_address']) ?></div>
                                    <div class="small mt-2 text-info">Payment: <?= e($b['payment_status'] ?? 'unpaid') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>

<script src="https://js.paystack.co/v2/inline.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const newOrderPanel = document.getElementById('new-order-panel');
    const toggleNewOrderBtn = document.getElementById('toggle-new-order');
    const closeNewOrderBtn = document.getElementById('close-new-order');

    toggleNewOrderBtn?.addEventListener('click', function () {
        newOrderPanel.style.display = '';
        newOrderPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    closeNewOrderBtn?.addEventListener('click', function () {
        newOrderPanel.style.display = 'none';
    });

    // ---------------- BOOKING FORM MAP ----------------
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

    delete L.Icon.Default.prototype._getIconUrl;
    L.Icon.Default.mergeOptions({
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    });

    const bookingMap = L.map('booking_map', { tap: false }).setView([
        parseFloat(pickupLat.value) || 6.5244,
        parseFloat(pickupLng.value) || 3.3792
    ], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(bookingMap);

    setTimeout(() => bookingMap.invalidateSize(), 500);

    function setMode(mode) {
        mapMode = mode;
        modeLabel.textContent = 'Mode: Selecting ' + mode.toUpperCase();
        modeLabel.className = mode === 'pickup' ? 'badge text-bg-warning' : 'badge text-bg-info';
        document.getElementById('booking_map').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function updateFormMarker(type, lat, lng) {
        if (type === 'pickup') {
            if (pickupMarker) bookingMap.removeLayer(pickupMarker);
            pickupMarker = L.marker([lat, lng], { draggable: true }).addTo(bookingMap).bindPopup('Pickup').openPopup();
            pickupLat.value = lat.toFixed(7);
            pickupLng.value = lng.toFixed(7);
            pickupMarker.on('dragend', async (e) => {
                const p = e.target.getLatLng();
                updateFormMarker('pickup', p.lat, p.lng);
                await reverseGeocode(p.lat, p.lng, pickupAddress);
            });
        } else {
            if (deliveryMarker) bookingMap.removeLayer(deliveryMarker);
            deliveryMarker = L.marker([lat, lng], { draggable: true }).addTo(bookingMap).bindPopup('Delivery').openPopup();
            deliveryLat.value = lat.toFixed(7);
            deliveryLng.value = lng.toFixed(7);
            deliveryMarker.on('dragend', async (e) => {
                const p = e.target.getLatLng();
                updateFormMarker('delivery', p.lat, p.lng);
                await reverseGeocode(p.lat, p.lng, deliveryAddress);
            });
        }
    }

    async function reverseGeocode(lat, lng, targetInput) {
        targetInput.value = "Locating address...";
        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
                headers: { 'Accept-Language': 'en' }
            });
            const data = await res.json();
            targetInput.value = data.display_name || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        } catch (e) {
            targetInput.value = `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
        }
    }

    bookingMap.on('click', async function(e) {
        if (!mapMode) {
            alert("Please choose Pickup or Delivery map mode first.");
            return;
        }
        const { lat, lng } = e.latlng;
        updateFormMarker(mapMode, lat, lng);
        await reverseGeocode(lat, lng, mapMode === 'pickup' ? pickupAddress : deliveryAddress);
    });

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
                bookingMap.setView([latitude, longitude], 16);
                updateFormMarker(target, latitude, longitude);
                await reverseGeocode(latitude, longitude, target === 'pickup' ? pickupAddress : deliveryAddress);
                btn.innerHTML = originalText;
                btn.disabled = false;
            },
            (err) => {
                alert(`Error (${err.code}): ${err.message}. Ensure HTTPS is enabled.`);
                btn.innerHTML = originalText;
                btn.disabled = false;
            },
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 0 }
        );
    }

    document.getElementById('select_pickup_mode')?.addEventListener('click', () => setMode('pickup'));
    document.getElementById('select_delivery_mode')?.addEventListener('click', () => setMode('delivery'));
    document.getElementById('use_current_pickup')?.addEventListener('click', function() { useCurrentLocation('pickup', this); });
    document.getElementById('use_current_delivery')?.addEventListener('click', function() { useCurrentLocation('delivery', this); });

    if (pickupLat?.value && pickupLng?.value) updateFormMarker('pickup', parseFloat(pickupLat.value), parseFloat(pickupLng.value));
    if (deliveryLat?.value && deliveryLng?.value) updateFormMarker('delivery', parseFloat(deliveryLat.value), parseFloat(deliveryLng.value));

    // ---------------- SELECTED BOOKING DETAIL MAP ----------------
    <?php if ($selectedBooking): ?>
    const selectedBookingId = <?= (int)$selectedBooking['id'] ?>;
    const pickupCoords = [<?= (float)$selectedBooking['pickup_latitude'] ?>, <?= (float)$selectedBooking['pickup_longitude'] ?>];
    const deliveryCoords = [<?= (float)$selectedBooking['delivery_latitude'] ?>, <?= (float)$selectedBooking['delivery_longitude'] ?>];
    let detailMap = L.map('detail_map').setView(pickupCoords, 13);
    let trackingMarker = null;
    let routingControl = null;
    let riderMarkers = {};
    let knownRiderIds = new Set();
    let pingSound = null;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(detailMap);

    L.marker(pickupCoords).addTo(detailMap).bindPopup("Pickup");
    L.marker(deliveryCoords).addTo(detailMap).bindPopup("Delivery");

    function clearRouteInternal() {
        if (routingControl) {
            detailMap.removeControl(routingControl);
            routingControl = null;
        }
        const rd = document.getElementById('routing-directions');
        if (rd) rd.style.display = 'none';
        const btn = document.getElementById('clear-route-btn');
        if (btn) btn.style.display = 'none';
        document.querySelectorAll('.eta-badge').forEach(el => el.style.display = 'none');
    }

    window.clearActiveRoute = function () {
        clearRouteInternal();
        detailMap.flyTo(pickupCoords, 13);
    };

    <?php if ($needsRider): ?>
    pingSound = new Audio('assets/sounds/notification.mp3');

    async function updateRiders() {
        try {
            const response = await fetch(`bookings/ajax_fetch_riders.php?booking_id=${selectedBookingId}`);
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
                        html: `<div style="color:${rider.vehicle_type === 'car' ? '#38bdf8' : '#fbbf24'};font-size:24px;text-shadow:0 0 5px #000;"><i class="fa-solid ${rider.vehicle_type === 'car' ? 'fa-car-side' : 'fa-motorcycle'}"></i></div>`,
                        className: '', iconSize: [30, 30], iconAnchor: [15, 15]
                    });
                    riderMarkers[rider.id] = L.marker(latlng, { icon }).addTo(detailMap).bindPopup(`<b>${rider.full_name}</b>`);
                }

                html += `
                    <div class="col-lg-6" id="rider-card-${rider.id}">
                        <div class="cardx p-4 h-100">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h2 class="h5 mb-1">${rider.full_name}</h2>
                                    <div class="text-soft small">
                                        <span>${rider.vehicle_type === 'car' ? '🚗' : '🏍️'} ${rider.vehicle_type.toUpperCase()}</span> | ⭐ ${parseFloat(rider.rating).toFixed(1)}
                                    </div>
                                    <div class="d-flex gap-2 mt-2 flex-wrap">
                                        <span class="badge bg-info">${parseFloat(rider.distance_km).toFixed(2)} km away</span>
                                        <span class="badge bg-dark border border-info text-info eta-badge" id="eta-${rider.id}" style="display:none;"></span>
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-outline-info" onclick="showRiderRoute(${rider.last_latitude}, ${rider.last_longitude}, ${rider.id})">
                                    <i class="fa-solid fa-route me-1"></i> Route
                                </button>
                            </div>
                            <div class="rider-card p-3 mt-3">
                                <form onsubmit="sendRiderRequest(event, this)">
                                    <input type="hidden" name="booking_id" value="${selectedBookingId}">
                                    <input type="hidden" name="rider_user_id" value="${rider.id}">
                                    <div class="row g-2 align-items-end">
                                        <div class="col">
                                            <label class="form-label small text-soft">Proposed Fee (₦)</label>
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

            if (newRiderFound && pingSound) pingSound.play().catch(() => {});
            knownRiderIds = activeIds;

            listContainer.innerHTML = html || '<div class="col-12 text-center text-soft py-5">No active riders found in range.</div>';
        } catch (err) {
            console.error("Update Error:", err);
        }
    }

    window.showRiderRoute = (rLat, rLng, rId) => {
        clearRouteInternal();
        const directionsDiv = document.getElementById('routing-directions');
        directionsDiv.style.display = 'block';
        directionsDiv.innerHTML = '<div class="text-center p-3 text-info"><i class="fa-solid fa-spinner fa-spin me-2"></i>Analyzing route...</div>';
        document.getElementById('clear-route-btn').style.display = 'inline-block';

        routingControl = L.Routing.control({
            waypoints: [
                L.latLng(rLat, rLng),
                L.latLng(pickupCoords[0], pickupCoords[1]),
                L.latLng(deliveryCoords[0], deliveryCoords[1])
            ],
            lineOptions: { styles: [{ color: '#38bdf8', opacity: 0.7, weight: 8 }] },
            createMarker: () => null,
            addWaypoints: false,
            itineraryClassName: 'routing-instructions-list',
            show: true
        }).addTo(detailMap);

        routingControl.on('routesfound', function(e) {
            const summary = e.routes[0].summary;
            const mins = Math.round(summary.totalTime / 60);

            const badge = document.getElementById(`eta-${rId}`);
            if (badge) {
                badge.style.display = 'inline-block';
                badge.innerHTML = `<i class="fa-regular fa-clock me-1"></i> ${mins}m ETA`;
            }

            const container = routingControl.getItinerary().getContainer();
            directionsDiv.innerHTML = '<h6 class="text-info fw-bold mb-3"><i class="fa-solid fa-diamond-turn-right me-2"></i>Route Details</h6>';
            directionsDiv.appendChild(container);
        });

        detailMap.fitBounds([[rLat, rLng], pickupCoords, deliveryCoords], { padding: [50, 50] });
    };

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

            if (response.ok) {
                location.reload();
            } else {
                throw new Error();
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerText = originalText;
            const container = document.getElementById('alert-container');
            container.innerHTML = `<div class="alert alert-danger alert-dismissible fade show">Failed to send request.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
        }
    }

    updateRiders();
    setInterval(updateRiders, 10000);
    <?php endif; ?>

    <?php if ($canTrack): ?>
    async function pollTracking() {
        try {
            const res = await fetch(`bookings/ajax_track_status.php?booking_id=${selectedBookingId}`);
            const json = await res.json();
            if (!json.status) return;

            const d = json.data;
            document.getElementById('booking_status_text').innerText = d.booking_status;
            document.getElementById('payment_status_text').innerText = d.payment_status;

            if (!d.rider_lat || !d.rider_lng) return;

            const riderLatLng = [parseFloat(d.rider_lat), parseFloat(d.rider_lng)];

            if (!trackingMarker) {
                trackingMarker = L.marker(riderLatLng).addTo(detailMap).bindPopup('Rider');
            } else {
                trackingMarker.setLatLng(riderLatLng);
            }

            let target;
            if (d.booking_status === 'accepted' || d.booking_status === 'matched') {
                target = [parseFloat(d.pickup_lat), parseFloat(d.pickup_lng)];
            } else {
                target = [parseFloat(d.delivery_lat), parseFloat(d.delivery_lng)];
            }

            clearRouteInternal();

            routingControl = L.Routing.control({
                waypoints: [
                    L.latLng(riderLatLng[0], riderLatLng[1]),
                    L.latLng(target[0], target[1])
                ],
                routeWhileDragging: false,
                addWaypoints: false,
                draggableWaypoints: false,
                createMarker: () => null,
                lineOptions: { styles: [{ color: '#38bdf8', weight: 6 }] },
                show: true,
                itineraryClassName: 'routing-instructions-list'
            }).addTo(detailMap);

            routingControl.on('routesfound', function(e) {
                const route = e.routes[0];
                const distKm = (route.summary.totalDistance / 1000).toFixed(2);
                const etaMin = Math.round(route.summary.totalTime / 60);
                document.getElementById('eta_text').innerText = `${etaMin} min · ${distKm} km`;

                const directionsDiv = document.getElementById('routing-directions');
                directionsDiv.style.display = 'block';
                directionsDiv.innerHTML = '<h6 class="text-info fw-bold mb-3"><i class="fa-solid fa-diamond-turn-right me-2"></i>Live Route Details</h6>';
                directionsDiv.appendChild(routingControl.getItinerary().getContainer());
            });

        } catch (e) {
            console.log('Tracking error', e);
        }
    }

    pollTracking();
    setInterval(pollTracking, 5000);
    <?php endif; ?>

<?php if ($canPay): ?>
document.getElementById('pay-now-btn')?.addEventListener('click', async function () {
    const btn = this;
    const originalText = btn.textContent;

    btn.disabled = true;
    btn.textContent = 'Redirecting to payment...';

    try {
        const response = await fetch('<?= e(url_path('payments/initialize.php')) ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            cache: 'no-store',
            body: JSON.stringify({
                booking_id: <?= (int)$selectedBooking['id'] ?>
            })
        });

        const text = await response.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (parseError) {
            throw new Error('Initialize endpoint did not return valid JSON.');
        }

        console.log('Initialize response:', data);

        if (!response.ok) {
            throw new Error(data?.message || `Initialize failed with HTTP ${response.status}`);
        }

        if (!data || data.status !== true) {
            throw new Error(data?.message || 'Unable to initialize payment');
        }

        if (!data.data || !data.data.authorization_url) {
            console.error('Initialize payload missing authorization_url:', data);
            throw new Error('Missing Paystack authorization URL.');
        }

        window.location.href = data.data.authorization_url;
    } catch (err) {
        console.error('Payment initialization error:', err);
        alert(err.message || 'Payment initialization failed.');
        btn.disabled = false;
        btn.textContent = originalText;
    }
});
<?php endif; ?>
    <?php endif; ?>
});
</script>
</body>
</html>