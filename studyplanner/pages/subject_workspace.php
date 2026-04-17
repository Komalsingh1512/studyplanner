<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
requireLogin();

$user = getCurrentUser();
$uid = $user['id'];
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$error = '';
$success = '';

$subject_stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE id = ? AND user_id = ?");
mysqli_stmt_bind_param($subject_stmt, "ii", $subject_id, $uid);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject) {
    header("Location: subjects.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_subtopic') {
    $title = sanitize($_POST['subtopic_title']);

    if ($title === '') {
        $error = "Please enter a subtopic title!";
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO subject_subtopics (user_id, subject_id, title) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iis", $uid, $subject_id, $title);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Subtopic added successfully!";
        } else {
            $error = "Unable to add subtopic right now!";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_subtopic') {
    $subtopic_id = (int)$_POST['subtopic_id'];
    mysqli_query(
        $conn,
        "UPDATE subject_subtopics SET is_completed = NOT is_completed
         WHERE id = $subtopic_id AND subject_id = $subject_id AND user_id = $uid"
    );
    $success = "Subtopic updated!";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_subtopic_note') {
    $subtopic_id = (int)$_POST['subtopic_id'];
    $note_content = trim($_POST['subtopic_note']);
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE subject_subtopics SET note_content = ? WHERE id = ? AND subject_id = ? AND user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "siii", $note_content, $subtopic_id, $subject_id, $uid);
    if (mysqli_stmt_execute($stmt)) {
        $success = "Subtopic note saved!";
    } else {
        $error = "Unable to save subtopic note right now!";
    }
}

if (isset($_GET['delete_subtopic'])) {
    $subtopic_id = (int)$_GET['delete_subtopic'];
    mysqli_query(
        $conn,
        "DELETE FROM subject_subtopics WHERE id = $subtopic_id AND subject_id = $subject_id AND user_id = $uid"
    );
    header("Location: subject_workspace.php?subject_id=$subject_id&msg=deleted");
    exit();
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success = "Subtopic deleted successfully!";
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
    if ((int)$subtopic['is_completed'] === 1) {
        $completed_subtopics++;
    }
}

$subject_pct = $subject['total_topics'] > 0
    ? round(($subject['completed_topics'] / $subject['total_topics']) * 100)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Workspace - Study Planner</title>
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
                Workspace 🧠
            </div>
            <h1 class="page-title"><?= htmlspecialchars($subject['name']) ?> Workspace</h1>
            <p class="page-subtitle">Add subtopics, mark them done, and keep notes for each one in a connected page for this subject.</p>
        </div>
        <a href="subjects.php" class="btn btn-outline-secondary">← Back to Subjects</a>
    </div>

    <section class="hero-panel fade-in-up mb-4">
        <div class="hero-grid">
            <div class="hero-copy">
                <div class="hero-chip-row">
                    <div class="hero-chip"><i class="bi bi-graph-up-arrow"></i> <?= $subject_pct ?>% subject progress</div>
                    <div class="hero-chip"><i class="bi bi-diagram-3"></i> <?= $completed_subtopics ?>/<?= count($subtopics) ?> subtopics done</div>
                    <div class="hero-chip"><i class="bi bi-speedometer2"></i> <?= htmlspecialchars(ucfirst($subject['difficulty'])) ?> difficulty</div>
                </div>
            </div>
            <aside class="hero-side-card">
                <h3>Quick jump ✨</h3>
                <div class="hero-side-list">
                    <div class="hero-side-list-item">
                        <div>
                            <strong>📝 Notes</strong>
                            <span>Subject-wise notes</span>
                        </div>
                        <a href="notes.php?subject_id=<?= $subject_id ?>" class="btn btn-sm btn-light">Open</a>
                    </div>
                    <div class="hero-side-list-item">
                        <div>
                            <strong>💬 Chat</strong>
                            <span>Ask doubts</span>
                        </div>
                        <a href="chat.php?subject_id=<?= $subject_id ?>" class="btn btn-sm btn-light">Open</a>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <h6 class="sp-card-title">Add Subtopic ➕</h6>
                </div>
                <div class="sp-card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_subtopic">
                        <div class="mb-3">
                            <label class="form-label">Subtopic Name *</label>
                            <input type="text" name="subtopic_title" class="form-control" placeholder="e.g. CPU scheduling algorithms" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add Subtopic 🚀</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <h6 class="sp-card-title">Subtopics & Notes 📚</h6>
                </div>
                <div class="sp-card-body">
                    <?php if (empty($subtopics)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-node-plus"></i></div>
                                <h3>No subtopics yet</h3>
                                <p>Add your first subtopic and start keeping notes for it.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="subtopic-list">
                            <?php foreach ($subtopics as $subtopic): ?>
                                <div class="subtopic-item <?= (int)$subtopic['is_completed'] === 1 ? 'subtopic-done' : '' ?>">
                                    <div class="subtopic-main">
                                        <form method="POST" class="subtopic-toggle-form">
                                            <input type="hidden" name="action" value="toggle_subtopic">
                                            <input type="hidden" name="subtopic_id" value="<?= $subtopic['id'] ?>">
                                            <button type="submit" class="task-check <?= (int)$subtopic['is_completed'] === 1 ? 'checked' : '' ?>">
                                                <?= (int)$subtopic['is_completed'] === 1 ? '✓' : '' ?>
                                            </button>
                                        </form>
                                        <div class="subtopic-copy">
                                            <div class="subtopic-title-row">
                                                <h5 class="subtopic-title"><?= htmlspecialchars($subtopic['title']) ?></h5>
                                                <span class="soft-badge">
                                                    <i class="bi bi-check2-circle"></i>
                                                    <?= (int)$subtopic['is_completed'] === 1 ? 'Completed' : 'Pending' ?>
                                                </span>
                                            </div>
                                            <p class="subtopic-caption">Write specific notes, formulas, examples, or revision points for this subtopic.</p>
                                        </div>
                                        <a href="?subject_id=<?= $subject_id ?>&delete_subtopic=<?= $subtopic['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this subtopic?')">Delete 🗑️</a>
                                    </div>
                                    <form method="POST" class="subtopic-note-form">
                                        <input type="hidden" name="action" value="save_subtopic_note">
                                        <input type="hidden" name="subtopic_id" value="<?= $subtopic['id'] ?>">
                                        <label class="form-label">Subtopic Notes 📝</label>
                                        <textarea name="subtopic_note" class="form-control subtopic-textarea" placeholder="Write notes for this subtopic..."><?= htmlspecialchars($subtopic['note_content'] ?? '') ?></textarea>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Save Notes 💾</button>
                                    </form>
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
