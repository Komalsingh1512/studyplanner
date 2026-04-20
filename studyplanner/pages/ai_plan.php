<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/ai.php';
requireLogin();

$user = getCurrentUser();
$uid  = $user['id'];

// Get user's subjects
$sub_res  = mysqli_query($conn, "SELECT * FROM subjects WHERE user_id = $uid ORDER BY created_at DESC, id DESC");
$subjects = [];
while ($s = mysqli_fetch_assoc($sub_res)) $subjects[] = $s;

$ai_plan  = '';
$ai_error = '';
$api_key_value = '';

// Generate AI plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $days        = (int)$_POST['days'];
    $hours_daily = (int)$_POST['hours_daily'];
    $focus       = sanitize($_POST['focus']);
    $submitted_api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';

    if ($submitted_api_key !== '') {
        $_SESSION['anthropic_api_key'] = $submitted_api_key;
    }

    $api_key_value = getAiApiKey();

    // Build subject info for prompt
    $subject_info = '';
    foreach ($subjects as $s) {
        $pct = $s['total_topics'] > 0
            ? round(($s['completed_topics'] / $s['total_topics']) * 100)
            : 0;
        $days_left = $s['exam_date']
            ? (int)((strtotime($s['exam_date']) - time()) / 86400)
            : 'unknown';
        $subject_info .= "- {$s['name']}: {$pct}% complete, difficulty: {$s['difficulty']}, exam in {$days_left} days\n";
    }

    $prompt = "You are an expert study planner for MCA (Master of Computer Applications) students in India.

Student details:
- Name: {$user['name']}
- Available days: {$days}
- Daily study hours: {$hours_daily}
- Special focus: {$focus}

Subjects:
{$subject_info}

Create a detailed {$days}-day study plan. For each day give:
1. Date (Day 1, Day 2, etc.)
2. Morning session (subject + specific topics)
3. Afternoon session (subject + specific topics)
4. Evening (revision/practice)
5. Daily goal

Prioritize subjects with:
- Less completion percentage (weak areas first)
- Closer exam dates
- Higher difficulty

Format the response in clear sections. Write in a mix of Hindi and English (Hinglish) to make it relatable for Indian MCA students. Be specific about topics, not generic.";

    if (empty($api_key_value)) {
        $ai_error = "Add your Anthropic API key first.";
    } elseif (!function_exists('curl_init')) {
        $ai_error = "cURL is not enabled in PHP.";
    } else {
        $payload = json_encode([
            'model'      => AI_MODEL,
            'max_tokens' => 2000,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
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
            $ai_error = "Request failed: " . $curl_error;
        } else {
            $data = json_decode($response, true);
            if ($http_code === 200 && isset($data['content'][0]['text'])) {
                $ai_plan = $data['content'][0]['text'];
                $_SESSION['last_ai_plan'] = $ai_plan;
            } elseif (isset($data['error']['message'])) {
                $ai_error = $data['error']['message'];
            } elseif ($http_code === 401) {
                $ai_error = "Invalid API key.";
            } elseif ($http_code === 429) {
                $ai_error = "Rate limit reached. Try again later.";
            } else {
                $ai_error = "Request failed. HTTP " . $http_code;
            }
        }
    }
}

if ($api_key_value === '') {
    $api_key_value = getAiApiKey();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Study Plan — Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260406-1433">
</head>
<body>
<div class="app-shell">
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div style="font-size:15px;font-weight:700;color:var(--primary-dark);">Generating plan...</div>
</div>

<nav class="navbar sp-navbar">
    <div class="container-fluid nav-shell">
        <a class="navbar-brand sp-brand" href="dashboard.php">
            <span class="brand-logo">SP</span> Study Planner
        </a>
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
                AI Plan 🤖
            </div>
            <h1 class="page-title">Generate a smarter study plan</h1>
            <p class="page-subtitle">Let AI turn your subjects, deadlines, and weak areas into a focused plan ✨</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="sp-card fade-in-up">
                <div class="sp-card-header">
                    <div>
                        <h6 class="sp-card-title">Generate AI Plan 🚀</h6>
                    </div>
                </div>
                <div class="sp-card-body">

                    <?php if (empty($subjects)): ?>
                        <div class="alert alert-warning">
                            Please <a href="subjects.php">add subjects</a> first, then generate AI plan!
                        </div>
                    <?php else: ?>

                    <div class="ai-badge">
                        <div class="ai-dot"></div>
                        Claude AI powered 🤖
                    </div>

                    <form method="POST" id="aiForm">
                        <div class="mb-3">
                            <label class="form-label">Anthropic API Key</label>
                            <input type="password" name="api_key" class="form-control"
                                   placeholder="sk-ant-..."
                                   value="<?= htmlspecialchars($api_key_value) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Days</label>
                            <select name="days" class="form-select">
                                <option value="3">3 days</option>
                                <option value="7" selected>7 days</option>
                                <option value="14">14 days</option>
                                <option value="30">30 days</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Hours per day</label>
                            <select name="hours_daily" class="form-select">
                                <option value="3">3 hours</option>
                                <option value="5" selected>5 hours</option>
                                <option value="7">7 hours</option>
                                <option value="10">10 hours</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Focus</label>
                            <input type="text" name="focus" class="form-control"
                                   placeholder="e.g. focus on numericals, or theory revision">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Subjects</label>
                            <div class="mini-list">
                                <?php foreach ($subjects as $s):
                                    $pct = $s['total_topics'] > 0
                                        ? round(($s['completed_topics'] / $s['total_topics']) * 100)
                                        : 0;
                                ?>
                                <div class="mini-list-row">
                                    <div>
                                        <strong><?= htmlspecialchars($s['name']) ?></strong>
                                        <span><?= htmlspecialchars(ucfirst($s['difficulty'])) ?></span>
                                    </div>
                                    <span class="pill <?= $pct >= 70 ? 'pill-success' : ($pct >= 40 ? 'pill-warning' : 'pill-danger') ?>"><?= $pct ?>%</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" name="generate" class="btn btn-primary w-100"
                                onclick="showLoading()">
                            Generate AI Plan ✨
                        </button>
                    </form>

                    <?php endif; ?>
                </div>
            </div>

            <?php if ($ai_plan): ?>
            <div class="sp-card mt-3 fade-in-up">
                <div class="sp-card-body">
                    <button onclick="copyPlan()" class="btn btn-outline-success w-100 mb-2">
                        Copy Plan 📋
                    </button>
                    <button onclick="printPlan()" class="btn btn-outline-secondary w-100">
                        Print / Save PDF 🖨️
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-8">
            <?php if ($ai_error): ?>
                <div class="alert alert-danger">
                    <strong>Error:</strong> <?= htmlspecialchars($ai_error) ?>
                    <hr>
                    <small>Check your key, internet, and PHP cURL.</small>
                </div>
            <?php endif; ?>

            <?php if ($ai_plan): ?>
                <div class="sp-card fade-in-up">
                    <div class="sp-card-header">
                        <div>
                            <h6 class="sp-card-title">Your AI Study Plan 🌟</h6>
                        </div>
                        <span class="ai-badge mb-0">
                            <span class="ai-dot"></span>
                            Generated by Claude AI 🤖
                        </span>
                    </div>
                    <div class="sp-card-body">
                        <div class="ai-output" id="aiOutput"><?= htmlspecialchars($ai_plan) ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state fade-in-up" style="min-height: 430px;">
                    <div class="empty-state-inner">
                        <div class="empty-state-icon"><i class="bi bi-stars"></i></div>
                        <h3>Your plan will appear here ✨</h3>
                        <p>Choose your options and click generate to get a polished study roadmap.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('show');
}
function copyPlan() {
    const text = document.getElementById('aiOutput').innerText;
    navigator.clipboard.writeText(text).then(() => {
        alert('Plan copied!');
    });
}
function printPlan() {
    window.print();
}
</script>
</body>
</html>
