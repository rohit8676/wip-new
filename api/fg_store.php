<?php
/**
 * WIP Management Portal - Finished Goods (FG) Store API
 * Endpoint: /api/fg_store.php
 * Handles Inward, Dispatch, Search MIP with Rework, and FG Inventory management.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/check_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/fg_helper.php';

$pdo = getDBConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Ensure all FG tables and columns exist
try {
    $pdo->exec("
        IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='fg_inventory' AND xtype='U')
        BEGIN
            CREATE TABLE fg_inventory (
                id INT IDENTITY(1,1) PRIMARY KEY,
                part_code NVARCHAR(50) NOT NULL,
                part_name NVARCHAR(150) NOT NULL,
                customer_name NVARCHAR(150) NULL,
                total_ok_qty DECIMAL(18, 3) NOT NULL DEFAULT 0.000,
                uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
                last_mip_no NVARCHAR(50) NULL,
                last_production_date DATE NULL,
                variant_stock_details NVARCHAR(MAX) NULL,
                created_at DATETIME DEFAULT GETDATE(),
                updated_at DATETIME DEFAULT GETDATE()
            );
        END
        IF EXISTS (SELECT * FROM sys.key_constraints WHERE name = 'UQ_fg_inv_part_code')
        BEGIN
            ALTER TABLE fg_inventory DROP CONSTRAINT UQ_fg_inv_part_code;
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inventory') AND name = 'variant_stock_details')
                ALTER TABLE fg_inventory ADD variant_stock_details NVARCHAR(MAX) NULL;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inventory') AND name = 'customer_name')
                ALTER TABLE fg_inventory ADD customer_name NVARCHAR(150) NULL;
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
                customer_name NVARCHAR(150) NULL,
                ok_qty DECIMAL(18, 3) NOT NULL,
                original_ok_qty DECIMAL(18, 3) NULL,
                rework_ok_qty DECIMAL(18, 3) DEFAULT 0,
                uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
                qc_status NVARCHAR(50) DEFAULT 'QC Passed',
                work_order NVARCHAR(100) NULL,
                received_by NVARCHAR(150) NULL,
                remarks NVARCHAR(500) NULL,
                variant_details NVARCHAR(MAX) NULL,
                is_rework BIT DEFAULT 0,
                production_entry_id INT NULL,
                has_rework_appended BIT DEFAULT 0,
                rework_appended_info NVARCHAR(MAX) NULL,
                created_at DATETIME DEFAULT GETDATE()
            );
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'variant_details')
                ALTER TABLE fg_inward_logs ADD variant_details NVARCHAR(MAX) NULL;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'customer_name')
                ALTER TABLE fg_inward_logs ADD customer_name NVARCHAR(150) NULL;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'is_rework')
                ALTER TABLE fg_inward_logs ADD is_rework BIT DEFAULT 0;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'production_entry_id')
                ALTER TABLE fg_inward_logs ADD production_entry_id INT NULL;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'original_ok_qty')
                ALTER TABLE fg_inward_logs ADD original_ok_qty DECIMAL(18, 3) NULL;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'rework_ok_qty')
                ALTER TABLE fg_inward_logs ADD rework_ok_qty DECIMAL(18, 3) DEFAULT 0;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'has_rework_appended')
                ALTER TABLE fg_inward_logs ADD has_rework_appended BIT DEFAULT 0;
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_inward_logs') AND name = 'rework_appended_info')
                ALTER TABLE fg_inward_logs ADD rework_appended_info NVARCHAR(MAX) NULL;
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
                variant_dispatch_details NVARCHAR(MAX) NULL,
                created_at DATETIME DEFAULT GETDATE()
            );
        END
        ELSE
        BEGIN
            IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('fg_dispatch_logs') AND name = 'variant_dispatch_details')
                ALTER TABLE fg_dispatch_logs ADD variant_dispatch_details NVARCHAR(MAX) NULL;
        END
    ");
} catch (PDOException $e) {
    // Table verification fallback
}

// -----------------------------------------------------------------
// 1. GET REQUEST - Search MIP, Fetch Inventory, Inward Logs, or Dispatch Logs
// -----------------------------------------------------------------
if ($method === 'GET') {
    requirePermission('fg_store', 'read');
    $action = trim($_GET['action'] ?? '');
    $type = trim($_GET['type'] ?? 'inventory');

    // Live search for scanned MIP (Parent + any Rework outputs)
    if ($action === 'search_mip') {
        $q = trim($_GET['q'] ?? '');
        $res = searchMipWithRework($pdo, $q);
        echo json_encode($res);
        exit;
    }

    try {
        // Fetch Inward Logs
        if ($type === 'logs' || $type === 'inward_logs') {
            $stmt = $pdo->query("
                SELECT id, inward_no, mip_no, customer_name,
                       CONVERT(VARCHAR(10), production_date, 120) as production_date,
                       part_code, part_name, ok_qty, original_ok_qty, rework_ok_qty,
                       uom, qc_status, work_order, received_by, remarks,
                       variant_details, is_rework, has_rework_appended, rework_appended_info,
                       production_entry_id,
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
                       variant_dispatch_details,
                       CONVERT(VARCHAR(19), created_at, 120) as created_at
                FROM fg_dispatch_logs
                ORDER BY id DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        // Default: FG Inventory
        else {
            syncFgInventoryRecords($pdo);
            $stmt = $pdo->query("
                SELECT id, part_code, part_name, customer_name, total_ok_qty, uom, 
                       last_mip_no, variant_stock_details,
                       CONVERT(VARCHAR(10), last_production_date, 120) as production_date,
                       CONVERT(VARCHAR(19), updated_at, 120) as updated_at
                FROM fg_inventory
                ORDER BY id DESC
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

    // A. Dispatch Finished Goods
    if ($action === 'save_dispatch') {
        $res = saveDispatchWithVariants($pdo, $data);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        exit;
    }

    // B. Inward Finished Goods (Consolidated Parent + Rework)
    if ($action === 'save_inward' || empty($action)) {
        $res = saveInwardConsolidated($pdo, $data);
        if (!$res['success']) {
            http_response_code(400);
        }
        echo json_encode($res);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
