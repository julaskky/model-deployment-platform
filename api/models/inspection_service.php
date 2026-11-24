<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Model Package Inspection Service
|--------------------------------------------------------------------------
|
| Responsibilities:
| - Execute the Python package inspector.
| - Never execute uploaded model code.
| - Validate the inspector's JSON response.
| - Persist inspection results.
| - Update model and version statuses safely.
| - Prevent older versions from overwriting the latest model status.
|
*/

const INSPECTION_TIMEOUT_SECONDS = 120;

const INSPECTION_PYTHON_SCRIPT =
    __DIR__ . '/../../python_engine/inspect_package.py';

const INSPECTION_STORAGE_DIRECTORY =
    __DIR__ . '/../storage/models';

/**
 * Check whether a version is the latest version of its model.
 *
 * The parent model row must already be locked FOR UPDATE
 * inside the current transaction.
 */
function isLatestModelVersion(
    PDO $pdo,
    int $modelId,
    int $versionId
): bool {
    $stmt = $pdo->prepare(
        'SELECT id
         FROM model_versions
         WHERE model_id = ?
         ORDER BY version DESC
         LIMIT 1'
    );

    $stmt->execute([$modelId]);

    $latestVersionId = $stmt->fetchColumn();

    return $latestVersionId !== false
        && (int) $latestVersionId === $versionId;
}

/**
 * Lock and verify the parent model.
 *
 * This serializes status updates with new-version uploads
 * that also lock the parent model row.
 */
function lockInspectionModel(
    PDO $pdo,
    int $modelId
): void {
    $stmt = $pdo->prepare(
        'SELECT id
         FROM models
         WHERE id = ?
         FOR UPDATE'
    );

    $stmt->execute([$modelId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(
            'The model could not be found.'
        );
    }
}

/**
 * Execute the Python inspection script.
 *
 * Uses separate temporary files for stdout and stderr to avoid
 * pipe-buffer deadlocks on Windows.
 */
function runPythonPackageInspector(string $zipPath): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException(
            'PHP process execution is unavailable.'
        );
    }

    $pythonScript = INSPECTION_PYTHON_SCRIPT;

    if (!is_file($pythonScript)) {
        throw new RuntimeException(
            'The Python inspection script could not be found.'
        );
    }

    if (!is_file($zipPath)) {
        throw new RuntimeException(
            'The stored model package could not be found.'
        );
    }

    $pythonExecutable = getenv('MODEL_PYTHON_BINARY');

    if (
        $pythonExecutable === false ||
        trim($pythonExecutable) === ''
    ) {
        $pythonExecutable = 'python';
    }

    $temporaryToken = bin2hex(random_bytes(12));

    $stdoutPath = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'model_inspection_'
        . $temporaryToken
        . '.out';

    $stderrPath = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'model_inspection_'
        . $temporaryToken
        . '.err';

    $command = [
        $pythonExecutable,
        $pythonScript,
        $zipPath
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w']
    ];

    $process = null;

    try {
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
            null
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Unable to start the Python inspection process.'
            );
        }

        fclose($pipes[0]);

        $startedAt = time();
        $timedOut = false;
        $processStatus = null;

        while (true) {
            $processStatus = proc_get_status($process);

            if (!$processStatus['running']) {
                break;
            }

            if (
                time() - $startedAt >
                INSPECTION_TIMEOUT_SECONDS
            ) {
                $timedOut = true;

                proc_terminate($process);

                break;
            }

            usleep(200000);
        }

        if ($timedOut) {
            throw new RuntimeException(
                'Python package inspection exceeded the allowed time.'
            );
        }

        $exitCode = (int) ($processStatus['exitcode'] ?? -1);

        proc_close($process);
        $process = null;

        $stdout = is_file($stdoutPath)
            ? file_get_contents($stdoutPath)
            : false;

        $stderr = is_file($stderrPath)
            ? file_get_contents($stderrPath)
            : false;

        if ($stdout === false || trim($stdout) === '') {
            throw new RuntimeException(
                'The Python inspector returned an empty response.'
            );
        }

        $result = json_decode(
            $stdout,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'The Python inspector returned an invalid response.'
            );
        }

        if (
            !array_key_exists('success', $result) ||
            !array_key_exists('status', $result) ||
            !array_key_exists('errors', $result) ||
            !array_key_exists('warnings', $result)
        ) {
            throw new RuntimeException(
                'The Python inspector response is missing required fields.'
            );
        }

        $allowedStatuses = [
            'READY',
            'NEEDS_CONFIGURATION',
            'UNSUPPORTED'
        ];

        if (!in_array($result['status'], $allowedStatuses, true)) {
            throw new RuntimeException(
                'The Python inspector returned an unknown status.'
            );
        }

        if (
            !is_array($result['errors']) ||
            !is_array($result['warnings'])
        ) {
            throw new RuntimeException(
                'The Python inspector returned invalid diagnostic data.'
            );
        }

        /*
         * Exit code 1 is acceptable when the inspector returns
         * a valid UNSUPPORTED inspection result.
         */
        if (
            $exitCode !== 0 &&
            !(
                $exitCode === 1 &&
                $result['success'] === false &&
                $result['status'] === 'UNSUPPORTED'
            )
        ) {
            $diagnostic = is_string($stderr)
                ? trim($stderr)
                : '';

            throw new RuntimeException(
                $diagnostic !== ''
                    ? 'Python inspection failed: ' . $diagnostic
                    : 'The Python inspection process failed.'
            );
        }

        return $result;

    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }

        if (is_file($stdoutPath)) {
            @unlink($stdoutPath);
        }

        if (is_file($stderrPath)) {
            @unlink($stderrPath);
        }
    }
}

/**
 * Persist the inspection result and update statuses.
 *
 * The version status is always updated.
 * The parent model status is updated only if this version
 * remains the latest version.
 */
function persistModelInspection(
    PDO $pdo,
    int $modelId,
    int $versionId,
    array $result
): void {
    $statusMap = [
        'READY' => 'ready',
        'NEEDS_CONFIGURATION' => 'needs_configuration',
        'UNSUPPORTED' => 'unsupported'
    ];

    if (!isset($statusMap[$result['status'] ?? ''])) {
        throw new RuntimeException(
            'Invalid inspection status.'
        );
    }

    $inspectionStatus = $statusMap[$result['status']];

    $metadata = $result['metadata'] ?? null;
    $files = $result['files'] ?? [];
    $requirements = $result['requirements'] ?? [];

    $jsonOptions = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES;

    $metadataJson = $metadata === null
        ? null
        : json_encode($metadata, $jsonOptions);

    $filesJson = json_encode($files, $jsonOptions);

    $requirementsJson = json_encode(
        $requirements,
        $jsonOptions
    );

    $errorsJson = json_encode(
        $result['errors'],
        $jsonOptions
    );

    $warningsJson = json_encode(
        $result['warnings'],
        $jsonOptions
    );

    $pdo->beginTransaction();

    try {
        /*
         * Lock parent model first.
         * New-version uploads must use the same lock.
         */
        lockInspectionModel($pdo, $modelId);

        /*
         * Confirm that the version belongs to the expected model.
         */
        $checkStmt = $pdo->prepare(
            'SELECT id
             FROM model_versions
             WHERE id = ?
               AND model_id = ?
             LIMIT 1'
        );

        $checkStmt->execute([
            $versionId,
            $modelId
        ]);

        if (!$checkStmt->fetchColumn()) {
            throw new RuntimeException(
                'The model version does not belong to this model.'
            );
        }

        /*
         * Save or update the inspection result.
         */
        $inspectionStmt = $pdo->prepare(
            'INSERT INTO model_inspections
                (
                    model_version_id,
                    status,
                    framework,
                    summary,
                    metadata,
                    files,
                    requirements,
                    errors,
                    warnings,
                    inspected_at
                )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                framework = VALUES(framework),
                summary = VALUES(summary),
                metadata = VALUES(metadata),
                files = VALUES(files),
                requirements = VALUES(requirements),
                errors = VALUES(errors),
                warnings = VALUES(warnings),
                inspected_at = CURRENT_TIMESTAMP'
        );

        $inspectionStmt->execute([
            $versionId,
            $inspectionStatus,
            $result['framework'] ?? null,
            $result['summary'] ?? null,
            $metadataJson,
            $filesJson,
            $requirementsJson,
            $errorsJson,
            $warningsJson
        ]);

        /*
         * Update the individual version status.
         */
        $versionStmt = $pdo->prepare(
            'UPDATE model_versions
             SET status = ?
             WHERE id = ?
               AND model_id = ?'
        );

        $versionStmt->execute([
            $inspectionStatus,
            $versionId,
            $modelId
        ]);

        /*
         * Update the overall model status only if this
         * inspected version is still the latest version.
         */
        if (isLatestModelVersion($pdo, $modelId, $versionId)) {
            $modelStmt = $pdo->prepare(
                'UPDATE models
                 SET status = ?
                 WHERE id = ?'
            );

            $modelStmt->execute([
                $inspectionStatus,
                $modelId
            ]);
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Record an operational inspection failure.
 *
 * The uploaded package remains available for retry.
 * Older versions cannot overwrite the latest model status.
 */
function recordModelInspectionFailure(
    PDO $pdo,
    int $modelId,
    int $versionId,
    string $message
): void {
    $pdo->beginTransaction();

    try {
        /*
         * Lock parent model before changing statuses.
         */
        lockInspectionModel($pdo, $modelId);

        /*
         * Verify version ownership.
         */
        $checkStmt = $pdo->prepare(
            'SELECT id
             FROM model_versions
             WHERE id = ?
               AND model_id = ?
             LIMIT 1'
        );

        $checkStmt->execute([
            $versionId,
            $modelId
        ]);

        if (!$checkStmt->fetchColumn()) {
            throw new RuntimeException(
                'The model version does not belong to this model.'
            );
        }

        /*
         * Save the operational failure.
         */
        $stmt = $pdo->prepare(
            'INSERT INTO model_inspections
                (
                    model_version_id,
                    status,
                    summary,
                    errors,
                    warnings
                )
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                summary = VALUES(summary),
                errors = VALUES(errors),
                warnings = VALUES(warnings),
                inspected_at = NULL'
        );

        $stmt->execute([
            $versionId,
            'failed',
            'Package inspection could not be completed.',
            json_encode(
                [$message],
                JSON_UNESCAPED_UNICODE
            ),
            '[]'
        ]);

        /*
         * Restore the individual version to uploaded.
         */
        $versionStmt = $pdo->prepare(
            'UPDATE model_versions
             SET status = ?
             WHERE id = ?
               AND model_id = ?'
        );

        $versionStmt->execute([
            'uploaded',
            $versionId,
            $modelId
        ]);

        /*
         * Restore parent model only when this is still
         * the latest version.
         */
        if (isLatestModelVersion($pdo, $modelId, $versionId)) {
            $modelStmt = $pdo->prepare(
                'UPDATE models
                 SET status = ?
                 WHERE id = ?'
            );

            $modelStmt->execute([
                'uploaded',
                $modelId
            ]);
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Main service entry point.
 */
function inspectStoredModelPackage(
    PDO $pdo,
    int $modelId,
    int $versionId,
    string $storageKey
): array {
    /*
     * Validate storage key before constructing a path.
     */
    if (
        !preg_match(
            '/^[a-f0-9]{48}\.zip$/',
            $storageKey
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid model storage key.'
        );
    }

    $storageDirectory = realpath(
        INSPECTION_STORAGE_DIRECTORY
    );

    $zipPath = INSPECTION_STORAGE_DIRECTORY
        . DIRECTORY_SEPARATOR
        . $storageKey;

    $resolvedZipPath = realpath($zipPath);

    if (
        $storageDirectory === false ||
        $resolvedZipPath === false ||
        !is_file($resolvedZipPath) ||
        dirname($resolvedZipPath) !== $storageDirectory
    ) {
        throw new RuntimeException(
            'The private model package could not be located.'
        );
    }

    /*
     * Mark version as analyzing.
     * Only update parent model if this is the latest version.
     */
    $pdo->beginTransaction();

    try {
        lockInspectionModel($pdo, $modelId);

        $versionStmt = $pdo->prepare(
            'UPDATE model_versions
             SET status = ?
             WHERE id = ?
               AND model_id = ?'
        );

        $versionStmt->execute([
            'analyzing',
            $versionId,
            $modelId
        ]);

        if ($versionStmt->rowCount() === 0) {
            $checkStmt = $pdo->prepare(
                'SELECT id
                 FROM model_versions
                 WHERE id = ?
                   AND model_id = ?'
            );

            $checkStmt->execute([
                $versionId,
                $modelId
            ]);

            if (!$checkStmt->fetchColumn()) {
                throw new RuntimeException(
                    'Model version was not found.'
                );
            }
        }

        if (isLatestModelVersion($pdo, $modelId, $versionId)) {
            $modelStmt = $pdo->prepare(
                'UPDATE models
                 SET status = ?
                 WHERE id = ?'
            );

            $modelStmt->execute([
                'analyzing',
                $modelId
            ]);
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    try {
        $result = runPythonPackageInspector(
            $resolvedZipPath
        );

        persistModelInspection(
            $pdo,
            $modelId,
            $versionId,
            $result
        );

        return [
            'success' => true,
            'inspection' => $result
        ];

    } catch (Throwable $e) {

        error_log(
            'Model inspection failed: ' .
            $e->getMessage()
        );

        recordModelInspectionFailure(
            $pdo,
            $modelId,
            $versionId,
            $e->getMessage()
        );

        return [
            'success' => false,
            'message' => 'The package was uploaded, but inspection could not be completed.',
            'retryable' => true
        ];
    }
}
