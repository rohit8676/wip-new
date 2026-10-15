<?php
/**
 * WIP Management Portal - Session Authentication Check
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    // User is not logged in, redirect to login page
    header('Location: index.php');
    exit;
}

// User details helper
$currentUser = [
    'id'    => $_SESSION['user_id'] ?? null,
    'name'  => $_SESSION['user_name'] ?? 'User',
    'email' => $_SESSION['user_email'] ?? '',
    'role'  => $_SESSION['user_role'] ?? 'User'
];

// Include RBAC Permission Helper
require_once __DIR__ . '/../includes/rbac.php';
