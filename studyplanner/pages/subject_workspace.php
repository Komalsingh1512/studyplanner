<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/syllabus_upload.php';
requireLogin();

$user = getCurrentUser();
$uid = (int) $user['id'];
$subject_id = isset($_GET['subject_id']) ? (int) $_GET['subject_id'] : 0;
$error = '';
$success = '';
$subtopic_visible_limit = 5;

$subject_stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($subject_stmt, "ii", $subject_id, $uid);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject) {
    header("Location: subjects.php");
    exit();
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') {
        $success = "Subtopic deleted successfully.";
    } elseif ($_GET['msg'] === 'subject_created') {
        $success = "Subject created successfully. You can now manage subtopics and notes here.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_subtopic') {
    $title = sanitize($_POST['subtopic_title']);

    if ($title === '') {
        $error = "Please enter a subtopic title.";
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO subject_subtopics (user_id, subject_id, title) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iis", $uid, $subject_id, $title);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Subtopic added successfully.";
        } else {
            $error = "Unable to add subtopic right now.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_subtopic') {
    $subtopic_id = (int) $_POST['subtopic_id'];
    mysqli_query(
        $conn,
        "UPDATE subject_subtopics
         SET is_completed = NOT is_completed
         WHERE id = $subtopic_id AND subject_id = $subject_id AND user_id = $uid"
    );
    $success = "Subtopic updated successfully.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_subtopic_note') {
    $subtopic_id = (int) $_POST['subtopic_id'];
    $note_content = trim($_POST['subtopic_note']);
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE subject_subtopics
         SET note_content = ?
         WHERE id = ? AND subject_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "siii", $note_content, $subtopic_id, $subject_id, $uid);
    if (mysqli_stmt_execute($stmt)) {
        $success = "Subtopic note saved successfully.";
    } else {
        $error = "Unable to save subtopic note right now.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_ai_settings') {
    $new_target = isset($_POST['ai_subtopic_target']) ? (int) $_POST['ai_subtopic_target'] : 5;
    $new_manual_syllabus = trim($_POST['subject_syllabus'] ?? '');
    $new_syllabus = $new_manual_syllabus;
    $new_file_name = $subject['syllabus_file_name'] ?? null;
    $new_file_path = $subject['syllabus_file_path'] ?? null;
    $new_source_type = $new_manual_syllabus !== '' ? 'text' : (($subject['syllabus_source_type'] ?? 'none') === 'none' ? 'none' : ($subject['syllabus_source_type'] ?? 'text'));

    if ($new_target < 1 || $new_target > 25) {
        $error = "AI subtopic count must be between 1 and 25.";
    } else {
        $uploadResult = processUploadedSyllabusFile($_FILES['syllabus_file'] ?? [], $uid);
        if (isset($uploadResult['error'])) {
            $error = $uploadResult['error'];
        } elseif (!empty($uploadResult['uploaded'])) {
            $new_syllabus = trim($new_manual_syllabus . "\n\n" . $uploadResult['text']);
            $new_file_name = $uploadResult['file_name'];
            $new_file_path = $uploadResult['file_path'];
            $new_source_type = $new_manual_syllabus !== '' ? 'text+file' : 'file';
        }
    }

    if ($error === '') {
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE subjects
             SET ai_subtopic_target = ?, subject_syllabus = ?, syllabus_file_name = ?, syllabus_file_path = ?, syllabus_source_type = ?
             WHERE id = ? AND user_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "issssii", $new_target, $new_syllabus, $new_file_name, $new_file_path, $new_source_type, $subject_id, $uid);
        if (mysqli_stmt_execute($stmt)) {
            $subject['ai_subtopic_target'] = $new_target;
            $subject['subject_syllabus'] = $new_syllabus;
            $subject['syllabus_file_name'] = $new_file_name;
            $subject['syllabus_file_path'] = $new_file_path;
            $subject['syllabus_source_type'] = $new_source_type;
            $success = "AI settings updated successfully.";
        } else {
            $error = "Unable to update AI settings right now.";
        }
    }
}

if (isset($_GET['delete_subtopic'])) {
    $subtopic_id = (int) $_GET['delete_subtopic'];
    mysqli_query(
        $conn,
        "DELETE FROM subject_subtopics
         WHERE id = $subtopic_id AND subject_id = $subject_id AND user_id = $uid"
    );
    header("Location: subject_workspace.php?subject_id=$subject_id&msg=deleted");
    exit();
}

$subtopics = [];
$subtopics_result = mysqli_query(
    $conn,
    "SELECT * FROM subject_subtopics
     WHERE user_id = $uid AND subject_id = $subject_id
     ORDER BY is_completed ASC, created_at DESC, id DESC"
);
while ($row = mysqli_fetch_assoc($subtopics_result)) {
    $subtopics[] = $row;
}

$completed_subtopics = 0;
foreach ($subtopics as $subtopic) {
    if ((int) $subtopic['is_completed'] === 1) {
        $completed_subtopics++;
    }
}

$subject_pct = (int) $subject['total_topics'] > 0
    ? round(((int) $subject['completed_topics'] / (int) $subject['total_topics']) * 100)
    : 0;
$days_left = !empty($subject['exam_date'])
    ? (int) floor((strtotime($subject['exam_date']) - time()) / 86400)
    : null;
$pending_subtopics = count($subtopics) - $completed_subtopics;
$auto_generate = isset($_GET['auto_generate']) && $_GET['auto_generate'] === '1';
$syllabus_preview = trim((string) ($subject['subject_syllabus'] ?? ''));
$syllabus_length = function_exists('mb_strlen') ? mb_strlen($syllabus_preview) : strlen($syllabus_preview);
$syllabus_preview_short = $syllabus_length > 220
    ? (function_exists('mb_substr') ? mb_substr($syllabus_preview, 0, 220) : substr($syllabus_preview, 0, 220)) . '...'
    : $syllabus_preview;
$ai_target = isset($subject['ai_subtopic_target']) ? (int) $subject['ai_subtopic_target'] : 5;
$syllabus_file_name = trim((string) ($subject['syllabus_file_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Workspace - Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260421-2359">
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
            <a href="notes.php?subject_id=<?= $subject_id ?>" class="btn btn-sm btn-outline-secondary">Notes</a>
            <a href="chat.php?subject_id=<?= $subject_id ?>" class="btn btn-sm btn-outline-secondary">Chat</a>
        </div>
    </div>
</nav>

<div class="page-wrap">
    <div class="page-header fade-in-up">
        <div>
            <div class="eyebrow">
                <span class="eyebrow-dot"></span>
                Workspace
            </div>
            <h1 class="page-title"><?= htmlspecialchars($subject['name']) ?> Workspace</h1>
            <p class="page-subtitle">Add subtopics manually, generate them from your syllabus, and keep long lists compact with a reveal button.</p>
        </div>
        <a href="subjects.php" class="btn btn-outline-secondary">Back to Subjects</a>
    </div>

    <section class="hero-panel fade-in-up mb-4">
        <div class="hero-grid">
            <div class="hero-copy">
                <div class="hero-chip-row">
                    <div class="hero-chip"><i class="bi bi-graph-up-arrow"></i> <?= $subject_pct ?>% subject progress</div>
                    <div class="hero-chip"><i class="bi bi-diagram-3"></i> <?= $completed_subtopics ?>/<?= count($subtopics) ?> subtopics done</div>
                    <div class="hero-chip"><i class="bi bi-stars"></i> AI target <?= $ai_target ?> subtopics</div>
                    <?php if ($days_left !== null): ?>
                        <div class="hero-chip"><i class="bi bi-calendar-event"></i> <?= $days_left >= 0 ? $days_left . ' days left' : 'Exam date passed' ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <aside class="hero-side-card">
                <h3>Quick jump</h3>
                <p>Open the connected tools for this subject in one click.</p>
                <div class="hero-side-list">
                    <div class="hero-side-list-item">
                        <div>
                            <strong>Notes</strong>
                            <span>Subject-wise note collection</span>
                        </div>
                        <a href="notes.php?subject_id=<?= $subject_id ?>" class="btn btn-sm btn-light">Open</a>
                    </div>
                    <div class="hero-side-list-item">
                        <div>
                            <strong>Chat</strong>
                            <span>Ask AI or talk with students</span>
                        </div>
                        <a href="chat.php?subject_id=<?= $subject_id ?>" class="btn btn-sm btn-light">Open</a>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <div class="row g-4 align-items-start">
        <div class="col-lg-4">
            <div class="workspace-side-stack">
                <div class="sp-card fade-in-up workspace-card-auto">
                    <div class="sp-card-header">
                        <div>
                            <h6 class="sp-card-title">Add Subtopic</h6>
                            <p class="sp-card-subtitle">Create a specific study step instantly.</p>
                        </div>
                    </div>
                    <div class="sp-card-body">
                        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                        <div id="aiGenerateStatus"></div>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_subtopic">
                            <div class="mb-3">
                                <label class="form-label">Subtopic Name *</label>
                                <input type="text" name="subtopic_title" class="form-control" placeholder="e.g. CPU scheduling algorithms" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Add Subtopic</button>
                        </form>
                    </div>
                </div>

                <div class="sp-card fade-in-up workspace-card-auto">
                    <div class="sp-card-header">
                        <div>
                            <h6 class="sp-card-title">AI Planner</h6>
                            <p class="sp-card-subtitle">Paste syllabus and choose how many subtopics should be generated.</p>
                        </div>
                    </div>
                    <div class="sp-card-body">
                        <form method="POST" class="stack-sm" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_ai_settings">
                            <div>
                                <label class="form-label">Target AI subtopics</label>
                                <input type="number" name="ai_subtopic_target" class="form-control" min="1" max="25" value="<?= $ai_target ?>">
                            </div>
                            <div>
                                <label class="form-label">Subject Syllabus</label>
                                <textarea name="subject_syllabus" class="form-control compact-textarea" placeholder="Paste syllabus, modules, chapters, units, or topics here."><?= htmlspecialchars($subject['subject_syllabus'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="form-label">Upload Syllabus File</label>
                                <input type="file" name="syllabus_file" class="form-control" accept=".txt,.pdf,.doc,.docx">
                                <div class="form-helper">Supported: PDF, Word (.doc/.docx), and text files.</div>
                                <?php if ($syllabus_file_name !== ''): ?>
                                    <div class="form-helper">Current uploaded file: <?= htmlspecialchars($syllabus_file_name) ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn-outline-primary w-100">Save AI Settings</button>
                        </form>
                        <button type="button" class="btn btn-primary w-100 mt-3" id="generateAiSubtopicsBtn">Generate AI Subtopics</button>
                    </div>
                </div>

                <div class="sp-card fade-in-up workspace-card-auto">
                    <div class="sp-card-header">
                        <div>
                            <h6 class="sp-card-title">Study Snapshot</h6>
                            <p class="sp-card-subtitle">The left side now stays compact and informative instead of blank.</p>
                        </div>
                    </div>
                    <div class="sp-card-body">
                        <div class="mini-list">
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= count($subtopics) ?> subtopics</strong>
                                    <span>Total breakdown inside this subject</span>
                                </div>
                                <span class="pill pill-success"><?= count($subtopics) ?></span>
                            </div>
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= $completed_subtopics ?> completed</strong>
                                    <span>Subtopics marked as done</span>
                                </div>
                                <span class="pill pill-success"><?= $completed_subtopics ?></span>
                            </div>
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= $pending_subtopics ?> pending</strong>
                                    <span>Still waiting for revision</span>
                                </div>
                                <span class="pill pill-warning"><?= $pending_subtopics ?></span>
                            </div>
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= (int) $subject['completed_topics'] ?>/<?= (int) $subject['total_topics'] ?> topics</strong>
                                    <span>Main syllabus progress</span>
                                </div>
                                <span class="pill pill-success"><?= $subject_pct ?>%</span>
                            </div>
                        </div>

                        <div class="syllabus-preview-card mt-4">
                            <strong>Syllabus Preview</strong>
                            <p><?= $syllabus_preview !== '' ? nl2br(htmlspecialchars($syllabus_preview_short)) : 'No syllabus added yet. Add it above to get better AI-generated subtopics.' ?></p>
                            <?php if ($syllabus_file_name !== ''): ?>
                                <div class="form-helper mt-2">Uploaded file: <?= htmlspecialchars($syllabus_file_name) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="subject-tools mt-4">
                            <a href="notes.php?subject_id=<?= $subject_id ?>" class="subject-tool-card">
                                <span class="subject-tool-icon"><i class="bi bi-journal-text"></i></span>
                                <span>Notes</span>
                            </a>
                            <a href="chat.php?subject_id=<?= $subject_id ?>" class="subject-tool-card">
                                <span class="subject-tool-icon"><i class="bi bi-chat-dots"></i></span>
                                <span>Chat</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">Subtopics and Notes</h6>
                        <p class="sp-card-subtitle">Only the first few subtopics are shown first, so large subjects stay easy to browse.</p>
                    </div>
                </div>
                <div class="sp-card-body">
                    <?php if (empty($subtopics)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-node-plus"></i></div>
                                <h3>No subtopics yet</h3>
                                <p>Add your first subtopic manually or generate a list from the saved syllabus.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="subtopic-list collapsible-list" id="subtopicsList" data-initial-visible="<?= $subtopic_visible_limit ?>">
                            <?php foreach ($subtopics as $index => $subtopic): ?>
                                <div class="subtopic-item collapsible-item<?= $index >= $subtopic_visible_limit ? ' is-hidden' : '' ?> <?= (int) $subtopic['is_completed'] === 1 ? 'subtopic-done' : '' ?>">
                                    <div class="subtopic-main">
                                        <form method="POST" class="subtopic-toggle-form">
                                            <input type="hidden" name="action" value="toggle_subtopic">
                                            <input type="hidden" name="subtopic_id" value="<?= (int) $subtopic['id'] ?>">
                                            <button type="submit" class="task-check <?= (int) $subtopic['is_completed'] === 1 ? 'checked' : '' ?>">
                                                <?= (int) $subtopic['is_completed'] === 1 ? '&#10003;' : '' ?>
                                            </button>
                                        </form>
                                        <div class="subtopic-copy">
                                            <div class="subtopic-title-row">
                                                <h5 class="subtopic-title"><?= htmlspecialchars($subtopic['title']) ?></h5>
                                                <span class="soft-badge">
                                                    <i class="bi bi-check2-circle"></i>
                                                    <?= (int) $subtopic['is_completed'] === 1 ? 'Completed' : 'Pending' ?>
                                                </span>
                                            </div>
                                            <p class="subtopic-caption">Write formulas, examples, definitions, or revision points for this exact subtopic.</p>
                                        </div>
                                        <a href="?subject_id=<?= $subject_id ?>&delete_subtopic=<?= (int) $subtopic['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this subtopic?')">Delete</a>
                                    </div>
                                    <form method="POST" class="subtopic-note-form">
                                        <input type="hidden" name="action" value="save_subtopic_note">
                                        <input type="hidden" name="subtopic_id" value="<?= (int) $subtopic['id'] ?>">
                                        <label class="form-label">Subtopic Notes</label>
                                        <textarea name="subtopic_note" class="form-control subtopic-textarea" placeholder="Write notes for this subtopic..."><?= htmlspecialchars($subtopic['note_content'] ?? '') ?></textarea>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Save Notes</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (count($subtopics) > $subtopic_visible_limit): ?>
                            <div class="list-reveal-wrap">
                                <button
                                    type="button"
                                    class="btn btn-outline-primary list-reveal-btn"
                                    data-list-id="subtopicsList"
                                    data-show-text="Show more subtopics"
                                    data-hide-text="Close subtopics"
                                >
                                    Show more subtopics
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
const subjectId = <?= $subject_id ?>;
const shouldAutoGenerate = <?= $auto_generate ? 'true' : 'false' ?>;
const generateButton = document.getElementById('generateAiSubtopicsBtn');
const aiGenerateStatus = document.getElementById('aiGenerateStatus');
let isGenerating = false;

function renderAiStatus(type, message) {
    aiGenerateStatus.innerHTML = '<div class="alert alert-' + type + '">' + message + '</div>';
}

async function generateAiSubtopics() {
    if (isGenerating) {
        return;
    }

    isGenerating = true;
    generateButton.disabled = true;
    renderAiStatus('warning', 'Generating AI subtopics from your saved syllabus and subject settings. Please wait...');

    try {
        const response = await fetch('../api/generate_subtopics.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({ subject_id: String(subjectId) })
        });

        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.error || data.message || 'Unable to generate AI subtopics right now.');
        }

        renderAiStatus('success', data.message || 'AI subtopics generated successfully.');
        window.setTimeout(() => {
            window.location.href = 'subject_workspace.php?subject_id=' + subjectId;
        }, 900);
    } catch (error) {
        renderAiStatus('danger', error.message || 'Unable to generate AI subtopics right now.');
    } finally {
        isGenerating = false;
        generateButton.disabled = false;
    }
}

generateButton.addEventListener('click', generateAiSubtopics);

document.querySelectorAll('.list-reveal-btn').forEach((button) => {
    button.addEventListener('click', () => {
        const list = document.getElementById(button.dataset.listId);
        if (!list) {
            return;
        }

        const items = Array.from(list.querySelectorAll('.collapsible-item'));
        const hiddenItems = list.querySelectorAll('.collapsible-item.is-hidden');
        const initialVisible = parseInt(list.dataset.initialVisible || items.length, 10);
        const isExpanded = hiddenItems.length === 0;

        if (isExpanded) {
            items.forEach((item, index) => {
                if (index >= initialVisible) {
                    item.classList.add('is-hidden');
                }
            });
            button.textContent = button.dataset.showText || 'Show more';
            list.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        items.forEach((item) => item.classList.remove('is-hidden'));
        button.textContent = button.dataset.hideText || 'Close';
    });
});

if (shouldAutoGenerate) {
    window.addEventListener('load', () => {
        generateAiSubtopics();
    });
}
</script>
</body>
</html>
