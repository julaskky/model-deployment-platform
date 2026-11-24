<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod($_SERVER['REQUEST_METHOD'] === 'GET' ? 'GET' : 'POST');

$userId = requireAuth();

/*
|--------------------------------------------------------------------------
| Verify authenticated user
|--------------------------------------------------------------------------
*/
$userStmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        status,
        created_at,
        updated_at
    FROM users
    WHERE id = :user_id
      AND status = 'active'
    LIMIT 1
");

$userStmt->execute([
    ':user_id' => $userId
]);

$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    respond([
        'success' => false,
        'message' => 'Your account is unavailable. Please sign in again.'
    ], 401);
}

/*
|--------------------------------------------------------------------------
| GET PROFILE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond([
        'success' => true,
        'data' => [
            'profile' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'status' => $user['status'],
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at']
            ]
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/
requireCsrf();

$input = getJsonInput();

$name = trim((string) ($input['name'] ?? ''));

if ($name === '') {
    respond([
        'success' => false,
        'message' => 'Name is required.'
    ], 422);
}

if (mb_strlen($name) > 150) {
    respond([
        'success' => false,
        'message' => 'Name cannot exceed 150 characters.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Update only editable profile information.
|
| Email is intentionally not changed here.
| Email-change verification can be implemented separately later.
|--------------------------------------------------------------------------
*/
$updateStmt = $pdo->prepare("
    UPDATE users
    SET
        name = :name,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = :user_id
      AND status = 'active'
");

$updateStmt->execute([
    ':name' => $name,
    ':user_id' => $userId
]);

/*
|--------------------------------------------------------------------------
| Return updated profile
|--------------------------------------------------------------------------
*/
$profileStmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        status,
        created_at,
        updated_at
    FROM users
    WHERE id = :user_id
      AND status = 'active'
    LIMIT 1
");

$profileStmt->execute([
    ':user_id' => $userId
]);

$updatedProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

respond([
    'success' => true,
    'message' => 'Profile updated successfully.',
    'data' => [
        'profile' => [
            'id' => (int) $updatedProfile['id'],
            'name' => $updatedProfile['name'],
            'email' => $updatedProfile['email'],
            'status' => $updatedProfile['status'],
            'created_at' => $updatedProfile['created_at'],
            'updated_at' => $updatedProfile['updated_at']
        ]
    ]
]);