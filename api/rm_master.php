<?php
/**
 * WIP Management Portal - RM Master API Endpoint (PHP + MSSQL)
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

// Handle GET request - Fetch all RM records
if ($method === 'GET') {
    requirePermission('rm_master', 'read');
    try {
        $stmt = $pdo->query("SELECT id, rm_code, rm_name, grade_spec, size_dimension, current_stock, uom, status, created_at FROM rm_master ORDER BY id DESC");
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

// Handle POST request (create, update, delete)
if ($method === 'POST') {
    // Read input (JSON or form-data)
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'create');

    // 1. DELETE ACTION
    if ($action === 'delete') {
        requirePermission('rm_master', 'delete');
        $id = intval($data['id'] ?? 0);
        $rmCode = trim($data['rm_code'] ?? '');

        if ($id <= 0 && empty($rmCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Material ID or RM Code is required for deletion.']);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM rm_master WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM rm_master WHERE rm_code = ?");
                $stmt->execute([$rmCode]);
            }

            echo json_encode(['success' => true, 'message' => 'Material deleted successfully.']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Common fields for create / update
    $rmCode = trim($data['rm_code'] ?? '');
    $rmName = trim($data['rm_name'] ?? '');
    $gradeSpec = trim($data['grade_spec'] ?? '');
    $sizeDimension = trim($data['size_dimension'] ?? '');
    $uom = trim($data['uom'] ?? 'KG');
    $currentStock = isset($data['current_stock']) ? floatval($data['current_stock']) : 0.0;
    $status = trim($data['status'] ?? 'Active');

    // Default status if empty
    if (empty($status)) {
        $status = 'Active';
    }

    // Validation
    if (empty($rmCode) || empty($rmName) || empty($uom)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'RM Code, RM Name, and UOM are required.']);
        exit;
    }

    // 2. UPDATE ACTION
    if ($action === 'update') {
        requirePermission('rm_master', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid material ID is required for update.']);
            exit;
        }

        try {
            // Check if RM Code is taken by another row
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM rm_master WHERE rm_code = ? AND id != ?");
            $checkStmt->execute([$rmCode, $id]);
            $exists = $checkStmt->fetch();
            if ($exists && $exists['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "RM Code '{$rmCode}' is already in use by another item."]);
                exit;
            }

            $updateStmt = $pdo->prepare("
                UPDATE rm_master 
                SET rm_code = ?, rm_name = ?, grade_spec = ?, size_dimension = ?, current_stock = ?, uom = ?, status = ?, updated_at = GETDATE()
                WHERE id = ?
            ");
            $updateStmt->execute([$rmCode, $rmName, $gradeSpec, $sizeDimension, $currentStock, $uom, $status, $id]);

            echo json_encode([
                'success' => true, 
                'message' => "Material '{$rmCode}' updated successfully.",
                'data' => [
                    'id' => $id,
                    'rm_code' => $rmCode,
                    'rm_name' => $rmName,
                    'grade_spec' => $gradeSpec,
                    'size_dimension' => $sizeDimension,
                    'current_stock' => $currentStock,
                    'uom' => $uom,
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
    requirePermission('rm_master', 'create');
    try {
        // Check for duplicate RM Code
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM rm_master WHERE rm_code = ?");
        $checkStmt->execute([$rmCode]);
        $exists = $checkStmt->fetch();
        if ($exists && $exists['cnt'] > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "RM Code '{$rmCode}' already exists in the catalog."]);
            exit;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO rm_master (rm_code, rm_name, grade_spec, size_dimension, current_stock, uom, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $insertStmt->execute([$rmCode, $rmName, $gradeSpec, $sizeDimension, $currentStock, $uom, $status]);

        $newId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Material '{$rmCode}' created successfully.",
            'data' => [
                'id' => $newId,
                'rm_code' => $rmCode,
                'rm_name' => $rmName,
                'grade_spec' => $gradeSpec,
                'size_dimension' => $sizeDimension,
                'uom' => $uom,
                'current_stock' => $currentStock,
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
