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
        // Rotate the session ID on every login so a session ID that existed before
        // authentication (e.g. fixed by an attacker, or left over from a previous
        // role on a shared device) can never be reused to inherit this login.
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'profile_completed' => (int)($user['profile_completed'] ?? 1)
        ];
        flash('success', t('login.welcome_back', ['name' => $user['full_name']]));
        if ((int)($user['profile_completed'] ?? 1) === 0) redirect_to('complete-profile');
        if ($user['role'] === 'rider') redirect_to('rider/');
        if (in_array($user['role'], ['admin', 'super_admin'], true)) redirect_to('admin/');
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
          <div class="mb-2"><label class="form-label"><?= e(t('login.password_label')) ?></label><input class="form-control" type="password" name="password"></div>
          <div class="mb-4 text-end"><a class="small text-soft" href="<?= e(url_path('forgot-password')) ?>"><?= e(t('login.forgot_password')) ?></a></div>
          <button class="btn btn-primary" type="submit"><?= e(t('login.submit')) ?></button>
          <a class="btn btn-outline-secondary ms-2" href="<?= e(url_path('')) ?>"><?= e(t('common.back')) ?></a>
        </form>
        <a class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 mt-3" href="<?= e(url_path('auth/google_login.php')) ?>">
          <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 01-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 009 18z"/><path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 013.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 000 9c0 1.45.35 2.83.96 4.05l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.59-2.59C13.46.89 11.43 0 9 0A9 9 0 00.96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg>
          <?= e(t('login.google_signin')) ?>
        </a>
        <p class="text-soft mt-4 mb-0"><?= e(t('login.no_account')) ?> <a href="<?= e(url_path('register')) ?>"><?= e(t('login.register_link')) ?></a></p>
      </div>
    </div>
  </div>
</div>
</body></html>
