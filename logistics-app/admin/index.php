<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $formAction = (string) ($_POST['form_action'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM withdrawal_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$withdrawal) {
        flash('error', t('admin.withdrawal_not_found'));
        redirect_to('admin/index.php');
    }

    if ($formAction === 'mark_processing') {
        if ($withdrawal['status'] !== 'pending') {
            flash('error', t('admin.withdrawal_not_pending'));
        } else {
            $stmt = $pdo->prepare('UPDATE withdrawal_requests SET status = "processing", admin_user_id = ? WHERE id = ?');
            $stmt->execute([$user['id'], $requestId]);
            flash('success', t('admin.marked_processing'));
        }
        redirect_to('admin/index.php');
    }

    if ($formAction === 'mark_paid') {
        if (!in_array($withdrawal['status'], ['pending', 'processing'], true)) {
            flash('error', t('admin.withdrawal_not_pending'));
            redirect_to('admin/index.php');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                UPDATE withdrawal_requests
                SET status = "paid", admin_user_id = ?, processed_at = NOW()
                WHERE id = ? AND status IN ("pending", "processing")
            ');
            $stmt->execute([$user['id'], $requestId]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Withdrawal request was already processed.');
            }

            $stmt = $pdo->prepare('
                INSERT INTO wallet_transactions (rider_user_id, withdrawal_request_id, type, amount, description)
                VALUES (?, ?, "withdrawal", ?, ?)
            ');
            $stmt->execute([
                (int) $withdrawal['rider_user_id'],
                $requestId,
                -1 * (float) $withdrawal['amount'],
                sprintf('Withdrawal to %s (%s)', $withdrawal['bank_name'], $withdrawal['account_number']),
            ]);

            $pdo->commit();
            flash('success', t('admin.marked_paid'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', t('admin.withdrawal_process_failed') . ' ' . $e->getMessage());
        }
        redirect_to('admin/index.php');
    }

    if ($formAction === 'reject') {
        if (!in_array($withdrawal['status'], ['pending', 'processing'], true)) {
            flash('error', t('admin.withdrawal_not_pending'));
        } else {
            $note = trim((string) ($_POST['admin_note'] ?? ''));
            $stmt = $pdo->prepare('
                UPDATE withdrawal_requests
                SET status = "rejected", admin_user_id = ?, admin_note = ?, processed_at = NOW()
                WHERE id = ?
            ');
            $stmt->execute([$user['id'], $note !== '' ? $note : null, $requestId]);
            flash('success', t('admin.marked_rejected'));
        }
        redirect_to('admin/index.php');
    }
}

$stmt = $pdo->prepare("
    SELECT wr.*, u.full_name AS rider_name, u.phone AS rider_phone
    FROM withdrawal_requests wr
    INNER JOIN users u ON u.id = wr.rider_user_id
    WHERE wr.status IN ('pending', 'processing')
    ORDER BY wr.requested_at ASC
");
$stmt->execute();
$openRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT wr.*, u.full_name AS rider_name
    FROM withdrawal_requests wr
    INNER JOIN users u ON u.id = wr.rider_user_id
    WHERE wr.status IN ('paid', 'rejected')
    ORDER BY wr.processed_at DESC
    LIMIT 100
");
$stmt->execute();
$closedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

function admin_withdrawal_status_badge_class(string $status): string {
    return match ($status) {
        'pending' => 'bg-warning text-dark',
        'processing' => 'bg-info text-dark',
        'paid' => 'bg-success',
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
    <title><?= e(t('admin.heading')) ?> | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .req-card{background:#ffffff;border:1px solid rgba(15,42,68,.10);border-radius:1rem;padding:1rem;margin-bottom:.75rem}
        .price-tag{font-weight:800;color:#0284c7}
        .mini-row{padding:.6rem 0;border-bottom:1px solid rgba(15,42,68,.08);display:flex;justify-content:space-between;align-items:center}
        .mini-row:last-child{border-bottom:none}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/riders.php')) ?>"><?= e(t('admin.nav_riders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/complaints.php')) ?>"><?= e(t('admin.nav_complaints')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('admin.withdrawals_heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="cardx p-4 mb-4">
        <h2 class="h5 fw-bold mb-3"><?= e(t('admin.pending_requests_heading')) ?></h2>
        <?php if (empty($openRequests)): ?>
            <div class="text-soft"><?= e(t('admin.no_pending_requests')) ?></div>
        <?php else: ?>
            <?php foreach ($openRequests as $w): ?>
                <div class="req-card p-3">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-bold"><?= e((string) $w['rider_name']) ?></div>
                            <div class="small text-soft"><?= e((string) ($w['rider_phone'] ?? '')) ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="price-tag">&#8358;<?= number_format((float) $w['amount'], 2) ?></span>
                            <span class="badge <?= e(admin_withdrawal_status_badge_class((string) $w['status'])) ?>"><?= e(booking_status_label((string) $w['status'])) ?></span>
                        </div>
                    </div>
                    <div class="small text-soft mb-1"><?= e(t('wallet.bank_name_label')) ?>: <?= e((string) $w['bank_name']) ?></div>
                    <div class="small text-soft mb-1"><?= e(t('wallet.account_number_label')) ?>: <?= e((string) $w['account_number']) ?></div>
                    <div class="small text-soft mb-2"><?= e(t('wallet.account_name_label')) ?>: <?= e((string) $w['account_name']) ?></div>
                    <div class="small text-soft mb-3"><?= e(t('admin.requested_at_label')) ?>: <?= e((string) $w['requested_at']) ?></div>

                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($w['status'] === 'pending'): ?>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="request_id" value="<?= (int) $w['id'] ?>">
                                <input type="hidden" name="form_action" value="mark_processing">
                                <button class="btn btn-sm btn-outline-info fw-bold" type="submit"><?= e(t('admin.mark_processing')) ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= (int) $w['id'] ?>">
                            <input type="hidden" name="form_action" value="mark_paid">
                            <button class="btn btn-sm btn-success fw-bold" type="submit"><?= e(t('admin.mark_paid')) ?></button>
                        </form>
                        <form method="post" class="d-inline d-flex gap-2 align-items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= (int) $w['id'] ?>">
                            <input type="hidden" name="form_action" value="reject">
                            <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?= e(t('admin.rejection_note_placeholder')) ?>" style="max-width:220px">
                            <button class="btn btn-sm btn-outline-danger fw-bold" type="submit"><?= e(t('admin.reject')) ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="cardx p-4">
        <h2 class="h5 fw-bold mb-3"><?= e(t('admin.history_heading')) ?></h2>
        <?php if (empty($closedRequests)): ?>
            <div class="text-soft"><?= e(t('admin.no_history')) ?></div>
        <?php else: ?>
            <?php foreach ($closedRequests as $w): ?>
                <div class="mini-row">
                    <div>
                        <div class="fw-bold"><?= e((string) $w['rider_name']) ?> &middot; &#8358;<?= number_format((float) $w['amount'], 2) ?></div>
                        <div class="small text-soft"><?= e((string) ($w['processed_at'] ?? $w['requested_at'])) ?><?php if (!empty($w['admin_note'])): ?> &middot; <?= e((string) $w['admin_note']) ?><?php endif; ?></div>
                    </div>
                    <span class="badge <?= e(admin_withdrawal_status_badge_class((string) $w['status'])) ?>"><?= e(booking_status_label((string) $w['status'])) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
