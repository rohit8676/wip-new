<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Fetch real data from MSSQL database
$pdo = getDBConnection();
$vendorItems = [];
$totalCount = 0;
$activeCount = 0;
$addressesCount = 0;
$uniqueAddresses = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, vendor_code, vendor_name, contact_person, phone, email, gstin, address, status FROM vendor_master ORDER BY id DESC");
        $vendorItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCount = count($vendorItems);
        foreach ($vendorItems as $v) {
            if (strcasecmp($v['status'] ?? '', 'active') === 0) {
                $activeCount++;
            }
            $addr = trim($v['address'] ?? '');
            if (!empty($addr) && !in_array(strtolower($addr), $uniqueAddresses)) {
                $uniqueAddresses[] = strtolower($addr);
            }
        }
        $addressesCount = count($uniqueAddresses);
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
  <title>Vendor Master - WIP Management Portal</title>

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

    /* Modal styles */
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

    .vm-form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .vm-form-group label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .vm-form-group .form-control {
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

    .vm-form-group .form-control:focus {
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
            <div class="stat-number" id="statTotalVendor"><?php echo $totalCount; ?></div>
            <div class="stat-title">Total Vendors</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number completed" id="statActiveVendor"><?php echo $activeCount; ?></div>
            <div class="stat-title">Active Vendors</div>
          </div>

          <div class="simple-card stat-box">
            <div class="stat-number in-progress" id="statAddressesVendor"><?php echo $addressesCount; ?></div>
            <div class="stat-title">Locations / Addresses</div>
          </div>
        </div>

        <!-- ==========================================
             Simple Table Card (Matching dashboard.php)
             ========================================== -->
        <div class="simple-card table-box">
          <div class="table-bar">
            <h2 class="box-title">Vendor Directory</h2>
            <div class="table-actions">
              <input type="text" id="vendorSearch" class="simple-input" placeholder="Search Code, Name, Address, GSTIN...">
              <?php if (hasPermission('vendor_master', 'create')): ?>
              <button type="button" class="btn-primary" id="openAddVendorModalBtn">+ Add Vendor</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-wrap">
            <table class="simple-table" id="vendorTable">
              <thead>
                <tr>
                  <th>Vendor Code</th>
                  <th>Vendor Name</th>
                  <th>Contact Person</th>
                  <th>Phone</th>
                  <th>Email</th>
                  <th>GSTIN</th>
                  <th>Address</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="vendorTableBody">
                <?php if (!empty($vendorItems)): ?>
                  <?php foreach ($vendorItems as $item): ?>
                    <?php 
                      $isActive = (strcasecmp($item['status'] ?? 'Active', 'Active') === 0);
                    ?>
                    <tr data-id="<?php echo htmlspecialchars($item['id']); ?>">
                      <td><strong><?php echo htmlspecialchars($item['vendor_code']); ?></strong></td>
                      <td><?php echo htmlspecialchars($item['vendor_name']); ?></td>
                      <td><?php echo htmlspecialchars($item['contact_person'] ?: '-'); ?></td>
                      <td><?php echo htmlspecialchars($item['phone'] ?: '-'); ?></td>
                      <td><?php echo htmlspecialchars($item['email'] ?: '-'); ?></td>
                      <td><span style="font-family: monospace; font-size: 0.82rem;"><?php echo htmlspecialchars($item['gstin'] ?: '-'); ?></span></td>
                      <td><?php echo htmlspecialchars($item['address'] ?: '-'); ?></td>
                      <td>
                        <?php if ($isActive): ?>
                          <span class="tag tag-completed">Active</span>
                        <?php else: ?>
                          <span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div style="display: flex; gap: 6px;">
                          <?php if (hasPermission('vendor_master', 'update')): ?>
                          <button type="button" class="btn-edit" title="Edit Vendor">Edit</button>
                          <?php endif; ?>
                          <?php if (hasPermission('vendor_master', 'delete')): ?>
                          <button type="button" class="btn-delete" title="Delete Vendor">Delete</button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr id="emptyTableRow">
                    <td colspan="9" class="empty-row-msg">No vendors found in database. Click "+ Add Vendor" to create one.</td>
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
       Add / Edit Vendor Popup Modal Form
       ========================================== -->
  <div id="vendorPopupModal" class="modal-overlay">
    <div class="modal-card">
      <div class="modal-header">
        <h3 class="modal-title" id="modalFormTitle">+ Add Vendor</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn" aria-label="Close modal">&times;</button>
      </div>

      <form id="vendorPopupForm">
        <div class="modal-body">
          <!-- Hidden field for edit item ID (database id) -->
          <input type="hidden" id="editVendorId" value="">

          <div class="popup-form-grid">
            
            <!-- 1. Vendor Code -->
            <div class="vm-form-group">
              <label for="inputVendorCode">Vendor Code *</label>
              <input type="text" id="inputVendorCode" class="form-control" placeholder="e.g., VND-007" required>
            </div>

            <!-- 2. Vendor Name -->
            <div class="vm-form-group">
              <label for="inputVendorName">Vendor Name *</label>
              <input type="text" id="inputVendorName" class="form-control" placeholder="e.g., Reliance Industries" required>
            </div>

            <!-- 3. Contact Person -->
            <div class="vm-form-group">
              <label for="inputContactPerson">Contact Person</label>
              <input type="text" id="inputContactPerson" class="form-control" placeholder="e.g., Ramesh Kumar">
            </div>

            <!-- 4. Phone -->
            <div class="vm-form-group">
              <label for="inputPhone">Phone</label>
              <input type="text" id="inputPhone" class="form-control" placeholder="e.g., +91 98765 43210">
            </div>

            <!-- 5. Email -->
            <div class="vm-form-group">
              <label for="inputEmail">Email</label>
              <input type="email" id="inputEmail" class="form-control" placeholder="e.g., vendor@company.com">
            </div>

            <!-- 6. GSTIN -->
            <div class="vm-form-group">
              <label for="inputGstin">GSTIN</label>
              <input type="text" id="inputGstin" class="form-control" placeholder="e.g., 27AAAAA0000A1Z5">
            </div>

            <!-- 7. Address -->
            <div class="vm-form-group">
              <label for="inputAddress">Address</label>
              <input type="text" id="inputAddress" class="form-control" placeholder="e.g., Plot 12, Industrial Area, Mumbai">
            </div>

            <!-- Hidden Status field (Default: Active) -->
            <input type="hidden" id="inputStatus" value="Active">

          </div>
        </div>

        <!-- Action Buttons -->
        <div class="modal-footer">
          <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
          <button type="submit" class="btn-primary" id="saveVendorSubmitBtn">+ Save Vendor</button>
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
      <p class="confirm-desc">Are you sure you want to delete vendor <strong id="deleteTargetCode"></strong> from the database?</p>
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
      const vendorSearch = document.getElementById('vendorSearch');
      const vendorTableBody = document.getElementById('vendorTableBody');
      const toastContainer = document.getElementById('toastContainer');

      // Add/Edit Modal Elements
      const vendorPopupModal = document.getElementById('vendorPopupModal');
      const vendorPopupForm = document.getElementById('vendorPopupForm');
      const modalFormTitle = document.getElementById('modalFormTitle');
      const saveVendorSubmitBtn = document.getElementById('saveVendorSubmitBtn');
      const editVendorId = document.getElementById('editVendorId');
      const openAddVendorModalBtn = document.getElementById('openAddVendorModalBtn');
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

      const statTotalVendor = document.getElementById('statTotalVendor');
      const statActiveVendor = document.getElementById('statActiveVendor');
      const statAddressesVendor = document.getElementById('statAddressesVendor');

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
        const rows = Array.from(vendorTableBody.querySelectorAll('tr')).filter(r => r.id !== 'emptyTableRow');
        let total = rows.length;
        let active = 0;
        let addressesSet = new Set();

        rows.forEach(r => {
          const cells = r.querySelectorAll('td');
          if (cells.length >= 8) {
            const addressText = cells[6].textContent.trim();
            const statusText = cells[7].textContent.trim();
            if (statusText.toLowerCase().includes('active')) active++;
            if (addressText && addressText !== '-') addressesSet.add(addressText.toLowerCase());
          }
        });

        if (statTotalVendor) statTotalVendor.textContent = total;
        if (statActiveVendor) statActiveVendor.textContent = active;
        if (statAddressesVendor) statAddressesVendor.textContent = addressesSet.size;

        const emptyRow = document.getElementById('emptyTableRow');
        if (total === 0) {
          if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.id = 'emptyTableRow';
            tr.innerHTML = `<td colspan="9" class="empty-row-msg">No vendors found in database. Click "+ Add Vendor" to create one.</td>`;
            vendorTableBody.appendChild(tr);
          }
        } else if (emptyRow) {
          emptyRow.remove();
        }
      }

      // Open Form Modal
      function openModal(title = '+ Add Vendor', buttonText = '+ Save Vendor') {
        modalFormTitle.textContent = title;
        saveVendorSubmitBtn.textContent = buttonText;
        vendorPopupModal.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
          document.getElementById('inputVendorCode').focus();
        }, 100);
      }

      // Close Form Modal
      function closeModal() {
        vendorPopupModal.classList.remove('active');
        document.body.style.overflow = '';
        vendorPopupForm.reset();
        editVendorId.value = '';
        document.getElementById('inputStatus').value = 'Active';
      }

      // Open Add Modal
      if (openAddVendorModalBtn) {
        openAddVendorModalBtn.addEventListener('click', () => {
          vendorPopupForm.reset();
          editVendorId.value = '';
          document.getElementById('inputStatus').value = 'Active';
          openModal('+ Add Vendor', '+ Save Vendor');
        });
      }

      if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
      if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

      // Close when clicking outside modal
      if (vendorPopupModal) {
        vendorPopupModal.addEventListener('click', (e) => {
          if (e.target === vendorPopupModal) closeModal();
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
          if (vendorPopupModal && vendorPopupModal.classList.contains('active')) closeModal();
          if (deleteConfirmModal && deleteConfirmModal.classList.contains('active')) closeDeleteConfirmModal();
        }
      });

      // Real-time table search filter
      if (vendorSearch && vendorTableBody) {
        vendorSearch.addEventListener('input', () => {
          const q = vendorSearch.value.toLowerCase().trim();
          const rows = vendorTableBody.querySelectorAll('tr');
          rows.forEach(row => {
            if (row.id === 'emptyTableRow') return;
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
          });
        });
      }

      // Submit Form: Store real data in MSSQL Database via API
      if (vendorPopupForm) {
        vendorPopupForm.addEventListener('submit', async (e) => {
          e.preventDefault();

          const id = editVendorId.value ? parseInt(editVendorId.value, 10) : 0;
          const code = document.getElementById('inputVendorCode').value.trim();
          const name = document.getElementById('inputVendorName').value.trim();
          const contact = document.getElementById('inputContactPerson').value.trim();
          const phone = document.getElementById('inputPhone').value.trim();
          const email = document.getElementById('inputEmail').value.trim();
          const gstin = document.getElementById('inputGstin').value.trim();
          const address = document.getElementById('inputAddress').value.trim();
          const status = document.getElementById('inputStatus').value || 'Active';

          const action = id > 0 ? 'update' : 'create';

          saveVendorSubmitBtn.disabled = true;
          const originalBtnText = saveVendorSubmitBtn.textContent;
          saveVendorSubmitBtn.textContent = 'Saving...';

          try {
            const response = await fetch('api/vendor_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: action,
                id: id,
                vendor_code: code,
                vendor_name: name,
                contact_person: contact,
                phone: phone,
                email: email,
                gstin: gstin,
                address: address,
                status: status
              })
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
              showToast(result.message || 'Error saving to database.', 'error');
              saveVendorSubmitBtn.disabled = false;
              saveVendorSubmitBtn.textContent = originalBtnText;
              return;
            }

            const activeTag = status === 'Active' 
              ? '<span class="tag tag-completed">Active</span>' 
              : '<span class="tag" style="background:#f1f5f9; color:#64748b;">Inactive</span>';

            const actionHtml = `
              <div style="display: flex; gap: 6px;">
                <button type="button" class="btn-edit" title="Edit Vendor">Edit</button>
                <button type="button" class="btn-delete" title="Delete Vendor">Delete</button>
              </div>
            `;

            if (id > 0) {
              // Update row in table
              const row = vendorTableBody.querySelector(`tr[data-id="${id}"]`);
              if (row) {
                row.children[0].innerHTML = `<strong>${code}</strong>`;
                row.children[1].textContent = name;
                row.children[2].textContent = contact || '-';
                row.children[3].textContent = phone || '-';
                row.children[4].textContent = email || '-';
                row.children[5].innerHTML = `<span style="font-family: monospace; font-size: 0.82rem;">${gstin || '-'}</span>`;
                row.children[6].textContent = address || '-';
                row.children[7].innerHTML = activeTag;
                row.children[8].innerHTML = actionHtml;
              }
              showToast(`Vendor "${code}" updated successfully.`, 'success');
            } else {
              // Remove empty state message if exists
              const emptyRow = document.getElementById('emptyTableRow');
              if (emptyRow) emptyRow.remove();

              // Insert new row at top of table
              const newId = result.data?.id || Date.now();
              const tr = document.createElement('tr');
              tr.setAttribute('data-id', newId);
              tr.innerHTML = `
                <td><strong>${code}</strong></td>
                <td>${name}</td>
                <td>${contact || '-'}</td>
                <td>${phone || '-'}</td>
                <td>${email || '-'}</td>
                <td><span style="font-family: monospace; font-size: 0.82rem;">${gstin || '-'}</span></td>
                <td>${address || '-'}</td>
                <td>${activeTag}</td>
                <td>${actionHtml}</td>
              `;
              vendorTableBody.prepend(tr);
              showToast(`New Vendor "${code}" saved successfully.`, 'success');
            }

            closeModal();
            updateCounters();

          } catch (err) {
            showToast('Network or server error while saving data.', 'error');
          } finally {
            saveVendorSubmitBtn.disabled = false;
            saveVendorSubmitBtn.textContent = originalBtnText;
          }
        });
      }

      // Handle Edit and Delete on Table rows
      if (vendorTableBody) {
        vendorTableBody.addEventListener('click', (e) => {
          const row = e.target.closest('tr');
          if (!row || row.id === 'emptyTableRow') return;

          const itemId = row.getAttribute('data-id');

          // Edit Action
          if (e.target.classList.contains('btn-edit')) {
            const cells = row.children;
            const code = cells[0].textContent.trim();
            const name = cells[1].textContent.trim();
            const contact = cells[2].textContent.trim();
            const phone = cells[3].textContent.trim();
            const email = cells[4].textContent.trim();
            const gstin = cells[5].textContent.trim();
            const address = cells[6].textContent.trim();
            const status = cells[7].textContent.trim().toLowerCase().includes('active') ? 'Active' : 'Inactive';

            document.getElementById('inputVendorCode').value = code;
            document.getElementById('inputVendorName').value = name;
            document.getElementById('inputContactPerson').value = contact === '-' ? '' : contact;
            document.getElementById('inputPhone').value = phone === '-' ? '' : phone;
            document.getElementById('inputEmail').value = email === '-' ? '' : email;
            document.getElementById('inputGstin').value = gstin === '-' ? '' : gstin;
            document.getElementById('inputAddress').value = address === '-' ? '' : address;
            document.getElementById('inputStatus').value = status;

            editVendorId.value = itemId;
            openModal(`Edit Vendor: ${code}`, 'Update Vendor');
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
            const response = await fetch('api/vendor_master.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                action: 'delete',
                id: pendingDeleteId,
                vendor_code: pendingDeleteCode
              })
            });

            const result = await response.json();

            if (response.ok && result.success) {
              if (pendingDeleteRow) pendingDeleteRow.remove();
              updateCounters();
              showToast(`Vendor "${pendingDeleteCode}" deleted successfully.`, 'success');
            } else {
              showToast(result.message || 'Failed to delete vendor from database.', 'error');
            }
          } catch (err) {
            showToast('Network error while deleting vendor.', 'error');
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
