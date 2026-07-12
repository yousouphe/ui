<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'update_details') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $errors = [];

        if ($fullName === '') $errors[] = t('profile.error.full_name_required');
        if ($phone === '') $errors[] = t('profile.error.phone_required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('register.error.invalid_email');

        if (!$errors && $email !== $dbUser['email']) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) $errors[] = t('register.error.email_exists');
        }

        $avatarPath = $dbUser['avatar_path'];
        if (!$errors && !empty($_FILES['avatar']['name'])) {
            try {
                $uploaded = save_uploaded_image($_FILES['avatar'], 'avatars', 'avatar', t('profile.avatar_label'));
                if ($uploaded) $avatarPath = $uploaded;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
        } else {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, phone = ?, email = ?, avatar_path = ? WHERE id = ?');
            $stmt->execute([$fullName, $phone, $email, $avatarPath, $user['id']]);

            $_SESSION['user']['full_name'] = $fullName;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['user']['email'] = $email;

            flash('success', t('profile.details_updated'));
        }
        redirect_to('admin/profile.php');
    }

    if ($formAction === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirmation'] ?? '');

        if (!password_verify($currentPassword, $dbUser['password_hash'])) {
            flash('error', t('profile.error.current_password_incorrect'));
        } elseif (strlen($newPassword) < 6) {
            flash('error', t('register.error.password_length'));
        } elseif ($newPassword !== $confirmPassword) {
            flash('error', t('register.error.password_mismatch'));
        } else {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
            flash('success', t('profile.password_updated'));
        }
        redirect_to('admin/profile.php');
    }
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('profile.heading')) ?> | SwiftDrop <?= e(t('admin.brand_suffix')) ?></title>
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
        .avatar-preview{width:96px;height:96px;object-fit:cover;border-radius:50%;border:1px solid rgba(15,42,68,.12)}
        .avatar-placeholder{width:96px;height:96px;border-radius:50%;background:#eaf2fb;display:flex;align-items:center;justify-content:center;color:#5c7a91;font-size:2.2rem}
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
            <a class="nav-link" href="<?= e(url_path('admin/users.php')) ?>"><?= e(t('admin.nav_users')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/logs.php')) ?>"><?= e(t('admin.nav_logs')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/profile.php')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('profile.heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="cardx p-4 h-100">
                <h2 class="h5 fw-bold mb-3"><?= e(t('profile.details_heading')) ?></h2>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="update_details">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <?php if (!empty($dbUser['avatar_path'])): ?>
                            <img src="<?= e(url_path($dbUser['avatar_path'])) ?>" class="avatar-preview" alt="">
                        <?php else: ?>
                            <div class="avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <label class="form-label small"><?= e(t('profile.avatar_label')) ?></label>
                            <input class="form-control" type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('register.full_name_label')) ?></label>
                        <input class="form-control" name="full_name" value="<?= e((string) $dbUser['full_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('register.phone_label')) ?></label>
                        <input class="form-control" name="phone" value="<?= e((string) $dbUser['phone']) ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><?= e(t('register.email_label')) ?></label>
                        <input class="form-control" type="email" name="email" value="<?= e((string) $dbUser['email']) ?>" required>
                    </div>
                    <button class="btn btn-primary fw-bold" type="submit"><?= e(t('profile.save_details')) ?></button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="cardx p-4 h-100">
                <h2 class="h5 fw-bold mb-3"><?= e(t('profile.password_heading')) ?></h2>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('profile.current_password_label')) ?></label>
                        <input class="form-control" type="password" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('register.password_label')) ?></label>
                        <input class="form-control" type="password" name="new_password" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><?= e(t('register.confirm_password_label')) ?></label>
                        <input class="form-control" type="password" name="new_password_confirmation" required>
                    </div>
                    <button class="btn btn-primary fw-bold" type="submit"><?= e(t('profile.update_password')) ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
