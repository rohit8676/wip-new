<?php
/**
 * WIP Management Portal - Reports & Analytics API & Export Engine
 * Endpoint: /api/reports.php
 * Handles filtered queries & CSV/Excel export for:
 * - Production (DPR)
 * - Material Issue (MIP)
 * - RM Inward
 * - Child Part Inward
 * - FG Dispatch
 * - Current Inventory Stocks
 */

require_once __DIR__ . '/../auth/check_auth.php';
require_once __DIR__ . '/../config/db.php';

// Enforce Read permission for Reports API
requirePermission('reports', 'read');

$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$reportType = trim($_GET['report_type'] ?? 'production');
$fromDate   = trim($_GET['from_date'] ?? '');
$toDate     = trim($_GET['to_date'] ?? '');
$search     = trim($_GET['search'] ?? '');
$export     = trim($_GET['export'] ?? ''); // 'csv' or 'excel' or empty (JSON)

// Sanitize dates
if (!empty($fromDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = '';
if (!empty($toDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = '';

$columns = [];
$rows = [];
$summary = [];

try {
    // -------------------------------------------------------------
    // 1. PRODUCTION (DPR) REPORT
    // -------------------------------------------------------------
    if ($reportType === 'production') {
        $where = ["1=1"];
        $params = [];

        if (!empty($fromDate)) {
            $where[] = "CAST(created_at AS DATE) >= ?";
            $params[] = $fromDate;
        }
        if (!empty($toDate)) {
            $where[] = "CAST(created_at AS DATE) <= ?";
            $params[] = $toDate;
        }
        if (!empty($search)) {
            $where[] = "(mip_no LIKE ? OR work_order LIKE ? OR part_code LIKE ? OR part_name LIKE ? OR operator_name LIKE ? OR process_name LIKE ?)";
            $term = "%{$search}%";
            array_push($params, $term, $term, $term, $term, $term, $term);
        }

        $sql = "
            SELECT id, mip_no, work_order, part_code, part_name,
                   process_name, shift, target_qty, ok_qty, rejected_qty,
                   operator_name, uom, status,
                   CONVERT(VARCHAR(16), created_at, 120) AS created_at
            FROM production_entry
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [
            'mip_no' => 'MIP No',
            'work_order' => 'Work Order',
            'part_code' => 'Part Code',
            'part_name' => 'Part Name',
            'process_name' => 'Process / Floor',
            'shift' => 'Shift',
            'target_qty' => 'Target Plan',
            'ok_qty' => 'OK Output',
            'rejected_qty' => 'Rejection / Scrap',
            'operator_name' => 'Operator',
            'uom' => 'UOM',
            'status' => 'Status',
            'created_at' => 'Date & Time'
        ];

        $totalTarget = 0; $totalOk = 0; $totalRej = 0;
        foreach ($rows as $r) {
            $totalTarget += floatval($r['target_qty']);
            $totalOk += floatval($r['ok_qty']);
            $totalRej += floatval($r['rejected_qty']);
        }
        $totProduced = $totalOk + $totalRej;
        $yieldRate = $totProduced > 0 ? round(($totalOk / $totProduced) * 100, 1) : 100;

        $summary = [
            ['label' => 'Total Entries', 'value' => count($rows)],
            ['label' => 'Total Target Planned', 'value' => number_format($totalTarget)],
            ['label' => 'Total OK Produced', 'value' => number_format($totalOk), 'highlight' => 'green'],
            ['label' => 'Total Scrapped', 'value' => number_format($totalRej), 'highlight' => ($totalRej > 0 ? 'red' : '')],
            ['label' => 'Overall Yield Rate', 'value' => $yieldRate . '%', 'highlight' => ($yieldRate >= 90 ? 'green' : 'amber')]
        ];
    }

    // -------------------------------------------------------------
    // 2. MATERIAL ISSUE (MIP) REPORT
    // -------------------------------------------------------------
    elseif ($reportType === 'mip') {
        $where = ["1=1"];
        $params = [];

        if (!empty($fromDate)) {
            $where[] = "issue_date >= ?";
            $params[] = $fromDate;
        }
        if (!empty($toDate)) {
            $where[] = "issue_date <= ?";
            $params[] = $toDate;
        }
        if (!empty($search)) {
            $where[] = "(issue_no LIKE ? OR work_order LIKE ? OR part_code LIKE ? OR part_name LIKE ? OR department LIKE ? OR received_by LIKE ?)";
            $term = "%{$search}%";
            array_push($params, $term, $term, $term, $term, $term, $term);
        }

        $sql = "
            SELECT id, issue_no, CONVERT(VARCHAR(10), issue_date, 120) AS issue_date,
                   work_order, part_code, part_name, issued_qty, uom,
                   department, received_by, status, remarks
            FROM material_issue
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [
            'issue_no' => 'Issue (MIP) No',
            'issue_date' => 'Issue Date',
            'work_order' => 'Work Order',
            'part_code' => 'Part Code',
            'part_name' => 'Part Name',
            'issued_qty' => 'Issued Quantity',
            'uom' => 'UOM',
            'department' => 'Department',
            'received_by' => 'Received By',
            'status' => 'Status',
            'remarks' => 'Remarks'
        ];

        $totIssued = 0;
        foreach ($rows as $r) $totIssued += floatval($r['issued_qty']);

        $summary = [
            ['label' => 'Total Issues', 'value' => count($rows)],
            ['label' => 'Total Qty Issued', 'value' => number_format($totIssued) . ' Units', 'highlight' => 'blue']
        ];
    }

    // -------------------------------------------------------------
    // 3. RAW MATERIAL (RM) INWARD REPORT
    // -------------------------------------------------------------
    elseif ($reportType === 'rm_inward') {
        $where = ["1=1"];
        $params = [];

        if (!empty($fromDate)) {
            $where[] = "inward_date >= ?";
            $params[] = $fromDate;
        }
        if (!empty($toDate)) {
            $where[] = "inward_date <= ?";
            $params[] = $toDate;
        }
        if (!empty($search)) {
            $where[] = "(inward_no LIKE ? OR vendor_name LIKE ? OR invoice_no LIKE ? OR rm_code LIKE ? OR rm_name LIKE ?)";
            $term = "%{$search}%";
            array_push($params, $term, $term, $term, $term, $term);
        }

        $sql = "
            SELECT id, inward_no, CONVERT(VARCHAR(10), inward_date, 120) AS inward_date,
                   vendor_name, invoice_no, CONVERT(VARCHAR(10), invoice_date, 120) AS invoice_date,
                   rm_code, rm_name, received_qty, uom
            FROM rm_inward
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [
            'inward_no' => 'Inward No',
            'inward_date' => 'Inward Date',
            'vendor_name' => 'Supplier / Vendor',
            'invoice_no' => 'Invoice No',
            'invoice_date' => 'Invoice Date',
            'rm_code' => 'RM Code',
            'rm_name' => 'Raw Material Name',
            'received_qty' => 'Received Qty',
            'uom' => 'UOM'
        ];

        $totRmIn = 0;
        foreach ($rows as $r) $totRmIn += floatval($r['received_qty']);

        $summary = [
            ['label' => 'Inward Invoices', 'value' => count($rows)],
            ['label' => 'Total RM Received', 'value' => number_format($totRmIn, 2) . ' KG', 'highlight' => 'green']
        ];
    }

    // -------------------------------------------------------------
    // 4. CHILD PART INWARD REPORT
    // -------------------------------------------------------------
    elseif ($reportType === 'child_inward') {
        $where = ["1=1"];
        $params = [];

        if (!empty($fromDate)) {
            $where[] = "inward_date >= ?";
            $params[] = $fromDate;
        }
        if (!empty($toDate)) {
            $where[] = "inward_date <= ?";
            $params[] = $toDate;
        }
        if (!empty($search)) {
            $where[] = "(inward_no LIKE ? OR vendor_name LIKE ? OR invoice_no LIKE ? OR part_code LIKE ? OR part_name LIKE ?)";
            $term = "%{$search}%";
            array_push($params, $term, $term, $term, $term, $term);
        }

        $sql = "
            SELECT id, inward_no, CONVERT(VARCHAR(10), inward_date, 120) AS inward_date,
                   vendor_name, invoice_no, CONVERT(VARCHAR(10), invoice_date, 120) AS invoice_date,
                   part_code, part_name, received_qty, uom
            FROM child_part_inward
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [
            'inward_no' => 'Inward No',
            'inward_date' => 'Inward Date',
            'vendor_name' => 'Supplier / Vendor',
            'invoice_no' => 'Invoice No',
            'invoice_date' => 'Invoice Date',
            'part_code' => 'RM & Child Part Code',
            'part_name' => 'RM & Child Part Name',
            'received_qty' => 'Received Qty',
            'uom' => 'UOM'
        ];

        $totCpIn = 0;
        foreach ($rows as $r) $totCpIn += floatval($r['received_qty']);

        $summary = [
            ['label' => 'Inward Entries', 'value' => count($rows)],
            ['label' => 'Total Components Received', 'value' => number_format($totCpIn) . ' PCS', 'highlight' => 'blue']
        ];
    }

    // -------------------------------------------------------------
    // 5. FINISHED GOODS (FG) DISPATCH REPORT
    // -------------------------------------------------------------
    elseif ($reportType === 'fg_dispatch') {
        $where = ["1=1"];
        $params = [];

        if (!empty($fromDate)) {
            $where[] = "dispatch_date >= ?";
            $params[] = $fromDate;
        }
        if (!empty($toDate)) {
            $where[] = "dispatch_date <= ?";
            $params[] = $toDate;
        }
        if (!empty($search)) {
            $where[] = "(dispatch_no LIKE ? OR invoice_ref LIKE ? OR part_code LIKE ? OR part_name LIKE ? OR customer LIKE ? OR batch_no LIKE ?)";
            $term = "%{$search}%";
            array_push($params, $term, $term, $term, $term, $term, $term);
        }

        $sql = "
            SELECT id, dispatch_no, CONVERT(VARCHAR(10), dispatch_date, 120) AS dispatch_date,
                   invoice_ref, part_code, part_name, batch_no,
                   dispatch_qty, uom, customer, dispatched_by, remarks
            FROM fg_dispatch_logs
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $columns = [
            'dispatch_no' => 'Dispatch No',
            'dispatch_date' => 'Dispatch Date',
            'invoice_ref' => 'Invoice Reference',
            'part_code' => 'Part Code',
            'part_name' => 'Part Name',
            'batch_no' => 'Batch / MIP No',
            'dispatch_qty' => 'Quantity Dispatched',
            'uom' => 'UOM',
            'customer' => 'Customer / Client',
            'dispatched_by' => 'Dispatched By',
            'remarks' => 'Remarks'
        ];

        $totDsp = 0;
        foreach ($rows as $r) $totDsp += floatval($r['dispatch_qty']);

        $summary = [
            ['label' => 'Total Dispatches', 'value' => count($rows)],
            ['label' => 'Total Units Dispatched', 'value' => number_format($totDsp) . ' Units', 'highlight' => 'blue']
        ];
    }

    // -------------------------------------------------------------
    // 6. INVENTORY STOCKS REPORT
    // -------------------------------------------------------------
    elseif ($reportType === 'inventory') {
        // Fetch combined inventory items (Excluding RM as hidden)
        $sql = "
            SELECT 'RM & Child Part' AS item_type, part_code AS code, part_name AS name,
                   current_stock, uom, status,
                   CONVERT(VARCHAR(19), updated_at, 120) AS last_updated
            FROM child_part_master
            UNION ALL
            SELECT 'Finished Goods (FG)' AS item_type, part_code AS code, part_name AS name,
                   total_ok_qty AS current_stock, uom, 'Active' AS status,
                   CONVERT(VARCHAR(19), updated_at, 120) AS last_updated
            FROM fg_inventory
            ORDER BY item_type, code
        ";
        $stmt = $pdo->query($sql);
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($search)) {
            $rows = array_values(array_filter($allRows, function($item) use ($search) {
                return (stripos($item['code'], $search) !== false) ||
                       (stripos($item['name'], $search) !== false) ||
                       (stripos($item['item_type'], $search) !== false);
            }));
        } else {
            $rows = $allRows;
        }

        $columns = [
            'item_type' => 'Category',
            'code' => 'Item Code',
            'name' => 'Item Description',
            'current_stock' => 'Current Available Stock',
            'uom' => 'UOM',
            'status' => 'Status',
            'last_updated' => 'Last Updated'
        ];

        $totItems = count($rows);
        $lowStockCount = 0;
        foreach ($rows as $r) {
            if (floatval($r['current_stock']) <= 5) $lowStockCount++;
        }

        $summary = [
            ['label' => 'Total Inventory Items', 'value' => $totItems],
            ['label' => 'Low Stock Warnings (<= 5)', 'value' => $lowStockCount, 'highlight' => ($lowStockCount > 0 ? 'red' : 'green')]
        ];
    }

    // -------------------------------------------------------------
    // CSV / EXCEL EXPORT HANDLER
    // -------------------------------------------------------------
    if ($export === 'csv' || $export === 'excel') {
        $filename = "report_{$reportType}_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Microsoft Excel renders accented/special characters correctly
        fputs($out, "\xEF\xBB\xBF");

        // Header Title
        fputcsv($out, ["WIP Management Portal - " . strtoupper(str_replace('_', ' ', $reportType)) . " REPORT"]);
        fputcsv($out, ["Generated On", date('Y-m-d H:i:s')]);
        if (!empty($fromDate) || !empty($toDate)) {
            fputcsv($out, ["Date Range Filter", ($fromDate ?: 'Start') . " to " . ($toDate ?: 'Present')]);
        }
        fputcsv($out, []); // Blank separator

        // Column Titles
        fputcsv($out, array_values($columns));

        // Rows
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $colKey) {
                $val = $row[$colKey] ?? '';
                if (is_numeric($val) && strpos((string)$val, '.') !== false) {
                    $val = rtrim(rtrim((string)$val, '0'), '.');
                }
                $line[] = $val;
            }
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }

    // JSON response for AJAX UI
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'report_type' => $reportType,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'count' => count($rows),
        'columns' => $columns,
        'rows' => $rows,
        'summary' => $summary
    ]);

} catch (Exception $e) {
    if ($export === 'csv' || $export === 'excel') {
        die("Export Error: " . $e->getMessage());
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
