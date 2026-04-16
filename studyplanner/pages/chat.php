<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/ai.php';
requireLogin();

$user = getCurrentUser();
$uid = $user['id'];
$subjects = [];
$subjects_result = mysqli_query($conn, "SELECT * FROM subjects WHERE user_id = $uid ORDER BY name ASC");
while ($row = mysqli_fetch_assoc($subjects_result)) {
    $subjects[] = $row;
}

$selected_subject = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$error = '';
$success = '';
$api_key_value = getAiApiKey();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ask') {
    $selected_subject = (int)$_POST['subject_id'];
    $question = trim($_POST['question']);
    $submitted_api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';

    if ($submitted_api_key !== '') {
        $_SESSION['anthropic_api_key'] = $submitted_api_key;
        $api_key_value = getAiApiKey();
    }

    $subject_data = null;
    foreach ($subjects as $subject) {
        if ((int)$subject['id'] === $selected_subject) {
            $subject_data = $subject;
            break;
        }
    }

    if (!$subject_data) {
        $error = "Please select a valid subject!";
    } elseif ($question === '') {
        $error = "Please enter your question!";
    } elseif (empty($api_key_value)) {
        $error = "Add your Anthropic API key first.";
    } elseif (!function_exists('curl_init')) {
        $error = "cURL is not enabled in PHP.";
    } else {
        $history = [];
        $history_query = mysqli_query(
            $conn,
            "SELECT role, message_text FROM subject_chat_messages
             WHERE user_id = $uid AND subject_id = $selected_subject
             ORDER BY created_at DESC, id DESC
             LIMIT 8"
        );
        while ($row = mysqli_fetch_assoc($history_query)) {
            $history[] = $row;
        }
        $history = array_reverse($history);

        $escaped_question = mysqli_real_escape_string($conn, $question);
        mysqli_query($conn, "INSERT INTO subject_chat_messages (user_id, subject_id, role, message_text) VALUES ($uid, $selected_subject, 'user', '$escaped_question')");

        $completion = $subject_data['total_topics'] > 0
            ? round(($subject_data['completed_topics'] / $subject_data['total_topics']) * 100)
            : 0;

        $messages_payload = [[
            'role' => 'user',
            'content' => "You are a subject tutor for {$subject_data['name']}. Student name: {$user['name']}. Completion: {$completion}%. Difficulty: {$subject_data['difficulty']}. Answer in clear Hinglish, be exam-focused, and keep explanations practical."
        ]];

        foreach ($history as $item) {
            $messages_payload[] = [
                'role' => $item['role'],
                'content' => $item['message_text']
            ];
        }

        $messages_payload[] = [
            'role' => 'user',
            'content' => $question
        ];

        $payload = json_encode([
            'model' => AI_MODEL,
            'max_tokens' => 1400,
            'messages' => $messages_payload
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $api_key_value,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $error = "Request failed: " . $curl_error;
        } else {
            $data = json_decode($response, true);
            if ($http_code === 200 && isset($data['content'][0]['text'])) {
                $answer = $data['content'][0]['text'];
                $stmt = mysqli_prepare($conn, "INSERT INTO subject_chat_messages (user_id, subject_id, role, message_text) VALUES (?, ?, 'assistant', ?)");
                mysqli_stmt_bind_param($stmt, "iis", $uid, $selected_subject, $answer);
                mysqli_stmt_execute($stmt);
                $success = "Reply generated.";
            } elseif (isset($data['error']['message'])) {
                $error = $data['error']['message'];
            } else {
                $error = "Unable to get a response right now. HTTP $http_code";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear') {
    $selected_subject = (int)$_POST['subject_id'];
    mysqli_query($conn, "DELETE FROM subject_chat_messages WHERE user_id = $uid AND subject_id = $selected_subject");
    $success = "Chat cleared.";
}

$messages = [];
if ($selected_subject > 0) {
    $messages_result = mysqli_query(
        $conn,
        "SELECT m.*, s.name AS subject_name
         FROM subject_chat_messages m
         JOIN subjects s ON s.id = m.subject_id
         WHERE m.user_id = $uid AND m.subject_id = $selected_subject
         ORDER BY m.created_at ASC, m.id ASC"
    );
    while ($row = mysqli_fetch_assoc($messages_result)) {
        $messages[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260406-1433">
</head>
<body>
<div class="app-shell">
<nav class="navbar sp-navbar">
    <div class="container-fluid nav-shell">
        <a class="navbar-brand sp-brand" href="dashboard.php"><span class="brand-logo">SP</span> Study Planner</a>
        <div class="ms-auto nav-actions">
            <span class="nav-user-badge">
                <i class="bi bi-person-circle"></i>
                <span><?= htmlspecialchars($user['name']) ?></span>
            </span>
            <a href="subjects.php" class="btn btn-sm btn-outline-secondary">Subjects</a>
            <a href="notes.php" class="btn btn-sm btn-outline-secondary">Notes</a>
        </div>
    </div>
</nav>

<div class="page-wrap">
    <div class="page-header fade-in-up">
        <div>
            <div class="eyebrow"><span class="eyebrow-dot"></span> Chat 💬</div>
            <h1 class="page-title">Ask subject doubts instantly</h1>
            <p class="page-subtitle">Keep a smart subject-wise AI conversation for concepts, revision, and exam prep 🤖</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header"><h6 class="sp-card-title">Ask Anything 🤔</h6></div>
                <div class="sp-card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                    <?php if (empty($subjects)): ?>
                        <div class="alert alert-warning">Please <a href="subjects.php">add a subject</a> first.</div>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="ask">
                        <div class="mb-3">
                            <label class="form-label">Anthropic API Key</label>
                            <input type="password" name="api_key" class="form-control" placeholder="sk-ant-..." value="<?= htmlspecialchars($api_key_value) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" <?= $selected_subject === (int)$subject['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($subject['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Question *</label>
                            <textarea name="question" class="form-control notes-textarea compact-textarea" placeholder="Ask a doubt, concept question, or exam question" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Send 🚀</button>
                    </form>
                    <?php if ($selected_subject > 0): ?>
                    <form method="POST" class="mt-3">
                        <input type="hidden" name="action" value="clear">
                        <input type="hidden" name="subject_id" value="<?= $selected_subject ?>">
                        <button type="submit" class="btn btn-outline-danger w-100" onclick="return confirm('Clear this subject chat?');">Clear Chat 🧹</button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <h6 class="sp-card-title">Conversation Thread 💭</h6>
                </div>
                <div class="sp-card-body">
                    <?php if ($selected_subject === 0): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-chat-dots"></i></div>
                                <h3>Select a subject first 📘</h3>
                                <p>Then ask questions and keep a focused study chat.</p>
                            </div>
                        </div>
                    <?php elseif (empty($messages)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-chat-square-heart"></i></div>
                                <h3>No chat yet 💬</h3>
                                <p>Ask your first subject doubt to start the conversation.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="chat-thread">
                            <?php foreach ($messages as $message): ?>
                                <div class="chat-bubble <?= $message['role'] === 'assistant' ? 'chat-bubble-ai' : 'chat-bubble-user' ?>">
                                    <div class="chat-meta"><?= $message['role'] === 'assistant' ? 'AI Tutor' : 'You' ?></div>
                                    <div><?= nl2br(htmlspecialchars($message['message_text'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</body>
</html>
