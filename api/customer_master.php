<?php
/**
 * WIP Management Portal - Customer Master API Endpoint (PHP + MSSQL)
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

// Ensure customer_master table exists
try {
    $tableCheck = $pdo->query("SELECT * FROM sysobjects WHERE name='customer_master' AND xtype='U'");
    if (!$tableCheck->fetch()) {
        $pdo->exec("
            CREATE TABLE customer_master (
                id INT IDENTITY(1,1) PRIMARY KEY,
                customer_code NVARCHAR(50) NULL,
                customer_name NVARCHAR(150) NOT NULL,
                contact_person NVARCHAR(100) NULL,
                phone NVARCHAR(50) NULL,
                email NVARCHAR(100) NULL,
                gstin NVARCHAR(50) NULL,
                address NVARCHAR(255) NULL,
                status NVARCHAR(50) DEFAULT 'Active',
                created_at DATETIME DEFAULT GETDATE(),
                updated_at DATETIME DEFAULT GETDATE()
            )
        ");
    }
} catch (PDOException $e) {
    // Ignore if table check or creation fails due to permissions, query will fail below if table doesn't exist
}

$method = $_SERVER['REQUEST_METHOD'];

// Handle GET - Fetch all customer records
if ($method === 'GET') {
    requirePermission('customer_master', 'read');
    try {
        $stmt = $pdo->query("SELECT id, customer_code, customer_name, contact_person, phone, email, gstin, address, status, created_at FROM customer_master ORDER BY id DESC");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $items,
            'count' => count($items)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Query error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle POST - create, update, delete
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'create');

    // 1. DELETE ACTION
    if ($action === 'delete') {
        requirePermission('customer_master', 'delete');
        $id = intval($data['id'] ?? 0);
        $customerCode = trim($data['customer_code'] ?? '');

        if ($id <= 0 && empty($customerCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Customer ID or Customer Code is required for deletion.']);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM customer_master WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM customer_master WHERE customer_code = ?");
                $stmt->execute([$customerCode]);
            }

            echo json_encode(['success' => true, 'message' => 'Customer deleted successfully.']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Common fields
    $customerCode = trim($data['customer_code'] ?? '');
    $customerName = trim($data['customer_name'] ?? '');
    $contactPerson = trim($data['contact_person'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');
    $gstin = trim($data['gstin'] ?? '');
    $address = trim($data['address'] ?? $data['city'] ?? '');
    $status = trim($data['status'] ?? 'Active');

    if (empty($status)) {
        $status = 'Active';
    }

    // Validation - Customer Name is required
    if (empty($customerName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Customer Name is required.']);
        exit;
    }

    // 2. UPDATE ACTION
    if ($action === 'update') {
        requirePermission('customer_master', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid Customer ID is required for update.']);
            exit;
        }

        try {
            $finalCustomerCode = !empty($customerCode) ? $customerCode : null;

            // Check duplicate code (if code is provided)
            if ($finalCustomerCode !== null) {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM customer_master WHERE customer_code = ? AND id != ?");
                $checkStmt->execute([$finalCustomerCode, $id]);
                $exists = $checkStmt->fetch();
                if ($exists && $exists['cnt'] > 0) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => "Customer Code '{$finalCustomerCode}' is already in use by another customer."]);
                    exit;
                }
            }

            $updateStmt = $pdo->prepare("
                UPDATE customer_master 
                SET customer_code = ?, customer_name = ?, contact_person = ?, phone = ?, email = ?, gstin = ?, address = ?, status = ?, updated_at = GETDATE()
                WHERE id = ?
            ");
            $updateStmt->execute([$finalCustomerCode, $customerName, $contactPerson, $phone, $email, $gstin, $address, $status, $id]);

            echo json_encode([
                'success' => true, 
                'message' => "Customer '{$customerName}' updated successfully.",
                'data' => [
                    'id' => $id,
                    'customer_code' => $finalCustomerCode,
                    'customer_name' => $customerName,
                    'contact_person' => $contactPerson,
                    'phone' => $phone,
                    'email' => $email,
                    'gstin' => $gstin,
                    'address' => $address,
                    'status' => $status
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. CREATE ACTION
    requirePermission('customer_master', 'create');
    try {
        $finalCustomerCode = !empty($customerCode) ? $customerCode : null;

        // Check duplicate code if entered manually
        if ($finalCustomerCode !== null) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM customer_master WHERE customer_code = ?");
            $checkStmt->execute([$finalCustomerCode]);
            $exists = $checkStmt->fetch();
            if ($exists && $exists['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Customer Code '{$finalCustomerCode}' already exists."]);
                exit;
            }
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO customer_master (customer_code, customer_name, contact_person, phone, email, gstin, address, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $insertStmt->execute([$finalCustomerCode, $customerName, $contactPerson, $phone, $email, $gstin, $address, $status]);

        $newId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Customer '{$customerName}' created successfully.",
            'data' => [
                'id' => $newId,
                'customer_code' => $finalCustomerCode,
                'customer_name' => $customerName,
                'contact_person' => $contactPerson,
                'phone' => $phone,
                'email' => $email,
                'gstin' => $gstin,
                'address' => $address,
                'status' => $status
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
