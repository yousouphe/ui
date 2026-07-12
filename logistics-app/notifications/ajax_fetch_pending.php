<?php
// Called by the service worker's push handler (same-origin, cookie-authenticated fetch)
// in response to an empty "wake up" push - returns the oldest undelivered notification
// for whichever account this browser is currently signed in as, and marks it delivered.
require_once __DIR__ . '/../config/functions.php';
require_auth();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$user = current_user();

$stmt = $pdo->prepare('
    SELECT id, title, body, url
    FROM push_notifications
    WHERE user_id = ? AND delivered_at IS NULL
    ORDER BY id ASC
    LIMIT 1
');
$stmt->execute([$user['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['title' => null]);
    exit;
}

$stmt = $pdo->prepare('UPDATE push_notifications SET delivered_at = NOW() WHERE id = ?');
$stmt->execute([$row['id']]);

echo json_encode([
    'title' => $row['title'],
    'body' => $row['body'],
    'url' => $row['url'],
]);
