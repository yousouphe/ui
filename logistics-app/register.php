<?php
require_once __DIR__ . '/config/functions.php';

if (!isset($_COOKIE['locale'])) {
    redirect_to('choose-language?redirect=register');
}

require_guest();
require_once __DIR__ . '/config/db.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $errors = validate_required([
        'full_name' => 'Full name',
        'email' => 'Email',
        'phone' => 'Phone',
        'password' => 'Password',
        'password_confirmation' => 'Password confirmation'
    ], $_POST);

    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirmation'] ?? '');

    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = t('register.error.invalid_email');
    if ($password && strlen($password) < 6) $errors['password'] = t('register.error.password_length');
    if ($password !== $confirm) $errors['password_confirmation'] = t('register.error.password_mismatch');

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors['email'] = t('register.error.email_exists');
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO users (full_name,email,phone,password_hash,role,status) VALUES (?,?,?,?,"sender","active")');
        $stmt->execute([$fullName,$email,$phone,password_hash($password, PASSWORD_DEFAULT)]);
        $_SESSION['user'] = [
            'id' => (int)$pdo->lastInsertId(),
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role' => 'sender'
        ];
        flash('success', t('register.success'));
        redirect_to('/bookings');
    }
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e(t('register.title')) ?></title>
  <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
    .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
    .form-control{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
    .form-control:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
    .text-soft{color:#5c7a91}
    .lang-switch{position:absolute;top:1rem;right:1rem;font-size:.85rem}
    .lang-switch a{color:#5c7a91;text-decoration:none}
    .lang-switch a.active{font-weight:700;color:#0f2c44}
  </style>
</head>
<body>
<div class="lang-switch">
  <a href="<?= e(url_path('set_locale?locale=en&redirect=register')) ?>" class="<?= current_locale() === 'en' ? 'active' : '' ?>">EN</a>
  &middot;
  <a href="<?= e(url_path('set_locale?locale=ha&redirect=register')) ?>" class="<?= current_locale() === 'ha' ? 'active' : '' ?>">HA</a>
</div>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-9">
      <div class="cardx p-4 p-lg-5">
        <div class="row g-4">
          <div class="col-lg-6">
            <h1 class="h2 fw-bold"><?= e(t('register.heading')) ?></h1>
            <p class="text-soft"><?= e(t('register.subheading')) ?></p>
            <?php if ($errors): ?><div class="alert alert-danger"><?= e(t('register.error.fix_fields')) ?></div><?php endif; ?>
            <form method="post" novalidate>
              <?= csrf_field() ?>
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.full_name_label')) ?></label>
                <input class="form-control" name="full_name" value="<?= e(old('full_name')) ?>">
                <?php if (!empty($errors['full_name'])): ?><div class="small text-danger mt-1"><?= e($errors['full_name']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.phone_label')) ?></label>
                <input class="form-control" name="phone" value="<?= e(old('phone')) ?>">
                <?php if (!empty($errors['phone'])): ?><div class="small text-danger mt-1"><?= e($errors['phone']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.email_label')) ?></label>
                <input class="form-control" type="email" name="email" value="<?= e(old('email')) ?>">
                <?php if (!empty($errors['email'])): ?><div class="small text-danger mt-1"><?= e($errors['email']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label"><?= e(t('register.password_label')) ?></label>
                <input class="form-control" type="password" name="password">
                <?php if (!empty($errors['password'])): ?><div class="small text-danger mt-1"><?= e($errors['password']) ?></div><?php endif; ?>
              </div>
              <div class="mb-4">
                <label class="form-label"><?= e(t('register.confirm_password_label')) ?></label>
                <input class="form-control" type="password" name="password_confirmation">
                <?php if (!empty($errors['password_confirmation'])): ?><div class="small text-danger mt-1"><?= e($errors['password_confirmation']) ?></div><?php endif; ?>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-primary" type="submit"><?= e(t('register.submit')) ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url_path('login')) ?>"><?= e(t('register.have_account')) ?></a>
              </div>
            </form>
          </div>
          <div class="col-lg-6">
            <div class="cardx p-4 h-100">
              <h2 class="h4"><?= e(t('register.features_heading')) ?></h2>
              <ul class="text-soft mb-0">
                <li class="mb-2"><?= e(t('register.feature.1')) ?></li>
                <li class="mb-2"><?= e(t('register.feature.2')) ?></li>
                <li class="mb-2"><?= e(t('register.feature.3')) ?></li>
                <li class="mb-2"><?= e(t('register.feature.4')) ?></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
      <div class="text-center mt-3"><a class="link-light text-decoration-none" href="<?= e(url_path('')) ?>"><?= e(t('register.back_home')) ?></a></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
