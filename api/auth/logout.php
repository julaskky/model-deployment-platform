
<?php

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');
requireCsrf();
requireAuth();

$_SESSION = [];

$params = session_get_cookie_params();

setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $params['path'],
    'domain' => $params['domain'],
    'secure' => $params['secure'],
    'httponly' => $params['httponly'],
    'samesite' => 'Lax'
]);

session_destroy();

respond([
    'success' => true,
    'message' => 'Logged out successfully.'
]);
