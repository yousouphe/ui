<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();

$eventType = trim((string) ($_GET['event_type'] ?? ''));
$targetType = trim((string) ($_GET['target_type'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ['1=1'];
$params = [];

if ($eventType !== '') {
    $where[] = 'el.event_type = ?';
    $params[] = $eventType;
}
if ($targetType !== '') {
    $where[] = 'el.target_type = ?';
    $params[] = $targetType;
}
if ($search !== '') {
    $where[] = 'el.description LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($dateFrom !== '') {
    $where[] = 'el.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'el.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM event_logs el WHERE $whereSql");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT el.*, u.full_name AS actor_name
    FROM event_logs el
    LEFT JOIN users u ON u.id = el.actor_user_id
    WHERE $whereSql
    ORDER BY el.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eventTypesStmt = $pdo->query('SELECT DISTINCT event_type FROM event_logs ORDER BY event_type ASC');
$eventTypes = $eventTypesStmt->fetchAll(PDO::FETCH_COLUMN);

$targetTypesStmt = $pdo->query('SELECT DISTINCT target_type FROM event_logs WHERE target_type IS NOT NULL ORDER BY target_type ASC');
$targetTypes = $targetTypesStmt->fetchAll(PDO::FETCH_COLUMN);

function admin_log_badge_class(string $eventType): string {
    if (str_contains($eventType, 'failed')) {
        return 'bg-danger';
    }
    if (str_contains($eventType, 'rejected') || str_contains($eventType, 'suspended')) {
        return 'bg-warning text-dark';
    }
    if ($eventType === 'email') {
        return 'bg-secondary';
    }
    return 'bg-info text-dark';
}

function admin_logs_query_string(array $overrides = []): string {
    $params = array_merge([
        'event_type' => $_GET['event_type'] ?? '',
        'target_type' => $_GET['target_type'] ?? '',
        'q' => $_GET['q'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'page' => $_GET['page'] ?? '1',
    ], $overrides);
    return http_build_query(array_filter($params, static fn($v) => $v !== ''));
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('admin.logs_heading')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .form-control,.form-select{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .log-row{padding:.75rem 0;border-bottom:1px solid rgba(15,42,68,.08)}
        .log-row:last-child{border-bottom:none}
        .log-meta{background:rgba(15,42,68,.04);border-radius:.5rem;padding:.5rem .75rem;font-family:monospace;font-size:.75rem;white-space:pre-wrap;word-break:break-word}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/bookings.php')) ?>"><?= e(t('admin.nav_bookings')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/riders.php')) ?>"><?= e(t('admin.nav_riders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/complaints.php')) ?>"><?= e(t('admin.nav_complaints')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/users.php')) ?>"><?= e(t('admin.nav_users')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/logs.php')) ?>"><?= e(t('admin.nav_logs')) ?></a>
            <?php if (($user['role'] ?? '') === 'super_admin'): ?>
                <a class="nav-link" href="<?= e(url_path('admin/pricing.php')) ?>"><?= e(t('admin.nav_pricing')) ?></a>
            <?php endif; ?>
            <a class="nav-link" href="<?= e(url_path('admin/profile.php')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('admin.logs_heading')) ?></h1>

    <div class="cardx p-4 mb-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small"><?= e(t('admin.logs_search_label')) ?></label>
                <input class="form-control" type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('admin.logs_search_placeholder')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?= e(t('admin.logs_event_type_label')) ?></label>
                <select class="form-select" name="event_type">
                    <option value=""><?= e(t('admin.all_events')) ?></option>
                    <?php foreach ($eventTypes as $et): ?>
                        <option value="<?= e((string) $et) ?>" <?= $eventType === $et ? 'selected' : '' ?>><?= e((string) $et) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?= e(t('admin.logs_target_type_label')) ?></label>
                <select class="form-select" name="target_type">
                    <option value=""><?= e(t('admin.all_targets')) ?></option>
                    <?php foreach ($targetTypes as $tt): ?>
                        <option value="<?= e((string) $tt) ?>" <?= $targetType === $tt ? 'selected' : '' ?>><?= e((string) $tt) ?></option>
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

        <?php if (empty($logs)): ?>
            <div class="text-soft"><?= e(t('admin.no_logs_found')) ?></div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="log-row">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <div>
                            <span class="badge <?= e(admin_log_badge_class((string) $log['event_type'])) ?>"><?= e((string) $log['event_type']) ?></span>
                            <span class="ms-2"><?= e((string) $log['description']) ?></span>
                        </div>
                        <div class="small text-soft"><?= e((string) $log['created_at']) ?></div>
                    </div>
                    <div class="small text-soft">
                        <?php if (!empty($log['actor_name'])): ?>
                            <?= e(t('admin.logs_actor_prefix')) ?> <?= e((string) $log['actor_name']) ?> (<?= e((string) $log['actor_role']) ?>)
                        <?php endif; ?>
                        <?php if (!empty($log['target_type'])): ?>
                            &middot; <?= e((string) $log['target_type']) ?>#<?= (int) $log['target_id'] ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($log['meta'])): ?>
                        <div class="log-meta mt-1"><?= e((string) $log['meta']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="d-flex justify-content-between mt-3">
                <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= e(admin_logs_query_string(['page' => (string) max(1, $page - 1)])) ?>"><?= e(t('common.back')) ?></a>
                <a class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= e(admin_logs_query_string(['page' => (string) min($totalPages, $page + 1)])) ?>"><?= e(t('admin.logs_next_page')) ?></a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
