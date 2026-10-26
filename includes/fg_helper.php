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
                   rework_source_entry_id,
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
            $reworkRuns[] = [
                'id' => intval($rw['id']),
                'ok_qty' => $rwOk,
                'production_date' => $rw['production_date'] ?: substr($rw['created_at'], 0, 10),
                'operator_name' => $rw['operator_name'] ?: 'Operator',
                'variants' => $rwVariants
            ];
            $reworkTotalOk += $rwOk;
            $combinedVariants = mergeVariantArrays($combinedVariants, $rwVariants);
        }

        $totalOkQty = $parentOk + $reworkTotalOk;

        // 3. Check duplicate inward status in fg_inward_logs
        $stDup = $pdo->prepare("
            SELECT TOP 1 id, inward_no, ok_qty, has_rework_appended, created_at
            FROM fg_inward_logs
            WHERE LOWER(RTRIM(LTRIM(mip_no))) = LOWER(?)
            ORDER BY id DESC
        ");
        $stDup->execute([$mipNo]);
        $dup = $stDup->fetch(PDO::FETCH_ASSOC);

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
                'already_inwarded' => $dup ? true : false,
                'inward_ref' => $dup ? $dup['inward_no'] : null,
                'inward_ok_qty' => $dup ? floatval($dup['ok_qty']) : 0
            ]
        ];
    }
}

if (!function_exists('saveInwardConsolidated')) {
    /**
     * Save consolidated finished goods inward into fg_inward_logs and update fg_inventory
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

        $rawVariants = $postData['variant_details'] ?? [];
        $variants = is_string($rawVariants) ? json_decode($rawVariants, true) : $rawVariants;

        $rawRwInfo = $postData['rework_appended_info'] ?? [];
        $reworkInfo = is_string($rawRwInfo) ? json_decode($rawRwInfo, true) : $rawRwInfo;
        $hasRework = !empty($postData['has_rework_appended']) || ($reworkOk > 0) || (!empty($reworkInfo));

        if (empty($inwardNo)) {
            $inwardNo = 'FGI-' . date('Y') . '-' . rand(100, 999);
        }
        if (empty($partCode)) {
            return ['success' => false, 'message' => 'Part code is required.'];
        }
        if ($okQty <= 0) {
            return ['success' => false, 'message' => 'Total OK quantity must be greater than zero.'];
        }

        try {
            $pdo->beginTransaction();

            // Check if inward log already exists for this MIP
            $stChkLog = $pdo->prepare("SELECT id FROM fg_inward_logs WHERE mip_no = ?");
            $stChkLog->execute([$mipNo]);
            $existingLogId = $stChkLog->fetchColumn();

            if ($existingLogId) {
                // Update existing inward record with consolidated data
                $stUpdLog = $pdo->prepare("
                    UPDATE fg_inward_logs
                    SET ok_qty = ?,
                        original_ok_qty = ?,
                        rework_ok_qty = ?,
                        has_rework_appended = ?,
                        customer_name = ?,
                        variant_details = ?,
                        rework_appended_info = ?,
                        production_date = ?,
                        received_by = ?,
                        remarks = ?
                    WHERE id = ?
                ");
                $stUpdLog->execute([
                    $okQty,
                    $parentOk,
                    $reworkOk,
                    $hasRework ? 1 : 0,
                    $customer,
                    !empty($variants) ? json_encode($variants) : null,
                    !empty($reworkInfo) ? json_encode($reworkInfo) : null,
                    !empty($prodDate) ? $prodDate : null,
                    $receivedBy,
                    $remarks,
                    $existingLogId
                ]);
            } else {
                // Insert new inward log
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

            // 2. Update fg_inventory
            $stChkInv = $pdo->prepare("SELECT id, total_ok_qty, variant_stock_details FROM fg_inventory WHERE part_code = ?");
            $stChkInv->execute([$partCode]);
            $existingInv = $stChkInv->fetch(PDO::FETCH_ASSOC);

            if ($existingInv) {
                // If this is a fresh inward or update:
                // If updating the same MIP, set quantity directly or accumulate new
                $stUpdInv = $pdo->prepare("
                    UPDATE fg_inventory
                    SET total_ok_qty = ?,
                        customer_name = COALESCE(?, customer_name),
                        variant_stock_details = ?,
                        part_name = ?,
                        uom = ?,
                        last_mip_no = ?,
                        last_production_date = ?,
                        updated_at = GETDATE()
                    WHERE part_code = ?
                ");
                $stUpdInv->execute([
                    $okQty,
                    $customer,
                    !empty($variants) ? json_encode($variants) : null,
                    $partName,
                    $uom,
                    $mipNo,
                    !empty($prodDate) ? $prodDate : null,
                    $partCode
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
                    !empty($variants) ? json_encode($variants) : null,
                    $mipNo,
                    !empty($prodDate) ? $prodDate : null
                ]);
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Finished Goods inward saved successfully!',
                'data' => [
                    'inward_no' => $inwardNo,
                    'mip_no' => $mipNo,
                    'part_code' => $partCode,
                    'part_name' => $partName,
                    'customer_name' => $customer,
                    'total_ok_qty' => $okQty,
                    'uom' => $uom,
                    'has_rework' => $hasRework,
                    'variants' => $variants
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

                // Check stock in fg_inventory
                $stChk = $pdo->prepare("SELECT id, total_ok_qty, customer_name, variant_stock_details FROM fg_inventory WHERE part_code = ?");
                $stChk->execute([$pCode]);
                $inv = $stChk->fetch(PDO::FETCH_ASSOC);

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

                // 2. Deduct from fg_inventory
                $stUpdInv = $pdo->prepare("
                    UPDATE fg_inventory
                    SET total_ok_qty = ?,
                        variant_stock_details = ?,
                        updated_at = GETDATE()
                    WHERE part_code = ?
                ");
                $stUpdInv->execute([
                    $newTotalStock,
                    !empty($updatedVariants) ? json_encode($updatedVariants) : null,
                    $pCode
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
