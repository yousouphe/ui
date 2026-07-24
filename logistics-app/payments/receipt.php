<?php
// Professional, printable payment receipt (module19). Reachable by the customer who paid, the rider
// on the delivery, or an admin. Supports view, browser print / save-as-PDF, and "resend to email".
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/receipts.php';
require_once __DIR__ . '/../config/emails.php';

$user = current_user();
$isAdmin = in_array($user['role'], ['admin', 'super_admin'], true);

// Look up the receipt by number (preferred) or booking id.
$receiptNumber = trim((string) ($_GET['no'] ?? ''));
$bookingId = (int) ($_GET['booking_id'] ?? 0);
$receipt = $receiptNumber !== '' ? find_receipt_by_number($pdo, $receiptNumber)
    : ($bookingId > 0 ? get_receipt_for_booking($pdo, $bookingId) : null);

if (!$receipt) {
    http_response_code(404);
    $error = t('receipt.not_found');
} else {
    // Authorise: customer, the assigned rider, or an admin may view.
    $ownsIt = (int) ($receipt['customer_user_id'] ?? 0) === (int) $user['id']
        || (int) ($receipt['rider_user_id'] ?? 0) === (int) $user['id']
        || $isAdmin;
    if (!$ownsIt) {
        http_response_code(403);
        $receipt = null;
        $error = t('receipt.forbidden');
    }
}

// Resend the receipt email to the customer on record.
if ($receipt && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ((string) ($_POST['action'] ?? '') === 'resend') {
        send_transaction_receipt_email(
            (string) $receipt['customer_email'],
            (string) $receipt['customer_name'],
            ['booking_code' => (string) $receipt['order_code'], 'item_name' => t('receipt.item_generic'), 'agreed_cost' => (float) $receipt['total_amount']],
            (string) $receipt['payment_reference']
        );
        audit_financial_event($pdo, 'receipt_email_sent', 'Receipt ' . $receipt['receipt_number'] . ' resent to ' . $receipt['customer_email'], (int) $user['id'], (string) $user['role'], (int) $receipt['booking_id'], (string) $receipt['payment_reference'], ['receipt_number' => $receipt['receipt_number'], 'resend' => true]);
        flash('success', t('receipt.resent'));
        redirect_to('payments/receipt.php?no=' . urlencode((string) $receipt['receipt_number']));
    }
}

$success = flash('success');
$hubUrl = $isAdmin ? 'admin/index.php' : ($user['role'] === 'rider' ? 'rider/index.php' : 'bookings/index.php');
$money = static fn(float $n): string => '₦' . number_format($n, 2);
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('receipt.title')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .receipt{background:#fff;border:1px solid rgba(15,42,68,.10);border-radius:1rem;box-shadow:0 18px 40px rgba(0,0,0,.14);max-width:720px;margin:0 auto}
        .receipt-head{background:linear-gradient(120deg,#0b6ec9,#38bdf8);color:#fff;border-radius:1rem 1rem 0 0;padding:1.5rem}
        .wordmark{font-weight:800;letter-spacing:2px;font-size:1.6rem}
        .text-soft{color:#5c7a91}
        .kv{display:flex;justify-content:space-between;gap:1rem;padding:.4rem 0;border-bottom:1px dashed rgba(15,42,68,.10)}
        .kv .k{color:#5c7a91}
        .kv .v{font-weight:600;text-align:right}
        .total-row{font-size:1.15rem;font-weight:800}
        .badge-paid{background:#dcfce7;color:#166534;border-radius:999px;padding:.25rem .75rem;font-weight:700}
        @media print{
            body{background:#fff}
            .no-print{display:none !important}
            .receipt{box-shadow:none;border:none;max-width:100%}
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx no-print">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path($hubUrl)) ?>"><?= e(t('common.brand')) ?></a>
        <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path($hubUrl)) ?>"><i class="fa-solid fa-house me-1"></i><?= e(t('nav.dashboard')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <?php if ($success): ?><div class="alert alert-success border-0 mb-4 no-print" style="max-width:720px;margin:0 auto 1rem"><?= e($success) ?></div><?php endif; ?>

    <?php if (empty($receipt)): ?>
        <div class="alert alert-danger border-0" style="max-width:720px;margin:0 auto"><?= e($error ?? t('receipt.not_found')) ?></div>
    <?php else: ?>
        <div class="receipt mb-4">
            <div class="receipt-head d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="wordmark">AIKE</div>
                    <div class="small opacity-75"><?= e(t('receipt.subtitle')) ?></div>
                </div>
                <div class="text-end">
                    <div class="small opacity-75"><?= e(t('receipt.receipt_no')) ?></div>
                    <div class="fw-bold"><?= e($receipt['receipt_number']) ?></div>
                </div>
            </div>
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge-paid"><i class="fa-solid fa-circle-check me-1"></i><?= e(t('receipt.status_paid')) ?></span>
                    <span class="text-soft small"><?= e(date('d M Y, h:i A', strtotime((string) $receipt['created_at']))) ?></span>
                </div>

                <div class="kv"><span class="k"><?= e(t('receipt.order_no')) ?></span><span class="v"><?= e((string) $receipt['order_code']) ?></span></div>
                <div class="kv"><span class="k"><?= e(t('receipt.reference')) ?></span><span class="v"><?= e((string) $receipt['payment_reference']) ?></span></div>
                <div class="kv"><span class="k"><?= e(t('receipt.customer')) ?></span><span class="v"><?= e((string) $receipt['customer_name']) ?></span></div>
                <?php if (!empty($receipt['rider_name'])): ?>
                <div class="kv"><span class="k"><?= e(t('receipt.rider')) ?></span><span class="v"><?= e((string) $receipt['rider_name']) ?></span></div>
                <?php endif; ?>
                <div class="kv"><span class="k"><?= e(t('receipt.pickup')) ?></span><span class="v"><?= e((string) $receipt['pickup_address']) ?></span></div>
                <div class="kv"><span class="k"><?= e(t('receipt.delivery')) ?></span><span class="v"><?= e((string) $receipt['delivery_address']) ?></span></div>
                <div class="kv"><span class="k"><?= e(t('receipt.method')) ?></span><span class="v text-capitalize"><?= e((string) $receipt['payment_method']) ?></span></div>

                <div class="mt-3">
                    <div class="kv"><span class="k"><?= e(t('receipt.amount_net')) ?></span><span class="v"><?= e($money((float) $receipt['amount'])) ?></span></div>
                    <?php if ((float) $receipt['vat_amount'] > 0): ?>
                    <div class="kv"><span class="k"><?= e(t('receipt.vat')) ?> (<?= e(rtrim(rtrim(number_format((float) $receipt['vat_percent'], 2), '0'), '.')) ?>%)</span><span class="v"><?= e($money((float) $receipt['vat_amount'])) ?></span></div>
                    <?php endif; ?>
                    <div class="kv total-row"><span class="k text-dark"><?= e(t('receipt.total_paid')) ?></span><span class="v"><?= e($money((float) $receipt['total_amount'])) ?></span></div>
                </div>

                <p class="text-soft small mt-4 mb-0 text-center"><?= e(t('receipt.footer')) ?></p>
            </div>
        </div>

        <div class="d-flex gap-2 justify-content-center flex-wrap no-print">
            <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i><?= e(t('receipt.print')) ?></button>
            <button type="button" class="btn btn-outline-primary" onclick="window.print()"><i class="fa-solid fa-file-pdf me-1"></i><?= e(t('receipt.download_pdf')) ?></button>
            <form method="post" action="<?= e(url_path('payments/receipt.php?no=' . urlencode((string) $receipt['receipt_number']))) ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn btn-outline-secondary"><i class="fa-solid fa-envelope me-1"></i><?= e(t('receipt.resend')) ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
