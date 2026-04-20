<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
requireLogin();

$user = getCurrentUser();
$uid  = $user['id'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $subject_id = (int)$_POST['subject_id'];
    $title = sanitize($_POST['title']);
    $note_content = trim($_POST['note_content']);

    if (!$subject_id) {
        $error = "Please select a subject!";
    } elseif ($title === '') {
        $error = "Please enter a note title!";
    } elseif ($note_content === '') {
        $error = "Please write your note!";
    } else {
        $subject_stmt = mysqli_prepare($conn, "SELECT id FROM subjects WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($subject_stmt, "ii", $subject_id, $uid);
        mysqli_stmt_execute($subject_stmt);
        $subject_result = mysqli_stmt_get_result($subject_stmt);

        if (!mysqli_fetch_assoc($subject_result)) {
            $error = "Selected subject not found!";
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO subject_notes (user_id, subject_id, title, note_content) VALUES (?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "iiss", $uid, $subject_id, $title, $note_content);

            if (mysqli_stmt_execute($stmt)) {
                $success = "Note saved successfully!";
            } else {
                $error = "Unable to save note right now.";
            }
        }
    }
}

if (isset($_GET['delete'])) {
    $note_id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM subject_notes WHERE id = $note_id AND user_id = $uid");
    header("Location: notes.php" . (isset($_GET['subject_id']) ? '?subject_id=' . (int)$_GET['subject_id'] . '&msg=deleted' : '?msg=deleted'));
    exit();
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success = "Note deleted successfully!";
}

$subjects = [];
$subjects_result = mysqli_query($conn, "SELECT id, name FROM subjects WHERE user_id = $uid ORDER BY created_at DESC, id DESC");
while ($row = mysqli_fetch_assoc($subjects_result)) {
    $subjects[] = $row;
}

$selected_subject = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$notes_query = "SELECT n.*, s.name AS subject_name
                FROM subject_notes n
                JOIN subjects s ON n.subject_id = s.id
                WHERE n.user_id = $uid";

if ($selected_subject > 0) {
    $notes_query .= " AND n.subject_id = $selected_subject";
}

$notes_query .= " ORDER BY n.created_at DESC";

$notes = [];
$notes_result = mysqli_query($conn, $notes_query);
while ($row = mysqli_fetch_assoc($notes_result)) {
    $notes[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes - Study Planner</title>
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
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>
            <a href="subjects.php" class="btn btn-sm btn-outline-secondary">Subjects</a>
            <a href="tasks.php" class="btn btn-sm btn-outline-secondary">Tasks</a>
            <a href="ai_plan.php" class="btn btn-sm btn-outline-secondary">AI Plan</a>
        </div>
    </div>
</nav>

<div class="page-wrap">
    <div class="page-header fade-in-up">
        <div>
            <div class="eyebrow">
                <span class="eyebrow-dot"></span>
                Notes 📝
            </div>
            <h1 class="page-title">Subject notes, beautifully organized</h1>
            <p class="page-subtitle">Capture key ideas, revision points, and shortcuts subject-wise in one calm workspace ✨</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">Add Note ✍️</h6>
                    </div>
                </div>
                <div class="sp-card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

                    <?php if (empty($subjects)): ?>
                        <div class="alert alert-warning">Please <a href="subjects.php">add a subject</a> first!</div>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
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
                            <label class="form-label">Title *</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. SQL joins summary" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Note *</label>
                            <textarea name="note_content" class="form-control notes-textarea" placeholder="Write your key points here..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Save Note 💾</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">My Notes 📚</h6>
                    </div>
                    <form method="GET" class="filter-form">
                        <select name="subject_id" class="form-select form-select-sm" style="width:200px;">
                            <option value="0">All subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $selected_subject === (int)$subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                    </form>
                </div>
                <div class="sp-card-body">
                    <?php if (empty($notes)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-journal-text"></i></div>
                                <h3>No notes yet 📝</h3>
                                <p>Create your first subject note and build your revision bank.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                            <div class="note-row">
                                <div class="subject-row-top">
                                    <div>
                                        <h3 class="subject-title"><?= htmlspecialchars($note['title']) ?></h3>
                                        <div class="subject-tags mt-2">
                                            <span class="soft-badge">
                                                <i class="bi bi-journal-bookmark"></i>
                                                <?= htmlspecialchars($note['subject_name']) ?>
                                            </span>
                                            <span class="soft-badge">
                                                <i class="bi bi-calendar3"></i>
                                                <?= date('d M Y, h:i A', strtotime($note['created_at'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <a href="?delete=<?= $note['id'] ?><?= $selected_subject > 0 ? '&subject_id=' . $selected_subject : '' ?>"
                                       onclick="return confirm('Delete this note?')"
                                       class="btn btn-sm btn-outline-danger">Delete 🗑️</a>
                                </div>
                                <div class="note-content"><?= nl2br(htmlspecialchars($note['note_content'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
