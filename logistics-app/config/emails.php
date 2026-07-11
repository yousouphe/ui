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
    mailer_send($toEmail, $fullName, 'Welcome to SwiftDrop', mailer_layout('Welcome, ' . $fullName . '!', $body));
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
    mailer_send($toEmail, $fullName, 'Payment Receipt - ' . $booking['booking_code'], mailer_layout('Payment Receipt', $body));
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
    mailer_send($toEmail, $fullName, 'Delivery Completed - ' . $booking['booking_code'], mailer_layout('Delivery Completed', $body));
}

function send_password_reset_email(string $toEmail, string $fullName, string $resetUrl): void {
    $body = '<p>Hi ' . e($fullName) . ',</p>'
        . '<p>We received a request to reset your password. Click the button below to choose a new one. This link expires in 30 minutes.</p>'
        . '<p style="text-align:center;margin:24px 0;"><a href="' . e($resetUrl) . '" style="background:#0284c7;color:#ffffff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Reset Password</a></p>'
        . '<p style="color:#5c7a91;font-size:13px;">If you did not request this, you can safely ignore this email.</p>';
    mailer_send($toEmail, $fullName, 'Reset your SwiftDrop password', mailer_layout('Reset Your Password', $body));
}
