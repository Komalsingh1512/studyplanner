<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/runtime.php';
requireLogin();

header('Content-Type: application/json');

$user = getCurrentUser();
$uid = $user['id'];
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

$subject_stmt = mysqli_prepare($conn, "SELECT id, name FROM subjects WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($subject_stmt, "ii", $subject_id, $uid);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject) {
    http_response_code(404);
    echo json_encode(['error' => 'Subject not found']);
    exit();
}

function buildSubjectRoomKey($name) {
    $normalized = strtolower(trim((string) $name));
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);
    return trim($normalized, '-');
}

$room_key = buildSubjectRoomKey($subject['name']);

$ai_history = [];
$ai_result = mysqli_query(
    $conn,
    "SELECT role, message_text, created_at
     FROM subject_chat_messages
     WHERE user_id = $uid AND subject_id = $subject_id
     ORDER BY created_at ASC, id ASC
     LIMIT 80"
);
while ($row = mysqli_fetch_assoc($ai_result)) {
    $ai_history[] = [
        'role' => $row['role'],
        'text' => $row['message_text'],
        'created_at' => $row['created_at']
    ];
}

$room_history = [];
$room_result = mysqli_query(
    $conn,
    "SELECT user_id, user_name_snapshot, message_text, created_at
     FROM subject_room_messages
     WHERE room_key = '" . mysqli_real_escape_string($conn, $room_key) . "'
     ORDER BY created_at ASC, id ASC
     LIMIT 120"
);
while ($row = mysqli_fetch_assoc($room_result)) {
    $room_history[] = [
        'user_id' => (int) $row['user_id'],
        'user_name' => $row['user_name_snapshot'],
        'text' => $row['message_text'],
        'created_at' => $row['created_at']
    ];
}

$token = signRealtimeToken([
    'userId' => (int) $uid,
    'userName' => $user['name'],
    'subjectId' => (int) $subject_id,
    'subjectName' => $subject['name'],
    'roomKey' => $room_key,
    'exp' => time() + 3600
]);

echo json_encode([
    'user' => [
        'id' => (int) $uid,
        'name' => $user['name']
    ],
    'subject' => [
        'id' => (int) $subject['id'],
        'name' => $subject['name'],
        'room_key' => $room_key
    ],
    'ws_url' => getRealtimeWsUrl(),
    'token' => $token,
    'groq_configured' => getGroqApiKey() !== '' && getRealtimeSharedSecret() !== '',
    'ai_history' => $ai_history,
    'room_history' => $room_history
]);
?>
