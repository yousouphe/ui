<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);

$user = current_user();
$sections = [
    [
        'icon' => 'fa-handshake',
        'title_key' => 'training.section.professionalism_title',
        'items_key' => 'training.section.professionalism_items',
    ],
    [
        'icon' => 'fa-comments',
        'title_key' => 'training.section.communication_title',
        'items_key' => 'training.section.communication_items',
    ],
    [
        'icon' => 'fa-box',
        'title_key' => 'training.section.handling_title',
        'items_key' => 'training.section.handling_items',
    ],
    [
        'icon' => 'fa-shield-halved',
        'title_key' => 'training.section.safety_title',
        'items_key' => 'training.section.safety_items',
    ],
    [
        'icon' => 'fa-star',
        'title_key' => 'training.section.rating_title',
        'items_key' => 'training.section.rating_items',
    ],
    [
        'icon' => 'fa-circle-exclamation',
        'title_key' => 'training.section.conduct_title',
        'items_key' => 'training.section.conduct_items',
    ],
];
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e(t('training.heading')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1.25rem;box-shadow:0 18px 40px rgba(0,0,0,.22)}
        .text-soft{color:#5c7a91}
        .section-icon{width:44px;height:44px;border-radius:50%;background:rgba(56,189,248,.15);color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('rider/')) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('rider/')) ?>"><i class="fa-solid fa-house me-1"></i><?= e(t('nav.dashboard')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/dashboard')) ?>"><i class="fa-solid fa-list-ul me-1"></i><?= e(t('nav.my_deliveries')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/wallet')) ?>"><i class="fa-solid fa-wallet me-1"></i><?= e(t('wallet.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('rider/kyc.php')) ?>"><i class="fa-solid fa-id-card me-1"></i><?= e(t('kyc.nav_label')) ?></a>
            <a class="nav-link active" href="<?= e(url_path('rider/training.php')) ?>"><i class="fa-solid fa-graduation-cap me-1"></i><?= e(t('training.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('profile')) ?>"><i class="fa-solid fa-user me-1"></i><?= e(t('profile.nav_label')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-2"><?= e(t('training.heading')) ?></h1>
    <p class="text-soft mb-4"><?= e(t('training.subheading')) ?></p>

    <div class="row g-4">
        <?php foreach ($sections as $section): ?>
            <div class="col-md-6">
                <div class="cardx p-4 h-100">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="section-icon"><i class="fa-solid <?= e($section['icon']) ?>"></i></div>
                        <h2 class="h5 fw-bold mb-0"><?= e(t($section['title_key'])) ?></h2>
                    </div>
                    <ul class="text-soft mb-0">
                        <?php foreach (explode('|', t($section['items_key'])) as $item): ?>
                            <li class="mb-2"><?= e(trim($item)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
