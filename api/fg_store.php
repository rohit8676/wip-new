<?php
/**
 * WIP Management Portal - Finished Goods (FG) Store API
 * Endpoint: /api/fg_store.php
 * Handles CRUD, Inward, Dispatch, and FG Inventory management (without Rack/Bin).
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

// Ensure all FG tables exist (Clean schema without rack/bin)
try {
    $pdo->exec("
        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='fg_inventory' AND xtype='U')
        BEGIN
            CREATE TABLE fg_inventory (
                id INT IDENTITY(1,1) PRIMARY KEY,
                part_code NVARCHAR(50) NOT NULL,
                part_name NVARCHAR(150) NOT NULL,
                total_ok_qty DECIMAL(18, 3) NOT NULL DEFAULT 0.000,
                uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
                last_mip_no NVARCHAR(50) NULL,
                last_production_date DATE NULL,
                created_at DATETIME DEFAULT GETDATE(),
                updated_at DATETIME DEFAULT GETDATE(),
                CONSTRAINT UQ_fg_inv_part_code UNIQUE (part_code)
            );
        END

        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='fg_inward_logs' AND xtype='U')
        BEGIN
            CREATE TABLE fg_inward_logs (
                id INT IDENTITY(1,1) PRIMARY KEY,
                inward_no NVARCHAR(50) NOT NULL,
                mip_no NVARCHAR(50) NOT NULL,
                production_date DATE NULL,
                part_code NVARCHAR(50) NOT NULL,
                part_name NVARCHAR(150) NOT NULL,
                ok_qty DECIMAL(18, 3) NOT NULL,
                uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
                qc_status NVARCHAR(50) DEFAULT 'QC Passed',
                work_order NVARCHAR(100) NULL,
                received_by NVARCHAR(150) NULL,
                remarks NVARCHAR(500) NULL,
                created_at DATETIME DEFAULT GETDATE()
            );
        END

        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='fg_dispatch_logs' AND xtype='U')
        BEGIN
            CREATE TABLE fg_dispatch_logs (
                id INT IDENTITY(1,1) PRIMARY KEY,
                dispatch_no NVARCHAR(50) NOT NULL,
                dispatch_date DATE NOT NULL,
                invoice_ref NVARCHAR(100) NOT NULL,
                part_code NVARCHAR(50) NOT NULL,
                part_name NVARCHAR(150) NOT NULL,
                batch_no NVARCHAR(50) NULL,
                dispatch_qty DECIMAL(18, 3) NOT NULL,
                uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
                customer NVARCHAR(200) NOT NULL,
                dispatched_by NVARCHAR(150) NOT NULL,
                remarks NVARCHAR(500) NULL,
                created_at DATETIME DEFAULT GETDATE()
            );
        END
    ");
} catch (PDOException $e) {
    // Table verification fallback
}

// -----------------------------------------------------------------
// 1. GET REQUEST - Fetch Inventory, Inward Logs, or Dispatch Logs
// -----------------------------------------------------------------
if ($method === 'GET') {
    requirePermission('fg_store', 'read');
    $action = trim($_GET['action'] ?? '');
    $type = trim($_GET['type'] ?? 'inventory');

    try {
        // Fetch Inward Logs
        if ($type === 'logs' || $type === 'inward_logs') {
            $stmt = $pdo->query("
                SELECT id, inward_no, mip_no, 
                       CONVERT(VARCHAR(10), production_date, 120) as production_date,
                       part_code, part_name, ok_qty, uom, qc_status, 
                       work_order, received_by, remarks,
                       CONVERT(VARCHAR(19), created_at, 120) as created_at
                FROM fg_inward_logs
                ORDER BY id DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        // Fetch Dispatch Logs
        elseif ($type === 'dispatch_logs') {
            $stmt = $pdo->query("
                SELECT id, dispatch_no,
                       CONVERT(VARCHAR(10), dispatch_date, 120) as dispatch_date,
                       invoice_ref, part_code, part_name, batch_no,
                       dispatch_qty, uom, customer, dispatched_by, remarks,
                       CONVERT(VARCHAR(19), created_at, 120) as created_at
                FROM fg_dispatch_logs
                ORDER BY id DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        // Default: FG Inventory
        else {
            $stmt = $pdo->query("
                SELECT id, part_code, part_name, total_ok_qty, uom, 
                       last_mip_no,
                       CONVERT(VARCHAR(10), last_production_date, 120) as production_date,
                       CONVERT(VARCHAR(19), updated_at, 120) as updated_at
                FROM fg_inventory
                ORDER BY part_code ASC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------
// 2. POST REQUEST - Save Inward or Dispatch
// -----------------------------------------------------------------
if ($method === 'POST') {
    requirePermission('fg_store', 'create');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $action = trim($_GET['action'] ?? $data['action'] ?? '');

    // -----------------------------------------------------------------
    // A. DISPATCH FINISHED GOODS (Single or Multiple Parts)
    // -----------------------------------------------------------------
    if ($action === 'save_dispatch') {
        $dispatchNo = trim($data['dispatch_no'] ?? '');
        $dispatchDate = trim($data['dispatch_date'] ?? date('Y-m-d'));
        $invoiceRef = trim($data['invoice_ref'] ?? '');
        $customer = trim($data['customer'] ?? '');
        $dispatchedBy = trim($data['dispatched_by'] ?? '');
        $remarks = trim($data['remarks'] ?? '');

        if (empty($dispatchNo)) {
            $dispatchNo = 'DSP-' . date('Y') . '-' . rand(100, 999);
        }

        if (empty($invoiceRef)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invoice / DC reference is required.']);
            exit;
        }


        // Support both multi-item array ($data['items']) and single-item format
        $rawItems = $data['items'] ?? [];
        if (!is_array($rawItems) || empty($rawItems)) {
            if (!empty($data['part_code'])) {
                $rawItems = [$data];
            }
        }

        if (empty($rawItems)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please add at least one finished part to dispatch.']);
            exit;
        }

        // 1. Validate all parts before beginning transaction
        $validatedItems = [];
        foreach ($rawItems as $idx => $it) {
            $pCode = trim($it['part_code'] ?? '');
            $dQty = floatval($it['dispatch_qty'] ?? 0);
            $rowNum = $idx + 1;

            if (empty($pCode)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Item #{$rowNum}: Finished part must be selected."]);
                exit;
            }
            if ($dQty <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Item #{$rowNum} ({$pCode}): Dispatch quantity must be greater than zero."]);
                exit;
            }

            $stmtCheck = $pdo->prepare("
                SELECT id, part_name, total_ok_qty, uom, last_mip_no
                FROM fg_inventory 
                WHERE part_code = ?
            ");
            $stmtCheck->execute([$pCode]);
            $invRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$invRow) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Item #{$rowNum}: Part {$pCode} is not available in inventory."]);
                exit;
            }

            $availableQty = floatval($invRow['total_ok_qty']);
            if ($dQty > $availableQty) {
                http_response_code(400);
                echo json_encode([
                    'success' => false, 
                    'message' => "Item #{$rowNum} ({$pCode}): Dispatch qty ({$dQty}) cannot exceed available stock ({$availableQty})."
                ]);
                exit;
            }

            $validatedItems[] = [
                'inv_id' => $invRow['id'],
                'part_code' => $pCode,
                'part_name' => !empty($it['part_name']) ? trim($it['part_name']) : $invRow['part_name'],
                'dispatch_qty' => $dQty,
                'uom' => !empty($it['uom']) ? trim($it['uom']) : ($invRow['uom'] ?? 'PCS'),
                'batch_no' => !empty($it['batch_no']) ? trim($it['batch_no']) : ($invRow['last_mip_no'] ?? ''),
                'available_qty' => $availableQty
            ];
        }

        try {
            $pdo->beginTransaction();

            $dispatchedCount = count($validatedItems);

            foreach ($validatedItems as $item) {
                // 1. Insert into fg_dispatch_logs
                $stmtLog = $pdo->prepare("
                    INSERT INTO fg_dispatch_logs 
                        (dispatch_no, dispatch_date, invoice_ref, part_code, part_name, batch_no, dispatch_qty, uom, customer, dispatched_by, remarks, created_at)
                    VALUES 
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                ");
                $stmtLog->execute([
                    $dispatchNo,
                    $dispatchDate,
                    $invoiceRef,
                    $item['part_code'],
                    $item['part_name'],
                    $item['batch_no'],
                    $item['dispatch_qty'],
                    $item['uom'],
                    $customer,
                    $dispatchedBy,
                    $remarks
                ]);

                // 2. Deduct stock directly from part inventory
                $newStock = $item['available_qty'] - $item['dispatch_qty'];
                if ($newStock < 0) $newStock = 0;

                $stmtUpd = $pdo->prepare("
                    UPDATE fg_inventory 
                    SET total_ok_qty = ?, updated_at = GETDATE()
                    WHERE part_code = ?
                ");
                $stmtUpd->execute([$newStock, $item['part_code']]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Dispatch successful! {$dispatchedCount} part(s) dispatched under {$dispatchNo}.",
                'data' => [
                    'dispatch_no' => $dispatchNo,
                    'items_count' => $dispatchedCount
                ]
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // -----------------------------------------------------------------
    // B. INWARD FINISHED GOODS (Default Post Action)
    // -----------------------------------------------------------------
    $inwardNo = trim($data['inward_no'] ?? '');
    $mipNo = trim($data['mip_no'] ?? '');
    $partCode = trim($data['part_code'] ?? '');
    $partName = trim($data['part_name'] ?? '');
    $okQty = floatval($data['inward_qty'] ?? $data['ok_qty'] ?? 0);
    $uom = trim($data['uom'] ?? 'PCS');
    $prodDate = trim($data['inward_date'] ?? $data['production_date'] ?? date('Y-m-d'));
    $workOrder = trim($data['work_order'] ?? '');
    $receivedBy = trim($data['received_by'] ?? '');
    $remarks = trim($data['remarks'] ?? '');

    if (empty($inwardNo)) {
        $inwardNo = 'FGI-' . date('Y') . '-' . rand(100, 999);
    }
    if (empty($partCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Part Code is required.']);
        exit;
    }
    if ($okQty <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ok quantity must be greater than zero.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Insert into fg_inward_logs
        $stmtLog = $pdo->prepare("
            INSERT INTO fg_inward_logs 
                (inward_no, mip_no, production_date, part_code, part_name, ok_qty, uom, qc_status, work_order, received_by, remarks, created_at)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, 'QC Passed', ?, ?, ?, GETDATE())
        ");
        $stmtLog->execute([
            $inwardNo,
            $mipNo,
            !empty($prodDate) ? $prodDate : null,
            $partCode,
            $partName,
            $okQty,
            $uom,
            $workOrder,
            $receivedBy,
            $remarks
        ]);

        // 2. Check if this part already exists in fg_inventory
        $stmtCheck = $pdo->prepare("SELECT id, total_ok_qty FROM fg_inventory WHERE part_code = ?");
        $stmtCheck->execute([$partCode]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Accumulate quantity
            $stmtUpd = $pdo->prepare("
                UPDATE fg_inventory 
                SET total_ok_qty = total_ok_qty + ?,
                    part_name = ?,
                    uom = ?,
                    last_mip_no = ?,
                    last_production_date = ?,
                    updated_at = GETDATE()
                WHERE part_code = ?
            ");
            $stmtUpd->execute([
                $okQty,
                $partName,
                $uom,
                $mipNo,
                !empty($prodDate) ? $prodDate : null,
                $partCode
            ]);
            $newTotal = floatval($existing['total_ok_qty']) + $okQty;
        } else {
            // New part: insert new row
            $stmtIns = $pdo->prepare("
                INSERT INTO fg_inventory 
                    (part_code, part_name, total_ok_qty, uom, last_mip_no, last_production_date, created_at, updated_at)
                VALUES 
                    (?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
            ");
            $stmtIns->execute([
                $partCode,
                $partName,
                $okQty,
                $uom,
                $mipNo,
                !empty($prodDate) ? $prodDate : null
            ]);
            $newTotal = $okQty;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Finished goods inward saved successfully!',
            'data' => [
                'part_code' => $partCode,
                'part_name' => $partName,
                'added_qty' => $okQty,
                'total_qty' => $newTotal,
                'uom' => $uom,
                'inward_no' => $inwardNo,
                'mip_no' => $mipNo
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
