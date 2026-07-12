<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');
$isSuperAdmin = $user['role'] === 'super_admin';

$allowedRoles = ['sender', 'rider', 'admin', 'super_admin'];
$adminLevelRoles = ['admin', 'super_admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $formAction = (string) ($_POST['form_action'] ?? '');

    // Only a super admin can assign roles - a regular admin can still suspend/activate
    // accounts, but role assignment (including promoting to admin) is super-admin-only.
    if ($formAction === 'change_role' && !$isSuperAdmin) {
        flash('error', t('admin.role_change_requires_super_admin'));
        redirect_to('admin/users.php');
    }

    if ($targetUserId === (int) $user['id'] && in_array($formAction, ['change_role', 'suspend_user'], true)) {
        flash('error', t('admin.cannot_modify_self'));
        redirect_to('admin/users.php');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        flash('error', t('admin.user_not_found'));
        redirect_to('admin/users.php');
    }

    if ($formAction === 'change_role') {
        $newRole = (string) ($_POST['role'] ?? '');
        if (!in_array($newRole, $allowedRoles, true)) {
            flash('error', t('admin.invalid_role'));
            redirect_to('admin/users.php');
        }

        if (in_array($targetUser['role'], $adminLevelRoles, true) && !in_array($newRole, $adminLevelRoles, true)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active'");
            $stmt->execute();
            if ((int) $stmt->fetchColumn() <= 1) {
                flash('error', t('admin.cannot_demote_last_admin'));
                redirect_to('admin/users.php');
            }
        }

        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$newRole, $targetUserId]);

        if ($newRole === 'rider') {
            $stmt = $pdo->prepare('SELECT id FROM rider_profiles WHERE user_id = ? LIMIT 1');
            $stmt->execute([$targetUserId]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare('INSERT INTO rider_profiles (user_id, kyc_status) VALUES (?, "pending")');
                $stmt->execute([$targetUserId]);
            }
        }

        flash('success', t('admin.role_updated'));
        log_event($pdo, 'role_changed', 'Changed role of ' . $targetUser['full_name'] . ' from ' . $targetUser['role'] . ' to ' . $newRole, (int) $user['id'], (string) $user['role'], 'user', $targetUserId, ['from' => $targetUser['role'], 'to' => $newRole]);
        redirect_to('admin/users.php');
    }

    if ($formAction === 'suspend_user') {
        if (in_array($targetUser['role'], $adminLevelRoles, true)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin') AND status = 'active'");
            $stmt->execute();
            if ((int) $stmt->fetchColumn() <= 1) {
                flash('error', t('admin.cannot_suspend_last_admin'));
                redirect_to('admin/users.php');
            }
        }
        $stmt = $pdo->prepare('UPDATE users SET status = "suspended" WHERE id = ?');
        $stmt->execute([$targetUserId]);
        $stmt = $pdo->prepare('UPDATE rider_profiles SET availability_status = "offline" WHERE user_id = ?');
        $stmt->execute([$targetUserId]);
        flash('success', t('admin.user_suspended'));
        log_event($pdo, 'user_suspended', 'Suspended ' . $targetUser['full_name'], (int) $user['id'], (string) $user['role'], 'user', $targetUserId);
        redirect_to('admin/users.php');
    }

    if ($formAction === 'activate_user') {
        $stmt = $pdo->prepare('UPDATE users SET status = "active" WHERE id = ?');
        $stmt->execute([$targetUserId]);
        flash('success', t('admin.user_activated'));
        log_event($pdo, 'user_activated', 'Activated ' . $targetUser['full_name'], (int) $user['id'], (string) $user['role'], 'user', $targetUserId);
        redirect_to('admin/users.php');
    }
}

$roleFilter = (string) ($_GET['role'] ?? '');
$roleFilter = in_array($roleFilter, $allowedRoles, true) ? $roleFilter : '';
$search = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT id, full_name, email, phone, role, status, avatar_path, created_at FROM users WHERE 1=1";
$params = [];
if ($roleFilter !== '') {
    $sql .= " AND role = ?";
    $params[] = $roleFilter;
}
if ($search !== '') {
    $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

function admin_role_badge_class(string $role): string {
    return match ($role) {
        'super_admin' => 'bg-dark',
        'admin' => 'bg-primary',
        'rider' => 'bg-info text-dark',
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
    <title><?= e(t('admin.users_heading')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .form-control,.form-select{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .mini-row{padding:.75rem 0;border-bottom:1px solid rgba(15,42,68,.08);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem}
        .mini-row:last-child{border-bottom:none}
        .avatar-thumb{width:44px;height:44px;object-fit:cover;border-radius:50%;border:1px solid rgba(15,42,68,.12)}
        .avatar-placeholder{width:44px;height:44px;border-radius:50%;background:#eaf2fb;display:flex;align-items:center;justify-content:center;color:#5c7a91}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/bookings.php')) ?>"><?= e(t('admin.nav_bookings')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/riders.php')) ?>"><?= e(t('admin.nav_riders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/complaints.php')) ?>"><?= e(t('admin.nav_complaints')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/users.php')) ?>"><?= e(t('admin.nav_users')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/logs.php')) ?>"><?= e(t('admin.nav_logs')) ?></a>
            <?php if ($isSuperAdmin): ?>
                <a class="nav-link" href="<?= e(url_path('admin/pricing.php')) ?>"><?= e(t('admin.nav_pricing')) ?></a>
            <?php endif; ?>
            <a class="nav-link" href="<?= e(url_path('admin/profile.php')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('admin.users_heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="cardx p-4 mb-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small"><?= e(t('admin.search_users_label')) ?></label>
                <input class="form-control" type="text" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('admin.search_users_placeholder')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small"><?= e(t('admin.filter_role_label')) ?></label>
                <select class="form-select" name="role">
                    <option value=""><?= e(t('admin.all_roles')) ?></option>
                    <option value="sender" <?= $roleFilter === 'sender' ? 'selected' : '' ?>><?= e(t('register.account_type_sender')) ?></option>
                    <option value="rider" <?= $roleFilter === 'rider' ? 'selected' : '' ?>><?= e(t('register.account_type_rider')) ?></option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>><?= e(t('admin.role_admin')) ?></option>
                    <option value="super_admin" <?= $roleFilter === 'super_admin' ? 'selected' : '' ?>><?= e(t('admin.role_super_admin')) ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100 fw-bold" type="submit"><?= e(t('common.submit')) ?></button>
            </div>
        </form>
    </div>

    <div class="cardx p-4">
        <?php if (empty($users)): ?>
            <div class="text-soft"><?= e(t('admin.no_users_found')) ?></div>
        <?php else: ?>
            <?php foreach ($users as $u): ?>
                <div class="mini-row">
                    <div class="d-flex align-items-center gap-3">
                        <?php if (!empty($u['avatar_path'])): ?>
                            <img src="<?= e(url_path($u['avatar_path'])) ?>" class="avatar-thumb" alt="">
                        <?php else: ?>
                            <div class="avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold">
                                <?= e((string) $u['full_name']) ?>
                                <span class="badge <?= e(admin_role_badge_class((string) $u['role'])) ?> ms-1"><?= e(ucfirst((string) $u['role'])) ?></span>
                                <?php if ($u['status'] === 'suspended'): ?><span class="badge bg-secondary ms-1"><?= e(t('admin.suspended_badge')) ?></span><?php endif; ?>
                                <?php if ((int) $u['id'] === (int) $user['id']): ?><span class="badge bg-dark ms-1"><?= e(t('admin.you_badge')) ?></span><?php endif; ?>
                            </div>
                            <div class="small text-soft"><?= e((string) $u['email']) ?> &middot; <?= e((string) $u['phone']) ?></div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                            <?php if ($isSuperAdmin): ?>
                                <form method="post" class="d-flex gap-1 align-items-center">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="form_action" value="change_role">
                                    <select name="role" class="form-select form-select-sm" style="width:auto">
                                        <option value="sender" <?= $u['role'] === 'sender' ? 'selected' : '' ?>><?= e(t('register.account_type_sender')) ?></option>
                                        <option value="rider" <?= $u['role'] === 'rider' ? 'selected' : '' ?>><?= e(t('register.account_type_rider')) ?></option>
                                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>><?= e(t('admin.role_admin')) ?></option>
                                        <option value="super_admin" <?= $u['role'] === 'super_admin' ? 'selected' : '' ?>><?= e(t('admin.role_super_admin')) ?></option>
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary fw-bold" type="submit"><?= e(t('admin.change_role')) ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <?php if ($u['status'] === 'suspended'): ?>
                                    <input type="hidden" name="form_action" value="activate_user">
                                    <button class="btn btn-sm btn-outline-success fw-bold" type="submit"><?= e(t('admin.activate_rider')) ?></button>
                                <?php else: ?>
                                    <input type="hidden" name="form_action" value="suspend_user">
                                    <button class="btn btn-sm btn-outline-danger fw-bold" type="submit"><?= e(t('admin.suspend_rider')) ?></button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
