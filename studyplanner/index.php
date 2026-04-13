<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: pages/dashboard.php");
} else {
    header("Location: pages/login.php");
    echo " hi my name is komal kummari singh ";
}
exit();
