<?php
/**
 * WIP Management Portal - Process Stage Execution API
 * Endpoint: /api/process_stage.php
 * Handles part-based stage execution, pass qty, rejected qty, and workflow progression.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../auth/check_auth.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();

if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// -----------------------------------------------------------------
// 1. GET REQUEST - Fetch saved stage records and workflow breakdown
// -----------------------------------------------------------------
if ($method === 'GET') {
    $entryId = isset($_GET['entry_id']) ? intval($_GET['entry_id']) : 0;
    $mipNo = isset($_GET['mip_no']) ? trim($_GET['mip_no']) : '';

    if ($entryId <= 0 && $mipNo === '') {
        echo json_encode(['success' => false, 'message' => 'Missing entry_id or mip_no.', 'data' => []]);
        exit;
    }

    try {
        // 1. Fetch Entry Record
        $entry = null;
        if ($entryId > 0) {
            $stmtE = $pdo->prepare("SELECT * FROM production_entry WHERE id = ?");
            $stmtE->execute([$entryId]);
            $entry = $stmtE->fetch(PDO::FETCH_ASSOC);
        }
        if (!$entry && $mipNo !== '') {
            $stmtE = $pdo->prepare("SELECT TOP 1 * FROM production_entry WHERE mip_no = ? ORDER BY id DESC");
            $stmtE->execute([$mipNo]);
            $entry = $stmtE->fetch(PDO::FETCH_ASSOC);
        }

        $targetQty = floatval($entry['target_qty'] ?? 0);
        $partCode = $entry['part_code'] ?? '';
        $partName = $entry['part_name'] ?? '';
        $customerName = $entry['customer_name'] ?? '';
        $uom = $entry['uom'] ?? 'PCS';
        $soNumber = $entry['so_number'] ?? '';
        $createdAt = $entry['created_at'] ?? '';
        $entryStatus = $entry['status'] ?? 'In Progress';
        if ($entryId <= 0 && $entry) {
            $entryId = intval($entry['id']);
        }
        if ($mipNo === '' && $entry) {
            $mipNo = $entry['mip_no'] ?? '';
        }

        // 2. Fetch Part Master processes
        $partProcesses = [];
        if (!empty($partCode)) {
            $stmtPart = $pdo->prepare("SELECT TOP 1 processes, output_variants FROM part_master WHERE part_code = ?");
            $stmtPart->execute([$partCode]);
            $pMaster = $stmtPart->fetch(PDO::FETCH_ASSOC);
            if ($pMaster && !empty($pMaster['processes'])) {
                $parsed = json_decode($pMaster['processes'], true);
                if (is_array($parsed)) {
                    foreach ($parsed as $pIdx => $proc) {
                        if (is_array($proc) && !empty($proc['name'])) {
                            $partProcesses[] = [
                                'code' => $proc['code'] ?? str_pad($pIdx + 1, 3, '0', STR_PAD_LEFT),
                                'name' => trim($proc['name'])
                            ];
                        } elseif (is_string($proc) && trim($proc) !== '') {
                            $partProcesses[] = [
                                'code' => str_pad($pIdx + 1, 3, '0', STR_PAD_LEFT),
                                'name' => trim($proc)
                            ];
                        }
                    }
                }
            }
        }

        if (empty($partProcesses)) {
            $stmtAllProc = $pdo->query("SELECT process_code, process_name FROM process_master WHERE status = 'Active' ORDER BY id ASC");
            if ($stmtAllProc) {
                $masterProcs = $stmtAllProc->fetchAll(PDO::FETCH_ASSOC);
                foreach ($masterProcs as $mIdx => $mp) {
                    $partProcesses[] = [
                        'code' => $mp['process_code'] ?? str_pad($mIdx + 1, 3, '0', STR_PAD_LEFT),
                        'name' => $mp['process_name'] ?? ('Process ' . ($mIdx + 1))
                    ];
                }
            }
        }

        if (empty($partProcesses)) {
            $partProcesses = [
                ['code' => '001', 'name' => 'Process 1'],
                ['code' => '002', 'name' => 'Process 2'],
                ['code' => '003', 'name' => 'Process 3'],
                ['code' => '004', 'name' => 'Process 4']
            ];
        }

        // 3. Fetch Stage Logs
        if ($entryId > 0) {
            $stmt = $pdo->prepare("
                SELECT * FROM production_stage_log 
                WHERE production_entry_id = ? 
                ORDER BY stage_order ASC, id ASC
            ");
            $stmt->execute([$entryId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM production_stage_log 
                WHERE mip_no = ? 
                ORDER BY stage_order ASC, id ASC
            ");
            $stmt->execute([$mipNo]);
        }
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $logsByOrder = [];
        foreach ($logs as $l) {
            $logsByOrder[intval($l['stage_order'])] = $l;
        }

        // 4. Calculate Stage Workflow Progression & Percentages
        $stages = [];
        $runningInput = $targetQty;
        $totalStagesCount = count($partProcesses);
        $finalFgQty = 0;
        $totalRejectedAll = 0;
        $completedStagesCount = 0;

        foreach ($partProcesses as $idx => $p) {
            $order = $idx + 1;
            $isFinal = ($order === $totalStagesCount);
            $savedLog = $logsByOrder[$order] ?? null;

            $cumPass = $savedLog ? floatval($savedLog['processed_qty'] ?? 0) : 0;
            $cumRej = $savedLog ? floatval($savedLog['rejected_qty'] ?? 0) : 0;
            $operator = $savedLog['operator_name'] ?? '';

            // Extract stage execution/update date
            $stageDate = null;
            $stageDateFormatted = '';
            $stageTimeFormatted = '';
            if ($savedLog) {
                $rawDate = !empty($savedLog['updated_at']) ? $savedLog['updated_at'] : (!empty($savedLog['created_at']) ? $savedLog['created_at'] : null);
                if ($rawDate instanceof DateTimeInterface) {
                    $stageDate = $rawDate->format('Y-m-d H:i:s');
                } elseif (!empty($rawDate)) {
                    $stageDate = strval($rawDate);
                }
                if ($stageDate) {
                    $ts = strtotime($stageDate);
                    if ($ts) {
                        $stageDateFormatted = date('d-m-Y', $ts);
                        $stageTimeFormatted = date('h:i A', $ts);
                    }
                }
            }

            $stageInput = ($idx === 0) ? $targetQty : $runningInput;
            $stageAvail = max(0, $stageInput - ($cumPass + $cumRej));

            $runningInput = $cumPass;
            if ($isFinal) {
                $finalFgQty = $cumPass;
            }
            $totalRejectedAll += $cumRej;

            $passPct = ($stageInput > 0) ? round(($cumPass / $stageInput) * 100, 1) : 0;
            $rejPct = ($stageInput > 0) ? round(($cumRej / $stageInput) * 100, 1) : 0;
            $remPct = max(0, round(100 - $passPct - $rejPct, 1));

            if ($stageInput > 0 && ($cumPass + $cumRej) >= $stageInput) {
                $stageStatus = 'Completed';
                $completedStagesCount++;
            } elseif ($cumPass > 0 || $cumRej > 0) {
                $stageStatus = 'In Progress';
            } elseif ($stageInput > 0) {
                $stageStatus = 'Ready';
            } else {
                $stageStatus = 'Waiting';
            }

            $procName = !empty($savedLog['process_name']) ? $savedLog['process_name'] : $p['name'];

            $stages[] = [
                'order' => $order,
                'code' => $p['code'],
                'name' => $procName,
                'input_qty' => $stageInput,
                'pass_qty' => $cumPass,
                'rejected_qty' => $cumRej,
                'remaining_qty' => $stageAvail,
                'pass_percent' => $passPct,
                'rej_percent' => $rejPct,
                'rem_percent' => $remPct,
                'status' => $stageStatus,
                'operator' => $operator,
                'date' => $stageDate,
                'date_formatted' => $stageDateFormatted,
                'time_formatted' => $stageTimeFormatted,
                'created_at' => !empty($savedLog['created_at']) ? (is_a($savedLog['created_at'], 'DateTimeInterface') ? $savedLog['created_at']->format('Y-m-d H:i:s') : strval($savedLog['created_at'])) : null,
                'updated_at' => !empty($savedLog['updated_at']) ? (is_a($savedLog['updated_at'], 'DateTimeInterface') ? $savedLog['updated_at']->format('Y-m-d H:i:s') : strval($savedLog['updated_at'])) : null,
                'is_final' => $isFinal,
                'variant_code' => $savedLog['variant_code'] ?? '',
                'variant_name' => $savedLog['variant_name'] ?? ''
            ];
        }

        $overallCompletion = ($targetQty > 0) ? round(($finalFgQty / $targetQty) * 100, 1) : 0;

        $createdAtFormatted = '';
        if (!empty($createdAt)) {
            $cTs = strtotime(is_a($createdAt, 'DateTimeInterface') ? $createdAt->format('Y-m-d H:i:s') : strval($createdAt));
            if ($cTs) $createdAtFormatted = date('d-m-Y', $cTs);
        }

        echo json_encode([
            'success' => true,
            'data' => $logs,
            'entry' => [
                'id' => $entryId,
                'mip_no' => $mipNo,
                'customer_name' => $customerName,
                'so_number' => $soNumber,
                'part_code' => $partCode,
                'part_name' => $partName,
                'target_qty' => $targetQty,
                'uom' => $uom,
                'created_at' => $createdAt,
                'created_at_formatted' => $createdAtFormatted,
                'status' => $entryStatus
            ],
            'overall' => [
                'target_qty' => $targetQty,
                'final_fg_qty' => $finalFgQty,
                'total_rejected' => $totalRejectedAll,
                'completion_percent' => $overallCompletion,
                'total_stages' => $totalStagesCount,
                'completed_stages' => $completedStagesCount
            ],
            'stages' => $stages
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------
// 2. POST REQUEST - Save individual or batch stage progress
// -----------------------------------------------------------------
if ($method === 'POST') {
    requirePermission('production', 'update');

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = trim($data['action'] ?? 'save_stage');
    $entryId = intval($data['production_entry_id'] ?? ($data['entry_id'] ?? 0));
    $mipNo = trim($data['mip_no'] ?? '');

    if ($action === 'save_stage') {
        $stageOrder = intval($data['stage_order'] ?? 1);
        $totalStages = intval($data['total_stages'] ?? 1);
        $processCode = trim($data['process_code'] ?? '');
        $processName = trim($data['process_name'] ?? '');
        $partCode = trim($data['part_code'] ?? '');
        $partName = trim($data['part_name'] ?? '');
        $availableQty = floatval($data['available_qty'] ?? 0);
        $passQty = floatval($data['pass_qty'] ?? 0);
        $rejectedQty = floatval($data['rejected_qty'] ?? 0);
        $remainingQty = floatval($data['remaining_qty'] ?? ($availableQty - $passQty - $rejectedQty));
        $operatorName = trim($data['operator_name'] ?? '');
        $designation = trim($data['designation'] ?? 'Operator');
        $isFinalStage = !empty($data['is_final_stage']) ? 1 : 0;
        $remarks = trim($data['remarks'] ?? '');

        if ($processName === '') {
            echo json_encode(['success' => false, 'message' => 'Process name is required.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Check if record exists for this entry & stage_order
            $stCheck = $pdo->prepare("SELECT id FROM production_stage_log WHERE production_entry_id = ? AND stage_order = ?");
            $stCheck->execute([$entryId, $stageOrder]);
            $existingId = $stCheck->fetchColumn();

            if ($existingId) {
                $stUpd = $pdo->prepare("
                    UPDATE production_stage_log 
                    SET process_name = ?,
                        process_code = ?,
                        input_qty = ?,
                        processed_qty = ?,
                        rejected_qty = ?,
                        operator_name = ?,
                        designation = ?,
                        is_final_stage = ?,
                        remarks = ?,
                        updated_at = GETDATE()
                    WHERE id = ?
                ");
                $stUpd->execute([
                    $processName,
                    $processCode,
                    $availableQty,
                    $passQty,
                    $rejectedQty,
                    $operatorName,
                    $designation,
                    $isFinalStage,
                    $remarks,
                    $existingId
                ]);
            } else {
                $stIns = $pdo->prepare("
                    INSERT INTO production_stage_log (
                        production_entry_id, mip_no, part_code, part_name,
                        stage_order, total_stages, process_code, process_name,
                        input_qty, processed_qty, rejected_qty,
                        operator_name, designation, is_final_stage,
                        remarks, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, GETDATE(), GETDATE()
                    )
                ");
                $stIns->execute([
                    $entryId,
                    $mipNo,
                    $partCode,
                    $partName,
                    $stageOrder,
                    $totalStages,
                    $processCode,
                    $processName,
                    $availableQty,
                    $passQty,
                    $rejectedQty,
                    $operatorName,
                    $designation,
                    $isFinalStage,
                    $remarks
                ]);
            }

            // Update parent production_entry metrics
            if ($entryId > 0) {
                // Calculate total rejected across all stages
                $stSumRej = $pdo->prepare("SELECT SUM(rejected_qty) as total_rej FROM production_stage_log WHERE production_entry_id = ?");
                $stSumRej->execute([$entryId]);
                $totalRej = floatval($stSumRej->fetchColumn() ?: 0);

                // Check final stage output
                $stFinal = $pdo->prepare("SELECT processed_qty FROM production_stage_log WHERE production_entry_id = ? AND is_final_stage = 1");
                $stFinal->execute([$entryId]);
                $finalOk = $stFinal->fetchColumn();

                $targetQtyStmt = $pdo->prepare("SELECT target_qty FROM production_entry WHERE id = ?");
                $targetQtyStmt->execute([$entryId]);
                $targetQty = floatval($targetQtyStmt->fetchColumn() ?: 0);

                $status = 'In Progress';
                $finalOkQty = 0;
                if ($finalOk !== false && $finalOk !== null) {
                    $finalOkQty = floatval($finalOk);
                }
                // Order is ONLY completed when all target pieces are finished or rejected (FG OK + Rejections >= Target Qty)
                if ($targetQty > 0 && ($finalOkQty + $totalRej) >= $targetQty) {
                    $status = 'Completed';
                } else {
                    $status = 'In Progress';
                }

                $stEntryUpd = $pdo->prepare("
                    UPDATE production_entry
                    SET ok_qty = ?,
                        rejected_qty = ?,
                        current_stage_order = ?,
                        current_stage_name = ?,
                        total_stages = ?,
                        status = ?,
                        updated_at = GETDATE()
                    WHERE id = ?
                ");
                $stEntryUpd->execute([
                    $finalOkQty,
                    $totalRej,
                    $stageOrder,
                    $processName,
                    $totalStages,
                    $status,
                    $entryId
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Stage '{$processName}' saved successfully.",
                'data' => [
                    'stage_order' => $stageOrder,
                    'process_name' => $processName,
                    'available_qty' => $availableQty,
                    'pass_qty' => $passQty,
                    'rejected_qty' => $rejectedQty,
                    'remaining_qty' => $remainingQty
                ]
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save stage: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save_all') {
        $stages = $data['stages'] ?? [];
        if (!is_array($stages) || empty($stages)) {
            echo json_encode(['success' => false, 'message' => 'No stages data provided.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $totalStages = count($stages);
            $totalRej = 0;
            $finalOkQty = 0;
            $maxCompletedOrder = 1;
            $lastProcName = '';

            foreach ($stages as $idx => $st) {
                $order = intval($st['stage_order'] ?? ($idx + 1));
                $pName = trim($st['process_name'] ?? ('Process ' . $order));
                $pCode = trim($st['process_code'] ?? sprintf('PR-%02d', $order));
                $pPartCode = trim($st['part_code'] ?? ($data['part_code'] ?? ''));
                $pPartName = trim($st['part_name'] ?? ($data['part_name'] ?? ''));
                $avail = floatval($st['available_qty'] ?? 0);
                $pass = floatval($st['pass_qty'] ?? 0);
                $rej = floatval($st['rejected_qty'] ?? 0);
                $rem = floatval($st['remaining_qty'] ?? ($avail - $pass - $rej));
                $op = trim($st['operator_name'] ?? ($data['operator_name'] ?? ''));
                $desig = trim($st['designation'] ?? 'Operator');
                $isFinal = ($order === $totalStages || !empty($st['is_final_stage'])) ? 1 : 0;
                $remk = trim($st['remarks'] ?? '');

                $totalRej += $rej;
                if ($isFinal) {
                    $finalOkQty = $pass;
                }
                if ($pass > 0) {
                    $maxCompletedOrder = $order;
                    $lastProcName = $pName;
                }

                $inputQty = floatval($st['input_qty'] ?? ($idx === 0 ? ($targetQty ?? 20) : $avail));

                $vCode = trim($st['variant_code'] ?? '');
                $vName = trim($st['variant_name'] ?? '');
                $vDetails = null;
                if (!empty($st['variant_output_details'])) {
                    $vDetails = is_string($st['variant_output_details']) ? $st['variant_output_details'] : json_encode($st['variant_output_details']);
                }

                if ($isFinal && !empty($vCode)) {
                    $finalVarCode = $vCode;
                    $finalVarName = $vName;
                }

                $chk = $pdo->prepare("SELECT id FROM production_stage_log WHERE production_entry_id = ? AND stage_order = ?");
                $chk->execute([$entryId, $order]);
                $exId = $chk->fetchColumn();

                if ($exId) {
                    $upd = $pdo->prepare("
                        UPDATE production_stage_log 
                        SET process_name = ?, process_code = ?, input_qty = ?, processed_qty = ?, rejected_qty = ?, operator_name = ?, designation = ?, is_final_stage = ?, variant_code = ?, variant_name = ?, variant_output_details = COALESCE(?, variant_output_details), remarks = ?, updated_at = GETDATE()
                        WHERE id = ?
                    ");
                    $upd->execute([$pName, $pCode, $inputQty, $pass, $rej, $op, $desig, $isFinal, $vCode, $vName, $vDetails, $remk, $exId]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO production_stage_log (
                            production_entry_id, mip_no, part_code, part_name,
                            stage_order, total_stages, process_code, process_name,
                            input_qty, processed_qty, rejected_qty,
                            operator_name, designation, is_final_stage,
                            variant_code, variant_name, variant_output_details,
                            remarks, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, ?, ?,
                            ?, GETDATE(), GETDATE()
                        )
                    ");
                    $ins->execute([$entryId, $mipNo, $pPartCode, $pPartName, $order, $totalStages, $pCode, $pName, $inputQty, $pass, $rej, $op, $desig, $isFinal, $vCode, $vName, $vDetails, $remk]);
                }
            }

            // Update parent entry
            if ($entryId > 0) {
                $targetQtyStmt = $pdo->prepare("SELECT target_qty FROM production_entry WHERE id = ?");
                $targetQtyStmt->execute([$entryId]);
                $targetQty = floatval($targetQtyStmt->fetchColumn() ?: 0);

                // Fetch accurate total rejections from all stages in this entry
                $stSumRej = $pdo->prepare("SELECT SUM(rejected_qty) as total_rej FROM production_stage_log WHERE production_entry_id = ?");
                $stSumRej->execute([$entryId]);
                $totalRej = floatval($stSumRej->fetchColumn() ?: 0);

                // Fetch final stage ok qty
                $stFinal = $pdo->prepare("SELECT processed_qty FROM production_stage_log WHERE production_entry_id = ? AND is_final_stage = 1");
                $stFinal->execute([$entryId]);
                $finalOkDb = $stFinal->fetchColumn();
                if ($finalOkDb !== false && $finalOkDb !== null) {
                    $finalOkQty = floatval($finalOkDb);
                }

                // Order is ONLY completed when all target pieces are finished or rejected (FG OK + Rejections >= Target Qty)
                $status = 'In Progress';
                if ($targetQty > 0 && ($finalOkQty + $totalRej) >= $targetQty) {
                    $status = 'Completed';
                } else {
                    $status = 'In Progress';
                }

                $stEntryUpd = $pdo->prepare("
                    UPDATE production_entry
                    SET ok_qty = ?,
                        rejected_qty = ?,
                        current_stage_order = ?,
                        current_stage_name = ?,
                        total_stages = ?,
                        final_variant_code = COALESCE(?, final_variant_code),
                        final_variant_name = COALESCE(?, final_variant_name),
                        status = ?,
                        updated_at = GETDATE()
                    WHERE id = ?
                ");
                $stEntryUpd->execute([
                    $finalOkQty,
                    $totalRej,
                    $maxCompletedOrder,
                    $lastProcName,
                    $totalStages,
                    !empty($finalVarCode) ? $finalVarCode : null,
                    !empty($finalVarName) ? $finalVarName : null,
                    $status,
                    $entryId
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => 'All process stages updated and saved successfully.'
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save stages: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}
