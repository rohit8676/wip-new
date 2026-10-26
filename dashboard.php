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
$runningParts = [];
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

        // WIP (Total Issues & Total Quantity from material_issue)
        $mipRow = $pdo->query("SELECT COUNT(*) AS total, ISNULL(SUM(issued_qty), 0) AS issued FROM material_issue")->fetch(PDO::FETCH_ASSOC);
        if ($mipRow) {
            $kpi['wip_total'] = intval($mipRow['total']);
            $kpi['wip_issued_qty'] = floatval($mipRow['issued']);
        }

        // Production & Active WIP (from production_entry)
        $prdRow = $pdo->query("
            SELECT 
                COUNT(*) AS total_runs,
                ISNULL(SUM(target_qty), 0) AS target, 
                ISNULL(SUM(ok_qty), 0) AS ok_q, 
                ISNULL(SUM(CASE WHEN is_rework = 0 OR is_rework IS NULL THEN rejected_qty ELSE 0 END), 0) AS rej_q,
                COUNT(CASE WHEN status != 'Completed' AND (target_qty = 0 OR (ISNULL(ok_qty, 0) + ISNULL(rejected_qty, 0) + ISNULL(rework_qty, 0)) < target_qty) THEN 1 END) AS active_wip,
                COUNT(CASE WHEN status = 'Completed' OR (target_qty > 0 AND (ISNULL(ok_qty, 0) + ISNULL(rejected_qty, 0) + ISNULL(rework_qty, 0)) >= target_qty) THEN 1 END) AS completed_wip
            FROM production_entry
        ")->fetch(PDO::FETCH_ASSOC);
        if ($prdRow) {
            $kpi['wip_active'] = intval($prdRow['active_wip']);
            $kpi['wip_completed'] = intval($prdRow['completed_wip']);
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
        $shifts = $pdo->query("
            SELECT 
                ISNULL(NULLIF(shift, ''), 'General') AS shift_name, 
                ISNULL(SUM(ok_qty), 0) AS ok_qty, 
                ISNULL(SUM(rejected_qty), 0) AS rejected_qty, 
                ISNULL(SUM(target_qty), 0) AS target_qty 
            FROM production_entry 
            GROUP BY ISNULL(NULLIF(shift, ''), 'General') 
            ORDER BY shift_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Processes
        $processes = $pdo->query("
            SELECT 
                ISNULL(NULLIF(process_name, ''), 'General') AS process_name, 
                ISNULL(SUM(ok_qty), 0) AS ok_qty 
            FROM production_entry 
            GROUP BY ISNULL(NULLIF(process_name, ''), 'General') 
            ORDER BY ok_qty DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Top RM & CP
        $topRm = $pdo->query("SELECT TOP 4 rm_code, rm_name, current_stock, uom FROM rm_master ORDER BY current_stock DESC")->fetchAll(PDO::FETCH_ASSOC);
        $topCp = $pdo->query("SELECT TOP 4 part_code, part_name, current_stock, uom FROM child_part_master ORDER BY current_stock DESC")->fetchAll(PDO::FETCH_ASSOC);

        // Shop Floor Production Parts (Running & Completed)
        $runningParts = $pdo->query("
            SELECT TOP 100 id, mip_no, customer_name, customer_code, so_number, work_order, part_code, part_name,
                   process_name, shift, target_qty, ok_qty, rejected_qty, rework_qty, is_rework, operator_name, uom, status,
                   current_stage_order, current_stage_name, total_stages,
                   CONVERT(VARCHAR(10), created_at, 120) AS entry_date,
                   CONVERT(VARCHAR(16), created_at, 120) AS created_at
            FROM production_entry 
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $runningCount = 0;
        $completedCount = 0;
        foreach ($runningParts as &$rp) {
            $tq = floatval($rp['target_qty'] ?? 0);
            $ok = floatval($rp['ok_qty'] ?? 0);
            $rej = floatval($rp['rejected_qty'] ?? 0);
            $rw = floatval($rp['rework_qty'] ?? 0);
            $st = trim($rp['status'] ?? '');
            $isDone = (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'Complete') === 0)
                   || ($tq > 0 && ($ok + $rej) >= $tq)
                   || ($tq > 0 && ($ok + $rej + $rw) >= $tq);
            $rp['effective_status'] = $isDone ? 'Completed' : 'In Progress';
            if ($isDone) {
                $completedCount++;
            } else {
                $runningCount++;
            }
        }
        unset($rp);

        // Recent Production Entries
        $recentProduction = $pdo->query("
            SELECT TOP 8 id, mip_no, work_order, part_code, part_name, process_name, shift, 
                         target_qty, ok_qty, rejected_qty, rework_qty, is_rework, operator_name, uom, status, 
                         CONVERT(VARCHAR(16), created_at, 120) AS created_at 
            FROM production_entry 
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
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

    /* KPI Grid - Single 5-Card Row */
    .kpi-row-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 12px;
      width: 100%;
    }

    .kpi-box {
      background: #ffffff;
      border: 1px solid var(--dash-border);
      border-radius: 10px;
      padding: 13px 14px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
      min-width: 0;
    }

    .kpi-box:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
      border-color: #cbd5e1;
    }

    .kpi-box-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
      gap: 6px;
    }

    .kpi-box-tag {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--dash-muted);
      text-transform: uppercase;
      letter-spacing: 0.03em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .kpi-icon-circ {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .kpi-icon-circ svg {
      width: 16px;
      height: 16px;
    }

    .kpi-icon-circ.blue { background: #eff6ff; color: #2563eb; }
    .kpi-icon-circ.green { background: #ecfdf5; color: #10b981; }
    .kpi-icon-circ.rose { background: #fff1f2; color: #e11d48; }
    .kpi-icon-circ.amber { background: #fffbeb; color: #d97706; }
    .kpi-icon-circ.purple { background: #f5f3ff; color: #7c3aed; }

    .kpi-box-val {
      font-size: 1.52rem;
      font-weight: 800;
      color: var(--dash-dark);
      line-height: 1.15;
      letter-spacing: -0.02em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .kpi-box-val span {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--dash-muted);
    }

    .kpi-box-foot {
      margin-top: 8px;
      padding-top: 7px;
      border-top: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 4px;
      font-size: 0.72rem;
      color: var(--dash-muted);
      white-space: nowrap;
    }

    .kpi-box-foot > span:first-child {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .kpi-chip {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      font-size: 0.69rem;
      font-weight: 700;
      padding: 1.5px 6px;
      border-radius: 5px;
      flex-shrink: 0;
      white-space: nowrap;
    }

    .kpi-chip.green { background: #ecfdf5; color: #059669; }
    .kpi-chip.amber { background: #fffbeb; color: #b45309; }
    .kpi-chip.blue { background: #eff6ff; color: #1d4ed8; }
    .kpi-chip.red { background: #fef2f2; color: #dc2626; }

    @media (max-width: 1200px) {
      .kpi-row-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      }
    }

    @media (max-width: 640px) {
      .kpi-row-grid {
        grid-template-columns: 1fr;
      }
    }

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

    
    .badge-status.in-progress { background: #eff6ff; color: #2563eb; }

    .spin-icon-anim {
      animation: spin-loop 0.8s linear infinite;
    }

    @keyframes spin-loop {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    /* Running In-Progress Parts Component Styles */
    .live-pulse-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 0.74rem;
      font-weight: 700;
    }

    .pulse-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #2563eb;
      box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7);
      animation: pulse-ring 1.8s infinite;
      display: inline-block;
    }

    .pulse-dot-sm {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #2563eb;
      display: inline-block;
    }

    @keyframes pulse-ring {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(37, 99, 235, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
    }

    .running-search-box {
      position: relative;
      width: 100%;
      min-width: 250px;
      max-width: 380px;
    }

    .running-search-icon {
      position: absolute;
      left: 10px;
      top: 50%;
      transform: translateY(-50%);
      width: 15px;
      height: 15px;
      color: #1e293b;
      pointer-events: none;
    }

    .running-search-input {
      width: 100%;
      padding: 7px 32px 7px 32px;
      font-size: 0.82rem;
      border: 1px solid #cbd5e1;
      border-radius: 3px;
      background: #ffffff;
      color: #1e293b;
      outline: none;
      transition: all 0.15s ease;
      font-family: inherit;
      box-sizing: border-box;
    }

    .running-search-input:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .running-search-clear {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      border: none;
      background: transparent;
      color: #94a3b8;
      font-size: 16px;
      cursor: pointer;
      padding: 0 4px;
      line-height: 1;
    }

    .running-search-clear:hover {
      color: #1e293b;
    }


    .running-parts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 12px;
    }

    .running-card-item {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-left: 4px solid #2563eb;
      border-radius: 8px;
      padding: 12px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
      transition: all 0.15s ease;
    }

    .running-card-item:hover {
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
      border-color: #cbd5e1;
      border-left-color: #1d4ed8;
    }

    .running-card-item.is-completed {
      border-left-color: #16a34a;
    }

    .running-card-item.is-completed:hover {
      border-left-color: #15803d;
      box-shadow: 0 4px 12px rgba(22, 163, 74, 0.08);
    }

    .running-card-info {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 0;
      flex: 1;
    }

    .running-part-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .running-part-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: #0f172a;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .running-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.67rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      white-space: nowrap;
      flex-shrink: 0;
    }

    .running-status-pill.in-prog {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }

    .running-status-pill.comp {
      background: #f0fdf4;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }

    .pulse-dot-sm {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #2563eb;
      display: inline-block;
      animation: pulse-ring-sm 1.5s infinite;
    }

    @keyframes pulse-ring-sm {
      0% { transform: scale(0.95); opacity: 0.9; }
      50% { transform: scale(1.3); opacity: 0.4; }
      100% { transform: scale(0.95); opacity: 0.9; }
    }

    .running-part-meta {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px;
      font-size: 0.78rem;
    }

    .running-part-mip {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #e2e8f0;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 0.72rem;
      font-weight: 600;
      line-height: 1.2;
    }

    .running-part-mip strong {
      color: #0f172a;
      font-weight: 700;
    }

    .running-part-code {
      background: #f8fafc;
      color: #64748b;
      border: 1px solid #e2e8f0;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 0.72rem;
      font-weight: 600;
      line-height: 1.2;
    }

    .running-part-cust {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.78rem;
      color: #64748b;
      font-weight: 500;
    }

    .running-part-qty-pill {
      font-size: 0.70rem;
      font-weight: 600;
      padding: 2px 6px;
      border-radius: 4px;
      background: #f8fafc;
      color: #475569;
      border: 1px solid #e2e8f0;
      line-height: 1.2;
    }

    .running-part-qty-pill.comp {
      background: #f0fdf4;
      color: #166534;
      border-color: #dcfce7;
    }

    .running-part-qty-pill.in-prog {
      background: #eff6ff;
      color: #1e40af;
      border-color: #dbeafe;
    }

    .btn-view {
      padding: 5px 14px;
      background: #ffffff;
      border: 1px solid var(--dash-border);
      border-radius: 6px;
      font-size: 0.82rem;
      font-weight: 600;
      color: #0284c7;
      cursor: pointer;
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      white-space: nowrap;
    }

    .btn-view:hover {
      background: #f0f9ff;
      border-color: #0284c7;
      color: #0369a1;
      box-shadow: 0 1px 3px rgba(2, 132, 199, 0.15);
    }

    .running-empty-state {
      text-align: center;
      padding: 24px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 8px;
    }

    .btn-clear-search-link {
      margin-top: 10px;
      padding: 5px 14px;
      background: #2563eb;
      color: #ffffff;
      border: none;
      border-radius: 5px;
      font-size: 0.75rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .btn-clear-search-link:hover {
      background: #1d4ed8;
    }

    /* =========================================================
       Right Slide-in Process Workflow Drawer / Slider (Half Screen)
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
      border-left: 3px solid #dc2626;
      background: linear-gradient(180deg, #ffffff 0%, #fef2f2 100%);
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
    .slider-stat-val.val-fg { color: #16a34a; }
    .slider-stat-val.val-rej { color: #dc2626; }
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
      background: #faf5ff;
      border-color: #f3e8ff;
    }
    .simple-stat-rej .simple-stat-pct {
      background: #8b5cf6;
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
    .simple-bar-seg.pass { background: #2563eb; }
    .simple-bar-seg.rej { background: #8b5cf6; }
    .simple-bar-seg.rem { background: #cbd5e1; }

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
        
        <!-- KPI Row 5-Cards Grid (Single Row Compact) -->
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
              <span>Issued: <strong id="valTotalIssuedQty"><?php echo number_format($kpi['wip_issued_qty']); ?> Units</strong></span>
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

          <!-- Card 3: Quality & Rework -->
          <div class="kpi-box">
            <div class="kpi-box-head">
              <span class="kpi-box-tag">Total Rework</span>
              <div class="kpi-icon-circ amber">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="23 4 23 10 17 10"></polyline>
                  <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                </svg>
              </div>
            </div>
            <div class="kpi-box-val" id="valProdRej" style="color: #ea580c;"><?php echo number_format($kpi['prod_rejected']); ?></div>
            <div class="kpi-box-foot">
              <span>Rework: <strong id="valRejRate"><?php echo $kpi['rejection_rate']; ?>%</strong></span>
              <span class="kpi-chip <?php echo ($kpi['rejection_rate'] > 10 ? 'amber' : 'green'); ?>" id="valQualityPill">
                <?php echo ($kpi['rejection_rate'] > 10 ? 'High Rework' : 'Optimal Quality'); ?>
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
            <div class="kpi-box-val" id="valRmStock"><?php echo number_format($kpi['rm_stock']); ?> <span>KG</span></div>
            <div class="kpi-box-foot">
              <span>Active: <strong id="valRmCount"><?php echo $kpi['rm_count']; ?> Types</strong></span>
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
            <div class="kpi-box-val" id="valFgStock"><?php echo number_format($kpi['fg_stock']); ?> <span>Units</span></div>
            <div class="kpi-box-foot">
              <span>Dispatched: <strong id="valDispatchedQty"><?php echo number_format($kpi['dispatch_qty']); ?> Units</strong></span>
              <a href="fg-store.php" class="kpi-chip blue" style="text-decoration: none;">View FG</a>
            </div>
          </div>

        </div>

        <!-- Live Operations: Shop Floor Production Parts (Running & Completed) -->
        <div class="dash-table-box" id="runningPartsSection" style="border-top: 3px solid #2563eb;">
          <div class="dash-table-bar" style="margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
              <div class="kpi-icon-circ blue" style="width: 36px; height: 36px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
              </div>
              <div>
                <h2 style="font-size: 1.05rem; font-weight: 700; margin: 0; color: var(--dash-dark); display: flex; align-items: center; gap: 8px;">
                  Currently Running Parts
                  <span class="live-pulse-badge" id="runningCountBadge">
                    <span class="pulse-dot"></span>
                    <span id="runningCountText"><?php echo $runningCount; ?> In Progress</span>
                  </span>
                </h2>
              </div>
            </div>

            <!-- Search Box Parallel to Currently Running Parts Title -->
            <div class="running-search-box">
              <svg class="running-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
              </svg>
              <input type="text" id="runningPartsSearch" class="running-search-input" placeholder="Search running or completed parts by Name, Code, MIP No..." autocomplete="off">
              <button type="button" id="runningSearchClear" class="running-search-clear" title="Clear Search" style="display: none;">&times;</button>
            </div>
          </div>

          <div id="runningPartsContainer">
            <?php if (!empty($runningParts)): ?>
              <div class="running-parts-grid">
                <?php foreach ($runningParts as $rp): 
                    $targetQ = floatval($rp['target_qty']);
                    $okQ = floatval($rp['ok_qty']);
                    $rejQ = floatval($rp['rejected_qty']);
                    $uom = htmlspecialchars($rp['uom'] ?? 'NOS');
                    $partTitle = htmlspecialchars($rp['part_name'] ?: $rp['part_code']);
                    $partCode = htmlspecialchars($rp['part_code'] ?? '');
                    $custTitle = htmlspecialchars($rp['customer_name'] ?: '-');
                    $mipNo = htmlspecialchars($rp['mip_no'] ?? '');
                    $effectiveStatus = $rp['effective_status'] ?? 'In Progress';
                    $isComp = ($effectiveStatus === 'Completed');
                    $statusClass = $isComp ? 'completed' : 'in-progress';
                    $isRw = !empty($rp['is_rework']);
                    $searchKeywords = strtolower($partTitle . ' ' . $partCode . ' ' . $mipNo . ' ' . ($isRw ? 'r rework ' : '') . $custTitle . ' ' . ($rp['work_order'] ?? '') . ' ' . ($rp['so_number'] ?? ''));
                ?>
                <div class="running-card-item <?php echo $isComp ? 'is-completed' : 'is-running'; ?>"
                     data-status="<?php echo $statusClass; ?>"
                     data-search="<?php echo htmlspecialchars($searchKeywords); ?>"
                     <?php if ($isComp) echo 'style="display: none;"'; ?>>
                  <div class="running-card-info">
                    <div class="running-part-head">
                      <div class="running-part-title" title="<?php echo $partTitle; ?>"><?php echo $partTitle; ?></div>
                      <?php if ($isComp): ?>
                        <span class="running-status-pill comp">
                          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                          Completed
                        </span>
                      <?php else: ?>
                        <span class="running-status-pill in-prog">
                          <span class="pulse-dot-sm"></span>
                          In Progress
                        </span>
                      <?php endif; ?>
                    </div>
                    <div class="running-part-meta">
                      <?php if (!empty($mipNo) && $mipNo !== '-'): ?>
                        <span class="running-part-mip">
                          <strong><?php echo $mipNo; ?></strong>
                          <?php if ($isRw): ?>
                            <span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>
                          <?php endif; ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($partCode)): ?>
                        <span class="running-part-code" title="Part Code"><?php echo $partCode; ?></span>
                      <?php endif; ?>
                      <div class="running-part-cust" title="Customer">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span><?php echo $custTitle; ?></span>
                      </div>
                      <div class="running-part-qty-pill <?php echo $isComp ? 'comp' : 'in-prog'; ?>" title="Production Quantity">
                        <span><?php echo ($isComp ? 'Done: ' : 'OK: ') . $okQ . ' / ' . $targetQ . ' ' . $uom; ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="running-card-actions">
                    <button type="button" class="btn-view"
                            data-id="<?php echo htmlspecialchars($rp['id']); ?>"
                            data-mip_no="<?php echo htmlspecialchars($rp['mip_no'] ?? ''); ?>"
                            data-is_rework="<?php echo $isRw ? '1' : '0'; ?>"
                            data-part_name="<?php echo htmlspecialchars($rp['part_name'] ?: $rp['part_code']); ?>"
                            data-part_code="<?php echo htmlspecialchars($rp['part_code']); ?>"
                            data-customer_name="<?php echo htmlspecialchars($rp['customer_name'] ?? ''); ?>"
                            data-target_qty="<?php echo htmlspecialchars($targetQ); ?>"
                            data-ok_qty="<?php echo htmlspecialchars($okQ); ?>"
                            data-rejected_qty="<?php echo htmlspecialchars($rejQ); ?>"
                            data-uom="<?php echo $uom; ?>"
                            data-status="<?php echo htmlspecialchars($effectiveStatus); ?>"
                            data-created_at="<?php echo htmlspecialchars($rp['entry_date'] ?? $rp['created_at'] ?? ''); ?>"
                            title="View Process Breakdown">
                      View
                    </button>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <div id="runningNoResults" class="running-empty-state" style="display: none;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 36px; height: 36px; color: #94a3b8; margin-bottom: 6px;">
                  <circle cx="11" cy="11" r="8"></circle>
                  <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <div style="font-size: 0.90rem; font-weight: 700; color: var(--dash-dark);">No matching parts found</div>
                <div style="font-size: 0.78rem; color: var(--dash-muted); margin-top: 2px;">Try searching with another keyword or MIP number.</div>
                <button type="button" class="btn-clear-search-link" id="btnResetRunningSearch">Clear Search</button>
              </div>
            <?php else: ?>
              <div class="running-empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 36px; height: 36px; color: #10b981; margin-bottom: 6px;">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <div style="font-size: 0.90rem; font-weight: 700; color: var(--dash-dark);">No Production Parts Found</div>
                <div style="font-size: 0.78rem; color: var(--dash-muted); margin-top: 2px;">There are no active or completed shop floor production runs.</div>
              </div>
            <?php endif; ?>
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
                <p class="viz-card-sub">Target Plan vs OK Output vs Rework across shifts</p>
              </div>
              <div style="display: flex; gap: 12px; font-size: 0.75rem; font-weight: 600;">
                <span style="display: flex; align-items: center; gap: 4px;"><span style="width: 10px; height: 10px; background: #cbd5e1; border-radius: 2px;"></span> Target</span>
                <span style="display: flex; align-items: center; gap: 4px;"><span style="width: 10px; height: 10px; background: #10b981; border-radius: 2px;"></span> OK Qty</span>
                <span style="display: flex; align-items: center; gap: 4px;"><span style="width: 10px; height: 10px; background: #ea580c; border-radius: 2px;"></span> Rework</span>
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
                <p class="viz-card-sub">Shop-Floor OK parts vs rework parts</p>
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

                <!-- Donut Segment: Rework -->
                <?php if ($kpi['prod_rejected'] > 0): ?>
                <circle cx="110" cy="95" r="65" fill="none" stroke="#ea580c" stroke-width="22"
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
                <span style="color: #ea580c; display: flex; align-items: center; gap: 6px;">
                  <span style="width: 10px; height: 10px; background: #ea580c; border-radius: 50%;"></span>
                  Rework: <?php echo number_format($kpi['prod_rejected']); ?> (<?php echo $kpi['rejection_rate']; ?>%)
                </span>
              </div>
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
                  <th>Rework</th>
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
                      $rw = floatval($r['rework_qty'] ?? 0);
                      $st = trim($r['status'] ?? '');
                      $isCompleted = (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'Complete') === 0)
                                  || ($target > 0 && ($ok + $rej) >= $target)
                                  || ($target > 0 && ($ok + $rej + $rw) >= $target);
                  ?>
                  <tr>
                    <td>
                      <strong><?php echo htmlspecialchars($r['mip_no'] ?? 'N/A'); ?></strong>
                      <?php if (!empty($r['is_rework'])): ?>
                        <span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>
                      <?php endif; ?>
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
                    <td><span style="<?php echo ($rej > 0 ? 'color: #ea580c; font-weight: 700;' : 'color: var(--dash-muted);'); ?>"><?php echo number_format($rej); ?></span></td>
                    <td><?php echo htmlspecialchars($r['operator_name'] ?? '-'); ?></td>
                    <td>
                      <span class="badge-status <?php echo ($isCompleted ? 'completed' : 'in-progress'); ?>">
                        <?php echo $isCompleted ? 'Completed' : 'In Progress'; ?>
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

  <!-- =========================================================
       Right Slide-in Process Workflow Drawer / Slider (Half Screen)
       ========================================================= -->
  <div id="processTimelineSliderOverlay" class="slider-overlay"></div>

  <div id="processTimelineSlider" class="slider-drawer">
    <!-- Slider Header: Target Qty, Finished Goods (FG), Total Rejections Only -->
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
      <div style="font-size: 0.8rem; color: var(--dash-muted);" id="sldFooterMeta">
        Shop Floor Live Stage Progress
      </div>
      <div style="display: flex; gap: 8px;">
        <button type="button" class="btn-banner-link" id="btnCloseSliderFooter">Close</button>
        <a href="#" id="btnSliderOpenWorkflow" class="btn-banner-link primary-btn" style="text-decoration: none; padding: 7px 16px; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px;">
          <span>Open Full Process Workspace</span>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14"></path><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
      </div>
    </div>
  </div>

  <!-- Dashboard JavaScript Controller with cache-busting -->
  <script src="js/dashboard.js?v=<?php echo time(); ?>"></script>
</body>
</html>
