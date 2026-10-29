<?php
// backend/routes/villages.php - Multi-Village Management

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';

header("Content-Type: application/json; charset=UTF-8");

$db = new DB();
$pdo = $db->getPDO();

$method = $_SERVER['REQUEST_METHOD'];
$role = $_SERVER['HTTP_X_USER_ROLE'] ?? 'user';
$userId = isset($_SERVER['HTTP_X_USER_NUMERIC_ID']) ? (int)$_SERVER['HTTP_X_USER_NUMERIC_ID'] : 1;

// 1. GET /api/villages - List villages
if ($method === 'GET') {
    try {
        if ($role === 'admin') {
            $stmt = $pdo->query("
                SELECT v.*, COUNT(vt.id) as voter_count,
                       SUM(CASE WHEN vt.is_voted = 1 THEN 1 ELSE 0 END) as voted_count
                FROM villages v
                LEFT JOIN voters vt ON vt.village_id = v.id
                GROUP BY v.id
                ORDER BY v.id ASC
            ");
            $villages = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare("
                SELECT v.*, COUNT(vt.id) as voter_count,
                       SUM(CASE WHEN vt.is_voted = 1 THEN 1 ELSE 0 END) as voted_count
                FROM villages v
                LEFT JOIN voters vt ON vt.village_id = v.id
                WHERE v.user_id = ? OR v.village_name LIKE '%Kommuvalasa%'
                GROUP BY v.id
                ORDER BY v.id ASC
            ");
            $stmt->execute([$userId]);
            $villages = $stmt->fetchAll();
        }

        // Map to format expected by UI
        $mapped = array_map(function($v) {
            $isKommu = stripos($v['village_name'], 'Kommuvalasa') !== false;
            return [
                'id' => (string)$v['id'],
                'numeric_id' => (int)$v['id'],
                'name' => $v['village_name'],
                'name_te' => $v['village_name_te'] ?? $v['village_name'],
                'state' => $v['state'],
                'district' => $v['district'],
                'constituency' => $v['constituency'],
                'mandal' => $v['mandal'],
                'booth_name' => $v['booth_name'] ?? ($v['village_name'] . ' పోలింగ్ కేంద్రం'),
                'part_no' => $v['part_no'] ?? '1',
                'fileName' => $v['pdf_filename'] ?? ($isKommu ? 'KOMMUVALASA_NEW_VOTER_WARD_LIST_2026.pdf' : ($v['village_name'] . '.pdf')),
                'isDefault' => $isKommu,
                'voterCount' => (int)($v['voter_count'] ?? 0),
                'votedCount' => (int)($v['voted_count'] ?? 0)
            ];
        }, $villages);

        echo json_encode($mapped, JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 2. POST /api/villages - Create village or Delete village
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];

    // Check if this is a delete request sent via POST
    if (isset($_GET['action']) && $_GET['action'] === 'delete' || isset($body['id']) && (isset($_GET['action']) || strpos($_SERVER['REQUEST_URI'], 'delete') !== false)) {
        $vId = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if ($vId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'చెల్లని గ్రామం ఐడీ'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM voters WHERE village_id = ?")->execute([$vId]);
            $pdo->prepare("DELETE FROM villages WHERE id = ?")->execute([$vId]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'గ్రామం మరియు ఓటర్ల డేటా తొలగించబడింది'], JSON_UNESCAPED_UNICODE);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    $vName = trim($body['village_name'] ?? $body['name'] ?? '');
    $state = trim($body['state'] ?? 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)');
    $district = trim($body['district'] ?? 'శ్రీకాకుళం (Srikakulam)');
    $constituency = trim($body['constituency'] ?? '4 - పాతపట్నం (Patapatnam)');
    $mandal = trim($body['mandal'] ?? 'ఎల్ ఎన్ పేట (L.N. Peta)');
    $boothName = trim($body['booth_name'] ?? ($vName . ' పోలింగ్ కేంద్రం'));
    $partNo = trim($body['part_no'] ?? '1');

    if (!$vName) {
        http_response_code(400);
        echo json_encode(['error' => 'గ్రామం పేరు తప్పనిసరి'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $ins = $pdo->prepare("
            INSERT INTO villages (user_id, village_name, village_name_te, state, district, constituency, mandal, booth_name, part_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$userId, $vName, $vName, $state, $district, $constituency, $mandal, $boothName, $partNo]);
        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'id' => (string)$newId,
            'name' => $vName,
            'name_te' => $vName,
            'state' => $state,
            'district' => $district,
            'constituency' => $constituency,
            'mandal' => $mandal,
            'booth_name' => $boothName,
            'part_no' => $partNo,
            'voterCount' => 0,
            'votedCount' => 0
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 3. DELETE /api/villages/:id
if ($method === 'DELETE' || isset($_GET['delete_id'])) {
    $vId = (int)($_GET['delete_id'] ?? $_GET['id'] ?? 0);

    if ($vId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'చెల్లని గ్రామం ఐడీ'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM voters WHERE village_id = ?")->execute([$vId]);
        $pdo->prepare("DELETE FROM villages WHERE id = ?")->execute([$vId]);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'గ్రామం మరియు ఓటర్ల డేటా తొలగించబడింది'], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
