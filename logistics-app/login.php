<?php
require_once __DIR__ . '/config/functions.php';
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
        $error = 'Invalid email or password.';
    } elseif ($user['status'] !== 'active') {
        $error = 'Account is not active.';
    } else {
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => $user['role']
        ];
        flash('success', 'Welcome back, ' . $user['full_name'] . '.');
        if ($user['role'] === 'rider') redirect_to('rider/');
        redirect_to('/bookings');
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login</title>
<base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
.cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
.form-control{background:#0b1430;color:#eef4ff;border-color:rgba(255,255,255,.1)}
.form-control:focus{background:#0b1430;color:#eef4ff;border-color:#6ea8fe;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
.text-soft{color:#9fb0d6}
</style>
</head><body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <div class="cardx p-4 p-lg-5">
        <h1 class="h2 fw-bold">Sign in</h1>
        <p class="text-soft">Sender and rider accounts both sign in here.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email"></div>
          <div class="mb-4"><label class="form-label">Password</label><input class="form-control" type="password" name="password"></div>
          <button class="btn btn-primary" type="submit">Login</button>
          <a class="btn btn-outline-light ms-2" href="<?= e(url_path('index.php')) ?>">Back</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body></html>
