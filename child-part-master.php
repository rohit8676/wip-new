<?php
/**
 * WIP Management Portal - Child Part Master
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Fetch real data from MSSQL database
$pdo = getDBConnection();
$childParts = [];
$totalCount = 0;
$activeCount = 0;
$gradesSet = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, part_code, part_name, grade_spec, size_dimension, nos_per_kg, current_stock, uom, status FROM child_part_master ORDER BY id DESC");
        $childParts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCount = count($childParts);
        foreach ($childParts as $cp) {
            if (strcasecmp($cp['status'] ?? '', 'active') === 0) {
                $activeCount++;
            }
            $g = trim($cp['grade_spec'] ?? '');
            if (!empty($g) && $g !== '-' && !in_array(strtolower($g), $gradesSet)) {
                $gradesSet[] = strtolower($g);
            }
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}
$gradeCount = count($gradesSet);

$pageTitle = 'RM & Child Part Master';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RM & Child Part Master - WIP Management Portal</title>
  
  <!-- Modern Clean Google Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="css/dashboard.css">

  <style>
    /* Table specific & utility styles */
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
      max-width: 680px;
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

    .popup-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px 18px;
    }

    @media (max-width: 620px) {
      .popup-form-grid {
        grid-template-columns: 1fr;
      }
    }

    .cp-form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .cp-form-group label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .cp-form-group .form-control {
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

    .cp-form-group .form-control:focus {
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
            <div class="stat-number" id="statTotalCp"><?php echo $totalCount; ?></div>
            <div class="stat-title">Total RM & Child Parts</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statActiveCp"><?php echo $activeCount; ?></div>
            <div class="stat-title">Active Items</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statGradesCp"><?php echo $gradeCount; ?></div>
            <div class="stat-title">Material Grades</div>
          </div>
        </div>

        <!-- ==========================================
             Simple Table Card
             ========================================== -->
        <div class="simple-card table-box">
          <div class="table-bar">
            <h2 class="box-title">RM & Child Part Directory</h2>
            <div class="table-actions">
              <input type="text" id="cpSearch" class="simple-input" placeholder="Search Code, Name, Grade, Size...">
              <?php if (hasPermission('child_part_master', 'create')): ?>
              <button type="button" class="btn-primary" id="openAddCpModalBtn">+ Add RM & Child Part</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-wrap">
            <table class="simple-table" id="cpTable">
              <thead>
                <tr>
                  <th style="width: 50px;">Sr No.</th>
                  <th>RM & Child Part Code</th>
                  <th>RM & Child Part Name</th>
                  <th>Grade / Specification</th>
                  <th>Size / Dimension</th>
                  <th>Nos Per K.g</th>
                  <th>Current Stock</th>
                  <th>UOM</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="cpTableBody">
                <?php if (!empty($childParts)): ?>
                  <?php $sr = 1; ?>
                  <?php foreach ($childParts as $item): ?>
                    <?php 
                      $isActive = (strcasecmp($item['status'] ?? 'Active', 'Active') === 0);
                      $stockVal = floatval($item['current_stock'] ?? 0);
                      $dispStock = ($stockVal == (int)$stockVal) ? number_format($stockVal, 0) : rtrim(rtrim(number_format($stockVal, 3), '0'), '.');
                      $dataStockVal = ($stockVal == (int)$stockVal) ? (string)(int)$stockVal : rtrim(rtrim(number_format($stockVal, 3, '.', ''), '0'), '.');
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>" data-stock="<?php echo htmlspecialchars($dataStockVal); ?>">
                      <td style="color: var(--text-sub); font-weight: 600;"><?php echo $sr++; ?></td>
                      <td><strong><?php echo htmlspecialchars($item['part_code']); ?></strong></td>
                      <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                      <td><?php echo htmlspecialchars($item['grade_spec'] ?: '-'); ?></td>
                      <td><?php echo htmlspecialchars($item['size_dimension'] ?: '-'); ?></td>
                      <td><?php echo htmlspecialchars($item['nos_per_kg'] ?: '-'); ?></td>
                      <td>
                        <span class="stock-badge" style="display:inline-block; font-weight:700; color:#0f172a; background:#f1f5f9; padding:3px 9px; border-radius:5px; border:1px solid #e2e8f0; font-variant-numeric:tabular-nums;">
                          <?php echo htmlspecialchars($dispStock); ?>
                        </span>
                      </td>
                      <td><?php echo htmlspecialchars($item['uom']); ?></td>
                      <td>
                        <?php if ($isActive): ?>
                          <span class="tag tag-completed">Active</span>
                        <?php else: ?>
                          <span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div style="display: flex; gap: 6px;">
                          <?php if (hasPermission('child_part_master', 'update')): ?>
                          <button type="button" class="btn-edit" title="Edit Item">Edit</button>
                          <?php endif; ?>
                          <?php if (hasPermission('child_part_master', 'delete')): ?>
                          <button type="button" class="btn-delete" title="Delete Item">Delete</button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr id="emptyTableRow">
                    <td colspan="10" class="empty-row-msg">No RM & child parts found. Click "+ Add RM & Child Part" to create one.</td>
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
       Add / Edit RM & Child Part Popup Modal
       ========================================== -->
  <div id="cpPopupModal" class="modal-overlay">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title" id="modalFormTitle">+ Add RM & Child Part</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="cpPopupForm">
        <input type="hidden" id="editItemId" value="">

        <div class="modal-body">
          <div class="popup-form-grid">
            
            <!-- 1. RM & Child Part Code -->
            <div class="cp-form-group">
              <label for="inputPartCode">RM & Child Part Code *</label>
              <input type="text" id="inputPartCode" class="form-control" placeholder="e.g., CP-1006" required>
            </div>

            <!-- 2. RM & Child Part Name -->
            <div class="cp-form-group">
              <label for="inputPartName">RM & Child Part Name *</label>
              <input type="text" id="inputPartName" class="form-control" placeholder="e.g., Mounting Flange Plate" required>
            </div>

            <!-- 3. Grade / Specification -->
            <div class="cp-form-group">
              <label for="inputGrade">Grade / Specification</label>
              <input type="text" id="inputGrade" class="form-control" placeholder="e.g., IS 513 CR2">
            </div>

            <!-- 4. Size / Dimension -->
            <div class="cp-form-group">
              <label for="inputSize">Size / Dimension</label>
              <input type="text" id="inputSize" class="form-control" placeholder="e.g., 120 x 85 mm">
            </div>

            <!-- 5. Nos Per K.g -->
            <div class="cp-form-group">
              <label for="inputNosPerKg">Nos Per K.g</label>
              <input type="number" step="any" min="0" id="inputNosPerKg" class="form-control" placeholder="e.g., 14.50">
            </div>

            <!-- 6. UOM -->
            <div class="cp-form-group">
              <label for="inputUom">UOM *</label>
              <select id="inputUom" class="form-control" required>
                <option value="">-- Select UOM --</option>
                <option value="NOS">NOS</option>
                <option value="SET">SET</option>
                <option value="PCS">PCS</option>
                <option value="KG">KG</option>
                <option value="MTR">MTR</option>
                <option value="SQM">SQM</option>
                <option value="TON">TON</option>
                <option value="LTR">LTR</option>
              </select>
            </div>

            <!-- 7. Current Stock -->
            <div class="cp-form-group">
              <label for="inputCurrentStock">Current Stock</label>
              <input type="number" step="any" min="0" id="inputCurrentStock" class="form-control" placeholder="0" value="0">
            </div>

            <!-- 8. Status -->
            <div class="cp-form-group">
              <label for="inputStatus">Status *</label>
              <select id="inputStatus" class="form-control" required>
                <option value="Active" selected>Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>

          </div>
        </div>

        <!-- Action Buttons -->
        <div class="modal-footer">
          <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
          <button type="submit" class="btn-primary" id="saveCpSubmitBtn">+ Save RM & Child Part</button>
        </div>
      </form>
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
      <p class="confirm-desc">Are you sure you want to delete RM & child part <strong id="deleteTargetCode" style="color:var(--text-main);"></strong>? This item will be removed from the list.</p>
      <div class="confirm-actions">
        <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-danger-confirm" id="confirmDeleteBtn">Yes, Delete</button>
      </div>
    </div>
  </div>

  <!-- Client-side Interactive Logic (UI Only) -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // DOM Elements
      const cpTableBody = document.getElementById('cpTableBody');
      const cpSearch = document.getElementById('cpSearch');
      const cpPopupModal = document.getElementById('cpPopupModal');
      const cpPopupForm = document.getElementById('cpPopupForm');
      const openAddCpModalBtn = document.getElementById('openAddCpModalBtn');
      const closeModalBtn = document.getElementById('closeModalBtn');
      const cancelModalBtn = document.getElementById('cancelModalBtn');
      const modalFormTitle = document.getElementById('modalFormTitle');
      const saveCpSubmitBtn = document.getElementById('saveCpSubmitBtn');
      const editItemId = document.getElementById('editItemId');
      const toastContainer = document.getElementById('toastContainer');

      // Delete Modal Elements
      const deleteConfirmModal = document.getElementById('deleteConfirmModal');
      const deleteTargetCode = document.getElementById('deleteTargetCode');
      const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

      let pendingDeleteId = null;
      let pendingDeleteRow = null;
      let pendingDeleteCode = null;

      // Stats Elements
      const statTotalCp = document.getElementById('statTotalCp');
      const statActiveCp = document.getElementById('statActiveCp');
      const statGradesCp = document.getElementById('statGradesCp');

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

      // Re-index Sr No. across all visible rows
      function reindexSrNo() {
        const rows = Array.from(cpTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        rows.forEach((r, idx) => {
          if (r.children[0]) {
            r.children[0].textContent = idx + 1;
          }
        });
      }

      // Update Statistics Counters
      function updateCounters() {
        const rows = Array.from(cpTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        let total = rows.length;
        let active = 0;
        let grades = new Set();

        rows.forEach(r => {
          const cells = r.querySelectorAll('td');
          if (cells.length >= 9) {
            const gradeText = cells[3].textContent.trim();
            const statusText = cells[8].textContent.trim();
            if (statusText.toLowerCase().includes('active')) active++;
            if (gradeText && gradeText !== '-') grades.add(gradeText.toLowerCase());
          }
        });

        if (statTotalCp) statTotalCp.textContent = total;
        if (statActiveCp) statActiveCp.textContent = active;
        if (statGradesCp) statGradesCp.textContent = grades.size;

        const emptyRow = document.getElementById('emptyTableRow');
        if (total === 0) {
          if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.id = 'emptyTableRow';
            tr.innerHTML = `<td colspan="10" class="empty-row-msg">No RM & child parts found. Click "+ Add RM & Child Part" to create one.</td>`;
            cpTableBody.appendChild(tr);
          }
        } else if (emptyRow) {
          emptyRow.remove();
        }
      }

      // Open Form Modal
      function openModal(title = '+ Add RM & Child Part', buttonText = '+ Save RM & Child Part') {
        modalFormTitle.textContent = title;
        saveCpSubmitBtn.textContent = buttonText;
        cpPopupModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
          document.getElementById('inputPartCode').focus();
        }, 100);
      }

      // Helper to format stock (only show decimal if fractional)
      function formatStock(val) {
        const num = parseFloat(val || 0);
        if (isNaN(num)) return '0';
        if (num % 1 === 0) {
          return num.toLocaleString('en-US');
        }
        return num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
      }

      // Close Form Modal
      function closeModal() {
        cpPopupModal.classList.remove('active');
        document.body.style.overflow = '';
        cpPopupForm.reset();
        editItemId.value = '';
        document.getElementById('inputStatus').value = 'Active';
        document.getElementById('inputCurrentStock').value = '0';
      }

      // Open Add Modal
      if (openAddCpModalBtn) {
        openAddCpModalBtn.addEventListener('click', () => {
          cpPopupForm.reset();
          editItemId.value = '';
          document.getElementById('inputStatus').value = 'Active';
          document.getElementById('inputCurrentStock').value = '0';
          openModal('+ Add RM & Child Part', '+ Save RM & Child Part');
        });
      }

      if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
      if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

      // Close when clicking outside modal
      if (cpPopupModal) {
        cpPopupModal.addEventListener('click', (e) => {
          if (e.target === cpPopupModal) closeModal();
        });
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

      // Escape key to close modals
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          if (cpPopupModal && cpPopupModal.classList.contains('active')) closeModal();
          if (deleteConfirmModal && deleteConfirmModal.classList.contains('active')) closeDeleteConfirmModal();
        }
      });

      // Real-time table search filter
      if (cpSearch && cpTableBody) {
        cpSearch.addEventListener('input', () => {
          const q = cpSearch.value.toLowerCase().trim();
          const rows = cpTableBody.querySelectorAll('tr');
          rows.forEach(row => {
            if (row.id === 'emptyTableRow') return;
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
          });
        });
      }

      // Submit Form: Store real data in MSSQL Database via API
      if (cpPopupForm) {
        cpPopupForm.addEventListener('submit', async (e) => {
          e.preventDefault();

          const id = editItemId.value ? parseInt(editItemId.value, 10) : 0;
          const code = document.getElementById('inputPartCode').value.trim();
          const name = document.getElementById('inputPartName').value.trim();
          const grade = document.getElementById('inputGrade').value.trim() || '-';
          const size = document.getElementById('inputSize').value.trim() || '-';
          const nosPerKg = document.getElementById('inputNosPerKg').value.trim() || '-';
          const uom = document.getElementById('inputUom').value;
          const currentStock = parseFloat(document.getElementById('inputCurrentStock').value || 0);
          const status = document.getElementById('inputStatus').value || 'Active';

          const action = id > 0 ? 'update' : 'create';

          saveCpSubmitBtn.disabled = true;
          const originalBtnText = saveCpSubmitBtn.textContent;
          saveCpSubmitBtn.textContent = 'Saving...';

          try {
            const response = await fetch('api/child_part_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: action,
                id: id,
                part_code: code,
                part_name: name,
                grade_spec: grade,
                size_dimension: size,
                nos_per_kg: nosPerKg,
                uom: uom,
                current_stock: currentStock,
                status: status
              })
            });

            let result;
            try {
              result = await response.json();
            } catch (jsonErr) {
              const rawText = await response.text().catch(() => '');
              throw new Error(rawText ? `Server returned non-JSON: ${rawText.slice(0, 100)}` : 'Invalid response from server.');
            }

            if (!response.ok || !result.success) {
              showToast(result.message || 'Error saving to database.', 'error');
              saveCpSubmitBtn.disabled = false;
              saveCpSubmitBtn.textContent = originalBtnText;
              return;
            }

            const activeTag = status === 'Active' 
              ? '<span class="tag tag-completed">Active</span>' 
              : '<span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>';

            const stockFormatted = formatStock(currentStock);
            const stockRawStr = (currentStock % 1 === 0) ? currentStock.toString() : parseFloat(currentStock.toFixed(3)).toString();

            const stockHtml = `
              <span class="stock-badge" style="display:inline-block; font-weight:700; color:#0f172a; background:#f1f5f9; padding:3px 9px; border-radius:5px; border:1px solid #e2e8f0; font-variant-numeric:tabular-nums;">
                ${stockFormatted}
              </span>
            `;

            const actionHtml = `
              <div style="display: flex; gap: 6px;">
                <button type="button" class="btn-edit" title="Edit Item">Edit</button>
                <button type="button" class="btn-delete" title="Delete Item">Delete</button>
              </div>
            `;

            if (id > 0) {
              // Update existing row
              const row = cpTableBody.querySelector(`tr[data-id="${id}"]`);
              if (row) {
                row.setAttribute('data-stock', stockRawStr);
                row.children[1].innerHTML = `<strong>${code}</strong>`;
                row.children[2].textContent = name;
                row.children[3].textContent = grade;
                row.children[4].textContent = size;
                row.children[5].textContent = nosPerKg;
                row.children[6].innerHTML = stockHtml;
                row.children[7].textContent = uom;
                row.children[8].innerHTML = activeTag;
                row.children[9].innerHTML = actionHtml;
              }
              showToast(`RM & Child Part "${code}" updated successfully.`, 'success');
            } else {
              // Create new row
              const emptyRow = document.getElementById('emptyTableRow');
              if (emptyRow) emptyRow.remove();

              const newId = result.data?.id || Date.now();
              const tr = document.createElement('tr');
              tr.setAttribute('data-id', newId);
              tr.setAttribute('data-stock', stockRawStr);
              tr.innerHTML = `
                <td style="color: var(--text-sub); font-weight: 600;">1</td>
                <td><strong>${code}</strong></td>
                <td>${name}</td>
                <td>${grade}</td>
                <td>${size}</td>
                <td>${nosPerKg}</td>
                <td>${stockHtml}</td>
                <td>${uom}</td>
                <td>${activeTag}</td>
                <td>${actionHtml}</td>
              `;
              cpTableBody.prepend(tr);
              reindexSrNo();
              showToast(`New RM & Child Part "${code}" saved successfully.`, 'success');
            }

            closeModal();
            updateCounters();

          } catch (err) {
            showToast(err.message || 'Network or server error while saving data.', 'error');
          } finally {
            saveCpSubmitBtn.disabled = false;
            saveCpSubmitBtn.textContent = originalBtnText;
          }
        });
      }

      // Handle Edit and Delete click on Table rows
      if (cpTableBody) {
        cpTableBody.addEventListener('click', (e) => {
          const row = e.target.closest('tr');
          if (!row || row.id === 'emptyTableRow') return;

          const itemId = row.getAttribute('data-id');

          // Edit Action
          if (e.target.classList.contains('btn-edit')) {
            const cells = row.children;
            const code = cells[1].textContent.trim();
            const name = cells[2].textContent.trim();
            const grade = cells[3].textContent.trim();
            const size = cells[4].textContent.trim();
            const nosPerKg = cells[5].textContent.trim();
            const rawStock = row.getAttribute('data-stock') || cells[6].textContent.trim().replace(/,/g, '');
            const numStock = parseFloat(rawStock || 0);
            const uom = cells[7].textContent.trim();
            const status = cells[8].textContent.trim().toLowerCase().includes('active') ? 'Active' : 'Inactive';

            document.getElementById('inputPartCode').value = code;
            document.getElementById('inputPartName').value = name;
            document.getElementById('inputGrade').value = grade === '-' ? '' : grade;
            document.getElementById('inputSize').value = size === '-' ? '' : size;
            document.getElementById('inputNosPerKg').value = nosPerKg === '-' ? '' : nosPerKg;
            document.getElementById('inputUom').value = uom;
            document.getElementById('inputCurrentStock').value = (numStock % 1 === 0) ? numStock.toString() : parseFloat(numStock.toFixed(3)).toString();
            document.getElementById('inputStatus').value = status;

            editItemId.value = itemId;
            openModal(`Edit RM & Child Part: ${code}`, 'Update RM & Child Part');
          }

          // Delete Action
          if (e.target.classList.contains('btn-delete')) {
            const code = row.children[1].textContent.trim();
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

      // Confirm Delete Action
      if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', async () => {
          if (!pendingDeleteRow) return;

          const code = pendingDeleteCode || 'Item';
          confirmDeleteBtn.disabled = true;
          confirmDeleteBtn.textContent = 'Deleting...';

          try {
            const response = await fetch('api/child_part_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: 'delete',
                id: pendingDeleteId,
                part_code: pendingDeleteCode
              })
            });

            const result = await response.json();

            if (response.ok && result.success) {
              pendingDeleteRow.remove();
              reindexSrNo();
              updateCounters();
              showToast(`RM & Child Part "${code}" deleted successfully.`, 'success');
            } else {
              showToast(result.message || 'Failed to delete RM & child part from database.', 'error');
            }
          } catch (err) {
            showToast('Network error while deleting RM & child part.', 'error');
          } finally {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.textContent = 'Yes, Delete';
            closeDeleteConfirmModal();
          }
        });
      }

    });
  </script>
</body>
</html>
