<?php
require_once __DIR__ . '/config/functions.php';
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

    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($password && strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters.';
    if ($password !== $confirm) $errors['password_confirmation'] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors['email'] = 'That email already exists.';
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
        flash('success', 'Registration successful.');
        redirect_to('/bookings');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Register</title>
  <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
    .cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
    .form-control{background:#0b1430;color:#eef4ff;border-color:rgba(255,255,255,.1)}
    .form-control:focus{background:#0b1430;color:#eef4ff;border-color:#6ea8fe;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
    .text-soft{color:#9fb0d6}
  </style>
</head>
<body>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-9">
      <div class="cardx p-4 p-lg-5">
        <div class="row g-4">
          <div class="col-lg-6">
            <h1 class="h2 fw-bold">Create sender account</h1>
            <p class="text-soft">Register to start booking and tracking deliveries.</p>
            <?php if ($errors): ?><div class="alert alert-danger">Please fix the highlighted fields.</div><?php endif; ?>
            <form method="post" novalidate>
              <?= csrf_field() ?>
              <div class="mb-3">
                <label class="form-label">Full name</label>
                <input class="form-control" name="full_name" value="<?= e(old('full_name')) ?>">
                <?php if (!empty($errors['full_name'])): ?><div class="small text-danger mt-1"><?= e($errors['full_name']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label">Phone</label>
                <input class="form-control" name="phone" value="<?= e(old('phone')) ?>">
                <?php if (!empty($errors['phone'])): ?><div class="small text-danger mt-1"><?= e($errors['phone']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" value="<?= e(old('email')) ?>">
                <?php if (!empty($errors['email'])): ?><div class="small text-danger mt-1"><?= e($errors['email']) ?></div><?php endif; ?>
              </div>
              <div class="mb-3">
                <label class="form-label">Password</label>
                <input class="form-control" type="password" name="password">
                <?php if (!empty($errors['password'])): ?><div class="small text-danger mt-1"><?= e($errors['password']) ?></div><?php endif; ?>
              </div>
              <div class="mb-4">
                <label class="form-label">Confirm password</label>
                <input class="form-control" type="password" name="password_confirmation">
                <?php if (!empty($errors['password_confirmation'])): ?><div class="small text-danger mt-1"><?= e($errors['password_confirmation']) ?></div><?php endif; ?>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-primary" type="submit">Create Account</button>
                <a class="btn btn-outline-light" href="<?= e(url_path('login.php')) ?>">Already have an account?</a>
              </div>
            </form>
          </div>
          <div class="col-lg-6">
            <div class="cardx p-4 h-100">
              <h2 class="h4">Module 1 access</h2>
              <ul class="text-soft mb-0">
                <li class="mb-2">Create and save booking requests</li>
                <li class="mb-2">Upload parcel image for clarity</li>
                <li class="mb-2">Store sender booking history</li>
                <li class="mb-2">Prepare records for rider matching in Module 2</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
      <div class="text-center mt-3"><a class="link-light text-decoration-none" href="<?= e(url_path('index.php')) ?>">Back home</a></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
