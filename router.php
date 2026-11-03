<?php
// backend/router.php - Clean Master Router delegating to split route files

require_once __DIR__ . '/cors.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Normalize path: remove any subfolder prefix dynamically (e.g. /voters_php, /voters)
$scriptDir = trim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($scriptDir && $scriptDir !== '.') {
    $uri = preg_replace('#^/' . preg_quote($scriptDir, '#') . '#i', '', $uri);
}
$uri = preg_replace('#^/voters[^/]*#i', '', $uri);
$uri = preg_replace('#^/router\.php#i', '', $uri);

// 1. Static file serving for uploads
if (strpos($uri, '/uploads/') === 0) {
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $filePath = $uploadsRoot
        ? realpath($uploadsRoot . DIRECTORY_SEPARATOR . substr($uri, strlen('/uploads/')))
        : false;
    $uploadsPrefix = $uploadsRoot ? $uploadsRoot . DIRECTORY_SEPARATOR : '';
    if ($filePath && strpos($filePath, $uploadsPrefix) === 0 && is_file($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'json' => 'application/json',
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
        exit();
    } else {
        http_response_code(404);
        echo "File not found";
        exit();
    }
}

// 2. Health check
if ($uri === '/api/health' || $uri === '/api/health/') {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        'status' => 'ok',
        'backend' => 'php',
        'cors' => 'enabled',
        'php_version' => PHP_VERSION,
        'timestamp' => date('c')
    ]);
    exit();
}

// 3. Delegate to modular route files

// Auth routes (/api/auth, /api/auth/login, /api/auth/*, or /backend/auth.php)
if (strpos($uri, '/api/auth') === 0 || $uri === '/backend/auth.php') {
    require __DIR__ . '/routes/auth.php';
    exit();
}

// Admin routes (/api/admin/* or /backend/admin.php)
if (strpos($uri, '/api/admin') === 0 || $uri === '/backend/admin.php') {
    if (strpos($uri, '/api/admin/stats') === 0) {
        $_GET['action'] = 'stats';
    } elseif (strpos($uri, '/api/admin/toggle-user') === 0) {
        $_GET['action'] = 'toggle-user';
    } elseif (strpos($uri, '/api/admin/delete-user') === 0) {
        $_GET['action'] = 'delete-user';
    } elseif (preg_match('#^/api/admin/users/(\d+)#', $uri, $matches)) {
        $_GET['id'] = $matches[1];
    } else {
        $_GET['action'] = 'users';
    }
    require __DIR__ . '/routes/admin.php';
    exit();
}

// Multi-village routes (/api/villages/*)
if (strpos($uri, '/api/villages') === 0) {
    if (preg_match('#^/api/villages/delete/(\d+)/?$#', $uri, $matches)) {
        $_GET['action'] = 'delete';
        $_GET['delete_id'] = $matches[1];
    } elseif (preg_match('#^/api/villages/delete/?$#', $uri)) {
        $_GET['action'] = 'delete';
    } elseif (preg_match('#^/api/villages/(\d+)/?$#', $uri, $matches)) {
        $_GET['delete_id'] = $matches[1];
    }
    require __DIR__ . '/routes/villages.php';
    exit();
}

// Voters routes (/api/voters/*)
if (strpos($uri, '/api/voters') === 0) {
    if (preg_match('#^/api/voters/(vote|flag|reset-votes|edit)/?$#', $uri, $matches)) {
        $_GET['action'] = $matches[1];
    }
    require __DIR__ . '/routes/voters.php';
    exit();
}

// PDF Upload route (/api/upload/*)
if (strpos($uri, '/api/upload') === 0) {
    require __DIR__ . '/routes/upload.php';
    exit();
}

// Direct file access support for sample PHP files if present
if (($uri === '/backend/clients.php' || $uri === '/api/clients') && file_exists(__DIR__ . '/clients.php')) {
    require __DIR__ . '/clients.php';
    exit();
}

if (($uri === '/backend/employees.php' || $uri === '/api/employees') && file_exists(__DIR__ . '/employees.php')) {
    require __DIR__ . '/employees.php';
    exit();
}

// Root fallback / status
if ($uri === '' || $uri === '/') {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        'message' => 'Voters PHP Backend Active',
        'auth_endpoint' => '/api/auth/login',
        'status' => 'ok'
    ]);
    exit();
}

// Not Found
http_response_code(404);
header("Content-Type: application/json; charset=UTF-8");
echo json_encode(['error' => 'API endpoint not found: ' . $uri]);
exit();
