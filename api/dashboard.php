<?php
/**
 * WIP Management Portal - Main Dashboard Analytics API
 * Endpoint: /api/dashboard.php
 * Fetches real-time KPIs, shift analysis, inventory stats, and production trends.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/check_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/rbac.php';

$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

requirePermission('dashboard', 'read');

try {
    // -------------------------------------------------------------
    // 1. KPI COUNTS & AGGREGATES
    // -------------------------------------------------------------
    
    // Raw Material Stats
    $rmStats = $pdo->query("
        SELECT 
            COUNT(*) AS total_rm_count,
            ISNULL(SUM(current_stock), 0) AS total_rm_stock,
            COUNT(CASE WHEN current_stock <= 5 THEN 1 END) AS low_stock_rm
        FROM rm_master
        WHERE status = 'Active' OR status IS NULL
    ")->fetch(PDO::FETCH_ASSOC);

    // Child Part Stats
    $cpStats = $pdo->query("
        SELECT 
            COUNT(*) AS total_cp_count,
            ISNULL(SUM(current_stock), 0) AS total_cp_stock,
            COUNT(CASE WHEN current_stock <= 5 THEN 1 END) AS low_stock_cp
        FROM child_part_master
        WHERE status = 'Active' OR status IS NULL
    ")->fetch(PDO::FETCH_ASSOC);

    // WIP / Material Issue (MIP) Stats
    $mipStats = $pdo->query("
        SELECT 
            COUNT(*) AS total_wip_issues,
            ISNULL(SUM(issued_qty), 0) AS total_issued_qty,
            COUNT(CASE WHEN status = 'Issued' OR status = 'In Progress' THEN 1 END) AS active_wip_count,
            COUNT(CASE WHEN status = 'Completed' OR status = 'Closed' THEN 1 END) AS completed_wip_count
        FROM material_issue
    ")->fetch(PDO::FETCH_ASSOC);

    // Production Performance Stats
    $prodStats = $pdo->query("
        SELECT 
            COUNT(*) AS total_runs,
            ISNULL(SUM(target_qty), 0) AS total_target_qty,
            ISNULL(SUM(ok_qty), 0) AS total_ok_qty,
            ISNULL(SUM(rejected_qty), 0) AS total_rejected_qty,
            ISNULL(SUM(rework_qty), 0) AS total_rework_qty
        FROM production_entry
    ")->fetch(PDO::FETCH_ASSOC);

    $totalProduced = floatval($prodStats['total_ok_qty']) + floatval($prodStats['total_rejected_qty']);
    $yieldRate = ($totalProduced > 0) ? round((floatval($prodStats['total_ok_qty']) / $totalProduced) * 100, 1) : 100;
    $rejectionRate = ($totalProduced > 0) ? round((floatval($prodStats['total_rejected_qty']) / $totalProduced) * 100, 1) : 0;

    // Finished Goods Inventory Stats
    $fgStats = $pdo->query("
        SELECT 
            COUNT(*) AS fg_part_count,
            ISNULL(SUM(total_ok_qty), 0) AS total_fg_stock
        FROM fg_inventory
    ")->fetch(PDO::FETCH_ASSOC);

    // FG Dispatch Stats
    $dispatchStats = $pdo->query("
        SELECT 
            COUNT(*) AS total_dispatch_count,
            ISNULL(SUM(dispatch_qty), 0) AS total_dispatched_qty
        FROM fg_dispatch_logs
    ")->fetch(PDO::FETCH_ASSOC);

    // Vendor and Process Counts
    $vendorCount = $pdo->query("SELECT COUNT(*) AS cnt FROM vendor_master WHERE status = 'Active' OR status IS NULL")->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
    $processCount = $pdo->query("SELECT COUNT(*) AS cnt FROM process_master WHERE status = 'Active' OR status IS NULL")->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
    $partMasterCount = $pdo->query("SELECT COUNT(*) AS cnt FROM part_master WHERE status = 'Active' OR status IS NULL")->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;

    // -------------------------------------------------------------
    // 2. SHIFT-WISE PRODUCTION (For Bar Chart)
    // -------------------------------------------------------------
    $shiftData = $pdo->query("
        SELECT 
            ISNULL(shift, 'Unassigned') AS shift_name,
            ISNULL(SUM(ok_qty), 0) AS ok_qty,
            ISNULL(SUM(rejected_qty), 0) AS rejected_qty,
            ISNULL(SUM(target_qty), 0) AS target_qty
        FROM production_entry
        GROUP BY shift
        ORDER BY shift
    ")->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------
    // 3. PROCESS-WISE PRODUCTION (For Process Output Chart)
    // -------------------------------------------------------------
    $processData = $pdo->query("
        SELECT 
            ISNULL(process_name, 'General') AS process_name,
            ISNULL(SUM(ok_qty), 0) AS ok_qty,
            ISNULL(SUM(rejected_qty), 0) AS rejected_qty
        FROM production_entry
        GROUP BY process_name
        ORDER BY ok_qty DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------
    // 4. TOP RAW MATERIAL STOCKS
    // -------------------------------------------------------------
    $topRmStock = $pdo->query("
        SELECT TOP 5 rm_code, rm_name, current_stock, uom
        FROM rm_master
        ORDER BY current_stock DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------
    // 5. TOP CHILD PART STOCKS
    // -------------------------------------------------------------
    $topCpStock = $pdo->query("
        SELECT TOP 5 part_code, part_name, current_stock, uom
        FROM child_part_master
        ORDER BY current_stock DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------
    // 6. RECENT SHOP FLOOR PRODUCTION ENTRIES (Latest 8)
    // -------------------------------------------------------------
    $recentProduction = $pdo->query("
        SELECT TOP 8
            id, mip_no, work_order, part_code, part_name,
            process_name, shift, target_qty, ok_qty, rejected_qty,
            operator_name, uom, status,
            CONVERT(VARCHAR(16), created_at, 120) AS created_at
        FROM production_entry
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------------------------
    // 7. RECENT DISPATCHES (Latest 5)
    // -------------------------------------------------------------
    $recentDispatches = $pdo->query("
        SELECT TOP 5
            id, dispatch_no, 
            CONVERT(VARCHAR(10), dispatch_date, 120) AS dispatch_date,
            invoice_ref, part_code, part_name, dispatch_qty, uom, customer, dispatched_by
        FROM fg_dispatch_logs
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Output JSON response
    echo json_encode([
        'success' => true,
        'kpi' => [
            'rm_count' => intval($rmStats['total_rm_count'] ?? 0),
            'rm_stock' => floatval($rmStats['total_rm_stock'] ?? 0),
            'rm_low_stock' => intval($rmStats['low_stock_rm'] ?? 0),

            'cp_count' => intval($cpStats['total_cp_count'] ?? 0),
            'cp_stock' => floatval($cpStats['total_cp_stock'] ?? 0),
            'cp_low_stock' => intval($cpStats['low_stock_cp'] ?? 0),

            'wip_total' => intval($mipStats['total_wip_issues'] ?? 0),
            'wip_active' => intval($mipStats['active_wip_count'] ?? 0),
            'wip_completed' => intval($mipStats['completed_wip_count'] ?? 0),
            'wip_issued_qty' => floatval($mipStats['total_issued_qty'] ?? 0),

            'prod_target' => floatval($prodStats['total_target_qty'] ?? 0),
            'prod_ok' => floatval($prodStats['total_ok_qty'] ?? 0),
            'prod_rejected' => floatval($prodStats['total_rejected_qty'] ?? 0),
            'yield_rate' => $yieldRate,
            'rejection_rate' => $rejectionRate,

            'fg_stock' => floatval($fgStats['total_fg_stock'] ?? 0),
            'fg_items' => intval($fgStats['fg_part_count'] ?? 0),

            'dispatch_qty' => floatval($dispatchStats['total_dispatched_qty'] ?? 0),
            'dispatch_count' => intval($dispatchStats['total_dispatch_count'] ?? 0),

            'vendor_count' => intval($vendorCount),
            'process_count' => intval($processCount),
            'part_count' => intval($partMasterCount)
        ],
        'charts' => [
            'shifts' => $shiftData,
            'processes' => $processData,
            'top_rm' => $topRmStock,
            'top_cp' => $topCpStock
        ],
        'recent_production' => $recentProduction,
        'recent_dispatches' => $recentDispatches
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
