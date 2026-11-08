<?php
require_once 'db.php';

$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';
$warning = '';


// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isReadOnly) {
        $error = 'View-Only Mode: Administrators cannot modify user data.';
    } elseif (isset($_POST['add_receive']) || isset($_POST['edit_receive'])) {
        $date = $_POST['date'] ?? date('Y-m-d');
        $karigorIdInput = intval($_POST['karigor_id'] ?? 0);
        $cashPaid = floatval($_POST['cash_paid'] ?? 0.0);
        $remark = trim($_POST['remark'] ?? '');
        $items = $_POST['items'] ?? [];
        $receiveId = intval($_POST['id'] ?? 0);

        if ($karigorIdInput <= 0 || empty($items)) {
            $error = 'Invalid Karigor or items list is empty!';
        } else {
            // Check settlement
            if (isset($_POST['edit_receive'])) {
                $stmtOrig = $pdo->prepare("SELECT karigor_id, date FROM karigor_kaj_receives WHERE id = ? AND user_id = ?");
                $stmtOrig->execute([$receiveId, $userId]);
                $origTxn = $stmtOrig->fetch();
                if ($origTxn && (isKarigorSettled($pdo, $origTxn['karigor_id'], $userId, $origTxn['date']) || isKarigorSettled($pdo, $karigorIdInput, $userId, $date))) {
                    if (!$isAdmin) {
                        $error = 'Access Denied: You cannot edit transactions within a settled period.';
                    } else {
                        $warning = 'Bypassed settlement check as administrator.';
                    }
                }
            } else {
                if (isKarigorSettled($pdo, $karigorIdInput, $userId, $date)) {
                    if (!$isAdmin) {
                        $error = 'Access Denied: You cannot add transactions to a settled period.';
                    } else {
                        $warning = 'Bypassed settlement check as administrator.';
                    }
                }
            }
        }

        if (empty($error)) {
            try {
                // Pre-calculate total receive fine to detect duplicate before insert
                $preRecFine = 0.0;
                foreach ($items as $it) {
                    $gross = floatval($it['gross'] ?? 0.0);
                    $less = floatval($it['less'] ?? 0.0);
                    $net = max(0, round($gross - $less, 3));
                    $milting = floatval($it['milting'] ?? 0.0);
                    $wastage = floatval($it['wastage'] ?? 0.0);
                    $netPart1 = floatval($it['net_part1'] ?? 0.0);
                    $netPart2 = floatval($it['net_part2'] ?? 0.0);
                    $wst1Raw = floatval($it['wastage1'] ?? 0.0);
                    $wst2Raw = floatval($it['wastage2'] ?? 0.0);
                    $extraPure = floatval($it['extra_pure'] ?? 0.0);
                    if ($netPart1 > 0 || $netPart2 > 0) {
                        $hisab1 = ($wst1Raw > 50) ? $wst1Raw : (($wst1Raw > 0) ? ($milting + $wst1Raw) : ($milting + $wastage));
                        $hisab2 = ($wst2Raw > 50) ? $wst2Raw : (($wst2Raw > 0) ? ($milting + $wst2Raw) : ($milting + $wastage));
                        $preRecFine += round(($netPart1 * ($hisab1 / 100.0)) + ($netPart2 * ($hisab2 / 100.0)) + $extraPure, 3);
                    } else {
                        $hisab = round($milting + $wastage, 2);
                        $preRecFine += round((($net * $hisab) / 100.0) + $extraPure, 3);
                    }
                }
                $preRecFine = round($preRecFine, 3);

                // Duplicate Check
                $confirmDuplicate = isset($_POST['confirm_duplicate']) && $_POST['confirm_duplicate'] == 1;
                $dup = !isset($_POST['edit_receive']) ? findDuplicateKarigorReceive($pdo, $userId, $karigorIdInput, $date, $preRecFine, $cashPaid) : null;

                if ($dup && !$confirmDuplicate) {
                    $dupAge = time() - strtotime($dup['created_at']);
                    if ($dupAge < 300) {
                        $error = "Double Entry Blocked: An identical Kaj Receive (#ID: {$dup['id']}) was already saved just moments ago (" . date('H:i:s', strtotime($dup['created_at'])) . ")! Duplicate submission prevented.";
                    } else {
                        $error = "Duplicate Entry Detected: An identical Kaj Receive (#ID: {$dup['id']}) already exists for this Karigor on {$date}.<br><label class='inline-flex items-center space-x-1.5 mt-2 cursor-pointer text-amber-400 font-bold'><input type='checkbox' name='confirm_duplicate' value='1' class='rounded'> <span>Yes, save this duplicate entry anyway</span></label>";
                    }
                } else {
                    $pdo->beginTransaction();
                
                $totalReceiveFine = 0.0;
                $totalProfitFine = 0.0;
                
                if (isset($_POST['edit_receive'])) {
                    // Update existing main entry
                    $stmt = $pdo->prepare("UPDATE karigor_kaj_receives SET date = ?, karigor_id = ?, cash_paid = ?, remark = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$date, $karigorIdInput, $cashPaid, $remark, $receiveId, $userId]);
                    
                    // Delete previous items
                    $stmtDel = $pdo->prepare("DELETE FROM karigor_kaj_receive_items WHERE karigor_receive_id = ?");
                    $stmtDel->execute([$receiveId]);
                } else {
                    // Insert new main entry
                    $stmt = $pdo->prepare("INSERT INTO karigor_kaj_receives (user_id, date, karigor_id, cash_paid, remark, total_receive_fine, total_profit_less) VALUES (?, ?, ?, ?, ?, 0, 0)");
                    $stmt->execute([$userId, $date, $karigorIdInput, $cashPaid, $remark]);
                    $receiveId = $pdo->lastInsertId();
                }
                
                // Prepare items statement
                $stmtItem = $pdo->prepare("
                    INSERT INTO karigor_kaj_receive_items (karigor_receive_id, item, gross, less, net, milting, wastage, hisab, receive_fine, profit_less, net_part1, net_part2, wastage1, wastage2, extra_pure) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $it) {
                    $item = trim($it['item'] ?? 'Ornament');
                    $gross = floatval($it['gross'] ?? 0.0);
                    $less = floatval($it['less'] ?? 0.0);
                    $milting = floatval($it['milting'] ?? 0.0);
                    $wastage = floatval($it['wastage'] ?? 0.0);
                    
                    $netPart1 = floatval($it['net_part1'] ?? 0.0);
                    $netPart2 = floatval($it['net_part2'] ?? 0.0);
                    $wst1Raw = floatval($it['wastage1'] ?? 0.0);
                    $wst2Raw = floatval($it['wastage2'] ?? 0.0);
                    $extraPure = floatval($it['extra_pure'] ?? 0.0);
                    
                    if (empty($item)) continue;
                    
                    $net = max(0, round($gross - $less, 3));
                    if ($netPart1 > 0 || $netPart2 > 0) {
                        $hisab1 = ($wst1Raw > 50) ? $wst1Raw : (($wst1Raw > 0) ? ($milting + $wst1Raw) : ($milting + $wastage));
                        $wstVal1 = ($wst1Raw > 50) ? max(0, $wst1Raw - $milting) : (($wst1Raw > 0) ? $wst1Raw : $wastage);
                        
                        $hisab2 = ($wst2Raw > 50) ? $wst2Raw : (($wst2Raw > 0) ? ($milting + $wst2Raw) : ($milting + $wastage));
                        $wstVal2 = ($wst2Raw > 50) ? max(0, $wst2Raw - $milting) : (($wst2Raw > 0) ? $wst2Raw : $wastage);

                        $hisab = $hisab1;
                        $net = $netPart1 + $netPart2;
                        $receiveFine = round(($netPart1 * ($hisab1 / 100.0)) + ($netPart2 * ($hisab2 / 100.0)) + $extraPure, 3);
                        $profitLess = round(($netPart1 * ($wstVal1 / 100.0)) + ($netPart2 * ($wstVal2 / 100.0)), 3);
                    } else {
                        $hisab = round($milting + $wastage, 2);
                        $receiveFine = round((($net * $hisab) / 100.0) + $extraPure, 3);
                        $profitLess = round(($wastage * $net) / 100.0, 3);
                    }
                    
                    $stmtItem->execute([
                        $receiveId, $item, $gross, $less, $net, $milting, $wastage, $hisab, $receiveFine, $profitLess, $netPart1, $netPart2, $wst1Raw, $wst2Raw, $extraPure
                    ]);
                    
                    $totalReceiveFine += $receiveFine;
                    $totalProfitFine += $profitLess;
                }
                
                // Update main record totals
                $stmtUpdate = $pdo->prepare("UPDATE karigor_kaj_receives SET total_receive_fine = ?, total_profit_less = ? WHERE id = ?");
                $stmtUpdate->execute([$totalReceiveFine, $totalProfitFine, $receiveId]);
                
                $pdo->commit();
                
                if (!empty($_POST['return_url'])) {
                    header("Location: " . $_POST['return_url']);
                    exit();
                }
                
                    $success = 'Kaj Receive entry saved successfully!';
                    $action = 'list';
                }

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to save entry: ' . $e->getMessage();
            }
        }
    }
}

// Handle Delete Kaj Receive Entry
if (isset($_GET['delete'])) {
    if ($isReadOnly) {
        die("Access Denied: View-Only Mode is active.");
    }
    
    $id = intval($_GET['delete']);
    
    $stmt = $pdo->prepare("SELECT karigor_id, date FROM karigor_kaj_receives WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $origTxn = $stmt->fetch();
    
    if ($origTxn) {
        if (isKarigorSettled($pdo, $origTxn['karigor_id'], $userId, $origTxn['date'])) {
            if (!$isAdmin) {
                die("Access Denied: This transaction is settled and cannot be deleted by non-administrators.");
            }
        }
        
        try {
            $pdo->beginTransaction();
            $stmtDelItems = $pdo->prepare("DELETE FROM karigor_kaj_receive_items WHERE karigor_receive_id = ?");
            $stmtDelItems->execute([$id]);

            $stmtDel = $pdo->prepare("DELETE FROM karigor_kaj_receives WHERE id = ? AND user_id = ?");
            $stmtDel->execute([$id, $userId]);
            $pdo->commit();
            $success = 'Receive entry deleted successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Failed to delete entry: " . $e->getMessage());
        }
    }
    header("Location: karigor_receive.php");
    exit();
}

// Fetch all Kaj Receive entries (Ordered by recent entry creation)
$stmt = $pdo->prepare("
    SELECT kr.*, k.name as karigor_name 
    FROM karigor_kaj_receives kr 
    JOIN karigors k ON kr.karigor_id = k.id 
    WHERE kr.user_id = ? 
    ORDER BY kr.id DESC
");
$stmt->execute([$userId]);
$receives = $stmt->fetchAll();


// Fetch karigors for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM karigors WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$karigorsList = $stmt->fetchAll();

// If action is edit, fetch target record and items
$editEntry = null;
$editItems = [];
if ($action === 'edit') {
    $editId = intval($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM karigor_kaj_receives WHERE id = ? AND user_id = ?");
    $stmt->execute([$editId, $userId]);
    $editEntry = $stmt->fetch();
    
    if ($editEntry) {
        $stmtItems = $pdo->prepare("SELECT * FROM karigor_kaj_receive_items WHERE karigor_receive_id = ?");
        $stmtItems->execute([$editId]);
        $editItems = $stmtItems->fetchAll();
    }
}

require_once 'header.php';
?>

<!-- Feedback Messages -->
<?php if ($error): ?>
    <div class="mb-5 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs flex items-center space-x-2">
        <span class="material-symbols-rounded text-lg">error</span> <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($warning): ?>
    <div class="mb-5 p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs flex items-center space-x-2">
        <span class="material-symbols-rounded text-lg">warning</span> <span><?= htmlspecialchars($warning) ?></span>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="mb-5 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs flex items-center space-x-2">
        <span class="material-symbols-rounded text-lg">check_circle</span> <span><?= htmlspecialchars($success) ?></span>
    </div>
<?php endif; ?>

<?php if ($action === 'new' || $action === 'edit'): 
    $isTxnSettled = false;
    if ($action === 'edit' && $editEntry) {
        $isTxnSettled = isKarigorSettled($pdo, $editEntry['karigor_id'], $userId, $editEntry['date']);
    }
    $blockForm = ($isTxnSettled && !$isAdmin) || $isReadOnly;
?>
    <!-- Kaj Receive Add/Edit Form -->
    <div class="max-w-3xl mx-auto premium-card">
        <h2 class="title-section text-white mb-6 flex items-center justify-between">
            <span class="flex items-center">
                <span class="material-symbols-rounded text-emerald-400 mr-2"><?= $action === 'edit' ? 'edit_note' : 'archive' ?></span> 
                <?= $action === 'edit' ? 'Edit Kaj Receive' : 'Kaj Receive (Karigor)' ?>
            </span>
            <a href="karigor_receive.php" class="text-xs text-slate-400 hover:text-white font-normal">Cancel</a>
        </h2>
        
        <form method="POST" id="kajForm" class="space-y-5">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_GET['return_url'] ?? '') ?>">
            <?php if ($action === 'edit'): ?>
                <input type="hidden" name="id" value="<?= $editEntry['id'] ?>">
            <?php endif; ?>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#d8a735] mb-2">Date *</label>
                    <input type="date" name="date" value="<?= $action === 'edit' ? $editEntry['date'] : date('Y-m-d') ?>" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#d8a735] mb-2">Select Karigor *</label>
                    <select name="karigor_id" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input searchable-select" placeholder="-- Choose Karigor --">
                        <option value="">-- Choose Karigor --</option>
                        <?php foreach ($karigorsList as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= ($action === 'edit' ? $editEntry['karigor_id'] == $k['id'] : ((isset($_GET['karigor_id']) && $_GET['karigor_id'] == $k['id']) ? 'selected' : '')) ? 'selected' : '' ?>><?= htmlspecialchars($k['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Items Dynamic Container -->
            <div class="space-y-4 pt-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-extrabold uppercase tracking-widest text-[#d8a735]">Received Ornaments / Fine Items</span>
                    <?php if (!$blockForm): ?>
                        <button type="button" onclick="addItemRow()" class="text-xs text-[#d8a735] hover:underline flex items-center font-semibold">
                            <span class="material-symbols-rounded text-sm mr-1">add_circle</span> Add Item
                        </button>
                    <?php endif; ?>
                </div>

                <div id="itemRows" class="space-y-4">
                    <!-- Dynamic rows injected here -->
                </div>
            </div>

            <!-- Summary Totals -->
            <div class="p-4 rounded-2xl bg-slate-950/80 border border-white/[0.08] grid grid-cols-1 sm:grid-cols-2 gap-4 my-4 font-mono text-xs">
                <div>
                    <span class="text-slate-300 text-[10px] uppercase font-bold tracking-wider block">Total Receive Fine (g)</span>
                    <span id="grandTotalReceiveFine" class="text-emerald-400 font-bold text-base">0.000 g</span>
                </div>
                <div>
                    <span class="text-slate-300 text-[10px] uppercase font-bold tracking-wider block">Total Profit Less (g)</span>
                    <span id="grandTotalProfitLess" class="text-amber-400 font-bold text-base">0.000 g</span>
                </div>
            </div>


            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Cash Paid / Labor Charge (₹)</label>
                    <input type="number" step="0.01" name="cash_paid" value="<?= $action === 'edit' ? $editEntry['cash_paid'] : '' ?>" <?= $blockForm ? 'disabled' : '' ?> class="premium-input" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Remark / Narration</label>
                    <input type="text" name="remark" value="<?= $action === 'edit' ? htmlspecialchars($editEntry['remark']) : '' ?>" <?= $blockForm ? 'disabled' : '' ?> class="premium-input" placeholder="Optional details...">
                </div>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-4 border-t border-white/[0.04]">
                <a href="<?= !empty($_GET['return_url']) ? htmlspecialchars($_GET['return_url']) : 'karigor_receive.php' ?>" class="btn-secondary text-sm px-5 py-2.5">Cancel</a>
                <?php if (!$blockForm): ?>
                    <button type="submit" name="<?= $action === 'edit' ? 'edit_receive' : 'add_receive' ?>" class="btn-gold text-sm px-5 py-2.5">
                        <?= $action === 'edit' ? 'Update Kaj Receive' : 'Save Kaj Receive' ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script>
        var rowCount = 0;
        var formBlocked = <?= $blockForm ? 'true' : 'false' ?>;

        function toggleNetSplit(idx) {
            const sec = document.getElementById(`netSplitSection_${idx}`);
            const icon = document.getElementById(`netSplitIcon_${idx}`);
            if (!sec) return;
            if (sec.classList.contains('hidden')) {
                sec.classList.remove('hidden');
                if (icon) icon.textContent = 'remove';
                
                const gross = parseFloat(document.getElementById(`gross_${idx}`).value) || 0;
                const less = parseFloat(document.getElementById(`less_${idx}`).value) || 0;
                const net = Math.max(0, gross - less);
                
                const p1 = document.getElementById(`netPart1_${idx}`);
                const p2 = document.getElementById(`netPart2_${idx}`);
                
                if (p1 && p2 && !p1.value && !p2.value) {
                    p1.value = net.toFixed(3);
                    p2.value = (0).toFixed(3);
                }
            } else {
                sec.classList.add('hidden');
                if (icon) icon.textContent = 'add';
            }
            calculateRow(idx);
        }

        function toggleExtraPure(idx) {
            const sec = document.getElementById(`extraPureSection_${idx}`);
            const icon = document.getElementById(`extraPureIcon_${idx}`);
            if (!sec) return;
            if (sec.classList.contains('hidden')) {
                sec.classList.remove('hidden');
                if (icon) icon.textContent = 'remove';
            } else {
                sec.classList.add('hidden');
                if (icon) icon.textContent = 'add';
                const ep = document.getElementById(`extraPure_${idx}`);
                if (ep) ep.value = '';
            }
            calculateRow(idx);
        }

        function onPart1Input(idx) {
            const gross = parseFloat(document.getElementById(`gross_${idx}`).value) || 0;
            const less = parseFloat(document.getElementById(`less_${idx}`).value) || 0;
            const totalNet = Math.max(0, gross - less);
            
            const p1Val = parseFloat(document.getElementById(`netPart1_${idx}`).value) || 0;
            const p2El = document.getElementById(`netPart2_${idx}`);
            
            if (p2El) {
                const rem = Math.max(0, totalNet - p1Val);
                p2El.value = rem.toFixed(3);
            }
            calculateRow(idx);
        }

        function onPart2Input(idx) {
            calculateRow(idx);
        }

        function addItemRow(item = '', gross = '', less = '0', milting = '91.80', wastage = '0', netPart1 = '', netPart2 = '', wastage1 = '', wastage2 = '', extraPure = '') {
            var container = document.getElementById('itemRows');
            var div = document.createElement('div');
            div.id = 'row_' + rowCount;
            div.className = 'p-4 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-3 relative';
            
            var isSplitActive = (parseFloat(netPart1) > 0 || parseFloat(netPart2) > 0);
            var isExtraPureActive = (parseFloat(extraPure) > 0);

            div.innerHTML = `
                <div class="flex items-center justify-between border-b border-white/[0.06] pb-2">
                    <span class="text-xs font-extrabold text-[#d8a735] uppercase tracking-wider flex items-center">
                        <span class="material-symbols-rounded text-sm mr-1.5">inventory_2</span> ITEM #${rowCount + 1}
                    </span>
                    ${!formBlocked && rowCount > 0 ? `
                        <button type="button" onclick="removeRow(${rowCount})" class="text-rose-400 hover:text-rose-300 text-xs flex items-center font-bold">
                            <span class="material-symbols-rounded text-sm mr-0.5">delete</span> Remove
                        </button>
                    ` : ''}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Item Name *</label>
                        <input type="text" name="items[${rowCount}][item]" value="${item}" required ${formBlocked ? 'disabled' : ''} class="premium-input text-xs" placeholder="e.g. Gold Necklace">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Gross (g) *</label>
                        <input type="number" step="0.001" name="items[${rowCount}][gross]" id="gross_${rowCount}" value="${gross}" required ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" placeholder="0.000" oninput="calculateRow(${rowCount}, 'main')">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Less (g)</label>
                        <input type="number" step="0.001" name="items[${rowCount}][less]" id="less_${rowCount}" value="${less}" ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" placeholder="0.000" oninput="calculateRow(${rowCount}, 'main')">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Purity / Mel (%) *</label>
                        <input type="number" step="0.001" name="items[${rowCount}][milting]" id="milting_${rowCount}" value="${milting}" required ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" placeholder="0.000" oninput="calculateRow(${rowCount})">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Wastage (%)</label>
                        <input type="number" step="0.001" name="items[${rowCount}][wastage]" id="wastage_${rowCount}" value="${wastage}" ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" placeholder="0.000" oninput="calculateRow(${rowCount})">
                    </div>
                </div>

                <!-- Net Weight Display -->
                <div class="bg-slate-950/80 p-2.5 rounded-xl border border-white/[0.08] mb-1 flex items-center justify-between">
                    <div>
                        <span class="text-slate-300 text-[10px] uppercase font-bold tracking-wider block">Net Weight</span>
                        <div class="text-sm font-bold text-white font-mono mt-0.5" id="netLabel_${rowCount}">0.000 g</div>
                    </div>
                </div>

                <!-- Action Controls: Split Net & Extra Pure Gold -->
                <div class="flex items-center space-x-4 pt-1">
                    <div class="flex items-center space-x-1.5">
                        <button type="button" onclick="toggleNetSplit(${rowCount})" class="w-6 h-6 rounded bg-[#d8a735]/20 border border-[#d8a735]/40 text-[#d8a735] hover:bg-[#d8a735]/30 flex items-center justify-center font-bold text-xs tap-target">
                            <span id="netSplitIcon_${rowCount}" class="material-symbols-rounded text-xs">${isSplitActive ? 'remove' : 'add'}</span>
                        </button>
                        <span class="text-xs font-bold text-slate-200">Split Net</span>
                    </div>

                    <div class="flex items-center space-x-1.5">
                        <button type="button" onclick="toggleExtraPure(${rowCount})" class="w-6 h-6 rounded bg-[#d8a735]/20 border border-[#d8a735]/40 text-[#d8a735] hover:bg-[#d8a735]/30 flex items-center justify-center font-bold text-xs tap-target">
                            <span id="extraPureIcon_${rowCount}" class="material-symbols-rounded text-xs">${isExtraPureActive ? 'remove' : 'add'}</span>
                        </button>
                        <span class="text-xs font-bold text-slate-200">Extra Pure Gold</span>
                    </div>
                </div>

                <!-- Split Net Container -->
                <div id="netSplitSection_${rowCount}" class="${isSplitActive ? '' : 'hidden'} p-3.5 rounded-xl bg-slate-950/80 border border-[#d8a735]/30 space-y-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-[#d8a735]">Split Net Calculation</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-[#d8a735] mb-1">Part 1 Net (g)</label>
                            <input type="number" step="0.001" id="netPart1_${rowCount}" name="items[${rowCount}][net_part1]" value="${netPart1}" oninput="onPart1Input(${rowCount})" class="premium-input text-xs font-mono" placeholder="0.000">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-[#d8a735] mb-1">Part 1 Wastage (%)</label>
                            <input type="number" step="0.001" id="wastage1_${rowCount}" name="items[${rowCount}][wastage1]" value="${wastage1}" oninput="calculateRow(${rowCount})" class="premium-input text-xs font-mono" placeholder="Default">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Part 2 Net (g)</label>
                            <input type="number" step="0.001" id="netPart2_${rowCount}" name="items[${rowCount}][net_part2]" value="${netPart2}" oninput="onPart2Input(${rowCount})" class="premium-input text-xs font-mono" placeholder="0.000">
                        </div>
                        <div>
                            <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Part 2 Wastage (%)</label>
                            <input type="number" step="0.001" id="wastage2_${rowCount}" name="items[${rowCount}][wastage2]" value="${wastage2}" oninput="calculateRow(${rowCount})" class="premium-input text-xs font-mono" placeholder="Default">
                        </div>
                    </div>
                </div>

                <!-- Extra Pure Input Section -->
                <div id="extraPureSection_${rowCount}" class="${isExtraPureActive ? '' : 'hidden'} p-3 rounded-xl bg-slate-950/40 border border-[#d8a735]/20">
                    <label class="block text-[8px] font-bold uppercase text-[#d8a735] mb-1">Extra Pure Gold (g)</label>
                    <input type="number" step="0.001" id="extraPure_${rowCount}" name="items[${rowCount}][extra_pure]" value="${extraPure}" oninput="calculateRow(${rowCount})" class="premium-input text-xs font-mono" placeholder="0.000">
                </div>

                <!-- Calculated Output per Item -->
                <div class="bg-slate-900/60 p-2.5 rounded-xl flex items-center justify-between text-xs font-mono">
                    <span class="text-slate-400">Receive Fine: <strong id="receiveFine_${rowCount}" class="text-emerald-400">0.000 g</strong></span>
                    <span class="text-slate-400">Profit Less: <strong id="profitLess_${rowCount}" class="text-amber-400">0.000 g</strong></span>
                </div>
            `;

            container.appendChild(div);
            calculateRow(rowCount);
            rowCount++;
        }

        function removeRow(idx) {
            var el = document.getElementById('row_' + idx);
            if (el) el.remove();
            updateGrandTotals();
        }

        function calculateRow(id, source = '') {
            var grossEl = document.getElementById('gross_' + id);
            if (!grossEl) return;
            var gross = parseFloat(grossEl.value) || 0;
            var less = parseFloat(document.getElementById('less_' + id).value) || 0;
            var net = Math.max(0, gross - less);
            var mel = parseFloat(document.getElementById('milting_' + id).value) || 0;
            var wst = parseFloat(document.getElementById('wastage_' + id).value) || 0;
            
            var netLabel = document.getElementById('netLabel_' + id);
            if (netLabel) netLabel.innerText = net.toFixed(3) + ' g';
            
            const epEl = document.getElementById(`extraPure_${id}`);
            const extraPure = (epEl && epEl.offsetParent !== null) ? (parseFloat(epEl.value) || 0) : 0;

            const netSplitSec = document.getElementById(`netSplitSection_${id}`);
            const isSplitActive = netSplitSec && !netSplitSec.classList.contains('hidden');

            var receiveFine = 0;
            var profitLess = 0;

            if (isSplitActive) {
                const p1El = document.getElementById(`netPart1_${id}`);
                const p2El = document.getElementById(`netPart2_${id}`);
                
                if (source === 'main' && net > 0) {
                    p1El.value = net.toFixed(3);
                    p2El.value = (0).toFixed(3);
                }
                
                const part1Net = parseFloat(p1El?.value) || 0;
                const part2Net = parseFloat(p2El?.value) || 0;
                const rawWst1 = document.getElementById(`wastage1_${id}`)?.value;
                const rawWst2 = document.getElementById(`wastage2_${id}`)?.value;

                const wst1Val = (rawWst1 !== undefined && rawWst1 !== '') ? parseFloat(rawWst1) : wst;
                const wst2Val = (rawWst2 !== undefined && rawWst2 !== '') ? parseFloat(rawWst2) : wst;

                const hisab1 = (wst1Val > 50) ? wst1Val : ((wst1Val > 0) ? (mel + wst1Val) : (mel + wst));
                const profitWst1 = (wst1Val > 50) ? Math.max(0, wst1Val - mel) : ((wst1Val > 0) ? wst1Val : wst);

                const hisab2 = (wst2Val > 50) ? wst2Val : ((wst2Val > 0) ? (mel + wst2Val) : (mel + wst));
                const profitWst2 = (wst2Val > 50) ? Math.max(0, wst2Val - mel) : ((wst2Val > 0) ? wst2Val : wst);

                receiveFine = (part1Net * (hisab1 / 100)) + (part2Net * (hisab2 / 100)) + extraPure;
                profitLess = (part1Net * (profitWst1 / 100)) + (part2Net * (profitWst2 / 100));
            } else {
                var hisab = mel + wst;
                receiveFine = (net * (hisab / 100)) + extraPure;
                profitLess = net * (wst / 100);
            }

            document.getElementById('receiveFine_' + id).innerText = receiveFine.toFixed(3) + ' g';
            document.getElementById('profitLess_' + id).innerText = profitLess.toFixed(3) + ' g';

            updateGrandTotals();
        }

        function updateGrandTotals() {
            var grandReceive = 0;
            var grandProfit = 0;
            for (var i = 0; i < rowCount; i++) {
                var rfEl = document.getElementById('receiveFine_' + i);
                var pfEl = document.getElementById('profitLess_' + i);
                if (rfEl && pfEl) {
                    grandReceive += parseFloat(rfEl.innerText) || 0;
                    grandProfit += parseFloat(pfEl.innerText) || 0;
                }
            }
            document.getElementById('grandTotalReceiveFine').innerText = grandReceive.toFixed(3) + ' g';
            document.getElementById('grandTotalProfitLess').innerText = grandProfit.toFixed(3) + ' g';
        }

        window.onload = function() {
            <?php if ($action === 'edit' && !empty($editItems)): ?>
                <?php foreach ($editItems as $it): ?>
                    addItemRow(
                        "<?= htmlspecialchars($it['item']) ?>",
                        "<?= floatval($it['gross']) ?>",
                        "<?= floatval($it['less']) ?>",
                        "<?= floatval($it['milting']) ?>",
                        "<?= floatval($it['wastage']) ?>",
                        "<?= floatval($it['net_part1'] ?? 0.0) ?>",
                        "<?= floatval($it['net_part2'] ?? 0.0) ?>",
                        "<?= floatval($it['wastage1'] ?? 0.0) ?>",
                        "<?= floatval($it['wastage2'] ?? 0.0) ?>",
                        "<?= floatval($it['extra_pure'] ?? 0.0) ?>"
                    );
                <?php endforeach; ?>
            <?php else: ?>
                addItemRow();
            <?php endif; ?>
        }
    </script>

<?php else: ?>
    <!-- Kaj Receive Entries List View -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white flex items-center">
                <span class="material-symbols-rounded text-emerald-400 mr-2 text-3xl">archive</span> Kaj Receive (Karigor)
            </h1>
        </div>
        <?php if (!$isReadOnly): ?>
            <a href="karigor_receive.php?action=new" class="btn-gold inline-flex items-center text-xs px-3.5 py-2 shadow-md">
                <span class="material-symbols-rounded text-sm mr-1">add</span> New Kaj Receive
            </a>
        <?php endif; ?>
    </div>

    <div class="space-y-4">
        <?php if (empty($receives)): ?>
            <div class="premium-card text-center py-10 text-slate-500">No Kaj Receive entries recorded.</div>
        <?php else: ?>
            <?php foreach ($receives as $r): ?>
                <div id="entry_<?= $r['id'] ?>" class="premium-card bg-[#111111]/85">
                    <div class="flex justify-between items-start border-b border-white/[0.04] pb-2.5 mb-2.5">
                        <div>
                            <span class="text-[9px] text-slate-500 font-mono"><?= date('d/m/Y', strtotime($r['date'])) ?></span>
                            <h3 class="font-bold text-white text-sm mt-0.5"><?= htmlspecialchars($r['karigor_name']) ?></h3>
                        </div>
                        <div class="text-right">
                            <span class="text-[8px] text-slate-500 block uppercase">Cash Paid</span>
                            <span class="font-mono text-amber-400 font-bold text-xs"><?= $r['cash_paid'] > 0 ? '₹' . number_format($r['cash_paid'], 0) : '--' ?></span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-[9px] text-slate-500 uppercase block">Kaj Receive Fine</span>
                            <span class="font-mono text-emerald-400 text-sm font-bold">+<?= number_format($r['total_receive_fine'], 3) ?> g</span>
                        </div>
                        <?php if (!$isReadOnly): ?>
                            <div class="flex items-center space-x-2">
                                <a href="karigor_receive.php?action=edit&id=<?= $r['id'] ?>&return_url=<?= urlencode("karigor_receive.php#entry_{$r['id']}") ?>" class="w-8 h-8 rounded-lg bg-slate-900 border border-white/[0.05] flex items-center justify-center text-slate-400 hover:text-white transition-colors tap-target">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </a>
                                <a href="karigor_receive.php?delete=<?= $r['id'] ?>" onclick="return confirm('Are you sure you want to delete this receive entry?')" class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require_once 'footer.php';
?>

