<?php
// backend/routes/upload.php - Hierarchical PDF Upload and Village Registration

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';

header("Content-Type: application/json; charset=UTF-8");

$userId = isset($_SERVER['HTTP_X_USER_NUMERIC_ID']) ? (int)$_SERVER['HTTP_X_USER_NUMERIC_ID'] : 1;

function sanitizeSegment($str) {
    if (!$str) return 'general';
    return preg_replace('/[\/\\:*?"<>|\s]/', '_', trim($str)) ?: 'general';
}

$state = trim($_POST['state'] ?? 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)');
$district = trim($_POST['district'] ?? 'శ్రీకాకుళం (Srikakulam)');
$constituency = trim($_POST['constituency'] ?? '4 - పాతపట్నం (Patapatnam)');
$mandal = trim($_POST['mandal'] ?? 'ఎల్ ఎన్ పేట (L.N. Peta)');
$village = trim($_POST['village'] ?? '');
$boothName = trim($_POST['booth_name'] ?? ($village . ' పోలింగ్ కేంద్రం'));
$partNo = trim($_POST['part_no'] ?? '1');

if (!$village) {
    http_response_code(400);
    echo json_encode(['error' => 'గ్రామం పేరు తప్పనిసరి (Village name is required)'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'దయచేసి సరైన PDF ఫైల్‌ను ఎంచుకోండి (Valid PDF file required)'], JSON_UNESCAPED_UNICODE);
    exit();
}

$file = $_FILES['file'];
$origName = basename($file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if ($ext !== 'pdf') {
    http_response_code(400);
    echo json_encode(['error' => 'కేవలం PDF ఫైల్స్ మాత్రమే అనుమతించబడతాయి (Only PDF files allowed)'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Requirement 10: Hierarchical uploads folder: uploads/{state}/{district}/{constituency}/{mandal}/{village}/
$subDir = sprintf(
    "uploads/%s/%s/%s/%s/%s",
    sanitizeSegment($state),
    sanitizeSegment($district),
    sanitizeSegment($constituency),
    sanitizeSegment($mandal),
    sanitizeSegment($village)
);

$targetDir = __DIR__ . '/../../' . $subDir;
if (!file_exists($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$timestamp = time();
$safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
$fileName = $timestamp . '_' . $safeName;
$targetPath = $targetDir . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'ఫైల్ భద్రపరచడంలో లోపం ఏర్పడింది (Failed to move uploaded file)'], JSON_UNESCAPED_UNICODE);
    exit();
}

$db = new DB();
$pdo = $db->getPDO();

try {
    $ins = $pdo->prepare("
        INSERT INTO villages (user_id, village_name, village_name_te, state, district, constituency, mandal, booth_name, part_no, pdf_filename, pdf_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $userId,
        $village,
        $village,
        $state,
        $district,
        $constituency,
        $mandal,
        $boothName,
        $partNo,
        $origName,
        $subDir . '/' . $fileName
    ]);
    $newVillageId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => "గ్రామం '{$village}' ఓటర్ల జాబితా విజయవంతంగా అప్‌లోడ్ చేయబడింది!",
        'village' => [
            'id' => (string)$newVillageId,
            'name' => $village,
            'name_te' => $village,
            'state' => $state,
            'district' => $district,
            'constituency' => $constituency,
            'mandal' => $mandal,
            'booth_name' => $boothName,
            'part_no' => $partNo,
            'fileName' => $origName,
            'isDefault' => false,
            'voterCount' => 0,
            'votedCount' => 0
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit();
}
