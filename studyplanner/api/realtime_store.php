<?php
require_once '../config/db.php';
require_once '../config/runtime.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');

if (!verifyRealtimeInternalRequest($rawBody)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$payload = json_decode($rawBody, true);
if (!is_array($payload) || empty($payload['type'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid payload']);
    exit();
}

$type = $payload['type'];

if ($type === 'ai_user' || $type === 'ai_assistant') {
    $user_id = (int) ($payload['user_id'] ?? 0);
    $subject_id = (int) ($payload['subject_id'] ?? 0);
    $message_text = trim((string) ($payload['message_text'] ?? ''));
    $role = $type === 'ai_user' ? 'user' : 'assistant';

    if ($user_id < 1 || $subject_id < 1 || $message_text === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Missing AI message fields']);
        exit();
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO subject_chat_messages (user_id, subject_id, role, message_text) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iiss", $user_id, $subject_id, $role, $message_text);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true]);
    exit();
}

if ($type === 'room_message') {
    $user_id = (int) ($payload['user_id'] ?? 0);
    $subject_id = (int) ($payload['subject_id'] ?? 0);
    $room_key = trim((string) ($payload['room_key'] ?? ''));
    $subject_name = trim((string) ($payload['subject_name'] ?? ''));
    $user_name = trim((string) ($payload['user_name'] ?? ''));
    $message_text = trim((string) ($payload['message_text'] ?? ''));

    if ($user_id < 1 || $subject_id < 1 || $room_key === '' || $subject_name === '' || $user_name === '' || $message_text === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Missing room message fields']);
        exit();
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO subject_room_messages (user_id, subject_id, room_key, subject_name_snapshot, user_name_snapshot, message_text) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iissss", $user_id, $subject_id, $room_key, $subject_name, $user_name, $message_text);
    mysqli_stmt_execute($stmt);
    echo json_encode(['ok' => true]);
    exit();
}

http_response_code(422);
echo json_encode(['error' => 'Unsupported type']);
?>
