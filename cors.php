<?php
// backend/cors.php - Centralized, Bulletproof CORS Headers for React App on http://localhost:3000 & All Origins

if (!ob_get_level()) {
    ob_start();
}

// Extract requesting origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// When Origin is present, reflect it dynamically with credentials support
if (!empty($origin)) {
    header("Access-Control-Allow-Origin: $origin", true);
    header("Access-Control-Allow-Credentials: true", true);
} else {
    header("Access-Control-Allow-Origin: *", true);
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS", true);
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, Cache-Control, Pragma, X-User-Id, X-User-Role, X-User-Numeric-Id", true);
header("Access-Control-Expose-Headers: Content-Length, Content-Type, Authorization", true);
header("Access-Control-Max-Age: 86400", true);

// Handle preflight OPTIONS request immediately
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    http_response_code(200);
    header("Content-Length: 0");
    exit(0);
}
