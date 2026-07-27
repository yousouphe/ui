<?php
$config = require __DIR__ . '/env.php';
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // Never echo the driver message to the browser - it leaks the DB host/user/schema.
    // Log the detail for operators, show a generic non-revealing 503 instead.
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable. Please try again shortly.']);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Service temporarily unavailable. Please try again shortly.';
    }
    exit;
}

// Opportunistic housekeeping on a small fraction of requests (see run_maintenance_gc()) so
// scratch data can't grow without bound. Guarded: a missing helper is a harmless no-op.
if (function_exists('run_maintenance_gc')) {
    try {
        if (random_int(1, 100) <= 2) {
            run_maintenance_gc($pdo);
        }
    } catch (Throwable $e) {
        error_log('maintenance gc trigger failed: ' . $e->getMessage());
    }
}
