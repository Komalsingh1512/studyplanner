<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: ../pages/login.php");
        exit();
    }
}

function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        header("Location: ../pages/dashboard.php");
        exit();
    }
}

function getCurrentUser() {
    return [
        'id'   => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email'=> $_SESSION['user_email']
    ];
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}
?>
