<?php
/**
 * WIP Management Portal - Part Master API Endpoint (PHP + MSSQL)
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

// Ensure all columns exist in part_master table
try {
    $pdo->exec("
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
    ");
} catch (Exception $e) {
    // continue
}

// Ensure uploads directory exists
$uploadDir = __DIR__ . '/../uploads/parts/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

/**
 * Handle file upload helper
 */
function processPartFileUpload($fileKey, $allowedExtensions, $prefix, $partCode, $uploadDir, &$errorMsg) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$fileKey];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = "File upload failed with error code: " . $file['error'];
        return false;
    }

    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        $errorMsg = "Invalid file extension '.{$ext}' for {$fileKey}. Allowed: " . implode(', ', $allowedExtensions);
        return false;
    }

    // Maximum file size check (25MB)
    if ($file['size'] > 25 * 1024 * 1024) {
        $errorMsg = "Uploaded file exceeds maximum limit of 25MB.";
        return false;
    }

    $cleanCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode);
    if (empty($cleanCode)) {
        $cleanCode = 'pt';
    }

    $uniqueSuffix = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $safeFileName = $prefix . '_' . $cleanCode . '_' . $uniqueSuffix . '.' . $ext;
    $targetPath = $uploadDir . $safeFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/parts/' . $safeFileName;
    } else {
        $errorMsg = "Could not save uploaded {$fileKey} to server destination.";
        return false;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// Handle GET request - Fetch all Part records
if ($method === 'GET') {
    requirePermission('part_master', 'read');
    try {
        $stmt = $pdo->query("
            SELECT id, part_code, part_name, 
                   ISNULL(gross_weight, '') as gross_weight,
                   ISNULL(pdf_file, '') as pdf_file,
                   ISNULL(excel_file, '') as excel_file,
                   ISNULL(remarks, '') as remarks,
                   child_parts, 
                   ISNULL(processes, '[]') as processes, 
                   ISNULL(output_variants, '[]') as output_variants, 
                   ISNULL(fg_variant_type, '') as fg_variant_type, 
                   status, created_at, updated_at 
            FROM part_master 
            ORDER BY id DESC
        ");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $items,
            'count' => count($items)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Query error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle POST request (create, update, delete)
if ($method === 'POST') {
    // Read input (JSON or form-data)
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'create');

    // 1. DELETE ACTION
    if ($action === 'delete') {
        requirePermission('part_master', 'delete');
        $id = intval($data['id'] ?? 0);
        $partCode = trim($data['part_code'] ?? '');

        if ($id <= 0 && empty($partCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Part ID or Part Code is required for deletion.']);
            exit;
        }

        try {
            // Find existing files to delete from disk if exists
            $fileStmt = $pdo->prepare($id > 0 ? "SELECT pdf_file, excel_file FROM part_master WHERE id = ?" : "SELECT pdf_file, excel_file FROM part_master WHERE part_code = ?");
            $fileStmt->execute([$id > 0 ? $id : $partCode]);
            $filesRow = $fileStmt->fetch(PDO::FETCH_ASSOC);
            if ($filesRow) {
                if (!empty($filesRow['pdf_file']) && file_exists(__DIR__ . '/../' . $filesRow['pdf_file'])) {
                    @unlink(__DIR__ . '/../' . $filesRow['pdf_file']);
                }
                if (!empty($filesRow['excel_file']) && file_exists(__DIR__ . '/../' . $filesRow['excel_file'])) {
                    @unlink(__DIR__ . '/../' . $filesRow['excel_file']);
                }
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM part_master WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM part_master WHERE part_code = ?");
                $stmt->execute([$partCode]);
            }

            echo json_encode(['success' => true, 'message' => 'Part deleted successfully.']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Common fields
    $partCode = trim($data['part_code'] ?? '');
    $partName = trim($data['part_name'] ?? '');
    $status = trim($data['status'] ?? 'Active');
    $grossWeight = trim($data['gross_weight'] ?? '');
    $remarks = trim($data['remarks'] ?? '');
    $fgVariantType = trim($data['fg_variant_type'] ?? '');

    // Child parts handling (array or JSON string)
    $childPartsRaw = $data['child_parts'] ?? '[]';
    if (is_array($childPartsRaw)) {
        $childPartsJson = json_encode($childPartsRaw, JSON_UNESCAPED_UNICODE);
    } else {
        $childPartsJson = trim($childPartsRaw);
        if (empty($childPartsJson) || $childPartsJson === 'null') {
            $childPartsJson = '[]';
        }
    }

    // Required processes handling (array or JSON string)
    $processesRaw = $data['processes'] ?? '[]';
    if (is_array($processesRaw)) {
        $processesJson = json_encode($processesRaw, JSON_UNESCAPED_UNICODE);
    } else {
        $processesJson = trim($processesRaw);
        if (empty($processesJson) || $processesJson === 'null') {
            $processesJson = '[]';
        }
    }

    // Output variants handling (array or JSON string)
    $outputVariantsRaw = $data['output_variants'] ?? '[]';
    if (is_array($outputVariantsRaw)) {
        $outputVariantsJson = json_encode($outputVariantsRaw, JSON_UNESCAPED_UNICODE);
    } else {
        $outputVariantsJson = trim($outputVariantsRaw);
        if (empty($outputVariantsJson) || $outputVariantsJson === 'null') {
            $outputVariantsJson = '[]';
        }
    }

    // If fgVariantType is empty, build from output variants
    if (empty($fgVariantType) && !empty($outputVariantsJson) && $outputVariantsJson !== '[]') {
        $ovList = json_decode($outputVariantsJson, true);
        if (is_array($ovList) && !empty($ovList)) {
            $names = [];
            foreach ($ovList as $ov) {
                if (!empty($ov['code'])) {
                    $names[] = $ov['code'];
                } elseif (!empty($ov['name'])) {
                    $names[] = $ov['name'];
                }
            }
            $fgVariantType = implode(', ', array_slice($names, 0, 5));
        }
    }

    if (empty($status)) {
        $status = 'Active';
    }

    // Validation
    if (empty($partCode)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Part Code is required.']);
        exit;
    }
    if (empty($partName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Part Name is required.']);
        exit;
    }
    if (empty($grossWeight) || floatval($grossWeight) <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid Gross Weight per Part (> 0 kg) is required.']);
        exit;
    }

    // Validate Child Parts [RM & CHILD PART]
    $childPartsList = json_decode($childPartsJson, true);
    if (!is_array($childPartsList) || empty($childPartsList)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'At least one RM & Child Part is required.']);
        exit;
    }
    $seenCpCodes = [];
    foreach ($childPartsList as $idx => $cp) {
        $rNum = $idx + 1;
        $cpCode = trim($cp['code'] ?? '');
        $cpCodeLower = strtolower($cpCode);
        if (empty($cpCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Please select RM & Child Part in row #{$rNum}."]);
            exit;
        }
        if (isset($seenCpCodes[$cpCodeLower])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Duplicate Child Part '{$cpCode}' in row #{$rNum}. Same child part cannot be added multiple times."]);
            exit;
        }
        $seenCpCodes[$cpCodeLower] = true;

        if (!isset($cp['qty']) || floatval($cp['qty']) <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Quantity/Ratio must be greater than 0 for Child Part in row #{$rNum}."]);
            exit;
        }
    }

    // Validate Required Processes [PROCESS]
    $processesList = json_decode($processesJson, true);
    if (!is_array($processesList) || empty($processesList)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'At least one Process is required.']);
        exit;
    }
    foreach ($processesList as $idx => $prc) {
        $rNum = $idx + 1;
        if (empty($prc['name']) && empty($prc['code'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Please select a Process in row #{$rNum}."]);
            exit;
        }
    }

    // Validate Output Variants [Output Part Code, Output Part Name]
    $outputVariantsList = json_decode($outputVariantsJson, true);
    if (!is_array($outputVariantsList) || empty($outputVariantsList)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'At least one Output Variant is required.']);
        exit;
    }
    $seenOvCodes = [];
    foreach ($outputVariantsList as $idx => $ov) {
        $rNum = $idx + 1;
        $ovCode = trim($ov['code'] ?? '');
        $ovCodeLower = strtolower($ovCode);
        if (empty($ovCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Output Part Code is required in Output Variant row #{$rNum}."]);
            exit;
        }
        if (isset($seenOvCodes[$ovCodeLower])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Duplicate Output Part Code '{$ovCode}' in row #{$rNum}. Same part code is not allowed."]);
            exit;
        }
        $seenOvCodes[$ovCodeLower] = true;

        if (empty($ov['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Output Part Name is required in Output Variant row #{$rNum}."]);
            exit;
        }
        if (!isset($ov['qty']) || floatval($ov['qty']) <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Quantity/Ratio must be greater than 0 in Output Variant row #{$rNum}."]);
            exit;
        }
    }

    // Process file uploads
    $uploadErr = '';
    $uploadedPdfPath = processPartFileUpload('pdf_file', ['pdf'], 'pdf', $partCode, $uploadDir, $uploadErr);
    if ($uploadedPdfPath === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $uploadErr]);
        exit;
    }

    $uploadedExcelPath = processPartFileUpload('excel_file', ['xlsx', 'xls', 'csv'], 'excel', $partCode, $uploadDir, $uploadErr);
    if ($uploadedExcelPath === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $uploadErr]);
        exit;
    }

    // 2. UPDATE ACTION
    if ($action === 'update') {
        requirePermission('part_master', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid ID is required for update.']);
            exit;
        }

        try {
            // Check if Part Code is taken by another row
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM part_master WHERE part_code = ? AND id != ?");
            $checkStmt->execute([$partCode, $id]);
            $exists = $checkStmt->fetch();
            if ($exists && $exists['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Part Code '{$partCode}' is already in use by another part."]);
                exit;
            }

            // Build update query dynamically to preserve existing files when no new file is uploaded
            $updateFields = [
                "part_code = ?",
                "part_name = ?",
                "gross_weight = ?",
                "remarks = ?",
                "child_parts = ?",
                "processes = ?",
                "output_variants = ?",
                "fg_variant_type = ?",
                "status = ?",
                "updated_at = GETDATE()"
            ];
            $params = [
                $partCode,
                $partName,
                $grossWeight,
                $remarks,
                $childPartsJson,
                $processesJson,
                $outputVariantsJson,
                $fgVariantType,
                $status
            ];

            if ($uploadedPdfPath !== null) {
                $updateFields[] = "pdf_file = ?";
                $params[] = $uploadedPdfPath;
            }
            if ($uploadedExcelPath !== null) {
                $updateFields[] = "excel_file = ?";
                $params[] = $uploadedExcelPath;
            }

            $params[] = $id;
            $updateSql = "UPDATE part_master SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($params);

            // Retrieve updated row
            $getStmt = $pdo->prepare("SELECT * FROM part_master WHERE id = ?");
            $getStmt->execute([$id]);
            $updatedItem = $getStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => "Part '{$partCode}' updated successfully.",
                'data' => $updatedItem
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. CREATE ACTION
    requirePermission('part_master', 'create');
    try {
        // Check for duplicate Part Code
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM part_master WHERE part_code = ?");
        $checkStmt->execute([$partCode]);
        $exists = $checkStmt->fetch();
        if ($exists && $exists['cnt'] > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => "Part Code '{$partCode}' already exists."]);
            exit;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO part_master (
                part_code, part_name, gross_weight, pdf_file, excel_file, remarks,
                child_parts, processes, output_variants, fg_variant_type, status,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $insertStmt->execute([
            $partCode, 
            $partName, 
            $grossWeight, 
            $uploadedPdfPath, 
            $uploadedExcelPath, 
            $remarks,
            $childPartsJson, 
            $processesJson, 
            $outputVariantsJson, 
            $fgVariantType, 
            $status
        ]);

        $newId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Part '{$partCode}' created successfully.",
            'data' => [
                'id' => $newId,
                'part_code' => $partCode,
                'part_name' => $partName,
                'gross_weight' => $grossWeight,
                'pdf_file' => $uploadedPdfPath,
                'excel_file' => $uploadedExcelPath,
                'remarks' => $remarks,
                'child_parts' => $childPartsJson,
                'processes' => $processesJson,
                'output_variants' => $outputVariantsJson,
                'fg_variant_type' => $fgVariantType,
                'status' => $status
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
