<?php
// Public account-deletion request page (Google Play policy requires a web URL reachable without
// logging in, alongside an in-app path - see ProfileScreen.kt on the Android side). Deliberately
// not gated by require_guest(): a logged-in user should still be able to reach and use this page.
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/emails.php';

if (!isset($_COOKIE['locale'])) {
    redirect_to('choose-language?redirect=remove_my_account');
}

require_once __DIR__ . '/config/db.php';

$submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $ip = client_ip();

    // Rate-limited the same way forgot-password.php is, and for the same reason: this must never
    // become a way to enumerate registered emails or mass-trigger account suspensions.
    $limited = is_rate_limited($pdo, 'account_deletion_ip', $ip, 5, 60)
        || ($email !== '' && is_rate_limited($pdo, 'account_deletion_email', $email, 3, 60));

    if (!$limited) {
        record_rate_limit_attempt($pdo, 'account_deletion_ip', $ip);
        if ($email !== '') {
            record_rate_limit_attempt($pdo, 'account_deletion_email', $email);
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare('SELECT id, full_name, email, role, status FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($foundUser && $foundUser['status'] !== 'suspended') {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE users SET status = "suspended" WHERE id = ?')->execute([$foundUser['id']]);
                    $pdo->prepare('UPDATE rider_profiles SET availability_status = "offline" WHERE user_id = ?')->execute([$foundUser['id']]);
                    $pdo->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([$foundUser['id']]);
                    $pdo->prepare('INSERT INTO account_deletion_requests (user_id, email) VALUES (?, ?)')->execute([$foundUser['id'], $foundUser['email']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('Account deletion request failed for user ' . $foundUser['id'] . ': ' . $e->getMessage());
                    $foundUser = null; // don't notify/email on a failed transaction
                }

                if ($foundUser) {
                    log_event($pdo, 'account_deletion_requested', 'Account deletion requested and account deactivated for ' . $foundUser['full_name'], null, 'system', 'user', (int) $foundUser['id']);
                    notify_admins($pdo, 'Account deletion requested - ' . $foundUser['full_name'], '<p><strong>' . e($foundUser['full_name']) . '</strong> (' . e($foundUser['email']) . ', role: ' . e($foundUser['role']) . ') has requested account deletion via the public form. Their account has been deactivated immediately - please complete the full data deletion/anonymization from the admin portal.</p>');
                    send_account_deletion_requested_email($foundUser['email'], $foundUser['full_name']);
                }
            }
        }
    }

    // Always the same message, whether or not the email matched an account - avoids leaking
    // which emails are registered.
    $submitted = true;
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('account_deletion.title')) ?></title>
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
        <h1 class="h2 fw-bold"><?= e(t('account_deletion.heading')) ?></h1>
        <p class="text-soft"><?= e(t('account_deletion.subheading')) ?></p>
        <?php if ($submitted): ?>
          <div class="alert alert-success"><?= e(t('account_deletion.success')) ?></div>
          <a class="btn btn-outline-secondary" href="<?= e(url_path('login')) ?>"><?= e(t('common.back')) ?></a>
        <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <div class="mb-4">
              <label class="form-label"><?= e(t('login.email_label')) ?></label>
              <input class="form-control" type="email" name="email" required>
              <div class="form-text text-soft"><?= e(t('account_deletion.email_hint')) ?></div>
            </div>
            <button class="btn btn-danger" type="submit"><?= e(t('account_deletion.submit')) ?></button>
            <a class="btn btn-outline-secondary ms-2" href="<?= e(url_path('login')) ?>"><?= e(t('common.back')) ?></a>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body></html>
