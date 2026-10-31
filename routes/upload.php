<?php
// backend/routes/upload.php - Hierarchical PDF Upload, Voter Extraction & Database Storage

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../pdf_extractor.php';

header("Content-Type: application/json; charset=UTF-8");

// Handle GET request to verify route is accessible
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode([
        'status' => 'active',
        'endpoint' => '/api/upload',
        'method' => 'POST',
        'message' => 'PDF Upload & Voter Extraction route is active and ready.',
        'cors' => 'enabled'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

$userId = isset($_SERVER['HTTP_X_USER_NUMERIC_ID']) ? (int)$_SERVER['HTTP_X_USER_NUMERIC_ID'] : 1;

function sanitizeSegment($str) {
    if (!$str) return 'general';
    return preg_replace('/[\/\\:*?"<>|\s]/', '_', trim($str)) ?: 'general';
}

$state = trim($_POST['state'] ?? 'ఆంధ్రప్రదేశ్ (Andhra Pradesh)');
$district = trim($_POST['district'] ?? 'శ్రీకాకుళం (Srikakulam)');
$constituency = trim($_POST['constituency'] ?? '4 - పాతపట్నం (Patapatnam)');
$mandal = trim($_POST['mandal'] ?? 'ఎల్ ఎన్ పేట (L.N. Peta)');
$village = trim($_POST['village'] ?? $_POST['village_name'] ?? $_POST['name'] ?? '');
$boothName = trim($_POST['booth_name'] ?? ($village ? $village . ' పోలింగ్ కేంద్రం' : 'పోలింగ్ కేంద్రం'));
$partNo = trim($_POST['part_no'] ?? '1');

if (!$village) {
    http_response_code(400);
    echo json_encode(['error' => 'గ్రామం పేరు తప్పనిసరి (Village name is required)'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Find uploaded file from any common field name
$file = null;
if (!empty($_FILES)) {
    $file = $_FILES['file'] ?? $_FILES['pdf'] ?? $_FILES['pdf_file'] ?? $_FILES['voter_list'] ?? reset($_FILES);
}

if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errCode = $file['error'] ?? 'NO_FILE';
    echo json_encode([
        'error' => 'దయచేసి సరైన PDF ఫైల్‌ను ఎంచుకోండి (Valid PDF file required. Code: ' . $errCode . ')'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$origName = basename($file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if ($ext !== 'pdf') {
    http_response_code(400);
    echo json_encode(['error' => 'కేవలం PDF ఫైల్స్ మాత్రమే అనుమతించబడతాయి (Only PDF files allowed)'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Hierarchical upload directory inside project root
$projectRoot = dirname(__DIR__);
$relativeSubDir = sprintf(
    "uploads/%s/%s/%s/%s/%s",
    sanitizeSegment($state),
    sanitizeSegment($district),
    sanitizeSegment($constituency),
    sanitizeSegment($mandal),
    sanitizeSegment($village)
);

$targetDir = $projectRoot . '/' . $relativeSubDir;
if (!file_exists($targetDir)) {
    @mkdir($targetDir, 0777, true);
}

$timestamp = time();
$safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
$fileName = $timestamp . '_' . $safeName;
$targetPath = $targetDir . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    if (!copy($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'ఫైల్ భద్రపరచడంలో లోపం ఏర్పడింది (Failed to save uploaded file)'], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

try {
    $db = new DB();
    $pdo = $db->getPDO();

    // 1. Register Village in database
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
        $relativeSubDir . '/' . $fileName
    ]);
    $newVillageId = (int)$pdo->lastInsertId();

    // 2. Extract Voters from PDF (Telugu & English names, EPIC, relation, house no, age, gender)
    $voters = PdfExtractor::extractVoters($targetPath, $newVillageId, $userId, $village);
    $insertedCount = 0;

    if (!empty($voters)) {
        $voterStmt = $pdo->prepare("
            INSERT INTO voters 
            (village_id, user_id, sl_no, epic, name, name_en, relation_type, relation_name, relation_name_en, house_no, age, gender, ward_no, section_name, is_voted, flag_color)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'none')
        ");

        foreach ($voters as $v) {
            $voterStmt->execute([
                $newVillageId,
                $userId,
                $v['sl_no'],
                $v['epic'],
                $v['name'],
                $v['name_en'],
                $v['relation_type'],
                $v['relation_name'],
                $v['relation_name_en'],
                $v['house_no'],
                $v['age'],
                $v['gender'],
                $v['ward_no'],
                $v['section_name']
            ]);
            $insertedCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "గ్రామం '{$village}' ఓటర్ల జాబితా విజయవంతంగా అప్‌లోడ్ చేయబడింది! {$insertedCount} మంది ఓటర్లు నమోదు చేయబడ్డారు.",
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
            'filePath' => $relativeSubDir . '/' . $fileName,
            'isDefault' => false,
            'voterCount' => $insertedCount,
            'votedCount' => 0
        ],
        'extractedCount' => $insertedCount
    ], JSON_UNESCAPED_UNICODE);
    exit();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'డేటాబేస్ లోపం (Database error): ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
