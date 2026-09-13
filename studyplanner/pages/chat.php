<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/runtime.php';
requireLogin();

$user = getCurrentUser();
$uid = $user['id'];
$subjects = [];
$subjects_result = mysqli_query($conn, "SELECT * FROM subjects WHERE user_id = $uid ORDER BY created_at DESC, id DESC");
while ($row = mysqli_fetch_assoc($subjects_result)) {
    $subjects[] = $row;
}

$selected_subject = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : 0;
if ($selected_subject === 0 && !empty($subjects)) {
    $selected_subject = (int) $subjects[0]['id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realtime Chat - Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260504-1835">
    <script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
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
            <div class="eyebrow">
                <span class="eyebrow-dot"></span>
                Realtime Chat 🔒
            </div>
            <h1 class="page-title">Study chat with AI and students</h1>
            <p class="page-subtitle">Use a secure real-time connection for AI tutoring and a live student room for each subject.</p>
        </div>
    </div>

    <?php if (empty($subjects)): ?>
        <div class="empty-state">
            <div class="empty-state-inner">
                <div class="empty-state-icon"><i class="bi bi-chat-square-dots"></i></div>
                <h3>No subjects yet</h3>
                <p>Add a subject first, then open chat for that subject.</p>
                <a href="subjects.php" class="btn btn-primary mt-3">Go to Subjects</a>
            </div>
        </div>
    <?php else: ?>
        <div class="chat-layout">
            <aside class="sp-card fade-in-up chat-side-panel">
                <div class="sp-card-header">
                    <h6 class="sp-card-title">Chat Controls ⚙️</h6>
                </div>
                <div class="sp-card-body">
                    <div class="mb-3">
                        <label class="form-label">Subject</label>
                        <select id="subjectSelect" class="form-select">
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $selected_subject === (int) $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="chat-status-panel">
                        <div class="chat-status-row">
                            <span class="section-note">Connection</span>
                            <span id="connectionBadge" class="pill pill-warning">Connecting</span>
                        </div>
                        <div class="chat-status-row">
                            <span class="section-note">AI Engine</span>
                            <span class="soft-badge">Active</span>
                        </div>
                        <div class="chat-status-row">
                            <span class="section-note">Transport</span>
                            <span class="soft-badge">WebSocket</span>
                        </div>
                    </div>

                    <div class="subject-tools mt-3">
                        <a id="workspaceLink" href="subject_workspace.php?subject_id=<?= $selected_subject ?>" class="subject-tool-card">
                            <span class="subject-tool-icon"><i class="bi bi-diagram-3"></i></span>
                            <span>Workspace</span>
                        </a>
                        <a id="notesLink" href="notes.php?subject_id=<?= $selected_subject ?>" class="subject-tool-card">
                            <span class="subject-tool-icon"><i class="bi bi-journal-text"></i></span>
                            <span>Notes</span>
                        </a>
                    </div>
                </div>
            </aside>

            <section class="sp-card fade-in-up chat-main-panel">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title" id="chatPanelTitle">Realtime Chat 💬</h6>
                        <p class="sp-card-subtitle" id="chatPanelSubtitle">Choose how you want to study.</p>
                    </div>
                    <div class="chat-tab-row">
                        <button class="chat-mode-tab active" data-mode="ai">AI Tutor 🤖</button>
                        <button class="chat-mode-tab" data-mode="room">Student Room 👥</button>
                    </div>
                </div>
                <div class="sp-card-body">
                    <div id="chatError" class="alert alert-danger d-none"></div>
                    <div id="chatThread" class="chat-thread chat-thread-large"></div>
                    <form id="chatForm" class="chat-compose-form">
                        <textarea id="chatInput" class="form-control chat-compose-textarea" placeholder="Type your message..."></textarea>
                        <button type="submit" class="btn btn-primary">Send 🚀</button>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>
</div>

<?php if (!empty($subjects)): ?>
<script>
const bootstrapUrl = '../api/chat_bootstrap.php';
const subjectSelect = document.getElementById('subjectSelect');
const chatThread = document.getElementById('chatThread');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatInput');
const connectionBadge = document.getElementById('connectionBadge');
const chatError = document.getElementById('chatError');
const chatPanelTitle = document.getElementById('chatPanelTitle');
const chatPanelSubtitle = document.getElementById('chatPanelSubtitle');
const workspaceLink = document.getElementById('workspaceLink');
const notesLink = document.getElementById('notesLink');
const modeTabs = Array.from(document.querySelectorAll('.chat-mode-tab'));

let socket = null;
let activeMode = 'ai';
let bootstrapData = null;
let aiMessages = [];
let roomMessages = [];

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

if (window.marked) {
    marked.setOptions({ breaks: true });
}

function renderPlainText(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
}

// AI tutor replies are Markdown (headings, tables, lists). Render them with
// marked.js, same as the AI Study Plan page. User-typed text (both the "You"
// bubbles in AI Tutor and every Student Room message) is never parsed as
// Markdown and stays plain/escaped, since it comes from other people.
function renderAiText(text) {
    if (!window.marked) {
        return renderPlainText(text);
    }
    return `<div class="ai-output">${marked.parse(String(text || ''))}</div>`;
}

function showError(message) {
    chatError.textContent = message;
    chatError.classList.remove('d-none');
}

function clearError() {
    chatError.classList.add('d-none');
    chatError.textContent = '';
}

function setConnectionState(label, type) {
    connectionBadge.className = 'pill';
    connectionBadge.classList.add(type === 'ok' ? 'pill-success' : (type === 'error' ? 'pill-danger' : 'pill-warning'));
    connectionBadge.textContent = label;
}

function renderMessages() {
    const messages = activeMode === 'ai' ? aiMessages : roomMessages;

    if (!messages.length) {
        chatThread.innerHTML = `
            <div class="empty-state" style="min-height: 300px;">
                <div class="empty-state-inner">
                    <div class="empty-state-icon"><i class="bi bi-chat-square-heart"></i></div>
                    <h3>No messages yet</h3>
                    <p>${activeMode === 'ai' ? 'Ask the AI tutor your first question.' : 'Start the first student room message for this subject.'}</p>
                </div>
            </div>
        `;
        return;
    }

    chatThread.innerHTML = messages.map((message) => {
        if (activeMode === 'ai') {
            const isAssistant = message.role === 'assistant';
            const bubbleClass = isAssistant ? 'chat-bubble-ai' : 'chat-bubble-user';
            const label = isAssistant ? 'AI Tutor' : 'You';
            const body = isAssistant ? renderAiText(message.text) : renderPlainText(message.text);
            return `
                <div class="chat-bubble ${bubbleClass}">
                    <div class="chat-meta">${escapeHtml(label)}</div>
                    <div>${body}</div>
                </div>
            `;
        }

        const bubbleClass = Number(message.user_id) === Number(bootstrapData.user.id) ? 'chat-bubble-user' : 'chat-bubble-ai';
        const label = Number(message.user_id) === Number(bootstrapData.user.id) ? 'You' : message.user_name;
        return `
            <div class="chat-bubble ${bubbleClass}">
                <div class="chat-meta">${escapeHtml(label)}</div>
                <div>${renderPlainText(message.text)}</div>
            </div>
        `;
    }).join('');

    chatThread.scrollTop = chatThread.scrollHeight;
}

function updateTabUi() {
    modeTabs.forEach((tab) => {
        tab.classList.toggle('active', tab.dataset.mode === activeMode);
    });

    if (activeMode === 'ai') {
        chatPanelTitle.textContent = 'AI Tutor 🤖';
        chatPanelSubtitle.textContent = 'Private subject-wise conversation with your AI tutor.';
        chatInput.placeholder = 'Ask the AI tutor about this subject...';
    } else {
        chatPanelTitle.textContent = 'Student Room 👥';
        chatPanelSubtitle.textContent = 'Live messages with other students in the same subject room.';
        chatInput.placeholder = 'Write a message to the student room...';
    }

    renderMessages();
}

function closeSocket() {
    if (socket) {
        socket.onclose = null;
        socket.close();
        socket = null;
    }
}

function resolveWsUrl(configuredUrl) {
    try {
        const url = new URL(configuredUrl);
        const pageHost = window.location.hostname;
        const isLoopbackConfigured = url.hostname === '127.0.0.1' || url.hostname === 'localhost';
        const isLoopbackPage = pageHost === '127.0.0.1' || pageHost === 'localhost';

        // If the configured host is a loopback address but the page itself was
        // opened from a different machine/IP, ws://127.0.0.1 would point back
        // at that other machine's own (nonexistent) server. Swap in the page's
        // actual hostname so the browser reaches the same host the page came from.
        if (isLoopbackConfigured && !isLoopbackPage) {
            url.hostname = pageHost;
        }

        // Browsers block plain ws:// connections from an https:// page
        // (mixed content). If the site is served over https, upgrade to wss://.
        if (window.location.protocol === 'https:' && url.protocol === 'ws:') {
            url.protocol = 'wss:';
        }

        return url.toString();
    } catch (error) {
        return configuredUrl;
    }
}

function connectSocket() {
    closeSocket();
    clearError();
    setConnectionState('Connecting', 'warn');

    const wsUrl = resolveWsUrl(bootstrapData.ws_url);
    socket = new WebSocket(`${wsUrl}?token=${encodeURIComponent(bootstrapData.token)}`);

    socket.onopen = () => {
        setConnectionState('Connected', 'ok');
    };

    socket.onclose = () => {
        setConnectionState('Disconnected', 'error');
    };

    socket.onerror = () => {
        setConnectionState('Error', 'error');
        showError('Realtime connection failed. Make sure the WebSocket server (node realtime/chat-server.js) is running and reachable at ' + wsUrl + '.');
    };

    socket.onmessage = (event) => {
        const payload = JSON.parse(event.data);

        if (payload.type === 'error') {
            showError(payload.message || 'Realtime error occurred.');
            return;
        }

        if (payload.type === 'ai_message') {
            aiMessages.push({
                role: payload.role || 'assistant',
                text: payload.text || ''
            });
            if (activeMode === 'ai') {
                renderMessages();
            }
            return;
        }

        if (payload.type === 'room_message') {
            roomMessages.push({
                user_id: payload.user_id,
                user_name: payload.user_name,
                text: payload.text || ''
            });
            if (activeMode === 'room') {
                renderMessages();
            }
        }
    };
}

async function loadBootstrap(subjectId) {
    clearError();
    const response = await fetch(`${bootstrapUrl}?subject_id=${encodeURIComponent(subjectId)}`, { credentials: 'same-origin' });
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.error || 'Unable to load chat.');
    }

    bootstrapData = payload;
    aiMessages = payload.ai_history || [];
    roomMessages = payload.room_history || [];
    workspaceLink.href = `subject_workspace.php?subject_id=${payload.subject.id}`;
    notesLink.href = `notes.php?subject_id=${payload.subject.id}`;

    if (!payload.groq_configured) {
        showError('AI chat is not configured yet. Update config/runtime.json or environment variables.');
    }

    updateTabUi();
    connectSocket();
}

subjectSelect.addEventListener('change', async () => {
    const subjectId = subjectSelect.value;
    history.replaceState({}, '', `chat.php?subject_id=${encodeURIComponent(subjectId)}`);
    await loadBootstrap(subjectId);
});

modeTabs.forEach((tab) => {
    tab.addEventListener('click', () => {
        activeMode = tab.dataset.mode;
        updateTabUi();
    });
});

chatForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const text = chatInput.value.trim();
    if (!text || !socket || socket.readyState !== WebSocket.OPEN) {
        return;
    }

    if (activeMode === 'ai') {
        aiMessages.push({ role: 'user', text });
        renderMessages();
        socket.send(JSON.stringify({
            type: 'ai_message',
            message: text,
            history: aiMessages.slice(-12)
        }));
    } else {
        socket.send(JSON.stringify({
            type: 'student_message',
            message: text
        }));
    }

    chatInput.value = '';
});

loadBootstrap(subjectSelect.value).catch((error) => {
    setConnectionState('Error', 'error');
    showError(error.message || 'Unable to start chat.');
});
</script>
<?php endif; ?>
</body>
</html>
