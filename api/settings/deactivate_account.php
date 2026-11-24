<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/runtime_service.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

$data = getJsonInput();

$currentPassword = $data['current_password'] ?? '';

if (!is_string($currentPassword) || $currentPassword === '') {
    respond([
        'success' => false,
        'message' => 'Your current password is required.'
    ], 422);
}

try {
    global $pdo;

    // Verify the account and password.
    $stmt = $pdo->prepare(
        "SELECT id, password_hash, status
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->execute([$userId]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['status'] !== 'active') {
        respond([
            'success' => false,
            'message' => 'This account cannot be deactivated.'
        ], 403);
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
        respond([
            'success' => false,
            'message' => 'The current password is incorrect.'
        ], 422);
    }

    // Find every runtime belonging to this account.
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

    // Stop active runtimes before changing account status.
    foreach ($runtimes as $runtime) {

        if ($runtime['status'] === 'stopped') {
            continue;
        }

        $runtimeId = (int) $runtime['id'];

        if ($runtime['workspace_status'] === 'ready') {

            if (empty($runtime['workspace_path'])) {
                throw new RuntimeException(
                    'A runtime workspace path is missing.'
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

        // A non-ready workspace cannot provide a usable
        // inference service, so its database status can
        // safely be marked stopped.
        $runtimeIdsToStop[] = $runtimeId;
    }

    // Update account records only after runtime shutdown.
    $pdo->beginTransaction();

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

    // Deactivate the account.
    $stmt = $pdo->prepare(
        "UPDATE users
         SET status = 'deactivated',
             deactivated_at = NOW()
         WHERE id = ?
           AND status = 'active'"
    );

    $stmt->execute([$userId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Account status could not be updated.'
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
        'message' => 'Your account has been deactivated successfully. You have been logged out.'
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
        'Account deactivation error: ' . $e->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'Account deactivation could not be completed. Please try again or contact support.'
    ], 500);
}
