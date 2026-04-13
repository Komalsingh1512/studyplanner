<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
requireLogin();

$uid     = $_SESSION['user_id'];
$task_id = (int)$_POST['task_id'];
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : 'dashboard.php';

// Toggle is_completed
mysqli_query($conn,
    "UPDATE study_tasks SET is_completed = NOT is_completed
     WHERE id = $task_id AND user_id = $uid"
);

header("Location: $redirect");
exit();
?>
