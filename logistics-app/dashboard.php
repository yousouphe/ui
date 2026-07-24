<?php
require_once __DIR__ . '/config/functions.php';
require_role(['sender']);
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
    $html = '<a href="' . e(url_path('bookings/?booking_id=' . (int) $b['id'])) . '" class="text-decoration-none order-select-link">';
    $html .= '<div class="cardx p-3 order-card">';
    $html .= '<div class="d-flex justify-content-between align-items-start">';
    $html .= '<div><div class="fw-bold">' . e($b['booking_code']) . '</div><div class="small text-soft">' . e($b['item_name']) . '</div></div>';
    $html .= '<span class="badge ' . $badgeClass . '">' . e($badgeText) . '</span>';
    $html .= '</div>';
    $html .= '<div class="small text-soft mt-2">' . e($b['pickup_address']) . '</div>';
    $html .= '<div class="small text-soft">' . e(t('dashboard.to_prefix')) . ' ' . e($b['delivery_address']) . '</div>';
    if ($extraLine !== null) {
        $html .= '<div class="small mt-2 text-info">' . $extraLine . '</div>';
    }
    if (($b['booking_status'] ?? '') === 'cancelled' && !empty($b['cancellation_reason'])) {
        $html .= '<div class="small mt-2 text-danger">' . e(t('dashboard.reason_prefix')) . ' ' . e($b['cancellation_reason']) . '</div>';
    }
    $html .= '</div></a>';
    return $html;
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e(t('dashboard.title')) ?></title>
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
        .order-card{cursor:pointer;transition:.2s ease}
        .order-card:hover{transform:translateY(-2px);border-color:rgba(110,168,254,.4)}
        .badge-soft{background:rgba(56,189,248,.12);color:#0369a1;border:1px solid rgba(56,189,248,.3)}
        .stat-chip{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:999px;background:rgba(15,42,68,.06);border:1px solid rgba(15,42,68,.10);color:#0f2c44;cursor:pointer;transition:.15s ease}
        .stat-chip:hover{border-color:rgba(56,189,248,.4);background:rgba(56,189,248,.08)}
        .stat-chip-count{font-weight:800;font-size:1.05rem;color:#0284c7}
        .stat-chip-label{font-size:.82rem;color:#5c7a91}
        .order-search-wrap{position:relative;max-width:360px}
        .order-search-wrap input{padding-left:2.25rem}
        .order-search-wrap i{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:#5c7a91}
        .history-filter-row{display:flex;gap:8px;margin-bottom:1rem}
        .history-filter-chip{padding:6px 14px;border-radius:999px;background:rgba(15,42,68,.06);border:1px solid rgba(15,42,68,.10);color:#5c7a91;font-size:.82rem;cursor:pointer}
        .history-filter-chip.active{background:rgba(56,189,248,.16);border-color:#38bdf8;color:#0f2c44}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/config/pwa.php'; pwa_boot_tags(); ?>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('bookings/')) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('bookings/')) ?>"><i class="fa-solid fa-house me-1"></i><?= e(t('dashboard.sender_hub')) ?></a>
            <a class="nav-link" href="<?= e(url_path('bookings/?new=1')) ?>"><i class="fa-solid fa-plus me-1"></i><?= e(t('nav.new_order')) ?></a>
            <a class="nav-link" href="<?= e(url_path('bookings/complaints.php')) ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= e(t('complaint.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('transactions.php')) ?>"><i class="fa-solid fa-receipt me-1"></i><?= e(t('nav.transactions')) ?></a>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
            <div class="small">
                <a href="<?= e(url_path('set_locale?locale=en&redirect=dashboard')) ?>" class="<?= current_locale() === 'en' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">EN</a>
                &middot;
                <a href="<?= e(url_path('set_locale?locale=ha&redirect=dashboard')) ?>" class="<?= current_locale() === 'ha' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">HA</a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5" id="orders-page">
    <h1 class="h3 fw-bold mb-4"><?= e(t('dashboard.heading')) ?></h1>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <button type="button" class="stat-chip" data-goto-tab="active-orders">
            <span class="stat-chip-count"><?= count($activeBookings) ?></span>
            <span class="stat-chip-label"><?= e(t('dashboard.stat.active')) ?></span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="active-orders">
            <span class="stat-chip-count"><?= count($pendingBookings) ?></span>
            <span class="stat-chip-label"><?= e(t('dashboard.stat.pending_match')) ?></span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="unpaid-orders">
            <span class="stat-chip-count"><?= count($unpaidBookings) ?></span>
            <span class="stat-chip-label"><?= e(t('dashboard.stat.unpaid')) ?></span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="history-orders" data-history-filter="completed">
            <span class="stat-chip-count"><?= count($historyBookings) ?></span>
            <span class="stat-chip-label"><?= e(t('dashboard.stat.completed')) ?></span>
        </button>
        <button type="button" class="stat-chip" data-goto-tab="history-orders" data-history-filter="cancelled">
            <span class="stat-chip-count"><?= count($cancelledBookings) ?></span>
            <span class="stat-chip-label"><?= e(t('dashboard.stat.cancelled')) ?></span>
        </button>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <ul class="nav nav-tabs" id="hubTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#active-orders" type="button"><?= e(t('dashboard.tab.active')) ?> <span class="badge badge-soft ms-1"><?= count($activeBookings) ?></span></button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#unpaid-orders" type="button"><?= e(t('dashboard.tab.unpaid')) ?> <span class="badge badge-soft ms-1"><?= count($unpaidBookings) ?></span></button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#history-orders" type="button"><?= e(t('dashboard.tab.history')) ?> <span class="badge badge-soft ms-1"><?= count($historyBookings) + count($cancelledBookings) ?></span></button>
            </li>
        </ul>
        <div class="order-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" class="form-control form-control-sm" id="order-search" placeholder="<?= e(t('dashboard.search_placeholder')) ?>">
        </div>
    </div>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="active-orders">
            <?php if (empty($activeBookings)): ?>
                <div class="cardx p-5 text-center text-soft"><?= e(t('dashboard.empty.active')) ?></div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($activeBookings as $b): ?>
                        <div class="col-md-6">
                            <?= order_card($b, 'badge-soft', booking_status_label((string) $b['booking_status']), !empty($b['rider_name']) ? e(t('dashboard.rider_prefix')) . ' ' . e($b['rider_name']) : e(t('dashboard.awaiting_rider'))) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="unpaid-orders">
            <?php if (empty($unpaidBookings)): ?>
                <div class="cardx p-5 text-center text-soft"><?= e(t('dashboard.empty.unpaid')) ?></div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($unpaidBookings as $b): ?>
                        <div class="col-md-6">
                            <?= order_card($b, 'bg-warning text-dark', booking_status_label((string) ($b['payment_status'] ?? 'unpaid')), e(t('dashboard.amount_prefix')) . ' &#8358;' . number_format((float) ($b['agreed_cost'] ?? 0), 2)) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="history-orders">
            <?php if (!empty($historyEntries)): ?>
                <div class="history-filter-row" id="history-filter-row">
                    <button type="button" class="history-filter-chip active" data-history-kind="all"><?= e(t('dashboard.filter.all')) ?></button>
                    <button type="button" class="history-filter-chip" data-history-kind="completed"><?= e(t('dashboard.stat.completed')) ?></button>
                    <button type="button" class="history-filter-chip" data-history-kind="cancelled"><?= e(t('dashboard.stat.cancelled')) ?></button>
                </div>
                <div class="row g-3">
                    <?php foreach ($historyEntries as $b): ?>
                        <div class="col-md-6" data-history-kind="<?= e($b['history_kind']) ?>">
                            <?= order_card($b, 'badge-soft', booking_status_label((string) $b['booking_status']), e(t('dashboard.payment_prefix')) . ' ' . e($b['payment_status'] ?? 'unpaid')) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="cardx p-5 text-center text-soft"><?= e(t('dashboard.empty.history')) ?></div>
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
