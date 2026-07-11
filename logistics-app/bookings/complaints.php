<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender', 'admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $complaintId = (int) ($_POST['complaint_id'] ?? 0);
    $satisfied = (string) ($_POST['satisfied'] ?? '');
    $feedbackText = trim((string) ($_POST['feedback_text'] ?? ''));

    $stmt = $pdo->prepare('
        SELECT bc.id, bc.status, bc.feedback_submitted_at, b.booking_code
        FROM booking_complaints bc
        INNER JOIN bookings b ON b.id = bc.booking_id
        WHERE bc.id = ? AND bc.sender_user_id = ?
        LIMIT 1
    ');
    $stmt->execute([$complaintId, $user['id']]);
    $complaintRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$complaintRow) {
        flash('error', t('complaint.not_found'));
    } elseif ($complaintRow['status'] !== 'resolved') {
        flash('error', t('complaint.feedback_not_resolved'));
    } elseif ($complaintRow['feedback_submitted_at'] !== null) {
        flash('error', t('complaint.feedback_already_submitted'));
    } elseif (!in_array($satisfied, ['1', '0'], true)) {
        flash('error', t('complaint.feedback_invalid'));
    } else {
        $stmt = $pdo->prepare('
            UPDATE booking_complaints
            SET sender_satisfied = ?, sender_feedback_text = ?, feedback_submitted_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([(int) $satisfied, $feedbackText !== '' ? $feedbackText : null, $complaintId]);
        flash('success', t('complaint.feedback_thanks'));

        $satisfiedLabel = $satisfied === '1' ? t('complaint.feedback_satisfied_label') : t('complaint.feedback_unsatisfied_label');
        $body = '<p><strong>' . e((string) $user['full_name']) . '</strong> left feedback on the resolution of complaint <strong>' . e((string) $complaintRow['booking_code']) . '</strong>.</p>'
            . '<p><strong>Satisfaction:</strong> ' . e($satisfiedLabel) . '</p>'
            . ($feedbackText !== '' ? '<p>' . nl2br(e($feedbackText)) . '</p>' : '');
        notify_admins($pdo, 'Complaint feedback received - ' . $complaintRow['booking_code'], $body);
    }
    redirect_to('bookings/complaints.php');
}

$stmt = $pdo->prepare('
    SELECT bc.*, b.booking_code
    FROM booking_complaints bc
    INNER JOIN bookings b ON b.id = bc.booking_id
    WHERE bc.sender_user_id = ?
    ORDER BY FIELD(bc.status, "open", "reviewing", "resolved"), bc.created_at DESC
');
$stmt->execute([$user['id']]);
$complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

function render_my_complaints_html(array $rows): string {
    if (empty($rows)) {
        return '<div class="text-soft">' . e(t('complaint.no_complaints_sender')) . '</div>';
    }
    ob_start();
    foreach ($rows as $c): ?>
        <div class="req-card p-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <div class="fw-bold"><?= e((string) $c['booking_code']) ?> &middot; <?= e(complaint_category_label((string) $c['category'])) ?></div>
                    <div class="small text-soft"><?= e((string) $c['created_at']) ?></div>
                </div>
                <span class="badge <?= e(complaint_status_badge_class((string) $c['status'])) ?>"><?= e(booking_status_label((string) $c['status'])) ?></span>
            </div>
            <p class="mb-2"><?= nl2br(e((string) $c['message'])) ?></p>

            <?php if ($c['status'] === 'resolved' && !empty($c['admin_note'])): ?>
                <div class="small text-soft mb-2"><?= e(t('complaint.resolution_note_label')) ?>: <?= e((string) $c['admin_note']) ?></div>
            <?php elseif ($c['status'] !== 'resolved'): ?>
                <div class="small text-soft mb-2"><?= e(t('complaint.being_reviewed_hint')) ?></div>
            <?php endif; ?>

            <?php if ($c['status'] === 'resolved'): ?>
                <?php if (!empty($c['feedback_submitted_at'])): ?>
                    <div class="small p-2 rounded" style="background:rgba(15,42,68,.04)">
                        <span class="fw-bold"><?= e(t('complaint.your_feedback_label')) ?>:</span>
                        <span class="badge <?= (int) $c['sender_satisfied'] === 1 ? 'bg-success' : 'bg-danger' ?>">
                            <?= (int) $c['sender_satisfied'] === 1 ? e(t('complaint.feedback_satisfied_label')) : e(t('complaint.feedback_unsatisfied_label')) ?>
                        </span>
                        <?php if (!empty($c['sender_feedback_text'])): ?>
                            <span class="text-soft">&mdash; "<?= e((string) $c['sender_feedback_text']) ?>"</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <form method="post" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="complaint_id" value="<?= (int) $c['id'] ?>">
                        <div class="small fw-bold mb-2"><?= e(t('complaint.feedback_prompt')) ?></div>
                        <div class="d-flex gap-2 mb-2">
                            <button class="btn btn-sm btn-outline-success" type="submit" name="satisfied" value="1"><i class="fa-solid fa-thumbs-up me-1"></i><?= e(t('complaint.feedback_yes')) ?></button>
                            <button class="btn btn-sm btn-outline-danger" type="submit" name="satisfied" value="0"><i class="fa-solid fa-thumbs-down me-1"></i><?= e(t('complaint.feedback_no')) ?></button>
                        </div>
                        <textarea class="form-control form-control-sm" name="feedback_text" rows="2" placeholder="<?= e(t('complaint.feedback_comment_placeholder')) ?>"></textarea>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

$myComplaintsHtml = render_my_complaints_html($complaints);
$myComplaintsSignature = sha1(json_encode($complaints));

if (isset($_GET['ajax']) && $_GET['ajax'] === 'snapshot') {
    respond_json([
        'complaints_html' => $myComplaintsHtml,
        'signature' => $myComplaintsSignature,
    ]);
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('complaint.heading')) ?> | SwiftDrop</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .req-card{background:#ffffff;border:1px solid rgba(15,42,68,.10);border-radius:1rem;padding:1rem;margin-bottom:.75rem}
        .form-control{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .form-control:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('bookings/')) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('dashboard')) ?>"><i class="fa-solid fa-list-ul me-1"></i><?= e(t('nav.my_orders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('bookings/?new=1')) ?>"><i class="fa-solid fa-plus me-1"></i><?= e(t('nav.new_order')) ?></a>
            <a class="nav-link active" href="<?= e(url_path('bookings/complaints.php')) ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= e(t('complaint.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
            <div class="small">
                <a href="<?= e(url_path('set_locale?locale=en&redirect=bookings/complaints.php')) ?>" class="<?= current_locale() === 'en' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">EN</a>
                &middot;
                <a href="<?= e(url_path('set_locale?locale=ha&redirect=bookings/complaints.php')) ?>" class="<?= current_locale() === 'ha' ? 'fw-bold text-dark' : 'text-soft' ?> text-decoration-none">HA</a>
            </div>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('complaint.heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="cardx p-4">
        <div id="my-complaints-list"><?= $myComplaintsHtml ?></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    let signature = <?= json_encode($myComplaintsSignature) ?>;
    const snapshotUrl = <?= json_encode(url_path('bookings/complaints.php?ajax=snapshot')) ?>;

    async function poll() {
        if (document.hidden) return;
        try {
            const response = await fetch(snapshotUrl, { headers: { 'Accept': 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || data.signature === signature) return;
            signature = data.signature;
            const wrap = document.getElementById('my-complaints-list');
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
