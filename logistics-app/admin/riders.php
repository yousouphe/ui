<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $riderUserId = (int) ($_POST['rider_user_id'] ?? 0);
    $formAction = (string) ($_POST['form_action'] ?? '');

    $stmt = $pdo->prepare('SELECT rp.*, u.status AS account_status FROM rider_profiles rp INNER JOIN users u ON u.id = rp.user_id WHERE rp.user_id = ? LIMIT 1');
    $stmt->execute([$riderUserId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) {
        flash('error', t('admin.rider_not_found'));
        redirect_to('admin/riders.php');
    }

    if ($formAction === 'approve_kyc') {
        $stmt = $pdo->prepare('UPDATE rider_profiles SET kyc_status = "approved", kyc_note = NULL, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE user_id = ?');
        $stmt->execute([$user['id'], $riderUserId]);
        flash('success', t('admin.kyc_approved'));
        redirect_to('admin/riders.php');
    }

    if ($formAction === 'reject_kyc') {
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        $stmt = $pdo->prepare('UPDATE rider_profiles SET kyc_status = "rejected", kyc_note = ?, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE user_id = ?');
        $stmt->execute([$note !== '' ? $note : null, $user['id'], $riderUserId]);
        flash('success', t('admin.kyc_rejected'));
        redirect_to('admin/riders.php');
    }

    if ($formAction === 'suspend_rider') {
        $stmt = $pdo->prepare('UPDATE users SET status = "suspended" WHERE id = ?');
        $stmt->execute([$riderUserId]);
        $stmt = $pdo->prepare('UPDATE rider_profiles SET availability_status = "offline" WHERE user_id = ?');
        $stmt->execute([$riderUserId]);
        flash('success', t('admin.rider_suspended'));
        redirect_to('admin/riders.php');
    }

    if ($formAction === 'activate_rider') {
        $stmt = $pdo->prepare('UPDATE users SET status = "active" WHERE id = ?');
        $stmt->execute([$riderUserId]);
        flash('success', t('admin.rider_activated'));
        redirect_to('admin/riders.php');
    }
}

$stmt = $pdo->prepare("
    SELECT rp.*, u.full_name, u.email, u.phone, u.status AS account_status
    FROM rider_profiles rp
    INNER JOIN users u ON u.id = rp.user_id
    WHERE rp.kyc_status = 'pending'
    ORDER BY rp.id ASC
");
$stmt->execute();
$pendingKyc = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT rp.*, u.full_name, u.email, u.phone, u.status AS account_status,
        (SELECT COUNT(*) FROM bookings b WHERE b.selected_rider_user_id = u.id AND b.booking_status = 'delivered') AS completed_count,
        (SELECT COUNT(*) FROM booking_complaints bc INNER JOIN bookings b2 ON b2.id = bc.booking_id WHERE b2.selected_rider_user_id = u.id) AS complaint_count
    FROM rider_profiles rp
    INNER JOIN users u ON u.id = rp.user_id
    ORDER BY u.full_name ASC
");
$stmt->execute();
$allRiders = $stmt->fetchAll(PDO::FETCH_ASSOC);

function admin_kyc_badge_class(string $status): string {
    return match ($status) {
        'pending' => 'bg-warning text-dark',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
        default => 'bg-dark border border-secondary',
    };
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('admin.riders_heading')) ?> | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .req-card{background:#ffffff;border:1px solid rgba(15,42,68,.10);border-radius:1rem;padding:1rem;margin-bottom:.75rem}
        .mini-row{padding:.6rem 0;border-bottom:1px solid rgba(15,42,68,.08);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem}
        .mini-row:last-child{border-bottom:none}
        .kyc-doc-thumb{width:64px;height:64px;object-fit:cover;border-radius:.5rem;border:1px solid rgba(15,42,68,.12)}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/riders.php')) ?>"><?= e(t('admin.nav_riders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/complaints.php')) ?>"><?= e(t('admin.nav_complaints')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('admin.riders_heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="cardx p-4 mb-4">
        <h2 class="h5 fw-bold mb-3"><?= e(t('admin.pending_kyc_heading')) ?></h2>
        <?php if (empty($pendingKyc)): ?>
            <div class="text-soft"><?= e(t('admin.no_pending_kyc')) ?></div>
        <?php else: ?>
            <?php foreach ($pendingKyc as $r): ?>
                <div class="req-card p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-2">
                        <div class="d-flex align-items-center gap-3">
                            <?php if (!empty($r['kyc_id_document_path'])): ?>
                                <a href="<?= e(url_path($r['kyc_id_document_path'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e(url_path($r['kyc_id_document_path'])) ?>" class="kyc-doc-thumb" alt="<?= e(t('admin.kyc_document_alt')) ?>">
                                </a>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold"><?= e((string) $r['full_name']) ?></div>
                                <div class="small text-soft"><?= e((string) $r['email']) ?> &middot; <?= e((string) $r['phone']) ?></div>
                                <div class="small text-soft"><?= e(t('register.vehicle_plate_label')) ?>: <?= e((string) ($r['kyc_vehicle_plate'] ?? '')) ?> &middot; <?= e(ucfirst((string) $r['vehicle_type'])) ?></div>
                            </div>
                        </div>
                        <span class="badge <?= e(admin_kyc_badge_class((string) $r['kyc_status'])) ?>"><?= e(ucfirst((string) $r['kyc_status'])) ?></span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="rider_user_id" value="<?= (int) $r['user_id'] ?>">
                            <input type="hidden" name="form_action" value="approve_kyc">
                            <button class="btn btn-sm btn-success fw-bold" type="submit"><?= e(t('admin.approve_kyc')) ?></button>
                        </form>
                        <form method="post" class="d-inline d-flex gap-2 align-items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="rider_user_id" value="<?= (int) $r['user_id'] ?>">
                            <input type="hidden" name="form_action" value="reject_kyc">
                            <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?= e(t('admin.rejection_note_placeholder')) ?>" style="max-width:220px">
                            <button class="btn btn-sm btn-outline-danger fw-bold" type="submit"><?= e(t('admin.reject_kyc')) ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="cardx p-4">
        <h2 class="h5 fw-bold mb-3"><?= e(t('admin.all_riders_heading')) ?></h2>
        <?php if (empty($allRiders)): ?>
            <div class="text-soft"><?= e(t('admin.no_riders')) ?></div>
        <?php else: ?>
            <?php foreach ($allRiders as $r): ?>
                <div class="mini-row">
                    <div>
                        <div class="fw-bold">
                            <?= e((string) $r['full_name']) ?>
                            <span class="badge <?= e(admin_kyc_badge_class((string) $r['kyc_status'])) ?> ms-1"><?= e(ucfirst((string) $r['kyc_status'])) ?></span>
                            <?php if ($r['account_status'] === 'suspended'): ?><span class="badge bg-secondary ms-1"><?= e(t('admin.suspended_badge')) ?></span><?php endif; ?>
                        </div>
                        <div class="small text-soft">
                            <?= e((string) $r['email']) ?> &middot;
                            <?= e(t('admin.rating_label')) ?>: <?= e(number_format((float) $r['rating'], 2)) ?> &middot;
                            <?= e(t('admin.completed_deliveries_label')) ?>: <?= (int) $r['completed_count'] ?> &middot;
                            <?= e(t('admin.complaints_label')) ?>: <?= (int) $r['complaint_count'] ?> &middot;
                            <?= e(t('wallet.available_balance')) ?>: &#8358;<?= number_format(rider_available_balance($pdo, (int) $r['user_id']), 2) ?>
                        </div>
                    </div>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="rider_user_id" value="<?= (int) $r['user_id'] ?>">
                        <?php if ($r['account_status'] === 'suspended'): ?>
                            <input type="hidden" name="form_action" value="activate_rider">
                            <button class="btn btn-sm btn-outline-success fw-bold" type="submit"><?= e(t('admin.activate_rider')) ?></button>
                        <?php else: ?>
                            <input type="hidden" name="form_action" value="suspend_rider">
                            <button class="btn btn-sm btn-outline-danger fw-bold" type="submit"><?= e(t('admin.suspend_rider')) ?></button>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
