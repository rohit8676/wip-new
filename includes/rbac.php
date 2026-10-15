<?php
/**
 * WIP Management Portal - Role-Based Access Control (RBAC) Engine
 * Provides granular CRUD permission checks and enforcement across modules.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

/**
 * Return all registered portal modules
 */
function getPortalModules() {
    return [
        'dashboard'         => ['name' => 'Dashboard', 'script' => 'dashboard.php', 'icon' => 'grid'],
        'vendor_master'     => ['name' => 'Vendor Master', 'script' => 'vendor-master.php', 'icon' => 'truck'],
        // 'rm_master'         => ['name' => 'Raw Material (RM) Master', 'script' => 'rm-master.php', 'icon' => 'box'], // Hidden as requested
        'child_part_master' => ['name' => 'RM & Child Part Master', 'script' => 'child-part-master.php', 'icon' => 'layers'],
        'process_master'    => ['name' => 'Process Master', 'script' => 'process-master.php', 'icon' => 'settings'],
        'part_master'       => ['name' => 'Part Master', 'script' => 'part-master.php', 'icon' => 'tool'],
        // 'rm_in'             => ['name' => 'RM Inward', 'script' => 'rm-in.php', 'icon' => 'download'], // Hidden as requested
        'child_part_in'     => ['name' => 'RM & Child Part Inward', 'script' => 'child-part-in.php', 'icon' => 'arrow-down-circle'],
        'mip'               => ['name' => 'Material Issue for Production (MIP)', 'script' => 'mip.php', 'icon' => 'send'],
        'production'        => ['name' => 'Production Entry / DPR', 'script' => 'production.php', 'icon' => 'activity'],
        'fg_store'          => ['name' => 'Finished Goods (FG) Store', 'script' => 'fg-store.php', 'icon' => 'archive'],
        'reports'           => ['name' => 'Reports & Analytics', 'script' => 'reports.php', 'icon' => 'bar-chart-2'],
        'users'             => ['name' => 'User Management & Roles', 'script' => 'users.php', 'icon' => 'users']
    ];
}

/**
 * Get current logged in user's role, verifying live from DB if possible
 */
function getCurrentUserRole() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        static $cachedDbRole = null;
        static $cachedUserId = null;
        if ($cachedDbRole !== null && $cachedUserId === $userId) {
            return $cachedDbRole;
        }
        $pdo = getDBConnection();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $dbRole = $stmt->fetchColumn();
                if ($dbRole) {
                    $_SESSION['user_role'] = $dbRole;
                    $cachedDbRole = $dbRole;
                    $cachedUserId = $userId;
                    return $dbRole;
                }
            } catch (Exception $e) {
                // fallback to session
            }
        }
    }

    return $_SESSION['user_role'] ?? 'User';
}

/**
 * Load permissions for a specific role (with per-request memory cache for high performance & instant updates)
 */
function loadRolePermissions($roleName) {
    if (empty($roleName)) {
        return [];
    }

    static $rolePermCache = [];
    if (isset($rolePermCache[$roleName])) {
        return $rolePermCache[$roleName];
    }

    $pdo = getDBConnection();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT rp.module_key, rp.can_create, rp.can_read, rp.can_update, rp.can_delete
            FROM role_permissions rp
            INNER JOIN roles r ON rp.role_id = r.id
            WHERE r.role_name = ?
        ");
        $stmt->execute([$roleName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $perms = [];
        foreach ($rows as $r) {
            $perms[$r['module_key']] = [
                'create' => (bool)$r['can_create'],
                'read'   => (bool)$r['can_read'],
                'update' => (bool)$r['can_update'],
                'delete' => (bool)$r['can_delete']
            ];
        }

        $rolePermCache[$roleName] = $perms;
        $_SESSION['user_perms'] = $perms;
        $_SESSION['user_perms_role'] = $roleName;
        return $perms;
    } catch (Exception $e) {
        error_log("RBAC Load Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if the currently logged-in user has permission for a specific module and action.
 * Action: 'read' (default), 'create', 'update', 'delete'
 */
function hasPermission($moduleKey, $action = 'read') {
    $userRole = getCurrentUserRole();

    // Administrator always has full unconstrained access
    if (strcasecmp($userRole, 'Administrator') === 0 || strcasecmp($userRole, 'Admin') === 0) {
        return true;
    }

    // Normalizing action string
    $action = strtolower(trim($action));
    if ($action === 'c') $action = 'create';
    if ($action === 'r') $action = 'read';
    if ($action === 'u') $action = 'update';
    if ($action === 'd') $action = 'delete';

    $perms = loadRolePermissions($userRole);

    if (!isset($perms[$moduleKey])) {
        // If not explicitly granted, deny access
        return false;
    }

    return !empty($perms[$moduleKey][$action]);
}

/**
 * Enforce permission check. If unauthorized, denies access.
 */
function requirePermission($moduleKey, $action = 'read') {
    if (!hasPermission($moduleKey, $action)) {
        // If API or AJAX request
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                  (strpos($uri, '/api/') !== false) ||
                  (strpos($script, '/api/') !== false) ||
                  (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) ||
                  (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);

        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => '403_FORBIDDEN',
                'message' => "Access Denied: You do not have permission to " . strtoupper($action) . " in " . $moduleKey . "."
            ]);
            exit;
        }

        // Render Access Denied Page for Web Browsers
        renderAccessDeniedPage($moduleKey, $action);
        exit;
    }
}

/**
 * Render clean 403 Access Denied template
 */
function renderAccessDeniedPage($moduleKey, $action) {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>403 - Access Denied</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
      <link rel="stylesheet" href="css/dashboard.css">
      <style>
        .denied-container {
          min-height: 80vh;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 24px;
        }
        .denied-box {
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 16px;
          padding: 40px 32px;
          max-width: 500px;
          text-align: center;
          box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }
        .denied-icon {
          width: 64px;
          height: 64px;
          background: #fef2f2;
          color: #dc2626;
          border-radius: 50%;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          margin-bottom: 20px;
        }
        .denied-title {
          font-size: 1.5rem;
          font-weight: 800;
          color: #0f172a;
          margin-bottom: 8px;
        }
        .denied-desc {
          font-size: 0.92rem;
          color: #64748b;
          line-height: 1.5;
          margin-bottom: 24px;
        }
        .btn-back {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          padding: 10px 20px;
          background: #2563eb;
          color: #ffffff;
          border-radius: 8px;
          text-decoration: none;
          font-weight: 700;
          font-size: 0.88rem;
          transition: background 0.15s ease;
        }
        .btn-back:hover {
          background: #1d4ed8;
        }
      </style>
    </head>
    <body>
      <div class="layout-container">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
          <?php include __DIR__ . '/header.php'; ?>
          <div class="content-body">
            <div class="denied-container">
              <div class="denied-box">
                <div class="denied-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 32px; height: 32px;">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                  </svg>
                </div>
                <h2 class="denied-title">403 - Access Denied</h2>
                <p class="denied-desc">
                  You do not have <strong><?= strtoupper(htmlspecialchars($action)) ?></strong> permission for the <strong><?= htmlspecialchars($moduleKey) ?></strong> module.<br>
                  Please contact your system Administrator if you believe this is an error.
                </p>
                <a href="dashboard.php" class="btn-back">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                  </svg>
                  Return to Dashboard
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </body>
    </html>
    <?php
}
