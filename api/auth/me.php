
<?php

require_once __DIR__ . '/../bootstrap.php';

requireMethod('GET');

$userId = requireAuth();

$stmt = $pdo->prepare(
    "SELECT id, name, email, status, created_at
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();

    respond([
        'success' => false,
        'message' => 'Account is unavailable.'
    ], 401);
}

respond([
    'success' => true,
    'user' => $user
]);
