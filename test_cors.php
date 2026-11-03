<?php
require_once __DIR__ . '/cors.php';

header("Content-Type: application/json; charset=UTF-8");

echo json_encode([
    'status' => 'success',
    'message' => 'CORS headers are active and working!',
    'client_origin' => $_SERVER['HTTP_ORIGIN'] ?? 'None provided (direct browser visit)',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'headers_received' => function_exists('getallheaders') ? getallheaders() : [],
    'php_version' => PHP_VERSION,
    'time' => date('c')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
