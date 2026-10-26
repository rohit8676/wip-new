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
                   target_qty, ok_qty, rejected_qty, rework_qty,
                   operator_name, uom, status, entry_type, is_rework, rework_source_entry_id,
                   rework_iteration, rework_label,
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
            // Check if this was a rework entry
            $stGet = $pdo->prepare("SELECT mip_no, target_qty, is_rework, rework_source_entry_id FROM production_entry WHERE id = ?");
            $stGet->execute([$id]);
            $delEntry = $stGet->fetch(PDO::FETCH_ASSOC);

            $delStmt = $pdo->prepare("DELETE FROM production_entry WHERE id = ?");
            $delStmt->execute([$id]);

            // If rework entry was deleted, restore pending qty to material_issue
            if ($delEntry && !empty($delEntry['is_rework']) && !empty($delEntry['mip_no'])) {
                $rQty = floatval($delEntry['target_qty'] ?? 0);
                $mipNoDel = trim($delEntry['mip_no']);
                $stRest = $pdo->prepare("
                    UPDATE material_issue
                    SET rework_pending_qty = rework_pending_qty + ?,
                        status = 'Rework Pending',
                        updated_at = GETDATE()
                    WHERE issue_no = ?
                ");
                $stRest->execute([$rQty, $mipNoDel]);
            }

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
    $shift = trim($data['shift'] ?? 'General');
    $status = trim($data['status'] ?? '');
    if (empty($status)) {
        $status = 'New Line up';
    }

    $entryType = trim($data['entry_type'] ?? 'Regular');
    $isRework = !empty($data['is_rework']) ? 1 : 0;
    $reworkSourceEntryId = !empty($data['rework_source_entry_id']) ? intval($data['rework_source_entry_id']) : null;
    if ($isRework && empty($reworkSourceEntryId) && !empty($mipNo)) {
        $stSrc = $pdo->prepare("SELECT TOP 1 id FROM production_entry WHERE mip_no = ? AND (is_rework = 0 OR is_rework IS NULL) ORDER BY id DESC");
        $stSrc->execute([$mipNo]);
        $srcId = $stSrc->fetchColumn();
        if ($srcId) {
            $reworkSourceEntryId = intval($srcId);
        }
    }

    // For brand new entries, production has not started yet.
    // Produced OK quantity must start at 0, rejected quantity at 0, and status at 'New Line up'.
    if ($action === 'create' || $id <= 0) {
        $okQty = 0;
        $rejectedQty = 0;
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
    if ($targetQty <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Target quantity must be greater than 0.']);
        exit;
    }

    // Duplicate & Limit Validation:
    if (!empty($mipNo)) {
        if ($action === 'create' || ($action === 'update' && $id <= 0)) {
            if ($isRework) {
                // Rework entries: verify pending rework quantity available on material_issue
                $stMipCheck = $pdo->prepare("SELECT status, rework_pending_qty FROM material_issue WHERE issue_no = ?");
                $stMipCheck->execute([$mipNo]);
                $mipRow = $stMipCheck->fetch(PDO::FETCH_ASSOC);
                $pendingRework = floatval($mipRow['rework_pending_qty'] ?? 0);
                if ($targetQty > $pendingRework) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => "Target rework quantity ({$targetQty}) exceeds available pending rework quantity ({$pendingRework}) for MIP '{$mipNo}'."
                    ]);
                    exit;
                }
            } else {
                // Regular entries cannot duplicate on same MIP and Part
                $dupStmt = $pdo->prepare("
                    SELECT id FROM production_entry 
                    WHERE LOWER(LTRIM(RTRIM(mip_no))) = LOWER(LTRIM(RTRIM(?))) 
                      AND LOWER(LTRIM(RTRIM(part_code))) = LOWER(LTRIM(RTRIM(?)))
                      AND (is_rework = 0 OR is_rework IS NULL)
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
            }
        } else if ($action === 'update' && $id > 0) {
            if (!$isRework) {
                $dupStmt = $pdo->prepare("
                    SELECT id FROM production_entry 
                    WHERE LOWER(LTRIM(RTRIM(mip_no))) = LOWER(LTRIM(RTRIM(?))) 
                      AND LOWER(LTRIM(RTRIM(part_code))) = LOWER(LTRIM(RTRIM(?)))
                      AND (is_rework = 0 OR is_rework IS NULL)
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
                    shift = ?,
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
                $shift,
                $id
            ]);

            // If rework entry is Completed, clear rejected quantity on original entry
            if ($isRework && (strcasecmp($status, 'Completed') === 0 || strcasecmp($status, 'Complete') === 0)) {
                $okAmt = floatval($okQty);
                $srcId = !empty($reworkSourceEntryId) ? intval($reworkSourceEntryId) : 0;
                if ($srcId > 0) {
                    $stClear = $pdo->prepare("
                        UPDATE production_entry
                        SET rejected_qty = CASE WHEN rejected_qty >= ? THEN rejected_qty - ? ELSE 0 END,
                            status = 'Completed',
                            updated_at = GETDATE()
                        WHERE id = ?
                    ");
                    $stClear->execute([$okAmt, $okAmt, $srcId]);
                } elseif (!empty($mipNo)) {
                    $stClear = $pdo->prepare("
                        UPDATE production_entry
                        SET rejected_qty = CASE WHEN rejected_qty >= ? THEN rejected_qty - ? ELSE 0 END,
                            status = 'Completed',
                            updated_at = GETDATE()
                        WHERE mip_no = ? AND (is_rework = 0 OR is_rework IS NULL)
                    ");
                    $stClear->execute([$okAmt, $okAmt, $mipNo]);
                }

                if (!empty($mipNo)) {
                    $stCheckMi = $pdo->prepare("SELECT rework_qty, rework_completed_qty, rework_pending_qty FROM material_issue WHERE issue_no = ?");
                    $stCheckMi->execute([$mipNo]);
                    $miRow = $stCheckMi->fetch(PDO::FETCH_ASSOC);
                    if ($miRow) {
                        $newCompleted = floatval($miRow['rework_completed_qty'] ?? 0) + $okAmt;
                        $totalRw = floatval($miRow['rework_qty'] ?? 0);
                        $newPending = max(0, $totalRw - $newCompleted);
                        $newStatus = ($newPending <= 0) ? 'Closed' : 'Rework Pending';
                        $newClosure = ($newPending <= 0) ? 'Rework Completed' : 'Pending Rework';

                        $stUpdMi = $pdo->prepare("
                            UPDATE material_issue
                            SET rework_pending_qty = ?,
                                rework_completed_qty = ?,
                                status = ?,
                                closure_status = ?,
                                updated_at = GETDATE()
                            WHERE issue_no = ?
                        ");
                        $stUpdMi->execute([$newPending, $newCompleted, $newStatus, $newClosure, $mipNo]);
                    }

                    if (isset($newPending) && $newPending <= 0) {
                        $stTrans = $pdo->prepare("UPDATE rework_transfers SET status = 'Completed' WHERE mip_no = ? AND status = 'Approved'");
                        $stTrans->execute([$mipNo]);
                    }
                }
            }

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
                    'shift' => $shift,
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
            // Determine rework iteration and label if this is a rework run
            $reworkIteration = 0;
            $reworkLabel = null;
            if ($isRework && !empty($mipNo)) {
                $stCountRw = $pdo->prepare("
                    SELECT COUNT(*) FROM production_entry 
                    WHERE LOWER(LTRIM(RTRIM(mip_no))) = LOWER(LTRIM(RTRIM(?))) 
                      AND is_rework = 1
                ");
                $stCountRw->execute([$mipNo]);
                $existingRwCount = intval($stCountRw->fetchColumn() ?: 0);
                $reworkIteration = $existingRwCount + 1;
                $reworkLabel = ($reworkIteration === 1) ? 'R' : ('R' . ($reworkIteration - 1));
            }

            $insertSql = "
                INSERT INTO production_entry (
                    mip_no, customer_name, customer_code, so_number, work_order, part_code, part_name,
                    department, received_by, process_name, shift,
                    target_qty, ok_qty, rejected_qty,
                    operator_name, uom, status,
                    entry_type, is_rework, rework_source_entry_id, rework_iteration, rework_label,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    GETDATE(), GETDATE()
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
                $shift,
                $targetQty,
                0, // ok_qty starts at 0 for new production entries
                0, // rejected_qty starts at 0 for new production entries
                $operatorName,
                $uom,
                'New Line up',
                $entryType,
                $isRework,
                $reworkSourceEntryId,
                $reworkIteration,
                $reworkLabel
            ]);

            $newId = (int)$pdo->lastInsertId();

            // Update Material Issue status & pending quantities
            if (!empty($mipNo)) {
                if ($isRework) {
                    // Fetch existing closure_history
                    $stHist = $pdo->prepare("SELECT closure_history FROM material_issue WHERE issue_no = ?");
                    $stHist->execute([$mipNo]);
                    $histRaw = $stHist->fetchColumn();
                    $historyList = [];
                    if (!empty($histRaw)) {
                        $parsed = json_decode($histRaw, true);
                        if (is_array($parsed)) $historyList = $parsed;
                    }

                    $historyList[] = [
                        'event' => 'Rework Entry Logged',
                        'status' => 'Rework In Progress',
                        'qty' => $targetQty,
                        'entry_id' => $newId,
                        'at' => date('Y-m-d H:i:s'),
                        'by' => $currentUser['name'] ?? 'System'
                    ];

                    $stUpdMip = $pdo->prepare("
                        UPDATE material_issue
                        SET rework_pending_qty = CASE WHEN rework_pending_qty >= ? THEN rework_pending_qty - ? ELSE 0 END,
                            status = 'Rework In Progress',
                            closure_history = ?,
                            updated_at = GETDATE()
                        WHERE issue_no = ?
                    ");
                    $stUpdMip->execute([$targetQty, $targetQty, json_encode($historyList), $mipNo]);
                } else {
                    // Regular entry: transition to 'In Production' if currently Open
                    $stUpdMip = $pdo->prepare("
                        UPDATE material_issue
                        SET status = 'In Production',
                            updated_at = GETDATE()
                        WHERE issue_no = ? AND (status = 'Open' OR status IS NULL)
                    ");
                    $stUpdMip->execute([$mipNo]);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => $isRework ? 'Rework production entry logged successfully.' : 'Production entry logged successfully.',
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
                    'ok_qty' => 0,
                    'rejected_qty' => 0,
                    'operator_name' => $operatorName,
                    'uom' => $uom,
                    'status' => 'New Line up',
                    'entry_type' => $entryType,
                    'is_rework' => $isRework,
                    'rework_iteration' => $reworkIteration,
                    'rework_label' => $reworkLabel,
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
