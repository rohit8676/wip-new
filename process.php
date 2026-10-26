<?php
/**
 * WIP Management Portal - Dedicated Part-Based Process Workflow Page
 * Single-page workflow for all processes of a part (Part-based, NOT child-based).
 * Passed amount of each process automatically becomes Available Quantity for the next process.
 * Blueprint layout: # | Process Name | Available Qty | Pass Qty | Rejected Qty | Remaining Qty | Action
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/rbac.php';

requirePermission('production', 'update');

$entryId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$mipNo = isset($_GET['mip']) ? trim($_GET['mip']) : '';

require_once __DIR__ . '/config/db.php';
$entryData = null;
$partData = null;
$mipData = null;

$issuedQty = 20;
$partCode = 'VE003';
$partName = 'Barcode Label';
$customerName = 'Tata Motors';
$soNumber = '';
$currentMipNo = $mipNo ?: '';
$entryDbId = 0;
$entryDbCreatedAt = '';

// Login User & Role
$loginUserName = !empty($currentUser['name']) && $currentUser['name'] !== 'User' 
    ? $currentUser['name'] 
    : ($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'Operator'));

$loginUserRole = !empty($currentUser['role']) && $currentUser['role'] !== 'User' 
    ? $currentUser['role'] 
    : ($_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'Operator'));

$masterProcesses = [];
$partProcesses = [];
$outputVariants = [];
$partChildParts = [];
$existingStageLogs = [];

try {
    $pdo = getDBConnection();

    // 1. Fetch active processes from process_master as baseline
    $stMaster = $pdo->query("SELECT id, process_code, process_name, remarks FROM process_master WHERE status = 'Active' ORDER BY id ASC");
    if ($stMaster) {
        $masterProcesses = $stMaster->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2. Fetch production_entry
    if ($entryId > 0) {
        $stEntry = $pdo->prepare("SELECT * FROM production_entry WHERE id = ?");
        $stEntry->execute([$entryId]);
        $entryData = $stEntry->fetch(PDO::FETCH_ASSOC);
        if ($entryData) {
            $entryDbId = intval($entryData['id']);
            $entryDbCreatedAt = $entryData['created_at'] ?? '';
            if (empty($mipNo) && !empty($entryData['mip_no'])) {
                $mipNo = $entryData['mip_no'];
            }
        }
    }

    // 3. Query material_issue if mipNo provided
    if ($mipNo !== '') {
        $st = $pdo->prepare("SELECT * FROM material_issue WHERE issue_no = ?");
        $st->execute([$mipNo]);
        $mipData = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$mipData && $entryData && !empty($entryData['mip_no'])) {
        $st = $pdo->prepare("SELECT * FROM material_issue WHERE issue_no = ?");
        $st->execute([$entryData['mip_no']]);
        $mipData = $st->fetch(PDO::FETCH_ASSOC);
    }

    // 4. Fallback lookup of production_entry by mip_no if not loaded by id
    if (!$entryData && !empty($mipNo)) {
        $stEntryFallback = $pdo->prepare("SELECT TOP 1 * FROM production_entry WHERE mip_no = ? ORDER BY id DESC");
        $stEntryFallback->execute([$mipNo]);
        $entryData = $stEntryFallback->fetch(PDO::FETCH_ASSOC);
        if ($entryData) {
            $entryDbId = intval($entryData['id']);
            $entryDbCreatedAt = $entryData['created_at'] ?? '';
        }
    }

    // 5. Populate variables:
    // IMPORTANT: production_entry is the primary record for a production batch run.
    // Its target_qty represents the planned quantity for this run (e.g. 4 NOS for Rework, 20 NOS for regular).
    // material_issue only provides master MIP details and fallback.
    $isReworkEntry = false;
    if ($entryData) {
        $entryDbId = intval($entryData['id']);
        $entryDbCreatedAt = $entryData['created_at'] ?? '';
        $currentMipNo = $entryData['mip_no'] ?? ($mipNo ?: ($mipData['issue_no'] ?? 'MIP-001'));
        if (!empty($entryData['part_code'])) $partCode = $entryData['part_code'];
        if (!empty($entryData['part_name'])) $partName = $entryData['part_name'];
        if (!empty($entryData['customer_name'])) $customerName = $entryData['customer_name'];
        if (isset($entryData['target_qty']) && floatval($entryData['target_qty']) > 0) {
            $issuedQty = floatval($entryData['target_qty']);
        }
        $soNumber = $entryData['so_number'] ?? ($entryData['work_order'] ?? ($mipData['so_number'] ?? ''));
        $isReworkEntry = (!empty($entryData['is_rework']) || (($entryData['entry_type'] ?? '') === 'Rework'));

        // Supplement missing metadata from material_issue if needed
        if ($mipData) {
            if (empty($partCode) && !empty($mipData['part_code'])) $partCode = $mipData['part_code'];
            if (empty($partName) && !empty($mipData['part_name'])) $partName = $mipData['part_name'];
            if (empty($customerName) && !empty($mipData['customer_name'])) $customerName = $mipData['customer_name'];
            if (empty($soNumber) && !empty($mipData['so_number'])) $soNumber = $mipData['so_number'];
        }
    } elseif ($mipData) {
        $currentMipNo = $mipData['issue_no'];
        if (!empty($mipData['part_code'])) $partCode = $mipData['part_code'];
        if (!empty($mipData['part_name'])) $partName = $mipData['part_name'];
        if (!empty($mipData['customer_name'])) $customerName = $mipData['customer_name'];
        if (isset($mipData['issued_qty']) && floatval($mipData['issued_qty']) > 0) {
            $issuedQty = floatval($mipData['issued_qty']);
        }
        if (!empty($mipData['so_number'])) $soNumber = $mipData['so_number'];
    } else {
        $currentMipNo = $mipNo ?: 'MIP-001';
    }

    $targetQty = floatval($issuedQty);

    // 6. Query part_master for processes, BOM, output variants
    if ($partCode !== '') {
        $st = $pdo->prepare("SELECT * FROM part_master WHERE part_code = ?");
        $st->execute([$partCode]);
        $partData = $st->fetch(PDO::FETCH_ASSOC);
        if ($partData) {
            if (!empty($partData['part_name'])) $partName = $partData['part_name'];

            // Output variants
            if (!empty($partData['output_variants'])) {
                $parsedVars = json_decode($partData['output_variants'], true);
                if (is_array($parsedVars) && count($parsedVars) > 0) {
                    $outputVariants = $parsedVars;
                }
            }

            // Child parts BOM reference
            if (!empty($partData['child_parts'])) {
                $parsedCp = json_decode($partData['child_parts'], true);
                if (is_array($parsedCp) && count($parsedCp) > 0) {
                    foreach ($parsedCp as $cp) {
                        if (is_array($cp)) {
                            $partChildParts[] = [
                                'code' => $cp['code'] ?? ($cp['part_code'] ?? ''),
                                'name' => $cp['name'] ?? ($cp['part_name'] ?? ''),
                                'ratio' => floatval($cp['ratio'] ?? ($cp['qty'] ?? 1)),
                                'uom' => $cp['uom'] ?? 'NOS'
                            ];
                        }
                    }
                }
            }

            // Part specific production processes
            if (!empty($partData['processes'])) {
                $parsedProc = json_decode($partData['processes'], true);
                if (is_array($parsedProc) && count($parsedProc) > 0) {
                    foreach ($parsedProc as $pIdx => $proc) {
                        if (is_array($proc)) {
                            $partProcesses[] = [
                                'code' => $proc['code'] ?? str_pad($pIdx + 1, 3, '0', STR_PAD_LEFT),
                                'name' => $proc['name'] ?? ('Process ' . ($pIdx + 1)),
                                'remarks' => $proc['remarks'] ?? ''
                            ];
                        } elseif (is_string($proc) && trim($proc) !== '') {
                            $partProcesses[] = [
                                'code' => str_pad($pIdx + 1, 3, '0', STR_PAD_LEFT),
                                'name' => trim($proc),
                                'remarks' => ''
                            ];
                        }
                    }
                }
            }
        }
    }

    // Fallback to process_master if part_master had no specific processes
    if (empty($partProcesses) && !empty($masterProcesses)) {
        foreach ($masterProcesses as $mIdx => $mp) {
            $partProcesses[] = [
                'code' => $mp['process_code'] ?? str_pad($mIdx + 1, 3, '0', STR_PAD_LEFT),
                'name' => $mp['process_name'] ?? ('Process ' . ($mIdx + 1)),
                'remarks' => $mp['remarks'] ?? ''
            ];
        }
    }

    // Default 4 processes if still empty (matches standard workflow)
    if (empty($partProcesses)) {
        $partProcesses = [
            ['code' => '001', 'name' => 'Process 1', 'remarks' => 'Initial stage'],
            ['code' => '002', 'name' => 'Process 2', 'remarks' => 'Intermediate stage'],
            ['code' => '003', 'name' => 'Process 3', 'remarks' => 'Inspection stage'],
            ['code' => '004', 'name' => 'Process 4', 'remarks' => 'Finishing stage']
        ];
    }

    // 7. Load existing saved stage logs from production_stage_log
    if ($entryDbId > 0) {
        $stLogs = $pdo->prepare("SELECT * FROM production_stage_log WHERE production_entry_id = ? ORDER BY stage_order ASC, id ASC");
        $stLogs->execute([$entryDbId]);
        $existingStageLogs = $stLogs->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($currentMipNo)) {
        $stLogs = $pdo->prepare("SELECT * FROM production_stage_log WHERE mip_no = ? AND (production_entry_id IS NULL OR production_entry_id = 0) ORDER BY stage_order ASC, id ASC");
        $stLogs->execute([$currentMipNo]);
        $existingStageLogs = $stLogs->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Graceful fallback
}

$logsByOrder = [];
foreach ($existingStageLogs as $log) {
    $logsByOrder[intval($log['stage_order'])] = $log;
}

$totalStages = count($partProcesses);

// Build chained process records
$chainedProcesses = [];
$runningInput = floatval($targetQty);

foreach ($partProcesses as $idx => $p) {
    $order = $idx + 1;
    $isFinal = ($order === $totalStages);
    $savedLog = $logsByOrder[$order] ?? null;

    $cumPass = $savedLog ? floatval($savedLog['processed_qty'] ?? 0) : 0;
    $cumRej = $savedLog ? floatval($savedLog['rejected_qty'] ?? 0) : 0;
    $operator = ($savedLog && !empty($savedLog['operator_name'])) ? $savedLog['operator_name'] : $loginUserName;
    $hasSaved = ($savedLog && ($cumPass > 0 || $cumRej > 0));

    // Input to this stage: stage 1 receives targetQty, stage k receives cumPass from stage k-1
    $stageInput = ($idx === 0) ? floatval($targetQty) : $runningInput;

    // Available Qty at this stage is what entered minus what has already been passed or rejected
    $stageAvailable = max(0, $stageInput - ($cumPass + $cumRej));

    // Input fields for Pass Qty and Rejected Qty are empty by default after save
    $pass = null;
    $rej = null;

    // Running input for the next stage is whatever has passed from this stage
    $runningInput = $cumPass;

    $chainedProcesses[] = [
        'order' => $order,
        'code' => $p['code'],
        'name' => $p['name'],
        'remarks' => $p['remarks'] ?? '',
        'input_total' => $stageInput,
        'cum_pass' => $cumPass,
        'cum_rej' => $cumRej,
        'available' => $stageAvailable,
        'base_available' => $stageAvailable,
        'pass' => $pass,
        'rej' => $rej,
        'operator' => $operator,
        'is_final' => $isFinal,
        'variant_code' => $savedLog['variant_code'] ?? ($entryData['final_variant_code'] ?? ''),
        'variant_name' => $savedLog['variant_name'] ?? ($entryData['final_variant_name'] ?? ''),
        'variant_output_details' => $savedLog['variant_output_details'] ?? '',
        'has_saved' => $hasSaved
    ];
}

// Prepare initial variant breakdown for Finished Goods (FG) Goods Display
$initialVariantBreakdown = [];
foreach ($outputVariants as $v) {
    $vCode = $v['code'] ?? (is_string($v) ? $v : '');
    $vName = $v['name'] ?? (is_string($v) ? $v : '');
    if ($vCode !== '') {
        $initialVariantBreakdown[$vCode] = [
            'code' => $vCode,
            'name' => $vName ?: $vCode,
            'qty' => 0
        ];
    }
}

$finalLog = $logsByOrder[$totalStages] ?? null;
if ($finalLog && !empty($finalLog['variant_output_details'])) {
    $details = json_decode($finalLog['variant_output_details'], true);
    if (is_array($details)) {
        foreach ($details as $k => $val) {
            if (is_array($val) && isset($val['code'])) {
                $c = $val['code'];
                $q = floatval($val['qty'] ?? 0);
                if (isset($initialVariantBreakdown[$c])) {
                    $initialVariantBreakdown[$c]['qty'] = $q;
                } else {
                    $initialVariantBreakdown[$c] = ['code' => $c, 'name' => $val['name'] ?? $c, 'qty' => $q];
                }
            } elseif (is_numeric($val) && isset($initialVariantBreakdown[$k])) {
                $initialVariantBreakdown[$k]['qty'] = floatval($val);
            }
        }
    }
} elseif ($finalLog && floatval($finalLog['processed_qty'] ?? 0) > 0) {
    $savedVCode = $finalLog['variant_code'] ?? ($entryData['final_variant_code'] ?? '');
    $savedQty = floatval($finalLog['processed_qty'] ?? 0);
    if (!empty($savedVCode) && isset($initialVariantBreakdown[$savedVCode])) {
        $initialVariantBreakdown[$savedVCode]['qty'] = $savedQty;
    } elseif (!empty($savedVCode)) {
        $initialVariantBreakdown[$savedVCode] = [
            'code' => $savedVCode,
            'name' => $finalLog['variant_name'] ?? ($entryData['final_variant_name'] ?? $savedVCode),
            'qty' => $savedQty
        ];
    } elseif (!empty($initialVariantBreakdown)) {
        $firstKey = array_key_first($initialVariantBreakdown);
        $initialVariantBreakdown[$firstKey]['qty'] = $savedQty;
    }
}

// Fallback if part has no output variants configured
if (empty($initialVariantBreakdown)) {
    $totalFgOk = floatval($finalLog['processed_qty'] ?? ($entryData['ok_qty'] ?? 0));
    $initialVariantBreakdown['STD'] = [
        'code' => 'Standard FG',
        'name' => $partName,
        'qty' => $totalFgOk
    ];
}

// Check if order workflow is already completed and has rejections
$isCompletedInDb = false;
$totalRejInDb = 0;
$finalOkInDb = 0;

if (!empty($existingStageLogs)) {
    foreach ($existingStageLogs as $log) {
        $totalRejInDb += floatval($log['rejected_qty'] ?? 0);
        if (!empty($log['is_final_stage'])) {
            $finalOkInDb = floatval($log['processed_qty'] ?? 0);
        }
    }
    if (($finalOkInDb + $totalRejInDb) >= floatval($targetQty) && floatval($targetQty) > 0) {
        $isCompletedInDb = true;
    }
}
if (($entryData['status'] ?? '') === 'Completed') {
    $isCompletedInDb = true;
    if ($finalOkInDb == 0 && isset($entryData['ok_qty'])) {
        $finalOkInDb = floatval($entryData['ok_qty']);
    }
    if ($totalRejInDb == 0 && isset($entryData['rejected_qty'])) {
        $totalRejInDb = floatval($entryData['rejected_qty']);
    }
}

$pageTitle = 'Process Workflow - ' . htmlspecialchars($partName);
$activeMenu = 'production.php';
$headerBackUrl = 'production.php';
$headerBackText = 'Back to DPR Entries';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Production Process Workflow - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
    .layout-container, .main-content {
      overflow-x: clip;
    }
    .topbar {
      position: sticky;
      top: 0;
      z-index: 30;
      background: #ffffff;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }

    /* Part Summary Card */
    .part-summary-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 14px 20px;
      margin-bottom: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .part-summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 16px;
      align-items: center;
    }
    .summary-item {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .summary-lbl {
      font-size: 0.72rem;
      font-weight: 600;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .summary-val {
      font-size: 0.95rem;
      font-weight: 700;
      color: #0f172a;
    }

    /* Main Blueprint Process Table Card */
    .blueprint-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
      margin-bottom: 24px;
    }
    .blueprint-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 14px;
      margin-bottom: 16px;
      padding-bottom: 14px;
      border-bottom: 1px solid #e2e8f0;
    }
    .blueprint-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .blueprint-sub {
      font-size: 0.78rem;
      color: #64748b;
      margin-top: 3px;
    }

    /* Table Matching User Blueprint */
    .blueprint-table-wrap {
      overflow-x: auto;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
    }
    .blueprint-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.88rem;
      text-align: left;
    }
    .blueprint-table th {
      background: #dbe4ee; /* Soft cool blueprint header */
      color: #1e293b;
      font-weight: 700;
      padding: 10px 14px;
      border-bottom: 1px solid #94a3b8;
      border-right: 1px solid #cbd5e1;
      font-size: 0.85rem;
    }
    .blueprint-table th:last-child {
      border-right: none;
    }
    .blueprint-table td {
      padding: 8px 14px;
      border-bottom: 1px solid #e2e8f0;
      border-right: 1px solid #e2e8f0;
      vertical-align: middle;
      color: #1e293b;
    }
    .blueprint-table td:last-child {
      border-right: none;
    }
    .blueprint-table tr:hover {
      background-color: #f8fafc;
    }
    .blueprint-table tr.row-saved {
      background-color: #f0fdf4;
    }

    /* Table Input Fields */
    .bp-input {
      width: 100%;
      max-width: 120px;
      padding: 6px 10px;
      font-size: 0.90rem;
      font-weight: 600;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      text-align: center;
      color: #0f172a;
      background: #ffffff;
      outline: none;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .bp-input:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
    }
    .bp-input.input-error {
      border-color: #ef4444;
      background-color: #fef2f2;
    }
    .bp-input.input-rej {
      color: #ea580c;
    }
    .bp-select-variant {
      width: 100%;
      max-width: 160px;
      padding: 6px 8px;
      font-size: 0.84rem;
      font-weight: 600;
      border: 1px solid #cbd5e1;
      border-radius: 5px;
      color: #0f172a;
      background: #ffffff;
      outline: none;
      cursor: pointer;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .bp-select-variant:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
    }

    /* Remove counter / number spinner arrows from all number inputs */
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type=number] {
      -moz-appearance: textfield;
      appearance: textfield;
    }

    /* Badge & Values */
    .val-avail {
      font-weight: 700;
      color: #0f172a;
      font-size: 0.95rem;
    }
    .val-rem {
      font-weight: 600;
      color: #64748b;
      font-size: 0.92rem;
    }
    .val-rem.has-pending {
      color: #b45309;
      font-weight: 700;
    }

    /* Toast Notification */
    #toastContainer {
      position: fixed;
      bottom: 24px;
      right: 24px;
      z-index: 99999;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .toast {
      padding: 12px 18px;
      background: #0f172a;
      color: #ffffff;
      border-radius: 8px;
      font-size: 0.84rem;
      font-weight: 600;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
      display: flex;
      align-items: center;
      gap: 8px;
      animation: toastSlideUp 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .toast-success { background: #059669; }
    .toast-error { background: #dc2626; }
    @keyframes toastSlideUp {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
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

      <div class="content-body" style="padding: 20px 24px;">
        
        <!-- 1. Part & Work Order Details Card -->
        <div class="part-summary-card">
          <div class="part-summary-grid">
            <div class="summary-item">
              <span class="summary-lbl">MIP Number</span>
              <span class="summary-val" style="color: #2563eb; display: inline-flex; align-items: center; gap: 6px;">
                <?php echo htmlspecialchars($currentMipNo); ?>
                <?php if ($isReworkEntry): ?>
                  <span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="summary-item">
              <span class="summary-lbl">Customer</span>
              <span class="summary-val"><?php echo htmlspecialchars($customerName); ?></span>
            </div>
            <div class="summary-item">
              <span class="summary-lbl">Part Name &amp; Code</span>
              <span class="summary-val"><?php echo htmlspecialchars($partCode . ' - ' . $partName); ?></span>
            </div>
            <div class="summary-item">
              <span class="summary-lbl">Target Quantity</span>
              <span class="summary-val" style="color: <?php echo $isReworkEntry ? '#ea580c' : '#059669'; ?>; font-weight: 800; display: inline-flex; align-items: center; gap: 6px;">
                <?php echo floatval($targetQty); ?> NOS
                <?php if ($isReworkEntry): ?>
                  <span style="font-size: 0.74rem; font-weight: 700; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; padding: 1px 6px; border-radius: 4px;">(Rework Qty)</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="summary-item">
              <span class="summary-lbl">SO Number</span>
              <span class="summary-val"><?php echo htmlspecialchars($soNumber ?: '-'); ?></span>
            </div>
          </div>
        </div>

        <!-- Operator & Designation Controls (Matches User Blueprint) -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; display: flex; gap: 32px; flex-wrap: wrap; align-items: flex-start;">
          <div style="display: flex; flex-direction: column; gap: 6px; min-width: 240px; flex: 1; max-width: 340px;">
            <label for="inputGlobalOperator" style="font-size: 0.84rem; font-weight: 600; color: #334155;">Operator / Technician:</label>
            <input type="text" 
                   id="inputGlobalOperator" 
                   class="bp-input" 
                   style="width: 100%; max-width: 100%; text-align: left; padding: 7px 12px; font-size: 0.88rem; border: 1px solid #64748b; border-radius: 4px;" 
                   value="<?php echo htmlspecialchars($loginUserName); ?>" 
                   placeholder="Operator / Technician">
          </div>
          <div style="display: flex; flex-direction: column; gap: 6px; min-width: 240px; flex: 1; max-width: 340px;">
            <label for="inputGlobalDesignation" style="font-size: 0.84rem; font-weight: 600; color: #334155;">Designation</label>
            <input type="text" 
                   id="inputGlobalDesignation" 
                   class="bp-input" 
                   style="width: 100%; max-width: 100%; text-align: left; padding: 7px 12px; font-size: 0.88rem; border: 1px solid #64748b; border-radius: 4px;" 
                   value="<?php echo htmlspecialchars($loginUserRole); ?>" 
                   placeholder="Designation">
          </div>
        </div>

        <!-- 2. Main Blueprint Process Table Card -->
        <div class="blueprint-card">
          <div class="blueprint-header">
            <div>
              <div class="blueprint-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                Process Stages &amp; Quantity Progression
              </div>
              <div class="blueprint-sub">
                <?php if ($isReworkEntry): ?>
                  <span style="color: #ea580c; font-weight: 700;">⟳ Rework Progression:</span> Passed quantity of each process automatically advances as Available Quantity for the next stage.
                <?php else: ?>
                  Part-based workflow: Passed quantity of each process automatically becomes Available Quantity for the next process.
                <?php endif; ?>
              </div>
            </div>

            <div id="blueprintSummaryPill" style="font-size: 0.80rem; font-weight: 700; color: <?php echo $isReworkEntry ? '#c2410c' : '#059669'; ?>; background: <?php echo $isReworkEntry ? '#fff7ed' : '#ecfdf5'; ?>; padding: 5px 12px; border-radius: 20px; border: 1px solid <?php echo $isReworkEntry ? '#fed7aa' : '#a7f3d0'; ?>;">
              Target: <?php echo floatval($targetQty); ?> NOS <?php if ($isReworkEntry): ?>(Rework)<?php endif; ?>
            </div>
          </div>

          <!-- Blueprint Table -->
          <div class="blueprint-table-wrap">
            <table class="blueprint-table" id="blueprintProcessTable">
              <thead>
                <tr>
                  <th style="width: 45px; text-align: center;">#</th>
                  <th style="min-width: 180px;">Process Name</th>
                  <th style="width: 130px; text-align: center;">Available Qty</th>
                  <th style="width: 140px; text-align: center;">Pass Qty</th>
                  <th style="width: 170px; text-align: center;">Rework Qty / Variant</th>
                  <th style="width: 130px; text-align: center;">Total Rework</th>
                </tr>
              </thead>
              <tbody id="blueprintTableBody">
                <!-- Populated dynamically via JS matching the blueprint -->
              </tbody>
            </table>
          </div>

          <!-- Variant Finished Goods Summary Bar (Matches User Mockup: Variant 1 - X NOS ... Total) -->
          <div style="display: flex; justify-content: flex-end; margin-top: 8px;">
            <div id="variantFgInlineBar" style="display: inline-flex; align-items: center; justify-content: space-between; gap: 36px; border: 1.5px solid #475569; border-radius: 4px; background: #ffffff; padding: 7px 18px; font-size: 0.90rem; color: #0f172a; min-width: 480px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
              <div id="variantFgItemsContainer" style="display: flex; align-items: center; gap: 28px; flex-wrap: wrap;">
                <!-- Populated dynamically via JS: Variant 1 – 3 NOS    Variant 2 – 2 NOS    Variant 3 – 3 NOS -->
              </div>
              <div style="font-weight: 800; font-size: 1.25rem; color: #0f172a; min-width: 24px; text-align: right; letter-spacing: -0.02em;" id="variantFgGrandTotal">
                0
              </div>
            </div>
          </div>

          <!-- Save Workflow Button Bar -->
          <div style="display: flex; justify-content: flex-end; align-items: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
            <button type="button" id="btnSaveProcessWorkflow" class="btn-primary" style="padding: 9px 24px; font-weight: 700; font-size: 0.90rem; display: inline-flex; align-items: center; gap: 8px; border-radius: 6px;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
              Save Process Workflow
            </button>
          </div>


        </div>

        <!-- 3. Recent Saved Process Logs / Activity Audit Trail -->
        <div class="blueprint-card" style="margin-bottom: 0;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div style="font-size: 0.92rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
              Saved Stage Execution History
            </div>
            <span style="font-size: 0.75rem; color: #64748b;" id="lblHistoryCount">
              <?php 
                $activeHistoryLogs = array_filter($existingStageLogs, function($h) {
                    return (floatval($h['processed_qty'] ?? 0) > 0 || floatval($h['rejected_qty'] ?? 0) > 0);
                });
                echo count($activeHistoryLogs); 
              ?> records saved
            </span>
          </div>

          <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
            <table class="blueprint-table" style="font-size: 0.82rem;" id="historyTable">
              <thead style="background: #f8fafc;">
                <tr>
                  <th style="padding: 6px 10px; width: 40px; text-align: center;">#</th>
                  <th style="padding: 6px 10px;">Date &amp; Time</th>
                  <th style="padding: 6px 10px;">Process Name</th>
                  <th style="padding: 6px 10px; text-align: center;">Input / Avail Qty</th>
                  <th style="padding: 6px 10px; text-align: center;">Passed Qty</th>
                  <th style="padding: 6px 10px; text-align: center;">Rework Qty</th>
                  <th style="padding: 6px 10px; text-align: center;">Remaining Qty</th>
                  <th style="padding: 6px 10px;">Operator</th>
                  <th style="padding: 6px 10px; text-align: center;">Status</th>
                </tr>
              </thead>
              <tbody id="historyTableBody">
                <?php if (empty($activeHistoryLogs)): ?>
                  <tr id="emptyHistoryRow">
                    <td colspan="9" style="text-align: center; padding: 20px; color: #94a3b8; font-style: italic;">
                      No saved stages yet. Enter Pass Qty above and click <strong>Save</strong>.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php $hCount = 0; foreach ($activeHistoryLogs as $h): $hCount++; ?>
                    <?php 
                      $hInput = floatval($h['input_qty'] ?? 0);
                      $hPass = floatval($h['processed_qty'] ?? 0);
                      $hRej = floatval($h['rejected_qty'] ?? 0);
                      $hRem = max(0, $hInput - ($hPass + $hRej));
                      $hDate = !empty($h['updated_at']) ? date('d-m-Y h:i A', strtotime($h['updated_at'])) : date('d-m-Y h:i A');
                    ?>
                    <tr>
                      <td style="text-align: center; color: #64748b; font-weight: 600;"><?php echo $hCount; ?></td>
                      <td style="color: #475569; font-size: 0.80rem; white-space: nowrap;"><?php echo htmlspecialchars($hDate); ?></td>
                      <td><strong style="color: #0f172a;"><?php echo htmlspecialchars($h['process_name']); ?></strong></td>
                      <td style="text-align: center; font-weight: 600;"><?php echo $hInput; ?> NOS</td>
                      <td style="text-align: center; font-weight: 700; color: #16a34a;"><?php echo $hPass; ?> NOS</td>
                      <td style="text-align: center; font-weight: 600; color: <?php echo $hRej > 0 ? '#ea580c' : '#94a3b8'; ?>;">
                        <?php echo $hRej > 0 ? $hRej . ' NOS' : '-'; ?>
                      </td>
                      <td style="text-align: center; font-weight: 600; color: <?php echo $hRem > 0 ? '#b45309' : '#64748b'; ?>;">
                        <?php echo $hRem > 0 ? $hRem . ' NOS' : '-'; ?>
                      </td>
                      <td style="color: #334155; font-size: 0.80rem;"><?php echo htmlspecialchars($h['operator_name'] ?: 'Operator'); ?></td>
                      <td style="text-align: center;">
                        <span style="font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; background: #ecfdf5; color: #059669; font-weight: 700;">
                          &check; Saved
                        </span>
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

  <!-- Toast Notification Container -->
  <div id="toastContainer"></div>

  <!-- JavaScript Workflow Controller -->
  <script>
    // Initial data from PHP
    const currentEntryId = <?php echo intval($entryDbId); ?>;
    const currentMipNo = <?php echo json_encode($currentMipNo); ?>;
    const currentPartCode = <?php echo json_encode($partCode); ?>;
    const currentPartName = <?php echo json_encode($partName); ?>;
    const initialTargetQty = <?php echo floatval($targetQty); ?>;
    const rawProcesses = <?php echo json_encode($chainedProcesses, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const partOutputVariants = <?php echo json_encode($outputVariants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const initialVariantBreakdown = <?php echo json_encode(array_values($initialVariantBreakdown), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let variantInventory = JSON.parse(JSON.stringify(initialVariantBreakdown || []));

    // Toast Utility
    function showToast(message, type = 'success') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = `toast toast-${type}`;
      toast.innerHTML = `
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
        <span>${message}</span>
      `;
      container.appendChild(toast);
      setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.25s ease';
        setTimeout(() => toast.remove(), 250);
      }, 3500);
    }

    function formatNum(val) {
      if (val === null || val === undefined || isNaN(val)) return '-';
      const num = parseFloat(val);
      if (num === 0) return '-';
      return Number.isInteger(num) ? num.toString() : num.toFixed(2);
    }

    function escapeHtml(text) {
      if (!text) return '';
      return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    // Process State Array
    let stageData = JSON.parse(JSON.stringify(rawProcesses || []));
    stageData.forEach(st => {
      st.base_available = parseFloat(st.base_available !== undefined ? st.base_available : (st.available || 0));
      st.available = st.base_available;
      st.cum_pass = parseFloat(st.cum_pass || 0);
      st.cum_rej = parseFloat(st.cum_rej || 0);
      st.input_total = parseFloat(st.input_total || 0);
      st.pass = null;
      st.rej = null;
    });

    const localBackupKey = currentEntryId 
      ? `wip_part_proc_${currentEntryId}` 
      : `wip_part_proc_${encodeURIComponent(currentMipNo)}`;

    // Clear stale drafts so inputs are empty by default after saving
    try {
      localStorage.removeItem(localBackupKey);
    } catch(e) {}

    // Render the Table matching the Blueprint (6 clean columns)
    function renderBlueprintTable() {
      const tbody = document.getElementById('blueprintTableBody');
      if (!tbody) return;
      tbody.innerHTML = '';

      stageData.forEach((st, idx) => {
        const order = idx + 1;
        const isFinalRow = Boolean(st.is_final || idx === stageData.length - 1);
        
        const tr = document.createElement('tr');
        tr.id = `row_${idx}`;
        if (st.has_saved) {
          tr.classList.add('row-saved');
        }

        let colRejectOrVariantHtml = '';
        let colSavedRejHtml = '';

        if (isFinalRow) {
          let variantOptionsHtml = '<option value="">-- Select Variant --</option>';
          if (Array.isArray(partOutputVariants) && partOutputVariants.length > 0) {
            partOutputVariants.forEach(v => {
              let code = '';
              let name = '';
              if (typeof v === 'string') {
                code = v;
                name = v;
              } else if (v && typeof v === 'object') {
                code = v.code || v.variant_code || '';
                name = v.name || v.variant_name || '';
              }
              if (!code && !name) return;
              const isSelected = (st.variant_code && (st.variant_code === code || st.variant_code === name)) ? 'selected' : '';
              const label = name && name !== code ? `${code} (${name})` : code;
              variantOptionsHtml += `<option value="${escapeHtml(code)}" data-name="${escapeHtml(name)}" ${isSelected}>${escapeHtml(label)}</option>`;
            });
          } else {
            variantOptionsHtml += '<option value="Standard FG" selected>Standard FG</option>';
          }

          colRejectOrVariantHtml = `
            <select class="bp-select-variant" id="selectVariant_${idx}" data-row="${idx}">
              ${variantOptionsHtml}
            </select>
          `;
          colSavedRejHtml = `
            <span style="font-weight: 600; font-size: 0.95rem; color: #94a3b8;">-</span>
          `;
        } else {
          colRejectOrVariantHtml = `
            <input type="number" 
                   class="bp-input input-rej" 
                   id="inputRej_${idx}" 
                   data-row="${idx}" 
                   min="0" 
                   max="${st.available}" 
                   step="any" 
                   placeholder="0" 
                   value="">
          `;
          colSavedRejHtml = `
            <span class="val-rej-saved" id="lblSavedRej_${idx}" style="font-weight: 700; font-size: 0.95rem; color: ${st.cum_rej > 0 ? '#ea580c' : '#64748b'};">
              ${st.cum_rej || 0}
            </span>
          `;
        }

        tr.innerHTML = `
          <td style="text-align: center; font-weight: 600; color: #64748b;">${order}</td>
          <td>
            <div style="font-weight: 700; color: #0f172a;">${escapeHtml(st.name)}</div>
          </td>
          <td style="text-align: center;">
            <span class="val-avail" id="lblAvail_${idx}">${st.available}</span>
          </td>
          <td style="text-align: center;">
            <input type="number" 
                   class="bp-input input-pass" 
                   id="inputPass_${idx}" 
                   data-row="${idx}" 
                   min="0" 
                   max="${st.available}" 
                   step="any" 
                   placeholder="0" 
                   value="">
          </td>
          <td style="text-align: center;">
            ${colRejectOrVariantHtml}
          </td>
          <td style="text-align: center;">
            ${colSavedRejHtml}
          </td>
        `;
        tbody.appendChild(tr);
      });

      updateFinalFgSummary();
      attachInputListeners();
    }

    // Dynamic Chaining: When user types pass or rejected qty in any row
    function recalcChainedWorkflow() {
      let incomingFromPrev = 0;

      stageData.forEach((st, idx) => {
        const isFinalRow = Boolean(st.is_final || idx === stageData.length - 1);
        const passInput = document.getElementById(`inputPass_${idx}`);
        const rejInput = document.getElementById(`inputRej_${idx}`);

        const rawPass = passInput?.value.trim() ?? '';
        const rawRej = rejInput ? (rejInput.value.trim() ?? '') : '';

        const passNum = rawPass !== '' ? parseFloat(rawPass) : 0;
        const rejNum = isFinalRow ? 0 : (rawRej !== '' ? parseFloat(rawRej) : 0);

        st.pass = rawPass !== '' ? passNum : null;
        st.rej = isFinalRow ? null : (rawRej !== '' ? rejNum : null);

        // Current available at this stage includes incoming pass from previous stage in this session
        const currentAvail = (st.base_available || 0) + incomingFromPrev;
        st.available = currentAvail;

        // Validation: Pass + Rejected cannot exceed Available
        const totalUsed = passNum + rejNum;
        const isExceeded = totalUsed > currentAvail;

        if (passInput) {
          passInput.classList.toggle('input-error', isExceeded);
          passInput.max = currentAvail;
        }
        if (rejInput) {
          rejInput.classList.toggle('input-error', isExceeded);
          rejInput.max = currentAvail;
        }

        const availEl = document.getElementById(`lblAvail_${idx}`);
        if (availEl) {
          availEl.textContent = currentAvail;
        }

        // Pass quantity in this row cascades to next row as incoming available
        incomingFromPrev = passNum;
      });

      updateFinalFgSummary();
    }

    // Render Inline Variant FG Summary Bar (Matches User Mockup: Variant 1 – 3 NOS ... 8)
    function renderVariantFgInlineBar() {
      const container = document.getElementById('variantFgItemsContainer');
      const totalEl = document.getElementById('variantFgGrandTotal');
      if (!container) return;

      container.innerHTML = '';

      const lastStage = stageData[stageData.length - 1];
      const sessionPass = parseFloat(lastStage?.pass || 0);
      const finalIdx = stageData.length - 1;
      const varSelect = document.getElementById(`selectVariant_${finalIdx}`);
      const selectedVarCode = varSelect ? varSelect.value.trim() : (lastStage?.variant_code || '');

      let grandTotal = 0;

      variantInventory.forEach(v => {
        const baseQty = parseFloat(v.qty || 0);
        const isSelectedNow = Boolean(selectedVarCode && (v.code === selectedVarCode || v.name === selectedVarCode));
        const pendingAdd = (isSelectedNow && sessionPass > 0) ? sessionPass : 0;
        const totalVariantQty = baseQty + pendingAdd;
        grandTotal += totalVariantQty;

        const displayName = v.name || v.code;
        const itemSpan = document.createElement('span');
        itemSpan.style.whiteSpace = 'nowrap';
        itemSpan.style.fontSize = '0.92rem';
        itemSpan.style.color = '#0f172a';
        itemSpan.innerHTML = `<span>${escapeHtml(displayName)} &ndash; ${totalVariantQty} NOS</span>`;
        container.appendChild(itemSpan);
      });

      if (totalEl) {
        totalEl.textContent = grandTotal;
      }
    }

    function updateFinalFgSummary() {
      renderVariantFgInlineBar();
    }

    function attachInputListeners() {
      document.querySelectorAll('.input-pass, .input-rej').forEach(input => {
        input.addEventListener('input', () => {
          const rowIdx = parseInt(input.getAttribute('data-row'), 10);
          const row = document.getElementById(`row_${rowIdx}`);
          if (row) row.classList.remove('row-saved');

          recalcChainedWorkflow();
        });
      });

      document.querySelectorAll('.bp-select-variant').forEach(select => {
        select.addEventListener('change', () => {
          const rowIdx = parseInt(select.getAttribute('data-row'), 10);
          const row = document.getElementById(`row_${rowIdx}`);
          if (row) row.classList.remove('row-saved');

          updateFinalFgSummary();
        });
      });
    }

    // Save All Process Stages Workflow in one unified action
    const btnSaveWorkflow = document.getElementById('btnSaveProcessWorkflow');
    if (btnSaveWorkflow) {
      btnSaveWorkflow.addEventListener('click', async () => {
        recalcChainedWorkflow();

        let hasAnyInput = false;
        for (let i = 0; i < stageData.length; i++) {
          const st = stageData[i];
          const isFinalRow = Boolean(st.is_final || i === stageData.length - 1);
          const pass = st.pass || 0;
          const rej = isFinalRow ? 0 : (st.rej || 0);
          if (pass > 0 || rej > 0) {
            hasAnyInput = true;
          }
          if (pass + rej > st.available) {
            showToast(`Validation error in '${st.name}': Pass Qty (${pass}) + Rework Qty (${rej}) exceeds Available Qty (${st.available}).`, 'error');
            return;
          }
        }

        if (!hasAnyInput) {
          showToast('Please enter Pass Qty or Rework Qty before saving.', 'error');
          return;
        }

        // Check if final stage has pass quantity and requires an output variant
        const finalIdx = stageData.length - 1;
        const lastSt = stageData[finalIdx];
        let selectedVariantCode = '';
        let selectedVariantName = '';
        const finalVarSelect = document.getElementById(`selectVariant_${finalIdx}`);
        if (finalVarSelect) {
          selectedVariantCode = finalVarSelect.value.trim();
          const selOpt = finalVarSelect.options[finalVarSelect.selectedIndex];
          selectedVariantName = selOpt ? (selOpt.getAttribute('data-name') || selOpt.text || selectedVariantCode) : selectedVariantCode;
        }

        if (lastSt && (lastSt.pass > 0) && Array.isArray(partOutputVariants) && partOutputVariants.length > 0 && !selectedVariantCode) {
          showToast(`Please select an Output Variant for final stage '${lastSt.name}'.`, 'error');
          if (finalVarSelect) finalVarSelect.focus();
          return;
        }

        const globalOp = document.getElementById('inputGlobalOperator')?.value.trim() || 'Operator';
        const globalDesig = document.getElementById('inputGlobalDesignation')?.value.trim() || 'Operator';

        const origHtml = btnSaveWorkflow.innerHTML;
        btnSaveWorkflow.disabled = true;
        btnSaveWorkflow.innerHTML = `
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="animate-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
          Saving Workflow...
        `;

        // Calculate new cumulative totals and available quantities
        let runningPassTotal = initialTargetQty;
        const stagesPayload = [];

        // Prepare updated variant breakdown for final stage
        let updatedVariantInventory = JSON.parse(JSON.stringify(variantInventory));
        if (selectedVariantCode && lastSt && lastSt.pass > 0) {
          const passToAdd = parseFloat(lastSt.pass);
          let matchFound = false;
          updatedVariantInventory.forEach(v => {
            if (v.code === selectedVariantCode || v.name === selectedVariantCode) {
              v.qty = (parseFloat(v.qty) || 0) + passToAdd;
              matchFound = true;
            }
          });
          if (!matchFound) {
            updatedVariantInventory.push({
              code: selectedVariantCode,
              name: selectedVariantName || selectedVariantCode,
              qty: passToAdd
            });
          }
        }

        for (let i = 0; i < stageData.length; i++) {
          const st = stageData[i];
          const isFinalRow = Boolean(st.is_final || i === stageData.length - 1);
          const batchPass = st.pass || 0;
          const batchRej = isFinalRow ? 0 : (st.rej || 0);
          const newCumPass = (st.cum_pass || 0) + batchPass;
          const newCumRej = isFinalRow ? 0 : ((st.cum_rej || 0) + batchRej);

          const stageInput = (i === 0) ? initialTargetQty : runningPassTotal;
          const newAvail = Math.max(0, stageInput - (newCumPass + newCumRej));
          runningPassTotal = newCumPass;

          let variantCode = '';
          let variantName = '';
          let variantDetails = null;
          if (isFinalRow) {
            variantCode = selectedVariantCode || (st.variant_code || '');
            variantName = selectedVariantName || (st.variant_name || '');
            variantDetails = updatedVariantInventory;
          }

          stagesPayload.push({
            stage_order: st.order,
            process_code: st.code,
            process_name: st.name,
            input_qty: stageInput,
            pass_qty: newCumPass,
            rejected_qty: newCumRej,
            batch_pass: batchPass,
            batch_rej: batchRej,
            available_qty: newAvail,
            operator_name: globalOp,
            designation: globalDesig,
            is_final_stage: isFinalRow ? 1 : 0,
            variant_code: variantCode,
            variant_name: variantName,
            variant_output_details: variantDetails,
            remarks: ''
          });
        }

        try {
          const res = await fetch('api/process_stage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'save_all',
              production_entry_id: currentEntryId,
              mip_no: currentMipNo,
              part_code: currentPartCode,
              part_name: currentPartName,
              operator_name: globalOp,
              stages: stagesPayload
            })
          });

          const rawText = await res.text();
          let resJson = null;
          try {
            resJson = JSON.parse(rawText);
          } catch(e) {
            const startIdx = rawText.indexOf('{');
            const endIdx = rawText.lastIndexOf('}');
            if (startIdx !== -1 && endIdx !== -1 && endIdx > startIdx) {
              try {
                resJson = JSON.parse(rawText.substring(startIdx, endIdx + 1));
              } catch(e2) {}
            }
          }

          if (!resJson) {
            console.error('Server response format error:', rawText);
            showToast('Invalid server response format. Please try again.', 'error');
            return;
          }

          if (resJson.success) {
            // Update local state with saved values
            variantInventory = updatedVariantInventory;

            try {
              stagesPayload.forEach((savedSt, idx) => {
                const st = stageData[idx];
                if (!st) return;
                const isFinalRow = Boolean(st.is_final || idx === stageData.length - 1);
                st.input_total = savedSt.input_qty;
                st.cum_pass = savedSt.pass_qty;
                st.cum_rej = savedSt.rejected_qty;
                st.base_available = savedSt.available_qty;
                st.available = savedSt.available_qty;
                st.pass = null;
                st.rej = null;
                if (isFinalRow && savedSt.variant_code) {
                  st.variant_code = savedSt.variant_code;
                  st.variant_name = savedSt.variant_name;
                }
                st.has_saved = (st.cum_pass > 0 || st.cum_rej > 0);

                // 1. UPDATE AVAILABLE QTY IN TABLE
                const availEl = document.getElementById(`lblAvail_${idx}`);
                if (availEl) {
                  availEl.textContent = st.available;
                }

                // 2. UPDATE REJECTED/REWORK QTY IN TABLE (Shows saved rework quantity for non-final stages)
                const savedRejEl = document.getElementById(`lblSavedRej_${idx}`);
                if (savedRejEl && !isFinalRow) {
                  savedRejEl.textContent = st.cum_rej || 0;
                  savedRejEl.style.color = st.cum_rej > 0 ? '#ea580c' : '#64748b';
                }

                // 3. EMPTY INPUT FIELDS AUTOMATICALLY AFTER SAVING
                const passInput = document.getElementById(`inputPass_${idx}`);
                const rejInput = document.getElementById(`inputRej_${idx}`);
                if (passInput) {
                  passInput.value = '';
                  passInput.max = st.available;
                  passInput.classList.remove('input-error');
                }
                if (rejInput) {
                  rejInput.value = '';
                  rejInput.max = st.available;
                  rejInput.classList.remove('input-error');
                }

                const row = document.getElementById(`row_${idx}`);
                if (row && st.has_saved) {
                  row.classList.add('row-saved');
                }

                // Append new history log for batches that had progress
                if (savedSt.batch_pass > 0 || savedSt.batch_rej > 0) {
                  appendHistoryLog({
                    time: `Today, ${new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }).toLowerCase()}`,
                    processName: st.name + (isFinalRow && savedSt.variant_code ? ` [${savedSt.variant_code}]` : ''),
                    avail: savedSt.input_qty,
                    pass: savedSt.batch_pass,
                    rej: isFinalRow ? '-' : savedSt.batch_rej,
                    rem: savedSt.available_qty,
                    operator: globalOp
                  });
                }
              });

              // Clear draft from localStorage
              try {
                localStorage.removeItem(localBackupKey);
              } catch(e) {}

              updateFinalFgSummary();
            } catch(domErr) {
              console.warn('DOM update error after saving:', domErr);
            }

            if (resJson.is_completed) {
              showToast('🎉 All processes completed successfully! Output stored in FG.', 'success');
              setTimeout(() => {
                window.location.reload();
              }, 1000);
            } else {
              showToast('✓ Process stages saved! Available quantities updated.', 'success');
            }
          } else {
            showToast(resJson.message || 'Failed to save all stages.', 'error');
          }
        } catch (err) {
          console.error('Error saving workflow:', err);
          showToast('Network error while saving process workflow.', 'error');
        } finally {
          btnSaveWorkflow.disabled = false;
          btnSaveWorkflow.innerHTML = origHtml;
        }
      });
    }

    // Helper: Append a new history log row to the audit trail
    function appendHistoryLog(log) {
      const tbody = document.getElementById('historyTableBody');
      const emptyRow = document.getElementById('emptyHistoryRow');
      if (emptyRow) emptyRow.remove();

      const tr = document.createElement('tr');
      const count = tbody.querySelectorAll('tr').length + 1;

      tr.innerHTML = `
        <td style="text-align: center; color: #64748b; font-weight: 600;">${count}</td>
        <td style="color: #475569; font-size: 0.80rem; white-space: nowrap;">${escapeHtml(log.time)}</td>
        <td><strong style="color: #0f172a;">${escapeHtml(log.processName)}</strong></td>
        <td style="text-align: center; font-weight: 600;">${log.avail} NOS</td>
        <td style="text-align: center; font-weight: 700; color: #16a34a;">${log.pass} NOS</td>
        <td style="text-align: center; font-weight: 600; color: ${log.rej > 0 ? '#ea580c' : '#94a3b8'};">
          ${log.rej > 0 ? log.rej + ' NOS' : '-'}
        </td>
        <td style="text-align: center; font-weight: 600; color: ${log.rem > 0 ? '#b45309' : '#64748b'};">
          ${log.rem > 0 ? log.rem + ' NOS' : '-'}
        </td>
        <td style="color: #334155; font-size: 0.80rem;">${escapeHtml(log.operator)}</td>
        <td style="text-align: center;">
          <span style="font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; background: #ecfdf4; color: #059669; font-weight: 700;">
            &check; Saved
          </span>
        </td>
      `;
      tbody.prepend(tr);

      const countLbl = document.getElementById('lblHistoryCount');
      if (countLbl) {
        countLbl.textContent = `${count} records saved`;
      }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', () => {
      renderBlueprintTable();
    });
  </script>
</body>
</html>
