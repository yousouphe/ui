<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

function sum_amount(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row['agreed_cost'] ?? $row['proposed_cost'] ?? 0);
    }
    return $total;
}

// ---------------- ACTIVE BOOKING ----------------
$stmt = $pdo->prepare('
    SELECT 
        b.*, 
        s.full_name AS sender_name, 
        s.phone AS sender_phone 
    FROM bookings b 
    INNER JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ? 
      AND b.booking_status IN ("matched", "accepted", "arrived_at_pickup", "package_received", "in_transit")
    ORDER BY b.id DESC
    LIMIT 1
');
$stmt->execute([$user['id']]);
$activeBooking = $stmt->fetch(PDO::FETCH_ASSOC);

$pickupLat = ($activeBooking && $activeBooking['pickup_latitude'] !== null) ? (float)$activeBooking['pickup_latitude'] : null;
$pickupLng = ($activeBooking && $activeBooking['pickup_longitude'] !== null) ? (float)$activeBooking['pickup_longitude'] : null;
$destLat   = ($activeBooking && $activeBooking['delivery_latitude'] !== null) ? (float)$activeBooking['delivery_latitude'] : null;
$destLng   = ($activeBooking && $activeBooking['delivery_longitude'] !== null) ? (float)$activeBooking['delivery_longitude'] : null;

$bookingAmount = $activeBooking ? (float)($activeBooking['agreed_cost'] ?? 0) : 0;
$currentStatus = $activeBooking['booking_status'] ?? null;

// sender package confirmation support
$senderConfirmedHandover =
    (bool)(
        $activeBooking['sender_handover_confirmed'] ??
        $activeBooking['package_handover_confirmed_by_sender'] ??
        $activeBooking['sender_package_confirmed'] ??
        false
    );

// ---------------- TARGET SWITCHING ----------------
$targetLat = null;
$targetLng = null;
$targetLabel = 'Destination';
$targetAddress = '';

if ($activeBooking) {
    if (in_array($currentStatus, ['matched', 'accepted'], true)) {
        $targetLat = $pickupLat;
        $targetLng = $pickupLng;
        $targetLabel = 'Pickup';
        $targetAddress = (string)($activeBooking['pickup_address'] ?? '');
    } else {
        $targetLat = $destLat;
        $targetLng = $destLng;
        $targetLabel = 'Destination';
        $targetAddress = (string)($activeBooking['delivery_address'] ?? '');
    }
}

$mapLink = ($targetLat !== null && $targetLng !== null)
    ? "https://www.google.com/maps/dir/?api=1&destination={$targetLat},{$targetLng}&travelmode=driving"
    : "#";

// ---------------- REQUESTS / ORDERS ----------------
$stmt = $pdo->prepare('
    SELECT 
        rr.*, 
        b.booking_code, 
        b.pickup_address, 
        b.delivery_address, 
        b.item_name,
        b.booking_status,
        b.agreed_cost,
        b.payment_status,
        b.sender_user_id,
        u.full_name AS sender_name
    FROM rider_requests rr
    INNER JOIN bookings b ON b.id = rr.booking_id
    LEFT JOIN users u ON u.id = b.sender_user_id
    WHERE rr.rider_user_id = ? 
    ORDER BY FIELD(rr.request_status, "pending","accepted","rejected"), rr.id DESC
');
$stmt->execute([$user['id']]);
$allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingOffers = array_values(array_filter($allRequests, fn($r) => ($r['request_status'] ?? '') === 'pending'));
$acceptedRequests = array_values(array_filter($allRequests, fn($r) => ($r['request_status'] ?? '') === 'accepted'));
$rejectedRequests = array_values(array_filter($allRequests, fn($r) => ($r['request_status'] ?? '') === 'rejected'));

// ---------------- ORDER SUMMARY DATA ----------------
$stmt = $pdo->prepare('
    SELECT 
        b.*,
        s.full_name AS sender_name,
        s.phone AS sender_phone
    FROM bookings b
    INNER JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ?
    ORDER BY b.id DESC
');
$stmt->execute([$user['id']]);
$allAssignedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$matchedBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'matched'));
$acceptedBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'accepted'));
$pickupBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'arrived_at_pickup'));
$packageReceivedBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'package_received'));
$inTransitBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'in_transit'));
$deliveredBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'delivered'));
$cancelledBookings = array_values(array_filter($allAssignedBookings, fn($b) => ($b['booking_status'] ?? '') === 'cancelled'));

$ongoingBookings = array_values(array_filter(
    $allAssignedBookings,
    fn($b) => in_array(($b['booking_status'] ?? ''), ['matched', 'accepted', 'arrived_at_pickup', 'package_received', 'in_transit'], true)
));

// ---------------- EARNINGS / PAYMENTS ----------------
$stmt = $pdo->prepare('
    SELECT 
        b.id,
        b.booking_code,
        b.agreed_cost,
        b.payment_status,
        b.booking_status,
        b.updated_at,
        b.created_at,
        s.full_name AS sender_name
    FROM bookings b
    LEFT JOIN users s ON s.id = b.sender_user_id
    WHERE b.selected_rider_user_id = ?
      AND b.booking_status = "delivered"
    ORDER BY b.id DESC
');
$stmt->execute([$user['id']]);
$deliveredEarningRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$paidEarningRows = array_values(array_filter($deliveredEarningRows, fn($r) => ($r['payment_status'] ?? '') === 'paid'));
$unpaidEarningRows = array_values(array_filter($deliveredEarningRows, fn($r) => ($r['payment_status'] ?? '') !== 'paid'));

$todayStart = (new DateTime('today'))->format('Y-m-d H:i:s');
$weekStart = (new DateTime('monday this week'))->format('Y-m-d H:i:s');
$monthStart = (new DateTime('first day of this month'))->format('Y-m-d H:i:s');

$todayPaidRows = array_values(array_filter($paidEarningRows, function ($r) use ($todayStart) {
    $dt = $r['updated_at'] ?? $r['created_at'] ?? null;
    return $dt && $dt >= $todayStart;
}));

$weekPaidRows = array_values(array_filter($paidEarningRows, function ($r) use ($weekStart) {
    $dt = $r['updated_at'] ?? $r['created_at'] ?? null;
    return $dt && $dt >= $weekStart;
}));

$monthPaidRows = array_values(array_filter($paidEarningRows, function ($r) use ($monthStart) {
    $dt = $r['updated_at'] ?? $r['created_at'] ?? null;
    return $dt && $dt >= $monthStart;
}));

$totalPaidToday = sum_amount($todayPaidRows);
$totalPaidWeek = sum_amount($weekPaidRows);
$totalPaidMonth = sum_amount($monthPaidRows);
$totalPaidOverall = sum_amount($paidEarningRows);
$totalOutstanding = sum_amount($unpaidEarningRows);
$totalExpectedOverall = sum_amount($deliveredEarningRows);

// ---------------- PROFILE ----------------
$stmt = $pdo->prepare('
    SELECT availability_status, last_latitude, last_longitude
    FROM rider_profiles
    WHERE user_id = ?
    LIMIT 1
');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

$isOnline = ($profile['availability_status'] ?? 'offline') === 'available';
// Fall back to Nigeria's geographic centroid (not any specific city) when a rider has no saved fix yet.
$initialLat = isset($profile['last_latitude']) ? (float)$profile['last_latitude'] : 9.0820;
$initialLng = isset($profile['last_longitude']) ? (float)$profile['last_longitude'] : 8.6753;

$respondRequestUrl = url_path('rider/respond_request.php');
$ajaxUpdateLocationUrl = url_path('rider/ajax_update_location.php');
$ajaxUpdateStatusUrl = url_path('rider/ajax_update_status.php');
$ajaxWorkflowUrl = url_path('rider/ajax_workflow_action.php');
$logoutUrl = url_path('logout.php');

function badge_class(string $status): string
{
    return match ($status) {
        'matched' => 'bg-info text-dark',
        'accepted' => 'bg-primary',
        'arrived_at_pickup' => 'bg-warning text-dark',
        'package_received' => 'bg-secondary',
        'in_transit' => 'bg-info',
        'delivered' => 'bg-success',
        'cancelled' => 'bg-danger',
        'paid' => 'bg-success',
        'pending' => 'bg-warning text-dark',
        'rejected' => 'bg-danger',
        default => 'bg-dark border border-secondary'
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title>Rider Dashboard | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css">
    <style>
        body {
            background: #09101d;
            min-height: 100vh;
            color: #eef4ff;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .navx {
            background: rgba(8,17,33,.88);
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .cardx {
            background: rgba(17,27,51,.95);
            border-radius: 1.5rem;
            border: 1px solid rgba(255,255,255,.08);
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
        }

        #nav_map {
            height: 400px;
            width: 100%;
            border-radius: 1.25rem;
            border: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        #route_details {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 1rem;
            padding: 14px;
            color: #cfe0ff;
            font-size: .92rem;
            margin-bottom: 1.5rem;
            max-height: 220px;
            overflow-y: auto;
        }

        #route_details .route-title {
            font-weight: 700;
            margin-bottom: 8px;
            color: #fff;
        }

        #route_details .route-step {
            padding: 6px 0;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        #route_details .route-step:last-child {
            border-bottom: none;
        }

        .stats-bar {
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.2);
            border-radius: 1rem;
            padding: 12px;
            margin-bottom: 1.5rem;
        }

        .summary-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 1rem;
            padding: 16px;
            height: 100%;
        }

        .stat-label {
            font-size: 0.65rem;
            color: #9fb0d6;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-value {
            font-size: 1rem;
            font-weight: 800;
            color: #fff;
        }

        .money-big {
            font-size: 1.4rem;
            font-weight: 800;
            color: #38bdf8;
        }

        .swipe-container {
            width: 100%;
            height: 54px;
            background: #16203a;
            border-radius: 27px;
            position: relative;
            cursor: pointer;
            border: 2px solid rgba(255,255,255,0.1);
            transition: 0.4s;
            user-select: none;
        }

        .swipe-handle {
            width: 46px;
            height: 46px;
            background: #9fb0d6;
            border-radius: 50%;
            position: absolute;
            top: 2px;
            left: 3px;
            transition: 0.4s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #09101d;
            z-index: 2;
        }

        .swipe-text {
            position: absolute;
            width: 100%;
            text-align: center;
            line-height: 50px;
            font-size: 0.85rem;
            font-weight: 800;
            letter-spacing: 1px;
            z-index: 1;
            pointer-events: none;
        }

        .swipe-container.active {
            background: #10b981;
            border-color: #10b981;
        }

        .swipe-container.active .swipe-handle {
            left: calc(100% - 49px);
            background: #fff;
            color: #10b981;
        }

        .req-card {
            background: rgba(255,255,255,0.03);
            border-radius: 1rem;
            border: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 1rem;
        }

        .price-tag {
            color: #38bdf8;
            font-weight: 800;
            font-size: 1.1rem;
        }

        .pulse-btn {
            animation: pulse-green 2s infinite;
            border-radius: 12px;
        }

        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 15px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .nav-tabs { border: none; gap: 8px; }

        .nav-link {
            color: #9fb0d6;
            border: none !important;
            border-radius: 10px !important;
            font-weight: 600;
            padding: 10px 20px;
        }

        .nav-link.active {
            background: #38bdf8 !important;
            color: #09101d !important;
        }

        .text-soft {
            color: #9fb0d6;
        }

        .map-legend {
            position: absolute;
            left: 12px;
            bottom: 12px;
            background: rgba(8,17,33,.82);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: .75rem;
            padding: .6rem .8rem;
            color: #cfe0ff;
            font-size: .82rem;
            z-index: 3;
        }

        .system-msg {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 1rem;
            padding: 12px 14px;
            font-size: .9rem;
            color: #cfe0ff;
            margin-bottom: 1rem;
        }

        .mini-row {
            border-bottom: 1px solid rgba(255,255,255,.06);
            padding: 10px 0;
        }

        .mini-row:last-child {
            border-bottom: none;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.08);
            font-size: .85rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('index.php')) ?>">SwiftDrop</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="<?= e($logoutUrl) ?>"><i class="fa-solid fa-right-from-bracket me-1"></i>Logout</a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Rider Dashboard</h1>
            <p class="text-soft mb-0">Track jobs, manage requests, monitor earnings, and update delivery workflow.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <span class="pill"><i class="fa-solid fa-box-open text-info"></i><?= count($ongoingBookings) ?> ongoing</span>
            <span class="pill"><i class="fa-solid fa-circle-check text-success"></i><?= count($deliveredBookings) ?> completed</span>
            <span class="pill"><i class="fa-solid fa-wallet text-warning"></i>₦<?= number_format($totalOutstanding, 2) ?> unpaid</span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="stat-label">Paid Today</div>
                <div class="money-big">₦<?= number_format($totalPaidToday, 2) ?></div>
                <div class="small text-soft mt-1"><?= count($todayPaidRows) ?> payment<?= count($todayPaidRows) === 1 ? '' : 's' ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="stat-label">Paid This Week</div>
                <div class="money-big">₦<?= number_format($totalPaidWeek, 2) ?></div>
                <div class="small text-soft mt-1"><?= count($weekPaidRows) ?> payment<?= count($weekPaidRows) === 1 ? '' : 's' ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="stat-label">Paid This Month</div>
                <div class="money-big">₦<?= number_format($totalPaidMonth, 2) ?></div>
                <div class="small text-soft mt-1"><?= count($monthPaidRows) ?> payment<?= count($monthPaidRows) === 1 ? '' : 's' ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="summary-card">
                <div class="stat-label">Outstanding Earnings</div>
                <div class="money-big">₦<?= number_format($totalOutstanding, 2) ?></div>
                <div class="small text-soft mt-1"><?= count($unpaidEarningRows) ?> unpaid delivery<?= count($unpaidEarningRows) === 1 ? '' : 'ies' ?></div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" id="riderDashboardTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview" type="button">Overview</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#offers" type="button">New Offers</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#orders" type="button">Orders</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#payments" type="button">Payments</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#history" type="button">History</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="overview">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="cardx p-3 p-md-4">
                        <?php if ($activeBooking): ?>
                            <div class="stats-bar d-flex justify-content-between align-items-center flex-wrap">
                                <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                                    <div class="stat-label">Current Job Value</div>
                                    <div class="stat-value text-info">₦<?= number_format($bookingAmount, 2) ?></div>
                                </div>
                                <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                                    <div class="stat-label">Distance</div>
                                    <div class="stat-value" id="distance_display">--</div>
                                </div>
                                <div class="text-center flex-fill border-end border-secondary border-opacity-25 px-2">
                                    <div class="stat-label">ETA</div>
                                    <div class="stat-value" id="eta_display">--</div>
                                </div>
                                <div class="text-center flex-fill px-2">
                                    <div class="stat-label">System</div>
                                    <div id="sync_status" class="stat-value small text-success">READY</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h2 class="h5 fw-bold mb-0">Rider Radar</h2>
                                <span id="sync_status" class="badge bg-dark border border-secondary text-info">OFFLINE</span>
                            </div>
                        <?php endif; ?>

                        <div id="nav_map">
                            <div class="map-legend">
                                <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#38bdf8;margin-right:6px"></span>Rider</div>
                                <div><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ef4444;margin-right:6px"></span><span id="target_label"><?= e($targetLabel) ?></span></div>
                            </div>
                        </div>

                        <div id="route_details">
                            <div class="route-title">Route Details</div>
                            <div id="route_details_body">Waiting for route...</div>
                        </div>

                        <div class="system-msg" id="geo_message">
                            <?php if ($activeBooking): ?>
                                Current target: <strong><?= e($targetLabel) ?></strong> — <?= e($targetAddress) ?>
                            <?php else: ?>
                                Tap the slider to go online. If GPS is unavailable, the map still shows your last known saved position.
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <div id="swipe-btn" class="swipe-container <?= $isOnline ? 'active' : '' ?>" onclick="toggleStatus()">
                                <div class="swipe-handle"><i class="fa-solid fa-motorcycle"></i></div>
                                <span class="swipe-text"><?= $isOnline ? 'TRACKING ONLINE' : 'SWIPE TO START WORKING' ?></span>
                            </div>
                        </div>

                        <?php if ($activeBooking): ?>
                            <div class="req-card p-3 border-info shadow-sm">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                    <span class="badge <?= e(badge_class($currentStatus)) ?>"><?= e(strtoupper(str_replace('_', ' ', (string)$currentStatus))) ?></span>
                                    <div class="d-flex gap-2">
                                        <a href="tel:<?= e($activeBooking['sender_phone']) ?>" class="btn btn-sm btn-dark border-secondary rounded-pill px-3">
                                            <i class="fa-solid fa-phone"></i>
                                        </a>
                                        <?php if ($targetLat !== null && $targetLng !== null): ?>
                                            <a href="<?= e($mapLink) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">
                                                <i class="fa-solid fa-diamond-turn-right me-1"></i> NAVIGATE
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <div class="small text-soft">Booking</div>
                                        <div class="fw-bold"><?= e($activeBooking['booking_code'] ?? '') ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-soft">Sender</div>
                                        <div class="fw-bold"><?= e($activeBooking['sender_name'] ?? '') ?></div>
                                    </div>
                                </div>

                                <p class="fw-bold mb-1 small text-truncate">
                                    <i class="fa-solid fa-location-dot me-2 text-danger"></i>
                                    <span id="target_address_text"><?= e($targetAddress) ?></span>
                                </p>
                                <p class="small text-soft mb-3">Item: <?= e($activeBooking['item_name'] ?? '') ?></p>

                                <?php if ($currentStatus === 'arrived_at_pickup'): ?>
                                    <div class="system-msg mb-3" id="sender_handover_notice">
                                        <?php if ($senderConfirmedHandover): ?>
                                            <i class="fa-solid fa-circle-check text-success me-2"></i>
                                            Sender has confirmed package handover. You can now mark package received.
                                        <?php else: ?>
                                            <i class="fa-solid fa-handshake-angle text-warning me-2"></i>
                                            Waiting for sender confirmation before you can select <strong>Received Package</strong>.
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <button
                                    type="button"
                                    id="btn_workflow"
                                    class="btn btn-secondary w-100 py-3 fw-bold pulse-btn"
                                    disabled
                                    data-sender-handover-confirmed="<?= $senderConfirmedHandover ? '1' : '0' ?>"
                                >
                                    CHECKING LOCATION...
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="system-msg mb-0">
                                <i class="fa-solid fa-satellite-dish me-2 text-info"></i>
                                No active delivery right now. Stay online to receive new offers.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="cardx p-4 h-100">
                        <h2 class="h5 fw-bold mb-3">Quick Summary</h2>

                        <div class="mini-row d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small text-soft">Pending Offers</div>
                                <div class="fw-bold"><?= count($pendingOffers) ?></div>
                            </div>
                            <span class="badge bg-warning text-dark">Awaiting response</span>
                        </div>

                        <div class="mini-row d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small text-soft">Ongoing Orders</div>
                                <div class="fw-bold"><?= count($ongoingBookings) ?></div>
                            </div>
                            <span class="badge bg-info text-dark">In progress</span>
                        </div>

                        <div class="mini-row d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small text-soft">Completed Orders</div>
                                <div class="fw-bold"><?= count($deliveredBookings) ?></div>
                            </div>
                            <span class="badge bg-success">Delivered</span>
                        </div>

                        <div class="mini-row d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small text-soft">Cancelled Orders</div>
                                <div class="fw-bold"><?= count($cancelledBookings) ?></div>
                            </div>
                            <span class="badge bg-danger">Cancelled</span>
                        </div>

                        <div class="mini-row d-flex justify-content-between align-items-center">
                            <div>
                                <div class="small text-soft">Paid Earnings</div>
                                <div class="fw-bold">₦<?= number_format($totalPaidOverall, 2) ?></div>
                            </div>
                            <span class="badge bg-success">Received</span>
                        </div>

                        <div class="pt-3">
                            <div class="small text-soft mb-2">Workflow distribution</div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="pill"><i class="fa-solid fa-user-check text-info"></i><?= count($matchedBookings) ?> matched</span>
                                <span class="pill"><i class="fa-solid fa-thumbs-up text-primary"></i><?= count($acceptedBookings) ?> accepted</span>
                                <span class="pill"><i class="fa-solid fa-location-crosshairs text-warning"></i><?= count($pickupBookings) ?> at pickup</span>
                                <span class="pill"><i class="fa-solid fa-box text-secondary"></i><?= count($packageReceivedBookings) ?> package received</span>
                                <span class="pill"><i class="fa-solid fa-truck-fast text-info"></i><?= count($inTransitBookings) ?> in transit</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="offers">
            <div class="cardx p-4">
                <h2 class="h5 fw-bold mb-3">New Offers</h2>
                <?php if (empty($pendingOffers)): ?>
                    <div class="text-center py-5 text-soft">
                        <i class="fa-solid fa-satellite-dish fa-3x mb-3 opacity-25"></i>
                        <p class="mb-0">Scanning for nearby orders...</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($pendingOffers as $req): ?>
                            <div class="col-lg-6">
                                <div class="req-card p-3 border-warning h-100">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="price-tag">₦<?= number_format((float)$req['proposed_cost'], 2) ?></span>
                                        <span class="small text-soft">#<?= e($req['booking_code']) ?></span>
                                    </div>
                                    <div class="small text-soft mb-2">Sender: <?= e($req['sender_name'] ?? 'Unknown') ?></div>
                                    <p class="small mb-2"><i class="fa-solid fa-map-pin me-2 text-warning"></i><?= e($req['pickup_address']) ?></p>
                                    <p class="small mb-3"><i class="fa-solid fa-location-dot me-2 text-info"></i><?= e($req['delivery_address']) ?></p>
                                    <form class="d-flex gap-2" method="post" action="<?= e($respondRequestUrl) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>">
                                        <button class="btn btn-success flex-grow-1 fw-bold" type="submit" name="action" value="accepted">ACCEPT OFFER</button>
                                        <button class="btn btn-outline-danger" type="submit" name="action" value="rejected"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-pane fade" id="orders">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="cardx p-4 h-100">
                        <h2 class="h5 fw-bold mb-3">Order Status Summary</h2>
                        <div class="mini-row d-flex justify-content-between"><span>Matched</span><strong><?= count($matchedBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Accepted</span><strong><?= count($acceptedBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Arrived at pickup</span><strong><?= count($pickupBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Package received</span><strong><?= count($packageReceivedBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>In transit</span><strong><?= count($inTransitBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Delivered</span><strong><?= count($deliveredBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Cancelled</span><strong><?= count($cancelledBookings) ?></strong></div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="cardx p-4">
                        <h2 class="h5 fw-bold mb-3">All Assigned Orders</h2>
                        <?php if (empty($allAssignedBookings)): ?>
                            <div class="text-soft">No assigned orders yet.</div>
                        <?php else: ?>
                            <?php foreach ($allAssignedBookings as $b): ?>
                                <div class="req-card p-3">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                        <div>
                                            <div class="fw-bold"><?= e($b['booking_code']) ?></div>
                                            <div class="small text-soft"><?= e($b['item_name'] ?? 'Package') ?></div>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <span class="badge <?= e(badge_class((string)($b['booking_status'] ?? ''))) ?>"><?= e(str_replace('_', ' ', (string)($b['booking_status'] ?? 'unknown'))) ?></span>
                                            <span class="badge <?= e(badge_class((string)($b['payment_status'] ?? 'pending'))) ?>"><?= e((string)($b['payment_status'] ?? 'pending')) ?></span>
                                        </div>
                                    </div>
                                    <div class="small text-soft mb-1">Sender: <?= e($b['sender_name'] ?? '') ?><?= !empty($b['sender_phone']) ? ' · ' . e($b['sender_phone']) : '' ?></div>
                                    <div class="small text-soft mb-1">Pickup: <?= e($b['pickup_address'] ?? '') ?></div>
                                    <div class="small text-soft mb-2">Delivery: <?= e($b['delivery_address'] ?? '') ?></div>
                                    <div class="price-tag">₦<?= number_format((float)($b['agreed_cost'] ?? 0), 2) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="payments">
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="stat-label">Total Paid Today</div>
                        <div class="money-big">₦<?= number_format($totalPaidToday, 2) ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="stat-label">Total Paid This Week</div>
                        <div class="money-big">₦<?= number_format($totalPaidWeek, 2) ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="stat-label">Total Paid This Month</div>
                        <div class="money-big">₦<?= number_format($totalPaidMonth, 2) ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="summary-card">
                        <div class="stat-label">Total Paid Overall</div>
                        <div class="money-big">₦<?= number_format($totalPaidOverall, 2) ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="cardx p-4 h-100">
                        <h2 class="h5 fw-bold mb-3">Payment Summary</h2>
                        <div class="mini-row d-flex justify-content-between"><span>Paid deliveries</span><strong><?= count($paidEarningRows) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Unpaid deliveries</span><strong><?= count($unpaidEarningRows) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Outstanding amount</span><strong>₦<?= number_format($totalOutstanding, 2) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span>Total expected</span><strong>₦<?= number_format($totalExpectedOverall, 2) ?></strong></div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="cardx p-4">
                        <h2 class="h5 fw-bold mb-3">Earnings Details</h2>
                        <?php if (empty($deliveredEarningRows)): ?>
                            <div class="text-soft">No delivered jobs yet.</div>
                        <?php else: ?>
                            <?php foreach ($deliveredEarningRows as $row): ?>
                                <div class="req-card p-3">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <div class="fw-bold"><?= e($row['booking_code']) ?></div>
                                            <div class="small text-soft"><?= e($row['sender_name'] ?? '') ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="price-tag">₦<?= number_format((float)($row['agreed_cost'] ?? 0), 2) ?></div>
                                            <span class="badge <?= e(badge_class((string)($row['payment_status'] ?? 'pending'))) ?>">
                                                <?= e((string)($row['payment_status'] ?? 'pending')) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="small text-soft mt-2">
                                        Delivered on <?= e((string)($row['updated_at'] ?? $row['created_at'] ?? '')) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="history">
            <div class="cardx p-4">
                <h2 class="h5 fw-bold mb-3">Request / Order History</h2>
                <?php
                $historyRows = array_filter($allRequests, fn($req) =>
                    ($req['request_status'] ?? '') !== 'pending' || in_array(($req['booking_status'] ?? ''), ['delivered', 'cancelled'], true)
                );
                ?>
                <?php if (empty($historyRows)): ?>
                    <div class="text-soft">No request history yet.</div>
                <?php else: ?>
                    <?php foreach ($historyRows as $req): ?>
                        <div class="req-card p-3">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <div>
                                    <div class="fw-bold"><?= e($req['booking_code']) ?></div>
                                    <div class="small text-soft"><?= e($req['item_name'] ?? '') ?></div>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <span class="badge <?= e(badge_class((string)($req['request_status'] ?? 'pending'))) ?>"><?= e((string)($req['request_status'] ?? 'pending')) ?></span>
                                    <span class="badge <?= e(badge_class((string)($req['booking_status'] ?? ''))) ?>"><?= e(str_replace('_', ' ', (string)($req['booking_status'] ?? 'unknown'))) ?></span>
                                </div>
                            </div>
                            <div class="small text-soft mb-1">Pickup: <?= e($req['pickup_address'] ?? '') ?></div>
                            <div class="small text-soft mb-1">Delivery: <?= e($req['delivery_address'] ?? '') ?></div>
                            <div class="small text-soft">Offer / Value: ₦<?= number_format((float)($req['proposed_cost'] ?? $req['agreed_cost'] ?? 0), 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
<script>
    const swipeBtn = document.getElementById('swipe-btn');
    const btnWorkflow = document.getElementById('btn_workflow');
    const distDisplay = document.getElementById('distance_display');
    const etaDisplay = document.getElementById('eta_display');
    const syncStatus = document.getElementById('sync_status');
    const geoMessage = document.getElementById('geo_message');
    const routeDetailsBody = document.getElementById('route_details_body');

    const ajaxUpdateLocationUrl = <?= json_encode($ajaxUpdateLocationUrl) ?>;
    const ajaxUpdateStatusUrl = <?= json_encode($ajaxUpdateStatusUrl) ?>;
    const ajaxWorkflowUrl = <?= json_encode($ajaxWorkflowUrl) ?>;
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    let watchId = null;
    let lastKnownPosition = null;
    let currentStatus = <?= json_encode($currentStatus) ?>;

    const bookingId = <?= $activeBooking ? (int)$activeBooking['id'] : 'null' ?>;
    const senderHandoverConfirmed = <?= json_encode($senderConfirmedHandover) ?>;

    const pickup = {
        lat: <?= $pickupLat !== null ? json_encode($pickupLat) : 'null' ?>,
        lng: <?= $pickupLng !== null ? json_encode($pickupLng) : 'null' ?>
    };

    const dest = {
        lat: <?= $destLat !== null ? json_encode($destLat) : 'null' ?>,
        lng: <?= $destLng !== null ? json_encode($destLng) : 'null' ?>
    };

    const initialRider = {
        lat: <?= json_encode($initialLat) ?>,
        lng: <?= json_encode($initialLng) ?>
    };

    let map = null;
    let riderMarker = null;
    let targetMarker = null;
    let routingControl = null;
    let latestRouteDistanceMeters = null;
    let latestRouteDurationSeconds = null;

    function getCurrentTarget() {
        if (!bookingId) return null;

        if (currentStatus === 'matched' || currentStatus === 'accepted') {
            return {
                type: 'pickup',
                lat: pickup.lat,
                lng: pickup.lng,
                label: 'Pickup',
                address: <?= json_encode((string)($activeBooking['pickup_address'] ?? '')) ?>
            };
        }

        if (currentStatus === 'arrived_at_pickup' || currentStatus === 'package_received' || currentStatus === 'in_transit') {
            return {
                type: 'delivery',
                lat: dest.lat,
                lng: dest.lng,
                label: 'Destination',
                address: <?= json_encode((string)($activeBooking['delivery_address'] ?? '')) ?>
            };
        }

        return null;
    }

    function explainGeoError(err) {
        if (!err) return 'Unable to fetch current location.';
        if (err.code === 1) return 'Location permission was denied.';
        if (err.code === 2) return 'Position unavailable. The device could not get a reliable fix.';
        if (err.code === 3) return 'Location request timed out.';
        return 'Unable to fetch current location.';
    }

    function formatDistance(meters) {
        if (meters === null || meters === undefined) return '--';
        return meters >= 1000 ? (meters / 1000).toFixed(1) + ' km' : Math.round(meters) + ' m';
    }

    function formatDuration(seconds) {
        if (seconds === null || seconds === undefined) return '--';
        const mins = Math.round(seconds / 60);
        if (mins < 60) return mins + ' min';
        const hrs = Math.floor(mins / 60);
        const rem = mins % 60;
        return hrs + 'h ' + rem + 'm';
    }

    function initMap() {
        map = L.map('nav_map', {
            zoomControl: true
        }).setView([initialRider.lat, initialRider.lng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        riderMarker = L.marker([initialRider.lat, initialRider.lng], {
            title: 'Rider'
        }).addTo(map);

        const target = getCurrentTarget();
        if (target && target.lat !== null && target.lng !== null) {
            targetMarker = L.marker([target.lat, target.lng], {
                title: target.label
            }).addTo(map);

            buildRoute(initialRider.lat, initialRider.lng, target.lat, target.lng);
        }

        setTimeout(() => map.invalidateSize(), 250);
    }

    function clearRoute() {
        if (routingControl) {
            map.removeControl(routingControl);
            routingControl = null;
        }
    }

    function renderRouteDetails(route, target) {
        if (!route) {
            routeDetailsBody.innerHTML = 'No route details available.';
            return;
        }

        const summary = `
            <div class="mb-2"><strong>Target:</strong> ${target.label}</div>
            <div class="mb-2"><strong>Address:</strong> ${target.address || '-'}</div>
            <div class="mb-2"><strong>Road distance:</strong> ${formatDistance(route.summary.totalDistance)}</div>
            <div class="mb-3"><strong>Estimated time:</strong> ${formatDuration(route.summary.totalTime)}</div>
        `;

        const instructions = (route.instructions || []).slice(0, 8).map(step => {
            return `<div class="route-step">${step.text} <span class="text-soft">(${formatDistance(step.distance)})</span></div>`;
        }).join('');

        routeDetailsBody.innerHTML = summary + (instructions || '<div>No turn-by-turn instructions.</div>');
    }

    function updateStatsFromRoute(route) {
        if (!route) return;

        latestRouteDistanceMeters = route.summary.totalDistance;
        latestRouteDurationSeconds = route.summary.totalTime;

        if (distDisplay) distDisplay.textContent = formatDistance(latestRouteDistanceMeters);
        if (etaDisplay) etaDisplay.textContent = formatDuration(latestRouteDurationSeconds);

        updateWorkflowButton(latestRouteDistanceMeters);
    }

    function buildRoute(fromLat, fromLng, toLat, toLng) {
        const target = getCurrentTarget();
        if (!target) return;

        clearRoute();

        routingControl = L.Routing.control({
            waypoints: [
                L.latLng(fromLat, fromLng),
                L.latLng(toLat, toLng)
            ],
            router: L.Routing.osrmv1({
                serviceUrl: 'https://router.project-osrm.org/route/v1'
            }),
            addWaypoints: false,
            draggableWaypoints: false,
            routeWhileDragging: false,
            fitSelectedRoutes: true,
            show: false,
            lineOptions: {
                styles: [{ color: '#38bdf8', opacity: 0.9, weight: 5 }]
            },
            createMarker: function(i, wp) {
                if (i === 0) {
                    riderMarker = L.marker(wp.latLng, { title: 'Rider' });
                    return riderMarker;
                } else {
                    targetMarker = L.marker(wp.latLng, { title: target.label });
                    return targetMarker;
                }
            }
        }).addTo(map);

        routingControl.on('routesfound', function(e) {
            const route = e.routes[0];
            updateStatsFromRoute(route);
            renderRouteDetails(route, target);

            const bounds = L.latLngBounds(route.coordinates);
            map.fitBounds(bounds, {
                padding: [40, 40],
                animate: true,
                duration: 0.75
            });
        });

        routingControl.on('routingerror', function() {
            routeDetailsBody.innerHTML = 'Unable to fetch road route details right now.';
            if (geoMessage) geoMessage.textContent = 'Routing service is temporarily unavailable.';
        });
    }

    function updateMapAndTargetUI(lat, lng) {
        if (!map || !riderMarker) return;

        const target = getCurrentTarget();
        if (!target || target.lat === null || target.lng === null) return;

        const currentTargetLabel = document.getElementById('target_label');
        if (currentTargetLabel) currentTargetLabel.textContent = target.label;

        const targetText = document.getElementById('target_address_text');
        if (targetText) targetText.textContent = target.address;

        buildRoute(lat, lng, target.lat, target.lng);
    }

    function updateWorkflowButton(distance) {
        if (!btnWorkflow || !bookingId) return;

        btnWorkflow.classList.remove('btn-success', 'btn-warning', 'btn-primary', 'btn-secondary', 'btn-danger');

        if (currentStatus === 'matched' || currentStatus === 'accepted') {
            if (distance !== null && distance <= 300) {
                btnWorkflow.disabled = false;
                btnWorkflow.classList.add('btn-success');
                btnWorkflow.innerHTML = '<i class="fa-solid fa-location-crosshairs me-2"></i>I HAVE ARRIVED';
            } else {
                btnWorkflow.disabled = true;
                btnWorkflow.classList.add('btn-secondary');
                btnWorkflow.innerHTML = '<i class="fa-solid fa-route me-2"></i>HEADING TO PICKUP';
            }
        } else if (currentStatus === 'arrived_at_pickup') {
            if (senderHandoverConfirmed) {
                btnWorkflow.disabled = false;
                btnWorkflow.classList.add('btn-warning');
                btnWorkflow.innerHTML = '<i class="fa-solid fa-box-open me-2"></i>CONFIRM PACKAGE RECEIVED';
            } else {
                btnWorkflow.disabled = true;
                btnWorkflow.classList.add('btn-danger');
                btnWorkflow.innerHTML = '<i class="fa-solid fa-handshake-angle me-2"></i>WAITING FOR SENDER CONFIRMATION';
            }
        } else if (currentStatus === 'package_received' || currentStatus === 'in_transit') {
            if (distance !== null && distance <= 300) {
                btnWorkflow.disabled = false;
                btnWorkflow.classList.add('btn-success');
                btnWorkflow.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>COMPLETE DELIVERY';
            } else {
                btnWorkflow.disabled = true;
                btnWorkflow.classList.add('btn-secondary');
                btnWorkflow.innerHTML = '<i class="fa-solid fa-truck-fast me-2"></i>HEADING TO DELIVERY';
            }
        } else {
            btnWorkflow.disabled = true;
            btnWorkflow.classList.add('btn-secondary');
            btnWorkflow.innerHTML = 'NO ACTIVE STEP';
        }
    }

    async function runWorkflowAction() {
        if (!bookingId || !btnWorkflow || btnWorkflow.disabled) return;

        let action = null;

        if (currentStatus === 'matched' || currentStatus === 'accepted') {
            action = 'arrived_at_pickup';
        } else if (currentStatus === 'arrived_at_pickup') {
            if (!senderHandoverConfirmed) {
                if (geoMessage) {
                    geoMessage.textContent = 'You cannot mark package received until the sender confirms handover.';
                }
                return;
            }
            action = 'package_received';
        } else if (currentStatus === 'package_received' || currentStatus === 'in_transit') {
            action = 'delivered';
        }

        if (!action) return;

        btnWorkflow.disabled = true;

        try {
            const res = await fetch(ajaxWorkflowUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    booking_id: bookingId,
                    action: action,
                    csrf_token: CSRF_TOKEN
                })
            });

            const data = await res.json();

            if (!res.ok || !data.success) {
                throw new Error(data.message || 'Workflow action failed.');
            }

            currentStatus = data.new_status;
            if (geoMessage) geoMessage.textContent = data.message || 'Status updated.';

            if (currentStatus === 'delivered') {
                window.location.reload();
                return;
            }

            const lat = lastKnownPosition ? lastKnownPosition.lat : initialRider.lat;
            const lng = lastKnownPosition ? lastKnownPosition.lng : initialRider.lng;
            updateMapAndTargetUI(lat, lng);
        } catch (err) {
            if (geoMessage) geoMessage.textContent = err.message || 'Action failed.';
            btnWorkflow.disabled = false;
        }
    }

    async function toggleStatus() {
        if (!swipeBtn) return;

        const isActivating = !swipeBtn.classList.contains('active');

        if (isActivating) {
            if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                if (geoMessage) geoMessage.textContent = 'GPS may fail because browser location usually requires HTTPS or localhost.';
            }

            if (!navigator.geolocation) {
                if (geoMessage) geoMessage.textContent = 'Geolocation is not supported on this browser.';
                return;
            }

            navigator.geolocation.getCurrentPosition(
                () => {
                    swipeBtn.classList.add('active');
                    swipeBtn.querySelector('.swipe-text').innerText = 'TRACKING ONLINE';
                    startTracking();
                    updateServerStatus('available');
                },
                (err) => {
                    if (geoMessage) geoMessage.textContent = explainGeoError(err);
                },
                { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
            );
        } else {
            swipeBtn.classList.remove('active');
            swipeBtn.querySelector('.swipe-text').innerText = 'SWIPE TO START WORKING';
            stopTracking();
            updateServerStatus('offline');
            if (geoMessage) geoMessage.textContent = 'Tracking stopped.';
        }
    }

    function startTracking() {
        if (!navigator.geolocation) return;

        if (watchId) {
            navigator.geolocation.clearWatch(watchId);
        }

        if (syncStatus) syncStatus.innerText = 'LIVE';
        if (geoMessage) geoMessage.textContent = 'Tracking started...';

        watchId = navigator.geolocation.watchPosition(
            async (pos) => {
                const { latitude, longitude } = pos.coords;
                lastKnownPosition = { lat: latitude, lng: longitude };

                updateMapAndTargetUI(latitude, longitude);

                try {
                    await fetch(ajaxUpdateLocationUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ latitude, longitude, status: 'available', csrf_token: CSRF_TOKEN })
                    });
                } catch (e) {
                    if (geoMessage) geoMessage.textContent = 'Live location updated on screen, but server sync failed.';
                }
            },
            (err) => {
                if (geoMessage) geoMessage.textContent = explainGeoError(err);
                if (syncStatus) syncStatus.innerText = 'GPS ISSUE';

                const fallbackLat = lastKnownPosition ? lastKnownPosition.lat : initialRider.lat;
                const fallbackLng = lastKnownPosition ? lastKnownPosition.lng : initialRider.lng;
                updateMapAndTargetUI(fallbackLat, fallbackLng);
            },
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 5000 }
        );
    }

    function stopTracking() {
        if (watchId) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
        }
        if (syncStatus) syncStatus.innerText = 'OFFLINE';
    }

    async function updateServerStatus(status) {
        try {
            await fetch(ajaxUpdateStatusUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status, csrf_token: CSRF_TOKEN })
            });
        } catch (e) {
            // ignore
        }
    }

    function initPage() {
        initMap();
        updateMapAndTargetUI(initialRider.lat, initialRider.lng);

        if (swipeBtn && swipeBtn.classList.contains('active')) {
            startTracking();
        }
    }

    if (btnWorkflow) {
        btnWorkflow.addEventListener('click', runWorkflowAction);
    }

    initPage();

    window.addEventListener('resize', function () {
        if (map) {
            map.invalidateSize();
        }
    });
</script>
</body>
</html>