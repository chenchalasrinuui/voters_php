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
        'voters_out_of_station' => '/api/voters/out-of-station/',
        'voters_vote' => '/api/voters/vote/',
        'voters_flag' => '/api/voters/flag/',
        'voters_edit' => '/api/voters/edit/',
        'villages' => '/api/villages',
        'admin_users' => '/api/admin/users/',
        'admin_locations' => '/api/admin/locations',
        'admin_stats' => '/api/admin/stats',
        'admin_toggle_user' => '/api/admin/toggle-user',
        'admin_delete_user' => '/api/admin/delete-user',
        'health' => '/api/health'
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
