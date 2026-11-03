<?php
require_once __DIR__ . '/../cors.php';
header("Content-Type: application/json; charset=UTF-8");
echo json_encode([
    'status' => 'ok',
    'backend' => 'php',
    'cors' => 'enabled',
    'php_version' => PHP_VERSION,
    'timestamp' => date('c')
]);
