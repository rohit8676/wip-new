<?php
/**
 * WIP Management Portal - Vendor Master API Endpoint (PHP + MSSQL)
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

// Handle GET - Fetch all vendor records
if ($method === 'GET') {
    requirePermission('vendor_master', 'read');
    try {
        $stmt = $pdo->query("SELECT id, vendor_code, vendor_name, contact_person, phone, email, gstin, address, status, created_at FROM vendor_master ORDER BY id DESC");
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
        requirePermission('vendor_master', 'delete');
        $id = intval($data['id'] ?? 0);
        $vendorCode = trim($data['vendor_code'] ?? '');

        if ($id <= 0 && empty($vendorCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Vendor ID or Vendor Code is required for deletion.']);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM vendor_master WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM vendor_master WHERE vendor_code = ?");
                $stmt->execute([$vendorCode]);
            }

            echo json_encode(['success' => true, 'message' => 'Vendor deleted successfully.']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Common fields
    $vendorCode = trim($data['vendor_code'] ?? '');
    $vendorName = trim($data['vendor_name'] ?? '');
    $contactPerson = trim($data['contact_person'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');
    $gstin = trim($data['gstin'] ?? '');
    $address = trim($data['address'] ?? $data['city'] ?? '');
    $status = trim($data['status'] ?? 'Active');

    if (empty($status)) {
        $status = 'Active';
    }

    // Validation
    if (empty($vendorCode) || empty($vendorName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Vendor Code and Vendor Name are required.']);
        exit;
    }

    // 2. UPDATE ACTION
    if ($action === 'update') {
        requirePermission('vendor_master', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid Vendor ID is required for update.']);
            exit;
        }

        try {
            // Check duplicate code
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM vendor_master WHERE vendor_code = ? AND id != ?");
            $checkStmt->execute([$vendorCode, $id]);
            $exists = $checkStmt->fetch();
            if ($exists && $exists['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Vendor Code '{$vendorCode}' is already in use by another vendor."]);
                exit;
            }

            $updateStmt = $pdo->prepare("
                UPDATE vendor_master 
                SET vendor_code = ?, vendor_name = ?, contact_person = ?, phone = ?, email = ?, gstin = ?, address = ?, status = ?, updated_at = GETDATE()
                WHERE id = ?
            ");
            $updateStmt->execute([$vendorCode, $vendorName, $contactPerson, $phone, $email, $gstin, $address, $status, $id]);

            echo json_encode(['success' => true, 'message' => "Vendor '{$vendorCode}' updated successfully."]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. CREATE ACTION
    requirePermission('vendor_master', 'create');
    try {
        // Check duplicate code
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM vendor_master WHERE vendor_code = ?");
        $checkStmt->execute([$vendorCode]);
        $exists = $checkStmt->fetch();
        if ($exists && $exists['cnt'] > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Vendor Code '{$vendorCode}' already exists."]);
            exit;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO vendor_master (vendor_code, vendor_name, contact_person, phone, email, gstin, address, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $insertStmt->execute([$vendorCode, $vendorName, $contactPerson, $phone, $email, $gstin, $address, $status]);

        $newId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Vendor '{$vendorCode}' created successfully.",
            'data' => [
                'id' => $newId,
                'vendor_code' => $vendorCode,
                'vendor_name' => $vendorName,
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
