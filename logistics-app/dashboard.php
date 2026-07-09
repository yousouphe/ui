<?php
require_once __DIR__ . '/config/functions.php';
require_role(['sender','admin']);
require_once __DIR__ . '/config/db.php';
$user = current_user();
$success = flash('success');

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE sender_user_id = ? ORDER BY id DESC');
$stmt->execute([$user['id']]);
$bookings = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sender Dashboard</title>
<base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
.navx{background:rgba(8,17,33,.88);border-bottom:1px solid rgba(255,255,255,.08)}
.cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
.table{--bs-table-bg:transparent;--bs-table-color:#eef4ff;--bs-table-border-color:rgba(255,255,255,.08)}
.text-soft{color:#9fb0d6}
</style>
</head><body>
<nav class="navbar navbar-expand-lg navbar-dark navx">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(url_path('index.php')) ?>">SwiftDrop M2</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="<?= e(url_path('bookings/discover.php?booking_id=1')) ?>">Discover Riders</a>
      <a class="nav-link" href="<?= e(url_path('logout.php')) ?>">Logout</a>
    </div>
  </div>
</nav>
<div class="container py-5">
  <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
  <div class="cardx p-4 mb-4">
    <h1 class="h3 fw-bold">Hello, <?= e($user['full_name']) ?></h1>
    <p class="text-soft mb-0">Choose a submitted booking and dispatch it to a nearby rider.</p>
  </div>
  <div class="cardx p-4">
    <h2 class="h5 mb-3">Your bookings</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Code</th><th>Recipient</th><th>Pickup</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($bookings as $booking): ?>
          <tr>
            <td><?= e($booking['booking_code']) ?></td>
            <td><?= e($booking['recipient_name']) ?></td>
            <td><?= e($booking['pickup_address']) ?></td>
            <td><span class="badge text-bg-<?= $booking['booking_status'] === 'matched' ? 'success' : 'secondary' ?>"><?= e($booking['booking_status']) ?></span></td>
            <td><a class="btn btn-sm btn-primary" href="<?= e(url_path('bookings/discover.php?booking_id=' . $booking['id'])) ?>">Find Riders</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body></html>
