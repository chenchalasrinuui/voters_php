<?php
// backend/routes/admin.php - Admin Panel Management (Users Creation, Stats, Status Toggles)

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth_session.php';

header("Content-Type: application/json; charset=UTF-8");

$userRole = $_SERVER['HTTP_X_USER_ROLE'] ?? 'user';
if ($userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'అడ్మిన్ అనుమతి అవసరం (Admin privileges required)'], JSON_UNESCAPED_UNICODE);
    exit();
}

$db = new DB();
$pdo = $db->getPDO();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

// 1. GET /api/admin/stats
if ($action === 'stats' && $method === 'GET') {
    try {
        $uCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $vCount = (int)$pdo->query("SELECT COUNT(*) FROM villages")->fetchColumn();
        $vtCount = (int)$pdo->query("SELECT COUNT(*) FROM voters")->fetchColumn();
        $votedCount = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE is_voted = 1")->fetchColumn();

        $turnout = $vtCount > 0 ? round(($votedCount / $vtCount) * 100, 1) : 0;

        echo json_encode([
            'totalUsers' => $uCount,
            'totalVillages' => $vCount,
            'totalVoters' => $vtCount,
            'totalVoted' => $votedCount,
            'turnoutPercentage' => (string)$turnout
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 2. GET /api/admin/users - List all users with summary counts
if (($action === 'users' || $action === '') && $method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT 
                u.id, u.username, u.name, u.state, u.district, u.constituency, u.mandal, u.village, u.status, u.created_at,
                u.created_by_admin_id,
                COUNT(DISTINCT v.id) as villages_count,
                COUNT(DISTINCT vt.id) as voters_count,
                SUM(CASE WHEN vt.is_voted = 1 THEN 1 ELSE 0 END) as voted_count
            FROM users u
            LEFT JOIN villages v ON v.user_id = u.id
            LEFT JOIN voters vt ON vt.user_id = u.id
            GROUP BY u.id
            ORDER BY u.id DESC
        ");
        $users = $stmt->fetchAll();
        echo json_encode($users, JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 3. POST /api/admin/users - Create new user (User Creation Form)
if (($action === 'users' || $action === 'create') && $method === 'POST') {
    $creatorAdminId = requireSuperAdminSession($pdo);
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');
    $name = trim($body['name'] ?? '');
    $state = trim($body['state'] ?? 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)');
    $district = trim($body['district'] ?? 'శ్రీకాకుళం (Srikakulam)');
    $constituency = trim($body['constituency'] ?? '4 - పాతపట్నం (Patapatnam)');
    $mandal = trim($body['mandal'] ?? 'ఎల్ ఎన్ పేట (L.N. Peta)');
    $village = trim($body['village'] ?? 'కొమ్మువలస (Kommuvalasa)');

    if (!$username || !$password || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'యూజర్‌నేమ్, పాస్‌వర్డ్ మరియు పేరు తప్పనిసరి'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        // Check uniqueness
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'ఈ యూజర్‌నేమ్ ఇప్పటికే వాడుకలో ఉంది. దయచేసి మరొకటి ఎంచుకోండి.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $pdo->beginTransaction();
        $ins = $pdo->prepare("
            INSERT INTO users (username, password, name, state, district, constituency, mandal, village, role, status, created_by_admin_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'user', 'active', ?)
        ");
        $ins->execute([$username, $password, $name, $state, $district, $constituency, $mandal, $village, $creatorAdminId]);
        $newId = (int)$pdo->lastInsertId();

        $insV = $pdo->prepare("
            INSERT INTO villages (user_id, village_name, village_name_te, state, district, constituency, mandal, booth_name, part_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insV->execute([$newId, $village, $village, $state, $district, $constituency, $mandal, $village . ' పోలింగ్ కేంద్రం', '1']);
        $pdo->commit();

        echo json_encode([
            'id' => $newId,
            'username' => $username,
            'password' => $password,
            'name' => $name,
            'state' => $state,
            'district' => $district,
            'constituency' => $constituency,
            'mandal' => $mandal,
            'village' => $village,
            'role' => 'user',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 4. POST / PATCH /api/admin/toggle-user
if ($action === 'toggle-user') {
    $userId = (int)($body['userId'] ?? $body['id'] ?? $_GET['id'] ?? 0);
    $status = trim($body['status'] ?? 'active');

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'చెల్లని యూజర్ ఐడీ'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $userId]);
        echo json_encode(['success' => true, 'id' => $userId, 'status' => $status], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 5. DELETE /api/admin/users/:id or action=delete-user
if ($action === 'delete-user' || ($method === 'DELETE' && isset($_GET['id']))) {
    $superAdminId = requireSuperAdminSession($pdo);
    $userId = (int)($_GET['id'] ?? $body['userId'] ?? $body['id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'చెల్లని యూజర్ ఐడీ'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $owner = $pdo->prepare("SELECT created_by_admin_id FROM users WHERE id = ? LIMIT 1");
        $owner->execute([$userId]);
        $creatorAdminId = $owner->fetchColumn();
        if ($creatorAdminId === false) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        if ((int)$creatorAdminId !== $superAdminId) {
            http_response_code(403);
            echo json_encode(['error' => 'You can delete only users created by your super-admin account.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM voters WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM villages WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'యూజర్ ఖాతా తొలగించబడింది'], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
