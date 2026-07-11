<?php
require_once __DIR__ . '/mailer.php';

function send_welcome_email(string $toEmail, string $fullName, string $role): void {
    $roleLabel = $role === 'rider' ? 'rider' : 'sender';
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>Welcome to SwiftDrop! Your ' . e($roleLabel) . ' account has been created successfully.</p>'
        . ($role === 'rider'
            ? '<p>Your registration is being reviewed by our team. You will be able to go online and accept deliveries once your documents are approved.</p>'
            : '<p>You can now book your first delivery from your dashboard.</p>')
        . '<p>Thanks for choosing us.</p>';
    mailer_dispatch($toEmail, $fullName, 'Welcome to SwiftDrop', mailer_layout('Welcome, ' . $fullName . '!', $body));
}

function send_transaction_receipt_email(string $toEmail, string $fullName, array $booking, string $reference): void {
    $rows = mailer_row('Booking Code', (string)$booking['booking_code'])
        . mailer_row('Item', (string)$booking['item_name'])
        . mailer_row('Amount Paid', '₦' . number_format((float)$booking['agreed_cost'], 2))
        . mailer_row('Reference', $reference)
        . mailer_row('Date', date('d M Y, h:i A'));
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>We have received your payment. Here is your receipt:</p>'
        . '<div style="margin:16px 0;">' . $rows . '</div>'
        . '<p>Thank you for using SwiftDrop.</p>';
    mailer_dispatch($toEmail, $fullName, 'Payment Receipt - ' . $booking['booking_code'], mailer_layout('Payment Receipt', $body));
}

function send_order_completion_email(string $toEmail, string $fullName, array $booking): void {
    $rows = mailer_row('Booking Code', (string)$booking['booking_code'])
        . mailer_row('Item', (string)$booking['item_name'])
        . mailer_row('Total Cost', '₦' . number_format((float)$booking['agreed_cost'], 2))
        . mailer_row('Delivered', date('d M Y, h:i A'));
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>Your delivery has been completed and closed out. Here is a summary:</p>'
        . '<div style="margin:16px 0;">' . $rows . '</div>'
        . '<p>We hope you had a great experience. You can leave a rating from your dashboard.</p>';
    mailer_dispatch($toEmail, $fullName, 'Delivery Completed - ' . $booking['booking_code'], mailer_layout('Delivery Completed', $body));
}

function send_password_reset_email(string $toEmail, string $fullName, string $resetUrl): void {
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>We received a request to reset your password. Click the button below to choose a new one. This link expires in 30 minutes.</p>'
        . '<p style="text-align:center;margin:24px 0;"><a href="' . e($resetUrl) . '" style="background:#0284c7;color:#ffffff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Reset Password</a></p>'
        . '<p style="color:#5c7a91;font-size:13px;">If you did not request this, you can safely ignore this email.</p>';
    mailer_dispatch($toEmail, $fullName, 'Reset your SwiftDrop password', mailer_layout('Reset Your Password', $body));
}

// Every admin gets every accountability event - not just whoever is logged in when it happens.
function notify_admins(PDO $pdo, string $subject, string $bodyHtml): void {
    foreach (admin_emails($pdo) as $admin) {
        mailer_dispatch((string) $admin['email'], (string) $admin['full_name'], $subject, mailer_layout($subject, $bodyHtml));
    }
}

// Rider-facing earning notification - shows the payout amount only, never the sender's full
// price or the 85% split (see rider_payout_amount() callers - riders never see that math).
function send_rider_earning_email(string $toEmail, string $fullName, array $booking, float $payoutAmount): void {
    $rows = mailer_row('Booking Code', (string) $booking['booking_code'])
        . mailer_row('Item', (string) $booking['item_name'])
        . mailer_row('Amount Credited', '₦' . number_format($payoutAmount, 2))
        . mailer_row('Date', date('d M Y, h:i A'));
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>Your earning for this delivery has been credited to your wallet:</p>'
        . '<div style="margin:16px 0;">' . $rows . '</div>'
        . '<p>You can view your balance and request a withdrawal anytime from your wallet page.</p>';
    mailer_dispatch($toEmail, $fullName, 'Earning Credited - ' . $booking['booking_code'], mailer_layout('Earning Credited', $body));
}

function send_kyc_decision_email(string $toEmail, string $fullName, bool $approved, ?string $note = null): void {
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . ($approved
            ? '<p>Your rider registration has been approved. You can now go online and start accepting deliveries.</p>'
            : '<p>Your rider registration was not approved at this time.</p>' . ($note ? '<p><strong>Reason:</strong> ' . e($note) . '</p>' : ''));
    mailer_dispatch($toEmail, $fullName, $approved ? 'Your rider registration is approved' : 'Update on your rider registration', mailer_layout($approved ? 'Registration Approved' : 'Registration Update', $body));
}

function send_withdrawal_requested_email(string $toEmail, string $fullName, float $amount): void {
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>We have received your withdrawal request for ' . mailer_row('Amount', '₦' . number_format($amount, 2))
        . '</p><p>Requests are typically processed within an hour. We will email you once it has been processed.</p>';
    mailer_dispatch($toEmail, $fullName, 'Withdrawal request received', mailer_layout('Withdrawal Request Received', $body));
}

function send_withdrawal_status_email(string $toEmail, string $fullName, float $amount, string $status, ?string $note = null): void {
    $statusLabels = ['processing' => 'now being processed', 'paid' => 'paid', 'rejected' => 'rejected'];
    $label = $statusLabels[$status] ?? $status;
    $rows = mailer_row('Amount', '₦' . number_format($amount, 2)) . mailer_row('Status', ucfirst($label));
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>Your withdrawal request is ' . e($label) . '.</p>'
        . '<div style="margin:16px 0;">' . $rows . '</div>'
        . ($note ? '<p><strong>Note:</strong> ' . e($note) . '</p>' : '');
    mailer_dispatch($toEmail, $fullName, 'Withdrawal ' . ucfirst($label), mailer_layout('Withdrawal Update', $body));
}

function send_complaint_resolved_email(string $toEmail, string $fullName, string $bookingCode, ?string $note = null): void {
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>Your reported issue for booking <strong>' . e($bookingCode) . '</strong> has been resolved.</p>'
        . ($note ? '<p><strong>Resolution note:</strong> ' . e($note) . '</p>' : '')
        . '<p>Thanks for your patience.</p>';
    mailer_dispatch($toEmail, $fullName, 'Your report has been resolved - ' . $bookingCode, mailer_layout('Report Resolved', $body));
}

function send_rider_matched_email(string $toEmail, string $fullName, string $riderName, string $bookingCode): void {
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p><strong>' . e($riderName) . '</strong> has been assigned to your delivery <strong>' . e($bookingCode) . '</strong> and is on the way.</p>'
        . '<p>You can track progress and chat with your rider from your dashboard.</p>';
    mailer_dispatch($toEmail, $fullName, 'Rider assigned - ' . $bookingCode, mailer_layout('Rider Assigned', $body));
}
