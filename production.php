<?php
/**
 * WIP Management Portal - Production Entry / DPR (Daily Production Report)
 * Clean, professional UI for tracking and logging shop-floor production output.
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Format numbers cleanly
if (!function_exists('formatCleanNum')) {
    function formatCleanNum($val, $decimals = 0) {
        $f = floatval($val ?? 0);
        if ($f == (int)$f && $decimals == 0) {
            return number_format($f, 0);
        }
        return rtrim(rtrim(number_format($f, 3), '0'), '.');
    }
}

// Format date to DD-MM-YYYY
if (!function_exists('formatDateDMY')) {
    function formatDateDMY($dStr) {
        if (empty($dStr)) return '';
        $clean = explode('T', (string)$dStr)[0];
        $ts = strtotime($clean);
        return $ts ? date('d-m-Y', $ts) : $dStr;
    }
}

// Fetch active parts and processes from database
$pdo = getDBConnection();
$partList = [];
$processList = [];
$mipList = [];
$customerList = [];

$todayDmy = date('d-m-Y');
$todaySqlDate = date('Y-m-d');
$yesterdaySqlDate = date('Y-m-d', strtotime('-1 day'));

if ($pdo) {
    try {
        $stmtParts = $pdo->query("SELECT id, part_code, part_name, child_parts, processes, output_variants FROM part_master WHERE status = 'Active' ORDER BY part_code ASC");
        $partList = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

        $stmtProc = $pdo->query("SELECT id, process_code, process_name, remarks FROM process_master WHERE status = 'Active' ORDER BY id ASC");
        $processList = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

        $stmtCust = $pdo->query("SELECT id, customer_code, customer_name FROM customer_master WHERE status = 'Active' ORDER BY customer_name ASC");
        if ($stmtCust) {
            $customerList = $stmtCust->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmtMips = $pdo->query("
            SELECT id, issue_no, 
                   CONVERT(VARCHAR(10), issue_date, 120) as issue_date,
                   customer_name, customer_code, so_number,
                   part_code, part_name, issued_qty, uom, 
                   received_by, remarks, 
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
            'customer_name' => 'Apex Industrial Corp',
            'customer_code' => 'CUST-001',
            'so_number' => 'SO-2026-101',
            'part_code' => 'P1001',
            'part_name' => 'Front Mounting Assembly',
            'issued_qty' => 120,
            'uom' => 'PCS',
            'received_by' => 'Ramesh Kumar',
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
            'customer_name' => 'Bharat Auto Ltd',
            'customer_code' => 'CUST-002',
            'so_number' => 'SO-2026-102',
            'part_code' => 'P1002',
            'part_name' => 'Main Chassis Sub-Assembly',
            'issued_qty' => 80,
            'uom' => 'PCS',
            'received_by' => 'Suresh Verma',
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
            'customer_name' => 'Zenith Precision',
            'customer_code' => 'CUST-003',
            'so_number' => 'SO-2026-098',
            'part_code' => 'P1003',
            'part_name' => 'Support Bracket Assembly',
            'issued_qty' => 100,
            'uom' => 'PCS',
            'received_by' => 'Rajesh Singh',
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
            SELECT id, mip_no, customer_name, customer_code, so_number, work_order, part_code, part_name,
                   department, received_by, process_name, shift,
                   target_qty, ok_qty, rejected_qty,
                   operator_name, uom, status,
                   is_rework, entry_type, rework_qty, rework_source_entry_id,
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
            if (empty($item['is_rework'])) {
                $totalRejections += floatval($item['rejected_qty'] ?? 0);
            }
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

$partsByCode = [];
foreach ($partList as $p) {
    $partsByCode[$p['part_code']] = $p;
}

if (!function_exists('getPartProcessesList')) {
    function getPartProcessesList($partCode, $partsByCode, $processList) {
        if (isset($partsByCode[$partCode]) && !empty($partsByCode[$partCode]['processes'])) {
            $pArr = json_decode($partsByCode[$partCode]['processes'], true);
            if (is_array($pArr) && count($pArr) > 0) {
                $res = [];
                foreach ($pArr as $idx => $p) {
                    if (is_array($p)) {
                        $res[] = [
                            'code' => $p['code'] ?? str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                            'name' => $p['name'] ?? ('Stage ' . ($idx + 1)),
                            'remarks' => $p['remarks'] ?? ''
                        ];
                    } elseif (is_string($p) && trim($p) !== '') {
                        $res[] = [
                            'code' => str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                            'name' => trim($p),
                            'remarks' => ''
                        ];
                    }
                }
                if (count($res) > 0) return $res;
            }
        }
        $res = [];
        foreach ($processList as $idx => $pm) {
            $res[] = [
                'code' => $pm['process_code'] ?? str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'name' => $pm['process_name'] ?? ('Stage ' . ($idx + 1)),
                'remarks' => $pm['remarks'] ?? ''
            ];
        }
        return $res;
    }
}
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

    /* Dynamic Process Action Button & Floating Menu */
    .btn-process-menu {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 9px;
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      border-radius: 4px;
      font-size: 0.78rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
      font-family: inherit;
      white-space: nowrap;
    }
    .btn-process-menu:hover, .btn-process-menu.active {
      background: #2563eb;
      color: #ffffff;
      border-color: #2563eb;
    }
    .floating-process-menu {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.08);
      min-width: 170px;
      padding: 4px 0;
      font-family: inherit;
      animation: menuFadeIn 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes menuFadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .fpm-list {
      max-height: 280px;
      overflow-y: auto;
    }
    .fpm-item {
      display: block;
      padding: 8px 14px;
      text-decoration: none;
      color: #334155;
      font-size: 0.84rem;
      font-weight: 500;
      line-height: 1.4;
      transition: background 0.12s ease, color 0.12s ease;
      white-space: nowrap;
    }
    .fpm-item:hover {
      background: #f1f5f9;
      color: #2563eb;
    }

    /* =========================================================
       Left Slide-in Process Workflow Drawer / Slider (Half Screen)
       ========================================================= */
    .slider-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.48);
      backdrop-filter: blur(4px);
      z-index: 99990;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      display: none;
    }
    .slider-overlay.active {
      opacity: 1 !important;
      pointer-events: auto !important;
      display: block !important;
    }

    .slider-drawer {
      position: fixed;
      top: 0;
      right: 0;
      bottom: 0;
      width: 50vw;
      min-width: 460px;
      max-width: 760px;
      height: 100vh;
      background: #ffffff;
      z-index: 99995;
      box-shadow: -20px 0 50px rgba(15, 23, 42, 0.25);
      display: flex;
      flex-direction: column;
      transform: translateX(100%);
      transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
      overflow: hidden;
      visibility: hidden;
    }
    .slider-drawer.active {
      transform: translateX(0) !important;
      visibility: visible !important;
    }

    @media (max-width: 768px) {
      .slider-drawer {
        width: 92vw;
        min-width: unset;
      }
    }

    /* Slider Header (Clean White, 3 KPI Cards Only) */
    .slider-header {
      background: #ffffff;
      color: #0f172a;
      padding: 14px 18px;
      flex-shrink: 0;
      position: relative;
      border-bottom: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }
    .slider-header-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .slider-summary-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      flex: 1;
    }
    .slider-stat-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 8px 12px;
      transition: all 0.15s ease;
    }
    .slider-stat-card:hover {
      background: #ffffff;
      border-color: #cbd5e1;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
    }
    .slider-stat-card.stat-card-target {
      border-left: 3px solid #2563eb;
    }
    .slider-stat-card.stat-card-fg {
      border-left: 3px solid #16a34a;
      background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
    }
    .slider-stat-card.stat-card-rej {
      border-left: 3px solid #ea580c;
      background: linear-gradient(180deg, #ffffff 0%, #fff7ed 100%);
    }
    .slider-stat-lbl {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #64748b;
    }
    .slider-stat-val {
      font-size: 1.05rem;
      font-weight: 800;
      color: #0f172a;
      margin-top: 2px;
      letter-spacing: -0.01em;
    }
    .slider-stat-val.val-fg {
      color: #16a34a;
    }
    .slider-stat-val.val-rej {
      color: #ea580c;
    }
    .slider-close-btn {
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      color: #64748b;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      cursor: pointer;
      transition: all 0.15s ease;
      flex-shrink: 0;
    }
    .slider-close-btn:hover {
      background: #e2e8f0;
      color: #0f172a;
      transform: scale(1.05);
    }

    /* Slider Body & Vertical Line Timeline */
    .slider-body {
      flex: 1;
      overflow-y: auto;
      padding: 24px 22px;
      background: #f8fafc;
      position: relative;
    }

    /* Vertical Timeline Track Line */
    .timeline-wrap {
      position: relative;
      padding-left: 10px;
    }
    .timeline-track-line {
      position: absolute;
      left: 27px;
      top: 18px;
      bottom: 24px;
      width: 3px;
      background: #cbd5e1;
      border-radius: 3px;
      z-index: 1;
    }

    /* Timeline Step Item */
    .timeline-step {
      display: flex;
      gap: 16px;
      position: relative;
      margin-bottom: 22px;
      z-index: 2;
    }
    .timeline-step:last-child {
      margin-bottom: 6px;
    }

    /* Step Node on the Vertical Line */
    .step-node {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 0.84rem;
      flex-shrink: 0;
      background: #ffffff;
      border: 2px solid #94a3b8;
      color: #64748b;
      box-shadow: 0 0 0 4px #f8fafc, 0 1px 3px rgba(0, 0, 0, 0.08);
      transition: all 0.2s ease;
    }
    .step-node.completed {
      background: #10b981;
      border-color: #059669;
      color: #ffffff;
      box-shadow: 0 0 0 4px #f8fafc, 0 2px 6px rgba(16, 185, 129, 0.35);
    }
    .step-node.in-progress {
      background: #2563eb;
      border-color: #1d4ed8;
      color: #ffffff;
      box-shadow: 0 0 0 4px #f8fafc, 0 0 0 7px rgba(37, 99, 235, 0.2);
    }
    .step-node.ready {
      background: #ffffff;
      border-color: #3b82f6;
      color: #2563eb;
    }

    /* Step Content Card */
    .step-card {
      flex: 1;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 9px;
      padding: 13px 15px;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 2px 6px rgba(15, 23, 42, 0.02);
      transition: all 0.15s ease;
    }
    .step-card:hover {
      border-color: #cbd5e1;
      transform: translateX(2px);
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
    }
    .step-card.active-stage {
      border-color: #93c5fd;
      background: linear-gradient(180deg, #ffffff 0%, #f0f7ff 100%);
    }

    /* Step Card Header */
    .step-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      margin-bottom: 8px;
    }
    .step-proc-name {
      font-size: 0.95rem;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .step-proc-order {
      font-size: 0.72rem;
      font-weight: 700;
      color: #64748b;
      background: #f1f5f9;
      padding: 1px 5px;
      border-radius: 4px;
    }

    .step-status-tag {
      font-size: 0.70rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding: 3px 8px;
      border-radius: 4px;
    }
    .step-status-completed {
      background: #dcfce7;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }
    .step-status-in-progress {
      background: #dbeafe;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .step-status-ready {
      background: #fef3c7;
      color: #b45309;
      border: 1px solid #fde68a;
    }
    .step-status-waiting {
      background: #f1f5f9;
      color: #64748b;
      border: 1px solid #e2e8f0;
    }

    /* Simple Stage Breakdown (Clean, Non-congested) */
    .simple-breakdown-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin: 8px 0;
    }
    .simple-stat-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.80rem;
      padding: 4px 10px;
      border-radius: 6px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      color: #334155;
    }
    .simple-stat-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .simple-stat-label {
      font-weight: 500;
      color: #64748b;
      font-size: 0.76rem;
    }
    .simple-stat-pct {
      font-weight: 800;
      font-size: 0.74rem;
      padding: 1px 6px;
      border-radius: 4px;
      margin-left: 2px;
    }
    .simple-stat-pass {
      background: #eff6ff;
      border-color: #bfdbfe;
    }
    .simple-stat-pass .simple-stat-pct {
      background: #2563eb;
      color: #ffffff;
    }
    .simple-stat-rej {
      background: #fff7ed;
      border-color: #fed7aa;
    }
    .simple-stat-rej .simple-stat-pct {
      background: #ea580c;
      color: #ffffff;
    }
    .simple-stat-pending {
      background: #f8fafc;
      border-color: #e2e8f0;
    }
    .simple-stat-pending .simple-stat-pct {
      background: #94a3b8;
      color: #ffffff;
    }

    /* Sleek Multi-segment Progress Bar */
    .simple-progress-bar {
      display: flex;
      height: 6px;
      background: #f1f5f9;
      border-radius: 6px;
      overflow: hidden;
      margin: 6px 0 4px 0;
    }
    .simple-bar-seg {
      height: 100%;
      transition: width 0.3s ease;
    }
    .simple-bar-seg.pass {
      background: #2563eb;
    }
    .simple-bar-seg.rej {
      background: #ea580c;
    }
    .simple-bar-seg.rem {
      background: #cbd5e1;
    }

    .step-operator-meta {
      font-size: 0.72rem;
      color: #64748b;
      margin-top: 6px;
      padding-top: 6px;
      border-top: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }
    .step-meta-item {
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .step-meta-item svg {
      flex-shrink: 0;
      color: #64748b;
    }
    .step-date-item {
      margin-left: auto;
    }

    /* Percentage Breakdown Pill Row */
    .step-pct-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 8px;
    }
    .pct-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-size: 0.78rem;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 6px;
    }
    .pct-pill-pass {
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
    }
    .pct-pill-rej {
      background: #fef2f2;
      color: #dc2626;
      border: 1px solid #fecaca;
    }
    .pct-pill-rem {
      background: #f8fafc;
      color: #64748b;
      border: 1px solid #e2e8f0;
    }

    /* Multi-Segment Stacked Progress Bar */
    .step-multi-bar {
      height: 8px;
      border-radius: 999px;
      background: #e2e8f0;
      display: flex;
      overflow: hidden;
      margin-bottom: 9px;
    }
    .bar-seg-pass {
      background: #10b981;
      transition: width 0.3s ease;
    }
    .bar-seg-rej {
      background: #ef4444;
      transition: width 0.3s ease;
    }
    .bar-seg-rem {
      background: #cbd5e1;
      transition: width 0.3s ease;
    }

    /* Step Metrics Grid */
    .step-metrics-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 6px;
      background: #f8fafc;
      border: 1px solid #f1f5f9;
      border-radius: 6px;
      padding: 6px 10px;
      font-size: 0.76rem;
    }
    .metric-item {
      display: flex;
      flex-direction: column;
    }
    .metric-lbl {
      color: #64748b;
      font-size: 0.68rem;
      font-weight: 600;
      text-transform: uppercase;
    }
    .metric-val {
      font-weight: 700;
      color: #0f172a;
      margin-top: 1px;
    }
    .metric-val.val-pass { color: #16a34a; }
    .metric-val.val-rej { color: #ea580c; }

    /* Slider Footer */
    .slider-footer {
      padding: 14px 22px;
      background: #ffffff;
      border-top: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      flex-shrink: 0;
    }
    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
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

          <!-- Card 4: Total Rework -->
          <div class="simple-card stat-box">
            <div class="stat-number" style="color: #ea580c;" id="statTotalRejections"><?php echo number_format($totalRejections); ?> <span style="font-size:0.85rem; font-weight:500; color:var(--text-sub);">PCS</span></div>
            <div class="stat-title">Total Rework</div>
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
              <input type="text" id="prdSearch" class="simple-input" style="min-width: 220px;" placeholder="Search MIP No, Customer, SO No, Part, Operator...">
              
              <!-- Filter by Status -->
              <select id="filterStatus" class="simple-input" style="width: auto;">
                <option value="">All Status</option>
                <option value="Complete">Complete</option>
                <option value="Progress">Progress</option>
              </select>

              <!-- + Log Production Button -->
              <?php if (hasPermission('production', 'create')): ?>
              <a href="production-add.php" class="btn-primary" id="openAddPrdModalBtn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">+ Log Production</a>
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
                  <th style="white-space: nowrap;">MIP No.</th>
                  <th style="white-space: nowrap;">Customer</th>
                  <th style="white-space: nowrap;">Part Name</th>
                  <th style="text-align: center; white-space: nowrap;">Target Qty</th>
                  <th style="text-align: center; white-space: nowrap;">OK Qty</th>
                  <th style="text-align: center; white-space: nowrap;">Status</th>
                  <th style="text-align: center; white-space: nowrap;">Action</th>
                </tr>
              </thead>
              <tbody id="prdTableBody">
                <?php if (empty($productionList)): ?>
                  <tr id="emptyTableRow">
                    <td colspan="9" style="text-align: center; padding: 40px 20px; color: var(--text-sub);">
                      No production entries found. Click <strong>+ Log Production</strong> to record shop-floor output.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php $sr = 1; ?>
                  <?php foreach ($productionList as $item): ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>"
                        data-mip_no="<?php echo htmlspecialchars($item['mip_no'] ?? ''); ?>"
                        data-customer_name="<?php echo htmlspecialchars($item['customer_name'] ?? ''); ?>"
                        data-customer_code="<?php echo htmlspecialchars($item['customer_code'] ?? ''); ?>"
                        data-so_number="<?php echo htmlspecialchars($item['so_number'] ?? ''); ?>"
                        data-work_order="<?php echo htmlspecialchars($item['work_order'] ?? ''); ?>"
                        data-part_code="<?php echo htmlspecialchars($item['part_code']); ?>"
                        data-part_name="<?php echo htmlspecialchars($item['part_name']); ?>"
                        data-department="<?php echo htmlspecialchars($item['department'] ?? ''); ?>"
                        data-received_by="<?php echo htmlspecialchars($item['received_by'] ?? ''); ?>"
                        data-process_name="<?php echo htmlspecialchars($item['process_name']); ?>"
                        data-target_qty="<?php echo htmlspecialchars($item['target_qty']); ?>"
                        data-ok_qty="<?php echo htmlspecialchars($item['ok_qty']); ?>"
                        data-rejected_qty="<?php echo htmlspecialchars($item['rejected_qty'] ?? 0); ?>"
                        data-rework_qty="<?php echo htmlspecialchars($item['rework_qty'] ?? 0); ?>"
                        data-is_rework="<?php echo !empty($item['is_rework']) ? '1' : '0'; ?>"
                        data-operator_name="<?php echo htmlspecialchars($item['operator_name']); ?>"
                        data-uom="<?php echo htmlspecialchars($item['uom'] ?? 'PCS'); ?>"
                        data-status="<?php 
                          $rStat = trim($item['status'] ?? 'In Progress');
                          $tq = floatval($item['target_qty'] ?? 0);
                          $ok = floatval($item['ok_qty'] ?? 0);
                          $rej = floatval($item['rejected_qty'] ?? 0);
                          $rw = floatval($item['rework_qty'] ?? 0);
                          $isDoneRow = (strcasecmp($rStat, 'Completed') === 0 || strcasecmp($rStat, 'Complete') === 0)
                                     || ($tq > 0 && ($ok + $rej) >= $tq)
                                     || ($tq > 0 && ($ok + $rej + $rw) >= $tq);
                          echo htmlspecialchars($isDoneRow ? 'Completed' : $rStat); 
                        ?>"
                        data-created_at="<?php echo !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : date('Y-m-d'); ?>">
                      <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $sr++; ?></td>
                      <td style="white-space: nowrap; text-align: center; font-size: 0.85rem; font-weight: 600; color: #475569;">
                        <?php echo !empty($item['created_at']) ? date('d-m-Y', strtotime($item['created_at'])) : date('d-m-Y'); ?>
                      </td>
                      <td style="white-space: nowrap;">
                        <strong style="color: #1e293b;"><?php echo htmlspecialchars($item['mip_no'] ?: '-'); ?></strong>
                        <?php if (!empty($item['is_rework'])): ?>
                          <span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>
                        <?php endif; ?>
                      </td>
                      <td style="white-space: nowrap;">
                        <span style="font-weight: 500; color: #334155;"><?php echo htmlspecialchars($item['customer_name'] ?: '-'); ?></span>
                      </td>
                      <td style="white-space: nowrap;">
                        <strong><?php echo htmlspecialchars($item['part_name'] ?: $item['part_code']); ?></strong>
                      </td>
                      <td style="text-align: center; font-variant-numeric: tabular-nums; font-weight: 600; color: #1e293b;">
                        <?php echo formatCleanNum($item['target_qty']); ?> <span style="font-size: 0.76rem; color: #64748b; font-weight: 500;"><?php echo htmlspecialchars($item['uom'] ?? 'PCS'); ?></span>
                      </td>
                      <td style="text-align: center; font-variant-numeric: tabular-nums; font-weight: 700; color: #16a34a;">
                        <?php echo formatCleanNum($item['ok_qty'] ?? 0); ?> <span style="font-size: 0.76rem; color: #059669; font-weight: 500;"><?php echo htmlspecialchars($item['uom'] ?? 'PCS'); ?></span>
                      </td>
                      <td style="text-align: center; white-space: nowrap;">
                        <?php 
                          $rowStatus = trim($item['status'] ?? 'In Progress');
                          $targetQ = floatval($item['target_qty'] ?? 0);
                          $okQ = floatval($item['ok_qty'] ?? 0);
                          $rejQ = floatval($item['rejected_qty'] ?? 0);
                          $rwQ = floatval($item['rework_qty'] ?? 0);
                          $isComplete = (strcasecmp($rowStatus, 'Completed') === 0 || strcasecmp($rowStatus, 'Complete') === 0)
                                     || ($targetQ > 0 && ($okQ + $rejQ) >= $targetQ)
                                     || ($targetQ > 0 && ($okQ + $rejQ + $rwQ) >= $targetQ);
                        ?>
                        <?php if ($isComplete): ?>
                          <span class="tag tag-completed" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-size: 0.76rem; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #16a34a;"></span>
                            Complete
                          </span>
                        <?php else: ?>
                          <span class="tag tag-in-progress" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-size: 0.76rem; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #2563eb;"></span>
                            Progress
                          </span>
                        <?php endif; ?>
                      </td>
                      <?php 
                        $itemPartCode = $item['part_code'] ?? '';
                        $itemStages = getPartProcessesList($itemPartCode, $partsByCode, $processList);
                      ?>
                      <td style="text-align: center;">
                        <div style="display: flex; gap: 5px; justify-content: center; align-items: center;">
                          <button type="button" class="btn-view" title="View Details">View</button>
                          
                          <!-- Dedicated Process Page Navigation (Single page, no dropdown) -->
                          <a href="process.php?id=<?php echo urlencode($item['id']); ?>&mip=<?php echo urlencode($item['mip_no'] ?? ''); ?>" 
                             class="btn-process" 
                             style="text-decoration: none; padding: 4px 10px; border-radius: 4px; font-size: 0.78rem; font-weight: 600; background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; display: inline-flex; align-items: center; gap: 4px;"
                             title="Open Process Workflow for this Part">
                            Process
                          </a>

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
              Date: <strong id="vCreateDate" style="color:var(--text-main);">-</strong>
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
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">MIP No. & SO Number</div>
            <div style="font-weight:600; color:var(--text-main); margin-top:3px;" id="vWorkOrder">-</div>
          </div>
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">Customer</div>
            <div style="font-weight:600; color:var(--text-main); margin-top:3px;" id="vCustomer">-</div>
          </div>
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">Process / Stage</div>
            <div style="font-weight:600; color:var(--text-main); margin-top:3px;" id="vProcessMachine">-</div>
          </div>
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">Operator / Technician</div>
            <div style="font-weight:500; color:var(--text-main); margin-top:3px;" id="vPersonnel">-</div>
          </div>
          <div>
            <div style="color:var(--text-sub); font-size:0.72rem; text-transform:uppercase; font-weight:600;">Material Received By</div>
            <div style="font-weight:500; color:var(--text-main); margin-top:3px;" id="vReceivedBy">-</div>
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
            <div style="background: #fff7ed; padding: 8px; border-radius: 4px; border: 1px solid #fed7aa;">
              <div style="font-size: 0.68rem; color: #ea580c; font-weight: 600;">REWORK QTY</div>
              <div style="font-size: 1rem; font-weight: 700; color: #ea580c;" id="vRejectedQty">0</div>
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

  <!-- =========================================================
       Left Slide-in Process Workflow Drawer / Slider (Half Screen)
       ========================================================= -->
  <div id="processTimelineSliderOverlay" class="slider-overlay"></div>

  <div id="processTimelineSlider" class="slider-drawer">
    <!-- Slider Header: Target Qty, Finished Goods (FG), Total Rework Only -->
    <div class="slider-header">
      <div class="slider-header-wrap">
        <div class="slider-summary-grid">
          <div class="slider-stat-card stat-card-target">
            <div class="slider-stat-lbl">Target Qty</div>
            <div class="slider-stat-val" id="sldStatTarget">0 NOS</div>
          </div>
          <div class="slider-stat-card stat-card-fg">
            <div class="slider-stat-lbl">Finished Goods (FG)</div>
            <div class="slider-stat-val val-fg" id="sldStatPassed">0 NOS</div>
          </div>
          <div class="slider-stat-card stat-card-rej" style="border-left: 3px solid #ea580c; background: linear-gradient(180deg, #ffffff 0%, #fff7ed 100%);">
            <div class="slider-stat-lbl" style="color: #ea580c;">Total Rework</div>
            <div class="slider-stat-val val-rej" id="sldStatRejected" style="color: #ea580c;">0 NOS</div>
          </div>
        </div>
        <button type="button" class="slider-close-btn" id="btnCloseSlider" title="Close">&times;</button>
      </div>
    </div>

    <!-- Slider Sub-Header with MIP, Part, and Entry Date -->
    <div class="slider-meta-bar" id="sldMetaBar" style="padding: 8px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 0.78rem; color: #64748b; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
      <div>MIP: <strong style="color: #0f172a;" id="sldMetaMip">-</strong> &bull; <span id="sldMetaPart" style="font-weight: 600; color: #334155;">-</span></div>
      <div style="display: flex; align-items: center; gap: 4px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        <span>Entry Date: <strong style="color: #0f172a;" id="sldMetaDate">-</strong></span>
      </div>
    </div>

    <!-- Slider Body: Vertical Line Timeline -->
    <div class="slider-body" id="sldTimelineBody">
      <div class="timeline-wrap">
        <div class="timeline-track-line"></div>
        <div id="sldTimelineStepsContainer">
          <!-- Populated dynamically via JS -->
        </div>
      </div>
    </div>

    <!-- Slider Footer -->
    <div class="slider-footer">
      <button type="button" class="btn-secondary" id="btnSliderPrintSlip" style="padding: 7px 14px; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
        Print Slip (A4)
      </button>

      <div style="display: flex; gap: 8px;">
        <button type="button" class="btn-secondary" id="btnCloseSliderFooter">Close</button>
        <a href="#" id="btnSliderOpenWorkflow" class="btn-primary" style="text-decoration: none; padding: 7px 16px; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
          <span>Open Full Process Workspace</span>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14"></path><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
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
      const clean = dStr.toString().trim().split('T')[0].split(' ')[0];
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
      const filterStatus = document.getElementById('filterStatus');

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

      // Disable mouse wheel scroll changing number input values
      document.addEventListener('wheel', () => {
        if (document.activeElement && document.activeElement.type === 'number') {
          document.activeElement.blur();
        }
      });

      // Filtering & Search (Shift removed)
      function applyFilters() {
        const query = prdSearch.value.toLowerCase().trim();
        const selectedStatus = filterStatus.value.trim();

        const rows = prdTableBody.querySelectorAll('tr:not(#emptyTableRow)');
        rows.forEach(r => {
          const text = r.textContent.toLowerCase();
          const rStatus = r.getAttribute('data-status') || '';

          const matchesQuery = !query || text.includes(query);
          const matchesStatus = !selectedStatus || rStatus.toLowerCase() === selectedStatus.toLowerCase();

          r.style.display = (matchesQuery && matchesStatus) ? '' : 'none';
        });
      }

      prdSearch.addEventListener('input', applyFilters);
      filterStatus.addEventListener('change', applyFilters);

      // Modal open & close
      // Available Parts Catalog with Child Parts (BOM) & Processes for View Details
      const partsCatalog = <?php 
        $catalog = [];
        foreach ($partList as $p) {
          $catalog[$p['part_code']] = $p;
        }
        echo json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); 
      ?>;

      const masterProcessList = <?php echo json_encode($processList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

      function getPartStagesForJs(pCode) {
        if (pCode && partsCatalog[pCode] && partsCatalog[pCode].processes) {
          try {
            let pr = partsCatalog[pCode].processes;
            if (typeof pr === 'string') pr = JSON.parse(pr);
            if (Array.isArray(pr) && pr.length > 0) {
              return pr.map((p, idx) => ({
                code: p.code || String(idx + 1).padStart(3, '0'),
                name: p.name || ('Stage ' + (idx + 1)),
                remarks: p.remarks || ''
              }));
            }
          } catch(e) {}
        }
        return masterProcessList.map((pm, idx) => ({
          code: pm.process_code || String(idx + 1).padStart(3, '0'),
          name: pm.process_name || ('Stage ' + (idx + 1)),
          remarks: pm.remarks || ''
        }));
      }

      function populateRow(tr, data) {
        tr.setAttribute('data-id', data.id);
        tr.setAttribute('data-mip_no', data.mip_no || '');
        tr.setAttribute('data-customer_name', data.customer_name || '');
        tr.setAttribute('data-customer_code', data.customer_code || '');
        tr.setAttribute('data-so_number', data.so_number || '');
        tr.setAttribute('data-work_order', data.work_order || '');
        tr.setAttribute('data-part_code', data.part_code);
        tr.setAttribute('data-part_name', data.part_name);
        tr.setAttribute('data-department', data.department || '');
        tr.setAttribute('data-received_by', data.received_by || '');
        tr.setAttribute('data-process_name', data.process_name);
        const tgt = parseFloat(data.target_qty || 0);
        const ok = parseFloat(data.ok_qty || 0);
        const rej = parseFloat(data.rejected_qty || 0);
        const rw = parseFloat(data.rework_qty || 0);
        const st = (data.status || '').toLowerCase();
        const isRowComplete = (st === 'completed' || st === 'complete')
          || (tgt > 0 && (ok + rej) >= tgt)
          || (tgt > 0 && (ok + rej + rw) >= tgt);

        tr.setAttribute('data-target_qty', data.target_qty || 0);
        tr.setAttribute('data-ok_qty', data.ok_qty || 0);
        tr.setAttribute('data-rejected_qty', data.rejected_qty || 0);
        tr.setAttribute('data-rework_qty', data.rework_qty || 0);
        tr.setAttribute('data-is_rework', data.is_rework ? '1' : '0');
        tr.setAttribute('data-operator_name', data.operator_name || '');
        tr.setAttribute('data-uom', data.uom || 'PCS');
        tr.setAttribute('data-status', isRowComplete ? 'Completed' : (data.status || 'In Progress'));
        tr.setAttribute('data-created_at', data.created_at ? data.created_at.substring(0, 10) : new Date().toISOString().substring(0, 10));

        const rejCell = parseFloat(data.rejected_qty || 0) > 0
          ? `<strong style="color: #ea580c;">${formatNumber(data.rejected_qty)}</strong>`
          : `<span style="color: #94a3b8;">-</span>`;

        const recByHtml = data.received_by ? `<br><span style="font-size: 0.72rem; color: var(--text-sub);">Rec: ${escapeHtml(data.received_by)}</span>` : '';
        const createDateStr = data.created_at ? formatDateDMY(data.created_at) : formatDateDMY(new Date().toISOString());
        const partStages = getPartStagesForJs(data.part_code);

        const statusBadgeHtml = isRowComplete
          ? `<span class="tag tag-completed" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-size: 0.76rem; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;"><span style="width: 6px; height: 6px; border-radius: 50%; background: #16a34a;"></span>Complete</span>`
          : `<span class="tag tag-in-progress" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-size: 0.76rem; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;"><span style="width: 6px; height: 6px; border-radius: 50%; background: #2563eb;"></span>Progress</span>`;

        tr.innerHTML = `
          <td style="color: var(--text-sub); font-weight: 600; text-align: center;">1</td>
          <td style="white-space: nowrap; text-align: center; font-size: 0.85rem; font-weight: 600; color: #475569;">
            ${escapeHtml(createDateStr)}
          </td>
          <td style="white-space: nowrap;">
            <strong style="color: #1e293b;">${escapeHtml(data.mip_no || '-')}</strong>
            ${data.is_rework ? '<span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>' : ''}
          </td>
          <td style="white-space: nowrap;">
            <span style="font-weight: 500; color: #334155;">${escapeHtml(data.customer_name || '-')}</span>
          </td>
          <td style="white-space: nowrap;">
            <strong>${escapeHtml(data.part_name || data.part_code || '-')}</strong>
          </td>
          <td style="text-align: center; font-variant-numeric: tabular-nums; font-weight: 600; color: #1e293b;">
            ${formatNumber(data.target_qty)} <span style="font-size: 0.76rem; color: #64748b; font-weight: 500;">${escapeHtml(data.uom || 'PCS')}</span>
          </td>
          <td style="text-align: center; font-variant-numeric: tabular-nums; font-weight: 700; color: #16a34a;">
            ${formatNumber(data.ok_qty || 0)} <span style="font-size: 0.76rem; color: #059669; font-weight: 500;">${escapeHtml(data.uom || 'PCS')}</span>
          </td>
          <td style="text-align: center; white-space: nowrap;">
            ${statusBadgeHtml}
          </td>
          <td style="text-align: center;">
            <div style="display: flex; gap: 5px; justify-content: center; align-items: center;">
              <button type="button" class="btn-view" title="View Details">View</button>
              <a href="process.php?id=${encodeURIComponent(data.id)}&mip=${encodeURIComponent(data.mip_no || '')}" 
                 class="btn-process" 
                 style="text-decoration: none; padding: 4px 10px; border-radius: 4px; font-size: 0.78rem; font-weight: 600; background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; display: inline-flex; align-items: center; gap: 4px;"
                 title="Open Process Workflow for this Part">
                Process
              </a>
              <button type="button" class="btn-delete" title="Delete Entry">Delete</button>
            </div>
          </td>
        `;
      }

      // View Modal
      function getRowData(row) {
        return {
          id: row.getAttribute('data-id') || '',
          mipNo: row.getAttribute('data-mip_no') || '',
          customerName: row.getAttribute('data-customer_name') || '',
          customerCode: row.getAttribute('data-customer_code') || '',
          soNumber: row.getAttribute('data-so_number') || '',
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
          reworkQty: parseFloat(row.getAttribute('data-rework_qty') || 0),
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

        const stLower = (data.status || '').toLowerCase();
        const isComp = (stLower === 'completed' || stLower === 'complete')
          || (data.targetQty > 0 && (data.okQty + data.rejectedQty) >= data.targetQty)
          || (data.targetQty > 0 && (data.okQty + data.rejectedQty + (data.reworkQty || 0)) >= data.targetQty);

        const statusTag = isComp
          ? '<span class="tag tag-completed">Completed</span>'
          : (stLower === 'in progress' || stLower === 'progress'
              ? '<span class="tag tag-in-progress">In Progress</span>' 
              : '<span class="tag" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a;">On Hold</span>');
        document.getElementById('vStatusTag').innerHTML = statusTag;

        document.getElementById('vPartDesc').textContent = `${data.partCode} - ${data.partName}`;
        document.getElementById('vWorkOrder').textContent = `${data.mipNo ? data.mipNo : '-'}${data.soNumber ? ' (SO: ' + data.soNumber + ')' : (data.workOrder ? ' (' + data.workOrder + ')' : '')}`;
        if (document.getElementById('vCustomer')) {
          document.getElementById('vCustomer').textContent = data.customerName || '-';
        }
        document.getElementById('vProcessMachine').textContent = data.processName;
        document.getElementById('vPersonnel').textContent = data.operatorName || '-';

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

      // =========================================================
      // Left Slide-in Process Workflow Slider (Half Screen)
      // =========================================================
      function closeProcessSlider() {
        const overlay = document.getElementById('processTimelineSliderOverlay');
        const drawer = document.getElementById('processTimelineSlider');
        if (overlay) overlay.classList.remove('active');
        if (drawer) drawer.classList.remove('active');
        document.body.style.overflow = '';
      }

      const btnCloseSlider = document.getElementById('btnCloseSlider');
      const btnCloseSliderFooter = document.getElementById('btnCloseSliderFooter');
      const sliderOverlay = document.getElementById('processTimelineSliderOverlay');
      const btnSliderPrintSlip = document.getElementById('btnSliderPrintSlip');

      if (btnCloseSlider) btnCloseSlider.addEventListener('click', closeProcessSlider);
      if (btnCloseSliderFooter) btnCloseSliderFooter.addEventListener('click', closeProcessSlider);
      if (sliderOverlay) sliderOverlay.addEventListener('click', closeProcessSlider);

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          const drawer = document.getElementById('processTimelineSlider');
          if (drawer && drawer.classList.contains('active')) {
            closeProcessSlider();
          }
        }
      });

      if (btnSliderPrintSlip) {
        btnSliderPrintSlip.addEventListener('click', () => {
          if (currentViewingData) {
            printProductionSlip(currentViewingData);
          }
        });
      }

      async function openProcessSlider(rowData) {
        currentViewingData = rowData;
        const mip = rowData.mipNo || '';
        const id = rowData.id || '';
        const partName = rowData.partName || rowData.partCode || 'Part Workflow';
        const targetQty = rowData.targetQty || 0;
        const uom = rowData.uom || 'PCS';
        const customer = rowData.customerName || '-';

        // Update Slider Top KPI Elements (Target, FG Passed, Rejections)
        const elStatTarget = document.getElementById('sldStatTarget');
        const elStatPassed = document.getElementById('sldStatPassed');
        const elStatRejected = document.getElementById('sldStatRejected');

        if (elStatTarget) elStatTarget.textContent = `${targetQty} ${uom}`;
        if (elStatPassed) elStatPassed.textContent = `-`;
        if (elStatRejected) elStatRejected.textContent = `-`;

        // Update Slider Sub-Header Meta
        const elMetaMip = document.getElementById('sldMetaMip');
        const elMetaPart = document.getElementById('sldMetaPart');
        const isRw = Boolean(rowData.isRework || (row ? (row.getAttribute('data-is_rework') === '1') : false));
        if (elMetaMip) {
          elMetaMip.innerHTML = (escapeHtml(mip || '-')) + (isRw ? ' <span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>' : '');
        }
        if (elMetaPart) elMetaPart.textContent = `${rowData.partCode || ''} ${rowData.partName ? '- ' + rowData.partName : ''}`.trim() || partName;
        if (elMetaDate) elMetaDate.textContent = rowData.createdAt ? formatDateDMY(rowData.createdAt) : '-';

        // Direct link to process.php
        const openWfBtn = document.getElementById('btnSliderOpenWorkflow');
        if (openWfBtn) {
          openWfBtn.href = `process.php?id=${encodeURIComponent(id)}&mip=${encodeURIComponent(mip)}`;
        }

        // Show Skeleton / Loading State
        const container = document.getElementById('sldTimelineStepsContainer');
        if (container) {
          container.innerHTML = `
            <div style="padding: 40px 20px; text-align: center; color: #64748b;">
              <div style="display: inline-block; width: 34px; height: 34px; border: 3px solid #e2e8f0; border-top-color: #2563eb; border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
              <div style="margin-top: 12px; font-weight: 700; font-size: 0.90rem; color: #1e293b;">Loading Workflow Progress &amp; Breakdown...</div>
              <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 4px;">Calculating live stage-by-stage pass &amp; rework percentages</div>
            </div>
          `;
        }

        // Slide Drawer in from Right
        const overlay = document.getElementById('processTimelineSliderOverlay');
        const drawer = document.getElementById('processTimelineSlider');
        if (overlay) overlay.classList.add('active');
        if (drawer) drawer.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Fetch Detailed Breakdown from API
        try {
          const res = await fetch(`api/process_stage.php?entry_id=${encodeURIComponent(id)}&mip_no=${encodeURIComponent(mip)}`);
          const resJson = await res.json();

          if (resJson.entry && resJson.entry.created_at_formatted && elMetaDate) {
            elMetaDate.textContent = resJson.entry.created_at_formatted;
          }

          if (resJson.success && Array.isArray(resJson.stages) && resJson.stages.length > 0) {
            renderProcessTimelineSteps(resJson.stages, resJson.overall, uom);
          } else {
            if (container) {
              container.innerHTML = `
                <div style="padding: 30px 20px; text-align: center; color: #64748b; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 8px;">
                  <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">No process stages logged yet</div>
                  <div style="font-size: 0.84rem;">Click "Open Full Process Workspace" below to begin recording process progressions.</div>
                </div>
              `;
            }
          }
        } catch (err) {
          if (container) {
            container.innerHTML = `
              <div style="padding: 24px; text-align: center; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; font-size: 0.85rem;">
                Failed to load process progress breakdown. Please try again or open the Process Workspace.
              </div>
            `;
          }
        }
      }

      // =========================================================
      // Donut Pie Chart Math & SVG Arc Generator (Matches User Mockup)
      // =========================================================
      function polarToCartesian(cx, cy, r, angleInDegrees) {
        const rad = (angleInDegrees - 90) * Math.PI / 180.0;
        return {
          x: cx + (r * Math.cos(rad)),
          y: cy + (r * Math.sin(rad))
        };
      }

      function describeDonutArc(cx, cy, rOuter, rInner, startAngle, endAngle) {
        const start = polarToCartesian(cx, cy, rOuter, startAngle);
        const end = polarToCartesian(cx, cy, rOuter, endAngle);
        const largeArc = (endAngle - startAngle) > 180 ? 1 : 0;

        const innerStart = polarToCartesian(cx, cy, rInner, startAngle);
        const innerEnd = polarToCartesian(cx, cy, rInner, endAngle);

        return [
          'M', start.x.toFixed(2), start.y.toFixed(2),
          'A', rOuter.toFixed(2), rOuter.toFixed(2), 0, largeArc, 1, end.x.toFixed(2), end.y.toFixed(2),
          'L', innerEnd.x.toFixed(2), innerEnd.y.toFixed(2),
          'A', rInner.toFixed(2), rInner.toFixed(2), 0, largeArc, 0, innerStart.x.toFixed(2), innerStart.y.toFixed(2),
          'Z'
        ].join(' ');
      }

      function renderDonutPieSvg(total, pass, rej, rem = 0, centerLabel = 'TOTAL QTY', size = 120) {
        total = parseFloat(total) || 0;
        pass = parseFloat(pass) || 0;
        rej = parseFloat(rej) || 0;
        rem = parseFloat(rem) || 0;

        const cx = size / 2;
        const cy = size / 2;
        const rOuter = size * 0.44;
        const rInner = size * 0.30;
        const rMid = (rOuter + rInner) / 2;
        const strokeWidth = rOuter - rInner;

        let paths = '';

        if (total <= 0) {
          paths = `<circle cx="${cx}" cy="${cy}" r="${rMid.toFixed(2)}" fill="none" stroke="#e2e8f0" stroke-width="${strokeWidth.toFixed(2)}" />`;
        } else {
          const passPct = (pass / total) * 100;
          const rejPct = (rej / total) * 100;
          const remPct = Math.max(0, 100 - passPct - rejPct);

          const segments = [];
          if (pass > 0) segments.push({ pct: passPct, color: '#2563eb', label: 'Passed' });
          if (rej > 0) segments.push({ pct: rejPct, color: '#ea580c', label: 'Rework' });
          if (remPct > 0.05) segments.push({ pct: remPct, color: '#cbd5e1', label: 'Pending' });

          if (segments.length === 0) {
            paths = `<circle cx="${cx}" cy="${cy}" r="${rMid.toFixed(2)}" fill="none" stroke="#e2e8f0" stroke-width="${strokeWidth.toFixed(2)}" />`;
          } else if (segments.length === 1) {
            paths = `<circle cx="${cx}" cy="${cy}" r="${rMid.toFixed(2)}" fill="none" stroke="${segments[0].color}" stroke-width="${strokeWidth.toFixed(2)}" />`;
          } else {
            const gap = 3.5; // Gap degrees between segments (matches user mockup)
            let currAngle = 0;
            segments.forEach(seg => {
              const sweep = (seg.pct / 100) * 360;
              const actualSweep = Math.max(0.6, sweep - gap);
              const startA = currAngle + (gap / 2);
              const endA = startA + actualSweep;
              currAngle += sweep;

              const d = describeDonutArc(cx, cy, rOuter, rInner, startA, endA);
              paths += `<path d="${d}" fill="${seg.color}" />`;
            });
          }
        }

        const displayTotal = total % 1 === 0 ? parseInt(total) : total.toFixed(1);
        const centerText = `
          <text x="${cx}" y="${cy - 3}" text-anchor="middle" dominant-baseline="central" font-size="20" font-weight="800" fill="#0f172a" font-family="system-ui, -apple-system, sans-serif">${displayTotal}</text>
          <text x="${cx}" y="${cy + 13}" text-anchor="middle" dominant-baseline="central" font-size="7.5" font-weight="700" letter-spacing="0.06em" fill="#64748b" font-family="system-ui, -apple-system, sans-serif">${centerLabel}</text>
        `;

        return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" style="display:block; flex-shrink:0;">${paths}${centerText}</svg>`;
      }

      function renderProcessTimelineSteps(stages, overall, uom) {
        const container = document.getElementById('sldTimelineStepsContainer');
        if (!container) return;
        container.innerHTML = '';

        if (overall) {
          const finalFg = overall.final_fg_qty !== undefined ? overall.final_fg_qty : 0;
          const totalRej = overall.total_rejected !== undefined ? overall.total_rejected : 0;
          const elPassed = document.getElementById('sldStatPassed');
          const elRejected = document.getElementById('sldStatRejected');
          if (elPassed) elPassed.textContent = `${finalFg} ${uom}`;
          if (elRejected) elRejected.textContent = `${totalRej} ${uom}`;
        }

        stages.forEach((st) => {
          const passPct = parseFloat(st.pass_percent || 0);
          const rejPct = parseFloat(st.rej_percent || 0);
          const remPct = parseFloat(st.rem_percent || 0);

          let nodeClass = '';
          let nodeIcon = `${st.order}`;
          let statusBadgeClass = 'step-status-waiting';

          if (st.status === 'Completed') {
            nodeClass = 'completed';
            nodeIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>';
            statusBadgeClass = 'step-status-completed';
          } else if (st.status === 'In Progress') {
            nodeClass = 'in-progress';
            statusBadgeClass = 'step-status-in-progress';
          } else if (st.status === 'Ready') {
            nodeClass = 'ready';
            statusBadgeClass = 'step-status-ready';
          }

          const stepDiv = document.createElement('div');
          stepDiv.className = 'timeline-step';

          stepDiv.innerHTML = `
            <div class="step-node ${nodeClass}">
              ${nodeIcon}
            </div>
            <div class="step-card ${st.status === 'In Progress' ? 'active-stage' : ''}">
              <div class="step-card-header">
                <div class="step-proc-name">
                  <span class="step-proc-order">#${st.order}</span>
                  <span>${escapeHtml(st.name)}</span>
                  ${st.is_final ? '<span style="font-size: 0.68rem; font-weight: 700; color: #16a34a; background: #dcfce7; padding: 1px 6px; border-radius: 4px; border: 1px solid #bbf7d0;">FINAL STAGE</span>' : ''}
                </div>
                <span class="step-status-tag ${statusBadgeClass}">${st.status}</span>
              </div>

              <!-- Simple Clean Breakdown (Non-congested) -->
              <div class="simple-breakdown-row">
                <span class="simple-stat-pill simple-stat-pass">
                  <span class="simple-stat-dot" style="background: #2563eb;"></span>
                  <span class="simple-stat-label">Passed:</span>
                  <strong>${st.pass_qty} ${uom}</strong>
                  <span class="simple-stat-pct">${passPct}%</span>
                </span>

                <span class="simple-stat-pill simple-stat-rej">
                  <span class="simple-stat-dot" style="background: #ea580c;"></span>
                  <span class="simple-stat-label">Rework:</span>
                  <strong>${st.rejected_qty} ${uom}</strong>
                  <span class="simple-stat-pct">${rejPct}%</span>
                </span>

                ${st.remaining_qty > 0 ? `
                  <span class="simple-stat-pill simple-stat-pending">
                    <span class="simple-stat-dot" style="background: #94a3b8;"></span>
                    <span class="simple-stat-label">Pending:</span>
                    <strong>${st.remaining_qty} ${uom}</strong>
                    <span class="simple-stat-pct">${remPct}%</span>
                  </span>
                ` : ''}
              </div>

              <!-- Sleek Multi-segment Progress Bar -->
              <div class="simple-progress-bar" title="Passed: ${passPct}% | Rework: ${rejPct}% | Pending: ${remPct}%">
                <div class="simple-bar-seg pass" style="width: ${passPct}%;"></div>
                <div class="simple-bar-seg rej" style="width: ${rejPct}%;"></div>
                <div class="simple-bar-seg rem" style="width: ${remPct}%;"></div>
              </div>

              ${(st.operator || st.date_formatted || st.date) ? `
                <div class="step-operator-meta">
                  ${st.operator ? `
                    <div class="step-meta-item">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                      <span>Operator: <strong>${escapeHtml(st.operator)}</strong></span>
                    </div>
                  ` : '<div></div>'}
                  ${(st.date_formatted || st.date) ? `
                    <div class="step-meta-item step-date-item" title="${escapeHtml(st.date || '')}">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                      <span>Date: <strong>${escapeHtml(st.date_formatted || formatDateDMY(st.date))}</strong>${st.time_formatted ? ` <span style="color: #94a3b8; font-weight: normal; font-size: 0.68rem;">(${escapeHtml(st.time_formatted)})</span>` : ''}</span>
                    </div>
                  ` : ''}
                </div>
              ` : ''}
            </div>
          `;

          container.appendChild(stepDiv);
        });
      }

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
                <div class="slip-date">Date: <strong>${escapeHtml(data.createdAt ? formatDateDMY(data.createdAt) : formatDateDMY(new Date().toISOString()))}</strong></div>
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
                <td class="lbl">SO Number:</td>
                <td class="val"><strong>${escapeHtml(data.soNumber || data.workOrder || '-')}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Stage / Process:</td>
                <td class="val"><strong>${escapeHtml(data.processName)}</strong></td>
                <td class="lbl">Customer:</td>
                <td class="val"><strong>${escapeHtml(data.customerName || '-')}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Material Received By:</td>
                <td class="val" colspan="3"><strong>${escapeHtml(data.receivedBy || '-')}</strong></td>
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
                  <th>Rework Qty</th>
                  <th>Total Inspected</th>
                  <th>Quality Yield %</th>
                  <th>Achievement %</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>${formatNumber(data.targetQty)} ${escapeHtml(data.uom)}</td>
                  <td style="color:#059669;">${formatNumber(data.okQty)} ${escapeHtml(data.uom)}</td>
                  <td style="color:${data.rejectedQty > 0 ? '#ea580c' : '#64748b'};">${formatNumber(data.rejectedQty)} ${escapeHtml(data.uom)}</td>
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
                  <div class="sig-line">Signature & Date: OK / Rework Verified</div>
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

        // View Details: Open Left Side Slider with Process Progress & Breakdown
        const viewBtn = e.target.closest('.btn-view');
        if (viewBtn) {
          e.preventDefault();
          e.stopPropagation();
          const rowData = getRowData(row);
          openProcessSlider(rowData);
          return;
        }

        // Print Slip (from View Modal only now)
        if (e.target.classList.contains('btn-print')) {
          const rowData = getRowData(row);
          printProductionSlip(rowData);
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

      // Document-level delegation for View button (handles static and dynamically created rows)
      document.addEventListener('click', (e) => {
        const viewBtn = e.target.closest('.btn-view');
        if (viewBtn) {
          const row = viewBtn.closest('tr');
          if (row && row.id !== 'emptyTableRow') {
            const rowData = getRowData(row);
            openProcessSlider(rowData);
          }
        }
      });
      window.openProcessSlider = openProcessSlider;



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
            const mipNo = pendingDeleteRow?.getAttribute('data-mip_no') || '';
            const delId = pendingDeleteRow?.getAttribute('data-id') || id || '';
            const delPart = pendingDeleteRow?.getAttribute('data-part_code') || '';

            // Clean localStorage & sessionStorage completely for this deleted entry and MIP
            try {
              [localStorage, sessionStorage].forEach(store => {
                if (mipNo) {
                  store.removeItem(`wip_wf_${encodeURIComponent(mipNo)}`);
                  store.removeItem(`wip_wf_${mipNo}`);
                }
                if (delId) {
                  store.removeItem(`wip_wf_${encodeURIComponent(mipNo)}_${delId}`);
                  store.removeItem(`wip_wf_${mipNo}_${delId}`);
                }
                Object.keys(store).forEach(k => {
                  const matchMip = mipNo && (k.includes(encodeURIComponent(mipNo)) || k.includes(mipNo));
                  const matchId = delId && (k.includes(`_${delId}_`) || k.endsWith(`_${delId}`));
                  const matchPart = delPart && (k.includes(encodeURIComponent(delPart)) || k.includes(delPart));
                  if ((k.startsWith('wip_wf_') || k.startsWith('wip_run_logs_')) && (matchMip || matchId || matchPart)) {
                    store.removeItem(k);
                  }
                });
              });
            } catch (e) {}

            pendingDeleteRow.remove();
            showToast('Production entry deleted from database.', 'success');

            const remainingRows = prdTableBody.querySelectorAll('tr:not(#emptyTableRow)');
            if (remainingRows.length === 0) {
              prdTableBody.innerHTML = `
                <tr id="emptyTableRow">
                  <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--text-sub);">
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
          const isRework = (r.getAttribute('data-is_rework') || '0') === '1';
          const pDate = r.getAttribute('data-created_at') || '';

          sumOk += ok;
          if (!isRework) {
            sumRej += rej;
          }
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

      // Search & Status Filter (Live Filter for Table)
      const prdSearchInput = document.getElementById('prdSearch');
      const filterStatusSelect = document.getElementById('filterStatus');

      function filterProductionTable() {
        const query = (prdSearchInput ? prdSearchInput.value : '').toLowerCase().trim();
        const selectedStatus = (filterStatusSelect ? filterStatusSelect.value : '').toLowerCase().trim();
        const rows = prdTableBody.querySelectorAll('tr:not(#emptyTableRow)');
        let visibleCount = 0;

        rows.forEach(r => {
          const rowText = r.textContent.toLowerCase();
          const rowStatus = (r.getAttribute('data-status') || '').toLowerCase();

          const matchesQuery = !query || rowText.includes(query);
          let matchesStatus = true;
          if (selectedStatus) {
            if (selectedStatus === 'complete' || selectedStatus === 'completed') {
              matchesStatus = (rowStatus === 'completed' || rowStatus === 'complete');
            } else if (selectedStatus === 'progress' || selectedStatus === 'in progress') {
              matchesStatus = (rowStatus === 'in progress' || rowStatus === 'progress');
            } else {
              matchesStatus = rowStatus.includes(selectedStatus);
            }
          }

          if (matchesQuery && matchesStatus) {
            r.style.display = '';
            visibleCount++;
          } else {
            r.style.display = 'none';
          }
        });

        let emptyFiltered = document.getElementById('emptyFilteredRow');
        if (visibleCount === 0 && rows.length > 0) {
          if (!emptyFiltered) {
            emptyFiltered = document.createElement('tr');
            emptyFiltered.id = 'emptyFilteredRow';
            emptyFiltered.innerHTML = `<td colspan="8" style="text-align: center; padding: 30px; color: #94a3b8; font-style: italic;">No matching production entries found for the selected filter.</td>`;
            prdTableBody.appendChild(emptyFiltered);
          }
        } else if (emptyFiltered) {
          emptyFiltered.remove();
        }
      }

      if (prdSearchInput) prdSearchInput.addEventListener('input', filterProductionTable);
      if (filterStatusSelect) filterStatusSelect.addEventListener('change', filterProductionTable);

    });
  </script>
</body>
</html>
