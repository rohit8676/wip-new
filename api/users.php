<?php
/**
 * WIP Management Portal - User Management API Endpoint (PHP + MSSQL)
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/rbac.php';

$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Handle GET - Fetch all users
if ($method === 'GET') {
    requirePermission('users', 'read');
    try {
        $stmt = $pdo->query("
            SELECT id, email, full_name, role, 
                   CONVERT(VARCHAR(19), created_at, 120) AS created_at,
                   CONVERT(VARCHAR(19), last_login, 120) AS last_login
            FROM users 
            ORDER BY id ASC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $users,
            'count' => count($users)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Query error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle POST - Create, Update, Delete
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'create');

    // 1. DELETE USER
    if ($action === 'delete') {
        requirePermission('users', 'delete');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
            exit;
        }

        // Prevent self-deletion
        if ($id === intval($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own logged-in account.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2. CREATE NEW USER
    if ($action === 'create') {
        requirePermission('users', 'create');
        $fullName = trim($data['full_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');
        $role = trim($data['role'] ?? 'User');

        if (empty($fullName) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Full Name, Email, and Password are required.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
            exit;
        }

        try {
            // Check for duplicate email
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $checkStmt->execute([$email]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'A user with this email address already exists.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, password, role, created_at)
                VALUES (?, ?, ?, ?, GETDATE())
            ");
            $stmt->execute([$fullName, $email, $password, $role]);

            echo json_encode(['success' => true, 'message' => 'User created successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. UPDATE EXISTING USER
    if ($action === 'update') {
        requirePermission('users', 'update');
        $id = intval($data['id'] ?? 0);
        $fullName = trim($data['full_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');
        $role = trim($data['role'] ?? 'User');

        if ($id <= 0 || empty($fullName) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Valid User ID, Full Name, and Email are required.']);
            exit;
        }

        try {
            // Check for duplicate email on other users
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $id]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Another user already uses this email address.']);
                exit;
            }

            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, password = ?, role = ? WHERE id = ?");
                $stmt->execute([$fullName, $email, $password, $role, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$fullName, $email, $role, $id]);
            }

            echo json_encode(['success' => true, 'message' => 'User updated successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
    exit;
}
