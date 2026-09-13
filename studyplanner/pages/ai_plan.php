<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/runtime.php';
requireLogin();

$user = getCurrentUser();
$uid  = $user['id'];

// Get user's subjects
$sub_res  = mysqli_query($conn, "SELECT * FROM subjects WHERE user_id = $uid ORDER BY created_at DESC, id DESC");
$subjects = [];
while ($s = mysqli_fetch_assoc($sub_res)) $subjects[] = $s;

$ai_plan  = '';
$ai_error = '';
$ai_warning = '';

// Generate AI plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $days        = (int)$_POST['days'];
    $hours_daily = (int)$_POST['hours_daily'];
    $focus       = sanitize($_POST['focus']);

    $api_key_value = getGroqApiKey();

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

Write the entire plan in clear, plain English only — do not mix in Hindi or any other language.
Format the response as clean Markdown: use '##' for each day's heading, a Markdown table with columns Session | Time | Topic | Detail for that day's sessions, and a bolded 'Daily Goal:' line after each table. Be specific about topics, not generic. Do not add any extra commentary before or after the plan.";

    if (empty($api_key_value)) {
        $ai_error = "AI is not configured on this project. Add a Groq API key in config/runtime.json.";
    } elseif (!function_exists('curl_init')) {
        $ai_error = "cURL is not enabled in PHP.";
    } else {
        // Scale the response budget with the number of days requested — a fixed
        // 2000-token cap was truncating anything beyond ~5 days of plan content,
        // so longer plans (14/30 days) silently got cut short mid-generation.
        $max_tokens = min(8000, max(2000, 350 * $days + 600));

        // GPT-OSS models spend part of the token budget on hidden "reasoning"
        // before writing the final answer; keeping that light leaves more
        // budget for the actual visible plan. Other model families don't
        // support this parameter, so only send it for GPT-OSS models.
        $model_name = getGroqModel();
        $payload_data = [
            'model'      => $model_name,
            'max_tokens' => $max_tokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];
        if (stripos($model_name, 'gpt-oss') !== false) {
            $payload_data['reasoning_effort'] = 'low';
        }
        $payload = json_encode($payload_data);

        $ch = curl_init(getGroqApiUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key_value
            ],
            CURLOPT_TIMEOUT => 90
        ]);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $ai_error = "Request failed: " . $curl_error;
        } else {
            $data = json_decode($response, true);
            if ($http_code === 200 && isset($data['choices'][0]['message']['content'])) {
                $ai_plan = $data['choices'][0]['message']['content'];
                $_SESSION['last_ai_plan'] = $ai_plan;

                // If the model ran out of tokens before finishing, say so instead
                // of silently handing back a plan that stops partway through.
                $finish_reason = $data['choices'][0]['finish_reason'] ?? '';
                if ($finish_reason === 'length') {
                    $ai_warning = "The plan below may be incomplete — it hit the response length limit. Try a shorter day range, or generate it again.";
                }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Study Plan — Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260903-1">
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
                        AI powered 🤖
                    </div>

                    <form method="POST" id="aiForm">
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
                    <button onclick="downloadPlanPdf(event)" class="btn btn-primary w-100 mb-2">
                        Download PDF 📄
                    </button>
                    <button onclick="printPlan()" class="btn btn-outline-secondary w-100">
                        Print (browser dialog) 🖨️
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
                    <small>Check the Groq API key in config/runtime.json, your internet connection, and PHP cURL.</small>
                </div>
            <?php endif; ?>

            <?php if ($ai_warning): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($ai_warning) ?>
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
                            Generated by AI 🤖
                        </span>
                    </div>
                    <div class="sp-card-body">
                        <div class="ai-output" id="aiOutput"></div>
                        <script id="aiPlanRaw" type="application/json"><?= json_encode($ai_plan, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
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
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
<script>
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('show');
}
function copyPlan() {
    const raw = document.getElementById('aiPlanRaw');
    const text = raw ? JSON.parse(raw.textContent) : '';
    navigator.clipboard.writeText(text).then(() => {
        alert('Plan copied!');
    });
}
function downloadPlanPdf(event) {
    const element = document.getElementById('aiOutput');
    if (!element || typeof html2pdf === 'undefined') {
        alert('PDF export failed to load. Check your internet connection and try again.');
        return;
    }

    const button = event ? event.currentTarget : null;
    const originalLabel = button ? button.innerHTML : null;
    if (button) {
        button.disabled = true;
        button.innerHTML = 'Preparing PDF…';
    }

    html2pdf()
        .set({
            margin: [10, 8, 10, 8],
            filename: 'ai-study-plan.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['css', 'legacy'] }
        })
        .from(element)
        .save()
        .finally(() => {
            if (button) {
                button.disabled = false;
                button.innerHTML = originalLabel;
            }
        });
}
function printPlan() {
    window.print();
}

(function renderAiPlan() {
    const rawEl = document.getElementById('aiPlanRaw');
    const outEl = document.getElementById('aiOutput');
    if (!rawEl || !outEl) return;
    const markdown = JSON.parse(rawEl.textContent);
    if (!markdown) return;
    outEl.innerHTML = marked.parse(markdown);
})();
</script>
</body>
</html>
