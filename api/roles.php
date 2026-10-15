<?php
/**
 * WIP Management Portal - Roles & Permission Matrix API Endpoint (PHP + MSSQL)
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/rbac.php';

$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// GET - Load roles, modules, and role permissions
if ($method === 'GET') {
    requirePermission('users', 'read');
    try {
        $rolesStmt = $pdo->query("SELECT id, role_name, description, is_system FROM roles ORDER BY id ASC");
        $roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

        $modules = getPortalModules();

        // If specific role_id requested, load its permissions
        $roleId = intval($_GET['role_id'] ?? ($roles[0]['id'] ?? 1));

        $permStmt = $pdo->prepare("
            SELECT module_key, can_create, can_read, can_update, can_delete 
            FROM role_permissions 
            WHERE role_id = ?
        ");
        $permStmt->execute([$roleId]);
        $permsList = $permStmt->fetchAll(PDO::FETCH_ASSOC);

        $permMap = [];
        foreach ($permsList as $p) {
            $permMap[$p['module_key']] = [
                'create' => (int)$p['can_create'],
                'read'   => (int)$p['can_read'],
                'update' => (int)$p['can_update'],
                'delete' => (int)$p['can_delete']
            ];
        }

        echo json_encode([
            'success' => true,
            'roles' => $roles,
            'modules' => $modules,
            'active_role_id' => $roleId,
            'permissions' => $permMap
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// POST - Save permissions, Create role, Delete role
if ($method === 'POST') {
    // Only Administrators can modify roles and permissions
    if (!hasPermission('users', 'update')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: Only Administrators can modify roles and permissions.']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'save_permissions');

    // 1. SAVE PERMISSIONS FOR A ROLE
    if ($action === 'save_permissions') {
        $roleId = intval($data['role_id'] ?? 0);
        $permissions = $data['permissions'] ?? [];

        if ($roleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid role specified.']);
            exit;
        }

        // Check if role exists
        $stmtCheck = $pdo->prepare("SELECT role_name, is_system FROM roles WHERE id = ?");
        $stmtCheck->execute([$roleId]);
        $role = $stmtCheck->fetch();
        if (!$role) {
            echo json_encode(['success' => false, 'message' => 'Role not found.']);
            exit;
        }

        // Prevent modifying Administrator permissions (admin always has 1 on everything)
        if ($role['is_system'] == 1 && strcasecmp($role['role_name'], 'Administrator') === 0) {
            echo json_encode(['success' => false, 'message' => 'Administrator role permissions are permanently set to Full Access for security.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $modules = getPortalModules();

            foreach ($modules as $modKey => $modInfo) {
                $c = !empty($permissions[$modKey]['create']) ? 1 : 0;
                $r = !empty($permissions[$modKey]['read']) ? 1 : 0;
                $u = !empty($permissions[$modKey]['update']) ? 1 : 0;
                $d = !empty($permissions[$modKey]['delete']) ? 1 : 0;

                // Check if row exists
                $chk = $pdo->prepare("SELECT id FROM role_permissions WHERE role_id = ? AND module_key = ?");
                $chk->execute([$roleId, $modKey]);
                $permId = $chk->fetchColumn();

                if ($permId) {
                    $upd = $pdo->prepare("
                        UPDATE role_permissions 
                        SET can_create = ?, can_read = ?, can_update = ?, can_delete = ?
                        WHERE id = ?
                    ");
                    $upd->execute([$c, $r, $u, $d, $permId]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO role_permissions (role_id, module_key, can_create, can_read, can_update, can_delete)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$roleId, $modKey, $c, $r, $u, $d]);
                }
            }

            $pdo->commit();

            // Clear session permission cache so changes take effect immediately
            unset($_SESSION['user_perms']);

            echo json_encode([
                'success' => true,
                'message' => "Permissions for role '{$role['role_name']}' updated successfully!"
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2. CREATE NEW ROLE
    if ($action === 'create_role') {
        $roleName = trim($data['role_name'] ?? '');
        $description = trim($data['description'] ?? '');

        if (empty($roleName)) {
            echo json_encode(['success' => false, 'message' => 'Role name is required.']);
            exit;
        }

        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_name = ?");
            $chk->execute([$roleName]);
            if ($chk->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'A role with this name already exists.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO roles (role_name, description, is_system, created_at) VALUES (?, ?, 0, GETDATE())");
            $stmt->execute([$roleName, $description]);
            $newRoleId = $pdo->lastInsertId();

            // Initialize default permissions (read-only on dashboard, 0 on rest)
            $modules = getPortalModules();
            $insPerm = $pdo->prepare("
                INSERT INTO role_permissions (role_id, module_key, can_create, can_read, can_update, can_delete)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($modules as $modKey => $modInfo) {
                $canRead = ($modKey === 'dashboard') ? 1 : 0;
                $insPerm->execute([$newRoleId, $modKey, 0, $canRead, 0, 0]);
            }

            echo json_encode([
                'success' => true,
                'message' => "Role '{$roleName}' created successfully!",
                'role_id' => $newRoleId
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error creating role: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. DELETE ROLE
    if ($action === 'delete_role') {
        $roleId = intval($data['role_id'] ?? 0);
        if ($roleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid role specified.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT role_name, is_system FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch();
            if (!$role) {
                echo json_encode(['success' => false, 'message' => 'Role not found.']);
                exit;
            }

            if ($role['is_system'] == 1) {
                echo json_encode(['success' => false, 'message' => 'System roles (e.g. Administrator) cannot be deleted.']);
                exit;
            }

            // Check if users currently assigned to this role
            $stmtUserCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
            $stmtUserCheck->execute([$role['role_name']]);
            $assignedCount = $stmtUserCheck->fetchColumn();
            if ($assignedCount > 0) {
                echo json_encode(['success' => false, 'message' => "Cannot delete role: {$assignedCount} active user(s) currently have this role assigned. Reassign them first."]);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
            $delStmt->execute([$roleId]);

            echo json_encode(['success' => true, 'message' => "Role '{$role['role_name']}' deleted successfully."]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}
