<?php
// backend/routes/auth.php - Database-Driven Authentication (User & Admin Login)

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';

header("Content-Type: application/json; charset=UTF-8");

// Diagnostic GET handler
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode([
        'status' => 'active',
        'endpoint' => '/api/auth/login',
        'method' => 'POST',
        'message' => 'Auth login route is active and connecting directly to server database.',
        'cors' => 'enabled'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

if (empty($data)) {
    $data = $_POST;
}

// Extract login identifier
$username = trim(
    $data['username'] ?? 
    $data['userId'] ?? 
    $data['user_id'] ?? 
    $data['userid'] ?? 
    $data['user'] ?? 
    $data['id'] ?? 
    $data['identifier'] ?? 
    $data['login'] ?? 
    $data['uname'] ?? 
    $data['email'] ?? 
    $data['phone'] ?? 
    ''
);

// Extract password
$password = trim(
    $data['password'] ?? 
    $data['pwd'] ?? 
    $data['pass'] ?? 
    $data['user_password'] ?? 
    $data['userPassword'] ?? 
    ''
);

$requestedRole = trim($data['role'] ?? $data['user_role'] ?? $data['type'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'error' => 'User ID / Username and Password are required.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Password verification function (supports plain text, bcrypt, md5, sha1, sha256)
function checkPassword($input, $stored) {
    $in = trim((string)$input);
    $st = trim((string)$stored);
    if ($in === $st) {
        return true;
    }
    if (password_verify($in, $st)) {
        return true;
    }
    if (password_verify($st, $in)) {
        return true;
    }
    if (md5($in) === $st || md5($st) === $in) {
        return true;
    }
    if (sha1($in) === $st || sha1($st) === $in) {
        return true;
    }
    if (hash('sha256', $in) === $st || hash('sha256', $st) === $in) {
        return true;
    }
    return false;
}

$db = new DB();
$pdo = null;

try {
    $pdo = $db->getPDO();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$matchedUser = null;
$matchedRole = 'user';

// Check order based on requested role
$checkAdminFirst = (strtolower($requestedRole) === 'admin' || strtolower($username) === 'admin');

if ($checkAdminFirst) {
    // 1. Check in Admin table
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$username]);
        $adminRow = $stmt->fetch();
        if ($adminRow && checkPassword($password, $adminRow['password'])) {
            $matchedUser = $adminRow;
            $matchedRole = 'admin';
        }
    } catch (Throwable $e) {
        error_log("Admin table lookup error: " . $e->getMessage());
    }

    // 2. Fallback to Users table
    if (!$matchedUser) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$username]);
            $userRow = $stmt->fetch();
            if ($userRow && checkPassword($password, $userRow['password'])) {
                $matchedUser = $userRow;
                $matchedRole = $userRow['role'] ?? 'user';
            }
        } catch (Throwable $e) {
            error_log("Users table lookup error: " . $e->getMessage());
        }
    }
} else {
    // 1. Check in Users table
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$username]);
        $userRow = $stmt->fetch();
        if ($userRow && checkPassword($password, $userRow['password'])) {
            $matchedUser = $userRow;
            $matchedRole = $userRow['role'] ?? 'user';
        }
    } catch (Throwable $e) {
        error_log("Users table lookup error: " . $e->getMessage());
    }

    // 2. Fallback to Admin table
    if (!$matchedUser) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM admin WHERE LOWER(TRIM(username)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$username]);
            $adminRow = $stmt->fetch();
            if ($adminRow && checkPassword($password, $adminRow['password'])) {
                $matchedUser = $adminRow;
                $matchedRole = 'admin';
            }
        } catch (Throwable $e) {
            error_log("Admin table lookup error: " . $e->getMessage());
        }
    }
}

// If user found in database
if ($matchedUser) {
    if (isset($matchedUser['status']) && strtolower($matchedUser['status']) !== 'active') {
        http_response_code(403);
        echo json_encode([
            'error' => 'Your account has been deactivated by the administrator.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $loginIdentifier = $matchedUser['username'] ?? $username;
    $token = bin2hex(random_bytes(24));

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'role' => $matchedRole,
        'token' => $token,
        'user' => [
            'id' => (int)($matchedUser['id'] ?? 1),
            'username' => $loginIdentifier,
            'userId' => $loginIdentifier,
            'name' => $matchedUser['name'] ?? $loginIdentifier,
            'state' => $matchedUser['state'] ?? 'Andhra Pradesh',
            'district' => $matchedUser['district'] ?? 'Srikakulam',
            'constituency' => $matchedUser['constituency'] ?? 'Patapatnam',
            'mandal' => $matchedUser['mandal'] ?? 'L.N. Peta',
            'village' => $matchedUser['village'] ?? 'Kommuvalasa',
            'role' => $matchedRole,
            'status' => $matchedUser['status'] ?? 'active'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// No matching user in database or incorrect password
http_response_code(401);
echo json_encode([
    'error' => 'Invalid User ID or Password. No matching user found in database.'
], JSON_UNESCAPED_UNICODE);
exit();
