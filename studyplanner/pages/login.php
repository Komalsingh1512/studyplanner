<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
redirectIfLoggedIn();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please enter email and password!";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, name, email, password_hash FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user   = mysqli_fetch_assoc($result);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Incorrect email or password!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260406-1433">
</head>
<body class="auth-page">
<div class="auth-layout fade-in-up">
    <section class="auth-hero">
        <div class="auth-logo">SP</div>
        <div class="eyebrow mb-3">
            <span class="eyebrow-dot"></span>
            Study Planner
        </div>
        <h1>Study smarter. ✨</h1>
        <p>Plan your sessions, track progress, and keep momentum every day 🚀</p>

        <div class="auth-feature-list">
            <div class="auth-feature-item">
                <i class="bi bi-grid-1x2-fill"></i>
                <div>
                    <strong>📚 Subjects</strong>
                    <span>Track progress</span>
                </div>
            </div>
            <div class="auth-feature-item">
                <i class="bi bi-clock-history"></i>
                <div>
                    <strong>✅ Tasks</strong>
                    <span>Plan your day</span>
                </div>
            </div>
            <div class="auth-feature-item">
                <i class="bi bi-stars"></i>
                <div>
                    <strong>🤖 AI Plan</strong>
                    <span>Get a quick plan</span>
                </div>
            </div>
        </div>
    </section>

    <section class="auth-card fade-in-up">
        <div class="auth-header">
            <div class="eyebrow mb-3">
                <span class="eyebrow-dot"></span>
                Login 🔐
            </div>
            <h4>Welcome back 👋</h4>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Your password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login ✨</button>
        </form>

        <p class="auth-meta">New here? <a href="register.php">Create account</a></p>
    </section>
</div>
</body>
</html>
