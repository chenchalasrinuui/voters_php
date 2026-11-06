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

// POST /api/voters/out-of-station - Update station status and contact phone
if ($action === 'out-of-station' || (isset($body['action']) && $body['action'] === 'out-of-station')) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST is required'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $voterId = $body['voterId'] ?? $body['id'] ?? null;
    $outOfStation = $body['out_of_station'] ?? $body['outOfStation'] ?? null;
    $phoneNumber = trim((string)($body['phone_number'] ?? $body['phoneNumber'] ?? ''));
    $location = trim((string)($body['location'] ?? $body['out_of_station_location'] ?? ''));
    $expectedToReturn = $body['expected_to_return'] ?? $body['expectedToReturn'] ?? null;
    $isOutOfStation = in_array($outOfStation, [true, 1, '1'], true);

    if (!$voterId || !in_array($outOfStation, [true, false, 0, 1, '0', '1'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Voter ID and a valid out-of-station status are required'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($isOutOfStation) {
        if (!in_array($expectedToReturn, [true, false, 0, 1, '0', '1'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Choose whether the voter is expected to return'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        if ($phoneNumber !== '') {
            $digitCount = preg_match_all('/[0-9]/', $phoneNumber);
            if (!preg_match('/^\+?[0-9\s().-]{7,20}$/', $phoneNumber) || $digitCount < 7 || $digitCount > 15) {
                http_response_code(400);
                echo json_encode(['error' => 'Enter a valid phone number with 7 to 15 digits, or leave it empty'], JSON_UNESCAPED_UNICODE);
                exit();
            }
        }
        $locationLength = function_exists('mb_strlen') ? mb_strlen($location, 'UTF-8') : strlen($location);
        if ($location === '' || $locationLength > 120) {
            http_response_code(400);
            echo json_encode(['error' => 'A location of up to 120 characters is required'], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    try {
        $targetStmt = $pdo->prepare("SELECT id, flag_color FROM voters WHERE id = ? OR epic = ? OR sl_no = ? LIMIT 1");
        $targetStmt->execute([$voterId, $voterId, $voterId]);
        $targetVoter = $targetStmt->fetch();
        if (!$targetVoter) {
            http_response_code(404);
            echo json_encode(['error' => 'Voter not found'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        if (in_array(strtolower((string)($targetVoter['flag_color'] ?? 'none')), ['dead', 'duplicate'], true)) {
            http_response_code(409);
            echo json_encode(['error' => 'Out-of-station actions are disabled for Dead and Duplicate voters'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $stmt = $pdo->prepare("
            UPDATE voters
            SET out_of_station = ?, out_of_station_phone = ?, out_of_station_location = ?, expected_to_return = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $isOutOfStation ? 1 : 0,
            $isOutOfStation && $phoneNumber !== '' ? $phoneNumber : null,
            $isOutOfStation ? $location : null,
            $isOutOfStation ? (in_array($expectedToReturn, [true, 1, '1'], true) ? 1 : 0) : null,
            $targetVoter['id']
        ]);

        $voter = $pdo->prepare("SELECT id, out_of_station, out_of_station_phone, out_of_station_location, expected_to_return FROM voters WHERE id = ? LIMIT 1");
        $voter->execute([$targetVoter['id']]);
        $savedVoter = $voter->fetch();
        if (!$savedVoter) {
            http_response_code(404);
            echo json_encode(['error' => 'Voter not found'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        echo json_encode([
            'success' => true,
            'out_of_station' => (int)$savedVoter['out_of_station'],
            'out_of_station_phone' => $savedVoter['out_of_station_phone'],
            'out_of_station_location' => $savedVoter['out_of_station_location'],
            'expected_to_return' => $savedVoter['expected_to_return'] === null ? null : (int)$savedVoter['expected_to_return']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

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
        $targetStmt = $pdo->prepare("SELECT id, flag_color FROM voters WHERE id = ? OR epic = ? OR sl_no = ? LIMIT 1");
        $targetStmt->execute([$voterId, $voterId, $voterId]);
        $targetVoter = $targetStmt->fetch();
        if (!$targetVoter) {
            http_response_code(404);
            echo json_encode(['error' => 'Voter not found'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        if (in_array(strtolower((string)($targetVoter['flag_color'] ?? 'none')), ['dead', 'duplicate'], true)) {
            http_response_code(409);
            echo json_encode(['error' => 'Voting actions are disabled for Dead and Duplicate voters'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($status !== null) {
            $isVoted = ($status === 'VOTED' || $status === 1 || $status === '1') ? 1 : 0;
            $stmt = $pdo->prepare("
                UPDATE voters 
                SET is_voted = ?, 
                    voted_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
                WHERE id = ?
            ");
            $stmt->execute([$isVoted, $isVoted, $targetVoter['id']]);
        } else {
            // Toggle
            $stmt = $pdo->prepare("
                UPDATE voters 
                SET is_voted = CASE WHEN is_voted = 1 THEN 0 ELSE 1 END,
                    voted_at = CASE WHEN is_voted = 0 THEN CURRENT_TIMESTAMP ELSE NULL END
                WHERE id = ?
            ");
            $stmt->execute([$targetVoter['id']]);
        }

        $voter = $pdo->prepare("SELECT * FROM voters WHERE id = ? LIMIT 1");
        $voter->execute([$targetVoter['id']]);
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

// 2. POST /api/voters/flag - Update voter flags
if ($action === 'flag' || (isset($body['action']) && $body['action'] === 'flag')) {
    $voterId = $body['voterId'] ?? $body['id'] ?? null;
    $flagColor = strtolower(trim($body['flagColor'] ?? $body['flag_color'] ?? 'none'));

    $valid = ['none', 'green', 'yellow', 'red', 'dead', 'duplicate'];
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
            $stmt = $pdo->prepare("UPDATE voters SET is_voted = 0, voted_at = NULL WHERE village_id = ? AND LOWER(COALESCE(flag_color, 'none')) NOT IN ('dead', 'duplicate')");
            $stmt->execute([$vId]);
        } else {
            $pdo->exec("UPDATE voters SET is_voted = 0, voted_at = NULL WHERE LOWER(COALESCE(flag_color, 'none')) NOT IN ('dead', 'duplicate')");
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

        $hasFamilyHead = array_key_exists('is_family_head', $body) || array_key_exists('isFamilyHead', $body);
        $hasFamilyEpic = array_key_exists('belongs_to_family', $body) || array_key_exists('belongsToFamily', $body);
        if ($hasFamilyHead !== $hasFamilyEpic) {
            http_response_code(400);
            echo json_encode(['error' => 'Both family head and family EPIC fields are required'], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $sql = "
            UPDATE voters
            SET notes = COALESCE(?, notes),
                name = COALESCE(?, name),
                house_no = COALESCE(?, house_no)
        ";
        $params = [$notes, $name, $houseNo];

        if ($hasFamilyHead) {
            $familyHeadValue = $body['is_family_head'] ?? $body['isFamilyHead'];
            if (!in_array($familyHeadValue, [true, false, 0, 1, '0', '1'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'A valid family head status is required'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $isFamilyHead = in_array($familyHeadValue, [true, 1, '1'], true);
            $belongsToFamily = strtoupper(trim((string)($body['belongs_to_family'] ?? $body['belongsToFamily'] ?? '')));
            if (strlen($belongsToFamily) > 32) {
                http_response_code(400);
                echo json_encode(['error' => 'Family head EPIC must be 32 characters or fewer'], JSON_UNESCAPED_UNICODE);
                exit();
            }

            $voterStmt = $pdo->prepare("SELECT id, epic, village_id, is_family_head FROM voters WHERE id = ? OR epic = ? OR sl_no = ? LIMIT 1");
            $voterStmt->execute([$voterId, $voterId, $voterId]);
            $targetVoter = $voterStmt->fetch();
            if (!$targetVoter) {
                http_response_code(404);
                echo json_encode(['error' => 'Voter not found'], JSON_UNESCAPED_UNICODE);
                exit();
            }

            if ($isFamilyHead) {
                $belongsToFamily = '';
            } elseif ($belongsToFamily !== '') {
                if (strcasecmp($belongsToFamily, (string)$targetVoter['epic']) === 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'A voter cannot belong to their own family head record'], JSON_UNESCAPED_UNICODE);
                    exit();
                }

                $headStmt = $pdo->prepare("
                    SELECT id FROM voters
                    WHERE UPPER(epic) = ? AND village_id = ? AND is_family_head = 1
                    LIMIT 1
                ");
                $headStmt->execute([$belongsToFamily, $targetVoter['village_id']]);
                if (!$headStmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Family head EPIC must belong to a voter marked as family head in the same village'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
            }

            if (!$isFamilyHead && (int)($targetVoter['is_family_head'] ?? 0) === 1) {
                $membersStmt = $pdo->prepare("SELECT COUNT(*) FROM voters WHERE UPPER(belongs_to_family) = UPPER(?) AND village_id = ?");
                $membersStmt->execute([$targetVoter['epic'], $targetVoter['village_id']]);
                if ((int)$membersStmt->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode(['error' => "Reassign this family head's members before removing the head status"], JSON_UNESCAPED_UNICODE);
                    exit();
                }
            }

            $sql .= ", is_family_head = ?, belongs_to_family = ?";
            $params[] = $isFamilyHead ? 1 : 0;
            $params[] = $belongsToFamily !== '' ? $belongsToFamily : null;
        }

        $sql .= " WHERE id = ? OR epic = ? OR sl_no = ?";
        $params[] = $voterId;
        $params[] = $voterId;
        $params[] = $voterId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
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
                        'out_of_station' => 0,
                        'out_of_station_phone' => null,
                        'out_of_station_location' => null,
                        'expected_to_return' => null,
                        'flag_color' => 'none',
                        'flagColor' => 'none',
                        'is_family_head' => 0,
                        'belongs_to_family' => null
                    ];
                }, $fallback, array_keys($fallback));
            }
        }

        // Format for UI with strict language isolation
        $mapped = array_map(function($r) {
            $nameTe = preg_replace('/[A-Za-z0-9]/', '', $r['name_te'] ?? $r['name'] ?? '');
            $nameTe = trim(preg_replace('/\s+/', ' ', $nameTe));

            $nameEn = preg_replace('/[\x{0C00}-\x{0C7F}]/u', '', $r['name_en'] ?? '');
            $nameEn = trim(preg_replace('/\s+/', ' ', $nameEn));

            $relTe = preg_replace('/[A-Za-z0-9]/', '', $r['relation_name'] ?? $r['guardian_name_te'] ?? '');
            $relTe = trim(preg_replace('/\s+/', ' ', $relTe));

            $relEn = preg_replace('/[\x{0C00}-\x{0C7F}]/u', '', $r['relation_name_en'] ?? $r['guardian_name_en'] ?? '');
            $relEn = trim(preg_replace('/\s+/', ' ', $relEn));

            $epic = trim($r['epic'] ?? '');
            $photo = $r['photo_url'] ?? '';
            if (empty($photo)) {
                $photo = "photos/{$epic}.jpg";
            }

            return [
                'id' => (int)$r['id'],
                'sl_no' => (int)$r['sl_no'],
                'slNo' => (int)$r['sl_no'],
                'epic' => $epic,
                'name' => $nameTe,
                'name_te' => $nameTe,
                'name_en' => $nameEn,
                'nameEn' => $nameEn,
                'relation_type' => $r['relation_type'] ?? 'Father',
                'relationType' => $r['relation_type'] ?? 'Father',
                'relation_name' => $relTe,
                'relationName' => $relTe,
                'relation_name_te' => $relTe,
                'relation_name_en' => $relEn,
                'relationNameEn' => $relEn,
                'house_no' => $r['house_no'] ?? '1-1',
                'houseNo' => $r['house_no'] ?? '1-1',
                'age' => (int)$r['age'],
                'gender' => $r['gender'],
                'ward_no' => (string)$r['ward_no'],
                'wardNo' => (string)$r['ward_no'],
                'section_name' => $r['section_name'] ?? '',
                'photo_url' => $photo,
                'photoUrl' => $photo,
                'is_voted' => (int)($r['is_voted'] ?? 0),
                'votingStatus' => ($r['is_voted'] ?? 0) == 1 ? 'VOTED' : 'NOT_VOTED',
                'out_of_station' => (int)($r['out_of_station'] ?? 0),
                'out_of_station_phone' => $r['out_of_station_phone'] ?? null,
                'out_of_station_location' => $r['out_of_station_location'] ?? null,
                'expected_to_return' => isset($r['expected_to_return']) ? (int)$r['expected_to_return'] : null,
                'flag_color' => $r['flag_color'] ?? 'none',
                'flagColor' => $r['flag_color'] ?? 'none',
                'is_family_head' => (int)($r['is_family_head'] ?? 0),
                'isFamilyHead' => (int)($r['is_family_head'] ?? 0),
                'belongs_to_family' => $r['belongs_to_family'] ?? null,
                'belongsToFamily' => $r['belongs_to_family'] ?? null,
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
