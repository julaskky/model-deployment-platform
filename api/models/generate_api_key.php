<?php
declare(strict_types=1);

require_once __DIR__ . '/api_key_service.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    runtime_service_json(
        false,
        [],
        'POST method required.',
        405
    );
}

try {
    /*
     * Existing platform authentication and CSRF protection.
     */
    runtime_service_auth();
    runtime_service_csrf();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        runtime_service_json(
            false,
            [],
            'Invalid request body.',
            400
        );
    }

    $runtimeId = filter_var(
        $input['runtime_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$runtimeId || $runtimeId < 1) {
        runtime_service_json(
            false,
            [],
            'Invalid runtime ID.',
            400
        );
    }

    /*
     * API key name is mandatory.
     */
    if (
        !array_key_exists('name', $input) ||
        !is_string($input['name'])
    ) {
        runtime_service_json(
            false,
            [],
            'API key name is required.',
            400
        );
    }

    $name = trim($input['name']);

    if ($name === '') {
        runtime_service_json(
            false,
            [],
            'API key name is required.',
            400
        );
    }

    if (mb_strlen($name) > 100) {
        runtime_service_json(
            false,
            [],
            'API key name must not exceed 100 characters.',
            400
        );
    }

    /*
     * Use the authenticated platform user.
     */
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId < 1) {
        runtime_service_json(
            false,
            [],
            'Authenticated user could not be identified.',
            401
        );
    }

    $pdo = runtime_service_database();

    $result = api_key_create(
        $pdo,
        (int) $runtimeId,
        $userId,
        $name
    );

    runtime_service_json(
        true,
        $result,
        'API key generated successfully.'
    );

} catch (InvalidArgumentException $e) {

    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        400
    );

} catch (RuntimeException $e) {

    /*
     * Deployment/readiness errors are safe to show to the
     * authenticated platform user because they describe the
     * current runtime state.
     */
    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        409
    );

} catch (Throwable $e) {

    /*
     * Do not expose unexpected internal PHP/database errors.
     */
    error_log(
        'API key generation error: ' .
        $e->getMessage()
    );

    runtime_service_json(
        false,
        [],
        'An internal error occurred while generating the API key.',
        500
    );
}