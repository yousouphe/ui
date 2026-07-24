<?php
// Transaction history for a sender or rider (module19, spec §2/§3). Search, date/type/status
// filters, sorting, pagination, and export to CSV or print/PDF. Admins have their own richer view
// at admin/transactions.php.
require_once __DIR__ . '/config/functions.php';
require_auth();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/transactions.php';

$user = current_user();
if (in_array($user['role'], ['admin', 'super_admin'], true)) {
    redirect_to('admin/transactions.php');
}

$filters = [
    'range' => (string) ($_GET['range'] ?? 'all'),
    'from' => (string) ($_GET['from'] ?? ''),
    'to' => (string) ($_GET['to'] ?? ''),
    'type' => (string) ($_GET['type'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'q' => (string) ($_GET['q'] ?? ''),
    'sort' => (string) ($_GET['sort'] ?? 'newest'),
];

$allRows = tx_rows_for_user($pdo, $user, $filters);
$summary = tx_summary($pdo, $user, $allRows);
$money = static fn($n): string => '₦' . number_format((float) $n, 2);
$walletId = 'AIKE-W-' . str_pad((string) $user['id'], 6, '0', STR_PAD_LEFT);

// CSV export of the filtered set (spec §3: export filtered results only).
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aike-transactions-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Transaction ID', 'Date', 'Type', 'Description', 'Order', 'Reference', 'Direction', 'Amount', 'Status']);
    foreach ($allRows as $r) {
        fputcsv($out, [$r['id'], $r['date'], $r['category'], $r['description'], $r['order_code'], $r['reference'], $r['direction'], number_format($r['amount'], 2, '.', ''), $r['status']]);
    }
    fclose($out);
    exit;
}

// Pagination for the on-screen table.
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = count($allRows);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$pageRows = array_slice($allRows, ($page - 1) * $perPage, $perPage);

$hub = $user['role'] === 'rider' ? 'rider/' : 'bookings/';
$typeOptions = $user['role'] === 'rider'
    ? ['' => 'all', 'ride_payment' => 'ride_payments', 'withdrawal' => 'withdrawals']
    : ['' => 'all', 'ride_payment' => 'ride_payments', 'refund' => 'refunded'];
$statusOptions = ['', 'successful', 'pending', 'failed', 'refunded'];
$rangeOptions = ['all', 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'custom'];
$sortOptions = ['newest', 'oldest', 'highest', 'lowest'];
$qs = static function (array $over) use ($filters, $page): string {
    return http_build_query(array_merge($filters, ['page' => $page], $over));
};
$statusBadge = static function (string $s): string {
    $map = ['successful' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'refunded' => 'info'];
    return '<span class="badge text-bg-' . ($map[$s] ?? 'secondary') . '">' . e(ucfirst($s)) . '</span>';
};
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e(t('tx.title')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.95);border:1px solid rgba(15,42,68,.10);border-radius:1rem;box-shadow:0 10px 30px rgba(0,0,0,.10)}
        .text-soft{color:#5c7a91}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem}
        .stat{background:#fff;border:1px solid rgba(15,42,68,.10);border-radius:.85rem;padding:.9rem 1.1rem}
        .stat .v{font-weight:800;font-size:1.15rem}
        .table thead th{color:#5c7a91;font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.03em}
        .print-head{display:none}
        @media print{
            body{background:#fff}
            .no-print{display:none !important}
            .print-head{display:block !important;margin-bottom:1rem}
            .cardx{box-shadow:none;border:none}
            .wordmark{font-weight:800;letter-spacing:2px;color:#0b6ec9;font-size:1.5rem}
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx no-print">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path($hub)) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path($hub)) ?>"><i class="fa-solid fa-house me-1"></i><?= e(t('nav.dashboard')) ?></a>
            <?php if ($user['role'] === 'rider'): ?><a class="nav-link" href="<?= e(url_path('rider/wallet')) ?>"><i class="fa-solid fa-wallet me-1"></i><?= e(t('wallet.nav_label')) ?></a><?php endif; ?>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <!-- Printable statement header -->
    <div class="print-head">
        <div class="d-flex justify-content-between align-items-start">
            <div><div class="wordmark">AIKE</div><div class="text-soft small"><?= e(t('tx.statement')) ?></div></div>
            <div class="text-end small">
                <div><strong><?= e($user['full_name']) ?></strong></div>
                <div class="text-soft"><?= e(t('admin.tx.wallet_id')) ?>: <?= e($walletId) ?></div>
                <div class="text-soft"><?= e(t('tx.generated')) ?>: <?= e(date('d M Y, H:i')) ?></div>
            </div>
        </div>
        <hr>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h3 fw-bold mb-0"><?= e(t('tx.title')) ?></h1>
        <div class="d-flex gap-2 no-print">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url_path('transactions.php?' . $qs(['export' => 'csv']))) ?>"><i class="fa-solid fa-file-csv me-1"></i><?= e(t('tx.export_csv')) ?></a>
            <button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i><?= e(t('tx.print')) ?></button>
            <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="fa-solid fa-file-pdf me-1"></i><?= e(t('tx.pdf')) ?></button>
        </div>
    </div>

    <!-- Summary tiles -->
    <div class="row g-2 mb-3">
        <?php if ($summary['has_balance']): ?>
        <div class="col-6 col-md-3"><div class="stat"><div class="text-soft small"><?= e(t('tx.opening')) ?></div><div class="v"><?= e($money($summary['opening'])) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="stat"><div class="text-soft small"><?= e(t('tx.closing')) ?></div><div class="v"><?= e($money($summary['closing'])) ?></div></div></div>
        <?php endif; ?>
        <div class="col-6 col-md-3"><div class="stat"><div class="text-soft small"><?= e(t('tx.total_credits')) ?></div><div class="v text-success">+<?= e($money($summary['credits'])) ?></div></div></div>
        <div class="col-6 col-md-3"><div class="stat"><div class="text-soft small"><?= e(t('tx.total_debits')) ?></div><div class="v text-danger">-<?= e($money($summary['debits'])) ?></div></div></div>
    </div>

    <!-- Filters -->
    <form method="get" class="cardx p-3 mb-3 no-print">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small text-soft mb-1"><?= e(t('tx.search')) ?></label>
                <input class="form-control form-control-sm" type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('tx.search_placeholder')) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-soft mb-1"><?= e(t('tx.period')) ?></label>
                <select class="form-select form-select-sm" name="range" onchange="this.form.submit()">
                    <?php foreach ($rangeOptions as $r): ?><option value="<?= e($r) ?>" <?= $filters['range'] === $r ? 'selected' : '' ?>><?= e(t('tx.range.' . $r)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-soft mb-1"><?= e(t('tx.type')) ?></label>
                <select class="form-select form-select-sm" name="type">
                    <?php foreach ($typeOptions as $val => $label): ?><option value="<?= e($val) ?>" <?= $filters['type'] === $val ? 'selected' : '' ?>><?= e(t('tx.type.' . $label)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-soft mb-1"><?= e(t('tx.status')) ?></label>
                <select class="form-select form-select-sm" name="status">
                    <?php foreach ($statusOptions as $s): ?><option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s === '' ? t('tx.status.all') : t('tx.status.' . $s)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-soft mb-1"><?= e(t('tx.sort')) ?></label>
                <select class="form-select form-select-sm" name="sort">
                    <?php foreach ($sortOptions as $s): ?><option value="<?= e($s) ?>" <?= $filters['sort'] === $s ? 'selected' : '' ?>><?= e(t('tx.sort.' . $s)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($filters['range'] === 'custom'): ?>
            <div class="col-6 col-md-2"><label class="form-label small text-soft mb-1"><?= e(t('tx.from')) ?></label><input class="form-control form-control-sm" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small text-soft mb-1"><?= e(t('tx.to')) ?></label><input class="form-control form-control-sm" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
            <?php endif; ?>
            <div class="col-12 col-md-2">
                <button class="btn btn-sm btn-primary w-100" type="submit"><i class="fa-solid fa-filter me-1"></i><?= e(t('tx.apply')) ?></button>
            </div>
        </div>
    </form>

    <!-- Table -->
    <div class="cardx p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th class="ps-3"><?= e(t('tx.col_date')) ?></th>
                <th><?= e(t('tx.col_desc')) ?></th>
                <th><?= e(t('tx.col_reference')) ?></th>
                <th><?= e(t('tx.col_status')) ?></th>
                <th class="text-end pe-3"><?= e(t('tx.col_amount')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($pageRows as $r): $credit = $r['amount'] >= 0; ?>
                <tr>
                    <td class="ps-3 text-soft small"><?= e(date('d M Y H:i', strtotime((string) $r['date']))) ?></td>
                    <td>
                        <div class="small fw-semibold"><?= e($r['description']) ?></div>
                        <div class="text-soft" style="font-size:.78rem"><?= e(t('tx.type.' . ($r['category'] === 'ride_payment' ? 'ride_payments' : ($r['category'] === 'withdrawal' ? 'withdrawals' : 'refunded')))) ?><?= $r['order_code'] ? ' · ' . e($r['order_code']) : '' ?></div>
                    </td>
                    <td class="mono small text-soft"><?= e($r['reference'] ?: '—') ?></td>
                    <td><?= $statusBadge((string) $r['status']) ?></td>
                    <td class="text-end pe-3 fw-bold <?= $credit ? 'text-success' : 'text-danger' ?>"><?= e(($credit ? '+' : '-') . $money(abs($r['amount']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$pageRows): ?><tr><td colspan="5" class="text-center text-soft py-4"><?= e(t('tx.none')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <nav class="mt-3 no-print d-flex justify-content-between align-items-center">
        <span class="text-soft small"><?= e(sprintf(t('tx.showing'), count($pageRows), $total)) ?></span>
        <div class="btn-group">
            <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(url_path('transactions.php?' . $qs(['page' => $page - 1]))) ?>">&laquo;</a>
            <span class="btn btn-sm btn-outline-secondary disabled"><?= $page ?> / <?= $pages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(url_path('transactions.php?' . $qs(['page' => $page + 1]))) ?>">&raquo;</a>
        </div>
    </nav>
    <?php endif; ?>
</div>
</body>
</html>
