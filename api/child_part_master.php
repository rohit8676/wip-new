<?php
/**
 * WIP Management Portal - Child Part Master API Endpoint (PHP + MSSQL)
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

// Handle GET request - Fetch all Child Part records
if ($method === 'GET') {
    requirePermission('child_part_master', 'read');
    try {
        $stmt = $pdo->query("SELECT id, part_code, part_name, grade_spec, size_dimension, nos_per_kg, ISNULL(gross_weight, 0) as gross_weight, ISNULL(min_stock, 0) as min_stock, ISNULL(max_stock, 0) as max_stock, current_stock, uom, status, created_at FROM child_part_master ORDER BY id DESC");
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
        requirePermission('child_part_master', 'delete');
        $id = intval($data['id'] ?? 0);
        $partCode = trim($data['part_code'] ?? '');

        if ($id <= 0 && empty($partCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Child Part ID or Part Code is required for deletion.']);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM child_part_master WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM child_part_master WHERE part_code = ?");
                $stmt->execute([$partCode]);
            }

            echo json_encode(['success' => true, 'message' => 'Child part deleted successfully.']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Common fields for create / update
    $partCode = trim($data['part_code'] ?? '');
    $partName = trim($data['part_name'] ?? '');
    $gradeSpec = trim($data['grade_spec'] ?? '');
    $sizeDimension = trim($data['size_dimension'] ?? '');
    $nosPerKg = trim($data['nos_per_kg'] ?? '');
    $grossWeight = isset($data['gross_weight']) ? floatval($data['gross_weight']) : 0.0;
    $minStock = isset($data['min_stock']) ? floatval($data['min_stock']) : 0.0;
    $maxStock = isset($data['max_stock']) ? floatval($data['max_stock']) : 0.0;
    $uom = trim($data['uom'] ?? 'NOS');
    $currentStock = isset($data['current_stock']) ? floatval($data['current_stock']) : 0.0;
    $status = trim($data['status'] ?? 'Active');

    if (empty($status)) {
        $status = 'Active';
    }

    // Validation
    if (empty($partCode) || empty($partName) || empty($uom)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Child Part Code, Part Name, and UOM are required.']);
        exit;
    }

    // 2. UPDATE ACTION
    if ($action === 'update') {
        requirePermission('child_part_master', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid ID is required for update.']);
            exit;
        }

        try {
            // Check if Part Code is taken by another row
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM child_part_master WHERE part_code = ? AND id != ?");
            $checkStmt->execute([$partCode, $id]);
            $exists = $checkStmt->fetch();
            if ($exists && $exists['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Part Code '{$partCode}' is already in use by another item."]);
                exit;
            }

            // Check if Part Name (RM Name) is taken by another row (Duplicate Name Check)
            $checkNameStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM child_part_master WHERE LOWER(LTRIM(RTRIM(part_name))) = LOWER(?) AND id != ?");
            $checkNameStmt->execute([$partName, $id]);
            $existsName = $checkNameStmt->fetch();
            if ($existsName && $existsName['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Part/RM Name '{$partName}' is already in use by another item."]);
                exit;
            }

            $updateStmt = $pdo->prepare("
                UPDATE child_part_master 
                SET part_code = ?, part_name = ?, grade_spec = ?, size_dimension = ?, nos_per_kg = ?, gross_weight = ?, min_stock = ?, max_stock = ?, current_stock = ?, uom = ?, status = ?, updated_at = GETDATE()
                WHERE id = ?
            ");
            $updateStmt->execute([$partCode, $partName, $gradeSpec, $sizeDimension, $nosPerKg, $grossWeight, $minStock, $maxStock, $currentStock, $uom, $status, $id]);

            echo json_encode([
                'success' => true, 
                'message' => "Child Part '{$partCode}' updated successfully.",
                'data' => [
                    'id' => $id,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'grade_spec' => $gradeSpec,
                    'size_dimension' => $sizeDimension,
                    'nos_per_kg' => $nosPerKg,
                    'gross_weight' => $grossWeight,
                    'min_stock' => $minStock,
                    'max_stock' => $maxStock,
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
    requirePermission('child_part_master', 'create');
    try {
        // Check for duplicate Part Code
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM child_part_master WHERE part_code = ?");
        $checkStmt->execute([$partCode]);
        $exists = $checkStmt->fetch();
        if ($exists && $exists['cnt'] > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Part Code '{$partCode}' already exists."]);
            exit;
        }

        // Check for duplicate Part Name (RM Name Duplicate Check)
        $checkNameStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM child_part_master WHERE LOWER(LTRIM(RTRIM(part_name))) = LOWER(?)");
        $checkNameStmt->execute([$partName]);
        $existsName = $checkNameStmt->fetch();
        if ($existsName && $existsName['cnt'] > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Part/RM Name '{$partName}' already exists."]);
            exit;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO child_part_master (part_code, part_name, grade_spec, size_dimension, nos_per_kg, gross_weight, min_stock, max_stock, current_stock, uom, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $insertStmt->execute([$partCode, $partName, $gradeSpec, $sizeDimension, $nosPerKg, $grossWeight, $minStock, $maxStock, $currentStock, $uom, $status]);

        $newId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Child Part '{$partCode}' created successfully.",
            'data' => [
                'id' => $newId,
                'part_code' => $partCode,
                'part_name' => $partName,
                'grade_spec' => $gradeSpec,
                'size_dimension' => $sizeDimension,
                'nos_per_kg' => $nosPerKg,
                'gross_weight' => $grossWeight,
                'min_stock' => $minStock,
                'max_stock' => $maxStock,
                'current_stock' => $currentStock,
                'uom' => $uom,
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
echo json_encode(['success' => false,
 'message' => 'Method not allowed.']);
