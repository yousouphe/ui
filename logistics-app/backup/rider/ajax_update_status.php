<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

$data = json_decode(file_get_contents('php://input'), true);
$user = current_user();

if ($user && isset($data['status'])) {
    $stmt = $pdo->prepare('UPDATE rider_profiles SET availability_status = ? WHERE user_id = ?');
    $stmt->execute([$data['status'], $user['id']]);
    echo json_encode(['success' => true]);
}