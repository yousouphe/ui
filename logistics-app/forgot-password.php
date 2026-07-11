<?php
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/emails.php';

if (!isset($_COOKIE['locale'])) {
    redirect_to('choose-language?redirect=forgot-password');
}

require_guest();
require_once __DIR__ . '/config/db.php';

$submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($foundUser) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
            $stmt->execute([$foundUser['id'], $tokenHash]);

            $resetUrl = rtrim((string)(config_app()['app_url'] ?? ''), '/') . url_path('reset-password?token=' . $token);
            send_password_reset_email($foundUser['email'], $foundUser['full_name'], $resetUrl);
        }
    }

    // Always show the same success message, whether or not the email exists,
    // so this form can't be used to enumerate registered accounts.
    $submitted = true;
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('forgot_password.title')) ?></title>
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
        <h1 class="h2 fw-bold"><?= e(t('forgot_password.heading')) ?></h1>
        <p class="text-soft"><?= e(t('forgot_password.subheading')) ?></p>
        <?php if ($submitted): ?>
          <div class="alert alert-success"><?= e(t('forgot_password.success')) ?></div>
          <a class="btn btn-outline-secondary" href="<?= e(url_path('login')) ?>"><?= e(t('common.back')) ?></a>
        <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <div class="mb-4">
              <label class="form-label"><?= e(t('login.email_label')) ?></label>
              <input class="form-control" type="email" name="email" required>
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('forgot_password.submit')) ?></button>
            <a class="btn btn-outline-secondary ms-2" href="<?= e(url_path('login')) ?>"><?= e(t('common.back')) ?></a>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body></html>
