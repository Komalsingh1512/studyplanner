<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/syllabus_upload.php';
requireLogin();

$user = getCurrentUser();
$uid  = (int) $user['id'];

$error = '';
$success = '';
$subject_visible_limit = 4;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name = sanitize($_POST['name']);
    $total = (int) $_POST['total_topics'];
    $completed = (int) $_POST['completed_topics'];
    $difficulty = sanitize($_POST['difficulty']);
    $exam_date = !empty($_POST['exam_date']) ? sanitize($_POST['exam_date']) : null;
    $manual_syllabus = trim($_POST['subject_syllabus'] ?? '');
    $subject_syllabus = $manual_syllabus;
    $syllabus_file_name = null;
    $syllabus_file_path = null;
    $syllabus_source_type = $manual_syllabus !== '' ? 'text' : 'none';
    $ai_subtopic_target = isset($_POST['ai_subtopic_target']) ? (int) $_POST['ai_subtopic_target'] : 5;
    $auto_generate_subtopics = isset($_POST['auto_generate_subtopics']);

    if ($name === '') {
        $error = "Please enter subject name.";
    } elseif ($total < 1) {
        $error = "Total topics must be at least 1.";
    } elseif ($completed < 0) {
        $error = "Completed topics cannot be negative.";
    } elseif ($completed > $total) {
        $error = "Completed topics cannot be more than total topics.";
    } elseif ($ai_subtopic_target < 1 || $ai_subtopic_target > 25) {
        $error = "AI subtopic count must be between 1 and 25.";
    } else {
        $uploadResult = processUploadedSyllabusFile($_FILES['syllabus_file'] ?? [], $uid);
        if (isset($uploadResult['error'])) {
            $error = $uploadResult['error'];
        } elseif (!empty($uploadResult['uploaded'])) {
            $subject_syllabus = trim($manual_syllabus . "\n\n" . $uploadResult['text']);
            $syllabus_file_name = $uploadResult['file_name'];
            $syllabus_file_path = $uploadResult['file_path'];
            $syllabus_source_type = $manual_syllabus !== '' ? 'text+file' : 'file';
        }
    }

    if ($error === '') {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO subjects (
                user_id, name, total_topics, completed_topics, difficulty, exam_date,
                subject_syllabus, ai_subtopic_target, syllabus_file_name, syllabus_file_path, syllabus_source_type
             )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "isiisssisss",
            $uid,
            $name,
            $total,
            $completed,
            $difficulty,
            $exam_date,
            $subject_syllabus,
            $ai_subtopic_target,
            $syllabus_file_name,
            $syllabus_file_path,
            $syllabus_source_type
        );

        if (mysqli_stmt_execute($stmt)) {
            $subjectId = (int) mysqli_insert_id($conn);
            $redirect = "subject_workspace.php?subject_id=" . $subjectId . "&msg=subject_created";
            if ($auto_generate_subtopics) {
                $redirect .= "&auto_generate=1";
            }
            header("Location: " . $redirect);
            exit();
        } else {
            $error = "Unable to add subject. " . mysqli_error($conn);
        }
    }
}

if (isset($_GET['delete'])) {
    $del_id = (int) $_GET['delete'];
    mysqli_query($conn, "DELETE FROM subjects WHERE id = $del_id AND user_id = $uid");
    header("Location: subjects.php?msg=deleted");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $sub_id = (int) $_POST['subject_id'];
    $completed = (int) $_POST['completed_topics'];
    $subject_check = mysqli_query($conn, "SELECT total_topics FROM subjects WHERE id = $sub_id AND user_id = $uid");
    $subject_data = $subject_check ? mysqli_fetch_assoc($subject_check) : null;

    if (!$subject_data) {
        $error = "Subject not found.";
    } elseif ($completed < 0) {
        $error = "Completed topics cannot be negative.";
    } elseif ($completed > (int) $subject_data['total_topics']) {
        $error = "Completed topics cannot be more than total topics.";
    } else {
        mysqli_query($conn, "UPDATE subjects SET completed_topics = $completed WHERE id = $sub_id AND user_id = $uid");
        $success = "Progress updated successfully.";
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success = "Subject removed successfully.";
}

$subjects_result = mysqli_query($conn, "SELECT * FROM subjects WHERE user_id = $uid ORDER BY created_at DESC, id DESC");
$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_result)) {
    $subjects[] = $row;
}

$subtopics_by_subject = [];
$subtopics_result = mysqli_query(
    $conn,
    "SELECT * FROM subject_subtopics WHERE user_id = $uid ORDER BY is_completed ASC, created_at DESC, id DESC"
);
while ($row = mysqli_fetch_assoc($subtopics_result)) {
    $current_subject_id = (int) $row['subject_id'];
    if (!isset($subtopics_by_subject[$current_subject_id])) {
        $subtopics_by_subject[$current_subject_id] = [];
    }
    $subtopics_by_subject[$current_subject_id][] = $row;
}

$total_subjects = count($subjects);
$passed_subjects = 0;
$upcoming_subjects = 0;
$total_subtopics = 0;
$completed_subtopics_total = 0;

foreach ($subjects as $subject) {
    $daysLeft = $subject['exam_date']
        ? (int) ((strtotime($subject['exam_date']) - time()) / 86400)
        : null;

    if ($daysLeft !== null) {
        if ($daysLeft < 0) {
            $passed_subjects++;
        } else {
            $upcoming_subjects++;
        }
    }

    $subjectSubtopics = $subtopics_by_subject[(int) $subject['id']] ?? [];
    $total_subtopics += count($subjectSubtopics);
    foreach ($subjectSubtopics as $subjectSubtopic) {
        if ((int) $subjectSubtopic['is_completed'] === 1) {
            $completed_subtopics_total++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects - Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260504-1835">
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
            <a href="notes.php" class="btn btn-sm btn-outline-secondary">Notes</a>
        </div>
    </div>
</nav>

<div class="page-wrap">
    <div class="page-header fade-in-up">
        <div>
            <div class="eyebrow">
                <span class="eyebrow-dot"></span>
                Subjects
            </div>
            <h1 class="page-title">Manage subjects like a pro</h1>
            <p class="page-subtitle">Track progress, include your syllabus, and generate smarter AI subtopics without making the page feel crowded.</p>
        </div>
    </div>

    <div class="row g-4 align-items-start">
        <div class="col-lg-4">
            <div class="subjects-side-stack">
                <div class="sp-card fade-in-up">
                    <div class="sp-card-header">
                        <div>
                            <h6 class="sp-card-title">Add New Subject</h6>
                            <p class="sp-card-subtitle">You can now include syllabus details and a custom AI subtopic count.</p>
                        </div>
                    </div>
                    <div class="sp-card-body">
                        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add">
                            <div class="mb-3">
                                <label class="form-label">Subject Name *</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Operating Systems" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Total Topics</label>
                                <input type="number" name="total_topics" class="form-control" value="50" min="1">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Completed Topics</label>
                                <input type="number" name="completed_topics" class="form-control" value="0" min="0">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Difficulty</label>
                                <select name="difficulty" class="form-select">
                                    <option value="easy">Easy</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Exam Date</label>
                                <input type="date" name="exam_date" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Subject Syllabus</label>
                                <textarea name="subject_syllabus" class="form-control compact-textarea" placeholder="Paste units, chapters, or syllabus points here. AI will use this to generate more relevant subtopics."></textarea>
                                <div class="form-helper">Example: Unit 1 Java basics, Unit 2 OOP, Unit 3 Exceptions, Unit 4 Collections.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Upload Syllabus File</label>
                                <input type="file" name="syllabus_file" class="form-control" accept=".txt,.pdf,.doc,.docx">
                                <div class="form-helper">Supported: PDF, Word (.doc/.docx), and text files. Uploaded text is used for AI generation.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">How many AI subtopics do you want?</label>
                                <input type="number" name="ai_subtopic_target" class="form-control" value="5" min="1" max="25">
                                <div class="form-helper">The AI will not generate more than this number.</div>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="autoGenerateSubtopics" name="auto_generate_subtopics" checked>
                                <label class="form-check-label" for="autoGenerateSubtopics">Auto-generate subtopics with AI</label>
                                <div class="form-helper">Uses your syllabus and target count when Groq is configured.</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Add Subject</button>
                        </form>
                    </div>
                </div>

                <div class="sp-card fade-in-up">
                    <div class="sp-card-header">
                        <div>
                            <h6 class="sp-card-title">Subjects Summary</h6>
                            <p class="sp-card-subtitle">A compact snapshot so the left side feels useful, not empty.</p>
                        </div>
                    </div>
                    <div class="sp-card-body">
                        <div class="mini-list">
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= $total_subjects ?> total subjects</strong>
                                    <span>Your full study list</span>
                                </div>
                                <span class="pill pill-success"><?= $total_subjects ?></span>
                            </div>
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= $upcoming_subjects ?> upcoming exams</strong>
                                    <span>Subjects still in progress</span>
                                </div>
                                <span class="pill pill-warning"><?= $upcoming_subjects ?></span>
                            </div>
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= $passed_subjects ?> passed exams</strong>
                                    <span>Subjects that can be deleted later</span>
                                </div>
                                <span class="pill pill-danger"><?= $passed_subjects ?></span>
                            </div>
                            <div class="mini-list-row">
                                <div>
                                    <strong><?= $completed_subtopics_total ?>/<?= $total_subtopics ?> subtopics done</strong>
                                    <span>Combined progress across all subjects</span>
                                </div>
                                <span class="pill pill-success"><?= $completed_subtopics_total ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">My Subjects</h6>
                        <p class="sp-card-subtitle">Only the first few subjects are shown at once, so the page stays compact.</p>
                    </div>
                </div>
                <div class="sp-card-body">
                    <?php if (empty($subjects)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-journal-plus"></i></div>
                                <h3>No subjects yet</h3>
                                <p>Add your first subject and start tracking progress beautifully.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="collapsible-list" id="subjectsList" data-initial-visible="<?= $subject_visible_limit ?>">
                            <?php foreach ($subjects as $index => $sub):
                                $pct = (int) $sub['total_topics'] > 0
                                    ? round(((int) $sub['completed_topics'] / (int) $sub['total_topics']) * 100)
                                    : 0;
                                $subject_subtopics = $subtopics_by_subject[(int) $sub['id']] ?? [];
                                $completed_subtopics = 0;
                                foreach ($subject_subtopics as $subject_subtopic) {
                                    if ((int) $subject_subtopic['is_completed'] === 1) {
                                        $completed_subtopics++;
                                    }
                                }
                                $days_left = $sub['exam_date']
                                    ? (int) ((strtotime($sub['exam_date']) - time()) / 86400)
                                    : null;
                                $badge = $sub['difficulty'] === 'easy' ? 'bg-success'
                                    : ($sub['difficulty'] === 'hard' ? 'bg-danger' : 'bg-warning text-dark');
                                $is_hidden_initially = $index >= $subject_visible_limit;
                            ?>
                            <div class="subject-row collapsible-item<?= $is_hidden_initially ? ' is-hidden' : '' ?>">
                                <div class="subject-row-top">
                                    <div>
                                        <h3 class="subject-title"><?= htmlspecialchars($sub['name']) ?></h3>
                                        <div class="subject-tags mt-2">
                                            <span class="soft-badge"><i class="bi bi-layers"></i><?= (int) $sub['total_topics'] ?> total topics</span>
                                            <span class="soft-badge"><i class="bi bi-bar-chart-line"></i><?= $pct ?>%</span>
                                            <span class="soft-badge"><i class="bi bi-diagram-3"></i><?= $completed_subtopics ?>/<?= count($subject_subtopics) ?> subtopics</span>
                                            <span class="badge <?= $badge ?> ms-1" style="font-size:11px;"><?= htmlspecialchars($sub['difficulty']) ?></span>
                                        </div>
                                        <?php if (!empty($sub['subject_syllabus'])): ?>
                                            <p class="subject-meta mt-2">
                                                Syllabus added for AI-guided subtopic generation.
                                                <?php if (!empty($sub['syllabus_file_name'])): ?>
                                                    File: <?= htmlspecialchars($sub['syllabus_file_name']) ?>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="task-actions">
                                        <?php if ($days_left !== null): ?>
                                            <span class="pill <?= $days_left < 0 ? 'pill-danger' : ($days_left <= 7 ? 'pill-warning' : 'pill-success') ?>">
                                                <i class="bi bi-calendar2-week"></i>
                                                <?= $days_left >= 0 ? $days_left . ' days left' : 'Exam passed' ?>
                                            </span>
                                        <?php endif; ?>
                                        <a href="?delete=<?= (int) $sub['id'] ?>" onclick="return confirm('Are you sure you want to delete?')" class="btn btn-sm btn-outline-danger">Delete</a>
                                    </div>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar <?= $pct >= 70 ? 'bg-success' : ($pct >= 40 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                                <div class="progress-text">
                                    <span><?= (int) $sub['completed_topics'] ?> of <?= (int) $sub['total_topics'] ?> topics completed</span>
                                    <span>AI target: <?= (int) ($sub['ai_subtopic_target'] ?? 8) ?> subtopics</span>
                                </div>
                                <form method="POST" class="inline-form mt-3">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="subject_id" value="<?= (int) $sub['id'] ?>">
                                    <span class="section-note">Done</span>
                                    <input type="number" name="completed_topics" value="<?= (int) $sub['completed_topics'] ?>" min="0" max="<?= (int) $sub['total_topics'] ?>" class="form-control form-control-sm" style="width:100px;">
                                    <span class="section-note">/ <?= (int) $sub['total_topics'] ?></span>
                                    <button type="submit" class="btn btn-sm btn-outline-success">Update</button>
                                    <span class="ms-auto section-note"><?= (int) $sub['completed_topics'] ?> done</span>
                                </form>
                                <div class="subject-tools">
                                    <a href="subject_workspace.php?subject_id=<?= (int) $sub['id'] ?>" class="subject-tool-card">
                                        <span class="subject-tool-icon"><i class="bi bi-diagram-3"></i></span>
                                        <span>Workspace</span>
                                    </a>
                                    <a href="notes.php?subject_id=<?= (int) $sub['id'] ?>" class="subject-tool-card">
                                        <span class="subject-tool-icon"><i class="bi bi-journal-text"></i></span>
                                        <span>Notes</span>
                                    </a>
                                    <a href="chat.php?subject_id=<?= (int) $sub['id'] ?>" class="subject-tool-card">
                                        <span class="subject-tool-icon"><i class="bi bi-chat-dots"></i></span>
                                        <span>Chat</span>
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (count($subjects) > $subject_visible_limit): ?>
                            <div class="list-reveal-wrap">
                                <button
                                    type="button"
                                    class="btn btn-outline-primary list-reveal-btn"
                                    data-list-id="subjectsList"
                                    data-show-text="Show more subjects"
                                    data-hide-text="Close subjects"
                                >
                                    Show more subjects
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
</script>
</body>
</html>
