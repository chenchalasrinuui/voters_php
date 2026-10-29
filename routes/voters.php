<?php
// backend/routes/voters.php - Voter Records, Telugu Search, Status & Color Flags

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';

header("Content-Type: application/json; charset=UTF-8");

$db = new DB();
$pdo = $db->getPDO();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

// 1. POST /api/voters/vote - Toggle Mark as Voted
if ($action === 'vote' || (isset($body['action']) && $body['action'] === 'vote')) {
    $voterId = $body['voterId'] ?? $body['id'] ?? null;
    $status = $body['status'] ?? null; // 'VOTED' | 'NOT_VOTED'

    if (!$voterId) {
        http_response_code(400);
        echo json_encode(['error' => 'Voter ID is required'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        if ($status !== null) {
            $isVoted = ($status === 'VOTED' || $status === 1 || $status === '1') ? 1 : 0;
            $stmt = $pdo->prepare("
                UPDATE voters 
                SET is_voted = ?, 
                    voted_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
                WHERE id = ? OR epic = ? OR sl_no = ?
            ");
            $stmt->execute([$isVoted, $isVoted, $voterId, $voterId, $voterId]);
        } else {
            // Toggle
            $stmt = $pdo->prepare("
                UPDATE voters 
                SET is_voted = CASE WHEN is_voted = 1 THEN 0 ELSE 1 END,
                    voted_at = CASE WHEN is_voted = 0 THEN CURRENT_TIMESTAMP ELSE NULL END
                WHERE id = ? OR epic = ? OR sl_no = ?
            ");
            $stmt->execute([$voterId, $voterId, $voterId]);
        }

        $voter = $pdo->prepare("SELECT * FROM voters WHERE id = ? OR epic = ? OR sl_no = ? LIMIT 1");
        $voter->execute([$voterId, $voterId, $voterId]);
        $res = $voter->fetch();

        echo json_encode([
            'success' => true,
            'is_voted' => (int)($res['is_voted'] ?? 0),
            'votingStatus' => ($res['is_voted'] ?? 0) == 1 ? 'VOTED' : 'NOT_VOTED'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 2. POST /api/voters/flag - Update Red, Green, Yellow, None button flags
if ($action === 'flag' || (isset($body['action']) && $body['action'] === 'flag')) {
    $voterId = $body['voterId'] ?? $body['id'] ?? null;
    $flagColor = strtolower(trim($body['flagColor'] ?? $body['flag_color'] ?? 'none'));

    $valid = ['none', 'green', 'yellow', 'red'];
    if (!in_array($flagColor, $valid)) {
        $flagColor = 'none';
    }

    if (!$voterId) {
        http_response_code(400);
        echo json_encode(['error' => 'Voter ID is required'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE voters SET flag_color = ? WHERE id = ? OR epic = ? OR sl_no = ?");
        $stmt->execute([$flagColor, $voterId, $voterId, $voterId]);

        echo json_encode(['success' => true, 'flag_color' => $flagColor, 'flagColor' => $flagColor], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 3. POST /api/voters/reset-votes
if ($action === 'reset-votes' || (isset($body['action']) && $body['action'] === 'reset-votes')) {
    $vId = (int)($body['village_id'] ?? $body['villageId'] ?? 0);
    try {
        if ($vId > 0) {
            $stmt = $pdo->prepare("UPDATE voters SET is_voted = 0, voted_at = NULL WHERE village_id = ?");
            $stmt->execute([$vId]);
        } else {
            $pdo->exec("UPDATE voters SET is_voted = 0, voted_at = NULL");
        }
        echo json_encode(['success' => true, 'message' => 'అన్ని ఓట్లు రీసెట్ చేయబడ్డాయి (Votes reset successfully)'], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 4. POST /api/voters/edit - Update voter details & notes
if ($action === 'edit' || (isset($body['action']) && $body['action'] === 'edit')) {
    $voterId = $body['id'] ?? $body['voterId'] ?? null;
    if (!$voterId) {
        http_response_code(400);
        echo json_encode(['error' => 'Voter ID is required'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    try {
        $notes = $body['notes'] ?? null;
        $name = $body['name'] ?? null;
        $houseNo = $body['house_no'] ?? $body['houseNo'] ?? null;

        $stmt = $pdo->prepare("
            UPDATE voters 
            SET notes = COALESCE(?, notes),
                name = COALESCE(?, name),
                house_no = COALESCE(?, house_no)
            WHERE id = ? OR epic = ? OR sl_no = ?
        ");
        $stmt->execute([$notes, $name, $houseNo, $voterId, $voterId, $voterId]);
        echo json_encode(['success' => true, 'message' => 'ఓటరు వివరాలు నవీకరించబడ్డాయి'], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// 5. GET /api/voters - Search & filter voters
if ($method === 'GET') {
    $villageParam = $_GET['village_id'] ?? $_GET['village'] ?? null;
    $search = trim($_GET['search'] ?? '');
    $gender = trim($_GET['gender'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $flag = trim($_GET['flag'] ?? '');
    $ward = trim($_GET['ward'] ?? '');
    $page = (int)($_GET['page'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 0);

    try {
        $conditions = ["1=1"];
        $params = [];

        // Match village by id or name
        if ($villageParam && $villageParam !== 'all') {
            if (is_numeric($villageParam)) {
                $conditions[] = "(village_id = ?)";
                $params[] = (int)$villageParam;
            } elseif (stripos($villageParam, 'kommu') !== false) {
                // Find Kommuvalasa village id
                $kStmt = $pdo->query("SELECT id FROM villages WHERE village_name LIKE '%Kommuvalasa%' LIMIT 1");
                $kRow = $kStmt->fetch();
                if ($kRow) {
                    $conditions[] = "(village_id = ?)";
                    $params[] = (int)$kRow['id'];
                }
            } else {
                $vStmt = $pdo->prepare("SELECT id FROM villages WHERE village_name = ? LIMIT 1");
                $vStmt->execute([$villageParam]);
                $vRow = $vStmt->fetch();
                if ($vRow) {
                    $conditions[] = "(village_id = ?)";
                    $params[] = (int)$vRow['id'];
                }
            }
        }

        // Search: bilingual support (Telugu script, English spelling, EPIC number, House number)
        if ($search !== '') {
            $like = "%$search%";
            $conditions[] = "(
                name LIKE ? OR 
                name_en LIKE ? OR 
                epic LIKE ? OR 
                house_no LIKE ? OR 
                relation_name LIKE ? OR 
                relation_name_en LIKE ?
            )";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Ward filter
        if ($ward !== '' && $ward !== 'all') {
            $conditions[] = "(ward_no = ?)";
            $params[] = $ward;
        }

        // Gender filter
        if ($gender !== '' && $gender !== 'all') {
            $conditions[] = "(LOWER(gender) = ?)";
            $params[] = strtolower($gender);
        }

        // Voting status filter
        if ($status !== '' && $status !== 'all') {
            if ($status === 'VOTED') {
                $conditions[] = "(is_voted = 1)";
            } elseif ($status === 'NOT_VOTED') {
                $conditions[] = "(is_voted = 0)";
            }
        }

        // Color Flag filter (green, yellow, red, none)
        if ($flag !== '' && $flag !== 'all') {
            $conditions[] = "(LOWER(flag_color) = ?)";
            $params[] = strtolower($flag);
        }

        $whereClause = implode(" AND ", $conditions);
        $sql = "SELECT * FROM voters WHERE $whereClause ORDER BY sl_no ASC";

        if ($limit > 0 && $page > 0) {
            $offset = ($page - 1) * $limit;
            $sql .= " LIMIT $limit OFFSET $offset";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // If no records in remote MySQL for village, fallback to public/voters_data.json
        if (empty($rows) && ($search === '' && $ward === '' && $gender === '' && $status === '' && $flag === '')) {
            $jsonPath = __DIR__ . '/../../public/voters_data.json';
            if (file_exists($jsonPath)) {
                $raw = file_get_contents($jsonPath);
                $fallback = json_decode($raw, true) ?? [];
                $rows = array_map(function($v, $idx) {
                    return [
                        'id' => $v['id'] ?? ($idx + 1),
                        'sl_no' => $v['slNo'] ?? ($idx + 1),
                        'slNo' => $v['slNo'] ?? ($idx + 1),
                        'epic' => $v['epic'] ?? '',
                        'name' => $v['name'] ?? '',
                        'name_en' => $v['nameEn'] ?? ($v['name'] ?? ''),
                        'nameEn' => $v['nameEn'] ?? ($v['name'] ?? ''),
                        'relation_type' => $v['relationType'] ?? 'Father',
                        'relationType' => $v['relationType'] ?? 'Father',
                        'relation_name' => $v['relationName'] ?? '',
                        'relationName' => $v['relationName'] ?? '',
                        'house_no' => $v['houseNo'] ?? '1-1',
                        'houseNo' => $v['houseNo'] ?? '1-1',
                        'age' => (int)($v['age'] ?? 35),
                        'gender' => $v['gender'] ?? 'Male',
                        'ward_no' => (string)($v['wardNo'] ?? '1'),
                        'wardNo' => (string)($v['wardNo'] ?? '1'),
                        'is_voted' => 0,
                        'votingStatus' => 'NOT_VOTED',
                        'flag_color' => 'none',
                        'flagColor' => 'none'
                    ];
                }, $fallback, array_keys($fallback));
            }
        }

        // Format for UI
        $mapped = array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'sl_no' => (int)$r['sl_no'],
                'slNo' => (int)$r['sl_no'],
                'epic' => $r['epic'],
                'name' => $r['name'],
                'name_en' => $r['name_en'] ?? $r['name'],
                'nameEn' => $r['name_en'] ?? $r['name'],
                'relation_type' => $r['relation_type'] ?? 'Father',
                'relationType' => $r['relation_type'] ?? 'Father',
                'relation_name' => $r['relation_name'] ?? '',
                'relationName' => $r['relation_name'] ?? '',
                'relation_name_en' => $r['relation_name_en'] ?? '',
                'relationNameEn' => $r['relation_name_en'] ?? '',
                'house_no' => $r['house_no'] ?? '1-1',
                'houseNo' => $r['house_no'] ?? '1-1',
                'age' => (int)$r['age'],
                'gender' => $r['gender'],
                'ward_no' => (string)$r['ward_no'],
                'wardNo' => (string)$r['ward_no'],
                'section_name' => $r['section_name'] ?? '',
                'photo_url' => $r['photo_url'] ?? '',
                'photoUrl' => $r['photo_url'] ?? '',
                'is_voted' => (int)($r['is_voted'] ?? 0),
                'votingStatus' => ($r['is_voted'] ?? 0) == 1 ? 'VOTED' : 'NOT_VOTED',
                'flag_color' => $r['flag_color'] ?? 'none',
                'flagColor' => $r['flag_color'] ?? 'none',
            ];
        }, $rows);

        echo json_encode($mapped, JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
