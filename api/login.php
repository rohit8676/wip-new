<?php
/**
 * WIP Management Portal - Login API Endpoint (PHP + MSSQL)
 */

header('Content-Type: application/json; charset=utf-8');

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Read input (support JSON payload and regular form-data)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$email = '';
$password = '';

if (is_array($data)) {
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
} else {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
}

// Basic validation
if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Email and Password are required.'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);
    exit;
}

// Connect to MSSQL
$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please check MSSQL Server status.'
    ]);
    exit;
}

try {
    // Prepared statement query
    $stmt = $pdo->prepare("SELECT id, email, password, full_name, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Check normal plain text password (with fallback to hashed)
    $isPasswordMatch = ($user && ($password === $user['password'] || password_verify($password, $user['password'])));

    if ($isPasswordMatch) {
        // Prevent session fixation
        session_regenerate_id(true);

        // Store user info in session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['logged_in_time'] = time();

        // Pre-load RBAC permissions into session
        require_once __DIR__ . '/../includes/rbac.php';
        loadRolePermissions($user['role']);

        // Update last login in MSSQL
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = GETDATE() WHERE id = ?");
        $updateStmt->execute([$user['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful! Redirecting...',
            'redirect' => 'dashboard.php',
            'user' => [
                'name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
        exit;
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password. Please try again.'
        ]);
        exit;
    }
} catch (PDOException $e) {
    error_log("Login Query Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal database error occurred.'
    ]);
    exit;
}
