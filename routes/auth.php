<?php
// backend/routes/auth.php - Authentication API (User & Admin Login from Database)

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';

header("Content-Type: application/json; charset=UTF-8");

// Handle GET request (Route Health / Diagnostic check)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode([
        'status' => 'active',
        'endpoint' => '/api/auth/login',
        'method' => 'POST',
        'message' => 'Auth login route is active. Database-driven authentication.',
        'cors' => 'enabled'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

// Also support x-www-form-urlencoded or multipart form data
if (empty($data)) {
    $data = $_POST;
}

// Accept username, userId, user_id, phone, email, mobile, or id from React
$username = trim(
    $data['username'] ?? 
    $data['userId'] ?? 
    $data['user_id'] ?? 
    $data['userid'] ?? 
    $data['phone'] ?? 
    $data['mobile'] ?? 
    $data['email'] ?? 
    $data['user'] ?? 
    $data['id'] ?? 
    ''
);

$password = trim(
    $data['password'] ?? 
    $data['pwd'] ?? 
    $data['pass'] ?? 
    ''
);

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode([
        'error' => 'యూజర్‌నేమ్ మరియు పాస్‌వర్డ్ నమోదు చేయండి (User ID / Username and Password required)'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Helper: compare password against database value (supports plain text, bcrypt, md5, sha1)
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
        'error' => 'డేటాబేస్ కనెక్షన్ లోపం (Database connection failed): ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$matchedUser = null;
$matchedRole = 'user';

// 1. Check in Admin table in Database
try {
    $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ? OR email = ? OR phone = ? LIMIT 1");
    $stmt->execute([$username, $username, $username]);
    $adminRow = $stmt->fetch();
    if ($adminRow && checkPassword($password, $adminRow['password'])) {
        $matchedUser = $adminRow;
        $matchedRole = 'admin';
    }
} catch (Throwable $e) {
    // Fallback if admin table does not have email/phone columns
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $adminRow = $stmt->fetch();
        if ($adminRow && checkPassword($password, $adminRow['password'])) {
            $matchedUser = $adminRow;
            $matchedRole = 'admin';
        }
    } catch (Throwable $e2) {}
}

// 2. Check in Users table in Database (Created by Admin or Registered)
if (!$matchedUser) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR phone = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $username, $username]);
        $userRow = $stmt->fetch();
        if ($userRow && checkPassword($password, $userRow['password'])) {
            $matchedUser = $userRow;
            $matchedRole = $userRow['role'] ?? 'user';
        }
    } catch (Throwable $e) {
        // Fallback if users table does not have phone/email columns
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $userRow = $stmt->fetch();
            if ($userRow && checkPassword($password, $userRow['password'])) {
                $matchedUser = $userRow;
                $matchedRole = $userRow['role'] ?? 'user';
            }
        } catch (Throwable $e2) {}
    }
}

// 3. Check in Employees table in Database
if (!$matchedUser) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE (email = ? OR phone = ?) AND is_active = 'T' LIMIT 1");
        $stmt->execute([$username, $username]);
        $empRow = $stmt->fetch();
        if ($empRow && (empty($empRow['password']) || checkPassword($password, $empRow['password']))) {
            $matchedUser = $empRow;
            $matchedRole = $empRow['role'] ?? 'Employee';
        }
    } catch (Throwable $e) {}
}

// Check if user account was found
if ($matchedUser) {
    // Check if account has been deactivated
    if (isset($matchedUser['status']) && strtolower($matchedUser['status']) !== 'active') {
        http_response_code(403);
        echo json_encode([
            'error' => 'మీ ఖాతా నిలిపివేయబడింది (Your account has been deactivated by administrator)'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $loginIdentifier = $matchedUser['username'] ?? $matchedUser['phone'] ?? $matchedUser['email'] ?? $username;
    $token = bin2hex(random_bytes(20));

    echo json_encode([
        'success' => true,
        'role' => $matchedRole,
        'token' => $token,
        'user' => [
            'id' => (int)($matchedUser['id'] ?? 1),
            'username' => $loginIdentifier,
            'userId' => $loginIdentifier,
            'name' => $matchedUser['name'] ?? $loginIdentifier,
            'phone' => $matchedUser['phone'] ?? '',
            'email' => $matchedUser['email'] ?? '',
            'state' => $matchedUser['state'] ?? 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)',
            'district' => $matchedUser['district'] ?? 'శ్రీకాకుళం (Srikakulam)',
            'constituency' => $matchedUser['constituency'] ?? '4 - పాతపట్నం (Patapatnam)',
            'mandal' => $matchedUser['mandal'] ?? 'ఎల్ ఎన్ పేట (L.N. Peta)',
            'village' => $matchedUser['village'] ?? 'కొమ్మువలస (Kommuvalasa)',
            'role' => $matchedRole
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// No matching user or password incorrect
http_response_code(401);
echo json_encode([
    'error' => 'చెల్లని యూజర్ ఐడీ లేదా పాస్‌వర్డ్ (Invalid User ID or Password. No matching user found in database.)'
], JSON_UNESCAPED_UNICODE);
exit();
