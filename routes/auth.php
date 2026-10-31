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

// Extract login identifier (userId, username, user_id, userid, user, id)
$username = trim(
    $data['username'] ?? 
    $data['userId'] ?? 
    $data['user_id'] ?? 
    $data['userid'] ?? 
    $data['phone'] ?? 
    $data['email'] ?? 
    $data['user'] ?? 
    $data['id'] ?? 
    ''
);

// Extract password (password, pwd, pass)
$password = trim(
    $data['password'] ?? 
    $data['pwd'] ?? 
    $data['pass'] ?? 
    ''
);

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'error' => 'User ID / Username and Password are required.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Password verification function (supports plain text, bcrypt, md5, sha1)
function checkPassword($input, $stored) {
    if ($input === $stored) {
        return true;
    }
    if (password_verify($input, $stored)) {
        return true;
    }
    if (md5($input) === $stored) {
        return true;
    }
    if (sha1($input) === $stored) {
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

// 1. Check in Admin table (id, username, password, name)
try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $stmt->execute([$username]);
    $adminRow = $stmt->fetch();
    if ($adminRow && checkPassword($password, $adminRow['password'])) {
        $matchedUser = $adminRow;
        $matchedRole = 'admin';
    }
} catch (Throwable $e) {
    error_log("Admin table lookup error: " . $e->getMessage());
}

// 2. Check in Users table (id, username, password, name, state, district, constituency, mandal, village, role, status)
if (!$matchedUser) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
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

// 3. Check in Employees table if present
if (!$matchedUser) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE LOWER(email) = LOWER(?) OR phone = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $empRow = $stmt->fetch();
        if ($empRow && (empty($empRow['password']) || checkPassword($password, $empRow['password']))) {
            $matchedUser = $empRow;
            $matchedRole = $empRow['role'] ?? 'Employee';
        }
    } catch (Throwable $e) {}
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

    $loginIdentifier = $matchedUser['username'] ?? $matchedUser['phone'] ?? $matchedUser['email'] ?? $username;
    $token = bin2hex(random_bytes(24));

    echo json_encode([
        'success' => true,
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
