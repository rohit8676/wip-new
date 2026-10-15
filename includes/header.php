<?php
/**
 * WIP Management Portal - Reusable Topbar Header Component
 * Auto-detects the page title from current script if $pageTitle is not provided.
 */
if (!isset($pageTitle)) {
    $script = basename($_SERVER['PHP_SELF']);
    $pageTitles = [
        'dashboard.php' => 'Dashboard',
        'vendor-master.php' => 'Vendor Master',
        'rm-master.php' => 'RM Master',
        'child-part-master.php' => 'RM & Child Part Master',
        'child-master.php' => 'RM & Child Part Master',
        'process-master.php' => 'Process Master',
        'part-master.php' => 'Part Master',
        'part-add.php' => 'Add New Part',
        'dpr.php' => 'DPR',
        'bom.php' => 'BOM',
        'rm-in.php' => 'RM & Child Part Inward',
        'child-part-in.php' => 'RM & Child Part Inward',
        'mip.php' => 'Material Issue for Production (MIP)',
        'production.php' => 'Production Entry / DPR',
        'fg-store.php' => 'Finished Goods Store (FG Store)',
        'reports.php' => 'Reports & Analytics',
        'users.php' => 'User Management',
    ];
    $pageTitle = $pageTitles[$script] ?? 'WIP Management';
}

$userName = $currentUser['name'] ?? ($_SESSION['name'] ?? 'User');
$userRole = $currentUser['role'] ?? ($_SESSION['role'] ?? 'Staff');

// Active script to module mapping
$scriptToModule = [
    'dashboard.php'         => 'dashboard',
    'vendor-master.php'     => 'vendor_master',
    'rm-master.php'         => 'rm_master',
    'child-part-master.php' => 'child_part_master',
    'child-master.php'      => 'child_part_master',
    'process-master.php'    => 'process_master',
    'part-master.php'       => 'part_master',
    'part-add.php'          => 'part_master',
    'rm-in.php'             => 'rm_in',
    'child-part-in.php'     => 'child_part_in',
    'mip.php'               => 'mip',
    'production.php'        => 'production',
    'dpr.php'               => 'production',
    'fg-store.php'          => 'fg_store',
    'reports.php'           => 'reports',
    'users.php'             => 'users',
];
$currentModuleKey = $scriptToModule[basename($_SERVER['PHP_SELF'])] ?? '';

// Enforce read access if module is defined
if (!empty($currentModuleKey) && function_exists('requirePermission')) {
    requirePermission($currentModuleKey, 'read');
}

$canCreate = !empty($currentModuleKey) && function_exists('hasPermission') ? hasPermission($currentModuleKey, 'create') : true;
$canUpdate = !empty($currentModuleKey) && function_exists('hasPermission') ? hasPermission($currentModuleKey, 'update') : true;
$canDelete = !empty($currentModuleKey) && function_exists('hasPermission') ? hasPermission($currentModuleKey, 'delete') : true;
?>
<!-- Reusable Topbar Header Component -->
<header class="topbar">
  <div class="topbar-left">
    <button id="menuToggleBtn" class="menu-toggle-btn" aria-label="Toggle navigation">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>
    <h1 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h1>
  </div>

  <div class="topbar-right">
    <span class="user-greeting">
      Welcome, <strong><?php echo htmlspecialchars($userName); ?></strong> 
      <span style="font-size: 0.75rem; padding: 2px 7px; background: #eff6ff; color: #2563eb; border-radius: 999px; margin-left: 4px; font-weight: 600;">
        <?php echo htmlspecialchars($userRole); ?>
      </span>
    </span>
    <a href="auth/logout.php" class="btn-sm-logout">Logout</a>
  </div>
</header>

<?php if (!empty($currentModuleKey)): ?>
<!-- Dynamic Role-Based CRUD Visual Access Guards -->
<style>
  <?php if (!$canDelete): ?>
  .btn-delete, .btn-action-delete, .btn-delete-row, [data-action="delete"], button[title*="Delete"], button[title*="delete"], a[title*="Delete"], #confirmDeleteBtn, button:has(.lucide-trash), button:has(.lucide-trash-2) {
    display: none !important;
    visibility: hidden !important;
    pointer-events: none !important;
  }
  <?php endif; ?>

  <?php if (!$canCreate): ?>
  .btn-primary-add, #openModalBtn, #btnAddItem, #btnAddVendor, #btnAddRM, #btnAddProcess, #btnAddPart, #btnAddChildPart, #openAddUserModalBtn, #openAddModalBtn, #openCreateModalBtn, .btn-add, .btn-create, #btnNewDispatch {
    display: none !important;
    visibility: hidden !important;
    pointer-events: none !important;
  }
  <?php endif; ?>

  <?php if (!$canUpdate): ?>
  .btn-edit, .btn-action-edit, .btn-update, button[title*="Edit"], a[title*="Edit"] {
    display: none !important;
    visibility: hidden !important;
    pointer-events: none !important;
  }
  <?php endif; ?>
</style>

<script>
  window.portalRBAC = {
    module: '<?php echo $currentModuleKey; ?>',
    canCreate: <?php echo $canCreate ? 'true' : 'false'; ?>,
    canRead: true,
    canUpdate: <?php echo $canUpdate ? 'true' : 'false'; ?>,
    canDelete: <?php echo $canDelete ? 'true' : 'false'; ?>
  };

  // Enforce DOM removal & event suppression for restricted actions
  (function() {
    const rbac = window.portalRBAC;
    if (!rbac) return;

    function purgeUnauthorizedElements() {
      if (!rbac.canDelete) {
        const deleteSelectors = '.btn-delete, .btn-action-delete, .btn-delete-row, [data-action="delete"], button[title*="Delete"], button[title*="delete"], a[title*="Delete"], #confirmDeleteBtn';
        document.querySelectorAll(deleteSelectors).forEach(el => {
          el.remove();
        });
      }
      if (!rbac.canCreate) {
        const createSelectors = '.btn-primary-add, #openModalBtn, #btnAddItem, #btnAddVendor, #btnAddRM, #btnAddProcess, #btnAddPart, #btnAddChildPart, #openAddUserModalBtn, #openAddModalBtn, #openCreateModalBtn, .btn-add, .btn-create, #btnNewDispatch';
        document.querySelectorAll(createSelectors).forEach(el => {
          el.remove();
        });
      }
      if (!rbac.canUpdate) {
        const updateSelectors = '.btn-edit, .btn-action-edit, .btn-update, button[title*="Edit"], a[title*="Edit"]';
        document.querySelectorAll(updateSelectors).forEach(el => {
          el.remove();
        });
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', purgeUnauthorizedElements);
    } else {
      purgeUnauthorizedElements();
    }

    // Monitor dynamic rendering (AJAX, DataTables, mod additions)
    try {
      const observer = new MutationObserver(function(mutations) {
        purgeUnauthorizedElements();
      });
      observer.observe(document.documentElement, { childList: true, subtree: true });
    } catch(e) {}

    // Global fetch interceptor to prevent unauthorized API requests
    const originalFetch = window.fetch;
    window.fetch = async function(...args) {
      const url = typeof args[0] === 'string' ? args[0] : (args[0] && args[0].url ? args[0].url : '');
      const options = args[1] || {};
      const method = (options.method || 'GET').toUpperCase();
      
      if (method === 'POST') {
        let action = '';
        if (typeof options.body === 'string') {
          try {
            const parsed = JSON.parse(options.body);
            action = (parsed.action || '').toLowerCase();
          } catch(e) {
            if (options.body.includes('action=delete')) action = 'delete';
          }
        } else if (options.body instanceof FormData) {
          action = (options.body.get('action') || '').toLowerCase();
        }

        if (action === 'delete' && !rbac.canDelete) {
          alert('Access Denied: You do not have permission to DELETE records.');
          return Promise.reject(new Error('Permission Denied'));
        }
      }
      return originalFetch.apply(this, args);
    };
  })();
</script>
<?php endif; ?>
