<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$allowedOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (
    !in_array($origin, $allowedOrigins, true) &&
    $origin !== ''
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Origin not permitted.'
    ]);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

require_once __DIR__ . '/config/database.php';

function respond(
    array $data,
    int $status = 200
): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        respond([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }
}

function getJsonInput(): array
{
    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        respond([
            'success' => false,
            'message' => 'Invalid JSON request.'
        ], 400);
    }

    return $input;
}

function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if (
        !$stored ||
        !$provided ||
        !hash_equals($stored, $provided)
    ) {
        respond([
            'success' => false,
            'message' => 'Invalid or missing CSRF token.'
        ], 403);
    }
}

function requireAuth(): int
{
    global $pdo;

    $userId = $_SESSION['user_id'] ?? null;

    if (!$userId) {
        respond([
            'success' => false,
            'message' => 'Authentication required.'
        ], 401);
    }

    $stmt = $pdo->prepare(
        "SELECT status
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->execute([(int) $userId]);

    $status = $stmt->fetchColumn();

    if ($status !== 'active') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax'
                ]
            );
        }

        session_destroy();

        respond([
            'success' => false,
            'message' => 'Your account is inactive. Please contact support.'
        ], 401);
    }

    return (int) $userId;
}
