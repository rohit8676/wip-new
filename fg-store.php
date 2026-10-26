<?php
/**
 * WIP Management Portal - Finished Goods Store (FG Store)
 * 3 Tabs Architecture: FG Stock Inventory, Inward Logs, Dispatch Logs
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/fg_helper.php';

// Format stock cleanly
function formatCleanStock($val) {
    $f = floatval($val ?? 0);
    if ($f == (int)$f) {
        return number_format($f, 0);
    }
    return rtrim(rtrim(number_format($f, 3), '0'), '.');
}

// Format date to DD-MM-YYYY
function formatDateDMY($dStr) {
    if (empty($dStr)) return '';
    $clean = explode('T', (string)$dStr)[0];
    $ts = strtotime($clean);
    return $ts ? date('d-m-Y', $ts) : $dStr;
}

$pdo = getDBConnection();

// Live AJAX API handler for scanning / searching MIP (Parent + any Rework batches)
if (isset($_GET['action']) && $_GET['action'] === 'search_mip') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    echo json_encode(searchMipWithRework($pdo, $q));
    exit;
}


// Auto-verify and create FG Store tables if not yet created
if ($pdo) {
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
                    updated_at DATETIME DEFAULT GETDATE()
                );
            END

            IF EXISTS (SELECT * FROM sys.key_constraints WHERE name = 'UQ_fg_inv_part_code')
            BEGIN
                ALTER TABLE fg_inventory DROP CONSTRAINT UQ_fg_inv_part_code;
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
        // Table check fallback
    }
}

// Live AJAX API handler for Saving Inward Finished Goods
if (((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_GET['action']) && $_GET['action'] === 'save_inward') ||
    ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_POST['action']) && $_POST['action'] === 'save_inward')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $postData = json_decode($rawInput, true);
    if (!is_array($postData) || empty($postData)) {
        $postData = $_POST;
    }

    echo json_encode(saveInwardConsolidated($pdo, $postData));
    exit;
}

// Live AJAX API handler for Saving Finished Goods Dispatch
if (((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_GET['action']) && $_GET['action'] === 'save_dispatch') ||
    ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && isset($_POST['action']) && $_POST['action'] === 'save_dispatch')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $postData = json_decode($rawInput, true);
    if (!is_array($postData) || empty($postData)) {
        $postData = $_POST;
    }

    echo json_encode(saveDispatchWithVariants($pdo, $postData));
    exit;
}


$activeParts = [];
$productionList = [];

if ($pdo) {
    // 1. Fetch active parts from part_master
    try {
        $stmtParts = $pdo->query("SELECT id, part_code, part_name FROM part_master WHERE status = 'Active' ORDER BY part_code ASC");
        if ($stmtParts) {
            $activeParts = $stmtParts->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Fallback gracefully
    }

    // 2. Fetch production records directly from production_entry table
    try {
        $stmtPrd = $pdo->query("
            SELECT id, mip_no, work_order, part_code, part_name,
                   department, received_by, process_name, shift,
                   target_qty, ok_qty, rework_qty, rejected_qty,
                   operator_name, uom, status,
                   CONVERT(VARCHAR(10), created_at, 120) as production_date,
                   CONVERT(VARCHAR(19), created_at, 120) as created_at
            FROM production_entry
            ORDER BY id DESC
        ");
        if ($stmtPrd) {
            $productionList = $stmtPrd->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Fallback gracefully
    }
}

// Fallback active parts if database is empty
if (empty($activeParts)) {
    $activeParts = [
        ['part_code' => 'P1001', 'part_name' => 'Front Mounting Assembly', 'uom' => 'PCS'],
        ['part_code' => 'P1002', 'part_name' => 'Main Chassis Sub-Assembly', 'uom' => 'PCS'],
        ['part_code' => 'P1003', 'part_name' => 'Support Bracket Assembly', 'uom' => 'PCS'],
        ['part_code' => 'P1004', 'part_name' => 'Retainer Frame Assembly', 'uom' => 'PCS'],
        ['part_code' => 'P1005', 'part_name' => 'Heavy Duty Base Plate', 'uom' => 'PCS'],
    ];
}

// Fallback sample production records only if database connection is not available
if (!$pdo && empty($productionList)) {
    $productionList = [
        [
            'id' => 1,
            'mip_no' => 'MIP-001',
            'work_order' => 'WO-9841',
            'part_code' => 'P1001',
            'part_name' => 'Front Mounting Assembly',
            'ok_qty' => 350,
            'uom' => 'PCS',
            'department' => 'Machining Shop',
            'operator_name' => 'Rajesh Sharma',
            'production_date' => date('Y-m-d', strtotime('-4 days')),
            'status' => 'Completed'
        ],
        [
            'id' => 2,
            'mip_no' => 'MIP-002',
            'work_order' => 'WO-9842',
            'part_code' => 'P1002',
            'part_name' => 'Main Chassis Sub-Assembly',
            'ok_qty' => 200,
            'uom' => 'PCS',
            'department' => 'Fabrication Floor',
            'operator_name' => 'Amit Kumar',
            'production_date' => date('Y-m-d', strtotime('-2 days')),
            'status' => 'Completed'
        ],
        [
            'id' => 3,
            'mip_no' => 'MIP-003',
            'work_order' => 'WO-9845',
            'part_code' => 'P1003',
            'part_name' => 'Support Bracket Assembly',
            'ok_qty' => 450,
            'uom' => 'PCS',
            'department' => 'Welding Bay 2',
            'operator_name' => 'Sunil Verma',
            'production_date' => date('Y-m-d', strtotime('-1 days')),
            'status' => 'Completed'
        ],
        [
            'id' => 4,
            'mip_no' => 'MIP-004',
            'work_order' => 'WO-9849',
            'part_code' => 'P1004',
            'part_name' => 'Retainer Frame Assembly',
            'ok_qty' => 180,
            'uom' => 'PCS',
            'department' => 'Assembly Line 1',
            'operator_name' => 'Rajesh Sharma',
            'production_date' => date('Y-m-d'),
            'status' => 'Completed'
        ],
        [
            'id' => 5,
            'mip_no' => 'MIP-005',
            'work_order' => 'WO-9850',
            'part_code' => 'P1005',
            'part_name' => 'Heavy Duty Base Plate',
            'ok_qty' => 220,
            'uom' => 'PCS',
            'department' => 'Press Shop',
            'operator_name' => 'Vikas Singh',
            'production_date' => date('Y-m-d'),
            'status' => 'Completed'
        ]
    ];
}

// Fetch real FG Inventory (Tab 1), Inward Logs (Tab 2), and Dispatch Logs (Tab 3)
$fgInventoryList = [];
$fgInwardLogsList = [];
$fgDispatchLogsList = [];

if ($pdo) {
    try {
        syncFgInventoryRecords($pdo);
        $stmtInv = $pdo->query("
            SELECT id, part_code, part_name, customer_name, total_ok_qty, uom,
                   variant_stock_details, last_mip_no,
                   CONVERT(VARCHAR(10), last_production_date, 120) as production_date,
                   CONVERT(VARCHAR(19), updated_at, 120) as updated_at
            FROM fg_inventory
            ORDER BY id DESC
        ");
        $fgInventoryList = $stmtInv ? $stmtInv->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        // Fallback
    }

    try {
        $stmtLogs = $pdo->query("
            SELECT id, inward_no, mip_no,
                   CONVERT(VARCHAR(10), production_date, 120) as production_date,
                   part_code, part_name, customer_name, ok_qty, original_ok_qty, rework_ok_qty,
                   has_rework_appended, rework_appended_info, variant_details,
                   uom, qc_status, work_order, received_by, remarks,
                   CONVERT(VARCHAR(19), created_at, 120) as created_at
            FROM fg_inward_logs
            ORDER BY id DESC
        ");
        if ($stmtLogs) {
            $fgInwardLogsList = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Fallback
    }
    try {
        $stmtDsp = $pdo->query("
            SELECT id, dispatch_no,
                   CONVERT(VARCHAR(10), dispatch_date, 120) as dispatch_date,
                   invoice_ref, part_code, part_name, batch_no,
                   dispatch_qty, uom, customer, dispatched_by, remarks,
                   variant_dispatch_details,
                   CONVERT(VARCHAR(19), created_at, 120) as created_at
            FROM fg_dispatch_logs
            ORDER BY id DESC
        ");
        if ($stmtDsp) {
            $fgDispatchLogsList = $stmtDsp->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Fallback
    }
}

// Available MIPs in store grouped for dispatch dropdowns
$availableMipsForDispatch = ($pdo) ? getAvailableMipsForDispatch($pdo) : [];

// Calculated stats based on real database records
$totalStock = 0;
$todayInward = 0;
$totalDispatched = 0;
$todayStr = date('Y-m-d');

foreach ($fgInventoryList as $item) {
    $totalStock += floatval($item['total_ok_qty'] ?? 0);
}

foreach ($fgInwardLogsList as $item) {
    $inDate = $item['production_date'] ?? '';
    if (empty($inDate) && !empty($item['created_at'])) {
        $inDate = substr($item['created_at'], 0, 10);
    }
    if ($inDate === $todayStr) {
        $todayInward += floatval($item['ok_qty'] ?? 0);
    }
}

foreach ($fgDispatchLogsList as $item) {
    $totalDispatched += floatval($item['dispatch_qty'] ?? 0);
}

$pageTitle = 'Finished Goods Store (FG Store)';
$activeMenu = 'fg-store.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Finished Goods Store (FG Store) - WIP Management Portal</title>
  
  <!-- Modern Clean Google Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="css/dashboard.css">

  <style>
    /* Clean, Simple UI Overrides matching MIP portal */
    .stat-number.dispatched {
      color: #6366f1;
    }
    
    /* Sub metadata below main text (Prevents text merging) */
    .sub-meta {
      display: block;
      font-size: 0.74rem;
      color: var(--text-sub);
      margin-top: 3px;
      font-weight: 500;
      line-height: 1.25;
      white-space: nowrap;
    }

    /* Batch / Lot Code */
    .batch-lot-code {
      white-space: nowrap;
      font-weight: 600;
      color: #334155;
      font-family: inherit;
      letter-spacing: -0.01em;
    }

    /* Quantity Badges */
    .qty-badge {
      display: inline-block;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 5px;
      font-variant-numeric: tabular-nums;
      font-size: 0.82rem;
      min-width: 48px;
      text-align: center;
      box-sizing: border-box;
      line-height: 1.3;
    }
    .qty-badge.neutral {
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #e2e8f0;
    }
    .qty-badge.in-stock {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
    }
    .qty-badge.low-stock {
      background: #fffbeb;
      color: #b45309;
      border: 1px solid #fde68a;
    }
    .qty-badge.zero-stock {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    /* Status Tags */
    .tag-available {
      background: #ecfdf5;
      color: #059669;
    }
    .tag-low {
      background: #fffbeb;
      color: #d97706;
    }
    .tag-dispatched {
      background: #eff6ff;
      color: #2563eb;
    }
    .tag-passed {
      background: #ecfdf5;
      color: #047857;
      font-weight: 700;
    }

    /* 2-Column Stats Row */
    .stats-row-2 {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 16px;
    }
    @media (max-width: 640px) {
      .stats-row-2 {
        grid-template-columns: 1fr;
      }
    }

    /* Action Buttons in Table */
    .action-btns {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
      flex-wrap: nowrap;
    }
    .btn-view, .btn-dispatch, .btn-print, .btn-delete {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 4px 9px;
      border-radius: 4px;
      font-size: 0.78rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
      line-height: 1.2;
      white-space: nowrap;
      border: 1px solid var(--border);
      text-decoration: none;
    }
    .btn-view {
      background: #ffffff;
      color: #0284c7;
      border-color: #bae6fd;
    }
    .btn-view:hover {
      background: #f0f9ff;
      border-color: #0284c7;
    }
    .btn-dispatch {
      background: #eef2ff;
      color: #4f46e5;
      border-color: #c7d2fe;
    }
    .btn-dispatch:hover {
      background: #e0e7ff;
      border-color: #4f46e5;
    }
    .btn-dispatch:disabled,
    .btn-dispatch.disabled,
    .btn-dispatched {
      background: #f1f5f9 !important;
      color: #94a3b8 !important;
      border-color: #cbd5e1 !important;
      cursor: not-allowed !important;
      pointer-events: none !important;
      opacity: 0.85;
      box-shadow: none !important;
    }
    .btn-print {
      background: #ffffff;
      color: #059669;
      border-color: #a7f3d0;
    }
    .btn-print:hover {
      background: #ecfdf5;
      border-color: #059669;
    }
    .btn-delete {
      background: #ffffff;
      color: #ef4444;
      border-color: #fecaca;
    }
    .btn-delete:hover {
      background: #fef2f2;
      border-color: #ef4444;
    }

    /* Table alignment & spacing enhancements */
    .simple-table th {
      vertical-align: middle;
      padding: 11px 12px;
      font-size: 0.76rem;
      letter-spacing: 0.03em;
    }
    .simple-table td {
      vertical-align: middle;
      padding: 10px 12px;
      font-size: 0.86rem;
    }

    /* 3 Clean Tabs Navigation */
    .tabs-nav {
      display: flex;
      gap: 10px;
      border-bottom: 2px solid #e2e8f0;
      margin-bottom: 18px;
    }
    .tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 11px 20px;
      font-size: 0.88rem;
      font-weight: 600;
      color: var(--text-sub);
      background: transparent;
      border: none;
      border-bottom: 2px solid transparent;
      margin-bottom: -2px;
      cursor: pointer;
      transition: all 0.15s ease;
      font-family: inherit;
    }
    .tab-btn:hover {
      color: var(--primary);
      background: #f8fafc;
      border-radius: 6px 6px 0 0;
    }
    .tab-btn.active {
      color: var(--primary);
      border-bottom: 2px solid var(--primary);
      background: transparent;
    }
    .tab-count {
      background: #f1f5f9;
      color: #475569;
      font-size: 0.74rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 12px;
    }
    .tab-btn.active .tab-count {
      background: var(--primary-light);
      color: var(--primary);
    }
    .tab-pane {
      display: none;
    }
    .tab-pane.active {
      display: block;
    }

    /* Modal Form Styles */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(3px);
      z-index: 100;
      align-items: center;
      justify-content: center;
      padding: 16px;
      box-sizing: border-box;
    }
    .modal-overlay.active {
      display: flex;
    }
    .modal-card {
      background: #ffffff;
      border-radius: 12px;
      width: 100%;
      max-width: 680px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      border: 1px solid var(--border);
      animation: modalPopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes modalPopIn {
      from { opacity: 0; transform: scale(0.96) translateY(8px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .modal-header {
      padding: 16px 22px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #f8fafc;
    }
    .modal-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.01em;
    }
    .modal-close-btn {
      background: transparent;
      border: none;
      font-size: 1.6rem;
      line-height: 1;
      color: var(--text-sub);
      cursor: pointer;
      padding: 2px 6px;
      border-radius: 4px;
      transition: all 0.15s ease;
    }
    .modal-close-btn:hover {
      color: var(--text-main);
      background: #e2e8f0;
    }
    .modal-body {
      padding: 22px;
      max-height: calc(85vh - 130px);
      overflow-y: auto;
    }
    .popup-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    @media (max-width: 580px) {
      .popup-form-grid {
        grid-template-columns: 1fr;
      }
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .col-full, .form-group.col-full {
      grid-column: 1 / -1;
    }
    .form-group label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }
    .form-group .form-control {
      width: 100%;
      height: 38px;
      padding: 0 12px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: inherit;
      font-size: 0.86rem;
      color: var(--text-main);
      background: #ffffff;
      outline: none;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
      box-sizing: border-box;
    }
    .form-group .form-control:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .form-group .form-control[readonly] {
      background: #f8fafc;
      color: var(--text-sub);
      cursor: not-allowed;
    }
    .modal-footer {
      padding: 14px 22px;
      border-top: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      background: #f8fafc;
    }
    .btn-secondary {
      padding: 8px 16px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 0.86rem;
      font-weight: 600;
      color: var(--text-sub);
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-secondary:hover {
      background: #f1f5f9;
      color: var(--text-main);
    }

    /* Print Slip Styles */
    @media print {
      body * {
        visibility: hidden !important;
      }
      #printArea, #printArea * {
        visibility: visible !important;
      }
      #printArea {
        position: fixed;
        left: 0;
        top: 0;
        width: 100%;
        background: #fff;
        padding: 20px;
        box-sizing: border-box;
      }
    }
    .print-box {
      display: none;
    }
    @media print {
      .print-box {
        display: block !important;
      }
    }

    /* Toast Notification System */
    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 10px;
      pointer-events: none;
    }
    .toast {
      min-width: 290px;
      max-width: 380px;
      padding: 13px 16px;
      border-radius: 8px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.05);
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.88rem;
      color: var(--text-main);
      pointer-events: auto;
      opacity: 0;
      transform: translateX(40px);
      transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .toast.show {
      opacity: 1;
      transform: translateX(0);
    }
    .toast.toast-success {
      border-left: 4px solid #10b981;
      color: #065f46;
    }
    .toast.toast-error {
      border-left: 4px solid #ef4444;
      color: #991b1b;
    }
    .toast.toast-info {
      border-left: 4px solid #2563eb;
      color: #1e40af;
    }
    .toast-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .toast-message {
      flex: 1;
      line-height: 1.4;
      font-weight: 500;
    }
  </style>
</head>
<body>

  <div class="layout-wrapper">
    
    <!-- Sidebar Navigation -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
      
      <!-- Top Header Navbar -->
      <?php include __DIR__ . '/includes/header.php'; ?>

      <!-- Content Body -->
      <div class="content-body">
        
        <!-- 2 Simple Stats Cards -->
        <div class="stats-row-2">
          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statTotalStock"><?php echo number_format($totalStock); ?></div>
            <div class="stat-title">Total FG Available Stock (Units)</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number dispatched" id="statTotalDispatched"><?php echo number_format($totalDispatched); ?></div>
            <div class="stat-title">Total Dispatched Qty</div>
          </div>
        </div>

        <!-- 3 Clean Tabs Navigation -->
        <div class="tabs-nav">
          <button type="button" class="tab-btn active" data-tab="tabInventory">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
            <span>FG Stock Inventory</span>
            <span class="tab-count" id="countInventory"><?php echo count($fgInventoryList); ?></span>
          </button>

          <button type="button" class="tab-btn" data-tab="tabInwardLogs">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
            <span>Inward Logs</span>
            <span class="tab-count" id="countInward"><?php echo count($fgInwardLogsList); ?></span>
          </button>

          <button type="button" class="tab-btn" data-tab="tabDispatchLogs">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
            <span>Dispatch Logs</span>
            <span class="tab-count" id="countDispatch"><?php echo count($fgDispatchLogsList); ?></span>
          </button>
        </div>

        <!-- =========================================================
             TAB 1: FG Stock Inventory
             ========================================================= -->
        <div class="tab-pane active" id="tabInventory">
          <div class="simple-card table-box">
            
            <div class="table-bar">
              <h2 class="box-title">Finished Goods (FG) Inventory Stock</h2>
              <div class="table-actions">
                <input type="text" id="fgSearch" class="simple-input" placeholder="Search Part Code or Name..." style="width: 280px;">
                <button type="button" class="btn-primary openInwardModalTrigger" id="openInwardModalBtnTab1">+ Inward FG</button>
                <button type="button" class="btn-primary openDispatchModalTrigger" style="background:#4f46e5; border-color:#4338ca;">+ Dispatch FG</button>
              </div>
            </div>

            <!-- Table Container -->
            <div class="table-wrap">
              <table class="simple-table" id="fgTable">
                <thead>
                  <tr>
                    <th style="width: 50px; text-align: center;">Sr No.</th>
                    <th style="min-width: 170px;">Customer</th>
                    <th style="min-width: 260px;">Part Code &amp; Name</th>
                    <th style="width: 140px; text-align: center; white-space: nowrap;">Stock Quantity</th>
                    <th style="width: 150px; text-align: center; white-space: nowrap;">Action</th>
                  </tr>
                </thead>
                <tbody id="fgTableBody">
                  <?php if (empty($fgInventoryList)): ?>
                    <tr id="emptyFgRow">
                      <td colspan="5" style="text-align: center; padding: 36px 20px; color: var(--text-sub);">
                        No finished goods inventory recorded yet. Go to <strong>Inward Logs</strong> tab and click <strong>+ Inward FG</strong> to add stock.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php $sr = 1; ?>
                    <?php foreach ($fgInventoryList as $item): ?>
                      <?php 
                        $stockQty = floatval($item['total_ok_qty']);
                        $isDispatched = ($stockQty <= 0);
                        $badgeCls = 'in-stock';
                        if ($stockQty <= 0) $badgeCls = 'zero-stock';
                        elseif ($stockQty <= 35) $badgeCls = 'low-stock';
                      ?>
                      <tr data-id="<?php echo htmlspecialchars($item['id']); ?>"
                          data-part_code="<?php echo htmlspecialchars($item['part_code']); ?>"
                          data-part_name="<?php echo htmlspecialchars($item['part_name']); ?>"
                          data-customer="<?php echo htmlspecialchars($item['customer_name'] ?? ''); ?>"
                          data-variant_stock_details="<?php echo htmlspecialchars($item['variant_stock_details'] ?? ''); ?>"
                          data-ok_qty="<?php echo htmlspecialchars($item['total_ok_qty']); ?>"
                          data-available_stock="<?php echo htmlspecialchars($item['total_ok_qty']); ?>"
                          data-uom="<?php echo htmlspecialchars($item['uom'] ?? 'PCS'); ?>"
                          data-mip_no="<?php echo htmlspecialchars($item['last_mip_no'] ?? ''); ?>"
                          data-inward_no="<?php echo htmlspecialchars($item['last_mip_no'] ?? ''); ?>"
                          data-batch_no="<?php echo htmlspecialchars($item['last_mip_no'] ?? ''); ?>"
                          data-production_date="<?php echo htmlspecialchars($item['production_date'] ?? ''); ?>"
                          data-inward_date="<?php echo htmlspecialchars($item['production_date'] ?? ''); ?>"
                          data-status="<?php echo $isDispatched ? 'Dispatched' : 'Available'; ?>">
                        
                        <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $sr++; ?></td>
                        
                        <td style="white-space: nowrap;">
                          <strong style="color: #1e293b; font-size: 0.90rem;">
                            <?php echo htmlspecialchars(!empty($item['customer_name']) ? $item['customer_name'] : '-'); ?>
                          </strong>
                        </td>

                        <td>
                          <div style="font-weight: 700; color: var(--text-main); font-size: 0.95rem;">
                            <?php echo htmlspecialchars($item['part_code']); ?> - <?php echo htmlspecialchars($item['part_name']); ?>
                          </div>
                          <div style="margin-top: 4px; display: inline-flex; flex-wrap: wrap; align-items: center; gap: 6px;">
                            <?php if (!empty($item['last_mip_no'])): ?>
                              <span class="tag" style="background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-size: 0.74rem; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                                MIP: <?php echo htmlspecialchars($item['last_mip_no']); ?>
                              </span>
                            <?php endif; ?>
                            <?php if (!empty($item['has_rework_appended'])): ?>
                              <span class="tag" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 0.72rem; font-weight: 700; padding: 1px 6px; border-radius: 4px;" title="Consolidated with Rework Runs">
                                + Rework
                              </span>
                            <?php endif; ?>
                          </div>
                        </td>
                        
                        <td style="text-align: center; white-space: nowrap;">
                          <span class="qty-badge <?php echo $badgeCls; ?>"><?php echo formatCleanStock($stockQty); ?> <?php echo htmlspecialchars($item['uom'] ?? 'PCS'); ?></span>
                        </td>

                        <td style="text-align: center; white-space: nowrap;">
                          <div class="action-btns" style="justify-content: center;">
                            <button type="button" class="btn-view" title="View Stock Details">View</button>
                            <?php if (hasPermission('fg_store', 'create')): ?>
                              <?php if ($isDispatched): ?>
                                <button type="button" class="btn-dispatch btn-dispatched" disabled title="Part has been fully dispatched">Dispatched</button>
                              <?php else: ?>
                                <button type="button" class="btn-dispatch" title="Dispatch Part">Dispatch</button>
                              <?php endif; ?>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>

        <!-- =========================================================
             TAB 2: Inward Logs
             ========================================================= -->
        <div class="tab-pane" id="tabInwardLogs">
          <div class="simple-card table-box">
            
            <div class="table-bar">
              <h2 class="box-title">Finished Goods Inward Receipts &amp; Logs</h2>
              <div class="table-actions">
                <input type="text" id="inwardSearch" class="simple-input" placeholder="Search MIP No, Part, Inward No..." style="width: 280px;">
                <?php if (hasPermission('fg_store', 'create')): ?>
                <button type="button" class="btn-primary openInwardModalTrigger" id="openInwardModalBtn">+ Inward FG</button>
                <?php endif; ?>
              </div>
            </div>

            <div class="table-wrap">
              <table class="simple-table" id="inwardTable">
                <thead>
                  <tr>
                    <th style="width: 50px; text-align: center;">Sr No.</th>
                    <th style="width: 140px; white-space: nowrap;">MIP No.</th>
                    <th style="min-width: 220px;">Part Code &amp; Name</th>
                    <th style="width: 140px; text-align: center; white-space: nowrap;">Ok Qty</th>
                    <th style="width: 130px; text-align: center; white-space: nowrap;">Production Date</th>
                    <th style="width: 130px; white-space: nowrap;">Received By</th>
                    <th style="width: 110px; text-align: center; white-space: nowrap;">Action</th>
                  </tr>
                </thead>
                <tbody id="inwardTableBody">
                  <?php if (empty($fgInwardLogsList)): ?>
                    <tr id="emptyInwardRow">
                      <td colspan="7" style="text-align: center; padding: 36px 20px; color: var(--text-sub);">
                        No inward logs recorded yet. Click <strong>+ Inward FG</strong> above to inward finished goods.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php $srIn = 1; ?>
                    <?php foreach ($fgInwardLogsList as $item): ?>
                      <tr data-inward_no="<?php echo htmlspecialchars($item['inward_no']); ?>"
                          data-mip_no="<?php echo htmlspecialchars($item['mip_no']); ?>"
                          data-part_code="<?php echo htmlspecialchars($item['part_code']); ?>"
                          data-part_name="<?php echo htmlspecialchars($item['part_name']); ?>"
                          data-customer="<?php echo htmlspecialchars($item['customer_name'] ?? ''); ?>"
                          data-variant_details="<?php echo htmlspecialchars($item['variant_details'] ?? ''); ?>"
                          data-has_rework="<?php echo !empty($item['has_rework_appended']) ? '1' : '0'; ?>"
                          data-rework_info="<?php echo htmlspecialchars($item['rework_appended_info'] ?? ''); ?>"
                          data-orig_qty="<?php echo htmlspecialchars($item['original_ok_qty'] ?? ''); ?>"
                          data-rework_qty="<?php echo htmlspecialchars($item['rework_ok_qty'] ?? ''); ?>"
                          data-batch_no="<?php echo htmlspecialchars($item['mip_no']); ?>"
                          data-inward_qty="<?php echo htmlspecialchars($item['ok_qty']); ?>"
                          data-ok_qty="<?php echo htmlspecialchars($item['ok_qty']); ?>"
                          data-uom="<?php echo htmlspecialchars($item['uom']); ?>"
                          data-work_order="<?php echo htmlspecialchars($item['work_order'] ?? ''); ?>"
                          data-received_by="<?php echo htmlspecialchars($item['received_by'] ?? ''); ?>"
                          data-production_date="<?php echo htmlspecialchars($item['production_date'] ?? ''); ?>"
                          data-created_at="<?php echo htmlspecialchars($item['created_at'] ?? ''); ?>"
                          data-remarks="<?php echo htmlspecialchars($item['remarks'] ?? ''); ?>">
                        <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $srIn++; ?></td>
                        <td style="white-space: nowrap;">
                          <strong style="color: var(--text-main); font-size: 0.95rem;"><?php echo htmlspecialchars($item['mip_no']); ?></strong>
                          <?php if (!empty($item['has_rework_appended'])): ?>
                            <span style="margin-left: 4px; background: #fef3c7; color: #92400e; font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; border: 1px solid #fde68a;" title="Consolidated with Rework Runs">+ Rework</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div style="font-weight: 700; color: var(--text-main);">
                            <?php echo htmlspecialchars($item['part_code']); ?> - <?php echo htmlspecialchars($item['part_name']); ?>
                          </div>
                          <?php if (!empty($item['customer_name'])): ?>
                            <div style="margin-top: 3px; font-size: 0.74rem; color: #475569;">
                              Customer: <strong style="color: #1e40af;"><?php echo htmlspecialchars($item['customer_name']); ?></strong>
                            </div>
                          <?php endif; ?>
                          <?php if (!empty($item['variant_details'])): ?>
                            <div style="margin-top: 4px;">
                              <?php echo renderVariantBadgesHtml($item['variant_details']); ?>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td style="text-align: center; white-space: nowrap;">
                          <span class="qty-badge neutral"><?php echo formatCleanStock($item['ok_qty']); ?> <?php echo htmlspecialchars($item['uom']); ?></span>
                          <?php if (!empty($item['has_rework_appended']) && floatval($item['rework_ok_qty'] ?? 0) > 0): ?>
                            <div class="sub-meta" style="color: #b45309; font-weight: 600; margin-top: 2px;">
                              (Orig: <?php echo formatCleanStock($item['original_ok_qty'] ?? $item['ok_qty']); ?> + R: <?php echo formatCleanStock($item['rework_ok_qty']); ?>)
                            </div>
                          <?php endif; ?>
                        </td>
                        <td style="text-align: center; white-space: nowrap; font-weight: 600; color: var(--text-main);">
                          <?php echo htmlspecialchars(formatDateDMY($item['production_date'])); ?>
                        </td>
                        <td style="white-space: nowrap;">
                          <strong><?php echo htmlspecialchars($item['received_by'] ?: '-'); ?></strong>
                        </td>
                        <td style="text-align: center; white-space: nowrap;">
                          <div class="action-btns" style="justify-content: center;">
                            <button type="button" class="btn-print" onclick="printRowTag(this)">Print Tag</button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>

        <!-- =========================================================
             TAB 3: Dispatch Logs
             ========================================================= -->
        <div class="tab-pane" id="tabDispatchLogs">
          <div class="simple-card table-box">
            
            <div class="table-bar">
              <h2 class="box-title">Finished Goods Dispatch &amp; Outward Logs</h2>
              <div class="table-actions">
                <input type="text" id="dispatchSearch" class="simple-input" placeholder="Search Dispatch No, Invoice, Part..." style="width: 280px;">
                <?php if (hasPermission('fg_store', 'create')): ?>
                <button type="button" class="btn-primary openDispatchModalTrigger" id="openDispatchModalBtn" style="background:#4f46e5; border-color:#4338ca;">+ Dispatch FG</button>
                <?php endif; ?>
              </div>
            </div>

            <div class="table-wrap">
              <table class="simple-table" id="dispatchTable">
                <thead>
                  <tr>
                    <th style="width: 45px; text-align: center;">Sr No.</th>
                    <th style="width: 140px; white-space: nowrap;">Dispatch No. &amp; Date</th>
                    <th style="width: 150px; white-space: nowrap;">Invoice / DC Ref.</th>
                    <th style="min-width: 200px;">Part Description</th>
                    <th style="width: 120px; text-align: center; white-space: nowrap;">Dispatched Qty</th>
                    <th style="width: 140px; white-space: nowrap;">Dispatched By</th>
                    <th style="width: 100px; text-align: center; white-space: nowrap;">Status</th>
                    <th style="width: 110px; text-align: center; white-space: nowrap;">Action</th>
                  </tr>
                </thead>
                <tbody id="dispatchTableBody">
                  <?php if (empty($fgDispatchLogsList)): ?>
                    <tr id="emptyDispatchRow">
                      <td colspan="8" style="text-align: center; padding: 36px 20px; color: var(--text-sub);">
                        No dispatch logs recorded yet. Click <strong>+ Dispatch FG</strong> above to dispatch finished goods.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php $srDsp = 1; ?>
                    <?php foreach ($fgDispatchLogsList as $dsp): ?>
                      <tr data-dispatch_no="<?php echo htmlspecialchars($dsp['dispatch_no']); ?>"
                          data-dispatch_date="<?php echo htmlspecialchars($dsp['dispatch_date']); ?>"
                          data-part_code="<?php echo htmlspecialchars($dsp['part_code']); ?>"
                          data-part_name="<?php echo htmlspecialchars($dsp['part_name']); ?>"
                          data-batch_no="<?php echo htmlspecialchars($dsp['batch_no'] ?? ''); ?>"
                          data-customer="<?php echo htmlspecialchars($dsp['customer']); ?>"
                          data-dispatch_qty="<?php echo htmlspecialchars($dsp['dispatch_qty']); ?>"
                          data-variant_dispatch="<?php echo htmlspecialchars($dsp['variant_dispatch_details'] ?? ''); ?>"
                          data-uom="<?php echo htmlspecialchars($dsp['uom'] ?? 'PCS'); ?>"
                          data-invoice_ref="<?php echo htmlspecialchars($dsp['invoice_ref']); ?>"
                          data-dispatched_by="<?php echo htmlspecialchars($dsp['dispatched_by']); ?>"
                          data-remarks="<?php echo htmlspecialchars($dsp['remarks'] ?? ''); ?>">
                        
                        <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $srDsp++; ?></td>
                        
                        <td style="white-space: nowrap;">
                          <strong style="color: var(--text-main);"><?php echo htmlspecialchars($dsp['dispatch_no']); ?></strong>
                          <span class="sub-meta"><?php echo htmlspecialchars(formatDateDMY($dsp['dispatch_date'])); ?></span>
                        </td>
                        
                        <td style="white-space: nowrap;">
                          <strong style="color: #4f46e5;"><?php echo htmlspecialchars($dsp['invoice_ref']); ?></strong>
                        </td>
                        
                        <td>
                          <div style="font-weight: 700; color: var(--text-main);">
                            <?php echo htmlspecialchars($dsp['part_code']); ?> - <?php echo htmlspecialchars($dsp['part_name']); ?>
                          </div>
                          <?php if (!empty($dsp['customer'])): ?>
                            <div style="margin-top: 3px; font-size: 0.74rem; color: #475569;">
                              Customer: <strong style="color: #1e40af;"><?php echo htmlspecialchars($dsp['customer']); ?></strong>
                            </div>
                          <?php endif; ?>
                          <?php if (!empty($dsp['batch_no'])): ?>
                            <span class="sub-meta">MIP: <?php echo htmlspecialchars($dsp['batch_no']); ?></span>
                          <?php endif; ?>
                        </td>
                        
                        <td style="text-align: center; white-space: nowrap;">
                          <span class="qty-badge" style="background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe;">
                            <?php echo formatCleanStock($dsp['dispatch_qty']); ?> <?php echo htmlspecialchars($dsp['uom'] ?? 'PCS'); ?>
                          </span>
                          <?php if (!empty($dsp['variant_dispatch_details'])): ?>
                            <div style="margin-top: 4px; display: flex; justify-content: center;">
                              <?php echo renderVariantBadgesHtml($dsp['variant_dispatch_details']); ?>
                            </div>
                          <?php endif; ?>
                        </td>
                        
                        <td style="white-space: nowrap;">
                          <strong><?php echo htmlspecialchars($dsp['dispatched_by']); ?></strong>
                        </td>
                        
                        <td style="text-align: center; white-space: nowrap;">
                          <span class="tag tag-dispatched">Dispatched</span>
                        </td>
                        
                        <td style="text-align: center; white-space: nowrap;">
                          <div class="action-btns" style="justify-content: center;">
                            <button type="button" class="btn-print" onclick="printDispatchSlipFromRow(this)">Print DC</button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>

      </div>

    </div>

  </div>

  <!-- =========================================================
       Modal 1: Inward Finished Goods (Scan & Fetch MIP)
       ========================================================= -->
  <div class="modal-overlay" id="inwardModal">
    <div class="modal-card" style="max-width: 760px;">
      <div class="modal-header">
        <h3 class="modal-title" id="inwardModalTitle">Inward Finished Goods</h3>
        <button type="button" class="modal-close-btn" id="closeInwardModalBtn" aria-label="Close modal">&times;</button>
      </div>
      
      <form id="inwardForm">
        <div class="modal-body">
          <div class="popup-form-grid" style="grid-template-columns: 1fr;">
            
            <!-- Scan MIP Issue Slip (Simple Plain Input Field - matching production) -->
            <div class="form-group" style="grid-column: 1 / -1;">
              <label for="scanMipInput">Scan MIP Issue Slip</label>
              <input type="text" id="scanMipInput" class="form-control" placeholder="Scan barcode or enter MIP No." autocomplete="off">
            </div>

            <!-- Fetched Production Data Table (Shows Parent MIP, Rework runs, and Consolidated Output) -->
            <div id="fetchedMipTableWrap" class="form-group" style="grid-column: 1 / -1; display: none;">
              
              <!-- Customer Banner -->
              <div id="fetchedCustomerBanner" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 14px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                  <span style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #166534; font-weight: 700; display: block;">Manufactured For Customer</span>
                  <span id="fetchedCustomerName" style="font-size: 1.05rem; font-weight: 800; color: #14532d;">-</span>
                </div>
                <div id="fetchedReworkBadgeWrap" style="display: none;">
                  <span style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 3px 9px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">
                    + Includes Rework Runs
                  </span>
                </div>
              </div>

              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-main);">
                  Production &amp; Rework Batches Breakdown:
                </span>
                <span id="fetchedMipStatusTag" style="font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; background: #ecfdf5; color: #059669; font-weight: 600; border: 1px solid #a7f3d0;">
                  Data Loaded
                </span>
              </div>

              <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 6px;">
                <table class="simple-table" style="font-size: 0.84rem; margin: 0; width: 100%;">
                  <thead style="background: #f8fafc;">
                    <tr>
                      <th style="padding: 8px 12px; width: 130px;">MIP / Run Ref</th>
                      <th style="padding: 8px 12px; width: 110px;">Type</th>
                      <th style="padding: 8px 12px; text-align: center; width: 100px;">Ok Qty</th>
                      <th style="padding: 8px 12px;">Output Variants</th>
                    </tr>
                  </thead>
                  <tbody id="fetchedRunsTableBody">
                    <!-- Populated dynamically via JS -->
                  </tbody>
                </table>
              </div>

              <div id="reworkConsolidationNotice" style="display: none; margin-top: 8px; font-size: 0.78rem; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 8px 12px; font-weight: 600;">
                &#9432; Completed Rework runs detected. On clicking <strong>Save Inward Entry</strong>, finished goods from rework will be consolidated and appended to the Parent FG stock.
              </div>
            </div>

            <!-- Hidden Inputs to hold scanned data for saving into tables -->
            <input type="hidden" id="inward_no" value="">
            <input type="hidden" id="inward_date" value="">
            <input type="hidden" id="part_code" value="">
            <input type="hidden" id="part_name" value="">
            <input type="hidden" id="customer_name" value="">
            <input type="hidden" id="batch_no" value="">
            <input type="hidden" id="inward_qty" value="">
            <input type="hidden" id="original_ok_qty" value="">
            <input type="hidden" id="rework_ok_qty" value="">
            <input type="hidden" id="has_rework_appended" value="0">
            <input type="hidden" id="variant_details" value="">
            <input type="hidden" id="rework_appended_info" value="">
            <input type="hidden" id="parent_entry_id" value="">
            <input type="hidden" id="uom" value="PCS">
            <input type="hidden" id="work_order" value="">
            <input type="hidden" id="received_by" value="">
            <input type="hidden" id="remarks" value="">
            <input type="hidden" id="is_append_rework" value="0">
            <input type="hidden" id="pending_rework_ok" value="0">
            <input type="hidden" id="pending_rework_runs" value="">
            <input type="hidden" id="pending_rework_variants" value="">

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-secondary" id="cancelInwardBtn">Cancel</button>
          <button type="submit" class="btn-primary" id="saveInwardBtn" disabled style="opacity: 0.5; cursor: not-allowed;">Save Inward Entry</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================
       Modal 2: Dispatch FG by Customer & In-Store MIP
       ========================================================= -->
  <div class="modal-overlay" id="dispatchModal">
    <div class="modal-card" style="max-width: 860px; width: 95%;">
      <div class="modal-header">
        <h3 class="modal-title">Dispatch Finished Goods</h3>
        <button type="button" class="modal-close-btn" id="closeDispatchModalBtn">&times;</button>
      </div>

      <form id="dispatchForm">
        <div class="modal-body">
          <div class="popup-form-grid">
            
            <!-- Step 1 & 2: Customer & In-Store MIP (Parallel in One Row) -->
            <div class="form-group">
              <label for="disp_customer" style="font-weight: 700; color: #0f172a;">Select Customer *</label>
              <select id="disp_customer" class="form-control" required style="font-weight: 600; font-size: 0.95rem;">
                <option value="">-- Choose Customer --</option>
              </select>
            </div>

            <div class="form-group">
              <label for="disp_mip" style="font-weight: 700; color: #0f172a;">Select In-Store MIP *</label>
              <select id="disp_mip" class="form-control" required disabled style="font-weight: 600; font-size: 0.95rem;">
                <option value="">-- Select Customer First --</option>
              </select>
            </div>

            <!-- Step 3: Invoice / DC Ref. -->
            <div class="form-group">
              <label for="disp_invoice" style="font-weight: 700; color: #0f172a;">Invoice / DC Ref. *</label>
              <input type="text" id="disp_invoice" class="form-control" placeholder="e.g. INV-2026-101 / DC-104" required>
            </div>
            
            <!-- Step 4: Dispatch Date -->
            <div class="form-group">
              <label for="disp_date" style="font-weight: 700; color: #0f172a;">Dispatch Date *</label>
              <input type="date" id="disp_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
            </div>

            <!-- Dispatched By -->
            <div class="form-group col-full">
              <label for="disp_by" style="font-weight: 700; color: #0f172a;">Dispatched By *</label>
              <input type="text" id="disp_by" class="form-control" placeholder="Store Executive" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Store Executive'); ?>" required>
            </div>

          </div><!-- /.popup-form-grid -->

          <!-- Step 5: Data Load Section (Full Width Table) -->
          <div id="dispDataLoadSection" style="display: none; width: 100%; margin-top: 18px; border-top: 1px dashed #cbd5e1; padding-top: 16px;">
            
            <!-- Hidden inputs for part data -->
            <input type="hidden" id="disp_part_code">
            <input type="hidden" id="disp_part_name">
            <input type="hidden" id="disp_uom" value="PCS">
            <input type="hidden" id="disp_max_qty" value="0">

            <!-- Dispatch Items Table (Full Width) -->
            <div style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; overflow-x: auto; background: #fff; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
              <table class="simple-table" style="margin: 0; width: 100%; border-collapse: collapse;">
                <thead style="background: #f8fafc; border-bottom: 1px solid #cbd5e1;">
                  <tr>
                    <th style="width: 50px; text-align: center; padding: 10px 8px; font-size: 0.78rem; text-transform: uppercase; color: #475569;">#</th>
                    <th id="dispTableHeaderDesc" style="padding: 10px 14px; font-size: 0.78rem; text-transform: uppercase; color: #475569;">Part / Variant Description</th>
                    <th style="width: 180px; text-align: center; white-space: nowrap; padding: 10px 12px; font-size: 0.78rem; text-transform: uppercase; color: #475569;">Available In-Store</th>
                    <th style="width: 220px; text-align: right; white-space: nowrap; padding: 10px 14px; font-size: 0.78rem; text-transform: uppercase; color: #475569;">Dispatch Qty *</th>
                  </tr>
                </thead>
                <tbody id="dispVariantsTableBody">
                  <!-- Rendered dynamically -->
                </tbody>
              </table>
            </div>

            <!-- Total Dispatch Summary Banner -->
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px 16px; width: 100%; box-sizing: border-box;">
              <span style="font-size: 0.92rem; font-weight: 700; color: #166534;">Total Dispatch Quantity:</span>
              <strong id="dispTotalQtySummary" style="font-size: 1.25rem; color: #15803d; font-weight: 800;">0 PCS</strong>
            </div>

            <!-- Consignment Remarks -->
            <div class="form-group" style="margin-top: 14px; margin-bottom: 0; width: 100%;">
              <label for="disp_remarks" style="font-weight: 600; color: #475569;">Remarks / Delivery Notes</label>
              <input type="text" id="disp_remarks" class="form-control" placeholder="Optional notes (e.g. Vehicle No., Transporter, Destination)" style="width: 100%;">
            </div>

          </div><!-- /#dispDataLoadSection -->
        </div>

        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
          <span id="dispStatusInfo" style="font-size: 0.82rem; color: var(--text-sub);">
            Select Customer &amp; MIP to load dispatch quantities
          </span>
          <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-secondary" id="cancelDispatchBtn">Cancel</button>
            <button type="submit" class="btn-primary" id="confirmDispatchBtn" style="background:#4f46e5; border-color:#4338ca;">Confirm Dispatch</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================
       Modal 3: View Details (Simple Clean Popup)
       ========================================================= -->
  <div class="modal-overlay" id="viewModal">
    <div class="modal-card" style="max-width: 580px;">
      <div class="modal-header">
        <h3 class="modal-title">Finished Goods Details</h3>
        <button type="button" class="modal-close-btn" id="closeViewModalBtn">&times;</button>
      </div>

      <div class="modal-body" id="viewModalContent">
        <!-- Rendered dynamically -->
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="closeViewBtn">Close</button>
        <button type="button" class="btn-print" id="printFromViewBtn">Print Slip</button>
        <button type="button" class="btn-primary" id="dispatchFromViewBtn" style="background:#4f46e5; border-color:#4338ca;">Dispatch Part</button>
      </div>
    </div>
  </div>

  <!-- =========================================================
       Print Slip Area (Hidden on screen, visible during print)
       ========================================================= -->
  <div id="printArea" class="print-box">
    <div style="border: 2px solid #000; padding: 20px; font-family: 'Plus Jakarta Sans', sans-serif;">
      <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 15px;">
        <h2 style="margin: 0; font-size: 20px; text-transform: uppercase;">Finished Goods Stock &amp; Inward Tag</h2>
        <p style="margin: 4px 0 0 0; font-size: 13px; color: #555;">WIP Manufacturing Management Portal - Store Department</p>
      </div>

      <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 13px;" border="1" cellpadding="8">
        <tr>
          <td style="width: 25%; font-weight: bold; background: #f2f2f2;">Inward Slip No:</td>
          <td style="width: 25%;" id="prn_inward_no">-</td>
          <td style="width: 25%; font-weight: bold; background: #f2f2f2;">Inward Date:</td>
          <td style="width: 25%;" id="prn_inward_date">-</td>
        </tr>
        <tr>
          <td style="font-weight: bold; background: #f2f2f2;">Part Code:</td>
          <td id="prn_part_code">-</td>
          <td style="font-weight: bold; background: #f2f2f2;">Part Name:</td>
          <td id="prn_part_name">-</td>
        </tr>
        <tr>
          <td style="font-weight: bold; background: #f2f2f2;">Batch / Lot No:</td>
          <td id="prn_batch_no">-</td>
          <td style="font-weight: bold; background: #f2f2f2;">Store:</td>
          <td id="prn_location">FG Warehouse</td>
        </tr>
        <tr>
          <td style="font-weight: bold; background: #f2f2f2;">Inward Quantity:</td>
          <td id="prn_inward_qty" style="font-weight: bold; font-size: 15px;">-</td>
          <td style="font-weight: bold; background: #f2f2f2;">Available Stock:</td>
          <td id="prn_available_stock" style="font-weight: bold; font-size: 15px;">-</td>
        </tr>
        <tr>
          <td style="font-weight: bold; background: #f2f2f2;">Work Order Ref:</td>
          <td id="prn_work_order">-</td>
          <td style="font-weight: bold; background: #f2f2f2;">Received By:</td>
          <td id="prn_received_by">-</td>
        </tr>
        <tr>
          <td style="font-weight: bold; background: #f2f2f2;">Remarks:</td>
          <td colspan="3" id="prn_remarks">-</td>
        </tr>
      </table>

      <div style="display: flex; justify-content: space-between; margin-top: 40px; padding: 0 20px;">
        <div style="text-align: center;">
          <div style="border-top: 1px solid #000; width: 160px; padding-top: 5px; font-size: 12px; font-weight: bold;">Store Keeper Signature</div>
        </div>
        <div style="text-align: center;">
          <div style="border-top: 1px solid #000; width: 160px; padding-top: 5px; font-size: 12px; font-weight: bold;">Quality QC Inspector</div>
        </div>
        <div style="text-align: center;">
          <div style="border-top: 1px solid #000; width: 160px; padding-top: 5px; font-size: 12px; font-weight: bold;">Authorized Signatory</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast Notification Container -->
  <div id="toastContainer" class="toast-container" aria-live="polite"></div>

  <!-- Client-side Interactive Logic -->
  <script>
    function escapeHtml(text) {
      if (text === null || text === undefined) return '';
      const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
      return text.toString().replace(/[&<>"']/g, m => map[m]);
    }

    function formatDateDMY(dStr) {
      if (!dStr) return '';
      const clean = dStr.toString().trim().split('T')[0].split(' ')[0];
      const p = clean.split('-');
      if (p.length === 3) {
        if (p[0].length === 4) {
          return `${p[2].padStart(2, '0')}-${p[1].padStart(2, '0')}-${p[0]}`;
        } else if (p[2].length === 4) {
          return clean;
        }
      }
      return dStr;
    }

    function showToast(message, type = 'success') {
      const container = document.getElementById('toastContainer');
      if (!container) return;
      const toast = document.createElement('div');
      toast.className = `toast toast-${type}`;
      const iconSvg = type === 'success'
        ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><polyline points="20 6 9 17 4 12"></polyline></svg>`
        : (type === 'error'
          ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`
          : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`);

      toast.innerHTML = `
        <div class="toast-icon">${iconSvg}</div>
        <div class="toast-message">${escapeHtml(message)}</div>
      `;
      container.appendChild(toast);
      requestAnimationFrame(() => toast.classList.add('show'));
      setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 250);
      }, 3500);
    }

    document.addEventListener('DOMContentLoaded', function() {
      // Available Production Entries from production_entry table
      const availableProductionEntries = <?php echo json_encode($productionList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

      // Set of MIP numbers already inwarded into FG Store (for quick duplicate check)
      const inwaredMipSet = new Set(
        <?php echo json_encode(
          array_values(array_filter(array_unique(array_column($fgInwardLogsList, 'mip_no')))),
          JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>.map(m => m.trim().toLowerCase())
      );

      // 3 Tabs switching logic
      const tabBtns = document.querySelectorAll('.tab-btn');
      const tabPanes = document.querySelectorAll('.tab-pane');

      tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
          tabBtns.forEach(function(b) { b.classList.remove('active'); });
          tabPanes.forEach(function(p) { p.classList.remove('active'); });

          btn.classList.add('active');
          const targetId = btn.dataset.tab;
          const targetPane = document.getElementById(targetId);
          if (targetPane) {
            targetPane.classList.add('active');
          }
        });
      });

      // Elements
      const fgSearch = document.getElementById('fgSearch');
      const statusFilter = document.getElementById('statusFilter');
      const fgTableBody = document.getElementById('fgTableBody');
      const inwardTableBody = document.getElementById('inwardTableBody');
      const dispatchTableBody = document.getElementById('dispatchTableBody');

      // Inward Modal & MIP Elements
      const inwardModal = document.getElementById('inwardModal');
      const openInwardModalBtn = document.getElementById('openInwardModalBtn');
      const closeInwardModalBtn = document.getElementById('closeInwardModalBtn');
      const cancelInwardBtn = document.getElementById('cancelInwardBtn');
      const inwardForm = document.getElementById('inwardForm');
      const scanMipInput = document.getElementById('scanMipInput');
      const saveInwardBtn = document.getElementById('saveInwardBtn');

      const dispatchModal = document.getElementById('dispatchModal');
      const closeDispatchModalBtn = document.getElementById('closeDispatchModalBtn');
      const cancelDispatchBtn = document.getElementById('cancelDispatchBtn');
      const dispatchForm = document.getElementById('dispatchForm');

      const viewModal = document.getElementById('viewModal');
      const closeViewModalBtn = document.getElementById('closeViewModalBtn');
      const closeViewBtn = document.getElementById('closeViewBtn');
      const viewModalContent = document.getElementById('viewModalContent');
      const printFromViewBtn = document.getElementById('printFromViewBtn');

      let currentActiveRow = null;

      // Clear MIP Scan state
      function clearMipScan() {
        if (scanMipInput) scanMipInput.value = '';
        const tblWrap = document.getElementById('fetchedMipTableWrap');
        if (tblWrap) tblWrap.style.display = 'none';

        const reworkBadge = document.getElementById('fetchedReworkBadgeWrap');
        if (reworkBadge) {
          reworkBadge.style.display = 'none';
          reworkBadge.innerHTML = `<span style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 3px 9px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">+ Includes Rework Runs</span>`;
        }
        const reworkNotice = document.getElementById('reworkConsolidationNotice');
        if (reworkNotice) {
          reworkNotice.style.display = 'none';
          reworkNotice.innerHTML = `&#9432; Completed Rework runs detected. On clicking <strong>Save Inward Entry</strong>, finished goods from rework will be consolidated and appended to the Parent FG stock.`;
        }

        const custNameEl = document.getElementById('fetchedCustomerName');
        if (custNameEl) custNameEl.textContent = '-';

        const statusTag = document.getElementById('fetchedMipStatusTag');
        if (statusTag) {
          statusTag.textContent = 'Data Loaded';
        }

        const runsTbody = document.getElementById('fetchedRunsTableBody');
        if (runsTbody) runsTbody.innerHTML = '';

        [
          'inward_no', 'inward_date', 'part_code', 'part_name', 'customer_name',
          'batch_no', 'inward_qty', 'original_ok_qty', 'rework_ok_qty',
          'has_rework_appended', 'variant_details', 'rework_appended_info',
          'parent_entry_id', 'uom', 'work_order', 'received_by', 'remarks',
          'is_append_rework', 'pending_rework_ok', 'pending_rework_runs', 'pending_rework_variants'
        ].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.value = '';
        });

        const isAppEl = document.getElementById('is_append_rework');
        if (isAppEl) isAppEl.value = '0';
        const pendOkEl = document.getElementById('pending_rework_ok');
        if (pendOkEl) pendOkEl.value = '0';

        if (saveInwardBtn) {
          saveInwardBtn.disabled = true;
          saveInwardBtn.style.opacity = '0.5';
          saveInwardBtn.style.cursor = 'not-allowed';
          saveInwardBtn.textContent = 'Save Inward Entry';
          saveInwardBtn.style.background = '';
        }
      }

      // Format clean integer / decimal for stock display
      function formatCleanStockJs(val) {
        const f = parseFloat(val) || 0;
        if (Number.isInteger(f)) {
          return f.toLocaleString();
        }
        return f.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 3});
      }

      // Render aesthetic variant pills in UI
      function renderVariantPillsJs(variants) {
        if (!Array.isArray(variants) || variants.length === 0) {
          return '<span style="color:#94a3b8; font-style:italic; font-size:0.75rem;">Standard Part (No Variant)</span>';
        }
        const active = variants.filter(v => (parseFloat(v.qty) || 0) > 0);
        if (active.length === 0) {
          return '<span style="color:#94a3b8; font-style:italic; font-size:0.75rem;">None</span>';
        }
        let html = '<div style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;">';
        active.forEach(v => {
          const qty = parseFloat(v.qty) || 0;
          const name = (v.name || v.code || 'Var').trim();
          const lower = name.toLowerCase();
          let bg = '#f1f5f9', color = '#334155', dot = '#64748b', border = '#e2e8f0';

          if (lower.includes('green') || lower.includes('grn')) {
            bg = '#ecfdf5'; color = '#065f46'; dot = '#10b981'; border = '#a7f3d0';
          } else if (lower.includes('yellow') || lower.includes('yel')) {
            bg = '#fffbeb'; color = '#92400e'; dot = '#f59e0b'; border = '#fde68a';
          } else if (lower.includes('red')) {
            bg = '#fef2f2'; color = '#991b1b'; dot = '#ef4444'; border = '#fecaca';
          } else if (lower.includes('blue')) {
            bg = '#eff6ff'; color = '#1e40af'; dot = '#3b82f6'; border = '#bfdbfe';
          }

          html += `<span style="display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:5px; background:${bg}; color:${color}; border:1px solid ${border}; font-size:0.75rem; font-weight:700;">
            <span style="width:6px; height:6px; border-radius:50%; background:${dot}; display:inline-block;"></span>
            ${escapeHtml(name)}: ${formatCleanStockJs(qty)}
          </span>`;
        });
        html += '</div>';
        return html;
      }

      // Render structured variant breakdown table for View Modal
      function renderVariantStockTableJs(variants, uom) {
        if (!Array.isArray(variants) || variants.length === 0) {
          return '<div style="color: #64748b; font-size: 0.82rem; font-style: italic; padding: 6px 0;">Standard Product (No Output Variants Defined)</div>';
        }
        const active = variants.filter(v => (parseFloat(v.qty) || 0) > 0);
        if (active.length === 0) {
          return '<div style="color: #64748b; font-size: 0.82rem; font-style: italic; padding: 6px 0;">No active variant stock available.</div>';
        }
        let html = `
          <table style="width: 100%; border-collapse: collapse; font-size: 0.84rem; background: #ffffff; border-radius: 6px; overflow: hidden; border: 1px solid #e2e8f0;">
            <thead>
              <tr style="background: #f1f5f9; color: #475569; font-size: 0.74rem; text-transform: uppercase;">
                <th style="padding: 7px 10px; text-align: left; border-bottom: 1px solid #e2e8f0;">Variant Name</th>
                <th style="padding: 7px 10px; text-align: left; border-bottom: 1px solid #e2e8f0;">Variant Code</th>
                <th style="padding: 7px 10px; text-align: right; border-bottom: 1px solid #e2e8f0;">In-Stock Qty</th>
              </tr>
            </thead>
            <tbody>
        `;
        active.forEach(v => {
          const qty = parseFloat(v.qty) || 0;
          const name = (v.name || v.code || 'Variant').trim();
          const code = (v.code || '-').trim();
          const lower = name.toLowerCase();
          let dot = '#64748b';
          if (lower.includes('green') || lower.includes('grn')) dot = '#10b981';
          else if (lower.includes('yellow') || lower.includes('yel')) dot = '#f59e0b';
          else if (lower.includes('red')) dot = '#ef4444';
          else if (lower.includes('blue')) dot = '#3b82f6';

          html += `
            <tr style="border-bottom: 1px solid #f1f5f9;">
              <td style="padding: 8px 10px; font-weight: 700; color: #1e293b;">
                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ${dot}; margin-right: 6px;"></span>
                ${escapeHtml(name)}
              </td>
              <td style="padding: 8px 10px; color: #64748b; font-family: monospace;">${escapeHtml(code)}</td>
              <td style="padding: 8px 10px; text-align: right; font-weight: 800; color: #0f172a;">
                ${formatCleanStockJs(qty)} <span style="font-size: 0.72rem; color: #64748b; font-weight: 500;">${escapeHtml(uom || 'PCS')}</span>
              </td>
            </tr>
          `;
        });
        html += '</tbody></table>';
        return html;
      }

      // Fetch Scanned MIP from production_entry table (Parent + any Rework batches)
      async function fetchScannedMip(code) {
        const query = (code || '').trim();
        if (!query) {
          clearMipScan();
          return;
        }

        const tblWrap = document.getElementById('fetchedMipTableWrap');
        const runsTbody = document.getElementById('fetchedRunsTableBody');

        try {
          const res = await fetch('fg-store.php?action=search_mip&q=' + encodeURIComponent(query));
          const resJson = await res.json();

          if (resJson.success && resJson.data && resJson.data.mip_no) {
            const match = resJson.data;

            // ── Duplicate Inward check & Append Remaining Rework logic ───────────
            const isAlreadyInwarded = match.already_inwarded === true;
            const canAppendRework = match.can_append_rework === true;

            if (isAlreadyInwarded && !canAppendRework) {
              const inwardRef = match.inward_ref ? ` (Ref: ${match.inward_ref})` : '';
              const inwQtyStr = formatCleanStockJs(match.inward_ok_qty || match.previously_inwarded_qty);
              showToast(
                `MIP "${match.mip_no}" is already fully inwarded into FG Store${inwardRef} (${inwQtyStr} ${match.uom || 'PCS'}). No new pending rework output found.`,
                'error'
              );
              if (scanMipInput) {
                scanMipInput.value = '';
                scanMipInput.focus();
              }
              clearMipScan();
              return;
            }
            // ───────────────────────────────────────────────────────────────────

            const pCode = match.part_code || '';
            const pName = match.part_name || pCode;
            const uom = match.uom || 'PCS';
            const customerName = match.customer_name || 'General Customer';
            const prodDate = match.production_date || new Date().toISOString().split('T')[0];
            const newInNo = 'FGI-' + new Date().getFullYear() + '-' + String(Math.floor(100 + Math.random() * 900));

            // Set Customer Banner
            const custEl = document.getElementById('fetchedCustomerName');
            if (custEl) custEl.textContent = customerName;

            const reworkBadge = document.getElementById('fetchedReworkBadgeWrap');
            const reworkNotice = document.getElementById('reworkConsolidationNotice');
            const statusTag = document.getElementById('fetchedMipStatusTag');

            let rowsHtml = '';

            if (isAlreadyInwarded && canAppendRework) {
              // ========================================================
              // MODE: APPEND REMAINING REWORK TO EXISTING FG STOCK
              // ========================================================
              const prevInwQty = parseFloat(match.previously_inwarded_qty || match.inward_ok_qty || 0);
              const pendingRwOk = parseFloat(match.pending_rework_ok || 0);
              const newTotal = parseFloat(match.new_total_ok_qty || (prevInwQty + pendingRwOk));

              if (reworkBadge) {
                reworkBadge.innerHTML = `<span style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 4px 10px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">
                  &#9888; In Stock: ${formatCleanStockJs(prevInwQty)} ${escapeHtml(uom)} &mdash; +${formatCleanStockJs(pendingRwOk)} ${escapeHtml(uom)} Rework Found
                </span>`;
                reworkBadge.style.display = 'block';
              }

              if (statusTag) {
                statusTag.innerHTML = `<span style="background: #fef3c7; color: #92400e; font-weight: 700; padding: 2px 8px; border-radius: 4px; border: 1px solid #fde68a;">+ Append Remaining Rework</span>`;
              }

              // Row 1: Previously Inwarded FG
              rowsHtml += `
                <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                  <td style="padding: 10px 12px; vertical-align: middle;">
                    <strong style="color: var(--text-main); font-size: 0.92rem;">${escapeHtml(match.mip_no)}</strong>
                    <div style="font-size: 0.72rem; color: #64748b;">Inward Ref: ${escapeHtml(match.inward_ref || '-')}</div>
                  </td>
                  <td style="padding: 10px 12px; vertical-align: middle;">
                    <span class="tag" style="background: #f1f5f9; color: #475569; font-weight: 700; border: 1px solid #cbd5e1;">Already In Stock</span>
                  </td>
                  <td style="padding: 10px 12px; text-align: center; vertical-align: middle;">
                    <strong style="color: #475569; font-size: 0.95rem;">${formatCleanStockJs(prevInwQty)} ${escapeHtml(uom)}</strong>
                  </td>
                  <td style="padding: 10px 12px; vertical-align: middle;">
                    ${renderVariantPillsJs(match.previously_inwarded_variants)}
                  </td>
                </tr>
              `;

              // Row 2..N: Pending Rework Runs
              if (Array.isArray(match.pending_rework_runs)) {
                match.pending_rework_runs.forEach(r => {
                  const rQty = parseFloat(r.ok_qty || 0);
                  rowsHtml += `
                    <tr style="background: #fffbeb;">
                      <td style="padding: 10px 12px; vertical-align: middle;">
                        <strong style="color: #92400e; font-size: 0.92rem;">${escapeHtml(match.mip_no)} <span style="font-size: 0.74rem; padding: 1px 6px; border-radius: 9999px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">${escapeHtml(r.rework_label || 'R')}</span></strong>
                        <div style="font-size: 0.72rem; color: #b45309;">Completed: ${escapeHtml(r.production_date || '-')}</div>
                      </td>
                      <td style="padding: 10px 12px; vertical-align: middle;">
                        <span class="tag" style="background: #fef3c7; color: #92400e; font-weight: 700; border: 1px solid #fde68a;">+ Rework (${escapeHtml(r.rework_label || 'R')})</span>
                      </td>
                      <td style="padding: 10px 12px; text-align: center; vertical-align: middle;">
                        <strong style="color: #b45309; font-size: 0.95rem;">+${formatCleanStockJs(rQty)} ${escapeHtml(uom)}</strong>
                      </td>
                      <td style="padding: 10px 12px; vertical-align: middle;">
                        ${renderVariantPillsJs(r.variants)}
                      </td>
                    </tr>
                  `;
                });
              }

              // Row 3: Updated Consolidated Total
              rowsHtml += `
                <tr style="background: #ecfdf5; border-top: 2px solid #a7f3d0;">
                  <td style="padding: 11px 12px; vertical-align: middle;">
                    <strong style="color: #065f46; font-size: 0.95rem;">Updated Total FG Stock</strong>
                  </td>
                  <td style="padding: 11px 12px; vertical-align: middle;">
                    <span class="tag" style="background: #d1fae5; color: #065f46; font-weight: 800; border: 1px solid #6ee7b7;">Consolidated FG</span>
                  </td>
                  <td style="padding: 11px 12px; text-align: center; vertical-align: middle;">
                    <strong style="color: #047857; font-size: 1.1rem;">${formatCleanStockJs(newTotal)} ${escapeHtml(uom)}</strong>
                  </td>
                  <td style="padding: 11px 12px; vertical-align: middle;">
                    ${renderVariantPillsJs(match.merged_all_variants)}
                  </td>
                </tr>
              `;

              if (reworkNotice) {
                reworkNotice.innerHTML = `&#9432; <strong>Remaining Rework Detected:</strong> MIP "<strong>${escapeHtml(match.mip_no)}</strong>" is already in FG Store (${formatCleanStockJs(prevInwQty)} ${escapeHtml(uom)}). Found <strong>+${formatCleanStockJs(pendingRwOk)} ${escapeHtml(uom)}</strong> newly finished Rework quantity. Click <strong>+ Append Rework to FG Stock</strong> below to update inventory stock and append new output variants.`;
                reworkNotice.style.display = 'block';
              }

              if (runsTbody) runsTbody.innerHTML = rowsHtml;

              // Set hidden inputs
              document.getElementById('is_append_rework').value = '1';
              document.getElementById('pending_rework_ok').value = pendingRwOk;
              document.getElementById('pending_rework_runs').value = JSON.stringify(match.pending_rework_runs || []);
              document.getElementById('pending_rework_variants').value = JSON.stringify(match.pending_rework_variants || []);
              document.getElementById('inward_no').value = match.inward_ref || newInNo;
              document.getElementById('inward_date').value = prodDate;
              document.getElementById('part_code').value = pCode;
              document.getElementById('part_name').value = pName;
              document.getElementById('customer_name').value = customerName;
              document.getElementById('batch_no').value = match.mip_no || '';
              document.getElementById('inward_qty').value = newTotal;
              document.getElementById('original_ok_qty').value = prevInwQty;
              document.getElementById('rework_ok_qty').value = pendingRwOk;
              document.getElementById('has_rework_appended').value = '1';
              document.getElementById('variant_details').value = JSON.stringify(match.merged_all_variants || []);
              document.getElementById('rework_appended_info').value = JSON.stringify(match.all_rework_runs || []);
              document.getElementById('parent_entry_id').value = match.parent_entry_id || '';
              document.getElementById('uom').value = uom;
              document.getElementById('work_order').value = match.work_order || '';
              document.getElementById('received_by').value = match.received_by || match.operator_name || '';
              document.getElementById('remarks').value = `Appended +${formatCleanStockJs(pendingRwOk)} ${uom} rework to existing stock (${formatCleanStockJs(prevInwQty)} ${uom}) for ${customerName}`;

              // Configure Save Button
              if (saveInwardBtn) {
                saveInwardBtn.disabled = false;
                saveInwardBtn.style.opacity = '1';
                saveInwardBtn.style.cursor = 'pointer';
                saveInwardBtn.textContent = `+ Append Rework (+${formatCleanStockJs(pendingRwOk)} ${uom})`;
                saveInwardBtn.style.background = '#059669';
              }

              if (tblWrap) tblWrap.style.display = 'block';
              showToast(`MIP "${match.mip_no}" is already in stock. Found +${formatCleanStockJs(pendingRwOk)} ${uom} new Rework ready to append!`, 'warning');
              return;
            }

            // ========================================================
            // MODE: FRESH CONSOLIDATED INWARD (Parent + Any Rework)
            // ========================================================
            const hasRework = match.has_rework && Array.isArray(match.rework_runs) && match.rework_runs.length > 0;
            const parentOk = parseFloat(match.parent_ok_qty || 0);
            const reworkOk = parseFloat(match.rework_total_ok || 0);
            const totalOk = parseFloat(match.total_ok_qty || (parentOk + reworkOk));

            if (reworkBadge) {
              reworkBadge.innerHTML = `<span style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 3px 9px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">+ Includes Rework Runs</span>`;
              reworkBadge.style.display = hasRework ? 'block' : 'none';
            }

            if (reworkNotice) {
              reworkNotice.innerHTML = `&#9432; Completed Rework runs detected. On clicking <strong>Save Inward Entry</strong>, finished goods from rework will be consolidated and appended to the Parent FG stock.`;
              reworkNotice.style.display = hasRework ? 'block' : 'none';
            }

            if (statusTag) {
              statusTag.innerHTML = 'Data Loaded';
            }

            // Row 1: Parent Initial Run
            rowsHtml += `
              <tr style="background: #ffffff;">
                <td style="padding: 9px 12px; vertical-align: middle;">
                  <strong style="color: var(--text-main); font-size: 0.92rem;">${escapeHtml(match.mip_no)}</strong>
                </td>
                <td style="padding: 9px 12px; vertical-align: middle;">
                  <span class="tag" style="background: #eff6ff; color: #1e40af; font-weight: 700; border: 1px solid #bfdbfe;">Initial Run</span>
                </td>
                <td style="padding: 9px 12px; text-align: center; vertical-align: middle;">
                  <strong style="color: #059669; font-size: 0.95rem;">${formatCleanStockJs(parentOk)} ${escapeHtml(uom)}</strong>
                </td>
                <td style="padding: 9px 12px; vertical-align: middle;">
                  ${renderVariantPillsJs(match.parent_variants)}
                </td>
              </tr>
            `;

            // Row 2..N: Completed Rework runs
            if (hasRework) {
              match.rework_runs.forEach((r, idx) => {
                const rQty = parseFloat(r.ok_qty || 0);
                rowsHtml += `
                  <tr style="background: #fffbeb;">
                    <td style="padding: 9px 12px; vertical-align: middle;">
                      <strong style="color: #92400e; font-size: 0.92rem;">${escapeHtml(match.mip_no)} <span style="font-size: 0.74rem; padding: 1px 6px; border-radius: 9999px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">${escapeHtml(r.rework_label || 'R')}</span></strong>
                    </td>
                    <td style="padding: 9px 12px; vertical-align: middle;">
                      <span class="tag" style="background: #fef3c7; color: #92400e; font-weight: 700; border: 1px solid #fde68a;">Rework (${escapeHtml(r.rework_label || 'R')})</span>
                    </td>
                    <td style="padding: 9px 12px; text-align: center; vertical-align: middle;">
                      <strong style="color: #b45309; font-size: 0.95rem;">+${formatCleanStockJs(rQty)} ${escapeHtml(uom)}</strong>
                    </td>
                    <td style="padding: 9px 12px; vertical-align: middle;">
                      ${renderVariantPillsJs(r.variants)}
                    </td>
                  </tr>
                `;
              });

              // Consolidated Summary Row
              rowsHtml += `
                <tr style="background: #ecfdf5; border-top: 2px solid #a7f3d0;">
                  <td style="padding: 10px 12px; vertical-align: middle;">
                    <strong style="color: #065f46; font-size: 0.95rem;">Total Consolidated FG</strong>
                  </td>
                  <td style="padding: 10px 12px; vertical-align: middle;">
                    <span class="tag" style="background: #d1fae5; color: #065f46; font-weight: 800; border: 1px solid #6ee7b7;">Parent + Rework</span>
                  </td>
                  <td style="padding: 10px 12px; text-align: center; vertical-align: middle;">
                    <strong style="color: #047857; font-size: 1.05rem;">${formatCleanStockJs(totalOk)} ${escapeHtml(uom)}</strong>
                  </td>
                  <td style="padding: 10px 12px; vertical-align: middle;">
                    ${renderVariantPillsJs(match.combined_variants)}
                  </td>
                </tr>
              `;
            }

            if (runsTbody) runsTbody.innerHTML = rowsHtml;

            // Set hidden inputs for saving
            document.getElementById('is_append_rework').value = '0';
            document.getElementById('pending_rework_ok').value = '0';
            document.getElementById('pending_rework_runs').value = '[]';
            document.getElementById('pending_rework_variants').value = '[]';
            document.getElementById('inward_no').value = newInNo;
            document.getElementById('inward_date').value = prodDate;
            document.getElementById('part_code').value = pCode;
            document.getElementById('part_name').value = pName;
            document.getElementById('customer_name').value = customerName;
            document.getElementById('batch_no').value = match.mip_no || '';
            document.getElementById('inward_qty').value = totalOk;
            document.getElementById('original_ok_qty').value = parentOk;
            document.getElementById('rework_ok_qty').value = reworkOk;
            document.getElementById('has_rework_appended').value = hasRework ? '1' : '0';
            document.getElementById('variant_details').value = JSON.stringify(match.combined_variants || match.parent_variants || []);
            document.getElementById('rework_appended_info').value = JSON.stringify(match.rework_runs || []);
            document.getElementById('parent_entry_id').value = match.parent_entry_id || '';
            document.getElementById('uom').value = uom;
            document.getElementById('work_order').value = match.work_order || '';
            document.getElementById('received_by').value = match.received_by || match.operator_name || '';
            document.getElementById('remarks').value = hasRework 
              ? `Consolidated FG Inward (Parent OK: ${formatCleanStockJs(parentOk)} + Rework OK: ${formatCleanStockJs(reworkOk)}) for ${customerName}`
              : `Inwarded from Production Entry for ${customerName}`;

            if (tblWrap) tblWrap.style.display = 'block';

            // Enable Save Button
            if (saveInwardBtn) {
              saveInwardBtn.disabled = false;
              saveInwardBtn.style.opacity = '1';
              saveInwardBtn.style.cursor = 'pointer';
              saveInwardBtn.textContent = 'Save Inward Entry';
              saveInwardBtn.style.background = '';
            }

            const toastMsg = hasRework
              ? `Parent MIP "${match.mip_no}" & ${match.rework_runs.length} Rework batch(es) loaded! Consolidated: ${formatCleanStockJs(totalOk)} ${uom}.`
              : `Production Entry for MIP "${match.mip_no}" loaded successfully!`;
            showToast(toastMsg, 'success');
          } else {
            clearMipScan();
            showToast(resJson.message || `No completed production record found for "${query}".`, 'error');
          }
        } catch (e) {
          clearMipScan();
          showToast(`Error fetching MIP: ${e.message}`, 'error');
        }
      }

      // Scan input events: Triggers strictly on Enter (barcode scanner) or change (blur)
      if (scanMipInput) {
        scanMipInput.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            fetchScannedMip(scanMipInput.value);
          }
        });

        scanMipInput.addEventListener('change', function() {
          fetchScannedMip(scanMipInput.value);
        });

        scanMipInput.addEventListener('input', function() {
          if (!scanMipInput.value.trim()) {
            clearMipScan();
          }
        });
      }

      // Open Inward Modal
      function openInwardModal() {
        clearMipScan();
        inwardModal.classList.add('active');
        setTimeout(() => {
          if (scanMipInput) scanMipInput.focus();
        }, 120);
      }

      document.querySelectorAll('.openInwardModalTrigger, #openInwardModalBtn, #openInwardModalBtnTab1').forEach(function(btn) {
        btn.addEventListener('click', openInwardModal);
      });

      // Close Inward Modal
      function closeInwardModal() {
        inwardModal.classList.remove('active');
      }
      if (closeInwardModalBtn) closeInwardModalBtn.addEventListener('click', closeInwardModal);
      if (cancelInwardBtn) cancelInwardBtn.addEventListener('click', closeInwardModal);

      // Close Dispatch Modal
      function closeDispatchModal() {
        dispatchModal.classList.remove('active');
      }
      if (closeDispatchModalBtn) closeDispatchModalBtn.addEventListener('click', closeDispatchModal);
      if (cancelDispatchBtn) cancelDispatchBtn.addEventListener('click', closeDispatchModal);

      // Close View Modal
      function closeViewModal() {
        viewModal.classList.remove('active');
      }
      if (closeViewModalBtn) closeViewModalBtn.addEventListener('click', closeViewModal);
      if (closeViewBtn) closeViewBtn.addEventListener('click', closeViewModal);

      // Close modal on click outside
      [inwardModal, dispatchModal, viewModal].forEach(function(m) {
        if (!m) return;
        m.addEventListener('click', function(e) {
          if (e.target === m) {
            m.classList.remove('active');
          }
        });
      });

      // Recalculate Stats & Tab counts
      function recalculateStats() {
        let totalStock = 0;
        let todayInward = 0;
        let totalDispatched = 0;
        const todayStr = new Date().toISOString().split('T')[0];

        const rows = fgTableBody.querySelectorAll('tr');
        rows.forEach(function(tr) {
          const stock = parseFloat(tr.dataset.available_stock || 0);
          const inQty = parseFloat(tr.dataset.inward_qty || 0);
          const dispQty = parseFloat(tr.dataset.dispatched_qty || 0);
          const inDate = tr.dataset.inward_date || '';

          totalStock += stock;
          totalDispatched += dispQty;
          if (inDate === todayStr) {
            todayInward += inQty;
          }
        });

        const statTotalStockEl = document.getElementById('statTotalStock');
        if (statTotalStockEl) statTotalStockEl.textContent = Math.round(totalStock).toLocaleString();
        const statTodayInwardEl = document.getElementById('statTodayInward');
        if (statTodayInwardEl) statTodayInwardEl.textContent = Math.round(todayInward).toLocaleString();
        const statTotalDispatchedEl = document.getElementById('statTotalDispatched');
        if (statTotalDispatchedEl) statTotalDispatchedEl.textContent = Math.round(totalDispatched).toLocaleString();

        document.getElementById('countInventory').textContent = rows.length;
        if (inwardTableBody) document.getElementById('countInward').textContent = inwardTableBody.querySelectorAll('tr').length;
        if (dispatchTableBody) document.getElementById('countDispatch').textContent = dispatchTableBody.querySelectorAll('tr').length;
      }

      // Save Inward Form (Persists consolidated finished goods into fg_inward_logs and fg_inventory)
      inwardForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const partCode = document.getElementById('part_code').value;
        if (!partCode) {
          showToast('Please scan or fetch a valid MIP No. first.', 'error');
          return;
        }

        const inNo = document.getElementById('inward_no').value;
        const inDate = document.getElementById('inward_date').value;
        const partName = document.getElementById('part_name').value;
        const customerName = document.getElementById('customer_name').value;
        const batchNo = document.getElementById('batch_no').value || (scanMipInput ? scanMipInput.value.trim() : '');
        const inQty = parseFloat(document.getElementById('inward_qty').value) || 0;
        const parentOk = parseFloat(document.getElementById('original_ok_qty').value) || inQty;
        const reworkOk = parseFloat(document.getElementById('rework_ok_qty').value) || 0;
        const hasRework = document.getElementById('has_rework_appended').value === '1';
        const variantsJson = document.getElementById('variant_details').value || '[]';
        const reworkInfoJson = document.getElementById('rework_appended_info').value || '[]';
        const parentEntryId = document.getElementById('parent_entry_id').value || null;
        const uom = document.getElementById('uom').value || 'PCS';
        const wo = document.getElementById('work_order').value;
        const recBy = document.getElementById('received_by').value;
        const remarks = document.getElementById('remarks').value;
        const isAppend = document.getElementById('is_append_rework') ? document.getElementById('is_append_rework').value === '1' : false;
        const pendingRwOk = parseFloat(document.getElementById('pending_rework_ok')?.value || 0);
        const pendingRwRuns = document.getElementById('pending_rework_runs')?.value || '[]';
        const pendingRwVars = document.getElementById('pending_rework_variants')?.value || '[]';

        if (inQty <= 0 && (!isAppend || pendingRwOk <= 0)) {
          showToast('Inward quantity must be greater than zero.', 'error');
          return;
        }

        if (saveInwardBtn) {
          saveInwardBtn.disabled = true;
          saveInwardBtn.textContent = isAppend ? 'Appending Rework...' : 'Saving Inward...';
        }

        const payload = {
          inward_no: inNo,
          inward_date: inDate,
          mip_no: batchNo,
          part_code: partCode,
          part_name: partName,
          customer_name: customerName,
          inward_qty: inQty,
          original_ok_qty: parentOk,
          rework_ok_qty: reworkOk,
          has_rework_appended: hasRework,
          variant_details: variantsJson,
          rework_appended_info: reworkInfoJson,
          parent_entry_id: parentEntryId,
          uom: uom,
          work_order: wo,
          received_by: recBy,
          remarks: remarks,
          is_append_rework: isAppend ? 1 : 0,
          pending_rework_ok: pendingRwOk,
          pending_rework_runs: pendingRwRuns,
          pending_rework_variants: pendingRwVars
        };

        try {
          const resp = await fetch('api/fg_store.php?action=save_inward', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
          });
          const res = await resp.json();

          if (res.success) {
            const successMsg = isAppend
              ? (res.message || `+${formatCleanStockJs(pendingRwOk)} ${uom} Rework appended to FG stock successfully!`)
              : (hasRework ? 'Finished Goods with Rework consolidated & saved successfully!' : 'Finished Goods inward saved successfully!');
            showToast(successMsg, 'success');
            closeInwardModal();
            setTimeout(() => {
              window.location.reload();
            }, 600);
          } else {
            showToast(res.message || 'Error saving inward entry.', 'error');
            if (saveInwardBtn) {
              saveInwardBtn.disabled = false;
              saveInwardBtn.textContent = isAppend ? `+ Append Rework (+${formatCleanStockJs(pendingRwOk)} ${uom})` : 'Save Inward Entry';
            }
          }
        } catch (err) {
          showToast('Network error while saving: ' + err.message, 'error');
          if (saveInwardBtn) {
            saveInwardBtn.disabled = false;
            saveInwardBtn.textContent = isAppend ? `+ Append Rework (+${formatCleanStockJs(pendingRwOk)} ${uom})` : 'Save Inward Entry';
          }
        }
      });

      // =========================================================
      // Finished Goods Dispatch by Customer & In-Store MIP
      // Sequence: 1. Customer -> 2. In-Store MIP -> 3. Invoice -> 4. Date -> 5. Load Qty & Variants
      // =========================================================
      const availableMipsData = <?php echo json_encode($availableMipsForDispatch); ?>;
      const dispCustomer = document.getElementById('disp_customer');
      const dispMip = document.getElementById('disp_mip');
      const dispInvoice = document.getElementById('disp_invoice');
      const dispDate = document.getElementById('disp_date');
      const dispBy = document.getElementById('disp_by');
      const dispRemarks = document.getElementById('disp_remarks');
      const dispDataLoadSection = document.getElementById('dispDataLoadSection');
      const dispPartCode = document.getElementById('disp_part_code');
      const dispPartName = document.getElementById('disp_part_name');
      const dispUom = document.getElementById('disp_uom');
      const dispMaxQty = document.getElementById('disp_max_qty');
      const dispTableHeaderDesc = document.getElementById('dispTableHeaderDesc');
      const dispVariantsTableBody = document.getElementById('dispVariantsTableBody');
      const dispTotalQtySummary = document.getElementById('dispTotalQtySummary');
      const dispStatusInfo = document.getElementById('dispStatusInfo');
      const confirmDispatchBtn = document.getElementById('confirmDispatchBtn');

      // Populate distinct customers from available in-store MIPs
      function populateCustomerDropdown(preselectedCustomer = null) {
        if (!dispCustomer) return;
        const customers = [...new Set(availableMipsData.map(m => m.customer_name).filter(Boolean))].sort();
        let custHtml = '<option value="">-- Choose Customer --</option>';
        customers.forEach(c => {
          const isSel = (preselectedCustomer && preselectedCustomer.toLowerCase() === c.toLowerCase()) ? 'selected' : '';
          custHtml += `<option value="${escapeHtml(c)}" ${isSel}>${escapeHtml(c)}</option>`;
        });
        dispCustomer.innerHTML = custHtml;
      }

      // Handle Customer Selection -> Populate In-Store MIPs for that Customer
      function onCustomerChanged(preselectedMip = null) {
        const selectedCustomer = dispCustomer.value.trim();
        if (!selectedCustomer) {
          dispMip.disabled = true;
          dispMip.innerHTML = '<option value="">-- Select Customer First --</option>';
          dispDataLoadSection.style.display = 'none';
          dispStatusInfo.textContent = 'Select Customer & MIP to load dispatch quantities';
          return;
        }

        const mipsForCust = availableMipsData.filter(m => m.customer_name.toLowerCase() === selectedCustomer.toLowerCase());
        if (mipsForCust.length === 0) {
          dispMip.disabled = true;
          dispMip.innerHTML = '<option value="">No in-stock MIPs found for this customer</option>';
          dispDataLoadSection.style.display = 'none';
          dispStatusInfo.textContent = 'No available stock for this customer';
          return;
        }

        dispMip.disabled = false;
        let mipHtml = '<option value="">-- Select In-Store MIP --</option>';
        mipsForCust.forEach(m => {
          const isSel = (preselectedMip && (preselectedMip === m.mip_no || preselectedMip === m.part_code)) ? 'selected' : '';
          mipHtml += `<option value="${escapeHtml(m.mip_no)}" data-part="${escapeHtml(m.part_code)}" ${isSel}>
            ${escapeHtml(m.mip_no)} &bull; ${escapeHtml(m.part_code)} - ${escapeHtml(m.part_name)} (${formatCleanStockJs(m.available_qty)} ${escapeHtml(m.uom)})
          </option>`;
        });
        dispMip.innerHTML = mipHtml;

        if (preselectedMip) {
          onMipChanged();
        } else if (mipsForCust.length === 1) {
          dispMip.selectedIndex = 1;
          onMipChanged();
        } else {
          dispDataLoadSection.style.display = 'none';
          dispStatusInfo.textContent = 'Please choose a MIP to load quantity details';
        }
      }

      // Handle MIP Selection -> Load Part Details & Dispatch Quantity Breakdown
      function onMipChanged() {
        const selectedCustomer = dispCustomer.value.trim();
        const selectedMip = dispMip.value.trim();
        if (!selectedCustomer || !selectedMip) {
          dispDataLoadSection.style.display = 'none';
          dispStatusInfo.textContent = 'Select Customer & MIP to load dispatch quantities';
          return;
        }

        const mipObj = availableMipsData.find(m => 
          m.customer_name.toLowerCase() === selectedCustomer.toLowerCase() && 
          m.mip_no === selectedMip
        );

        if (!mipObj) {
          dispDataLoadSection.style.display = 'none';
          showToast('Could not load details for selected MIP.', 'error');
          return;
        }

        // Populate hidden part details
        dispPartCode.value = mipObj.part_code;
        dispPartName.value = mipObj.part_name;
        dispUom.value = mipObj.uom;
        dispMaxQty.value = mipObj.available_qty;

        const variants = Array.isArray(mipObj.available_variants) ? mipObj.available_variants.filter(v => parseFloat(v.qty) > 0) : [];

        let vTbodyHtml = '';
        if (variants.length > 0) {
          // Case 1: Part has Output Variants
          if (dispTableHeaderDesc) dispTableHeaderDesc.textContent = 'Output Variant Breakdown';

          variants.forEach((v, idx) => {
            const maxV = parseFloat(v.qty) || 0;
            const vName = v.name || v.code || 'Variant';
            vTbodyHtml += `
              <tr>
                <td style="text-align: center; vertical-align: middle; padding: 10px 8px; color: var(--text-sub); font-weight: 600;">
                  ${idx + 1}
                </td>
                <td style="padding: 10px 14px; vertical-align: middle;">
                  <strong style="color: #0f172a; font-size: 0.92rem;">${escapeHtml(vName)}</strong>
                  ${v.code && v.code !== vName ? `<span style="font-size: 0.74rem; color: #64748b; margin-left: 6px;">(${escapeHtml(v.code)})</span>` : ''}
                </td>
                <td style="text-align: center; vertical-align: middle; padding: 10px 12px;">
                  <span class="tag" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 700; font-size: 0.86rem; padding: 4px 10px; border-radius: 4px;">
                    ${formatCleanStockJs(maxV)} ${escapeHtml(mipObj.uom)}
                  </span>
                </td>
                <td style="text-align: right; vertical-align: middle; padding: 10px 14px;">
                  <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                    <input type="number" class="form-control disp-var-qty-input" 
                           data-code="${escapeHtml(v.code || '')}" 
                           data-name="${escapeHtml(vName)}" 
                           min="0" max="${maxV}" step="any" placeholder="0" 
                           style="width: 120px; height: 36px; padding: 4px 10px; font-size: 0.95rem; font-weight: 700; text-align: right;">
                    <span style="font-size: 0.82rem; font-weight: 600; color: #64748b; min-width: 32px;">${escapeHtml(mipObj.uom)}</span>
                  </div>
                </td>
              </tr>
            `;
          });
          dispVariantsTableBody.innerHTML = vTbodyHtml;

          dispVariantsTableBody.querySelectorAll('.disp-var-qty-input').forEach(inp => {
            inp.addEventListener('input', updateVariantTotal);
          });
          updateVariantTotal();
        } else {
          // Case 2: Part has NO Output Variants -> Render in the full width table
          if (dispTableHeaderDesc) dispTableHeaderDesc.textContent = 'Finished Part Description';

          vTbodyHtml = `
            <tr>
              <td style="text-align: center; vertical-align: middle; padding: 10px 8px; color: var(--text-sub); font-weight: 600;">
                1
              </td>
              <td style="padding: 10px 14px; vertical-align: middle;">
                <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem;">${escapeHtml(mipObj.part_code)} - ${escapeHtml(mipObj.part_name)}</div>
                <div style="font-size: 0.74rem; color: #475569; margin-top: 3px;">
                  MIP Batch: <strong style="color: #4f46e5;">${escapeHtml(mipObj.mip_no)}</strong> &bull; Customer: <strong style="color: #166534;">${escapeHtml(mipObj.customer_name)}</strong>
                </div>
              </td>
              <td style="text-align: center; vertical-align: middle; padding: 10px 12px;">
                <span class="tag" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 700; font-size: 0.86rem; padding: 4px 10px; border-radius: 4px;">
                  ${formatCleanStockJs(mipObj.available_qty)} ${escapeHtml(mipObj.uom)}
                </span>
              </td>
              <td style="text-align: right; vertical-align: middle; padding: 10px 14px;">
                <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                  <input type="number" id="disp_direct_qty" class="form-control" 
                         min="0.001" max="${mipObj.available_qty}" step="any" placeholder="Enter Qty" 
                         style="width: 120px; height: 36px; padding: 4px 10px; font-size: 0.95rem; font-weight: 700; text-align: right;">
                  <span style="font-size: 0.82rem; font-weight: 600; color: #64748b; min-width: 32px;">${escapeHtml(mipObj.uom)}</span>
                </div>
              </td>
            </tr>
          `;
          dispVariantsTableBody.innerHTML = vTbodyHtml;

          const directInp = document.getElementById('disp_direct_qty');
          if (directInp) {
            directInp.addEventListener('input', function() {
              const q = parseFloat(directInp.value) || 0;
              dispTotalQtySummary.textContent = `${formatCleanStockJs(q)} ${mipObj.uom}`;
            });
            setTimeout(() => directInp.focus(), 100);
          }
          dispTotalQtySummary.textContent = `0 ${mipObj.uom}`;
        }

        dispDataLoadSection.style.display = 'block';
        dispStatusInfo.textContent = `Ready to dispatch from ${mipObj.mip_no}`;
      }

      // Calculate Total Dispatch Qty from variants
      function updateVariantTotal() {
        let sum = 0;
        const uom = dispUom.value || 'PCS';
        dispVariantsTableBody.querySelectorAll('.disp-var-qty-input').forEach(inp => {
          sum += (parseFloat(inp.value) || 0);
        });
        dispTotalQtySummary.textContent = `${formatCleanStockJs(sum)} ${uom}`;
      }

      // Open Dispatch Modal
      function openDispatchModal(preselectedCustomer = null, preselectedMip = null, preselectedPartCode = null) {
        if (!dispatchModal) return;
        dispatchForm.reset();
        dispDataLoadSection.style.display = 'none';
        dispTotalQtySummary.textContent = '0 PCS';

        const dDateInput = document.getElementById('disp_date');
        if (dDateInput) dDateInput.value = new Date().toISOString().split('T')[0];

        let targetCustomer = preselectedCustomer;
        let targetMip = preselectedMip;

        if (!targetCustomer && preselectedPartCode) {
          const found = availableMipsData.find(m => m.part_code === preselectedPartCode);
          if (found) {
            targetCustomer = found.customer_name;
            if (!targetMip) targetMip = found.mip_no;
          }
        }

        populateCustomerDropdown(targetCustomer);

        if (targetCustomer) {
          dispCustomer.value = targetCustomer;
          onCustomerChanged(targetMip);
        } else {
          dispMip.disabled = true;
          dispMip.innerHTML = '<option value="">-- Select Customer First --</option>';
          dispStatusInfo.textContent = 'Select Customer & MIP to load dispatch quantities';
        }

        dispatchModal.classList.add('active');
        if (dispInvoice) {
          setTimeout(() => dispInvoice.focus(), 150);
        }
      }

      if (dispCustomer) {
        dispCustomer.addEventListener('change', () => onCustomerChanged());
      }
      if (dispMip) {
        dispMip.addEventListener('change', onMipChanged);
      }

      document.querySelectorAll('.openDispatchModalTrigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
          openDispatchModal();
        });
      });

      // Handle Dispatch Form Submission
      dispatchForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const dCustomer = dispCustomer.value.trim();
        const dMip = dispMip.value.trim();
        const invRef = dispInvoice.value.trim();
        const dDate = dispDate.value;
        const dBy = dispBy.value.trim();
        const dRemarks = dispRemarks.value.trim();

        if (!dCustomer) {
          showToast('Please select a Customer.', 'error');
          dispCustomer.focus();
          return;
        }
        if (!dMip) {
          showToast('Please select an in-store MIP.', 'error');
          dispMip.focus();
          return;
        }
        if (!invRef) {
          showToast('Please enter Invoice / DC Ref.', 'error');
          dispInvoice.focus();
          return;
        }

        const pCode = dispPartCode.value.trim();
        const pName = dispPartName.value.trim();
        const uom = dispUom.value.trim() || 'PCS';
        const maxStock = parseFloat(dispMaxQty.value) || 0;

        let totalDispatchQty = 0;
        const variantDispatch = [];

        const varInputs = dispVariantsTableBody.querySelectorAll('.disp-var-qty-input');
        if (varInputs.length > 0) {
          let varValError = null;
          varInputs.forEach(vInp => {
            if (varValError) return;
            const vQty = parseFloat(vInp.value) || 0;
            const vMax = parseFloat(vInp.max) || 0;
            const vCode = vInp.dataset.code || '';
            const vName = vInp.dataset.name || vCode;

            if (vQty > vMax) {
              varValError = `Variant '${vName}' dispatch qty (${vQty}) exceeds available stock (${vMax}).`;
              return;
            }
            if (vQty > 0) {
              totalDispatchQty += vQty;
              variantDispatch.push({
                code: vCode,
                name: vName,
                qty: vQty
              });
            }
          });

          if (varValError) {
            showToast(varValError, 'error');
            return;
          }
          if (totalDispatchQty <= 0) {
            showToast('Please enter dispatch quantity for at least one variant.', 'error');
            return;
          }
        } else {
          const directInp = document.getElementById('disp_direct_qty');
          totalDispatchQty = directInp ? (parseFloat(directInp.value) || 0) : 0;
          if (totalDispatchQty <= 0) {
            showToast('Please enter a valid dispatch quantity greater than 0.', 'error');
            if (directInp) directInp.focus();
            return;
          }
          if (totalDispatchQty > maxStock) {
            showToast(`Dispatch quantity (${totalDispatchQty}) exceeds available stock (${maxStock}).`, 'error');
            if (directInp) directInp.focus();
            return;
          }
        }

        if (confirmDispatchBtn) {
          confirmDispatchBtn.disabled = true;
          confirmDispatchBtn.textContent = 'Processing Dispatch...';
        }

        const payload = {
          action: 'save_dispatch',
          invoice_ref: invRef,
          dispatch_date: dDate,
          customer: dCustomer,
          dispatched_by: dBy,
          remarks: dRemarks,
          items: [{
            part_code: pCode,
            part_name: pName,
            customer: dCustomer,
            dispatch_qty: totalDispatchQty,
            variant_dispatch: variantDispatch,
            uom: uom,
            batch_no: dMip
          }]
        };

        try {
          const resp = await fetch('api/fg_store.php?action=save_dispatch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          const res = await resp.json();

          if (res.success) {
            showToast(res.message || 'Dispatch completed successfully!', 'success');
            closeDispatchModal();
            setTimeout(() => {
              window.location.reload();
            }, 650);
          } else {
            showToast(res.message || 'Error processing dispatch.', 'error');
            if (confirmDispatchBtn) {
              confirmDispatchBtn.disabled = false;
              confirmDispatchBtn.textContent = 'Confirm Dispatch';
            }
          }
        } catch (err) {
          showToast('Network error while dispatching: ' + err.message, 'error');
          if (confirmDispatchBtn) {
            confirmDispatchBtn.disabled = false;
            confirmDispatchBtn.textContent = 'Confirm Dispatch';
          }
        }
      });

      // Print Delivery Challan (DC) Slip on Standard A4 format (Matching MIP Slip design)
      function printDeliveryChallanSlip(data) {
        const customerName = data.customer || data.items?.[0]?.customer || 'General Customer';

        const items = Array.isArray(data.items) && data.items.length > 0 ? data.items : [{
          partCode: data.partCode || '',
          partName: data.partName || '',
          batchNo: data.batchNo || '',
          qty: parseFloat(data.qty || 0),
          uom: data.uom || 'PCS',
          variants: data.variants || ''
        }];

        let totalQty = 0;
        let itemRowsHtml = '';
        const primaryUom = items[0]?.uom || 'PCS';

        items.forEach((it, idx) => {
          totalQty += parseFloat(it.qty || 0);

          let variantsList = [];
          if (typeof it.variants === 'string' && it.variants.trim() !== '') {
            try {
              variantsList = JSON.parse(it.variants);
            } catch (e) {
              variantsList = [];
            }
          } else if (Array.isArray(it.variants)) {
            variantsList = it.variants;
          }

          let variantsHtml = '';
          if (Array.isArray(variantsList) && variantsList.length > 0) {
            const activeVariants = variantsList.filter(v => (parseFloat(v.qty) || 0) > 0);
            if (activeVariants.length > 0) {
              variantsHtml = `
                <div style="margin-top: 6px; border: 1px solid #94a3b8; border-radius: 4px; overflow: hidden; background: #ffffff;">
                  <div style="padding: 4px 8px; background: #f1f5f9; font-size: 9.5px; font-weight: bold; color: #1e293b; text-transform: uppercase; border-bottom: 1px solid #94a3b8; display: flex; justify-content: space-between;">
                    <span>Dispatched Goods Variant</span>
                    <span>Quantity (${escapeHtml(it.uom || 'PCS')})</span>
                  </div>
                  <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                    <tbody>
              `;
              activeVariants.forEach(v => {
                const vQty = parseFloat(v.qty) || 0;
                const vName = (v.name || v.code || 'Variant').trim();
                const vCode = (v.code && v.code !== v.name) ? ` <span style="color: #64748b; font-size: 10px;">[${escapeHtml(v.code)}]</span>` : '';
                variantsHtml += `
                  <tr style="border-bottom: 1px dashed #cbd5e1;">
                    <td style="padding: 4px 8px; border: none; color: #0f172a;">
                      <strong style="color: #1e293b;">${escapeHtml(vName)}</strong>${vCode}
                    </td>
                    <td style="padding: 4px 8px; border: none; text-align: right; font-weight: bold; color: #1e40af;">
                      ${formatCleanStockJs(vQty)} ${escapeHtml(it.uom || 'PCS')}
                    </td>
                  </tr>
                `;
              });
              variantsHtml += `
                    </tbody>
                  </table>
                </div>
              `;
            }
          }

          itemRowsHtml += `
            <tr>
              <td style="text-align: center; color: #475569; vertical-align: top; padding-top: 8px;">${idx + 1}</td>
              <td style="vertical-align: top; padding-top: 8px;"><strong style="font-size: 12.5px;">${escapeHtml(it.partCode)}</strong></td>
              <td style="vertical-align: top; padding-top: 8px;">
                <div style="font-weight: bold; font-size: 12.5px; color: #0f172a;">${escapeHtml(it.partName)}</div>
                ${variantsHtml}
              </td>
              <td style="text-align: center; vertical-align: top; padding-top: 8px;">
                <strong style="color: #334155; font-size: 12px;">${escapeHtml(it.batchNo || '-')}</strong>
              </td>
              <td style="text-align: center; font-weight: bold; color: #0f172a; font-size: 13px; vertical-align: top; padding-top: 8px;">
                ${formatCleanStockJs(it.qty)}
              </td>
              <td style="text-align: center; vertical-align: top; padding-top: 8px; color: #475569;">${escapeHtml(it.uom || 'PCS')}</td>
            </tr>
          `;
        });

        const printHtml = `
          <!DOCTYPE html>
          <html>
          <head>
            <meta charset="UTF-8">
            <title>Delivery_Challan_${escapeHtml(data.dispatchNo || 'DC')}</title>
            <style>
              @page {
                size: A4 portrait;
                margin: 14mm 15mm;
              }
              * {
                box-sizing: border-box;
                font-family: Arial, Helvetica, sans-serif;
              }
              body {
                margin: 0;
                padding: 0;
                color: #0f172a;
                font-size: 12px;
                line-height: 1.4;
              }
              .slip-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                border-bottom: 2px solid #0f172a;
                padding-bottom: 8px;
                margin-bottom: 14px;
              }
              .company-title {
                font-size: 17px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin: 0;
              }
              .doc-title {
                font-size: 12px;
                font-weight: bold;
                color: #4f46e5;
                margin-top: 3px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
              }
              .slip-badge {
                text-align: right;
              }
              .slip-no {
                font-size: 16px;
                font-weight: bold;
                color: #0f172a;
              }
              .slip-date {
                font-size: 11.5px;
                color: #475569;
                margin-top: 2px;
              }

              /* Meta Information Table */
              table.meta-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 16px;
              }
              table.meta-table td {
                padding: 6px 10px;
                border: 1px solid #94a3b8;
                font-size: 12px;
                vertical-align: middle;
              }
              table.meta-table td.lbl {
                background: #f1f5f9;
                font-weight: bold;
                width: 20%;
                color: #334155;
              }
              table.meta-table td.val {
                width: 30%;
              }

              .section-heading {
                font-size: 12.5px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.02em;
                margin-bottom: 6px;
                color: #0f172a;
              }

              /* Items Table */
              table.items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 25px;
              }
              table.items-table th {
                background: #f1f5f9;
                border: 1px solid #64748b;
                padding: 7px 8px;
                font-size: 11px;
                text-transform: uppercase;
                text-align: left;
                color: #1e293b;
              }
              table.items-table td {
                border: 1px solid #94a3b8;
                padding: 6px 8px;
                font-size: 11.5px;
              }

              /* Signatures Section */
              table.sig-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 35px;
              }
              table.sig-table td {
                width: 33.33%;
                border: 1px solid #94a3b8;
                padding: 10px 12px;
                font-size: 11px;
                vertical-align: top;
                background: #fafafa;
              }
              .sig-title {
                font-weight: bold;
                color: #334155;
                text-transform: uppercase;
                font-size: 11px;
              }
              .sig-space {
                height: 48px;
              }
              .sig-line {
                border-top: 1px dashed #64748b;
                padding-top: 4px;
                font-size: 10px;
                color: #475569;
              }

              .footer-bar {
                margin-top: 18px;
                border-top: 1px solid #cbd5e1;
                padding-top: 5px;
                font-size: 10px;
                color: #64748b;
                display: flex;
                justify-content: space-between;
              }
            </style>
          </head>
          <body>
            <div class="slip-header">
              <div>
                <div class="company-title">WIP Management Portal</div>
                <div class="doc-title">Finished Goods Delivery Challan (Dispatch Slip)</div>
              </div>
              <div class="slip-badge">
                <div class="slip-no">DC NO: ${escapeHtml(data.dispatchNo || '-')}</div>
                <div class="slip-date">Date: ${formatDateDMY(data.dispatchDate)}</div>
              </div>
            </div>

            <table class="meta-table">
              <tr>
                <td class="lbl">Customer / Consignee:</td>
                <td class="val"><strong style="color: #1e40af; font-size: 13px;">${escapeHtml(customerName)}</strong></td>
                <td class="lbl">Invoice / DC Ref:</td>
                <td class="val"><strong style="color: #4f46e5; font-size: 13px;">${escapeHtml(data.invoiceRef || '-')}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Dispatch Date:</td>
                <td class="val"><strong>${formatDateDMY(data.dispatchDate)}</strong></td>
                <td class="lbl">Dispatch Status:</td>
                <td class="val"><span style="color: #15803d; font-weight: bold;">DISPATCHED / OUTWARD</span></td>
              </tr>
              <tr>
                <td class="lbl">Dispatched By:</td>
                <td class="val"><strong>${escapeHtml(data.dispatchedBy || '-')}</strong></td>
                <td class="lbl">Consignment Remarks:</td>
                <td class="val">${escapeHtml(data.remarks || 'None')}</td>
              </tr>
            </table>

            <div class="section-heading">Dispatched Finished Goods Items</div>
            <table class="items-table">
              <thead>
                <tr>
                  <th style="width: 35px; text-align: center;">#</th>
                  <th style="width: 140px;">Part Code</th>
                  <th>Finished Goods Part Description</th>
                  <th style="width: 130px; text-align: center;">MIP / Batch Ref</th>
                  <th style="width: 120px; text-align: center;">Dispatched Qty</th>
                  <th style="width: 70px; text-align: center;">UOM</th>
                </tr>
              </thead>
              <tbody>
                ${itemRowsHtml}
              </tbody>
              <tfoot>
                <tr style="background: #f8fafc; font-weight: bold;">
                  <td colspan="4" style="text-align: right; padding-right: 12px;">Total Dispatched Quantity:</td>
                  <td style="text-align: center; color: #4f46e5; font-size: 13px;">${formatCleanStockJs(totalQty)}</td>
                  <td style="text-align: center;">${escapeHtml(primaryUom)}</td>
                </tr>
              </tfoot>
            </table>

            <table class="sig-table">
              <tr>
                <td>
                  <div class="sig-title">DISPATCHED BY (STORE)</div>
                  <div style="margin: 8px 0 4px; font-size: 12px; font-weight: bold; color: #0f172a;">${escapeHtml(data.dispatchedBy || 'Store Executive')}</div>
                  <div class="sig-space"></div>
                  <div class="sig-line">Signature & Date</div>
                </td>
                <td>
                  <div class="sig-title">SECURITY / LOGISTICS IN-CHARGE</div>
                  <div class="sig-space" style="height: 60px;"></div>
                  <div class="sig-line">Gate Pass / Vehicle Verified</div>
                </td>
                <td>
                  <div class="sig-title">AUTHORIZED SIGNATORY</div>
                  <div class="sig-space" style="height: 60px;"></div>
                  <div class="sig-line">Signature & Date</div>
                </td>
              </tr>
            </table>

            <div class="footer-bar">
              <span>System Generated Delivery Challan - Finished Goods Store</span>
              <span>Print Date: ${new Date().toLocaleDateString('en-GB')} ${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
              <span>Standard A4 Format</span>
            </div>
          </body>
          </html>
        `;

        let printIframe = document.getElementById('printIframe');
        if (!printIframe) {
          printIframe = document.createElement('iframe');
          printIframe.id = 'printIframe';
          printIframe.style.position = 'fixed';
          printIframe.style.right = '0';
          printIframe.style.bottom = '0';
          printIframe.style.width = '0';
          printIframe.style.height = '0';
          printIframe.style.border = '0';
          document.body.appendChild(printIframe);
        }

        const doc = printIframe.contentWindow.document;
        doc.open();
        doc.write(printHtml);
        doc.close();

        setTimeout(() => {
          printIframe.contentWindow.focus();
          printIframe.contentWindow.print();
        }, 300);
      }

      // Print Delivery Challan (DC) Slip from Dispatch Logs Row
      window.printDispatchSlipFromRow = function(btn) {
        const tr = btn.closest('tr');
        if (!tr) return;
        const d = tr.dataset;

        const dispatchNo = d.dispatch_no;
        let items = [];

        // Check if there are other items in the table matching the same dispatch_no
        const allMatchingRows = document.querySelectorAll(
          `#dispatchTableBody tr[data-dispatch_no="${dispatchNo}"]`
        );

        if (allMatchingRows && allMatchingRows.length > 0) {
          allMatchingRows.forEach(r => {
            items.push({
              partCode: r.dataset.part_code || '',
              partName: r.dataset.part_name || '',
              batchNo: r.dataset.batch_no || d.batch_no || '',
              qty: parseFloat(r.dataset.dispatch_qty || 0),
              uom: r.dataset.uom || 'PCS',
              customer: r.dataset.customer || d.customer || '',
              variants: r.dataset.variant_dispatch || ''
            });
          });
        } else {
          items.push({
            partCode: d.part_code || '',
            partName: d.part_name || '',
            batchNo: d.batch_no || '',
            qty: parseFloat(d.dispatch_qty || 0),
            uom: d.uom || 'PCS',
            customer: d.customer || '',
            variants: d.variant_dispatch || ''
          });
        }

        printDeliveryChallanSlip({
          dispatchNo: d.dispatch_no,
          dispatchDate: d.dispatch_date,
          invoiceRef: d.invoice_ref,
          dispatchedBy: d.dispatched_by,
          customer: d.customer || (items[0]?.customer || ''),
          remarks: d.remarks,
          items: items
        });
      };

      // Row Actions handler
      function attachRowEvents(tr) {
        // View Details
        const viewBtn = tr.querySelector('.btn-view');
        if (viewBtn) {
          viewBtn.addEventListener('click', function() {
            currentActiveRow = tr;
            showViewModal(tr);
          });
        }

        // Dispatch
        const dispatchBtn = tr.querySelector('.btn-dispatch');
        if (dispatchBtn) {
          dispatchBtn.addEventListener('click', function() {
            currentActiveRow = tr;
            const stock = parseFloat(tr.dataset.available_stock || 0);
            if (stock <= 0) {
              showToast('This part has zero stock available for dispatch.', 'warning');
              return;
            }
            openDispatchModal(tr.dataset.customer, tr.dataset.mip_no, tr.dataset.part_code);
          });
        }

        // Print Tag
        const printBtn = tr.querySelector('.btn-print');
        if (printBtn) {
          printBtn.addEventListener('click', function() {
            populatePrintArea(tr);
            window.print();
          });
        }

        // Delete Row
        const deleteBtn = tr.querySelector('.btn-delete');
        if (deleteBtn) {
          deleteBtn.addEventListener('click', function() {
            if (confirm('Are you sure you want to delete this finished goods entry?')) {
              tr.remove();
              recalculateStats();
            }
          });
        }
      }

      // Show View Modal
      function showViewModal(tr) {
        const d = tr.dataset;
        const dParts = (d.inward_date || d.production_date || '').split('-');
        const dateDMY = dParts.length === 3 ? (dParts[2] + '-' + dParts[1] + '-' + dParts[0]) : (d.inward_date || d.production_date || '-');
        const totalQty = parseFloat(d.ok_qty || d.available_stock || 0);
        const isDispatched = (totalQty <= 0) || (d.status === 'Dispatched');

        let variants = [];
        try {
          variants = JSON.parse(d.variant_stock_details || d.variant_details || '[]');
        } catch (e) {
          variants = [];
        }

        const hasRework = d.has_rework === '1';

        viewModalContent.innerHTML = `
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; font-size: 0.9rem;">
            <!-- Customer Banner -->
            <div style="grid-column: 1 / -1; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;">
              <div>
                <span style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #166534; font-weight: 700; display: block;">Manufactured For Customer</span>
                <span style="font-size: 1.05rem; font-weight: 800; color: #14532d;">${escapeHtml(d.customer || 'General Customer')}</span>
              </div>
              ${hasRework ? `<span style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 3px 9px; border-radius: 5px; font-weight: 700; font-size: 0.76rem;">+ Consolidated Rework</span>` : ''}
            </div>

            <div style="grid-column: 1 / -1;">
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block;">Finished Part</span>
              <strong style="font-size: 1.15rem; color: var(--text-main);">${escapeHtml(d.part_code)}</strong> - <span style="font-weight: 600; color: #334155;">${escapeHtml(d.part_name)}</span>
            </div>
            <div>
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block;">Total Available Stock</span>
              <strong style="font-size: 1.25rem; color: ${isDispatched ? '#64748b' : '#059669'};">${formatCleanStockJs(totalQty)} ${escapeHtml(d.uom || 'PCS')}</strong>
              ${hasRework && parseFloat(d.rework_qty || 0) > 0 ? `
                <div style="font-size: 0.74rem; color: #b45309; font-weight: 600; margin-top: 2px;">
                  (Orig: ${formatCleanStockJs(d.orig_qty || totalQty)} + Rework: ${formatCleanStockJs(d.rework_qty)})
                </div>
              ` : ''}
            </div>
            <div>
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block;">Stock Status</span>
              ${isDispatched 
                ? '<span class="tag" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:700; padding:2px 8px; border-radius:4px; font-size:0.75rem;">Dispatched</span>' 
                : '<span class="tag tag-available" style="font-weight:700; padding:2px 8px; border-radius:4px; font-size:0.75rem;">Available</span>'}
            </div>
            <div>
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block;">Last Inward MIP</span>
              <strong style="color: var(--primary); font-size: 0.95rem;">${escapeHtml(d.mip_no || d.batch_no || d.inward_no || '-')}</strong>
            </div>
            <div>
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block;">Last Production Date</span>
              <strong>${escapeHtml(dateDMY || '-')}</strong>
            </div>

            <!-- Output Variants Breakdown -->
            <div style="grid-column: 1 / -1; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.80rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em;">Output Goods Variants Stock Breakdown:</span>
                <span style="font-size: 0.74rem; color: #64748b; font-weight: 600;">Total: <strong style="color: #0f172a;">${formatCleanStockJs(totalQty)} ${escapeHtml(d.uom || 'PCS')}</strong></span>
              </div>
              ${renderVariantStockTableJs(variants, d.uom || 'PCS')}
            </div>

            ${d.remarks ? `
            <div style="grid-column: 1 / -1;">
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block;">Remarks</span>
              <p style="margin: 3px 0 0 0; color: #475569;">${escapeHtml(d.remarks)}</p>
            </div>` : ''}
          </div>
        `;
        viewModal.classList.add('active');
      }

      // Populate Print Area (Original Tag Design)
      function populatePrintArea(tr) {
        const d = (tr && tr.dataset) ? tr.dataset : (tr || {});
        const dateVal = d.production_date || d.inward_date || d.created_at || '';
        const dParts = dateVal.split('-');
        const dateDMY = dParts.length === 3 ? (dParts[2] + '-' + dParts[1] + '-' + dParts[0]) : (dateVal ? formatDateDMY(dateVal) : '-');

        const inwQty = parseFloat(d.ok_qty || d.inward_qty || d.available_stock || 0);
        const availStock = parseFloat(d.available_stock || d.ok_qty || d.inward_qty || 0);
        const uom = d.uom || 'PCS';

        if (document.getElementById('prn_inward_no')) document.getElementById('prn_inward_no').textContent = d.inward_no || d.mip_no || '-';
        if (document.getElementById('prn_inward_date')) document.getElementById('prn_inward_date').textContent = dateDMY;
        if (document.getElementById('prn_part_code')) document.getElementById('prn_part_code').textContent = d.part_code || '-';
        if (document.getElementById('prn_part_name')) document.getElementById('prn_part_name').textContent = d.part_name || '-';
        if (document.getElementById('prn_batch_no')) document.getElementById('prn_batch_no').textContent = d.batch_no || d.mip_no || '-';
        if (document.getElementById('prn_location')) document.getElementById('prn_location').textContent = d.location || 'FG Warehouse';
        if (document.getElementById('prn_inward_qty')) document.getElementById('prn_inward_qty').textContent = formatCleanStockJs(inwQty) + ' ' + uom;
        if (document.getElementById('prn_available_stock')) document.getElementById('prn_available_stock').textContent = formatCleanStockJs(availStock) + ' ' + uom;
        if (document.getElementById('prn_work_order')) document.getElementById('prn_work_order').textContent = d.work_order || '-';
        if (document.getElementById('prn_received_by')) document.getElementById('prn_received_by').textContent = d.received_by || '-';
        if (document.getElementById('prn_remarks')) document.getElementById('prn_remarks').textContent = d.remarks || '-';
      }

      window.printRowTag = function(target) {
        let tr = null;
        if (target && target.nodeType === 1) {
          tr = target.closest('tr');
        } else if (typeof target === 'string' && target.trim()) {
          const cleanTarget = target.trim();
          if (inwardTableBody) {
            tr = inwardTableBody.querySelector(`tr[data-inward_no="${cleanTarget}"]`) ||
                 inwardTableBody.querySelector(`tr[data-mip_no="${cleanTarget}"]`);
          }
          if (!tr && fgTableBody) {
            tr = fgTableBody.querySelector(`tr[data-inward_no="${cleanTarget}"]`) ||
                 fgTableBody.querySelector(`tr[data-part_code="${cleanTarget}"]`);
          }
        }
        if (!tr && currentActiveRow) {
          tr = currentActiveRow;
        }

        if (tr) {
          populatePrintArea(tr);
          window.print();
        } else {
          showToast('Could not find data to print inward tag.', 'error');
        }
      };

      if (printFromViewBtn) {
        printFromViewBtn.addEventListener('click', function() {
          if (currentActiveRow) {
            populatePrintArea(currentActiveRow);
            window.print();
          }
        });
      }

      const dispatchFromViewBtn = document.getElementById('dispatchFromViewBtn');
      if (dispatchFromViewBtn) {
        dispatchFromViewBtn.addEventListener('click', function() {
          if (currentActiveRow) {
            const cust = currentActiveRow.dataset.customer;
            const mip = currentActiveRow.dataset.mip_no;
            const pCode = currentActiveRow.dataset.part_code;
            closeViewModal();
            openDispatchModal(cust, mip, pCode);
          }
        });
      }

      // Attach events to existing rows
      fgTableBody.querySelectorAll('tr').forEach(attachRowEvents);

      // Search Filter for Tab 1 (Inventory)
      function filterInventoryTable() {
        const q = (fgSearch ? fgSearch.value : '').trim().toLowerCase();

        fgTableBody.querySelectorAll('tr').forEach(function(tr) {
          const text = tr.textContent.toLowerCase();
          const matchesSearch = !q || text.includes(q);
          tr.style.display = matchesSearch ? '' : 'none';
        });
      }

      if (fgSearch) fgSearch.addEventListener('input', filterInventoryTable);

      // Search for Tab 2 (Inward Logs)
      const inwardSearch = document.getElementById('inwardSearch');
      if (inwardSearch && inwardTableBody) {
        inwardSearch.addEventListener('input', function() {
          const q = (inwardSearch.value || '').trim().toLowerCase();
          inwardTableBody.querySelectorAll('tr').forEach(function(tr) {
            tr.style.display = (!q || tr.textContent.toLowerCase().includes(q)) ? '' : 'none';
          });
        });
      }

      // Search for Tab 3 (Dispatch Logs)
      const dispatchSearch = document.getElementById('dispatchSearch');
      if (dispatchSearch && dispatchTableBody) {
        dispatchSearch.addEventListener('input', function() {
          const q = (dispatchSearch.value || '').trim().toLowerCase();
          dispatchTableBody.querySelectorAll('tr').forEach(function(tr) {
            tr.style.display = (!q || tr.textContent.toLowerCase().includes(q)) ? '' : 'none';
          });
        });
      }
    });
  </script>
</body>
</html>
