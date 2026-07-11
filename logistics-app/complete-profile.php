<?php
require_once __DIR__ . '/config/functions.php';
require_auth();
require_once __DIR__ . '/config/db.php';

$user = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $phone = trim((string)($_POST['phone'] ?? ''));

    if ($phone === '') {
        $errors['phone'] = t('register.error.fix_fields');
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE users SET phone = ?, profile_completed = 1 WHERE id = ?');
        $stmt->execute([$phone, $user['id']]);

        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['profile_completed'] = 1;

        flash('success', t('complete_profile.success'));
        if ($user['role'] === 'rider') redirect_to('rider/');
        if (in_array($user['role'], ['admin', 'super_admin'], true)) redirect_to('admin/');
        redirect_to('/bookings');
    }
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('complete_profile.title')) ?></title>
<base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
.cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
.form-control{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
.form-control:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
.text-soft{color:#5c7a91}
</style>
</head><body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="cardx p-4 p-lg-5">
        <h1 class="h2 fw-bold"><?= e(t('complete_profile.heading')) ?></h1>
        <p class="text-soft"><?= e(t('complete_profile.subheading')) ?></p>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-4">
            <label class="form-label"><?= e(t('register.phone_label')) ?></label>
            <input class="form-control" name="phone" value="<?= e(old('phone')) ?>" required>
            <?php if (!empty($errors['phone'])): ?><div class="small text-danger mt-1"><?= e($errors['phone']) ?></div><?php endif; ?>
          </div>
          <button class="btn btn-primary" type="submit"><?= e(t('complete_profile.submit')) ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
</body></html>
