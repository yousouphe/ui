<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $complaintId = (int) ($_POST['complaint_id'] ?? 0);
    $newStatus = (string) ($_POST['status'] ?? '');
    $adminNote = trim((string) ($_POST['admin_note'] ?? ''));

    if (!in_array($newStatus, ['open', 'reviewing', 'resolved'], true)) {
        flash('error', t('admin.invalid_status'));
        redirect_to('admin/complaints.php');
    }

    $stmt = $pdo->prepare('
        SELECT bc.id, bc.status, b.booking_code, s.full_name AS sender_full_name, s.email AS sender_email
        FROM booking_complaints bc
        INNER JOIN bookings b ON b.id = bc.booking_id
        INNER JOIN users s ON s.id = bc.sender_user_id
        WHERE bc.id = ?
        LIMIT 1
    ');
    $stmt->execute([$complaintId]);
    $complaintRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$complaintRow) {
        flash('error', t('admin.complaint_not_found'));
        redirect_to('admin/complaints.php');
    }

    if ($newStatus === 'resolved') {
        $stmt = $pdo->prepare('UPDATE booking_complaints SET status = ?, admin_note = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?');
        $stmt->execute([$newStatus, $adminNote !== '' ? $adminNote : null, $user['id'], $complaintId]);
        if ($complaintRow['status'] !== 'resolved') {
            send_complaint_resolved_email((string) $complaintRow['sender_email'], (string) $complaintRow['sender_full_name'], (string) $complaintRow['booking_code'], $adminNote !== '' ? $adminNote : null);
        }
    } else {
        $stmt = $pdo->prepare('UPDATE booking_complaints SET status = ?, admin_note = ?, resolved_by = NULL, resolved_at = NULL WHERE id = ?');
        $stmt->execute([$newStatus, $adminNote !== '' ? $adminNote : null, $complaintId]);
    }

    flash('success', t('admin.complaint_updated'));
    redirect_to('admin/complaints.php');
}

$stmt = $pdo->prepare("
    SELECT bc.*, b.booking_code, s.full_name AS sender_name, r.full_name AS rider_name
    FROM booking_complaints bc
    INNER JOIN bookings b ON b.id = bc.booking_id
    INNER JOIN users s ON s.id = bc.sender_user_id
    LEFT JOIN users r ON r.id = b.selected_rider_user_id
    ORDER BY FIELD(bc.status, 'open', 'reviewing', 'resolved'), bc.created_at DESC
");
$stmt->execute();
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

function render_complaints_html(array $rows): string {
    if (empty($rows)) {
        return '<div class="text-soft">' . e(t('admin.no_complaints')) . '</div>';
    }
    ob_start();
    foreach ($rows as $c): ?>
        <div class="req-card p-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <div class="fw-bold"><?= e((string) $c['booking_code']) ?> &middot; <?= e(complaint_category_label((string) $c['category'])) ?></div>
                    <div class="small text-soft">
                        <?= e(t('admin.complaint_from_label')) ?>: <?= e((string) $c['sender_name']) ?>
                        <?php if (!empty($c['rider_name'])): ?> &middot; <?= e(t('admin.complaint_rider_label')) ?>: <?= e((string) $c['rider_name']) ?><?php endif; ?>
                        &middot; <?= e((string) $c['created_at']) ?>
                    </div>
                </div>
                <span class="badge <?= e(complaint_status_badge_class((string) $c['status'])) ?>"><?= e(booking_status_label((string) $c['status'])) ?></span>
            </div>
            <p class="mb-2"><?= nl2br(e((string) $c['message'])) ?></p>
            <?php if (!empty($c['admin_note'])): ?>
                <div class="small text-soft mb-2"><?= e(t('admin.admin_note_label')) ?>: <?= e((string) $c['admin_note']) ?></div>
            <?php endif; ?>
            <?php if (!empty($c['feedback_submitted_at'])): ?>
                <div class="small mb-2 p-2 rounded" style="background:rgba(15,42,68,.04)">
                    <span class="badge <?= (int) $c['sender_satisfied'] === 1 ? 'bg-success' : 'bg-danger' ?>">
                        <?= (int) $c['sender_satisfied'] === 1 ? e(t('complaint.feedback_satisfied_label')) : e(t('complaint.feedback_unsatisfied_label')) ?>
                    </span>
                    <?php if (!empty($c['sender_feedback_text'])): ?>
                        <span class="text-soft">&mdash; "<?= e((string) $c['sender_feedback_text']) ?>"</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                <?= csrf_field() ?>
                <input type="hidden" name="complaint_id" value="<?= (int) $c['id'] ?>">
                <select name="status" class="form-select form-select-sm" style="max-width:160px">
                    <option value="open" <?= $c['status'] === 'open' ? 'selected' : '' ?>><?= e(t('status.open')) ?></option>
                    <option value="reviewing" <?= $c['status'] === 'reviewing' ? 'selected' : '' ?>><?= e(t('status.reviewing')) ?></option>
                    <option value="resolved" <?= $c['status'] === 'resolved' ? 'selected' : '' ?>><?= e(t('status.resolved')) ?></option>
                </select>
                <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?= e(t('admin.admin_note_placeholder')) ?>" value="<?= e((string) ($c['admin_note'] ?? '')) ?>" style="max-width:260px">
                <button class="btn btn-sm btn-primary fw-bold" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

$complaintsHtml = render_complaints_html($complaints);
$complaintsSignature = sha1(json_encode($complaints));

if (isset($_GET['ajax']) && $_GET['ajax'] === 'snapshot') {
    respond_json([
        'complaints_html' => $complaintsHtml,
        'signature' => $complaintsSignature,
    ]);
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('admin.complaints_heading')) ?> | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .req-card{background:#ffffff;border:1px solid rgba(15,42,68,.10);border-radius:1rem;padding:1rem;margin-bottom:.75rem}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/riders.php')) ?>"><?= e(t('admin.nav_riders')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/complaints.php')) ?>"><?= e(t('admin.nav_complaints')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/users.php')) ?>"><?= e(t('admin.nav_users')) ?></a>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('admin.complaints_heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="cardx p-4">
        <div id="admin-complaints-list"><?= $complaintsHtml ?></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    let signature = <?= json_encode($complaintsSignature) ?>;
    const snapshotUrl = <?= json_encode(url_path('admin/complaints.php?ajax=snapshot')) ?>;

    async function poll() {
        if (document.hidden) return;
        try {
            const response = await fetch(snapshotUrl, { headers: { 'Accept': 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || data.signature === signature) return;
            signature = data.signature;

            const wrap = document.getElementById('admin-complaints-list');
            if (wrap && typeof data.complaints_html === 'string') wrap.innerHTML = data.complaints_html;
        } catch (err) {
            console.error('Complaints poll failed:', err);
        }
    }

    setInterval(poll, 10000);
})();
</script>
</body>
</html>
