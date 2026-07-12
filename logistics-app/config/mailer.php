<?php
// Minimal dependency-free SMTP mailer (no Composer/PHPMailer available on this host).
// Supports implicit TLS (port 465) and STARTTLS (port 587/25) with AUTH LOGIN.
// Every public entry point swallows its own errors and returns bool - a failed email
// must never break a registration, payment, or delivery flow.

function mailer_config(): array {
    return config_app();
}

function mailer_dispatch(string $toEmail, string $toName, string $subject, string $htmlBody): void {
    // Deferred until after the response is flushed to the client (see mailer_flush_response()
    // below), so a slow or unreachable mail server can never delay a user-facing action -
    // e.g. the rider's "confirm payment received" button waiting on an SMTP handshake.
    register_shutdown_function(static function () use ($toEmail, $toName, $subject, $htmlBody) {
        $sent = mailer_send($toEmail, $toName, $subject, $htmlBody);

        // Best-effort accountability log - $pdo is set up by config/db.php at the top
        // level of whichever page triggered this email, so it's a PHP global by the time
        // this shutdown function runs. Never let logging affect whether the email itself
        // was considered sent.
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO && function_exists('log_event')) {
            log_event(
                $pdo,
                'email',
                ($sent ? 'Sent' : 'Failed to send') . ' "' . $subject . '" to ' . $toEmail,
                null,
                null,
                'email',
                null,
                ['to' => $toEmail, 'to_name' => $toName, 'subject' => $subject, 'sent' => $sent]
            );
        }
    });
}

function mailer_flush_response(): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

function mailer_send(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $config = mailer_config();
    $host = trim((string)($config['smtp_host'] ?? ''));
    $port = (int)($config['smtp_port'] ?? 587);
    $user = trim((string)($config['smtp_user'] ?? ''));
    $pass = (string)($config['smtp_pass'] ?? '');
    $fromEmail = trim((string)($config['smtp_from_email'] ?? ''));
    $fromName = trim((string)($config['smtp_from_name'] ?? ($config['app_name'] ?? 'Aike')));
    $secure = strtolower(trim((string)($config['smtp_secure'] ?? 'tls')));

    $isConfigured = $host !== '' && $fromEmail !== '' && !str_starts_with($host, 'REDACTED') && !str_starts_with($fromEmail, 'REDACTED');

    if (!$isConfigured || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('mailer_send skipped: SMTP not configured or invalid recipient (' . $toEmail . ')');
        return false;
    }

    try {
        $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client(
            $transport . ':' . $port,
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        if (!$socket) {
            throw new RuntimeException("Could not connect to SMTP host: $errstr ($errno)");
        }
        // The connect timeout above only bounds the initial TCP handshake - every read after
        // that (EHLO/STARTTLS/AUTH/MAIL FROM/RCPT TO/DATA responses) would otherwise block on
        // PHP's default_socket_timeout (60s each) if the server stops responding mid-transaction.
        stream_set_timeout($socket, 5);

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
    $appName = e((string)(config_app()['app_name'] ?? 'Aike'));
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
