<?php
/**
 * WIP Management Portal - Finished Goods (FG) Store Helper
 * Supports MIP scanning with Parent + Rework consolidation,
 * variant output tracking, customer mapping, and variant-level dispatch.
 */

if (!function_exists('mergeVariantArrays')) {
    /**
     * Merge two lists of variant breakdown arrays:
     * [ ['code' => '...', 'name' => '...', 'qty' => 5], ... ]
     */
    function mergeVariantArrays($parentVariants, $addVariants) {
        $map = [];
        if (is_string($parentVariants)) {
            $parentVariants = json_decode($parentVariants, true);
        }
        if (is_string($addVariants)) {
            $addVariants = json_decode($addVariants, true);
        }

        if (is_array($parentVariants)) {
            foreach ($parentVariants as $v) {
                if (!is_array($v)) continue;
                $code = trim($v['code'] ?? '');
                $name = trim($v['name'] ?? '');
                $key = $code !== '' ? $code : $name;
                if ($key === '') continue;
                $map[$key] = [
                    'code' => $code,
                    'name' => $name !== '' ? $name : $code,
                    'qty'  => floatval($v['qty'] ?? 0)
                ];
            }
        }

        if (is_array($addVariants)) {
            foreach ($addVariants as $v) {
                if (!is_array($v)) continue;
                $code = trim($v['code'] ?? '');
                $name = trim($v['name'] ?? '');
                $key = $code !== '' ? $code : $name;
                if ($key === '') continue;
                $qty = floatval($v['qty'] ?? 0);
                if (isset($map[$key])) {
                    $map[$key]['qty'] += $qty;
                } else {
                    $map[$key] = [
                        'code' => $code,
                        'name' => $name !== '' ? $name : $code,
                        'qty'  => $qty
                    ];
                }
            }
        }

        return array_values($map);
    }
}

if (!function_exists('extractFinalStageVariants')) {
    /**
     * Extract variant details from production entry and its final stage log
     */
    function extractFinalStageVariants($pdo, $entryId, $partCode = '', $entryOkQty = 0) {
        $variants = [];

        // 1. Check final stage log
        $stPsl = $pdo->prepare("
            SELECT TOP 1 variant_code, variant_name, variant_output_details, processed_qty
            FROM production_stage_log
            WHERE production_entry_id = ? AND is_final_stage = 1
            ORDER BY stage_order DESC
        ");
        $stPsl->execute([$entryId]);
        $psl = $stPsl->fetch(PDO::FETCH_ASSOC);

        // Fallback to highest stage if not explicitly flagged is_final_stage
        if (!$psl) {
            $stPsl2 = $pdo->prepare("
                SELECT TOP 1 variant_code, variant_name, variant_output_details, processed_qty
                FROM production_stage_log
                WHERE production_entry_id = ?
                ORDER BY stage_order DESC
            ");
            $stPsl2->execute([$entryId]);
            $psl = $stPsl2->fetch(PDO::FETCH_ASSOC);
        }

        if ($psl && !empty($psl['variant_output_details'])) {
            $raw = json_decode($psl['variant_output_details'], true);
            if (is_array($raw)) {
                foreach ($raw as $it) {
                    $q = floatval($it['qty'] ?? 0);
                    if ($q > 0) {
                        $variants[] = [
                            'code' => trim($it['code'] ?? ''),
                            'name' => trim($it['name'] ?? ''),
                            'qty'  => $q
                        ];
                    }
                }
            }
        }

        // Fallback to variant_code / variant_name if details array had 0 total
        if (empty($variants) && $psl && !empty($psl['variant_code'])) {
            $q = floatval($psl['processed_qty'] ?: $entryOkQty);
            if ($q > 0) {
                $variants[] = [
                    'code' => trim($psl['variant_code']),
                    'name' => trim($psl['variant_name'] ?: $psl['variant_code']),
                    'qty'  => $q
                ];
            }
        }

        // Fallback to production_entry final variant columns
        if (empty($variants)) {
            $stPe = $pdo->prepare("SELECT final_variant_code, final_variant_name, ok_qty FROM production_entry WHERE id = ?");
            $stPe->execute([$entryId]);
            $pe = $stPe->fetch(PDO::FETCH_ASSOC);
            if ($pe && !empty($pe['final_variant_code'])) {
                $q = floatval($pe['ok_qty'] ?: $entryOkQty);
                if ($q > 0) {
                    $variants[] = [
                        'code' => trim($pe['final_variant_code']),
                        'name' => trim($pe['final_variant_name'] ?: $pe['final_variant_code']),
                        'qty'  => $q
                    ];
                }
            }
        }

        return $variants;
    }
}

if (!function_exists('searchMipWithRework')) {
    /**
     * Search production_entry for a scanned MIP, returning:
     * - Parent MIP record & output variants
     * - Any associated rework entries & rework output variants
     * - Consolidated total OK quantity & merged variants
     * - Customer name manufactured for
     * - Already inwarded status in FG store
     */
    function searchMipWithRework($pdo, $q) {
        $q = trim($q);
        if (empty($q)) {
            return ['success' => false, 'message' => 'Empty query'];
        }

        $cleanQ = preg_replace('/[^a-zA-Z0-9]/', '', $q);

        // 1. Find the primary/parent production entry for this MIP
        // First try matching regular run (is_rework = 0)
        $stParent = $pdo->prepare("
            SELECT TOP 1 id, mip_no, work_order, part_code, part_name, customer_name, customer_code,
                   department, received_by, process_name, shift, target_qty, ok_qty, rework_qty,
                   rejected_qty, operator_name, uom, status, is_rework, rework_source_entry_id,
                   CONVERT(VARCHAR(10), created_at, 120) as production_date,
                   CONVERT(VARCHAR(19), created_at, 120) as created_at
            FROM production_entry
            WHERE (LOWER(RTRIM(LTRIM(mip_no))) = LOWER(?)
               OR REPLACE(REPLACE(LOWER(mip_no), '-', ''), ' ', '') = LOWER(?))
              AND (is_rework = 0 OR is_rework IS NULL)
            ORDER BY id ASC
        ");
        $stParent->execute([$q, $cleanQ]);
        $parent = $stParent->fetch(PDO::FETCH_ASSOC);

        // If no regular entry found, find any entry matching this MIP
        if (!$parent) {
            $stAny = $pdo->prepare("
                SELECT TOP 1 id, mip_no, work_order, part_code, part_name, customer_name, customer_code,
                       department, received_by, process_name, shift, target_qty, ok_qty, rework_qty,
                       rejected_qty, operator_name, uom, status, is_rework, rework_source_entry_id,
                       CONVERT(VARCHAR(10), created_at, 120) as production_date,
                       CONVERT(VARCHAR(19), created_at, 120) as created_at
                FROM production_entry
                WHERE LOWER(RTRIM(LTRIM(mip_no))) = LOWER(?)
                   OR REPLACE(REPLACE(LOWER(mip_no), '-', ''), ' ', '') = LOWER(?)
                ORDER BY id ASC
            ");
            $stAny->execute([$q, $cleanQ]);
            $parent = $stAny->fetch(PDO::FETCH_ASSOC);
        }

        if (!$parent || empty($parent['mip_no'])) {
            return ['success' => false, 'message' => 'No production record found for MIP: ' . $q];
        }

        $mipNo = trim($parent['mip_no']);
        $parentOk = floatval($parent['ok_qty'] ?? 0);
        $partCode = trim($parent['part_code'] ?? '');
        $customerName = trim($parent['customer_name'] ?? '');

        // Fallback customer name from material_issue if missing in production_entry
        if (empty($customerName)) {
            $stMi = $pdo->prepare("SELECT customer_name FROM material_issue WHERE issue_no = ?");
            $stMi->execute([$mipNo]);
            $customerName = trim($stMi->fetchColumn() ?: '');
        }

        // Extract parent variants
        $parentVariants = extractFinalStageVariants($pdo, $parent['id'], $partCode, $parentOk);

        // 2. Find any Completed rework entries for this MIP
        $stRework = $pdo->prepare("
            SELECT id, mip_no, work_order, part_code, part_name, customer_name,
                   target_qty, ok_qty, operator_name, uom, status, is_rework,
                   rework_source_entry_id, rework_iteration, rework_label,
                   CONVERT(VARCHAR(10), created_at, 120) as production_date,
                   CONVERT(VARCHAR(19), created_at, 120) as created_at
            FROM production_entry
            WHERE (LOWER(RTRIM(LTRIM(mip_no))) = LOWER(?)
               OR REPLACE(REPLACE(LOWER(mip_no), '-', ''), ' ', '') = LOWER(?))
              AND is_rework = 1
              AND id != ?
            ORDER BY id ASC
        ");
        $stRework->execute([$mipNo, $cleanQ, $parent['id']]);
        $reworkRows = $stRework->fetchAll(PDO::FETCH_ASSOC);

        $reworkRuns = [];
        $reworkTotalOk = 0;
        $combinedVariants = $parentVariants;

        foreach ($reworkRows as $rw) {
            $rwOk = floatval($rw['ok_qty'] ?? 0);
            if ($rwOk <= 0) continue;
            $rwVariants = extractFinalStageVariants($pdo, $rw['id'], $partCode, $rwOk);
            $rwLabel = !empty($rw['rework_label']) ? $rw['rework_label'] : 'R';
            $reworkRuns[] = [
                'id' => intval($rw['id']),
                'ok_qty' => $rwOk,
                'rework_label' => $rwLabel,
                'rework_iteration' => intval($rw['rework_iteration'] ?? 1),
                'production_date' => $rw['production_date'] ?: substr($rw['created_at'], 0, 10),
                'operator_name' => $rw['operator_name'] ?: 'Operator',
                'variants' => $rwVariants
            ];
            $reworkTotalOk += $rwOk;
            $combinedVariants = mergeVariantArrays($combinedVariants, $rwVariants);
        }

        $totalOkQty = $parentOk + $reworkTotalOk;

        // 3. Check duplicate inward status and detect any remaining rework in fg_inward_logs
        $stDup = $pdo->prepare("
            SELECT TOP 1 id, inward_no, ok_qty, original_ok_qty, rework_ok_qty,
                         has_rework_appended, rework_appended_info, variant_details, created_at
            FROM fg_inward_logs
            WHERE LOWER(RTRIM(LTRIM(mip_no))) = LOWER(?)
            ORDER BY id DESC
        ");
        $stDup->execute([$mipNo]);
        $dup = $stDup->fetch(PDO::FETCH_ASSOC);

        $alreadyInwarded = ($dup !== false && !empty($dup['id']));
        $alreadyInwardedQty = $alreadyInwarded ? floatval($dup['ok_qty'] ?? 0) : 0;
        $previouslyInwardedVariants = [];
        $alreadyInwardedReworkIds = [];
        $alreadyInwardedReworkRuns = [];
        $pendingReworkRuns = [];
        $pendingReworkOk = 0;
        $pendingReworkVariants = [];
        $canAppendRework = false;
        $newTotalOkQty = $totalOkQty;
        $mergedAllVariants = $combinedVariants;
        $allReworkRuns = $reworkRuns;

        if ($alreadyInwarded) {
            if (!empty($dup['variant_details'])) {
                $previouslyInwardedVariants = is_string($dup['variant_details'])
                    ? json_decode($dup['variant_details'], true)
                    : $dup['variant_details'];
                if (!is_array($previouslyInwardedVariants)) {
                    $previouslyInwardedVariants = [];
                }
            }

            $existingReworkAppended = [];
            if (!empty($dup['rework_appended_info'])) {
                $existingReworkAppended = is_string($dup['rework_appended_info'])
                    ? json_decode($dup['rework_appended_info'], true)
                    : $dup['rework_appended_info'];
                if (!is_array($existingReworkAppended)) {
                    $existingReworkAppended = [];
                }
            }

            foreach ($existingReworkAppended as $app) {
                if (!empty($app['id'])) {
                    $alreadyInwardedReworkIds[] = intval($app['id']);
                }
            }

            foreach ($reworkRuns as $rw) {
                $rwId = intval($rw['id']);
                if (in_array($rwId, $alreadyInwardedReworkIds, true)) {
                    $alreadyInwardedReworkRuns[] = $rw;
                } else {
                    $pendingReworkRuns[] = $rw;
                    $pendingReworkOk += floatval($rw['ok_qty']);
                    $pendingReworkVariants = mergeVariantArrays($pendingReworkVariants, $rw['variants']);
                }
            }

            // Fallback: If pendingReworkOk is 0 but production has more OK qty than already inwarded
            if ($pendingReworkOk <= 0 && $totalOkQty > $alreadyInwardedQty) {
                $pendingReworkOk = $totalOkQty - $alreadyInwardedQty;
            }

            $canAppendRework = ($pendingReworkOk > 0);
            $newTotalOkQty = $alreadyInwardedQty + $pendingReworkOk;
            $mergedAllVariants = mergeVariantArrays($previouslyInwardedVariants, $pendingReworkVariants);
            $allReworkRuns = array_merge($existingReworkAppended, $pendingReworkRuns);
        }

        return [
            'success' => true,
            'data' => [
                'mip_no' => $mipNo,
                'part_code' => $parent['part_code'],
                'part_name' => $parent['part_name'],
                'customer_name' => $customerName ?: 'Standard Customer',
                'uom' => $parent['uom'] ?: 'NOS',
                'work_order' => $parent['work_order'] ?? '',
                'received_by' => $parent['operator_name'] ?: 'Production',
                'production_date' => $parent['production_date'] ?: date('Y-m-d'),
                'parent_entry_id' => intval($parent['id']),
                'parent_ok_qty' => $parentOk,
                'parent_variants' => $parentVariants,
                'has_rework' => count($reworkRuns) > 0,
                'rework_runs' => $reworkRuns,
                'rework_total_ok' => $reworkTotalOk,
                'total_ok_qty' => $totalOkQty,
                'combined_variants' => $combinedVariants,
                'already_inwarded' => $alreadyInwarded,
                'can_append_rework' => $canAppendRework,
                'inward_ref' => $dup ? $dup['inward_no'] : null,
                'inward_log_id' => $dup ? intval($dup['id']) : null,
                'inward_ok_qty' => $alreadyInwardedQty,
                'previously_inwarded_qty' => $alreadyInwardedQty,
                'previously_inwarded_variants' => $previouslyInwardedVariants,
                'already_inwarded_runs' => $alreadyInwardedReworkRuns,
                'pending_rework_runs' => $pendingReworkRuns,
                'pending_rework_ok' => $pendingReworkOk,
                'pending_rework_variants' => $pendingReworkVariants,
                'new_total_ok_qty' => $newTotalOkQty,
                'all_rework_runs' => $allReworkRuns,
                'merged_all_variants' => $mergedAllVariants
            ]
        ];
    }
}

if (!function_exists('saveInwardConsolidated')) {
    /**
     * Save consolidated finished goods inward into fg_inward_logs and update fg_inventory
     * Supports appending newly completed rework quantities to existing stock.
     */
    function saveInwardConsolidated($pdo, $postData) {
        $inwardNo   = trim($postData['inward_no'] ?? '');
        $mipNo      = trim($postData['mip_no'] ?? '');
        $partCode   = trim($postData['part_code'] ?? '');
        $partName   = trim($postData['part_name'] ?? '');
        $customer   = trim($postData['customer_name'] ?? '');
        $okQty      = floatval($postData['inward_qty'] ?? $postData['ok_qty'] ?? 0);
        $parentOk   = floatval($postData['original_ok_qty'] ?? $postData['parent_ok_qty'] ?? $okQty);
        $reworkOk   = floatval($postData['rework_ok_qty'] ?? 0);
        $uom        = trim($postData['uom'] ?? 'NOS');
        $prodDate   = trim($postData['inward_date'] ?? $postData['production_date'] ?? date('Y-m-d'));
        $workOrder  = trim($postData['work_order'] ?? '');
        $receivedBy = trim($postData['received_by'] ?? '');
        $remarks    = trim($postData['remarks'] ?? '');
        $parentEntryId = !empty($postData['parent_entry_id']) ? intval($postData['parent_entry_id']) : null;

        $isAppend   = !empty($postData['is_append_rework']);
        $pendingRwOk = floatval($postData['pending_rework_ok'] ?? 0);

        $rawVariants = $postData['variant_details'] ?? [];
        $variants = is_string($rawVariants) ? json_decode($rawVariants, true) : $rawVariants;

        $rawRwInfo = $postData['rework_appended_info'] ?? [];
        $reworkInfo = is_string($rawRwInfo) ? json_decode($rawRwInfo, true) : $rawRwInfo;

        $rawPendingRwRuns = $postData['pending_rework_runs'] ?? [];
        $pendingRwRuns = is_string($rawPendingRwRuns) ? json_decode($rawPendingRwRuns, true) : $rawPendingRwRuns;
        if (!is_array($pendingRwRuns)) $pendingRwRuns = [];

        $rawPendingRwVars = $postData['pending_rework_variants'] ?? [];
        $pendingRwVars = is_string($rawPendingRwVars) ? json_decode($rawPendingRwVars, true) : $rawPendingRwVars;
        if (!is_array($pendingRwVars)) $pendingRwVars = [];

        $hasRework = !empty($postData['has_rework_appended']) || ($reworkOk > 0) || (!empty($reworkInfo)) || ($isAppend && $pendingRwOk > 0);

        if (empty($inwardNo)) {
            $inwardNo = 'FGI-' . date('Y') . '-' . rand(100, 999);
        }
        if (empty($partCode)) {
            return ['success' => false, 'message' => 'Part code is required.'];
        }
        if ($okQty <= 0 && $pendingRwOk <= 0) {
            return ['success' => false, 'message' => 'Quantity must be greater than zero.'];
        }

        try {
            $pdo->beginTransaction();

            // Check if inward log already exists for this MIP
            $stChkLog = $pdo->prepare("
                SELECT id, inward_no, ok_qty, original_ok_qty, rework_ok_qty,
                       variant_details, rework_appended_info, remarks, customer_name
                FROM fg_inward_logs 
                WHERE LOWER(RTRIM(LTRIM(mip_no))) = LOWER(?)
                ORDER BY id DESC
            ");
            $stChkLog->execute([$mipNo]);
            $existingLog = $stChkLog->fetch(PDO::FETCH_ASSOC);

            if ($existingLog) {
                $existingLogId = $existingLog['id'];
                $prevOk = floatval($existingLog['ok_qty'] ?? 0);
                $prevReworkOk = floatval($existingLog['rework_ok_qty'] ?? 0);
                $prevOrigOk = floatval($existingLog['original_ok_qty'] ?: $prevOk);

                $prevVariants = json_decode($existingLog['variant_details'] ?? '[]', true) ?: [];
                $prevReworkInfo = json_decode($existingLog['rework_appended_info'] ?? '[]', true) ?: [];

                if ($isAppend) {
                    $addedRwQty = ($pendingRwOk > 0) ? $pendingRwOk : max(0, $okQty - $prevOk);
                    $newTotalOk = $prevOk + $addedRwQty;
                    $newTotalReworkOk = $prevReworkOk + $addedRwQty;

                    // Merge variants: previous inward variants + new rework variants
                    $newMergedVariants = mergeVariantArrays($prevVariants, !empty($pendingRwVars) ? $pendingRwVars : $variants);
                    // Merge rework runs info
                    $newMergedReworkInfo = array_merge($prevReworkInfo, $pendingRwRuns);
                    $appendNote = "Appended +{$addedRwQty} {$uom} rework on " . date('Y-m-d H:i');
                    $fullRemarks = !empty($existingLog['remarks'])
                        ? $existingLog['remarks'] . " | " . $appendNote
                        : $appendNote;
                    if (!empty($remarks)) {
                        $fullRemarks .= " (" . $remarks . ")";
                    }
                } else {
                    $addedRwQty = max(0, $okQty - $prevOk);
                    $newTotalOk = $okQty;
                    $newTotalReworkOk = $reworkOk;
                    $newMergedVariants = !empty($variants) ? $variants : $prevVariants;
                    $newMergedReworkInfo = !empty($reworkInfo) ? $reworkInfo : $prevReworkInfo;
                    $fullRemarks = !empty($remarks) ? $remarks : $existingLog['remarks'];
                }

                $stUpdLog = $pdo->prepare("
                    UPDATE fg_inward_logs
                    SET ok_qty = ?,
                        original_ok_qty = ?,
                        rework_ok_qty = ?,
                        has_rework_appended = 1,
                        customer_name = COALESCE(?, customer_name),
                        variant_details = ?,
                        rework_appended_info = ?,
                        production_date = COALESCE(?, production_date),
                        received_by = COALESCE(?, received_by),
                        remarks = ?
                    WHERE id = ?
                ");
                $stUpdLog->execute([
                    $newTotalOk,
                    $prevOrigOk,
                    $newTotalReworkOk,
                    !empty($customer) ? $customer : null,
                    !empty($newMergedVariants) ? json_encode($newMergedVariants) : null,
                    !empty($newMergedReworkInfo) ? json_encode($newMergedReworkInfo) : null,
                    !empty($prodDate) ? $prodDate : null,
                    !empty($receivedBy) ? $receivedBy : null,
                    $fullRemarks,
                    $existingLogId
                ]);
            } else {
                // Insert new inward log (Fresh Inward)
                $addedRwQty = 0;
                $newTotalOk = $okQty;
                $newMergedVariants = $variants;
                $newMergedReworkInfo = $reworkInfo;
                $stInsLog = $pdo->prepare("
                    INSERT INTO fg_inward_logs (
                        inward_no, mip_no, production_date, part_code, part_name,
                        customer_name, ok_qty, original_ok_qty, rework_ok_qty,
                        has_rework_appended, uom, qc_status, work_order,
                        received_by, remarks, variant_details, rework_appended_info,
                        is_rework, production_entry_id, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, 'QC Passed', ?,
                        ?, ?, ?, ?,
                        0, ?, GETDATE()
                    )
                ");
                $stInsLog->execute([
                    $inwardNo,
                    $mipNo,
                    !empty($prodDate) ? $prodDate : null,
                    $partCode,
                    $partName,
                    $customer,
                    $okQty,
                    $parentOk,
                    $reworkOk,
                    $hasRework ? 1 : 0,
                    $uom,
                    $workOrder,
                    $receivedBy,
                    $remarks,
                    !empty($variants) ? json_encode($variants) : null,
                    !empty($reworkInfo) ? json_encode($reworkInfo) : null,
                    $parentEntryId
                ]);
            }

            // 2. Update fg_inventory keyed by MIP Batch (last_mip_no)
            $stChkInv = $pdo->prepare("SELECT id, total_ok_qty, variant_stock_details FROM fg_inventory WHERE last_mip_no = ?");
            $stChkInv->execute([$mipNo]);
            $existingInv = $stChkInv->fetch(PDO::FETCH_ASSOC);

            if ($existingInv) {
                if ($isAppend) {
                    $newInvQty = floatval($existingInv['total_ok_qty']) + $addedRwQty;
                    $curVarStock = json_decode($existingInv['variant_stock_details'] ?? '[]', true) ?: [];
                    $newInvVarStock = mergeVariantArrays($curVarStock, !empty($pendingRwVars) ? $pendingRwVars : $newMergedVariants);
                } else {
                    $newInvQty = $okQty;
                    $newInvVarStock = $newMergedVariants;
                }

                $stUpdInv = $pdo->prepare("
                    UPDATE fg_inventory
                    SET total_ok_qty = ?,
                        customer_name = COALESCE(?, customer_name),
                        variant_stock_details = ?,
                        part_name = ?,
                        part_code = ?,
                        uom = ?,
                        last_production_date = ?,
                        updated_at = GETDATE()
                    WHERE id = ?
                ");
                $stUpdInv->execute([
                    $newInvQty,
                    !empty($customer) ? $customer : null,
                    !empty($newInvVarStock) ? json_encode($newInvVarStock) : null,
                    $partName,
                    $partCode,
                    $uom,
                    !empty($prodDate) ? $prodDate : null,
                    $existingInv['id']
                ]);
            } else {
                $stInsInv = $pdo->prepare("
                    INSERT INTO fg_inventory (
                        part_code, part_name, customer_name, total_ok_qty, uom,
                        variant_stock_details, last_mip_no, last_production_date,
                        created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        GETDATE(), GETDATE()
                    )
                ");
                $stInsInv->execute([
                    $partCode,
                    $partName,
                    $customer,
                    $okQty,
                    $uom,
                    !empty($newMergedVariants) ? json_encode($newMergedVariants) : null,
                    $mipNo,
                    !empty($prodDate) ? $prodDate : null
                ]);
            }

            $pdo->commit();

            // Auto-synchronize fg_inventory with all inward & dispatch logs to guarantee accuracy
            syncFgInventoryRecords($pdo);

            $msg = $isAppend 
                ? "Successfully appended +{$addedRwQty} {$uom} rework to MIP \"{$mipNo}\" stock!"
                : "Finished Goods inward saved successfully!";

            return [
                'success' => true,
                'message' => $msg,
                'data' => [
                    'inward_no' => $existingLog ? $existingLog['inward_no'] : $inwardNo,
                    'mip_no' => $mipNo,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'customer_name' => $customer,
                    'total_ok_qty' => $newTotalOk,
                    'added_rework_qty' => $addedRwQty,
                    'is_append' => $isAppend
                ]
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('saveDispatchWithVariants')) {
    /**
     * Save Finished Goods dispatch with customer and variant breakdown deductions
     */
    function saveDispatchWithVariants($pdo, $postData) {
        $dispatchNo   = trim($postData['dispatch_no'] ?? '');
        $dispatchDate = trim($postData['dispatch_date'] ?? date('Y-m-d'));
        $invoiceRef   = trim($postData['invoice_ref'] ?? '');
        $customer     = trim($postData['customer'] ?? '');
        $dispatchedBy = trim($postData['dispatched_by'] ?? 'Store Executive');
        $remarks      = trim($postData['remarks'] ?? '');

        if (empty($dispatchNo)) {
            $dispatchNo = 'DSP-' . date('Y') . '-' . rand(100, 999);
        }
        if (empty($invoiceRef)) {
            return ['success' => false, 'message' => 'Invoice / DC reference is required.'];
        }

        $rawItems = $postData['items'] ?? [];
        if (!is_array($rawItems) || empty($rawItems)) {
            if (!empty($postData['part_code'])) {
                $rawItems = [$postData];
            }
        }

        if (empty($rawItems)) {
            return ['success' => false, 'message' => 'Please add at least one finished part to dispatch.'];
        }

        try {
            $pdo->beginTransaction();

            $dispatchedCount = 0;

            foreach ($rawItems as $idx => $it) {
                $pCode = trim($it['part_code'] ?? '');
                $pName = trim($it['part_name'] ?? $pCode);
                $dQty  = floatval($it['dispatch_qty'] ?? 0);
                $uom   = trim($it['uom'] ?? 'NOS');
                $bNo   = trim($it['batch_no'] ?? '');
                $itemCustomer = trim($it['customer'] ?? $customer);

                if (empty($pCode)) continue;
                if ($dQty <= 0) {
                    throw new Exception("Dispatch quantity for {$pCode} must be greater than zero.");
                }

                // Check stock in fg_inventory (match batch/MIP first, fallback to part_code)
                $inv = null;
                if (!empty($bNo)) {
                    $stChk = $pdo->prepare("SELECT id, total_ok_qty, customer_name, variant_stock_details FROM fg_inventory WHERE last_mip_no = ?");
                    $stChk->execute([$bNo]);
                    $inv = $stChk->fetch(PDO::FETCH_ASSOC);
                }
                if (!$inv) {
                    $stChk = $pdo->prepare("SELECT id, total_ok_qty, customer_name, variant_stock_details FROM fg_inventory WHERE part_code = ?");
                    $stChk->execute([$pCode]);
                    $inv = $stChk->fetch(PDO::FETCH_ASSOC);
                }

                if (!$inv) {
                    throw new Exception("Part {$pCode} does not exist in FG Inventory.");
                }

                $availableStock = floatval($inv['total_ok_qty']);
                if ($dQty > $availableStock) {
                    throw new Exception("Dispatch quantity ({$dQty}) exceeds available stock ({$availableStock}) for part {$pCode}.");
                }

                // Use the customer for whom the part was manufactured
                $finalCustomer = !empty($inv['customer_name']) ? $inv['customer_name'] : ($itemCustomer ?: 'Customer');

                // Handle variant level deduction if provided
                $variantDispatch = $it['variant_dispatch'] ?? [];
                $currentVariants = json_decode($inv['variant_stock_details'] ?? '[]', true) ?: [];
                $updatedVariants = $currentVariants;

                if (!empty($variantDispatch) && is_array($variantDispatch) && !empty($currentVariants)) {
                    $variantMap = [];
                    foreach ($currentVariants as $cv) {
                        $k = trim($cv['code'] ?? $cv['name'] ?? '');
                        if ($k !== '') $variantMap[$k] = floatval($cv['qty'] ?? 0);
                    }

                    foreach ($variantDispatch as $vd) {
                        $vk = trim($vd['code'] ?? $vd['name'] ?? '');
                        $vdQty = floatval($vd['qty'] ?? 0);
                        if ($vdQty <= 0) continue;
                        if (!isset($variantMap[$vk]) || $variantMap[$vk] < $vdQty) {
                            $availV = $variantMap[$vk] ?? 0;
                            throw new Exception("Variant '{$vk}' only has {$availV} in stock. Cannot dispatch {$vdQty}.");
                        }
                        $variantMap[$vk] -= $vdQty;
                    }

                    // Rebuild updated variants array
                    $updatedVariants = [];
                    foreach ($currentVariants as $cv) {
                        $k = trim($cv['code'] ?? $cv['name'] ?? '');
                        $updatedVariants[] = [
                            'code' => $cv['code'] ?? '',
                            'name' => $cv['name'] ?? '',
                            'qty'  => max(0, $variantMap[$k] ?? 0)
                        ];
                    }
                }

                $newTotalStock = max(0, $availableStock - $dQty);

                // 1. Insert into fg_dispatch_logs
                $stLog = $pdo->prepare("
                    INSERT INTO fg_dispatch_logs (
                        dispatch_no, dispatch_date, invoice_ref, part_code, part_name,
                        batch_no, dispatch_qty, uom, customer, dispatched_by,
                        remarks, variant_dispatch_details, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, GETDATE()
                    )
                ");
                $stLog->execute([
                    $dispatchNo,
                    $dispatchDate,
                    $invoiceRef,
                    $pCode,
                    $pName,
                    $bNo,
                    $dQty,
                    $uom,
                    $finalCustomer,
                    $dispatchedBy,
                    $remarks,
                    !empty($variantDispatch) ? json_encode($variantDispatch) : null
                ]);

                // 2. Deduct from fg_inventory for this specific batch
                $stUpdInv = $pdo->prepare("
                    UPDATE fg_inventory
                    SET total_ok_qty = ?,
                        variant_stock_details = ?,
                        updated_at = GETDATE()
                    WHERE id = ?
                ");
                $stUpdInv->execute([
                    $newTotalStock,
                    !empty($updatedVariants) ? json_encode($updatedVariants) : null,
                    $inv['id']
                ]);

                $dispatchedCount++;
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => "Dispatch successful! {$dispatchedCount} part(s) dispatched under {$dispatchNo}.",
                'data' => [
                    'dispatch_no' => $dispatchNo,
                    'customer' => $customer,
                    'items_count' => $dispatchedCount
                ]
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('renderVariantBadgesHtml')) {
    /**
     * Helper to render colorful aesthetic variant pills/badges
     */
    function renderVariantBadgesHtml($variantDetailsJson, $maxShow = 4) {
        if (empty($variantDetailsJson)) return '';

        $variants = is_string($variantDetailsJson) ? json_decode($variantDetailsJson, true) : $variantDetailsJson;
        if (!is_array($variants) || empty($variants)) {
            return '';
        }

        $html = '<div style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;">';
        $shown = 0;
        foreach ($variants as $v) {
            $qty = floatval($v['qty'] ?? 0);
            if ($qty <= 0) continue;
            $name = trim($v['name'] ?? $v['code'] ?? 'Var');
            $code = trim($v['code'] ?? '');

            // Palette based on name or code
            $lower = strtolower($name . ' ' . $code);
            $dotColor = '#64748b';
            $bg = '#f1f5f9';
            $color = '#334155';
            $border = '#e2e8f0';

            if (strpos($lower, 'green') !== false || strpos($lower, 'grn') !== false) {
                $dotColor = '#10b981';
                $bg = '#ecfdf5';
                $color = '#065f46';
                $border = '#a7f3d0';
            } elseif (strpos($lower, 'yellow') !== false || strpos($lower, 'yel') !== false) {
                $dotColor = '#f59e0b';
                $bg = '#fffbeb';
                $color = '#92400e';
                $border = '#fde68a';
            } elseif (strpos($lower, 'red') !== false) {
                $dotColor = '#ef4444';
                $bg = '#fef2f2';
                $color = '#991b1b';
                $border = '#fecaca';
            } elseif (strpos($lower, 'blue') !== false) {
                $dotColor = '#3b82f6';
                $bg = '#eff6ff';
                $color = '#1e40af';
                $border = '#bfdbfe';
            }

            $cleanQty = ($qty == (int)$qty) ? number_format($qty, 0) : rtrim(rtrim(number_format($qty, 2), '0'), '.');

            $html .= '<span style="display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:5px; background:' . $bg . '; color:' . $color . '; border:1px solid ' . $border . '; font-size:0.75rem; font-weight:700; white-space:nowrap;" title="' . htmlspecialchars($name . ' (' . $code . '): ' . $cleanQty) . '">';
            $html .= '<span style="width:6px; height:6px; border-radius:50%; background:' . $dotColor . '; display:inline-block;"></span>';
            $html .= htmlspecialchars($name) . ': ' . $cleanQty;
            $html .= '</span>';
            $shown++;
            if ($maxShow > 0 && $shown >= $maxShow && count($variants) > $maxShow) {
                $more = count($variants) - $shown;
                $html .= '<span style="font-size:0.72rem; color:#64748b; font-weight:600; padding:2px 4px;">+' . $more . ' more</span>';
                break;
            }
        }
        $html .= '</div>';

        return ($shown > 0) ? $html : '';
    }
}

if (!function_exists('syncFgInventoryRecords')) {
    /**
     * Auto-synchronize fg_inventory with all fg_inward_logs and fg_dispatch_logs.
     * Ensures every inwarded MIP has its corresponding stock entry and accurate remaining quantity.
     */
    function syncFgInventoryRecords($pdo) {
        if (!$pdo) return;

        try {
            // Ensure unique part_code constraint is dropped so multiple batches of same part coexist
            $pdo->exec("
                IF EXISTS (SELECT * FROM sys.key_constraints WHERE name = 'UQ_fg_inv_part_code')
                BEGIN
                    ALTER TABLE fg_inventory DROP CONSTRAINT UQ_fg_inv_part_code;
                END
            ");

            // Fetch all inward logs
            $stInw = $pdo->query("SELECT * FROM fg_inward_logs ORDER BY id ASC");
            $inwardLogs = $stInw ? $stInw->fetchAll(PDO::FETCH_ASSOC) : [];

            // Fetch all dispatch logs
            $stDsp = $pdo->query("SELECT * FROM fg_dispatch_logs");
            $dspLogs = $stDsp ? $stDsp->fetchAll(PDO::FETCH_ASSOC) : [];

            // Map dispatches by (batch_no, part_code)
            $dispMap = [];
            $dispVarMap = [];
            foreach ($dspLogs as $d) {
                $k = trim($d['batch_no'] ?? '') . '||' . trim($d['part_code'] ?? '');
                $dispMap[$k] = ($dispMap[$k] ?? 0) + floatval($d['dispatch_qty'] ?? 0);

                if (!empty($d['variant_dispatch_details'])) {
                    $vList = json_decode($d['variant_dispatch_details'], true);
                    if (is_array($vList)) {
                        foreach ($vList as $v) {
                            $vk = trim($v['code'] ?? $v['name'] ?? '');
                            if ($vk !== '') {
                                $vkKey = $k . '||' . $vk;
                                $dispVarMap[$vkKey] = ($dispVarMap[$vkKey] ?? 0) + floatval($v['qty'] ?? 0);
                            }
                        }
                    }
                }
            }

            // Fetch existing fg_inventory records mapped by last_mip_no
            $stInv = $pdo->query("SELECT id, last_mip_no, part_code, total_ok_qty FROM fg_inventory");
            $existingInv = [];
            if ($stInv) {
                foreach ($stInv->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $m = trim($r['last_mip_no'] ?? '');
                    if ($m !== '') {
                        $existingInv[$m] = $r;
                    }
                }
            }

            $stIns = $pdo->prepare("
                INSERT INTO fg_inventory (
                    part_code, part_name, customer_name, total_ok_qty, uom,
                    variant_stock_details, last_mip_no, last_production_date,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, GETDATE()
                )
            ");

            $stUpd = $pdo->prepare("
                UPDATE fg_inventory
                SET total_ok_qty = ?,
                    variant_stock_details = ?,
                    customer_name = ?,
                    part_name = ?,
                    uom = ?,
                    last_production_date = ?,
                    updated_at = GETDATE()
                WHERE id = ?
            ");

            foreach ($inwardLogs as $inw) {
                $mip = trim($inw['mip_no'] ?? '');
                $pCode = trim($inw['part_code'] ?? '');
                if (empty($mip)) continue;

                $k = $mip . '||' . $pCode;
                $inwQty = floatval($inw['ok_qty'] ?? 0);
                $dQty = $dispMap[$k] ?? 0;
                $remQty = max(0, $inwQty - $dQty);

                $rawVariants = json_decode($inw['variant_details'] ?? '[]', true) ?: [];
                $remVariants = [];
                foreach ($rawVariants as $rv) {
                    $vk = trim($rv['code'] ?? $rv['name'] ?? '');
                    $vInwQty = floatval($rv['qty'] ?? 0);
                    $vDispQty = $dispVarMap[$k . '||' . $vk] ?? 0;
                    $remVQty = max(0, $vInwQty - $vDispQty);
                    $remVariants[] = [
                        'code' => $rv['code'] ?? '',
                        'name' => $rv['name'] ?? '',
                        'qty'  => $remVQty
                    ];
                }

                $varsJson = !empty($remVariants) ? json_encode($remVariants) : null;
                $cust = !empty($inw['customer_name']) ? $inw['customer_name'] : 'General Customer';
                $pDate = !empty($inw['production_date']) ? $inw['production_date'] : null;

                if (isset($existingInv[$mip])) {
                    // Update
                    $stUpd->execute([
                        $remQty,
                        $varsJson,
                        $cust,
                        $inw['part_name'],
                        $inw['uom'] ?? 'NOS',
                        $pDate,
                        $existingInv[$mip]['id']
                    ]);
                } else {
                    // Insert
                    $stIns->execute([
                        $pCode,
                        $inw['part_name'],
                        $cust,
                        $remQty,
                        $inw['uom'] ?? 'NOS',
                        $varsJson,
                        $mip,
                        $pDate,
                        $inw['created_at']
                    ]);
                }
            }
        } catch (PDOException $e) {
            // Fallback
        }
    }
}

if (!function_exists('getAvailableMipsForDispatch')) {
    /**
     * Fetch all in-stock MIP batches available for dispatch grouped with customer & variant breakdown
     */
    function getAvailableMipsForDispatch($pdo) {
        if (!$pdo) return [];

        // Auto sync inventory records first
        syncFgInventoryRecords($pdo);

        // 1. Fetch inward logs
        $inwardLogs = [];
        try {
            $stInw = $pdo->query("
                SELECT id, inward_no, mip_no, part_code, part_name, customer_name, ok_qty, uom, variant_details
                FROM fg_inward_logs
                ORDER BY id DESC
            ");
            if ($stInw) $inwardLogs = $stInw->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        // 2. Fetch dispatch logs
        $dspLogs = [];
        try {
            $stDsp = $pdo->query("
                SELECT id, dispatch_no, batch_no, part_code, customer, dispatch_qty, variant_dispatch_details
                FROM fg_dispatch_logs
            ");
            if ($stDsp) $dspLogs = $stDsp->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}

        // 3. Fetch inventory stock
        $invMap = [];
        $invMapByMip = [];
        $inventoryRows = [];
        try {
            $stInv = $pdo->query("
                SELECT id, part_code, customer_name, total_ok_qty, uom, variant_stock_details, last_mip_no, part_name 
                FROM fg_inventory
            ");
            if ($stInv) {
                $inventoryRows = $stInv->fetchAll(PDO::FETCH_ASSOC);
                foreach ($inventoryRows as $r) {
                    $invMap[$r['part_code']] = $r;
                    $m = trim($r['last_mip_no'] ?? '');
                    if ($m !== '') {
                        $invMapByMip[$m] = $r;
                    }
                }
            }
        } catch (PDOException $e) {}

        // Aggregate dispatches by (batch_no, part_code)
        $dispatchedByBatch = [];
        $dispatchedVariantsByBatch = [];
        foreach ($dspLogs as $d) {
            $key = trim($d['batch_no'] ?? '') . '||' . trim($d['part_code'] ?? '');
            $dispatchedByBatch[$key] = ($dispatchedByBatch[$key] ?? 0) + floatval($d['dispatch_qty'] ?? 0);

            if (!empty($d['variant_dispatch_details'])) {
                $vars = json_decode($d['variant_dispatch_details'], true);
                if (is_array($vars)) {
                    foreach ($vars as $v) {
                        $vk = trim($v['code'] ?? $v['name'] ?? '');
                        if ($vk !== '') {
                            $vkey = $key . '||' . $vk;
                            $dispatchedVariantsByBatch[$vkey] = ($dispatchedVariantsByBatch[$vkey] ?? 0) + floatval($v['qty'] ?? 0);
                        }
                    }
                }
            }
        }

        $availableMips = [];
        $seenMipPart = [];

        // First pass: from inward logs
        foreach ($inwardLogs as $inw) {
            $mip = trim($inw['mip_no'] ?? '');
            $pCode = trim($inw['part_code'] ?? '');
            $cust = trim($inw['customer_name'] ?? '');
            if ($cust === '') $cust = 'General Customer';
            $key = $mip . '||' . $pCode;

            if (isset($seenMipPart[$key])) continue;
            $seenMipPart[$key] = true;

            $inwQty = floatval($inw['ok_qty'] ?? 0);
            $totalDisp = $dispatchedByBatch[$key] ?? 0;
            $availQty = max(0, $inwQty - $totalDisp);

            $invRow = $invMapByMip[$mip] ?? $invMap[$pCode] ?? null;
            if ($invRow) {
                $invStock = floatval($invRow['total_ok_qty'] ?? 0);
                if ($invStock < $availQty) {
                    $availQty = $invStock;
                }
                if (empty($inw['customer_name']) && !empty($invRow['customer_name'])) {
                    $cust = $invRow['customer_name'];
                }
            }

            if ($availQty <= 0) continue;

            // Compute available variants for this MIP
            $rawVars = json_decode($inw['variant_details'] ?? '[]', true) ?: [];
            $availVars = [];
            foreach ($rawVars as $rv) {
                $vCode = trim($rv['code'] ?? '');
                $vName = trim($rv['name'] ?? $vCode);
                $vKey = $vCode !== '' ? $vCode : $vName;
                $inwVQty = floatval($rv['qty'] ?? 0);
                $dispVQty = $dispatchedVariantsByBatch[$key . '||' . $vKey] ?? 0;
                $remVQty = max(0, $inwVQty - $dispVQty);

                if ($invRow && !empty($invRow['variant_stock_details'])) {
                    $invVars = json_decode($invRow['variant_stock_details'], true);
                    if (is_array($invVars)) {
                        foreach ($invVars as $iv) {
                            $ivKey = trim($iv['code'] ?? $iv['name'] ?? '');
                            if ($ivKey === $vKey) {
                                $remVQty = min($remVQty, floatval($iv['qty'] ?? 0));
                                break;
                            }
                        }
                    }
                }

                if ($remVQty > 0) {
                    $availVars[] = [
                        'code' => $vCode,
                        'name' => $vName,
                        'qty'  => $remVQty
                    ];
                }
            }

            $availableMips[] = [
                'mip_no' => $mip,
                'customer_name' => $cust,
                'part_code' => $pCode,
                'part_name' => trim($inw['part_name'] ?? $pCode),
                'uom' => trim($inw['uom'] ?? 'NOS'),
                'available_qty' => $availQty,
                'available_variants' => $availVars
            ];
        }

        // Second pass: fallback from fg_inventory
        foreach ($inventoryRows as $inv) {
            $stock = floatval($inv['total_ok_qty'] ?? 0);
            if ($stock <= 0) continue;
            $mip = trim($inv['last_mip_no'] ?? '');
            if ($mip === '') $mip = 'FG-' . $inv['part_code'];
            $pCode = trim($inv['part_code']);
            $key = $mip . '||' . $pCode;

            if (!isset($seenMipPart[$key])) {
                $seenMipPart[$key] = true;
                $availVars = [];
                $rawVars = json_decode($inv['variant_stock_details'] ?? '[]', true) ?: [];
                foreach ($rawVars as $rv) {
                    $q = floatval($rv['qty'] ?? 0);
                    if ($q > 0) {
                        $availVars[] = [
                            'code' => trim($rv['code'] ?? ''),
                            'name' => trim($rv['name'] ?? $rv['code'] ?? 'Var'),
                            'qty'  => $q
                        ];
                    }
                }
                $availableMips[] = [
                    'mip_no' => $mip,
                    'customer_name' => trim($inv['customer_name'] ?? 'General Customer') ?: 'General Customer',
                    'part_code' => $pCode,
                    'part_name' => trim($inv['part_name'] ?? $pCode),
                    'uom' => trim($inv['uom'] ?? 'NOS'),
                    'available_qty' => $stock,
                    'available_variants' => $availVars
                ];
            }
        }

        return $availableMips;
    }
}

