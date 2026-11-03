<?php
// backend/cors.php - Centralized Bulletproof CORS for React (http://localhost:3000 & all origins)

if (!ob_get_level()) {
    ob_start();
}

// 1. Determine Origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (empty($origin) && !empty($_SERVER['HTTP_REFERER'])) {
    if (preg_match('#^(https?://[^/]+)#i', $_SERVER['HTTP_REFERER'], $m)) {
        $origin = $m[1];
    }
}
if (empty($origin) && function_exists('getallheaders')) {
    $h = getallheaders();
    $origin = $h['Origin'] ?? $h['origin'] ?? '';
}
if (empty($origin)) {
    $origin = 'http://localhost:3000';
}

// 2. Set CORS Headers
header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");

$reqHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
if (!empty($reqHeaders)) {
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-User-Id, X-User-Role, X-User-Numeric-Id, $reqHeaders, *");
} else {
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-User-Id, X-User-Role, X-User-Numeric-Id, *");
}

header("Access-Control-Expose-Headers: Content-Length, Content-Type, Authorization, *");
header("Access-Control-Max-Age: 86400");

// 3. Immediately respond to preflight OPTIONS requests with 200 OK
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    http_response_code(200);
    header("Content-Length: 0");
    exit(0);
}
