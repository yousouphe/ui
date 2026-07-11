<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function config_app(): array {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/env.php';
    }
    return $config;
}

function respond_json(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function mapbox_token(): string {
    return trim((string)(config_app()['mapbox_token'] ?? ''));
}

function base_url(): string {
    $configured = trim((string)(config_app()['base_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($scriptName), '/');
    foreach (['/bookings', '/rider', '/admin', '/auth', '/payments', '/chat'] as $suffix) {
        if (str_ends_with($dir, $suffix)) {
            $dir = substr($dir, 0, -strlen($suffix));
            break;
        }
    }
    return ($dir === '/' || $dir === '.' || $dir === '') ? '' : $dir;
}

function url_path(string $path = ''): string {
    $base = base_url();
    $path = ltrim($path, '/');
    return $base . ($path !== '' ? '/' . $path : '');
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

const SUPPORTED_LOCALES = ['en', 'ha'];

function current_locale(): string {
    $locale = $_SESSION['locale'] ?? $_COOKIE['locale'] ?? 'en';
    return in_array($locale, SUPPORTED_LOCALES, true) ? $locale : 'en';
}

function set_locale(string $locale): void {
    if (!in_array($locale, SUPPORTED_LOCALES, true)) {
        return;
    }
    $_SESSION['locale'] = $locale;
    setcookie('locale', $locale, time() + 60 * 60 * 24 * 365, '/');
}

function translations(): array {
    static $cache = [];
    $locale = current_locale();
    if (!isset($cache[$locale])) {
        $path = __DIR__ . '/../lang/' . $locale . '.php';
        $cache[$locale] = is_file($path) ? require $path : [];
    }
    return $cache[$locale];
}

function t(string $key, array $replacements = []): string {
    $text = translations()[$key] ?? $key;
    foreach ($replacements as $k => $v) {
        $text = str_replace(':' . $k, (string) $v, $text);
    }
    return $text;
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_meta_tag(): string {
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

function require_csrf(?array $source = null): void {
    $token = null;
    if (is_array($source) && isset($source['csrf_token'])) {
        $token = (string)$source['csrf_token'];
    } elseif (isset($_POST['csrf_token'])) {
        $token = (string)$_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    if ($token !== null && $token !== '' && hash_equals(csrf_token(), $token)) {
        return;
    }

    $wantsJson = str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');

    http_response_code(419);
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'status' => false, 'message' => 'Invalid or expired security token. Please refresh and try again.']);
    } else {
        echo 'Invalid or expired security token. Please refresh the page and try again.';
    }
    exit;
}

function redirect_to(string $path): void {
    header('Location: ' . url_path($path));
    exit;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_auth(): void {
    if (!is_logged_in()) redirect_to('login');
    $user = current_user();
    $requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    $currentPage = basename(rtrim($requestPath, '/'));
    $isCompleteProfilePage = in_array($currentPage, ['complete-profile', 'complete-profile.php'], true);
    if ((int)($user['profile_completed'] ?? 1) === 0 && !$isCompleteProfilePage) {
        redirect_to('complete-profile');
    }
}

function require_role(array $roles): void {
    require_auth();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function require_guest(): void {
    if (is_logged_in()) {
        $user = current_user();
        $role = $user['role'] ?? '';
        if ($role === 'rider') redirect_to('rider/');
        if ($role === 'admin') redirect_to('admin/');
        redirect_to('bookings/');
    }
}

function flash(string $key, ?string $message = null): ?string {
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    if (isset($_SESSION['_flash'][$key])) {
        $msg = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
        return $msg;
    }
    return null;
}

function old(string $key, string $default = ''): string {
    return $_POST[$key] ?? $default;
}

function validate_required(array $fields, array $source): array {
    $errors = [];
    foreach ($fields as $field => $label) {
        if (!isset($source[$field]) || trim((string)$source[$field]) === '') {
            $errors[$field] = $label . ' is required.';
        }
    }
    return $errors;
}

function save_uploaded_image(array $file, string $subdir, string $prefix, string $errorLabel): ?string {
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($errorLabel . ' upload failed.');
    }
    $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG and WEBP are allowed.');
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException($errorLabel . ' must not exceed 5MB.');

    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $dir = dirname(__DIR__) . '/uploads/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Failed to move uploaded ' . strtolower($errorLabel) . '.');
    return 'uploads/' . $subdir . '/' . $name;
}

function save_item_image(array $file): ?string {
    return save_uploaded_image($file, 'items', 'item', 'Item image');
}

function save_kyc_document(array $file): ?string {
    return save_uploaded_image($file, 'kyc', 'kyc', 'ID document');
}



function db_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = 'table:' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function db_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function response_cache_headers(string $etag, int $maxAge = 3): void {
    header('Cache-Control: private, max-age=' . max(0, $maxAge) . ', must-revalidate');
    header('ETag: "' . $etag . '"');
    $incoming = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"');
    if ($incoming !== '' && hash_equals($incoming, $etag)) {
        http_response_code(304);
        exit;
    }
}

function load_sender_bookings(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT
            b.*,
            r.full_name AS rider_name,
            r.phone AS rider_phone,
            rp.last_latitude,
            rp.last_longitude,
            rp.availability_status
        FROM bookings b
        LEFT JOIN users r ON r.id = b.selected_rider_user_id
        LEFT JOIN rider_profiles rp ON rp.user_id = b.selected_rider_user_id
        WHERE b.sender_user_id = ?
        ORDER BY b.id DESC
    ");
    $stmt->execute([$userId]);
    $allBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $activeBookings = [];
    $pendingBookings = [];
    $unpaidBookings = [];
    $cancelledBookings = [];
    $historyBookings = [];

    foreach ($allBookings as $b) {
        $paymentStatus = $b['payment_status'] ?? 'unpaid';
        $bookingStatus = $b['booking_status'] ?? '';

        if ($bookingStatus === 'draft') {
            continue;
        }

        if ($bookingStatus === 'cancelled') {
            // Cancelled orders never owe payment - keep them out of "Unpaid" where money is
            // genuinely due, so senders don't have to hunt for a closed order in that list.
            $cancelledBookings[] = $b;
        } elseif ($paymentStatus === 'paid') {
            $historyBookings[] = $b;
        } elseif ($bookingStatus === 'delivered') {
            $unpaidBookings[] = $b;
        } elseif ($bookingStatus === 'submitted') {
            $pendingBookings[] = $b;
            $activeBookings[] = $b;
        } else {
            $activeBookings[] = $b;
        }
    }

    return [
        'all' => $allBookings,
        'active' => $activeBookings,
        'pending' => $pendingBookings,
        'unpaid' => $unpaidBookings,
        'cancelled' => $cancelledBookings,
        'history' => $historyBookings,
    ];
}

function haversine_sql(string $latField, string $lngField, float $lat, float $lng): string {
    return "(6371 * ACOS(
        COS(RADIANS($lat)) * COS(RADIANS($latField)) *
        COS(RADIANS($lngField) - RADIANS($lng)) +
        SIN(RADIANS($lat)) * SIN(RADIANS($latField))
    ))";
}

function booking_status_label(string $status): string {
    $key = 'status.' . $status;
    $label = t($key);
    return $label !== $key ? $label : ucwords(str_replace('_', ' ', $status));
}

const RIDER_PAYOUT_SHARE = 0.85;

function rider_payout_amount(float $agreedCost): float {
    return round($agreedCost * RIDER_PAYOUT_SHARE, 2);
}

// Sum of every wallet ledger entry for a rider - earnings (positive) minus withdrawals
// (negative) - is that rider's all-time settled balance.
function rider_wallet_balance(PDO $pdo, int $riderUserId): float {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE rider_user_id = ?');
    $stmt->execute([$riderUserId]);
    return (float) $stmt->fetchColumn();
}

// Balance minus withdrawal requests still pending/processing - what the rider can actually
// request right now, so they can't submit more than one overlapping withdrawal.
function rider_available_balance(PDO $pdo, int $riderUserId): float {
    $balance = rider_wallet_balance($pdo, $riderUserId);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM withdrawal_requests
        WHERE rider_user_id = ? AND status IN ('pending', 'processing')
    ");
    $stmt->execute([$riderUserId]);
    $held = (float) $stmt->fetchColumn();
    return $balance - $held;
}

// Active admin accounts to notify for events that need their attention (new KYC, new
// withdrawal request, new complaint) - "accountability" means every admin sees every event,
// not just whoever happens to be online when it happens.
function admin_emails(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT full_name, email FROM users WHERE role = 'admin' AND status = 'active'");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
