<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/runtime.php';
requireLogin();

header('Content-Type: application/json');

$user = getCurrentUser();
$uid = $user['id'];
$subject_id = isset($_POST['subject_id']) ? (int) $_POST['subject_id'] : 0;

if ($subject_id < 1) {
    http_response_code(422);
    echo json_encode(['error' => 'Missing subject id']);
    exit();
}

$subject_stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($subject_stmt, "ii", $subject_id, $uid);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject) {
    http_response_code(404);
    echo json_encode(['error' => 'Subject not found']);
    exit();
}

function extractGroqSubtopicsFromTextApi($text) {
    $clean = trim((string) $text);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/```$/', '', $clean);

    $decoded = json_decode($clean, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $lines = preg_split('/\r\n|\r|\n/', $clean);
    $items = [];
    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('/^[-*\d\.\)\s]+/', '', $line);
        if ($line !== '') {
            $items[] = $line;
        }
    }
    return $items;
}

$apiKey = getGroqApiKey();
if ($apiKey === '' || !function_exists('curl_init')) {
    http_response_code(422);
    echo json_encode(['error' => 'Groq is not configured on this project']);
    exit();
}

$existingTitles = [];
$existing_result = mysqli_query(
    $conn,
    "SELECT title FROM subject_subtopics WHERE user_id = $uid AND subject_id = $subject_id"
);
while ($row = mysqli_fetch_assoc($existing_result)) {
    $existingTitles[strtolower(trim($row['title']))] = true;
}

$configuredCount = isset($subject['ai_subtopic_target']) ? (int) $subject['ai_subtopic_target'] : 5;
$count = max(1, min(25, $configuredCount));
$syllabus = trim((string) ($subject['subject_syllabus'] ?? ''));
$syllabusFileName = trim((string) ($subject['syllabus_file_name'] ?? ''));
$prompt = "Generate {$count} practical study subtopics for the subject \"{$subject['name']}\".\n"
    . "Difficulty: {$subject['difficulty']}\n"
    . "Total topics in syllabus tracker: {$subject['total_topics']}\n"
    . "You must return exactly {$count} subtopics and never more than {$count}.\n";

if ($syllabus !== '') {
    $prompt .= "Use this syllabus as the primary source when deciding the subtopics:\n{$syllabus}\n\n";
} else {
    $prompt .= "No detailed syllabus was provided, so infer a balanced structure from the subject name.\n\n";
}

$prompt .= "Return ONLY a JSON array of concise subtopic names.\n"
    . "No explanation. No markdown. No numbering.";

$payload = json_encode([
    'model' => getGroqModel(),
    'temperature' => 0.3,
    'max_tokens' => 600,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You generate clean study subtopic lists for students. Output must be valid JSON only.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ]
    ]
]);

$ch = curl_init(getGroqApiUrl());
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode(['error' => $curlError !== '' ? $curlError : 'Groq request failed']);
    exit();
}

$decoded = json_decode($response, true);
$text = $decoded['choices'][0]['message']['content'] ?? '';
$items = extractGroqSubtopicsFromTextApi($text);

$normalized = [];
$seen = [];
foreach ($items as $item) {
    $item = trim(strip_tags((string) $item));
    if ($item === '') {
        continue;
    }
    $key = strtolower($item);
    if (isset($seen[$key]) || isset($existingTitles[$key])) {
        continue;
    }
    $seen[$key] = true;
    $normalized[] = $item;
}

$normalized = array_slice($normalized, 0, $count);

if (empty($normalized)) {
    echo json_encode(['ok' => true, 'created' => 0, 'message' => 'No new subtopics were generated']);
    exit();
}

$created = 0;
$stmt = mysqli_prepare($conn, "INSERT INTO subject_subtopics (user_id, subject_id, title) VALUES (?, ?, ?)");
foreach ($normalized as $subtopicTitle) {
    mysqli_stmt_bind_param($stmt, "iis", $uid, $subject_id, $subtopicTitle);
    if (mysqli_stmt_execute($stmt)) {
        $created++;
    }
}

echo json_encode([
    'ok' => true,
    'created' => $created,
    'message' => $created > 0
        ? $created . ' AI subtopics generated from the saved syllabus' . ($syllabusFileName !== '' ? ' file' : '') . '.'
        : 'No new subtopics were created'
]);
?>
