<?php
/**
 * WIP Management Portal - Add / Edit Part (Dedicated Page)
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
  <title>Add New Part - WIP Management Portal</title>
  
  <!-- Modern Clean Google Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="css/dashboard.css">

  <style>
    /* Add Part Page Styling */
    .page-header-strip {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 8px;
      flex-wrap: wrap;
    }

    .breadcrumb-nav {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.84rem;
      color: var(--text-sub);
    }

    .breadcrumb-nav a {
      color: var(--text-sub);
      text-decoration: none;
      transition: color 0.15s ease;
    }

    .breadcrumb-nav a:hover {
      color: var(--primary);
    }

    .breadcrumb-separator {
      color: #94a3b8;
    }

    .breadcrumb-current {
      color: var(--text-main);
      font-weight: 600;
    }

    .btn-back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 0.85rem;
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

    .btn-back-link svg {
      width: 16px;
      height: 16px;
    }

    /* Main Container Card */
    .part-form-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 28px 32px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
      margin-bottom: 24px;
    }

    .form-section-title {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 4px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .form-section-subtitle {
      font-size: 0.86rem;
      color: var(--text-sub);
      margin-bottom: 24px;
      line-height: 1.5;
    }

    /* Top Row Grid (Part Code, Part Name, Status) */
    .top-fields-grid {
      display: grid;
      grid-template-columns: 220px 1fr 180px;
      gap: 20px;
      margin-bottom: 30px;
      align-items: flex-start;
    }

    @media (max-width: 900px) {
      .top-fields-grid {
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width: 600px) {
      .top-fields-grid {
        grid-template-columns: 1fr;
      }
    }

    /* Mid Fields Grid (Weight, PDF, Excel) */
    .mid-fields-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 20px;
      margin-bottom: 20px;
      align-items: flex-end;
    }

    @media (max-width: 900px) {
      .mid-fields-grid {
        grid-template-columns: 1fr;
      }
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
      font-size: 0.90rem;
      color: var(--text-main);
      background: transparent;
      box-shadow: none !important;
      box-sizing: border-box;
    }

    .input-suffix {
      background: #f1f5f9;
      color: #475569;
      font-size: 0.86rem;
      font-weight: 600;
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
      justify-content: center;
      gap: 12px;
      height: 42px;
      border: 1.5px dashed #bfdbfe;
      background: #f8fafc;
      border-radius: 6px;
      padding: 0 12px;
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

    .upload-icon svg {
      width: 18px;
      height: 18px;
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
    }

    .upload-title {
      font-size: 0.80rem;
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

    .field-item {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .field-item label {
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--text-main);
    }

    .field-item .form-control {
      width: 100%;
      height: 40px;
      padding: 0 12px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: inherit;
      font-size: 0.90rem;
      color: var(--text-main);
      background: #ffffff;
      outline: none;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
      box-sizing: border-box;
    }

    .field-item .form-control:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    /* Sub-Section Box */
    .section-box {
      margin-bottom: 30px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 22px 24px;
      background: #fafcff;
    }

    .section-header-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      flex-wrap: wrap;
      gap: 12px;
    }

    .section-heading {
      font-size: 1.05rem;
      font-weight: 800;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .section-subtitle {
      font-size: 0.82rem;
      color: #64748b;
      margin-top: 3px;
      font-weight: 400;
    }

    .btn-add-table-row {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--primary);
      cursor: pointer;
      transition: all 0.15s ease;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }

    .btn-add-table-row:hover {
      background: var(--primary-light);
      border-color: var(--primary);
    }

    /* Structured Tables */
    .structured-table {
      width: 100%;
      border-collapse: collapse;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      overflow: hidden;
    }

    .structured-table th,
    .structured-table td {
      border: 1px solid #cbd5e1;
    }

    .structured-table th {
      background: #f8fafc;
      padding: 10px 14px;
      text-align: left;
      font-size: 0.80rem;
      font-weight: 700;
      color: #334155;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .structured-table td {
      padding: 8px 12px;
      vertical-align: middle;
      font-size: 0.88rem;
    }

    .cell-select, .cell-input {
      width: 100%;
      height: 36px;
      padding: 0 10px;
      border: 1px solid #cbd5e1;
      border-radius: 4px;
      font-family: inherit;
      font-size: 0.86rem;
      outline: none;
      background: #ffffff;
      color: var(--text-main);
    }

    .cell-select:focus, .cell-input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
    }

    .uom-badge {
      display: inline-block;
      font-weight: 700;
      color: #334155;
      font-size: 0.86rem;
      padding: 2px 6px;
    }

    /* Child Part Code & Process Code Badge */
    .cp-code-badge {
      display: inline-block;
      font-weight: 700;
      font-size: 0.84rem;
      color: #475569;
      background: #f8fafc;
      padding: 5px 12px;
      border-radius: 6px;
      border: 1px solid #cbd5e1;
      min-width: 90px;
      text-align: center;
      letter-spacing: 0.02em;
      transition: all 0.15s ease;
      font-family: inherit;
    }

    .cp-code-badge.has-val {
      color: var(--primary);
      background: #eff6ff;
      border-color: #bfdbfe;
      font-weight: 800;
    }

    /* Action Buttons Group */
    .action-btn-group {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    /* Circular Action Buttons (+ and -) matching wireframe */
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

    /* FG Variant Type Section */
    .fg-variant-card {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 20px 22px;
    }

    .fg-variant-label {
      font-size: 0.95rem;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .fg-variant-hint {
      font-size: 0.82rem;
      color: #64748b;
      margin-bottom: 12px;
    }

    .fg-variant-input {
      width: 100%;
      height: 42px;
      padding: 0 14px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: inherit;
      font-size: 0.90rem;
      color: var(--text-main);
      outline: none;
      transition: all 0.15s ease;
    }

    .fg-variant-input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .quick-tags-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 10px;
      flex-wrap: wrap;
    }

    .quick-tag-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: #64748b;
    }

    .quick-tag {
      padding: 4px 10px;
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      border-radius: 999px;
      font-size: 0.76rem;
      font-weight: 600;
      color: #334155;
      cursor: pointer;
      transition: all 0.15s ease;
      user-select: none;
    }

    .quick-tag:hover {
      background: var(--primary-light);
      border-color: #bfdbfe;
      color: var(--primary);
    }

    .quick-tag.selected {
      background: var(--primary);
      border-color: var(--primary);
      color: #ffffff;
    }

    /* Footer Action Buttons */
    .form-footer-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 12px;
      padding-top: 22px;
      border-top: 1px solid var(--border);
    }

    .btn-cancel {
      padding: 10px 22px;
      background: #ffffff;
      color: var(--text-main);
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 0.88rem;
      font-weight: 600;
      text-decoration: none;
      transition: background 0.15s ease;
    }

    .btn-cancel:hover {
      background: #f1f5f9;
    }

    .btn-save-part {
      padding: 10px 26px;
      background: var(--primary);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 0.90rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.15s ease;
      box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
    }

    .btn-save-part:hover {
      background: var(--primary-hover);
    }

    .btn-save-part:disabled {
      opacity: 0.65;
      cursor: not-allowed;
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

    /* File preview link for edit mode */
    .file-view-link {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      margin-top: 6px;
      font-size: 0.78rem;
      font-weight: 600;
      color: var(--primary);
      text-decoration: none;
    }

    .file-view-link:hover {
      text-decoration: underline;
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

    <!-- Main Content Area -->
    <div class="main-content">
      
      <!-- Reusable Top Bar Component -->
      <?php include __DIR__ . '/includes/header.php'; ?>

      <!-- Page Body -->
      <div class="content-body">
        
        <!-- Header Strip with Breadcrumb & Back Button -->
        <div class="page-header-strip">
          <nav class="breadcrumb-nav" aria-label="Breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <span class="breadcrumb-separator">/</span>
            <a href="part-master.php">Part Master</a>
            <span class="breadcrumb-separator">/</span>
            <span class="breadcrumb-current"><?php echo $isEditMode ? 'Edit Part' : 'Add Part'; ?></span>
          </nav>

          <a href="part-master.php" class="btn-back-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="19" y1="12" x2="5" y2="12"></line>
              <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Back to Part Master Directory
          </a>
        </div>

        <!-- Main Form Card -->
        <div class="part-form-card">
          <form id="addPartForm" novalidate>
            <input type="hidden" id="editPartId" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['id']) : ''; ?>">
            
            <h1 class="form-section-title">
              <?php echo $isEditMode ? 'Edit Part Master: ' . htmlspecialchars($existingPart['part_code']) : 'Part Master Addition'; ?>
            </h1>
            <p class="form-section-subtitle">
              <?php echo $isEditMode ? 'Update primary part specifications, mapped child parts, required manufacturing processes, and FG output variants.' : 'Add primary part information, mapped child parts, required manufacturing processes, and Finished Goods (FG) variant type.'; ?>
            </p>

            <!-- 1. Top Row: Part Code*, Part Name*, Status -->
            <div class="top-fields-grid">
              
              <div class="field-item">
                <label for="inputPartCode">Part Code*</label>
                <input type="text" id="inputPartCode" class="form-control" placeholder="e.g., PT-1008" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['part_code']) : ''; ?>" required autocomplete="off">
              </div>

              <div class="field-item">
                <label for="inputPartName">Part Name*</label>
                <input type="text" id="inputPartName" class="form-control" placeholder="e.g., Rear Bumper Sub-Assembly" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['part_name']) : ''; ?>" required autocomplete="off">
              </div>

              <div class="field-item">
                <label for="inputStatus">Status</label>
                <select id="inputStatus" class="form-control" required>
                  <option value="Active" <?php echo ($isEditMode && strcasecmp($existingPart['status'] ?? '', 'Active') === 0) ? 'selected' : (! $isEditMode ? 'selected' : ''); ?>>Active</option>
                  <option value="Inactive" <?php echo ($isEditMode && strcasecmp($existingPart['status'] ?? '', 'Inactive') === 0) ? 'selected' : ''; ?>>Inactive</option>
                </select>
              </div>

            </div>

            <!-- Mid Row: Gross Weight, Product Data Sheet (PDF), Description File (Excel) -->
            <?php 
              $hasPdf = ($isEditMode && !empty($existingPart['pdf_file'])); 
              $pdfName = $hasPdf ? basename($existingPart['pdf_file']) : 'Click to upload PDF';
              $hasExcel = ($isEditMode && !empty($existingPart['excel_file'])); 
              $excelName = $hasExcel ? basename($existingPart['excel_file']) : 'Click to upload Excel';
            ?>
            <div class="mid-fields-grid">
              
              <!-- 1. Gross Weight per Part (kg) * -->
              <div class="field-item">
                <label for="inputGrossWeight">Gross Weight per Part (kg) <span style="color: #ef4444;">*</span></label>
                <div class="input-with-suffix">
                  <input type="number" step="any" min="0" id="inputGrossWeight" placeholder="Enter weight (e.g. 0.250)" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['gross_weight'] ?? '') : ''; ?>" required autocomplete="off">
                  <span class="input-suffix">kg</span>
                </div>
              </div>

              <!-- 2. Product Data Sheet (PDF) * -->
              <div class="field-item">
                <label>Product Data Sheet (PDF) <span style="color: #ef4444;">*</span></label>
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

              <!-- 3. Description File (Excel) * -->
              <div class="field-item">
                <label>Description File (Excel) <span style="color: #ef4444;">*</span></label>
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

            <!-- Remarks / Notes -->
            <div class="field-item" style="margin-bottom: 28px;">
              <label for="inputRemarks">Remarks / Notes</label>
              <input type="text" id="inputRemarks" class="form-control" placeholder="Enter any additional notes (optional)" value="<?php echo $isEditMode ? htmlspecialchars($existingPart['remarks'] ?? '') : ''; ?>" autocomplete="off">
            </div>

            <!-- 2. Section: Child Parts -->
            <div class="section-box">
              <div class="section-header-row">
                <div>
                  <h2 class="section-heading">Child Parts</h2>
                  <div class="section-subtitle">Raw material & child part bill of materials (BOM) needed to produce this part</div>
                </div>
              </div>

              <div style="overflow-x: auto;">
                <table class="structured-table" id="cpTable">
                  <thead>
                    <tr>
                      <th style="width: 50px; text-align: center;">#</th>
                      <th style="min-width: 300px;">RM & CHILD PART</th>
                      <th style="width: 170px;">QUANTITY / RATIO</th>
                      <th style="width: 120px; text-align: center;">UOM</th>
                      <th style="width: 100px; text-align: center;">ACTION</th>
                    </tr>
                  </thead>
                  <tbody id="cpTableBody">
                    <!-- Dynamic Child Part Rows -->
                  </tbody>
                </table>
              </div>
            </div>

            <!-- 3. Section: Required Processes -->
            <div class="section-box">
              <div class="section-header-row">
                <div>
                  <h2 class="section-heading">Processes</h2>
                  <div class="section-subtitle">Sequence of manufacturing, fabrication, or machining processes required for this part</div>
                </div>
              </div>

              <div style="overflow-x: auto;">
                <table class="structured-table" id="prcTable">
                  <thead>
                    <tr>
                      <th style="width: 50px; text-align: center;">#</th>
                      <th style="min-width: 280px;">PROCESS</th>
                      <th style="min-width: 250px;">STAGE DETAILS / REMARKS</th>
                      <th style="width: 100px; text-align: center;">ACTION</th>
                    </tr>
                  </thead>
                  <tbody id="prcTableBody">
                    <!-- Dynamic Process Rows -->
                  </tbody>
                </table>
              </div>
            </div>

            <!-- 4. Section: Output Variants -->
            <div class="section-box">
              <div class="section-header-row">
                <div>
                  <h2 class="section-heading">Output Variants</h2>
                  <div class="section-subtitle">Specify which finished goods (FG) variant types or output parts will be produced from this part</div>
                </div>
              </div>

              <div style="overflow-x: auto;">
                <table class="structured-table" id="outputVariantsTable">
                  <thead>
                    <tr>
                      <th style="width: 50px; text-align: center;">#</th>
                      <th style="width: 160px;">Output Part Code.</th>
                      <th style="min-width: 260px;">Output Part Name</th>
                      <th style="width: 130px;">Qty/Ratio</th>
                      <th style="width: 120px; text-align: center;">UOM</th>
                      <th style="width: 100px; text-align: center;">ACTION</th>
                    </tr>
                  </thead>
                  <tbody id="outputVariantsTableBody">
                    <!-- Dynamic Output Variant Rows -->
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Form Action Footer -->
            <div class="form-footer-actions">
              <a href="part-master.php" class="btn-cancel">Cancel</a>
              <button type="submit" class="btn-save-part" id="savePartBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;">
                  <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                  <polyline points="17 21 17 13 7 13 7 21"></polyline>
                  <polyline points="7 3 7 8 15 8"></polyline>
                </svg>
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
      const savePartBtn = document.getElementById('savePartBtn');
      const outputVariantsTableBody = document.getElementById('outputVariantsTableBody');
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
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
        } else if (type === 'error') {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
        } else {
          iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`;
        }

        toast.innerHTML = `
          <span style="display:inline-flex; width:20px; height:20px;">${iconSvg}</span>
          <span>${message}</span>
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

      // Re-index serial numbers (1, 2, 3, 4...) across table rows
      function reindexRows(tbody) {
        const rows = tbody.querySelectorAll('tr');
        rows.forEach((r, idx) => {
          const numCell = r.querySelector('.row-num');
          if (numCell) numCell.textContent = idx + 1;
        });
      }

      // Real-time duplicate check for Child Parts
      function checkDuplicateChildParts(triggeredSelect = null) {
        const rows = cpTableBody.querySelectorAll('tr');
        const codeMap = {};
        
        rows.forEach(row => {
          const sel = row.querySelector('.cp-select');
          if (sel) {
            const val = sel.value.trim().toLowerCase();
            if (val) {
              if (!codeMap[val]) codeMap[val] = [];
              codeMap[val].push(sel);
            }
          }
        });

        let hasDup = false;
        rows.forEach(row => {
          const sel = row.querySelector('.cp-select');
          if (sel) {
            const val = sel.value.trim().toLowerCase();
            if (val && codeMap[val] && codeMap[val].length > 1) {
              sel.classList.add('is-invalid');
              hasDup = true;
              if (triggeredSelect && sel === triggeredSelect) {
                showToast(`Child Part "${sel.value.trim()}" is already added. Same part cannot be selected multiple times!`, 'error');
              }
            } else if (val) {
              sel.classList.remove('is-invalid');
            }
          }
        });

        return hasDup;
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
          selectOptions += `<option value="${cp.part_code}" data-name="${cp.part_name}" data-spec="${cp.grade_spec || '-'}" data-uom="${cp.uom || 'NOS'}" ${isSel}>${cp.part_code} - ${cp.part_name}</option>`;
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

        // Update UOM dynamically when child part selection changes
        const selectEl = tr.querySelector('.cp-select');
        const uomBadge = tr.querySelector('.cp-uom');

        selectEl.addEventListener('change', () => {
          const opt = selectEl.options[selectEl.selectedIndex];
          const uom = (opt && opt.value) ? (opt.getAttribute('data-uom') || 'NOS') : 'NOS';
          uomBadge.textContent = uom;
          checkDuplicateChildParts(selectEl);
        });

        // Inline Add button (+) handler
        tr.querySelector('.btn-add-cp-inline').addEventListener('click', () => {
          addCpRow('', 1, tr);
        });

        // Inline Remove button (-) handler
        tr.querySelector('.btn-remove-cp').addEventListener('click', () => {
          if (cpTableBody.querySelectorAll('tr').length > 1) {
            tr.remove();
            reindexRows(cpTableBody);
            checkDuplicateChildParts();
          } else {
            // If only 1 row left, reset its fields
            selectEl.value = '';
            tr.querySelector('.cp-qty').value = 1;
            uomBadge.textContent = 'NOS';
            selectEl.classList.remove('is-invalid');
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

        // Update Stage details / remarks dynamically when process is selected
        const prcSelectEl = tr.querySelector('.prc-select');
        const remarksInput = tr.querySelector('.prc-remarks');

        prcSelectEl.addEventListener('change', () => {
          const opt = prcSelectEl.options[prcSelectEl.selectedIndex];
          const desc = (opt && opt.value) ? (opt.getAttribute('data-desc') || '') : '';
          if (remarksInput) {
            remarksInput.value = desc;
          }
        });

        // Inline Add button (+) handler
        tr.querySelector('.btn-add-prc-inline').addEventListener('click', () => {
          addPrcRow('', '', tr);
        });

        // Inline Remove button (-) handler
        tr.querySelector('.btn-remove-prc').addEventListener('click', () => {
          if (prcTableBody.querySelectorAll('tr').length > 1) {
            tr.remove();
            reindexRows(prcTableBody);
          } else {
            // Reset row
            prcSelectEl.value = '';
            tr.querySelector('.prc-remarks').value = '';
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

      // ==========================================
      // 3. OUTPUT VARIANTS TABLE ROWS LOGIC
      // ==========================================
      function addOutputVariantRow(code = '', name = '', qty = 1, uom = 'NOS', insertAfterEl = null) {
        const tr = document.createElement('tr');

        tr.innerHTML = `
          <td class="row-num" style="text-align: center; color: #475569; font-weight: 700;">1</td>
          <td>
            <input type="text" class="cell-input var-code" value="${escapeHtml(code)}" placeholder="e.g., FG-1001-A" required autocomplete="off">
          </td>
          <td>
            <input type="text" class="cell-input var-name" value="${escapeHtml(name)}" placeholder="e.g., Finished Assembly Variant A" required autocomplete="off">
          </td>
          <td>
            <input type="number" step="any" min="0.001" class="cell-input var-qty" value="${qty}" placeholder="1" required>
          </td>
          <td style="text-align: center;">
            <select class="cell-select var-uom" required>
              <option value="NOS" ${uom === 'NOS' ? 'selected' : ''}>NOS</option>
              <option value="SET" ${uom === 'SET' ? 'selected' : ''}>SET</option>
              <option value="PCS" ${uom === 'PCS' ? 'selected' : ''}>PCS</option>
              <option value="KG" ${uom === 'KG' ? 'selected' : ''}>KG</option>
              <option value="MTR" ${uom === 'MTR' ? 'selected' : ''}>MTR</option>
              <option value="SQM" ${uom === 'SQM' ? 'selected' : ''}>SQM</option>
              <option value="TON" ${uom === 'TON' ? 'selected' : ''}>TON</option>
              <option value="LTR" ${uom === 'LTR' ? 'selected' : ''}>LTR</option>
            </select>
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

        // Inline Add button (+) handler
        tr.querySelector('.btn-add-var-inline').addEventListener('click', () => {
          addOutputVariantRow('', '', 1, 'NOS', tr);
        });

        // Inline Remove button (-) handler
        tr.querySelector('.btn-remove-var').addEventListener('click', () => {
          if (outputVariantsTableBody.querySelectorAll('tr').length > 1) {
            tr.remove();
            reindexRows(outputVariantsTableBody);
            checkDuplicateVariantCodes();
          } else {
            // Reset row
            tr.querySelector('.var-code').value = '';
            tr.querySelector('.var-name').value = '';
            tr.querySelector('.var-qty').value = 1;
            tr.querySelector('.var-uom').value = 'NOS';
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
          addOutputVariantRow(ov.code || '', ov.name || '', ov.qty || 1, ov.uom || 'NOS');
        });
      } else {
        addOutputVariantRow('', '', 1, 'NOS');
      }

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
          const qtyInput = row.querySelector('.var-qty');
          const uomSelect = row.querySelector('.var-uom');

          const vCode = codeInput ? codeInput.value.trim() : '';
          const vName = nameInput ? nameInput.value.trim() : '';
          const vQty = qtyInput ? parseFloat(qtyInput.value) : 0;
          const vUom = uomSelect ? uomSelect.value : 'NOS';

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

          if (isNaN(vQty) || vQty <= 0) {
            showToast(`Please enter a valid Qty/Ratio (> 0) in Output Variant row #${rowNum}.`, 'error');
            if (qtyInput) {
              qtyInput.classList.add('is-invalid');
              qtyInput.focus();
            }
            return;
          }

          outputVariants.push({
            code: vCode,
            name: vName,
            qty: vQty,
            uom: vUom
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
