<?php
require_once __DIR__ . '/../config/functions.php';
require_role(['rider']);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paystack.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($input) ? $input : null);

$accountNumber = trim((string) ($input['account_number'] ?? ''));
$bankCode = trim((string) ($input['bank_code'] ?? ''));

if ($bankCode === '' || $accountNumber === '' || !ctype_digit($accountNumber)) {
    respond_json(['success' => false, 'message' => t('wallet.account_verification_invalid_input')], 422);
}

$result = paystack_resolve_account($accountNumber, $bankCode);
if (!$result['ok']) {
    respond_json(['success' => false, 'message' => $result['message'] ?: t('wallet.account_verification_failed')], 422);
}

respond_json(['success' => true, 'account_name' => $result['account_name']]);
