<?php
/**
 * WIP Management Portal - Process Master
 */
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Fetch real data from MSSQL database
$pdo = getDBConnection();
$processes = [];
$totalCount = 0;
$activeCount = 0;
$inactiveCount = 0;

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, process_code, process_name, status, remarks FROM process_master ORDER BY id DESC");
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCount = count($processes);
        foreach ($processes as $p) {
            if (strcasecmp($p['status'] ?? '', 'active') === 0) {
                $activeCount++;
            } else {
                $inactiveCount++;
            }
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

$pageTitle = 'Process Master';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Process Master - WIP Management Portal</title>
  
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
      max-width: 540px;
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

    .process-form-grid {
      display: grid;
      grid-template-columns: 1fr 140px;
      gap: 14px;
    }

    @media (max-width: 520px) {
      .process-form-grid {
        grid-template-columns: 1fr;
      }
    }

    .prc-form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .prc-form-group label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .prc-form-group .form-control {
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

    .prc-form-group textarea.form-control {
      height: auto;
      min-height: 64px;
      padding: 8px 12px;
      resize: vertical;
    }

    .prc-form-group .form-control:focus {
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
            <div class="stat-number" id="statTotalPrc"><?php echo $totalCount; ?></div>
            <div class="stat-title">Total Processes</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statActivePrc"><?php echo $activeCount; ?></div>
            <div class="stat-title">Active Processes</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statInactivePrc"><?php echo $inactiveCount; ?></div>
            <div class="stat-title">Inactive / Standby</div>
          </div>
        </div>

        <!-- ==========================================
             Simple Table Card
             ========================================== -->
        <div class="simple-card table-box">
          <div class="table-bar">
            <h2 class="box-title">Process Directory</h2>
            <div class="table-actions">
              <input type="text" id="prcSearch" class="simple-input" placeholder="Search Process Code, Name, Remarks...">
              <?php if (hasPermission('process_master', 'create')): ?>
              <button type="button" class="btn-primary" id="openAddPrcModalBtn">+ Add Process</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-wrap">
            <table class="simple-table" id="prcTable">
              <thead>
                <tr>
                  <th style="width: 50px;">Sr No.</th>
                  <th style="width: 150px;">Process Code</th>
                  <th>Process Name</th>
                  <th style="width: 100px;">Status</th>
                  <th>Remarks</th>
                  <th style="width: 130px;">Action</th>
                </tr>
              </thead>
              <tbody id="prcTableBody">
                <?php if (!empty($processes)): ?>
                  <?php $sr = 1; ?>
                  <?php foreach ($processes as $item): ?>
                    <?php 
                      $isActive = (strcasecmp($item['status'] ?? 'Active', 'Active') === 0);
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>">
                      <td style="color: var(--text-sub); font-weight: 600;"><?php echo $sr++; ?></td>
                      <td><strong class="col-prc-code"><?php echo htmlspecialchars($item['process_code']); ?></strong></td>
                      <td class="col-prc-name"><?php echo htmlspecialchars($item['process_name']); ?></td>
                      <td class="col-prc-status">
                        <?php if ($isActive): ?>
                          <span class="tag tag-completed">Active</span>
                        <?php else: ?>
                          <span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td class="col-prc-remarks"><?php echo htmlspecialchars($item['remarks'] ?: '-'); ?></td>
                      <td>
                        <div style="display: flex; gap: 6px;">
                          <?php if (hasPermission('process_master', 'update')): ?>
                          <button type="button" class="btn-edit" title="Edit Process">Edit</button>
                          <?php endif; ?>
                          <?php if (hasPermission('process_master', 'delete')): ?>
                          <button type="button" class="btn-delete" title="Delete Process">Delete</button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr id="emptyTableRow">
                    <td colspan="6" class="empty-row-msg">No processes found. Click "+ Add Process" to create one.</td>
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
       Add / Edit Process Popup Modal
       ========================================== -->
  <div id="prcPopupModal" class="modal-overlay">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title" id="modalFormTitle">+ Add Process</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="prcPopupForm">
        <input type="hidden" id="editItemId" value="">

        <div class="modal-body">
          <div class="process-form-grid">
            
            <!-- 1. Process Code -->
            <div class="prc-form-group">
              <label for="inputProcessCode">Process Code *</label>
              <input type="text" id="inputProcessCode" class="form-control" placeholder="e.g., PRC-008" required>
            </div>

            <!-- 2. Status -->
            <div class="prc-form-group">
              <label for="inputStatus">Status *</label>
              <select id="inputStatus" class="form-control" required>
                <option value="Active" selected>Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>

            <!-- 3. Process Name -->
            <div class="prc-form-group" style="grid-column: 1 / -1;">
              <label for="inputProcessName">Process Name *</label>
              <input type="text" id="inputProcessName" class="form-control" placeholder="e.g., Deburring & Chamfering" required>
            </div>

            <!-- 4. Remarks -->
            <div class="prc-form-group" style="grid-column: 1 / -1;">
              <label for="inputRemarks">Remarks</label>
              <textarea id="inputRemarks" class="form-control" rows="2" placeholder="Optional notes, machine details, or operation instructions..."></textarea>
            </div>

          </div>
        </div>

        <!-- Action Buttons -->
        <div class="modal-footer">
          <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
          <button type="submit" class="btn-primary" id="savePrcSubmitBtn">+ Save Process</button>
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
      <p class="confirm-desc">Are you sure you want to delete process <strong id="deleteTargetCode" style="color:var(--text-main);"></strong>? This operation will be removed from the database.</p>
      <div class="confirm-actions">
        <button type="button" class="btn-secondary" id="cancelDeleteBtn">Cancel</button>
        <button type="button" class="btn-danger-confirm" id="confirmDeleteBtn">Yes, Delete</button>
      </div>
    </div>
  </div>

  <!-- Client-side Interactive Logic & Database API Integration -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // DOM Elements
      const prcTableBody = document.getElementById('prcTableBody');
      const prcSearch = document.getElementById('prcSearch');
      const prcPopupModal = document.getElementById('prcPopupModal');
      const prcPopupForm = document.getElementById('prcPopupForm');
      const openAddPrcModalBtn = document.getElementById('openAddPrcModalBtn');
      const closeModalBtn = document.getElementById('closeModalBtn');
      const cancelModalBtn = document.getElementById('cancelModalBtn');
      const modalFormTitle = document.getElementById('modalFormTitle');
      const savePrcSubmitBtn = document.getElementById('savePrcSubmitBtn');
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
      const statTotalPrc = document.getElementById('statTotalPrc');
      const statActivePrc = document.getElementById('statActivePrc');
      const statInactivePrc = document.getElementById('statInactivePrc');

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

      // Re-index Sr No. across all visible rows
      function reindexSrNo() {
        const rows = Array.from(prcTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        rows.forEach((r, idx) => {
          if (r.children[0]) {
            r.children[0].textContent = idx + 1;
          }
        });
      }

      // Update Statistics Counters
      function updateCounters() {
        const rows = Array.from(prcTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        let total = rows.length;
        let active = 0;
        let inactive = 0;

        rows.forEach(r => {
          const statusCell = r.querySelector('.col-prc-status');
          if (statusCell) {
            const statusText = statusCell.textContent.trim().toLowerCase();
            if (statusText.includes('active')) {
              active++;
            } else {
              inactive++;
            }
          }
        });

        if (statTotalPrc) statTotalPrc.textContent = total;
        if (statActivePrc) statActivePrc.textContent = active;
        if (statInactivePrc) statInactivePrc.textContent = inactive;

        const emptyRow = document.getElementById('emptyTableRow');
        if (total === 0) {
          if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.id = 'emptyTableRow';
            tr.innerHTML = `<td colspan="6" class="empty-row-msg">No processes found. Click "+ Add Process" to create one.</td>`;
            prcTableBody.appendChild(tr);
          }
        } else if (emptyRow) {
          emptyRow.remove();
        }
      }

      // Open Form Modal
      function openModal(title = '+ Add Process', buttonText = '+ Save Process') {
        modalFormTitle.textContent = title;
        savePrcSubmitBtn.textContent = buttonText;
        ptPopupModalActive(true);
        setTimeout(() => {
          document.getElementById('inputProcessCode').focus();
        }, 100);
      }

      function ptPopupModalActive(isActive) {
        if (isActive) {
          prcPopupModal.classList.add('active');
          document.body.style.overflow = 'hidden';
        } else {
          prcPopupModal.classList.remove('active');
          document.body.style.overflow = '';
        }
      }

      // Close Form Modal
      function closeModal() {
        ptPopupModalActive(false);
        prcPopupForm.reset();
        editItemId.value = '';
        document.getElementById('inputStatus').value = 'Active';
      }

      // Open Add Modal
      if (openAddPrcModalBtn) {
        openAddPrcModalBtn.addEventListener('click', () => {
          prcPopupForm.reset();
          editItemId.value = '';
          document.getElementById('inputStatus').value = 'Active';
          openModal('+ Add Process', '+ Save Process');
        });
      }

      if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
      if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

      // Close when clicking outside modal
      if (prcPopupModal) {
        prcPopupModal.addEventListener('click', (e) => {
          if (e.target === prcPopupModal) closeModal();
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
          if (prcPopupModal && prcPopupModal.classList.contains('active')) closeModal();
          if (deleteConfirmModal && deleteConfirmModal.classList.contains('active')) closeDeleteConfirmModal();
        }
      });

      // Real-time table search filter
      if (prcSearch && prcTableBody) {
        prcSearch.addEventListener('input', () => {
          const q = prcSearch.value.toLowerCase().trim();
          const rows = prcTableBody.querySelectorAll('tr');
          rows.forEach(row => {
            if (row.id === 'emptyTableRow') return;
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
          });
        });
      }

      // Submit Form: Save / Update to Database API
      if (prcPopupForm) {
        prcPopupForm.addEventListener('submit', async (e) => {
          e.preventDefault();

          const id = editItemId.value ? parseInt(editItemId.value, 10) : 0;
          const code = document.getElementById('inputProcessCode').value.trim();
          const name = document.getElementById('inputProcessName').value.trim();
          const remarks = document.getElementById('inputRemarks').value.trim();
          const status = document.getElementById('inputStatus').value || 'Active';

          if (!code || !name) {
            showToast('Please fill in Process Code and Process Name.', 'error');
            return;
          }

          savePrcSubmitBtn.disabled = true;
          const originalBtnText = savePrcSubmitBtn.textContent;
          savePrcSubmitBtn.textContent = 'Saving...';

          try {
            const payload = {
              action: id > 0 ? 'update' : 'create',
              id: id,
              process_code: code,
              process_name: name,
              status: status,
              remarks: remarks
            };

            const resp = await fetch('api/process_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload)
            });

            const res = await resp.json();

            if (!res.success) {
              showToast(res.message || 'Error saving process.', 'error');
              return;
            }

            const activeTag = status === 'Active' 
              ? '<span class="tag tag-completed">Active</span>' 
              : '<span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>';

            const remarksDisplay = remarks || '-';

            const actionHtml = `
              <div style="display: flex; gap: 6px;">
                <button type="button" class="btn-edit" title="Edit Process">Edit</button>
                <button type="button" class="btn-delete" title="Delete Process">Delete</button>
              </div>
            `;

            if (id > 0) {
              // Update existing row
              const row = prcTableBody.querySelector(`tr[data-id="${id}"]`);
              if (row) {
                row.querySelector('.col-prc-code').textContent = code;
                row.querySelector('.col-prc-name').textContent = name;
                row.querySelector('.col-prc-status').innerHTML = activeTag;
                row.querySelector('.col-prc-remarks').textContent = remarksDisplay;
              }
              showToast(`Process "${code}" updated successfully.`, 'success');
            } else {
              // Insert new row
              const emptyRow = document.getElementById('emptyTableRow');
              if (emptyRow) emptyRow.remove();

              const newId = res.data && res.data.id ? res.data.id : Date.now();
              const tr = document.createElement('tr');
              tr.setAttribute('data-id', newId);
              tr.innerHTML = `
                <td style="color: var(--text-sub); font-weight: 600;">1</td>
                <td><strong class="col-prc-code">${escapeHtml(code)}</strong></td>
                <td class="col-prc-name">${escapeHtml(name)}</td>
                <td class="col-prc-status">${activeTag}</td>
                <td class="col-prc-remarks">${escapeHtml(remarksDisplay)}</td>
                <td>${actionHtml}</td>
              `;
              prcTableBody.prepend(tr);
              reindexSrNo();
              showToast(`New Process "${code}" added successfully.`, 'success');
            }

            closeModal();
            updateCounters();
          } catch (err) {
            console.error(err);
            showToast('Network error while saving process.', 'error');
          } finally {
            savePrcSubmitBtn.disabled = false;
            savePrcSubmitBtn.textContent = originalBtnText;
          }
        });
      }

      // Handle Edit and Delete click on Table rows
      if (prcTableBody) {
        prcTableBody.addEventListener('click', (e) => {
          const row = e.target.closest('tr');
          if (!row || row.id === 'emptyTableRow') return;

          const itemId = row.getAttribute('data-id');

          // Edit Action
          if (e.target.classList.contains('btn-edit')) {
            const codeEl = row.querySelector('.col-prc-code');
            const nameEl = row.querySelector('.col-prc-name');
            const statusEl = row.querySelector('.col-prc-status');
            const remarksEl = row.querySelector('.col-prc-remarks');

            const code = codeEl ? codeEl.textContent.trim() : '';
            const name = nameEl ? nameEl.textContent.trim() : '';
            const status = (statusEl && statusEl.textContent.toLowerCase().includes('active')) ? 'Active' : 'Inactive';
            const remarks = (remarksEl && remarksEl.textContent.trim() !== '-') ? remarksEl.textContent.trim() : '';

            document.getElementById('inputProcessCode').value = code;
            document.getElementById('inputProcessName').value = name;
            document.getElementById('inputRemarks').value = remarks;
            document.getElementById('inputStatus').value = status;

            editItemId.value = itemId;
            openModal(`Edit Process: ${code}`, 'Update Process');
          }

          // Delete Action
          if (e.target.classList.contains('btn-delete')) {
            const codeEl = row.querySelector('.col-prc-code');
            const code = codeEl ? codeEl.textContent.trim() : 'Process';
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
            const resp = await fetch('api/process_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: 'delete',
                id: pendingDeleteId,
                process_code: pendingDeleteCode
              })
            });

            const res = await resp.json();

            if (!res.success) {
              showToast(res.message || 'Failed to delete process.', 'error');
              return;
            }

            const code = pendingDeleteCode || 'Process';
            pendingDeleteRow.remove();
            closeDeleteConfirmModal();
            reindexSrNo();
            updateCounters();
            showToast(`Process "${code}" deleted successfully.`, 'success');
          } catch (err) {
            console.error(err);
            showToast('Error deleting process from server.', 'error');
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
