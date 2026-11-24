<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

$input = getJsonInput();

$currentPassword = (string) ($input['current_password'] ?? '');
$newPassword = (string) ($input['new_password'] ?? '');
$confirmPassword = (string) ($input['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    respond([
        'success' => false,
        'message' => 'All password fields are required.'
    ], 422);
}

if ($newPassword !== $confirmPassword) {
    respond([
        'success' => false,
        'message' => 'The new password and confirmation do not match.'
    ], 422);
}

if (strlen($newPassword) < 12) {
    respond([
        'success' => false,
        'message' => 'Your new password must contain at least 12 characters.'
    ], 422);
}

if (strlen($newPassword) > 4096) {
    respond([
        'success' => false,
        'message' => 'The new password is too long.'
    ], 422);
}

$stmt = $pdo->prepare("
    SELECT password_hash
    FROM users
    WHERE id = :user_id
      AND status = 'active'
    LIMIT 1
");

$stmt->execute([
    ':user_id' => $userId
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    respond([
        'success' => false,
        'message' => 'Your account could not be verified.'
    ], 401);
}

if (!password_verify($currentPassword, $user['password_hash'])) {
    respond([
        'success' => false,
        'message' => 'Your current password is incorrect.'
    ], 422);
}

if (password_verify($newPassword, $user['password_hash'])) {
    respond([
        'success' => false,
        'message' => 'Your new password must be different from your current password.'
    ], 422);
}

$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

if ($newPasswordHash === false) {
    respond([
        'success' => false,
        'message' => 'Unable to securely process your new password.'
    ], 500);
}

$updateStmt = $pdo->prepare("
    UPDATE users
    SET
        password_hash = :password_hash,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = :user_id
      AND status = 'active'
");

$updateStmt->execute([
    ':password_hash' => $newPasswordHash,
    ':user_id' => $userId
]);

respond([
    'success' => true,
    'message' => 'Your password has been changed successfully.'
]);
