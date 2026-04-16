<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
redirectIfLoggedIn();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = sanitize($_POST['name']);
    $email    = sanitize($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if (empty($name) || empty($email) || empty($password)) {
        $error = "Please fill all fields!";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match!";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters!";
    } else {
        // Check if email exists
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        if (mysqli_stmt_num_rows($stmt) > 0) {
            $error = "This email is already registered!";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt2 = mysqli_prepare($conn, "INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt2, "sss", $name, $email, $hash);
            if (mysqli_stmt_execute($stmt2)) {
                $success = "Account created! Please login.";
            } else {
                $error = "Something went wrong, please try again!";
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
    <title>Register — Study Planner</title>
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
        <h2>Start strong. 🌟</h2>
        <p>Build your study system once and stay on track every week 📈</p>

        <div class="auth-feature-list">
            <div class="auth-feature-item">
                <i class="bi bi-journal-bookmark-fill"></i>
                <div>
                    <strong>📘 Subjects</strong>
                    <span>Add syllabus</span>
                </div>
            </div>
            <div class="auth-feature-item">
                <i class="bi bi-check2-square"></i>
                <div>
                    <strong>🗓️ Tasks</strong>
                    <span>Add sessions</span>
                </div>
            </div>
            <div class="auth-feature-item">
                <i class="bi bi-graph-up-arrow"></i>
                <div>
                    <strong>📊 Progress</strong>
                    <span>Stay on track</span>
                </div>
            </div>
        </div>
    </section>

    <section class="auth-card fade-in-up">
        <div class="auth-header">
            <div class="eyebrow mb-3">
                <span class="eyebrow-dot"></span>
                Register 📝
            </div>
            <h4>Create your account 🎉</h4>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?> <a href="login.php">Login here</a></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" placeholder="Your full name" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Min 6 characters" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Same password again" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Create Account 🚀</button>
        </form>

        <p class="auth-meta">Already have an account? <a href="login.php">Login</a></p>
    </section>
</div>
</body>
</html>
