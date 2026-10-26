<?php
/**
 * WIP Management Portal - MSSQL Database Setup Script
 * Run from CLI or browser: http://localhost/wip-excel/setup_database.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "====================================================\n";
echo " WIP Management Portal - MSSQL Database Setup\n";
echo "====================================================\n\n";

$serversToTry = [
    (isset($_SERVER['COMPUTERNAME']) ? $_SERVER['COMPUTERNAME'] . '\\SQLEXPRESS' : 'DESKTOP-1P9OI55\\SQLEXPRESS'),
    '.\\SQLEXPRESS',
    'localhost\\SQLEXPRESS',
    '(local)\\SQLEXPRESS',
    '127.0.0.1\\SQLEXPRESS',
    'localhost',
    '127.0.0.1'
];
$connectedServer = null;
$pdoMaster = null;

foreach ($serversToTry as $srv) {
    try {
        $dsn = "sqlsrv:Server={$srv};Database=master;TrustServerCertificate=true";
        $pdoMaster = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $connectedServer = $srv;
        echo "[OK] Connected to MSSQL Server on: {$srv}\n";
        break;
    } catch (PDOException $e) {
        // try next
    }
}

if (!$pdoMaster) {
    die("[ERROR] Could not connect to any MSSQL Server instance (tried: " . implode(', ', $serversToTry) . ")\n");
}

$dbName = 'wip_excel_db';
$dbDir = __DIR__ . '\\database';

if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0777, true);
}

// 1. Check & Create Database
try {
    $checkDb = $pdoMaster->query("SELECT database_id FROM sys.databases WHERE name = '{$dbName}'")->fetch();

    if (!$checkDb) {
        echo "[INFO] Creating database '{$dbName}'...\n";
        try {
            $pdoMaster->exec("CREATE DATABASE [{$dbName}]");
            echo "[OK] Database '{$dbName}' created successfully.\n";
        } catch (PDOException $eDb) {
            // Fallback with custom file paths
            $mdfPath = $dbDir . '\\wip_excel_db.mdf';
            $ldfPath = $dbDir . '\\wip_excel_db_log.ldf';
            $createDbSql = "
                CREATE DATABASE [{$dbName}]
                ON PRIMARY (
                    NAME = N'{$dbName}',
                    FILENAME = N'{$mdfPath}'
                )
                LOG ON (
                    NAME = N'{$dbName}_log',
                    FILENAME = N'{$ldfPath}'
                )
            ";
            $pdoMaster->exec($createDbSql);
            echo "[OK] Database '{$dbName}' created successfully with explicit files.\n";
        }
    } else {
        echo "[OK] Database '{$dbName}' already exists.\n";
    }
} catch (PDOException $e) {
    die("[ERROR] Failed while creating database: " . $e->getMessage() . "\n");
}

// 2. Connect to the wip_excel_db
try {
    $dsnApp = "sqlsrv:Server={$connectedServer};Database={$dbName};TrustServerCertificate=true";
    $pdoApp = new PDO($dsnApp, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "[OK] Connected to '{$dbName}'.\n";
} catch (PDOException $e) {
    die("[ERROR] Failed to connect to '{$dbName}': " . $e->getMessage() . "\n");
}

// 3. Create users Table
try {
    $createTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='users' AND xtype='U')
    BEGIN
        CREATE TABLE users (
            id INT IDENTITY(1,1) PRIMARY KEY,
            email NVARCHAR(150) NOT NULL UNIQUE,
            password NVARCHAR(255) NOT NULL,
            full_name NVARCHAR(100) NOT NULL,
            role NVARCHAR(50) DEFAULT 'User',
            created_at DATETIME DEFAULT GETDATE(),
            last_login DATETIME NULL
        );
    END
    ";
    $pdoApp->exec($createTableSql);
    // Create roles Table
    $createRolesTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='roles' AND xtype='U')
    BEGIN
        CREATE TABLE roles (
            id INT IDENTITY(1,1) PRIMARY KEY,
            role_name NVARCHAR(50) NOT NULL UNIQUE,
            description NVARCHAR(255) NULL,
            is_system BIT DEFAULT 0,
            created_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createRolesTableSql);
    echo "[OK] 'roles' table is ready.\n";

    // Create role_permissions Table
    $createRolePermsTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='role_permissions' AND xtype='U')
    BEGIN
        CREATE TABLE role_permissions (
            id INT IDENTITY(1,1) PRIMARY KEY,
            role_id INT NOT NULL,
            module_key NVARCHAR(50) NOT NULL,
            can_create BIT DEFAULT 0,
            can_read BIT DEFAULT 0,
            can_update BIT DEFAULT 0,
            can_delete BIT DEFAULT 0,
            created_at DATETIME DEFAULT GETDATE(),
            CONSTRAINT FK_role_perm_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT UQ_role_module UNIQUE (role_id, module_key)
        );
    END
    ";
    $pdoApp->exec($createRolePermsTableSql);
    echo "[OK] 'role_permissions' table is ready.\n";

    // Seed default roles and permissions
    $defaultRoles = [
        ['role_name' => 'Administrator', 'description' => 'Full unconstrained system access', 'is_system' => 1, 'all' => 1],
        ['role_name' => 'Supervisor', 'description' => 'Production & Material supervisor', 'is_system' => 0, 'all' => 0],
        ['role_name' => 'Store Keeper', 'description' => 'Store and inward keeper', 'is_system' => 0, 'all' => 0],
        ['role_name' => 'Operator', 'description' => 'DPR and production operator', 'is_system' => 0, 'all' => 0],
        ['role_name' => 'User', 'description' => 'Standard user with view access', 'is_system' => 0, 'all' => 0]
    ];
    $portalModules = ['dashboard', 'vendor_master', 'customer_master', 'child_part_master', 'process_master', 'part_master', 'child_part_in', 'mip', 'production', 'fg_store', 'reports', 'users'];

    foreach ($defaultRoles as $r) {
        $chkRole = $pdoApp->prepare("SELECT id FROM roles WHERE role_name = ?");
        $chkRole->execute([$r['role_name']]);
        $existingRole = $chkRole->fetch();

        if (!$existingRole) {
            $insRole = $pdoApp->prepare("INSERT INTO roles (role_name, description, is_system) VALUES (?, ?, ?)");
            $insRole->execute([$r['role_name'], $r['description'], $r['is_system']]);
            $roleId = $pdoApp->lastInsertId();
        } else {
            $roleId = $existingRole['id'];
        }

        foreach ($portalModules as $mod) {
            $chkPerm = $pdoApp->prepare("SELECT id FROM role_permissions WHERE role_id = ? AND module_key = ?");
            $chkPerm->execute([$roleId, $mod]);
            if (!$chkPerm->fetch()) {
                $canAll = $r['all'] ? 1 : ($mod === 'dashboard' ? 1 : 0);
                $insPerm = $pdoApp->prepare("INSERT INTO role_permissions (role_id, module_key, can_create, can_read, can_update, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
                $insPerm->execute([$roleId, $mod, $canAll, $canAll || $r['role_name'] === 'User' || $r['role_name'] === 'Supervisor' || $r['role_name'] === 'Store Keeper' || $r['role_name'] === 'Operator' ? 1 : 0, $canAll, $canAll]);
            }
        }
    }
    echo "[OK] Default roles and permissions are configured.\n";

    // Create rm_master Table
    $createRmTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rm_master' AND xtype='U')
    BEGIN
        CREATE TABLE rm_master (
            id INT IDENTITY(1,1) PRIMARY KEY,
            rm_code NVARCHAR(50) NOT NULL UNIQUE,
            rm_name NVARCHAR(150) NOT NULL,
            grade_spec NVARCHAR(100) NULL,
            size_dimension NVARCHAR(100) NULL,
            current_stock DECIMAL(18, 3) NOT NULL DEFAULT 0.000,
            uom NVARCHAR(20) NOT NULL,
            status NVARCHAR(50) DEFAULT 'Active',
            is_active BIT DEFAULT 1,
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='rm_master' AND COLUMN_NAME='current_stock')
        BEGIN
            ALTER TABLE rm_master ADD current_stock DECIMAL(18, 3) NOT NULL DEFAULT 0.000;
        END
    END
    ";
    $pdoApp->exec($createRmTableSql);
    echo "[OK] 'rm_master' table is ready.\n";

    // Create vendor_master Table
    $createVendorTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='vendor_master' AND xtype='U')
    BEGIN
        CREATE TABLE vendor_master (
            id INT IDENTITY(1,1) PRIMARY KEY,
            vendor_code NVARCHAR(50) NULL,
            vendor_name NVARCHAR(150) NOT NULL,
            contact_person NVARCHAR(100) NULL,
            phone NVARCHAR(50) NULL,
            email NVARCHAR(100) NULL,
            gstin NVARCHAR(50) NULL,
            address NVARCHAR(255) NULL,
            status NVARCHAR(50) DEFAULT 'Active',
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createVendorTableSql);
    echo "[OK] 'vendor_master' table is ready.\n";

    // Create customer_master Table
    $createCustomerTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='customer_master' AND xtype='U')
    BEGIN
        CREATE TABLE customer_master (
            id INT IDENTITY(1,1) PRIMARY KEY,
            customer_code NVARCHAR(50) NULL,
            customer_name NVARCHAR(150) NOT NULL,
            contact_person NVARCHAR(100) NULL,
            phone NVARCHAR(50) NULL,
            email NVARCHAR(100) NULL,
            gstin NVARCHAR(50) NULL,
            address NVARCHAR(255) NULL,
            status NVARCHAR(50) DEFAULT 'Active',
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createCustomerTableSql);
    echo "[OK] 'customer_master' table is ready.\n";

    // Create child_part_master Table
    $createChildPartTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='child_part_master' AND xtype='U')
    BEGIN
        CREATE TABLE child_part_master (
            id INT IDENTITY(1,1) PRIMARY KEY,
            part_code NVARCHAR(50) NOT NULL UNIQUE,
            part_name NVARCHAR(150) NOT NULL,
            grade_spec NVARCHAR(100) NULL,
            size_dimension NVARCHAR(100) NULL,
            nos_per_kg NVARCHAR(50) NULL,
            current_stock DECIMAL(18, 3) NOT NULL DEFAULT 0.000,
            uom NVARCHAR(20) NOT NULL,
            status NVARCHAR(50) DEFAULT 'Active',
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='child_part_master' AND COLUMN_NAME='current_stock')
        BEGIN
            ALTER TABLE child_part_master ADD current_stock DECIMAL(18, 3) NOT NULL DEFAULT 0.000;
        END
    END
    ";
    $pdoApp->exec($createChildPartTableSql);
    echo "[OK] 'child_part_master' table is ready.\n";

    // Create part_master Table
    $createPartTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='part_master' AND xtype='U')
    BEGIN
        CREATE TABLE part_master (
            id INT IDENTITY(1,1) PRIMARY KEY,
            part_code NVARCHAR(50) NOT NULL UNIQUE,
            part_name NVARCHAR(150) NOT NULL,
            gross_weight NVARCHAR(50) NULL,
            pdf_file NVARCHAR(255) NULL,
            excel_file NVARCHAR(255) NULL,
            remarks NVARCHAR(MAX) NULL,
            child_parts NVARCHAR(MAX) NULL,
            processes NVARCHAR(MAX) NULL,
            output_variants NVARCHAR(MAX) NULL,
            fg_variant_type NVARCHAR(255) NULL,
            status NVARCHAR(50) DEFAULT 'Active',
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='part_master' AND COLUMN_NAME='processes')
        BEGIN
            ALTER TABLE part_master ADD processes NVARCHAR(MAX) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='part_master' AND COLUMN_NAME='fg_variant_type')
        BEGIN
            ALTER TABLE part_master ADD fg_variant_type NVARCHAR(255) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='part_master' AND COLUMN_NAME='gross_weight')
        BEGIN
            ALTER TABLE part_master ADD gross_weight NVARCHAR(50) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='part_master' AND COLUMN_NAME='pdf_file')
        BEGIN
            ALTER TABLE part_master ADD pdf_file NVARCHAR(255) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='part_master' AND COLUMN_NAME='excel_file')
        BEGIN
            ALTER TABLE part_master ADD excel_file NVARCHAR(255) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='part_master' AND COLUMN_NAME='remarks')
        BEGIN
            ALTER TABLE part_master ADD remarks NVARCHAR(MAX) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='part_master' AND COLUMN_NAME='output_variants')
        BEGIN
            ALTER TABLE part_master ADD output_variants NVARCHAR(MAX) NULL;
        END
    END
    ";
    $pdoApp->exec($createPartTableSql);
    echo "[OK] 'part_master' table is ready.\n";

    // Create process_master Table
    $createProcessTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='process_master' AND xtype='U')
    BEGIN
        CREATE TABLE process_master (
            id INT IDENTITY(1,1) PRIMARY KEY,
            process_code NVARCHAR(50) NOT NULL UNIQUE,
            process_name NVARCHAR(150) NOT NULL,
            status NVARCHAR(50) DEFAULT 'Active',
            remarks NVARCHAR(500) NULL,
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createProcessTableSql);
    echo "[OK] 'process_master' table is ready.\n";

    // Create rm_inward Table
    $createRmInwardTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='rm_inward' AND xtype='U')
    BEGIN
        CREATE TABLE rm_inward (
            id INT IDENTITY(1,1) PRIMARY KEY,
            inward_no NVARCHAR(50) NOT NULL,
            inward_date DATE NOT NULL,
            vendor_id INT NULL,
            vendor_name NVARCHAR(150) NOT NULL,
            invoice_no NVARCHAR(50) NULL,
            invoice_date DATE NULL,
            rm_code NVARCHAR(50) NOT NULL,
            rm_name NVARCHAR(150) NOT NULL,
            received_qty DECIMAL(18, 3) NOT NULL,
            uom NVARCHAR(20) NOT NULL DEFAULT 'KG',
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createRmInwardTableSql);
    echo "[OK] 'rm_inward' table is ready.\n";

    // Create child_part_inward Table
    $createChildPartInwardTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='child_part_inward' AND xtype='U')
    BEGIN
        CREATE TABLE child_part_inward (
            id INT IDENTITY(1,1) PRIMARY KEY,
            inward_no NVARCHAR(50) NOT NULL,
            inward_date DATE NOT NULL,
            vendor_id INT NULL,
            vendor_name NVARCHAR(150) NOT NULL,
            invoice_no NVARCHAR(50) NULL,
            invoice_date DATE NULL,
            part_code NVARCHAR(50) NOT NULL,
            part_name NVARCHAR(150) NOT NULL,
            received_qty DECIMAL(18, 3) NOT NULL,
            uom NVARCHAR(20) NOT NULL DEFAULT 'NOS',
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createChildPartInwardTableSql);
    echo "[OK] 'child_part_inward' table is ready.\n";

    // Create material_issue Table (MIP)
    $createMipTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='material_issue' AND xtype='U')
    BEGIN
        CREATE TABLE material_issue (
            id INT IDENTITY(1,1) PRIMARY KEY,
            issue_no NVARCHAR(50) NOT NULL UNIQUE,
            issue_date DATE NOT NULL,
            customer_name NVARCHAR(150) NULL,
            customer_code NVARCHAR(50) NULL,
            so_number NVARCHAR(100) NOT NULL,
            part_code NVARCHAR(50) NOT NULL,
            part_name NVARCHAR(150) NOT NULL,
            issued_qty DECIMAL(18, 3) NOT NULL,
            uom NVARCHAR(20) NOT NULL DEFAULT 'NOS',
            received_by NVARCHAR(150) NOT NULL,
            remarks NVARCHAR(500) NULL,
            child_parts_details NVARCHAR(MAX) NULL,
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createMipTableSql);
    echo "[OK] 'material_issue' table is ready.\n";

    // Create production_entry Table
    $createPrdTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='production_entry' AND xtype='U')
    BEGIN
        CREATE TABLE production_entry (
            id INT IDENTITY(1,1) PRIMARY KEY,
            mip_no NVARCHAR(50) NULL,
            customer_name NVARCHAR(150) NULL,
            customer_code NVARCHAR(50) NULL,
            so_number NVARCHAR(100) NULL,
            work_order NVARCHAR(100) NULL,
            part_code NVARCHAR(50) NOT NULL,
            part_name NVARCHAR(150) NOT NULL,
            department NVARCHAR(100) NULL,
            received_by NVARCHAR(150) NULL,
            process_name NVARCHAR(100) NOT NULL,
            shift NVARCHAR(50) NULL,
            target_qty DECIMAL(18, 3) NOT NULL,
            ok_qty DECIMAL(18, 3) NOT NULL,
            rework_qty DECIMAL(18, 3) DEFAULT 0,
            rejected_qty DECIMAL(18, 3) DEFAULT 0,
            operator_name NVARCHAR(150) NOT NULL,
            uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
            status NVARCHAR(50) DEFAULT 'Completed',
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    ELSE
    BEGIN
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='production_entry' AND COLUMN_NAME='customer_name')
        BEGIN
            ALTER TABLE production_entry ADD customer_name NVARCHAR(150) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='production_entry' AND COLUMN_NAME='customer_code')
        BEGIN
            ALTER TABLE production_entry ADD customer_code NVARCHAR(50) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='production_entry' AND COLUMN_NAME='so_number')
        BEGIN
            ALTER TABLE production_entry ADD so_number NVARCHAR(100) NULL;
        END
        IF EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='production_entry' AND COLUMN_NAME='shift' AND IS_NULLABLE='NO')
        BEGIN
            ALTER TABLE production_entry ALTER COLUMN shift NVARCHAR(50) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='production_entry' AND COLUMN_NAME='rework_label')
        BEGIN
            ALTER TABLE production_entry ADD rework_label NVARCHAR(20) NULL;
        END
        IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='production_entry' AND COLUMN_NAME='rework_iteration')
        BEGIN
            ALTER TABLE production_entry ADD rework_iteration INT DEFAULT 0;
        END
    END
    ";
    $pdoApp->exec($createPrdTableSql);
    echo "[OK] 'production_entry' table is ready.\n";

    // Create fg_inventory Table (Summary per part code with accumulated stock)
    $createFgInventoryTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='fg_inventory' AND xtype='U')
    BEGIN
        CREATE TABLE fg_inventory (
            id INT IDENTITY(1,1) PRIMARY KEY,
            part_code NVARCHAR(50) NOT NULL,
            part_name NVARCHAR(150) NOT NULL,
            total_ok_qty DECIMAL(18, 3) NOT NULL DEFAULT 0.000,
            uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
            last_mip_no NVARCHAR(50) NULL,
            last_production_date DATE NULL,
            created_at DATETIME DEFAULT GETDATE(),
            updated_at DATETIME DEFAULT GETDATE()
        );
    END
    IF EXISTS (SELECT * FROM sys.key_constraints WHERE name = 'UQ_fg_inv_part_code')
    BEGIN
        ALTER TABLE fg_inventory DROP CONSTRAINT UQ_fg_inv_part_code;
    END
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='fg_inventory' AND COLUMN_NAME='customer_name')
    BEGIN
        ALTER TABLE fg_inventory ADD customer_name NVARCHAR(150) NULL;
    END
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='fg_inventory' AND COLUMN_NAME='variant_stock_details')
    BEGIN
        ALTER TABLE fg_inventory ADD variant_stock_details NVARCHAR(MAX) NULL;
    END
    ";
    $pdoApp->exec($createFgInventoryTableSql);
    echo "[OK] 'fg_inventory' table is ready.\n";

    // Create fg_inward_logs Table (Inward transaction logs)
    $createFgInwardLogsTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='fg_inward_logs' AND xtype='U')
    BEGIN
        CREATE TABLE fg_inward_logs (
            id INT IDENTITY(1,1) PRIMARY KEY,
            inward_no NVARCHAR(50) NOT NULL,
            mip_no NVARCHAR(50) NOT NULL,
            production_date DATE NULL,
            part_code NVARCHAR(50) NOT NULL,
            part_name NVARCHAR(150) NOT NULL,
            ok_qty DECIMAL(18, 3) NOT NULL,
            uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
            qc_status NVARCHAR(50) DEFAULT 'QC Passed',
            work_order NVARCHAR(100) NULL,
            received_by NVARCHAR(150) NULL,
            remarks NVARCHAR(500) NULL,
            created_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createFgInwardLogsTableSql);
    echo "[OK] 'fg_inward_logs' table is ready.\n";

    // Create fg_dispatch_logs Table (Dispatch and outward transaction logs)
    $createFgDispatchLogsTableSql = "
    IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='fg_dispatch_logs' AND xtype='U')
    BEGIN
        CREATE TABLE fg_dispatch_logs (
            id INT IDENTITY(1,1) PRIMARY KEY,
            dispatch_no NVARCHAR(50) NOT NULL,
            dispatch_date DATE NOT NULL,
            invoice_ref NVARCHAR(100) NOT NULL,
            part_code NVARCHAR(50) NOT NULL,
            part_name NVARCHAR(150) NOT NULL,
            batch_no NVARCHAR(50) NULL,
            dispatch_qty DECIMAL(18, 3) NOT NULL,
            uom NVARCHAR(20) NOT NULL DEFAULT 'PCS',
            customer NVARCHAR(200) NOT NULL,
            dispatched_by NVARCHAR(150) NOT NULL,
            remarks NVARCHAR(500) NULL,
            created_at DATETIME DEFAULT GETDATE()
        );
    END
    ";
    $pdoApp->exec($createFgDispatchLogsTableSql);
    echo "[OK] 'fg_dispatch_logs' table is ready.\n";
} catch (PDOException $e) {
    die("[ERROR] Failed to create tables: " . $e->getMessage() . "\n");
}

// 4. Seed Default Users
$seedUsers = [
    [
        'email' => 'admin@wip.com',
        'password' => 'admin123',
        'full_name' => 'Administrator',
        'role' => 'Administrator'
    ],
    [
        'email' => 'user@wip.com',
        'password' => 'user123',
        'full_name' => 'Demo User',
        'role' => 'User'
    ]
];

foreach ($seedUsers as $u) {
    try {
        $stmt = $pdoApp->prepare("SELECT COUNT(*) AS cnt FROM users WHERE email = ?");
        $stmt->execute([$u['email']]);
        $row = $stmt->fetch();

        if ($row['cnt'] == 0) {
            $insertStmt = $pdoApp->prepare("INSERT INTO users (email, password, full_name, role) VALUES (?, ?, ?, ?)");
            $insertStmt->execute([$u['email'], $u['password'], $u['full_name'], $u['role']]);
            echo "[OK] Created user: {$u['email']} (Password: {$u['password']}) [Role: {$u['role']}]\n";
        } else {
            echo "[INFO] User '{$u['email']}' already exists.\n";
        }
    } catch (PDOException $e) {
        echo "[ERROR] Failed to seed user {$u['email']}: " . $e->getMessage() . "\n";
    }
}

echo "\n====================================================\n";
echo " Setup Completed Successfully!\n";
echo " Default Logins:\n";
echo " 1) Email: admin@wip.com | Password: admin123\n";
echo " 2) Email: user@wip.com  | Password: user123\n";
echo "====================================================\n";
