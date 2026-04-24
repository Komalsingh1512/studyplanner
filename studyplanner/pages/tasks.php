<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
requireLogin();
$user = getCurrentUser();
$uid  = $user['id'];

$error = '';
$success = '';

// Add task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $subject_id   = (int)$_POST['subject_id'];
    $title        = sanitize($_POST['title']);
    $sched_date   = sanitize($_POST['scheduled_date']);
    $start_time   = sanitize($_POST['start_time']);
    $duration_min = (int)$_POST['duration_min'];

    if (empty($title) || empty($sched_date) || !$subject_id) {
        $error = "Please fill required fields!";
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO study_tasks (user_id, subject_id, title, scheduled_date, start_time, duration_min)
             VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iisssi", $uid, $subject_id, $title, $sched_date, $start_time, $duration_min);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Task add ho gaya!";
        } else {
            $error = "Kuch galat hua!";
        }
    }
}

// Delete task
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM study_tasks WHERE id = $del_id AND user_id = $uid");
    header("Location: tasks.php");
    exit();
}

// Get subjects for dropdown
$sub_res  = mysqli_query($conn, "SELECT id, name FROM subjects WHERE user_id = $uid ORDER BY created_at DESC, id DESC");
$sub_list = [];
while ($s = mysqli_fetch_assoc($sub_res)) $sub_list[] = $s;

// Get tasks — filter by date if given
$filter_date = isset($_GET['date']) ? sanitize($_GET['date']) : date('Y-m-d');
$tasks_result = mysqli_query($conn,
    "SELECT t.*, s.name as subject_name FROM study_tasks t
     JOIN subjects s ON t.subject_id = s.id
     WHERE t.user_id = $uid AND t.scheduled_date = '$filter_date'
     ORDER BY t.start_time ASC"
);
$tasks = [];
while ($row = mysqli_fetch_assoc($tasks_result)) $tasks[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks — Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260424-1215">
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
                Tasks ✅
            </div>
            <h1 class="page-title">Plan your study sessions</h1>
            <p class="page-subtitle">Organize focused sessions, stay consistent, and finish more with less stress ⏳</p>
        </div>
    </div>

    <div class="row g-4 align-items-start">
        <div class="col-md-4">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">Add New Task ✍️</h6>
                    </div>
                </div>
                <div class="sp-card-body">
                    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
                    <?php if (empty($sub_list)): ?>
                        <div class="alert alert-warning">Please <a href="subjects.php">add a subject</a> first!</div>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Subject *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select subject</option>
                                <?php foreach ($sub_list as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Task Title *</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. OS — Paging revision" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date *</label>
                            <input type="date" name="scheduled_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" name="duration_min" class="form-control" value="60" min="15" max="480">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Add Task 🚀</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">Tasks for the Day 🗓️</h6>
                    </div>
                    <form method="GET" class="filter-form">
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= $filter_date ?>" style="width:160px;">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                    </form>
                </div>
                <div class="sp-card-body">
                    <?php if (empty($tasks)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-calendar2-x"></i></div>
                                <h3>No tasks for this date 😌</h3>
                                <p>Add a task to give this day a clear mission.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                        <div class="task-row <?= $task['is_completed'] ? 'task-done' : '' ?>">
                            <div class="task-info flex-grow-1">
                                <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                                <div class="task-meta">
                                    <?= htmlspecialchars($task['subject_name']) ?>
                                    <?php if ($task['start_time']): ?>
                                        · <?= date('h:i A', strtotime($task['start_time'])) ?>
                                    <?php endif; ?>
                                    · <?= $task['duration_min'] ?> min
                                </div>
                            </div>
                            <div class="task-actions">
                                <?php if (!$task['is_completed']): ?>
                                    <form method="POST" action="toggle_task.php">
                                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                        <input type="hidden" name="redirect" value="tasks.php?date=<?= $filter_date ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success">✓ Done</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge bg-success">Completed</span>
                                <?php endif; ?>
                                <a href="?delete=<?= $task['id'] ?>&date=<?= $filter_date ?>"
                                   onclick="return confirm('Delete this task?')"
                                   class="btn btn-sm btn-outline-danger">✕</a>
                            </div>
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
