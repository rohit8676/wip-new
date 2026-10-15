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
            SELECT id, mip_no, work_order, part_code, part_name,
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
    $workOrder = trim($data['work_order'] ?? '');
    $partCode = trim($data['part_code'] ?? '');
    $partName = trim($data['part_name'] ?? '');
    $department = trim($data['department'] ?? '');
    $receivedBy = trim($data['received_by'] ?? '');
    $processName = trim($data['process_name'] ?? '');
    $shift = trim($data['shift'] ?? '');
    $targetQty = floatval($data['target_qty'] ?? 0);
    $okQty = floatval($data['ok_qty'] ?? 0);
    $rejectedQty = floatval($data['rejected_qty'] ?? 0);
    $operatorName = trim($data['operator_name'] ?? '');
    $uom = trim($data['uom'] ?? 'PCS');
    $status = trim($data['status'] ?? 'Completed');

    // Validation
    if (empty($partCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Part details are required. Please scan an MIP slip.']);
        exit;
    }
    if (empty($processName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please select a Process / Stage.']);
        exit;
    }
    if (empty($shift)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please select a Shift.']);
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

    if ($action === 'update' && $id > 0) {
        // UPDATE EXISTING ENTRY
        requirePermission('production', 'update');
        try {
            $updateSql = "
                UPDATE production_entry SET
                    mip_no = ?,
                    work_order = ?,
                    part_code = ?,
                    part_name = ?,
                    department = ?,
                    received_by = ?,
                    process_name = ?,
                    shift = ?,
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
                $workOrder,
                $partCode,
                $partName,
                $department,
                $receivedBy,
                $processName,
                $shift,
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
                    'work_order' => $workOrder,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'department' => $department,
                    'received_by' => $receivedBy,
                    'process_name' => $processName,
                    'shift' => $shift,
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
                    mip_no, work_order, part_code, part_name,
                    department, received_by, process_name, shift,
                    target_qty, ok_qty, rejected_qty,
                    operator_name, uom, status, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, GETDATE(), GETDATE()
                )
            ";
            $inStmt = $pdo->prepare($insertSql);
            $inStmt->execute([
                $mipNo,
                $workOrder,
                $partCode,
                $partName,
                $department,
                $receivedBy,
                $processName,
                $shift,
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
                    'work_order' => $workOrder,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'department' => $department,
                    'received_by' => $receivedBy,
                    'process_name' => $processName,
                    'shift' => $shift,
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
