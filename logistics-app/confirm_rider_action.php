<?php
// Public landing page for the email-link half of rider withdrawal/bank-change verification (see
// config/rider_verification.php). Deliberately does NOT consume the token on GET - many email
// clients/security scanners prefetch links in inbox previews, which would silently burn a
// legitimate token before the rider ever clicks it. GET only previews; POST actually confirms.
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/rider_verification.php';

if (!isset($_COOKIE['locale'])) {
    redirect_to('choose-language?redirect=confirm_rider_action');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$error = null;
$success = false;
$resultMessage = null;
$verification = null;

if ($tokenHash !== '') {
    $stmt = $pdo->prepare('SELECT * FROM rider_action_verifications WHERE link_token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);
}

$tokenValid = $verification
    && $verification['used_at'] === null
    && strtotime((string) $verification['expires_at']) > time();

if (!$tokenValid) {
    $error = t('confirm_action.error.invalid_token');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $result = rider_verification_confirm_token($pdo, $token);
    if (!$result['ok']) {
        $error = $result['message'];
    } else {
        $execResult = execute_rider_verified_action($pdo, (int) $result['riderUserId'], (string) $result['actionType'], (array) $result['payload']);
        $success = $execResult['ok'];
        $resultMessage = $execResult['message'];
        if (!$execResult['ok']) {
            $error = $execResult['message'];
        }
    }
}

$actionLabel = $verification && $verification['action_type'] === 'withdrawal' ? t('confirm_action.type_withdrawal') : t('confirm_action.type_bank_change');
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('confirm_action.title')) ?></title>
<base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
.cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
.text-soft{color:#5c7a91}
</style>
</head><body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="cardx p-4 p-lg-5">
        <h1 class="h2 fw-bold"><?= e(t('confirm_action.heading')) ?></h1>
        <?php if ($success): ?>
          <div class="alert alert-success"><?= e($resultMessage) ?></div>
          <a class="btn btn-outline-secondary" href="<?= e(url_path('login')) ?>"><?= e(t('common.back')) ?></a>
        <?php elseif (!$tokenValid || $error): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php else: ?>
          <p class="text-soft"><?= e(t('confirm_action.subheading', ['action' => $actionLabel])) ?></p>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <button class="btn btn-primary" type="submit"><?= e(t('confirm_action.submit')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body></html>
