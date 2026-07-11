<?php
// Minimal dependency-free SMTP mailer (no Composer/PHPMailer available on this host).
// Supports implicit TLS (port 465) and STARTTLS (port 587/25) with AUTH LOGIN.
// Every public entry point swallows its own errors and returns bool - a failed email
// must never break a registration, payment, or delivery flow.

function mailer_config(): array {
    return config_app();
}

function mailer_send(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $config = mailer_config();
    $host = trim((string)($config['smtp_host'] ?? ''));
    $port = (int)($config['smtp_port'] ?? 587);
    $user = trim((string)($config['smtp_user'] ?? ''));
    $pass = (string)($config['smtp_pass'] ?? '');
    $fromEmail = trim((string)($config['smtp_from_email'] ?? ''));
    $fromName = trim((string)($config['smtp_from_name'] ?? ($config['app_name'] ?? 'SwiftDrop')));
    $secure = strtolower(trim((string)($config['smtp_secure'] ?? 'tls')));

    if ($host === '' || $fromEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('mailer_send skipped: SMTP not configured or invalid recipient (' . $toEmail . ')');
        return false;
    }

    try {
        $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client(
            $transport . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        if (!$socket) {
            throw new RuntimeException("Could not connect to SMTP host: $errstr ($errno)");
        }

        $expect = static function ($socket, string $prefix) {
            $line = '';
            do {
                $chunk = fgets($socket, 515);
                if ($chunk === false) {
                    throw new RuntimeException('SMTP connection closed unexpectedly.');
                }
                $line = $chunk;
            } while (isset($line[3]) && $line[3] === '-');
            if (!str_starts_with($line, $prefix)) {
                throw new RuntimeException('Unexpected SMTP response: ' . trim($line));
            }
            return $line;
        };

        $send = static function ($socket, string $cmd) {
            fwrite($socket, $cmd . "\r\n");
        };

        $expect($socket, '220');
        $localHost = trim((string)($config['app_url'] ?? 'localhost'));
        $localHost = preg_replace('#^https?://#', '', $localHost) ?: 'localhost';

        $send($socket, 'EHLO ' . $localHost);
        $expect($socket, '250');

        if ($secure === 'tls') {
            $send($socket, 'STARTTLS');
            $expect($socket, '220');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed.');
            }
            $send($socket, 'EHLO ' . $localHost);
            $expect($socket, '250');
        }

        if ($user !== '') {
            $send($socket, 'AUTH LOGIN');
            $expect($socket, '334');
            $send($socket, base64_encode($user));
            $expect($socket, '334');
            $send($socket, base64_encode($pass));
            $expect($socket, '235');
        }

        $send($socket, 'MAIL FROM:<' . $fromEmail . '>');
        $expect($socket, '250');
        $send($socket, 'RCPT TO:<' . $toEmail . '>');
        $expect($socket, '250');
        $send($socket, 'DATA');
        $expect($socket, '354');

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . mailer_encode_header($fromName) . ' <' . $fromEmail . '>';
        $headers[] = 'To: ' . mailer_encode_header($toName) . ' <' . $toEmail . '>';
        $headers[] = 'Subject: ' . mailer_encode_header($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $escapedBody = preg_replace('/^\./m', '..', $htmlBody);

        $send($socket, implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.");
        $expect($socket, '250');

        $send($socket, 'QUIT');
        fclose($socket);

        return true;
    } catch (Throwable $e) {
        error_log('mailer_send failed for ' . $toEmail . ': ' . $e->getMessage());
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}

function mailer_encode_header(string $text): string {
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}

function mailer_layout(string $title, string $bodyHtml): string {
    $appName = e((string)(config_app()['app_name'] ?? 'SwiftDrop'));
    return '<!doctype html><html><body style="margin:0;padding:0;background:#eef8ff;font-family:Segoe UI,Arial,sans-serif;color:#0f2c44;">'
        . '<div style="max-width:560px;margin:0 auto;padding:32px 24px;">'
        . '<div style="text-align:center;margin-bottom:24px;"><span style="font-size:20px;font-weight:800;color:#0284c7;">' . $appName . '</span></div>'
        . '<div style="background:#ffffff;border-radius:16px;padding:28px;box-shadow:0 8px 24px rgba(0,0,0,.08);">'
        . '<h1 style="font-size:18px;margin:0 0 16px;">' . e($title) . '</h1>'
        . $bodyHtml
        . '</div>'
        . '<div style="text-align:center;color:#5c7a91;font-size:12px;margin-top:20px;">' . $appName . '</div>'
        . '</div></body></html>';
}

function mailer_row(string $label, string $value): string {
    return '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eef2f5;">'
        . '<span style="color:#5c7a91;">' . e($label) . '</span>'
        . '<span style="font-weight:600;">' . e($value) . '</span>'
        . '</div>';
}
