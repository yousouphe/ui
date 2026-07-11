<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();

function badge_class(string $status): string {
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

// Riders only ever see their post-cut earnings (85% of what the sender pays), never the
// underlying full price - it's presented to them simply as "the price" for the job.
function sum_amount(array $rows): float {
    $total = 0.0;
    foreach ($rows as $row) {
        $total += rider_payout_amount((float) ($row['agreed_cost'] ?? $row['proposed_cost'] ?? 0));
    }
    return $total;
}

$stmt = $pdo->prepare('
    SELECT
        rr.id, rr.request_status, rr.proposed_cost,
        b.id AS booking_id, b.booking_code, b.pickup_address, b.delivery_address,
        b.item_name, b.booking_status, b.agreed_cost, b.payment_status,
        b.sender_user_id, u.full_name AS sender_name
    FROM rider_requests rr
    INNER JOIN bookings b ON b.id = rr.booking_id
    LEFT JOIN users u ON u.id = b.sender_user_id
    WHERE rr.rider_user_id = ?
    ORDER BY FIELD(rr.request_status, "pending","accepted","rejected"), rr.id DESC
');
$stmt->execute([$user['id']]);
$allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('
    SELECT b.*, s.full_name AS sender_name, s.phone AS sender_phone
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

$stmt = $pdo->prepare('
    SELECT b.id, b.booking_code, b.agreed_cost, b.payment_status, b.booking_status, b.updated_at, b.created_at, s.full_name AS sender_name
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

$totalPaidToday = sum_amount(array_filter($paidEarningRows, fn($r) => ($r['updated_at'] ?? $r['created_at'] ?? '') >= (new DateTime('today'))->format('Y-m-d H:i:s')));
$totalPaidWeek = sum_amount(array_filter($paidEarningRows, fn($r) => ($r['updated_at'] ?? $r['created_at'] ?? '') >= (new DateTime('monday this week'))->format('Y-m-d H:i:s')));
$totalPaidMonth = sum_amount(array_filter($paidEarningRows, fn($r) => ($r['updated_at'] ?? $r['created_at'] ?? '') >= (new DateTime('first day of this month'))->format('Y-m-d H:i:s')));
$totalPaidOverall = sum_amount($paidEarningRows);
$totalOutstanding = sum_amount($unpaidEarningRows);
$totalExpectedOverall = sum_amount($deliveredEarningRows);

$historyRows = array_filter($allRequests, fn($req) =>
    ($req['request_status'] ?? '') !== 'pending' || in_array(($req['booking_status'] ?? ''), ['delivered', 'cancelled'], true)
);
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e(t('rider.deliveries.heading')) ?> | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .form-control{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .form-control:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
        .summary-card{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1rem;padding:1rem}
        .stat-label{font-size:.8rem;color:#5c7a91}
        .money-big{font-size:1.4rem;font-weight:800}
        .mini-row{padding:.5rem 0;border-bottom:1px solid rgba(15,42,68,.08)}
        .req-card{background:#ffffff;border:1px solid rgba(15,42,68,.10);border-radius:1rem;padding:1rem;margin-bottom:.75rem}
        .price-tag{font-weight:800;color:#0284c7}
        .badge-soft{background:rgba(56,189,248,.12);color:#0369a1;border:1px solid rgba(56,189,248,.3)}
        .order-search-wrap{position:relative;max-width:320px}
        .order-search-wrap input{padding-left:2.25rem}
        .order-search-wrap i{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:#5c7a91}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('rider/')) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('rider/')) ?>"><i class="fa-solid fa-house me-1"></i><?= e(t('nav.dashboard')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/wallet')) ?>"><i class="fa-solid fa-wallet me-1"></i><?= e(t('wallet.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
            <div class="small">
                <a href="<?= e(url_path('set_locale?locale=en&redirect=rider/dashboard')) ?>" class="<?= current_locale() === 'en' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">EN</a>
                &middot;
                <a href="<?= e(url_path('set_locale?locale=ha&redirect=rider/dashboard')) ?>" class="<?= current_locale() === 'ha' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">HA</a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5" id="deliveries-page">
    <h1 class="h3 fw-bold mb-4"><?= e(t('rider.deliveries.heading')) ?></h1>

    <div class="row g-4 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="stat-label"><?= e(t('rider.deliveries.total_paid_today')) ?></div>
                <div class="money-big">&#8358;<?= number_format($totalPaidToday, 2) ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="stat-label"><?= e(t('rider.deliveries.total_paid_week')) ?></div>
                <div class="money-big">&#8358;<?= number_format($totalPaidWeek, 2) ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="stat-label"><?= e(t('rider.deliveries.total_paid_month')) ?></div>
                <div class="money-big">&#8358;<?= number_format($totalPaidMonth, 2) ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="stat-label"><?= e(t('rider.deliveries.total_paid_overall')) ?></div>
                <div class="money-big">&#8358;<?= number_format($totalPaidOverall, 2) ?></div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" id="deliveriesTabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#orders" type="button"><?= e(t('rider.deliveries.tab.orders')) ?></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#payments" type="button"><?= e(t('rider.deliveries.tab.payments')) ?></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#history" type="button"><?= e(t('rider.deliveries.tab.history')) ?></button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="orders">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="cardx p-4 h-100">
                        <h2 class="h5 fw-bold mb-3"><?= e(t('rider.deliveries.order_status_summary')) ?></h2>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(booking_status_label('matched')) ?></span><strong><?= count($matchedBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(booking_status_label('accepted')) ?></span><strong><?= count($acceptedBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(booking_status_label('arrived_at_pickup')) ?></span><strong><?= count($pickupBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(booking_status_label('package_received')) ?></span><strong><?= count($packageReceivedBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(booking_status_label('in_transit')) ?></span><strong><?= count($inTransitBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(booking_status_label('delivered')) ?></span><strong><?= count($deliveredBookings) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(booking_status_label('cancelled')) ?></span><strong><?= count($cancelledBookings) ?></strong></div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="cardx p-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <h2 class="h5 fw-bold mb-0"><?= e(t('rider.deliveries.all_assigned_orders')) ?></h2>
                            <?php if (!empty($allAssignedBookings)): ?>
                            <div class="order-search-wrap">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="search" class="form-control form-control-sm" id="assigned-orders-search" placeholder="<?= e(t('rider.deliveries.search_placeholder')) ?>">
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($allAssignedBookings)): ?>
                            <div class="text-soft"><?= e(t('rider.deliveries.no_assigned_orders')) ?></div>
                        <?php else: ?>
                            <div id="assigned-orders-list">
                            <?php foreach ($allAssignedBookings as $b): ?>
                                <div class="req-card p-3">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                        <div>
                                            <div class="fw-bold"><?= e($b['booking_code']) ?></div>
                                            <div class="small text-soft"><?= e($b['item_name'] ?? 'Package') ?></div>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <span class="badge <?= e(badge_class((string) ($b['booking_status'] ?? ''))) ?>"><?= e(booking_status_label((string) ($b['booking_status'] ?? 'unknown'))) ?></span>
                                            <span class="badge <?= e(badge_class((string) ($b['payment_status'] ?? 'pending'))) ?>"><?= e(booking_status_label((string) ($b['payment_status'] ?? 'pending'))) ?></span>
                                        </div>
                                    </div>
                                    <div class="small text-soft mb-1"><?= e(t('rider.sender_prefix')) ?> <?= e($b['sender_name'] ?? '') ?><?= !empty($b['sender_phone']) ? ' &middot; ' . e($b['sender_phone']) : '' ?></div>
                                    <div class="small text-soft mb-1"><?= e(t('booking.pickup_label')) ?> <?= e($b['pickup_address'] ?? '') ?></div>
                                    <div class="small text-soft mb-2"><?= e(t('booking.delivery_label')) ?> <?= e($b['delivery_address'] ?? '') ?></div>
                                    <div class="price-tag">&#8358;<?= number_format(rider_payout_amount((float) ($b['agreed_cost'] ?? 0)), 2) ?></div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="payments">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="cardx p-4 h-100">
                        <h2 class="h5 fw-bold mb-3"><?= e(t('rider.deliveries.payment_summary')) ?></h2>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(t('rider.deliveries.paid_deliveries')) ?></span><strong><?= count($paidEarningRows) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(t('rider.deliveries.unpaid_deliveries')) ?></span><strong><?= count($unpaidEarningRows) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(t('rider.deliveries.outstanding_amount')) ?></span><strong>&#8358;<?= number_format($totalOutstanding, 2) ?></strong></div>
                        <div class="mini-row d-flex justify-content-between"><span><?= e(t('rider.deliveries.total_expected')) ?></span><strong>&#8358;<?= number_format($totalExpectedOverall, 2) ?></strong></div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="cardx p-4">
                        <h2 class="h5 fw-bold mb-3"><?= e(t('rider.deliveries.earnings_details')) ?></h2>
                        <?php if (empty($deliveredEarningRows)): ?>
                            <div class="text-soft"><?= e(t('rider.deliveries.no_delivered_jobs')) ?></div>
                        <?php else: ?>
                            <?php foreach ($deliveredEarningRows as $row): ?>
                                <div class="req-card p-3">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <div class="fw-bold"><?= e($row['booking_code']) ?></div>
                                            <div class="small text-soft"><?= e($row['sender_name'] ?? '') ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="price-tag">&#8358;<?= number_format(rider_payout_amount((float) ($row['agreed_cost'] ?? 0)), 2) ?></div>
                                            <span class="badge <?= e(badge_class((string) ($row['payment_status'] ?? 'pending'))) ?>">
                                                <?= e(booking_status_label((string) ($row['payment_status'] ?? 'pending'))) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="small text-soft mt-2">
                                        <?= e(t('rider.deliveries.delivered_on_prefix')) ?> <?= e((string) ($row['updated_at'] ?? $row['created_at'] ?? '')) ?>
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
                <h2 class="h5 fw-bold mb-3"><?= e(t('rider.deliveries.request_order_history')) ?></h2>
                <?php if (empty($historyRows)): ?>
                    <div class="text-soft"><?= e(t('rider.deliveries.no_request_history')) ?></div>
                <?php else: ?>
                    <?php foreach ($historyRows as $req): ?>
                        <div class="req-card p-3">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <div>
                                    <div class="fw-bold"><?= e($req['booking_code']) ?></div>
                                    <div class="small text-soft"><?= e($req['item_name'] ?? '') ?></div>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <span class="badge <?= e(badge_class((string) ($req['request_status'] ?? 'pending'))) ?>"><?= e(booking_status_label((string) ($req['request_status'] ?? 'pending'))) ?></span>
                                    <span class="badge <?= e(badge_class((string) ($req['booking_status'] ?? ''))) ?>"><?= e(booking_status_label((string) ($req['booking_status'] ?? 'unknown'))) ?></span>
                                </div>
                            </div>
                            <div class="small text-soft mb-1"><?= e(t('booking.pickup_label')) ?> <?= e($req['pickup_address'] ?? '') ?></div>
                            <div class="small text-soft mb-1"><?= e(t('booking.delivery_label')) ?> <?= e($req['delivery_address'] ?? '') ?></div>
                            <div class="small text-soft"><?= e(t('rider.deliveries.offer_value_prefix')) ?> &#8358;<?= number_format(rider_payout_amount((float) ($req['proposed_cost'] ?? $req['agreed_cost'] ?? 0)), 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('assigned-orders-search')?.addEventListener('input', function () {
    const query = this.value.trim().toLowerCase();
    document.querySelectorAll('#assigned-orders-list > .req-card').forEach(card => {
        const matches = query === '' || card.textContent.toLowerCase().includes(query);
        card.style.display = matches ? '' : 'none';
    });
});
</script>
</body>
</html>
