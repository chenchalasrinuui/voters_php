<?php
require_once __DIR__ . '/../cors.php';
header("Content-Type: application/json; charset=UTF-8");
echo json_encode([
    'status' => 'ok',
    'backend' => 'php',
    'cors' => 'enabled',
    'endpoints' => [
        'auth_login' => '/api/auth/login',
        'auth' => '/api/auth',
        'voters' => '/api/voters',
        'villages' => '/api/villages',
        'admin_stats' => '/api/admin/stats',
        'health' => '/api/health'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
