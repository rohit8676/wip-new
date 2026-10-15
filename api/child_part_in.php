<?php
/**
 * WIP Management Portal - Child Part Inward API Endpoint (PHP + MSSQL)
 * Stores each child part item as a separate row in child_part_inward.
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

// Handle GET request - Fetch all Child Part Inward records (grouped by inward_no)
if ($method === 'GET') {
    requirePermission('child_part_in', 'read');
    try {
        $stmt = $pdo->query("
            SELECT id, inward_no, CONVERT(VARCHAR(10), inward_date, 120) as inward_date, 
                   vendor_name, invoice_no, CONVERT(VARCHAR(10), invoice_date, 120) as invoice_date, 
                   part_code, part_name, received_qty, uom, created_at 
            FROM child_part_inward 
            ORDER BY id DESC
        ");
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group rows by inward_no
        $grouped = [];
        foreach ($rawRows as $row) {
            $inwNo = !empty($row['inward_no']) ? $row['inward_no'] : ('ID_' . $row['id']);

            if (!isset($grouped[$inwNo])) {
                $grouped[$inwNo] = [
                    'id' => $row['id'],
                    'inward_no' => $row['inward_no'] ?? '',
                    'inward_date' => $row['inward_date'] ?? '',
                    'vendor_name' => $row['vendor_name'] ?? '',
                    'invoice_no' => $row['invoice_no'] ?? '',
                    'invoice_date' => $row['invoice_date'] ?? '',
                    'items' => [],
                    'received_qty' => 0,
                    'uom' => $row['uom'] ?? 'NOS',
                    'created_at' => $row['created_at'] ?? ''
                ];
            }

            $grouped[$inwNo]['items'][] = [
                'id' => $row['id'],
                'part_code' => $row['part_code'] ?? '',
                'part_name' => $row['part_name'] ?? '',
                'received_qty' => floatval($row['received_qty'] ?? 0),
                'uom' => $row['uom'] ?? 'NOS'
            ];
            $grouped[$inwNo]['received_qty'] += floatval($row['received_qty'] ?? 0);
        }

        $items = [];
        foreach ($grouped as $entry) {
            $itemsList = $entry['items'];
            $itemCount = count($itemsList);
            $firstItem = $itemCount > 0 ? $itemsList[0] : null;

            $entry['item_count'] = $itemCount;
            $entry['part_code'] = ($itemCount === 1) ? ($firstItem['part_code'] ?? '') : (($firstItem['part_code'] ?? '') . " (+" . ($itemCount - 1) . " more)");
            $entry['part_name'] = ($itemCount === 1) ? ($firstItem['part_name'] ?? '') : ($itemCount . " Child Part Items");
            $entry['uom'] = $firstItem['uom'] ?? ($entry['uom'] ?? 'NOS');
            $entry['items_data'] = json_encode($itemsList, JSON_UNESCAPED_UNICODE);

            $items[] = $entry;
        }

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
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'create');

    // 1. DELETE ACTION
    if ($action === 'delete') {
        requirePermission('child_part_in', 'delete');
        $id = intval($data['id'] ?? 0);
        $inwardNo = trim($data['inward_no'] ?? '');

        if ($id <= 0 && empty($inwardNo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Inward ID or Inward No is required for deletion.']);
            exit;
        }

        try {
            // Find inward_no if only id was passed
            if (empty($inwardNo) && $id > 0) {
                $findStmt = $pdo->prepare("SELECT inward_no FROM child_part_inward WHERE id = ?");
                $findStmt->execute([$id]);
                $found = $findStmt->fetch();
                if ($found && !empty($found['inward_no'])) {
                    $inwardNo = $found['inward_no'];
                }
            }

            $itemsToRevert = [];
            if (!empty($inwardNo)) {
                $itemsStmt = $pdo->prepare("SELECT part_code, received_qty FROM child_part_inward WHERE inward_no = ?");
                $itemsStmt->execute([$inwardNo]);
                $itemsToRevert = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            } else if ($id > 0) {
                $itemsStmt = $pdo->prepare("SELECT part_code, received_qty FROM child_part_inward WHERE id = ?");
                $itemsStmt->execute([$id]);
                $itemsToRevert = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $pdo->beginTransaction();

            // Revert stock from child_part_master
            $revertStockStmt = $pdo->prepare("
                UPDATE child_part_master 
                SET current_stock = CASE WHEN COALESCE(current_stock, 0) >= ? THEN current_stock - ? ELSE 0 END,
                    updated_at = GETDATE()
                WHERE part_code = ?
            ");
            foreach ($itemsToRevert as $revIt) {
                $revQty = floatval($revIt['received_qty'] ?? 0);
                if ($revQty > 0 && !empty($revIt['part_code'])) {
                    $revertStockStmt->execute([$revQty, $revQty, $revIt['part_code']]);
                }
            }

            if (!empty($inwardNo)) {
                $stmt = $pdo->prepare("DELETE FROM child_part_inward WHERE inward_no = ?");
                $stmt->execute([$inwardNo]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM child_part_inward WHERE id = ?");
                $stmt->execute([$id]);
            }

            $pdo->commit();

            echo json_encode(['success' => true, 'message' => 'Child Part Inward entry deleted successfully.']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Common fields
    $inwardNo = trim($data['inward_no'] ?? '');
    $inwardDate = trim($data['inward_date'] ?? date('Y-m-d'));
    $vendorName = trim($data['vendor_name'] ?? '');
    $invoiceNo = trim($data['invoice_no'] ?? '');
    $invoiceDate = trim($data['invoice_date'] ?? '');

    if (empty($invoiceDate)) {
        $invoiceDate = null;
    }

    if (empty($vendorName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Vendor is required.']);
        exit;
    }

    // Process all items
    $itemsList = [];
    $totalQty = 0;
    $primaryUom = 'NOS';

    if (!empty($data['items']) && is_array($data['items'])) {
        foreach ($data['items'] as $it) {
            $itPartCode = trim($it['part_code'] ?? '');
            $itPartName = trim($it['part_name'] ?? '');
            $itQty = floatval($it['received_qty'] ?? 0);
            $itUom = trim($it['uom'] ?? 'NOS');

            if (empty($itPartCode) || $itQty <= 0) continue;

            if (empty($itPartName)) {
                $cpLk = $pdo->prepare("SELECT part_name, uom FROM child_part_master WHERE part_code = ?");
                $cpLk->execute([$itPartCode]);
                $f = $cpLk->fetch();
                if ($f) {
                    $itPartName = $f['part_name'];
                    if (empty($it['uom']) && !empty($f['uom'])) {
                        $itUom = $f['uom'];
                    }
                }
            }

            $itemsList[] = [
                'part_code' => $itPartCode,
                'part_name' => $itPartName,
                'received_qty' => $itQty,
                'uom' => $itUom
            ];
            $totalQty += $itQty;
            $primaryUom = $itUom;
        }
    } elseif (!empty($data['part_code']) && floatval($data['received_qty'] ?? 0) > 0) {
        $singlePartCode = trim($data['part_code']);
        $singlePartName = trim($data['part_name'] ?? '');
        $singleQty = floatval($data['received_qty']);
        $singleUom = trim($data['uom'] ?? 'NOS');

        if (empty($singlePartName)) {
            $cpLk = $pdo->prepare("SELECT part_name, uom FROM child_part_master WHERE part_code = ?");
            $cpLk->execute([$singlePartCode]);
            $f = $cpLk->fetch();
            if ($f) {
                $singlePartName = $f['part_name'];
                if (empty($data['uom']) && !empty($f['uom'])) {
                    $singleUom = $f['uom'];
                }
            }
        }

        $itemsList[] = [
            'part_code' => $singlePartCode,
            'part_name' => $singlePartName,
            'received_qty' => $singleQty,
            'uom' => $singleUom
        ];
        $totalQty = $singleQty;
        $primaryUom = $singleUom;
    }

    if (empty($itemsList)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please provide at least one valid Child Part item with positive quantity.']);
        exit;
    }

    $firstItem = $itemsList[0];
    $itemCount = count($itemsList);
    $summaryPartCode = ($itemCount === 1) ? $firstItem['part_code'] : ($firstItem['part_code'] . " (+" . ($itemCount - 1) . " more)");
    $summaryPartName = ($itemCount === 1) ? $firstItem['part_name'] : ($itemCount . " Child Part Items");
    $itemsJson = json_encode($itemsList, JSON_UNESCAPED_UNICODE);

    // 2. UPDATE ACTION
    if ($action === 'update') {
        requirePermission('child_part_in', 'update');
        $id = intval($data['id'] ?? 0);
        if ($id <= 0 && empty($inwardNo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid ID or Inward No is required for update.']);
            exit;
        }

        try {
            if (empty($inwardNo) && $id > 0) {
                $lookupStmt = $pdo->prepare("SELECT inward_no FROM child_part_inward WHERE id = ?");
                $lookupStmt->execute([$id]);
                $cur = $lookupStmt->fetch();
                if ($cur && !empty($cur['inward_no'])) {
                    $inwardNo = $cur['inward_no'];
                }
            }

            if (empty($inwardNo)) {
                $inwardNo = "CP-INW-" . date('y') . "-" . str_pad($id, 4, '0', STR_PAD_LEFT);
            }

            $pdo->beginTransaction();

            // 1. Fetch old items for this inward_no to revert their stock in child_part_master
            $oldItemsStmt = $pdo->prepare("SELECT part_code, received_qty FROM child_part_inward WHERE inward_no = ?");
            $oldItemsStmt->execute([$inwardNo]);
            $oldItems = $oldItemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Revert old stock
            $revertStockStmt = $pdo->prepare("
                UPDATE child_part_master 
                SET current_stock = CASE WHEN COALESCE(current_stock, 0) >= ? THEN current_stock - ? ELSE 0 END,
                    updated_at = GETDATE()
                WHERE part_code = ?
            ");
            foreach ($oldItems as $oldIt) {
                $oldQty = floatval($oldIt['received_qty'] ?? 0);
                if ($oldQty > 0 && !empty($oldIt['part_code'])) {
                    $revertStockStmt->execute([$oldQty, $oldQty, $oldIt['part_code']]);
                }
            }

            // 3. Delete existing records for this inward_no
            $delStmt = $pdo->prepare("DELETE FROM child_part_inward WHERE inward_no = ?");
            $delStmt->execute([$inwardNo]);

            // 4. Insert updated records into child_part_inward
            $insertStmt = $pdo->prepare("
                INSERT INTO child_part_inward (
                    inward_no, inward_date, vendor_name, invoice_no, invoice_date,
                    part_code, part_name, received_qty, uom,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
            ");
            $updStockStmt = $pdo->prepare("
                UPDATE child_part_master 
                SET current_stock = COALESCE(current_stock, 0) + ?,
                    updated_at = GETDATE()
                WHERE part_code = ?
            ");
            $insPartStmt = $pdo->prepare("
                INSERT INTO child_part_master (part_code, part_name, uom, current_stock, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'Active', GETDATE(), GETDATE())
            ");

            $firstInsertedId = null;
            foreach ($itemsList as $it) {
                $insertStmt->execute([
                    $inwardNo,
                    $inwardDate,
                    $vendorName,
                    $invoiceNo,
                    $invoiceDate,
                    $it['part_code'],
                    $it['part_name'],
                    $it['received_qty'],
                    $it['uom']
                ]);

                if ($firstInsertedId === null) {
                    $firstInsertedId = $pdo->lastInsertId();
                }

                // Add received_qty to child_part_master.current_stock
                $updStockStmt->execute([$it['received_qty'], $it['part_code']]);
                if ($updStockStmt->rowCount() === 0) {
                    $insPartStmt->execute([$it['part_code'], $it['part_name'], $it['uom'], $it['received_qty']]);
                }
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Child Part Inward entry '{$inwardNo}' updated successfully (" . count($itemsList) . " items saved as separate rows).",
                'data' => [
                    'id' => $firstInsertedId ?: $id,
                    'inward_no' => $inwardNo,
                    'inward_date' => $inwardDate,
                    'vendor_name' => $vendorName,
                    'invoice_no' => $invoiceNo,
                    'invoice_date' => $invoiceDate,
                    'part_code' => $summaryPartCode,
                    'part_name' => $summaryPartName,
                    'received_qty' => $totalQty,
                    'uom' => $primaryUom,
                    'items_data' => $itemsJson,
                    'items' => $itemsList
                ]
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. CREATE ACTION
    requirePermission('child_part_in', 'create');
    try {
        if (empty($inwardNo)) {
            $yearPrefix = date('y');
            $prefix = "CP-INW-{$yearPrefix}-";
            $stmtSeq = $pdo->prepare("SELECT inward_no FROM child_part_inward WHERE inward_no LIKE ?");
            $stmtSeq->execute([$prefix . '%']);
            $existingNos = $stmtSeq->fetchAll(PDO::FETCH_COLUMN);
            $maxNum = 0;
            foreach ($existingNos as $no) {
                if (preg_match('/^CP-INW-\d{2}-(\d+)$/', $no, $m)) {
                    $num = intval($m[1]);
                    if ($num > $maxNum) {
                        $maxNum = $num;
                    }
                }
            }
            $nextSeq = $maxNum + 1;
            $inwardNo = $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
        } else {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM child_part_inward WHERE inward_no = ?");
            $checkStmt->execute([$inwardNo]);
            $exists = $checkStmt->fetch();
            if ($exists && $exists['cnt'] > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => "Inward No '{$inwardNo}' already exists."]);
                exit;
            }
        }

        $pdo->beginTransaction();

        $insertStmt = $pdo->prepare("
            INSERT INTO child_part_inward (
                inward_no, inward_date, vendor_name, invoice_no, invoice_date,
                part_code, part_name, received_qty, uom,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE())
        ");
        $updStockStmt = $pdo->prepare("
            UPDATE child_part_master 
            SET current_stock = COALESCE(current_stock, 0) + ?,
                updated_at = GETDATE()
            WHERE part_code = ?
        ");
        $insPartStmt = $pdo->prepare("
            INSERT INTO child_part_master (part_code, part_name, uom, current_stock, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'Active', GETDATE(), GETDATE())
        ");

        $firstInsertedId = null;
        foreach ($itemsList as $it) {
            $insertStmt->execute([
                $inwardNo,
                $inwardDate,
                $vendorName,
                $invoiceNo,
                $invoiceDate,
                $it['part_code'],
                $it['part_name'],
                $it['received_qty'],
                $it['uom']
            ]);

            if ($firstInsertedId === null) {
                $firstInsertedId = $pdo->lastInsertId();
            }

            // Add received_qty to child_part_master.current_stock
            $updStockStmt->execute([$it['received_qty'], $it['part_code']]);
            if ($updStockStmt->rowCount() === 0) {
                $insPartStmt->execute([$it['part_code'], $it['part_name'], $it['uom'], $it['received_qty']]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Child Part Inward entry '{$inwardNo}' (" . count($itemsList) . " items) stored as separate rows in database.",
            'data' => [
                'id' => $firstInsertedId,
                'inward_no' => $inwardNo,
                'inward_date' => $inwardDate,
                'vendor_name' => $vendorName,
                'invoice_no' => $invoiceNo,
                'invoice_date' => $invoiceDate,
                'part_code' => $summaryPartCode,
                'part_name' => $summaryPartName,
                'received_qty' => $totalQty,
                'uom' => $primaryUom,
                'items_data' => $itemsJson,
                'items' => $itemsList
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
