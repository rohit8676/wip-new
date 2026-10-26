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
        $stmtInv = $pdo->query("
            SELECT id, part_code, part_name, customer_name, total_ok_qty, uom,
                   variant_stock_details, last_mip_no,
                   CONVERT(VARCHAR(10), last_production_date, 120) as production_date,
                   CONVERT(VARCHAR(19), updated_at, 120) as updated_at
            FROM fg_inventory
            ORDER BY part_code ASC
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
    .form-group.col-full {
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
        
        <!-- 3 Simple Stats Cards (Identical to MIP & Child Part Pages) -->
        <div class="stats-row">
          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statTotalStock"><?php echo number_format($totalStock); ?></div>
            <div class="stat-title">Total FG Available Stock (Units)</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statTodayInward"><?php echo number_format($todayInward); ?></div>
            <div class="stat-title">Today's Inward Qty</div>
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
                    <th style="width: 60px; text-align: center;">Sr No.</th>
                    <th style="min-width: 280px;">Part Code &amp; Name</th>
                    <th style="width: 160px; text-align: center; white-space: nowrap;">Stock Quantity</th>
                    <th style="width: 150px; text-align: center; white-space: nowrap;">Action</th>
                  </tr>
                </thead>
                <tbody id="fgTableBody">
                  <?php if (empty($fgInventoryList)): ?>
                    <tr id="emptyFgRow">
                      <td colspan="4" style="text-align: center; padding: 36px 20px; color: var(--text-sub);">
                        No finished goods inventory recorded yet. Go to <strong>Inward Logs</strong> tab and click <strong>+ Inward FG</strong> to add stock.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php $sr = 1; ?>
                    <?php foreach ($fgInventoryList as $item): ?>
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
                          data-status="Available">
                        
                        <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $sr++; ?></td>
                        
                        <td>
                          <div style="font-weight: 700; color: var(--text-main); font-size: 0.95rem;">
                            <?php echo htmlspecialchars($item['part_code']); ?> - <?php echo htmlspecialchars($item['part_name']); ?>
                          </div>
                          <?php if (!empty($item['customer_name'])): ?>
                            <div style="margin-top: 4px; display: inline-flex; align-items: center; gap: 5px;">
                              <span style="font-size: 0.72rem; color: #64748b; font-weight: 600;">Customer:</span>
                              <span class="tag" style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; font-size: 0.74rem; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                                <?php echo htmlspecialchars($item['customer_name']); ?>
                              </span>
                            </div>
                          <?php endif; ?>
                        </td>
                        
                        <td style="text-align: center; white-space: nowrap;">
                          <?php 
                            $stockQty = floatval($item['total_ok_qty']);
                            $badgeCls = 'in-stock';
                            if ($stockQty <= 0) $badgeCls = 'zero-stock';
                            elseif ($stockQty <= 35) $badgeCls = 'low-stock';
                          ?>
                          <span class="qty-badge <?php echo $badgeCls; ?>"><?php echo formatCleanStock($stockQty); ?> <?php echo htmlspecialchars($item['uom'] ?? 'PCS'); ?></span>
                          <?php if (!empty($item['variant_stock_details'])): ?>
                            <div style="margin-top: 5px; display: flex; justify-content: center;">
                              <?php echo renderVariantBadgesHtml($item['variant_stock_details']); ?>
                            </div>
                          <?php endif; ?>
                        </td>

                        <td style="text-align: center; white-space: nowrap;">
                          <div class="action-btns" style="justify-content: center;">
                            <button type="button" class="btn-view" title="View Stock Details">View</button>
                            <?php if (hasPermission('fg_store', 'create')): ?>
                            <button type="button" class="btn-dispatch" title="Dispatch Part">Dispatch</button>
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
       Modal 2: Multi-Item Dispatch FG (Clean Popup with Table)
       ========================================================= -->
  <div class="modal-overlay" id="dispatchModal">
    <div class="modal-card" style="max-width: 820px; width: 95%;">
      <div class="modal-header">
        <h3 class="modal-title">Dispatch Finished Goods</h3>
        <button type="button" class="modal-close-btn" id="closeDispatchModalBtn">&times;</button>
      </div>

      <form id="dispatchForm">
        <div class="modal-body">
          <div class="popup-form-grid">
            
            <div class="form-group">
              <label for="disp_invoice">Invoice / DC Ref. *</label>
              <input type="text" id="disp_invoice" class="form-control" placeholder="e.g. INV-2026-101 / DC-104" required>
            </div>
            
            <div class="form-group">
              <label for="disp_date">Dispatch Date *</label>
              <input type="date" id="disp_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
            </div>

            <!-- Customer Field: Pre-filled & Non-editable (Readonly) as per requirement -->
            <div class="form-group col-full">
              <label for="disp_customer">Customer (Manufactured For) *</label>
              <input type="text" id="disp_customer" class="form-control" readonly placeholder="Auto-populated from selected part (Non-editable)" style="background: #f8fafc; font-weight: 700; color: #1e293b; cursor: not-allowed; border-color: #cbd5e1;" required>
            </div>

            <div class="form-group col-full">
              <label for="disp_by">Dispatched By *</label>
              <input type="text" id="disp_by" class="form-control" placeholder="Store Executive" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Store Executive'); ?>" required>
            </div>

            <!-- Multiple Dispatch Items Table -->
            <div class="form-group col-full" style="margin-top: 6px;">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <label style="margin: 0; font-weight: 700; color: var(--text-main);">Dispatch Parts List *</label>
                <button type="button" class="btn-secondary" id="addDispItemRowBtn" style="padding: 4px 10px; font-size: 0.78rem; font-weight: 600;">
                  + Add Row
                </button>
              </div>

              <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 6px; background: #fff;">
                <table class="simple-table" id="dispItemsTable" style="margin: 0; width: 100%; border: none;">
                  <thead style="background: #f8fafc;">
                    <tr>
                      <th style="width: 36px; text-align: center;">#</th>
                      <th style="min-width: 220px;">Select Finished Part</th>
                      <th style="width: 140px; text-align: center; white-space: nowrap;">Available Stock</th>
                      <th style="min-width: 190px; text-align: center; white-space: nowrap;">Dispatch Qty</th>
                      <th style="width: 36px; text-align: center;"></th>
                    </tr>
                  </thead>
                  <tbody id="dispItemsTableBody">
                    <!-- Dynamic rows inserted here -->
                  </tbody>
                </table>
              </div>
            </div>

            <!-- General Remarks -->
            <div class="form-group col-full">
              <label for="disp_remarks">Remarks</label>
              <input type="text" id="disp_remarks" class="form-control" placeholder="Optional notes for this delivery challan">
            </div>

          </div>
        </div>

        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
          <span id="dispTotalSummary" style="font-weight: 600; color: var(--text-sub); font-size: 0.85rem;">
            Total Parts: <strong id="dispItemsCount" style="color: var(--text-main);">0</strong>
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
        if (reworkBadge) reworkBadge.style.display = 'none';
        const reworkNotice = document.getElementById('reworkConsolidationNotice');
        if (reworkNotice) reworkNotice.style.display = 'none';

        const custNameEl = document.getElementById('fetchedCustomerName');
        if (custNameEl) custNameEl.textContent = '-';

        const runsTbody = document.getElementById('fetchedRunsTableBody');
        if (runsTbody) runsTbody.innerHTML = '';

        [
          'inward_no', 'inward_date', 'part_code', 'part_name', 'customer_name',
          'batch_no', 'inward_qty', 'original_ok_qty', 'rework_ok_qty',
          'has_rework_appended', 'variant_details', 'rework_appended_info',
          'parent_entry_id', 'uom', 'work_order', 'received_by', 'remarks'
        ].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.value = '';
        });

        if (saveInwardBtn) {
          saveInwardBtn.disabled = true;
          saveInwardBtn.style.opacity = '0.5';
          saveInwardBtn.style.cursor = 'not-allowed';
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

            // ── Duplicate Inward check ──────────────────────────────────
            if (match.already_inwarded === true) {
              const inwardRef = match.inward_ref ? ` (Ref: ${match.inward_ref})` : '';
              showToast(
                `MIP "${match.mip_no}" has already been inwarded into FG Store${inwardRef}. Duplicate inward is not allowed.`,
                'error'
              );
              if (scanMipInput) {
                scanMipInput.value = '';
                scanMipInput.focus();
              }
              clearMipScan();
              return;
            }
            // ───────────────────────────────────────────────────────────

            const pCode = match.part_code || '';
            const pName = match.part_name || pCode;
            const uom = match.uom || 'PCS';
            const customerName = match.customer_name || 'General Customer';
            const prodDate = match.production_date || new Date().toISOString().split('T')[0];
            const newInNo = 'FGI-' + new Date().getFullYear() + '-' + String(Math.floor(100 + Math.random() * 900));

            const hasRework = match.has_rework && Array.isArray(match.rework_runs) && match.rework_runs.length > 0;
            const parentOk = parseFloat(match.parent_ok_qty || 0);
            const reworkOk = parseFloat(match.rework_total_ok || 0);
            const totalOk = parseFloat(match.total_ok_qty || (parentOk + reworkOk));

            // Set Customer Banner
            const custEl = document.getElementById('fetchedCustomerName');
            if (custEl) custEl.textContent = customerName;

            const reworkBadge = document.getElementById('fetchedReworkBadgeWrap');
            if (reworkBadge) reworkBadge.style.display = hasRework ? 'block' : 'none';

            const reworkNotice = document.getElementById('reworkConsolidationNotice');
            if (reworkNotice) reworkNotice.style.display = hasRework ? 'block' : 'none';

            // Populate Table of Runs
            let rowsHtml = '';

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
                      <strong style="color: #92400e; font-size: 0.92rem;">${escapeHtml(match.mip_no)} (Run #${r.id})</strong>
                    </td>
                    <td style="padding: 9px 12px; vertical-align: middle;">
                      <span class="tag" style="background: #fef3c7; color: #92400e; font-weight: 700; border: 1px solid #fde68a;">Rework Run</span>
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

        document.getElementById('statTotalStock').textContent = Math.round(totalStock).toLocaleString();
        document.getElementById('statTodayInward').textContent = Math.round(todayInward).toLocaleString();
        document.getElementById('statTotalDispatched').textContent = Math.round(totalDispatched).toLocaleString();

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

        if (inQty <= 0) {
          showToast('Inward quantity must be greater than zero.', 'error');
          return;
        }

        if (saveInwardBtn) {
          saveInwardBtn.disabled = true;
          saveInwardBtn.textContent = 'Saving Inward...';
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
          remarks: remarks
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
            showToast(hasRework ? 'Finished Goods with Rework consolidated & saved successfully!' : 'Finished Goods inward saved successfully!', 'success');
            closeInwardModal();
            setTimeout(() => {
              window.location.reload();
            }, 600);
          } else {
            showToast(res.message || 'Error saving inward entry.', 'error');
            if (saveInwardBtn) {
              saveInwardBtn.disabled = false;
              saveInwardBtn.textContent = 'Save Inward Entry';
            }
          }
        } catch (err) {
          showToast('Network error while saving: ' + err.message, 'error');
          if (saveInwardBtn) {
            saveInwardBtn.disabled = false;
            saveInwardBtn.textContent = 'Save Inward Entry';
          }
        }
      });

      // Multi-Item Dispatch Elements & Data
      const fgInventoryData = <?php echo json_encode($fgInventoryList); ?>;
      const openDispatchModalBtn = document.getElementById('openDispatchModalBtn');
      const dispItemsTableBody = document.getElementById('dispItemsTableBody');
      const addDispItemRowBtn = document.getElementById('addDispItemRowBtn');
      const dispItemsCount = document.getElementById('dispItemsCount');
      const confirmDispatchBtn = document.getElementById('confirmDispatchBtn');

      function updateDispatchItemCount() {
        if (!dispItemsTableBody) return;
        const rows = dispItemsTableBody.querySelectorAll('tr');
        if (dispItemsCount) dispItemsCount.textContent = rows.length;
        rows.forEach((tr, idx) => {
          const numCell = tr.querySelector('.disp-row-num');
          if (numCell) numCell.textContent = idx + 1;
        });
      }

      function addDispatchItemRow(preselectedPartCode = null) {
        if (!dispItemsTableBody) return;

        const row = document.createElement('tr');
        const rowIndex = dispItemsTableBody.querySelectorAll('tr').length + 1;

        // Build Part Options from fgInventoryData (only parts with stock > 0)
        let partOptionsHtml = '<option value="">-- Select In-Stock Part --</option>';
        fgInventoryData.forEach(p => {
          const stock = parseFloat(p.total_ok_qty || 0);
          if (stock > 0) {
            const isSel = (preselectedPartCode && p.part_code === preselectedPartCode) ? 'selected' : '';
            partOptionsHtml += `<option value="${escapeHtml(p.part_code)}" ${isSel} 
              data-name="${escapeHtml(p.part_name)}" 
              data-customer="${escapeHtml(p.customer_name || '')}" 
              data-uom="${escapeHtml(p.uom || 'PCS')}" 
              data-stock="${stock}" 
              data-batch="${escapeHtml(p.last_mip_no || '')}" 
              data-variants='${escapeHtml(p.variant_stock_details || "[]")}'>
              ${escapeHtml(p.part_code)} - ${escapeHtml(p.part_name)} (${formatCleanStockJs(stock)} ${escapeHtml(p.uom || 'PCS')})
            </option>`;
          }
        });

        row.innerHTML = `
          <td class="disp-row-num" style="text-align: center; color: var(--text-sub); font-weight: 600; vertical-align: top; padding-top: 12px;">${rowIndex}</td>
          <td style="vertical-align: top;">
            <select class="form-control disp-row-part" style="width: 100%; font-size: 0.84rem;" required>
              ${partOptionsHtml}
            </select>
            <input type="hidden" class="disp-row-part-name">
            <input type="hidden" class="disp-row-customer">
            <input type="hidden" class="disp-row-uom" value="PCS">
            <input type="hidden" class="disp-row-batch" value="">
          </td>
          <td style="text-align: center; white-space: nowrap; vertical-align: top; padding-top: 12px;">
            <span class="disp-row-avail" style="font-weight: 700; color: #059669; font-size: 0.86rem;">-</span>
          </td>
          <td style="vertical-align: top;">
            <input type="number" class="form-control disp-row-qty" min="0.001" step="any" placeholder="Qty" style="text-align: right; font-weight: 700;" required disabled>
            <div class="disp-row-variants-box" style="display: none; margin-top: 6px; padding: 7px 9px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; text-align: left;"></div>
          </td>
          <td style="text-align: center; vertical-align: top; padding-top: 10px;">
            <button type="button" class="btn-delete-row" title="Remove Item" style="background: transparent; border: none; color: #ef4444; cursor: pointer; font-size: 1.15rem; line-height: 1; padding: 4px 6px;">
              &times;
            </button>
          </td>
        `;

        const partSelect = row.querySelector('.disp-row-part');
        const availSpan = row.querySelector('.disp-row-avail');
        const qtyInput = row.querySelector('.disp-row-qty');
        const variantsBox = row.querySelector('.disp-row-variants-box');
        const partNameInput = row.querySelector('.disp-row-part-name');
        const customerInput = row.querySelector('.disp-row-customer');
        const uomInput = row.querySelector('.disp-row-uom');
        const batchInput = row.querySelector('.disp-row-batch');
        const delBtn = row.querySelector('.btn-delete-row');
        const dispCustomerMain = document.getElementById('disp_customer');

        function onPartChanged() {
          const opt = partSelect.options[partSelect.selectedIndex];
          if (!opt || !partSelect.value) {
            availSpan.textContent = '-';
            qtyInput.value = '';
            qtyInput.disabled = true;
            variantsBox.style.display = 'none';
            variantsBox.innerHTML = '';
            partNameInput.value = '';
            customerInput.value = '';
            batchInput.value = '';
            if (dispItemsTableBody.querySelectorAll('tr').length <= 1 && dispCustomerMain) {
              dispCustomerMain.value = '';
            }
            return;
          }

          const stock = parseFloat(opt.dataset.stock || 0);
          const uom = opt.dataset.uom || 'PCS';
          const cust = opt.dataset.customer || '';

          // Auto-populate Customer in the non-editable Customer input field
          if (dispCustomerMain) {
            dispCustomerMain.value = cust || 'General Customer';
          }

          partNameInput.value = opt.dataset.name || '';
          customerInput.value = cust;
          uomInput.value = uom;
          batchInput.value = opt.dataset.batch || '';
          availSpan.textContent = `${formatCleanStockJs(stock)} ${uom}`;

          // Parse variant breakdown
          let variants = [];
          try {
            variants = JSON.parse(opt.dataset.variants || '[]');
          } catch (e) {
            variants = [];
          }

          const activeVariants = Array.isArray(variants) ? variants.filter(v => (parseFloat(v.qty) || 0) > 0) : [];

          if (activeVariants.length > 0) {
            // Render dynamic input fields for each variant
            let vHtml = `<div style="font-size:0.72rem; font-weight:700; color:#475569; margin-bottom:5px; text-transform:uppercase;">Dispatch By Variant:</div>`;
            activeVariants.forEach(v => {
              const maxV = parseFloat(v.qty) || 0;
              const vName = v.name || v.code || 'Var';
              vHtml += `
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; gap:6px;">
                  <span style="font-size:0.78rem; font-weight:600; color:#1e293b; max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(vName)}">${escapeHtml(vName)}:</span>
                  <div style="display:flex; align-items:center; gap:4px;">
                    <input type="number" class="form-control disp-variant-input" data-code="${escapeHtml(v.code || '')}" data-name="${escapeHtml(vName)}" min="0" max="${maxV}" step="any" placeholder="0" style="width:72px; height:28px; padding:0 6px; font-size:0.8rem; text-align:right;">
                    <span style="font-size:0.7rem; color:#64748b; white-space:nowrap;">/ ${formatCleanStockJs(maxV)}</span>
                  </div>
                </div>
              `;
            });
            variantsBox.innerHTML = vHtml;
            variantsBox.style.display = 'block';

            // Make main qty input readonly and auto-calculate sum from variant inputs
            qtyInput.readOnly = true;
            qtyInput.style.background = '#f1f5f9';
            qtyInput.style.cursor = 'not-allowed';
            qtyInput.placeholder = 'Sum of variants';
            qtyInput.value = '';
            qtyInput.disabled = false;

            variantsBox.querySelectorAll('.disp-variant-input').forEach(vInp => {
              vInp.addEventListener('input', function() {
                let sum = 0;
                variantsBox.querySelectorAll('.disp-variant-input').forEach(i => {
                  sum += (parseFloat(i.value) || 0);
                });
                qtyInput.value = sum > 0 ? sum : '';
              });
            });
          } else {
            // No variants: allow direct entry in qtyInput
            variantsBox.style.display = 'none';
            variantsBox.innerHTML = '';
            qtyInput.readOnly = false;
            qtyInput.style.background = '#ffffff';
            qtyInput.style.cursor = 'text';
            qtyInput.placeholder = 'Qty';
            qtyInput.disabled = false;
            qtyInput.max = stock;
            qtyInput.focus();
          }
        }

        partSelect.addEventListener('change', onPartChanged);

        delBtn.addEventListener('click', function() {
          const allRows = dispItemsTableBody.querySelectorAll('tr');
          if (allRows.length > 1) {
            row.remove();
            updateDispatchItemCount();
          } else {
            partSelect.value = '';
            onPartChanged();
          }
        });

        dispItemsTableBody.appendChild(row);
        updateDispatchItemCount();

        if (preselectedPartCode) {
          onPartChanged();
        }
      }

      function openDispatchModal(preselectedPartCode = null) {
        if (!dispatchModal) return;
        dispatchForm.reset();
        dispItemsTableBody.innerHTML = '';

        const dDateInput = document.getElementById('disp_date');
        if (dDateInput) dDateInput.value = new Date().toISOString().split('T')[0];

        const dispCustomerMain = document.getElementById('disp_customer');
        if (dispCustomerMain) dispCustomerMain.value = '';

        addDispatchItemRow(preselectedPartCode);
        dispatchModal.classList.add('active');
      }

      document.querySelectorAll('.openDispatchModalTrigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
          openDispatchModal();
        });
      });

      if (addDispItemRowBtn) {
        addDispItemRowBtn.addEventListener('click', function() {
          addDispatchItemRow();
        });
      }

      // Handle Multi-Item Dispatch Form Submission
      dispatchForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const invRef = document.getElementById('disp_invoice').value.trim();
        const dDate = document.getElementById('disp_date').value;
        const dCustomer = document.getElementById('disp_customer').value.trim();
        const dBy = document.getElementById('disp_by').value.trim();
        const dRemarks = document.getElementById('disp_remarks').value.trim();

        if (!invRef) {
          showToast('Please enter Invoice / Delivery Challan Ref.', 'error');
          return;
        }

        const rows = dispItemsTableBody.querySelectorAll('tr');
        if (rows.length === 0) {
          showToast('Please add at least one part to dispatch.', 'error');
          return;
        }

        const items = [];
        let validationError = null;

        rows.forEach((tr, i) => {
          if (validationError) return;
          const pCode = tr.querySelector('.disp-row-part').value.trim();
          const pName = tr.querySelector('.disp-row-part-name').value.trim();
          const uVal = tr.querySelector('.disp-row-uom').value.trim();
          const bNo = tr.querySelector('.disp-row-batch').value.trim();
          const qtyInput = tr.querySelector('.disp-row-qty');
          const dQty = parseFloat(qtyInput.value) || 0;
          const maxStock = parseFloat(qtyInput.max) || 0;

          if (!pCode) {
            validationError = `Row #${i + 1}: Please select a finished part.`;
            return;
          }
          if (dQty <= 0) {
            validationError = `Row #${i + 1} (${pCode}): Dispatch quantity must be greater than zero.`;
            return;
          }
          if (maxStock > 0 && dQty > maxStock) {
            validationError = `Row #${i + 1} (${pCode}): Dispatch quantity (${dQty}) exceeds available stock (${maxStock}).`;
            return;
          }

          // Check if variant breakdown inputs exist in this row
          const variantInputs = tr.querySelectorAll('.disp-variant-input');
          const variantDispatch = [];
          if (variantInputs.length > 0) {
            let variantSum = 0;
            variantInputs.forEach(vInp => {
              const vQty = parseFloat(vInp.value) || 0;
              const vMax = parseFloat(vInp.max) || 0;
              const vCode = vInp.dataset.code || '';
              const vName = vInp.dataset.name || vCode;

              if (vQty > vMax) {
                validationError = `Row #${i + 1} (${pCode}): Variant '${vName}' dispatch qty (${vQty}) exceeds available (${vMax}).`;
                return;
              }
              if (vQty > 0) {
                variantSum += vQty;
                variantDispatch.push({
                  code: vCode,
                  name: vName,
                  qty: vQty
                });
              }
            });

            if (validationError) return;
            if (variantDispatch.length === 0) {
              validationError = `Row #${i + 1} (${pCode}): Please enter dispatch quantity for at least one variant.`;
              return;
            }
          }

          items.push({
            part_code: pCode,
            part_name: pName,
            customer: dCustomer,
            dispatch_qty: dQty,
            variant_dispatch: variantDispatch,
            uom: uVal || 'PCS',
            batch_no: bNo
          });
        });

        if (validationError) {
          showToast(validationError, 'error');
          return;
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
          items: items
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
        const items = Array.isArray(data.items) && data.items.length > 0 ? data.items : [{
          partCode: data.partCode || '',
          partName: data.partName || '',
          batchNo: data.batchNo || '',
          qty: parseFloat(data.qty || 0),
          uom: data.uom || 'PCS'
        }];

        let totalQty = 0;
        let itemRowsHtml = '';
        const primaryUom = items[0]?.uom || 'PCS';

        items.forEach((it, idx) => {
          totalQty += parseFloat(it.qty || 0);
          itemRowsHtml += `
            <tr>
              <td style="text-align: center; color: #475569;">${idx + 1}</td>
              <td><strong>${escapeHtml(it.partCode)}</strong></td>
              <td><strong>${escapeHtml(it.partName)}</strong></td>
              <td style="text-align: center;">${escapeHtml(it.batchNo || '-')}</td>
              <td style="text-align: center; font-weight: bold; color: #0f172a;">${formatCleanStockJs(it.qty)}</td>
              <td style="text-align: center;">${escapeHtml(it.uom || 'PCS')}</td>
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
                <td class="lbl">Invoice / DC Ref:</td>
                <td class="val"><strong style="color: #4f46e5;">${escapeHtml(data.invoiceRef || '-')}</strong></td>
                <td class="lbl">Dispatch Date:</td>
                <td class="val"><strong>${formatDateDMY(data.dispatchDate)}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Dispatched By:</td>
                <td class="val"><strong>${escapeHtml(data.dispatchedBy || '-')}</strong></td>
                <td class="lbl">Dispatch Status:</td>
                <td class="val"><span style="color: #15803d; font-weight: bold;">DISPATCHED / OUTWARD</span></td>
              </tr>
              <tr>
                <td class="lbl">Consignment Remarks:</td>
                <td class="val" colspan="3">${escapeHtml(data.remarks || 'None')}</td>
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
              batchNo: r.dataset.batch_no || '',
              qty: parseFloat(r.dataset.dispatch_qty || 0),
              uom: r.dataset.uom || 'PCS'
            });
          });
        } else {
          items.push({
            partCode: d.part_code || '',
            partName: d.part_name || '',
            batchNo: d.batch_no || '',
            qty: parseFloat(d.dispatch_qty || 0),
            uom: d.uom || 'PCS'
          });
        }

        printDeliveryChallanSlip({
          dispatchNo: d.dispatch_no,
          dispatchDate: d.dispatch_date,
          invoiceRef: d.invoice_ref,
          dispatchedBy: d.dispatched_by,
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
            openDispatchModal(tr.dataset.part_code);
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
              <strong style="font-size: 1.25rem; color: #059669;">${formatCleanStockJs(totalQty)} ${escapeHtml(d.uom || 'PCS')}</strong>
              ${hasRework && parseFloat(d.rework_qty || 0) > 0 ? `
                <div style="font-size: 0.74rem; color: #b45309; font-weight: 600; margin-top: 2px;">
                  (Orig: ${formatCleanStockJs(d.orig_qty || totalQty)} + Rework: ${formatCleanStockJs(d.rework_qty)})
                </div>
              ` : ''}
            </div>
            <div>
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block;">Stock Status</span>
              <span class="tag tag-available">Available</span>
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
            <div style="grid-column: 1 / -1;">
              <span style="font-size: 0.78rem; color: var(--text-sub); display: block; margin-bottom: 5px;">Output Variants Stock:</span>
              ${renderVariantPillsJs(variants)}
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
            const pCode = currentActiveRow.dataset.part_code;
            closeViewModal();
            openDispatchModal(pCode);
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
