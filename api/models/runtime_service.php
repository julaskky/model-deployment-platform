<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// --------------------------------------------------
// JSON RESPONSE
// --------------------------------------------------

function runtime_service_json(
    bool $success,
    array $data = [],
    string $message = '',
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

// --------------------------------------------------
// DATABASE CONNECTION
// --------------------------------------------------

function runtime_service_database(): PDO
{
    foreach ([
        'db',
        'getDb',
        'getPDO',
        'getDatabaseConnection',
        'database',
    ] as $function) {
        if (function_exists($function)) {
            $pdo = $function();

            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }
    }

    if (
        isset($GLOBALS['pdo']) &&
        $GLOBALS['pdo'] instanceof PDO
    ) {
        return $GLOBALS['pdo'];
    }

    throw new RuntimeException(
        'Database connection is unavailable.'
    );
}

// --------------------------------------------------
// AUTHENTICATION
// --------------------------------------------------

function runtime_service_auth(): void
{
    if (function_exists('requireAuth')) {
        requireAuth();
        return;
    }

    if (function_exists('require_auth')) {
        require_auth();
        return;
    }

    if (empty($_SESSION['user_id'])) {
        runtime_service_json(
            false,
            [],
            'Authentication required.',
            401
        );
    }
}

// --------------------------------------------------
// CSRF VALIDATION
// --------------------------------------------------

function runtime_service_csrf(): void
{
    if (!function_exists('requireCsrf')) {
        throw new RuntimeException(
            'Application CSRF validation helper is unavailable.'
        );
    }

    requireCsrf();
}

// --------------------------------------------------
// PYTHON EXECUTABLE FOR SERVICE MANAGER
// --------------------------------------------------

function runtime_service_environment_python(
    string $environmentPath
): string {
    $environmentPath = trim($environmentPath);

    if ($environmentPath === '') {
        throw new RuntimeException(
            'Runtime Python environment path is empty.'
        );
    }

    // Resolve relative paths from the project root.
    if (
        !preg_match('/^(?:[a-zA-Z]:[\\\\\/]|\\\\\\\\)/', $environmentPath)
    ) {
        $environmentPath = dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                ltrim($environmentPath, '/\\')
            );
    }

    $environment = realpath($environmentPath);

    if ($environment === false || !is_dir($environment)) {
        throw new RuntimeException(
            'Runtime Python environment does not exist. '
            . 'Resolved path: ' . $environmentPath
        );
    }

    $candidates = [];

    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = $environment
            . DIRECTORY_SEPARATOR . 'Scripts'
            . DIRECTORY_SEPARATOR . 'python.exe';
    }

    $candidates[] = $environment
        . DIRECTORY_SEPARATOR . 'bin'
        . DIRECTORY_SEPARATOR . 'python';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        'Runtime Python executable was not found in: '
        . $environment
    );
}

// --------------------------------------------------
// PRIVATE RUNTIME STORAGE ROOT
// --------------------------------------------------

function runtime_service_storage_root(): string
{
    $root = realpath(
        __DIR__ . '/../storage/runtimes'
    );

    if ($root === false || !is_dir($root)) {
        throw new RuntimeException(
            'Private runtime storage directory does not exist.'
        );
    }

    return rtrim(
        $root,
        DIRECTORY_SEPARATOR
    );
}

// --------------------------------------------------
// PATH CONTAINMENT VALIDATION
// --------------------------------------------------

function runtime_service_path_is_inside(
    string $path,
    string $root
): bool {
    $path = rtrim(
        $path,
        DIRECTORY_SEPARATOR
    );

    $root = rtrim(
        $root,
        DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR;

    if (PHP_OS_FAMILY === 'Windows') {
        return strncasecmp(
            $path . DIRECTORY_SEPARATOR,
            $root,
            strlen($root)
        ) === 0;
    }

    return str_starts_with(
        $path . DIRECTORY_SEPARATOR,
        $root
    );
}

// --------------------------------------------------
// RUNTIME WORKSPACE RESOLUTION
// --------------------------------------------------

function runtime_service_workspace(string $workspacePath): string
{
    $runtimeRoot = runtime_service_storage_root();

    $apiRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');

    if ($apiRoot === false) {
        throw new RuntimeException(
            'Unable to resolve the API directory.'
        );
    }

    $storedPath = trim($workspacePath);
    $candidates = [];

    /*
     * Resolve the stored database path.
     *
     * Absolute paths are checked directly.
     * Relative paths are resolved from the API directory,
     * not PHP's current working directory.
     */
    if ($storedPath !== '') {
        $normalizedPath = str_replace(
            ['/', '\\'],
            DIRECTORY_SEPARATOR,
            $storedPath
        );

        $isAbsolute = (bool) preg_match(
            '/^(?:[A-Za-z]:\\\\|\\\\\\\\|\/)/',
            $storedPath
        );

        if ($isAbsolute) {
            $candidates[] = $normalizedPath;
        } else {
            $candidates[] = $apiRoot
                . DIRECTORY_SEPARATOR
                . ltrim($normalizedPath, "\\/");
        }
    }

    /*
     * Fallback: resolve using the runtime key.
     */
    $runtimeKey = basename(
        str_replace(
            '\\',
            '/',
            rtrim($storedPath, "/\\")
        )
    );

    if (preg_match('/^[a-f0-9]{32}$/i', $runtimeKey)) {
        $candidates[] = $runtimeRoot
            . DIRECTORY_SEPARATOR
            . $runtimeKey;
    }

    /*
     * Find a valid workspace inside private runtime storage.
     */
    $workspace = '';

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);

        if (
            $resolved !== false &&
            is_dir($resolved) &&
            runtime_service_path_is_inside(
                $resolved,
                $runtimeRoot
            )
        ) {
            $workspace = $resolved;
            break;
        }
    }

    if ($workspace === '') {
        throw new RuntimeException(
            'Runtime workspace could not be resolved. '
            . 'Database path: ' . $storedPath
            . '. Runtime key: ' . $runtimeKey
        );
    }

    /*
     * Verify that the workspace has the required structure.
     */
    $requiredDirectories = [
        'config',
        'model',
        'model/artifacts',
        'environment',
        'runtime',
    ];

    foreach ($requiredDirectories as $directory) {
        $path = $workspace
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $directory
            );

        if (!is_dir($path)) {
            throw new RuntimeException(
                'Runtime workspace is incomplete. '
                . 'Missing directory: ' . $directory
            );
        }
    }

    return $workspace;
}


// --------------------------------------------------
// RUNTIME PYTHON ENVIRONMENT
// --------------------------------------------------

//====== We removed duplicate function============

function runtime_service_python(): string
{
    $configured = getenv('MODEL_PYTHON_BINARY');

    if ($configured && is_file($configured)) {
        return $configured;
    }

    // Use the system Python executable to run the service manager.
    if (PHP_OS_FAMILY === 'Windows') {
        return 'python';
    }

    return 'python3';
}


// --------------------------------------------------
// INFERENCE SERVICE MANAGER
// --------------------------------------------------

function runtime_service_manager(): string
{
    $path = realpath(
        __DIR__
        . '/../../python_engine/inference/service_manager.py'
    );

    if (
        $path === false ||
        !is_file($path)
    ) {
        throw new RuntimeException(
            'Inference service manager was not found.'
        );
    }

    return $path;
}

// --------------------------------------------------
// EXECUTE SERVICE MANAGER
// --------------------------------------------------

function runtime_service_run_manager(
    string $action,
    string $workspace,
    string $python
): array {
    $manager = runtime_service_manager();

    $managerPython = runtime_service_python();

    $command = [
        $managerPython,
        $manager,
        '--action',
        $action,
        '--workspace',
        $workspace,
    ];

    if ($action === 'start') {
        $command[] = '--python';
        $command[] = $python;
    }

    $escaped = [];

    foreach ($command as $part) {
        $escaped[] = '"' . str_replace(
            '"',
            '\"',
            $part
        ) . '"';
    }

    $commandLine = implode(' ', $escaped);

    $output = [];
    $exitCode = 0;

    exec(
        $commandLine . ' 2>&1',
        $output,
        $exitCode
    );

    $text = trim(
        implode("\n", $output)
    );

    /*
     * service_manager.py should emit a JSON response.
     */
    $lines = preg_split(
        '/\R/',
        $text ?: ''
    );

    for (
        $i = count($lines) - 1;
        $i >= 0;
        $i--
    ) {
        $line = trim($lines[$i]);

        if ($line === '') {
            continue;
        }

        $decoded = json_decode(
            $line,
            true
        );

        if (is_array($decoded)) {
            if (
                $exitCode !== 0 &&
                ($decoded['success'] ?? false) !== true
            ) {
                throw new RuntimeException(
                    $decoded['message']
                    ?? 'Runtime service operation failed.'
                );
            }

            return $decoded;
        }
    }

    if ($exitCode !== 0) {
        throw new RuntimeException(
            $text ?: 'Runtime service operation failed.'
        );
    }

    throw new RuntimeException(
        'Runtime service returned an invalid response. '
        . 'Output: ' . $text
    );
}

// --------------------------------------------------
// GET RUNTIME AND RELATED MODEL INFORMATION
// --------------------------------------------------

function runtime_service_get_runtime(
    PDO $pdo,
    int $runtimeId
): array {
    $sql = "
        SELECT
            r.id,
            r.runtime_key,
            r.runtime_name,
            r.engine_version,
            r.status,
            r.workspace_status,
            r.workspace_path,

            re.id AS environment_id,
            re.status AS environment_status,
            re.environment_path,

            mv.id AS model_version_id,
            mv.version,

            m.id AS model_id,
            m.name AS model_name,
            m.slug AS model_slug

        FROM model_runtimes r

        INNER JOIN model_versions mv
            ON mv.id = r.model_version_id

        INNER JOIN models m
            ON m.id = mv.model_id

        LEFT JOIN runtime_environments re
            ON re.runtime_id = r.id

        WHERE r.id = :runtime_id
          AND m.user_id = :user_id

        LIMIT 1
    ";

    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        throw new RuntimeException(
            'Authenticated user not found.'
        );
    }

    $statement = $pdo->prepare($sql);

    $statement->execute([
        ':runtime_id' => $runtimeId,
        ':user_id' => $userId,
    ]);

    $runtime = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$runtime) {
        throw new RuntimeException(
            'Runtime was not found.'
        );
    }

    return $runtime;
}
