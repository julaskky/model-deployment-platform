<?php
declare(strict_types=1);

/**
 * API Key Service
 *
 * Handles secure generation and storage of API credentials.
 * Plaintext API keys are NEVER stored in the database.
 */

require_once __DIR__ . '/runtime_service.php';

/**
 * Generate a cryptographically secure API key.
 *
 * @return array{
 *     plain_key: string,
 *     key_prefix: string,
 *     key_hash: string
 * }
 */
function api_key_generate(): array
{
    $random = random_bytes(48);

    $token = rtrim(
        strtr(base64_encode($random), '+/', '-_'),
        '='
    );

    $plainKey = 'mdp_' . $token;

    return [
        'plain_key' => $plainKey,
        'key_prefix' => substr($plainKey, 0, 16),
        'key_hash' => hash('sha256', $plainKey),
    ];
}

/**
 * Create an API key for a deployed runtime.
 *
 * The plaintext key is returned only once.
 */
function api_key_create(
    PDO $pdo,
    int $runtimeId,
    int $userId,
    string $name = ''
): array {
    /*
     * Validate identifiers.
     */
    if ($runtimeId < 1 || $userId < 1) {
        throw new InvalidArgumentException(
            'Invalid runtime or user ID.'
        );
    }

    /*
     * Validate API key name.
     */
    $name = trim($name);

    if ($name === '') {
        throw new InvalidArgumentException(
            'API key name is required.'
        );
    }

    if (mb_strlen($name) > 100) {
        throw new InvalidArgumentException(
            'API key name must not exceed 100 characters.'
        );
    }

    /*
     * Retrieve runtime and enforce ownership.
     */
    $runtime = runtime_service_get_runtime(
        $pdo,
        $runtimeId
    );

    if (!is_array($runtime) || empty($runtime['id'])) {
        throw new RuntimeException(
            'Runtime could not be verified.'
        );
    }

    /*
     * Check workspace readiness.
     */
    $workspaceStatus = strtolower(
        trim((string) ($runtime['workspace_status'] ?? ''))
    );

    if ($workspaceStatus !== 'ready') {
        throw new RuntimeException(
            'Runtime workspace is not ready.'
        );
    }

    /*
     * Check Python environment readiness.
     */
    $environmentStatus = strtolower(
        trim((string) ($runtime['environment_status'] ?? ''))
    );

    if (!in_array(
        $environmentStatus,
        ['created', 'ready'],
        true
    )) {
        throw new RuntimeException(
            'Python environment is not ready.'
        );
    }

    /*
     * Validate workspace path.
     */
    $workspacePath = trim(
        (string) ($runtime['workspace_path'] ?? '')
    );

    if ($workspacePath === '') {
        throw new RuntimeException(
            'Runtime workspace path is unavailable.'
        );
    }

    $workspace = runtime_service_workspace(
        $workspacePath
    );

    /*
     * Validate Python environment path and executable.
     */
    $environmentPath = trim(
        (string) ($runtime['environment_path'] ?? '')
    );

    if ($environmentPath === '') {
        throw new RuntimeException(
            'Python environment path is unavailable.'
        );
    }

    runtime_service_environment_python(
        $environmentPath
    );

    /*
     * Verify the actual inference process and HTTP health.
     */
    try {
        $serviceResult = runtime_service_run_manager(
            'status',
            $workspace,
            ''
        );
    } catch (Throwable $e) {
        error_log(
            'API key runtime health check failed: ' .
            $e->getMessage()
        );

        throw new RuntimeException(
            'The inference service could not be verified as healthy.'
        );
    }

    /*
     * Validate service manager response.
     *
     * The service manager returns status and health
     * at the top level, not inside a nested data object.
     */
    if (
        !is_array($serviceResult) ||
        ($serviceResult['success'] ?? false) !== true
    ) {
        error_log(
            'API key service manager returned an invalid response: ' .
            json_encode(
                $serviceResult,
                JSON_UNESCAPED_SLASHES
            )
        );

        throw new RuntimeException(
            'The inference service returned an unsuccessful status response.'
        );
    }

    /*
     * Read top-level service information.
     */
    $service = $serviceResult;

    $serviceStatus = strtolower(
        trim((string) ($service['status'] ?? ''))
    );

    $serviceHealth = strtolower(
        trim((string) ($service['health'] ?? ''))
    );

    /*
     * API keys can only be created for a genuinely
     * running and healthy inference service.
     */
    if (
        $serviceStatus !== 'running' ||
        $serviceHealth !== 'healthy'
    ) {
        error_log(
            'API key readiness check failed: ' .
            json_encode(
                [
                    'status' => $serviceStatus,
                    'health' => $serviceHealth,
                    'service_response' => $service,
                ],
                JSON_UNESCAPED_SLASHES
            )
        );

        throw new RuntimeException(
            'Inference service is not healthy. ' .
            'Status: ' . ($serviceStatus ?: 'missing') .
            '. Health: ' . ($serviceHealth ?: 'missing') .
            '.'
        );
    }

    /*
     * Generate the credential only after all readiness
     * and health checks have passed.
     */
    $credential = api_key_generate();

    /*
     * Store only the SHA-256 hash and key prefix.
     * The plaintext key is never stored in the database.
     */
    $insert = $pdo->prepare("
        INSERT INTO api_keys (
            runtime_id,
            user_id,
            name,
            key_prefix,
            key_hash,
            status
        )
        VALUES (
            :runtime_id,
            :user_id,
            :name,
            :key_prefix,
            :key_hash,
            'active'
        )
    ");

    $insert->execute([
        ':runtime_id' => $runtimeId,
        ':user_id' => $userId,
        ':name' => $name,
        ':key_prefix' => $credential['key_prefix'],
        ':key_hash' => $credential['key_hash'],
    ]);

    /*
     * Return the plaintext key once to the frontend.
     */
    return [
        'id' => (int) $pdo->lastInsertId(),
        'runtime_id' => (int) $runtime['id'],
        'runtime_key' => $runtime['runtime_key'],
        'model_version_id' => (int) $runtime['model_version_id'],
        'model_name' => $runtime['model_name'],
        'model_slug' => $runtime['model_slug'],
        'name' => $name,
        'key_prefix' => $credential['key_prefix'],
        'api_key' => $credential['plain_key'],
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
    ];
}
