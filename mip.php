<?php
/**
 * WIP Management Portal - Material Issue for Production (MIP)
 * Simple, clean UI for issuing material/parts to shop floor.
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Format stock cleanly (e.g. 50 instead of 50.000, but 50.5 if decimal)
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

// Fetch active customers, parts, and child parts data from database
$pdo = getDBConnection();
$customerList = [];
$partList = [];
$childPartStockMap = [];

if ($pdo) {
    try {
        $stmtCust = $pdo->query("SELECT id, customer_code, customer_name FROM customer_master WHERE LOWER(status) = 'active' ORDER BY customer_name ASC");
        $customerList = $stmtCust ? $stmtCust->fetchAll(PDO::FETCH_ASSOC) : [];

        $stmtParts = $pdo->query("SELECT id, part_code, part_name, child_parts FROM part_master WHERE LOWER(status) = 'active' ORDER BY part_code ASC");
        $partList = $stmtParts ? $stmtParts->fetchAll(PDO::FETCH_ASSOC) : [];

        // Fetch child part master for real-time stock and UOM info
        $stmtCp = $pdo->query("SELECT part_code, part_name, grade_spec, current_stock, uom FROM child_part_master");
        if ($stmtCp) {
            while ($row = $stmtCp->fetch(PDO::FETCH_ASSOC)) {
                $cCode = trim($row['part_code'] ?? '');
                if ($cCode !== '') {
                    $childPartStockMap[$cCode] = [
                        'part_code' => $cCode,
                        'part_name' => $row['part_name'] ?? '',
                        'grade_spec' => $row['grade_spec'] ?? '',
                        'current_stock' => floatval($row['current_stock'] ?? 0),
                        'uom' => !empty($row['uom']) ? $row['uom'] : 'NOS'
                    ];
                }
            }
        }
    } catch (PDOException $e) {
        // Continue gracefully
    }
}

// Parse and normalize child parts catalog for quick lookup
$partsCatalog = [];
foreach ($partList as &$p) {
    $rawCp = $p['child_parts'] ?? '[]';
    $decoded = is_string($rawCp) ? json_decode($rawCp, true) : $rawCp;
    if (!is_array($decoded)) {
        $decoded = [];
    }

    $cleanCpList = [];
    foreach ($decoded as $cpItem) {
        $cpCode = trim($cpItem['code'] ?? ($cpItem['part_code'] ?? ''));
        if ($cpCode === '') continue;

        $cpName = $cpItem['name'] ?? ($cpItem['part_name'] ?? '');
        $cpQty = floatval($cpItem['qty'] ?? ($cpItem['quantity'] ?? 1));
        if ($cpQty <= 0) $cpQty = 1;

        $cpStock = 0;
        $cpUom = 'NOS';
        if (isset($childPartStockMap[$cpCode])) {
            $cpStock = $childPartStockMap[$cpCode]['current_stock'];
            $cpUom = $childPartStockMap[$cpCode]['uom'];
            if (empty($cpName)) {
                $cpName = $childPartStockMap[$cpCode]['part_name'];
            }
        } elseif (!empty($cpItem['uom'])) {
            $cpUom = $cpItem['uom'];
        }

        $cleanCpList[] = [
            'code' => $cpCode,
            'name' => $cpName,
            'qty' => $cpQty,
            'stock' => $cpStock,
            'uom' => $cpUom
        ];
    }

    $p['child_parts_clean'] = $cleanCpList;
    $partsCatalog[$p['part_code']] = [
        'part_code' => $p['part_code'],
        'part_name' => $p['part_name'],
        'child_parts' => $cleanCpList
    ];
}
unset($p);

// Today date formatted
$todayDmy = date('d-m-Y');
$todaySqlDate = date('Y-m-d');

// Real MIP records from MSSQL database
$mipList = [];
$totalCount = 0;
$totalQty = 0;
$todayCount = 0;
$totalPendingRework = 0;
$totalReworkQty = 0;
$nextIssueNo = 'MIP-001';

if ($pdo) {
    try {
        $stmtMips = $pdo->query("
            SELECT id, issue_no, 
                   CONVERT(VARCHAR(10), issue_date, 120) as issue_date,
                   customer_name, customer_code, so_number,
                   part_code, part_name, issued_qty, uom, 
                   received_by, remarks, 
                   status, rework_qty, rework_completed_qty, rework_pending_qty,
                   closure_status, closure_history,
                   child_parts_details, created_at, updated_at
            FROM material_issue
            ORDER BY id DESC
        ");
        if ($stmtMips) {
            $rawMips = $stmtMips->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawMips as $m) {
                $m['issued_qty'] = floatval($m['issued_qty'] ?? 0);
                $m['rework_qty'] = floatval($m['rework_qty'] ?? 0);
                $m['rework_completed_qty'] = floatval($m['rework_completed_qty'] ?? 0);
                $m['rework_pending_qty'] = floatval($m['rework_pending_qty'] ?? 0);
                $totalQty += $m['issued_qty'];
                $totalPendingRework += $m['rework_pending_qty'];
                $totalReworkQty += $m['rework_qty'];
                $totalCount++;
                if (($m['issue_date'] ?? '') === $todaySqlDate) {
                    $todayCount++;
                }
                $mipList[] = $m;
            }
        }

        // Determine next issue number
        $maxStmt = $pdo->query("SELECT TOP 1 issue_no FROM material_issue WHERE issue_no LIKE 'MIP-%' ORDER BY id DESC");
        $maxRow = $maxStmt ? $maxStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($maxRow && preg_match('/MIP-(\d+)/i', $maxRow['issue_no'], $matches)) {
            $nextNum = intval($matches[1]) + 1;
            $nextIssueNo = 'MIP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        }
    } catch (PDOException $e) {
        // Continue gracefully
    }
}

$pageTitle = 'Material Issue for Production (MIP)';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Material Issue for Production (MIP) - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
    /* Action Buttons in Table (Matching Master Pages) */
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

    .btn-rework-entry {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      background: #ea580c;
      border: 1px solid #c2410c;
      border-radius: 4px;
      font-size: 0.76rem;
      font-weight: 700;
      color: #ffffff !important;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.15s ease;
      white-space: nowrap;
      box-shadow: 0 1px 2px rgba(234, 88, 12, 0.25);
    }
    .btn-rework-entry:hover {
      background: #c2410c;
      transform: translateY(-1px);
    }

    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 9px;
      border-radius: 9999px;
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.01em;
      white-space: nowrap;
    }
    .badge-status-rework-pending {
      background: #fff7ed;
      color: #c2410c;
      border: 1px solid #fed7aa;
    }
    .badge-status-rework-active {
      background: #faf5ff;
      color: #7e22ce;
      border: 1px solid #e9d5ff;
    }
    .badge-status-closed {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #cbd5e1;
    }
    .badge-status-in-prod {
      background: #eff6ff;
      color: #2563eb;
      border: 1px solid #bfdbfe;
    }
    .badge-status-open {
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
    }

    /* Sub metadata below main text */
    .sub-meta {
      display: block;
      font-size: 0.75rem;
      color: var(--text-sub);
      margin-top: 2px;
      font-variant-numeric: tabular-nums;
    }

    .qty-badge {
      display: inline-block;
      font-weight: 700;
      color: #0f172a;
      background: #f1f5f9;
      padding: 3px 9px;
      border-radius: 5px;
      border: 1px solid #e2e8f0;
      font-variant-numeric: tabular-nums;
    }

    /* Popup Modal Form (Identical to Master Pages) */
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
      max-width: 720px;
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
      padding: 22px;
      max-height: calc(85vh - 130px);
      overflow-y: auto;
    }

    .popup-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    @media (max-width: 540px) {
      .popup-form-grid {
        grid-template-columns: 1fr;
      }
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
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

    /* Simple Child Parts Table in Modal */
    .cp-simple-container {
      margin-top: 4px;
    }
    .cp-simple-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
    }
    .cp-simple-header label {
      margin: 0;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }
    .cp-simple-badge {
      font-size: 0.74rem;
      color: var(--text-sub);
      font-weight: 500;
    }
    .cp-simple-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.81rem;
    }
    .cp-simple-table th {
      background: #f8fafc;
      color: #475569;
      font-size: 0.74rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.02em;
      padding: 7px 10px;
      border-bottom: 1px solid var(--border);
      border-right: 1px solid #f1f5f9;
      text-align: left;
      white-space: nowrap;
    }
    .cp-simple-table th:last-child {
      border-right: none;
    }
    .cp-simple-table td {
      padding: 7px 10px;
      border-bottom: 1px solid var(--border);
      border-right: 1px solid #f8fafc;
      color: var(--text-main);
      vertical-align: middle;
    }
    .cp-simple-table td:last-child {
      border-right: none;
    }
    .cp-simple-table tbody tr:last-child td {
      border-bottom: none;
    }
    .cp-simple-table tbody tr:hover td {
      background: #fbfcfe;
    }
    .cp-simple-empty {
      padding: 12px;
      text-align: center;
      color: var(--text-sub);
      font-size: 0.8rem;
      background: #ffffff;
    }
    .cp-simple-note {
      font-size: 0.74rem;
      color: var(--text-sub);
      margin-top: 5px;
    }

    /* Clean Child Part Chips in Table */
    .cp-chip-list {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
    }
    .cp-chip {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 1px 6px;
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      border-radius: 4px;
      font-size: 0.74rem;
      color: var(--text-main);
      white-space: nowrap;
    }
    .cp-chip .cp-code {
      font-weight: 600;
      color: var(--primary);
    }
    .cp-chip .cp-qty {
      color: var(--text-sub);
      font-size: 0.72rem;
    }

    /* Compact Single-Row Stats Cards for MIP */
    .mip-stats-row {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }
    .mip-stats-row .stat-box {
      padding: 10px 14px;
      border-radius: 8px;
      background: var(--bg-white);
      border: 1px solid var(--border);
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
      display: flex;
      flex-direction: column;
      justify-content: center;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .mip-stats-row .stat-box:hover {
      transform: translateY(-1px);
      box-shadow: 0 3px 8px rgba(0, 0, 0, 0.05);
    }
    .mip-stats-row .stat-number {
      font-size: 1.35rem;
      font-weight: 700;
      line-height: 1.15;
    }
    .mip-stats-row .stat-title {
      font-size: 0.76rem;
      font-weight: 500;
      color: var(--text-sub);
      margin-top: 2px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    @media (max-width: 1024px) {
      .mip-stats-row {
        grid-template-columns: repeat(2, 1fr);
      }
    }
    @media (max-width: 520px) {
      .mip-stats-row {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>

  <div class="layout-container">

    <!-- Reusable Sidebar -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">

      <!-- Reusable Header Component -->
      <?php include __DIR__ . '/includes/header.php'; ?>

      <!-- Content Body -->
      <div class="content-body">

        <!-- 4 Stats Cards in Single Compact Row -->
        <div class="stats-row mip-stats-row">
          <div class="simple-card stat-box">
            <div class="stat-number" id="statTotalIssues"><?php echo number_format($totalCount); ?></div>
            <div class="stat-title">Total Issues (MIP)</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statTotalQty"><?php echo formatCleanStock($totalQty); ?></div>
            <div class="stat-title">Total Qty Issued</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statTodayIssues"><?php echo number_format($todayCount); ?></div>
            <div class="stat-title">Today's Issues</div>
          </div>

          <div class="simple-card stat-box" style="border-left: 3px solid #ea580c;">
            <div class="stat-number" style="color: #ea580c;" id="statPendingRework"><?php echo formatCleanStock($totalPendingRework); ?></div>
            <div class="stat-title">Pending Rework (NOS)</div>
          </div>
        </div>

        <!-- Table Card -->
        <div class="simple-card table-box">
          
          <div class="table-bar">
            <h2 class="box-title">Material Issue for Production (MIP)</h2>
            <div class="table-actions">
              <input type="text" id="mipSearch" class="simple-input" placeholder="Search Issue No, Customer, SO No, Part, Received By...">
              <?php if (hasPermission('mip', 'create')): ?>
              <button type="button" class="btn-primary" id="openAddModalBtn">+ Issue Material</button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Table Container -->
          <div class="table-wrap">
            <table class="simple-table" id="mipTable">
              <thead>
                <tr>
                  <th style="width: 45px; text-align: center;">Sr No.</th>
                  <th>Customer</th>
                  <th style="width: 135px;">Issue No. & Date</th>
                  <th>Part Description</th>
                  <th style="width: 90px; text-align: center;">Issued Qty</th>
                  <th style="width: 55px; text-align: center;">UOM</th>
                  <th style="width: 140px; text-align: center;">Status</th>
                  <th>Received By</th>
                  <th style="width: 220px; text-align: center;">Action</th>
                </tr>
              </thead>
              <tbody id="mipTableBody">
                <?php if (empty($mipList)): ?>
                  <tr id="emptyTableRow">
                    <td colspan="9" style="text-align: center; padding: 40px 20px; color: var(--text-sub);">
                      No Material Issue records found in database. Click <strong>+ Issue Material</strong> to create your first production issue slip.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php $sr = 1; ?>
                  <?php foreach ($mipList as $item): ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>"
                        data-issue_no="<?php echo htmlspecialchars($item['issue_no']); ?>"
                        data-issue_date="<?php echo htmlspecialchars($item['issue_date']); ?>"
                        data-customer_name="<?php echo htmlspecialchars($item['customer_name'] ?? ''); ?>"
                        data-customer_code="<?php echo htmlspecialchars($item['customer_code'] ?? ''); ?>"
                        data-so_number="<?php echo htmlspecialchars($item['so_number'] ?? ''); ?>"
                        data-part_code="<?php echo htmlspecialchars($item['part_code']); ?>"
                        data-part_name="<?php echo htmlspecialchars($item['part_name']); ?>"
                        data-issued_qty="<?php echo htmlspecialchars($item['issued_qty']); ?>"
                        data-uom="<?php echo htmlspecialchars($item['uom']); ?>"
                        data-status="<?php echo htmlspecialchars($item['status'] ?? 'Open'); ?>"
                        data-rework_qty="<?php echo htmlspecialchars($item['rework_qty'] ?? 0); ?>"
                        data-rework_completed_qty="<?php echo htmlspecialchars($item['rework_completed_qty'] ?? 0); ?>"
                        data-rework_pending_qty="<?php echo htmlspecialchars($item['rework_pending_qty'] ?? 0); ?>"
                        data-closure_status="<?php echo htmlspecialchars($item['closure_status'] ?? ''); ?>"
                        data-closure_history="<?php echo htmlspecialchars($item['closure_history'] ?? ''); ?>"
                        data-remarks="<?php echo htmlspecialchars($item['remarks'] ?? ''); ?>"
                        data-received_by="<?php echo htmlspecialchars($item['received_by'] ?? ''); ?>"
                        data-child_parts_details="<?php echo htmlspecialchars($item['child_parts_details'] ?? ''); ?>">
                      <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $sr++; ?></td>
                      <td>
                        <strong><?php echo htmlspecialchars(!empty($item['customer_name']) ? $item['customer_name'] : '-'); ?></strong>
                        <?php if (!empty($item['customer_code'])): ?>
                          <span class="sub-meta"><?php echo htmlspecialchars($item['customer_code']); ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <strong><?php echo htmlspecialchars($item['issue_no']); ?></strong>
                        <?php if (floatval($item['rework_qty'] ?? 0) > 0 || floatval($item['rework_pending_qty'] ?? 0) > 0): ?>
                          <span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>
                        <?php endif; ?>
                        <span class="sub-meta"><?php echo htmlspecialchars(formatDateDMY($item['issue_date'])); ?></span>
                      </td>
                      <td>
                        <strong><?php echo htmlspecialchars($item['part_code']); ?></strong> - <?php echo htmlspecialchars($item['part_name']); ?>
                      </td>
                      <td style="text-align: center;">
                        <span class="qty-badge"><?php echo formatCleanStock($item['issued_qty']); ?></span>
                      </td>
                      <td style="text-align: center;"><?php echo htmlspecialchars($item['uom']); ?></td>
                      <td style="text-align: center;">
                        <?php 
                          $rPending = floatval($item['rework_pending_qty'] ?? 0);
                          $mStatus = trim($item['status'] ?? 'Open');

                          if ($rPending > 0) {
                              echo '<span class="badge-status badge-status-rework-pending" title="Parts pending rework"><span style="font-size: 0.85rem;">⟳</span> Rework Pending (' . intval($rPending) . ')</span>';
                          } elseif ($mStatus === 'Rework In Progress') {
                              echo '<span class="badge-status badge-status-rework-active" title="Rework in production"><span style="font-size: 0.85rem;">⟳</span> Rework Active</span>';
                          } elseif ($mStatus === 'Closed') {
                              echo '<span class="badge-status badge-status-closed">✓ Closed</span>';
                          } elseif ($mStatus === 'In Production') {
                              echo '<span class="badge-status badge-status-in-prod">In Production</span>';
                          } else {
                              echo '<span class="badge-status badge-status-open">Open</span>';
                          }
                        ?>
                      </td>
                      <td>
                        <strong style="color: var(--text-main);"><?php echo htmlspecialchars(!empty($item['received_by']) ? $item['received_by'] : '-'); ?></strong>
                      </td>
                      <td style="text-align: center;">
                        <div style="display: flex; gap: 4px; justify-content: center; align-items: center; flex-wrap: wrap;">
                          <?php if (floatval($item['rework_pending_qty'] ?? 0) > 0): ?>
                          <a href="production-add.php?mip=<?php echo urlencode($item['issue_no']); ?>&rework=1" class="btn-rework-entry" title="Log Rework Production Entry for <?php echo intval($item['rework_pending_qty']); ?> rework parts">
                            <span style="font-size: 0.85rem;">⟳</span> Log Rework (<?php echo intval($item['rework_pending_qty']); ?>)
                          </a>
                          <?php endif; ?>
                          <button type="button" class="btn-view" title="View Details">View</button>
                          <button type="button" class="btn-print" title="Print Slip (A4)">Print</button>
                          <?php if (hasPermission('mip', 'update')): ?>
                          <button type="button" class="btn-edit" title="Edit Entry">Edit</button>
                          <?php endif; ?>
                          <?php if (hasPermission('mip', 'delete')): ?>
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
       Add / Edit MIP Popup Modal Form
       ========================================================= -->
  <div id="mipModal" class="modal-overlay">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title" id="modalFormTitle">+ Issue Material for Production</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="mipForm">
        <div class="modal-body">
          <input type="hidden" id="editItemId" value="">

          <div class="popup-form-grid">
            
            <!-- 1. Select Customer (Above MIP No) -->
            <div class="form-group" style="grid-column: 1 / -1;">
              <label for="inputCustomerSelect">Select Customer *</label>
              <select id="inputCustomerSelect" class="form-control" required>
                <?php if (empty($customerList)): ?>
                  <option value="">-- No Active Customers Available --</option>
                <?php else: ?>
                  <option value="">-- Select Customer --</option>
                  <?php foreach ($customerList as $cust): ?>
                    <option value="<?php echo htmlspecialchars($cust['customer_name']); ?>"
                            data-code="<?php echo htmlspecialchars($cust['customer_code'] ?? ''); ?>">
                      <?php echo htmlspecialchars(!empty($cust['customer_code']) ? ($cust['customer_code'] . ' - ' . $cust['customer_name']) : $cust['customer_name']); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <!-- 2. Issue No (MIP No), SO Number & Issue Date -->
            <div style="grid-column: 1 / -1; display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px;">
              <div class="form-group">
                <label for="inputIssueNo">Issue No. *</label>
                <input type="text" id="inputIssueNo" class="form-control" placeholder="e.g., MIP-001" value="<?php echo htmlspecialchars($nextIssueNo); ?>" required>
              </div>

              <div class="form-group">
                <label for="inputSoNumber">SO Number</label>
                <input type="text" id="inputSoNumber" class="form-control" placeholder="e.g., SO-1001 (optional)">
              </div>

              <div class="form-group">
                <label for="inputIssueDate">Issue Date *</label>
                <input type="date" id="inputIssueDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
              </div>
            </div>

            <!-- 4. Select Part (Replaced Material Type and Select Item) -->
            <div class="form-group" style="grid-column: 1 / -1;">
              <label for="inputPartSelect">Select Part *</label>
              <select id="inputPartSelect" class="form-control" required>
                <?php if (empty($partList)): ?>
                  <option value="">-- No Active Parts Available --</option>
                <?php else: ?>
                  <option value="">-- Select Part --</option>
                  <?php foreach ($partList as $p): ?>
                    <option value="<?php echo htmlspecialchars($p['part_code']); ?>" 
                            data-name="<?php echo htmlspecialchars($p['part_name']); ?>"
                            data-child-parts="<?php echo htmlspecialchars(json_encode($p['child_parts_clean'] ?? [])); ?>">
                      <?php echo htmlspecialchars($p['part_code']); ?> - <?php echo htmlspecialchars($p['part_name']); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <!-- 5. Issued Qty -->
            <div class="form-group">
              <label for="inputIssueQty">Issued Quantity *</label>
              <input type="number" step="any" min="0.001" id="inputIssueQty" class="form-control" placeholder="0" required>
            </div>

            <!-- 6. UOM -->
            <div class="form-group">
              <label for="inputUom">UOM *</label>
              <select id="inputUom" class="form-control" required>
                <option value="NOS" selected>NOS</option>
                <option value="PCS">PCS</option>
                <option value="SET">SET</option>
                <option value="KG">KG</option>
                <option value="MTR">MTR</option>
                <option value="SQM">SQM</option>
                <option value="TON">TON</option>
                <option value="LTR">LTR</option>
              </select>
            </div>

            <!-- Child Parts Requirement (BOM) Section - Simple Table -->
            <div class="form-group" id="childPartsSection" style="grid-column: 1 / -1; display: none;">
              <div class="cp-simple-container">
                <div class="cp-simple-header">
                  <label>RM & Child Parts Required (BOM)</label>
                  <span class="cp-simple-badge" id="cpCountBadge"></span>
                </div>

                <div style="width: 100%; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; background: #fff;">
                  <table class="cp-simple-table" id="cpBomTable" style="margin: 0; border: none;">
                    <thead>
                      <tr>
                        <th style="width: 32px; text-align: center;">#</th>
                        <th>RM & Child Part Code & Name</th>
                        <th style="width: 95px; text-align: center;">BOM Qty</th>
                        <th style="width: 105px; text-align: center;">Total Req.</th>
                        <th style="width: 110px; text-align: center;">Current Stock</th>
                        <th style="width: 95px; text-align: center;">Status</th>
                      </tr>
                    </thead>
                    <tbody id="cpBomTableBody">
                      <!-- Dynamic rows generated by JS -->
                    </tbody>
                  </table>
                  <div class="cp-simple-empty" id="cpBomEmpty" style="display: none;">
                    No RM & child parts configured for this part in Part Master.
                  </div>
                </div>
                <div class="cp-simple-note" id="cpBomSummaryText"></div>
              </div>
            </div>

            <!-- 7. Received By (Required) -->
            <div class="form-group">
              <label for="inputReceivedBy">Received By *</label>
              <input type="text" id="inputReceivedBy" class="form-control" placeholder="Name of person receiving material" required>
            </div>

            <!-- 8. Remarks -->
            <div class="form-group">
              <label for="inputRemarks">Remarks</label>
              <input type="text" id="inputRemarks" class="form-control" placeholder="Notes or instructions">
            </div>

          </div>
        </div>

        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
          <div id="modalShortageNotice" style="display: none; color: #dc2626; font-size: 0.82rem; font-weight: 600; align-items: center; gap: 6px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span id="modalShortageNoticeText">RM & Child parts stock is insufficient — Save blocked!</span>
          </div>
          <div style="display: flex; gap: 8px; margin-left: auto;">
            <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
            <button type="submit" class="btn-primary" id="saveSubmitBtn">+ Save Issue</button>
          </div>
        </div>
      </form>
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
          Are you sure you want to delete Issue Entry <strong id="deleteTargetCode" style="color:#0f172a;"></strong>?
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-delete" id="confirmDeleteBtn" style="background:#ef4444; color:#fff; border-color:#ef4444; padding:6px 14px; font-weight:600;">Delete</button>
      </div>
    </div>
  </div>

  <!-- =========================================================
       View MIP Details & Child Parts Breakdown Modal
       ========================================================= -->
  <div id="viewModal" class="modal-overlay">
    <div class="modal-card" style="max-width: 680px;">
      <div class="modal-header">
        <h3 class="modal-title" id="viewModalTitle">Issue Details</h3>
        <button type="button" class="modal-close-btn" id="closeViewBtn" aria-label="Close modal">&times;</button>
      </div>

      <div class="modal-body">
        <!-- Overview summary grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; margin-bottom: 16px;">
          <div>
            <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-sub); font-weight: 600;">Issue No & Date</div>
            <div style="font-size: 0.88rem; font-weight: 700; color: var(--text-main); margin-top: 2px;" id="viewIssueNoDate"></div>
          </div>
          <div>
            <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-sub); font-weight: 600;">Customer</div>
            <div style="font-size: 0.88rem; font-weight: 700; color: var(--text-main); margin-top: 2px;" id="viewCustomerName"></div>
          </div>
          <div>
            <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-sub); font-weight: 600;">SO Number</div>
            <div style="font-size: 0.88rem; font-weight: 700; color: #2563eb; margin-top: 2px;" id="viewSoNumber">-</div>
          </div>
          <div>
            <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-sub); font-weight: 600;">Part Code & Name</div>
            <div style="font-size: 0.88rem; font-weight: 700; color: var(--text-main); margin-top: 2px;" id="viewPartName"></div>
          </div>
          <div>
            <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-sub); font-weight: 600;">Issued Quantity</div>
            <div style="font-size: 0.88rem; font-weight: 700; color: var(--primary); margin-top: 2px;" id="viewIssuedQty"></div>
          </div>
          <div>
            <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-sub); font-weight: 600;">Received By</div>
            <div style="font-size: 0.88rem; font-weight: 600; color: var(--text-main); margin-top: 2px;" id="viewReceivedBy">-</div>
          </div>
        </div>

        <!-- RM & Child Parts Breakdown Heading -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
          <label style="margin: 0; font-size: 0.84rem; font-weight: 700; color: var(--text-main);">RM & Child Parts Used Details</label>
          <span id="viewCpCountBadge" style="font-size: 0.75rem; color: var(--text-sub); font-weight: 500;"></span>
        </div>

        <!-- RM & Child Parts Table -->
        <div style="width: 100%; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; background: #fff;">
          <table class="cp-simple-table" id="viewCpTable" style="margin: 0; border: none;">
            <thead>
              <tr>
                <th style="width: 36px; text-align: center;">#</th>
                <th>RM & Child Part Code & Name</th>
                <th style="width: 120px; text-align: center;">BOM Qty</th>
                <th style="width: 130px; text-align: center;">Total Consumed</th>
              </tr>
            </thead>
            <tbody id="viewCpTableBody">
              <!-- Populated dynamically via JS -->
            </tbody>
          </table>
          <div id="viewCpEmpty" style="display: none; padding: 14px; text-align: center; color: var(--text-sub); font-size: 0.82rem;">
            No RM & child parts configured for this part in Part Master.
          </div>
        </div>

        <div id="viewRemarksRow" style="margin-top: 12px; font-size: 0.8rem; color: var(--text-sub); background: #fdfdfd; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); display: none;">
          <strong>Remarks:</strong> <span id="viewRemarksText"></span>
        </div>
        <div id="viewReceivedByRow" style="margin-top: 8px; font-size: 0.8rem; color: var(--text-sub); background: #fdfdfd; padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); display: none;">
          <strong>Received By:</strong> <span id="viewReceivedByText"></span>
        </div>

        <!-- Rework Section (Shows if MIP has pending or completed rework) -->
        <div id="viewReworkSection" style="display: none; margin-top: 14px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 12px 14px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <div style="font-size: 0.84rem; font-weight: 700; color: #c2410c; display: flex; align-items: center; gap: 5px;">
              <span>⟳</span> Rework Status & Allocation
            </div>
            <a href="#" id="viewLogReworkBtn" class="btn-rework-entry" style="font-size: 0.74rem; padding: 3px 8px;">
              <span>⟳</span> Log Rework Entry
            </a>
          </div>
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; font-size: 0.80rem; margin-top: 6px;">
            <div>
              <span style="color: #9a3412;">Total Rework Logged:</span>
              <strong id="viewReworkTotal" style="color: #c2410c; display: block; font-size: 0.95rem;">0 NOS</strong>
            </div>
            <div>
              <span style="color: #9a3412;">Pending to Log:</span>
              <strong id="viewReworkPending" style="color: #ea580c; display: block; font-size: 0.95rem;">0 NOS</strong>
            </div>
            <div>
              <span style="color: #9a3412;">Completed:</span>
              <strong id="viewReworkCompleted" style="color: #16a34a; display: block; font-size: 0.95rem;">0 NOS</strong>
            </div>
          </div>
          <div id="viewReworkHistoryNotes" style="font-size: 0.76rem; color: #7c2d12; margin-top: 8px; border-top: 1px dashed #fdba74; padding-top: 6px; display: none;"></div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="closeViewFooterBtn">Close</button>
        <button type="button" class="btn-primary" id="printViewBtn" style="display: inline-flex; align-items: center; gap: 6px; background: #059669; border-color: #059669;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 6 2 18 2 18 9"></polyline>
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
            <rect x="6" y="14" width="12" height="8"></rect>
          </svg>
          Print Slip (A4)
        </button>
      </div>
    </div>
  </div>

  <!-- JavaScript -->
  <script>
    const partsCatalog = <?php echo json_encode($partsCatalog); ?>;
    const childPartStockMap = <?php echo json_encode($childPartStockMap); ?>;

    function formatStock(val) {
      const num = parseFloat(val || 0);
      if (num % 1 === 0) {
        return num.toLocaleString('en-US');
      }
      return num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
    }

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

    function showToast(message, type = 'success') {
      let container = document.getElementById('toastContainer');
      if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
      }
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

    async function refreshChildPartStock() {
      try {
        const res = await fetch('api/child_part_master.php');
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
          json.data.forEach(item => {
            const cCode = (item.part_code || '').trim();
            if (cCode) {
              if (!childPartStockMap[cCode]) childPartStockMap[cCode] = {};
              childPartStockMap[cCode].current_stock = parseFloat(item.current_stock || 0);
              childPartStockMap[cCode].part_name = item.part_name || '';
              childPartStockMap[cCode].uom = item.uom || 'NOS';
            }
          });
        }
      } catch(e) {}
    }

    function createTableRow(item) {
      const tr = document.createElement('tr');
      updateTableRow(tr, item);
      return tr;
    }

    function updateTableRow(tr, item) {
      const reworkPending = parseFloat(item.rework_pending_qty || 0);
      const mStatus = (item.status || 'Open').trim();

      tr.setAttribute('data-id', item.id);
      tr.setAttribute('data-issue_no', item.issue_no);
      tr.setAttribute('data-issue_date', item.issue_date);
      tr.setAttribute('data-customer_name', item.customer_name || '');
      tr.setAttribute('data-customer_code', item.customer_code || '');
      tr.setAttribute('data-so_number', item.so_number || '');
      tr.setAttribute('data-part_code', item.part_code);
      tr.setAttribute('data-part_name', item.part_name);
      tr.setAttribute('data-issued_qty', item.issued_qty);
      tr.setAttribute('data-uom', item.uom);
      tr.setAttribute('data-status', mStatus);
      tr.setAttribute('data-rework_qty', item.rework_qty || 0);
      tr.setAttribute('data-rework_completed_qty', item.rework_completed_qty || 0);
      tr.setAttribute('data-rework_pending_qty', reworkPending);
      tr.setAttribute('data-closure_status', item.closure_status || '');
      tr.setAttribute('data-closure_history', typeof item.closure_history === 'string' ? item.closure_history : JSON.stringify(item.closure_history || []));
      tr.setAttribute('data-remarks', item.remarks || '');
      tr.setAttribute('data-received_by', item.received_by || '');
      tr.setAttribute('data-child_parts_details', typeof item.child_parts_details === 'string' ? item.child_parts_details : JSON.stringify(item.child_parts_details || []));

      let statusHtml = '';
      if (reworkPending > 0) {
        statusHtml = `<span class="badge-status badge-status-rework-pending" title="Parts pending rework"><span style="font-size:0.85rem;">⟳</span> Rework Pending (${reworkPending})</span>`;
      } else if (mStatus === 'Rework In Progress') {
        statusHtml = `<span class="badge-status badge-status-rework-active"><span style="font-size:0.85rem;">⟳</span> Rework Active</span>`;
      } else if (mStatus === 'Closed') {
        statusHtml = `<span class="badge-status badge-status-closed">✓ Closed</span>`;
      } else if (mStatus === 'In Production') {
        statusHtml = `<span class="badge-status badge-status-in-prod">In Production</span>`;
      } else {
        statusHtml = `<span class="badge-status badge-status-open">Open</span>`;
      }

      let reworkActionHtml = '';
      if (reworkPending > 0) {
        reworkActionHtml = `
          <a href="production-add.php?mip=${encodeURIComponent(item.issue_no)}&rework=1" class="btn-rework-entry" title="Log Rework Production Entry for ${reworkPending} rework parts">
            <span style="font-size:0.85rem;">⟳</span> Log Rework (${reworkPending})
          </a>
        `;
      }

      tr.innerHTML = `
        <td style="color: var(--text-sub); font-weight: 600; text-align: center;">1</td>
        <td>
          <strong>${escapeHtml(item.customer_name || '-')}</strong>
          ${item.customer_code ? `<span class="sub-meta">${escapeHtml(item.customer_code)}</span>` : ''}
        </td>
        <td>
          <strong>${escapeHtml(item.issue_no)}</strong>
          ${(parseFloat(item.rework_qty || 0) > 0 || parseFloat(item.rework_pending_qty || 0) > 0) ? '<span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>' : ''}
          <span class="sub-meta">${formatDateDMY(item.issue_date)}</span>
        </td>
        <td>
          <strong>${escapeHtml(item.part_code)}</strong> - ${escapeHtml(item.part_name)}
        </td>
        <td style="text-align: center;">
          <span class="qty-badge">${formatStock(item.issued_qty)}</span>
        </td>
        <td style="text-align: center;">${escapeHtml(item.uom)}</td>
        <td style="text-align: center;">${statusHtml}</td>
        <td>
          <strong style="color: var(--text-main);">${escapeHtml(item.received_by || '-')}</strong>
        </td>
        <td style="text-align: center;">
          <div style="display: flex; gap: 4px; justify-content: center; align-items: center; flex-wrap: wrap;">
            ${reworkActionHtml}
            <button type="button" class="btn-view" title="View Details">View</button>
            <button type="button" class="btn-print" title="Print Slip (A4)">Print</button>
            <button type="button" class="btn-edit" title="Edit Entry">Edit</button>
            <button type="button" class="btn-delete" title="Delete Entry">Delete</button>
          </div>
        </td>
      `;
    }

    document.addEventListener('DOMContentLoaded', () => {

      const openAddModalBtn = document.getElementById('openAddModalBtn');
      const mipModal = document.getElementById('mipModal');
      const closeModalBtn = document.getElementById('closeModalBtn');
      const cancelModalBtn = document.getElementById('cancelModalBtn');
      const mipForm = document.getElementById('mipForm');
      const modalFormTitle = document.getElementById('modalFormTitle');
      const editItemId = document.getElementById('editItemId');

      const inputCustomerSelect = document.getElementById('inputCustomerSelect');
      const inputSoNumber = document.getElementById('inputSoNumber');
      const inputPartSelect = document.getElementById('inputPartSelect');
      const inputIssueQty = document.getElementById('inputIssueQty');
      const inputUom = document.getElementById('inputUom');
      const mipTableBody = document.getElementById('mipTableBody');
      const mipSearch = document.getElementById('mipSearch');

      // BOM UI Elements
      const childPartsSection = document.getElementById('childPartsSection');
      const cpCountBadge = document.getElementById('cpCountBadge');
      const cpBomInfoText = document.getElementById('cpBomInfoText');
      const cpBomTableWrap = document.getElementById('cpBomTableWrap');
      const cpBomTableBody = document.getElementById('cpBomTableBody');
      const cpBomEmpty = document.getElementById('cpBomEmpty');
      const cpBomSummaryText = document.getElementById('cpBomSummaryText');

      const deleteModal = document.getElementById('deleteModal');
      const closeDeleteBtn = document.getElementById('closeDeleteBtn');
      const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
      const deleteTargetCode = document.getElementById('deleteTargetCode');

      let pendingDeleteRow = null;
      let pendingDeleteId = null;
      let currentShortages = 0;
      let currentShortageList = [];

      // Function to render Child Parts Requirement based on selected part and issued qty
      function renderChildPartsRequirement() {
        const pCode = inputPartSelect.value;
        const saveBtn = document.getElementById('saveSubmitBtn');
        const modalShortageNotice = document.getElementById('modalShortageNotice');
        const modalShortageNoticeText = document.getElementById('modalShortageNoticeText');

        currentShortages = 0;
        currentShortageList = [];

        if (!pCode) {
          childPartsSection.style.display = 'none';
          cpBomTableBody.innerHTML = '';
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.style.opacity = '';
            saveBtn.style.cursor = '';
            saveBtn.style.pointerEvents = '';
            saveBtn.title = '';
          }
          if (modalShortageNotice) modalShortageNotice.style.display = 'none';
          return;
        }

        childPartsSection.style.display = 'block';

        let childParts = [];
        if (partsCatalog[pCode] && Array.isArray(partsCatalog[pCode].child_parts)) {
          childParts = partsCatalog[pCode].child_parts;
        } else {
          const opt = inputPartSelect.options[inputPartSelect.selectedIndex];
          if (opt && opt.getAttribute('data-child-parts')) {
            try {
              childParts = JSON.parse(opt.getAttribute('data-child-parts')) || [];
            } catch (err) {
              childParts = [];
            }
          }
        }

        if (!childParts || childParts.length === 0) {
          cpCountBadge.textContent = '0 items';
          document.getElementById('cpBomTable').style.display = 'none';
          cpBomEmpty.style.display = 'block';
          cpBomSummaryText.textContent = '';
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.style.opacity = '';
            saveBtn.style.cursor = '';
            saveBtn.style.pointerEvents = '';
            saveBtn.title = '';
          }
          if (modalShortageNotice) modalShortageNotice.style.display = 'none';
          return;
        }

        cpCountBadge.textContent = `${childParts.length} item${childParts.length > 1 ? 's' : ''}`;
        document.getElementById('cpBomTable').style.display = 'table';
        cpBomEmpty.style.display = 'none';

        const rawQty = parseFloat(inputIssueQty.value);
        const hasValidQty = !isNaN(rawQty) && rawQty > 0;
        const multiplier = hasValidQty ? rawQty : 1;
        const uom = inputUom.value || 'NOS';

        // Check if editing an existing record to credit back previously consumed stock
        let oldConsumedMap = {};
        if (editItemId && editItemId.value) {
          const editRow = mipTableBody.querySelector(`tr[data-id="${editItemId.value}"]`);
          if (editRow) {
            try {
              const oldDetails = JSON.parse(editRow.getAttribute('data-child_parts_details') || '[]');
              if (Array.isArray(oldDetails)) {
                oldDetails.forEach(d => {
                  const dc = d.code || d.part_code;
                  if (dc) oldConsumedMap[dc] = parseFloat(d.consumed_qty || 0);
                });
              }
            } catch(e) {}
          }
        }

        let rowsHtml = '';
        let totalShortages = 0;

        childParts.forEach((cp, idx) => {
          const code = cp.code || cp.part_code || '';
          const name = cp.name || cp.part_name || (childPartStockMap[code] ? childPartStockMap[code].part_name : '-');
          const bomRatio = parseFloat(cp.qty || 1);
          const cpUom = cp.uom || (childPartStockMap[code] ? childPartStockMap[code].uom : 'NOS');
          const totalReq = bomRatio * multiplier;

          let stock = 0;
          if (childPartStockMap[code] && typeof childPartStockMap[code].current_stock !== 'undefined') {
            stock = parseFloat(childPartStockMap[code].current_stock);
          } else if (typeof cp.stock !== 'undefined') {
            stock = parseFloat(cp.stock);
          }

          const effectiveStock = stock + (oldConsumedMap[code] || 0);
          let isShort = false;
          let shortBy = 0;
          let statusBadge = '';

          if (hasValidQty) {
            if (effectiveStock < totalReq) {
              isShort = true;
              totalShortages++;
              shortBy = totalReq - effectiveStock;
              currentShortageList.push(`${code} - ${name} (Short: ${formatStock(shortBy)} ${cpUom})`);
              statusBadge = `<span style="display:inline-block; padding:3px 8px; border-radius:4px; font-weight:600; font-size:0.75rem; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; white-space:nowrap;">✕ Short (-${formatStock(shortBy)} ${cpUom})</span>`;
            } else {
              statusBadge = `<span style="display:inline-block; padding:3px 8px; border-radius:4px; font-weight:600; font-size:0.75rem; background:#dcfce7; color:#15803d; border:1px solid #86efac; white-space:nowrap;">✓ Available</span>`;
            }
          } else {
            // Preview mode before quantity is typed: check against 1 unit BOM ratio
            if (effectiveStock < bomRatio) {
              isShort = true;
              totalShortages++;
              shortBy = bomRatio - effectiveStock;
              currentShortageList.push(`${code} - ${name} (Stock: ${formatStock(effectiveStock)} ${cpUom})`);
              statusBadge = `<span style="display:inline-block; padding:3px 8px; border-radius:4px; font-weight:600; font-size:0.75rem; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; white-space:nowrap;">${effectiveStock <= 0 ? '✕ Out of Stock (0)' : '✕ Short for 1 unit'}</span>`;
            } else {
              statusBadge = `<span style="display:inline-block; padding:3px 8px; border-radius:4px; font-weight:600; font-size:0.75rem; background:#dcfce7; color:#15803d; border:1px solid #86efac; white-space:nowrap;">In Stock</span>`;
            }
          }

          const rowBg = isShort ? 'background: #fff5f5;' : '';
          const stockHtml = isShort
            ? `<span style="color: #dc2626; font-weight: 700;">${formatStock(stock)} ${cpUom}</span>`
            : `<span style="color: #15803d; font-weight: 600;">${formatStock(stock)} ${cpUom}</span>`;

          rowsHtml += `
            <tr style="${rowBg}">
              <td style="text-align: center; color: var(--text-sub); font-size: 0.78rem;">${idx + 1}</td>
              <td><strong>${escapeHtml(code)}</strong> - ${escapeHtml(name)}</td>
              <td style="text-align: center; font-variant-numeric: tabular-nums;">${formatStock(bomRatio)} ${cpUom}</td>
              <td style="text-align: center; font-variant-numeric: tabular-nums; font-weight: 700; color: #2563eb;">${formatStock(totalReq)} ${cpUom}</td>
              <td style="text-align: center; font-variant-numeric: tabular-nums;">${stockHtml}</td>
              <td style="text-align: center;">${statusBadge}</td>
            </tr>
          `;
        });

        cpBomTableBody.innerHTML = rowsHtml;
        currentShortages = totalShortages;

        if (totalShortages > 0) {
          // Disable save button and display warning
          if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.style.opacity = '0.45';
            saveBtn.style.cursor = 'not-allowed';
            saveBtn.style.pointerEvents = 'none';
            saveBtn.title = 'Cannot Save: RM & Child parts stock is insufficient';
          }
          if (modalShortageNotice) {
            modalShortageNotice.style.display = 'flex';
            if (modalShortageNoticeText) {
              modalShortageNoticeText.textContent = `${totalShortages} RM & child part(s) short — Save blocked!`;
            }
          }

          const qtyDesc = hasValidQty ? `${formatStock(rawQty)} ${uom}` : '1 unit';
          cpBomSummaryText.innerHTML = `
            <div style="margin-top: 10px; padding: 12px 14px; background: #fef2f2; border: 1.5px solid #f87171; border-radius: 8px; color: #991b1b; display: flex; align-items: flex-start; gap: 10px;">
              <span style="font-size: 1.3rem; line-height: 1;">🚫</span>
              <div style="flex: 1;">
                <div style="font-weight: 700; font-size: 0.88rem; margin-bottom: 3px;">Material Issue Blocked: Insufficient RM & Child Part Stock</div>
                <div style="font-size: 0.82rem; line-height: 1.45; color: #b91c1c;">
                  <strong>${totalShortages} RM & child part(s)</strong> me required quantity (${qtyDesc}) ke anusaar stock kam hai. Production me material issue save karne ka option band kar diya gaya hai. Kripya pehle stock inward karein.
                </div>
              </div>
            </div>
          `;
        } else {
          // Sufficient stock
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.style.opacity = '';
            saveBtn.style.cursor = '';
            saveBtn.style.pointerEvents = '';
            saveBtn.title = '';
          }
          if (modalShortageNotice) {
            modalShortageNotice.style.display = 'none';
          }

          if (hasValidQty) {
            cpBomSummaryText.innerHTML = `
              <div style="margin-top: 10px; padding: 10px 14px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; color: #15803d; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.15rem; line-height: 1;">✓</span>
                <div style="font-size: 0.82rem; font-weight: 600;">
                  Sabhi RM & child parts ka sufficient stock uplabdh hai (${formatStock(rawQty)} ${uom}). Issue save karne ke liye ready hai.
                </div>
              </div>
            `;
          } else {
            cpBomSummaryText.innerHTML = `<span style="font-size: 0.82rem; color: #64748b;">Showing 1 unit BOM ratio. Enter Issued Quantity above to calculate total required.</span>`;
          }
        }
      }

      // Event listeners for dynamic child parts requirement calculation
      inputPartSelect.addEventListener('change', renderChildPartsRequirement);
      inputIssueQty.addEventListener('input', renderChildPartsRequirement);
      inputIssueQty.addEventListener('change', renderChildPartsRequirement);
      inputUom.addEventListener('change', renderChildPartsRequirement);

      // Open Modal
      function openModal(title = '+ Issue Material for Production') {
        modalFormTitle.textContent = title;
        mipModal.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function closeModal() {
        mipModal.classList.remove('active');
        document.body.style.overflow = '';
        mipForm.reset();
        editItemId.value = '';
        childPartsSection.style.display = 'none';
        cpBomTableBody.innerHTML = '';
        cpBomSummaryText.innerHTML = '';
        currentShortages = 0;
        currentShortageList = [];
        const saveBtn = document.getElementById('saveSubmitBtn');
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.style.opacity = '';
          saveBtn.style.cursor = '';
          saveBtn.style.pointerEvents = '';
          saveBtn.title = '';
        }
        const modalShortageNotice = document.getElementById('modalShortageNotice');
        if (modalShortageNotice) modalShortageNotice.style.display = 'none';
      }

      openAddModalBtn.addEventListener('click', async () => {
        editItemId.value = '';
        try {
          const res = await fetch('api/mip.php');
          const d = await res.json();
          if (d.success && d.next_issue_no) {
            document.getElementById('inputIssueNo').value = d.next_issue_no;
          }
        } catch(e) {}
        document.getElementById('inputIssueDate').value = new Date().toISOString().split('T')[0];
        inputCustomerSelect.value = '';
        inputSoNumber.value = '';
        inputPartSelect.value = '';
        document.getElementById('inputIssueQty').value = '';
        document.getElementById('inputUom').value = 'NOS';
        document.getElementById('inputReceivedBy').value = '';
        document.getElementById('inputRemarks').value = '';
        await refreshChildPartStock();
        renderChildPartsRequirement();
        openModal('+ Issue Material for Production');
      });

      closeModalBtn.addEventListener('click', closeModal);
      cancelModalBtn.addEventListener('click', closeModal);

      // Search filter
      mipSearch.addEventListener('input', () => {
        const q = mipSearch.value.toLowerCase().trim();
        const rows = mipTableBody.querySelectorAll('tr');
        rows.forEach(r => {
          if (r.id === 'emptyTableRow') return;
          r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
      });

      // Submit Form (Add or Edit via Database API)
      mipForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const id = editItemId.value;
        const issueNo = document.getElementById('inputIssueNo').value.trim();
        const issueDate = document.getElementById('inputIssueDate').value.trim();
        
        const custSel = inputCustomerSelect.options[inputCustomerSelect.selectedIndex];
        const customerName = custSel ? custSel.value : '';
        const customerCode = custSel ? (custSel.getAttribute('data-code') || '') : '';
        const soNumber = inputSoNumber.value.trim();

        const sel = inputPartSelect.options[inputPartSelect.selectedIndex];
        const partCode = sel ? sel.value : '';
        const partName = sel ? (sel.getAttribute('data-name') || '') : '';
        const qty = parseFloat(document.getElementById('inputIssueQty').value || 0);
        const uom = document.getElementById('inputUom').value.trim() || 'NOS';
        const receivedBy = document.getElementById('inputReceivedBy').value.trim();
        const remarks = document.getElementById('inputRemarks').value.trim();

        if (!customerName) {
          showToast('Please select a Customer.', 'error');
          inputCustomerSelect.focus();
          return;
        }

        if (!partCode) {
          showToast('Please select a Part.', 'error');
          inputPartSelect.focus();
          return;
        }
        if (qty <= 0) {
          showToast('Issued quantity must be greater than 0.', 'error');
          return;
        }
        if (!receivedBy) {
          showToast('Received By is required.', 'error');
          inputReceivedBy.focus();
          return;
        }

        // Hard validation: Block submission if there are any child part shortages
        if (currentShortages > 0) {
          showToast(`Cannot save issue: RM & Child parts stock is insufficient (${currentShortageList.join(', ')}). Please replenish stock first.`, 'error');
          return;
        }

        const saveBtn = document.getElementById('saveSubmitBtn');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        try {
          const payload = {
            action: id ? 'update' : 'create',
            id: id || undefined,
            issue_no: issueNo,
            issue_date: issueDate,
            customer_name: customerName,
            customer_code: customerCode,
            so_number: soNumber,
            part_code: partCode,
            part_name: partName,
            issued_qty: qty,
            uom: uom,
            received_by: receivedBy,
            remarks: remarks
          };

          const res = await fetch('api/mip.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });

          const result = await res.json();
          if (!res.ok || !result.success) {
            throw new Error(result.message || 'Operation failed. Please try again.');
          }

          const savedData = result.data;
          const emptyRow = document.getElementById('emptyTableRow');
          if (emptyRow) emptyRow.remove();

          if (id) {
            // Update existing row
            const row = mipTableBody.querySelector(`tr[data-id="${id}"]`);
            if (row) {
              updateTableRow(row, savedData);
            }
            showToast(result.message || 'Issue updated successfully!', 'success');
          } else {
            // Prepend new row
            const newRow = createTableRow(savedData);
            mipTableBody.insertBefore(newRow, mipTableBody.firstChild);
            showToast(result.message || 'Material issued successfully!', 'success');
          }

          reindexRows();
          updateCounters();
          closeModal();
          refreshChildPartStock();

        } catch (err) {
          showToast(err.message || 'Error occurred while saving.', 'error');
        } finally {
          saveBtn.disabled = false;
          saveBtn.textContent = '+ Save Issue';
        }
      });

      // View Modal Elements & Handlers
      const viewModal = document.getElementById('viewModal');
      const closeViewBtn = document.getElementById('closeViewBtn');
      const closeViewFooterBtn = document.getElementById('closeViewFooterBtn');
      const printViewBtn = document.getElementById('printViewBtn');
      let currentViewingData = null;

      function closeViewModal() {
        viewModal.classList.remove('active');
        document.body.style.overflow = '';
      }
      closeViewBtn.addEventListener('click', closeViewModal);
      closeViewFooterBtn.addEventListener('click', closeViewModal);

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

      // Print Slip on A4 Sheet
      function printIssueSlip(data) {
        let childParts = [];
        if (data.childPartsDetails) {
          try {
            const parsed = typeof data.childPartsDetails === 'string' ? JSON.parse(data.childPartsDetails) : data.childPartsDetails;
            if (Array.isArray(parsed) && parsed.length > 0) {
              childParts = parsed;
            }
          } catch(e) {}
        }
        if (childParts.length === 0 && partsCatalog[data.partCode] && Array.isArray(partsCatalog[data.partCode].child_parts)) {
          childParts = partsCatalog[data.partCode].child_parts;
        }

        let cpRowsHtml = '';
        if (childParts.length === 0) {
          cpRowsHtml = '<tr><td colspan="5" style="text-align:center; padding:14px; color:#64748b;">No RM & child parts configured for this part in Part Master.</td></tr>';
        } else {
          childParts.forEach((cp, idx) => {
            const cCode = cp.code || cp.part_code || '';
            const cName = cp.name || cp.part_name || (childPartStockMap[cCode] ? childPartStockMap[cCode].part_name : '-');
            const ratio = parseFloat(cp.ratio || cp.qty || 1);
            const cpUom = cp.uom || (childPartStockMap[cCode] ? childPartStockMap[cCode].uom : 'NOS');
            const totalUsed = typeof cp.consumed_qty !== 'undefined' ? parseFloat(cp.consumed_qty) : (ratio * data.qty);

            cpRowsHtml += `
              <tr>
                <td style="text-align:center;">${idx + 1}</td>
                <td><strong>${escapeHtml(cCode)}</strong></td>
                <td>${escapeHtml(cName)}</td>
                <td style="text-align:center;">${formatStock(ratio)} ${cpUom}/unit</td>
                <td style="text-align:center; font-weight:bold; color:#0f172a;">${formatStock(totalUsed)} ${cpUom}</td>
              </tr>
            `;
          });
        }

        const printHtml = `
          <!DOCTYPE html>
          <html>
          <head>
            <meta charset="UTF-8">
            <title>MIP_Slip_${escapeHtml(data.issueNo)}</title>
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

              /* Child Parts Consumed Table */
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
                height: 45px;
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
                <div class="doc-title">Material Issue for Production (MIP) Slip</div>
              </div>
              <div class="slip-badge">
                <div class="slip-no">ISSUE NO: ${escapeHtml(data.issueNo)}</div>
                <div class="slip-date">Issue Date: ${formatDateDMY(data.issueDate)}</div>
              </div>
            </div>

            <table class="meta-table">
              <tr>
                <td class="lbl">Customer:</td>
                <td class="val">
                  <strong>${escapeHtml(data.customerName || '-')}</strong>
                  ${data.customerCode ? ` <span style="color:#64748b; font-size:11px;">(${escapeHtml(data.customerCode)})</span>` : ''}
                </td>
                <td class="lbl">SO Number:</td>
                <td class="val">
                  <strong style="color: #1e3a8a;">${escapeHtml(data.soNumber || '-')}</strong>
                </td>
              </tr>
              <tr>
                <td class="lbl">Part Code & Name:</td>
                <td class="val" colspan="3"><strong>${escapeHtml(data.partCode)}</strong> - ${escapeHtml(data.partName)}</td>
              </tr>
              <tr>
                <td class="lbl">MIP Slip No:</td>
                <td class="val" style="vertical-align: middle;">
                  ${data.issueNo && data.issueNo !== '-' ? `
                  <div style="display: inline-flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">
                    ${generateBarcode128Svg(data.issueNo, 42)}
                    <div style="font-weight: 700; color: #0f172a; font-size: 11.5px; letter-spacing: 0.5px; margin-top: 3px; text-align: center;">
                      ${escapeHtml(data.issueNo)}
                    </div>
                  </div>` : `<strong>-</strong>`}
                </td>
                <td class="lbl">Issued Quantity:</td>
                <td class="val"><strong>${formatStock(data.qty)} ${escapeHtml(data.uom)}</strong></td>
              </tr>
              <tr>
                <td class="lbl">Received By:</td>
                <td class="val"><strong>${escapeHtml(data.receivedBy || '-')}</strong></td>
                <td class="lbl">Remarks:</td>
                <td class="val">${escapeHtml(data.remarks || 'None')}</td>
              </tr>
            </table>

            <div class="section-heading">RM & Child Parts Consumed / Required Details (BOM)</div>
            <table class="items-table">
              <thead>
                <tr>
                  <th style="width:35px; text-align:center;">#</th>
                  <th style="width:130px;">RM & Child Part Code</th>
                  <th>RM & Child Part Name</th>
                  <th style="width:110px; text-align:center;">BOM Qty</th>
                  <th style="width:130px; text-align:center;">Total Consumed</th>
                </tr>
              </thead>
              <tbody>
                ${cpRowsHtml}
              </tbody>
            </table>

            <table class="sig-table">
              <tr>
                <td>
                  <div class="sig-title">ISSUED BY (STORE)</div>
                  <div class="sig-space"></div>
                  <div class="sig-line">Signature & Date</div>
                </td>
                <td>
                  <div class="sig-title">RECEIVED BY (PRODUCTION)</div>
                  ${data.receivedBy ? `<div style="margin: 8px 0 4px; font-size: 13px; font-weight: bold; color: #0f172a;">${escapeHtml(data.receivedBy)}</div>` : '<div class="sig-space"></div>'}
                  <div class="sig-line">Signature & Date</div>
                </td>
                <td>
                  <div class="sig-title">AUTHORIZED SIGNATORY</div>
                  <div class="sig-space"></div>
                  <div class="sig-line">Signature & Date</div>
                </td>
              </tr>
            </table>

            <div class="footer-bar">
              <span>System Generated Issue Slip - WIP Management Portal</span>
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

      printViewBtn.addEventListener('click', () => {
        if (currentViewingData) {
          printIssueSlip(currentViewingData);
        }
      });

      function openViewModal(data) {
        currentViewingData = data;
        document.getElementById('viewModalTitle').textContent = `Issue Details - ${data.issueNo}`;
        document.getElementById('viewIssueNoDate').innerHTML = `${escapeHtml(data.issueNo)} <span style="font-size:0.75rem; color:var(--text-sub); display:block;">${formatDateDMY(data.issueDate)}</span>`;
        document.getElementById('viewCustomerName').innerHTML = `<strong>${escapeHtml(data.customerName || '-')}</strong>${data.customerCode ? `<span style="font-size:0.75rem; color:var(--text-sub); display:block;">${escapeHtml(data.customerCode)}</span>` : ''}`;
        document.getElementById('viewSoNumber').textContent = data.soNumber || '-';
        document.getElementById('viewPartName').innerHTML = `<strong>${escapeHtml(data.partCode)}</strong> - ${escapeHtml(data.partName)}`;
        document.getElementById('viewIssuedQty').textContent = `${formatStock(data.qty)} ${escapeHtml(data.uom)}`;
        document.getElementById('viewReceivedBy').textContent = data.receivedBy || '-';

        const remarksRow = document.getElementById('viewRemarksRow');
        if (data.remarks) {
          remarksRow.style.display = 'block';
          document.getElementById('viewRemarksText').textContent = data.remarks;
        } else {
          remarksRow.style.display = 'none';
        }

        let childParts = [];
        if (data.childPartsDetails) {
          try {
            const parsed = typeof data.childPartsDetails === 'string' ? JSON.parse(data.childPartsDetails) : data.childPartsDetails;
            if (Array.isArray(parsed) && parsed.length > 0) {
              childParts = parsed;
            }
          } catch(e) {}
        }
        if (childParts.length === 0 && partsCatalog[data.partCode] && Array.isArray(partsCatalog[data.partCode].child_parts)) {
          childParts = partsCatalog[data.partCode].child_parts;
        }

        const viewCpTable = document.getElementById('viewCpTable');
        const viewCpEmpty = document.getElementById('viewCpEmpty');
        const viewCpBody = document.getElementById('viewCpTableBody');
        const viewCpBadge = document.getElementById('viewCpCountBadge');

        if (!childParts || childParts.length === 0) {
          viewCpTable.style.display = 'none';
          viewCpEmpty.style.display = 'block';
          viewCpBadge.textContent = '0 RM & Child Parts';
        } else {
          viewCpTable.style.display = 'table';
          viewCpEmpty.style.display = 'none';
          viewCpBadge.textContent = `${childParts.length} RM & Child Part${childParts.length > 1 ? 's' : ''}`;

          let rowsHtml = '';
          childParts.forEach((cp, idx) => {
            const cCode = cp.code || cp.part_code || '';
            const cName = cp.name || cp.part_name || (childPartStockMap[cCode] ? childPartStockMap[cCode].part_name : '-');
            const ratio = parseFloat(cp.ratio || cp.qty || 1);
            const cpUom = cp.uom || (childPartStockMap[cCode] ? childPartStockMap[cCode].uom : 'NOS');
            const totalUsed = typeof cp.consumed_qty !== 'undefined' ? parseFloat(cp.consumed_qty) : (ratio * data.qty);

            rowsHtml += `
              <tr>
                <td style="text-align: center; color: var(--text-sub); font-size: 0.78rem;">${idx + 1}</td>
                <td><strong>${escapeHtml(cCode)}</strong> - ${escapeHtml(cName)}</td>
                <td style="text-align: center; font-variant-numeric: tabular-nums;">${formatStock(ratio)} ${cpUom}/unit</td>
                <td style="text-align: center; font-variant-numeric: tabular-nums; font-weight: 700; color: #2563eb;">${formatStock(totalUsed)} ${cpUom}</td>
              </tr>
            `;
          });
          viewCpBody.innerHTML = rowsHtml;
        }

        // Rework section in view modal
        const rwSection = document.getElementById('viewReworkSection');
        const rwBtn = document.getElementById('viewLogReworkBtn');
        const rPend = parseFloat(data.reworkPendingQty || 0);
        const rTot = parseFloat(data.reworkQty || 0);
        const rComp = parseFloat(data.reworkCompletedQty || 0);

        if (rwSection) {
          if (rPend > 0 || rTot > 0 || rComp > 0) {
            rwSection.style.display = 'block';
            document.getElementById('viewReworkTotal').textContent = `${formatStock(rTot)} ${escapeHtml(data.uom)}`;
            document.getElementById('viewReworkPending').textContent = `${formatStock(rPend)} ${escapeHtml(data.uom)}`;
            document.getElementById('viewReworkCompleted').textContent = `${formatStock(rComp)} ${escapeHtml(data.uom)}`;

            if (rPend > 0 && rwBtn) {
              rwBtn.style.display = 'inline-flex';
              rwBtn.href = `production-add.php?mip=${encodeURIComponent(data.issueNo)}&rework=1`;
              rwBtn.innerHTML = `<span>⟳</span> Log Rework (${formatStock(rPend)})`;
            } else if (rwBtn) {
              rwBtn.style.display = 'none';
            }

            const notesEl = document.getElementById('viewReworkHistoryNotes');
            if (notesEl) {
              if (data.closureHistory) {
                try {
                  const hist = typeof data.closureHistory === 'string' ? JSON.parse(data.closureHistory) : data.closureHistory;
                  if (Array.isArray(hist) && hist.length > 0) {
                    const latest = hist[hist.length - 1];
                    notesEl.style.display = 'block';
                    notesEl.innerHTML = `<strong>Latest Log:</strong> ${escapeHtml(latest.event || latest.status)} — ${escapeHtml(latest.reason || '')} <span style="color:#a16207;">(${escapeHtml(latest.at || '')})</span>`;
                  } else {
                    notesEl.style.display = 'none';
                  }
                } catch(e) {
                  notesEl.style.display = 'none';
                }
              } else {
                notesEl.style.display = 'none';
              }
            }
          } else {
            rwSection.style.display = 'none';
          }
        }

        viewModal.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function getRowData(row) {
        return {
          id: row.getAttribute('data-id') || '',
          issueNo: row.getAttribute('data-issue_no') || '',
          issueDate: row.getAttribute('data-issue_date') || '',
          customerName: row.getAttribute('data-customer_name') || '',
          customerCode: row.getAttribute('data-customer_code') || '',
          soNumber: row.getAttribute('data-so_number') || '',
          partCode: row.getAttribute('data-part_code') || '',
          partName: row.getAttribute('data-part_name') || '',
          qty: parseFloat(row.getAttribute('data-issued_qty') || 0),
          uom: row.getAttribute('data-uom') || 'NOS',
          status: row.getAttribute('data-status') || 'Open',
          reworkQty: parseFloat(row.getAttribute('data-rework_qty') || 0),
          reworkCompletedQty: parseFloat(row.getAttribute('data-rework_completed_qty') || 0),
          reworkPendingQty: parseFloat(row.getAttribute('data-rework_pending_qty') || 0),
          closureStatus: row.getAttribute('data-closure_status') || '',
          closureHistory: row.getAttribute('data-closure_history') || '',
          receivedBy: row.getAttribute('data-received_by') || '',
          remarks: row.getAttribute('data-remarks') || '',
          childPartsDetails: row.getAttribute('data-child_parts_details') || ''
        };
      }

      // Table Action clicks (View, Print, Edit & Delete)
      mipTableBody.addEventListener('click', (e) => {
        const row = e.target.closest('tr');
        if (!row || row.id === 'emptyTableRow') return;

        // View Details
        const viewBtn = e.target.closest('.btn-view');
        if (viewBtn) {
          const rowData = getRowData(row);
          openViewModal(rowData);
          return;
        }

        // Print Slip
        const printBtn = e.target.closest('.btn-print');
        if (printBtn) {
          const rowData = getRowData(row);
          printIssueSlip(rowData);
          return;
        }

        // Edit
        const editBtn = e.target.closest('.btn-edit');
        if (editBtn) {
          editItemId.value = row.getAttribute('data-id');
          document.getElementById('inputIssueNo').value = row.getAttribute('data-issue_no') || '';
          document.getElementById('inputIssueDate').value = row.getAttribute('data-issue_date') || '';
          
          const custName = row.getAttribute('data-customer_name') || '';
          const custCode = row.getAttribute('data-customer_code') || '';
          inputCustomerSelect.value = custName;
          if (inputCustomerSelect.selectedIndex === -1 && custCode) {
            for (let opt of inputCustomerSelect.options) {
              if (opt.getAttribute('data-code') === custCode) {
                inputCustomerSelect.value = opt.value;
                break;
              }
            }
          }

          inputSoNumber.value = row.getAttribute('data-so_number') || '';

          const pCode = row.getAttribute('data-part_code') || '';
          inputPartSelect.value = pCode;

          document.getElementById('inputIssueQty').value = row.getAttribute('data-issued_qty') || '';
          document.getElementById('inputUom').value = row.getAttribute('data-uom') || 'NOS';
          document.getElementById('inputReceivedBy').value = row.getAttribute('data-received_by') || '';
          document.getElementById('inputRemarks').value = row.getAttribute('data-remarks') || '';

          refreshChildPartStock().then(() => {
            renderChildPartsRequirement();
          });
          renderChildPartsRequirement();
          openModal('Edit Issue: ' + (row.getAttribute('data-issue_no') || ''));
          return;
        }

        // Delete
        const deleteBtn = e.target.closest('.btn-delete');
        if (deleteBtn) {
          pendingDeleteRow = row;
          pendingDeleteId = row.getAttribute('data-id');
          deleteTargetCode.textContent = row.getAttribute('data-issue_no') || 'this record';
          deleteModal.classList.add('active');
          document.body.style.overflow = 'hidden';
          return;
        }
      });

      // Confirm Delete via Database API
      confirmDeleteBtn.addEventListener('click', async () => {
        if (!pendingDeleteId) return;

        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.textContent = 'Deleting...';

        try {
          const res = await fetch('api/mip.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: pendingDeleteId })
          });
          const result = await res.json();
          if (!res.ok || !result.success) {
            throw new Error(result.message || 'Delete failed.');
          }

          const deletedIssueNo = result.deleted_issue_no || pendingDeleteRow?.getAttribute('data-issue_no');
          if (deletedIssueNo) {
            try {
              localStorage.removeItem(`wip_wf_${encodeURIComponent(deletedIssueNo)}`);
              localStorage.removeItem(`wip_wf_${deletedIssueNo}`);
              Object.keys(localStorage).forEach(k => {
                if (k.startsWith('wip_wf_') && (k.includes(encodeURIComponent(deletedIssueNo)) || k.includes(deletedIssueNo))) {
                  localStorage.removeItem(k);
                }
              });
              Object.keys(sessionStorage).forEach(k => {
                if (k.startsWith('wip_run_logs_') && (k.includes(encodeURIComponent(deletedIssueNo)) || k.includes(deletedIssueNo))) {
                  sessionStorage.removeItem(k);
                }
              });
            } catch (e) {}
          }

          if (pendingDeleteRow) {
            pendingDeleteRow.remove();
          }

          const remainingRows = mipTableBody.querySelectorAll('tr:not(#emptyTableRow)');
          if (remainingRows.length === 0) {
            mipTableBody.innerHTML = `
              <tr id="emptyTableRow">
                <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--text-sub);">
                  No Material Issue records found in database. Click <strong>+ Issue Material</strong> to create your first production issue slip.
                </td>
              </tr>
            `;
          }

          reindexRows();
          updateCounters();
          closeDelete();
          showToast(result.message || 'MIP record deleted successfully.', 'success');
          refreshChildPartStock();

        } catch (err) {
          showToast(err.message || 'Error deleting record.', 'error');
        } finally {
          confirmDeleteBtn.disabled = false;
          confirmDeleteBtn.textContent = 'Delete';
        }
      });

      function closeDelete() {
        deleteModal.classList.remove('active');
        document.body.style.overflow = '';
      }
      closeDeleteBtn.addEventListener('click', closeDelete);
      cancelDeleteBtn.addEventListener('click', closeDelete);

      function reindexRows() {
        const rows = mipTableBody.querySelectorAll('tr:not(#emptyTableRow)');
        rows.forEach((r, idx) => {
          r.children[0].textContent = idx + 1;
        });
      }

      function updateCounters() {
        const rows = mipTableBody.querySelectorAll('tr:not(#emptyTableRow)');
        let total = rows.length;
        let sumQty = 0;
        let today = 0;
        const todaySqlStr = '<?php echo $todaySqlDate; ?>';
        const todayDmyStr = '<?php echo $todayDmy; ?>';

        rows.forEach(r => {
          sumQty += parseFloat(r.getAttribute('data-issued_qty') || 0);
          const rDate = r.getAttribute('data-issue_date');
          if (rDate === todaySqlStr || rDate === todayDmyStr) {
            today++;
          }
        });

        document.getElementById('statTotalIssues').textContent = total.toLocaleString('en-US');
        document.getElementById('statTotalQty').textContent = formatStock(sumQty);
        document.getElementById('statTodayIssues').textContent = today.toLocaleString('en-US');
      }

    });
  </script>
  <div id="toastContainer" class="toast-container"></div>
</body>
</html>
