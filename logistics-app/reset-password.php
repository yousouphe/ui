<?php
require_once __DIR__ . '/config/functions.php';

if (!isset($_COOKIE['locale'])) {
    redirect_to('choose-language?redirect=reset-password');
}

require_guest();
require_once __DIR__ . '/config/db.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$error = null;
$success = false;
$tokenRecord = null;

if ($tokenHash !== '') {
    $stmt = $pdo->prepare('
        SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, u.full_name
        FROM password_reset_tokens prt
        INNER JOIN users u ON u.id = prt.user_id
        WHERE prt.token_hash = ?
        LIMIT 1
    ');
    $stmt->execute([$tokenHash]);
    $tokenRecord = $stmt->fetch(PDO::FETCH_ASSOC);
}

$tokenValid = $tokenRecord
    && $tokenRecord['used_at'] === null
    && strtotime((string)$tokenRecord['expires_at']) > time();

if (!$tokenValid) {
    $error = t('reset_password.error.invalid_token');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirmation'] ?? '');

    if (strlen($password) < 6) {
        $error = t('register.error.password_length');
    } elseif ($password !== $confirm) {
        $error = t('register.error.password_mismatch');
    } else {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $tokenRecord['user_id']]);
        $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?');
        $stmt->execute([$tokenRecord['id']]);
        $pdo->commit();
        $success = true;
    }
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('reset_password.title')) ?></title>
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
        <h1 class="h2 fw-bold"><?= e(t('reset_password.heading')) ?></h1>
        <?php if ($success): ?>
          <div class="alert alert-success"><?= e(t('reset_password.success')) ?></div>
          <a class="btn btn-primary" href="<?= e(url_path('login')) ?>"><?= e(t('login.submit')) ?></a>
        <?php elseif (!$tokenValid): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
          <a class="btn btn-outline-secondary" href="<?= e(url_path('forgot-password')) ?>"><?= e(t('forgot_password.heading')) ?></a>
        <?php else: ?>
          <p class="text-soft"><?= e(t('reset_password.subheading', ['name' => $tokenRecord['full_name']])) ?></p>
          <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="mb-3">
              <label class="form-label"><?= e(t('register.password_label')) ?></label>
              <input class="form-control" type="password" name="password" required>
            </div>
            <div class="mb-4">
              <label class="form-label"><?= e(t('register.confirm_password_label')) ?></label>
              <input class="form-control" type="password" name="password_confirmation" required>
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('reset_password.submit')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body></html>
