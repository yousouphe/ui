<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/emails.php';
require_once __DIR__ . '/../config/paystack.php';
require_once __DIR__ . '/../config/rider_verification.php';

$user = current_user();
$success = flash('success');
$error = flash('error');

$stmt = $pdo->prepare('SELECT bank_name, bank_code, account_number, account_name, verified_at FROM rider_bank_accounts WHERE rider_user_id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$bankAccount = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'save_bank_account') {
        $bankCode = trim((string) ($_POST['bank_code'] ?? ''));
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));

        if ($bankCode === '' || $accountNumber === '') {
            flash('error', t('wallet.bank_details_required'));
            redirect_to('rider/wallet');
        }

        if (!ctype_digit($accountNumber)) {
            flash('error', t('wallet.account_number_invalid'));
            redirect_to('rider/wallet');
        }

        $bankStmt = $pdo->prepare('SELECT name FROM paystack_banks WHERE code = ? LIMIT 1');
        $bankStmt->execute([$bankCode]);
        $bankName = (string) ($bankStmt->fetchColumn() ?: '');
        if ($bankName === '') {
            flash('error', t('wallet.invalid_bank_selected'));
            redirect_to('rider/wallet');
        }

        // Authoritative check happens here regardless of what the client-side "Verify"
        // button showed - the resolved name from Paystack is what gets saved, never
        // whatever the rider might have typed.
        $verifyResult = paystack_resolve_account($accountNumber, $bankCode);
        if (!$verifyResult['ok']) {
            flash('error', t('wallet.account_verification_failed') . ' ' . ($verifyResult['message'] ?: ''));
            redirect_to('rider/wallet');
        }

        if (!names_match((string) $verifyResult['account_name'], (string) $user['full_name'])) {
            flash('error', t('wallet.bank_name_mismatch'));
            redirect_to('rider/wallet');
        }

        // Not saved yet - a verification code/link must be confirmed first (see the
        // confirm_verification handler below and confirm_rider_action.php for the email-link path).
        $verification = rider_verification_start($pdo, $user, 'bank_change', [
            'bankName' => $bankName,
            'bankCode' => $bankCode,
            'accountNumber' => $accountNumber,
            'accountName' => (string) $verifyResult['account_name'],
        ]);
        if (!$verification['ok']) {
            flash('error', $verification['message']);
            redirect_to('rider/wallet');
        }
        $_SESSION['pending_rider_verification_id'] = $verification['verificationId'];
        flash('success', $verification['message']);
        redirect_to('rider/wallet');
    }

    if ($formAction === 'request_withdrawal') {
        $amount = (float) ($_POST['amount'] ?? 0);

        if (!$bankAccount || empty($bankAccount['bank_code']) || empty($bankAccount['verified_at'])) {
            flash('error', t('wallet.add_bank_details_first'));
            redirect_to('rider/wallet');
        }
        if ($amount <= 0) {
            flash('error', t('wallet.invalid_withdrawal_amount'));
            redirect_to('rider/wallet');
        }
        // Fast-feedback pre-check only - the authoritative, row-locked check happens in
        // execute_rider_verified_action() at confirm time, not here, so a withdrawal can't be
        // created without the rider actually confirming via code or email link first.
        if ($amount > rider_available_balance($pdo, (int) $user['id'])) {
            flash('error', t('wallet.withdrawal_exceeds_balance'));
            redirect_to('rider/wallet');
        }

        $verification = rider_verification_start($pdo, $user, 'withdrawal', ['amount' => $amount]);
        if (!$verification['ok']) {
            flash('error', $verification['message']);
            redirect_to('rider/wallet');
        }
        $_SESSION['pending_rider_verification_id'] = $verification['verificationId'];
        flash('success', $verification['message']);
        redirect_to('rider/wallet');
    }

    if ($formAction === 'confirm_verification') {
        $verificationId = (int) ($_SESSION['pending_rider_verification_id'] ?? 0);
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($verificationId <= 0 || $code === '') {
            flash('error', t('wallet.verification_code_required'));
            redirect_to('rider/wallet');
        }
        $result = rider_verification_confirm_code($pdo, $user, $verificationId, $code);
        if (!$result['ok']) {
            flash('error', $result['message']);
            redirect_to('rider/wallet');
        }
        unset($_SESSION['pending_rider_verification_id']);
        $execResult = execute_rider_verified_action($pdo, (int) $user['id'], (string) $result['actionType'], (array) $result['payload']);
        if (!$execResult['ok']) {
            flash('error', $execResult['message']);
            redirect_to('rider/wallet');
        }
        if ($result['actionType'] === 'withdrawal') {
            notify_admins($pdo, 'New withdrawal request', '<p><strong>' . e((string) $user['full_name']) . '</strong> requested a withdrawal.</p><p>Review it from the admin portal.</p>');
        }
        flash('success', $execResult['message']);
        redirect_to('rider/wallet');
    }
}

$banks = paystack_banks_list($pdo);

$availableBalance = rider_available_balance($pdo, (int) $user['id']);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE rider_user_id = ? AND type = 'earning'");
$stmt->execute([$user['id']]);
$totalEarned = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(-amount), 0) FROM wallet_transactions WHERE rider_user_id = ? AND type = 'withdrawal'");
$stmt->execute([$user['id']]);
$totalWithdrawn = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('
    SELECT type, amount, description, created_at
    FROM wallet_transactions
    WHERE rider_user_id = ?
    ORDER BY id DESC
    LIMIT 100
');
$stmt->execute([$user['id']]);
$ledgerRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('
    SELECT amount, status, admin_note, requested_at, processed_at
    FROM withdrawal_requests
    WHERE rider_user_id = ?
    ORDER BY id DESC
    LIMIT 50
');
$stmt->execute([$user['id']]);
$withdrawalRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function withdrawal_status_badge_class(string $status): string {
    return match ($status) {
        'pending' => 'bg-warning text-dark',
        'processing' => 'bg-info text-dark',
        'paid' => 'bg-success',
        'rejected' => 'bg-danger',
        default => 'bg-dark border border-secondary',
    };
}

function render_withdrawal_history_html(array $rows): string {
    if (empty($rows)) {
        return '<div class="text-soft">' . e(t('wallet.no_withdrawal_history')) . '</div>';
    }
    ob_start();
    foreach ($rows as $w): ?>
        <div class="mini-row">
            <div>
                <div class="fw-bold">&#8358;<?= number_format((float) $w['amount'], 2) ?></div>
                <div class="small text-soft"><?= e((string) $w['requested_at']) ?></div>
            </div>
            <span class="badge <?= e(withdrawal_status_badge_class((string) $w['status'])) ?>"><?= e(booking_status_label((string) $w['status'])) ?></span>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

function render_ledger_html(array $rows): string {
    if (empty($rows)) {
        return '<div class="text-soft">' . e(t('wallet.no_ledger_history')) . '</div>';
    }
    ob_start();
    foreach ($rows as $tx): ?>
        <div class="mini-row">
            <div>
                <div class="small text-soft"><?= e((string) $tx['description']) ?></div>
                <div class="small text-soft"><?= e((string) $tx['created_at']) ?></div>
            </div>
            <div class="fw-bold <?= $tx['type'] === 'earning' ? 'text-success' : 'text-danger' ?>">
                <?= $tx['type'] === 'earning' ? '+' : '' ?>&#8358;<?= number_format((float) $tx['amount'], 2) ?>
            </div>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

$withdrawalHistoryHtml = render_withdrawal_history_html($withdrawalRows);
$ledgerHtml = render_ledger_html($ledgerRows);
$walletSignature = sha1(json_encode([$availableBalance, $totalEarned, $totalWithdrawn, $withdrawalRows, $ledgerRows]));

if (isset($_GET['ajax']) && $_GET['ajax'] === 'snapshot') {
    respond_json([
        'available_balance' => $availableBalance,
        'total_earned' => $totalEarned,
        'total_withdrawn' => $totalWithdrawn,
        'withdrawal_history_html' => $withdrawalHistoryHtml,
        'ledger_html' => $ledgerHtml,
        'signature' => $walletSignature,
    ]);
}
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= csrf_meta_tag() ?>
    <title><?= e(t('wallet.heading')) ?> | Aike</title>
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
        .summary-card{background:rgba(255,255,255,.92);border:1px solid rgba(15,42,68,.10);border-radius:1rem;padding:1.25rem}
        .stat-label{font-size:.8rem;color:#5c7a91}
        .money-big{font-size:1.6rem;font-weight:800}
        .mini-row{padding:.6rem 0;border-bottom:1px solid rgba(15,42,68,.08);display:flex;justify-content:space-between;align-items:center}
        .mini-row:last-child{border-bottom:none}
    </style>
</head>
<body>
<?= render_app_nav(current_user(), 'wallet', 'rider/wallet') ?>

<div class="container py-5">
    <h1 class="h3 fw-bold mb-4"><?= e(t('wallet.heading')) ?></h1>

    <?php if ($success): ?><div class="alert alert-success border-0 mb-4"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger border-0 mb-4"><?= e($error) ?></div><?php endif; ?>

    <?php if (!empty($_SESSION['pending_rider_verification_id'])): ?>
        <div class="cardx p-4 mb-4">
            <h2 class="h5 fw-bold mb-2"><?= e(t('wallet.confirm_action_heading')) ?></h2>
            <p class="text-soft"><?= e(t('wallet.confirm_action_subheading')) ?></p>
            <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="confirm_verification">
                <div class="col-auto">
                    <input class="form-control" name="code" placeholder="<?= e(t('wallet.confirm_action_code_placeholder')) ?>" maxlength="6" required>
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary fw-bold" type="submit"><?= e(t('confirm_action.submit')) ?></button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="summary-card">
                <div class="stat-label"><?= e(t('wallet.available_balance')) ?></div>
                <div class="money-big text-info" id="wallet-available-balance">&#8358;<?= number_format($availableBalance, 2) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <div class="stat-label"><?= e(t('wallet.total_earned')) ?></div>
                <div class="money-big" id="wallet-total-earned">&#8358;<?= number_format($totalEarned, 2) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="summary-card">
                <div class="stat-label"><?= e(t('wallet.total_withdrawn')) ?></div>
                <div class="money-big" id="wallet-total-withdrawn">&#8358;<?= number_format($totalWithdrawn, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="cardx p-4 h-100">
                <h2 class="h5 fw-bold mb-3"><?= e(t('wallet.bank_details_heading')) ?></h2>
                <form method="post" id="bank-details-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="save_bank_account">
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('wallet.bank_name_label')) ?></label>
                        <select class="form-select" name="bank_code" id="bank-code-select" required>
                            <option value=""><?= e(t('wallet.bank_select_placeholder')) ?></option>
                            <?php foreach ($banks as $b): ?>
                                <option value="<?= e((string) $b['code']) ?>" <?= (string) ($bankAccount['bank_code'] ?? '') === (string) $b['code'] ? 'selected' : '' ?>><?= e((string) $b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($banks)): ?><div class="small text-danger mt-1"><?= e(t('wallet.bank_list_unavailable')) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(t('wallet.account_number_label')) ?></label>
                        <input class="form-control" name="account_number" id="account-number-input" value="<?= e($bankAccount['account_number'] ?? '') ?>" maxlength="10" required>
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="verify-account-btn"><?= e(t('wallet.verify_account_button')) ?></button>
                        <div id="verify-result" class="small mt-2"></div>
                    </div>
                    <?php if (!empty($bankAccount['account_name']) && !empty($bankAccount['verified_at'])): ?>
                        <div class="small text-soft mb-3"><?= e(t('wallet.verified_account_name_label')) ?>: <strong><?= e((string) $bankAccount['account_name']) ?></strong></div>
                    <?php endif; ?>
                    <p class="small text-soft"><?= e(t('wallet.verify_first_hint')) ?></p>
                    <button class="btn btn-primary fw-bold" type="submit"><?= e(t('wallet.save_bank_details')) ?></button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="cardx p-4 h-100">
                <h2 class="h5 fw-bold mb-3"><?= e(t('wallet.request_withdrawal_heading')) ?></h2>
                <?php if (!$bankAccount || empty($bankAccount['bank_code']) || empty($bankAccount['verified_at'])): ?>
                    <div class="text-soft"><?= e(t('wallet.add_bank_details_first')) ?></div>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form_action" value="request_withdrawal">
                        <div class="mb-3">
                            <label class="form-label"><?= e(t('wallet.withdrawal_amount_label')) ?></label>
                            <div class="input-group">
                                <span class="input-group-text">&#8358;</span>
                                <input class="form-control" type="number" step="0.01" min="0.01" max="<?= e((string) $availableBalance) ?>" name="amount" id="wallet-withdraw-amount-input" required>
                            </div>
                            <div class="form-text" id="wallet-available-to-withdraw"><?= e(t('wallet.available_to_withdraw_prefix')) ?> &#8358;<?= number_format($availableBalance, 2) ?></div>
                        </div>
                        <p class="small text-soft"><?= e(t('wallet.withdrawal_processing_note')) ?></p>
                        <button class="btn btn-success fw-bold" type="submit" id="wallet-withdraw-submit-btn" <?= $availableBalance <= 0 ? 'disabled' : '' ?>><?= e(t('wallet.submit_withdrawal_request')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="cardx p-4 h-100">
                <h2 class="h5 fw-bold mb-3"><?= e(t('wallet.withdrawal_history_heading')) ?></h2>
                <div id="wallet-withdrawal-history"><?= $withdrawalHistoryHtml ?></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="cardx p-4 h-100">
                <h2 class="h5 fw-bold mb-3"><?= e(t('wallet.ledger_heading')) ?></h2>
                <div id="wallet-ledger"><?= $ledgerHtml ?></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const verifyBtn = document.getElementById('verify-account-btn');
    const verifyResult = document.getElementById('verify-result');
    const bankSelect = document.getElementById('bank-code-select');
    const accountInput = document.getElementById('account-number-input');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const verifyUrl = <?= json_encode(url_path('rider/ajax_verify_bank_account.php')) ?>;

    if (verifyBtn) {
        verifyBtn.addEventListener('click', async function () {
            const bankCode = bankSelect.value;
            const accountNumber = accountInput.value.trim();
            verifyResult.textContent = '';
            verifyResult.className = 'small mt-2';

            if (!bankCode || !accountNumber) {
                verifyResult.textContent = <?= json_encode(t('wallet.verify_select_bank_first')) ?>;
                verifyResult.classList.add('text-danger');
                return;
            }

            verifyBtn.disabled = true;
            const originalLabel = verifyBtn.textContent;
            verifyBtn.textContent = <?= json_encode(t('wallet.verifying_label')) ?>;

            try {
                const response = await fetch(verifyUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrfToken, bank_code: bankCode, account_number: accountNumber })
                });
                const data = await response.json();
                if (data.success) {
                    verifyResult.textContent = <?= json_encode(t('wallet.verified_prefix')) ?> + ' ' + data.account_name;
                    verifyResult.classList.add('text-success', 'fw-bold');
                } else {
                    verifyResult.textContent = data.message || <?= json_encode(t('wallet.account_verification_failed')) ?>;
                    verifyResult.classList.add('text-danger');
                }
            } catch (err) {
                verifyResult.textContent = <?= json_encode(t('wallet.verify_network_error')) ?>;
                verifyResult.classList.add('text-danger');
            } finally {
                verifyBtn.disabled = false;
                verifyBtn.textContent = originalLabel;
            }
        });
    }

    let signature = <?= json_encode($walletSignature) ?>;
    const snapshotUrl = <?= json_encode(url_path('rider/wallet.php?ajax=snapshot')) ?>;
    const availableLabel = <?= json_encode(t('wallet.available_to_withdraw_prefix')) ?>;

    function formatMoney(amount) {
        return Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    async function pollWallet() {
        if (document.hidden) return;
        try {
            const response = await fetch(snapshotUrl, { headers: { 'Accept': 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || data.signature === signature) return;
            signature = data.signature;

            const balanceEl = document.getElementById('wallet-available-balance');
            if (balanceEl) balanceEl.textContent = '₦' + formatMoney(data.available_balance);
            const earnedEl = document.getElementById('wallet-total-earned');
            if (earnedEl) earnedEl.textContent = '₦' + formatMoney(data.total_earned);
            const withdrawnEl = document.getElementById('wallet-total-withdrawn');
            if (withdrawnEl) withdrawnEl.textContent = '₦' + formatMoney(data.total_withdrawn);

            const amountInput = document.getElementById('wallet-withdraw-amount-input');
            if (amountInput) amountInput.max = data.available_balance;
            const availableHint = document.getElementById('wallet-available-to-withdraw');
            if (availableHint) availableHint.textContent = availableLabel + ' ₦' + formatMoney(data.available_balance);
            const submitBtn = document.getElementById('wallet-withdraw-submit-btn');
            if (submitBtn) submitBtn.disabled = Number(data.available_balance) <= 0;

            const historyWrap = document.getElementById('wallet-withdrawal-history');
            if (historyWrap && typeof data.withdrawal_history_html === 'string') historyWrap.innerHTML = data.withdrawal_history_html;
            const ledgerWrap = document.getElementById('wallet-ledger');
            if (ledgerWrap && typeof data.ledger_html === 'string') ledgerWrap.innerHTML = data.ledger_html;
        } catch (err) {
            console.error('Wallet poll failed:', err);
        }
    }

    setInterval(pollWallet, 10000);
})();
</script>
</body>
</html>
