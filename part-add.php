<?php
/**
 * WIP Management Portal - Add / Edit Part (Dedicated Page)
 * Simple and clean design aligned with Production Log Entry (DPR).
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/rbac.php';

$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEditMode = ($editId > 0);

if ($isEditMode) {
    requirePermission('part_master', 'update');
    $pageTitle = 'Edit Part';
} else {
    requirePermission('part_master', 'create');
    $pageTitle = 'Add New Part';
}

$activeMenu = 'part-master.php';
$headerBackUrl = 'part-master.php';
$headerBackText = 'Back to Part Master';

$pdo = getDBConnection();
$childPartsAvailable = [];
$processesAvailable = [];
$existingPart = null;

if ($pdo) {
    try {
        if ($isEditMode) {
            $stmt = $pdo->prepare("
                SELECT id, part_code, part_name, gross_weight, pdf_file, excel_file, remarks,
                       child_parts, processes, output_variants, fg_variant_type, status
                FROM part_master
                WHERE id = ?
            ");
            $stmt->execute([$editId]);
            $existingPart = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingPart) {
                header("Location: part-master.php");
                exit;
            }
        }

        // Fetch active child parts to populate Bill of Materials (BOM) selection dropdowns
        $cpStmt = $pdo->query("SELECT id, part_code, part_name, grade_spec, uom FROM child_part_master WHERE status = 'Active' ORDER BY part_code ASC");
        if ($cpStmt) {
            $childPartsAvailable = $cpStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch active processes with remarks/description to populate Required Processes selection dropdowns
        $prcStmt = $pdo->query("SELECT id, process_code, process_name, ISNULL(remarks, '') as remarks FROM process_master WHERE status = 'Active' ORDER BY process_name ASC");
        if ($prcStmt) {
            $processesAvailable = $prcStmt->fetchAll(PDO::FETCH_ASSOC);
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
  <title><?php echo $isEditMode ? 'Edit Part' : 'Add New Part'; ?> - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
    /* Sticky Topbar Header (aligned with DPR) */
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

    /* Main Form Card (DPR Style) */
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

    /* Section Subheadings (DPR Style) */
    .sub-section-title {
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding-bottom: 6px;
      border-bottom: 1.5px solid #e2e8f0;
      margin-top: 24px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* Form Grids */
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

    /* Hide number input up/down spin buttons */
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type=number] {
      -moz-appearance: textfield;
      appearance: textfield;
    }

    /* Input with suffix (kg) */
    .input-with-suffix {
      display: flex;
      align-items: center;
      border: 1px solid var(--border);
      border-radius: 6px;
      background: #ffffff;
      overflow: hidden;
      height: 40px;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
      box-sizing: border-box;
    }
    .input-with-suffix:focus-within {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .input-with-suffix input {
      flex: 1;
      height: 100%;
      border: none !important;
      outline: none !important;
      padding: 0 12px;
      font-family: inherit;
      font-size: 0.88rem;
      color: var(--text-main);
      background: transparent;
      box-shadow: none !important;
      box-sizing: border-box;
    }
    .input-suffix {
      background: #f1f5f9;
      color: #475569;
      font-size: 0.82rem;
      font-weight: 700;
      padding: 0 14px;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      border-left: 1px solid var(--border);
      user-select: none;
    }

    /* Upload Dropzone Box */
    .upload-dropzone {
      display: flex;
      align-items: center;
      gap: 12px;
      height: 40px;
      border: 1.5px dashed #cbd5e1;
      background: #f8fafc;
      border-radius: 6px;
      padding: 0 14px;
      cursor: pointer;
      transition: all 0.18s ease;
      box-sizing: border-box;
      user-select: none;
    }
    .upload-dropzone:hover {
      background: #eff6ff;
      border-color: #3b82f6;
    }
    .upload-dropzone.has-file {
      border-color: #10b981;
      background: #f0fdf4;
    }
    .upload-icon {
      width: 20px;
      height: 20px;
      color: #2563eb;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .upload-dropzone.has-file .upload-icon {
      color: #10b981;
    }
    .upload-text-wrap {
      display: flex;
      flex-direction: column;
      line-height: 1.25;
      overflow: hidden;
      text-align: left;
      flex: 1;
      min-width: 0;
    }
    .upload-title {
      font-size: 0.82rem;
      font-weight: 600;
      color: #334155;
      white-space: nowrap;
      text-overflow: ellipsis;
      overflow: hidden;
    }
    .upload-subtitle {
      font-size: 0.70rem;
      color: #64748b;
    }

    /* Simple Table inside Rounded Container (DPR Style) */
    .table-container-wrap {
      overflow-x: auto;
      border: 1px solid var(--border);
      border-radius: 8px;
      margin-bottom: 24px;
    }
    .table-section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
    }
    .table-section-desc {
      font-size: 0.80rem;
      color: var(--text-sub);
    }
    .count-badge {
      font-size: 0.72rem;
      padding: 2px 8px;
      border-radius: 10px;
      background: #e0f2fe;
      color: #0369a1;
      font-weight: 600;
      border: 1px solid #bae6fd;
      white-space: nowrap;
    }

    .simple-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.84rem;
      margin: 0;
    }
    .simple-table th {
      padding: 9px 12px;
      font-size: 0.76rem;
      font-weight: 700;
      color: var(--text-sub);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      border-bottom: 1px solid var(--border);
      background: #f8fafc;
      text-align: left;
    }
    .simple-table td {
      padding: 8px 12px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
      font-size: 0.86rem;
      color: var(--text-main);
    }
    .simple-table tbody tr:last-child td {
      border-bottom: none;
    }
    .simple-table tbody tr:hover td {
      background: #fafcff;
    }

    .cell-select, .cell-input {
      width: 100%;
      height: 38px;
      padding: 0 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: inherit;
      font-size: 0.86rem;
      outline: none;
      background: #ffffff;
      color: var(--text-main);
      box-sizing: border-box;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .cell-select:focus, .cell-input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .uom-badge {
      display: inline-block;
      font-weight: 700;
      color: #0284c7;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 4px;
      font-size: 0.76rem;
      padding: 3px 8px;
      letter-spacing: 0.02em;
    }

    /* Action Buttons in Rows */
    .action-btn-group {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .btn-circle-action {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #ffffff;
      border: 1.5px solid #64748b;
      color: #64748b;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 16px;
      font-weight: 700;
      line-height: 1;
      padding: 0;
      transition: all 0.15s ease;
      flex-shrink: 0;
    }
    .btn-circle-action svg {
      width: 13px;
      height: 13px;
    }
    .btn-circle-add {
      border-color: var(--primary);
      color: var(--primary);
    }
    .btn-circle-add:hover {
      background: #eff6ff;
      border-color: #1d4ed8;
      color: #1d4ed8;
      transform: scale(1.08);
    }
    .btn-circle-minus {
      border-color: #64748b;
      color: #64748b;
    }
    .btn-circle-minus:hover {
      border-color: #ef4444;
      color: #ef4444;
      background: #fee2e2;
      transform: scale(1.08);
    }

    /* Action Buttons in Footer (DPR Style) */
    .form-card-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 28px;
      padding-top: 20px;
      border-top: 1px solid var(--border);
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

    /* Validation error highlight */
    .is-invalid {
      border-color: #ef4444 !important;
      background-color: #fffafb !important;
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
    }
  </style>
</head>
<body>

  <!-- Toast Notification Container -->
  <div id="toastContainer" class="toast-container" aria-live="polite"></div>

  <!-- Mobile Sidebar Backdrop -->
  <div id="sidebarBackdrop" class="sidebar-backdrop"></div>

  <div class="layout-container">
    
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
      
      <!-- Topbar Header -->
      <?php require_once __DIR__ . '/includes/header.php'; ?>

      <div class="content-body">
        
        <!-- Main Form Container (DPR Card Style) -->
        <div class="prd-form-card">

          <form id="addPartForm" novalidate>
            <input type="hidden" id="editPartId" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['id']) : ''; ?>">

            <!-- 1. Part Details & Specifications Section -->
            <div class="sub-section-title" style="margin-top: 0;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
              Part Details &amp; Specifications
            </div>

            <!-- Top Grid: Part Code, Part Name, Status -->
            <div class="form-grid-3">
              <div class="form-group">
                <label for="inputPartCode">Part Code *</label>
                <input type="text" id="inputPartCode" class="form-control" placeholder="e.g., PT-1008" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['part_code']) : ''; ?>" required autocomplete="off">
              </div>

              <div class="form-group">
                <label for="inputPartName">Part Name *</label>
                <input type="text" id="inputPartName" class="form-control" placeholder="e.g., Rear Bumper Sub-Assembly" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['part_name']) : ''; ?>" required autocomplete="off">
              </div>

              <div class="form-group">
                <label for="inputStatus">Status</label>
                <select id="inputStatus" class="form-control" required>
                  <option value="Active" <?php echo ($isEditMode && strcasecmp($existingPart['status'] ?? '', 'Active') === 0) ? 'selected' : (! $isEditMode ? 'selected' : ''); ?>>Active</option>
                  <option value="Inactive" <?php echo ($isEditMode && strcasecmp($existingPart['status'] ?? '', 'Inactive') === 0) ? 'selected' : ''; ?>>Inactive</option>
                </select>
              </div>
            </div>

            <!-- Mid Grid: Gross Weight & Remarks -->
            <div class="form-grid-3" style="margin-top: 18px;">
              <div class="form-group">
                <label for="inputGrossWeight">Gross Weight per Part (kg) *</label>
                <div class="input-with-suffix">
                  <input type="number" step="any" min="0" id="inputGrossWeight" placeholder="e.g. 0.250" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['gross_weight'] ?? '') : ''; ?>" required autocomplete="off">
                  <span class="input-suffix">kg</span>
                </div>
              </div>

              <div class="form-group" style="grid-column: span 2;">
                <label for="inputRemarks">Remarks / Notes</label>
                <input type="text" id="inputRemarks" class="form-control" placeholder="Enter any additional notes (optional)" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['remarks'] ?? '') : ''; ?>" autocomplete="off">
              </div>
            </div>

            <!-- File Uploads Grid: Product Data Sheet (PDF) & Description File (Excel) -->
            <?php 
              $hasPdf = ($isEditMode && !empty($existingPart['pdf_file'])); 
              $pdfName = $hasPdf ? basename($existingPart['pdf_file']) : 'Click to upload PDF';
              $hasExcel = ($isEditMode && !empty($existingPart['excel_file'])); 
              $excelName = $hasExcel ? basename($existingPart['excel_file']) : 'Click to upload Excel';
            ?>
            <div class="form-grid-2" style="margin-top: 18px; margin-bottom: 26px;">
              
              <!-- PDF Upload -->
              <div class="form-group">
                <label>Product Data Sheet (PDF) *</label>
                <input type="file" id="inputPdfFile" accept=".pdf" style="display: none;">
                <div class="upload-dropzone <?php echo $hasPdf ? 'has-file' : ''; ?>" id="pdfDropzone" title="<?php echo $hasPdf ? 'Click to replace PDF' : 'Click to upload PDF'; ?>">
                  <div class="upload-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
                      <polyline points="12 13 12 8 9 11"></polyline>
                      <polyline points="12 8 15 11"></polyline>
                    </svg>
                  </div>
                  <div class="upload-text-wrap">
                    <span class="upload-title" id="pdfFileTitle"><?php echo htmlspecialchars($pdfName); ?></span>
                    <span class="upload-subtitle" id="pdfFileSubtitle" style="<?php echo $hasPdf ? 'display:block; color:#10b981;' : 'display:none;'; ?>"><?php echo $hasPdf ? 'Current File (Click to replace)' : ''; ?></span>
                  </div>
                </div>
              </div>

              <!-- Excel Upload -->
              <div class="form-group">
                <label>Description File (Excel) *</label>
                <input type="file" id="inputExcelFile" accept=".xlsx,.xls,.csv" style="display: none;">
                <div class="upload-dropzone <?php echo $hasExcel ? 'has-file' : ''; ?>" id="excelDropzone" title="<?php echo $hasExcel ? 'Click to replace Excel' : 'Click to upload Excel'; ?>">
                  <div class="upload-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>
                      <polyline points="12 13 12 8 9 11"></polyline>
                      <polyline points="12 8 15 11"></polyline>
                    </svg>
                  </div>
                  <div class="upload-text-wrap">
                    <span class="upload-title" id="excelFileTitle"><?php echo htmlspecialchars($excelName); ?></span>
                    <span class="upload-subtitle" id="excelFileSubtitle" style="<?php echo $hasExcel ? 'display:block; color:#10b981;' : 'display:none;'; ?>"><?php echo $hasExcel ? 'Current File (Click to replace)' : ''; ?></span>
                  </div>
                </div>
              </div>

            </div>

            <!-- 2. Section: Child Parts (BOM Requirements) -->
            <div class="sub-section-title">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
              RM &amp; Child Parts (BOM Requirements)
            </div>

            <div class="table-section-header">
              <span class="table-section-desc">
                Raw material &amp; child part bill of materials (BOM) needed to produce this part
              </span>
              <span id="cpCountBadge" class="count-badge">
                1 Item
              </span>
            </div>

            <div class="table-container-wrap">
              <table class="simple-table" id="cpTable">
                <thead>
                  <tr>
                    <th style="width: 45px; text-align: center;">#</th>
                    <th style="min-width: 280px;">RM &amp; Child Part *</th>
                    <th style="width: 180px;">Quantity / Ratio *</th>
                    <th style="width: 100px; text-align: center;">UOM</th>
                    <th style="width: 90px; text-align: center;">Action</th>
                  </tr>
                </thead>
                <tbody id="cpTableBody">
                  <!-- Dynamic Child Part Rows -->
                </tbody>
              </table>
            </div>

            <!-- 3. Section: Required Processes -->
            <div class="sub-section-title">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
              Manufacturing Processes &amp; Stages
            </div>

            <div class="table-section-header">
              <span class="table-section-desc">
                Sequence of manufacturing, fabrication, or machining processes required for this part
              </span>
              <span id="prcCountBadge" class="count-badge">
                1 Process
              </span>
            </div>

            <div class="table-container-wrap">
              <table class="simple-table" id="prcTable">
                <thead>
                  <tr>
                    <th style="width: 45px; text-align: center;">#</th>
                    <th style="min-width: 260px;">Process *</th>
                    <th style="min-width: 260px;">Stage Details / Remarks</th>
                    <th style="width: 90px; text-align: center;">Action</th>
                  </tr>
                </thead>
                <tbody id="prcTableBody">
                  <!-- Dynamic Process Rows -->
                </tbody>
              </table>
            </div>

            <!-- 4. Section: Output Variants -->
            <div class="sub-section-title">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
              Finished Goods (FG) Output Variants
            </div>

            <div class="table-section-header">
              <span class="table-section-desc">
                Specify FG output part variants produced from this base part (prefixed with Part Code)
              </span>
              <span id="varCountBadge" class="count-badge">
                1 Variant
              </span>
            </div>

            <div class="table-container-wrap">
              <table class="simple-table" id="outputVariantsTable">
                <thead>
                  <tr>
                    <th style="width: 45px; text-align: center;">#</th>
                    <th style="width: 220px;">Output Part Code *</th>
                    <th style="min-width: 280px;">Output Part Name *</th>
                    <th style="width: 90px; text-align: center;">Action</th>
                  </tr>
                </thead>
                <tbody id="outputVariantsTableBody">
                  <!-- Dynamic Output Variant Rows -->
                </tbody>
              </table>
            </div>

            <!-- Form Footer Actions (DPR Style) -->
            <div class="form-card-footer">
              <a href="part-master.php" class="btn-secondary" style="height: 38px; display: inline-flex; align-items: center; text-decoration: none;">Cancel</a>
              <button type="submit" class="btn-primary" id="savePartBtn" style="height: 38px; min-width: 170px;">
                <?php echo $isEditMode ? 'Update Part' : '+ Save Part'; ?>
              </button>
            </div>

          </form>

        </div>

      </div>

    </div>

  </div>

  <script>
    // Data passed from server
    const isEditMode = <?php echo $isEditMode ? 'true' : 'false'; ?>;
    const availableChildParts = <?php echo json_encode($childPartsAvailable, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const availableProcesses = <?php echo json_encode($processesAvailable, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const existingChildParts = <?php echo json_encode($isEditMode ? (json_decode($existingPart['child_parts'] ?? '[]', true) ?: []) : []); ?>;
    const existingProcesses = <?php echo json_encode($isEditMode ? (json_decode($existingPart['processes'] ?? '[]', true) ?: []) : []); ?>;
    const existingOutputVariants = <?php echo json_encode($isEditMode ? (json_decode($existingPart['output_variants'] ?? '[]', true) ?: []) : []); ?>;

    document.addEventListener('DOMContentLoaded', () => {
      const addPartForm = document.getElementById('addPartForm');
      const cpTableBody = document.getElementById('cpTableBody');
      const prcTableBody = document.getElementById('prcTableBody');
      const outputVariantsTableBody = document.getElementById('outputVariantsTableBody');
      const savePartBtn = document.getElementById('savePartBtn');
      const inputPartCode = document.getElementById('inputPartCode');
      const toastContainer = document.getElementById('toastContainer');

      // PDF File Upload Handler
      const pdfDropzone = document.getElementById('pdfDropzone');
      const inputPdfFile = document.getElementById('inputPdfFile');
      const pdfFileTitle = document.getElementById('pdfFileTitle');
      const pdfFileSubtitle = document.getElementById('pdfFileSubtitle');

      if (pdfDropzone && inputPdfFile) {
        pdfDropzone.addEventListener('click', () => {
          inputPdfFile.click();
        });

        inputPdfFile.addEventListener('change', () => {
          if (inputPdfFile.files && inputPdfFile.files[0]) {
            const file = inputPdfFile.files[0];
            const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            pdfFileTitle.textContent = file.name;
            if (pdfFileSubtitle) {
              pdfFileSubtitle.textContent = `(${sizeMb} MB) - Click to replace`;
              pdfFileSubtitle.style.display = 'block';
            }
            pdfDropzone.classList.add('has-file');
          } else {
            pdfFileTitle.textContent = 'Click to upload PDF';
            if (pdfFileSubtitle) {
              pdfFileSubtitle.textContent = '';
              pdfFileSubtitle.style.display = 'none';
            }
            pdfDropzone.classList.remove('has-file');
          }
        });
      }

      // Excel File Upload Handler
      const excelDropzone = document.getElementById('excelDropzone');
      const inputExcelFile = document.getElementById('inputExcelFile');
      const excelFileTitle = document.getElementById('excelFileTitle');
      const excelFileSubtitle = document.getElementById('excelFileSubtitle');

      if (excelDropzone && inputExcelFile) {
        excelDropzone.addEventListener('click', () => {
          inputExcelFile.click();
        });

        inputExcelFile.addEventListener('change', () => {
          if (inputExcelFile.files && inputExcelFile.files[0]) {
            const file = inputExcelFile.files[0];
            const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            excelFileTitle.textContent = file.name;
            if (excelFileSubtitle) {
              excelFileSubtitle.textContent = `(${sizeMb} MB) - Click to replace`;
              excelFileSubtitle.style.display = 'block';
            }
            excelDropzone.classList.add('has-file');
          } else {
            excelFileTitle.textContent = 'Click to upload Excel';
            if (excelFileSubtitle) {
              excelFileSubtitle.textContent = '';
              excelFileSubtitle.style.display = 'none';
            }
            excelDropzone.classList.remove('has-file');
          }
        });
      }

      // Toast Notification Function
      const showToast = (message, type = 'info') => {
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        let iconSvg = '';
        if (type === 'success') {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
        } else if (type === 'error') {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
        } else {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`;
        }

        toast.innerHTML = `
          <span>${iconSvg}</span>
          <span>${escapeHtml(message)}</span>
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
        }, 3800);
      };

      // Helper to escape HTML characters
      function escapeHtml(str) {
        if (!str) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      // Update row count badges
      function updateRowCounts() {
        const cpCount = cpTableBody ? cpTableBody.querySelectorAll('tr').length : 0;
        const prcCount = prcTableBody ? prcTableBody.querySelectorAll('tr').length : 0;
        const varCount = outputVariantsTableBody ? outputVariantsTableBody.querySelectorAll('tr').length : 0;

        const cpBadge = document.getElementById('cpCountBadge');
        if (cpBadge) cpBadge.textContent = `${cpCount} Item${cpCount !== 1 ? 's' : ''}`;

        const prcBadge = document.getElementById('prcCountBadge');
        if (prcBadge) prcBadge.textContent = `${prcCount} Process${prcCount !== 1 ? 'es' : ''}`;

        const varBadge = document.getElementById('varCountBadge');
        if (varBadge) varBadge.textContent = `${varCount} Variant${varCount !== 1 ? 's' : ''}`;
      }

      // Re-index serial numbers (1, 2, 3...)
      function reindexRows(tbody) {
        const rows = tbody.querySelectorAll('tr');
        rows.forEach((r, idx) => {
          const numCell = r.querySelector('.row-num');
          if (numCell) numCell.textContent = idx + 1;
        });
        updateRowCounts();
      }

      // Real-time update child part dropdown options to exclude already selected parts
      function updateChildPartDropdowns() {
        const rows = cpTableBody.querySelectorAll('tr');
        const selectedValues = new Set();
        rows.forEach(r => {
          const sel = r.querySelector('.cp-select');
          if (sel && sel.value.trim()) {
            selectedValues.add(sel.value.trim().toLowerCase());
          }
        });

        rows.forEach(r => {
          const sel = r.querySelector('.cp-select');
          if (!sel) return;

          const currentVal = (sel.value || '').trim();
          const currentValLower = currentVal.toLowerCase();

          sel.innerHTML = '';
          const defaultOpt = document.createElement('option');
          defaultOpt.value = '';
          defaultOpt.textContent = '--Select Child Part--';
          sel.appendChild(defaultOpt);

          let countAvailable = 0;
          availableChildParts.forEach(cp => {
            const cpCode = (cp.part_code || '').trim();
            const cpCodeLower = cpCode.toLowerCase();

            if (!selectedValues.has(cpCodeLower) || cpCodeLower === currentValLower) {
              countAvailable++;
              const opt = document.createElement('option');
              opt.value = cpCode;
              opt.setAttribute('data-name', cp.part_name || '');
              opt.setAttribute('data-spec', cp.grade_spec || '-');
              opt.setAttribute('data-uom', cp.uom || 'NOS');
              opt.textContent = `${cpCode} - ${cp.part_name || ''}`;
              if (cpCodeLower === currentValLower) {
                opt.selected = true;
              }
              sel.appendChild(opt);
            }
          });

          if (countAvailable === 0 && !currentVal) {
            defaultOpt.textContent = '--All Child Parts Selected--';
          }
        });
      }

      // Real-time update process dropdown options to exclude already selected processes
      function updateProcessDropdowns() {
        const rows = prcTableBody.querySelectorAll('tr');
        const selectedValues = new Set();
        rows.forEach(r => {
          const sel = r.querySelector('.prc-select');
          if (sel && sel.value.trim()) {
            selectedValues.add(sel.value.trim().toLowerCase());
          }
        });

        rows.forEach(r => {
          const sel = r.querySelector('.prc-select');
          if (!sel) return;

          const currentVal = (sel.value || '').trim();
          const currentValLower = currentVal.toLowerCase();

          sel.innerHTML = '';
          const defaultOpt = document.createElement('option');
          defaultOpt.value = '';
          defaultOpt.textContent = '--Select Process--';
          sel.appendChild(defaultOpt);

          let countAvailable = 0;
          availableProcesses.forEach(prc => {
            const prcName = (prc.process_name || '').trim();
            const prcNameLower = prcName.toLowerCase();
            const prcCode = (prc.process_code || '').trim();
            const prcCodeLower = prcCode.toLowerCase();

            const isSelectedElsewhere = selectedValues.has(prcNameLower) || selectedValues.has(prcCodeLower);
            const isCurrent = (prcNameLower === currentValLower || prcCodeLower === currentValLower);

            if (!isSelectedElsewhere || isCurrent) {
              countAvailable++;
              const opt = document.createElement('option');
              opt.value = prcName;
              opt.setAttribute('data-code', prcCode);
              opt.setAttribute('data-desc', prc.remarks || '');
              opt.textContent = `${prcCode} - ${prcName}`;
              if (isCurrent) {
                opt.selected = true;
              }
              sel.appendChild(opt);
            }
          });

          if (countAvailable === 0 && !currentVal) {
            defaultOpt.textContent = '--All Processes Selected--';
          }
        });
      }

      // Real-time duplicate check for Output Variant Codes
      function checkDuplicateVariantCodes(triggeredInput = null) {
        const rows = outputVariantsTableBody.querySelectorAll('tr');
        const codeMap = {};
        
        rows.forEach(row => {
          const input = row.querySelector('.var-code');
          if (input) {
            const val = input.value.trim().toLowerCase();
            if (val) {
              if (!codeMap[val]) codeMap[val] = [];
              codeMap[val].push(input);
            }
          }
        });

        let hasDup = false;
        rows.forEach(row => {
          const input = row.querySelector('.var-code');
          if (input) {
            const val = input.value.trim().toLowerCase();
            if (val && codeMap[val] && codeMap[val].length > 1) {
              input.classList.add('is-invalid');
              hasDup = true;
              if (triggeredInput && input === triggeredInput) {
                showToast(`Output Part Code "${input.value.trim()}" is duplicate. Same part code is not allowed!`, 'error');
              }
            } else if (val) {
              input.classList.remove('is-invalid');
            }
          }
        });

        return hasDup;
      }

      // ==========================================
      // 1. CHILD PARTS TABLE ROWS LOGIC
      // ==========================================
      function addCpRow(selectedCode = '', qty = 1, insertAfterEl = null) {
        const tr = document.createElement('tr');

        let selectOptions = '<option value="">--Select Child Part--</option>';
        let initialUom = 'NOS';

        availableChildParts.forEach(cp => {
          const isSel = (cp.part_code === selectedCode) ? 'selected' : '';
          if (isSel) {
            initialUom = cp.uom || 'NOS';
          }
          selectOptions += `<option value="${escapeHtml(cp.part_code)}" data-name="${escapeHtml(cp.part_name)}" data-spec="${escapeHtml(cp.grade_spec || '-')}" data-uom="${escapeHtml(cp.uom || 'NOS')}" ${isSel}>${escapeHtml(cp.part_code)} - ${escapeHtml(cp.part_name)}</option>`;
        });

        tr.innerHTML = `
          <td class="row-num" style="text-align: center; color: #475569; font-weight: 700;">1</td>
          <td>
            <select class="cell-select cp-select" required>
              ${selectOptions}
            </select>
          </td>
          <td>
            <input type="number" step="any" min="0.001" class="cell-input cp-qty" value="${qty}" required placeholder="Qty">
          </td>
          <td style="text-align: center;">
            <span class="uom-badge cp-uom">${initialUom}</span>
          </td>
          <td style="text-align: center;">
            <div class="action-btn-group">
              <button type="button" class="btn-circle-action btn-circle-add btn-add-cp-inline" title="Add Row Below" aria-label="Add Row Below">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </button>
              <button type="button" class="btn-circle-action btn-circle-minus btn-remove-cp" title="Remove Row" aria-label="Remove Row">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </button>
            </div>
          </td>
        `;

        const selectEl = tr.querySelector('.cp-select');
        const uomBadge = tr.querySelector('.cp-uom');

        selectEl.addEventListener('change', () => {
          const opt = selectEl.options[selectEl.selectedIndex];
          const uom = (opt && opt.value) ? (opt.getAttribute('data-uom') || 'NOS') : 'NOS';
          uomBadge.textContent = uom;
          selectEl.classList.remove('is-invalid');
          updateChildPartDropdowns();
        });

        // Inline Add button (+) handler
        tr.querySelector('.btn-add-cp-inline').addEventListener('click', () => {
          if (!selectEl.value) {
            showToast('Please select a child part in the current row before adding another.', 'info');
            selectEl.focus();
            return;
          }
          const currentSelects = cpTableBody.querySelectorAll('.cp-select');
          const selectedCount = Array.from(currentSelects).filter(s => s.value).length;
          if (selectedCount >= availableChildParts.length && availableChildParts.length > 0) {
            showToast('All available child parts have already been selected.', 'info');
            return;
          }
          addCpRow('', 1, tr);
          updateChildPartDropdowns();
        });

        // Inline Remove button (-) handler
        tr.querySelector('.btn-remove-cp').addEventListener('click', () => {
          if (cpTableBody.querySelectorAll('tr').length > 1) {
            tr.remove();
            reindexRows(cpTableBody);
            updateChildPartDropdowns();
          } else {
            selectEl.value = '';
            tr.querySelector('.cp-qty').value = 1;
            uomBadge.textContent = 'NOS';
            selectEl.classList.remove('is-invalid');
            updateChildPartDropdowns();
            showToast('Child part row cleared.', 'info');
          }
        });

        if (insertAfterEl && insertAfterEl.parentNode === cpTableBody) {
          insertAfterEl.insertAdjacentElement('afterend', tr);
        } else {
          cpTableBody.appendChild(tr);
        }

        reindexRows(cpTableBody);
      }

      // Initialize Child Part rows (Edit mode vs Add mode)
      if (isEditMode && Array.isArray(existingChildParts) && existingChildParts.length > 0) {
        existingChildParts.forEach(cp => {
          addCpRow(cp.code || '', cp.qty || 1);
        });
      } else {
        addCpRow('', 1);
      }
      updateChildPartDropdowns();

      // ==========================================
      // 2. REQUIRED PROCESSES TABLE ROWS LOGIC
      // ==========================================
      function addPrcRow(selectedCode = '', remarks = '', insertAfterEl = null) {
        const tr = document.createElement('tr');

        let selectOptions = '<option value="">--Select Process--</option>';
        let initialRemarks = remarks || '';

        availableProcesses.forEach(prc => {
          const isSel = (prc.process_code === selectedCode || prc.process_name === selectedCode) ? 'selected' : '';
          const desc = prc.remarks || '';
          if (isSel && !initialRemarks) {
            initialRemarks = desc;
          }
          selectOptions += `<option value="${escapeHtml(prc.process_name)}" data-code="${escapeHtml(prc.process_code)}" data-desc="${escapeHtml(desc)}" ${isSel}>${escapeHtml(prc.process_code)} - ${escapeHtml(prc.process_name)}</option>`;
        });

        tr.innerHTML = `
          <td class="row-num" style="text-align: center; color: #475569; font-weight: 700;">1</td>
          <td>
            <select class="cell-select prc-select">
              ${selectOptions}
            </select>
          </td>
          <td>
            <input type="text" class="cell-input prc-remarks" value="${escapeHtml(initialRemarks)}" placeholder="e.g., Primary stamping, Heat treatment, Surface coating...">
          </td>
          <td style="text-align: center;">
            <div class="action-btn-group">
              <button type="button" class="btn-circle-action btn-circle-add btn-add-prc-inline" title="Add Process Below" aria-label="Add Process Below">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </button>
              <button type="button" class="btn-circle-action btn-circle-minus btn-remove-prc" title="Remove Process Row" aria-label="Remove Process Row">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </button>
            </div>
          </td>
        `;

        const prcSelectEl = tr.querySelector('.prc-select');
        const remarksInput = tr.querySelector('.prc-remarks');

        prcSelectEl.addEventListener('change', () => {
          const opt = prcSelectEl.options[prcSelectEl.selectedIndex];
          const desc = (opt && opt.value) ? (opt.getAttribute('data-desc') || '') : '';
          if (remarksInput) {
            remarksInput.value = desc;
          }
          prcSelectEl.classList.remove('is-invalid');
          updateProcessDropdowns();
        });

        // Inline Add button (+) handler
        tr.querySelector('.btn-add-prc-inline').addEventListener('click', () => {
          if (!prcSelectEl.value) {
            showToast('Please select a process in the current row before adding another.', 'info');
            prcSelectEl.focus();
            return;
          }
          const currentSelects = prcTableBody.querySelectorAll('.prc-select');
          const selectedCount = Array.from(currentSelects).filter(s => s.value).length;
          if (selectedCount >= availableProcesses.length && availableProcesses.length > 0) {
            showToast('All available processes have already been selected.', 'info');
            return;
          }
          addPrcRow('', '', tr);
          updateProcessDropdowns();
        });

        // Inline Remove button (-) handler
        tr.querySelector('.btn-remove-prc').addEventListener('click', () => {
          if (prcTableBody.querySelectorAll('tr').length > 1) {
            tr.remove();
            reindexRows(prcTableBody);
            updateProcessDropdowns();
          } else {
            prcSelectEl.value = '';
            tr.querySelector('.prc-remarks').value = '';
            prcSelectEl.classList.remove('is-invalid');
            updateProcessDropdowns();
            showToast('Process row cleared.', 'info');
          }
        });

        if (insertAfterEl && insertAfterEl.parentNode === prcTableBody) {
          insertAfterEl.insertAdjacentElement('afterend', tr);
        } else {
          prcTableBody.appendChild(tr);
        }

        reindexRows(prcTableBody);
      }

      // Initialize Process rows (Edit mode vs Add mode)
      if (isEditMode && Array.isArray(existingProcesses) && existingProcesses.length > 0) {
        existingProcesses.forEach(prc => {
          addPrcRow(prc.name || prc.code || '', prc.remarks || '');
        });
      } else {
        addPrcRow('', '');
      }
      updateProcessDropdowns();

      // ==========================================
      // 3. OUTPUT VARIANTS TABLE ROWS LOGIC
      // ==========================================
      function getPartCodePrefix(code = null) {
        const raw = (code !== null ? code : (inputPartCode ? inputPartCode.value : '')).trim();
        if (!raw) return '';
        return raw.endsWith('-') ? raw : (raw + '-');
      }

      function addOutputVariantRow(code = '', name = '', insertAfterEl = null) {
        const tr = document.createElement('tr');

        const effectivePrefix = getPartCodePrefix();
        const initialCode = (code !== '') ? code : effectivePrefix;
        const currentPlaceholder = effectivePrefix ? `e.g., ${effectivePrefix}A` : 'e.g., FG-1001-A';

        tr.innerHTML = `
          <td class="row-num" style="text-align: center; color: #475569; font-weight: 700;">1</td>
          <td>
            <input type="text" class="cell-input var-code" value="${escapeHtml(initialCode)}" placeholder="${escapeHtml(currentPlaceholder)}" required autocomplete="off">
          </td>
          <td>
            <input type="text" class="cell-input var-name" value="${escapeHtml(name)}" placeholder="e.g., Finished Assembly Variant A" required autocomplete="off">
          </td>
          <td style="text-align: center;">
            <div class="action-btn-group">
              <button type="button" class="btn-circle-action btn-circle-add btn-add-var-inline" title="Add Output Variant Below" aria-label="Add Output Variant Below">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </button>
              <button type="button" class="btn-circle-action btn-circle-minus btn-remove-var" title="Remove Output Variant Row" aria-label="Remove Output Variant Row">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
              </button>
            </div>
          </td>
        `;

        // Output Part Code duplicate check
        const codeInput = tr.querySelector('.var-code');
        codeInput.addEventListener('blur', () => {
          checkDuplicateVariantCodes(codeInput);
        });
        codeInput.addEventListener('input', () => {
          checkDuplicateVariantCodes();
        });

        // Place cursor at the end when focused via tab if value ends with '-'
        codeInput.addEventListener('focus', () => {
          setTimeout(() => {
            if (document.activeElement === codeInput && codeInput.selectionStart === 0 && codeInput.selectionEnd === codeInput.value.length && codeInput.value.endsWith('-')) {
              const len = codeInput.value.length;
              codeInput.setSelectionRange(len, len);
            }
          }, 0);
        });

        // Inline Add button (+) handler
        tr.querySelector('.btn-add-var-inline').addEventListener('click', () => {
          addOutputVariantRow('', '', tr);
        });

        // Inline Remove button (-) handler
        tr.querySelector('.btn-remove-var').addEventListener('click', () => {
          if (outputVariantsTableBody.querySelectorAll('tr').length > 1) {
            tr.remove();
            reindexRows(outputVariantsTableBody);
            checkDuplicateVariantCodes();
          } else {
            const prefix = getPartCodePrefix();
            tr.querySelector('.var-code').value = prefix;
            tr.querySelector('.var-name').value = '';
            tr.querySelector('.var-code').classList.remove('is-invalid');
            showToast('Output variant row cleared.', 'info');
          }
        });

        if (insertAfterEl && insertAfterEl.parentNode === outputVariantsTableBody) {
          insertAfterEl.insertAdjacentElement('afterend', tr);
        } else {
          outputVariantsTableBody.appendChild(tr);
        }

        reindexRows(outputVariantsTableBody);
      }

      // Initialize Output Variant rows (Edit mode vs Add mode)
      if (isEditMode && Array.isArray(existingOutputVariants) && existingOutputVariants.length > 0) {
        existingOutputVariants.forEach(ov => {
          addOutputVariantRow(ov.code || '', ov.name || '');
        });
      } else {
        addOutputVariantRow('', '');
      }

      // Real-time synchronization of Part Code with Output Variant Codes
      let lastPartCode = inputPartCode ? inputPartCode.value.trim() : '';

      function syncVariantCodesWithPartCode() {
        if (!inputPartCode) return;
        const currentPartCode = inputPartCode.value.trim();
        const oldPrefix = getPartCodePrefix(lastPartCode);
        const newPrefix = getPartCodePrefix(currentPartCode);
        const placeholderText = newPrefix ? `e.g., ${newPrefix}A` : 'e.g., FG-1001-A';

        const rows = outputVariantsTableBody.querySelectorAll('tr');
        rows.forEach(tr => {
          const varCodeInput = tr.querySelector('.var-code');
          if (!varCodeInput) return;

          varCodeInput.placeholder = placeholderText;
          const currentVal = varCodeInput.value;

          if (!currentVal || currentVal === oldPrefix || (lastPartCode && currentVal === lastPartCode)) {
            varCodeInput.value = newPrefix;
          } else if (oldPrefix && currentVal.startsWith(oldPrefix)) {
            const suffix = currentVal.substring(oldPrefix.length);
            varCodeInput.value = newPrefix ? (newPrefix + suffix) : suffix;
          }
        });

        lastPartCode = currentPartCode;
        checkDuplicateVariantCodes();
      }

      if (inputPartCode) {
        inputPartCode.addEventListener('input', syncVariantCodesWithPartCode);
        inputPartCode.addEventListener('change', syncVariantCodesWithPartCode);
      }

      // Initial synchronization on page load
      syncVariantCodesWithPartCode();

      // Real-time invalid border cleanup
      addPartForm.addEventListener('input', (e) => {
        if (e.target && e.target.classList.contains('is-invalid')) {
          e.target.classList.remove('is-invalid');
        }
      });
      addPartForm.addEventListener('change', (e) => {
        if (e.target && e.target.classList.contains('is-invalid')) {
          e.target.classList.remove('is-invalid');
        }
      });

      // ==========================================
      // 4. SUBMIT FORM HANDLER (Save / Update API)
      // ==========================================
      addPartForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        // Clear existing error highlights
        addPartForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

        const codeEl = document.getElementById('inputPartCode');
        const nameEl = document.getElementById('inputPartName');
        const weightEl = document.getElementById('inputGrossWeight');
        const statusEl = document.getElementById('inputStatus');
        const remarksEl = document.getElementById('inputRemarks');
        const editPartIdEl = document.getElementById('editPartId');

        const code = codeEl ? codeEl.value.trim() : '';
        const name = nameEl ? nameEl.value.trim() : '';
        const grossWeight = weightEl ? weightEl.value.trim() : '';
        const status = statusEl ? statusEl.value : 'Active';
        const remarks = remarksEl ? remarksEl.value.trim() : '';
        const editId = editPartIdEl ? editPartIdEl.value.trim() : '';
        const action = (isEditMode && editId) ? 'update' : 'create';

        // 1. Validate Top Fields
        if (!code) {
          showToast('Please enter Part Code.', 'error');
          if (codeEl) {
            codeEl.classList.add('is-invalid');
            codeEl.focus();
          }
          return;
        }

        if (!name) {
          showToast('Please enter Part Name.', 'error');
          if (nameEl) {
            nameEl.classList.add('is-invalid');
            nameEl.focus();
          }
          return;
        }

        if (!grossWeight || isNaN(parseFloat(grossWeight)) || parseFloat(grossWeight) <= 0) {
          showToast('Please enter a valid Gross Weight per Part (> 0 kg).', 'error');
          if (weightEl) {
            weightEl.classList.add('is-invalid');
            weightEl.focus();
          }
          return;
        }

        // 2. Validate [RM & CHILD PART]
        const childParts = [];
        const seenCpCodes = new Set();
        const cpRows = cpTableBody.querySelectorAll('tr');
        if (cpRows.length === 0) {
          showToast('At least one RM & Child Part is required.', 'error');
          return;
        }

        for (let i = 0; i < cpRows.length; i++) {
          const row = cpRows[i];
          const rowNum = i + 1;
          const select = row.querySelector('.cp-select');
          const qtyInput = row.querySelector('.cp-qty');
          const pCode = select ? select.value.trim() : '';
          const qty = qtyInput ? parseFloat(qtyInput.value) : 0;

          if (!pCode) {
            showToast(`Please select RM & Child Part in row #${rowNum}.`, 'error');
            if (select) {
              select.classList.add('is-invalid');
              select.focus();
            }
            return;
          }

          const pCodeLower = pCode.toLowerCase();
          if (seenCpCodes.has(pCodeLower)) {
            showToast(`Duplicate RM & Child Part "${pCode}" in row #${rowNum}. Same child part cannot be selected multiple times.`, 'error');
            if (select) {
              select.classList.add('is-invalid');
              select.focus();
            }
            return;
          }
          seenCpCodes.add(pCodeLower);

          if (isNaN(qty) || qty <= 0) {
            showToast(`Please enter a valid Quantity (> 0) for Child Part in row #${rowNum}.`, 'error');
            if (qtyInput) {
              qtyInput.classList.add('is-invalid');
              qtyInput.focus();
            }
            return;
          }

          const opt = select.options[select.selectedIndex];
          const pName = opt ? opt.getAttribute('data-name') : '';
          const pUom = opt ? opt.getAttribute('data-uom') : 'NOS';

          childParts.push({
            code: pCode,
            name: pName,
            qty: qty,
            uom: pUom
          });
        }

        // 3. Validate [PROCESS]
        const processes = [];
        const seenPrcNames = new Set();
        const prcRows = prcTableBody.querySelectorAll('tr');
        if (prcRows.length === 0) {
          showToast('At least one Process is required.', 'error');
          return;
        }

        for (let i = 0; i < prcRows.length; i++) {
          const row = prcRows[i];
          const rowNum = i + 1;
          const select = row.querySelector('.prc-select');
          const remarksInput = row.querySelector('.prc-remarks');
          const prcName = select ? select.value.trim() : '';
          const remarksVal = remarksInput ? remarksInput.value.trim() : '';

          if (!prcName) {
            showToast(`Please select a Process in row #${rowNum}.`, 'error');
            if (select) {
              select.classList.add('is-invalid');
              select.focus();
            }
            return;
          }

          const prcNameLower = prcName.toLowerCase();
          if (seenPrcNames.has(prcNameLower)) {
            showToast(`Duplicate Process "${prcName}" in row #${rowNum}. Same process cannot be selected multiple times.`, 'error');
            if (select) {
              select.classList.add('is-invalid');
              select.focus();
            }
            return;
          }
          seenPrcNames.add(prcNameLower);

          const opt = select.options[select.selectedIndex];
          const prcCode = opt ? opt.getAttribute('data-code') : '';
          processes.push({
            code: prcCode,
            name: prcName,
            remarks: remarksVal
          });
        }

        // 4. Validate [Output Part Code, Output Part Name]
        const outputVariants = [];
        const seenVarCodes = new Set();
        const varRows = outputVariantsTableBody.querySelectorAll('tr');
        if (varRows.length === 0) {
          showToast('At least one Output Variant is required.', 'error');
          return;
        }

        for (let i = 0; i < varRows.length; i++) {
          const row = varRows[i];
          const rowNum = i + 1;
          const codeInput = row.querySelector('.var-code');
          const nameInput = row.querySelector('.var-name');

          const vCode = codeInput ? codeInput.value.trim() : '';
          const vName = nameInput ? nameInput.value.trim() : '';

          if (!vCode) {
            showToast(`Output Part Code is required in Output Variant row #${rowNum}.`, 'error');
            if (codeInput) {
              codeInput.classList.add('is-invalid');
              codeInput.focus();
            }
            return;
          }

          const vCodeLower = vCode.toLowerCase();
          if (seenVarCodes.has(vCodeLower)) {
            showToast(`Duplicate Output Part Code "${vCode}" in Output Variant row #${rowNum}. Same part code is not allowed!`, 'error');
            if (codeInput) {
              codeInput.classList.add('is-invalid');
              codeInput.focus();
            }
            return;
          }
          seenVarCodes.add(vCodeLower);

          if (!vName) {
            showToast(`Output Part Name is required in Output Variant row #${rowNum}.`, 'error');
            if (nameInput) {
              nameInput.classList.add('is-invalid');
              nameInput.focus();
            }
            return;
          }

          outputVariants.push({
            code: vCode,
            name: vName
          });
        }

        savePartBtn.disabled = true;
        const originalText = savePartBtn.innerHTML;
        savePartBtn.innerHTML = isEditMode ? '<span>Updating Part...</span>' : '<span>Saving Part...</span>';

        try {
          const formData = new FormData();
          formData.append('action', action);
          if (action === 'update') {
            formData.append('id', editId);
          }
          formData.append('part_code', code);
          formData.append('part_name', name);
          formData.append('status', status);
          formData.append('gross_weight', grossWeight);
          formData.append('remarks', remarks);
          formData.append('child_parts', JSON.stringify(childParts));
          formData.append('processes', JSON.stringify(processes));
          formData.append('output_variants', JSON.stringify(outputVariants));

          const variantNames = outputVariants.map(v => v.code || v.name).filter(Boolean);
          formData.append('fg_variant_type', variantNames.join(', '));

          if (inputPdfFile && inputPdfFile.files && inputPdfFile.files[0]) {
            formData.append('pdf_file', inputPdfFile.files[0]);
          }

          if (inputExcelFile && inputExcelFile.files && inputExcelFile.files[0]) {
            formData.append('excel_file', inputExcelFile.files[0]);
          }

          const resp = await fetch('api/part_master.php', {
            method: 'POST',
            body: formData
          });

          const res = await resp.json();

          if (!res.success) {
            showToast(res.message || 'Error saving part.', 'error');
            savePartBtn.disabled = false;
            savePartBtn.innerHTML = originalText;
            return;
          }

          const successMsg = isEditMode
            ? `Part "${code}" updated successfully! Redirecting...`
            : `Part "${code}" created successfully! Redirecting...`;

          showToast(successMsg, 'success');
          setTimeout(() => {
            window.location.href = 'part-master.php';
          }, 800);

        } catch (err) {
          console.error(err);
          showToast('Network error while saving part.', 'error');
          savePartBtn.disabled = false;
          savePartBtn.innerHTML = originalText;
        }
      });
    });
  </script>

</body>
</html>
