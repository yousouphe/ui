<?php
require_once __DIR__ . '/config/functions.php';
require_role(['sender', 'admin']);
require_once __DIR__ . '/config/db.php';

$user = current_user();

$senderBookings = load_sender_bookings($pdo, (int) $user['id']);
$activeBookings = $senderBookings['active'];
$pendingBookings = $senderBookings['pending'];
$unpaidBookings = $senderBookings['unpaid'];
$cancelledBookings = $senderBookings['cancelled'];
$historyBookings = $senderBookings['history'];

$historyEntries = array_merge(
    array_map(fn($b) => $b + ['history_kind' => 'completed'], $historyBookings),
    array_map(fn($b) => $b + ['history_kind' => 'cancelled'], $cancelledBookings)
);
usort($historyEntries, fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);

function order_card(array $b, string $badgeClass, string $badgeText, ?string $extraLine = null): string {
    $html = '<a href="' . e(url_path('bookings/index.php?booking_id=' . (int) $b['id'])) . '" class="text-decoration-none order-select-link">';
    $html .= '<div class="cardx p-3 order-card">';
    $html .= '<div class="d-flex justify-content-between align-items-start">';
    $html .= '<div><div class="fw-bold">' . e($b['booking_code']) . '</div><div class="small text-soft">' . e($b['item_name']) . '</div></div>';
    $html .= '<span class="badge ' . $badgeClass . '">' . e($badgeText) . '</span>';
    $html .= '</div>';
    $html .= '<div class="small text-soft mt-2">' . e($b['pickup_address']) . '</div>';
    $html .= '<div class="small text-soft">to ' . e($b['delivery_address']) . '</div>';
    if ($extraLine !== null) {
        $html .= '<div class="small mt-2 text-info">' . $extraLine . '</div>';
    }
    if (($b['booking_status'] ?? '') === 'cancelled' && !empty($b['cancellation_reason'])) {
        $html .= '<div class="small mt-2 text-danger">Reason: ' . e($b['cancellation_reason']) . '</div>';
    }
    $html .= '</div></a>';
    return $html;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My Orders | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
        .navx{background:rgba(8,17,33,.88);border-bottom:1px solid rgba(255,255,255,.08)}
        .cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#9fb0d6}
        .form-control{background:#0b1430;color:#eef4ff;border-color:rgba(255,255,255,.1)}
        .form-control:focus{background:#0b1430;color:#eef4ff;border-color:#6ea8fe;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
        .order-card{cursor:pointer;transition:.2s ease}
        .order-card:hover{transform:translateY(-2px);border-color:rgba(110,168,254,.4)}
        .badge-soft{background:rgba(56,189,248,.12);color:#9ddcff;border:1px solid rgba(56,189,248,.3)}
        .stat-chip{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#eef4ff;cursor:pointer;transition:.15s ease}
        .stat-chip:hover{border-color:rgba(56,189,248,.4);background:rgba(56,189,248,.08)}
        .stat-chip-count{font-weight:800;font-size:1.05rem;color:#38bdf8}
        .stat-chip-label{font-size:.82rem;color:#9fb0d6}
        .order-search-wrap{position:relative;max-width:360px}
        .order-search-wrap input{padding-left:2.25rem}
        .order-search-wrap i{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:#9fb0d6}
        .history-filter-row{display:flex;gap:8px;margin-bottom:1rem}
        .history-filter-chip{padding:6px 14px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);color:#9fb0d6;font-size:.82rem;cursor:pointer}
        .history-filter-chip.active{background:rgba(56,189,248,.16);border-color:#38bdf8;color:#eef4ff}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('bookings/index.php')) ?>">SwiftDrop</a>
        <div class="navbar-nav ms-auto flex-row gap-3">
            <a class="nav-link" href="<?= e(url_path('bookings/index.php')) ?>"><i class="fa-solid fa-house me-1"></i>Sender Hub</a>
            <a class="nav-link" href="<?= e(url_path('bookings/index.php?new=1')) ?>"><i class="fa-solid fa-plus me-1"></i>New Order</a>
            <a class="nav-link" href="<?= e(url_path('logout.php')) ?>">Logout</a>
        </div>
    </div>
</nav>

<div class="container py-5" id="orders-page">
    <h1 class="h3 fw-bold mb-4">My Orders</h1>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <button type="button" class="stat-chip" data-goto-tab="active-orders">
            <span class="stat-chip-count"><?= count($activeBookings) ?></span>
            <span class="stat-chip-label">Active</span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="active-orders">
            <span class="stat-chip-count"><?= count($pendingBookings) ?></span>
            <span class="stat-chip-label">Pending Match</span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="unpaid-orders">
            <span class="stat-chip-count"><?= count($unpaidBookings) ?></span>
            <span class="stat-chip-label">Unpaid</span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="history-orders" data-history-filter="completed">
            <span class="stat-chip-count"><?= count($historyBookings) ?></span>
            <span class="stat-chip-label">Completed</span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="history-orders" data-history-filter="cancelled">
            <span class="stat-chip-count"><?= count($cancelledBookings) ?></span>
            <span class="stat-chip-label">Cancelled</span>
        </button>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <ul class="nav nav-tabs" id="hubTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#active-orders" type="button">Active <span class="badge badge-soft ms-1"><?= count($activeBookings) ?></span></button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#unpaid-orders" type="button">Unpaid <span class="badge badge-soft ms-1"><?= count($unpaidBookings) ?></span></button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#history-orders" type="button">History <span class="badge badge-soft ms-1"><?= count($historyBookings) + count($cancelledBookings) ?></span></button>
            </li>
        </ul>
        <div class="order-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" class="form-control form-control-sm" id="order-search" placeholder="Search by code, item, or recipient...">
        </div>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="active-orders">
            <?php if (empty($activeBookings)): ?>
                <div class="cardx p-5 text-center text-soft">No active orders yet.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($activeBookings as $b): ?>
                        <div class="col-md-6">
                            <?= order_card($b, 'badge-soft', (string) $b['booking_status'], !empty($b['rider_name']) ? 'Rider: ' . e($b['rider_name']) : 'Awaiting rider selection') ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="unpaid-orders">
            <?php if (empty($unpaidBookings)): ?>
                <div class="cardx p-5 text-center text-soft">No unpaid orders.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($unpaidBookings as $b): ?>
                        <div class="col-md-6">
                            <?= order_card($b, 'bg-warning text-dark', (string) ($b['payment_status'] ?? 'unpaid'), 'Amount: &#8358;' . number_format((float) ($b['agreed_cost'] ?? 0), 2)) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="history-orders">
            <?php if (!empty($historyEntries)): ?>
                <div class="history-filter-row" id="history-filter-row">
                    <button type="button" class="history-filter-chip active" data-history-kind="all">All</button>
                    <button type="button" class="history-filter-chip" data-history-kind="completed">Completed</button>
                    <button type="button" class="history-filter-chip" data-history-kind="cancelled">Cancelled</button>
                </div>
                <div class="row g-3">
                    <?php foreach ($historyEntries as $b): ?>
                        <div class="col-md-6" data-history-kind="<?= e($b['history_kind']) ?>">
                            <?= order_card($b, 'badge-soft', (string) $b['booking_status'], 'Payment: ' . e($b['payment_status'] ?? 'unpaid')) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="cardx p-5 text-center text-soft">No completed or cancelled bookings yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('orders-page');

    root.querySelectorAll('.stat-chip').forEach(chip => {
        chip.addEventListener('click', function () {
            const tabButton = root.querySelector(`#hubTabs button[data-bs-target="#${this.dataset.gotoTab}"]`);
            if (tabButton && window.bootstrap) {
                window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
            }
            const historyFilter = this.dataset.historyFilter;
            if (historyFilter) {
                const filterChip = root.querySelector(`.history-filter-chip[data-history-kind="${historyFilter}"]`);
                if (filterChip) filterChip.click();
            }
        });
    });

    root.querySelectorAll('.history-filter-chip').forEach(chip => {
        chip.addEventListener('click', function () {
            root.querySelectorAll('.history-filter-chip').forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            const kind = this.dataset.historyKind;
            root.querySelectorAll('#history-orders [data-history-kind]').forEach(card => {
                if (card.classList.contains('history-filter-chip')) return;
                const matches = kind === 'all' || card.dataset.historyKind === kind;
                card.style.display = matches ? '' : 'none';
            });
        });
    });

    const orderSearchInput = root.querySelector('#order-search');
    orderSearchInput?.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        root.querySelectorAll('.order-select-link').forEach(link => {
            const matches = query === '' || link.textContent.toLowerCase().includes(query);
            link.style.display = matches ? '' : 'none';
        });
    });
});
</script>
</body>
</html>
