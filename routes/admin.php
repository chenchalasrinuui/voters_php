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

// GET /api/admin/locations - Location choices for dependent registration dropdowns
if ($action === 'locations' && $method === 'GET') {
    try {
        $locationSources = '(SELECT state, district, constituency, mandal FROM users
            UNION
            SELECT state, district, constituency, mandal FROM villages) location_options';
        $getOptions = static function (string $column, array $filters) use ($pdo, $locationSources): array {
            $allowedColumns = ['state', 'district', 'constituency', 'mandal'];
            if (!in_array($column, $allowedColumns, true)) {
                throw new InvalidArgumentException('Unsupported location field.');
            }

            $where = ["`$column` IS NOT NULL", "`$column` <> ''"];
            $values = [];
            foreach ($filters as $filterColumn => $value) {
                if ($value !== '' && in_array($filterColumn, $allowedColumns, true)) {
                    $where[] = "`$filterColumn` = ?";
                    $values[] = $value;
                }
            }

            $stmt = $pdo->prepare(
                "SELECT DISTINCT `$column` FROM $locationSources WHERE "
                . implode(' AND ', $where)
                . " ORDER BY `$column`"
            );
            $stmt->execute($values);
            return array_column($stmt->fetchAll(), $column);
        };

        $state = trim((string)($_GET['state'] ?? ''));
        $district = trim((string)($_GET['district'] ?? ''));
        $constituency = trim((string)($_GET['constituency'] ?? ''));

        echo json_encode([
            'states' => $getOptions('state', []),
            'districts' => $getOptions('district', ['state' => $state]),
            'constituencies' => $getOptions('constituency', [
                'state' => $state,
                'district' => $district,
            ]),
            'mandals' => $getOptions('mandal', [
                'state' => $state,
                'district' => $district,
                'constituency' => $constituency,
            ]),
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

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

// POST /api/admin/users/:id - Update a user created by the current super-admin
if ($action === 'update-user' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $superAdminId = requireSuperAdminSession($pdo);
    $userId = (int)($_GET['id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user ID.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $fields = ['username', 'name', 'state', 'district', 'constituency', 'mandal', 'village'];
    $values = [];
    foreach ($fields as $field) {
        if (!isset($body[$field]) || !is_string($body[$field]) || trim($body[$field]) === '') {
            http_response_code(400);
            echo json_encode(['error' => ucfirst($field) . ' is required.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $values[$field] = trim($body[$field]);
    }

    $password = $body['password'] ?? '';
    if (!is_string($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be a string.'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $owner = $pdo->prepare('SELECT created_by_admin_id, village FROM users WHERE id = ? LIMIT 1');
        $owner->execute([$userId]);
        $userRecord = $owner->fetch(PDO::FETCH_ASSOC);
        if (!$userRecord) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        if ((int)$userRecord['created_by_admin_id'] !== $superAdminId) {
            http_response_code(403);
            echo json_encode(['error' => 'You can edit only users created by your super-admin account.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $check->execute([$values['username'], $userId]);
        if ($check->fetchColumn()) {
            http_response_code(400);
            echo json_encode(['error' => 'Username is already in use.'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $pdo->beginTransaction();
        $updateFields = 'username = ?, name = ?, state = ?, district = ?, constituency = ?, mandal = ?, village = ?';
        $updateValues = [
            $values['username'],
            $values['name'],
            $values['state'],
            $values['district'],
            $values['constituency'],
            $values['mandal'],
            $values['village'],
        ];
        if (trim($password) !== '') {
            $updateFields .= ', password = ?';
            $updateValues[] = trim($password);
        }
        $updateValues[] = $userId;
        $pdo->prepare("UPDATE users SET $updateFields WHERE id = ?")->execute($updateValues);

        if ((string)$userRecord['village'] !== $values['village']) {
            $primaryVillage = $pdo->prepare(
                'SELECT id FROM villages WHERE user_id = ? AND village_name = ? ORDER BY id ASC LIMIT 1'
            );
            $primaryVillage->execute([$userId, $userRecord['village']]);
            $primaryVillageId = $primaryVillage->fetchColumn();
            if ($primaryVillageId !== false) {
                $pdo->prepare(
                    'UPDATE villages SET village_name = ?, village_name_te = ?, booth_name = ? WHERE id = ?'
                )->execute([
                    $values['village'],
                    $values['village'],
                    $values['village'] . ' పోలింగ్ కేంద్రం',
                    $primaryVillageId,
                ]);
            }
        }

        $pdo->prepare(
            'UPDATE villages SET state = ?, district = ?, constituency = ?, mandal = ? WHERE user_id = ?'
        )->execute([
            $values['state'],
            $values['district'],
            $values['constituency'],
            $values['mandal'],
            $userId,
        ]);
        $pdo->commit();

        echo json_encode(['success' => true, 'id' => $userId] + $values, JSON_UNESCAPED_UNICODE);
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

http_response_code(404);
echo json_encode(['error' => 'Admin endpoint not found.'], JSON_UNESCAPED_UNICODE);
