<?php
/**
 * WIP Management Portal - Dynamic Route & Stage Processing
 * Allows shop-floor operators to track and shift material through each part-specific manufacturing stage.
 * Each stage reports processed OK qty, rejection qty, child part consumption, operator name, and designation.
 * Final stage allocates completed output to the configured Output Variant(s).
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/rbac.php';

requirePermission('production', 'update');

$entryId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$mipNo = isset($_GET['mip']) ? trim($_GET['mip']) : '';
$stage = isset($_GET['stage']) ? intval($_GET['stage']) : 1;

header("Location: process.php?id=" . urlencode($entryId) . "&mip=" . urlencode($mipNo));
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Production Stage Routing & Tracking - WIP Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
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

    /* Overall Summary Card */
    .workflow-kpi-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px 24px;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
      margin-bottom: 20px;
    }

    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 16px;
      margin-bottom: 16px;
    }
    .kpi-item {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .kpi-lbl {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-sub);
    }
    .kpi-val {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-main);
    }

    /* Overall Progress Bar */
    .workflow-progress-wrap {
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 16px;
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .wf-progress-bar-bg {
      flex: 1;
      height: 10px;
      background: #e2e8f0;
      border-radius: 999px;
      overflow: hidden;
    }
    .wf-progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #2563eb, #10b981);
      border-radius: 999px;
      transition: width 0.4s ease;
      width: 0%;
    }
    .wf-progress-text {
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--text-main);
      min-width: 80px;
      text-align: right;
    }

    /* Dynamic Stepper Bar */
    .stages-stepper-wrap {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding: 4px 2px 12px 2px;
      margin-bottom: 22px;
      scrollbar-width: thin;
    }
    .stage-step-pill {
      flex: 1;
      min-width: 170px;
      background: #ffffff;
      border: 1.5px solid var(--border);
      border-radius: 10px;
      padding: 12px 14px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      position: relative;
    }
    .stage-step-pill:hover {
      border-color: #93c5fd;
      transform: translateY(-2px);
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .stage-step-pill.active {
      border-color: #2563eb;
      background: #eff6ff;
      box-shadow: 0 4px 10px rgba(37, 99, 235, 0.15);
    }
    .stage-step-pill.completed {
      border-color: #10b981;
      background: #f0fdf4;
    }
    .stage-step-pill.locked {
      background: #f8fafc;
      border-color: #e2e8f0;
      opacity: 0.65;
      cursor: not-allowed;
    }

    .step-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .step-num {
      font-size: 0.72rem;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 999px;
      background: #e2e8f0;
      color: #475569;
    }
    .stage-step-pill.active .step-num {
      background: #2563eb;
      color: #ffffff;
    }
    .stage-step-pill.completed .step-num {
      background: #10b981;
      color: #ffffff;
    }
    .step-badge {
      font-size: 0.7rem;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 4px;
    }
    .badge-ready { background: #dbeafe; color: #1e40af; }
    .badge-completed { background: #dcfce7; color: #166534; }
    .badge-in_progress { background: #fef3c7; color: #92400e; }
    .badge-locked { background: #f1f5f9; color: #64748b; }

    .step-title {
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--text-main);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .step-subtitle {
      font-size: 0.74rem;
      color: var(--text-sub);
    }

    /* Main Stage Processing Card */
    .stage-action-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 24px 28px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      margin-bottom: 24px;
    }

    .stage-header-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 14px;
      border-bottom: 1.5px solid #e2e8f0;
      margin-bottom: 20px;
      flex-wrap: wrap;
      gap: 12px;
    }
    .stage-heading {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .avail-input-chip {
      background: #eff6ff;
      border: 1.5px solid #bfdbfe;
      border-radius: 8px;
      padding: 6px 14px;
      font-size: 0.86rem;
      font-weight: 700;
      color: #1d4ed8;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px 24px;
    }
    @media (max-width: 768px) {
      .form-grid-2 { grid-template-columns: 1fr; }
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .form-group label {
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--text-main);
    }
    .form-control {
      height: 40px;
      padding: 8px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 0.88rem;
      color: var(--text-main);
      background: #ffffff;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
      box-sizing: border-box;
      width: 100%;
    }
    .form-control:focus {
      border-color: #2563eb;
      outline: none;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }

    /* Sub-sections */
    .section-divider-title {
      font-size: 0.82rem;
      font-weight: 800;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin: 22px 0 12px 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* Final Variant Box */
    .final-variant-box {
      background: linear-gradient(135deg, #fffbeb, #fef3c7);
      border: 1.5px solid #fde68a;
      border-radius: 10px;
      padding: 18px 20px;
      margin: 20px 0;
    }
    .final-variant-title {
      font-size: 0.92rem;
      font-weight: 800;
      color: #92400e;
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 6px;
    }
    .final-variant-desc {
      font-size: 0.82rem;
      color: #78350f;
      margin-bottom: 14px;
      line-height: 1.4;
    }

    /* Buttons */
    .btn-shift-stage {
      background: linear-gradient(135deg, #2563eb, #1d4ed8);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      padding: 10px 24px;
      font-size: 0.92rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
      box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
    }
    .btn-shift-stage:hover {
      background: linear-gradient(135deg, #1d4ed8, #1e40af);
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
    }
    .btn-complete-fg {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      padding: 10px 24px;
      font-size: 0.92rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
      box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
    }
    .btn-complete-fg:hover {
      background: linear-gradient(135deg, #059669, #047857);
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
    }

    /* Audit Log Table */
    .stage-history-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px 24px;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
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
        
        <!-- Pure Frontend UI Mode: Instant render without backend API dependency -->
        <div id="workflowContent">
          
          <!-- 1. Header Overview & KPI Card -->
          <div class="workflow-kpi-card">
            <div class="kpi-grid">
              <div class="kpi-item">
                <span class="kpi-lbl">MIP Number</span>
                <span class="kpi-val" id="kpiMipNo" style="color: var(--primary);">-</span>
              </div>
              <div class="kpi-item">
                <span class="kpi-lbl">Customer Name</span>
                <span class="kpi-val" id="kpiCustomer">-</span>
              </div>
              <div class="kpi-item">
                <span class="kpi-lbl">Part Name &amp; Code</span>
                <span class="kpi-val" id="kpiPartDesc">-</span>
              </div>
              <div class="kpi-item">
                <span class="kpi-lbl">Total Issued Qty</span>
                <span class="kpi-val" id="kpiTargetQty">-</span>
              </div>
              <div class="kpi-item">
                <span class="kpi-lbl">Current Stage</span>
                <span class="kpi-val" id="kpiActiveStage" style="color: #0284c7;">-</span>
              </div>
            </div>

            <!-- Overall Progress Bar with Percentage -->
            <div class="workflow-progress-wrap">
              <span style="font-size: 0.82rem; font-weight: 700; color: var(--text-sub); white-space: nowrap;">
                Production Tracking:
              </span>
              <div class="wf-progress-bar-bg">
                <div class="wf-progress-bar-fill" id="wfProgressFill"></div>
              </div>
              <span class="wf-progress-text" id="wfProgressText">0% Complete</span>
            </div>
          </div>

          <!-- 2. Dynamic Route / Stages Navigation Stepper -->
          <div style="margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.05em;">
              Part Stages &amp; Process Routes:
            </span>
            <span style="font-size: 0.74rem; color: var(--text-sub);">
              Click any stage to view material or shift to next stage
            </span>
          </div>

          <div class="stages-stepper-wrap" id="stagesStepperWrap">
            <!-- Dynamically populated step pills -->
          </div>

          <!-- 3. Stage Action Form Card -->
          <div class="stage-action-card" id="stageActionCard">
            
            <form id="stageProcessForm">
              <input type="hidden" id="formEntryId" value="<?php echo $entryId; ?>">
              <input type="hidden" id="formMipNo" value="<?php echo htmlspecialchars($mipNo); ?>">
              <input type="hidden" id="formStageOrder" value="1">
              <input type="hidden" id="formTotalStages" value="1">
              <input type="hidden" id="formProcessCode" value="">
              <input type="hidden" id="formProcessName" value="">
              <input type="hidden" id="formInputQty" value="0">
              <input type="hidden" id="formIsFinalStage" value="0">

              <div class="stage-header-row">
                <div>
                  <div class="stage-heading" id="displayStageTitle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    Stage 1: Process Name
                  </div>
                  <div style="font-size: 0.78rem; color: var(--text-sub); margin-top: 3px;" id="displayStageRemarks">
                    Stage Process Description
                  </div>
                </div>
              </div>

              <!-- Output Quantities -->
              <div class="section-divider-title" style="margin-top: 0;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                1. Stage Output &amp; Rework Reporting
              </div>

              <div class="form-grid-2">
                <div class="form-group">
                  <label for="inputProcessedQty">Processed Quantity (OK Pieces to Shift) *</label>
                  <input type="number" id="inputProcessedQty" class="form-control" step="any" min="0" placeholder="e.g., 20" required>
                  <small style="color: #64748b; font-size: 0.74rem;">Good pieces ready to advance to next stage.</small>
                </div>

                <div class="form-group">
                  <label for="inputRejectedQty">Rework Quantity (Defects to Rework)</label>
                  <input type="number" id="inputRejectedQty" class="form-control" step="any" min="0" value="0">
                  <small style="color: #64748b; font-size: 0.74rem;">Parts requiring rework/correction.</small>
                </div>
              </div>

              <!-- Child Parts Processing Table -->
              <div class="section-divider-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                2. Child Parts Processing &amp; Consumption (BOM)
              </div>

              <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 20px;">
                <table class="simple-table" style="font-size: 0.82rem; margin: 0; width: 100%;">
                  <thead style="background: #f8fafc;">
                    <tr>
                      <th style="padding: 8px 10px; width: 35px; text-align: center;">#</th>
                      <th style="padding: 8px 10px;">Child Part Code</th>
                      <th style="padding: 8px 10px;">Child Part Name</th>
                      <th style="padding: 8px 10px; text-align: center;">BOM Ratio</th>
                      <th style="padding: 8px 10px; text-align: center;">Available Qty</th>
                      <th style="padding: 8px 10px; text-align: center; width: 150px;">Child Processed Qty *</th>
                      <th style="padding: 8px 10px; text-align: center; width: 130px;">Child Rework Qty</th>
                      <th style="padding: 8px 10px; text-align: center;">UOM</th>
                    </tr>
                  </thead>
                  <tbody id="tblChildPartsProcessBody">
                    <!-- Populated dynamically via JS -->
                  </tbody>
                </table>
              </div>

              <!-- Operator & Designation -->
              <div class="section-divider-title">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                3. Operator &amp; Responsibility Details
              </div>

              <div class="form-grid-2">
                <div class="form-group">
                  <label for="inputOperatorName">Operator Name *</label>
                  <input type="text" id="inputOperatorName" class="form-control" value="<?php echo htmlspecialchars($currentUser['name'] ?? 'Operator'); ?>" placeholder="e.g., Ramesh Kumar" required>
                </div>

                <div class="form-group">
                  <label for="inputDesignation">Role / Designation *</label>
                  <input type="text" id="inputDesignation" class="form-control" value="<?php echo htmlspecialchars($currentUser['role'] ?? 'Operator'); ?>" placeholder="e.g., Operator / Supervisor" required>
                </div>
              </div>

              <div class="form-grid-2" style="margin-top: 14px;">
                <div class="form-group">
                  <label for="inputStationName">Station Name *</label>
                  <input type="text" id="inputStationName" class="form-control" value="Station 1" placeholder="e.g., Cutting Station 1" required>
                </div>
                <div class="form-group">
                  <label for="inputRemarks">Notes <span style="font-size: 0.74rem; color: #64748b; font-weight: normal;">(Optional)</span></label>
                  <input type="text" id="inputRemarks" class="form-control" placeholder="Optional notes regarding this shift or process run...">
                </div>
              </div>

              <!-- FINAL STAGE ONLY: Output Variant Allocation Box -->
              <div id="finalStageVariantBox" class="final-variant-box" style="display: none;">
                <div class="final-variant-title">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path></svg>
                  Final Stage Completion: Output Variant Allocation
                </div>
                <div class="final-variant-desc">
                  This is the final manufacturing stage. Completed units will become Finished Goods (FG) and must be allocated to an Output Variant.
                </div>

                <div class="form-grid-2">
                  <div class="form-group">
                    <label for="selectOutputVariant">Select Finished Goods (FG) Variant *</label>
                    <select id="selectOutputVariant" class="form-control">
                      <option value="">-- Select Output Variant --</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>Selected Variant Code &amp; Name</label>
                    <div id="variantDisplayPreview" style="height: 40px; display: flex; align-items: center; padding: 0 12px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 700; color: #1e293b;">
                      None Selected
                    </div>
                  </div>
                </div>
              </div>

              <!-- Action Submit Button -->
              <div style="margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px; align-items: center;">
                <span id="shiftStatusHint" style="font-size: 0.82rem; color: #64748b;"></span>
                <button type="submit" id="btnShiftMaterial" class="btn-shift-stage">
                  <span>Shift Material to Next Stage</span>
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </button>
              </div>

            </form>
          </div>

          <!-- 4. Stage Transfer Ledger & History Table -->
          <div class="stage-history-card">
            <div style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
              Stage Transfer &amp; Execution Ledger (Audit Trail)
            </div>

            <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 8px;">
              <table class="simple-table" style="font-size: 0.82rem; margin: 0; width: 100%;">
                <thead style="background: #f8fafc;">
                  <tr>
                    <th style="padding: 8px 12px; text-align: center;">#</th>
                    <th style="padding: 8px 12px;">Date &amp; Time</th>
                    <th style="padding: 8px 12px;">Stage &amp; Process</th>
                    <th style="padding: 8px 12px; text-align: center;">Input Qty</th>
                    <th style="padding: 8px 12px; text-align: center;">Processed OK</th>
                    <th style="padding: 8px 12px; text-align: center;">Rework</th>
                    <th style="padding: 8px 12px;">Operator (Designation)</th>
                    <th style="padding: 8px 12px;">Output Variant (Final)</th>
                  </tr>
                </thead>
                <tbody id="tblStageHistoryBody">
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 24px; color: var(--text-sub);">
                      No material transfer logs recorded yet. Complete Stage 1 to begin.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>

      </div>

    </div>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      
      const urlParams = new URLSearchParams(window.location.search);
      const paramId = urlParams.get('id') || '<?php echo $entryId; ?>' || '7';
      const paramMip = urlParams.get('mip') || '<?php echo htmlspecialchars($mipNo); ?>' || 'MIP-003';
      const initialStageParam = parseInt(urlParams.get('stage') || '1', 10);

      // Toast Notification Helper
      function showToast(msg, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = msg;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
      }

      // Frontend Mock Data Model (Pure Client-side, no backend dependencies)
      let currentWorkflow = {
        success: true,
        entry: {
          id: paramId,
          mip_no: paramMip,
          customer_name: 'Tata Motors',
          status: 'In Progress'
        },
        part: {
          part_code: 'VE003',
          part_name: 'Barcode Label',
          target_qty: 20,
          uom: 'NOS',
          output_variants: [
            { code: 'VAR-RED', name: 'Variant-Red' },
            { code: 'VAR-BLU', name: 'Variant-Blue' },
            { code: 'VAR-GRN', name: 'Variant-Green' }
          ]
        },
        active_stage_order: 2,
        progress_pct: 16.7,
        pipeline: [
          {
            stage_order: 1,
            total_stages: 6,
            process_code: '001',
            process_name: 'Cutting',
            remarks: 'Piece Cutting of RM .',
            input_available: 20,
            status: 'completed',
            processed_qty: 20,
            rejected_qty: 0,
            is_final_stage: false,
            child_parts: [
              { code: 'CH001', name: 'CHILD1', ratio: 3, uom: 'KG' },
              { code: 'CH002', name: 'Child2', ratio: 2, uom: 'KG' }
            ]
          },
          {
            stage_order: 2,
            total_stages: 6,
            process_code: '002',
            process_name: 'Melting',
            remarks: 'High temperature induction furnace melting',
            input_available: 40,
            status: 'in_progress',
            processed_qty: 0,
            rejected_qty: 0,
            is_final_stage: false,
            child_parts: [
              { code: 'CH001', name: 'CHILD1', ratio: 3, uom: 'KG' }
            ]
          },
          {
            stage_order: 3,
            total_stages: 6,
            process_code: '003',
            process_name: 'Impurity Extraction',
            remarks: 'Removal of Unnecessary particles & slag separation',
            input_available: 20,
            status: 'ready',
            processed_qty: 0,
            rejected_qty: 0,
            is_final_stage: false,
            child_parts: []
          },
          {
            stage_order: 4,
            total_stages: 6,
            process_code: '004',
            process_name: 'Shaping',
            remarks: 'Hydraulic press precision component shaping',
            input_available: 40,
            status: 'pending',
            processed_qty: 0,
            rejected_qty: 0,
            is_final_stage: false,
            child_parts: [
              { code: 'CH002', name: 'Child2', ratio: 2, uom: 'KG' }
            ]
          },
          {
            stage_order: 5,
            total_stages: 6,
            process_code: '005',
            process_name: 'QC inspection',
            remarks: 'Analyzing & Testing of Products for quality compliance',
            input_available: 40,
            status: 'pending',
            processed_qty: 0,
            rejected_qty: 0,
            is_final_stage: false,
            child_parts: []
          },
          {
            stage_order: 6,
            total_stages: 6,
            process_code: '006',
            process_name: 'Final FG',
            remarks: 'Storing Finished Goods, packaging & barcode labeling',
            input_available: 60,
            status: 'pending',
            processed_qty: 0,
            rejected_qty: 0,
            is_final_stage: true,
            child_parts: []
          }
        ],
        all_logs: [
          {
            id: 1,
            stage_order: 1,
            process_name: 'Cutting',
            input_qty: 20,
            processed_qty: 20,
            rejected_qty: 0,
            operator_name: 'Ramesh Kumar',
            designation: 'Machine Operator',
            variant_code: '',
            variant_name: '',
            created_at: new Date(Date.now() - 3600000).toLocaleString('en-IN', { hour12: true })
          }
        ]
      };

      let selectedStageOrder = (initialStageParam >= 1 && initialStageParam <= 6) ? initialStageParam : 2;

      // 1. Render Header KPIs & Progress Bar
      function renderHeaderKPIs() {
        if (!currentWorkflow) return;
        const e = currentWorkflow.entry;
        const p = currentWorkflow.part;

        document.getElementById('kpiMipNo').textContent = e.mip_no || '-';
        document.getElementById('kpiCustomer').textContent = e.customer_name || '-';
        document.getElementById('kpiPartDesc').textContent = `${p.part_code} - ${p.part_name}`;
        document.getElementById('kpiTargetQty').textContent = `${p.target_qty} ${p.uom}`;

        // Find active stage name
        const activeStage = currentWorkflow.pipeline.find(s => s.stage_order === currentWorkflow.active_stage_order);
        document.getElementById('kpiActiveStage').textContent = activeStage ? `Stage ${activeStage.stage_order}/${activeStage.total_stages}: ${activeStage.process_name}` : 'Completed';

        // Overall progress percentage
        const pct = currentWorkflow.progress_pct || 0;
        document.getElementById('wfProgressFill').style.width = `${pct}%`;
        document.getElementById('wfProgressText').textContent = `${pct}% Complete`;
      }

      // 2. Render Stepper
      function renderStepper() {
        const wrap = document.getElementById('stagesStepperWrap');
        if (!wrap || !currentWorkflow) return;
        wrap.innerHTML = '';

        currentWorkflow.pipeline.forEach(stg => {
          const pill = document.createElement('div');
          pill.className = `stage-step-pill ${stg.status}`;
          if (stg.stage_order === selectedStageOrder) {
            pill.classList.add('active');
          }

          let badgeText = 'Pending';
          if (stg.status === 'completed') badgeText = 'Done ✓';
          else if (stg.status === 'ready') badgeText = 'Ready';
          else if (stg.status === 'in_progress') badgeText = 'Active';
          else if (stg.status === 'locked') badgeText = 'Locked';

          let subText = `Avail: ${stg.input_available}`;
          if (stg.is_final_stage) {
            subText += ' (Final FG)';
          }

          pill.innerHTML = `
            <div class="step-top">
              <span class="step-num">${stg.stage_order}</span>
              <span class="step-badge badge-${stg.status}">${badgeText}</span>
            </div>
            <div class="step-title" title="${stg.process_name}">${stg.process_name}</div>
            <div class="step-subtitle">${subText}</div>
          `;

          pill.addEventListener('click', () => {
            selectedStageOrder = stg.stage_order;
            renderStepper();
            renderStageForm();
          });

          wrap.appendChild(pill);
        });
      }

      // 3. Render Active Stage Form
      function renderStageForm() {
        if (!currentWorkflow) return;
        const stg = currentWorkflow.pipeline.find(s => s.stage_order === selectedStageOrder);
        if (!stg) return;

        // Hidden fields
        document.getElementById('formEntryId').value = currentWorkflow.entry.id;
        document.getElementById('formMipNo').value = currentWorkflow.entry.mip_no;
        document.getElementById('formStageOrder').value = stg.stage_order;
        document.getElementById('formTotalStages').value = stg.total_stages;
        document.getElementById('formProcessCode').value = stg.process_code;
        document.getElementById('formProcessName').value = stg.process_name;
        document.getElementById('formInputQty').value = stg.input_available;
        document.getElementById('formIsFinalStage').value = stg.is_final_stage ? '1' : '0';

        // Header
        document.getElementById('displayStageTitle').innerHTML = `
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          Stage ${stg.stage_order} of ${stg.total_stages}: ${stg.process_name}
        `;
        document.getElementById('displayStageRemarks').textContent = stg.remarks || 'Standard manufacturing process stage';

        // Pre-fill default processed qty = remaining input
        const processedInput = document.getElementById('inputProcessedQty');
        const defaultQty = stg.input_available > 0 ? stg.input_available : 0;
        processedInput.value = defaultQty;
        processedInput.max = stg.input_available;
        document.getElementById('inputRejectedQty').value = '0';

        // Render Child Parts table
        renderChildPartsTable(stg, defaultQty);

        // Processed Qty input listener to live recalculate child parts
        processedInput.oninput = () => {
          const val = parseFloat(processedInput.value || 0);
          renderChildPartsTable(stg, val);
        };

        // Final Stage Variant Box
        const variantBox = document.getElementById('finalStageVariantBox');
        const submitBtn = document.getElementById('btnShiftMaterial');
        const hintSpan = document.getElementById('shiftStatusHint');

        if (stg.is_final_stage) {
          variantBox.style.display = 'block';
          populateVariantDropdown(currentWorkflow.part.output_variants || []);
          submitBtn.className = 'btn-complete-fg';
          submitBtn.innerHTML = `
            <span>Complete Final Stage &amp; Move to FG Store</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
          `;
          hintSpan.textContent = 'Final manufacturing output will be ready for FG Store';
        } else {
          variantBox.style.display = 'none';
          submitBtn.className = 'btn-shift-stage';
          const nextStageNum = stg.stage_order + 1;
          const nextStageObj = currentWorkflow.pipeline.find(s => s.stage_order === nextStageNum);
          const nextName = nextStageObj ? nextStageObj.process_name : `Stage ${nextStageNum}`;
          submitBtn.innerHTML = `
            <span>Shift Material to Stage ${nextStageNum}: ${nextName}</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
          `;
          hintSpan.textContent = `OK quantity will advance to ${nextName}`;
        }

        // Lock form if no input available
        if (stg.input_available <= 0) {
          submitBtn.disabled = true;
          submitBtn.style.opacity = '0.5';
          submitBtn.style.cursor = 'not-allowed';
          hintSpan.textContent = 'Waiting for previous stage material output';
        } else {
          submitBtn.disabled = false;
          submitBtn.style.opacity = '1';
          submitBtn.style.cursor = 'pointer';
        }
      }

      // Render Child Parts processing rows
      function renderChildPartsTable(stg, parentQty) {
        const tbody = document.getElementById('tblChildPartsProcessBody');
        if (!tbody) return;
        tbody.innerHTML = '';

        const cps = stg.child_parts || [];
        if (cps.length === 0) {
          tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding: 14px; color: #94a3b8; font-style: italic;">No child parts / BOM components linked to this part</td></tr>`;
          return;
        }

        cps.forEach((cp, idx) => {
          const ratioVal = parseFloat(cp.ratio ?? cp.qty ?? cp.quantity ?? cp.bom_ratio ?? 1);
          const bomRatio = (isNaN(ratioVal) || ratioVal <= 0) ? 1 : ratioVal;
          const req = parseFloat((bomRatio * parentQty).toFixed(3));
          const tr = document.createElement('tr');
          tr.setAttribute('data-cp-code', cp.code);
          tr.setAttribute('data-cp-name', cp.name);
          tr.setAttribute('data-cp-uom', cp.uom);

          tr.innerHTML = `
            <td style="text-align:center; color: var(--text-sub);">${idx + 1}</td>
            <td><strong>${cp.code}</strong></td>
            <td>${cp.name}</td>
            <td style="text-align:center;"><span style="background: #f1f5f9; padding: 2px 7px; border-radius: 4px; font-weight: 600;">1 : ${bomRatio}</span></td>
            <td style="text-align:center;"><strong style="color: #0284c7;">${req}</strong></td>
            <td style="text-align:center;">
              <input type="number" class="form-control cp-proc-qty" placeholder="0" step="any" min="0" value="" style="height: 32px; font-size: 0.8rem; text-align: center; font-weight: 600;">
            </td>
            <td style="text-align:center;">
              <input type="number" class="form-control cp-rej-qty" placeholder="0" step="any" min="0" value="" style="height: 32px; font-size: 0.8rem; text-align: center; font-weight: 600;">
            </td>
            <td style="text-align:center;"><span style="font-size: 0.76rem; color: #64748b;">${cp.uom}</span></td>
          `;
          tbody.appendChild(tr);
        });
      }

      // Populate Variant Dropdown
      function populateVariantDropdown(variants) {
        const sel = document.getElementById('selectOutputVariant');
        const preview = document.getElementById('variantDisplayPreview');
        if (!sel) return;
        sel.innerHTML = '<option value="">-- Select Output Variant --</option>';

        variants.forEach(v => {
          const opt = document.createElement('option');
          opt.value = v.code;
          opt.setAttribute('data-name', v.name);
          opt.textContent = `${v.code} - ${v.name}`;
          sel.appendChild(opt);
        });

        // Auto select first variant if only 1 exists
        if (variants.length === 1) {
          sel.selectedIndex = 1;
          preview.textContent = `${variants[0].code} - ${variants[0].name}`;
        }

        sel.onchange = () => {
          const opt = sel.selectedOptions[0];
          preview.textContent = opt && opt.value ? `${opt.value} - ${opt.getAttribute('data-name')}` : 'None Selected';
        };
      }

      // 4. Render Stage History Ledger
      function renderHistoryLedger() {
        const tbody = document.getElementById('tblStageHistoryBody');
        if (!tbody || !currentWorkflow) return;
        tbody.innerHTML = '';

        const logs = currentWorkflow.all_logs || [];
        if (logs.length === 0) {
          tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 24px; color: var(--text-sub);">No material transfer logs recorded yet. Complete Stage 1 to begin.</td></tr>`;
          return;
        }

        logs.forEach((l, idx) => {
          const tr = document.createElement('tr');
          const rejHtml = parseFloat(l.rejected_qty) > 0 ? `<strong style="color: #dc2626;">${l.rejected_qty}</strong>` : `<span style="color: #94a3b8;">-</span>`;
          const varHtml = l.variant_code ? `<strong>${l.variant_code}</strong> <span style="font-size:0.75rem; color:#64748b;">(${l.variant_name})</span>` : `<span style="color: #94a3b8;">-</span>`;

          tr.innerHTML = `
            <td style="text-align: center; color: var(--text-sub); font-weight: 600;">${idx + 1}</td>
            <td style="white-space: nowrap;">${l.created_at}</td>
            <td><strong style="color: #1e293b;">Stage ${l.stage_order}: ${l.process_name}</strong></td>
            <td style="text-align: center; font-variant-numeric: tabular-nums;">${l.input_qty}</td>
            <td style="text-align: center; font-variant-numeric: tabular-nums;"><strong style="color: #059669;">${l.processed_qty}</strong></td>
            <td style="text-align: center; font-variant-numeric: tabular-nums;">${rejHtml}</td>
            <td><strong>${l.operator_name}</strong> <span style="font-size: 0.75rem; color: #64748b;">(${l.designation})</span></td>
            <td>${varHtml}</td>
          `;
          tbody.appendChild(tr);
        });
      }

      // Form Submission (Shift Material in Pure Frontend Mode)
      const form = document.getElementById('stageProcessForm');
      form.addEventListener('submit', (e) => {
        e.preventDefault();

        const stageOrder = parseInt(document.getElementById('formStageOrder').value);
        const totalStages = parseInt(document.getElementById('formTotalStages').value);
        const processName = document.getElementById('formProcessName').value;
        const inputQty = parseFloat(document.getElementById('formInputQty').value || 0);
        const isFinal = document.getElementById('formIsFinalStage').value === '1';

        const processedQty = parseFloat(document.getElementById('inputProcessedQty').value || 0);
        const rejectedQty = parseFloat(document.getElementById('inputRejectedQty').value || 0);
        const operatorName = document.getElementById('inputOperatorName').value.trim();
        const designation = document.getElementById('inputDesignation').value.trim();
        const remarks = document.getElementById('inputRemarks').value.trim();

        if (processedQty <= 0 && rejectedQty <= 0) {
          showToast('Please enter Processed Qty or Rework Qty.', 'error');
          return;
        }

        if ((processedQty + rejectedQty) > (inputQty + 0.001)) {
          showToast(`Total output (${processedQty + rejectedQty}) cannot exceed input (${inputQty})!`, 'error');
          return;
        }

        // Final stage variant validation
        let variantCode = '';
        let variantName = '';
        if (isFinal) {
          const selVar = document.getElementById('selectOutputVariant');
          if (selVar && selVar.options.length > 1 && !selVar.value) {
            showToast('Please select an Output Variant for final stage completion.', 'error');
            selVar.focus();
            return;
          }
          if (selVar && selVar.value) {
            variantCode = selVar.value;
            variantName = selVar.selectedOptions[0].getAttribute('data-name') || '';
          }
        }

        // Update current stage status in frontend memory
        const currentStg = currentWorkflow.pipeline.find(s => s.stage_order === stageOrder);
        if (currentStg) {
          currentStg.status = 'completed';
          currentStg.processed_qty = processedQty;
          currentStg.rejected_qty = rejectedQty;
        }

        // Append log to ledger
        currentWorkflow.all_logs.unshift({
          id: currentWorkflow.all_logs.length + 1,
          stage_order: stageOrder,
          process_name: processName,
          input_qty: inputQty,
          processed_qty: processedQty,
          rejected_qty: rejectedQty,
          operator_name: operatorName || 'Operator',
          designation: designation || 'Staff',
          variant_code: variantCode,
          variant_name: variantName,
          created_at: new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true })
        });

        // Advance to next stage or finish
        if (!isFinal && stageOrder < totalStages) {
          const nextStg = currentWorkflow.pipeline.find(s => s.stage_order === stageOrder + 1);
          if (nextStg) {
            nextStg.input_available = processedQty;
            nextStg.status = 'in_progress';
          }
          currentWorkflow.active_stage_order = stageOrder + 1;
          selectedStageOrder = stageOrder + 1;
          showToast(`Stage ${stageOrder} completed! Shifted ${processedQty} to Stage ${stageOrder + 1}.`, 'success');
        } else {
          currentWorkflow.active_stage_order = totalStages;
          showToast(`Final stage complete! ${processedQty} units allocated to variant: ${variantName || 'Finished Goods'}.`, 'success');
        }

        // Recalculate progress percentage
        const completedCount = currentWorkflow.pipeline.filter(s => s.status === 'completed').length;
        currentWorkflow.progress_pct = Math.round((completedCount / totalStages) * 100);

        renderHeaderKPIs();
        renderStepper();
        renderStageForm();
        renderHistoryLedger();
      });

      // Initial Render
      renderHeaderKPIs();
      renderStepper();
      renderStageForm();
      renderHistoryLedger();

    });
  </script>
</body>
</html>
