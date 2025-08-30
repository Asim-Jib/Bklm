<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
}

function redirectIfNotSuperAdmin() {
    redirectIfNotLoggedIn();
    if (!isSuperAdmin()) {
        header('Location: ../index.php');
        exit();
    }
}
?>