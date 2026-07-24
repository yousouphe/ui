<?php
// Admin transaction management (module19, spec §1). View any user's or rider's complete financial
// history — payments (with Paystack reference + status), wallet credits/debits, withdrawals/payouts,
// refunds, and timestamps/metadata. Wallet BALANCES are sensitive: revealing one requires a
// one-time code emailed to the admin (see config/otp.php), and every view attempt is audited.
require_once __DIR__ . '/../config/functions.php';
require_role(['admin', 'super_admin']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/otp.php';

$admin = current_user();
$success = flash('success');
$error = flash('error');

$money = static fn($n): string => '₦' . number_format((float) $n, 2);
$mask = static fn(string $acct): string => strlen($acct) > 4 ? str_repeat('*', strlen($acct) - 4) . substr($acct, -4) : $acct;

// Resolve the target user being inspected.
$targetId = (int) ($_GET['user_id'] ?? 0);
$target = null;
if ($targetId > 0) {
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, role, status, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$targetType = $target && $target['role'] === 'rider' ? 'rider' : 'user';

// POST: OTP request / verify (balance gate).
if ($target && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');
    if ($formAction === 'request_otp') {
        $res = generate_admin_balance_otp($pdo, $admin, $targetType, (int) $target['id']);
        flash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect_to('admin/transactions.php?user_id=' . (int) $target['id'] . '&otp=1');
    } elseif ($formAction === 'verify_otp') {
        $res = verify_admin_balance_otp($pdo, $admin, $targetType, (int) $target['id'], (string) ($_POST['code'] ?? ''));
        if ($res['ok']) {
            $_SESSION['balance_unlock'][(int) $target['id']] = time();
            flash('success', $res['message']);
            redirect_to('admin/transactions.php?user_id=' . (int) $target['id']);
        }
        flash('error', $res['message']);
        redirect_to('admin/transactions.php?user_id=' . (int) $target['id'] . '&otp=1');
    }
}

// Is the balance currently unlocked for this target? (5-minute window after a verified OTP.)
$balanceUnlocked = $target
    && isset($_SESSION['balance_unlock'][(int) $target['id']])
    && (time() - (int) $_SESSION['balance_unlock'][(int) $target['id']]) <= ADMIN_OTP_TTL_SECONDS;
$otpPrompt = isset($_GET['otp']);

// Search box results (name/email) when no specific target is selected.
$q = trim((string) ($_GET['q'] ?? ''));
$results = [];
if (!$target) {
    if ($q !== '') {
        $stmt = $pdo->prepare("SELECT id, full_name, email, role FROM users
            WHERE full_name LIKE ? OR email LIKE ? ORDER BY full_name LIMIT 25");
        $stmt->execute(['%' . $q . '%', '%' . $q . '%']);
    } else {
        $stmt = $pdo->query("SELECT id, full_name, email, role FROM users
            WHERE role IN ('sender','rider') ORDER BY created_at DESC LIMIT 25");
    }
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Assemble the target's transaction history + balances.
$payments = $walletTx = $withdrawals = [];
$balance = ['wallet' => 0.0, 'available' => 0.0, 'paid' => 0.0, 'refunded' => 0.0];
if ($target) {
    if ($targetType === 'rider') {
        $balance['wallet'] = rider_wallet_balance($pdo, (int) $target['id']);
        $balance['available'] = rider_available_balance($pdo, (int) $target['id']);
        $stmt = $pdo->prepare('SELECT wt.type, wt.amount, wt.description, wt.created_at, wt.booking_id, b.booking_code
            FROM wallet_transactions wt LEFT JOIN bookings b ON b.id = wt.booking_id
            WHERE wt.rider_user_id = ? ORDER BY wt.id DESC LIMIT 100');
        $stmt->execute([(int) $target['id']]);
        $walletTx = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT amount, status, bank_name, account_number, paystack_transfer_reference, requested_at, processed_at, admin_note
            FROM withdrawal_requests WHERE rider_user_id = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([(int) $target['id']]);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT bp.amount, bp.currency, bp.reference, bp.status, bp.paid_at, bp.created_at,
                bp.refund_status, bp.refund_amount, bp.refunded_at, b.booking_code, b.payment_status
            FROM booking_payments bp INNER JOIN bookings b ON b.id = bp.booking_id
            WHERE bp.user_id = ? ORDER BY bp.id DESC LIMIT 100");
        $stmt->execute([(int) $target['id']]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $balance['paid'] = (float) ($pdo->query("SELECT COALESCE(SUM(agreed_cost),0) FROM bookings WHERE sender_user_id = " . (int) $target['id'] . " AND payment_status = 'paid'")->fetchColumn());
        $balance['refunded'] = (float) ($pdo->query("SELECT COALESCE(SUM(refund_amount),0) FROM booking_payments WHERE user_id = " . (int) $target['id'] . " AND refund_status <> 'none'")->fetchColumn());
    }
}

$statusBadge = static function (string $s): string {
    $map = ['success' => 'success', 'paid' => 'success', 'initialized' => 'secondary', 'pending' => 'warning', 'processing' => 'info', 'rejected' => 'danger', 'failed' => 'danger'];
    $cls = $map[$s] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . e(ucfirst($s)) . '</span>';
};
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('admin.tx.title')) ?> | Aike</title>
    <base href="<?= e((base_url() === '' ? '/' : base_url() . '/')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);min-height:100vh;color:#0f2c44}
        .navx{background:rgba(255,255,255,.85);border-bottom:1px solid rgba(15,42,68,.10)}
        .cardx{background:rgba(255,255,255,.95);border:1px solid rgba(15,42,68,.10);border-radius:1rem;box-shadow:0 10px 30px rgba(0,0,0,.10)}
        .text-soft{color:#5c7a91}
        .table thead th{color:#5c7a91;font-weight:600;font-size:.82rem;text-transform:uppercase;letter-spacing:.03em}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.85rem}
        .balance-blur{filter:blur(7px);user-select:none}
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light navx">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= e(url_path('admin/')) ?>"><?= e(t('common.brand')) ?> <?= e(t('admin.brand_suffix')) ?></a>
        <div class="navbar-nav ms-auto flex-row flex-wrap gap-3 align-items-lg-center">
            <a class="nav-link" href="<?= e(url_path('admin/')) ?>"><?= e(t('admin.nav_withdrawals')) ?></a>
            <a class="nav-link fw-bold" href="<?= e(url_path('admin/transactions.php')) ?>"><?= e(t('admin.nav_transactions')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/bookings.php')) ?>"><?= e(t('admin.nav_bookings')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/users.php')) ?>"><?= e(t('admin.nav_users')) ?></a>
            <a class="nav-link" href="<?= e(url_path('admin/logs.php')) ?>"><?= e(t('admin.nav_logs')) ?></a>
            <a class="nav-link" href="<?= e(url_path('logout')) ?>"><?= e(t('common.logout')) ?></a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('admin.tx.title')) ?></h1>
    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$target): ?>
        <div class="cardx p-4 mb-4">
            <form method="get" class="d-flex gap-2 flex-wrap">
                <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('admin.tx.search_placeholder')) ?>" style="max-width:420px">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i><?= e(t('admin.tx.search')) ?></button>
            </form>
        </div>
        <div class="cardx p-0">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th class="ps-3"><?= e(t('admin.tx.col_name')) ?></th><th><?= e(t('admin.tx.col_email')) ?></th><th><?= e(t('admin.tx.col_role')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= e($r['full_name']) ?></td>
                        <td class="text-soft"><?= e($r['email']) ?></td>
                        <td><span class="badge text-bg-light text-capitalize"><?= e($r['role']) ?></span></td>
                        <td class="text-end pe-3"><a class="btn btn-sm btn-outline-primary" href="<?= e(url_path('admin/transactions.php?user_id=' . (int) $r['id'])) ?>"><?= e(t('admin.tx.view')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$results): ?><tr><td colspan="4" class="text-center text-soft py-4"><?= e(t('admin.tx.no_results')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <a class="btn btn-sm btn-outline-secondary mb-3" href="<?= e(url_path('admin/transactions.php')) ?>"><i class="fa-solid fa-arrow-left me-1"></i><?= e(t('admin.tx.back')) ?></a>

        <!-- Target profile + balance -->
        <div class="cardx p-4 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="h5 fw-bold mb-1"><?= e($target['full_name']) ?> <span class="badge text-bg-light text-capitalize align-middle"><?= e($target['role']) ?></span></div>
                    <div class="text-soft small"><?= e($target['email']) ?> · <?= e($target['phone']) ?></div>
                    <div class="text-soft small"><?= e(t('admin.tx.wallet_id')) ?>: <span class="mono">AIKE-W-<?= str_pad((string) $target['id'], 6, '0', STR_PAD_LEFT) ?></span></div>
                </div>
                <div class="text-end">
                    <div class="text-soft small mb-1"><?= e($targetType === 'rider' ? t('admin.tx.wallet_balance') : t('admin.tx.spend_summary')) ?></div>
                    <?php if ($balanceUnlocked): ?>
                        <?php if ($targetType === 'rider'): ?>
                            <div class="h4 fw-bold text-success mb-0"><?= e($money($balance['wallet'])) ?></div>
                            <div class="small text-soft"><?= e(t('admin.tx.available')) ?>: <?= e($money($balance['available'])) ?></div>
                        <?php else: ?>
                            <div class="h4 fw-bold mb-0"><?= e($money($balance['paid'])) ?></div>
                            <div class="small text-soft"><?= e(t('admin.tx.refunded')) ?>: <?= e($money($balance['refunded'])) ?></div>
                        <?php endif; ?>
                        <div class="small text-success"><i class="fa-solid fa-lock-open me-1"></i><?= e(t('admin.tx.unlocked')) ?></div>
                    <?php else: ?>
                        <div class="h4 fw-bold balance-blur mb-0">₦0000.00</div>
                        <?php if ($otpPrompt): ?>
                            <form method="post" class="mt-2 d-flex gap-2 justify-content-end" action="<?= e(url_path('admin/transactions.php?user_id=' . (int) $target['id'])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="verify_otp">
                                <input class="form-control form-control-sm mono" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" style="max-width:120px" autofocus>
                                <button class="btn btn-sm btn-primary" type="submit"><?= e(t('admin.tx.verify')) ?></button>
                            </form>
                            <div class="small text-soft mt-1"><?= e(t('admin.tx.otp_hint')) ?></div>
                        <?php else: ?>
                            <form method="post" class="mt-2" action="<?= e(url_path('admin/transactions.php?user_id=' . (int) $target['id'])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="request_otp">
                                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="fa-solid fa-shield-halved me-1"></i><?= e(t('admin.tx.view_balance')) ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($targetType === 'rider'): ?>
            <!-- Rider wallet ledger -->
            <div class="cardx p-0 mb-4">
                <div class="p-3 fw-bold border-bottom"><?= e(t('admin.tx.wallet_ledger')) ?></div>
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th class="ps-3"><?= e(t('admin.tx.col_date')) ?></th><th><?= e(t('admin.tx.col_type')) ?></th><th><?= e(t('admin.tx.col_desc')) ?></th><th><?= e(t('admin.tx.col_order')) ?></th><th class="text-end pe-3"><?= e(t('admin.tx.col_amount')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($walletTx as $w): $credit = $w['type'] === 'earning'; ?>
                        <tr>
                            <td class="ps-3 text-soft small"><?= e(date('d M Y H:i', strtotime((string) $w['created_at']))) ?></td>
                            <td><span class="badge text-bg-<?= $credit ? 'success' : 'danger' ?>"><?= e($credit ? t('admin.tx.credit') : t('admin.tx.debit')) ?></span></td>
                            <td class="small"><?= e((string) $w['description']) ?></td>
                            <td class="mono small"><?= e((string) ($w['booking_code'] ?? '—')) ?></td>
                            <td class="text-end pe-3 fw-semibold <?= $credit ? 'text-success' : 'text-danger' ?>"><?= e(($credit ? '+' : '') . $money($w['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$walletTx): ?><tr><td colspan="5" class="text-center text-soft py-3"><?= e(t('admin.tx.none')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <!-- Rider withdrawals / payouts -->
            <div class="cardx p-0 mb-4">
                <div class="p-3 fw-bold border-bottom"><?= e(t('admin.tx.withdrawals')) ?></div>
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th class="ps-3"><?= e(t('admin.tx.col_requested')) ?></th><th><?= e(t('admin.tx.col_amount')) ?></th><th><?= e(t('admin.tx.col_status')) ?></th><th><?= e(t('admin.tx.col_bank')) ?></th><th><?= e(t('admin.tx.col_reference')) ?></th><th class="pe-3"><?= e(t('admin.tx.col_processed')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td class="ps-3 text-soft small"><?= e(date('d M Y H:i', strtotime((string) $w['requested_at']))) ?></td>
                            <td class="fw-semibold"><?= e($money($w['amount'])) ?></td>
                            <td><?= $statusBadge((string) $w['status']) ?></td>
                            <td class="small"><?= e((string) $w['bank_name']) ?><br><span class="mono text-soft"><?= e($mask((string) $w['account_number'])) ?></span></td>
                            <td class="mono small"><?= e((string) ($w['paystack_transfer_reference'] ?? '—')) ?></td>
                            <td class="pe-3 text-soft small"><?= e($w['processed_at'] ? date('d M Y H:i', strtotime((string) $w['processed_at'])) : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$withdrawals): ?><tr><td colspan="6" class="text-center text-soft py-3"><?= e(t('admin.tx.none')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php else: ?>
            <!-- Sender payments -->
            <div class="cardx p-0 mb-4">
                <div class="p-3 fw-bold border-bottom"><?= e(t('admin.tx.payments')) ?></div>
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th class="ps-3"><?= e(t('admin.tx.col_date')) ?></th><th><?= e(t('admin.tx.col_order')) ?></th><th><?= e(t('admin.tx.col_amount')) ?></th><th><?= e(t('admin.tx.col_status')) ?></th><th><?= e(t('admin.tx.col_reference')) ?></th><th class="pe-3"><?= e(t('admin.tx.col_refund')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="ps-3 text-soft small"><?= e(date('d M Y H:i', strtotime((string) ($p['paid_at'] ?: $p['created_at'])))) ?></td>
                            <td class="mono small"><?= e((string) $p['booking_code']) ?></td>
                            <td class="fw-semibold"><?= e($money($p['amount'])) ?></td>
                            <td><?= $statusBadge((string) $p['status']) ?></td>
                            <td class="mono small"><?= e((string) $p['reference']) ?></td>
                            <td class="pe-3 small"><?= ($p['refund_status'] ?? 'none') !== 'none' ? e(ucfirst((string) $p['refund_status']) . ' ' . $money($p['refund_amount'])) : '<span class="text-soft">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?><tr><td colspan="6" class="text-center text-soft py-3"><?= e(t('admin.tx.none')) ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
