<?php
/**
 * WIP Management Portal - Part Master
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Fetch real data from MSSQL database
$pdo = getDBConnection();
$parts = [];
$childPartsAvailable = [];
$totalCount = 0;
$activeCount = 0;
$totalChildPartsCount = 0;

if ($pdo) {
    try {
        // Fetch all registered parts
        $stmt = $pdo->query("
            SELECT id, part_code, part_name, child_parts, 
                   ISNULL(processes, '[]') as processes, 
                   ISNULL(output_variants, '[]') as output_variants, 
                   ISNULL(gross_weight, '') as gross_weight, 
                   ISNULL(pdf_file, '') as pdf_file, 
                   ISNULL(excel_file, '') as excel_file, 
                   ISNULL(remarks, '') as remarks, 
                   status 
            FROM part_master 
            ORDER BY id DESC
        ");
        $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCount = count($parts);
        foreach ($parts as $p) {
            if (strcasecmp($p['status'] ?? '', 'active') === 0) {
                $activeCount++;
            }
            $cpArr = json_decode($p['child_parts'] ?? '[]', true) ?: [];
            $totalChildPartsCount += count($cpArr);
        }

        // Fetch active child parts to populate selection dropdowns
        $cpStmt = $pdo->query("SELECT id, part_code, part_name, grade_spec, uom FROM child_part_master WHERE status = 'Active' ORDER BY part_code ASC");
        $childPartsAvailable = $cpStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

$pageTitle = 'Part Master';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Part Master - WIP Management Portal</title>
  
  <!-- Modern Clean Google Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="css/dashboard.css">

  <style>
    /* Table specific & utility styles */
    .btn-view,
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
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: normal;
    }

    .btn-view:hover,
    .btn-edit:hover {
      background: var(--primary-light);
      border-color: var(--primary);
      color: var(--primary);
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
      max-width: 560px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.08);
      overflow: hidden;
      transform: scale(0.96) translateY(-8px);
      transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .modal-overlay.active .modal-card {
      transform: scale(1) translateY(0);
    }

    .modal-header {
      padding: 16px 20px;
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
      padding: 20px;
      max-height: 75vh;
      overflow-y: auto;
    }

    /* Simple 2-column form grid */
    .popup-form-grid {
      display: grid;
      grid-template-columns: 1fr 140px;
      gap: 14px;
    }

    @media (max-width: 520px) {
      .popup-form-grid {
        grid-template-columns: 1fr;
      }
    }

    .pt-form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .pt-form-group label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .pt-form-group .form-control {
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

    .pt-form-group .form-control:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    /* Simple Child Parts Section */
    .cp-section {
      margin-top: 16px;
      padding-top: 14px;
      border-top: 1px solid var(--border);
    }

    .cp-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }

    .cp-label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .btn-add-cp {
      background: none;
      border: none;
      color: var(--primary);
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      padding: 3px 6px;
      border-radius: 4px;
    }

    .btn-add-cp:hover {
      background: var(--primary-light);
    }

    .cp-rows {
      display: flex;
      flex-direction: column;
      gap: 8px;
      max-height: 190px;
      overflow-y: auto;
    }

    .cp-row {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .cp-row select {
      flex: 1;
      height: 38px;
      padding: 0 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 0.84rem;
      font-family: inherit;
      color: var(--text-main);
      background: #ffffff;
      outline: none;
      box-sizing: border-box;
    }

    .cp-row select:focus {
      border-color: var(--primary);
    }

    .cp-row input {
      width: 80px;
      height: 38px;
      padding: 0 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 0.84rem;
      font-family: inherit;
      color: var(--text-main);
      background: #ffffff;
      outline: none;
      text-align: center;
      box-sizing: border-box;
    }

    .cp-row input:focus {
      border-color: var(--primary);
    }

    .btn-remove-cp {
      width: 38px;
      height: 38px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 6px;
      color: #94a3b8;
      cursor: pointer;
      font-size: 1.2rem;
      line-height: 1;
      transition: all 0.15s ease;
      box-sizing: border-box;
    }

    .btn-remove-cp:hover {
      color: #ef4444;
      border-color: #fecaca;
      background: #fee2e2;
    }

    .cp-empty-notice {
      padding: 6px 0;
      font-size: 0.82rem;
      color: var(--text-sub);
    }

    /* Clean Child Part Chips in Table */
    .cp-chip-list {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
    }

    .cp-chip {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 7px;
      background: #f1f5f9;
      border-radius: 4px;
      font-size: 0.78rem;
      color: var(--text-main);
      white-space: nowrap;
    }

    .cp-chip .cp-code {
      font-weight: 600;
      color: var(--primary);
    }

    .cp-chip .cp-qty {
      color: var(--text-sub);
      font-size: 0.74rem;
    }

    .modal-footer {
      padding: 14px 20px;
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
      max-width: 400px !important;
      text-align: center;
      padding: 24px 20px;
    }

    .confirm-icon-box {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: #fee2e2;
      color: #ef4444;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 12px auto;
    }

    .confirm-icon-box svg {
      width: 24px;
      height: 24px;
    }

    .confirm-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 6px;
    }

    .confirm-desc {
      font-size: 0.86rem;
      color: var(--text-sub);
      line-height: 1.45;
      margin-bottom: 20px;
    }

    .confirm-actions {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
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

    /* ==========================================
       Right-Side Slide-Over Drawer for Part Details
       ========================================== */
    .view-drawer-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(2px);
      z-index: 1050;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.25s ease, visibility 0.25s ease;
    }

    .view-drawer-backdrop.active {
      opacity: 1;
      visibility: visible;
    }

    .view-drawer {
      position: fixed;
      top: 0;
      right: 0;
      bottom: 0;
      width: 660px;
      max-width: 94vw;
      height: 100vh;
      background: #ffffff;
      box-shadow: -8px 0 32px rgba(15, 23, 42, 0.18);
      z-index: 1060;
      display: flex;
      flex-direction: column;
      transform: translateX(100%);
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .view-drawer.active {
      transform: translateX(0);
    }

    .view-drawer-header {
      padding: 16px 22px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #ffffff;
      flex-shrink: 0;
    }

    .view-drawer-icon {
      width: 38px;
      height: 38px;
      border-radius: 8px;
      background: #eff6ff;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .view-drawer-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: #0f172a;
      margin: 0;
    }

    .view-drawer-subtitle {
      font-size: 0.78rem;
      color: #64748b;
      margin-top: 2px;
    }

    .view-drawer-close {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      background: transparent;
      border: 1px solid #e2e8f0;
      color: #64748b;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      line-height: 1;
      transition: all 0.15s ease;
    }

    .view-drawer-close:hover {
      background: #f1f5f9;
      color: #0f172a;
      border-color: #cbd5e1;
    }

    .view-drawer-body {
      flex: 1;
      overflow-y: auto;
      padding: 20px 22px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      background: #f8fafc;
    }

    .view-drawer-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 16px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    }

    .view-drawer-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #f1f5f9;
    }

    .view-drawer-card-header h4 {
      margin: 0;
      font-size: 0.86rem;
      font-weight: 700;
      color: #1e293b;
    }

    .view-badge-count {
      font-size: 0.72rem;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 9999px;
      background: #e2e8f0;
      color: #475569;
    }

    .view-detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px 16px;
    }

    .view-detail-item {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .view-detail-label {
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #64748b;
    }

    .view-detail-value {
      font-size: 0.88rem;
      color: #1e293b;
      font-weight: 600;
      word-break: break-word;
    }

    .view-detail-value.code-highlight {
      color: var(--primary);
      font-size: 1rem;
      font-weight: 700;
    }

    /* Files in Drawer */
    .view-files-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    @media (max-width: 500px) {
      .view-files-grid {
        grid-template-columns: 1fr;
      }
    }

    .view-file-box {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #ffffff;
      min-width: 0;
      overflow: hidden;
    }

    .file-icon-wrap {
      width: 34px;
      height: 34px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .file-icon-wrap.pdf {
      background: #fee2e2;
      color: #dc2626;
    }

    .file-icon-wrap.excel {
      background: #dcfce7;
      color: #16a34a;
    }

    .file-info-wrap {
      flex: 1;
      min-width: 0;
      overflow: hidden;
    }

    .file-info-wrap .file-title {
      font-size: 0.78rem;
      font-weight: 600;
      color: #1e293b;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      margin-bottom: 2px;
    }

    .file-info-wrap .file-action {
      font-size: 0.76rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .file-info-wrap .file-action a {
      color: var(--primary);
      text-decoration: none;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .file-info-wrap .file-action a:hover {
      text-decoration: underline;
    }

    /* Table in Drawer */
    .view-table-wrap {
      overflow-x: auto;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #ffffff;
    }

    .view-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.82rem;
      table-layout: fixed;
    }

    .view-table th {
      background: #f8fafc;
      padding: 9px 12px;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #475569;
      border-bottom: 1.5px solid #e2e8f0;
      white-space: nowrap;
    }

    .view-table td {
      padding: 10px 12px;
      border-bottom: 1px solid #f1f5f9;
      color: #334155;
      vertical-align: middle;
      word-break: break-word;
    }

    .view-table tr:last-child td {
      border-bottom: none;
    }

    .view-table tr:hover td {
      background: #f8fafc;
    }

    .uom-pill {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      background: #eff6ff;
      color: #1d4ed8;
      font-weight: 600;
      font-size: 0.74rem;
      border: 1px solid #bfdbfe;
      letter-spacing: 0.02em;
    }

    /* Process Flow Arrow Badges */
    .process-arrow-chips-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 12px 14px;
      align-items: center;
      padding: 6px 2px;
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
      cursor: default;
    }

    .process-arrow-chip:hover {
      background: #dfe5ec;
      border-color: #1d4ed8;
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(37, 99, 235, 0.12);
    }

    .process-arrow-name {
      display: flex;
      align-items: center;
      padding: 0 4px 0 14px;
      font-size: 0.88rem;
      font-weight: 600;
      color: #2563eb;
      white-space: nowrap;
      user-select: none;
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

    .process-arrow-empty {
      color: #94a3b8;
      font-size: 0.84rem;
      font-style: italic;
      padding: 8px 4px;
    }

    .view-drawer-footer {
      padding: 12px 22px;
      border-top: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: flex-end;
      background: #ffffff;
      flex-shrink: 0;
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
            <div class="stat-number" id="statTotalPt"><?php echo $totalCount; ?></div>
            <div class="stat-title">Total Parts</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statActivePt"><?php echo $activeCount; ?></div>
            <div class="stat-title">Active Parts</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statChildPartsPt"><?php echo $totalChildPartsCount; ?></div>
            <div class="stat-title">Mapped RM & Child Parts</div>
          </div>
        </div>

        <!-- ==========================================
             Simple Table Card
             ========================================== -->
        <div class="simple-card table-box">
          <div class="table-bar">
            <h2 class="box-title">Part Master Directory</h2>
            <div class="table-actions">
              <input type="text" id="ptSearch" class="simple-input" placeholder="Search Part Code, Name...">
              <?php if (hasPermission('part_master', 'create')): ?>
              <a href="part-add.php" class="btn-primary" id="openAddPtBtn" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">+ Add Part</a>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-wrap">
            <table class="simple-table" id="ptTable">
              <thead>
                <tr>
                  <th style="width: 50px;">Sr No.</th>
                  <th style="width: 140px;">Part Code</th>
                  <th>Part Name</th>
                  <th style="width: 90px;">Status</th>
                  <th style="width: 185px;">Action</th>
                </tr>
              </thead>
              <tbody id="ptTableBody">
                <?php if (!empty($parts)): ?>
                  <?php $sr = 1; ?>
                  <?php foreach ($parts as $item): ?>
                    <?php 
                      $isActive = (strcasecmp($item['status'] ?? 'Active', 'Active') === 0);
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>" 
                        data-part-code="<?php echo htmlspecialchars($item['part_code']); ?>"
                        data-part-name="<?php echo htmlspecialchars($item['part_name']); ?>"
                        data-status="<?php echo htmlspecialchars($item['status'] ?? 'Active'); ?>"
                        data-gross-weight="<?php echo htmlspecialchars($item['gross_weight'] ?? ''); ?>"
                        data-pdf-file="<?php echo htmlspecialchars($item['pdf_file'] ?? ''); ?>"
                        data-excel-file="<?php echo htmlspecialchars($item['excel_file'] ?? ''); ?>"
                        data-remarks="<?php echo htmlspecialchars($item['remarks'] ?? ''); ?>"
                        data-child-parts="<?php echo htmlspecialchars($item['child_parts'] ?? '[]'); ?>"
                        data-processes="<?php echo htmlspecialchars($item['processes'] ?? '[]'); ?>"
                        data-output-variants="<?php echo htmlspecialchars($item['output_variants'] ?? '[]'); ?>">
                      <td style="color: var(--text-sub); font-weight: 600;"><?php echo $sr++; ?></td>
                      <td><strong class="col-part-code"><?php echo htmlspecialchars($item['part_code']); ?></strong></td>
                      <td class="col-part-name">
                        <div style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($item['part_name']); ?></div>
                      </td>
                      <td class="col-status">
                        <?php if ($isActive): ?>
                          <span class="tag tag-completed">Active</span>
                        <?php else: ?>
                          <span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div style="display: flex; gap: 6px;">
                          <button type="button" class="btn-view" title="View Part Details">View</button>
                          <?php if (hasPermission('part_master', 'update')): ?>
                          <a href="part-add.php?id=<?php echo htmlspecialchars($item['id']); ?>" class="btn-edit" title="Edit Part" style="text-decoration:none; display:inline-flex; align-items:center;">Edit</a>
                          <?php endif; ?>
                          <?php if (hasPermission('part_master', 'delete')): ?>
                          <button type="button" class="btn-delete" title="Delete Part">Delete</button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr id="emptyTableRow">
                    <td colspan="5" class="empty-row-msg">No parts found. <a href="part-add.php" style="color: var(--primary); font-weight: 600; text-decoration: underline;">+ Add Part</a> to create one.</td>
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
       Add / Edit Part Popup Modal
       ========================================== -->
  <div id="ptPopupModal" class="modal-overlay">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title" id="modalFormTitle">+ Add Part</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="ptPopupForm">
        <input type="hidden" id="editItemId" value="">

        <div class="modal-body">
          <div class="popup-form-grid">
            
            <!-- 1. Part Code -->
            <div class="pt-form-group">
              <label for="inputPartCode">Part Code *</label>
              <input type="text" id="inputPartCode" class="form-control" placeholder="e.g., PT-1006" required>
            </div>

            <!-- 2. Status -->
            <div class="pt-form-group">
              <label for="inputStatus">Status *</label>
              <select id="inputStatus" class="form-control" required>
                <option value="Active" selected>Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>

            <!-- 3. Part Name -->
            <div class="pt-form-group" style="grid-column: 1 / -1;">
              <label for="inputPartName">Part Name *</label>
              <input type="text" id="inputPartName" class="form-control" placeholder="e.g., Rear Bumper Stiffener" required>
            </div>

          </div>

          <!-- Clean RM & Child Parts Section -->
          <div class="cp-section">
            <div class="cp-header">
              <label class="cp-label">RM & Child Parts</label>
              <button type="button" class="btn-add-cp" id="btnAddCpRow">+ Add RM & Child Part</button>
            </div>

            <div id="cpRowsContainer" class="cp-rows">
              <!-- Dynamic child part rows injected here -->
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="modal-footer">
          <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
          <button type="submit" class="btn-primary" id="savePtSubmitBtn">+ Save Part</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==========================================
       Right-Side Slide-Over Drawer for Part Details
       ========================================== -->
  <div id="viewDrawerBackdrop" class="view-drawer-backdrop"></div>
  <div id="viewPartDrawer" class="view-drawer" aria-label="Part Details Drawer">
    <div class="view-drawer-header">
      <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
        <div class="view-drawer-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; color: var(--primary);">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
          </svg>
        </div>
        <div style="min-width: 0;">
          <h3 class="view-drawer-title" id="viewPartTitle">Part Details</h3>
          <div class="view-drawer-subtitle" id="viewPartSubtitle">Complete specifications, child parts BOM, and process stages</div>
        </div>
      </div>
      <button type="button" class="view-drawer-close" id="closeViewDrawerBtn" aria-label="Close drawer">&times;</button>
    </div>

    <div class="view-drawer-body">
      
      <!-- 1. General Specifications Card -->
      <div class="view-drawer-card">
        <div class="view-drawer-card-header">
          <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 15px; height: 15px; color: var(--primary);">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="16" x2="12" y2="12"></line>
              <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            General Information
          </h4>
          <div id="viewPartStatus">-</div>
        </div>

        <div class="view-detail-grid">
          <div class="view-detail-item">
            <span class="view-detail-label">Part Code</span>
            <span class="view-detail-value code-highlight" id="viewPartCode">-</span>
          </div>

          <div class="view-detail-item">
            <span class="view-detail-label">Gross Weight</span>
            <span class="view-detail-value" id="viewPartWeight">-</span>
          </div>

          <div class="view-detail-item" style="grid-column: 1 / -1;">
            <span class="view-detail-label">Part Name</span>
            <span class="view-detail-value" id="viewPartName" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">-</span>
          </div>

          <div class="view-detail-item" style="grid-column: 1 / -1;">
            <span class="view-detail-label">Remarks / Notes</span>
            <span class="view-detail-value" id="viewPartRemarks" style="font-weight: 500; color: #475569; font-size: 0.84rem;">-</span>
          </div>
        </div>
      </div>

      <!-- 2. Attached Documents & Files -->
      <div class="view-drawer-card">
        <div class="view-drawer-card-header">
          <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 15px; height: 15px; color: var(--primary);">
              <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
            </svg>
            Attached Documents
          </h4>
        </div>

        <div class="view-files-grid">
          <div class="view-file-box" id="viewPdfBox">
            <div class="file-icon-wrap pdf">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px;">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
              </svg>
            </div>
            <div class="file-info-wrap">
              <div class="file-title">Data Sheet (PDF)</div>
              <div class="file-action" id="viewPdfAction">No file</div>
            </div>
          </div>

          <div class="view-file-box" id="viewExcelBox">
            <div class="file-icon-wrap excel">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 18px; height: 18px;">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
              </svg>
            </div>
            <div class="file-info-wrap">
              <div class="file-title">Description (Excel)</div>
              <div class="file-action" id="viewExcelAction">No file</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 3. Section 1: Child Parts BOM -->
      <div class="view-drawer-card">
        <div class="view-drawer-card-header">
          <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 15px; height: 15px; color: var(--primary);">
              <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
              <polyline points="2 17 12 22 22 17"></polyline>
              <polyline points="2 12 12 17 22 12"></polyline>
            </svg>
            Raw Material & Child Parts (BOM)
          </h4>
          <span class="view-badge-count" id="viewCpCount">0 items</span>
        </div>
        <div class="view-table-wrap">
          <table class="view-table">
            <thead>
              <tr>
                <th style="width: 45px; text-align: center;">#</th>
                <th style="width: 140px; text-align: left;">Child Part Code</th>
                <th style="text-align: left;">Child Part Name</th>
                <th style="width: 110px; text-align: center;">Quantity</th>
                <th style="width: 80px; text-align: center;">UOM</th>
              </tr>
            </thead>
            <tbody id="viewCpTableBody">
              <!-- Populated via JS -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- 4. Section 2: Manufacturing Processes -->
      <div class="view-drawer-card">
        <div class="view-drawer-card-header">
          <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 15px; height: 15px; color: var(--primary);">
              <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
            </svg>
            Required Manufacturing Processes
          </h4>
          <span class="view-badge-count" id="viewPrcCount">0 processes</span>
        </div>
        <div id="viewPrcFlowContainer" class="process-arrow-chips-wrap">
          <!-- Populated as process arrow badges via JS -->
        </div>
      </div>

      <!-- 5. Section 3: Output Variants -->
      <div class="view-drawer-card">
        <div class="view-drawer-card-header">
          <h4>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 15px; height: 15px; color: var(--primary);">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
            </svg>
            Output Variants (Finished Goods)
          </h4>
          <span class="view-badge-count" id="viewVarCount">0 variants</span>
        </div>
        <div class="view-table-wrap">
          <table class="view-table">
            <thead>
              <tr>
                <th style="width: 45px; text-align: center;">#</th>
                <th style="width: 140px; text-align: left;">Output Part Code</th>
                <th style="text-align: left;">Output Part Name</th>
                <th style="width: 110px; text-align: center;">Qty / Ratio</th>
                <th style="width: 80px; text-align: center;">UOM</th>
              </tr>
            </thead>
            <tbody id="viewVarTableBody">
              <!-- Populated via JS -->
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <div class="view-drawer-footer">
      <button type="button" class="btn-secondary" id="closeViewDrawerFooterBtn">Close</button>
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
      <p class="confirm-desc">Are you sure you want to delete part <strong id="deleteTargetCode" style="color:var(--text-main);"></strong>? This part will be removed from the database.</p>
      <div class="confirm-actions">
        <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-danger-confirm" id="confirmDeleteBtn">Yes, Delete</button>
      </div>
    </div>
  </div>

  <!-- Client-side Interactive Logic & Database API Integration -->
  <script>
    // Available child parts from database
    const availableChildParts = <?php echo json_encode($childPartsAvailable, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    document.addEventListener('DOMContentLoaded', () => {
      // DOM Elements
      const ptTableBody = document.getElementById('ptTableBody');
      const ptSearch = document.getElementById('ptSearch');
      const ptPopupModal = document.getElementById('ptPopupModal');
      const ptPopupForm = document.getElementById('ptPopupForm');
      const openAddPtModalBtn = document.getElementById('openAddPtModalBtn');
      const closeModalBtn = document.getElementById('closeModalBtn');
      const cancelModalBtn = document.getElementById('cancelModalBtn');
      const modalFormTitle = document.getElementById('modalFormTitle');
      const savePtSubmitBtn = document.getElementById('savePtSubmitBtn');
      const editItemId = document.getElementById('editItemId');
      const toastContainer = document.getElementById('toastContainer');

      // Child Parts Elements
      const cpRowsContainer = document.getElementById('cpRowsContainer');
      const btnAddCpRow = document.getElementById('btnAddCpRow');

      // View Drawer Elements (Right-Side Slider)
      const viewDrawerBackdrop = document.getElementById('viewDrawerBackdrop');
      const viewPartDrawer = document.getElementById('viewPartDrawer');
      const closeViewDrawerBtn = document.getElementById('closeViewDrawerBtn');
      const closeViewDrawerFooterBtn = document.getElementById('closeViewDrawerFooterBtn');
      const viewPartTitle = document.getElementById('viewPartTitle');
      const viewPartSubtitle = document.getElementById('viewPartSubtitle');
      const viewPartCode = document.getElementById('viewPartCode');
      const viewPartName = document.getElementById('viewPartName');
      const viewPartStatus = document.getElementById('viewPartStatus');
      const viewPartWeight = document.getElementById('viewPartWeight');
      const viewPartRemarks = document.getElementById('viewPartRemarks');
      const viewPdfAction = document.getElementById('viewPdfAction');
      const viewExcelAction = document.getElementById('viewExcelAction');
      const viewCpCount = document.getElementById('viewCpCount');
      const viewCpTableBody = document.getElementById('viewCpTableBody');
      const viewPrcCount = document.getElementById('viewPrcCount');
      const viewPrcFlowContainer = document.getElementById('viewPrcFlowContainer');
      const viewVarCount = document.getElementById('viewVarCount');
      const viewVarTableBody = document.getElementById('viewVarTableBody');

      // Delete Modal Elements
      const deleteConfirmModal = document.getElementById('deleteConfirmModal');
      const deleteTargetCode = document.getElementById('deleteTargetCode');
      const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

      let pendingDeleteId = null;
      let pendingDeleteRow = null;
      let pendingDeleteCode = null;

      // Stats Elements
      const statTotalPt = document.getElementById('statTotalPt');
      const statActivePt = document.getElementById('statActivePt');
      const statChildPartsPt = document.getElementById('statChildPartsPt');

      // Toast Notification Function
      const showToast = (message, type = 'info') => {
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        let iconSvg = '';
        if (type === 'success') {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
        } else if (type === 'error') {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
        } else {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`;
        }

        toast.innerHTML = `
          <span class="toast-icon">${iconSvg}</span>
          <span class="toast-message">${message}</span>
        `;

        toastContainer.appendChild(toast);

        requestAnimationFrame(() => {
          toast.classList.add('show');
        });

        setTimeout(() => {
          toast.classList.remove('show');
          setTimeout(() => {
            if (toast.parentElement) toast.remove();
          }, 280);
        }, 4000);
      };

      // Update Sr No. across all visible rows
      function reindexSrNo() {
        const rows = Array.from(ptTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        rows.forEach((r, idx) => {
          if (r.children[0]) {
            r.children[0].textContent = idx + 1;
          }
        });
      }

      // Update Statistics Counters
      function updateCounters() {
        const rows = Array.from(ptTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        let total = rows.length;
        let active = 0;
        let totalChildParts = 0;

        rows.forEach(r => {
          const statusCell = r.querySelector('.col-status');
          if (statusCell && statusCell.textContent.toLowerCase().includes('active')) {
            active++;
          }
          const cpDataRaw = r.getAttribute('data-child-parts');
          if (cpDataRaw) {
            try {
              const cpArr = JSON.parse(cpDataRaw);
              if (Array.isArray(cpArr)) {
                totalChildParts += cpArr.length;
              }
            } catch (err) {}
          }
        });

        if (statTotalPt) statTotalPt.textContent = total;
        if (statActivePt) statActivePt.textContent = active;
        if (statChildPartsPt) statChildPartsPt.textContent = totalChildParts;

        const emptyRow = document.getElementById('emptyTableRow');
        if (total === 0) {
          if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.id = 'emptyTableRow';
            tr.innerHTML = `<td colspan="6" class="empty-row-msg">No parts found. <a href="part-add.php" style="color: var(--primary); font-weight: 600; text-decoration: underline;">+ Add Part</a> to create one.</td>`;
            ptTableBody.appendChild(tr);
          }
        } else if (emptyRow) {
          emptyRow.remove();
        }
      }

      // Helper function to escape HTML
      function escapeHtml(text) {
        if (!text) return '';
        return String(text)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      // Build Child Part Row Element
      function createChildPartRow(selectedCode = '', qty = 1) {
        const row = document.createElement('div');
        row.className = 'cp-row';

        let optionsHtml = '<option value="">-- Select RM & Child Part --</option>';
        availableChildParts.forEach(cp => {
          const isSelected = (cp.part_code === selectedCode) ? 'selected' : '';
          const label = `${cp.part_code} - ${cp.part_name}` + (cp.uom ? ` (${cp.uom})` : '');
          optionsHtml += `<option value="${escapeHtml(cp.part_code)}" data-name="${escapeHtml(cp.part_name)}" ${isSelected}>${escapeHtml(label)}</option>`;
        });

        // If selectedCode not in list
        if (selectedCode && !availableChildParts.some(cp => cp.part_code === selectedCode)) {
          optionsHtml += `<option value="${escapeHtml(selectedCode)}" data-name="" selected>${escapeHtml(selectedCode)}</option>`;
        }

        row.innerHTML = `
          <select class="cp-select" required>
            ${optionsHtml}
          </select>
          <input type="number" step="0.001" min="0.001" class="cp-qty-input" placeholder="Qty" value="${qty}" title="Qty per Part" required>
          <button type="button" class="btn-remove-cp" title="Remove">&times;</button>
        `;

        // Remove row handler
        row.querySelector('.btn-remove-cp').addEventListener('click', () => {
          row.remove();
          if (cpRowsContainer.children.length === 0) {
            showEmptyNotice();
          }
        });

        return row;
      }

      function showEmptyNotice() {
        cpRowsContainer.innerHTML = `<div class="cp-empty-notice" id="cpEmptyNotice">No RM & child parts added. Click "+ Add RM & Child Part" to attach one.</div>`;
      }

      function addCpRow(selectedCode = '', qty = 1) {
        const emptyNotice = document.getElementById('cpEmptyNotice');
        if (emptyNotice) emptyNotice.remove();

        const row = createChildPartRow(selectedCode, qty);
        cpRowsContainer.appendChild(row);
      }

      // Add Row Button
      if (btnAddCpRow) {
        btnAddCpRow.addEventListener('click', () => {
          addCpRow('', 1);
        });
      }

      // Helper to generate Child Part chips HTML
      function generateCpChipsHtml(cpList) {
        if (!Array.isArray(cpList) || cpList.length === 0) {
          return '<span style="color: var(--text-sub); font-size: 0.82rem;">-</span>';
        }

        let html = '<div class="cp-chip-list">';
        cpList.forEach(cp => {
          const code = escapeHtml(cp.code || '');
          const name = escapeHtml(cp.name || '');
          const qty = escapeHtml(cp.qty || 1);
          html += `
            <span class="cp-chip" title="${name}">
              <span class="cp-code">${code}</span>
              <span class="cp-qty">(x${qty})</span>
            </span>
          `;
        });
        html += '</div>';
        return html;
      }

      // Open Form Modal
      function openModal(title = '+ Add Part', buttonText = '+ Save Part') {
        modalFormTitle.textContent = title;
        savePtSubmitBtn.textContent = buttonText;
        ptPopupModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
          document.getElementById('inputPartCode').focus();
        }, 100);
      }

      // Close Form Modal
      function closeModal() {
        ptPopupModal.classList.remove('active');
        document.body.style.overflow = '';
        ptPopupForm.reset();
        editItemId.value = '';
        document.getElementById('inputStatus').value = 'Active';
        cpRowsContainer.innerHTML = '';
      }

      // Open Add Page (Direct navigation instead of dialog)
      const openAddPtBtn = document.getElementById('openAddPtBtn') || document.getElementById('openAddPtModalBtn');
      if (openAddPtBtn && openAddPtBtn.tagName !== 'A') {
        openAddPtBtn.addEventListener('click', () => {
          window.location.href = 'part-add.php';
        });
      }

      if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
      if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

      // Close when clicking outside modal
      if (ptPopupModal) {
        ptPopupModal.addEventListener('click', (e) => {
          if (e.target === ptPopupModal) closeModal();
        });
      }

      // View Drawer (Right Slider) Handlers
      function closeViewDrawer() {
        if (viewPartDrawer) {
          viewPartDrawer.classList.remove('active');
        }
        if (viewDrawerBackdrop) {
          viewDrawerBackdrop.classList.remove('active');
        }
        document.body.style.overflow = '';
      }

      if (closeViewDrawerBtn) closeViewDrawerBtn.addEventListener('click', closeViewDrawer);
      if (closeViewDrawerFooterBtn) closeViewDrawerFooterBtn.addEventListener('click', closeViewDrawer);
      if (viewDrawerBackdrop) {
        viewDrawerBackdrop.addEventListener('click', closeViewDrawer);
      }

      function openViewDrawerForRow(row) {
        if (!row || !viewPartDrawer) return;

        const partId = row.getAttribute('data-id') || '';
        const partCode = row.getAttribute('data-part-code') || (row.querySelector('.col-part-code') ? row.querySelector('.col-part-code').textContent.trim() : '');
        const partName = row.getAttribute('data-part-name') || (row.querySelector('.col-part-name') ? row.querySelector('.col-part-name').childNodes[0].textContent.trim() : '');
        const status = row.getAttribute('data-status') || 'Active';
        const weight = row.getAttribute('data-gross-weight') || '';
        const pdfFile = row.getAttribute('data-pdf-file') || '';
        const excelFile = row.getAttribute('data-excel-file') || '';
        const remarks = row.getAttribute('data-remarks') || '';

        let childParts = [];
        try {
          const rawCp = row.getAttribute('data-child-parts');
          if (rawCp) childParts = JSON.parse(rawCp);
        } catch (e) {
          childParts = [];
        }

        let processes = [];
        try {
          const rawPrc = row.getAttribute('data-processes');
          if (rawPrc) processes = JSON.parse(rawPrc);
        } catch (e) {
          processes = [];
        }

        let variants = [];
        try {
          const rawVar = row.getAttribute('data-output-variants');
          if (rawVar) variants = JSON.parse(rawVar);
        } catch (e) {
          variants = [];
        }

        // Header
        if (viewPartTitle) viewPartTitle.textContent = partCode ? `Part: ${partCode}` : 'Part Details';
        if (viewPartSubtitle) viewPartSubtitle.textContent = partName ? partName : 'Complete specifications and mapped structures';
        
        // General info
        if (viewPartCode) viewPartCode.textContent = partCode || '-';
        if (viewPartName) viewPartName.textContent = partName || '-';

        // Status badge
        if (viewPartStatus) {
          const isActive = (String(status).toLowerCase() === 'active');
          viewPartStatus.innerHTML = isActive 
            ? '<span class="tag tag-completed">Active</span>' 
            : '<span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>';
        }

        // Weight
        if (viewPartWeight) {
          viewPartWeight.textContent = weight ? `${weight} kg` : '-';
        }

        // Remarks
        if (viewPartRemarks) {
          viewPartRemarks.textContent = remarks ? remarks : 'No special remarks recorded.';
        }

        // PDF Action
        if (viewPdfAction) {
          if (pdfFile) {
            let pdfUrl = String(pdfFile).trim().replace(/^\/+/, '');
            if (!pdfUrl.startsWith('uploads/')) {
              pdfUrl = 'uploads/parts/' + pdfUrl;
            }
            viewPdfAction.innerHTML = `
              <a href="${escapeHtml(pdfUrl)}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(pdfFile)}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 13px; height: 13px; flex-shrink: 0;">
                  <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                  <polyline points="15 3 21 3 21 9"></polyline>
                  <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg>
                <span>View PDF Document</span>
              </a>
            `;
          } else {
            viewPdfAction.innerHTML = `<span style="color: #94a3b8; font-style: italic;">No PDF attached</span>`;
          }
        }

        // Excel Action
        if (viewExcelAction) {
          if (excelFile) {
            let excelUrl = String(excelFile).trim().replace(/^\/+/, '');
            if (!excelUrl.startsWith('uploads/')) {
              excelUrl = 'uploads/parts/' + excelUrl;
            }
            viewExcelAction.innerHTML = `
              <a href="${escapeHtml(excelUrl)}" target="_blank" rel="noopener noreferrer" download title="${escapeHtml(excelFile)}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 13px; height: 13px; flex-shrink: 0;">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                  <polyline points="7 10 12 15 17 10"></polyline>
                  <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                <span>Download Excel File</span>
              </a>
            `;
          } else {
            viewExcelAction.innerHTML = `<span style="color: #94a3b8; font-style: italic;">No Excel attached</span>`;
          }
        }

        // Child Parts BOM
        if (viewCpCount) viewCpCount.textContent = `${childParts.length} ${childParts.length === 1 ? 'item' : 'items'}`;
        if (viewCpTableBody) {
          if (!Array.isArray(childParts) || childParts.length === 0) {
            viewCpTableBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:14px; font-style:italic;">No RM & Child Parts attached.</td></tr>`;
          } else {
            let cpRowsHtml = '';
            childParts.forEach((cp, idx) => {
              const code = escapeHtml(cp.code || '');
              const name = escapeHtml(cp.name || '-');
              const qty = escapeHtml(cp.qty || 1);
              const uom = escapeHtml(cp.uom || 'NOS');
              cpRowsHtml += `
                <tr>
                  <td style="text-align: center; font-weight: 600; color: #64748b;">${idx + 1}</td>
                  <td><strong style="color: var(--primary); font-size: 0.88rem;">${code}</strong></td>
                  <td style="font-weight: 500; color: #1e293b;">${name}</td>
                  <td style="text-align: center; font-weight: 700; color: #0f172a;">${qty}</td>
                  <td style="text-align: center;"><span class="uom-pill">${uom}</span></td>
                </tr>
              `;
            });
            viewCpTableBody.innerHTML = cpRowsHtml;
          }
        }

        // Manufacturing Processes (Chevron Arrow Step Flow as requested)
        if (viewPrcCount) viewPrcCount.textContent = `${processes.length} ${processes.length === 1 ? 'process' : 'processes'}`;
        if (viewPrcFlowContainer) {
          if (!Array.isArray(processes) || processes.length === 0) {
            viewPrcFlowContainer.innerHTML = `<div class="process-arrow-empty">No manufacturing processes configured.</div>`;
          } else {
            let prcHtml = '';
            processes.forEach((prc) => {
              const name = escapeHtml(prc.name || prc.code || 'Process');
              const remarks = prc.remarks ? `Stage Details / Remarks: ${escapeHtml(prc.remarks)}` : `Process: ${escapeHtml(name)}`;
              prcHtml += `
                <div class="process-arrow-chip" title="${remarks}">
                  <span class="process-arrow-name">${name}</span>
                  <svg class="process-arrow-svg" viewBox="0 0 14 36" preserveAspectRatio="none">
                    <polygon points="0,0 14,18 0,36" fill="#eaedf2" class="arrow-fill" />
                    <polyline points="0,0 14,18 0,36" fill="none" stroke="#2563eb" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="arrow-stroke" />
                  </svg>
                </div>
              `;
            });
            viewPrcFlowContainer.innerHTML = prcHtml;
          }
        }

        // Output Variants
        if (viewVarCount) viewVarCount.textContent = `${variants.length} ${variants.length === 1 ? 'variant' : 'variants'}`;
        if (viewVarTableBody) {
          if (!Array.isArray(variants) || variants.length === 0) {
            viewVarTableBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:14px; font-style:italic;">No Output Variants defined.</td></tr>`;
          } else {
            let varRowsHtml = '';
            variants.forEach((ov, idx) => {
              const code = escapeHtml(ov.code || '');
              const name = escapeHtml(ov.name || '-');
              const qty = escapeHtml(ov.qty || 1);
              const uom = escapeHtml(ov.uom || 'NOS');
              varRowsHtml += `
                <tr>
                  <td style="text-align: center; font-weight: 600; color: #64748b;">${idx + 1}</td>
                  <td><strong style="color: var(--primary); font-size: 0.88rem;">${code}</strong></td>
                  <td style="font-weight: 500; color: #1e293b;">${name}</td>
                  <td style="text-align: center; font-weight: 700; color: #0f172a;">${qty}</td>
                  <td style="text-align: center;"><span class="uom-pill">${uom}</span></td>
                </tr>
              `;
            });
            viewVarTableBody.innerHTML = varRowsHtml;
          }
        }

        // Open Drawer
        if (viewDrawerBackdrop) viewDrawerBackdrop.classList.add('active');
        viewPartDrawer.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      // Delete Confirm Modal Handlers
      function closeDeleteConfirmModal() {
        if (deleteConfirmModal) {
          deleteConfirmModal.classList.remove('active');
          document.body.style.overflow = '';
        }
        pendingDeleteId = null;
        pendingDeleteRow = null;
        pendingDeleteCode = null;
      }

      if (cancelDeleteBtn) cancelDeleteBtn.addEventListener('click', closeDeleteConfirmModal);

      if (deleteConfirmModal) {
        deleteConfirmModal.addEventListener('click', (e) => {
          if (e.target === deleteConfirmModal) closeDeleteConfirmModal();
        });
      }

      // Escape key to close modals and drawer
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          if (viewPartDrawer && viewPartDrawer.classList.contains('active')) closeViewDrawer();
          if (ptPopupModal && ptPopupModal.classList.contains('active')) closeModal();
          if (deleteConfirmModal && deleteConfirmModal.classList.contains('active')) closeDeleteConfirmModal();
        }
      });

      // Real-time table search filter
      if (ptSearch && ptTableBody) {
        ptSearch.addEventListener('input', () => {
          const q = ptSearch.value.toLowerCase().trim();
          const rows = ptTableBody.querySelectorAll('tr');
          rows.forEach(row => {
            if (row.id === 'emptyTableRow') return;
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
          });
        });
      }

      // Form Submission (Add / Edit) with Database API
      if (ptPopupForm) {
        ptPopupForm.addEventListener('submit', async (e) => {
          e.preventDefault();

          const id = editItemId.value ? parseInt(editItemId.value, 10) : 0;
          const code = document.getElementById('inputPartCode').value.trim();
          const name = document.getElementById('inputPartName').value.trim();
          const status = document.getElementById('inputStatus').value || 'Active';

          if (!code || !name) {
            showToast('Please fill in Part Code and Part Name.', 'error');
            return;
          }

          // Collect Child Parts
          const childParts = [];
          const rows = cpRowsContainer.querySelectorAll('.cp-row');
          rows.forEach(r => {
            const sel = r.querySelector('.cp-select');
            const qtyInput = r.querySelector('.cp-qty-input');
            if (sel && sel.value) {
              const opt = sel.options[sel.selectedIndex];
              childParts.push({
                code: sel.value,
                name: opt.getAttribute('data-name') || '',
                qty: parseFloat(qtyInput.value) || 1
              });
            }
          });

          // Disable submit button during request
          savePtSubmitBtn.disabled = true;
          const origBtnText = savePtSubmitBtn.textContent;
          savePtSubmitBtn.textContent = 'Saving...';

          try {
            const payload = {
              action: id > 0 ? 'update' : 'create',
              id: id,
              part_code: code,
              part_name: name,
              status: status,
              child_parts: childParts
            };

            const resp = await fetch('api/part_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload)
            });

            const res = await resp.json();

            if (!res.success) {
              showToast(res.message || 'Error occurred while saving.', 'error');
              return;
            }

            const activeTag = status === 'Active' 
              ? '<span class="tag tag-completed">Active</span>' 
              : '<span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>';

            const chipsHtml = generateCpChipsHtml(childParts);
            const childPartsJsonStr = JSON.stringify(childParts);

            const actionHtml = `
              <div style="display: flex; gap: 6px;">
                <button type="button" class="btn-view" title="View Part Details">View</button>
                <button type="button" class="btn-edit" title="Edit Part">Edit</button>
                <button type="button" class="btn-delete" title="Delete Part">Delete</button>
              </div>
            `;

            if (id > 0) {
              // Update existing table row
              const targetRow = ptTableBody.querySelector(`tr[data-id="${id}"]`);
              if (targetRow) {
                targetRow.setAttribute('data-child-parts', childPartsJsonStr);
                targetRow.querySelector('.col-part-code').textContent = code;
                targetRow.querySelector('.col-part-name').textContent = name;
                if (targetRow.querySelector('.col-child-parts')) {
                  targetRow.querySelector('.col-child-parts').innerHTML = chipsHtml;
                }
                targetRow.querySelector('.col-status').innerHTML = activeTag;
              }
              showToast(`Part "${code}" updated successfully.`, 'success');
            } else {
              // Create new table row
              const emptyRow = document.getElementById('emptyTableRow');
              if (emptyRow) emptyRow.remove();

              const newId = res.data && res.data.id ? res.data.id : Date.now();
              const tr = document.createElement('tr');
              tr.setAttribute('data-id', newId);
              tr.setAttribute('data-child-parts', childPartsJsonStr);
              tr.innerHTML = `
                <td style="color: var(--text-sub); font-weight: 600;">1</td>
                <td><strong class="col-part-code">${escapeHtml(code)}</strong></td>
                <td class="col-part-name">${escapeHtml(name)}</td>
                <td class="col-status">${activeTag}</td>
                <td>${actionHtml}</td>
              `;
              ptTableBody.prepend(tr);
              reindexSrNo();
              showToast(`New Part "${code}" created successfully.`, 'success');
            }

            closeModal();
            updateCounters();
          } catch (err) {
            console.error(err);
            showToast('Network or server error while saving part.', 'error');
          } finally {
            savePtSubmitBtn.disabled = false;
            savePtSubmitBtn.textContent = origBtnText;
          }
        });
      }

      // Handle View, Edit and Delete click on Table rows
      if (ptTableBody) {
        ptTableBody.addEventListener('click', (e) => {
          const row = e.target.closest('tr');
          if (!row || row.id === 'emptyTableRow') return;

          const itemId = row.getAttribute('data-id');

          // View Action - Open Part Details Right Drawer
          if (e.target.closest('.btn-view')) {
            openViewDrawerForRow(row);
            return;
          }

          // Edit Action - Redirect to dedicated page for updation
          if (e.target.closest('.btn-edit')) {
            window.location.href = `part-add.php?id=${itemId}`;
            return;
          }

          // Delete Action
          if (e.target.closest('.btn-delete')) {
            const codeEl = row.querySelector('.col-part-code');
            const code = codeEl ? codeEl.textContent.trim() : 'Part';
            pendingDeleteId = itemId;
            pendingDeleteRow = row;
            pendingDeleteCode = code;

            if (deleteTargetCode) deleteTargetCode.textContent = code;
            if (deleteConfirmModal) {
              deleteConfirmModal.classList.add('active');
              document.body.style.overflow = 'hidden';
            }
          }
        });
      }

      // Confirm Delete Action with Database API
      if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', async () => {
          if (!pendingDeleteRow) return;

          confirmDeleteBtn.disabled = true;
          confirmDeleteBtn.textContent = 'Deleting...';

          try {
            const resp = await fetch('api/part_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: 'delete',
                id: pendingDeleteId,
                part_code: pendingDeleteCode
              })
            });

            const res = await resp.json();

            if (!res.success) {
              showToast(res.message || 'Failed to delete part.', 'error');
              return;
            }

            const code = pendingDeleteCode || 'Part';
            pendingDeleteRow.remove();
            closeDeleteConfirmModal();
            reindexSrNo();
            updateCounters();
            showToast(`Part "${code}" deleted successfully.`, 'success');
          } catch (err) {
            console.error(err);
            showToast('Error deleting part from server.', 'error');
          } finally {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.textContent = 'Yes, Delete';
          }
        });
      }

    });
  </script>
</body>
</html>
