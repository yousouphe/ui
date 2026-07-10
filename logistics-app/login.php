<?php
require_once __DIR__ . '/config/functions.php';

if (!isset($_COOKIE['locale'])) {
    redirect_to('choose-language?redirect=login');
}

require_guest();
require_once __DIR__ . '/config/db.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = t('login.error.invalid');
    } elseif ($user['status'] !== 'active') {
        $error = t('login.error.inactive');
    } else {
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => $user['role']
        ];
        flash('success', t('login.welcome_back', ['name' => $user['full_name']]));
        if ($user['role'] === 'rider') redirect_to('rider/');
        redirect_to('/bookings');
    }
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('login.title')) ?></title>
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
</head><body>
<div class="lang-switch">
  <a href="<?= e(url_path('set_locale?locale=en&redirect=login')) ?>" class="<?= current_locale() === 'en' ? 'active' : '' ?>">EN</a>
  &middot;
  <a href="<?= e(url_path('set_locale?locale=ha&redirect=login')) ?>" class="<?= current_locale() === 'ha' ? 'active' : '' ?>">HA</a>
</div>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <div class="cardx p-4 p-lg-5">
        <h1 class="h2 fw-bold"><?= e(t('login.heading')) ?></h1>
        <p class="text-soft"><?= e(t('login.subheading')) ?></p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label"><?= e(t('login.email_label')) ?></label><input class="form-control" type="email" name="email"></div>
          <div class="mb-4"><label class="form-label"><?= e(t('login.password_label')) ?></label><input class="form-control" type="password" name="password"></div>
          <button class="btn btn-primary" type="submit"><?= e(t('login.submit')) ?></button>
          <a class="btn btn-outline-secondary ms-2" href="<?= e(url_path('')) ?>"><?= e(t('common.back')) ?></a>
        </form>
        <p class="text-soft mt-4 mb-0"><?= e(t('login.no_account')) ?> <a href="<?= e(url_path('register')) ?>"><?= e(t('login.register_link')) ?></a></p>
      </div>
    </div>
  </div>
</div>
</body></html>
