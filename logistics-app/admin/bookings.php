<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../config/emails.php';
require_once __DIR__ . '/../config/push.php';
require_once __DIR__ . '/../config/mapbox.php';

// Booking statuses where the parcel hasn't physically changed hands yet - an admin can
// still (re)assign a rider up to this point. Once a rider has arrived at pickup or later,
// swapping riders doesn't make sense since a different rider isn't holding the parcel.
const ADMIN_ASSIGNABLE_BOOKING_STATUSES = ['draft', 'submitted', 'matched', 'accepted'];

$user = current_user();
$success = flash('success');
$error = flash('error');

$bookingStatusFilter = trim((string) ($_GET['booking_status'] ?? ''));
$paymentStatusFilter = trim((string) ($_GET['payment_status'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $formAction = (string) ($_POST['form_action'] ?? '');

    $stmt = $pdo->prepare('
        SELECT b.*, u.full_name AS sender_full_name, u.email AS sender_email
        FROM bookings b
        INNER JOIN users u ON u.id = b.sender_user_id
        WHERE b.id = ?
        LIMIT 1
    ');
    $stmt->execute([$bookingId]);
    $targetBooking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetBooking) {
        flash('error', t('admin.booking_not_found'));
        redirect_to('admin/bookings.php');
    }

    if ($formAction === 'force_cancel') {
        if (in_array($targetBooking['booking_status'], ['delivered', 'cancelled'], true)) {
            flash('error', t('admin.booking_cannot_cancel'));
        } else {
            $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'Cancelled by admin.';
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET booking_status = 'cancelled', cancellation_reason = ?, cancelled_by = 'admin'
                WHERE id = ?
            ");
            $stmt->execute([$reason, $bookingId]);
            send_web_push($pdo, (int) $targetBooking['sender_user_id'], 'Booking cancelled', 'Booking ' . $targetBooking['booking_code'] . ' was cancelled by our team: ' . $reason, url_path('bookings/index.php?booking_id=' . $bookingId));
            log_event($pdo, 'booking_cancelled_by_admin', 'Booking ' . $targetBooking['booking_code'] . ' force-cancelled by admin', (int) $user['id'], (string) $user['role'], 'booking', $bookingId, ['reason' => $reason]);
            flash('success', t('admin.booking_cancelled'));
        }
        redirect_to('admin/bookings.php?booking_id=' . $bookingId);
    }

    if ($formAction === 'refund') {
        if (($targetBooking['payment_status'] ?? '') !== 'paid') {
            flash('error', t('admin.booking_not_refundable'));
            redirect_to('admin/bookings.php?booking_id=' . $bookingId);
        }

        $reference = (string) ($targetBooking['paystack_reference'] ?? '');
        if ($reference === '') {
            flash('error', t('admin.booking_no_payment_reference'));
            redirect_to('admin/bookings.php?booking_id=' . $bookingId);
        }

        $refundResult = paystack_initiate_refund($reference);

        if (!$refundResult['ok']) {
            log_event($pdo, 'booking_refund_failed', 'Refund failed for booking ' . $targetBooking['booking_code'] . ': ' . $refundResult['message'], (int) $user['id'], (string) $user['role'], 'booking', $bookingId);
            flash('error', t('admin.refund_failed') . ' ' . $refundResult['message']);
            redirect_to('admin/bookings.php?booking_id=' . $bookingId);
        }

        $amount = (float) $targetBooking['agreed_cost'];
        $stmt = $pdo->prepare("
            UPDATE booking_payments
            SET refund_status = 'processed', refund_amount = ?, refunded_at = NOW()
            WHERE booking_id = ? AND reference = ?
        ");
        $stmt->execute([$amount, $bookingId, $reference]);

        $stmt = $pdo->prepare("UPDATE bookings SET payment_status = 'refunded' WHERE id = ?");
        $stmt->execute([$bookingId]);

        send_refund_email((string) $targetBooking['sender_email'], (string) $targetBooking['sender_full_name'], (string) $targetBooking['booking_code'], $amount);
        send_web_push($pdo, (int) $targetBooking['sender_user_id'], 'Refund issued', '₦' . number_format($amount, 2) . ' has been refunded for booking ' . $targetBooking['booking_code'] . '.', url_path('bookings/index.php?booking_id=' . $bookingId));
        log_event($pdo, 'booking_refunded', 'Refund issued for booking ' . $targetBooking['booking_code'], (int) $user['id'], (string) $user['role'], 'booking', $bookingId, ['amount' => $amount, 'reference' => $reference]);

        flash('success', t('admin.refund_issued'));
        redirect_to('admin/bookings.php?booking_id=' . $bookingId);
    }

    if ($formAction === 'assign_rider') {
        $riderUserId = (int) ($_POST['rider_user_id'] ?? 0);

        if (!in_array($targetBooking['booking_status'], ADMIN_ASSIGNABLE_BOOKING_STATUSES, true)) {
            flash('error', t('admin.booking_not_assignable'));
            redirect_to('admin/bookings.php?booking_id=' . $bookingId);
        }
        if ($targetBooking['pickup_latitude'] === null || $targetBooking['delivery_latitude'] === null) {
            flash('error', t('admin.booking_missing_coordinates'));
            redirect_to('admin/bookings.php?booking_id=' . $bookingId);
        }

        // Re-validate server-side rather than trusting the submitted rider id - the same
        // eligibility rules as the sender-facing rider list (active, KYC-approved), matching
        // the booking's chosen vehicle type when one was recorded, plus room for one more
        // active delivery (RIDER_MAX_CONCURRENT_ORDERS).
        $bookingVehicleType = $targetBooking['vehicle_type'] ?? null;
        $vehicleTypeSql = $bookingVehicleType !== null ? 'AND rp.vehicle_type = ?' : '';
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.email, rp.vehicle_type
            FROM users u
            INNER JOIN rider_profiles rp ON rp.user_id = u.id
            WHERE u.id = ? AND u.role = 'rider' AND u.status = 'active' AND rp.kyc_status = 'approved'
              $vehicleTypeSql
              AND (
                  SELECT COUNT(*) FROM bookings b
                  WHERE b.selected_rider_user_id = u.id AND b.id <> ?
                  AND b.booking_status IN ('" . implode("','", RIDER_ACTIVE_BOOKING_STATUSES) . "')
              ) < " . RIDER_MAX_CONCURRENT_ORDERS . "
            LIMIT 1
        ");
        $stmt->execute($bookingVehicleType !== null ? [$riderUserId, $bookingVehicleType, $bookingId] : [$riderUserId, $bookingId]);
        $rider = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rider) {
            flash('error', t('admin.rider_not_eligible'));
            redirect_to('admin/bookings.php?booking_id=' . $bookingId);
        }

        // Bookings created since transport type was moved up front (see bookings/index.php's
        // wizard) already carry a locked-in agreed_cost from submission - only bookings from
        // before that change, or ones created while Mapbox was unreachable, can still have a
        // null price here.
        $manualPriceRaw = trim((string) ($_POST['manual_price'] ?? ''));
        $manualPrice = $manualPriceRaw !== '' && is_numeric($manualPriceRaw) ? (float) $manualPriceRaw : null;

        if ($targetBooking['agreed_cost'] !== null) {
            $newCost = (float) $targetBooking['agreed_cost'];
        } elseif ($manualPrice !== null && $manualPrice > 0) {
            // Emergency override for when Mapbox is genuinely down and pricing can't be
            // computed automatically - an admin typing in a considered price is a deliberate
            // human decision, not the system silently guessing off an approximate distance,
            // so this doesn't reopen the no-haversine-fallback guarantee.
            $newCost = $manualPrice;
        } else {
            try {
                $distanceKm = pricing_distance_km(
                    (float) $targetBooking['pickup_latitude'],
                    (float) $targetBooking['pickup_longitude'],
                    (float) $targetBooking['delivery_latitude'],
                    (float) $targetBooking['delivery_longitude']
                );
            } catch (NoRouteFoundException $e) {
                flash('error', t('admin.no_route_found'));
                redirect_to('admin/bookings.php?booking_id=' . $bookingId);
            } catch (RuntimeException $e) {
                flash('error', t('admin.pricing_unavailable'));
                redirect_to('admin/bookings.php?booking_id=' . $bookingId);
            }
            $newCost = calculate_delivery_price($pdo, $distanceKm, (string) $rider['vehicle_type'])['total'];
        }
        $newStatus = in_array($targetBooking['booking_status'], ['draft', 'submitted'], true)
            ? 'matched'
            : $targetBooking['booking_status'];
        $previousRiderId = $targetBooking['selected_rider_user_id'] !== null ? (int) $targetBooking['selected_rider_user_id'] : null;

        $stmt = $pdo->prepare('
            UPDATE bookings SET selected_rider_user_id = ?, agreed_cost = ?, booking_status = ?, matched_at = COALESCE(matched_at, NOW()) WHERE id = ?
        ');
        $stmt->execute([$riderUserId, $newCost, $newStatus, $bookingId]);

        $stmt = $pdo->prepare("UPDATE rider_requests SET request_status = 'cancelled' WHERE booking_id = ? AND request_status = 'pending'");
        $stmt->execute([$bookingId]);

        send_rider_matched_email((string) $targetBooking['sender_email'], (string) $targetBooking['sender_full_name'], (string) $rider['full_name'], (string) $targetBooking['booking_code']);
        send_web_push($pdo, (int) $targetBooking['sender_user_id'], 'Rider assigned', (string) $rider['full_name'] . ' has been assigned to your delivery ' . $targetBooking['booking_code'] . '.', url_path('bookings/index.php?booking_id=' . $bookingId));
        send_web_push($pdo, $riderUserId, 'New delivery assigned', 'You have been assigned to booking ' . $targetBooking['booking_code'] . '.', url_path('rider/'));
        if ($previousRiderId !== null && $previousRiderId !== $riderUserId) {
            send_web_push($pdo, $previousRiderId, 'Booking reassigned', 'Booking ' . $targetBooking['booking_code'] . ' has been reassigned to another rider.', url_path('rider/'));
        }
        log_event(
            $pdo,
            'booking_rider_assigned_by_admin',
            'Booking ' . $targetBooking['booking_code'] . ' assigned to rider ' . $rider['full_name'] . ' by admin',
            (int) $user['id'],
            (string) $user['role'],
            'booking',
            $bookingId,
            ['rider_user_id' => $riderUserId, 'previous_rider_user_id' => $previousRiderId, 'amount' => $newCost, 'manual_price' => $manualPrice !== null && $manualPrice > 0]
        );

        flash('success', t('admin.rider_assigned'));
        redirect_to('admin/bookings.php?booking_id=' . $bookingId);
    }
}

$selectedBookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
$selectedBooking = null;
$timeline = [];

if ($selectedBookingId > 0) {
    $stmt = $pdo->prepare('
        SELECT b.*,
            su.full_name AS sender_full_name, su.email AS sender_email, su.phone AS sender_phone,
            ru.full_name AS rider_full_name, ru.email AS rider_email, ru.phone AS rider_phone
        FROM bookings b
        INNER JOIN users su ON su.id = b.sender_user_id
        LEFT JOIN users ru ON ru.id = b.selected_rider_user_id
        WHERE b.id = ?
        LIMIT 1
    ');
    $stmt->execute([$selectedBookingId]);
    $selectedBooking = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedBooking) {
        $stmt = $pdo->prepare("
            SELECT el.*, u.full_name AS actor_name
            FROM event_logs el
            LEFT JOIN users u ON u.id = el.actor_user_id
            WHERE el.target_type = 'booking' AND el.target_id = ?
            ORDER BY el.id ASC
        ");
        $stmt->execute([$selectedBookingId]);
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Candidate riders for manual assignment - purely a manual pick, not automatic matching, so
// rider location is deliberately not considered (no distance filter/sort): the admin decides
// who's right for the job, the same way the automatic flow decides eligibility minus location.
// Matches the booking's chosen vehicle type when one was recorded (bookings created before
// transport type was moved up front won't have one, so those show every vehicle type).
$eligibleRiders = [];
$pricingUnavailable = false;
$noRouteFound = false;
if (
    $selectedBooking
    && in_array($selectedBooking['booking_status'], ADMIN_ASSIGNABLE_BOOKING_STATUSES, true)
    && $selectedBooking['pickup_latitude'] !== null
    && $selectedBooking['delivery_latitude'] !== null
) {
    $pickupLat = (float) $selectedBooking['pickup_latitude'];
    $pickupLng = (float) $selectedBooking['pickup_longitude'];
    $bookingVehicleType = $selectedBooking['vehicle_type'] ?? null;
    $vehicleTypeSql = $bookingVehicleType !== null ? 'AND rp.vehicle_type = ?' : '';

    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, rp.vehicle_type, rp.rating,
               (
                   SELECT COUNT(*) FROM bookings b
                   WHERE b.selected_rider_user_id = u.id AND b.id <> ?
                   AND b.booking_status IN ('" . implode("','", RIDER_ACTIVE_BOOKING_STATUSES) . "')
               ) AS active_order_count
        FROM users u
        INNER JOIN rider_profiles rp ON rp.user_id = u.id
        WHERE u.role = 'rider' AND u.status = 'active' AND rp.kyc_status = 'approved'
          $vehicleTypeSql
        HAVING active_order_count < " . RIDER_MAX_CONCURRENT_ORDERS . "
        ORDER BY rp.rating DESC, u.full_name ASC
        LIMIT 100
    ");
    $stmt->execute($bookingVehicleType !== null ? [$selectedBookingId, $bookingVehicleType] : [$selectedBookingId]);
    $eligibleRiders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // A booking that already has an agreed cost keeps that price no matter which rider is
    // picked (see the assign_rider handler above), so there's nothing to look up here - a
    // per-rider "suggested fee" would be misleading busywork for a reassignment. Only
    // bookings from before transport type was moved up front can still be unpriced here.
    if ($eligibleRiders && $selectedBooking['agreed_cost'] === null) {
        try {
            $deliveryDistanceKm = pricing_distance_km(
                $pickupLat,
                $pickupLng,
                (float) $selectedBooking['delivery_latitude'],
                (float) $selectedBooking['delivery_longitude']
            );
            foreach ($eligibleRiders as &$candidate) {
                $candidate['suggested_fee'] = calculate_delivery_price($pdo, $deliveryDistanceKm, (string) $candidate['vehicle_type'])['total'];
            }
            unset($candidate);
        } catch (NoRouteFoundException $e) {
            $noRouteFound = true;
        } catch (RuntimeException $e) {
            $pricingUnavailable = true;
        }
    }
}

$where = ['1=1'];
$params = [];

if ($bookingStatusFilter !== '') {
    $where[] = 'b.booking_status = ?';
    $params[] = $bookingStatusFilter;
}
if ($paymentStatusFilter !== '') {
    $where[] = 'b.payment_status = ?';
    $params[] = $paymentStatusFilter;
}
if ($search !== '') {
    $where[] = '(b.booking_code LIKE ? OR su.full_name LIKE ? OR ru.full_name LIKE ? OR b.recipient_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($dateFrom !== '') {
    $where[] = 'b.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'b.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM bookings b
    INNER JOIN users su ON su.id = b.sender_user_id
    LEFT JOIN users ru ON ru.id = b.selected_rider_user_id
    WHERE $whereSql
");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT b.id, b.booking_code, b.booking_status, b.payment_status, b.agreed_cost, b.created_at,
        su.full_name AS sender_full_name, ru.full_name AS rider_full_name
    FROM bookings b
    INNER JOIN users su ON su.id = b.sender_user_id
    LEFT JOIN users ru ON ru.id = b.selected_rider_user_id
    WHERE $whereSql
    ORDER BY b.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$bookingsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

function admin_bookings_query_string(array $overrides = []): string {
    $params = array_merge([
        'booking_status' => $_GET['booking_status'] ?? '',
        'payment_status' => $_GET['payment_status'] ?? '',
        'q' => $_GET['q'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'page' => $_GET['page'] ?? '1',
    ], $overrides);
    return http_build_query(array_filter($params, static fn($v) => $v !== ''));
}

function admin_booking_status_badge_class(string $status): string {
    return match ($status) {
        'delivered' => 'bg-success',
        'cancelled' => 'bg-dark border border-secondary',
        'draft' => 'bg-secondary',
        default => 'bg-info text-dark',
    };
}

function admin_payment_status_badge_class(string $status): string {
    return match ($status) {
        'paid' => 'bg-success',
        'failed' => 'bg-danger',
        'refunded' => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('admin.bookings_heading')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .form-control,.form-select{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .booking-row{padding:.75rem 0;border-bottom:1px solid rgba(15,42,68,.08)}
        .booking-row:last-child{border-bottom:none}
        .timeline-row{padding:.5rem 0;border-bottom:1px solid rgba(15,42,68,.06);font-size:.85rem}
        .timeline-row:last-child{border-bottom:none}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/transactions.php')) ?>"><?= e(t('admin.nav_transactions')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/bookings.php')) ?>"><?= e(t('admin.nav_bookings')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/riders.php')) ?>"><?= e(t('admin.nav_riders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/complaints.php')) ?>"><?= e(t('admin.nav_complaints')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/users.php')) ?>"><?= e(t('admin.nav_users')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/logs.php')) ?>"><?= e(t('admin.nav_logs')) ?></a>
            <?php if (($user['role'] ?? '') === 'super_admin'): ?>
                <a class="nav-link" href="<?= e(url_path('admin/pricing.php')) ?>"><?= e(t('admin.nav_pricing')) ?></a>
            <?php endif; ?>
            <a class="nav-link" href="<?= e(url_path('admin/profile.php')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('admin.bookings_heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <?php if ($selectedBooking): ?>
        <div class="cardx p-4 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="h5 fw-bold mb-1"><?= e((string) $selectedBooking['booking_code']) ?></h2>
                    <span class="badge <?= e(admin_booking_status_badge_class((string) $selectedBooking['booking_status'])) ?>"><?= e(booking_status_label((string) $selectedBooking['booking_status'])) ?></span>
                    <span class="badge <?= e(admin_payment_status_badge_class((string) $selectedBooking['payment_status'])) ?>"><?= e(booking_status_label((string) $selectedBooking['payment_status'])) ?></span>
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url_path('admin/bookings.php')) ?>"><?= e(t('common.back')) ?></a>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('booking.recipient_label')) ?></div>
                    <div><?= e((string) $selectedBooking['recipient_name']) ?> &middot; <?= e((string) $selectedBooking['recipient_phone']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('admin.sender_label')) ?></div>
                    <div><?= e((string) $selectedBooking['sender_full_name']) ?> &middot; <?= e((string) $selectedBooking['sender_email']) ?> &middot; <?= e((string) $selectedBooking['sender_phone']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('booking.pickup_label')) ?></div>
                    <div><?= e((string) $selectedBooking['pickup_address']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('booking.delivery_label')) ?></div>
                    <div><?= e((string) $selectedBooking['delivery_address']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('booking.rider_label')) ?></div>
                    <div><?= !empty($selectedBooking['rider_full_name']) ? e((string) $selectedBooking['rider_full_name']) . ' &middot; ' . e((string) $selectedBooking['rider_phone']) : e(t('booking.not_assigned_yet')) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('booking.package_label')) ?></div>
                    <div><?= e((string) $selectedBooking['item_name']) ?> &middot; <?= e(complaint_category_label((string) $selectedBooking['item_category'])) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('admin.amount_label')) ?></div>
                    <div class="fw-bold">₦<?= number_format((float) $selectedBooking['agreed_cost'], 2) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-soft"><?= e(t('admin.requested_at_label')) ?></div>
                    <div><?= e((string) $selectedBooking['created_at']) ?></div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap mb-4">
                <?php if (!in_array($selectedBooking['booking_status'], ['delivered', 'cancelled'], true)): ?>
                    <form method="post" onsubmit="return confirm('<?= e(t('admin.confirm_force_cancel')) ?>');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="force_cancel">
                        <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking['id'] ?>">
                        <input type="text" name="reason" class="form-control form-control-sm d-inline-block mb-2" style="width:220px" placeholder="<?= e(t('admin.rejection_note_placeholder')) ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(t('admin.force_cancel_booking')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if (($selectedBooking['payment_status'] ?? '') === 'paid'): ?>
                    <form method="post" onsubmit="return confirm('<?= e(t('admin.confirm_refund')) ?>');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="refund">
                        <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking['id'] ?>">
                        <button class="btn btn-sm btn-outline-warning" type="submit"><?= e(t('admin.issue_refund')) ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (in_array($selectedBooking['booking_status'], ADMIN_ASSIGNABLE_BOOKING_STATUSES, true)): ?>
                <div class="cardx p-3 mb-4">
                    <h3 class="h6 fw-bold mb-2"><?= e(empty($selectedBooking['rider_full_name']) ? t('admin.assign_rider_heading') : t('admin.reassign_rider_heading')) ?></h3>
                    <?php if ($selectedBooking['pickup_latitude'] === null || $selectedBooking['delivery_latitude'] === null): ?>
                        <div class="text-soft small"><?= e(t('admin.booking_missing_coordinates')) ?></div>
                    <?php elseif (empty($eligibleRiders)): ?>
                        <div class="text-soft small"><?= e(t('admin.no_eligible_riders')) ?></div>
                    <?php else: ?>
                        <?php if ($selectedBooking['agreed_cost'] !== null): ?>
                            <div class="text-soft small mb-2"><?= e(t('admin.price_stays_note')) ?> ₦<?= number_format((float) $selectedBooking['agreed_cost'], 2) ?></div>
                        <?php elseif ($noRouteFound || $pricingUnavailable): ?>
                            <div class="text-soft small mb-2"><?= e($noRouteFound ? t('admin.no_route_found') : t('admin.pricing_unavailable')) ?> <?= e(t('admin.manual_price_hint')) ?></div>
                        <?php endif; ?>
                        <form method="post" class="row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="assign_rider">
                            <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking['id'] ?>">
                            <div class="col-md-<?= ($noRouteFound || $pricingUnavailable) && $selectedBooking['agreed_cost'] === null ? '5' : '8' ?>">
                                <select class="form-select" name="rider_user_id" required>
                                    <option value=""><?= e(t('admin.select_rider_placeholder')) ?></option>
                                    <?php foreach ($eligibleRiders as $candidate): ?>
                                        <option value="<?= (int) $candidate['id'] ?>">
                                            <?= e((string) $candidate['full_name']) ?> &middot; <?= e(t('vehicle.' . (string) $candidate['vehicle_type'])) ?>
                                            <?php if ($candidate['rating'] !== null): ?>
                                                &middot; <?= number_format((float) $candidate['rating'], 1) ?> &#9733;
                                            <?php endif; ?>
                                            &middot; <?= (int) $candidate['active_order_count'] ?>/<?= RIDER_MAX_CONCURRENT_ORDERS ?> <?= e(t('admin.active_orders_suffix')) ?>
                                            <?php if (isset($candidate['suggested_fee'])): ?>
                                                &middot; ₦<?= number_format((float) $candidate['suggested_fee'], 2) ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (($noRouteFound || $pricingUnavailable) && $selectedBooking['agreed_cost'] === null): ?>
                                <div class="col-md-3">
                                    <input class="form-control" type="number" step="0.01" min="0.01" name="manual_price" placeholder="<?= e(t('admin.manual_price_placeholder')) ?>" required>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-4">
                                <button class="btn btn-primary fw-bold w-100" type="submit"><?= e(empty($selectedBooking['rider_full_name']) ? t('admin.assign_rider_button') : t('admin.reassign_rider_button')) ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h3 class="h6 fw-bold mb-2"><?= e(t('admin.booking_timeline_heading')) ?></h3>
            <?php if (empty($timeline)): ?>
                <div class="text-soft small"><?= e(t('admin.no_logs_found')) ?></div>
            <?php else: ?>
                <?php foreach ($timeline as $entry): ?>
                    <div class="timeline-row">
                        <span class="text-soft"><?= e((string) $entry['created_at']) ?></span>
                        &middot; <?= e((string) $entry['description']) ?>
                        <?php if (!empty($entry['actor_name'])): ?>
                            <span class="text-soft">(<?= e((string) $entry['actor_name']) ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="cardx p-4 mb-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small"><?= e(t('admin.logs_search_label')) ?></label>
                <input class="form-control" type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('admin.bookings_search_placeholder')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?= e(t('admin.booking_status_label')) ?></label>
                <select class="form-select" name="booking_status">
                    <option value=""><?= e(t('admin.all_targets')) ?></option>
                    <?php foreach (['draft','submitted','matched','accepted','arrived_at_pickup','package_received','in_transit','delivered','cancelled'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= $bookingStatusFilter === $st ? 'selected' : '' ?>><?= e(booking_status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?= e(t('admin.payment_status_label')) ?></label>
                <select class="form-select" name="payment_status">
                    <option value=""><?= e(t('admin.all_targets')) ?></option>
                    <?php foreach (['unpaid','pending','paid','failed','refunded'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= $paymentStatusFilter === $st ? 'selected' : '' ?>><?= e(booking_status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?= e(t('admin.logs_date_from_label')) ?></label>
                <input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?= e(t('admin.logs_date_to_label')) ?></label>
                <input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary w-100 fw-bold" type="submit"><?= e(t('common.submit')) ?></button>
            </div>
        </form>
    </div>

    <div class="cardx p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="small text-soft"><?= e(t('admin.logs_total_prefix')) ?> <?= (int) $totalCount ?></div>
            <div class="small text-soft"><?= e(t('admin.logs_page_prefix')) ?> <?= (int) $page ?> / <?= (int) $totalPages ?></div>
        </div>

        <?php if (empty($bookingsList)): ?>
            <div class="text-soft"><?= e(t('admin.no_bookings_found')) ?></div>
        <?php else: ?>
            <?php foreach ($bookingsList as $b): ?>
                <a class="booking-row d-block text-decoration-none text-reset" href="<?= e(url_path('admin/bookings.php?booking_id=' . $b['id'])) ?>">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <strong><?= e((string) $b['booking_code']) ?></strong>
                            <span class="badge <?= e(admin_booking_status_badge_class((string) $b['booking_status'])) ?> ms-2"><?= e(booking_status_label((string) $b['booking_status'])) ?></span>
                            <span class="badge <?= e(admin_payment_status_badge_class((string) $b['payment_status'])) ?>"><?= e(booking_status_label((string) $b['payment_status'])) ?></span>
                        </div>
                        <div class="fw-bold">₦<?= number_format((float) $b['agreed_cost'], 2) ?></div>
                    </div>
                    <div class="small text-soft mt-1">
                        <?= e(t('admin.sender_label')) ?>: <?= e((string) $b['sender_full_name']) ?>
                        <?php if (!empty($b['rider_full_name'])): ?> &middot; <?= e(t('booking.rider_label')) ?> <?= e((string) $b['rider_full_name']) ?><?php endif; ?>
                        &middot; <?= e((string) $b['created_at']) ?>
                    </div>
                </a>
            <?php endforeach; ?>

            <div class="d-flex justify-content-between mt-3">
                <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= e(admin_bookings_query_string(['page' => (string) max(1, $page - 1)])) ?>"><?= e(t('common.back')) ?></a>
                <a class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= e(admin_bookings_query_string(['page' => (string) min($totalPages, $page + 1)])) ?>"><?= e(t('admin.logs_next_page')) ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
