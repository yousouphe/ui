<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['super_admin']);
require_once __DIR__ . '/../config/db.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $minimumFee = (float) ($_POST['minimum_fee'] ?? 0);
    $perKmRate = (float) ($_POST['per_km_rate'] ?? 0);
    $bikeMultiplier = (float) ($_POST['bike_multiplier'] ?? 0);
    $carMultiplier = (float) ($_POST['car_multiplier'] ?? 0);
    $vanMultiplier = (float) ($_POST['van_multiplier'] ?? 0);
    $taxPercent = (float) ($_POST['tax_percent'] ?? 0);

    $errors = [];
    if ($minimumFee < 0) $errors[] = t('admin.pricing.error_minimum_fee');
    if ($perKmRate < 0) $errors[] = t('admin.pricing.error_per_km_rate');
    if ($bikeMultiplier <= 0 || $carMultiplier <= 0 || $vanMultiplier <= 0) $errors[] = t('admin.pricing.error_multiplier');
    if ($taxPercent < 0 || $taxPercent > 100) $errors[] = t('admin.pricing.error_tax_percent');

    if ($errors) {
        flash('error', implode(' ', $errors));
    } else {
        $stmt = $pdo->prepare('
            UPDATE pricing_settings
            SET minimum_fee = ?, per_km_rate = ?, bike_multiplier = ?, car_multiplier = ?, van_multiplier = ?, tax_percent = ?, updated_by = ?
            WHERE id = 1
        ');
        $stmt->execute([$minimumFee, $perKmRate, $bikeMultiplier, $carMultiplier, $vanMultiplier, $taxPercent, $user['id']]);
        log_event($pdo, 'pricing_updated', 'Delivery pricing settings updated', (int) $user['id'], (string) $user['role'], 'pricing_settings', 1, [
            'minimum_fee' => $minimumFee, 'per_km_rate' => $perKmRate,
            'bike_multiplier' => $bikeMultiplier, 'car_multiplier' => $carMultiplier, 'van_multiplier' => $vanMultiplier,
            'tax_percent' => $taxPercent,
        ]);
        flash('success', t('admin.pricing.saved'));
    }
    redirect_to('admin/pricing.php');
}

$settings = pricing_settings($pdo);

// A couple of worked examples at the current settings so a super admin can sanity-check
// the numbers without doing the arithmetic themselves.
$sampleDistances = [0.5, 3, 10];
$sampleQuotes = [];
foreach ($sampleDistances as $km) {
    $sampleQuotes[] = [
        'distance_km' => $km,
        'bike' => calculate_delivery_price($pdo, $km, 'bike'),
        'car' => calculate_delivery_price($pdo, $km, 'car'),
        'van' => calculate_delivery_price($pdo, $km, 'van'),
    ];
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('admin.pricing.heading')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .form-control{background:#ffffff;color:#0f2c44;border-color:rgba(15,42,68,.12)}
        .form-control:focus{background:#ffffff;color:#0f2c44;border-color:#38bdf8;box-shadow:0 0 0 .2rem rgba(110,168,254,.18)}
        .quote-table th, .quote-table td{padding:.5rem .75rem}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/bookings.php')) ?>"><?= e(t('admin.nav_bookings')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/riders.php')) ?>"><?= e(t('admin.nav_riders')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/complaints.php')) ?>"><?= e(t('admin.nav_complaints')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/users.php')) ?>"><?= e(t('admin.nav_users')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/pricing.php')) ?>"><?= e(t('admin.nav_pricing')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/logs.php')) ?>"><?= e(t('admin.nav_logs')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/profile.php')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-2"><?= e(t('admin.pricing.heading')) ?></h1>
    <p class="text-soft mb-4"><?= e(t('admin.pricing.subheading')) ?></p>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="cardx p-4">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('admin.pricing.minimum_fee_label')) ?></label>
                        <input class="form-control" type="number" step="0.01" min="0" name="minimum_fee" value="<?= e((string) $settings['minimum_fee']) ?>" required>
                        <div class="form-text text-soft"><?= e(t('admin.pricing.minimum_fee_hint')) ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('admin.pricing.per_km_rate_label')) ?></label>
                        <input class="form-control" type="number" step="0.01" min="0" name="per_km_rate" value="<?= e((string) $settings['per_km_rate']) ?>" required>
                        <div class="form-text text-soft"><?= e(t('admin.pricing.per_km_rate_hint')) ?></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label small"><?= e(t('vehicle.bike')) ?></label>
                            <input class="form-control" type="number" step="0.01" min="0.01" name="bike_multiplier" value="<?= e((string) $settings['bike_multiplier']) ?>" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small"><?= e(t('vehicle.car')) ?></label>
                            <input class="form-control" type="number" step="0.01" min="0.01" name="car_multiplier" value="<?= e((string) $settings['car_multiplier']) ?>" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small"><?= e(t('vehicle.van')) ?></label>
                            <input class="form-control" type="number" step="0.01" min="0.01" name="van_multiplier" value="<?= e((string) $settings['van_multiplier']) ?>" required>
                        </div>
                        <div class="form-text text-soft"><?= e(t('admin.pricing.multiplier_hint')) ?></div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><?= e(t('admin.pricing.tax_percent_label')) ?></label>
                        <input class="form-control" type="number" step="0.01" min="0" max="100" name="tax_percent" value="<?= e((string) $settings['tax_percent']) ?>" required>
                        <div class="form-text text-soft"><?= e(t('admin.pricing.tax_percent_hint')) ?></div>
                    </div>
                    <button class="btn btn-primary fw-bold" type="submit"><?= e(t('admin.pricing.save_button')) ?></button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="cardx p-4">
                <h2 class="h6 fw-bold mb-3"><?= e(t('admin.pricing.preview_heading')) ?></h2>
                <div class="table-responsive">
                    <table class="table quote-table small mb-0">
                        <thead>
                            <tr>
                                <th><?= e(t('admin.pricing.distance_column')) ?></th>
                                <th><?= e(t('vehicle.bike')) ?></th>
                                <th><?= e(t('vehicle.car')) ?></th>
                                <th><?= e(t('vehicle.van')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sampleQuotes as $q): ?>
                                <tr>
                                    <td><?= e(number_format($q['distance_km'], 1)) ?> km</td>
                                    <td>₦<?= number_format($q['bike']['total'], 2) ?></td>
                                    <td>₦<?= number_format($q['car']['total'], 2) ?></td>
                                    <td>₦<?= number_format($q['van']['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-soft small mt-3 mb-0"><?= e(t('admin.pricing.preview_hint')) ?></p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
