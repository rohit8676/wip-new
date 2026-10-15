<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

$pageTitle = 'Executive WIP & Production Dashboard';
$pdo = getDBConnection();

// Initial server-side metrics load for instantaneous, zero-latency rendering
$kpi = [
    'wip_active' => 0, 'wip_total' => 0, 'wip_issued_qty' => 0,
    'prod_ok' => 0, 'prod_target' => 0, 'prod_rejected' => 0,
    'yield_rate' => 100, 'rejection_rate' => 0,
    'rm_stock' => 0, 'rm_count' => 0, 'rm_low_stock' => 0,
    'cp_stock' => 0, 'cp_count' => 0, 'cp_low_stock' => 0,
    'fg_stock' => 0, 'dispatch_qty' => 0
];
$shifts = [];
$processes = [];
$topRm = [];
$topCp = [];
$recentProduction = [];

if ($pdo) {
    try {
        // RM
        $rmRow = $pdo->query("SELECT COUNT(*) AS cnt, ISNULL(SUM(current_stock), 0) AS stock, COUNT(CASE WHEN current_stock <= 5 THEN 1 END) AS low FROM rm_master WHERE status = 'Active' OR status IS NULL")->fetch(PDO::FETCH_ASSOC);
        if ($rmRow) {
            $kpi['rm_count'] = intval($rmRow['cnt']);
            $kpi['rm_stock'] = floatval($rmRow['stock']);
            $kpi['rm_low_stock'] = intval($rmRow['low']);
        }

        // Child Part
        $cpRow = $pdo->query("SELECT COUNT(*) AS cnt, ISNULL(SUM(current_stock), 0) AS stock, COUNT(CASE WHEN current_stock <= 5 THEN 1 END) AS low FROM child_part_master WHERE status = 'Active' OR status IS NULL")->fetch(PDO::FETCH_ASSOC);
        if ($cpRow) {
            $kpi['cp_count'] = intval($cpRow['cnt']);
            $kpi['cp_stock'] = floatval($cpRow['stock']);
            $kpi['cp_low_stock'] = intval($cpRow['low']);
        }

        // WIP
        $mipRow = $pdo->query("SELECT COUNT(*) AS total, ISNULL(SUM(issued_qty), 0) AS issued, COUNT(CASE WHEN status = 'Issued' OR status = 'In Progress' THEN 1 END) AS active FROM material_issue")->fetch(PDO::FETCH_ASSOC);
        if ($mipRow) {
            $kpi['wip_total'] = intval($mipRow['total']);
            $kpi['wip_issued_qty'] = floatval($mipRow['issued']);
            $kpi['wip_active'] = intval($mipRow['active']);
        }

        // Production
        $prdRow = $pdo->query("SELECT ISNULL(SUM(target_qty), 0) AS target, ISNULL(SUM(ok_qty), 0) AS ok_q, ISNULL(SUM(rejected_qty), 0) AS rej_q FROM production_entry")->fetch(PDO::FETCH_ASSOC);
        if ($prdRow) {
            $kpi['prod_target'] = floatval($prdRow['target']);
            $kpi['prod_ok'] = floatval($prdRow['ok_q']);
            $kpi['prod_rejected'] = floatval($prdRow['rej_q']);
            $tot = $kpi['prod_ok'] + $kpi['prod_rejected'];
            $kpi['yield_rate'] = $tot > 0 ? round(($kpi['prod_ok'] / $tot) * 100, 1) : 100;
            $kpi['rejection_rate'] = $tot > 0 ? round(($kpi['prod_rejected'] / $tot) * 100, 1) : 0;
        }

        // FG
        $fgRow = $pdo->query("SELECT ISNULL(SUM(total_ok_qty), 0) AS stock FROM fg_inventory")->fetch(PDO::FETCH_ASSOC);
        if ($fgRow) $kpi['fg_stock'] = floatval($fgRow['stock']);

        // Dispatches
        $dspRow = $pdo->query("SELECT ISNULL(SUM(dispatch_qty), 0) AS dsp FROM fg_dispatch_logs")->fetch(PDO::FETCH_ASSOC);
        if ($dspRow) $kpi['dispatch_qty'] = floatval($dspRow['dsp']);

        // Shifts
        $shifts = $pdo->query("SELECT ISNULL(shift, 'General') AS shift_name, ISNULL(SUM(ok_qty), 0) AS ok_qty, ISNULL(SUM(rejected_qty), 0) AS rejected_qty, ISNULL(SUM(target_qty), 0) AS target_qty FROM production_entry GROUP BY shift ORDER BY shift")->fetchAll(PDO::FETCH_ASSOC);

        // Processes
        $processes = $pdo->query("SELECT ISNULL(process_name, 'General') AS process_name, ISNULL(SUM(ok_qty), 0) AS ok_qty FROM production_entry GROUP BY process_name ORDER BY ok_qty DESC")->fetchAll(PDO::FETCH_ASSOC);

        // Top RM & CP
        $topRm = $pdo->query("SELECT TOP 4 rm_code, rm_name, current_stock, uom FROM rm_master ORDER BY current_stock DESC")->fetchAll(PDO::FETCH_ASSOC);
        $topCp = $pdo->query("SELECT TOP 4 part_code, part_name, current_stock, uom FROM child_part_master ORDER BY current_stock DESC")->fetchAll(PDO::FETCH_ASSOC);

        // Recent Production Entries
        $recentProduction = $pdo->query("SELECT TOP 8 id, mip_no, work_order, part_code, part_name, process_name, shift, target_qty, ok_qty, rejected_qty, operator_name, uom, status, CONVERT(VARCHAR(16), created_at, 120) AS created_at FROM production_entry ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fallback gracefully
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans with local system fallback -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Core Dashboard CSS with cache-busting -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <!-- Embedded Self-Contained Styles: Guaranteed 100% Offline Reliable Layout -->
  <style>
    :root {
      --dash-primary: #2563eb;
      --dash-primary-hover: #1d4ed8;
      --dash-dark: #0f172a;
      --dash-surface: #ffffff;
      --dash-border: #e2e8f0;
      --dash-text: #1e293b;
      --dash-muted: #64748b;
      --dash-success: #10b981;
      --dash-danger: #ef4444;
      --dash-warning: #f59e0b;
    }

    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background-color: #f8fafc;
      color: var(--dash-text);
      margin: 0;
      padding: 0;
    }

    .content-body {
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 22px;
      max-width: 1600px;
    }

    /* Top Toolbar (Refresh Only) */
    .dash-action-bar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      margin-bottom: -6px;
    }

    .btn-banner-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ffffff;
      color: #475569;
      border: 1px solid var(--dash-border);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.84rem;
      font-weight: 600;
      text-decoration: none;
      cursor: pointer;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
      transition: all 0.15s ease;
    }

    .btn-banner-link:hover {
      background: #f8fafc;
      color: #0f172a;
      border-color: #cbd5e1;
      transform: translateY(-1px);
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
    }

    .btn-banner-link.primary-btn {
      background: var(--dash-primary);
      color: #ffffff;
      border-color: var(--dash-primary);
    }

    .btn-banner-link.primary-btn:hover {
      background: var(--dash-primary-hover);
      border-color: var(--dash-primary-hover);
      color: #ffffff;
    }

    .btn-banner-link svg {
      width: 15px;
      height: 15px;
    }

    /* KPI Grid */
    .kpi-row-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
    }

    .kpi-box {
      background: #ffffff;
      border: 1px solid var(--dash-border);
      border-radius: 12px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      transition: all 0.2s ease;
    }

    .kpi-box:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(0, 0, 0, 0.06);
    }

    .kpi-box-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
    }

    .kpi-box-tag {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--dash-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .kpi-icon-circ {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .kpi-icon-circ svg {
      width: 20px;
      height: 20px;
    }

    .kpi-icon-circ.blue { background: #eff6ff; color: #2563eb; }
    .kpi-icon-circ.green { background: #ecfdf5; color: #10b981; }
    .kpi-icon-circ.rose { background: #fff1f2; color: #e11d48; }
    .kpi-icon-circ.amber { background: #fffbeb; color: #d97706; }
    .kpi-icon-circ.purple { background: #f5f3ff; color: #7c3aed; }

    .kpi-box-val {
      font-size: 1.85rem;
      font-weight: 800;
      color: var(--dash-dark);
      line-height: 1.1;
      letter-spacing: -0.02em;
    }

    .kpi-box-foot {
      margin-top: 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.78rem;
      color: var(--dash-muted);
    }

    .kpi-chip {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.74rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
    }

    .kpi-chip.green { background: #ecfdf5; color: #059669; }
    .kpi-chip.amber { background: #fffbeb; color: #b45309; }
    .kpi-chip.blue { background: #eff6ff; color: #1d4ed8; }
    .kpi-chip.red { background: #fef2f2; color: #dc2626; }

    /* Visualizations Grid */
    .viz-grid-row {
      display: grid;
      grid-template-columns: 3fr 2fr;
      gap: 20px;
    }

    .viz-grid-equal {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    @media (max-width: 1024px) {
      .viz-grid-row, .viz-grid-equal {
        grid-template-columns: 1fr;
      }
    }

    .viz-card {
      background: #ffffff;
      border: 1px solid var(--dash-border);
      border-radius: 12px;
      padding: 22px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      display: flex;
      flex-direction: column;
    }

    .viz-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }

    .viz-card-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--dash-dark);
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0;
    }

    .viz-card-title svg {
      width: 18px;
      height: 18px;
      color: var(--dash-primary);
    }

    .viz-card-sub {
      font-size: 0.78rem;
      color: var(--dash-muted);
      margin-top: 3px;
    }

    /* Native SVG Chart Containers */
    .svg-chart-wrap {
      width: 100%;
      height: 270px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .svg-chart-wrap svg {
      width: 100%;
      height: 100%;
      overflow: visible;
    }

    /* Process Progress Bars */
    .proc-row-item {
      display: flex;
      flex-direction: column;
      gap: 6px;
      padding: 10px 0;
      border-bottom: 1px solid #f1f5f9;
    }

    .proc-row-item:last-child {
      border-bottom: none;
    }

    .proc-info {
      display: flex;
      justify-content: space-between;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .proc-bar-track {
      width: 100%;
      height: 10px;
      background: #f1f5f9;
      border-radius: 999px;
      overflow: hidden;
    }

    .proc-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #3b82f6, #1d4ed8);
      border-radius: 999px;
      transition: width 0.6s ease;
    }

    /* Stock List */
    .comp-stock-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 14px;
      background: #f8fafc;
      border-radius: 8px;
      border: 1px solid #f1f5f9;
      margin-bottom: 8px;
    }

    .comp-stock-item:hover {
      background: #f1f5f9;
      border-color: var(--dash-border);
    }

    /* Table styles */
    .dash-table-box {
      background: #ffffff;
      border: 1px solid var(--dash-border);
      border-radius: 12px;
      padding: 22px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    .dash-table-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      flex-wrap: wrap;
      gap: 12px;
    }

    .search-input-box {
      height: 38px;
      padding: 0 12px;
      border: 1px solid var(--dash-border);
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.85rem;
      width: 250px;
      outline: none;
    }

    .search-input-box:focus {
      border-color: var(--dash-primary);
    }

    .table-container {
      overflow-x: auto;
    }

    .dash-data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
      text-align: left;
    }

    .dash-data-table th {
      background: #f8fafc;
      padding: 10px 14px;
      font-weight: 700;
      color: var(--dash-muted);
      border-bottom: 2px solid var(--dash-border);
      white-space: nowrap;
    }

    .dash-data-table td {
      padding: 12px 14px;
      border-bottom: 1px solid #f1f5f9;
      color: var(--dash-text);
      vertical-align: middle;
    }

    .dash-data-table tr:hover td {
      background: #fafbfc;
    }

    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.74rem;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 999px;
    }

    .badge-status.completed { background: #ecfdf5; color: #059669; }
    .badge-status.in-progress { background: #eff6ff; color: #2563eb; }

    .spin-icon-anim {
      animation: spin-loop 0.8s linear infinite;
    }

    @keyframes spin-loop {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>

  <!-- Mobile Sidebar Backdrop -->
  <div id="sidebarBackdrop" class="sidebar-backdrop"></div>

  <div class="layout-container">
    
    <!-- Reusable Sidebar Component -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
      
      <!-- Reusable Top Bar Header Component -->
      <?php include __DIR__ . '/includes/header.php'; ?>

      <!-- Page Body -->
      <div class="content-body">
        
        <!-- Top Action Bar (Refresh Only) -->
        <div class="dash-action-bar">
          <button type="button" class="btn-banner-link" id="refreshDashBtn" title="Refresh Live Data">
            <svg id="refreshIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
            </svg>
            <span>Refresh</span>
          </button>
        </div>

        <!-- KPI Row 5-Cards Grid -->
        <div class="kpi-row-grid">
          
          <!-- Card 1: Active WIP -->
          <div class="kpi-box">
            <div class="kpi-box-head">
              <span class="kpi-box-tag">Active WIP Orders</span>
              <div class="kpi-icon-circ blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="16" y1="13" x2="8" y2="13"></line>
                  <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
              </div>
            </div>
            <div class="kpi-box-val" id="valActiveWip"><?php echo $kpi['wip_active']; ?></div>
            <div class="kpi-box-foot">
              <span>Total Issued: <strong id="valTotalIssuedQty"><?php echo number_format($kpi['wip_issued_qty']); ?> Units</strong></span>
              <span class="kpi-chip blue" id="valWipTotalCount"><?php echo $kpi['wip_total']; ?> Orders</span>
            </div>
          </div>

          <!-- Card 2: Production Output (OK) -->
          <div class="kpi-box">
            <div class="kpi-box-head">
              <span class="kpi-box-tag">Production Output</span>
              <div class="kpi-icon-circ green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
              </div>
            </div>
            <div class="kpi-box-val" id="valProdOk"><?php echo number_format($kpi['prod_ok']); ?></div>
            <div class="kpi-box-foot">
              <span>Target: <strong id="valProdTarget"><?php echo number_format($kpi['prod_target']); ?></strong></span>
              <span class="kpi-chip green" id="valYieldPill">Yield: <?php echo $kpi['yield_rate']; ?>%</span>
            </div>
          </div>

          <!-- Card 3: Quality & Rejections -->
          <div class="kpi-box">
            <div class="kpi-box-head">
              <span class="kpi-box-tag">Rejection & Scrap</span>
              <div class="kpi-icon-circ rose">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="15" y1="9" x2="9" y2="15"></line>
                  <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
              </div>
            </div>
            <div class="kpi-box-val" id="valProdRej"><?php echo number_format($kpi['prod_rejected']); ?></div>
            <div class="kpi-box-foot">
              <span>Scrap Rate: <strong id="valRejRate"><?php echo $kpi['rejection_rate']; ?>%</strong></span>
              <span class="kpi-chip <?php echo ($kpi['rejection_rate'] > 10 ? 'red' : 'green'); ?>" id="valQualityPill">
                <?php echo ($kpi['rejection_rate'] > 10 ? 'High Scrap' : 'Optimal Quality'); ?>
              </span>
            </div>
          </div>

          <!-- Card 4: RM Stock -->
          <div class="kpi-box">
            <div class="kpi-box-head">
              <span class="kpi-box-tag">Raw Material Stock</span>
              <div class="kpi-icon-circ amber">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                </svg>
              </div>
            </div>
            <div class="kpi-box-val" id="valRmStock"><?php echo number_format($kpi['rm_stock']); ?> <span style="font-size: 1rem; font-weight: 500; color: var(--dash-muted);">KG</span></div>
            <div class="kpi-box-foot">
              <span>Active RM: <strong id="valRmCount"><?php echo $kpi['rm_count']; ?> Types</strong></span>
              <span class="kpi-chip <?php echo ($kpi['rm_low_stock'] > 0 ? 'amber' : 'green'); ?>" id="valRmAlertPill">
                <?php echo ($kpi['rm_low_stock'] > 0 ? $kpi['rm_low_stock'] . ' Low Stock' : 'Stock Healthy'); ?>
              </span>
            </div>
          </div>

          <!-- Card 5: FG Store Stock -->
          <div class="kpi-box">
            <div class="kpi-box-head">
              <span class="kpi-box-tag">FG Store Stock</span>
              <div class="kpi-icon-circ purple">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="21 8 21 21 3 21 3 8"></polyline>
                  <rect x="1" y="3" width="22" height="5"></rect>
                  <line x1="10" y1="12" x2="14" y2="12"></line>
                </svg>
              </div>
            </div>
            <div class="kpi-box-val" id="valFgStock"><?php echo number_format($kpi['fg_stock']); ?> <span style="font-size: 1rem; font-weight: 500; color: var(--dash-muted);">Units</span></div>
            <div class="kpi-box-foot">
              <span>Dispatched: <strong id="valDispatchedQty"><?php echo number_format($kpi['dispatch_qty']); ?> Units</strong></span>
              <a href="fg-store.php" class="kpi-chip blue" style="text-decoration: none;">View FG</a>
            </div>
          </div>

        </div>

        <!-- Viz Row 1: Shift Performance & Quality Donut -->
        <div class="viz-grid-row">
          
          <!-- Shift-wise Production Performance Chart -->
          <div class="viz-card">
            <div class="viz-card-head">
              <div>
                <h3 class="viz-card-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                  </svg>
                  Shift-wise Production Performance
                </h3>
                <p class="viz-card-sub">Target Plan vs OK Output vs Rejections across shifts</p>
              </div>
              <div style="display: flex; gap: 12px; font-size: 0.75rem; font-weight: 600;">
                <span style="display: flex; align-items: center; gap: 4px;"><span style="width: 10px; height: 10px; background: #cbd5e1; border-radius: 2px;"></span> Target</span>
                <span style="display: flex; align-items: center; gap: 4px;"><span style="width: 10px; height: 10px; background: #10b981; border-radius: 2px;"></span> OK Qty</span>
                <span style="display: flex; align-items: center; gap: 4px;"><span style="width: 10px; height: 10px; background: #ef4444; border-radius: 2px;"></span> Rejection</span>
              </div>
            </div>
            
            <!-- Native Crisp Responsive SVG Bar Chart -->
            <div class="svg-chart-wrap" id="shiftChartContainer">
              <?php
              $shiftNames = !empty($shifts) ? $shifts : [
                  ['shift_name' => 'Shift A', 'target_qty' => 1, 'ok_qty' => 1, 'rejected_qty' => 0],
                  ['shift_name' => 'Shift B', 'target_qty' => 10, 'ok_qty' => 8, 'rejected_qty' => 2],
                  ['shift_name' => 'Shift C', 'target_qty' => 7, 'ok_qty' => 6, 'rejected_qty' => 1]
              ];
              $maxVal = 1;
              foreach ($shiftNames as $s) {
                  $m = max(floatval($s['target_qty']), floatval($s['ok_qty']) + floatval($s['rejected_qty']));
                  if ($m > $maxVal) $maxVal = $m;
              }
              $maxVal = ceil($maxVal * 1.25);
              if ($maxVal <= 0) $maxVal = 10;
              ?>
              <svg viewBox="0 0 540 230" preserveAspectRatio="xMidYMid meet">
                <!-- Background Gridlines -->
                <line x1="45" y1="30" x2="520" y2="30" stroke="#f1f5f9" stroke-dasharray="3,3" />
                <text x="35" y="34" font-size="10" fill="#94a3b8" text-anchor="end"><?php echo $maxVal; ?></text>

                <line x1="45" y1="95" x2="520" y2="95" stroke="#f1f5f9" stroke-dasharray="3,3" />
                <text x="35" y="99" font-size="10" fill="#94a3b8" text-anchor="end"><?php echo round($maxVal * 0.66); ?></text>

                <line x1="45" y1="160" x2="520" y2="160" stroke="#f1f5f9" stroke-dasharray="3,3" />
                <text x="35" y="164" font-size="10" fill="#94a3b8" text-anchor="end"><?php echo round($maxVal * 0.33); ?></text>

                <line x1="45" y1="195" x2="520" y2="195" stroke="#e2e8f0" stroke-width="1.5" />
                <text x="35" y="198" font-size="10" fill="#94a3b8" text-anchor="end">0</text>

                <!-- Grouped Bars per Shift -->
                <?php
                $chartH = 165;
                $baseY = 195;
                $groupCount = count($shiftNames);
                $groupWidth = 475 / max(1, $groupCount);
                $barW = 24;

                foreach ($shiftNames as $idx => $s) {
                    $centerX = 45 + ($idx * $groupWidth) + ($groupWidth / 2);
                    $tgtH = ($s['target_qty'] / $maxVal) * $chartH;
                    $okH  = ($s['ok_qty'] / $maxVal) * $chartH;
                    $rejH = ($s['rejected_qty'] / $maxVal) * $chartH;

                    $tgtX = $centerX - 36;
                    $okX  = $centerX - 10;
                    $rejX = $centerX + 16;
                    ?>
                    <!-- Target Bar -->
                    <rect x="<?php echo $tgtX; ?>" y="<?php echo $baseY - $tgtH; ?>" width="<?php echo $barW; ?>" height="<?php echo $tgtH; ?>" fill="#cbd5e1" rx="4" />
                    <?php if ($s['target_qty'] > 0): ?>
                    <text x="<?php echo $tgtX + ($barW/2); ?>" y="<?php echo max(20, $baseY - $tgtH - 5); ?>" font-size="10" font-weight="700" fill="#64748b" text-anchor="middle"><?php echo round($s['target_qty']); ?></text>
                    <?php endif; ?>

                    <!-- OK Bar -->
                    <rect x="<?php echo $okX; ?>" y="<?php echo $baseY - $okH; ?>" width="<?php echo $barW; ?>" height="<?php echo $okH; ?>" fill="#10b981" rx="4" />
                    <?php if ($s['ok_qty'] > 0): ?>
                    <text x="<?php echo $okX + ($barW/2); ?>" y="<?php echo max(20, $baseY - $okH - 5); ?>" font-size="10" font-weight="700" fill="#047857" text-anchor="middle"><?php echo round($s['ok_qty']); ?></text>
                    <?php endif; ?>

                    <!-- Rejection Bar -->
                    <rect x="<?php echo $rejX; ?>" y="<?php echo $baseY - $rejH; ?>" width="<?php echo $barW; ?>" height="<?php echo $rejH; ?>" fill="#ef4444" rx="4" />
                    <?php if ($s['rejected_qty'] > 0): ?>
                    <text x="<?php echo $rejX + ($barW/2); ?>" y="<?php echo max(20, $baseY - $rejH - 5); ?>" font-size="10" font-weight="700" fill="#b91c1c" text-anchor="middle"><?php echo round($s['rejected_qty']); ?></text>
                    <?php endif; ?>

                    <!-- Shift Label -->
                    <text x="<?php echo $centerX + 2; ?>" y="215" font-size="12" font-weight="700" fill="#334155" text-anchor="middle"><?php echo htmlspecialchars($s['shift_name']); ?></text>
                <?php } ?>
              </svg>
            </div>
          </div>

          <!-- Quality & Yield Ratio Donut Chart -->
          <div class="viz-card">
            <div class="viz-card-head">
              <div>
                <h3 class="viz-card-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M12 2a10 10 0 0 1 10 10"></path>
                  </svg>
                  Quality & Yield Ratio
                </h3>
                <p class="viz-card-sub">Shop-Floor OK parts vs rejection scrap</p>
              </div>
            </div>

            <!-- Crisp SVG Donut Chart with Exact Arc -->
            <div class="svg-chart-wrap" style="flex-direction: column; gap: 14px;">
              <?php
              $totP = $kpi['prod_ok'] + $kpi['prod_rejected'];
              $okPct = $totP > 0 ? ($kpi['prod_ok'] / $totP) : 1;
              $rejPct = $totP > 0 ? ($kpi['prod_rejected'] / $totP) : 0;
              $circ = 2 * pi() * 65; // ~408.4
              $okDash = $okPct * $circ;
              $rejDash = $rejPct * $circ;
              ?>
              <svg viewBox="0 0 220 200" width="220" height="180">
                <!-- Background Ring -->
                <circle cx="110" cy="95" r="65" fill="none" stroke="#f1f5f9" stroke-width="22" />
                
                <!-- OK Output Segment -->
                <circle cx="110" cy="95" r="65" fill="none" stroke="#10b981" stroke-width="22"
                  stroke-dasharray="<?php echo $okDash; ?> <?php echo $circ; ?>"
                  stroke-dashoffset="0"
                  transform="rotate(-90 110 95)" />

                <!-- Rejection Segment -->
                <?php if ($kpi['prod_rejected'] > 0): ?>
                <circle cx="110" cy="95" r="65" fill="none" stroke="#ef4444" stroke-width="22"
                  stroke-dasharray="<?php echo $rejDash; ?> <?php echo $circ; ?>"
                  stroke-dashoffset="<?php echo -$okDash; ?>"
                  transform="rotate(-90 110 95)" />
                <?php endif; ?>

                <!-- Center Text -->
                <text x="110" y="92" font-size="24" font-weight="800" fill="#0f172a" text-anchor="middle"><?php echo $kpi['yield_rate']; ?>%</text>
                <text x="110" y="110" font-size="11" font-weight="600" fill="#64748b" text-anchor="middle">YIELD RATE</text>
              </svg>

              <!-- Legend Pills -->
              <div style="display: flex; gap: 20px; font-size: 0.85rem; font-weight: 600;">
                <span style="color: #059669; display: flex; align-items: center; gap: 6px;">
                  <span style="width: 10px; height: 10px; background: #10b981; border-radius: 50%;"></span>
                  OK: <?php echo number_format($kpi['prod_ok']); ?> (<?php echo $kpi['yield_rate']; ?>%)
                </span>
                <span style="color: #dc2626; display: flex; align-items: center; gap: 6px;">
                  <span style="width: 10px; height: 10px; background: #ef4444; border-radius: 50%;"></span>
                  Scrap: <?php echo number_format($kpi['prod_rejected']); ?> (<?php echo $kpi['rejection_rate']; ?>%)
                </span>
              </div>
            </div>
          </div>

        </div>

        <!-- Viz Row 2: Process Output & Warehouse Inventory Health -->
        <div class="viz-grid-equal">
          
          <!-- Process / Workcenter Production Output -->
          <div class="viz-card">
            <div class="viz-card-head">
              <div>
                <h3 class="viz-card-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                  </svg>
                  Process / Workcenter Production Output
                </h3>
                <p class="viz-card-sub">Units completed per manufacturing department</p>
              </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 6px;" id="processListContainer">
              <?php
              $procList = !empty($processes) ? $processes : [
                  ['process_name' => 'FLOOR1 (Assembly)', 'ok_qty' => $kpi['prod_ok']]
              ];
              $maxP = 1;
              foreach ($procList as $p) {
                  if (floatval($p['ok_qty']) > $maxP) $maxP = floatval($p['ok_qty']);
              }
              foreach ($procList as $p):
                  $pQty = floatval($p['ok_qty']);
                  $pPct = $maxP > 0 ? round(($pQty / $maxP) * 100) : 0;
              ?>
              <div class="proc-row-item">
                <div class="proc-info">
                  <span><?php echo htmlspecialchars($p['process_name']); ?></span>
                  <span style="color: var(--dash-primary); font-weight: 700;"><?php echo number_format($pQty); ?> Units</span>
                </div>
                <div class="proc-bar-track">
                  <div class="proc-bar-fill" style="width: <?php echo max(5, $pPct); ?>%;"></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Top Component Stocks (RM & Child Parts) -->
          <div class="viz-card">
            <div class="viz-card-head">
              <div>
                <h3 class="viz-card-title">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                    <polyline points="2 17 12 22 22 17"></polyline>
                    <polyline points="2 12 12 17 22 12"></polyline>
                  </svg>
                  Top Component Stocks (Warehouse)
                </h3>
                <p class="viz-card-sub">Current available stock levels & low stock alerts</p>
              </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px;" id="stockListContainer">
              <?php
              // RM Top
              foreach (array_slice($topRm, 0, 2) as $rm):
                  $isLow = floatval($rm['current_stock']) <= 5;
              ?>
              <div class="comp-stock-item">
                <div>
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 0.7rem; font-weight: 700; background: #fffbeb; color: #b45309; padding: 2px 6px; border-radius: 4px;">RAW MATERIAL</span>
                    <strong style="font-size: 0.86rem;"><?php echo htmlspecialchars($rm['rm_name']); ?></strong>
                  </div>
                  <span style="font-size: 0.74rem; color: var(--dash-muted); font-family: monospace;"><?php echo htmlspecialchars($rm['rm_code']); ?></span>
                </div>
                <div style="text-align: right;">
                  <div style="font-size: 0.92rem; font-weight: 800; <?php echo ($isLow ? 'color: #dc2626;' : ''); ?>">
                    <?php echo number_format(floatval($rm['current_stock'])); ?> <span style="font-size: 0.74rem; color: var(--dash-muted);"><?php echo htmlspecialchars($rm['uom']); ?></span>
                  </div>
                  <?php if ($isLow): ?>
                  <span style="font-size: 0.7rem; color: #dc2626; font-weight: 700;">Low Stock</span>
                  <?php else: ?>
                  <span style="font-size: 0.7rem; color: #059669; font-weight: 700;">Healthy</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>

              <?php
              // Child Parts Top
              foreach (array_slice($topCp, 0, 2) as $cp):
                  $isLow = floatval($cp['current_stock']) <= 5;
              ?>
              <div class="comp-stock-item">
                <div>
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 0.7rem; font-weight: 700; background: #eff6ff; color: #1d4ed8; padding: 2px 6px; border-radius: 4px;">RM & CHILD PART</span>
                    <strong style="font-size: 0.86rem;"><?php echo htmlspecialchars($cp['part_name']); ?></strong>
                  </div>
                  <span style="font-size: 0.74rem; color: var(--dash-muted); font-family: monospace;"><?php echo htmlspecialchars($cp['part_code']); ?></span>
                </div>
                <div style="text-align: right;">
                  <div style="font-size: 0.92rem; font-weight: 800; <?php echo ($isLow ? 'color: #dc2626;' : ''); ?>">
                    <?php echo number_format(floatval($cp['current_stock'])); ?> <span style="font-size: 0.74rem; color: var(--dash-muted);"><?php echo htmlspecialchars($cp['uom']); ?></span>
                  </div>
                  <?php if ($isLow): ?>
                  <span style="font-size: 0.7rem; color: #dc2626; font-weight: 700;">Low Stock</span>
                  <?php else: ?>
                  <span style="font-size: 0.7rem; color: #059669; font-weight: 700;">Healthy</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

        </div>

        <!-- Live Operations: Shop Floor Production Logs Table -->
        <div class="dash-table-box">
          <div class="dash-table-bar">
            <div>
              <h2 style="font-size: 1.05rem; font-weight: 700; margin: 0; color: var(--dash-dark);">Recent Shop Floor Production Entries (DPR)</h2>
              <p style="font-size: 0.8rem; color: var(--dash-muted); margin: 2px 0 0 0;">Latest entries recorded on shop-floor shifts</p>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
              <input type="text" id="prodSearch" class="search-input-box" placeholder="Search MIP, WO, part, operator...">
              <a href="production.php" class="btn-banner-link primary-btn" style="text-decoration: none;">+ Production Log</a>
            </div>
          </div>

          <div class="table-container">
            <table class="dash-data-table" id="prodTable">
              <thead>
                <tr>
                  <th>MIP / WO</th>
                  <th>Part Details</th>
                  <th>Process / Floor</th>
                  <th>Shift</th>
                  <th>Target</th>
                  <th>OK Qty</th>
                  <th>Rejected</th>
                  <th>Operator</th>
                  <th>Status</th>
                  <th>Timestamp</th>
                </tr>
              </thead>
              <tbody id="prodTableBody">
                <?php if (!empty($recentProduction)): ?>
                  <?php foreach ($recentProduction as $r): 
                      $target = floatval($r['target_qty']);
                      $ok = floatval($r['ok_qty']);
                      $rej = floatval($r['rejected_qty']);
                      $isCompleted = strtolower($r['status']) === 'completed';
                  ?>
                  <tr>
                    <td>
                      <strong><?php echo htmlspecialchars($r['mip_no'] ?? 'N/A'); ?></strong>
                      <?php if (!empty($r['work_order'])): ?>
                      <div style="font-size: 0.74rem; color: var(--dash-muted);">WO: <?php echo htmlspecialchars($r['work_order']); ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight: 700; color: var(--dash-dark);"><?php echo htmlspecialchars($r['part_name'] ?? 'Assembly'); ?></div>
                      <div style="font-size: 0.74rem; color: var(--dash-muted); font-family: monospace;"><?php echo htmlspecialchars($r['part_code'] ?? ''); ?></div>
                    </td>
                    <td><?php echo htmlspecialchars($r['process_name'] ?? 'General'); ?></td>
                    <td><strong><?php echo htmlspecialchars($r['shift'] ?? 'Shift'); ?></strong></td>
                    <td><?php echo number_format($target); ?> <?php echo htmlspecialchars($r['uom'] ?? 'PCS'); ?></td>
                    <td><strong style="color: #059669;"><?php echo number_format($ok); ?></strong></td>
                    <td><span style="<?php echo ($rej > 0 ? 'color: #dc2626; font-weight: 700;' : 'color: var(--dash-muted);'); ?>"><?php echo number_format($rej); ?></span></td>
                    <td><?php echo htmlspecialchars($r['operator_name'] ?? '-'); ?></td>
                    <td>
                      <span class="badge-status <?php echo ($isCompleted ? 'completed' : 'in-progress'); ?>">
                        <?php echo htmlspecialchars($r['status'] ?? 'Completed'); ?>
                      </span>
                    </td>
                    <td style="font-size: 0.76rem; color: var(--dash-muted);"><?php echo htmlspecialchars($r['created_at'] ?? '-'); ?></td>
                  </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="10" style="text-align: center; padding: 24px; color: var(--dash-muted);">
                      No production entries found. Start logging with "+ Production Log".
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

    </div>

  </div>

  <!-- Dashboard JavaScript Controller with cache-busting -->
  <script src="js/dashboard.js?v=<?php echo time(); ?>"></script>
</body>
</html>
