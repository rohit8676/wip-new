<?php
/**
 * WIP Management Portal - RM Inward (RM In)
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Fetch real data from MSSQL database
$pdo = getDBConnection();
$inwards = [];
$totalCount = 0;
$totalQty = 0;
$todayCount = 0;
$todayDateStr = date('Y-m-d');

$activeVendors = [];
$activeRMs = [];

if ($pdo) {
    try {
        // 1. Fetch Inward Records (separate rows in rm_inward)
        $stmt = $pdo->query("
            SELECT id, inward_no, CONVERT(VARCHAR(10), inward_date, 120) as inward_date, 
                   vendor_name, invoice_no, CONVERT(VARCHAR(10), invoice_date, 120) as invoice_date, 
                   rm_code, rm_name, received_qty, uom, created_at 
            FROM rm_inward 
            ORDER BY id DESC
        ");
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group rows by inward_no for consolidated receipt UI display
        $grouped = [];
        foreach ($rawRows as $row) {
            $totalQty += floatval($row['received_qty'] ?? 0);
            $inwNo = !empty($row['inward_no']) ? $row['inward_no'] : ('ID_' . $row['id']);

            if (!isset($grouped[$inwNo])) {
                $grouped[$inwNo] = [
                    'id' => $row['id'],
                    'inward_no' => $row['inward_no'] ?? '',
                    'inward_date' => $row['inward_date'] ?? '',
                    'vendor_name' => $row['vendor_name'] ?? '',
                    'invoice_no' => $row['invoice_no'] ?? '',
                    'invoice_date' => $row['invoice_date'] ?? '',
                    'items' => [],
                    'received_qty' => 0,
                    'uom' => $row['uom'] ?? 'KG',
                    'created_at' => $row['created_at'] ?? ''
                ];

                if (($row['inward_date'] ?? '') === $todayDateStr) {
                    $todayCount++;
                }
            }

            $grouped[$inwNo]['items'][] = [
                'id' => $row['id'],
                'rm_code' => $row['rm_code'] ?? '',
                'rm_name' => $row['rm_name'] ?? '',
                'received_qty' => floatval($row['received_qty'] ?? 0),
                'uom' => $row['uom'] ?? 'KG'
            ];
            $grouped[$inwNo]['received_qty'] += floatval($row['received_qty'] ?? 0);
        }

        foreach ($grouped as $entry) {
            $itemsList = $entry['items'];
            $itemCount = count($itemsList);
            $firstItem = $itemCount > 0 ? $itemsList[0] : null;

            $entry['item_count'] = $itemCount;
            $entry['rm_code'] = ($itemCount === 1) ? ($firstItem['rm_code'] ?? '') : (($firstItem['rm_code'] ?? '') . " (+" . ($itemCount - 1) . " more)");
            $entry['rm_name'] = ($itemCount === 1) ? ($firstItem['rm_name'] ?? '') : ($itemCount . " RM Items");
            $entry['uom'] = $firstItem['uom'] ?? ($entry['uom'] ?? 'KG');
            $entry['items_data'] = json_encode($itemsList, JSON_UNESCAPED_UNICODE);

            $inwards[] = $entry;
        }

        $totalCount = count($inwards);

        // 2. Fetch Active Vendors for dropdown
        $vendorStmt = $pdo->query("SELECT vendor_code, vendor_name FROM vendor_master WHERE status = 'Active' ORDER BY vendor_name ASC");
        $activeVendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch Active RMs for dropdown
        $rmStmt = $pdo->query("SELECT rm_code, rm_name, uom FROM rm_master WHERE status = 'Active' ORDER BY rm_code ASC");
        $activeRMs = $rmStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

function formatDateDMY($dateStr) {
    if (empty($dateStr)) return '';
    $ts = strtotime($dateStr);
    return $ts ? date('d-m-Y', $ts) : $dateStr;
}

$pageTitle = 'RM Inward';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RM Inward (RM In) - WIP Management Portal</title>
  
  <!-- Modern Clean Google Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="css/dashboard.css">

  <style>
    /* Table specific & utility styles */
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
      background: #fee2e2;
      border-color: #ef4444;
    }

    /* Badge & highlights */
    .inward-code-badge {
      font-weight: 700;
      color: var(--primary);
      font-family: monospace;
      font-size: 0.9rem;
    }

    .qty-badge {
      display: inline-block;
      font-weight: 700;
      color: #065f46;
      background: #d1fae5;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 0.85rem;
    }

    .sub-meta {
      display: block;
      font-size: 0.75rem;
      color: var(--text-sub);
      margin-top: 2px;
    }

    /* Toast Notification */
    .toast-container {
      position: fixed;
      top: 24px;
      right: 24px;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 10px;
      pointer-events: none;
    }

    .toast {
      min-width: 280px;
      max-width: 380px;
      background: #ffffff;
      border-radius: 8px;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.08);
      padding: 12px 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-left: 4px solid var(--primary);
      opacity: 0;
      transform: translateY(-12px);
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      pointer-events: auto;
      font-size: 0.88rem;
      color: var(--text-main);
    }

    .toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    .toast-success {
      border-left-color: #10b981;
    }

    .toast-error {
      border-left-color: #ef4444;
    }

    .toast-icon {
      flex-shrink: 0;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .toast-icon svg {
      width: 18px;
      height: 18px;
    }

    .toast-success .toast-icon {
      color: #10b981;
    }

    .toast-error .toast-icon {
      color: #ef4444;
    }

    .toast-message {
      flex: 1;
      line-height: 1.4;
      font-weight: 500;
    }

    /* Modal Styles */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(2px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.2s ease, visibility 0.2s ease;
      padding: 16px;
    }

    .modal-overlay.active {
      opacity: 1;
      visibility: visible;
    }

    .modal-card {
      background: #ffffff;
      border-radius: 10px;
      width: 100%;
      max-width: 650px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.08);
      overflow: hidden;
      transform: scale(0.96) translateY(-8px);
      transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .modal-overlay.active .modal-card {
      transform: scale(1) translateY(0);
    }

    .modal-header {
      padding: 16px 22px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #ffffff;
    }

    .modal-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-main);
      margin: 0;
    }

    .modal-close-btn {
      background: transparent;
      border: none;
      font-size: 1.5rem;
      line-height: 1;
      color: var(--text-sub);
      cursor: pointer;
      padding: 0 4px;
    }

    .modal-close-btn:hover {
      color: var(--text-main);
    }

    .modal-body {
      padding: 20px 22px;
      max-height: 75vh;
      overflow-y: auto;
    }

    .inward-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .rm-item-row {
      display: grid;
      grid-template-columns: 2fr 1fr 36px;
      gap: 12px;
      align-items: flex-start;
      background: #f8fafc;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
    }

    .item-field-label {
      font-size: 0.78rem;
      font-weight: 600;
      color: var(--text-sub);
      margin-bottom: 4px;
    }

    .btn-add-item-row {
      padding: 5px 12px;
      background: var(--primary-light);
      border: 1px solid var(--primary);
      border-radius: 6px;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--primary);
      cursor: pointer;
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .btn-add-item-row:hover {
      background: var(--primary);
      color: #ffffff;
    }

    .btn-remove-item-row {
      height: 38px;
      width: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #ffffff;
      border: 1px solid #fecaca;
      border-radius: 6px;
      color: #ef4444;
      font-size: 1.25rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-remove-item-row:hover:not(:disabled) {
      background: #fee2e2;
      border-color: #ef4444;
    }

    .btn-remove-item-row:disabled {
      opacity: 0.35;
      cursor: not-allowed;
      border-color: var(--border);
      color: var(--text-sub);
    }

    @media (max-width: 580px) {
      .inward-form-grid {
        grid-template-columns: 1fr;
      }
      .rm-item-row {
        grid-template-columns: 1fr 1fr 36px;
      }
      .item-col-rm {
        grid-column: 1 / -1;
      }
    }

    .inw-form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .inw-form-group label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .inw-form-group .form-control {
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

    .inw-form-group textarea.form-control {
      height: auto;
      min-height: 60px;
      padding: 8px 12px;
      resize: vertical;
    }

    .inw-form-group .form-control:focus {
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
      height: 36px;
      padding: 0 14px;
      background: #ffffff;
      color: var(--text-main);
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .btn-secondary:hover {
      background: #f1f5f9;
    }

    /* Delete Confirm Modal */
    .confirm-modal-card {
      max-width: 420px !important;
      text-align: center;
      padding: 24px 20px;
    }

    .confirm-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: #fee2e2;
      color: #ef4444;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 14px auto;
    }

    .confirm-icon-box svg {
      width: 26px;
      height: 26px;
    }

    .confirm-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 8px;
    }

    .confirm-desc {
      font-size: 0.88rem;
      color: var(--text-sub);
      line-height: 1.45;
      margin-bottom: 22px;
    }

    .confirm-actions {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
    }

    .btn-danger-confirm {
      height: 36px;
      padding: 0 18px;
      background: #ef4444;
      color: #ffffff;
      border: none;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }

    .btn-danger-confirm:hover {
      background: #dc2626;
    }
  </style>
</head>
<body>

  <!-- Toast Notification Container -->
  <div id="toastContainer" class="toast-container" aria-live="polite"></div>

  <!-- Mobile Sidebar Backdrop -->
  <div id="sidebarBackdrop" class="sidebar-backdrop"></div>

  <div class="layout-container">
    
    <!-- Reusable Sidebar Component -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- ==========================================
         Main Area
         ========================================== -->
    <div class="main-content">
      
      <!-- Reusable Top Bar Component -->
      <?php include __DIR__ . '/includes/header.php'; ?>

      <!-- Page Body -->
      <div class="content-body">
        
        <!-- Simple 3 Stats Cards -->
        <div class="stats-row">
          <div class="simple-card stat-box">
            <div class="stat-number" id="statTotalInwards"><?php echo number_format($totalCount); ?></div>
            <div class="stat-title">Total Inward Entries</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statTotalQty"><?php echo number_format($totalQty, 2); ?></div>
            <div class="stat-title">Total Qty Received (kg)</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statTodayInwards"><?php echo number_format($todayCount); ?></div>
            <div class="stat-title">Today's Inwards</div>
          </div>
        </div>

        <!-- ==========================================
             Simple Table Card
             ========================================== -->
        <div class="simple-card table-box">
          <div class="table-bar">
            <h2 class="box-title">RM Inward Register</h2>
            <div class="table-actions">
              <input type="text" id="inwardSearch" class="simple-input" placeholder="Search Vendor, Invoice No, RM Item...">
              <?php if (hasPermission('rm_in', 'create')): ?>
              <button type="button" class="btn-primary" id="openAddModalBtn">+ Inward RM</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-wrap">
            <table class="simple-table" id="inwardTable">
              <thead>
                <tr>
                  <th style="width: 55px; text-align: center;">Sr No.</th>
                  <th style="width: 155px;">Inward No. & Date</th>
                  <th>Vendor</th>
                  <th>Invoice / Challan No.</th>
                  <th style="width: 120px;">Invoice Date</th>
                  <th>RM Item</th>
                  <th style="width: 140px;">Received Qty</th>
                  <th style="width: 175px; text-align: center;">Action</th>
                </tr>
              </thead>
              <tbody id="inwardTableBody">
                <?php if (!empty($inwards)): ?>
                  <?php $sr = 1; ?>
                  <?php foreach ($inwards as $item): ?>
                    <?php
                      $rowItemsJson = $item['items_data'] ?? '';
                      if (empty($rowItemsJson)) {
                          $rowItemsJson = json_encode([[
                              'rm_code' => $item['rm_code'] ?? '',
                              'rm_name' => $item['rm_name'] ?? '',
                              'received_qty' => floatval($item['received_qty'] ?? 0),
                              'uom' => $item['uom'] ?? 'KG'
                          ]], JSON_UNESCAPED_UNICODE);
                      }
                      $parsedItems = json_decode($rowItemsJson, true) ?: [];
                      $itemCount = count($parsedItems);
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>"
                        data-inward_no="<?php echo htmlspecialchars($item['inward_no'] ?? ''); ?>"
                        data-inward_date="<?php echo htmlspecialchars($item['inward_date'] ?? ''); ?>"
                        data-vendor_name="<?php echo htmlspecialchars($item['vendor_name'] ?? ''); ?>"
                        data-invoice_no="<?php echo htmlspecialchars($item['invoice_no'] ?? ''); ?>"
                        data-invoice_date="<?php echo htmlspecialchars($item['invoice_date'] ?? ''); ?>"
                        data-rm_code="<?php echo htmlspecialchars($item['rm_code'] ?? ''); ?>"
                        data-rm_name="<?php echo htmlspecialchars($item['rm_name'] ?? ''); ?>"
                        data-received_qty="<?php echo htmlspecialchars($item['received_qty'] ?? '0'); ?>"
                        data-uom="<?php echo htmlspecialchars($item['uom'] ?? 'KG'); ?>"
                        data-items="<?php echo htmlspecialchars($rowItemsJson, ENT_QUOTES, 'UTF-8'); ?>">
                      <td style="color: var(--text-sub); font-weight: 600; text-align: center;"><?php echo $sr++; ?></td>
                      <td class="col-inward-info">
                        <?php if (!empty($item['inward_no'])): ?>
                          <strong class="inward-code-badge"><?php echo htmlspecialchars($item['inward_no']); ?></strong>
                        <?php else: ?>
                          <span style="color: var(--text-sub);">-</span>
                        <?php endif; ?>
                        <?php if (!empty($item['inward_date'])): ?>
                          <span class="sub-meta"><?php echo htmlspecialchars(formatDateDMY($item['inward_date'])); ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <strong class="col-vendor-name"><?php echo htmlspecialchars($item['vendor_name']); ?></strong>
                      </td>
                      <td class="col-invoice-no">
                        <?php if (!empty($item['invoice_no'])): ?>
                          <strong><?php echo htmlspecialchars($item['invoice_no']); ?></strong>
                        <?php else: ?>
                          <span style="color: var(--text-sub);">-</span>
                        <?php endif; ?>
                      </td>
                      <td class="col-invoice-date">
                        <?php echo !empty($item['invoice_date']) ? htmlspecialchars(formatDateDMY($item['invoice_date'])) : '<span style="color: var(--text-sub);">-</span>'; ?>
                      </td>
                      <td>
                        <div class="col-rm-info">
                          <?php if ($itemCount > 1): ?>
                            <strong style="color: var(--primary);"><?php echo $itemCount; ?> RM Items</strong>
                            <span class="sub-meta"><?php echo htmlspecialchars($item['rm_code']); ?></span>
                          <?php else: ?>
                            <strong><?php echo htmlspecialchars($item['rm_code']); ?></strong>
                            <?php if (!empty($item['rm_name'])): ?>
                              <span class="sub-meta"><?php echo htmlspecialchars($item['rm_name']); ?></span>
                            <?php endif; ?>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="col-qty">
                        <span class="qty-badge">
                          <?php echo number_format(floatval($item['received_qty']), 2); ?>
                        </span>
                      </td>
                      <td style="text-align: center;">
                        <div style="display: flex; gap: 5px; justify-content: center;">
                          <button type="button" class="btn-view" title="View Inward Details">View</button>
                          <?php if (hasPermission('rm_in', 'update')): ?>
                          <button type="button" class="btn-edit" title="Edit Inward Entry">Edit</button>
                          <?php endif; ?>
                          <?php if (hasPermission('rm_in', 'delete')): ?>
                          <button type="button" class="btn-delete" title="Delete Inward Entry">Delete</button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr id="emptyTableRow">
                    <td colspan="8" class="empty-row-msg">No RM inward records found. Click "+ Inward RM" to create one.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

    </div>

  </div>

  <!-- ==========================================
       Add / Edit RM Inward Popup Modal
       ========================================== -->
  <div id="rmInPopupModal" class="modal-overlay">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title" id="modalFormTitle">+ Inward Raw Material</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="rmInPopupForm">
        <input type="hidden" id="editItemId" value="">
        <input type="hidden" id="inputInwardNo" value="">
        <input type="hidden" id="inputInwardDate" value="<?php echo date('Y-m-d'); ?>">

        <div class="modal-body">
          <div class="inward-form-grid">
            
            <!-- Vendor Dropdown -->
            <div class="inw-form-group" style="grid-column: 1 / -1;">
              <label for="inputVendorName">Vendor *</label>
              <select id="inputVendorName" class="form-control" required>
                <option value="">-- Select Vendor --</option>
                <?php foreach ($activeVendors as $v): ?>
                  <option value="<?php echo htmlspecialchars($v['vendor_name']); ?>">
                    <?php echo htmlspecialchars($v['vendor_name']); ?> (<?php echo htmlspecialchars($v['vendor_code']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Invoice / Challan No. -->
            <div class="inw-form-group">
              <label for="inputInvoiceNo">Invoice / Challan No.</label>
              <input type="text" id="inputInvoiceNo" class="form-control" placeholder="e.g. INV-2026-901">
            </div>

            <!-- Invoice Date -->
            <div class="inw-form-group">
              <label for="inputInvoiceDate">Invoice Date</label>
              <input type="date" id="inputInvoiceDate" class="form-control">
            </div>

            <!-- Multiple Raw Material Items Section -->
            <div style="grid-column: 1 / -1; margin-top: 6px;">
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <label style="font-size: 0.84rem; font-weight: 700; color: var(--text-main);">Raw Material Items *</label>
                <button type="button" id="btnAddItemRow" class="btn-add-item-row" title="Add another raw material item">+ Add More Item</button>
              </div>

              <div id="itemsContainer" style="display: flex; flex-direction: column; gap: 10px;">
                <!-- Dynamically injected item rows -->
              </div>
            </div>

          </div>
        </div>

        <!-- Action Buttons -->
        <div class="modal-footer">
          <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
          <button type="submit" class="btn-primary" id="saveInwardSubmitBtn">+ Save Inward Entry</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Item Row Template for Multiple Items -->
  <template id="itemRowTemplate">
    <div class="rm-item-row">
      <!-- RM Item -->
      <div class="inw-form-group item-col-rm">
        <label class="item-field-label">RM Item *</label>
        <select class="form-control item-rm-code" required>
          <option value="">-- Select Raw Material --</option>
          <?php foreach ($activeRMs as $rm): ?>
            <option value="<?php echo htmlspecialchars($rm['rm_code']); ?>" 
                    data-name="<?php echo htmlspecialchars($rm['rm_name']); ?>" 
                    data-uom="<?php echo htmlspecialchars($rm['uom']); ?>">
              <?php echo htmlspecialchars($rm['rm_code']); ?> - <?php echo htmlspecialchars($rm['rm_name']); ?> (<?php echo htmlspecialchars($rm['uom']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" class="item-rm-name" value="">
      </div>

      <!-- Received Quantity -->
      <div class="inw-form-group item-col-qty">
        <label class="item-field-label">Received Qty *</label>
        <input type="number" step="0.01" min="0.01" class="form-control item-received-qty" placeholder="0.00" required>
      </div>

      <!-- Hidden UOM (Auto-retrieved from RM Item) -->
      <input type="hidden" class="item-uom" value="KG">

      <!-- Action: Remove -->
      <div class="item-col-action">
        <label class="item-field-label" style="visibility: hidden;">Del</label>
        <button type="button" class="btn-remove-item-row" title="Remove this item">&times;</button>
      </div>
    </div>
  </template>

  <!-- ==========================================
       View Details Popup Modal
       ========================================== -->
  <div id="viewInwardModal" class="modal-overlay">
    <div class="modal-card" style="max-width: 620px;">
      <div class="modal-header">
        <h3 class="modal-title" style="display: flex; align-items: center; gap: 8px;">
          <span>Inward Details</span>
          <span id="viewInwardBadge" class="inward-code-badge" style="font-size: 0.95rem;"></span>
        </h3>
        <button type="button" class="modal-close-btn" id="closeViewModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <div class="modal-body" style="padding: 20px 24px; max-height: 75vh; overflow-y: auto;">
        <!-- Top Info Cards Grid -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; background: #f8fafc; padding: 14px 16px; border-radius: 8px; border: 1px solid var(--border);">
          <div>
            <div style="font-size: 0.74rem; font-weight: 600; color: var(--text-sub); text-transform: uppercase;">Vendor Name</div>
            <div id="viewVendor" style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin-top: 3px;"></div>
          </div>
          <div>
            <div style="font-size: 0.74rem; font-weight: 600; color: var(--text-sub); text-transform: uppercase;">Inward Date</div>
            <div id="viewInwardDate" style="font-size: 0.92rem; font-weight: 500; color: var(--text-main); margin-top: 3px;"></div>
          </div>
          <div>
            <div style="font-size: 0.74rem; font-weight: 600; color: var(--text-sub); text-transform: uppercase;">Invoice / Challan No.</div>
            <div id="viewInvoiceNo" style="font-size: 0.92rem; font-weight: 600; color: var(--text-main); margin-top: 3px;"></div>
          </div>
          <div>
            <div style="font-size: 0.74rem; font-weight: 600; color: var(--text-sub); text-transform: uppercase;">Invoice Date</div>
            <div id="viewInvoiceDate" style="font-size: 0.92rem; font-weight: 500; color: var(--text-main); margin-top: 3px;"></div>
          </div>
        </div>

        <!-- Raw Materials Table Section -->
        <div style="margin-top: 18px;">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
            <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-main);">Raw Material Items & Quantities</div>
            <span id="viewItemsCountBadge" class="tag tag-completed">0 Items</span>
          </div>

          <div style="border: 1px solid var(--border); border-radius: 8px; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
              <thead>
                <tr style="background: #f1f5f9; border-bottom: 1px solid var(--border);">
                  <th style="padding: 9px 12px; width: 45px; color: var(--text-sub); font-weight: 600; text-align: center;">#</th>
                  <th style="padding: 9px 12px; color: var(--text-sub); font-weight: 600;">Raw Material</th>
                  <th style="padding: 9px 12px; text-align: right; width: 130px; color: var(--text-sub); font-weight: 600;">Received Qty</th>
                  <th style="padding: 9px 12px; text-align: center; width: 70px; color: var(--text-sub); font-weight: 600;">UOM</th>
                </tr>
              </thead>
              <tbody id="viewItemsTableBody">
                <!-- Dynamically populated rows -->
              </tbody>
              <tfoot id="viewItemsTableFoot" style="background: #f8fafc; border-top: 1px solid var(--border); font-weight: 700;">
                <!-- Total row -->
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-secondary" id="closeViewModalBtn2">Close</button>
      </div>
    </div>
  </div>

  <!-- ==========================================
       Delete Confirmation Popup Modal
       ========================================== -->
  <div id="deleteConfirmModal" class="modal-overlay">
    <div class="modal-card confirm-modal-card">
      <div class="confirm-icon-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
          <line x1="12" y1="9" x2="12" y2="13"></line>
          <line x1="12" y1="17" x2="12.01" y2="17"></line>
        </svg>
      </div>
      <h3 class="confirm-title">Confirm Deletion</h3>
      <p class="confirm-desc">Are you sure you want to delete Inward Entry <strong id="deleteTargetInwardNo" style="color:var(--text-main);"></strong>? This receipt record will be removed from the database.</p>
      <div class="confirm-actions">
        <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-danger-confirm" id="confirmDeleteBtn">Delete Inward</button>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // 1. Toast Notification Helper
      function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const iconSvg = type === 'success' 
          ? `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`
          : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`;

        toast.innerHTML = `
          <div class="toast-icon">${iconSvg}</div>
          <div class="toast-message">${escapeHtml(message)}</div>
        `;

        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));

        setTimeout(() => {
          toast.classList.remove('show');
          setTimeout(() => toast.remove(), 250);
        }, 3200);
      }

      function escapeHtml(text) {
        if (!text) return '';
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

      // 2. Elements Cache
      const modal = document.getElementById('rmInPopupModal');
      const modalTitle = document.getElementById('modalFormTitle');
      const form = document.getElementById('rmInPopupForm');
      const openAddBtn = document.getElementById('openAddModalBtn');
      const closeModalBtn = document.getElementById('closeModalBtn');
      const cancelModalBtn = document.getElementById('cancelModalBtn');
      const saveSubmitBtn = document.getElementById('saveInwardSubmitBtn');

      const editItemId = document.getElementById('editItemId');
      const inputInwardNo = document.getElementById('inputInwardNo');
      const inputInwardDate = document.getElementById('inputInwardDate');
      const inputVendorName = document.getElementById('inputVendorName');
      const inputInvoiceNo = document.getElementById('inputInvoiceNo');
      const inputInvoiceDate = document.getElementById('inputInvoiceDate');

      const itemsContainer = document.getElementById('itemsContainer');
      const btnAddItemRow = document.getElementById('btnAddItemRow');

      const tableBody = document.getElementById('inwardTableBody');
      const searchInput = document.getElementById('inwardSearch');

      // Stat Counters
      const statTotalInwards = document.getElementById('statTotalInwards');
      const statTotalQty = document.getElementById('statTotalQty');
      const statTodayInwards = document.getElementById('statTodayInwards');

      // Delete Modal
      const deleteModal = document.getElementById('deleteConfirmModal');
      const deleteTargetInwardNo = document.getElementById('deleteTargetInwardNo');
      const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
      let itemToDeleteId = null;
      let itemToDeleteInwardNo = null;
      let itemToDeleteRow = null;

      // 3. Dynamic Item Row Helpers (Add, Remove, Auto UOM)
      function addItemRow(initialData = null) {
        const tpl = document.getElementById('itemRowTemplate');
        const clone = tpl.content.cloneNode(true);
        const row = clone.querySelector('.rm-item-row');
        const rmSelect = row.querySelector('.item-rm-code');
        const rmNameHidden = row.querySelector('.item-rm-name');
        const qtyInput = row.querySelector('.item-received-qty');
        const uomInput = row.querySelector('.item-uom');
        const removeBtn = row.querySelector('.btn-remove-item-row');

        // Auto-fill UOM and name when RM selected
        rmSelect.addEventListener('change', () => {
          const selected = rmSelect.options[rmSelect.selectedIndex];
          if (selected && selected.value) {
            rmNameHidden.value = selected.getAttribute('data-name') || '';
            uomInput.value = (selected.getAttribute('data-uom') || 'KG').toUpperCase();
          } else {
            rmNameHidden.value = '';
            uomInput.value = 'KG';
          }
        });

        // Remove row listener
        removeBtn.addEventListener('click', () => {
          const allRows = itemsContainer.querySelectorAll('.rm-item-row');
          if (allRows.length > 1) {
            row.remove();
            updateRemoveButtons();
          } else {
            showToast('At least one item is required.', 'error');
          }
        });

        // Populate initial data if in edit mode
        if (initialData) {
          if (initialData.rm_code) rmSelect.value = initialData.rm_code;
          if (initialData.rm_name) rmNameHidden.value = initialData.rm_name;
          if (initialData.received_qty) qtyInput.value = initialData.received_qty;
          if (initialData.uom) uomInput.value = initialData.uom;
        }

        itemsContainer.appendChild(row);
        updateRemoveButtons();
        return row;
      }

      function updateRemoveButtons() {
        const allRows = itemsContainer.querySelectorAll('.rm-item-row');
        allRows.forEach(r => {
          const btn = r.querySelector('.btn-remove-item-row');
          if (btn) btn.disabled = (allRows.length <= 1);
        });
      }

      if (btnAddItemRow) {
        btnAddItemRow.addEventListener('click', () => {
          const newRow = addItemRow();
          const newSelect = newRow.querySelector('.item-rm-code');
          if (newSelect) newSelect.focus();
        });
      }

      // 4. Modal Open & Close
      function openModal(isEdit = false, editItemData = null) {
        if (!isEdit) {
          modalTitle.textContent = '+ Inward Raw Material';
          saveSubmitBtn.textContent = '+ Save Inward Entry';
          form.reset();
          editItemId.value = '';
          inputInwardNo.value = '';
          inputInwardDate.value = new Date().toISOString().split('T')[0];
          inputVendorName.value = '';
          inputInvoiceNo.value = '';
          inputInvoiceDate.value = '';
          itemsContainer.innerHTML = '';
          addItemRow();
          if (btnAddItemRow) btnAddItemRow.style.display = 'inline-flex';
        } else {
          modalTitle.textContent = 'Edit RM Inward';
          saveSubmitBtn.textContent = 'Update Inward Entry';
          itemsContainer.innerHTML = '';
          if (Array.isArray(editItemData) && editItemData.length > 0) {
            editItemData.forEach(it => addItemRow(it));
          } else if (editItemData) {
            addItemRow(editItemData);
          } else {
            addItemRow();
          }
          if (btnAddItemRow) btnAddItemRow.style.display = 'inline-flex';
        }
        modal.classList.add('active');
        setTimeout(() => {
          if (!isEdit) {
            inputVendorName.focus();
          } else {
            const firstQty = itemsContainer.querySelector('.item-received-qty');
            if (firstQty) firstQty.focus();
          }
        }, 100);
      }

      function closeModal() {
        modal.classList.remove('active');
        form.reset();
        editItemId.value = '';
      }

      if (openAddBtn) openAddBtn.addEventListener('click', () => openModal(false));
      if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
      if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });

      // 5. Recalculate Stats Numbers
      function refreshStats() {
        const rows = tableBody.querySelectorAll('tr[data-id]');
        const total = rows.length;
        let sumQty = 0;
        let todayCount = 0;
        const todayStr = new Date().toISOString().split('T')[0];

        rows.forEach(r => {
          const q = parseFloat(r.dataset.received_qty || 0);
          sumQty += isNaN(q) ? 0 : q;
          if (r.dataset.inward_date === todayStr) {
            todayCount++;
          }
        });

        if (statTotalInwards) statTotalInwards.textContent = total.toLocaleString();
        if (statTotalQty) statTotalQty.textContent = sumQty.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (statTodayInwards) statTodayInwards.textContent = todayCount.toLocaleString();

        const emptyRow = document.getElementById('emptyTableRow');
        if (total === 0) {
          if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.id = 'emptyTableRow';
            tr.innerHTML = `<td colspan="8" class="empty-row-msg">No RM inward records found. Click "+ Inward RM" to create one.</td>`;
            tableBody.appendChild(tr);
          }
        } else if (emptyRow) {
          emptyRow.remove();
        }

        reindexSerialNumbers();
      }

      function reindexSerialNumbers() {
        const visibleRows = Array.from(tableBody.querySelectorAll('tr[data-id]')).filter(r => r.style.display !== 'none');
        visibleRows.forEach((r, idx) => {
          const firstCell = r.querySelector('td:first-child');
          if (firstCell) firstCell.textContent = idx + 1;
        });
      }

      // 6. Form Submission (Add or Update)
      form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const id = editItemId.value ? parseInt(editItemId.value, 10) : null;
        const isEdit = !!id;

        // Gather all rows from itemsContainer
        const itemRows = itemsContainer.querySelectorAll('.rm-item-row');
        const items = [];

        itemRows.forEach(row => {
          const rmSelect = row.querySelector('.item-rm-code');
          const rmNameHidden = row.querySelector('.item-rm-name');
          const qtyInput = row.querySelector('.item-received-qty');
          const uomSelect = row.querySelector('.item-uom');

          const rmCode = rmSelect ? rmSelect.value.trim() : '';
          const rmName = rmNameHidden ? (rmNameHidden.value.trim() || rmSelect.options[rmSelect.selectedIndex]?.getAttribute('data-name') || '') : '';
          const qty = qtyInput ? (parseFloat(qtyInput.value) || 0) : 0;
          const uom = uomSelect ? (uomSelect.value.trim() || 'KG') : 'KG';

          if (rmCode && qty > 0) {
            items.push({
              rm_code: rmCode,
              rm_name: rmName,
              received_qty: qty,
              uom: uom
            });
          }
        });

        if (!inputVendorName.value.trim()) {
          showToast('Please select a vendor.', 'error');
          return;
        }

        if (items.length === 0) {
          showToast('Please select at least one RM item and enter a valid quantity.', 'error');
          return;
        }

        const payload = {
          action: isEdit ? 'update' : 'create',
          id: id,
          inward_no: inputInwardNo.value.trim(),
          inward_date: inputInwardDate.value.trim(),
          vendor_name: inputVendorName.value.trim(),
          invoice_no: inputInvoiceNo.value.trim(),
          invoice_date: inputInvoiceDate.value.trim(),
          items: items
        };

        saveSubmitBtn.disabled = true;
        saveSubmitBtn.textContent = 'Saving...';

        try {
          const res = await fetch('api/rm_in.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });

          const data = await res.json();

          if (!res.ok || !data.success) {
            throw new Error(data.message || 'Operation failed. Please try again.');
          }

          const savedData = data.data;

          if (isEdit) {
            // Update existing row
            let row = tableBody.querySelector(`tr[data-id="${id}"]`) || 
                      (savedData.inward_no ? tableBody.querySelector(`tr[data-inward_no="${savedData.inward_no}"]`) : null);
            if (row) {
              row.dataset.id = savedData.id;
              updateTableRow(row, savedData);
            }
            showToast(data.message || 'RM Inward entry updated successfully!', 'success');
          } else {
            // Prepend new single row
            const emptyRow = document.getElementById('emptyTableRow');
            if (emptyRow) emptyRow.remove();

            const newRow = createTableRow(savedData);
            tableBody.insertBefore(newRow, tableBody.firstChild);
            showToast(data.message || 'RM Inward entry created successfully!', 'success');
          }

          closeModal();
          refreshStats();

        } catch (err) {
          showToast(err.message || 'Error occurred while saving.', 'error');
        } finally {
          saveSubmitBtn.disabled = false;
          saveSubmitBtn.textContent = isEdit ? 'Update Inward Entry' : '+ Save Inward Entry';
        }
      });

      // 7. Render Helper: Create Row
      function createTableRow(item) {
        const tr = document.createElement('tr');
        tr.dataset.id = item.id;
        updateTableRow(tr, item);
        return tr;
      }

      // 8. Render Helper: Update Row Content & Datasets
      function updateTableRow(tr, item) {
        tr.dataset.inward_no = item.inward_no || '';
        tr.dataset.inward_date = item.inward_date || '';
        tr.dataset.vendor_name = item.vendor_name || '';
        tr.dataset.invoice_no = item.invoice_no || '';
        tr.dataset.invoice_date = item.invoice_date || '';
        tr.dataset.rm_code = item.rm_code || '';
        tr.dataset.rm_name = item.rm_name || '';
        tr.dataset.received_qty = item.received_qty || '0';
        tr.dataset.uom = item.uom || 'KG';

        let itemsData = item.items_data;
        if (!itemsData && item.items) {
          itemsData = JSON.stringify(item.items);
        } else if (!itemsData) {
          itemsData = JSON.stringify([{
            rm_code: item.rm_code || '',
            rm_name: item.rm_name || '',
            received_qty: item.received_qty || 0,
            uom: item.uom || 'KG'
          }]);
        }
        tr.dataset.items = itemsData;

        let parsedItems = [];
        try {
          parsedItems = JSON.parse(itemsData || '[]');
        } catch(e) {}
        const itemCount = Array.isArray(parsedItems) ? parsedItems.length : 1;

        const formattedQty = parseFloat(item.received_qty || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const formattedInwardDate = formatDateDMY(item.inward_date);
        const formattedInvoiceDate = formatDateDMY(item.invoice_date);

        const inwardNoHtml = item.inward_no 
          ? `<strong class="inward-code-badge">${escapeHtml(item.inward_no)}</strong>` 
          : `<span style="color:var(--text-sub);">-</span>`;
        const inwardDateHtml = formattedInwardDate 
          ? `<span class="sub-meta">${escapeHtml(formattedInwardDate)}</span>` 
          : '';
        const invoiceNoHtml = item.invoice_no ? `<strong>${escapeHtml(item.invoice_no)}</strong>` : `<span style="color:var(--text-sub);">-</span>`;
        const invoiceDateHtml = formattedInvoiceDate 
          ? escapeHtml(formattedInvoiceDate) 
          : `<span style="color:var(--text-sub);">-</span>`;

        const rmInfoHtml = itemCount > 1 ? `
          <strong style="color: var(--primary);">${itemCount} RM Items</strong>
          <span class="sub-meta">${escapeHtml(item.rm_code)}</span>
        ` : `
          <strong>${escapeHtml(item.rm_code)}</strong>
          ${item.rm_name ? `<span class="sub-meta">${escapeHtml(item.rm_name)}</span>` : ''}
        `;

        tr.innerHTML = `
          <td style="color: var(--text-sub); font-weight: 600; text-align: center;">#</td>
          <td class="col-inward-info">
            ${inwardNoHtml}
            ${inwardDateHtml}
          </td>
          <td><strong class="col-vendor-name">${escapeHtml(item.vendor_name)}</strong></td>
          <td class="col-invoice-no">${invoiceNoHtml}</td>
          <td class="col-invoice-date">${invoiceDateHtml}</td>
          <td>
            <div class="col-rm-info">
              ${rmInfoHtml}
            </div>
          </td>
          <td class="col-qty">
            <span class="qty-badge">${formattedQty}</span>
          </td>
          <td style="text-align: center;">
            <div style="display: flex; gap: 5px; justify-content: center;">
              <button type="button" class="btn-view" title="View Inward Details">View</button>
              <button type="button" class="btn-edit" title="Edit Inward Entry">Edit</button>
              <button type="button" class="btn-delete" title="Delete Inward Entry">Delete</button>
            </div>
          </td>
        `;
      }

      // 9. Row Action Delegation (View, Edit & Delete)
      tableBody.addEventListener('click', (e) => {
        const viewBtn = e.target.closest('.btn-view');
        const editBtn = e.target.closest('.btn-edit');
        const deleteBtn = e.target.closest('.btn-delete');
        const row = e.target.closest('tr[data-id]');

        if (!row) return;

        if (viewBtn) {
          openViewModal(row);
        } else if (editBtn) {
          // Open edit modal with row dataset
          editItemId.value = row.dataset.id;
          inputInwardNo.value = row.dataset.inward_no || '';
          inputInwardDate.value = row.dataset.inward_date || '';
          inputVendorName.value = row.dataset.vendor_name || '';
          inputInvoiceNo.value = row.dataset.invoice_no || '';
          inputInvoiceDate.value = row.dataset.invoice_date || '';

          let parsedItems = [];
          try {
            parsedItems = JSON.parse(row.dataset.items || '[]');
          } catch(e) {}

          if (!Array.isArray(parsedItems) || parsedItems.length === 0) {
            parsedItems = [{
              rm_code: row.dataset.rm_code || '',
              rm_name: row.dataset.rm_name || '',
              received_qty: row.dataset.received_qty || '',
              uom: row.dataset.uom || 'KG'
            }];
          }

          openModal(true, parsedItems);
        } else if (deleteBtn) {
          // Open delete confirm modal
          itemToDeleteId = row.dataset.id;
          itemToDeleteInwardNo = row.dataset.inward_no || '';
          itemToDeleteRow = row;
          deleteTargetInwardNo.textContent = row.dataset.inward_no || `Entry #${itemToDeleteId}`;
          deleteModal.classList.add('active');
        }
      });

      // 10. View Modal Handlers
      const viewModal = document.getElementById('viewInwardModal');
      const closeViewModalBtn = document.getElementById('closeViewModalBtn');
      const closeViewModalBtn2 = document.getElementById('closeViewModalBtn2');

      function openViewModal(row) {
        if (!viewModal) return;
        const inwardNo = row.dataset.inward_no || `Entry #${row.dataset.id}`;
        document.getElementById('viewInwardBadge').textContent = inwardNo;
        document.getElementById('viewVendor').textContent = row.dataset.vendor_name || '-';
        document.getElementById('viewInwardDate').textContent = formatDateDMY(row.dataset.inward_date) || '-';
        document.getElementById('viewInvoiceNo').textContent = row.dataset.invoice_no || '-';
        document.getElementById('viewInvoiceDate').textContent = formatDateDMY(row.dataset.invoice_date) || '-';

        let items = [];
        try {
          items = JSON.parse(row.dataset.items || '[]');
        } catch(e) {}

        if (!Array.isArray(items) || items.length === 0) {
          items = [{
            rm_code: row.dataset.rm_code || '-',
            rm_name: row.dataset.rm_name || '',
            received_qty: parseFloat(row.dataset.received_qty || 0),
            uom: row.dataset.uom || 'KG'
          }];
        }

        const countBadge = document.getElementById('viewItemsCountBadge');
        if (countBadge) countBadge.textContent = `${items.length} ${items.length === 1 ? 'Item' : 'Items'}`;

        const tbody = document.getElementById('viewItemsTableBody');
        const tfoot = document.getElementById('viewItemsTableFoot');
        tbody.innerHTML = '';

        let totalQty = 0;
        let lastUom = 'KG';

        items.forEach((it, idx) => {
          const q = parseFloat(it.received_qty || 0);
          totalQty += q;
          const u = it.uom || 'KG';
          lastUom = u;

          const tr = document.createElement('tr');
          tr.style.borderBottom = (idx < items.length - 1) ? '1px solid var(--border)' : 'none';
          tr.innerHTML = `
            <td style="padding: 10px 12px; text-align: center; color: var(--text-sub); font-weight: 600;">${idx + 1}</td>
            <td style="padding: 10px 12px;">
              <strong style="color: var(--text-main); font-size: 0.9rem;">${escapeHtml(it.rm_code)}</strong>
              ${it.rm_name ? `<div style="font-size: 0.8rem; color: var(--text-sub); margin-top: 2px;">${escapeHtml(it.rm_name)}</div>` : ''}
            </td>
            <td style="padding: 10px 12px; text-align: right; font-weight: 700; color: #065f46; font-size: 0.92rem;">
              ${q.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </td>
            <td style="padding: 10px 12px; text-align: center;">
              <span class="qty-badge" style="font-size: 0.78rem;">${escapeHtml(u)}</span>
            </td>
          `;
          tbody.appendChild(tr);
        });

        if (tfoot) {
          tfoot.innerHTML = `
            <tr>
              <td colspan="2" style="padding: 10px 12px; text-align: right; color: var(--text-main); font-weight: 700;">
                Total Received Qty:
              </td>
              <td style="padding: 10px 12px; text-align: right; font-weight: 800; color: #065f46; font-size: 0.95rem;">
                ${totalQty.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </td>
              <td style="padding: 10px 12px; text-align: center; font-weight: 700; color: var(--text-main);">
                ${items.length === 1 ? escapeHtml(lastUom) : 'TOTAL'}
              </td>
            </tr>
          `;
        }

        viewModal.classList.add('active');
      }

      function closeViewModal() {
        if (viewModal) viewModal.classList.remove('active');
      }

      if (closeViewModalBtn) closeViewModalBtn.addEventListener('click', closeViewModal);
      if (closeViewModalBtn2) closeViewModalBtn2.addEventListener('click', closeViewModal);
      if (viewModal) {
        viewModal.addEventListener('click', (e) => {
          if (e.target === viewModal) closeViewModal();
        });
      }

      // 11. Delete Modal Actions
      function closeDeleteModal() {
        deleteModal.classList.remove('active');
        itemToDeleteId = null;
        itemToDeleteInwardNo = null;
        itemToDeleteRow = null;
      }

      if (cancelDeleteBtn) cancelDeleteBtn.addEventListener('click', closeDeleteModal);
      deleteModal.addEventListener('click', (e) => {
        if (e.target === deleteModal) closeDeleteModal();
      });

      if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', async () => {
          if (!itemToDeleteId) return;

          confirmDeleteBtn.disabled = true;
          confirmDeleteBtn.textContent = 'Deleting...';

          try {
            const res = await fetch('api/rm_in.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ action: 'delete', id: itemToDeleteId, inward_no: itemToDeleteInwardNo })
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
              throw new Error(data.message || 'Delete operation failed.');
            }

            if (itemToDeleteRow) {
              itemToDeleteRow.style.opacity = '0';
              itemToDeleteRow.style.transform = 'scale(0.95)';
              setTimeout(() => {
                itemToDeleteRow.remove();
                refreshStats();
              }, 200);
            }

            showToast(data.message || 'RM Inward entry deleted successfully.', 'success');
            closeDeleteModal();

          } catch (err) {
            showToast(err.message || 'Failed to delete record.', 'error');
          } finally {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.textContent = 'Delete Inward';
          }
        });
      }

      // 11. Live Search Filter
      if (searchInput) {
        searchInput.addEventListener('input', () => {
          const query = searchInput.value.trim().toLowerCase();
          const rows = tableBody.querySelectorAll('tr[data-id]');

          rows.forEach(r => {
            const inwardNo = (r.dataset.inward_no || '').toLowerCase();
            const inwardDate = (formatDateDMY(r.dataset.inward_date) || '').toLowerCase();
            const vendor = (r.dataset.vendor_name || '').toLowerCase();
            const rmCode = (r.dataset.rm_code || '').toLowerCase();
            const rmName = (r.dataset.rm_name || '').toLowerCase();
            const invoiceNo = (r.dataset.invoice_no || '').toLowerCase();
            const invoiceDate = (formatDateDMY(r.dataset.invoice_date) || '').toLowerCase();

            const matches = inwardNo.includes(query) || 
                            inwardDate.includes(query) ||
                            vendor.includes(query) || 
                            rmCode.includes(query) || 
                            rmName.includes(query) || 
                            invoiceNo.includes(query) ||
                            invoiceDate.includes(query);

            r.style.display = matches ? '' : 'none';
          });

          reindexSerialNumbers();
        });
      }

      // Initial numbering
      reindexSerialNumbers();
    });
  </script>
</body>
</html>
