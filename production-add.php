<?php
/**
 * WIP Management Portal - Log / Edit Production Entry (Dedicated Page)
 * Full-page interface for shop-floor Daily Production Reporting (DPR).
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/rbac.php';

$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEditMode = ($editId > 0);

if ($isEditMode) {
    requirePermission('production', 'update');
    $pageTitle = 'Edit Production Entry (DPR)';
} else {
    requirePermission('production', 'create');
    $pageTitle = 'Log Production Entry (DPR)';
}

$activeMenu = 'production.php';
$headerBackUrl = 'production.php';
$headerBackText = 'Back to Production List';

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

$pdo = getDBConnection();
$customerList = [];
$mipList = [];
$partList = [];
$processList = [];
$loggedProductionList = [];
$existingEntry = null;

$todayDmy = date('d-m-Y');
$todaySqlDate = date('Y-m-d');
$yesterdaySqlDate = date('Y-m-d', strtotime('-1 day'));

if ($pdo) {
    try {
        // Fetch active customers
        $stmtCust = $pdo->query("SELECT id, customer_code, customer_name FROM customer_master WHERE status = 'Active' ORDER BY customer_name ASC");
        if ($stmtCust) {
            $customerList = $stmtCust->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch active parts with child parts & processes
        $stmtParts = $pdo->query("SELECT id, part_code, part_name, child_parts, processes FROM part_master WHERE status = 'Active' ORDER BY part_code ASC");
        if ($stmtParts) {
            $partList = $stmtParts->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch global processes
        $stmtProc = $pdo->query("SELECT id, process_code, process_name FROM process_master WHERE status = 'Active' ORDER BY process_name ASC");
        if ($stmtProc) {
            $processList = $stmtProc->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch all issued MIP records
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

        // Fetch already saved production entries to prevent duplicate saves
        $stmtLogged = $pdo->query("SELECT id, mip_no, part_code FROM production_entry");
        $loggedProductionList = $stmtLogged ? $stmtLogged->fetchAll(PDO::FETCH_ASSOC) : [];

        // If edit mode, load existing entry
        if ($isEditMode) {
            $stmtEdit = $pdo->prepare("
                SELECT id, mip_no, customer_name, customer_code, so_number, work_order, part_code, part_name,
                       department, received_by, process_name,
                       target_qty, ok_qty, rejected_qty,
                       operator_name, uom, status,
                       CONVERT(VARCHAR(10), created_at, 120) as entry_date,
                       CONVERT(VARCHAR(19), created_at, 120) as created_at
                FROM production_entry
                WHERE id = ?
            ");
            $stmtEdit->execute([$editId]);
            $existingEntry = $stmtEdit->fetch(PDO::FETCH_ASSOC);
            if (!$existingEntry) {
                header("Location: production.php");
                exit;
            }
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $isEditMode ? 'Edit Production Entry' : 'Log Production Entry'; ?> - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
    /* Sticky Topbar Header */
    .layout-container, .main-content {
      overflow-x: clip;
    }
    .topbar {
      position: -webkit-sticky;
      position: sticky;
      top: 0;
      z-index: 30;
      background: var(--bg-white, #ffffff);
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    }

    .btn-back-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
      text-decoration: none;
      transition: all 0.15s ease;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    }
    .btn-back-link:hover {
      background: #f1f5f9;
      border-color: #cbd5e1;
      color: var(--primary);
    }

    /* Main Form Card */
    .prd-form-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 28px 32px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
      margin-bottom: 30px;
    }
    @media (max-width: 768px) {
      .prd-form-card {
        padding: 20px 16px;
      }
    }

    .form-heading-wrap {
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--border);
    }
    .form-section-title {
      font-size: 1.2rem;
      font-weight: 800;
      color: var(--text-main);
      margin: 0 0 6px 0;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .form-section-subtitle {
      font-size: 0.86rem;
      color: var(--text-sub);
      margin: 0;
      line-height: 1.5;
    }

    /* Form Grid */
    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px 24px;
    }
    @media (max-width: 768px) {
      .form-grid-2 {
        grid-template-columns: 1fr;
      }
    }

    .form-grid-3 {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px 24px;
    }
    @media (max-width: 900px) {
      .form-grid-3 {
        grid-template-columns: 1fr;
      }
    }

    /* Process Flow Arrow Badges (Chevron Steps) */
    .process-arrow-chips-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 12px 14px;
      align-items: center;
      padding: 12px 14px;
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 8px;
      min-height: 56px;
      box-sizing: border-box;
      transition: border-color 0.15s ease;
    }

    .process-arrow-chip {
      display: inline-flex;
      align-items: stretch;
      height: 36px;
      background: #eaedf2;
      border: 1.5px solid #2563eb;
      border-right: none;
      border-radius: 4px 0 0 4px;
      box-sizing: border-box;
      transition: all 0.15s ease;
      cursor: pointer;
    }

    .process-arrow-chip:hover {
      background: #dfe5ec;
      border-color: #1d4ed8;
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(37, 99, 235, 0.16);
    }

    .process-arrow-chip.active {
      background: #2563eb;
      border-color: #1d4ed8;
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.32);
      transform: translateY(-1px);
    }

    .process-arrow-name {
      display: flex;
      align-items: center;
      padding: 0 6px 0 14px;
      font-size: 0.88rem;
      font-weight: 600;
      color: #2563eb;
      white-space: nowrap;
      user-select: none;
      transition: color 0.15s ease;
    }

    .process-arrow-chip.active .process-arrow-name {
      color: #ffffff;
      font-weight: 700;
    }

    .process-arrow-svg {
      width: 14px;
      height: 36px;
      margin-top: -1.5px;
      margin-bottom: -1.5px;
      margin-left: -0.5px;
      flex-shrink: 0;
      display: block;
    }

    .process-arrow-chip:hover .arrow-fill {
      fill: #dfe5ec;
    }

    .process-arrow-chip:hover .arrow-stroke {
      stroke: #1d4ed8;
    }

    .process-arrow-chip.active .arrow-fill {
      fill: #2563eb;
    }

    .process-arrow-chip.active .arrow-stroke {
      stroke: #1d4ed8;
    }

    .process-arrow-empty {
      color: #94a3b8;
      font-size: 0.84rem;
      font-style: italic;
      padding: 6px 4px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .form-group.full-width {
      grid-column: 1 / -1;
    }
    .form-group label {
      font-size: 0.84rem;
      font-weight: 600;
      color: var(--text-main);
    }
    .form-group .form-control {
      width: 100%;
      height: 40px;
      padding: 0 12px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: inherit;
      font-size: 0.88rem;
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
    .form-group .form-control:disabled {
      background: #f8fafc;
      cursor: not-allowed;
      color: #94a3b8;
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

    /* Section Subheadings */
    .sub-section-title {
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding-bottom: 6px;
      border-bottom: 1.5px solid #e2e8f0;
      margin-top: 22px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* Yield Live Calculation Box */
    .yield-calc-box {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      padding: 16px 20px;
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 8px;
      margin-top: 10px;
    }
    @media (max-width: 640px) {
      .yield-calc-box {
        grid-template-columns: 1fr 1fr;
      }
    }
    .yield-stat-item {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .yield-stat-lbl {
      font-size: 0.72rem;
      text-transform: uppercase;
      font-weight: 700;
      color: var(--text-sub);
      letter-spacing: 0.03em;
    }
    .yield-stat-val {
      font-size: 1.15rem;
      font-weight: 700;
      color: var(--text-main);
      font-variant-numeric: tabular-nums;
    }

    /* Action Buttons in Footer */
    .form-card-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 28px;
      padding-top: 20px;
      border-top: 1px solid var(--border);
    }
  </style>
</head>
<body>

  <!-- Toast Notification Container -->
  <div id="toastContainer" class="toast-container" aria-live="polite"></div>

  <div class="layout-container">
    
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
      
      <!-- Topbar Header -->
      <?php require_once __DIR__ . '/includes/header.php'; ?>

      <div class="content-body">
        
        <!-- Main Form Container -->
        <div class="prd-form-card">

          <form id="prdAddForm">
            <input type="hidden" id="editPrdId" value="<?php echo $editId; ?>">

            <!-- 1. Customer & Part Selection Section -->
            <div class="sub-section-title" style="margin-top: 0;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              Customer &amp; Part Selection
            </div>

            <div class="form-grid-2">
              
              <!-- Select Customer (Only Customer Name) -->
              <div class="form-group">
                <label for="prdCustomerSelect">Select Customer *</label>
                <select id="prdCustomerSelect" class="form-control" required>
                  <option value="">-- Select Customer --</option>
                </select>
              </div>

              <!-- Select Part Name (Disabled until customer selected) -->
              <div class="form-group">
                <label for="prdPartSelect">Select Part Name *</label>
                <select id="prdPartSelect" class="form-control" required disabled>
                  <option value="">-- Select Customer First --</option>
                </select>
              </div>

            </div>

            <!-- Hidden Sync Fields -->
            <input type="hidden" id="inputMipNo" value="<?php echo htmlspecialchars($existingEntry['mip_no'] ?? ''); ?>">
            <input type="hidden" id="inputCustomerName" value="<?php echo htmlspecialchars($existingEntry['customer_name'] ?? ''); ?>">
            <input type="hidden" id="inputCustomerCode" value="<?php echo htmlspecialchars($existingEntry['customer_code'] ?? ''); ?>">
            <input type="hidden" id="inputSoNumber" value="<?php echo htmlspecialchars($existingEntry['so_number'] ?? ''); ?>">
            <input type="hidden" id="inputWorkOrder" value="<?php echo htmlspecialchars($existingEntry['work_order'] ?? ''); ?>">
            <input type="hidden" id="inputPartCode" value="<?php echo htmlspecialchars($existingEntry['part_code'] ?? ''); ?>">
            <input type="hidden" id="inputPartName" value="<?php echo htmlspecialchars($existingEntry['part_name'] ?? ''); ?>">
            <input type="hidden" id="inputDepartment" value="<?php echo htmlspecialchars($existingEntry['department'] ?? ''); ?>">
            <input type="hidden" id="inputReceivedBy" value="<?php echo htmlspecialchars($existingEntry['received_by'] ?? ''); ?>">
            <input type="hidden" id="inputUom" value="<?php echo htmlspecialchars($existingEntry['uom'] ?? 'PCS'); ?>">
            <input type="hidden" id="inputStatus" value="<?php echo htmlspecialchars($existingEntry['status'] ?? 'New Line up'); ?>">
            <input type="hidden" id="inputTargetQty" value="<?php echo htmlspecialchars($existingEntry['target_qty'] ?? '0'); ?>">
            <input type="hidden" id="inputOkQty" value="<?php echo htmlspecialchars($existingEntry['ok_qty'] ?? '0'); ?>">
            <input type="hidden" id="inputRejectedQty" value="<?php echo htmlspecialchars($existingEntry['rejected_qty'] ?? '0'); ?>">

            <!-- Fetched Material Issue Details Table (Shows once Customer & Part selected) -->
            <div id="fetchedMipTableWrap" style="margin-top: 18px; display: none;">
              <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                <span style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">
                  Fetched Material Issue Details:
                </span>
                <span id="fetchedMipStatusTag" style="font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; background: #ecfdf5; color: #059669; font-weight: 600; border: 1px solid #a7f3d0;">
                  Data Loaded
                </span>
              </div>
              <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 8px;">
                <table class="simple-table" style="font-size: 0.84rem; margin: 0; width: 100%;">
                  <thead style="background: #f8fafc;">
                    <tr>
                      <th style="padding: 8px 12px;">MIP No.</th>
                      <th style="padding: 8px 12px;">Customer</th>
                      <th style="padding: 8px 12px;">Part Code</th>
                      <th style="padding: 8px 12px;">Part Name</th>
                      <th style="padding: 8px 12px; text-align: center;">Issued Qty</th>
                      <th style="padding: 8px 12px;">Received By</th>
                      <th style="padding: 8px 12px; text-align: center;">Issue Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td style="padding: 9px 12px;"><strong id="tblMipNo">-</strong></td>
                      <td style="padding: 9px 12px;" id="tblMipCust">-</td>
                      <td style="padding: 9px 12px;"><strong id="tblMipPartCode" style="color: #2563eb;">-</strong></td>
                      <td style="padding: 9px 12px;" id="tblMipPartName">-</td>
                      <td style="padding: 9px 12px; text-align: center;"><strong id="tblMipQty" style="color: #059669;">-</strong></td>
                      <td style="padding: 9px 12px;" id="tblMipReceivedBy">-</td>
                      <td style="padding: 9px 12px; text-align: center;" id="tblMipDate">-</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- RM & Child Parts BOM Breakdown -->
              <div id="fetchedChildPartsSection" style="margin-top: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                  <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    RM &amp; Child Part Details (BOM Requirements):
                  </span>
                  <span id="childPartsCountBadge" style="font-size: 0.72rem; padding: 2px 8px; border-radius: 10px; background: #e0f2fe; color: #0369a1; font-weight: 600; border: 1px solid #bae6fd;">
                    0 Items
                  </span>
                </div>
                <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 8px;">
                  <table class="simple-table" style="font-size: 0.82rem; margin: 0; width: 100%;">
                    <thead style="background: #f8fafc;">
                      <tr>
                        <th style="padding: 7px 12px; width: 35px; text-align: center;">#</th>
                        <th style="padding: 7px 12px;">RM &amp; Child Part Code</th>
                        <th style="padding: 7px 12px;">RM &amp; Child Part Name</th>
                        <th style="padding: 7px 12px; text-align: center;">BOM Ratio</th>
                        <th style="padding: 7px 12px; text-align: center;">Total Issued / Req. Qty</th>
                        <th style="padding: 7px 12px; text-align: center;">UOM</th>
                      </tr>
                    </thead>
                    <tbody id="tblChildPartsBody">
                      <!-- Populated dynamically via JS -->
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- 2. Production Execution Details -->
            <div class="sub-section-title">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
              Production Execution &amp; Output
            </div>

            <!-- Row: Process / Stage (Auto marked as 'New Line up') & Operator / Technician -->
            <div class="form-grid-2" style="margin-bottom: 20px;">
              
              <!-- Process / Stage (Auto marked) -->
              <div class="form-group">
                <label for="inputProcess">Process / Stage</label>
                <div style="position: relative;">
                  <input type="text" id="inputProcess" class="form-control" value="<?php echo htmlspecialchars($existingEntry['process_name'] ?? 'New Line up'); ?>" readonly style="background: #f8fafc; color: #0284c7; font-weight: 700; cursor: default; border-color: #cbd5e1; padding-left: 28px;">
                  <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 8px; height: 8px; border-radius: 50%; background: #0284c7; display: inline-block;"></span>
                </div>
                <small style="color: #64748b; font-size: 0.72rem; margin-top: 4px; display: block;">Automatically marked as <strong>New Line up</strong> upon saving.</small>
              </div>

              <!-- Operator / Technician -->
              <div class="form-group">
                <label for="inputOperator">Operator / Technician *</label>
                <input type="text" id="inputOperator" class="form-control" placeholder="e.g., Ramesh Kumar" value="<?php echo htmlspecialchars($existingEntry['operator_name'] ?? ''); ?>" required>
              </div>

            </div>

            <!-- Footer Buttons -->
            <div class="form-card-footer">
              <a href="production.php" class="btn-secondary" style="height: 38px; display: inline-flex; align-items: center; text-decoration: none;">Cancel</a>
              <button type="submit" class="btn-primary" id="savePrdSubmitBtn" style="height: 38px; min-width: 170px;">
                <?php echo $isEditMode ? 'Update Production Entry' : '+ Save Production Entry'; ?>
              </button>
            </div>

          </form>

        </div>

      </div>

    </div>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      
      const prdAddForm = document.getElementById('prdAddForm');
      const editPrdId = document.getElementById('editPrdId');
      const prdCustomerSelect = document.getElementById('prdCustomerSelect');
      const prdPartSelect = document.getElementById('prdPartSelect');
      const inputProcess = document.getElementById('inputProcess');
      const inputTargetQty = document.getElementById('inputTargetQty');
      const inputOkQty = document.getElementById('inputOkQty');
      const inputRejectedQty = document.getElementById('inputRejectedQty');
      const inputOperator = document.getElementById('inputOperator');
      const inputUom = document.getElementById('inputUom');

      // Toast Notifications
      function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
          toast.style.animation = 'fadeOut 0.3s ease-out forwards';
          setTimeout(() => toast.remove(), 300);
        }, 3500);
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

      function formatNumber(num) {
        const n = parseFloat(num || 0);
        return Number.isInteger(n) ? n.toString() : n.toFixed(2);
      }



      // Disable mouse wheel changing number input values
      document.addEventListener('wheel', () => {
        if (document.activeElement && document.activeElement.type === 'number') {
          document.activeElement.blur();
        }
      });

      // Data catalogs from backend
      const availableMips = <?php echo json_encode($mipList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      const customerMasterList = <?php echo json_encode($customerList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      const loggedProductionEntries = <?php echo json_encode($loggedProductionList ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      const partsCatalog = <?php 
        $catalog = [];
        foreach ($partList as $p) {
          $catalog[$p['part_code']] = $p;
        }
        echo json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); 
      ?>;
      const globalProcessList = <?php echo json_encode($processList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

      // Populate Customer Dropdown (ONLY customer name)
      function initCustomerDropdown(selectedCustomer = '') {
        if (!prdCustomerSelect) return;
        prdCustomerSelect.innerHTML = '<option value="">-- Select Customer --</option>';

        const custMap = new Map();

        // 1. Customers from MIP issues
        if (Array.isArray(availableMips)) {
          availableMips.forEach(m => {
            const name = (m.customer_name || '').trim();
            if (name && !custMap.has(name)) {
              custMap.set(name, { name: name, code: m.customer_code || '' });
            }
          });
        }

        // 2. Customers from Customer Master
        if (Array.isArray(customerMasterList)) {
          customerMasterList.forEach(c => {
            const name = (c.customer_name || '').trim();
            if (name && !custMap.has(name)) {
              custMap.set(name, { name: name, code: c.customer_code || '' });
            }
          });
        }

        const sorted = Array.from(custMap.values()).sort((a, b) => a.name.localeCompare(b.name));

        sorted.forEach(c => {
          const opt = document.createElement('option');
          opt.value = c.name;
          opt.setAttribute('data-code', c.code);
          // Only customer name
          opt.textContent = c.name;
          if (selectedCustomer && (selectedCustomer.toLowerCase() === c.name.toLowerCase() || (c.code && selectedCustomer.toLowerCase() === c.code.toLowerCase()))) {
            opt.selected = true;
          }
          prdCustomerSelect.appendChild(opt);
        });
      }

      // Populate Part Dropdown based on selected customer (Display Part Name, exclude already saved)
      function populatePartDropdown(selectedCustomerName, selectedVal = '') {
        if (!prdPartSelect) return;
        prdPartSelect.innerHTML = '';

        const custClean = (selectedCustomerName || '').trim().toLowerCase();
        if (!custClean) {
          prdPartSelect.disabled = true;
          const defOpt = document.createElement('option');
          defOpt.value = '';
          defOpt.textContent = '-- Select Customer First --';
          prdPartSelect.appendChild(defOpt);
          return;
        }

        // All MIPs for this customer
        const allCustomerMips = (availableMips || []).filter(m => {
          const mCust = (m.customer_name || '').trim().toLowerCase();
          const mCode = (m.customer_code || '').trim().toLowerCase();
          return mCust === custClean || (mCode && mCode === custClean);
        });

        if (allCustomerMips.length === 0) {
          prdPartSelect.disabled = true;
          const noOpt = document.createElement('option');
          noOpt.value = '';
          noOpt.textContent = '-- No Parts issued in MIP for this customer --';
          prdPartSelect.appendChild(noOpt);
          return;
        }

        // Filter out already saved MIP parts (except current record in edit mode)
        const availableCustomerMips = allCustomerMips.filter(m => {
          const mIssueNo = (m.issue_no || '').trim().toLowerCase();
          const mPartCode = (m.part_code || '').trim().toLowerCase();
          const isAlreadySaved = loggedProductionEntries.some(lp => {
            if (isEdit && String(lp.id) === String(editPrdId.value)) return false;
            const lpMip = (lp.mip_no || '').trim().toLowerCase();
            const lpPart = (lp.part_code || '').trim().toLowerCase();
            return lpMip && lpPart && lpMip === mIssueNo && lpPart === mPartCode;
          });
          return !isAlreadySaved;
        });

        if (availableCustomerMips.length === 0) {
          prdPartSelect.disabled = true;
          const noOpt = document.createElement('option');
          noOpt.value = '';
          noOpt.textContent = '-- All MIP parts already saved for this customer --';
          prdPartSelect.appendChild(noOpt);
          return;
        }

        prdPartSelect.disabled = false;
        const defOpt = document.createElement('option');
        defOpt.value = '';
        defOpt.textContent = '-- Select Part Name --';
        prdPartSelect.appendChild(defOpt);

        availableCustomerMips.forEach(m => {
          const opt = document.createElement('option');
          const issueNo = (m.issue_no || '').trim();
          opt.value = issueNo || m.part_code;
          opt.setAttribute('data-so', m.so_number || '');
          opt.setAttribute('data-mip', issueNo);
          opt.setAttribute('data-part-code', m.part_code || '');

          const pName = (m.part_name || (partsCatalog[m.part_code] ? partsCatalog[m.part_code].part_name : m.part_code) || '').trim();
          opt.setAttribute('data-part-name', pName);

          // Display Part Name and MIP No.
          if (issueNo) {
            opt.textContent = `${pName} (${issueNo})`;
          } else {
            opt.textContent = pName;
          }

          if (selectedVal && (
            (issueNo && selectedVal.toLowerCase() === issueNo.toLowerCase()) ||
            (m.part_code && selectedVal.toLowerCase() === m.part_code.toLowerCase()) ||
            (pName && selectedVal.toLowerCase() === pName.toLowerCase()) ||
            (m.so_number && selectedVal.toLowerCase() === m.so_number.toLowerCase())
          )) {
            opt.selected = true;
          }
          prdPartSelect.appendChild(opt);
        });
      }

      function clearMipData(clearCustomer = true) {
        if (clearCustomer) {
          if (prdCustomerSelect) prdCustomerSelect.value = '';
          populatePartDropdown('');
        }
        const tblWrap = document.getElementById('fetchedMipTableWrap');
        if (tblWrap) tblWrap.style.display = 'none';
        const tbody = document.getElementById('tblChildPartsBody');
        if (tbody) tbody.innerHTML = '';
        const countBadge = document.getElementById('childPartsCountBadge');
        if (countBadge) countBadge.textContent = '0 Items';
        const elCust = document.getElementById('tblMipCust');
        if (elCust) elCust.textContent = '-';
        const elPartCode = document.getElementById('tblMipPartCode');
        if (elPartCode) elPartCode.textContent = '-';
        const elPartName = document.getElementById('tblMipPartName');
        if (elPartName) elPartName.textContent = '-';
        const elRecBy = document.getElementById('tblMipReceivedBy');
        if (elRecBy) elRecBy.textContent = '-';

        document.getElementById('inputMipNo').value = '';
        document.getElementById('inputCustomerName').value = '';
        document.getElementById('inputCustomerCode').value = '';
        document.getElementById('inputSoNumber').value = '';
        document.getElementById('inputWorkOrder').value = '';
        document.getElementById('inputPartCode').value = '';
        document.getElementById('inputPartName').value = '';
        document.getElementById('inputDepartment').value = '';
        document.getElementById('inputReceivedBy').value = '';
        if (inputTargetQty) inputTargetQty.value = '0';
        if (inputOkQty) inputOkQty.value = '0';
        if (inputRejectedQty) inputRejectedQty.value = '0';

        const submitBtn = document.getElementById('savePrdSubmitBtn');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.style.opacity = '1';
        }
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

      function loadDataForPart(selectedVal) {
        if (!selectedVal) {
          clearMipData(false);
          return;
        }

        const match = (availableMips || []).find(m => 
          (m.issue_no && m.issue_no.toLowerCase() === selectedVal.toLowerCase()) ||
          (m.part_code && m.part_code.toLowerCase() === selectedVal.toLowerCase()) ||
          (m.part_name && m.part_name.toLowerCase() === selectedVal.toLowerCase()) ||
          (m.so_number && m.so_number.toLowerCase() === selectedVal.toLowerCase())
        );

        const tblWrap = document.getElementById('fetchedMipTableWrap');

        if (match) {
          // 1. Sync hidden fields
          document.getElementById('inputMipNo').value = match.issue_no || '';
          document.getElementById('inputCustomerName').value = match.customer_name || (prdCustomerSelect ? prdCustomerSelect.value : '');
          document.getElementById('inputCustomerCode').value = match.customer_code || '';
          document.getElementById('inputSoNumber').value = match.so_number || '';
          document.getElementById('inputWorkOrder').value = match.work_order || match.so_number || '';

          // 2. Part details
          const pCode = match.part_code || '';
          const pName = match.part_name || (partsCatalog[pCode] ? partsCatalog[pCode].part_name : pCode);
          document.getElementById('inputPartCode').value = pCode;
          document.getElementById('inputPartName').value = pName;
          document.getElementById('inputDepartment').value = match.department || '';
          document.getElementById('inputReceivedBy').value = match.received_by || '';

          // 3. Process Stage (Automatically marked as 'New Line up')
          if (inputProcess) inputProcess.value = 'New Line up';

          // 4. Target Qty & UOM (Synced to hidden fields)
          const qty = parseFloat(match.issued_qty || 0);
          if (inputTargetQty) inputTargetQty.value = qty > 0 ? qty : '0';
          if (inputOkQty) inputOkQty.value = qty > 0 ? qty : '0';
          if (inputRejectedQty) inputRejectedQty.value = '0';
          if (match.uom) {
            document.getElementById('inputUom').value = match.uom;
          }

          // 5. Populate Details Table
          const elNo = document.getElementById('tblMipNo');
          const elCust = document.getElementById('tblMipCust');
          const elPartCode = document.getElementById('tblMipPartCode');
          const elPartName = document.getElementById('tblMipPartName');
          const elQty = document.getElementById('tblMipQty');
          const elReceivedBy = document.getElementById('tblMipReceivedBy');
          const elDate = document.getElementById('tblMipDate');

          if (elNo) elNo.textContent = match.issue_no || '-';
          if (elCust) elCust.textContent = match.customer_name || '-';
          if (elPartCode) elPartCode.textContent = pCode || '-';
          if (elPartName) elPartName.textContent = pName || '-';
          if (elQty) elQty.textContent = `${qty} ${match.uom || 'PCS'}`;
          if (elReceivedBy) elReceivedBy.textContent = match.received_by || '-';
          if (elDate) elDate.textContent = match.issue_date || '-';

          // 6. Child parts BOM
          renderChildParts(match, qty);

          if (tblWrap) tblWrap.style.display = 'block';

          // Duplicate validation check on selected MIP part
          const mIssueNo = (match.issue_no || '').trim().toLowerCase();
          const mPartCode = pCode.trim().toLowerCase();
          const isAlreadySaved = loggedProductionEntries.some(lp => {
            if (isEdit && String(lp.id) === String(editPrdId.value)) return false;
            const lpMip = (lp.mip_no || '').trim().toLowerCase();
            const lpPart = (lp.part_code || '').trim().toLowerCase();
            return lpMip && lpPart && lpMip === mIssueNo && lpPart === mPartCode;
          });

          const submitBtn = document.getElementById('savePrdSubmitBtn');
          if (isAlreadySaved) {
            showToast(`MIP slip '${match.issue_no}' (Part: ${pCode}) has already been saved in Production. It cannot be saved again!`, 'error');
            if (submitBtn) {
              submitBtn.disabled = true;
              submitBtn.style.opacity = '0.5';
              submitBtn.title = 'Cannot save duplicate MIP Part entry';
            }
          } else {
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.style.opacity = '1';
              submitBtn.title = '';
            }
            showToast(`Part ${pName} data loaded (Stage: New Line up)!`, 'success');
            if (inputOperator) inputOperator.focus();
          }
        } else {
          clearMipData(false);
          showToast('No MIP details found for selected Part.', 'error');
        }
      }

      // Event Listeners for Customer & Part Dropdowns
      if (prdCustomerSelect) {
        prdCustomerSelect.addEventListener('change', () => {
          const custName = prdCustomerSelect.value;
          clearMipData(false);
          populatePartDropdown(custName);
        });
      }

      if (prdPartSelect) {
        prdPartSelect.addEventListener('change', () => {
          const selectedVal = prdPartSelect.value;
          if (selectedVal) {
            loadDataForPart(selectedVal);
          } else {
            clearMipData(false);
          }
        });
      }

      // Form Submission
      prdAddForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const id = editPrdId.value;
        const customerName = (document.getElementById('inputCustomerName').value.trim()) || (prdCustomerSelect.value.trim());
        const customerCode = document.getElementById('inputCustomerCode').value.trim();
        const soNumber = (document.getElementById('inputSoNumber').value.trim()) ||
                         (prdPartSelect.selectedOptions[0] ? (prdPartSelect.selectedOptions[0].getAttribute('data-so') || '') : '');
        const mipNo = (document.getElementById('inputMipNo').value.trim()) ||
                      (prdPartSelect.selectedOptions[0] ? (prdPartSelect.selectedOptions[0].getAttribute('data-mip') || prdPartSelect.value.trim()) : '');
        const workOrder = document.getElementById('inputWorkOrder').value.trim() || soNumber;
        const partCode = document.getElementById('inputPartCode').value.trim() ||
                         (prdPartSelect.selectedOptions[0] ? (prdPartSelect.selectedOptions[0].getAttribute('data-part-code') || '') : '');
        const partName = document.getElementById('inputPartName').value.trim() ||
                         (prdPartSelect.selectedOptions[0] ? (prdPartSelect.selectedOptions[0].getAttribute('data-part-name') || '') : '') ||
                         (partsCatalog[partCode] ? partsCatalog[partCode].part_name : partCode);
        const department = document.getElementById('inputDepartment').value.trim();
        const receivedBy = document.getElementById('inputReceivedBy').value.trim();
        const processName = (inputProcess ? inputProcess.value.trim() : '') || 'New Line up';
        const targetQty = parseFloat(inputTargetQty.value || 0);
        const okQty = parseFloat(inputOkQty.value || 0);
        const rejectedQty = parseFloat(inputRejectedQty.value || 0);
        const operatorName = inputOperator.value.trim();
        const uom = document.getElementById('inputUom').value || 'PCS';
        const status = (document.getElementById('inputStatus').value.trim()) || 'New Line up';

        if (!customerName) {
          showToast('Please select a Customer.', 'error');
          return;
        }
        if (!partCode) {
          showToast('Please select a Part Name.', 'error');
          return;
        }
        if (!operatorName) {
          showToast('Please enter Operator / Technician name.', 'error');
          return;
        }

        // Validate duplicate MIP Part
        const isDuplicate = loggedProductionEntries.some(lp => {
          if (id && String(lp.id) === String(id)) return false;
          const lpMip = (lp.mip_no || '').trim().toLowerCase();
          const lpPart = (lp.part_code || '').trim().toLowerCase();
          return lpMip && lpPart && lpMip === mipNo.toLowerCase() && lpPart === partCode.toLowerCase();
        });
        if (isDuplicate) {
          showToast(`Production entry for MIP slip '${mipNo}' (Part: ${partCode}) is already saved. Duplicate entries are not allowed.`, 'error');
          return;
        }

        const payload = {
          action: id ? 'update' : 'create',
          id: id,
          mip_no: mipNo,
          customer_name: customerName,
          customer_code: customerCode,
          so_number: soNumber,
          work_order: workOrder,
          part_code: partCode,
          part_name: partName,
          department: department,
          received_by: receivedBy,
          process_name: processName,
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

          // Clear old stale workflow states and run logs so the new entry starts completely fresh
          if (!id) {
            const cleanMip = mipNo || '';
            const cleanPart = partCode || '';
            try {
              [localStorage, sessionStorage].forEach(store => {
                if (cleanMip) {
                  store.removeItem(`wip_wf_${encodeURIComponent(cleanMip)}`);
                  store.removeItem(`wip_wf_${cleanMip}`);
                }
                Object.keys(store).forEach(k => {
                  const matchMip = cleanMip && (k.includes(encodeURIComponent(cleanMip)) || k.includes(cleanMip));
                  const matchPart = cleanPart && (k.includes(encodeURIComponent(cleanPart)) || k.includes(cleanPart));
                  if ((k.startsWith('wip_wf_') || k.startsWith('wip_run_logs_')) && (matchMip || matchPart)) {
                    store.removeItem(k);
                  }
                });
              });
            } catch (e) {}
          }

          showToast(id ? 'Production entry updated successfully!' : 'Production entry logged successfully!', 'success');
          setTimeout(() => {
            window.location.href = 'production.php';
          }, 600);
        } catch (err) {
          showToast('Network error while saving production entry.', 'error');
          submitBtn.disabled = false;
          submitBtn.textContent = origText;
        }
      });

      // Initialize on Page Load
      const isEdit = <?php echo $isEditMode ? 'true' : 'false'; ?>;
      const initialCust = <?php echo json_encode($existingEntry['customer_name'] ?? ''); ?>;
      const initialMip = <?php echo json_encode($existingEntry['mip_no'] ?? ''); ?>;
      const initialPart = <?php echo json_encode($existingEntry['part_code'] ?? ''); ?>;
      const initialProcess = <?php echo json_encode($existingEntry['process_name'] ?? ''); ?>;

      initCustomerDropdown(initialCust);

      if (isEdit && initialCust) {
        populatePartDropdown(initialCust, initialMip || initialPart);

        const tblWrap = document.getElementById('fetchedMipTableWrap');
        const elNo = document.getElementById('tblMipNo');
        const elCust = document.getElementById('tblMipCust');
        const elPartCode = document.getElementById('tblMipPartCode');
        const elPartName = document.getElementById('tblMipPartName');
        const elQty = document.getElementById('tblMipQty');
        const elReceivedBy = document.getElementById('tblMipReceivedBy');
        const elDate = document.getElementById('tblMipDate');

        if (elNo) elNo.textContent = initialMip || '-';
        if (elCust) elCust.textContent = initialCust || '-';
        if (elPartCode) elPartCode.textContent = initialPart || '-';
        if (elPartName) elPartName.textContent = <?php echo json_encode($existingEntry['part_name'] ?? ''); ?> || (partsCatalog[initialPart] ? partsCatalog[initialPart].part_name : '-');
        if (elQty) elQty.textContent = `${<?php echo floatval($existingEntry['target_qty'] ?? 0); ?>} ${<?php echo json_encode($existingEntry['uom'] ?? 'PCS'); ?>}`;
        if (elReceivedBy) elReceivedBy.textContent = <?php echo json_encode($existingEntry['received_by'] ?? '-'); ?>;
        if (elDate) elDate.textContent = <?php echo json_encode(formatDateDMY($existingEntry['created_at'] ?? '')); ?>;

        const match = availableMips.find(m => m.issue_no === initialMip) || { part_code: initialPart, issued_qty: <?php echo floatval($existingEntry['target_qty'] ?? 0); ?> };
        renderChildParts(match, <?php echo floatval($existingEntry['target_qty'] ?? 0); ?>);
        if (tblWrap) tblWrap.style.display = 'block';
      }

    });
  </script>
</body>
</html>
