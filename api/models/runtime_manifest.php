<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');

$userId = requireAuth();

requireCsrf();

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

const RUNTIME_MANIFEST_SCRIPT =
    __DIR__ . '/../../python_engine/build_runtime_manifest.py';

const RUNTIME_MANIFEST_TIMEOUT = 60;

/*
|--------------------------------------------------------------------------
| Decode saved inspection JSON
|--------------------------------------------------------------------------
*/

function decodeInspectionJson(
    mixed $value,
    string $field
): array {
    if (!is_string($value) || $value === '') {
        throw new RuntimeException(
            "Saved inspection field '{$field}' is missing."
        );
    }

    try {
        $decoded = json_decode(
            $value,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        throw new RuntimeException(
            "Saved inspection field '{$field}' contains invalid JSON."
        );
    }

    if (!is_array($decoded)) {
        throw new RuntimeException(
            "Saved inspection field '{$field}' has an invalid structure."
        );
    }

    return $decoded;
}

/*
|--------------------------------------------------------------------------
| Run Python runtime manifest builder
|--------------------------------------------------------------------------
*/

function runRuntimeManifestBuilder(array $payload): array
{
    $scriptPath = RUNTIME_MANIFEST_SCRIPT;

    if (!is_file($scriptPath)) {
        throw new RuntimeException(
            'Runtime manifest builder script was not found.'
        );
    }

    $pythonBinary = getenv('MODEL_PYTHON_BINARY');

    if (
        !is_string($pythonBinary) ||
        trim($pythonBinary) === ''
    ) {
        $pythonBinary = 'python';
    }

    $stdoutPath = tempnam(
        sys_get_temp_dir(),
        'runtime_manifest_out_'
    );

    $stderrPath = tempnam(
        sys_get_temp_dir(),
        'runtime_manifest_err_'
    );

    if ($stdoutPath === false || $stderrPath === false) {
        throw new RuntimeException(
            'Unable to create temporary process output files.'
        );
    }

    $process = null;
    $stdin = null;

    try {
        $command = [
            $pythonBinary,
            $scriptPath
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w']
        ];

        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__, 2)
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Unable to start the Python runtime manifest builder.'
            );
        }

        $stdin = $pipes[0];

        $jsonInput = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );

        $written = 0;
        $inputLength = strlen($jsonInput);

        while ($written < $inputLength) {
            $result = fwrite(
                $stdin,
                substr($jsonInput, $written)
            );

            if ($result === false || $result === 0) {
                throw new RuntimeException(
                    'Unable to send inspection data to Python.'
                );
            }

            $written += $result;
        }

        fclose($stdin);
        $stdin = null;

        /*
        |--------------------------------------------------------------------------
        | Wait for process with timeout
        |--------------------------------------------------------------------------
        */

        $startedAt = microtime(true);
        $timedOut = false;

        do {
            $processStatus = proc_get_status($process);

            if (!$processStatus['running']) {
                break;
            }

            if (
                microtime(true) - $startedAt >
                RUNTIME_MANIFEST_TIMEOUT
            ) {
                $timedOut = true;

                proc_terminate($process);

                break;
            }

            usleep(100000);

        } while (true);

        if ($timedOut) {
            throw new RuntimeException(
                'Runtime manifest generation exceeded the time limit.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Read output
        |--------------------------------------------------------------------------
        */

        $stdout = file_get_contents($stdoutPath);
        $stderr = file_get_contents($stderrPath);

        if ($stdout === false || trim($stdout) === '') {
            error_log(
                'Runtime manifest Python error: ' .
                ($stderr ?: 'No diagnostic output.')
            );

            throw new RuntimeException(
                'The Python builder returned no valid output.'
            );
        }

        try {
            $result = json_decode(
                $stdout,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            error_log(
                'Invalid runtime manifest JSON: ' . $stdout
            );

            throw new RuntimeException(
                'The Python builder returned invalid JSON.'
            );
        }

        if (!is_array($result)) {
            throw new RuntimeException(
                'The Python builder returned an invalid response.'
            );
        }

        if (!empty($stderr)) {
            error_log(
                'Runtime manifest diagnostic: ' . $stderr
            );
        }

        return $result;

    } finally {
        if (is_resource($stdin)) {
            fclose($stdin);
        }

        if (is_resource($process)) {
            proc_close($process);
        }

        if (is_file($stdoutPath)) {
            unlink($stdoutPath);
        }

        if (is_file($stderrPath)) {
            unlink($stderrPath);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Validate request
|--------------------------------------------------------------------------
*/

$input = getJsonInput();

$modelId = filter_var(
    $input['model_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

$modelVersionId = filter_var(
    $input['model_version_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

$pythonVersion = $input['python_version'] ?? null;

if ($modelId === false || $modelId === null) {
    respond([
        'success' => false,
        'message' => 'A valid model_id is required.'
    ], 422);
}

if (
    $modelVersionId === false ||
    $modelVersionId === null
) {
    respond([
        'success' => false,
        'message' => 'A valid model_version_id is required.'
    ], 422);
}

if (
    !is_string($pythonVersion) ||
    !preg_match('/^\d+\.\d+$/', trim($pythonVersion))
) {
    respond([
        'success' => false,
        'message' =>
            'Python version must use MAJOR.MINOR format, for example 3.14.'
    ], 422);
}

$pythonVersion = trim($pythonVersion);

/*
|--------------------------------------------------------------------------
| Retrieve model version and inspection
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT
        m.id AS model_id,
        m.name AS model_name,

        mv.id AS model_version_id,
        mv.version AS version_number,
        mv.status AS version_status,

        mi.status AS inspection_status,
        mi.framework,
        mi.summary,
        mi.metadata,
        mi.files,
        mi.requirements,
        mi.errors,
        mi.warnings,
        mi.inspected_at

    FROM models m

    INNER JOIN model_versions mv
        ON mv.model_id = m.id

    INNER JOIN model_inspections mi
        ON mi.model_version_id = mv.id

    WHERE
        m.id = ?
        AND m.user_id = ?
        AND mv.id = ?

    LIMIT 1"
);

$stmt->execute([
    $modelId,
    $userId,
    $modelVersionId
]);

$record = $stmt->fetch();

if (!$record) {
    respond([
        'success' => false,
        'message' =>
            'The requested model version was not found.'
    ], 404);
}

/*
|--------------------------------------------------------------------------
| Require successful inspection
|--------------------------------------------------------------------------
*/

if (
    $record['version_status'] !== 'ready' ||
    $record['inspection_status'] !== 'ready'
) {
    respond([
        'success' => false,
        'message' =>
            'Runtime manifest generation requires a successfully inspected model version.',

        'data' => [
            'version_status' =>
                $record['version_status'],

            'inspection_status' =>
                $record['inspection_status']
        ]
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Reconstruct inspection result
|--------------------------------------------------------------------------
*/

try {
    $inspection = [
        'success' => true,

        'status' => 'READY',

        'framework' =>
            $record['framework'],

        'summary' =>
            $record['summary'],

        'metadata' =>
            decodeInspectionJson(
                $record['metadata'],
                'metadata'
            ),

        'files' =>
            decodeInspectionJson(
                $record['files'],
                'files'
            ),

        'requirements' =>
            decodeInspectionJson(
                $record['requirements'],
                'requirements'
            ),

        'errors' =>
            $record['errors'] !== null
                ? decodeInspectionJson(
                    $record['errors'],
                    'errors'
                )
                : [],

        'warnings' =>
            $record['warnings'] !== null
                ? decodeInspectionJson(
                    $record['warnings'],
                    'warnings'
                )
                : []
    ];

    /*
    |--------------------------------------------------------------------------
    | Build Python payload
    |--------------------------------------------------------------------------
    */

    $payload = [
        'inspection' => $inspection,

        'model_id' =>
            (int) $record['model_id'],

        'model_version_id' =>
            (int) $record['model_version_id'],

        'python_version' =>
            $pythonVersion
    ];

    /*
    |--------------------------------------------------------------------------
    | Generate manifest
    |--------------------------------------------------------------------------
    */

    $result = runRuntimeManifestBuilder(
        $payload
    );

    if (
        empty($result['success']) ||
        !isset($result['manifest']) ||
        !is_array($result['manifest'])
    ) {
        respond([
            'success' => false,

            'message' =>
                'Runtime manifest generation could not be completed.',

            'data' => [
                'status' =>
                    $result['status'] ?? 'FAILED',

                'errors' =>
                    $result['errors'] ?? []
            ]
        ], 422);
    }

    $manifest = $result['manifest'];

    /*
    |--------------------------------------------------------------------------
    | Extract runtime metadata
    |--------------------------------------------------------------------------
    */

    $manifestVersion =
        isset($manifest['manifest_version'])
            ? (string) $manifest['manifest_version']
            : '1.0';

    $framework =
        isset($manifest['runtime']['framework'])
            ? (string) $manifest['runtime']['framework']
            : (string) $record['framework'];

    $frameworkVersion =
        $manifest['runtime']['framework_version']
            ?? null;

    if ($frameworkVersion !== null) {
        $frameworkVersion =
            (string) $frameworkVersion;
    }

    /*
    |--------------------------------------------------------------------------
    | Encode manifest for database
    |--------------------------------------------------------------------------
    */

    $manifestJson = json_encode(
        $manifest,
        JSON_THROW_ON_ERROR |
        JSON_UNESCAPED_UNICODE
    );

    /*
    |--------------------------------------------------------------------------
    | Persist runtime configuration
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    try {
        /*
        |--------------------------------------------------------------------------
        | Lock model version
        |--------------------------------------------------------------------------
        */

        $lockStmt = $pdo->prepare(
            "SELECT id
             FROM model_versions
             WHERE id = ?
             FOR UPDATE"
        );

        $lockStmt->execute([
            $modelVersionId
        ]);

        if (!$lockStmt->fetch()) {
            throw new RuntimeException(
                'The model version no longer exists.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Upsert runtime configuration
        |--------------------------------------------------------------------------
        */

        $runtimeStmt = $pdo->prepare(
            "INSERT INTO model_runtime_configurations (
                model_version_id,
                status,
                manifest_version,
                python_version,
                framework,
                framework_version,
                manifest,
                errors
            )
            VALUES (
                ?,
                'generated',
                ?,
                ?,
                ?,
                ?,
                ?,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                status = 'generated',
                manifest_version = VALUES(manifest_version),
                python_version = VALUES(python_version),
                framework = VALUES(framework),
                framework_version = VALUES(framework_version),
                manifest = VALUES(manifest),
                errors = NULL,
                updated_at = CURRENT_TIMESTAMP"
        );

        $runtimeStmt->execute([
            $modelVersionId,
            $manifestVersion,
            $pythonVersion,
            $framework,
            $frameworkVersion,
            $manifestJson
        ]);

        /*
        |--------------------------------------------------------------------------
        | Retrieve saved configuration ID
        |--------------------------------------------------------------------------
        */

        $idStmt = $pdo->prepare(
            "SELECT id
             FROM model_runtime_configurations
             WHERE model_version_id = ?
             LIMIT 1"
        );

        $idStmt->execute([
            $modelVersionId
        ]);

        $runtimeId = $idStmt->fetchColumn();

        if ($runtimeId === false) {
            throw new RuntimeException(
                'Runtime configuration was not saved.'
            );
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    /*
    |--------------------------------------------------------------------------
    | Return successful response
    |--------------------------------------------------------------------------
    */

    respond([
        'success' => true,

        'message' =>
            'Runtime manifest generated and saved successfully.',

        'data' => [
            'runtime_configuration' => [
                'id' => (int) $runtimeId,

                'model' => [
                    'id' =>
                        (int) $record['model_id'],

                    'name' =>
                        $record['model_name']
                ],

                'version' => [
                    'id' =>
                        (int) $record['model_version_id'],

                    'number' =>
                        (int) $record['version_number']
                ],

                'status' =>
                    'generated',

                'python_version' =>
                    $pythonVersion,

                'framework' =>
                    $framework,

                'framework_version' =>
                    $frameworkVersion
            ],

            'manifest' =>
                $manifest
        ]
    ]);

} catch (Throwable $e) {

    error_log(
        'Runtime manifest API error: ' .
        $e->getMessage()
    );

    respond([
        'success' => false,
        'message' =>
            'An error occurred while generating and saving the runtime manifest.'
    ], 500);
}