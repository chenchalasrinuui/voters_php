<?php
// backend/routes/villages.php - Multi-Village Management (with universal CORS & two-stage deletion)

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth_session.php';

header("Content-Type: application/json; charset=UTF-8");

// Helper function to recursively delete village upload directory
function deleteDirectoryRecursively($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectoryRecursively($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

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
    // Extract ID or Name from all possible sources
    $vIdRaw = $_GET['delete_id'] ?? 
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
              null;

    $vName = trim((string)(
        $_GET['village_name'] ?? 
        $_GET['village'] ?? 
        $_GET['name'] ?? 
        $body['village_name'] ?? 
        $body['village'] ?? 
        $body['name'] ?? 
        $_POST['village_name'] ?? 
        $_POST['village'] ?? 
        $_POST['name'] ?? 
        ''
    ));

    $targetVillageId = is_numeric($vIdRaw) ? (int)$vIdRaw : 0;
    if ($targetVillageId <= 0 && is_string($vIdRaw) && !empty($vIdRaw)) {
        $vName = trim($vIdRaw);
    }

    // Fallback: check if ID or name was passed in URI segment (e.g. /api/villages/delete/1 or /api/villages/1)
    if ($targetVillageId <= 0 && preg_match('#/villages/(?:delete/)?([^/?#]+)#', $_SERVER['REQUEST_URI'] ?? '', $m)) {
        if (is_numeric($m[1])) {
            $targetVillageId = (int)$m[1];
        } else if ($m[1] !== 'delete') {
            $vName = urldecode($m[1]);
        }
    }

    if ($targetVillageId <= 0 && empty($vName)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'చెల్లని గ్రామం ఐడీ లేదా పేరు (Village ID or name is required)',
            'success' => false
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (!$pdo) {
        http_response_code(503);
        echo json_encode([
            'error' => 'Database connection is unavailable. No records were deleted.',
            'success' => false
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $superAdminId = requireSuperAdminSession($pdo);

    $deletedVoters = 0;
    $deletedVillages = 0;
    $vRows = [];

    try {
        $pdo->beginTransaction();

        if ($targetVillageId > 0) {
            $vStmt = $pdo->prepare("SELECT * FROM villages WHERE id = ? LIMIT 1");
            $vStmt->execute([$targetVillageId]);
            $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($vRow) {
                $vRows[] = $vRow;
            }
        } else {
            $vStmt = $pdo->prepare("SELECT * FROM villages WHERE village_name = ? OR village_name_te = ?");
            $vStmt->execute([$vName, $vName]);
            $vRows = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!$vRows) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode([
                'error' => 'Village not found. No records were deleted.',
                'success' => false
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $ownerStmt = $pdo->prepare("
            SELECT u.created_by_admin_id
            FROM villages v
            INNER JOIN users u ON u.id = v.user_id
            WHERE v.id = ?
            LIMIT 1
        ");
        foreach ($vRows as $vRow) {
            $ownerStmt->execute([(int)$vRow['id']]);
            $creatorAdminId = $ownerStmt->fetchColumn();
            if ($creatorAdminId === false || (int)$creatorAdminId !== $superAdminId) {
                $pdo->rollBack();
                http_response_code(403);
                echo json_encode([
                    'error' => 'You can delete this village and its voters only if your super-admin account created its user.',
                    'success' => false
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }

        $ids = array_map(static function ($village) {
            return (int)$village['id'];
        }, $vRows);
        $targetVillageId = $ids[0];
        if (empty($vName)) {
            $vName = $vRows[0]['village_name'] ?? '';
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $delVotersStmt = $pdo->prepare("DELETE FROM voters WHERE village_id IN ($placeholders)");
        $delVotersStmt->execute($ids);
        $deletedVoters = $delVotersStmt->rowCount();

        $delVillageStmt = $pdo->prepare("DELETE FROM villages WHERE id IN ($placeholders)");
        $delVillageStmt->execute($ids);
        $deletedVillages = $delVillageStmt->rowCount();
        if ($deletedVillages !== count($ids)) {
            throw new RuntimeException('Village deletion was incomplete.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Village and voters deletion failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to delete the village and its voter records. No records were deleted.',
            'success' => false
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Clean up village folders only after the database transaction commits.
    foreach ($vRows as $vRow) {
        $state = trim($vRow['state'] ?? '');
        $district = trim($vRow['district'] ?? '');
        $constituency = trim($vRow['constituency'] ?? '');
        $mandal = trim($vRow['mandal'] ?? '');
        $villageName = trim($vRow['village_name'] ?? '');

        if ($villageName !== '') {
            $projectRoot = dirname(__DIR__);
            $folderSegments = array_map(function($s) {
                return preg_replace('/[\/\\:*?"<>|]/', '_', trim($s));
            }, [$state, $district, $constituency, $mandal, $villageName]);

            $villageDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $folderSegments);
            if (is_dir($villageDir)) {
                deleteDirectoryRecursively($villageDir);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'village_id' => $targetVillageId ?: $vName,
        'village_name' => $vName,
        'deleted_voters' => $deletedVoters,
        'deleted_village' => $deletedVillages,
        'message' => 'గ్రామం మరియు ఓటర్ల డేటా విజయవంతంగా తొలగించబడింది (Voters deleted first, then village deleted successfully)'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// 2. GET /api/villages - List villages
if ($method === 'GET') {
    $villages = [];
    if ($pdo) {
        try {
            if ($role === 'admin') {
                $stmt = $pdo->query("
                    SELECT v.*, MAX(u.created_by_admin_id) AS created_by_admin_id,
                           COUNT(vt.id) as voter_count,
                           SUM(CASE WHEN vt.is_voted = 1 THEN 1 ELSE 0 END) as voted_count
                    FROM villages v
                    LEFT JOIN users u ON u.id = v.user_id
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
            'votedCount' => (int)($v['voted_count'] ?? 0),
            'createdByAdminId' => isset($v['created_by_admin_id']) ? (int)$v['created_by_admin_id'] : null
        ];
    }, $villages);

    echo json_encode($mapped, JSON_UNESCAPED_UNICODE);
    exit();
}

// 3. POST /api/villages - Create village
if ($method === 'POST') {
    $vName = trim($body['village_name'] ?? $body['name'] ?? $_POST['village_name'] ?? $_POST['name'] ?? '');
    $state = trim($body['state'] ?? $_POST['state'] ?? 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)');
    $district = trim($body['district'] ?? $_POST['district'] ?? 'శ్రీకాకుళం (Srikakulam)');
    $constituency = trim($body['constituency'] ?? $_POST['constituency'] ?? '4 - పాతపట్నం (Patapatnam)');
    $mandal = trim($body['mandal'] ?? $_POST['mandal'] ?? 'ఎల్ ఎన్ పేట (L.N. Peta)');
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
