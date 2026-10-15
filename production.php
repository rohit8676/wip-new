<?php
/**
 * WIP Management Portal - Production Entry / DPR (Daily Production Report)
 * Clean, professional UI for tracking and logging shop-floor production output.
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Format numbers cleanly
function formatCleanNum($val, $decimals = 0) {
    $f = floatval($val ?? 0);
    if ($f == (int)$f && $decimals == 0) {
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

// Fetch active parts and processes from database
$pdo = getDBConnection();
$partList = [];
$processList = [];
$mipList = [];

$todayDmy = date('d-m-Y');
$todaySqlDate = date('Y-m-d');
$yesterdaySqlDate = date('Y-m-d', strtotime('-1 day'));

if ($pdo) {
    try {
        $stmtParts = $pdo->query("SELECT id, part_code, part_name, child_parts FROM part_master WHERE status = 'Active' ORDER BY part_code ASC");
        $partList = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

        $stmtProc = $pdo->query("SELECT id, process_code, process_name FROM process_master WHERE status = 'Active' ORDER BY process_name ASC");
        $processList = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

        $stmtMips = $pdo->query("
            SELECT id, issue_no, 
                   CONVERT(VARCHAR(10), issue_date, 120) as issue_date,
                   part_code, part_name, issued_qty, uom, 
                   work_order, department, received_by, status, remarks, 
                   child_parts_details
            FROM material_issue
            ORDER BY id DESC
        ");
        if ($stmtMips) {
            $mipList = $stmtMips->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Fallback gracefully
    }
}

// Fallback sample MIP records if table is empty
if (empty($mipList)) {
    $mipList = [
        [
            'id' => 1,
            'issue_no' => 'MIP-001',
            'issue_date' => $todaySqlDate,
            'part_code' => 'P1001',
            'part_name' => 'Front Mounting Assembly',
            'issued_qty' => 120,
            'uom' => 'PCS',
            'work_order' => 'WO-2026-101',
            'department' => 'FLOOR1',
            'received_by' => 'Ramesh Kumar',
            'status' => 'Issued',
            'remarks' => 'Batch 1 for press line',
            'child_parts_details' => json_encode([
                ['code' => 'CP-101', 'name' => 'Bracket Steel Plate', 'ratio' => 1, 'consumed_qty' => 120, 'uom' => 'PCS'],
                ['code' => 'CP-102', 'name' => 'M8 Hex Bushing', 'ratio' => 2, 'consumed_qty' => 240, 'uom' => 'NOS']
            ])
        ],
        [
            'id' => 2,
            'issue_no' => 'MIP-002',
            'issue_date' => $todaySqlDate,
            'part_code' => 'P1002',
            'part_name' => 'Main Chassis Sub-Assembly',
            'issued_qty' => 80,
            'uom' => 'PCS',
            'work_order' => 'WO-2026-102',
            'department' => 'FLOOR2',
            'received_by' => 'Suresh Verma',
            'status' => 'Issued',
            'remarks' => 'Welding bay allocation',
            'child_parts_details' => json_encode([
                ['code' => 'CP-201', 'name' => 'Side Gusset Plate', 'ratio' => 2, 'consumed_qty' => 160, 'uom' => 'PCS'],
                ['code' => 'CP-202', 'name' => 'Cross Beam Member', 'ratio' => 1, 'consumed_qty' => 80, 'uom' => 'PCS']
            ])
        ],
        [
            'id' => 3,
            'issue_no' => 'MIP-003',
            'issue_date' => $yesterdaySqlDate,
            'part_code' => 'P1003',
            'part_name' => 'Support Bracket Assembly',
            'issued_qty' => 100,
            'uom' => 'PCS',
            'work_order' => 'WO-2026-098',
            'department' => 'FLOOR1',
            'received_by' => 'Rajesh Singh',
            'status' => 'Issued',
            'remarks' => 'Laser profiling requirement',
            'child_parts_details' => json_encode([
                ['code' => 'CP-301', 'name' => 'L-Bracket Base', 'ratio' => 1, 'consumed_qty' => 100, 'uom' => 'PCS'],
                ['code' => 'CP-302', 'name' => 'Support Flange', 'ratio' => 2, 'consumed_qty' => 200, 'uom' => 'PCS']
            ])
        ]
    ];
}

// Fallback sample parts if database is empty
if (empty($partList)) {
    $partList = [
        [
            'part_code' => 'P1001', 
            'part_name' => 'Front Mounting Assembly',
            'child_parts' => json_encode([
                ['code' => 'CP-101', 'name' => 'Bracket Steel Plate', 'qty' => 1, 'uom' => 'PCS'],
                ['code' => 'CP-102', 'name' => 'M8 Hex Bushing', 'qty' => 2, 'uom' => 'NOS']
            ])
        ],
        [
            'part_code' => 'P1002', 
            'part_name' => 'Main Chassis Sub-Assembly',
            'child_parts' => json_encode([
                ['code' => 'CP-201', 'name' => 'Side Gusset Plate', 'qty' => 2, 'uom' => 'PCS'],
                ['code' => 'CP-202', 'name' => 'Cross Beam Member', 'qty' => 1, 'uom' => 'PCS']
            ])
        ],
        [
            'part_code' => 'P1003', 
            'part_name' => 'Support Bracket Assembly',
            'child_parts' => json_encode([
                ['code' => 'CP-301', 'name' => 'L-Bracket Base', 'qty' => 1, 'uom' => 'PCS'],
                ['code' => 'CP-302', 'name' => 'Support Flange', 'qty' => 2, 'uom' => 'PCS']
            ])
        ],
        [
            'part_code' => 'P1004', 
            'part_name' => 'Retainer Frame Assembly',
            'child_parts' => '[]'
        ]
    ];
}

// Fallback sample processes if database is empty
if (empty($processList)) {
    $processList = [
        ['process_code' => 'PR-01', 'process_name' => 'Stamping & Press'],
        ['process_code' => 'PR-02', 'process_name' => 'Welding & Assembly'],
        ['process_code' => 'PR-03', 'process_name' => 'Laser Profiling'],
        ['process_code' => 'PR-04', 'process_name' => 'CNC Bending'],
        ['process_code' => 'PR-05', 'process_name' => 'Powder Coating']
    ];
}

$todayDmy = date('d-m-Y');
$todaySqlDate = date('Y-m-d');
$yesterdaySqlDate = date('Y-m-d', strtotime('-1 day'));

// Real Production / DPR records from MSSQL Database
$productionList = [];
$totalProducedOk = 0;
$totalRejections = 0;
$todayOutput = 0;

if ($pdo) {
    try {
        $stmtPrd = $pdo->query("
            SELECT id, mip_no, work_order, part_code, part_name,
                   department, received_by, process_name, shift,
                   target_qty, ok_qty, rejected_qty,
                   operator_name, uom, status,
                   CONVERT(VARCHAR(10), created_at, 120) as entry_date,
                   CONVERT(VARCHAR(19), created_at, 120) as created_at
            FROM production_entry
            ORDER BY id DESC
        ");
        if ($stmtPrd) {
            $productionList = $stmtPrd->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($productionList as $item) {
            $totalProducedOk += floatval($item['ok_qty'] ?? 0);
            $totalRejections += floatval($item['rejected_qty'] ?? 0);
            if (($item['entry_date'] ?? '') === $todaySqlDate) {
                $todayOutput += floatval($item['ok_qty'] ?? 0);
            }
        }
    } catch (PDOException $e) {
        // Fallback gracefully
    }
}

// Aggregate Statistics
$totalEntries = count($productionList);
$totalInspected = $totalProducedOk + $totalRejections;
$yieldRate = $totalInspected > 0 ? round(($totalProducedOk / $totalInspected) * 100, 1) : 100.0;

$pageTitle = 'Production Entry / Daily Production Report (DPR)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Production Entry (DPR) - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
    /* Specialized Production Page Styles */
    .shift-tag {
      display: inline-block;
      padding: 3px 8px;
      font-size: 0.72rem;
      font-weight: 600;
      border-radius: 4px;
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #e2e8f0;
      white-space: nowrap;
    }
    .shift-tag.shift-a {
      background: #eff6ff;
      color: #1d4ed8;
      border-color: #bfdbfe;
    }
    .shift-tag.shift-b {
      background: #faf5ff;
      color: #7e22ce;
      border-color: #e9d5ff;
    }
    .shift-tag.shift-c {
      background: #fff7ed;
      color: #c2410c;
      border-color: #ffedd5;
    }

    .badge-ok {
      display: inline-block;
      padding: 3px 8px;
      font-weight: 700;
      border-radius: 4px;
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
      font-variant-numeric: tabular-nums;
    }

    .badge-rej {
      display: inline-block;
      padding: 2px 7px;
      font-weight: 600;
      border-radius: 4px;
      background: #fef2f2;
      color: #dc2626;
      border: 1px solid #fecaca;
      font-variant-numeric: tabular-nums;
      font-size: 0.75rem;
    }



    .badge-nil {
      color: #94a3b8;
      font-size: 0.8rem;
    }

    /* Action Buttons in Table */
    .btn-view {
      padding: 4px 10px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 4px;
      font-size: 0.78rem;
      font-weight: 500;
      color: #0284c7;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-view:hover {
      background: #f0f9ff;
      border-color: #0284c7;
    }

    .btn-print {
      padding: 4px 10px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 4px;
      font-size: 0.78rem;
      font-weight: 500;
      color: #059669;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-print:hover {
      background: #ecfdf5;
      border-color: #059669;
    }

    .btn-edit {
      padding: 4px 10px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 4px;
      font-size: 0.78rem;
      font-weight: 500;
      color: var(--primary);
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-edit:hover {
      background: var(--primary-light);
      border-color: var(--primary);
    }

    .btn-delete {
      padding: 4px 10px;
      background: #ffffff;
      border: 1px solid #fecaca;
      border-radius: 4px;
      font-size: 0.78rem;
      font-weight: 500;
      color: #ef4444;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-delete:hover {
      background: #fef2f2;
      border-color: #ef4444;
    }

    /* Live Quality Yield Card in Modal */
    .yield-calc-box {
      border: 1px solid var(--border);
      background: #f8fafc;
      border-radius: 6px;
      padding: 12px 14px;
      margin-top: 4px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .yield-stat-item {
      display: flex;
      flex-direction: column;
    }
    .yield-stat-lbl {
      font-size: 0.72rem;
      color: var(--text-sub);
      text-transform: uppercase;
      font-weight: 600;
      letter-spacing: 0.03em;
    }
    .yield-stat-val {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-main);
      font-variant-numeric: tabular-nums;
    }

    /* Progress bar in View Modal */
    .progress-track {
      width: 100%;
      height: 7px;
      background: #e2e8f0;
      border-radius: 999px;
      overflow: hidden;
      margin-top: 6px;
    }
    .progress-fill {
      height: 100%;
      background: #10b981;
      border-radius: 999px;
      transition: width 0.3s ease;
    }

    /* Sub metadata below main text */
    .sub-meta {
      display: block;
      font-size: 0.75rem;
      color: var(--text-sub);
      margin-top: 2px;
      font-variant-numeric: tabular-nums;
    }

    /* 4-Column Stats Row (matching MIP portal style) */
    .stats-row-4 {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
    }
    @media (max-width: 1024px) {
      .stats-row-4 {
        grid-template-columns: repeat(2, 1fr);
      }
    }
    @media (max-width: 640px) {
      .stats-row-4 {
        grid-template-columns: 1fr;
      }
    }

    /* =========================================================
       Modal Overlay & 2-Column Form Layout
       ========================================================= */
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
      max-width: 780px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      border: 1px solid var(--border);
      animation: modalPopIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
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
      padding: 20px 22px;
      max-height: calc(85vh - 130px);
      overflow-y: auto;
    }

    /* Clean 2-Column Form Grid */
    .popup-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px 18px;
    }
    @media (max-width: 640px) {
      .popup-form-grid {
        grid-template-columns: 1fr;
      }
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
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

    /* Hide number input up/down spin buttons / scroll arrows */
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type=number] {
      -moz-appearance: textfield;
      appearance: textfield;
    }

    .form-section-title {
      grid-column: 1 / -1;
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding-bottom: 5px;
      border-bottom: 1.5px solid #e2e8f0;
      margin-top: 6px;
      margin-bottom: 2px;
      display: flex;
      align-items: center;
      gap: 6px;
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
  </style>
</head>
<body>
  <div class="layout-container">
    
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
      
      <!-- Topbar Header -->
      <?php require_once __DIR__ . '/includes/header.php'; ?>

      <div class="content-body">
        
        <!-- 4 Stats Cards (Clean Portal Design) -->
        <div class="stats-row-4">
          
          <!-- Card 1: Total Produced OK Qty -->
          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statTotalProduced"><?php echo number_format($totalProducedOk); ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-sub);">PCS</span></div>
            <div class="stat-title">Total OK Produced</div>
          </div>

          <!-- Card 2: Today's Production Output -->
          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statTodayOutput"><?php echo number_format($todayOutput); ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-sub);">PCS</span></div>
            <div class="stat-title">Today's Output (<?php echo $todayDmy; ?>)</div>
          </div>

          <!-- Card 3: Quality Yield Rate -->
          <div class="simple-card stat-box">
            <div class="stat-number" style="color: #0284c7;" id="statYieldRate"><?php echo $yieldRate; ?>%</div>
            <div class="stat-title">Quality Yield Rate (&ge; 98%)</div>
          </div>

          <!-- Card 4: Total Rejections / Scrap -->
          <div class="simple-card stat-box">
            <div class="stat-number" style="color: #ef4444;" id="statTotalRejections"><?php echo number_format($totalRejections); ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-sub);">PCS</span></div>
            <div class="stat-title">Total Rejections / Scrap</div>
          </div>

        </div>

        <!-- Main Production Section -->
        <div class="simple-card table-box">
          
          <!-- Table Bar & Controls -->
          <div class="table-bar" style="flex-wrap: wrap; gap: 12px;">
            <div>
              <h2 class="box-title">Daily Production Entries (DPR)</h2>
            </div>
            
            <div class="table-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
              <!-- Search Input -->
              <input type="text" id="prdSearch" class="simple-input" style="min-width: 220px;" placeholder="Search MIP No, Part, Work Order, Operator...">
              
              <!-- Filter by Shift -->
              <select id="filterShift" class="simple-input" style="width: auto;">
                <option value="">All Shifts</option>
                <option value="Shift A">Shift A (Morning)</option>
                <option value="Shift B">Shift B (Evening)</option>
                <option value="Shift C">Shift C (Night)</option>
                <option value="General Shift">General Shift</option>
              </select>

              <!-- Filter by Status -->
              <select id="filterStatus" class="simple-input" style="width: auto;">
                <option value="">All Status</option>
                <option value="Completed">Completed</option>
                <option value="In Progress">In Progress</option>
                <option value="On Hold">On Hold</option>
              </select>

              <!-- + Log Production Button -->
              <?php if (hasPermission('production', 'create')): ?>
              <button type="button" class="btn-primary" id="openAddPrdModalBtn">+ Log Production</button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Table Container -->
          <div class="table-wrap">
            <table class="simple-table" id="prdTable">
              <thead>
                <tr>
                  <th style="width: 45px; text-align: center;">#</th>
                  <th style="text-align: center; white-space: nowrap;">Date</th>
                  <th style="white-space: nowrap;">MIP No. &amp; WO</th>
                  <th style="white-space: nowrap;">Part Description</th>
                  <th style="white-space: nowrap;">Process &amp; Shift</th>
                  <th style="text-align: center; white-space: nowrap;">Target</th>
                  <th style="text-align: center; white-space: nowrap;">OK Qty</th>
                                    <th style="text-align: center; white-space: nowrap;">Rejected</th>
                  <th style="white-space: nowrap;">Operator</th>
                  <th style="text-align: center; white-space: nowrap;">Action</th>
                </tr>
              </thead>
              <tbody id="prdTableBody">
                <?php if (empty($productionList)): ?>
                  <tr id="emptyTableRow">
                    <td colspan="10" style="text-align: center; padding: 40px 20px; color: var(--text-sub);">
                      No production entries found. Click <strong>+ Log Production</strong> to record shop-floor output.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php $sr = 1; ?>
                  <?php foreach ($productionList as $item): ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>"
                        data-mip_no="<?php echo htmlspecialchars($item['mip_no'] ?? ''); ?>"
                        data-work_order="<?php echo htmlspecialchars($item['work_order'] ?? ''); ?>"
                        data-part_code="<?php echo htmlspecialchars($item['part_code']); ?>"
                        data-part_name="<?php echo htmlspecialchars($item['part_name']); ?>"
                        data-department="<?php echo htmlspecialchars($item['department'] ?? ''); ?>"
                        data-received_by="<?php echo htmlspecialchars($item['received_by'] ?? ''); ?>"
                        data-process_name="<?php echo htmlspecialchars($item['process_name']); ?>"
                        data-shift="<?php echo htmlspecialchars($item['shift']); ?>"
                        data-target_qty="<?php echo htmlspecialchars($item['target_qty']); ?>"
                        data-ok_qty="<?php echo htmlspecialchars($item['ok_qty']); ?>"
                        data-rejected_qty="<?php echo htmlspecialchars($item['rejected_qty'] ?? 0); ?>"
                        data-operator_name="<?php echo htmlspecialchars($item['operator_name']); ?>"
                        data-uom="<?php echo htmlspecialchars($item['uom'] ?? 'PCS'); ?>"
                        data-status="<?php echo htmlspecialchars($item['status'] ?? 'Completed'); ?>"
                        data-created_at="<?php echo !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : date('Y-m-d'); ?>">
                      <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $sr++; ?></td>
                      <td style="white-space: nowrap; text-align: center; font-size: 0.85rem; font-weight: 600; color: #475569;">
                        <?php echo !empty($item['created_at']) ? date('d-m-Y', strtotime($item['created_at'])) : date('d-m-Y'); ?>
                      </td>
                      <td style="white-space: nowrap;">
                        <strong style="color: #1e293b;"><?php echo htmlspecialchars($item['mip_no'] ?: '-'); ?></strong><br>
                        <span style="font-size: 0.75rem; color: var(--text-sub);"><?php echo htmlspecialchars($item['work_order'] ?: '-'); ?></span>
                      </td>
                      <td style="white-space: nowrap;">
                        <strong><?php echo htmlspecialchars($item['part_code']); ?></strong> - <?php echo htmlspecialchars($item['part_name']); ?>
                      </td>
                      <td style="white-space: nowrap;">
                        <span style="font-weight: 500;"><?php echo htmlspecialchars($item['process_name']); ?></span><br>
                        <span class="shift-tag shift-a" style="font-size: 0.7rem; padding: 1px 6px; margin-top: 3px; display: inline-block;">
                          <?php echo htmlspecialchars($item['shift']); ?>
                        </span>
                      </td>
                      <td style="text-align: center; font-variant-numeric: tabular-nums;"><?php echo formatCleanNum($item['target_qty']); ?></td>
                      <td style="text-align: center; font-variant-numeric: tabular-nums;">
                        <strong style="color: #059669;"><?php echo formatCleanNum($item['ok_qty']); ?></strong>
                      </td>
                      <td style="text-align: center; font-variant-numeric: tabular-nums;">
                        <?php if (floatval($item['rejected_qty'] ?? 0) > 0): ?>
                          <strong style="color: #dc2626;"><?php echo formatCleanNum($item['rejected_qty']); ?></strong>
                        <?php else: ?>
                          <span style="color: #94a3b8;">-</span>
                        <?php endif; ?>
                      </td>
                      <td style="white-space: nowrap;">
                        <span style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($item['operator_name']); ?></span>
                        <?php if (!empty($item['received_by'])): ?>
                          <br><span style="font-size: 0.72rem; color: var(--text-sub);">Rec: <?php echo htmlspecialchars($item['received_by']); ?></span>
                        <?php endif; ?>
                      </td>
                      <td style="text-align: center;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; min-width: 110px;">
                          <button type="button" class="btn-view" title="View Details">View</button>
                          <button type="button" class="btn-print" title="Print Production Slip">Print</button>
                          <?php if (hasPermission('production', 'update')): ?>
                          <button type="button" class="btn-edit" title="Edit Entry">Edit</button>
                          <?php endif; ?>
                          <?php if (hasPermission('production', 'delete')): ?>
                          <button type="button" class="btn-delete" title="Delete Entry">Delete</button>
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

    </div>

  </div>

  <!-- =========================================================
       Add / Edit Production Modal Form (Two-Column Layout)
       ========================================================= -->
  <div id="prdModal" class="modal-overlay">
    <div class="modal-card" style="max-width: 780px;">
      <div class="modal-header">
        <h3 class="modal-title" id="prdModalTitle">+ Log Production Entry</h3>
        <button type="button" class="modal-close-btn" id="closePrdModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="prdForm">
        <div class="modal-body">
          <input type="hidden" id="editPrdId" value="">

          <div class="popup-form-grid">
            
            <!-- Scan MIP Issue Slip (Simple Plain Input Field) -->
            <div class="form-group" style="grid-column: 1 / -1;">
              <label for="scanMipInput">Scan MIP Issue Slip</label>
              <input type="text" id="scanMipInput" class="form-control" placeholder="Scan barcode or enter MIP No." autocomplete="off">
            </div>

            <!-- Fetched MIP Data Simple Table (Shows right before Section 1) -->
            <div id="fetchedMipTableWrap" class="form-group" style="grid-column: 1 / -1; display: none;">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-main);">
                  Fetched Material Issue Details:
                </span>
                <span id="fetchedMipStatusTag" style="font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; background: #ecfdf5; color: #059669; font-weight: 600; border: 1px solid #a7f3d0;">
                  Data Loaded
                </span>
              </div>
              <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 6px;">
                <table class="simple-table" style="font-size: 0.82rem; margin: 0; width: 100%;">
                  <thead style="background: #f8fafc;">
                    <tr>
                      <th style="padding: 7px 10px;">MIP No.</th>
                      <th style="padding: 7px 10px;">Work Order</th>
                      <th style="padding: 7px 10px;">Part Code &amp; Name</th>
                      <th style="padding: 7px 10px; text-align: center;">Issued Qty</th>
                      <th style="padding: 7px 10px;">Floor / Dept</th>
                      <th style="padding: 7px 10px;">Received By</th>
                      <th style="padding: 7px 10px; text-align: center;">Issue Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td style="padding: 8px 10px;"><strong id="tblMipNo">-</strong></td>
                      <td style="padding: 8px 10px;"><strong id="tblMipWo">-</strong></td>
                      <td style="padding: 8px 10px;" id="tblMipPart">-</td>
                      <td style="padding: 8px 10px; text-align: center;"><strong id="tblMipQty" style="color: #059669;">-</strong></td>
                      <td style="padding: 8px 10px;" id="tblMipDept">-</td>
                      <td style="padding: 8px 10px;" id="tblMipReceivedBy">-</td>
                      <td style="padding: 8px 10px; text-align: center;" id="tblMipDate">-</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Child Part Details Section (BOM) -->
              <div id="fetchedChildPartsSection" style="margin-top: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                  <span style="font-size: 0.78rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    RM & Child Part Details (BOM):
                  </span>
                  <span id="childPartsCountBadge" style="font-size: 0.7rem; padding: 1px 7px; border-radius: 10px; background: #e0f2fe; color: #0369a1; font-weight: 600; border: 1px solid #bae6fd;">
                    0 Items
                  </span>
                </div>
                <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 6px;">
                  <table class="simple-table" style="font-size: 0.8rem; margin: 0; width: 100%;">
                    <thead style="background: #f8fafc;">
                      <tr>
                        <th style="padding: 6px 10px; width: 35px; text-align: center;">#</th>
                        <th style="padding: 6px 10px;">RM & Child Part Code</th>
                        <th style="padding: 6px 10px;">RM & Child Part Name</th>
                        <th style="padding: 6px 10px; text-align: center;">BOM Ratio</th>
                        <th style="padding: 6px 10px; text-align: center;">Total Issued / Req. Qty</th>
                        <th style="padding: 6px 10px; text-align: center;">UOM</th>
                      </tr>
                    </thead>
                    <tbody id="tblChildPartsBody">
                      <!-- Populated dynamically via JS -->
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- Production Details (Unified Single Section) -->
            <div class="form-section-title">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
              Production Details
            </div>

            <!-- Auto-generated & Synced Hidden Fields -->
            <input type="hidden" id="inputWorkOrder" value="">
            <input type="hidden" id="inputPartCode" value="">
            <input type="hidden" id="inputPartName" value="">
            <input type="hidden" id="inputPartSelect" value="">
            <input type="hidden" id="inputDepartment" value="">
            <input type="hidden" id="inputReceivedBy" value="">
            <input type="hidden" id="inputStatus" value="Completed">

            <!-- 2. Process / Stage -->
            <div class="form-group">
              <label for="inputProcess">Process / Stage *</label>
              <select id="inputProcess" class="form-control" required>
                <option value="">-- Select Process --</option>
                <?php foreach ($processList as $pr): ?>
                  <option value="<?php echo htmlspecialchars($pr['process_name']); ?>">
                    <?php echo htmlspecialchars($pr['process_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <!-- 1. Shift -->
            <div class="form-group">
              <label for="inputShift">Shift *</label>
              <select id="inputShift" class="form-control" required>
                <option value="">-- Select Shift --</option>
                <option value="Shift A">Shift A</option>
                <option value="Shift B">Shift B</option>
                <option value="Shift C">Shift C</option>
                <option value="General Shift">General Shift</option>
              </select>
            </div>

            <!-- Auto-populated UOM from Scanned MIP Slip -->
            <input type="hidden" id="inputUom" value="PCS">

            <!-- 3. Target Quantity (Readonly from Scanned MIP) -->
            <div class="form-group">
              <label for="inputTargetQty">Target / Planned Qty *</label>
              <input type="number" step="any" min="1" id="inputTargetQty" class="form-control" readonly style="background: #f8fafc; cursor: not-allowed;" required>
            </div>

            <!-- 4. Produced OK Quantity -->
            <div class="form-group">
              <label for="inputOkQty">Produced (OK) Qty *</label>
              <input type="number" step="any" min="0" id="inputOkQty" class="form-control" required>
            </div>

            <!-- 5. Rejected Quantity -->
            <div class="form-group">
              <label for="inputRejectedQty">Rejected / Scrap Qty</label>
              <input type="number" step="any" min="0" id="inputRejectedQty" class="form-control">
            </div>

            <!-- 7. Operator Name (Moved Below Quantities) -->
            <div class="form-group" style="grid-column: 1 / -1;">
              <label for="inputOperator">Operator / Technician *</label>
              <input type="text" id="inputOperator" class="form-control" placeholder="e.g., Ramesh Kumar" required>
            </div>

            <!-- 8. Live Quality / Yield Summary Calculation Box -->
            <div class="form-group" style="grid-column: 1 / -1;">
              <div class="yield-calc-box">
                <div class="yield-stat-item">
                  <span class="yield-stat-lbl">Total Inspected</span>
                  <span class="yield-stat-val" id="calcTotalInspected">0 PCS</span>
                </div>
                <div class="yield-stat-item">
                  <span class="yield-stat-lbl">Target Achievement</span>
                  <span class="yield-stat-val" id="calcAchievement">0%</span>
                </div>
                <div class="yield-stat-item">
                  <span class="yield-stat-lbl">Quality Yield Rate</span>
                  <span class="yield-stat-val" id="calcYieldRate" style="color: #10b981;">100%</span>
                </div>
                <div class="yield-stat-item">
                  <span class="yield-stat-lbl">Rejection Ratio</span>
                  <span class="yield-stat-val" id="calcRejectionRate" style="color: #64748b;">0.0%</span>
                </div>
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-secondary" id="cancelPrdModalBtn">Cancel</button>
          <button type="submit" class="btn-primary" id="savePrdSubmitBtn">+ Save Production</button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================
       View Production Run Details Modal
       ========================================================= -->
  <div id="viewPrdModal" class="modal-overlay">
    <div class="modal-card" style="max-width: 660px;">
      <div class="modal-header">
        <h3 class="modal-title" id="viewPrdModalTitle">Production Entry Details</h3>
        <button type="button" class="modal-close-btn" id="closeViewPrdBtn">&times;</button>
      </div>

      <div class="modal-body" style="padding: 20px;">
        
        <!-- Header summary pill -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--border);">
          <div>
            <div style="font-size:1.15rem; font-weight:700; color:var(--text-main);" id="vModalHeaderTitle">Production Entry Details</div>
            <div style="font-size:0.8rem; color:var(--text-sub); margin-top:2px;">
              Date: <strong id="vCreateDate" style="color:var(--text-main);">-</strong> &nbsp;|&nbsp; Shift: <span id="vShift" class="shift-tag shift-a">Shift A</span>
            </div>
          </div>
          <div id="vStatusTag">
            <span class="tag tag-completed">Completed</span>
          </div>
        </div>

        <!-- Meta Details Grid -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; margin-bottom: 18px; font-size: 0.85rem;">
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">Part Code & Name</div>
            <div style="font-weight:600; color:var(--text-main); margin-top:3px;" id="vPartDesc">-</div>
          </div>
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">MIP Slip / Work Order</div>
            <div style="font-weight:600; color:var(--text-main); margin-top:3px;" id="vWorkOrder">-</div>
          </div>
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">Process / Stage</div>
            <div style="font-weight:500; color:var(--text-main); margin-top:3px;" id="vProcessMachine">-</div>
          </div>
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">Operator / Technician</div>
            <div style="font-weight:500; color:var(--text-main); margin-top:3px;" id="vPersonnel">-</div>
          </div>
        </div>

        <!-- Quantitative Output Box -->
        <div style="border: 1px solid var(--border); border-radius: 6px; padding: 14px; background: #fafafa; margin-bottom: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <span style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #334155;">Production Output Metrics</span>
            <span style="font-size: 0.8rem; font-weight: 700; color: #059669;" id="vYieldRate">100% Yield</span>
          </div>

          <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; text-align: center;">
            <div style="background: #fff; padding: 8px; border-radius: 4px; border: 1px solid #e2e8f0;">
              <div style="font-size: 0.68rem; color: #64748b; font-weight: 600;">TARGET</div>
              <div style="font-size: 1rem; font-weight: 700; color: #0f172a;" id="vTargetQty">0</div>
            </div>
            <div style="background: #ecfdf5; padding: 8px; border-radius: 4px; border: 1px solid #a7f3d0;">
              <div style="font-size: 0.68rem; color: #059669; font-weight: 600;">PRODUCED OK</div>
              <div style="font-size: 1rem; font-weight: 700; color: #059669;" id="vOkQty">0</div>
            </div>
            <div style="background: #fef2f2; padding: 8px; border-radius: 4px; border: 1px solid #fecaca;">
              <div style="font-size: 0.68rem; color: #dc2626; font-weight: 600;">REJECTED</div>
              <div style="font-size: 1rem; font-weight: 700; color: #dc2626;" id="vRejectedQty">0</div>
            </div>
            <div style="background: #eff6ff; padding: 8px; border-radius: 4px; border: 1px solid #bfdbfe;">
              <div style="font-size: 0.68rem; color: #2563eb; font-weight: 600;">ACHIEVED</div>
              <div style="font-size: 1rem; font-weight: 700; color: #2563eb;" id="vAchievedRate">0%</div>
            </div>
          </div>

          <div class="progress-track">
            <div class="progress-fill" id="vProgressBar" style="width: 100%;"></div>
          </div>
        </div>

        <!-- Material Issue Info -->
        <div style="font-size: 0.82rem; color: #475569; line-height: 1.6; background: #fff; padding: 10px 14px; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 12px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
          <div><strong>Floor / Dept:</strong> <span id="vDepartment">-</span></div>
          <div><strong>Material Received By:</strong> <span id="vReceivedBy">-</span></div>
        </div>

        <!-- RM & Child Parts / BOM Breakdown -->
        <div id="vChildPartsSection">
          <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: #334155; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;">
            <span style="display: flex; align-items: center; gap: 5px;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
              RM & Child Parts / BOM Requirements:
            </span>
            <span id="vChildPartsCount" style="font-size: 0.7rem; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 1px 7px; border-radius: 10px;">0 Items</span>
          </div>
          <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 6px;">
            <table class="simple-table" style="font-size: 0.78rem; margin: 0; width: 100%;">
              <thead style="background: #f8fafc;">
                <tr>
                  <th style="padding: 5px 8px; width: 30px; text-align: center;">#</th>
                  <th style="padding: 5px 8px;">RM & Child Part Code</th>
                  <th style="padding: 5px 8px;">RM & Child Part Name</th>
                  <th style="padding: 5px 8px; text-align: center;">Ratio</th>
                  <th style="padding: 5px 8px; text-align: center;">Req. Qty</th>
                  <th style="padding: 5px 8px; text-align: center;">UOM</th>
                </tr>
              </thead>
              <tbody id="vChildPartsBody">
                <!-- Rendered by JS -->
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <div class="modal-footer" style="display:flex; justify-content:space-between;">
        <button type="button" class="btn-print" id="printViewPrdBtn" style="padding: 6px 14px; font-weight: 600;">Print Slip (A4)</button>
        <button type="button" class="btn-secondary" id="closeViewPrdFooterBtn">Close</button>
      </div>
    </div>
  </div>

  <!-- Delete Confirm Modal -->
  <div id="deleteModal" class="modal-overlay">
    <div class="modal-card" style="max-width: 400px;">
      <div class="modal-header">
        <h3 class="modal-title" style="color: #ef4444;">Confirm Delete</h3>
        <button type="button" class="modal-close-btn" id="closeDeleteBtn">&times;</button>
      </div>
      <div class="modal-body" style="padding: 18px 22px;">
        <p style="font-size: 0.9rem; color: #475569;">
          Are you sure you want to delete Production Entry <strong id="deleteTargetCode" style="color:#0f172a;"></strong>?
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-delete" id="confirmDeleteBtn" style="background:#ef4444; color:#fff; border-color:#ef4444; padding:6px 14px; font-weight:600;">Delete</button>
      </div>
    </div>
  </div>

  <!-- Toast Notification Container -->
  <div id="toastContainer" class="toast-container"></div>

  <!-- JavaScript Interaction Logic -->
  <script>
    function escapeHtml(text) {
      if (text === null || text === undefined) return '';
      const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
      return text.toString().replace(/[&<>"']/g, m => map[m]);
    }

    function formatDateDMY(dStr) {
      if (!dStr) return '';
      const clean = dStr.toString().split('T')[0];
      const p = clean.split('-');
      if (p.length === 3 && p[0].length === 4) {
        return `${p[2]}-${p[1]}-${p[0]}`;
      }
      return dStr;
    }

    function formatNumber(val) {
      const num = parseFloat(val || 0);
      return num.toLocaleString('en-US');
    }

    function showToast(message, type = 'success') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = `toast toast-${type}`;
      const iconSvg = type === 'success'
        ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><polyline points="20 6 9 17 4 12"></polyline></svg>`
        : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`;

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

    document.addEventListener('DOMContentLoaded', () => {

      const prdTableBody = document.getElementById('prdTableBody');
      const prdSearch = document.getElementById('prdSearch');
      const filterShift = document.getElementById('filterShift');
      const filterStatus = document.getElementById('filterStatus');

      const prdModal = document.getElementById('prdModal');
      const prdModalTitle = document.getElementById('prdModalTitle');
      const openAddPrdModalBtn = document.getElementById('openAddPrdModalBtn');
      const closePrdModalBtn = document.getElementById('closePrdModalBtn');
      const cancelPrdModalBtn = document.getElementById('cancelPrdModalBtn');
      const prdForm = document.getElementById('prdForm');
      const editPrdId = document.getElementById('editPrdId');

      // Form calculation inputs
      const inputTargetQty = document.getElementById('inputTargetQty');
      const inputOkQty = document.getElementById('inputOkQty');
      const inputRejectedQty = document.getElementById('inputRejectedQty');
      const inputUom = document.getElementById('inputUom');

      const calcTotalInspected = document.getElementById('calcTotalInspected');
      const calcAchievement = document.getElementById('calcAchievement');
      const calcYieldRate = document.getElementById('calcYieldRate');
      const calcRejectionRate = document.getElementById('calcRejectionRate');

      // View Modal
      const viewPrdModal = document.getElementById('viewPrdModal');
      const closeViewPrdBtn = document.getElementById('closeViewPrdBtn');
      const closeViewPrdFooterBtn = document.getElementById('closeViewPrdFooterBtn');
      const printViewPrdBtn = document.getElementById('printViewPrdBtn');
      let currentViewingData = null;

      // Delete Modal
      const deleteModal = document.getElementById('deleteModal');
      const closeDeleteBtn = document.getElementById('closeDeleteBtn');
      const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
      const deleteTargetCode = document.getElementById('deleteTargetCode');
      let pendingDeleteRow = null;

      // Calculate live yield inside Add/Edit modal
      function updateLiveYieldCalculation() {
        const target = parseFloat(inputTargetQty.value || 0);
        const ok = parseFloat(inputOkQty.value || 0);
        const rej = parseFloat(inputRejectedQty.value || 0);
        const uom = inputUom.value || 'PCS';

        const totalInspected = ok + rej;
        calcTotalInspected.textContent = `${formatNumber(totalInspected)} ${uom}`;

        if (target > 0) {
          const ach = Math.round((ok / target) * 100);
          calcAchievement.textContent = `${ach}%`;
        } else {
          calcAchievement.textContent = '0%';
        }

        if (totalInspected > 0) {
          const yRate = ((ok / totalInspected) * 100).toFixed(1);
          const rRate = ((rej / totalInspected) * 100).toFixed(1);

          calcYieldRate.textContent = `${yRate}%`;
          calcYieldRate.style.color = yRate >= 98.0 ? '#10b981' : (yRate >= 95.0 ? '#d97706' : '#ef4444');

          calcRejectionRate.textContent = `${rRate}%`;
          calcRejectionRate.style.color = rej > 0 ? '#ef4444' : '#64748b';
        } else {
          calcYieldRate.textContent = '100%';
          calcYieldRate.style.color = '#10b981';
          calcRejectionRate.textContent = '0.0%';
          calcRejectionRate.style.color = '#64748b';
        }
      }

      inputTargetQty.addEventListener('input', updateLiveYieldCalculation);
      inputOkQty.addEventListener('input', updateLiveYieldCalculation);
      inputRejectedQty.addEventListener('input', updateLiveYieldCalculation);
      if (inputUom) inputUom.addEventListener('change', updateLiveYieldCalculation);

      // Disable mouse wheel scroll changing number input values
      document.addEventListener('wheel', () => {
        if (document.activeElement && document.activeElement.type === 'number') {
          document.activeElement.blur();
        }
      });

      // Filtering & Search
      function applyFilters() {
        const query = prdSearch.value.toLowerCase().trim();
        const selectedShift = filterShift.value.trim();
        const selectedStatus = filterStatus.value.trim();

        const rows = prdTableBody.querySelectorAll('tr:not(#emptyTableRow)');
        rows.forEach(r => {
          const text = r.textContent.toLowerCase();
          const rShift = r.getAttribute('data-shift') || '';
          const rStatus = r.getAttribute('data-status') || '';

          const matchesQuery = !query || text.includes(query);
          const matchesShift = !selectedShift || rShift.includes(selectedShift);
          const matchesStatus = !selectedStatus || rStatus.toLowerCase() === selectedStatus.toLowerCase();

          r.style.display = (matchesQuery && matchesShift && matchesStatus) ? '' : 'none';
        });
      }

      prdSearch.addEventListener('input', applyFilters);
      filterShift.addEventListener('change', applyFilters);
      filterStatus.addEventListener('change', applyFilters);

      // Modal open & close
      function openModal(title = '+ Log Production Entry') {
        prdModalTitle.textContent = title;
        prdModal.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function closeModal() {
        prdModal.classList.remove('active');
        document.body.style.overflow = '';
        prdForm.reset();
        editPrdId.value = '';
        if (typeof clearMipScan === 'function') {
          clearMipScan();
        }
        updateLiveYieldCalculation();
      }

      openAddPrdModalBtn.addEventListener('click', () => {
        editPrdId.value = '';
        if (typeof clearMipScan === 'function') {
          clearMipScan();
        }
        document.getElementById('inputShift').value = '';
        document.getElementById('inputWorkOrder').value = '';
        if (document.getElementById('inputPartSelect')) {
          document.getElementById('inputPartSelect').value = '';
          document.getElementById('inputPartSelect').setAttribute('data-name', '');
        }
        if (document.getElementById('inputPartCode')) document.getElementById('inputPartCode').value = '';
        if (document.getElementById('inputPartName')) document.getElementById('inputPartName').value = '';
        if (document.getElementById('inputDepartment')) document.getElementById('inputDepartment').value = '';
        if (document.getElementById('inputReceivedBy')) document.getElementById('inputReceivedBy').value = '';
        document.getElementById('inputProcess').value = '';
        document.getElementById('inputOperator').value = '';
        document.getElementById('inputTargetQty').value = '';
        document.getElementById('inputOkQty').value = '';
        document.getElementById('inputRejectedQty').value = '';
        updateLiveYieldCalculation();
        openModal('+ Log Production Entry');
        setTimeout(() => {
          const scanEl = document.getElementById('scanMipInput');
          if (scanEl) scanEl.focus();
        }, 150);
      });

      // Available MIP records from backend
      const availableMips = <?php echo json_encode($mipList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

      // Available Parts Catalog with Child Parts (BOM)
      const partsCatalog = <?php 
        $catalog = [];
        foreach ($partList as $p) {
          $catalog[$p['part_code']] = $p;
        }
        echo json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); 
      ?>;

      const scanMipInput = document.getElementById('scanMipInput');

      function clearMipScan() {
        if (scanMipInput) scanMipInput.value = '';
        const tblWrap = document.getElementById('fetchedMipTableWrap');
        if (tblWrap) tblWrap.style.display = 'none';
        const tbody = document.getElementById('tblChildPartsBody');
        if (tbody) tbody.innerHTML = '';
        const countBadge = document.getElementById('childPartsCountBadge');
        if (countBadge) countBadge.textContent = '0 Items';
        const elRecBy = document.getElementById('tblMipReceivedBy');
        if (elRecBy) elRecBy.textContent = '-';
        if (document.getElementById('inputPartSelect')) {
          document.getElementById('inputPartSelect').value = '';
          document.getElementById('inputPartSelect').setAttribute('data-name', '');
        }
        if (document.getElementById('inputPartCode')) document.getElementById('inputPartCode').value = '';
        if (document.getElementById('inputPartName')) document.getElementById('inputPartName').value = '';
        if (document.getElementById('inputDepartment')) document.getElementById('inputDepartment').value = '';
        if (document.getElementById('inputReceivedBy')) document.getElementById('inputReceivedBy').value = '';
      }

      function renderChildParts(match, issuedQty) {
        const tbody = document.getElementById('tblChildPartsBody');
        const countBadge = document.getElementById('childPartsCountBadge');
        if (!tbody) return;
        tbody.innerHTML = '';

        let childParts = [];

        // 1. Try parsing child_parts_details from MIP record
        if (match && match.child_parts_details) {
          try {
            if (typeof match.child_parts_details === 'string') {
              childParts = JSON.parse(match.child_parts_details);
            } else if (Array.isArray(match.child_parts_details)) {
              childParts = match.child_parts_details;
            }
          } catch (e) {
            childParts = [];
          }
        }

        // 2. If empty, fallback to BOM from partsCatalog
        if ((!childParts || childParts.length === 0) && match && match.part_code && partsCatalog[match.part_code]) {
          try {
            const pData = partsCatalog[match.part_code];
            let bom = [];
            if (typeof pData.child_parts === 'string') {
              bom = JSON.parse(pData.child_parts || '[]');
            } else if (Array.isArray(pData.child_parts)) {
              bom = pData.child_parts;
            }
            if (Array.isArray(bom) && bom.length > 0) {
              const parentQty = parseFloat(issuedQty || match.issued_qty || 0);
              childParts = bom.map(cp => {
                const ratio = parseFloat(cp.qty || cp.ratio || 1) || 1;
                return {
                  code: cp.code || cp.part_code || '',
                  name: cp.name || cp.part_name || '',
                  ratio: ratio,
                  consumed_qty: ratio * parentQty,
                  uom: cp.uom || 'NOS'
                };
              });
            }
          } catch (e) {
            // ignore
          }
        }

        // 3. Render rows into child parts table
        if (Array.isArray(childParts) && childParts.length > 0) {
          if (countBadge) countBadge.textContent = `${childParts.length} Item${childParts.length > 1 ? 's' : ''}`;
          childParts.forEach((cp, idx) => {
            const cCode = cp.code || cp.part_code || '-';
            const cName = cp.name || cp.part_name || '-';
            const ratio = parseFloat(cp.ratio || cp.qty || 1);
            let totalQty = cp.consumed_qty !== undefined ? parseFloat(cp.consumed_qty) : (ratio * parseFloat(issuedQty || 0));
            if (isNaN(totalQty)) totalQty = ratio * parseFloat(issuedQty || 0);
            const uom = cp.uom || 'NOS';

            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td style="padding: 6px 10px; text-align: center; color: var(--text-sub);">${idx + 1}</td>
              <td style="padding: 6px 10px;"><strong style="color: #1e293b;">${escapeHtml(cCode)}</strong></td>
              <td style="padding: 6px 10px; color: #334155;">${escapeHtml(cName)}</td>
              <td style="padding: 6px 10px; text-align: center;"><span style="background: #f1f5f9; padding: 2px 7px; border-radius: 4px; font-weight: 600; font-size: 0.76rem;">1 : ${ratio}</span></td>
              <td style="padding: 6px 10px; text-align: center;"><strong style="color: #0284c7;">${Number.isInteger(totalQty) ? totalQty : totalQty.toFixed(2)}</strong></td>
              <td style="padding: 6px 10px; text-align: center;"><span style="color: #64748b; font-size: 0.76rem;">${escapeHtml(uom)}</span></td>
            `;
            tbody.appendChild(tr);
          });
        } else {
          if (countBadge) countBadge.textContent = '0 Items';
          tbody.innerHTML = `
            <tr>
              <td colspan="6" style="padding: 8px 10px; text-align: center; color: #94a3b8; font-style: italic;">
                No RM & child parts linked with this assembly / MIP slip
              </td>
            </tr>
          `;
        }
      }

      async function fetchScannedMip(code) {
        const query = (code || '').trim().toLowerCase();
        if (!query) return;

        // Search in preloaded MIP list by issue_no or work_order
        let match = availableMips.find(m => 
          (m.issue_no && m.issue_no.toLowerCase() === query) ||
          (m.work_order && m.work_order.toLowerCase() === query)
        );

        // Fallback: If not found in preloaded list, fetch live from api/mip.php
        if (!match) {
          try {
            const res = await fetch('api/mip.php');
            const resJson = await res.json();
            if (resJson.status === 'success' && Array.isArray(resJson.data)) {
              resJson.data.forEach(item => {
                if (!availableMips.find(m => m.issue_no === item.issue_no)) {
                  availableMips.push(item);
                }
              });
              match = availableMips.find(m => 
                (m.issue_no && m.issue_no.toLowerCase() === query) ||
                (m.work_order && m.work_order.toLowerCase() === query)
              );
            }
          } catch (e) {
            // ignore network errors
          }
        }

        const tblWrap = document.getElementById('fetchedMipTableWrap');

        if (match) {
          // ── Duplicate MIP check ──────────────────────────────────────
          // If we are in ADD mode (no editPrdId), block duplicate MIP no.
          const currentEditId = editPrdId ? editPrdId.value : '';
          const existingRows = prdTableBody ? prdTableBody.querySelectorAll('tr[data-mip_no]') : [];
          let dupRow = null;
          existingRows.forEach(r => {
            const rowMip = (r.getAttribute('data-mip_no') || '').trim().toLowerCase();
            const rowId  = (r.getAttribute('data-id') || '').trim();
            if (rowMip && rowMip === match.issue_no.toLowerCase()) {
              // Only flag as duplicate if this is NOT the row currently being edited
              if (!currentEditId || rowId !== currentEditId) {
                dupRow = r;
              }
            }
          });

          if (dupRow) {
            showToast(
              `MIP slip "${match.issue_no}" is already logged in production. Duplicate entries are not allowed.`,
              'error'
            );
            if (scanMipInput) {
              scanMipInput.value = '';
              scanMipInput.focus();
            }
            if (tblWrap) tblWrap.style.display = 'none';
            return; // ── stop here ──
          }
          // ─────────────────────────────────────────────────────────────

          // 1. Auto-fill Work Order
          if (match.work_order) {
            document.getElementById('inputWorkOrder').value = match.work_order;
          }

          // 2. Auto-fill Part (code & name in hidden fields)
          const pCode = match.part_code || '';
          const pName = match.part_name || (partsCatalog[pCode] ? partsCatalog[pCode].part_name : pCode);
          const partSelect = document.getElementById('inputPartSelect');
          const partCodeInput = document.getElementById('inputPartCode');
          const partNameInput = document.getElementById('inputPartName');
          if (partSelect) {
            partSelect.value = pCode;
            partSelect.setAttribute('data-name', pName);
          }
          if (partCodeInput) partCodeInput.value = pCode;
          if (partNameInput) partNameInput.value = pName;
          if (document.getElementById('inputDepartment')) {
            document.getElementById('inputDepartment').value = match.department || '';
          }
          if (document.getElementById('inputReceivedBy')) {
            document.getElementById('inputReceivedBy').value = match.received_by || '';
          }

          // 3. Auto-fill Target Quantity from Issued Qty
          const qty = parseFloat(match.issued_qty || 0);
          if (qty > 0) {
            document.getElementById('inputTargetQty').value = qty;
          }

          // 4. Auto-fill UOM
          const uomEl = document.getElementById('inputUom');
          if (uomEl && match.uom) {
            uomEl.value = match.uom;
          }

          // 5. Populate Fetched Data Simple Table
          const elNo = document.getElementById('tblMipNo');
          const elWo = document.getElementById('tblMipWo');
          const elPart = document.getElementById('tblMipPart');
          const elQty = document.getElementById('tblMipQty');
          const elDept = document.getElementById('tblMipDept');
          const elReceivedBy = document.getElementById('tblMipReceivedBy');
          const elDate = document.getElementById('tblMipDate');

          if (elNo) elNo.textContent = match.issue_no;
          if (elWo) elWo.textContent = match.work_order || '-';
          if (elPart) elPart.textContent = `${match.part_code} - ${match.part_name || ''}`;
          if (elQty) elQty.textContent = `${qty} ${match.uom || 'PCS'}`;
          if (elDept) elDept.textContent = match.department || '-';
          if (elReceivedBy) elReceivedBy.textContent = match.received_by || '-';
          if (elDate) elDate.textContent = formatDateDMY(match.issue_date || '');

          // 6. Populate Child Parts Details Table
          renderChildParts(match, qty);

          if (tblWrap) tblWrap.style.display = 'block';

          updateLiveYieldCalculation();
          showToast(`MIP slip ${match.issue_no} scanned & loaded!`, 'success');

          // Auto-advance focus to Process dropdown for quick shop-floor entry
          const procEl = document.getElementById('inputProcess');
          if (procEl) procEl.focus();

        } else {
          if (tblWrap) tblWrap.style.display = 'none';
          showToast(`No MIP slip found for "${code}"`, 'error');
        }
      }

      if (scanMipInput) {
        scanMipInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            fetchScannedMip(scanMipInput.value);
          }
        });
        scanMipInput.addEventListener('change', () => {
          fetchScannedMip(scanMipInput.value);
        });
      }

      closePrdModalBtn.addEventListener('click', closeModal);
      cancelPrdModalBtn.addEventListener('click', closeModal);

      // Create / Update Row Helper
      function getShiftClass(shiftStr) {
        if (shiftStr.includes('Shift B')) return 'shift-b';
        if (shiftStr.includes('Shift C')) return 'shift-c';
        return 'shift-a';
      }

      function populateRow(tr, data) {
        tr.setAttribute('data-id', data.id);
        tr.setAttribute('data-mip_no', data.mip_no || '');
        tr.setAttribute('data-work_order', data.work_order || '');
        tr.setAttribute('data-part_code', data.part_code);
        tr.setAttribute('data-part_name', data.part_name);
        tr.setAttribute('data-department', data.department || '');
        tr.setAttribute('data-received_by', data.received_by || '');
        tr.setAttribute('data-process_name', data.process_name);
        tr.setAttribute('data-shift', data.shift);
        tr.setAttribute('data-target_qty', data.target_qty);
        tr.setAttribute('data-ok_qty', data.ok_qty);
        tr.setAttribute('data-rejected_qty', data.rejected_qty || 0);
        tr.setAttribute('data-operator_name', data.operator_name || '');
        tr.setAttribute('data-uom', data.uom || 'PCS');
        tr.setAttribute('data-status', data.status || 'Completed');
        tr.setAttribute('data-created_at', data.created_at ? data.created_at.substring(0, 10) : new Date().toISOString().substring(0, 10));

        const rejCell = parseFloat(data.rejected_qty || 0) > 0
          ? `<strong style="color: #dc2626;">${formatNumber(data.rejected_qty)}</strong>`
          : `<span style="color: #94a3b8;">-</span>`;

        const recByHtml = data.received_by ? `<br><span style="font-size: 0.72rem; color: var(--text-sub);">Rec: ${escapeHtml(data.received_by)}</span>` : '';
        const createDateStr = data.created_at ? formatDateDMY(data.created_at) : formatDateDMY(new Date().toISOString());

        tr.innerHTML = `
          <td style="color: var(--text-sub); font-weight: 600; text-align: center;">1</td>
          <td style="white-space: nowrap; text-align: center; font-size: 0.85rem; font-weight: 600; color: #475569;">
            ${escapeHtml(createDateStr)}
          </td>
          <td style="white-space: nowrap;">
            <strong style="color: #1e293b;">${escapeHtml(data.mip_no || '-')}</strong><br>
            <span style="font-size: 0.75rem; color: var(--text-sub);">${escapeHtml(data.work_order || '-')}</span>
          </td>
          <td style="white-space: nowrap;">
            <strong>${escapeHtml(data.part_code)}</strong> - ${escapeHtml(data.part_name)}
          </td>
          <td style="white-space: nowrap;">
            <span style="font-weight: 500;">${escapeHtml(data.process_name)}</span><br>
            <span class="shift-tag shift-a" style="font-size: 0.7rem; padding: 1px 6px; margin-top: 3px; display: inline-block;">${escapeHtml(data.shift)}</span>
          </td>
          <td style="text-align: center; font-variant-numeric: tabular-nums;">${formatNumber(data.target_qty)}</td>
          <td style="text-align: center; font-variant-numeric: tabular-nums;">
            <strong style="color: #059669;">${formatNumber(data.ok_qty)}</strong>
          </td>
          <td style="text-align: center; font-variant-numeric: tabular-nums;">${rejCell}</td>
          <td style="white-space: nowrap;">
            <span style="font-weight: 600; color: #1e293b;">${escapeHtml(data.operator_name || '-')}</span>
            ${recByHtml}
          </td>
          <td style="text-align: center;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; min-width: 110px;">
              <button type="button" class="btn-view" title="View Details">View</button>
              <button type="button" class="btn-print" title="Print Production Slip">Print</button>
              <button type="button" class="btn-edit" title="Edit Entry">Edit</button>
              <button type="button" class="btn-delete" title="Delete Entry">Delete</button>
            </div>
          </td>
        `;
      }

      // Submit Form (Add / Edit via API to Database)
      prdForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const id = editPrdId.value;
        const mipNo = (document.getElementById('scanMipInput') ? document.getElementById('scanMipInput').value.trim() : '');
        const shift = document.getElementById('inputShift').value;
        const workOrder = document.getElementById('inputWorkOrder').value.trim();
        const partSelect = document.getElementById('inputPartSelect');
        const partCode = (document.getElementById('inputPartCode') ? document.getElementById('inputPartCode').value.trim() : '') ||
                         (partSelect ? partSelect.value.trim() : '');
        const partName = (document.getElementById('inputPartName') ? document.getElementById('inputPartName').value.trim() : '') ||
                         (partSelect ? (partSelect.getAttribute('data-name') || '') : '') ||
                         (partsCatalog[partCode] ? partsCatalog[partCode].part_name : partCode);
        const department = (document.getElementById('inputDepartment') ? document.getElementById('inputDepartment').value.trim() : '');
        const receivedBy = (document.getElementById('inputReceivedBy') ? document.getElementById('inputReceivedBy').value.trim() : '');
        const processName = document.getElementById('inputProcess').value;
        const targetQty = parseFloat(document.getElementById('inputTargetQty').value || 0);
        const okQty = parseFloat(document.getElementById('inputOkQty').value || 0);
        const rejectedQty = parseFloat(document.getElementById('inputRejectedQty').value || 0);
        const operatorName = document.getElementById('inputOperator').value.trim();
        const uom = document.getElementById('inputUom').value || 'PCS';
        const status = document.getElementById('inputStatus').value || 'Completed';

        if (!partCode) {
          showToast('Please scan an MIP slip to load part details.', 'error');
          return;
        }
        if (!processName) {
          showToast('Please select a Process / Stage.', 'error');
          return;
        }
        if (!shift) {
          showToast('Please select a Shift.', 'error');
          return;
        }
        if (!operatorName) {
          showToast('Please enter Operator / Technician name.', 'error');
          return;
        }

        const payload = {
          action: id ? 'update' : 'create',
          id: id,
          mip_no: mipNo,
          work_order: workOrder,
          part_code: partCode,
          part_name: partName,
          department: department,
          received_by: receivedBy,
          process_name: processName,
          shift: shift,
          target_qty: targetQty,
          ok_qty: okQty,
          rejected_qty: rejectedQty,
          operator_name: operatorName,
          uom: uom,
          status: status
        };

        const submitBtn = document.getElementById('savePrdSubmitBtn');
        const origText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';

        try {
          const res = await fetch('api/production.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          const resJson = await res.json();

          if (!resJson.success) {
            showToast(resJson.message || 'Failed to save production entry', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = origText;
            return;
          }

          const savedData = resJson.data;

          if (id) {
            // Edit existing row
            const row = prdTableBody.querySelector(`tr[data-id="${id}"]`);
            if (row) {
              populateRow(row, savedData);
              showToast('Production entry updated successfully in database!', 'success');
            }
          } else {
            // Add new row
            const emptyRow = document.getElementById('emptyTableRow');
            if (emptyRow) emptyRow.remove();

            const tr = document.createElement('tr');
            populateRow(tr, savedData);
            prdTableBody.insertBefore(tr, prdTableBody.firstChild);
            showToast('Production entry logged & stored in database!', 'success');
          }

          reindexRows();
          updateCounters();
          closeModal();
        } catch (err) {
          showToast('Network error while saving production entry.', 'error');
        } finally {
          submitBtn.disabled = false;
          submitBtn.textContent = origText;
        }
      });

      // View Modal
      function getRowData(row) {
        return {
          id: row.getAttribute('data-id') || '',
          mipNo: row.getAttribute('data-mip_no') || '',
          shift: row.getAttribute('data-shift') || '',
          partCode: row.getAttribute('data-part_code') || '',
          partName: row.getAttribute('data-part_name') || '',
          processName: row.getAttribute('data-process_name') || '',
          workOrder: row.getAttribute('data-work_order') || '',
          department: row.getAttribute('data-department') || '',
          receivedBy: row.getAttribute('data-received_by') || '',
          operatorName: row.getAttribute('data-operator_name') || '',
          targetQty: parseFloat(row.getAttribute('data-target_qty') || 0),
          okQty: parseFloat(row.getAttribute('data-ok_qty') || 0),
          rejectedQty: parseFloat(row.getAttribute('data-rejected_qty') || 0),
          uom: row.getAttribute('data-uom') || 'PCS',
          status: row.getAttribute('data-status') || 'Completed',
          createdAt: row.getAttribute('data-created_at') || ''
        };
      }

      function openViewModal(data) {
        currentViewingData = data;
        document.getElementById('viewPrdModalTitle').textContent = `Production Entry Details #${data.id}`;
        
        const createDateEl = document.getElementById('vCreateDate');
        if (createDateEl) {
          createDateEl.textContent = data.createdAt ? formatDateDMY(data.createdAt) : formatDateDMY(new Date().toISOString());
        }

        const shiftTag = document.getElementById('vShift');
        shiftTag.className = `shift-tag ${getShiftClass(data.shift)}`;
        shiftTag.textContent = data.shift;

        const statusTag = data.status === 'Completed'
          ? '<span class="tag tag-completed">Completed</span>'
          : (data.status === 'In Progress' 
              ? '<span class="tag tag-in-progress">In Progress</span>' 
              : '<span class="tag" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a;">On Hold</span>');
        document.getElementById('vStatusTag').innerHTML = statusTag;

        document.getElementById('vPartDesc').textContent = `${data.partCode} - ${data.partName}`;
        document.getElementById('vWorkOrder').textContent = `${data.mipNo ? data.mipNo + ' / ' : ''}${data.workOrder || '-'}`;
        document.getElementById('vProcessMachine').textContent = data.processName;
        document.getElementById('vPersonnel').textContent = data.operatorName || '-';

        if (document.getElementById('vDepartment')) {
          document.getElementById('vDepartment').textContent = data.department || '-';
        }
        if (document.getElementById('vReceivedBy')) {
          document.getElementById('vReceivedBy').textContent = data.receivedBy || '-';
        }

        const totalInsp = data.okQty + data.rejectedQty;
        const yieldPercent = totalInsp > 0 ? ((data.okQty / totalInsp) * 100).toFixed(1) : 100.0;
        const achPercent = data.targetQty > 0 ? Math.round((data.okQty / data.targetQty) * 100) : 0;

        document.getElementById('vYieldRate').textContent = `${yieldPercent}% Yield`;
        document.getElementById('vTargetQty').textContent = `${formatNumber(data.targetQty)} ${data.uom}`;
        document.getElementById('vOkQty').textContent = `${formatNumber(data.okQty)} ${data.uom}`;
        document.getElementById('vRejectedQty').textContent = `${formatNumber(data.rejectedQty)} ${data.uom}`;
        document.getElementById('vAchievedRate').textContent = `${achPercent}%`;

        document.getElementById('vProgressBar').style.width = `${Math.min(yieldPercent, 100)}%`;

        // Child parts for viewed part
        const vCpBody = document.getElementById('vChildPartsBody');
        const vCpCount = document.getElementById('vChildPartsCount');
        if (vCpBody) {
          vCpBody.innerHTML = '';
          let cpList = [];
          if (partsCatalog[data.partCode]) {
            try {
              const pData = partsCatalog[data.partCode];
              if (typeof pData.child_parts === 'string') {
                cpList = JSON.parse(pData.child_parts || '[]');
              } else if (Array.isArray(pData.child_parts)) {
                cpList = pData.child_parts;
              }
            } catch(e) { cpList = []; }
          }
          if (Array.isArray(cpList) && cpList.length > 0) {
            if (vCpCount) vCpCount.textContent = `${cpList.length} Item${cpList.length > 1 ? 's' : ''}`;
            cpList.forEach((cp, idx) => {
              const ratio = parseFloat(cp.qty || cp.ratio || 1) || 1;
              const reqQty = ratio * (data.okQty || data.targetQty || 0);
              const tr = document.createElement('tr');
              tr.innerHTML = `
                <td style="padding: 5px 8px; text-align: center; color: var(--text-sub);">${idx + 1}</td>
                <td style="padding: 5px 8px;"><strong>${escapeHtml(cp.code || cp.part_code || '-')}</strong></td>
                <td style="padding: 5px 8px;">${escapeHtml(cp.name || cp.part_name || '-')}</td>
                <td style="padding: 5px 8px; text-align: center;"><span style="background: #f1f5f9; padding: 1px 6px; border-radius: 4px; font-weight: 600;">1 : ${ratio}</span></td>
                <td style="padding: 5px 8px; text-align: center;"><strong style="color: #0284c7;">${Number.isInteger(reqQty) ? reqQty : reqQty.toFixed(2)}</strong></td>
                <td style="padding: 5px 8px; text-align: center;"><span style="color: #64748b; font-size: 0.74rem;">${escapeHtml(cp.uom || 'NOS')}</span></td>
              `;
              vCpBody.appendChild(tr);
            });
          } else {
            if (vCpCount) vCpCount.textContent = '0 Items';
            vCpBody.innerHTML = `<tr><td colspan="6" style="padding: 8px; text-align: center; color: #94a3b8; font-style: italic;">No RM & child parts linked with this assembly</td></tr>`;
          }
        }

        viewPrdModal.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function closeViewModal() {
        viewPrdModal.classList.remove('active');
        document.body.style.overflow = '';
      }
      closeViewPrdBtn.addEventListener('click', closeViewModal);
      closeViewPrdFooterBtn.addEventListener('click', closeViewModal);

      // High-Quality Code 128 (Subset B) SVG Barcode Generator (Offline, High-Density)
      function generateBarcode128Svg(text, height = 42) {
        if (!text || text === '-') return '';
        const str = String(text).trim();
        const P = [
          '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
          '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
          '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
          '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
          '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
          '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
          '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
          '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
          '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
          '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
          '114131','311141','411131','211412','211214','211232','2331112'
        ];

        let codes = [104]; // Start B
        let check = 104;
        for (let i = 0; i < str.length; i++) {
          let val = str.charCodeAt(i) - 32;
          if (val < 0 || val > 95) val = 0;
          codes.push(val);
          check += val * (i + 1);
        }
        codes.push(check % 103);
        codes.push(106); // Stop

        const patternStr = codes.map(c => P[c]).join('');
        const unit = 1.3;
        const quietZone = unit * 5;
        let currentX = quietZone;
        let rects = '';
        let isBar = true;

        for (let i = 0; i < patternStr.length; i++) {
          const width = parseInt(patternStr[i], 10) * unit;
          if (isBar) {
            rects += `<rect x="${currentX.toFixed(2)}" y="0" width="${width.toFixed(2)}" height="${height}" fill="#000000"/>`;
          }
          currentX += width;
          isBar = !isBar;
        }

        const totalWidth = currentX + quietZone;

        return `
          <svg width="${Math.ceil(totalWidth)}" height="${height}" viewBox="0 0 ${totalWidth} ${height}" xmlns="http://www.w3.org/2000/svg" style="display:block;">
            <rect x="0" y="0" width="${totalWidth}" height="${height}" fill="#ffffff"/>
            ${rects}
          </svg>
        `;
      }

      // Print Production Slip (A4 Portrait)
      function printProductionSlip(data) {
        const totalInsp = data.okQty + data.rejectedQty;
        const yieldPercent = totalInsp > 0 ? ((data.okQty / totalInsp) * 100).toFixed(1) : 100.0;
        const achPercent = data.targetQty > 0 ? Math.round((data.okQty / data.targetQty) * 100) : 0;

        const printHtml = `
          <!DOCTYPE html>
          <html>
          <head>
            <meta charset="UTF-8">
            <title>DPR_Slip_${escapeHtml(data.id || data.mipNo || 'Entry')}</title>
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
                color: #2563eb;
                margin-top: 3px;
                text-transform: uppercase;
              }
              .slip-badge {
                text-align: right;
              }
              .slip-no {
                font-size: 16px;
                font-weight: bold;
              }
              .slip-date {
                font-size: 11.5px;
                color: #475569;
                margin-top: 2px;
              }

              table.meta-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 18px;
              }
              table.meta-table td {
                padding: 7px 10px;
                border: 1px solid #94a3b8;
                font-size: 12px;
                vertical-align: middle;
              }
              table.meta-table td.lbl {
                background: #f1f5f9;
                font-weight: bold;
                width: 18%;
                color: #334155;
              }
              table.meta-table td.val {
                width: 32%;
              }

              .section-heading {
                font-size: 12.5px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.02em;
                margin-bottom: 6px;
                color: #0f172a;
              }

              table.metrics-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 25px;
              }
              table.metrics-table th {
                background: #f1f5f9;
                border: 1px solid #64748b;
                padding: 8px 10px;
                font-size: 11px;
                text-transform: uppercase;
                text-align: center;
                color: #1e293b;
              }
              table.metrics-table td {
                border: 1px solid #94a3b8;
                padding: 10px;
                font-size: 13px;
                text-align: center;
                font-weight: bold;
              }

              table.sig-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 40px;
              }
              table.sig-table td {
                width: 33.33%;
                border: 1px solid #94a3b8;
                padding: 12px;
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
                height: 50px;
              }
              .sig-line {
                border-top: 1px dashed #64748b;
                padding-top: 4px;
                font-size: 10px;
                color: #475569;
              }

              .footer-bar {
                margin-top: 25px;
                border-top: 1px solid #cbd5e1;
                padding-top: 6px;
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
                <div class="doc-title">Daily Production Report (DPR) / Output Slip</div>
              </div>
              <div class="slip-badge">
                <div class="slip-no">ENTRY ID: #${escapeHtml(data.id || '-')}</div>
                <div class="slip-date">Date: <strong>${escapeHtml(data.createdAt ? formatDateDMY(data.createdAt) : formatDateDMY(new Date().toISOString()))}</strong> &nbsp;|&nbsp; Shift: ${escapeHtml(data.shift)}</div>
              </div>
            </div>

            <table class="meta-table">
              <tr>
                <td class="lbl">Part Code & Name:</td>
                <td class="val" colspan="3"><strong>${escapeHtml(data.partCode)}</strong> - ${escapeHtml(data.partName)}</td>
              </tr>
              <tr>
                <td class="lbl">MIP Slip No:</td>
                <td class="val" style="vertical-align: middle;">
                  ${data.mipNo && data.mipNo !== '-' ? `
                  <div style="display: inline-flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">
                    ${generateBarcode128Svg(data.mipNo, 42)}
                    <div style="font-weight: 700; color: #0f172a; font-size: 11.5px; letter-spacing: 0.5px; margin-top: 3px; text-align: center;">
                      ${escapeHtml(data.mipNo)}
                    </div>
                  </div>` : `<strong>-</strong>`}
                </td>
                <td class="lbl">Work Order No:</td>
                <td class="val"><strong>${escapeHtml(data.workOrder || '-')}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Stage / Process:</td>
                <td class="val"><strong>${escapeHtml(data.processName)}</strong></td>
                <td class="lbl">Production Shift:</td>
                <td class="val">${escapeHtml(data.shift)}</td>
              </tr>
              <tr>
                <td class="lbl">Floor / Department:</td>
                <td class="val">${escapeHtml(data.department || '-')}</td>
                <td class="lbl">Material Received By:</td>
                <td class="val"><strong>${escapeHtml(data.receivedBy || '-')}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Create Date:</td>
                <td class="val"><strong>${escapeHtml(data.createdAt ? formatDateDMY(data.createdAt) : formatDateDMY(new Date().toISOString()))}</strong></td>
                <td class="lbl">Operator / Technician:</td>
                <td class="val"><strong>${escapeHtml(data.operatorName || '-')}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Production Status:</td>
                <td class="val" colspan="3"><strong>${escapeHtml(data.status)}</strong></td>
              </tr>
            </table>

            <div class="section-heading">Production & Quality Performance Metrics</div>
            <table class="metrics-table">
              <thead>
                <tr>
                  <th>Target / Planned</th>
                  <th>Produced (OK Qty)</th>
                  <th>Rejected / Scrap</th>
                  <th>Total Inspected</th>
                  <th>Quality Yield %</th>
                  <th>Achievement %</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>${formatNumber(data.targetQty)} ${escapeHtml(data.uom)}</td>
                  <td style="color:#059669;">${formatNumber(data.okQty)} ${escapeHtml(data.uom)}</td>
                  <td style="color:${data.rejectedQty > 0 ? '#dc2626' : '#64748b'};">${formatNumber(data.rejectedQty)} ${escapeHtml(data.uom)}</td>
                  <td>${formatNumber(totalInsp)} ${escapeHtml(data.uom)}</td>
                  <td style="color:#059669;">${yieldPercent}%</td>
                  <td style="color:#2563eb;">${achPercent}%</td>
                </tr>
              </tbody>
            </table>

            <table class="sig-table">
              <tr>
                <td>
                  <div class="sig-title">LOGGED BY (OPERATOR)</div>
                  <div class="sig-space"></div>
                  <div class="sig-line">Signature & Date: ${escapeHtml(data.operatorName || '')}</div>
                </td>
                <td>
                  <div class="sig-title">INSPECTED BY (QUALITY / QC)</div>
                  <div class="sig-space"></div>
                  <div class="sig-line">Signature & Date: OK / Rejected Verified</div>
                </td>
                <td>
                  <div class="sig-title">AUTHORIZED BY (SUPERVISOR)</div>
                  <div class="sig-space"></div>
                  <div class="sig-line">Signature & Date: ${escapeHtml(data.supervisorName || '')}</div>
                </td>
              </tr>
            </table>

            <div class="footer-bar">
              <span>System Generated Production Slip - WIP Management Portal</span>
              <span>Printed: ${new Date().toLocaleDateString('en-GB')} ${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</span>
              <span>Standard A4 Sheet</span>
            </div>
          </body>
          </html>
        `;

        let printIframe = document.getElementById('printPrdIframe');
        if (!printIframe) {
          printIframe = document.createElement('iframe');
          printIframe.id = 'printPrdIframe';
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

      printViewPrdBtn.addEventListener('click', () => {
        if (currentViewingData) {
          printProductionSlip(currentViewingData);
        }
      });

      // Table Action Delegations
      prdTableBody.addEventListener('click', (e) => {
        const row = e.target.closest('tr');
        if (!row || row.id === 'emptyTableRow') return;

        // View Details
        if (e.target.classList.contains('btn-view')) {
          const rowData = getRowData(row);
          openViewModal(rowData);
          return;
        }

        // Print Slip
        if (e.target.classList.contains('btn-print')) {
          const rowData = getRowData(row);
          printProductionSlip(rowData);
          return;
        }

        // Edit
        if (e.target.classList.contains('btn-edit')) {
          editPrdId.value = row.getAttribute('data-id');
          const shiftVal = row.getAttribute('data-shift') || '';
          const shiftEl = document.getElementById('inputShift');
          shiftEl.value = '';
          for (let i = 0; i < shiftEl.options.length; i++) {
            if (shiftEl.options[i].value && (shiftVal.includes(shiftEl.options[i].value) || shiftEl.options[i].value === shiftVal)) {
              shiftEl.selectedIndex = i;
              break;
            }
          }
          const mipNoVal = row.getAttribute('data-mip_no') || '';
          if (scanMipInput) scanMipInput.value = mipNoVal;
          document.getElementById('inputWorkOrder').value = row.getAttribute('data-work_order') || '';
          const pCode = row.getAttribute('data-part_code') || '';
          const pName = row.getAttribute('data-part_name') || '';
          if (document.getElementById('inputPartSelect')) {
            document.getElementById('inputPartSelect').value = pCode;
            document.getElementById('inputPartSelect').setAttribute('data-name', pName);
          }
          if (document.getElementById('inputPartCode')) document.getElementById('inputPartCode').value = pCode;
          if (document.getElementById('inputPartName')) document.getElementById('inputPartName').value = pName;
          if (document.getElementById('inputDepartment')) document.getElementById('inputDepartment').value = row.getAttribute('data-department') || '';
          if (document.getElementById('inputReceivedBy')) document.getElementById('inputReceivedBy').value = row.getAttribute('data-received_by') || '';
          document.getElementById('inputProcess').value = row.getAttribute('data-process_name') || '';
          document.getElementById('inputOperator').value = row.getAttribute('data-operator_name') || '';
          document.getElementById('inputTargetQty').value = row.getAttribute('data-target_qty') || '';
          document.getElementById('inputOkQty').value = row.getAttribute('data-ok_qty') || '';
          document.getElementById('inputRejectedQty').value = row.getAttribute('data-rejected_qty') || '0';
          document.getElementById('inputUom').value = row.getAttribute('data-uom') || 'PCS';
          document.getElementById('inputStatus').value = row.getAttribute('data-status') || 'Completed';

          if (mipNoVal) {
            const tblWrap = document.getElementById('fetchedMipTableWrap');
            const elNo = document.getElementById('tblMipNo');
            const elWo = document.getElementById('tblMipWo');
            const elPart = document.getElementById('tblMipPart');
            const elQty = document.getElementById('tblMipQty');
            const elDept = document.getElementById('tblMipDept');
            const elReceivedBy = document.getElementById('tblMipReceivedBy');
            const elDate = document.getElementById('tblMipDate');

            if (elNo) elNo.textContent = mipNoVal;
            if (elWo) elWo.textContent = row.getAttribute('data-work_order') || '-';
            if (elPart) elPart.textContent = `${pCode} - ${pName}`;
            if (elQty) elQty.textContent = `${row.getAttribute('data-target_qty') || 0} ${row.getAttribute('data-uom') || 'PCS'}`;
            if (elDept) elDept.textContent = row.getAttribute('data-department') || '-';
            if (elReceivedBy) elReceivedBy.textContent = row.getAttribute('data-received_by') || '-';
            if (elDate) elDate.textContent = '-';

            const match = availableMips.find(m => m.issue_no === mipNoVal) || { part_code: pCode, issued_qty: row.getAttribute('data-target_qty') };
            renderChildParts(match, row.getAttribute('data-target_qty'));
            if (tblWrap) tblWrap.style.display = 'block';
          }

          updateLiveYieldCalculation();
          openModal('Edit Production Entry (ID #' + row.getAttribute('data-id') + ')');
          return;
        }

        // Delete
        if (e.target.classList.contains('btn-delete')) {
          pendingDeleteRow = row;
          deleteTargetCode.textContent = row.getAttribute('data-mip_no') ? `MIP: ${row.getAttribute('data-mip_no')}` : `ID #${row.getAttribute('data-id')}`;
          deleteModal.classList.add('active');
          document.body.style.overflow = 'hidden';
        }
      });

      // Confirm Delete via API
      confirmDeleteBtn.addEventListener('click', async () => {
        if (!pendingDeleteRow) return;

        const id = pendingDeleteRow.getAttribute('data-id');
        if (!id) {
          pendingDeleteRow.remove();
          closeDelete();
          reindexRows();
          updateCounters();
          return;
        }

        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.textContent = 'Deleting...';

        try {
          const res = await fetch('api/production.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: id })
          });
          const resJson = await res.json();

          if (resJson.success) {
            pendingDeleteRow.remove();
            showToast('Production entry deleted from database.', 'success');

            const remainingRows = prdTableBody.querySelectorAll('tr:not(#emptyTableRow)');
            if (remainingRows.length === 0) {
              prdTableBody.innerHTML = `
                <tr id="emptyTableRow">
                  <td colspan="11" style="text-align: center; padding: 40px 20px; color: var(--text-sub);">
                    No production entries found. Click <strong>+ Log Production</strong> to record shop-floor output.
                  </td>
                </tr>
              `;
            }
            reindexRows();
            updateCounters();
            closeDelete();
          } else {
            showToast(resJson.message || 'Failed to delete entry.', 'error');
          }
        } catch (e) {
          showToast('Network error while deleting entry.', 'error');
        } finally {
          confirmDeleteBtn.disabled = false;
          confirmDeleteBtn.textContent = 'Delete Entry';
        }
      });

      function closeDelete() {
        deleteModal.classList.remove('active');
        document.body.style.overflow = '';
      }
      closeDeleteBtn.addEventListener('click', closeDelete);
      cancelDeleteBtn.addEventListener('click', closeDelete);

      function reindexRows() {
        const rows = prdTableBody.querySelectorAll('tr:not(#emptyTableRow)');
        rows.forEach((r, idx) => {
          r.children[0].textContent = idx + 1;
        });
      }

      function updateCounters() {
        const rows = prdTableBody.querySelectorAll('tr:not(#emptyTableRow)');
        let sumOk = 0;
        let sumRej = 0;
        let todayOk = 0;
        const todaySqlStr = '<?php echo $todaySqlDate; ?>';

        rows.forEach(r => {
          const ok = parseFloat(r.getAttribute('data-ok_qty') || 0);
          const rej = parseFloat(r.getAttribute('data-rejected_qty') || 0);
          const pDate = r.getAttribute('data-created_at') || '';

          sumOk += ok;
          sumRej += rej;
          if (pDate === todaySqlStr) {
            todayOk += ok;
          }
        });

        const totalInsp = sumOk + sumRej;
        const yRate = totalInsp > 0 ? ((sumOk / totalInsp) * 100).toFixed(1) : 100.0;

        document.getElementById('statTotalProduced').innerHTML = `${formatNumber(sumOk)} <span style="font-size:0.85rem; font-weight:500; color:var(--text-sub);">PCS</span>`;
        document.getElementById('statTodayOutput').innerHTML = `${formatNumber(todayOk)} <span style="font-size:0.85rem; font-weight:500; color:var(--text-sub);">PCS</span>`;
        document.getElementById('statYieldRate').textContent = `${yRate}%`;
        document.getElementById('statTotalRejections').innerHTML = `${formatNumber(sumRej)} <span style="font-size:0.85rem; font-weight:500; color:var(--text-sub);">PCS</span>`;
      }

    });
  </script>
</body>
</html>
