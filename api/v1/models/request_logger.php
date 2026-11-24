<?php
declare(strict_types=1);

/**
 * Public API request logger.
 *
 * Never stores bearer credentials or prediction payloads.
 */
function public_api_log_request(
    array $context,
    bool $success,
    int $status,
    string $message
): void {
    if (!isset($context['pdo']) || !$context['pdo'] instanceof PDO) {
        return;
    }

    try {
        $pdo = $context['pdo'];

        $errorCode = $success
            ? null
            : 'HTTP_' . $status;

        $errorMessage = $success
            ? null
            : mb_substr($message, 0, 500);

        $stmt = $pdo->prepare("
            INSERT INTO api_request_logs (
                api_key_id,
                user_id,
                runtime_id,
                model_id,
                model_version_id,
                http_method,
                model_slug,
                http_status,
                success,
                response_time_ms,
                request_size_bytes,
                error_code,
                error_message
            ) VALUES (
                :api_key_id,
                :user_id,
                :runtime_id,
                :model_id,
                :model_version_id,
                :http_method,
                :model_slug,
                :http_status,
                :success,
                :response_time_ms,
                :request_size_bytes,
                :error_code,
                :error_message
            )
        ");

        $elapsed = (int) round(
            (microtime(true) - ($context['started_at'] ?? microtime(true)))
            * 1000
        );

        $stmt->execute([
            ':api_key_id' => $context['api_key_id'] ?? null,
            ':user_id' => $context['user_id'] ?? null,
            ':runtime_id' => $context['runtime_id'] ?? null,
            ':model_id' => $context['model_id'] ?? null,
            ':model_version_id' => $context['model_version_id'] ?? null,
            ':http_method' => $context['http_method'] ?? 'POST',
            ':model_slug' => $context['model_slug'] ?? 'unknown',
            ':http_status' => $status,
            ':success' => $success ? 1 : 0,
            ':response_time_ms' => max(0, $elapsed),
            ':request_size_bytes' => max(
                0,
                (int) ($context['request_size_bytes'] ?? 0)
            ),
            ':error_code' => $errorCode,
            ':error_message' => $errorMessage,
        ]);

    } catch (Throwable $e) {
        // Logging must never break the prediction response.
        error_log(
            'Public API request logging failed: ' . $e->getMessage()
        );
    }
}