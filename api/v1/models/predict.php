<?php
declare(strict_types=1);

/**
 * Model Deployment Platform
 * Public Prediction API
 *
 * Endpoint:
 * POST /api/v1/models/{model-slug}/predict
 *
 * Authentication:
 * Authorization: Bearer mdp_...
 *
 * Customer API requests do not require dashboard
 * sessions or CSRF tokens.
 *
 * Request logging:
 * Records request metadata in api_request_logs.
 * Never stores API credentials or prediction payloads.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

const PUBLIC_API_MAX_REQUEST_SIZE = 1048576;
const PUBLIC_API_TIMEOUT = 30;

/*
|--------------------------------------------------------------------------
| Request logging context
|--------------------------------------------------------------------------
*/

$GLOBALS['public_api_log_context'] = [
    'pdo' => null,
    'started_at' => microtime(true),
    'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
    'model_slug' => 'unknown',
    'request_size_bytes' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
    'api_key_id' => null,
    'user_id' => null,
    'runtime_id' => null,
    'model_id' => null,
    'model_version_id' => null,
];

$GLOBALS['public_api_logged'] = false;

/*
|--------------------------------------------------------------------------
| REQUEST LOGGER
|--------------------------------------------------------------------------
*/

function public_api_log_request(
    array $context,
    bool $success,
    int $status,
    string $message
): void {

    $pdo = $context['pdo'] ?? null;

    if (!($pdo instanceof PDO)) {
        return;
    }

    try {

        $elapsed = (int) round(
            (
                microtime(true)
                - (float) ($context['started_at'] ?? microtime(true))
            ) * 1000
        );

        $errorCode = $success
            ? null
            : 'HTTP_' . $status;

        $errorMessage = $success
            ? null
            : substr($message, 0, 500);

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

        error_log(
            'Public API request logging failed: '
            . $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| JSON RESPONSE HELPER
|--------------------------------------------------------------------------
*/

function public_api_respond(
    bool $success,
    array $data = [],
    string $message = '',
    int $status = 200
): never {

    if (empty($GLOBALS['public_api_logged'])) {

        $GLOBALS['public_api_logged'] = true;

        public_api_log_request(
            $GLOBALS['public_api_log_context'] ?? [],
            $success,
            $status,
            $message
        );
    }

    http_response_code($status);

    echo json_encode(
        [
            'success' => $success,
            'data' => $data,
            'message' => $message,
        ],
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| AUTHORIZATION HEADER
|--------------------------------------------------------------------------
*/

function public_api_authorization_header(): string
{
    $headers = [];

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    foreach ($headers as $name => $value) {

        if (strtolower((string) $name) === 'authorization') {
            return trim((string) $value);
        }
    }

    return trim(
        (string) (
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        )
    );
}

/*
|--------------------------------------------------------------------------
| BEARER API KEY EXTRACTION
|--------------------------------------------------------------------------
*/

function public_api_bearer_key(): string
{
    $authorization = public_api_authorization_header();

    if (!preg_match(
        '/^Bearer\s+(mdp_[A-Za-z0-9_-]+)$/i',
        $authorization,
        $matches
    )) {

        public_api_respond(
            false,
            [],
            'A valid Bearer API key is required.',
            401
        );
    }

    return $matches[1];
}

/*
|--------------------------------------------------------------------------
| API KEY AUTHENTICATION
|--------------------------------------------------------------------------
*/

function public_api_authenticate(
    PDO $pdo,
    string $plainKey,
    string $modelSlug
): array {

    $keyHash = hash('sha256', $plainKey);
    $keyPrefix = substr($plainKey, 0, 16);

    $stmt = $pdo->prepare("
        SELECT
            ak.id AS api_key_id,
            ak.runtime_id,
            ak.user_id,
            ak.name AS api_key_name,
            ak.status AS api_key_status,
            ak.expires_at,

            r.runtime_key,
            r.status AS runtime_status,
            r.workspace_status,
            r.workspace_path,

            mv.id AS model_version_id,
            mv.version AS model_version,

            m.id AS model_id,
            m.name AS model_name,
            m.slug AS model_slug

        FROM api_keys ak

        INNER JOIN users u
            ON u.id = ak.user_id

        INNER JOIN model_runtimes r
            ON r.id = ak.runtime_id

        INNER JOIN model_versions mv
            ON mv.id = r.model_version_id

        INNER JOIN models m
            ON m.id = mv.model_id

        WHERE
            ak.key_hash = :key_hash
            AND ak.key_prefix = :key_prefix
            AND ak.status = 'active'
            AND ak.user_id = m.user_id
            AND u.status = 'active'
            AND m.slug = :model_slug

        LIMIT 1
    ");

    $stmt->execute([
        ':key_hash' => $keyHash,
        ':key_prefix' => $keyPrefix,
        ':model_slug' => $modelSlug,
    ]);

    $runtime = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$runtime) {

        public_api_respond(
            false,
            [],
            'Invalid API key or model access denied.',
            401
        );
    }

    /*
     * Check API key expiration.
     */

    if (
        !empty($runtime['expires_at'])
        && strtotime($runtime['expires_at']) <= time()
    ) {

        public_api_respond(
            false,
            [],
            'This API key has expired.',
            401
        );
    }

    /*
     * Check workspace readiness.
     */

    if (
        strtolower((string) $runtime['workspace_status'])
        !== 'ready'
    ) {

        public_api_respond(
            false,
            [],
            'The model runtime workspace is not ready.',
            409
        );
    }

    /*
     * Update API key last-used timestamp.
     */

    $update = $pdo->prepare("
        UPDATE api_keys
        SET last_used_at = CURRENT_TIMESTAMP
        WHERE id = :id
          AND status = 'active'
    ");

    $update->execute([
        ':id' => (int) $runtime['api_key_id'],
    ]);

    return $runtime;
}

/*
|--------------------------------------------------------------------------
| PRIVATE INFERENCE SERVICE CONFIGURATION
|--------------------------------------------------------------------------
*/

function public_api_service_config(array $runtime): array
{
    $workspacePath = trim(
        (string) ($runtime['workspace_path'] ?? '')
    );

    if ($workspacePath === '') {

        public_api_respond(
            false,
            [],
            'Model runtime workspace path is missing.',
            503
        );
    }

    $apiRoot = realpath(dirname(__DIR__, 2));

    if ($apiRoot === false) {

        public_api_respond(
            false,
            [],
            'Unable to resolve the API directory.',
            503
        );
    }

    $runtimeRoot = realpath(
        $apiRoot
        . DIRECTORY_SEPARATOR
        . 'storage'
        . DIRECTORY_SEPARATOR
        . 'runtimes'
    );

    if ($runtimeRoot === false || !is_dir($runtimeRoot)) {

        public_api_respond(
            false,
            [],
            'Private runtime storage is unavailable.',
            503
        );
    }

    $isAbsolute = preg_match(
        '/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/',
        $workspacePath
    ) === 1;

    if ($isAbsolute) {

        $candidatePath = $workspacePath;

    } else {

        $candidatePath = $apiRoot
            . DIRECTORY_SEPARATOR
            . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $workspacePath
            );
    }

    $resolvedWorkspace = realpath($candidatePath);

    if (
        $resolvedWorkspace === false
        || !is_dir($resolvedWorkspace)
    ) {

        public_api_respond(
            false,
            [],
            'Model runtime workspace is unavailable.',
            503
        );
    }

    /*
     * Ensure the workspace is inside private runtime storage.
     */

    $normalizedWorkspace = rtrim(
        $resolvedWorkspace,
        DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR;

    $normalizedRuntimeRoot = rtrim(
        $runtimeRoot,
        DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR;

    $pathComparison = DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp(
            $normalizedWorkspace,
            $normalizedRuntimeRoot,
            strlen($normalizedRuntimeRoot)
        )
        : strncmp(
            $normalizedWorkspace,
            $normalizedRuntimeRoot,
            strlen($normalizedRuntimeRoot)
        );

    if ($pathComparison !== 0) {

        public_api_respond(
            false,
            [],
            'Model runtime workspace is outside private runtime storage.',
            403
        );
    }

    /*
     * Build inference service configuration path.
     */

    $configPath = $resolvedWorkspace
        . DIRECTORY_SEPARATOR
        . 'runtime'
        . DIRECTORY_SEPARATOR
        . 'inference_service.json';

    if (!is_file($configPath) || !is_readable($configPath)) {

        public_api_respond(
            false,
            [],
            'Inference service is not running.',
            503
        );
    }

    $contents = file_get_contents($configPath);

    if ($contents === false) {

        public_api_respond(
            false,
            [],
            'Could not read inference service configuration.',
            503
        );
    }

    $service = json_decode($contents, true);

    if (!is_array($service)) {

        public_api_respond(
            false,
            [],
            'Inference service configuration is invalid.',
            503
        );
    }

    $port = filter_var(
        $service['port'] ?? null,
        FILTER_VALIDATE_INT
    );

    $token = (string) ($service['token'] ?? '');

    if (
        !$port
        || $port < 1024
        || $port > 65535
        || strlen($token) < 32
    ) {

        public_api_respond(
            false,
            [],
            'Inference service configuration is invalid.',
            503
        );
    }

    return [
        'port' => $port,
        'token' => $token,
    ];
}

/*
|--------------------------------------------------------------------------
| INTERNAL PYTHON INFERENCE REQUEST
|--------------------------------------------------------------------------
*/

function public_api_inference_request(
    array $service,
    string $method,
    ?string $payload = null
): array {

    if (!function_exists('curl_init')) {

        public_api_respond(
            false,
            [],
            'PHP cURL extension is required.',
            500
        );
    }

    $url = 'http://127.0.0.1:'
        . (int) $service['port']
        . (
            $method === 'GET'
                ? '/health'
                : '/predict'
        );

    $headers = [
        'Accept: application/json',
        'X-Runtime-Token: ' . $service['token'],
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => PUBLIC_API_TIMEOUT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ];

    if ($method === 'POST') {

        $headers[] = 'Content-Type: application/json';

        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $payload;
    }

    $ch = curl_init($url);

    if ($ch === false) {

        public_api_respond(
            false,
            [],
            'Could not initialize inference connection.',
            503
        );
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    if ($response === false) {

        error_log(
            'Public inference request failed: '
            . curl_error($ch)
        );

        curl_close($ch);

        public_api_respond(
            false,
            [],
            'Inference service is temporarily unavailable.',
            503
        );
    }

    $httpCode = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {

        public_api_respond(
            false,
            [],
            'Inference service returned an invalid response.',
            502
        );
    }

    return [
        'http_code' => $httpCode,
        'response' => $decoded,
    ];
}

/*
|--------------------------------------------------------------------------
| MAIN REQUEST HANDLER
|--------------------------------------------------------------------------
*/

/*
 * CORS preflight.
 */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(204);
    exit;
}

/*
 * Only POST is supported.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    public_api_respond(
        false,
        [],
        'POST method required.',
        405
    );
}

try {

    /*
    |--------------------------------------------------------------------------
    | 1. VALIDATE MODEL SLUG
    |--------------------------------------------------------------------------
    */

    $modelSlug = trim(
        (string) ($_GET['model_slug'] ?? '')
    );

    if (
        $modelSlug === ''
        || !preg_match('/^[a-zA-Z0-9_-]{1,150}$/', $modelSlug)
    ) {

        public_api_respond(
            false,
            [],
            'Invalid model identifier.',
            400
        );
    }

    $GLOBALS['public_api_log_context']['model_slug'] = $modelSlug;

    /*
    |--------------------------------------------------------------------------
    | 2. CHECK REQUEST SIZE
    |--------------------------------------------------------------------------
    */

    $contentLength = (int) (
        $_SERVER['CONTENT_LENGTH'] ?? 0
    );

    if ($contentLength > PUBLIC_API_MAX_REQUEST_SIZE) {

        public_api_respond(
            false,
            [],
            'Request exceeds the maximum allowed size.',
            413
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. LOAD DATABASE CONNECTION
    |--------------------------------------------------------------------------
    */

    require_once dirname(__DIR__, 2)
        . '/config/database.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {

        throw new RuntimeException(
            'Database connection is unavailable.'
        );
    }

    $GLOBALS['public_api_log_context']['pdo'] = $pdo;

    /*
    |--------------------------------------------------------------------------
    | 4. AUTHENTICATE API KEY
    |--------------------------------------------------------------------------
    */

    $plainKey = public_api_bearer_key();

    $runtime = public_api_authenticate(
        $pdo,
        $plainKey,
        $modelSlug
    );

    /*
     * Attach authenticated request information to logger.
     */

    $GLOBALS['public_api_log_context'] = array_merge(
        $GLOBALS['public_api_log_context'],
        [
            'api_key_id' => (int) $runtime['api_key_id'],
            'user_id' => (int) $runtime['user_id'],
            'runtime_id' => (int) $runtime['runtime_id'],
            'model_id' => (int) $runtime['model_id'],
            'model_version_id' => (int) $runtime['model_version_id'],
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | 5. READ JSON PREDICTION INPUT
    |--------------------------------------------------------------------------
    */

    $rawInput = file_get_contents('php://input');

    if (
        $rawInput === false
        || strlen($rawInput) > PUBLIC_API_MAX_REQUEST_SIZE
    ) {

        public_api_respond(
            false,
            [],
            'Invalid or oversized request body.',
            413
        );
    }

    $GLOBALS['public_api_log_context']['request_size_bytes'] = max(
        (int) $GLOBALS['public_api_log_context']['request_size_bytes'],
        strlen($rawInput)
    );

    $decodedObject = json_decode($rawInput);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_object($decodedObject)
    ) {

        public_api_respond(
            false,
            [],
            'Request body must be a valid JSON object.',
            400
        );
    }

    $predictionInput = json_decode($rawInput, true);

    if (!is_array($predictionInput)) {

        public_api_respond(
            false,
            [],
            'Invalid prediction input.',
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 6. RESOLVE PRIVATE INFERENCE SERVICE
    |--------------------------------------------------------------------------
    */

    $service = public_api_service_config($runtime);

    /*
    |--------------------------------------------------------------------------
    | 7. VERIFY ACTUAL INFERENCE HEALTH
    |--------------------------------------------------------------------------
    */

    $healthResult = public_api_inference_request(
        $service,
        'GET'
    );

    $health = $healthResult['response'];
    $healthCode = $healthResult['http_code'];

    if (
        $healthCode !== 200
        || ($health['success'] ?? false) !== true
        || strtolower((string) ($health['status'] ?? ''))
            !== 'healthy'
        || ($health['model_loaded'] ?? false) !== true
    ) {

        public_api_respond(
            false,
            [],
            'The model inference service is not healthy.',
            503
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 8. EXECUTE REAL MODEL PREDICTION
    |--------------------------------------------------------------------------
    */

    $payload = json_encode(
        $predictionInput,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $predictionResult = public_api_inference_request(
        $service,
        'POST',
        $payload
    );

    $inferenceResponse = $predictionResult['response'];
    $inferenceCode = $predictionResult['http_code'];

    if (
        $inferenceCode < 200
        || $inferenceCode >= 300
        || ($inferenceResponse['success'] ?? false) !== true
    ) {

        $errorCode = (
            $inferenceCode >= 400
            && $inferenceCode <= 599
        ) ? $inferenceCode : 502;

        public_api_respond(
            false,
            $inferenceResponse['data'] ?? [],
            $inferenceResponse['message']
                ?? 'Model prediction failed.',
            $errorCode
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 9. RETURN PREDICTION RESULT
    |--------------------------------------------------------------------------
    */

    public_api_respond(
        true,
        [
            'model' => [
                'name' => $runtime['model_name'],
                'slug' => $runtime['model_slug'],
                'version' => (int) $runtime['model_version'],
            ],
            'prediction' => $inferenceResponse['data'] ?? [],
        ],
        'Prediction completed successfully.',
        200
    );

} catch (JsonException $e) {

    public_api_respond(
        false,
        [],
        'Invalid JSON request.',
        400
    );

} catch (Throwable $e) {

    error_log(
        'Public prediction API error: '
        . $e->getMessage()
    );

    public_api_respond(
        false,
        [],
        'An internal error occurred while processing the prediction.',
        500
    );
}
