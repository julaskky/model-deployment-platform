<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Authenticate the user and verify that the account is active.
 */
function billing_require_user(): int
{
    $userId = requireAuth();

    $stmt = $GLOBALS['pdo']->prepare("
        SELECT id
        FROM users
        WHERE id = :user_id
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $userId
    ]);

    if (!$stmt->fetchColumn()) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        respond([
            'success' => false,
            'message' => 'Your account is unavailable. Please sign in again.'
        ], 401);
    }

    return (int) $userId;
}

/**
 * Return validated pagination parameters.
 */
function billing_pagination(): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));

    $limit = (int) ($_GET['limit'] ?? 20);
    $limit = max(1, min(100, $limit));

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => ($page - 1) * $limit
    ];
}

/**
 * Decode plan feature JSON safely.
 */
function billing_decode_features(?string $features): array
{
    if (!$features) {
        return [];
    }

    $decoded = json_decode($features, true);

    return is_array($decoded) ? $decoded : [];
}
