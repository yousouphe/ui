<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['sender','admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$bookingId = (int)($_GET['booking_id'] ?? 0);

$stmt = $pdo->prepare('SELECT b.*, u.full_name AS rider_name, u.phone AS rider_phone
                       FROM bookings b
                       LEFT JOIN users u ON u.id = b.selected_rider_user_id
                       WHERE b.id = ? AND b.sender_user_id = ? LIMIT 1');
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch();
if (!$booking) exit('Booking not found.');

$stmt = $pdo->prepare('SELECT * FROM rider_requests WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$bookingId]);
$request = $stmt->fetch();
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Request Status</title>
<base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:linear-gradient(180deg,#09101d,#0d1530 42%,#0b1020);min-height:100vh;color:#eef4ff}
.cardx{background:rgba(17,27,51,.92);border:1px solid rgba(255,255,255,.08);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
.text-soft{color:#9fb0d6}
</style>
</head><body>
<div class="container py-5">
  <div class="cardx p-4 p-lg-5">
    <h1 class="h3 fw-bold mb-2">Request status for <?= e($booking['booking_code']) ?></h1>
    <p class="text-soft">Selected rider: <?= e((string)($booking['rider_name'] ?? 'Not assigned')) ?></p>

    <?php if ($request): ?>
      <div class="alert alert-<?= $request['request_status'] === 'accepted' ? 'success' : ($request['request_status'] === 'rejected' ? 'danger' : 'info') ?>">
        Current request status: <strong><?= e($request['request_status']) ?></strong>
      </div>
      <div class="row g-4">
        <div class="col-md-6"><div class="cardx p-3"><strong>Proposed cost</strong><div class="text-soft">₦<?= e(number_format((float)$request['proposed_cost'], 2)) ?></div></div></div>
        <div class="col-md-6"><div class="cardx p-3"><strong>Rider response note</strong><div class="text-soft"><?= e((string)($request['rider_response_note'] ?: 'No note yet')) ?></div></div></div>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">No active rider request found.</div>
    <?php endif; ?>

    <div class="mt-4">
      <a class="btn btn-primary" href="<?= e(url_path('dashboard.php')) ?>">Back to Dashboard</a>
      <a class="btn btn-outline-light" href="<?= e(url_path('bookings/discover.php?booking_id=' . $booking['id'])) ?>">Choose Another Rider</a>
    </div>
  </div>
</div>
</body></html>
