<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/runtime_service.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

$data = getJsonInput();

$currentPassword = $data['current_password'] ?? '';
$confirmation = $data['confirmation'] ?? '';

if (!is_string($currentPassword) || $currentPassword === '') {
    respond([
        'success' => false,
        'message' => 'Your current password is required.'
    ], 422);
}

if (
    !is_string($confirmation) ||
    $confirmation !== 'DELETE MY ACCOUNT'
) {
    respond([
        'success' => false,
        'message' => 'Please enter DELETE MY ACCOUNT exactly to confirm.'
    ], 422);
}

try {
    global $pdo;

    // Verify the account and current password.
    $stmt = $pdo->prepare(
        "SELECT id, name, email, password_hash, status
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['status'] !== 'active') {
        respond([
            'success' => false,
            'message' => 'This account cannot be deleted.'
        ], 403);
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
        respond([
            'success' => false,
            'message' => 'The current password is incorrect.'
        ], 422);
    }

    // Retrieve every runtime belonging to this account.
    $stmt = $pdo->prepare(
        "SELECT
            r.id,
            r.status,
            r.workspace_status,
            r.workspace_path
         FROM model_runtimes r
         INNER JOIN model_versions mv
            ON mv.id = r.model_version_id
         INNER JOIN models m
            ON m.id = mv.model_id
         WHERE m.user_id = ?"
    );

    $stmt->execute([$userId]);

    $runtimes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $runtimeIdsToStop = [];

    // Stop inference services before closing the account.
    foreach ($runtimes as $runtime) {

        if ($runtime['status'] === 'stopped') {
            continue;
        }

        $runtimeId = (int) $runtime['id'];

        if ($runtime['workspace_status'] === 'ready') {

            if (empty($runtime['workspace_path'])) {
                throw new RuntimeException(
                    'Runtime ' . $runtimeId . ' has no workspace path.'
                );
            }

            $workspace = runtime_service_workspace(
                $runtime['workspace_path']
            );

            $result = runtime_service_run_manager(
                'stop',
                $workspace,
                ''
            );

            if (($result['success'] ?? false) !== true) {
                throw new RuntimeException(
                    'Runtime ' . $runtimeId . ' could not be stopped.'
                );
            }
        }

        $runtimeIdsToStop[] = $runtimeId;
    }

    // Begin the database changes.
    $pdo->beginTransaction();

    // Mark all account runtimes as stopped.
    if (!empty($runtimeIdsToStop)) {

        $updateRuntime = $pdo->prepare(
            "UPDATE model_runtimes
             SET status = 'stopped',
                 stopped_at = NOW(),
                 errors = NULL
             WHERE id = ?"
        );

        foreach ($runtimeIdsToStop as $runtimeId) {
            $updateRuntime->execute([$runtimeId]);
        }
    }

    // Revoke all active API keys.
    $stmt = $pdo->prepare(
        "UPDATE api_keys
         SET status = 'revoked',
             revoked_at = COALESCE(revoked_at, NOW())
         WHERE user_id = ?
           AND status = 'active'"
    );

    $stmt->execute([$userId]);

    // Remove personal API preferences.
    $stmt = $pdo->prepare(
        "DELETE FROM user_api_preferences
         WHERE user_id = ?"
    );

    $stmt->execute([$userId]);

    // Generate a unique replacement email address.
    $deletedEmail = 'deleted-' . $userId . '@deleted.invalid';

    // Replace personal account details and disable authentication.
    $replacementPassword = bin2hex(random_bytes(32));

    $replacementPasswordHash = password_hash(
        $replacementPassword,
        PASSWORD_DEFAULT
    );

    if ($replacementPasswordHash === false) {
        throw new RuntimeException(
            'Account credentials could not be anonymised.'
        );
    }

    $stmt = $pdo->prepare(
        "UPDATE users
         SET name = 'Deleted Account',
             email = ?,
             password_hash = ?,
             status = 'deleted',
             deleted_at = NOW()
         WHERE id = ?
           AND status = 'active'"
    );

    $stmt->execute([
        $deletedEmail,
        $replacementPasswordHash,
        $userId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Account deletion could not be completed.'
        );
    }

    $pdo->commit();

    // Destroy the authenticated session.
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();

    respond([
        'success' => true,
        'message' => 'Your account has been closed and personal account details have been anonymised. You have been logged out.'
    ]);

} catch (Throwable $e) {

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        'Account deletion error: ' . $e->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'Account deletion could not be completed. Please try again or contact support.'
    ], 500);
}
