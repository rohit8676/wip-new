<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Fetch real data from MSSQL database
$pdo = getDBConnection();
$rmItems = [];
$totalCount = 0;
$activeCount = 0;
$gradesSet = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, rm_code, rm_name, grade_spec, size_dimension, current_stock, uom, status FROM rm_master ORDER BY id DESC");
        $rmItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCount = count($rmItems);
        foreach ($rmItems as $it) {
            if (strcasecmp($it['status'] ?? '', 'active') === 0) {
                $activeCount++;
            }
            $g = trim($it['grade_spec'] ?? '');
            if (!empty($g) && $g !== '-' && !in_array(strtolower($g), $gradesSet)) {
                $gradesSet[] = strtolower($g);
            }
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}
$gradeCount = count($gradesSet);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RM Master - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Dashboard CSS -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
    /* Action Buttons in Table */
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
      background: #fef2f2;
      border-color: #ef4444;
    }

    /* =========================================================
       Toast Notification System (Matching Login Page)
       ========================================================= */
    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 10px;
      pointer-events: none;
    }

    .toast {
      min-width: 290px;
      max-width: 380px;
      padding: 13px 16px;
      border-radius: 8px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.05);
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.88rem;
      color: var(--text-main);
      pointer-events: auto;
      opacity: 0;
      transform: translateX(40px);
      transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .toast.show {
      opacity: 1;
      transform: translateX(0);
    }

    .toast.toast-success {
      border-left: 4px solid #10b981;
      color: #065f46;
    }

    .toast.toast-error {
      border-left: 4px solid #ef4444;
      color: #991b1b;
    }

    .toast.toast-info {
      border-left: 4px solid #2563eb;
      color: #1e40af;
    }

    .toast-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .toast-icon svg {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
    }

    .toast-message {
      flex: 1;
      font-size: 0.88rem;
      font-weight: 500;
      line-height: 1.4;
    }

    /* =========================================================
       Popup Modal Styles
       ========================================================= */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(15, 23, 42, 0.45);
      backdrop-filter: blur(3px);
      z-index: 100;
      align-items: center;
      justify-content: center;
      padding: 16px;
      box-sizing: border-box;
    }

    .modal-overlay.active {
      display: flex;
    }

    .modal-card {
      background: #ffffff;
      border-radius: 12px;
      width: 100%;
      max-width: 640px;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      border: 1px solid var(--border);
      animation: modalPopIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes modalPopIn {
      from {
        opacity: 0;
        transform: scale(0.96) translateY(8px);
      }
      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }

    .modal-header {
      padding: 16px 22px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #f8fafc;
    }

    .modal-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.01em;
    }

    .modal-close-btn {
      background: transparent;
      border: none;
      font-size: 1.6rem;
      line-height: 1;
      color: var(--text-sub);
      cursor: pointer;
      padding: 2px 6px;
      border-radius: 4px;
      transition: all 0.15s ease;
    }

    .modal-close-btn:hover {
      color: var(--text-main);
      background: #e2e8f0;
    }

    .modal-body {
      padding: 22px;
      max-height: calc(85vh - 130px);
      overflow-y: auto;
    }

    .popup-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    @media (max-width: 540px) {
      .popup-form-grid {
        grid-template-columns: 1fr;
      }
    }

    .rm-form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .rm-form-group label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .rm-form-group .form-control {
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

    .rm-form-group .form-control:focus {
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

    /* Delete Confirm Modal Specific */
    .confirm-modal-card {
      max-width: 420px !important;
      text-align: center;
      padding: 24px 20px;
    }

    .confirm-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: #fef2f2;
      color: #ef4444;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 14px;
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
      line-height: 1.5;
      margin-bottom: 22px;
    }

    .confirm-actions {
      display: flex;
      justify-content: center;
      gap: 12px;
    }

    .btn-danger {
      height: 38px;
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

    .btn-danger:hover {
      background: #dc2626;
    }

    .empty-row-msg {
      text-align: center;
      padding: 24px !important;
      color: var(--text-sub);
      font-style: italic;
    }
  </style>
</head>
<body>

  <!-- Toast Notification Container (Matching Login Page) -->
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
        
        <!-- Simple 3 Stats Cards (Live Database Counts) -->
        <div class="stats-row">
          <div class="simple-card stat-box">
            <div class="stat-number" id="statTotalRm"><?php echo $totalCount; ?></div>
            <div class="stat-title">Total RM Items</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statActiveRm"><?php echo $activeCount; ?></div>
            <div class="stat-title">Active Items</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statGradesRm"><?php echo $gradeCount; ?></div>
            <div class="stat-title">Material Grades</div>
          </div>
        </div>

        <!-- ==========================================
             Simple Table Card (Matching dashboard.php)
             ========================================== -->
        <div class="simple-card table-box">
          <div class="table-bar">
            <h2 class="box-title">Raw Material (RM) Master</h2>
            <div class="table-actions">
              <input type="text" id="rmSearch" class="simple-input" placeholder="Search RM Code, Name, Grade...">
              <?php if (hasPermission('rm_master', 'create')): ?>
              <button type="button" class="btn-primary" id="openAddRmModalBtn">+ Add RM</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-wrap">
            <table class="simple-table" id="rmTable">
              <thead>
                <tr>
                  <th>RM Code</th>
                  <th>RM Name</th>
                  <th>Grade / Specification</th>
                  <th>Size / Dimension</th>
                  <th>Current Stock</th>
                  <th>UOM</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="rmTableBody">
                <?php if (!empty($rmItems)): ?>
                  <?php foreach ($rmItems as $item): ?>
                    <?php 
                      $isActive = (strcasecmp($item['status'] ?? 'Active', 'Active') === 0);
                      $stockVal = floatval($item['current_stock'] ?? 0);
                      $dispStock = ($stockVal == (int)$stockVal) ? number_format($stockVal, 0) : rtrim(rtrim(number_format($stockVal, 3), '0'), '.');
                      $dataStockVal = ($stockVal == (int)$stockVal) ? (string)(int)$stockVal : rtrim(rtrim(number_format($stockVal, 3, '.', ''), '0'), '.');
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>" data-stock="<?php echo htmlspecialchars($dataStockVal); ?>">
                      <td><strong><?php echo htmlspecialchars($item['rm_code']); ?></strong></td>
                      <td><?php echo htmlspecialchars($item['rm_name']); ?></td>
                      <td><?php echo htmlspecialchars($item['grade_spec'] ?: '-'); ?></td>
                      <td><?php echo htmlspecialchars($item['size_dimension'] ?: '-'); ?></td>
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
                          <?php if (hasPermission('rm_master', 'update')): ?>
                          <button type="button" class="btn-edit" title="Edit Item">Edit</button>
                          <?php endif; ?>
                          <?php if (hasPermission('rm_master', 'delete')): ?>
                          <button type="button" class="btn-delete" title="Delete Item">Delete</button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr id="emptyTableRow">
                    <td colspan="8" class="empty-row-msg">No raw materials found in database. Click "+ Add RM" to create one.</td>
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
       Add / Edit RM Popup Modal Form
       ========================================== -->
  <div id="rmPopupModal" class="modal-overlay">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title" id="modalFormTitle">+ Add Raw Material (RM)</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="rmPopupForm">
        <div class="modal-body">
          <!-- Hidden field for edit item ID (database id) -->
          <input type="hidden" id="editItemId" value="">

          <div class="popup-form-grid">
            
            <!-- 1. RM Code -->
            <div class="rm-form-group">
              <label for="inputRmCode">RM Code *</label>
              <input type="text" id="inputRmCode" class="form-control" placeholder="e.g., RM-008" required>
            </div>

            <!-- 2. RM Name -->
            <div class="rm-form-group">
              <label for="inputRmName">RM Name *</label>
              <input type="text" id="inputRmName" class="form-control" placeholder="e.g., Carbon Steel Plate" required>
            </div>

            <!-- 3. Grade / Specification -->
            <div class="rm-form-group">
              <label for="inputGrade">Grade / Specification</label>
              <input type="text" id="inputGrade" class="form-control" placeholder="e.g., ASTM A36">
            </div>

            <!-- 4. Size / Dimension -->
            <div class="rm-form-group">
              <label for="inputSize">Size / Dimension</label>
              <input type="text" id="inputSize" class="form-control" placeholder="e.g., 5mm x 1500mm">
            </div>

            <!-- 5. UOM -->
            <div class="rm-form-group">
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

            <!-- 6. Current Stock -->
            <div class="rm-form-group">
              <label for="inputCurrentStock">Current Stock</label>
              <input type="number" step="any" min="0" id="inputCurrentStock" class="form-control" placeholder="0" value="0">
            </div>

            <!-- 7. Status -->
            <div class="rm-form-group">
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
          <button type="submit" class="btn-primary" id="saveRmSubmitBtn">+ Save Material</button>
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
      <p class="confirm-desc">Are you sure you want to delete material <strong id="deleteTargetCode"></strong> from the database?</p>
      <div class="confirm-actions">
        <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-danger" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>

  <!-- Dashboard JavaScript -->
  <script src="js/dashboard.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const rmSearch = document.getElementById('rmSearch');
      const rmTableBody = document.getElementById('rmTableBody');
      const toastContainer = document.getElementById('toastContainer');

      // Add/Edit Modal Elements
      const rmPopupModal = document.getElementById('rmPopupModal');
      const rmPopupForm = document.getElementById('rmPopupForm');
      const modalFormTitle = document.getElementById('modalFormTitle');
      const saveRmSubmitBtn = document.getElementById('saveRmSubmitBtn');
      const editItemId = document.getElementById('editItemId');
      const openAddRmModalBtn = document.getElementById('openAddRmModalBtn');
      const closeModalBtn = document.getElementById('closeModalBtn');
      const cancelModalBtn = document.getElementById('cancelModalBtn');

      // Delete Confirm Modal Elements
      const deleteConfirmModal = document.getElementById('deleteConfirmModal');
      const deleteTargetCode = document.getElementById('deleteTargetCode');
      const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
      const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

      let pendingDeleteId = null;
      let pendingDeleteCode = null;
      let pendingDeleteRow = null;

      const statTotalRm = document.getElementById('statTotalRm');
      const statActiveRm = document.getElementById('statActiveRm');
      const statGradesRm = document.getElementById('statGradesRm');

      // ==========================================
      // Toast Notification (Identical to Login page)
      // ==========================================
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

      // Update header statistics counters
      function updateCounters() {
        const rows = Array.from(rmTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        let total = rows.length;
        let active = 0;
        let grades = new Set();

        rows.forEach(r => {
          const cells = r.querySelectorAll('td');
          if (cells.length >= 7) {
            const gradeText = cells[2].textContent.trim();
            const statusText = cells[6].textContent.trim();
            if (statusText.toLowerCase().includes('active')) active++;
            if (gradeText && gradeText !== '-') grades.add(gradeText.toLowerCase());
          }
        });

        if (statTotalRm) statTotalRm.textContent = total;
        if (statActiveRm) statActiveRm.textContent = active;
        if (statGradesRm) statGradesRm.textContent = grades.size;

        const emptyRow = document.getElementById('emptyTableRow');
        if (total === 0) {
          if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.id = 'emptyTableRow';
            tr.innerHTML = `<td colspan="8" class="empty-row-msg">No raw materials found in database. Click "+ Add RM" to create one.</td>`;
            rmTableBody.appendChild(tr);
          }
        } else if (emptyRow) {
          emptyRow.remove();
        }
      }

      // Open Form Modal
      function openModal(title = '+ Add Raw Material (RM)', buttonText = '+ Save Material') {
        modalFormTitle.textContent = title;
        saveRmSubmitBtn.textContent = buttonText;
        rmPopupModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
          document.getElementById('inputRmCode').focus();
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
        rmPopupModal.classList.remove('active');
        document.body.style.overflow = '';
        rmPopupForm.reset();
        editItemId.value = '';
        document.getElementById('inputStatus').value = 'Active';
        document.getElementById('inputCurrentStock').value = '0';
      }

      // Open Add Modal
      if (openAddRmModalBtn) {
        openAddRmModalBtn.addEventListener('click', () => {
          rmPopupForm.reset();
          editItemId.value = '';
          document.getElementById('inputStatus').value = 'Active';
          document.getElementById('inputCurrentStock').value = '0';
          openModal('+ Add Raw Material (RM)', '+ Save Material');
        });
      }

      if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
      if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

      // Close when clicking outside modal
      if (rmPopupModal) {
        rmPopupModal.addEventListener('click', (e) => {
          if (e.target === rmPopupModal) closeModal();
        });
      }

      // Delete Confirm Modal Handlers
      function closeDeleteConfirmModal() {
        if (deleteConfirmModal) {
          deleteConfirmModal.classList.remove('active');
          document.body.style.overflow = '';
        }
        pendingDeleteId = null;
        pendingDeleteCode = null;
        pendingDeleteRow = null;
      }

      if (cancelDeleteBtn) cancelDeleteBtn.addEventListener('click', closeDeleteConfirmModal);

      if (deleteConfirmModal) {
        deleteConfirmModal.addEventListener('click', (e) => {
          if (e.target === deleteConfirmModal) closeDeleteConfirmModal();
        });
      }

      // Escape key to close any active modal
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          if (rmPopupModal && rmPopupModal.classList.contains('active')) closeModal();
          if (deleteConfirmModal && deleteConfirmModal.classList.contains('active')) closeDeleteConfirmModal();
        }
      });

      // Real-time table search filter
      if (rmSearch && rmTableBody) {
        rmSearch.addEventListener('input', () => {
          const q = rmSearch.value.toLowerCase().trim();
          const rows = rmTableBody.querySelectorAll('tr');
          rows.forEach(row => {
            if (row.id === 'emptyTableRow') return;
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
          });
        });
      }

      // Submit Form: Store real data in MSSQL Database via API
      if (rmPopupForm) {
        rmPopupForm.addEventListener('submit', async (e) => {
          e.preventDefault();

          const id = editItemId.value ? parseInt(editItemId.value, 10) : 0;
          const code = document.getElementById('inputRmCode').value.trim();
          const name = document.getElementById('inputRmName').value.trim();
          const grade = document.getElementById('inputGrade').value.trim() || '-';
          const size = document.getElementById('inputSize').value.trim() || '-';
          const uom = document.getElementById('inputUom').value;
          const currentStock = parseFloat(document.getElementById('inputCurrentStock').value || 0);
          const status = document.getElementById('inputStatus').value || 'Active';

          const action = id > 0 ? 'update' : 'create';

          // Disable submit button during request
          saveRmSubmitBtn.disabled = true;
          const originalBtnText = saveRmSubmitBtn.textContent;
          saveRmSubmitBtn.textContent = 'Saving...';

          try {
            const response = await fetch('api/rm_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: action,
                id: id,
                rm_code: code,
                rm_name: name,
                grade_spec: grade,
                size_dimension: size,
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
              saveRmSubmitBtn.disabled = false;
              saveRmSubmitBtn.textContent = originalBtnText;
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
              // Update row in table
              const row = rmTableBody.querySelector(`tr[data-id="${id}"]`);
              if (row) {
                row.setAttribute('data-stock', stockRawStr);
                row.children[0].innerHTML = `<strong>${code}</strong>`;
                row.children[1].textContent = name;
                row.children[2].textContent = grade;
                row.children[3].textContent = size;
                row.children[4].innerHTML = stockHtml;
                row.children[5].textContent = uom;
                row.children[6].innerHTML = activeTag;
                row.children[7].innerHTML = actionHtml;
              }
              showToast(`Material "${code}" updated successfully.`, 'success');
            } else {
              // Remove empty state message if exists
              const emptyRow = document.getElementById('emptyTableRow');
              if (emptyRow) emptyRow.remove();

              // Insert new row at top of table
              const newId = result.data?.id || Date.now();
              const tr = document.createElement('tr');
              tr.setAttribute('data-id', newId);
              tr.setAttribute('data-stock', stockRawStr);
              tr.innerHTML = `
                <td><strong>${code}</strong></td>
                <td>${name}</td>
                <td>${grade}</td>
                <td>${size}</td>
                <td>${stockHtml}</td>
                <td>${uom}</td>
                <td>${activeTag}</td>
                <td>${actionHtml}</td>
              `;
              rmTableBody.prepend(tr);
              showToast(`New Material "${code}" saved successfully.`, 'success');
            }

            closeModal();
            updateCounters();

          } catch (err) {
            showToast(err.message || 'Network or server error while saving data.', 'error');
          } finally {
            saveRmSubmitBtn.disabled = false;
            saveRmSubmitBtn.textContent = originalBtnText;
          }
        });
      }

      // Handle Edit and Delete on Table rows
      if (rmTableBody) {
        rmTableBody.addEventListener('click', (e) => {
          const row = e.target.closest('tr');
          if (!row || row.id === 'emptyTableRow') return;

          const itemId = row.getAttribute('data-id');

          // Edit Action
          if (e.target.classList.contains('btn-edit')) {
            const cells = row.children;
            const code = cells[0].textContent.trim();
            const name = cells[1].textContent.trim();
            const grade = cells[2].textContent.trim();
            const size = cells[3].textContent.trim();
            const rawStock = row.getAttribute('data-stock') || cells[4].textContent.trim().replace(/,/g, '');
            const numStock = parseFloat(rawStock || 0);
            const uom = cells[5].textContent.trim();
            const status = cells[6].textContent.trim().toLowerCase().includes('active') ? 'Active' : 'Inactive';

            document.getElementById('inputRmCode').value = code;
            document.getElementById('inputRmName').value = name;
            document.getElementById('inputGrade').value = grade === '-' ? '' : grade;
            document.getElementById('inputSize').value = size === '-' ? '' : size;
            document.getElementById('inputUom').value = uom;
            document.getElementById('inputCurrentStock').value = (numStock % 1 === 0) ? numStock.toString() : parseFloat(numStock.toFixed(3)).toString();
            document.getElementById('inputStatus').value = status;

            editItemId.value = itemId;
            openModal(`Edit Raw Material: ${code}`, 'Update Material');
          }

          // Delete Action: Show Confirm Popup Modal
          if (e.target.classList.contains('btn-delete')) {
            const code = row.children[0].textContent.trim();
            pendingDeleteId = itemId;
            pendingDeleteCode = code;
            pendingDeleteRow = row;

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
          if (!pendingDeleteId && !pendingDeleteCode) return;

          confirmDeleteBtn.disabled = true;
          confirmDeleteBtn.textContent = 'Deleting...';

          try {
            const response = await fetch('api/rm_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: 'delete',
                id: pendingDeleteId,
                rm_code: pendingDeleteCode
              })
            });

            const result = await response.json();

            if (response.ok && result.success) {
              if (pendingDeleteRow) pendingDeleteRow.remove();
              updateCounters();
              showToast(`Material "${pendingDeleteCode}" deleted successfully.`, 'success');
            } else {
              showToast(result.message || 'Failed to delete material from database.', 'error');
            }
          } catch (err) {
            showToast('Network error while deleting material.', 'error');
          } finally {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.textContent = 'Delete';
            closeDeleteConfirmModal();
          }
        });
      }

      updateCounters();
    });
  </script>
</body>
</html>
