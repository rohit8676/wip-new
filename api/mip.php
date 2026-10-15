<?php
/**
 * WIP Management Portal - Material Issue for Production (MIP) API Endpoint
 * Handles CRUD operations and stock management in MSSQL database.
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

// Helper to calculate BOM child parts details snapshot
function calculateChildPartsSnapshot($pdo, $partCode, $issuedQty) {
    $snapshot = [];
    try {
        $stmt = $pdo->prepare("SELECT child_parts FROM part_master WHERE part_code = ?");
        $stmt->execute([$partCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['child_parts'])) {
            $cpArr = json_decode($row['child_parts'], true);
            if (is_array($cpArr)) {
                // Fetch current UOM and names from child_part_master
                $cpMasterMap = [];
                $cpStmt = $pdo->query("SELECT part_code, part_name, uom FROM child_part_master");
                if ($cpStmt) {
                    while ($m = $cpStmt->fetch(PDO::FETCH_ASSOC)) {
                        $cpMasterMap[trim($m['part_code'])] = $m;
                    }
                }

                foreach ($cpArr as $item) {
                    $cCode = trim($item['code'] ?? ($item['part_code'] ?? ''));
                    if ($cCode === '') continue;

                    $cName = $item['name'] ?? ($item['part_name'] ?? '');
                    if (empty($cName) && isset($cpMasterMap[$cCode])) {
                        $cName = $cpMasterMap[$cCode]['part_name'];
                    }

                    $ratio = floatval($item['qty'] ?? ($item['quantity'] ?? 1));
                    if ($ratio <= 0) $ratio = 1;

                    $uom = $item['uom'] ?? '';
                    if (empty($uom) && isset($cpMasterMap[$cCode])) {
                        $uom = $cpMasterMap[$cCode]['uom'];
                    }
                    if (empty($uom)) $uom = 'NOS';

                    $consumed = $ratio * floatval($issuedQty);

                    $snapshot[] = [
                        'code' => $cCode,
                        'name' => $cName,
                        'ratio' => $ratio,
                        'consumed_qty' => $consumed,
                        'uom' => $uom
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Continue
    }
    return $snapshot;
}

// 1. GET REQUEST - Fetch MIP records
if ($method === 'GET') {
    requirePermission('mip', 'read');
    try {
        $stmt = $pdo->query("
            SELECT id, issue_no, 
                   CONVERT(VARCHAR(10), issue_date, 120) as issue_date,
                   part_code, part_name, issued_qty, uom, 
                   work_order, department, status, remarks, received_by,
                   child_parts_details, created_at, updated_at
            FROM material_issue
            ORDER BY id DESC
        ");
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate next suggested Issue Number
        $nextIssueNo = 'MIP-001';
        $maxStmt = $pdo->query("SELECT TOP 1 issue_no FROM material_issue WHERE issue_no LIKE 'MIP-%' ORDER BY id DESC");
        $maxRow = $maxStmt ? $maxStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($maxRow && preg_match('/MIP-(\d+)/i', $maxRow['issue_no'], $matches)) {
            $nextNum = intval($matches[1]) + 1;
            $nextIssueNo = 'MIP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        }

        echo json_encode([
            'success' => true,
            'data' => $records,
            'next_issue_no' => $nextIssueNo,
            'count' => count($records)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// 2. POST REQUEST - Create, Update, Delete
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
        requirePermission('mip', 'delete');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid MIP ID is required for deletion.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch record to restore stock
            $findStmt = $pdo->prepare("SELECT child_parts_details FROM material_issue WHERE id = ?");
            $findStmt->execute([$id]);
            $found = $findStmt->fetch(PDO::FETCH_ASSOC);

            if ($found && !empty($found['child_parts_details'])) {
                $details = json_decode($found['child_parts_details'], true);
                if (is_array($details)) {
                    $revertStmt = $pdo->prepare("
                        UPDATE child_part_master 
                        SET current_stock = COALESCE(current_stock, 0) + ?,
                            updated_at = GETDATE()
                        WHERE part_code = ?
                    ");
                    foreach ($details as $d) {
                        $qty = floatval($d['consumed_qty'] ?? 0);
                        $cCode = trim($d['code'] ?? '');
                        if ($qty > 0 && !empty($cCode)) {
                            $revertStmt->execute([$qty, $cCode]);
                        }
                    }
                }
            }

            // Delete issue record
            $delStmt = $pdo->prepare("DELETE FROM material_issue WHERE id = ?");
            $delStmt->execute([$id]);

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'MIP record deleted successfully.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------
    // VALIDATE COMMON FIELDS
    // -------------------------------------------------------------
    $issueNo = trim($data['issue_no'] ?? '');
    $issueDate = trim($data['issue_date'] ?? date('Y-m-d'));
    $partCode = trim($data['part_code'] ?? '');
    $partName = trim($data['part_name'] ?? '');
    $issuedQty = floatval($data['issued_qty'] ?? 0);
    $uom = trim($data['uom'] ?? 'NOS');
    $workOrder = trim($data['work_order'] ?? '');
    $department = trim($data['department'] ?? '');
    $status = trim($data['status'] ?? 'Issued');
    $remarks = trim($data['remarks'] ?? '');
    $receivedBy = trim($data['received_by'] ?? '');

    if (empty($partCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Part selection is required.']);
        exit;
    }

    if ($issuedQty <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Issued quantity must be greater than 0.']);
        exit;
    }

    // Auto-fetch part_name if missing
    if (empty($partName)) {
        $pStmt = $pdo->prepare("SELECT part_name FROM part_master WHERE part_code = ?");
        $pStmt->execute([$partCode]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $partName = $pRow['part_name'];
        }
    }

    // Auto-generate issue_no if empty
    if (empty($issueNo)) {
        $maxStmt = $pdo->query("SELECT TOP 1 issue_no FROM material_issue WHERE issue_no LIKE 'MIP-%' ORDER BY id DESC");
        $maxRow = $maxStmt ? $maxStmt->fetch(PDO::FETCH_ASSOC) : null;
        $nextNum = 1;
        if ($maxRow && preg_match('/MIP-(\d+)/i', $maxRow['issue_no'], $matches)) {
            $nextNum = intval($matches[1]) + 1;
        }
        $issueNo = 'MIP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    }

    // Child parts snapshot
    $childPartsSnapshot = calculateChildPartsSnapshot($pdo, $partCode, $issuedQty);
    $childPartsJson = json_encode($childPartsSnapshot, JSON_UNESCAPED_UNICODE);

    // -------------------------------------------------------------
    // UPDATE ACTION
    // -------------------------------------------------------------
    if ($action === 'update') {
        requirePermission('mip', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid ID is required for update.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // 1. Fetch old record to revert old stock
            $oldStmt = $pdo->prepare("SELECT child_parts_details FROM material_issue WHERE id = ?");
            $oldStmt->execute([$id]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if ($oldRow && !empty($oldRow['child_parts_details'])) {
                $oldDetails = json_decode($oldRow['child_parts_details'], true);
                if (is_array($oldDetails)) {
                    $revertStmt = $pdo->prepare("
                        UPDATE child_part_master 
                        SET current_stock = COALESCE(current_stock, 0) + ?,
                            updated_at = GETDATE()
                        WHERE part_code = ?
                    ");
                    foreach ($oldDetails as $od) {
                        $oQty = floatval($od['consumed_qty'] ?? 0);
                        $oCode = trim($od['code'] ?? '');
                        if ($oQty > 0 && !empty($oCode)) {
                            $revertStmt->execute([$oQty, $oCode]);
                        }
                    }
                }
            }

            // 2. Check if issue_no belongs to another record
            $chkStmt = $pdo->prepare("SELECT id FROM material_issue WHERE issue_no = ? AND id != ?");
            $chkStmt->execute([$issueNo, $id]);
            if ($chkStmt->fetch()) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Issue No '{$issueNo}' is already in use by another record."]);
                exit;
            }

            // 3. Validate child parts stock availability for updated quantities
            $insufficientParts = [];
            foreach ($childPartsSnapshot as $cd) {
                $cQty = floatval($cd['consumed_qty'] ?? 0);
                $cCode = trim($cd['code'] ?? '');
                if ($cQty > 0 && !empty($cCode)) {
                    $stkStmt = $pdo->prepare("SELECT part_name, current_stock, uom FROM child_part_master WHERE part_code = ?");
                    $stkStmt->execute([$cCode]);
                    $stkRow = $stkStmt->fetch(PDO::FETCH_ASSOC);
                    $availStock = floatval($stkRow['current_stock'] ?? 0);
                    if ($availStock < $cQty) {
                        $cpName = !empty($stkRow['part_name']) ? $stkRow['part_name'] : ($cd['name'] ?? $cCode);
                        $cpUom = !empty($stkRow['uom']) ? $stkRow['uom'] : ($cd['uom'] ?? 'NOS');
                        $insufficientParts[] = "{$cCode} - {$cpName} (Required: {$cQty} {$cpUom}, Available: {$availStock} {$cpUom})";
                    }
                }
            }

            if (!empty($insufficientParts)) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Production me update save nahi ho sakta: Child part ka stock kam hai! " . implode('; ', $insufficientParts)
                ]);
                exit;
            }

            // 4. Update material_issue
            $updStmt = $pdo->prepare("
                UPDATE material_issue SET
                    issue_no = ?,
                    issue_date = ?,
                    part_code = ?,
                    part_name = ?,
                    issued_qty = ?,
                    uom = ?,
                    work_order = ?,
                    department = ?,
                    status = ?,
                    remarks = ?,
                    received_by = ?,
                    child_parts_details = ?,
                    updated_at = GETDATE()
                WHERE id = ?
            ");
            $updStmt->execute([
                $issueNo, $issueDate, $partCode, $partName, $issuedQty, $uom,
                $workOrder, $department, $status, $remarks, $receivedBy, $childPartsJson, $id
            ]);

            // 5. Deduct new child parts stock
            $deductStmt = $pdo->prepare("
                UPDATE child_part_master 
                SET current_stock = CASE WHEN COALESCE(current_stock, 0) >= ? THEN current_stock - ? ELSE 0 END,
                    updated_at = GETDATE()
                WHERE part_code = ?
            ");
            foreach ($childPartsSnapshot as $cd) {
                $cQty = floatval($cd['consumed_qty'] ?? 0);
                $cCode = trim($cd['code'] ?? '');
                if ($cQty > 0 && !empty($cCode)) {
                    $deductStmt->execute([$cQty, $cQty, $cCode]);
                }
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Material Issue record updated successfully.',
                'data' => [
                    'id' => $id,
                    'issue_no' => $issueNo,
                    'issue_date' => $issueDate,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'issued_qty' => $issuedQty,
                    'uom' => $uom,
                    'work_order' => $workOrder,
                    'department' => $department,
                    'status' => $status,
                    'remarks' => $remarks,
                    'received_by' => $receivedBy,
                    'child_parts_details' => $childPartsJson
                ]
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------
    // CREATE ACTION
    // -------------------------------------------------------------
    if ($action === 'create') {
        requirePermission('mip', 'create');
        try {
            // Check if issue_no already exists
            $chkStmt = $pdo->prepare("SELECT id FROM material_issue WHERE issue_no = ?");
            $chkStmt->execute([$issueNo]);
            if ($chkStmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Issue No '{$issueNo}' already exists. Please choose a unique Issue No."]);
                exit;
            }

            // Validate child parts stock availability
            $insufficientParts = [];
            foreach ($childPartsSnapshot as $cd) {
                $cQty = floatval($cd['consumed_qty'] ?? 0);
                $cCode = trim($cd['code'] ?? '');
                if ($cQty > 0 && !empty($cCode)) {
                    $stkStmt = $pdo->prepare("SELECT part_name, current_stock, uom FROM child_part_master WHERE part_code = ?");
                    $stkStmt->execute([$cCode]);
                    $stkRow = $stkStmt->fetch(PDO::FETCH_ASSOC);
                    $availStock = floatval($stkRow['current_stock'] ?? 0);
                    if ($availStock < $cQty) {
                        $cpName = !empty($stkRow['part_name']) ? $stkRow['part_name'] : ($cd['name'] ?? $cCode);
                        $cpUom = !empty($stkRow['uom']) ? $stkRow['uom'] : ($cd['uom'] ?? 'NOS');
                        $insufficientParts[] = "{$cCode} - {$cpName} (Required: {$cQty} {$cpUom}, Available: {$availStock} {$cpUom})";
                    }
                }
            }

            if (!empty($insufficientParts)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Production me issue save nahi ho sakta: Child part ka stock kam hai! " . implode('; ', $insufficientParts)
                ]);
                exit;
            }

            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                INSERT INTO material_issue (
                    issue_no, issue_date, part_code, part_name, issued_qty, uom,
                    work_order, department, status, remarks, received_by, child_parts_details,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
            ");
            $insertStmt->execute([
                $issueNo, $issueDate, $partCode, $partName, $issuedQty, $uom,
                $workOrder, $department, $status, $remarks, $receivedBy, $childPartsJson
            ]);

            $newId = intval($pdo->lastInsertId());

            // Deduct child parts stock
            $deductStmt = $pdo->prepare("
                UPDATE child_part_master 
                SET current_stock = CASE WHEN COALESCE(current_stock, 0) >= ? THEN current_stock - ? ELSE 0 END,
                    updated_at = GETDATE()
                WHERE part_code = ?
            ");
            foreach ($childPartsSnapshot as $cd) {
                $cQty = floatval($cd['consumed_qty'] ?? 0);
                $cCode = trim($cd['code'] ?? '');
                if ($cQty > 0 && !empty($cCode)) {
                    $deductStmt->execute([$cQty, $cQty, $cCode]);
                }
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Material Issue created and child part stock updated successfully.',
                'data' => [
                    'id' => $newId,
                    'issue_no' => $issueNo,
                    'issue_date' => $issueDate,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'issued_qty' => $issuedQty,
                    'uom' => $uom,
                    'work_order' => $workOrder,
                    'department' => $department,
                    'status' => $status,
                    'remarks' => $remarks,
                    'received_by' => $receivedBy,
                    'child_parts_details' => $childPartsJson
                ]
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create record: ' . $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
    exit;
}
