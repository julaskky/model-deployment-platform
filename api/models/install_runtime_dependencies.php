<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

$input = getJsonInput();

$runtimeId = filter_var(
    $input['runtime_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($runtimeId === false || $runtimeId === null) {
    respond([
        'success' => false,
        'message' => 'A valid runtime_id is required.'
    ], 422);
}

$environmentId = 0;
$runtime = null;
$wheelhouseName = '';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

$normalizePackageName = static function (string $name): string {
    return strtolower(
        preg_replace('/[-_.]+/', '-', trim($name)) ?? ''
    );
};

$isWithinPath = static function (
    string $path,
    string $parent
): bool {
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $parent = rtrim($parent, DIRECTORY_SEPARATOR);

    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $parent = strtolower($parent);
    }

    return str_starts_with(
        $path,
        $parent . DIRECTORY_SEPARATOR
    );
};

$decodeJson = static function (string $json): array {
    return json_decode(
        $json,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
};

/*
|--------------------------------------------------------------------------
| Load and lock runtime and environment
|--------------------------------------------------------------------------
*/

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            r.id AS runtime_id,
            r.runtime_key,
            r.status AS runtime_status,
            r.workspace_status,
            r.python_version,
            r.manifest,

            mv.id AS model_version_id,
            m.id AS model_id,
            m.user_id,

            re.id AS environment_id,
            re.status AS environment_status,
            re.environment_path,
            re.dependency_status,
            re.updated_at AS environment_updated_at

        FROM model_runtimes r

        INNER JOIN model_versions mv
            ON mv.id = r.model_version_id

        INNER JOIN models m
            ON m.id = mv.model_id

        INNER JOIN runtime_environments re
            ON re.runtime_id = r.id

        WHERE r.id = ?
          AND m.user_id = ?

        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $runtimeId,
        $userId
    ]);

    $runtime = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$runtime) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Runtime or environment not found, or access denied.'
        ], 404);
    }

    if ($runtime['runtime_status'] !== 'registered') {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Dependencies can only be installed in a registered runtime.'
        ], 409);
    }

    if ($runtime['workspace_status'] !== 'ready') {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime workspace must be ready.'
        ], 409);
    }

    if ($runtime['environment_status'] !== 'created') {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The Python runtime environment must be created first.'
        ], 409);
    }

    if (!preg_match('/^[a-f0-9]{32}$/', $runtime['runtime_key'])) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime key is invalid.'
        ], 422);
    }

    $environmentId = (int) $runtime['environment_id'];

    /*
     * An installation older than 30 minutes is considered stale.
     * This allows the dashboard to retry after an interrupted request.
     */

    if ($runtime['dependency_status'] === 'installing') {
        $updatedAt = strtotime(
            (string) $runtime['environment_updated_at']
        );

        $isStale = $updatedAt !== false
            && $updatedAt < (time() - 1800);

        if (!$isStale) {
            $pdo->rollBack();

            respond([
                'success' => false,
                'message' => 'Dependency installation is already in progress.'
            ], 409);
        }

        $staleStmt = $pdo->prepare("
            UPDATE runtime_environments
            SET
                dependency_status = 'failed',
                dependency_errors = ?
            WHERE id = ?
              AND dependency_status = 'installing'
        ");

        $staleStmt->execute([
            json_encode([
                'The previous installation request did not complete within 30 minutes. A retry is permitted.'
            ], JSON_UNESCAPED_SLASHES),
            $environmentId
        ]);
    }

    if ($runtime['dependency_status'] === 'installed') {
        $pdo->commit();

        respond([
            'success' => true,
            'message' => 'Dependencies have already been installed for this runtime.',
            'data' => [
                'runtime_id' => (int) $runtimeId,
                'dependency_status' => 'installed'
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate runtime manifest
    |--------------------------------------------------------------------------
    */

    try {
        $manifest = $decodeJson((string) $runtime['manifest']);
    } catch (Throwable $exception) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The saved runtime manifest contains invalid JSON.'
        ], 422);
    }

    $manifestModelId = (int) (
        $manifest['model']['model_id'] ?? 0
    );

    $manifestVersionId = (int) (
        $manifest['model']['model_version_id'] ?? 0
    );

    $manifestFramework = (string) (
        $manifest['runtime']['framework'] ?? ''
    );

    $manifestPythonVersion = (string) (
        $manifest['runtime']['python_version'] ?? ''
    );

    $pythonVersion = (string) $runtime['python_version'];

    if (
        ($manifest['status'] ?? null) !== 'READY' ||
        $manifestModelId !== (int) $runtime['model_id'] ||
        $manifestVersionId !== (int) $runtime['model_version_id'] ||
        $manifestFramework === '' ||
        $manifestPythonVersion !== $pythonVersion
    ) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime manifest does not match the registered runtime.'
        ], 422);
    }

    if (!preg_match('/^\d+\.\d+$/', $pythonVersion)) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime Python version is invalid.'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate direct dependencies in the manifest
    |--------------------------------------------------------------------------
    */

    $manifestDependencies = $manifest['dependencies'] ?? null;

    if (!is_array($manifestDependencies)) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime manifest has no valid dependency list.'
        ], 422);
    }

    $expectedDirectDependencies = [];

    foreach ($manifestDependencies as $dependency) {
        if (!is_array($dependency)) {
            $pdo->rollBack();

            respond([
                'success' => false,
                'message' => 'The runtime manifest contains an invalid dependency.'
            ], 422);
        }

        $declaration = (string) (
            $dependency['declaration'] ?? ''
        );

        if (!preg_match(
            '/^([A-Za-z0-9][A-Za-z0-9._-]*)==([A-Za-z0-9][A-Za-z0-9.!+_-]*)$/D',
            $declaration,
            $matches
        )) {
            $pdo->rollBack();

            respond([
                'success' => false,
                'message' => 'A runtime dependency is not pinned to an exact version.'
            ], 422);
        }

        $packageName = $normalizePackageName($matches[1]);
        $packageVersion = $matches[2];

        if (
            isset($dependency['name']) &&
            $normalizePackageName((string) $dependency['name'])
                !== $packageName
        ) {
            $pdo->rollBack();

            respond([
                'success' => false,
                'message' => 'A dependency name does not match its declaration.'
            ], 422);
        }

        if (isset($expectedDirectDependencies[$packageName])) {
            $pdo->rollBack();

            respond([
                'success' => false,
                'message' => 'The runtime manifest contains duplicate dependencies.'
            ], 422);
        }

        $expectedDirectDependencies[$packageName] = $packageVersion;
    }

    ksort($expectedDirectDependencies);

    /*
    |--------------------------------------------------------------------------
    | Determine target platform
    |--------------------------------------------------------------------------
    */

    $platform = getenv('MODEL_RUNTIME_PLATFORM');

    if ($platform === false || $platform === '') {
        $platform = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Linux' => 'linux',
            'Darwin' => 'macos',
            default => ''
        };
    }

    if (!in_array($platform, ['windows', 'linux', 'macos'], true)) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime platform is not supported or configured.'
        ], 422);
    }

    $pythonTag = 'cp' . str_replace('.', '', $pythonVersion);

    $lockPlatform = $platform . '-' . $pythonTag;

    $wheelhouseName = $lockPlatform . '-resolved';

    /*
    |--------------------------------------------------------------------------
    | Mark installation as in progress
    |--------------------------------------------------------------------------
    */

    $updateStmt = $pdo->prepare("
        UPDATE runtime_environments
        SET
            dependency_status = 'installing',
            dependency_platform = ?,
            dependency_lock_path = NULL,
            dependency_verified_at = NULL,
            dependencies_installed_at = NULL,
            installed_packages = NULL,
            dependency_installation_details = NULL,
            dependency_errors = NULL
        WHERE id = ?
    ");

    $updateStmt->execute([
        $wheelhouseName,
        $environmentId
    ]);

    $pdo->commit();

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Dependency installation preparation error: '
        . $exception->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'Unable to prepare dependency installation.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Failure persistence
|--------------------------------------------------------------------------
*/

$failInstallation = static function (
    string $message,
    array $details = []
) use ($pdo, $environmentId): never {

    $message = substr($message, 0, 1500);

    try {
        $errorsJson = json_encode(
            [$message],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        $detailsJson = json_encode(
            $details,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        $stmt = $pdo->prepare("
            UPDATE runtime_environments
            SET
                dependency_status = 'failed',
                dependency_errors = ?,
                dependency_installation_details = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $errorsJson ?: '[]',
            $detailsJson ?: '{}',
            $environmentId
        ]);

    } catch (Throwable $exception) {
        error_log(
            'Could not persist dependency installation failure: '
            . $exception->getMessage()
        );
    }

    error_log(
        'Runtime dependency installation failed: ' . $message
    );

    respond([
        'success' => false,
        'message' => 'Runtime dependency installation failed.',
        'error' => $message
    ], 500);
};

/*
|--------------------------------------------------------------------------
| Resolve and validate private storage paths
|--------------------------------------------------------------------------
*/

$projectRoot = realpath(dirname(__DIR__, 2));

$runtimeStorageRoot = realpath(
    __DIR__ . '/../storage/runtimes'
);

$packageStorageRoot = realpath(
    __DIR__ . '/../storage/packages'
);

if (
    $projectRoot === false ||
    $runtimeStorageRoot === false ||
    $packageStorageRoot === false
) {
    $failInstallation(
        'The project, runtime, or package storage directory could not be resolved.'
    );
}

$expectedEnvironmentPath =
    'storage/runtimes/'
    . $runtime['runtime_key']
    . '/environment/python';

if ($runtime['environment_path'] !== $expectedEnvironmentPath) {
    $failInstallation(
        'The saved runtime environment path is invalid.'
    );
}

$runtimeWorkspace = realpath(
    $runtimeStorageRoot
    . DIRECTORY_SEPARATOR
    . $runtime['runtime_key']
);

if (
    $runtimeWorkspace === false ||
    !is_dir($runtimeWorkspace) ||
    !$isWithinPath($runtimeWorkspace, $runtimeStorageRoot)
) {
    $failInstallation(
        'The runtime workspace could not be safely resolved.'
    );
}

$environmentDirectory = realpath(
    $runtimeWorkspace
    . DIRECTORY_SEPARATOR
    . 'environment'
    . DIRECTORY_SEPARATOR
    . 'python'
);

if (
    $environmentDirectory === false ||
    !is_dir($environmentDirectory) ||
    !$isWithinPath($environmentDirectory, $runtimeStorageRoot)
) {
    $failInstallation(
        'The Python environment path could not be safely resolved.'
    );
}

/*
|--------------------------------------------------------------------------
| Resolve runtime Python executable
|--------------------------------------------------------------------------
*/

$pythonRelativePath = PHP_OS_FAMILY === 'Windows'
    ? 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe'
    : 'bin' . DIRECTORY_SEPARATOR . 'python';

$pythonExecutable = realpath(
    $environmentDirectory
    . DIRECTORY_SEPARATOR
    . $pythonRelativePath
);

if (
    $pythonExecutable === false ||
    !is_file($pythonExecutable) ||
    !$isWithinPath($pythonExecutable, $environmentDirectory)
) {
    $failInstallation(
        'The runtime Python executable could not be safely resolved.'
    );
}

/*
|--------------------------------------------------------------------------
| Resolve wheelhouse and lockfile
|--------------------------------------------------------------------------
*/

$wheelhouse = realpath(
    $packageStorageRoot
    . DIRECTORY_SEPARATOR
    . $wheelhouseName
);

if (
    $wheelhouse === false ||
    !is_dir($wheelhouse) ||
    !$isWithinPath($wheelhouse, $packageStorageRoot)
) {
    $failInstallation(
        'The required dependency wheelhouse does not exist: '
        . $wheelhouseName
    );
}

$lockfile = realpath(
    $wheelhouse . DIRECTORY_SEPARATOR . 'dependency-lock.json'
);

if (
    $lockfile === false ||
    !is_file($lockfile) ||
    !$isWithinPath($lockfile, $wheelhouse)
) {
    $failInstallation(
        'The dependency lockfile could not be safely resolved.'
    );
}

/*
|--------------------------------------------------------------------------
| Validate lockfile against runtime manifest
|--------------------------------------------------------------------------
*/

try {
    $lockData = $decodeJson(
        (string) file_get_contents($lockfile)
    );
} catch (Throwable $exception) {
    $failInstallation(
        'The dependency lockfile contains invalid JSON.'
    );
}

$lockPythonVersion = (string) (
    $lockData['python_version'] ?? ''
);

$lockFramework = (string) (
    $lockData['framework'] ?? ''
);

$lockModelId = (int) (
    $lockData['model_id'] ?? 0
);

$lockModelVersionId = (int) (
    $lockData['model_version_id'] ?? 0
);

if (
    ($lockData['lock_version'] ?? null) !== '1.0' ||
    ($lockData['platform'] ?? null) !== $lockPlatform ||
    $lockFramework !== $manifestFramework ||
    $lockPythonVersion !== $pythonVersion ||
    ($lockData['integrity_algorithm'] ?? null) !== 'sha256'
) {
    $failInstallation(
        'The dependency lockfile does not match the runtime configuration.'
    );
}

$lockDirectDependencies = $lockData['direct_dependencies'] ?? null;

if (!is_array($lockDirectDependencies)) {
    $failInstallation(
        'The dependency lockfile has no valid direct dependency list.'
    );
}

$lockedDirectDependencies = [];

foreach ($lockDirectDependencies as $dependency) {
    if (
        !is_array($dependency) ||
        !is_string($dependency['name'] ?? null) ||
        !is_string($dependency['version'] ?? null)
    ) {
        $failInstallation(
            'The lockfile contains an invalid direct dependency.'
        );
    }

    $name = $normalizePackageName($dependency['name']);
    $version = $dependency['version'];

    if (isset($lockedDirectDependencies[$name])) {
        $failInstallation(
            'The lockfile contains duplicate direct dependencies.'
        );
    }

    $lockedDirectDependencies[$name] = $version;
}

ksort($lockedDirectDependencies);

if ($lockedDirectDependencies !== $expectedDirectDependencies) {
    $failInstallation(
        'The lockfile direct dependencies do not match the runtime manifest.'
    );
}

/*
|--------------------------------------------------------------------------
| Validate all resolved package records
|--------------------------------------------------------------------------
*/

$lockedPackages = $lockData['packages'] ?? null;

if (
    !is_array($lockedPackages) ||
    count($lockedPackages) < 1 ||
    (int) ($lockData['resolved_package_count'] ?? 0)
        !== count($lockedPackages)
) {
    $failInstallation(
        'The lockfile resolved package count is invalid.'
    );
}

$lockedPackageMap = [];

foreach ($lockedPackages as $package) {
    if (
        !is_array($package) ||
        !is_string($package['name'] ?? null) ||
        !is_string($package['version'] ?? null) ||
        !is_string($package['filename'] ?? null) ||
        !is_string($package['sha256'] ?? null)
    ) {
        $failInstallation(
            'The lockfile contains an invalid package record.'
        );
    }

    $name = $normalizePackageName($package['name']);

    if (
        $name === '' ||
        isset($lockedPackageMap[$name]) ||
        !preg_match('/^[a-f0-9]{64}$/', $package['sha256']) ||
        basename($package['filename']) !== $package['filename'] ||
        !str_ends_with(strtolower($package['filename']), '.whl')
    ) {
        $failInstallation(
            'The lockfile contains a duplicate or unsafe package record.'
        );
    }

    $lockedPackageMap[$name] = $package['version'];
}

foreach ($expectedDirectDependencies as $name => $version) {
    if (
        !isset($lockedPackageMap[$name]) ||
        $lockedPackageMap[$name] !== $version
    ) {
        $failInstallation(
            'A direct dependency is missing or has a different version in the resolved packages.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Resolve Python engine and installer
|--------------------------------------------------------------------------
*/

$pythonEngineRoot = realpath(
    $projectRoot . DIRECTORY_SEPARATOR . 'python_engine'
);

if ($pythonEngineRoot === false || !is_dir($pythonEngineRoot)) {
    $failInstallation(
        'The Python engine directory could not be resolved.'
    );
}

$installerScript = realpath(
    $pythonEngineRoot
    . DIRECTORY_SEPARATOR
    . 'runtime_environment'
    . DIRECTORY_SEPARATOR
    . 'dependency_installer.py'
);

if (
    $installerScript === false ||
    !is_file($installerScript) ||
    !$isWithinPath($installerScript, $pythonEngineRoot)
) {
    $failInstallation(
        'The dependency installer script could not be safely resolved.'
    );
}

/*
|--------------------------------------------------------------------------
| Resolve system Python used to launch the installer
|--------------------------------------------------------------------------
*/

$pythonBinary = getenv('MODEL_PYTHON_BINARY');

if ($pythonBinary === false || trim($pythonBinary) === '') {
    $pythonBinary = 'python';
}

$pythonBinary = trim($pythonBinary);

if (is_file($pythonBinary)) {
    $resolvedPythonBinary = realpath($pythonBinary);

    if ($resolvedPythonBinary === false) {
        $failInstallation(
            'The configured system Python executable could not be resolved.'
        );
    }

    $pythonBinary = $resolvedPythonBinary;

} elseif (!in_array($pythonBinary, ['python', 'python3'], true)) {
    $failInstallation(
        'MODEL_PYTHON_BINARY must be an executable path or python/python3.'
    );
}

/*
|--------------------------------------------------------------------------
| Execute the controlled offline installer
|--------------------------------------------------------------------------
*/

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

$command = [
    $pythonBinary,
    $installerScript,
    '--python',
    $pythonExecutable,
    '--wheelhouse',
    $wheelhouse,
    '--lockfile',
    $lockfile
];

$stderrFile = tempnam(sys_get_temp_dir(), 'mdp_dependency_');

if ($stderrFile === false) {
    $failInstallation(
        'Unable to create a temporary installer log.'
    );
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['file', $stderrFile, 'a']
];

$process = proc_open(
    $command,
    $descriptors,
    $pipes,
    $pythonEngineRoot
);

if (!is_resource($process)) {
    @unlink($stderrFile);

    $failInstallation(
        'Unable to start the dependency installer.'
    );
}

fclose($pipes[0]);

$maximumStdoutBytes = 2 * 1024 * 1024;

$stdout = stream_get_contents(
    $pipes[1],
    $maximumStdoutBytes + 1
);

fclose($pipes[1]);

if (
    $stdout === false ||
    strlen($stdout) > $maximumStdoutBytes
) {
    proc_terminate($process);
    proc_close($process);
    @unlink($stderrFile);

    $failInstallation(
        'The dependency installer returned excessive output.'
    );
}

$exitCode = proc_close($process);

$stderr = '';

if (is_file($stderrFile)) {
    $stderrHandle = fopen($stderrFile, 'rb');

    if ($stderrHandle !== false) {
        $stderr = (string) fread($stderrHandle, 65536);
        fclose($stderrHandle);
    }
}

@unlink($stderrFile);

$result = json_decode(
    (string) $stdout,
    true
);

if (
    $exitCode !== 0 ||
    !is_array($result) ||
    empty($result['success']) ||
    ($result['status'] ?? null) !== 'installed'
) {
    $message = is_array($result)
        ? (string) ($result['message'] ?? 'Dependency installation failed.')
        : trim($stderr);

    if ($message === '') {
        $message = 'The Python installer returned an invalid response.';
    }

    $failInstallation(
        $message,
        [
            'stdout' => trim((string) $stdout),
            'stderr' => trim($stderr),
            'exit_code' => $exitCode
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Verify installer result against the lockfile
|--------------------------------------------------------------------------
*/

$installedPackages = $result['installed_packages'] ?? null;

$verification = $result['verification'] ?? null;

if (
    !is_array($installedPackages) ||
    !is_array($verification) ||
    count($installedPackages) !== count($lockedPackageMap) ||
    (int) ($result['installed_count'] ?? 0)
        !== count($installedPackages) ||
    ($result['network_access'] ?? null) !== false ||
    ($verification['status'] ?? null) !== 'verified' ||
    (int) ($verification['verified_count'] ?? 0)
        !== (int) ($verification['expected_count'] ?? -1) ||
    (int) ($verification['expected_count'] ?? 0)
        !== count($lockedPackageMap)
) {
    $failInstallation(
        'The installer result did not pass application-level verification.',
        $result
    );
}

$installedPackageMap = [];

foreach ($installedPackages as $package) {
    if (
        !is_array($package) ||
        !is_string($package['name'] ?? null) ||
        !is_string($package['version'] ?? null)
    ) {
        $failInstallation(
            'The installer returned an invalid installed package record.',
            $result
        );
    }

    $name = $normalizePackageName($package['name']);

    if (
        $name === '' ||
        isset($installedPackageMap[$name])
    ) {
        $failInstallation(
            'The installer returned duplicate or invalid installed packages.',
            $result
        );
    }

    $installedPackageMap[$name] = $package['version'];
}

ksort($installedPackageMap);

if ($installedPackageMap !== $lockedPackageMap) {
    $failInstallation(
        'The installed package versions do not match the verified lockfile.',
        $result
    );
}

/*
|--------------------------------------------------------------------------
| Persist successful installation
|--------------------------------------------------------------------------
*/

$relativeLockPath =
    'storage/packages/'
    . $wheelhouseName
    . '/dependency-lock.json';

try {
    $installedPackagesJson = json_encode(
        $installedPackages,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR
    );

    $detailsJson = json_encode(
        $result,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE |
        JSON_THROW_ON_ERROR
    );

    $pdo->beginTransaction();

    $saveStmt = $pdo->prepare("
        UPDATE runtime_environments
        SET
            dependency_status = 'installed',
            dependency_platform = ?,
            dependency_lock_path = ?,
            dependency_verified_at = CURRENT_TIMESTAMP,
            dependencies_installed_at = CURRENT_TIMESTAMP,
            installed_packages = ?,
            dependency_installation_details = ?,
            dependency_errors = NULL
        WHERE id = ?
          AND dependency_status = 'installing'
    ");

    $saveStmt->execute([
        $wheelhouseName,
        $relativeLockPath,
        $installedPackagesJson,
        $detailsJson,
        $environmentId
    ]);

    if ($saveStmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Dependency installation state could not be updated.'
        );
    }

    $pdo->commit();

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Dependency installation persistence error: '
        . $exception->getMessage()
    );

    $failInstallation(
        'Packages were installed, but their installation state could not be saved.'
    );
}

/*
|--------------------------------------------------------------------------
| Return successful result
|--------------------------------------------------------------------------
*/

respond([
    'success' => true,
    'message' => 'Runtime dependencies installed and verified successfully.',
    'data' => [
        'runtime_id' => (int) $runtimeId,
        'environment_id' => $environmentId,
        'dependency_status' => 'installed',
        'platform' => $wheelhouseName,
        'verified_count' => (int) $verification['verified_count'],
        'installed_count' => count($installedPackages),
        'verified_at' => date('Y-m-d H:i:s'),
        'installed_packages' => $installedPackages,
        'network_access' => false
    ]
], 200);