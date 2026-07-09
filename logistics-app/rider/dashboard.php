<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');

$stmt = $pdo->prepare('SELECT rr.*, b.booking_code, b.pickup_address, b.delivery_address, b.item_name, b.item_category, s.full_name AS sender_name, s.phone AS sender_phone
                       FROM rider_requests rr
                       INNER JOIN bookings b ON b.id = rr.booking_id
                       INNER JOIN users s ON s.id = rr.sender_user_id
                       WHERE rr.rider_user_id = ?
                       ORDER BY FIELD(rr.request_status, "pending","accepted","rejected","expired","cancelled"), rr.id DESC');
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT rp.* FROM rider_profiles rp WHERE rp.user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rider Dashboard</title>
<base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
.navx{background:rgba(8,17,33,.88);border-bottom:1px solid rgba(255,255,255,.08)}
.cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
.text-soft{color:#9fb0d6}
.table{--bs-table-bg:transparent;--bs-table-color:#eef4ff;--bs-table-border-color:rgba(255,255,255,.08)}
</style>
</head><body>
<nav class="navbar navbar-expand-lg navbar-dark navx">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(url_path('rider/dashboard.php')) ?>">Rider Dashboard</a>
    <div class="navbar-nav ms-auto">
      <a class="nav-link" href="<?= e(url_path('rider/update_location.php')) ?>">Update Location</a>
      <a class="nav-link" href="<?= e(url_path('logout.php')) ?>">Logout</a>
    </div>
  </div>
</nav>
<div class="container py-5">
  <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
  <div class="cardx p-4 mb-4">
    <div class="row g-3">
      <div class="col-lg-8">
        <h1 class="h3 fw-bold">Hello, <?= e($user['full_name']) ?></h1>
        <p class="text-soft mb-0">Review incoming delivery requests and accept or reject them.</p>
      </div>
      <div class="col-lg-4 text-lg-end">
        <span class="badge text-bg-<?= ($profile['availability_status'] ?? 'offline') === 'available' ? 'success' : 'secondary' ?>">
          <?= e((string)($profile['availability_status'] ?? 'offline')) ?>
        </span>
      </div>
    </div>
  </div>

  <div class="cardx p-4">
    <h2 class="h5 mb-3">Incoming requests</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Booking</th><th>Pickup</th><th>Delivery</th><th>Cost</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $request): ?>
          <tr>
            <td>
              <?= e($request['booking_code']) ?><br>
              <small class="text-soft"><?= e($request['sender_name']) ?> · <?= e($request['sender_phone']) ?></small>
            </td>
            <td><?= e($request['pickup_address']) ?></td>
            <td><?= e($request['delivery_address']) ?></td>
            <td>₦<?= e(number_format((float)$request['proposed_cost'], 2)) ?></td>
            <td><span class="badge text-bg-<?= $request['request_status'] === 'accepted' ? 'success' : ($request['request_status'] === 'rejected' ? 'danger' : 'info') ?>"><?= e($request['request_status']) ?></span></td>
            <td>
              <?php if ($request['request_status'] === 'pending'): ?>
                <form class="d-flex gap-2 flex-wrap" method="post" action="<?= e(url_path('rider/respond_request.php')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                  <button class="btn btn-sm btn-success" type="submit" name="action" value="accepted">Accept</button>
                  <button class="btn btn-sm btn-danger" type="submit" name="action" value="rejected">Reject</button>
                </form>
              <?php else: ?>
                <span class="text-soft">No action</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body></html>
