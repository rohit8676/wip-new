<?php
/**
 * WIP Management Portal - Process Master API Endpoint (PHP + MSSQL)
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

// Handle GET request - Fetch all Process records
if ($method === 'GET') {
    requirePermission('process_master', 'read');
    try {
        $stmt = $pdo->query("SELECT id, process_code, process_name, status, remarks, created_at FROM process_master ORDER BY id DESC");
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
        requirePermission('process_master', 'delete');
        $id = intval($data['id'] ?? 0);
        $processCode = trim($data['process_code'] ?? '');

        if ($id <= 0 && empty($processCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Process ID or Process Code is required for deletion.']);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM process_master WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM process_master WHERE process_code = ?");
                $stmt->execute([$processCode]);
            }

            echo json_encode(['success' => true, 'message' => 'Process deleted successfully.']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Common fields
    $processCode = trim($data['process_code'] ?? '');
    $processName = trim($data['process_name'] ?? '');
    $status = trim($data['status'] ?? 'Active');
    $remarks = trim($data['remarks'] ?? '');

    if (empty($status)) {
        $status = 'Active';
    }

    // Validation
    if (empty($processCode) || empty($processName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Process Code and Process Name are required.']);
        exit;
    }

    // 2. UPDATE ACTION
    if ($action === 'update') {
        requirePermission('process_master', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid ID is required for update.']);
            exit;
        }

        try {
            // Check if Process Code is taken by another row
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM process_master WHERE process_code = ? AND id != ?");
            $checkStmt->execute([$processCode, $id]);
            $exists = $checkStmt->fetch();
            if ($exists && $exists['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Process Code '{$processCode}' is already in use by another process."]);
                exit;
            }

            $updateStmt = $pdo->prepare("
                UPDATE process_master 
                SET process_code = ?, process_name = ?, status = ?, remarks = ?, updated_at = GETDATE()
                WHERE id = ?
            ");
            $updateStmt->execute([$processCode, $processName, $status, $remarks, $id]);

            echo json_encode([
                'success' => true,
                'message' => "Process '{$processCode}' updated successfully.",
                'data' => [
                    'id' => $id,
                    'process_code' => $processCode,
                    'process_name' => $processName,
                    'status' => $status,
                    'remarks' => $remarks
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. CREATE ACTION
    requirePermission('process_master', 'create');
    try {
        // Check for duplicate Process Code
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM process_master WHERE process_code = ?");
        $checkStmt->execute([$processCode]);
        $exists = $checkStmt->fetch();
        if ($exists && $exists['cnt'] > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Process Code '{$processCode}' already exists."]);
            exit;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO process_master (process_code, process_name, status, remarks, created_at, updated_at)
            VALUES (?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $insertStmt->execute([$processCode, $processName, $status, $remarks]);

        $newId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Process '{$processCode}' created successfully.",
            'data' => [
                'id' => $newId,
                'process_code' => $processCode,
                'process_name' => $processName,
                'status' => $status,
                'remarks' => $remarks
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
