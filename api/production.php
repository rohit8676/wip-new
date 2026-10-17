<?php
/**
 * WIP Management Portal - Production Entry (DPR) API
 * Endpoint: /api/production.php
 * Handles CRUD operations for shop-floor daily production entries.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/check_auth.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// -----------------------------------------------------------------
// 1. GET REQUEST - Fetch production records
// -----------------------------------------------------------------
if ($method === 'GET') {
    requirePermission('production', 'read');
    try {
        $stmt = $pdo->query("
            SELECT id, mip_no, customer_name, customer_code, so_number, work_order, part_code, part_name,
                   department, received_by, process_name, shift,
                   target_qty, ok_qty, rejected_qty,
                   operator_name, uom, status,
                   CONVERT(VARCHAR(19), created_at, 120) as created_at
            FROM production_entry
            ORDER BY id DESC
        ");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $records,
            'count' => count($records)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------
// 2. POST REQUEST - Create, Update, Delete
// -----------------------------------------------------------------
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'create');

    // -------------------------------------------------------------
    // DELETE ACTION
    // -------------------------------------------------------------
    if ($action === 'delete') {
        requirePermission('production', 'delete');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid Production Entry ID is required.']);
            exit;
        }

        try {
            $delStmt = $pdo->prepare("DELETE FROM production_entry WHERE id = ?");
            $delStmt->execute([$id]);

            echo json_encode([
                'success' => true,
                'message' => 'Production entry deleted successfully.'
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete production entry: ' . $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------
    // CREATE / UPDATE ACTION
    // -------------------------------------------------------------
    $id = intval($data['id'] ?? 0);
    $mipNo = trim($data['mip_no'] ?? '');
    $customerName = trim($data['customer_name'] ?? '');
    $customerCode = trim($data['customer_code'] ?? '');
    $soNumber = trim($data['so_number'] ?? '');
    $workOrder = trim($data['work_order'] ?? '');
    $partCode = trim($data['part_code'] ?? '');
    $partName = trim($data['part_name'] ?? '');
    $department = trim($data['department'] ?? '');
    $receivedBy = trim($data['received_by'] ?? '');
    $processName = trim($data['process_name'] ?? '');
    if (empty($processName)) {
        $processName = 'New Line up';
    }
    $targetQty = floatval($data['target_qty'] ?? 0);
    $okQty = floatval($data['ok_qty'] ?? 0);
    $rejectedQty = floatval($data['rejected_qty'] ?? 0);
    $operatorName = trim($data['operator_name'] ?? '');
    $uom = trim($data['uom'] ?? 'PCS');
    $status = trim($data['status'] ?? '');
    if (empty($status)) {
        $status = 'New Line up';
    }

    // Validation
    if (empty($partCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Part details are required. Please select a Part.']);
        exit;
    }
    if (empty($operatorName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Operator / Technician name is required.']);
        exit;
    }
    if ($okQty < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Produced (OK) quantity cannot be negative.']);
        exit;
    }

    // Duplicate Validation: same MIP slip and Part cannot be saved more than once
    if (!empty($mipNo)) {
        if ($action === 'create' || ($action === 'update' && $id <= 0)) {
            $dupStmt = $pdo->prepare("
                SELECT id FROM production_entry 
                WHERE LOWER(LTRIM(RTRIM(mip_no))) = LOWER(LTRIM(RTRIM(?))) 
                  AND LOWER(LTRIM(RTRIM(part_code))) = LOWER(LTRIM(RTRIM(?)))
            ");
            $dupStmt->execute([$mipNo, $partCode]);
            if ($dupStmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => "Production entry for MIP slip '{$mipNo}' (Part: {$partCode}) has already been saved. It cannot be saved again."
                ]);
                exit;
            }
        } else if ($action === 'update' && $id > 0) {
            $dupStmt = $pdo->prepare("
                SELECT id FROM production_entry 
                WHERE LOWER(LTRIM(RTRIM(mip_no))) = LOWER(LTRIM(RTRIM(?))) 
                  AND LOWER(LTRIM(RTRIM(part_code))) = LOWER(LTRIM(RTRIM(?)))
                  AND id != ?
            ");
            $dupStmt->execute([$mipNo, $partCode, $id]);
            if ($dupStmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => "Another production entry for MIP slip '{$mipNo}' (Part: {$partCode}) already exists. Duplicate entries are not allowed."
                ]);
                exit;
            }
        }
    }

    if ($action === 'update' && $id > 0) {
        // UPDATE EXISTING ENTRY
        requirePermission('production', 'update');
        try {
            $updateSql = "
                UPDATE production_entry SET
                    mip_no = ?,
                    customer_name = ?,
                    customer_code = ?,
                    so_number = ?,
                    work_order = ?,
                    part_code = ?,
                    part_name = ?,
                    department = ?,
                    received_by = ?,
                    process_name = ?,
                    target_qty = ?,
                    ok_qty = ?,
                    rejected_qty = ?,
                    operator_name = ?,
                    uom = ?,
                    status = ?,
                    updated_at = GETDATE()
                WHERE id = ?
            ";
            $upStmt = $pdo->prepare($updateSql);
            $upStmt->execute([
                $mipNo,
                $customerName,
                $customerCode,
                $soNumber,
                $workOrder,
                $partCode,
                $partName,
                $department,
                $receivedBy,
                $processName,
                $targetQty,
                $okQty,
                $rejectedQty,
                $operatorName,
                $uom,
                $status,
                $id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Production entry updated successfully.',
                'data' => [
                    'id' => $id,
                    'mip_no' => $mipNo,
                    'customer_name' => $customerName,
                    'customer_code' => $customerCode,
                    'so_number' => $soNumber,
                    'work_order' => $workOrder,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'department' => $department,
                    'received_by' => $receivedBy,
                    'process_name' => $processName,
                    'target_qty' => $targetQty,
                    'ok_qty' => $okQty,
                    'rejected_qty' => $rejectedQty,
                    'operator_name' => $operatorName,
                    'uom' => $uom,
                    'status' => $status,
                    'created_at' => trim($data['created_at'] ?? date('Y-m-d H:i:s'))
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update production entry: ' . $e->getMessage()]);
        }
        exit;
    } else {
        // CREATE NEW ENTRY
        requirePermission('production', 'create');
        try {
            $insertSql = "
                INSERT INTO production_entry (
                    mip_no, customer_name, customer_code, so_number, work_order, part_code, part_name,
                    department, received_by, process_name,
                    target_qty, ok_qty, rejected_qty,
                    operator_name, uom, status, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, GETDATE(), GETDATE()
                )
            ";
            $inStmt = $pdo->prepare($insertSql);
            $inStmt->execute([
                $mipNo,
                $customerName,
                $customerCode,
                $soNumber,
                $workOrder,
                $partCode,
                $partName,
                $department,
                $receivedBy,
                $processName,
                $targetQty,
                $okQty,
                $rejectedQty,
                $operatorName,
                $uom,
                $status
            ]);

            $newId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Production entry logged successfully.',
                'data' => [
                    'id' => $newId,
                    'mip_no' => $mipNo,
                    'customer_name' => $customerName,
                    'customer_code' => $customerCode,
                    'so_number' => $soNumber,
                    'work_order' => $workOrder,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'department' => $department,
                    'received_by' => $receivedBy,
                    'process_name' => $processName,
                    'target_qty' => $targetQty,
                    'ok_qty' => $okQty,
                    'rejected_qty' => $rejectedQty,
                    'operator_name' => $operatorName,
                    'uom' => $uom,
                    'status' => $status,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create production entry: ' . $e->getMessage()]);
        }
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit;
