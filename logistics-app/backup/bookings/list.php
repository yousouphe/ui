<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$stmt = $pdo->prepare('SELECT * FROM bookings WHERE sender_user_id = ? ORDER BY id DESC');
$stmt->execute([$user['id']]);
$bookings = $stmt->fetchAll();
$success = flash('success');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Bookings</title>
  <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
    .navx{background:rgba(8,17,33,.88);border-bottom:1px solid rgba(255,255,255,.08)}
    .cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
    .table{--bs-table-bg:transparent;--bs-table-color:#eef4ff;--bs-table-border-color:rgba(255,255,255,.08)}
    .thumb{width:64px;height:64px;object-fit:cover;border-radius:.75rem}
    .text-soft{color:#9fb0d6}
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navx">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(url_path('index.php')) ?>">SwiftDrop</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="<?= e(url_path('dashboard.php')) ?>">Dashboard</a>
      <a class="nav-link" href="<?= e(url_path('bookings/create.php')) ?>">New Booking</a>
      <a class="nav-link" href="<?= e(url_path('logout.php')) ?>">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-5">
  <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
  <div class="cardx p-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
      <div>
        <h1 class="h3 fw-bold mb-1">My bookings</h1>
        <p class="text-soft mb-0">These requests will feed directly into rider matching in Module 2.</p>
      </div>
      <a class="btn btn-primary" href="<?= e(url_path('bookings/create.php')) ?>">Create Booking</a>
    </div>

    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th>Code</th>
            <th>Recipient</th>
            <th>Pickup</th>
            <th>Delivery</th>
            <th>Item</th>
            <th>Status</th>
            <th>Image</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$bookings): ?>
            <tr><td colspan="8" class="text-soft">No bookings yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($bookings as $booking): ?>
            <tr>
              <td><?= e($booking['booking_code']) ?></td>
              <td><?= e($booking['recipient_name']) ?><br><small class="text-soft"><?= e($booking['recipient_phone']) ?></small></td>
              <td><?= e($booking['pickup_address']) ?></td>
              <td><?= e($booking['delivery_address']) ?></td>
              <td><?= e($booking['item_name']) ?><br><small class="text-soft"><?= e($booking['item_category']) ?></small></td>
              <td><span class="badge text-bg-<?= $booking['booking_status'] === 'submitted' ? 'primary' : 'secondary' ?>"><?= e($booking['booking_status']) ?></span></td>
              <td>
                <?php if ($booking['item_image_path']): ?>
                  <a href="<?= e(url_path($booking['item_image_path'])) ?>" target="_blank">
                    <img class="thumb" src="<?= e(url_path($booking['item_image_path'])) ?>" alt="Item image">
                  </a>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td><?= e($booking['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
