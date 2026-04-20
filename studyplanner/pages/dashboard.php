<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
requireLogin();

$user = getCurrentUser();
$uid  = $user['id'];

// Subjects fetch
$subjects_result = mysqli_query($conn, "SELECT * FROM subjects WHERE user_id = $uid ORDER BY created_at DESC, id DESC");
$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_result)) {
    $subjects[] = $row;
}

// Today's tasks
$today = date('Y-m-d');
$tasks_result = mysqli_query($conn,
    "SELECT t.*, s.name as subject_name FROM study_tasks t
     JOIN subjects s ON t.subject_id = s.id
     WHERE t.user_id = $uid AND t.scheduled_date = '$today'
     ORDER BY t.start_time ASC"
);
$tasks = [];
while ($row = mysqli_fetch_assoc($tasks_result)) {
    $tasks[] = $row;
}

// Stats
$total_subjects = count($subjects);
$total_tasks_today = count($tasks);
$completed_today = 0;
foreach ($tasks as $t) {
    if ($t['is_completed']) $completed_today++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260406-1433">
</head>
<body>
<div class="app-shell">
<nav class="navbar navbar-expand-lg sp-navbar">
    <div class="container-fluid nav-shell">
        <span class="navbar-brand sp-brand">
            <span class="brand-logo">SP</span>
            Study Planner
        </span>
        <div class="ms-auto nav-actions">
            <span class="nav-user-badge">
                <i class="bi bi-person-circle"></i>
                <span>Hello, <strong><?= htmlspecialchars($user['name']) ?></strong></span>
            </span>
            <a href="subjects.php" class="btn btn-sm btn-outline-secondary">Subjects</a>
            <a href="tasks.php" class="btn btn-sm btn-outline-secondary">Tasks</a>
            <a href="notes.php" class="btn btn-sm btn-outline-secondary">Notes</a>
            <a href="ai_plan.php" class="btn btn-sm btn-primary">AI Plan</a>
            <a href="logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
        </div>
    </div>
</nav>

<div class="page-wrap">
    <section class="hero-panel fade-in-up">
        <div class="hero-grid">
            <div class="hero-copy">
                <div class="eyebrow">
                    <span class="eyebrow-dot"></span>
                    Dashboard ✨
                </div>
                <h2>Hello, <?= htmlspecialchars($user['name']) ?> 👋</h2>
                <p>Your study space is ready. Let’s turn today into real progress 🎯</p>
                <div class="hero-chip-row">
                    <div class="hero-chip"><i class="bi bi-calendar-event"></i> Today: <?= date('d M Y') ?></div>
                    <div class="hero-chip"><i class="bi bi-check2-circle"></i> <?= $completed_today ?> wins ✅</div>
                    <div class="hero-chip"><i class="bi bi-journal-richtext"></i> <?= $total_subjects ?> subjects 📚</div>
                </div>
            </div>
            <aside class="hero-side-card">
                <h3>Quick actions ⚡</h3>
                <div class="hero-side-list">
                    <div class="hero-side-list-item">
                        <div>
                            <strong>📚 Subjects</strong>
                        </div>
                        <a href="subjects.php" class="btn btn-sm btn-light">Open</a>
                    </div>
                    <div class="hero-side-list-item">
                        <div>
                            <strong>✅ Tasks</strong>
                        </div>
                        <a href="tasks.php" class="btn btn-sm btn-light">Open</a>
                    </div>
                    <div class="hero-side-list-item">
                        <div>
                            <strong>📝 Notes</strong>
                        </div>
                        <a href="notes.php" class="btn btn-sm btn-light">Open</a>
                    </div>
                    <div class="hero-side-list-item">
                        <div>
                            <strong>🤖 AI Plan</strong>
                        </div>
                        <a href="ai_plan.php" class="btn btn-sm btn-light">Launch</a>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-card-top">
                    <div class="stat-label">Subjects</div>
                    <div class="metric-icon"><i class="bi bi-journal-bookmark"></i></div>
                </div>
                <div class="stat-value"><?= $total_subjects ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-card-top">
                    <div class="stat-label">Today's Tasks</div>
                    <div class="metric-icon"><i class="bi bi-list-check"></i></div>
                </div>
                <div class="stat-value"><?= $total_tasks_today ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-card-top">
                    <div class="stat-label">Completed Today</div>
                    <div class="metric-icon"><i class="bi bi-check2-circle"></i></div>
                </div>
                <div class="stat-value text-success"><?= $completed_today ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-card-top">
                    <div class="stat-label">Remaining Tasks</div>
                    <div class="metric-icon"><i class="bi bi-hourglass-split"></i></div>
                </div>
                <div class="stat-value text-warning"><?= $total_tasks_today - $completed_today ?></div>
            </div>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="dashboard-main section-stack">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">My Subjects 📚</h6>
                    </div>
                    <a href="subjects.php" class="btn btn-sm sp-btn-add">+ Add Subject ✨</a>
                </div>
                <div class="sp-card-body">
                    <?php if (empty($subjects)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-journal-plus"></i></div>
                                <h3>No subjects yet 📘</h3>
                                <p>Add your first subject and start building your study map.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($subjects as $sub):
                            $pct = $sub['total_topics'] > 0
                                ? round(($sub['completed_topics'] / $sub['total_topics']) * 100)
                                : 0;
                            $days_left = $sub['exam_date']
                                ? (int)((strtotime($sub['exam_date']) - time()) / 86400)
                                : null;
                            $bar_color = $pct >= 70 ? 'bg-success' : ($pct >= 40 ? 'bg-warning' : 'bg-danger');
                        ?>
                        <div class="subject-row">
                            <div class="subject-row-top">
                                <div>
                                    <h3 class="subject-title"><?= htmlspecialchars($sub['name']) ?></h3>
                                    <div class="subject-tags mt-2">
                                        <span class="soft-badge">
                                            <i class="bi bi-bar-chart-line"></i>
                                            <?= $pct ?>% complete
                                        </span>
                                        <span class="soft-badge">
                                            <i class="bi bi-layers"></i>
                                            <?= $sub['completed_topics'] ?>/<?= $sub['total_topics'] ?> topics
                                        </span>
                                    </div>
                                </div>
                                <?php if ($days_left !== null): ?>
                                    <span class="pill <?= $days_left < 0 ? 'pill-danger' : ($days_left <= 7 ? 'pill-warning' : 'pill-success') ?>">
                                        <i class="bi bi-calendar2-week"></i>
                                        <?= $days_left >= 0 ? $days_left.' days left' : 'Exam passed' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="progress">
                                <div class="progress-bar <?= $bar_color ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="progress-text">
                                <span><?= htmlspecialchars(ucfirst($sub['difficulty'])) ?> difficulty</span>
                                <span><?= $sub['completed_topics'] ?>/<?= $sub['total_topics'] ?> topics covered</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dashboard-side section-stack">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">Today's Tasks ✅</h6>
                        <p class="sp-card-subtitle"><?= date('d M Y') ?></p>
                    </div>
                    <a href="tasks.php" class="btn btn-sm sp-btn-add">+ Add Task 🚀</a>
                </div>
                <div class="sp-card-body">
                    <?php if (empty($tasks)): ?>
                        <div class="empty-state">
                            <div class="empty-state-inner">
                                <div class="empty-state-icon"><i class="bi bi-calendar2-plus"></i></div>
                                <h3>No tasks today 🎉</h3>
                                <p>Add a task and give your day a clear target.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                        <div class="task-row <?= $task['is_completed'] ? 'task-done' : '' ?>">
                            <form method="POST" action="toggle_task.php" style="display:inline;">
                                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                <button type="submit" class="task-check <?= $task['is_completed'] ? 'checked' : '' ?>">
                                    <?= $task['is_completed'] ? '✓' : '' ?>
                                </button>
                            </form>
                            <div class="task-info">
                                <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                                <div class="task-meta">
                                    <?= htmlspecialchars($task['subject_name']) ?>
                                    <?php if ($task['start_time']): ?>
                                        · <?= date('h:i A', strtotime($task['start_time'])) ?>
                                    <?php endif; ?>
                                    · <?= $task['duration_min'] ?> min
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">Study Pulse 💡</h6>
                    </div>
                </div>
                <div class="sp-card-body">
                    <div class="mini-list">
                        <div class="mini-list-row">
                            <div>
                                <strong><?= $completed_today ?> tasks done</strong>
                            </div>
                            <span class="pill pill-success"><?= $completed_today ?></span>
                        </div>
                        <div class="mini-list-row">
                            <div>
                                <strong><?= $total_tasks_today - $completed_today ?> tasks left</strong>
                            </div>
                            <span class="pill pill-warning"><?= max(0, $total_tasks_today - $completed_today) ?></span>
                        </div>
                        <div class="mini-list-row">
                            <div>
                                <strong><?= $total_subjects ?> subjects active</strong>
                            </div>
                            <span class="pill pill-success"><?= $total_subjects ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
