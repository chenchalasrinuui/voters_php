<?php
require_once __DIR__ . '/../../cors.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestPath) && preg_match('#/api/admin(?:/([^/]+)(?:/([^/]+))?)?/?$#i', $requestPath, $matches)) {
    $resource = strtolower($matches[1] ?? '');
    $adminActions = [
        'users' => 'users',
        'stats' => 'stats',
        'locations' => 'locations',
        'toggle-user' => 'toggle-user',
        'delete-user' => 'delete-user',
    ];

    if (isset($adminActions[$resource])) {
        $_GET['action'] = $resource === 'users'
            && isset($matches[2])
            && in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH'], true)
            ? 'update-user'
            : $adminActions[$resource];
        if ($resource === 'users' && isset($matches[2]) && ctype_digit($matches[2])) {
            $_GET['id'] = $matches[2];
        }
    } else {
        $_GET['action'] = 'not-found';
    }
}

require_once __DIR__ . '/../../routes/admin.php';
