
<?php

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');
requireCsrf();

$data = getJsonInput();

$email = strtolower(trim($data['email'] ?? ''));
$password = $data['password'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !is_string($password) ||
    $password === '') {
    respond([
        'success' => false,
        'message' => 'Invalid email or password.'
    ], 422);
}

$stmt = $pdo->prepare(
    "SELECT id, name, email, password_hash, status
     FROM users
     WHERE email = ?
     LIMIT 1"
);

$stmt->execute([$email]);
$user = $stmt->fetch();

if (
    !$user ||
    $user['status'] !== 'active' ||
    !password_verify($password, $user['password_hash'])
) {
    respond([
        'success' => false,
        'message' => 'Invalid email or password.'
    ], 401);
}

session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

respond([
    'success' => true,
    'message' => 'Login successful.',
    'user' => [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email']
    ],
    'csrf_token' => $_SESSION['csrf_token']
]);
