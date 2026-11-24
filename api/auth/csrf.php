<?php

require_once __DIR__ . '/../bootstrap.php';

requireMethod('GET');

respond([
    'success' => true,
    'csrf_token' => generateCsrfToken()
]);
