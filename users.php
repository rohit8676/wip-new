<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/rbac.php';

// Enforce read access on User Management
requirePermission('users', 'read');

$pageTitle = 'User Management & Access Control';
$pdo = getDBConnection();

$users = [];
$roles = [];
$totalUsers = 0;
$adminCount = 0;
$staffCount = 0;

if ($pdo) {
    try {
        // Fetch Users
        $stmt = $pdo->query("
            SELECT id, email, full_name, role,
                   CONVERT(VARCHAR(19), created_at, 120) AS created_at,
                   CONVERT(VARCHAR(19), last_login, 120) AS last_login
            FROM users 
            ORDER BY id ASC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalUsers = count($users);
        foreach ($users as $u) {
            $r = strtolower($u['role'] ?? '');
            if (strpos($r, 'admin') !== false) {
                $adminCount++;
            } else {
                $staffCount++;
            }
        }

        // Fetch Roles
        $rolesStmt = $pdo->query("SELECT id, role_name, description, is_system FROM roles ORDER BY id ASC");
        $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $errorMsg = $e->getMessage();
    }
}

$modules = getPortalModules();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management & Access Control - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Core Dashboard CSS with cache-busting -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --border: #e2e8f0;
      --text-main: #0f172a;
      --text-muted: #64748b;
    }

    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background-color: #f8fafc;
      color: #1e293b;
      margin: 0;
      padding: 0;
    }

    .content-body {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      min-width: 0;
    }

    /* Top Navigation Tabs */
    .portal-tabs {
      display: flex;
      gap: 8px;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 0;
      margin-bottom: 4px;
    }

    .portal-tab {
      padding: 10px 18px;
      background: transparent;
      border: none;
      border-bottom: 3px solid transparent;
      margin-bottom: -2px;
      font-family: inherit;
      font-size: 0.92rem;
      font-weight: 700;
      color: #64748b;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.15s ease;
    }

    .portal-tab:hover {
      color: #0f172a;
    }

    .portal-tab.active {
      color: var(--primary);
      border-bottom-color: var(--primary);
      background: rgba(37, 99, 235, 0.04);
      border-radius: 8px 8px 0 0;
    }

    .portal-tab svg {
      width: 18px;
      height: 18px;
    }

    /* KPI Summary Strip */
    .user-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 14px;
      width: 100%;
      box-sizing: border-box;
    }

    .kpi-card {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    }

    .kpi-info {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .kpi-label {
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-muted);
    }

    .kpi-val {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--text-main);
      line-height: 1.1;
    }

    .kpi-icon-wrap {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #eff6ff;
      color: var(--primary);
    }

    .kpi-icon-wrap svg {
      width: 22px;
      height: 22px;
    }

    /* Main Table Panel */
    .card-panel {
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      display: flex;
      flex-direction: column;
      gap: 16px;
      width: 100%;
      box-sizing: border-box;
      min-width: 0;
    }

    .panel-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .search-box {
      position: relative;
      flex: 1 1 240px;
      max-width: 380px;
    }

    .search-box input {
      width: 100%;
      height: 38px;
      padding: 0 14px 0 36px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.85rem;
      color: var(--text-main);
      outline: none;
      box-sizing: border-box;
      transition: border-color 0.15s ease;
    }

    .search-box input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .search-box svg {
      position: absolute;
      left: 11px;
      top: 11px;
      width: 16px;
      height: 16px;
      color: var(--text-muted);
      pointer-events: none;
    }

    .btn-primary-add {
      height: 38px;
      padding: 0 16px;
      background: var(--primary);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.85rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: background 0.15s ease;
      white-space: nowrap;
    }

    .btn-primary-add:hover {
      background: var(--primary-hover);
    }

    .btn-primary-add svg {
      width: 16px;
      height: 16px;
    }

    /* Table Container */
    .table-container {
      overflow-x: auto;
      border: 1px solid var(--border);
      border-radius: 8px;
      width: 100%;
      box-sizing: border-box;
    }

    .users-table, .matrix-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.84rem;
      text-align: left;
    }

    .users-table th, .matrix-table th {
      background: #f8fafc;
      padding: 11px 14px;
      font-weight: 700;
      color: #475569;
      border-bottom: 2px solid var(--border);
      white-space: nowrap;
    }

    .users-table td, .matrix-table td {
      padding: 12px 14px;
      border-bottom: 1px solid #f1f5f9;
      color: #1e293b;
      vertical-align: middle;
      white-space: nowrap;
    }

    .users-table tr:hover td, .matrix-table tr:hover td {
      background: #fafbfc;
    }

    /* Role Badges */
    .role-badge {
      display: inline-block;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 0.74rem;
      font-weight: 700;
    }

    .role-badge.admin {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }

    .role-badge.supervisor {
      background: #f0fdf4;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }

    .role-badge.store {
      background: #fefce8;
      color: #a16207;
      border: 1px solid #fef08a;
    }

    .role-badge.operator, .role-badge.user {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #e2e8f0;
    }

    /* Action Buttons */
    .action-group {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .btn-action-edit, .btn-action-delete {
      padding: 5px 10px;
      border-radius: 6px;
      font-size: 0.76rem;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .btn-action-edit {
      background: #eff6ff;
      color: #2563eb;
      border-color: #dbeafe;
    }

    .btn-action-edit:hover {
      background: #dbeafe;
    }

    .btn-action-delete {
      background: #fef2f2;
      color: #dc2626;
      border-color: #fee2e2;
    }

    .btn-action-delete:hover {
      background: #c9dde6ff;
    }

    /* Matrix Controls */
    .matrix-top-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      padding: 14px 18px;
      background: #f8fafc;
      border: 1px solid var(--border);
      border-radius: 10px;
    }

    .role-select-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .role-select-label {
      font-size: 0.85rem;
      font-weight: 700;
      color: #334155;
    }

    .role-dropdown {
      height: 38px;
      padding: 0 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: #ffffff;
      font-family: inherit;
      font-size: 0.88rem;
      font-weight: 600;
      color: var(--text-main);
      outline: none;
    }

    /* Permission Checkboxes */
    .perm-toggle-cell {
      text-align: center;
    }

    .perm-checkbox {
      width: 18px;
      height: 18px;
      cursor: pointer;
      accent-color: var(--primary);
    }

    .perm-checkbox:disabled {
      cursor: not-allowed;
      opacity: 0.6;
    }

    /* Modal */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      z-index: 100;
      align-items: center;
      justify-content: center;
      padding: 16px;
      backdrop-filter: blur(2px);
    }

    .modal-overlay.open {
      display: flex;
    }

    .modal-dialog {
      background: #ffffff;
      border-radius: 14px;
      width: 100%;
      max-width: 480px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
      overflow: hidden;
      animation: modalSlide 0.2s ease;
    }

    @keyframes modalSlide {
      from { transform: translateY(-12px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
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
      font-size: 1.4rem;
      color: var(--text-muted);
      cursor: pointer;
      line-height: 1;
    }

    .modal-body {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .form-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: #334155;
    }

    .form-input, .form-select {
      height: 38px;
      padding: 0 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.85rem;
      color: var(--text-main);
      outline: none;
      box-sizing: border-box;
      transition: border-color 0.15s ease;
      width: 100%;
    }

    .form-input:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .modal-footer {
      padding: 14px 20px;
      background: #f8fafc;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .btn-cancel {
      height: 36px;
      padding: 0 14px;
      background: #ffffff;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 600;
      color: #475569;
      cursor: pointer;
    }

    .btn-save {
      height: 36px;
      padding: 0 16px;
      background: var(--primary);
      border: none;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 700;
      color: #ffffff;
      cursor: pointer;
    }

    .btn-save:hover {
      background: var(--primary-hover);
    }
  </style>
</head>
<body>

  <!-- Mobile Sidebar Backdrop -->
  <div id="sidebarBackdrop" class="sidebar-backdrop"></div>

  <div class="layout-container">
    
    <!-- Reusable Sidebar Component -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
      
      <!-- Reusable Top Bar Header Component -->
      <?php include __DIR__ . '/includes/header.php'; ?>

      <!-- Page Content Body -->
      <div class="content-body">
        
        <!-- Tab Navigation Switcher -->
        <div class="portal-tabs">
          <button type="button" class="portal-tab active" id="tabBtnUsers" onclick="switchTab('users')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span>User Accounts (<?php echo $totalUsers; ?>)</span>
          </button>

          <button type="button" class="portal-tab" id="tabBtnMatrix" onclick="switchTab('matrix')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            <span>Roles & CRUD Permission Matrix</span>
          </button>
        </div>

        <!-- ======================================================== -->
        <!-- TAB 1: USERS LIST SECTION                                -->
        <!-- ======================================================== -->
        <div id="usersTabPane">
          
          <!-- KPI Summary Cards -->
          <div class="user-kpi-grid">
            
            <div class="kpi-card">
              <div class="kpi-info">
                <span class="kpi-label">Total Users</span>
                <span class="kpi-val" id="kpiTotalUsers"><?php echo $totalUsers; ?></span>
              </div>
              <div class="kpi-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                  <circle cx="9" cy="7" r="4"></circle>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                  <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
              </div>
            </div>

            <div class="kpi-card">
              <div class="kpi-info">
                <span class="kpi-label">Administrators</span>
                <span class="kpi-val" id="kpiAdminUsers" style="color: #2563eb;"><?php echo $adminCount; ?></span>
              </div>
              <div class="kpi-icon-wrap" style="background: #eff6ff; color: #2563eb;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                </svg>
              </div>
            </div>

            <div class="kpi-card">
              <div class="kpi-info">
                <span class="kpi-label">Standard & Operations Staff</span>
                <span class="kpi-val" id="kpiStaffUsers"><?php echo $staffCount; ?></span>
              </div>
              <div class="kpi-icon-wrap" style="background: #f1f5f9; color: #475569;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                  <circle cx="12" cy="7" r="4"></circle>
                </svg>
              </div>
            </div>

          </div>

          <!-- Users Table Card -->
          <div class="card-panel" style="margin-top: 14px;">
            
            <div class="panel-toolbar">
              
              <div class="search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="11" cy="11" r="8"></circle>
                  <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="userSearchInput" placeholder="Search by name, email, or role...">
              </div>

              <?php if (hasPermission('users', 'create')): ?>
              <button type="button" class="btn-primary-add" id="openAddUserModalBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <line x1="12" y1="5" x2="12" y2="19"></line>
                  <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add New User
              </button>
              <?php endif; ?>

            </div>

            <!-- Table Wrapper -->
            <div class="table-container">
              <table class="users-table" id="usersTable">
                <thead>
                  <tr>
                    <th style="width: 40px; text-align: center;">#</th>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>Assigned Role</th>
                    <th>Last Login</th>
                    <th>Created At</th>
                    <th style="width: 140px; text-align: center;">Actions</th>
                  </tr>
                </thead>
                <tbody id="usersTableBody">
                  <?php if (empty($users)): ?>
                    <tr>
                      <td colspan="7" style="text-align: center; padding: 32px; color: var(--text-muted);">
                        No users found.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($users as $idx => $u): 
                      $roleLower = strtolower($u['role'] ?? '');
                      $badgeClass = 'user';
                      if (strpos($roleLower, 'admin') !== false) $badgeClass = 'admin';
                      elseif (strpos($roleLower, 'super') !== false) $badgeClass = 'supervisor';
                      elseif (strpos($roleLower, 'store') !== false) $badgeClass = 'store';
                      elseif (strpos($roleLower, 'oper') !== false) $badgeClass = 'operator';
                    ?>
                      <tr data-name="<?php echo htmlspecialchars(strtolower($u['full_name'] ?? '')); ?>"
                          data-email="<?php echo htmlspecialchars(strtolower($u['email'] ?? '')); ?>"
                          data-role="<?php echo htmlspecialchars(strtolower($u['role'] ?? '')); ?>">
                        <td style="text-align: center; color: var(--text-muted); font-size: 0.78rem;"><?php echo $idx + 1; ?></td>
                        <td><strong><?php echo htmlspecialchars($u['full_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                          <span class="role-badge <?php echo $badgeClass; ?>">
                            <?php echo htmlspecialchars($u['role']); ?>
                          </span>
                        </td>
                        <td><?php echo !empty($u['last_login']) ? htmlspecialchars($u['last_login']) : '<span style="color: #94a3b8;">Never</span>'; ?></td>
                        <td><?php echo !empty($u['created_at']) ? htmlspecialchars($u['created_at']) : '-'; ?></td>
                        <td style="text-align: center;">
                          <div class="action-group" style="justify-content: center;">
                            <?php if (hasPermission('users', 'update')): ?>
                            <button type="button" class="btn-action-edit" 
                                    onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                              Edit
                            </button>
                            <?php endif; ?>
                            <?php if (hasPermission('users', 'delete') && intval($u['id']) !== intval($_SESSION['user_id'] ?? 0)): ?>
                              <button type="button" class="btn-action-delete" onclick="deleteUser(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')">
                                Delete
                              </button>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>

        </div>

        <!-- ======================================================== -->
        <!-- TAB 2: ROLES & CRUD PERMISSION MATRIX                    -->
        <!-- ======================================================== -->
        <div id="matrixTabPane" style="display: none;">
          
          <div class="card-panel">
            
            <div class="matrix-top-bar">
              <div class="role-select-wrap">
                <span class="role-select-label">Select Role to Configure:</span>
                <select id="roleSelectDropdown" class="role-dropdown" onchange="loadRoleMatrix(this.value)">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?php echo $r['id']; ?>" <?php echo ($r['id'] == 1 ? 'selected' : ''); ?>>
                      <?php echo htmlspecialchars($r['role_name']); ?> <?php echo ($r['is_system'] ? '(System Role)' : ''); ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <span id="roleBadgeNotice" style="font-size: 0.78rem; font-weight: 700; padding: 4px 8px; border-radius: 6px; background: #eff6ff; color: #1d4ed8;">
                  Administrator (Full Unrestricted Access)
                </span>
              </div>

              <div style="display: flex; gap: 8px;">
                <?php if (hasPermission('users', 'create')): ?>
                <button type="button" class="btn-primary-add" id="openAddRoleModalBtn" style="background: #ffffff; color: #334155; border: 1px solid var(--border);">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                  </svg>
                  New Role
                </button>
                <?php endif; ?>

                <?php if (hasPermission('users', 'update')): ?>
                <button type="button" class="btn-primary-add" id="savePermissionsBtn" onclick="savePermissionsMatrix()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                    <polyline points="17 21 17 13 7 13 7 21"></polyline>
                    <polyline points="7 3 7 8 15 8"></polyline>
                  </svg>
                  Save Permissions
                </button>
                <?php endif; ?>
              </div>
            </div>

            <!-- Permission Matrix Table -->
            <div class="table-container">
              <table class="matrix-table" id="matrixTable">
                <thead>
                  <tr>
                    <th style="width: 260px;">Module / System Section</th>
                    <th class="perm-toggle-cell" style="width: 110px;">Create (C)</th>
                    <th class="perm-toggle-cell" style="width: 110px;">Read / View (R)</th>
                    <th class="perm-toggle-cell" style="width: 110px;">Update / Edit (U)</th>
                    <th class="perm-toggle-cell" style="width: 110px;">Delete (D)</th>
                    <th style="width: 140px; text-align: center;">Quick Action</th>
                  </tr>
                </thead>
                <tbody id="matrixTableBody">
                  <?php foreach ($modules as $modKey => $modInfo): ?>
                    <tr data-module="<?php echo $modKey; ?>">
                      <td>
                        <strong><?php echo htmlspecialchars($modInfo['name']); ?></strong>
                        <div style="font-size: 0.74rem; color: var(--text-muted);"><?php echo $modInfo['script']; ?></div>
                      </td>
                      <td class="perm-toggle-cell">
                        <input type="checkbox" class="perm-checkbox perm-c" data-perm="create" data-mod="<?php echo $modKey; ?>">
                      </td>
                      <td class="perm-toggle-cell">
                        <input type="checkbox" class="perm-checkbox perm-r" data-perm="read" data-mod="<?php echo $modKey; ?>">
                      </td>
                      <td class="perm-toggle-cell">
                        <input type="checkbox" class="perm-checkbox perm-u" data-perm="update" data-mod="<?php echo $modKey; ?>">
                      </td>
                      <td class="perm-toggle-cell">
                        <input type="checkbox" class="perm-checkbox perm-d" data-perm="delete" data-mod="<?php echo $modKey; ?>">
                      </td>
                      <td style="text-align: center;">
                        <button type="button" class="btn-action-edit" style="font-size: 0.72rem; padding: 2px 7px;" onclick="toggleRowPerms('<?php echo $modKey; ?>', true)">All</button>
                        <button type="button" class="btn-action-delete" style="font-size: 0.72rem; padding: 2px 7px;" onclick="toggleRowPerms('<?php echo $modKey; ?>', false)">None</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

          </div>

        </div>

      </div>

    </div>

  </div>

  <!-- Add / Edit User Modal Dialog -->
  <div class="modal-overlay" id="userModal">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">Add New User</h3>
        <button type="button" class="modal-close-btn" id="closeModalBtn">&times;</button>
      </div>
      <form id="userForm">
        <input type="hidden" id="userId" name="id" value="">
        <input type="hidden" id="formAction" name="action" value="create">
        
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="userNameInput">Full Name *</label>
            <input type="text" id="userNameInput" name="full_name" class="form-input" required placeholder="e.g. Rahul Sharma">
          </div>

          <div class="form-group">
            <label class="form-label" for="userEmailInput">Email Address *</label>
            <input type="email" id="userEmailInput" name="email" class="form-input" required placeholder="user@company.com">
          </div>

          <div class="form-group">
            <label class="form-label" for="userPasswordInput" id="passwordLabel">Password *</label>
            <input type="password" id="userPasswordInput" name="password" class="form-input" placeholder="Enter password">
            <small id="passwordHelp" style="display: none; font-size: 0.72rem; color: #64748b; margin-top: 2px;">Leave blank to keep existing password.</small>
          </div>

          <div class="form-group">
            <label class="form-label" for="userRoleInput">Assign Role *</label>
            <select id="userRoleInput" name="role" class="form-select">
              <?php foreach ($roles as $r): ?>
                <option value="<?php echo htmlspecialchars($r['role_name']); ?>">
                  <?php echo htmlspecialchars($r['role_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-cancel" id="cancelModalBtn">Cancel</button>
          <button type="submit" class="btn-save" id="saveUserBtn">Save User</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Create Role Modal Dialog -->
  <div class="modal-overlay" id="roleModal">
    <div class="modal-dialog">
      <div class="modal-header">
        <h3 class="modal-title">Create New Role</h3>
        <button type="button" class="modal-close-btn" id="closeRoleModalBtn">&times;</button>
      </div>
      <form id="roleForm" onsubmit="event.preventDefault(); submitNewRole();">
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="roleNameInput">Role Name *</label>
            <input type="text" id="roleNameInput" class="form-input" required placeholder="e.g. Quality Inspector, Dispatch Officer">
          </div>
          <div class="form-group">
            <label class="form-label" for="roleDescInput">Description / Responsibilities</label>
            <input type="text" id="roleDescInput" class="form-input" placeholder="e.g. Inspects part quality and logs rejection entries">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-cancel" id="cancelRoleModalBtn">Cancel</button>
          <button type="submit" class="btn-save" id="saveRoleBtn">Create Role</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Tab Switcher
    function switchTab(tab) {
      const usersPane = document.getElementById('usersTabPane');
      const matrixPane = document.getElementById('matrixTabPane');
      const btnUsers = document.getElementById('tabBtnUsers');
      const btnMatrix = document.getElementById('tabBtnMatrix');

      if (tab === 'users') {
        usersPane.style.display = 'block';
        matrixPane.style.display = 'none';
        btnUsers.classList.add('active');
        btnMatrix.classList.remove('active');
      } else {
        usersPane.style.display = 'none';
        matrixPane.style.display = 'block';
        btnUsers.classList.remove('active');
        btnMatrix.classList.add('active');
        
        // Load active role matrix
        const selRoleId = document.getElementById('roleSelectDropdown').value;
        loadRoleMatrix(selRoleId);
      }
    }

    // Role Matrix Engine
    let currentMatrixData = {};

    async function loadRoleMatrix(roleId) {
      try {
        const res = await fetch(`api/roles.php?role_id=${roleId}`);
        const data = await res.json();
        if (!data.success) {
          alert('Failed to load role permissions: ' + data.message);
          return;
        }

        currentMatrixData = data;
        const activeRole = data.roles.find(r => String(r.id) === String(roleId));
        const isAdmin = activeRole && (activeRole.is_system == 1 && activeRole.role_name.toLowerCase() === 'administrator');

        // Update notice badge
        const badge = document.getElementById('roleBadgeNotice');
        const saveBtn = document.getElementById('savePermissionsBtn');
        if (isAdmin) {
          badge.textContent = 'Administrator (Full Unrestricted Access permanently)';
          badge.style.background = '#eff6ff';
          badge.style.color = '#1d4ed8';
          saveBtn.disabled = true;
          saveBtn.style.opacity = '0.5';
        } else {
          badge.textContent = `${activeRole.role_name} (${activeRole.description || 'Configurable Role'})`;
          badge.style.background = '#f0fdf4';
          badge.style.color = '#15803d';
          saveBtn.disabled = false;
          saveBtn.style.opacity = '1';
        }

        // Populate checkboxes
        const perms = data.permissions || {};
        document.querySelectorAll('#matrixTableBody tr').forEach(row => {
          const mod = row.dataset.module;
          const modPerm = perms[mod] || { create: 0, read: 0, update: 0, delete: 0 };

          const c = row.querySelector('.perm-c');
          const r = row.querySelector('.perm-r');
          const u = row.querySelector('.perm-u');
          const d = row.querySelector('.perm-d');

          c.checked = isAdmin || Boolean(modPerm.create);
          r.checked = isAdmin || Boolean(modPerm.read);
          u.checked = isAdmin || Boolean(modPerm.update);
          d.checked = isAdmin || Boolean(modPerm.delete);

          c.disabled = isAdmin;
          r.disabled = isAdmin;
          u.disabled = isAdmin;
          d.disabled = isAdmin;
        });

      } catch (err) {
        console.error(err);
      }
    }

    function toggleRowPerms(modKey, enableAll) {
      const row = document.querySelector(`tr[data-module="${modKey}"]`);
      if (!row) return;
      const c = row.querySelector('.perm-c');
      if (c.disabled) return;
      row.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = enableAll);
    }

    async function savePermissionsMatrix() {
      const roleId = document.getElementById('roleSelectDropdown').value;
      const permissions = {};

      document.querySelectorAll('#matrixTableBody tr').forEach(row => {
        const mod = row.dataset.module;
        permissions[mod] = {
          create: row.querySelector('.perm-c').checked ? 1 : 0,
          read:   row.querySelector('.perm-r').checked ? 1 : 0,
          update: row.querySelector('.perm-u').checked ? 1 : 0,
          delete: row.querySelector('.perm-d').checked ? 1 : 0
        };
      });

      const saveBtn = document.getElementById('savePermissionsBtn');
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      try {
        const res = await fetch('api/roles.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'save_permissions',
            role_id: roleId,
            permissions: permissions
          })
        });
        const data = await res.json();
        alert(data.message);
      } catch (err) {
        console.error(err);
        alert('Failed to save permissions.');
      } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = `
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
            <polyline points="17 21 17 13 7 13 7 21"></polyline>
            <polyline points="7 3 7 8 15 8"></polyline>
          </svg>
          Save Permissions
        `;
      }
    }

    // Add New Role
    async function submitNewRole() {
      const roleName = document.getElementById('roleNameInput').value.trim();
      const desc = document.getElementById('roleDescInput').value.trim();

      if (!roleName) return;

      try {
        const res = await fetch('api/roles.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'create_role',
            role_name: roleName,
            description: desc
          })
        });
        const data = await res.json();
        if (data.success) {
          alert(data.message);
          window.location.reload();
        } else {
          alert('Error: ' + data.message);
        }
      } catch (err) {
        alert('Server error.');
      }
    }

    // DOM Ready Initializations
    document.addEventListener('DOMContentLoaded', () => {
      const searchInput = document.getElementById('userSearchInput');
      const tableRows = document.querySelectorAll('#usersTableBody tr');
      const userModal = document.getElementById('userModal');
      const roleModal = document.getElementById('roleModal');
      const modalTitle = document.getElementById('modalTitle');
      const userForm = document.getElementById('userForm');
      const userId = document.getElementById('userId');
      const formAction = document.getElementById('formAction');
      const userNameInput = document.getElementById('userNameInput');
      const userEmailInput = document.getElementById('userEmailInput');
      const userPasswordInput = document.getElementById('userPasswordInput');
      const userRoleInput = document.getElementById('userRoleInput');
      const passwordLabel = document.getElementById('passwordLabel');
      const passwordHelp = document.getElementById('passwordHelp');

      // Live search filter
      if (searchInput) {
        searchInput.addEventListener('input', (e) => {
          const q = e.target.value.toLowerCase().trim();
          tableRows.forEach(row => {
            if (!row.dataset.name) return;
            const name = row.dataset.name;
            const email = row.dataset.email;
            const role = row.dataset.role;
            if (name.includes(q) || email.includes(q) || role.includes(q)) {
              row.style.display = '';
            } else {
              row.style.display = 'none';
            }
          });
        });
      }

      // User Modal Handlers
      const openUserAddBtn = document.getElementById('openAddUserModalBtn');
      if (openUserAddBtn) {
        openUserAddBtn.addEventListener('click', () => {
          userForm.reset();
          userId.value = '';
          formAction.value = 'create';
          modalTitle.textContent = 'Add New User';
          passwordLabel.textContent = 'Password *';
          userPasswordInput.required = true;
          passwordHelp.style.display = 'none';
          userModal.classList.add('open');
        });
      }

      const closeUserModal = () => userModal.classList.remove('open');
      document.getElementById('closeModalBtn').addEventListener('click', closeUserModal);
      document.getElementById('cancelModalBtn').addEventListener('click', closeUserModal);

      // Role Modal Handlers
      const openRoleModalBtn = document.getElementById('openAddRoleModalBtn');
      if (openRoleModalBtn) {
        openRoleModalBtn.addEventListener('click', () => {
          document.getElementById('roleForm').reset();
          roleModal.classList.add('open');
        });
      }
      const closeRoleModal = () => roleModal.classList.remove('open');
      document.getElementById('closeRoleModalBtn').addEventListener('click', closeRoleModal);
      document.getElementById('cancelRoleModalBtn').addEventListener('click', closeRoleModal);

      // Open Edit Modal
      window.openEditUserModal = function(user) {
        userForm.reset();
        userId.value = user.id;
        formAction.value = 'update';
        modalTitle.textContent = 'Edit User: ' + user.full_name;
        userNameInput.value = user.full_name || '';
        userEmailInput.value = user.email || '';
        userPasswordInput.value = '';
        userPasswordInput.required = false;
        passwordLabel.textContent = 'Password (Optional)';
        passwordHelp.style.display = 'block';
        userRoleInput.value = user.role || 'User';
        userModal.classList.add('open');
      };

      // Form Submit (AJAX)
      userForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
          action: formAction.value,
          id: userId.value,
          full_name: userNameInput.value.trim(),
          email: userEmailInput.value.trim(),
          password: userPasswordInput.value,
          role: userRoleInput.value
        };

        const saveBtn = document.getElementById('saveUserBtn');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        try {
          const res = await fetch('api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          const data = await res.json();
          if (data.success) {
            alert(data.message);
            window.location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        } catch (err) {
          console.error(err);
          alert('Network or server error.');
        } finally {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save User';
        }
      });

      // Delete User
      window.deleteUser = async function(id, name) {
        if (!confirm(`Are you sure you want to delete user "${name}"?`)) return;

        try {
          const res = await fetch('api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: id })
          });
          const data = await res.json();
          if (data.success) {
            alert(data.message);
            window.location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        } catch (err) {
          console.error(err);
          alert('Failed to delete user.');
        }
      };

    });
  </script>
</body>
</html>
