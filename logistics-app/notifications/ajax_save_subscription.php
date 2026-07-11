<?php
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();
$input = json_decode(file_get_contents('php://input'), true);
require_csrf(is_array($input) ? $input : null);

$endpoint = trim((string) ($input['endpoint'] ?? ''));
if ($endpoint === '') {
    http_response_code(422);
    echo json_encode(['success' => false]);
    exit;
}

// endpoint_hash is the unique key (not user_id+endpoint) - a browser only ever has one
// push subscription at a time, so if a different account logs in on the same device we
// want that same endpoint reassigned to them, not duplicated.
$endpointHash = hash('sha256', $endpoint);
$stmt = $pdo->prepare('
    INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint)
');
$stmt->execute([$user['id'], $endpoint, $endpointHash]);

echo json_encode(['success' => true]);
