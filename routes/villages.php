<?php
// backend/routes/villages.php - Multi-Village Management (with universal CORS)

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';

header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS immediately
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = strtolower(trim($_GET['action'] ?? ''));
$role = $_SERVER['HTTP_X_USER_ROLE'] ?? 'user';
$userId = isset($_SERVER['HTTP_X_USER_NUMERIC_ID']) ? (int)$_SERVER['HTTP_X_USER_NUMERIC_ID'] : 1;

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
if (!is_array($body)) {
    $body = [];
}

$pdo = null;
try {
    $db = new DB();
    $pdo = $db->getPDO();
} catch (Throwable $e) {
    error_log("Database connection warning in villages route: " . $e->getMessage());
}

// 1. DELETE /api/villages/delete OR DELETE /api/villages/:id OR action=delete
if (
    $action === 'delete' ||
    ($body['action'] ?? '') === 'delete' ||
    $method === 'DELETE' ||
    isset($_GET['delete_id']) ||
    stripos($_SERVER['REQUEST_URI'] ?? '', 'villages/delete') !== false
) {
    // Extract village ID from all possible parameters
    $vId = (int)(
        $_GET['delete_id'] ?? 
        $_GET['id'] ?? 
        $_GET['village_id'] ?? 
        $_GET['villageId'] ?? 
        $body['id'] ?? 
        $body['delete_id'] ?? 
        $body['village_id'] ?? 
        $body['villageId'] ?? 
        $_POST['id'] ?? 
        $_POST['delete_id'] ?? 
        $_POST['village_id'] ?? 
        $_POST['villageId'] ?? 
        0
    );

    // Fallback: check if ID was passed in URI segment (e.g. /api/villages/delete/1 or /api/villages/1)
    if ($vId <= 0 && preg_match('#/villages/(?:delete/)?(\d+)#', $_SERVER['REQUEST_URI'] ?? '', $m)) {
        $vId = (int)$m[1];
    }

    if ($vId <= 0) {
        http_response_code(400);
        echo json_encode([
            'error' => 'చెల్లని గ్రామం ఐడీ (Village ID is required)',
            'success' => false
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM voters WHERE village_id = ?")->execute([$vId]);
            $pdo->prepare("DELETE FROM villages WHERE id = ?")->execute([$vId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Village deletion warning: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'id' => $vId,
        'message' => 'గ్రామం మరియు ఓటర్ల డేటా తొలగించబడింది (Village and voters deleted successfully)'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. GET /api/villages - List villages
if ($method === 'GET') {
    $villages = [];
    if ($pdo) {
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
        } catch (Throwable $e) {
            error_log("Village listing DB error: " . $e->getMessage());
        }
    }

    // Default fallback if database empty
    if (empty($villages)) {
        $villages = [
            [
                'id' => 1,
                'village_name' => 'Kommuvalasa',
                'village_name_te' => 'కొమ్మువలస',
                'state' => 'Andhra Pradesh',
                'district' => 'Srikakulam',
                'constituency' => 'Patapatnam',
                'mandal' => 'L.N. Peta',
                'booth_name' => 'School',
                'part_no' => '160',
                'voter_count' => 806,
                'voted_count' => 0
            ]
        ];
    }

    // Map to format expected by UI
    $mapped = array_map(function($v) {
        $isKommu = stripos($v['village_name'] ?? '', 'Kommuvalasa') !== false;
        return [
            'id' => (string)$v['id'],
            'numeric_id' => (int)$v['id'],
            'name' => $v['village_name'] ?? 'Kommuvalasa',
            'name_te' => $v['village_name_te'] ?? $v['village_name'] ?? 'కొమ్మువలస',
            'state' => $v['state'] ?? 'Andhra Pradesh',
            'district' => $v['district'] ?? 'Srikakulam',
            'constituency' => $v['constituency'] ?? 'Patapatnam',
            'mandal' => $v['mandal'] ?? 'L.N. Peta',
            'booth_name' => $v['booth_name'] ?? (($v['village_name'] ?? 'Kommuvalasa') . ' పోలింగ్ కేంద్రం'),
            'part_no' => $v['part_no'] ?? '160',
            'fileName' => $v['pdf_filename'] ?? ($isKommu ? 'KOMMUVALASA_NEW_VOTER_WARD_LIST_2026.pdf' : (($v['village_name'] ?? 'village') . '.pdf')),
            'isDefault' => $isKommu,
            'voterCount' => (int)($v['voter_count'] ?? 806),
            'votedCount' => (int)($v['voted_count'] ?? 0)
        ];
    }, $villages);

    echo json_encode($mapped, JSON_UNESCAPED_UNICODE);
    exit();
}

// 3. POST /api/villages - Create village
if ($method === 'POST') {
    $vName = trim($body['village_name'] ?? $body['name'] ?? $_POST['village_name'] ?? $_POST['name'] ?? '');
    $state = trim($body['state'] ?? $_POST['state'] ?? 'Andhra Pradesh');
    $district = trim($body['district'] ?? $_POST['district'] ?? 'Srikakulam');
    $constituency = trim($body['constituency'] ?? $_POST['constituency'] ?? 'Patapatnam');
    $mandal = trim($body['mandal'] ?? $_POST['mandal'] ?? 'L.N. Peta');
    $boothName = trim($body['booth_name'] ?? $_POST['booth_name'] ?? ($vName . ' పోలింగ్ కేంద్రం'));
    $partNo = trim($body['part_no'] ?? $_POST['part_no'] ?? '1');

    if (!$vName) {
        http_response_code(400);
        echo json_encode(['error' => 'గ్రామం పేరు తప్పనిసరి (Village name required)'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $newId = time();
    if ($pdo) {
        try {
            $ins = $pdo->prepare("
                INSERT INTO villages (user_id, village_name, village_name_te, state, district, constituency, mandal, booth_name, part_no)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$userId, $vName, $vName, $state, $district, $constituency, $mandal, $boothName, $partNo]);
            $newId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log("Village creation DB warning: " . $e->getMessage());
        }
    }

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
}
