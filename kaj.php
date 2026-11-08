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
    } elseif (isset($_POST['add_kaj']) || isset($_POST['edit_kaj'])) {
        $date = $_POST['date'] ?? date('Y-m-d');
        $bapariIdInput = intval($_POST['bapari_id']);
        $cashBill = floatval($_POST['cash_bill'] ?? 0.0);
        $remark = trim($_POST['remark'] ?? '');
        $items = $_POST['items'] ?? []; // Array of items

        if (isset($_POST['edit_kaj'])) {
            $kajEntryId = intval($_POST['id']);
            $stmt = $pdo->prepare("SELECT bapari_id, date FROM kaj_entries WHERE id = ? AND user_id = ?");
            $stmt->execute([$kajEntryId, $userId]);
            $origTxn = $stmt->fetch();
            
            if (!$origTxn) {
                $error = 'Transaction not found!';
            } elseif (isSettled($pdo, $origTxn['bapari_id'], $userId, $origTxn['date']) || isSettled($pdo, $bapariIdInput, $userId, $date)) {
                if (!$isAdmin) {
                    $error = 'Access Denied: You are not authorized to edit jobs in a settled period.';
                }
            }
        } else {
            if (isSettled($pdo, $bapariIdInput, $userId, $date)) {
                if (!$isAdmin) {
                    $error = 'Access Denied: You cannot add job transactions to a settled period.';
                }
            }
        }

        if ($bapariIdInput <= 0 || empty($items)) {
            $error = 'Invalid Customer or items list is empty!';
        }

        if (empty($error)) {
            try {
                // Pre-calculate total fine to detect duplicate before insert
                $preKajFine = 0.0;
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
                        $preKajFine += round(($netPart1 * ($hisab1 / 100.0)) + ($netPart2 * ($hisab2 / 100.0)) + $extraPure, 3);
                    } else {
                        $hisab = round($milting + $wastage, 2);
                        $preKajFine += round((($net * $hisab) / 100.0) + $extraPure, 3);
                    }
                }
                $preKajFine = round($preKajFine, 3);

                // Duplicate Check
                $confirmDuplicate = isset($_POST['confirm_duplicate']) && $_POST['confirm_duplicate'] == 1;
                $dup = !isset($_POST['edit_kaj']) ? findDuplicateKaj($pdo, $userId, $bapariIdInput, $date, $preKajFine, $cashBill) : null;

                if ($dup && !$confirmDuplicate) {
                    $dupAge = time() - strtotime($dup['created_at']);
                    if ($dupAge < 300) {
                        $error = "Double Entry Blocked: An identical Kaarigari Job (#ID: {$dup['id']}) was already saved just moments ago (" . date('H:i:s', strtotime($dup['created_at'])) . ")! Duplicate submission prevented.";
                    } else {
                        $error = "Duplicate Entry Detected: An identical Kaarigari Job (#ID: {$dup['id']}) already exists for this Bapari on {$date}.<br><label class='inline-flex items-center space-x-1.5 mt-2 cursor-pointer text-amber-400 font-bold'><input type='checkbox' name='confirm_duplicate' value='1' class='rounded'> <span>Yes, save this duplicate entry anyway</span></label>";
                    }
                } else {
                    $pdo->beginTransaction();
                
                $totalKajFine = 0.0;
                $totalProfitFine = 0.0;
                
                if (isset($_POST['edit_kaj'])) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE kaj_entries SET date = ?, bapari_id = ?, cash_bill = ?, remark = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$date, $bapariIdInput, $cashBill, $remark, $kajEntryId, $userId]);
                    
                    // Delete previous items
                    $stmtDel = $pdo->prepare("DELETE FROM kaj_items WHERE kaj_entry_id = ?");
                    $stmtDel->execute([$kajEntryId]);
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("INSERT INTO kaj_entries (user_id, date, bapari_id, cash_bill, remark) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $date, $bapariIdInput, $cashBill, $remark]);
                    $kajEntryId = $pdo->lastInsertId();
                }
                
                // Prepare items statement
                $stmtItem = $pdo->prepare("
                    INSERT INTO kaj_items (kaj_entry_id, item, gross, less, net, milting, wastage, hisab, kaj_fine, profit_fine, net_part1, net_part2, wastage1, wastage2, extra_pure) 
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
                    
                    // Calculations
                    $net = max(0, round($gross - $less, 3));
                    if ($netPart1 > 0 || $netPart2 > 0) {
                        $hisab1 = ($wst1Raw > 50) ? $wst1Raw : (($wst1Raw > 0) ? ($milting + $wst1Raw) : ($milting + $wastage));
                        $wstVal1 = ($wst1Raw > 50) ? max(0, $wst1Raw - $milting) : (($wst1Raw > 0) ? $wst1Raw : $wastage);
                        
                        $hisab2 = ($wst2Raw > 50) ? $wst2Raw : (($wst2Raw > 0) ? ($milting + $wst2Raw) : ($milting + $wastage));
                        $wstVal2 = ($wst2Raw > 50) ? max(0, $wst2Raw - $milting) : (($wst2Raw > 0) ? $wst2Raw : $wastage);

                        $hisab = $hisab1;
                        $net = $netPart1 + $netPart2;
                        $kajFine = round(($netPart1 * ($hisab1 / 100.0)) + ($netPart2 * ($hisab2 / 100.0)) + $extraPure, 3);
                        $profitFine = round(($netPart1 * ($wstVal1 / 100.0)) + ($netPart2 * ($wstVal2 / 100.0)), 3);
                    } else {
                        $hisab = round($milting + $wastage, 2);
                        $kajFine = round((($net * $hisab) / 100.0) + $extraPure, 3);
                        $profitFine = round(($wastage * $net) / 100.0, 3);
                    }
                    
                    $stmtItem->execute([
                        $kajEntryId, $item, $gross, $less, $net, $milting, $wastage, $hisab, $kajFine, $profitFine, $netPart1, $netPart2, $wst1Raw, $wst2Raw, $extraPure
                    ]);
                    
                    $totalKajFine += $kajFine;
                    $totalProfitFine += $profitFine;
                }
                
                // Update the main entry totals
                $stmtUpdate = $pdo->prepare("UPDATE kaj_entries SET total_kaj_fine = ?, total_profit_fine = ? WHERE id = ?");
                $stmtUpdate->execute([$totalKajFine, $totalProfitFine, $kajEntryId]);
                
                $pdo->commit();
                
                if (!empty($_POST['return_url'])) {
                    header("Location: " . $_POST['return_url']);
                    exit();
                }
                
                    $success = 'Kaarigari Job saved successfully!';
                    $action = 'list';
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to save entry: ' . $e->getMessage();
            }
        }
    }
}

// Handle Delete Kaj Entry
if (isset($_GET['delete'])) {
    if ($isReadOnly) {
        die("Access Denied: View-Only Mode is active.");
    }
    
    $id = intval($_GET['delete']);
    
    $stmt = $pdo->prepare("SELECT bapari_id, date FROM kaj_entries WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $origTxn = $stmt->fetch();
    
    if ($origTxn) {
        if (isSettled($pdo, $origTxn['bapari_id'], $userId, $origTxn['date'])) {
            if (!$isAdmin) {
                die("Access Denied: This transaction is settled and cannot be deleted by non-administrators.");
            }
        }
        
        try {
            $pdo->beginTransaction();
            $stmtDelItems = $pdo->prepare("DELETE FROM kaj_items WHERE kaj_entry_id = ?");
            $stmtDelItems->execute([$id]);

            $stmtDel = $pdo->prepare("DELETE FROM kaj_entries WHERE id = ? AND user_id = ?");
            $stmtDel->execute([$id, $userId]);
            $pdo->commit();
            $success = 'Kaarigari Job deleted successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Failed to delete entry: " . $e->getMessage());
        }
    }
    header("Location: kaj.php");
    exit();
}

$editEntry = null;
$editItems = [];
if ($action === 'edit') {
    $editId = intval($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM kaj_entries WHERE id = ? AND user_id = ?");
    $stmt->execute([$editId, $userId]);
    $editEntry = $stmt->fetch();
    
    if (!$editEntry) {
        $error = 'Entry not found!';
        $action = 'list';
    } else {
        $stmtItems = $pdo->prepare("SELECT * FROM kaj_items WHERE kaj_entry_id = ?");
        $stmtItems->execute([$editId]);
        $editItems = $stmtItems->fetchAll();
    }
}

// Fetch all Kaj Entries joined with Baparis (Ordered by recent entry creation)
$stmt = $pdo->prepare("
    SELECT k.*, b.name as bapari_name 
    FROM kaj_entries k
    JOIN baparis b ON k.bapari_id = b.id 
    WHERE k.user_id = ? 
    ORDER BY k.id DESC
");
$stmt->execute([$userId]);
$kajEntries = $stmt->fetchAll();


// Fetch Baparis for form selection
$stmt = $pdo->prepare("SELECT id, name FROM baparis WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$baparisList = $stmt->fetchAll();

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

<?php if ($isReadOnly): ?>
    <div class="mb-5 p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs flex items-center space-x-2 no-print">
        <span class="material-symbols-rounded text-lg">info</span>
        <span><strong>View-Only Mode:</strong> Administrators cannot create or modify transactions on this account.</span>
    </div>
<?php endif; ?>

<?php if ($action === 'new' || $action === 'edit'): 
    $blockForm = false;
    if ($action === 'edit' && $editEntry) {
        $isTxnSettled = isSettled($pdo, $editEntry['bapari_id'], $userId, $editEntry['date']);
        $blockForm = ($isTxnSettled && !$isAdmin) || $isReadOnly;
        
        if ($isTxnSettled && !$isReadOnly) {
            if ($blockForm) {
                $error = 'Access Denied: This transaction is settled and cannot be edited by non-administrators.';
            } else {
                $warning = '⚠️ WARNING: This transaction belongs to a settled period. Editing it will shift settled balances.';
            }
        }
    }
?>
    <!-- Add/Edit Kaj Entry Form -->
    <div class="premium-card">
        <h2 class="title-section text-white mb-6 flex items-center">
            <span class="material-symbols-rounded text-[#F4B400] mr-2">construction</span> <?= $action === 'edit' ? 'Edit' : 'Add' ?> Kaarigari Job Entry
        </h2>
        
        <!-- Error & Warning blocks -->
        <?php if ($error): ?>
            <div class="mb-5 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($warning): ?>
            <div class="mb-5 p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs">
                <?= htmlspecialchars($warning) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="kajForm" class="space-y-5">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_GET['return_url'] ?? '') ?>">
            <?php if ($action === 'edit'): ?>
                <input type="hidden" name="id" value="<?= $editEntry['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#d8a735] mb-2">Date *</label>
                    <input type="date" name="date" value="<?= $action === 'edit' ? $editEntry['date'] : date('Y-m-d') ?>" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#d8a735] mb-2">Select Customer *</label>
                    <select name="bapari_id" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input searchable-select" placeholder="-- Choose Customer --">
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($baparisList as $b): 
                            $selected = ($action === 'edit' && intval($editEntry['bapari_id']) === intval($b['id'])) ? 'selected' : '';
                        ?>
                            <option value="<?= $b['id'] ?>" <?= $selected ?>><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Majdoori Bill / Labor Charge (₹)</label>
                    <input type="number" step="0.01" name="cash_bill" value="<?= $action === 'edit' ? $editEntry['cash_bill'] : '' ?>" <?= $blockForm ? 'disabled' : '' ?> class="premium-input" placeholder="0.00">
                </div>
            </div>
            
            <!-- Items Area -->
            <div class="mt-4">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-800">
                    <h3 class="text-xs font-extrabold uppercase tracking-widest text-[#d8a735]">Item Details</h3>
                </div>
                
                <div id="itemRows" class="space-y-4">
                    <!-- Dynamic rows injected here -->
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Remark / Narration</label>
                <input type="text" name="remark" value="<?= $action === 'edit' ? htmlspecialchars($editEntry['remark']) : '' ?>" <?= $blockForm ? 'disabled' : '' ?> class="premium-input" placeholder="Optional details...">
            </div>
            
            <div class="flex items-center justify-between pt-4 border-t border-slate-800">
                <div class="text-xs font-mono text-slate-400">
                    Est. Total Gold: <span id="totalFineDisp" class="text-rose-400 font-bold">0.000 g</span>
                </div>
                <div class="flex items-center space-x-3">
                    <a href="<?= !empty($_GET['return_url']) ? htmlspecialchars($_GET['return_url']) : 'kaj.php' ?>" class="btn-secondary text-sm px-5 py-2.5">Cancel</a>
                    <?php if (!$blockForm): ?>
                        <button type="submit" name="<?= $action === 'edit' ? 'edit_kaj' : 'add_kaj' ?>" class="btn-gold text-sm px-5 py-2.5">Save Job</button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <script>
        var rowCount = 0;
        var formBlocked = <?= $blockForm ? 'true' : 'false' ?>;
        
        function addItemRow(item = '', gross = '', less = '0', milting = '91.80', wastage = '0', netPart1 = '', netPart2 = '', wastage1 = '', wastage2 = '', extraPure = '') {
            var container = document.getElementById('itemRows');
            var div = document.createElement('div');
            div.id = 'row_' + rowCount;
            div.className = 'premium-card bg-slate-900/80 p-4 border border-slate-800 space-y-3';
            
            var isSplitActive = (parseFloat(netPart1) > 0 || parseFloat(netPart2) > 0);
            var isExtraPureActive = (parseFloat(extraPure) > 0);

            div.innerHTML = `
                <div class="flex items-center justify-between border-b border-white/[0.06] pb-2 mb-1">
                    <span class="text-xs font-extrabold text-[#d8a735] uppercase tracking-wider flex items-center">
                        <span class="material-symbols-rounded text-sm mr-1.5">inventory_2</span> ITEM DETAILS
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Ornament / Item Name *</label>
                        <input type="text" name="items[${rowCount}][item]" value="${item}" required ${formBlocked ? 'disabled' : ''} class="premium-input" placeholder="Ornament Name (e.g. Chain)">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Gross (g) *</label>
                        <input type="number" step="0.001" name="items[${rowCount}][gross]" id="gross_${rowCount}" value="${gross}" required ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" placeholder="0.000" oninput="calculateRow(${rowCount}, 'main')">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Less (g)</label>
                        <input type="number" step="0.001" name="items[${rowCount}][less]" id="less_${rowCount}" value="${less}" ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" oninput="calculateRow(${rowCount}, 'main')">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Mel / Purity (%) *</label>
                        <input type="number" step="0.001" name="items[${rowCount}][milting]" id="milting_${rowCount}" value="${milting}" required ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" placeholder="0.000" oninput="calculateRow(${rowCount})">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-200 mb-1.5">Chhij / Wastage (%)</label>
                        <input type="number" step="0.001" name="items[${rowCount}][wastage]" id="wastage_${rowCount}" value="${wastage}" ${formBlocked ? 'disabled' : ''} class="premium-input text-right font-mono text-sm" oninput="calculateRow(${rowCount})">
                    </div>
                </div>
                
                <!-- Net Split controls inside dynamic card -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                    <div class="bg-slate-950/80 p-2.5 rounded-xl border border-white/[0.08] flex items-center justify-between">
                        <div>
                            <span class="text-slate-300 text-[10px] uppercase font-bold tracking-wider block">Net Weight</span>
                            <div class="text-sm font-bold text-white font-mono mt-0.5" id="netLabel_${rowCount}">0.000 g</div>
                        </div>
                        <button type="button" onclick="toggleNetSplit(${rowCount})" class="w-7 h-7 rounded-lg bg-[#d8a735]/20 border border-[#d8a735]/40 text-[#d8a735] hover:bg-[#d8a735]/30 flex items-center justify-center font-bold text-xs tap-target" title="Split Net into 2 parts">
                            <span id="netSplitIcon_${rowCount}" class="material-symbols-rounded text-sm">${isSplitActive ? 'remove' : 'add'}</span>
                        </button>
                    </div>
                    
                    <div class="bg-slate-950/80 p-2.5 rounded-xl border border-white/[0.08]">
                        <span class="text-slate-300 text-[10px] uppercase font-bold tracking-wider block">Hisab %</span>
                        <div class="text-sm font-bold text-[#d8a735] font-mono mt-0.5" id="hisabLabel_${rowCount}">0.00%</div>
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
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-[#d8a735] mb-1">Part 1 Hisab / Wastage (%)</label>
                            <input type="number" step="0.001" id="wastage1_${rowCount}" name="items[${rowCount}][wastage1]" value="${wastage1}" oninput="calculateRow(${rowCount})" class="premium-input text-xs font-mono" placeholder="Default Mel+Wst">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-[#d8a735] mb-1">Part 2 Net (g)</label>
                            <input type="number" step="0.001" id="netPart2_${rowCount}" name="items[${rowCount}][net_part2]" value="${netPart2}" oninput="onPart2Input(${rowCount})" class="premium-input text-xs font-mono" placeholder="0.000">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-[#d8a735] mb-1">Part 2 Hisab / Wastage (%)</label>
                            <input type="number" step="0.001" id="wastage2_${rowCount}" name="items[${rowCount}][wastage2]" value="${wastage2}" oninput="calculateRow(${rowCount})" class="premium-input text-xs font-mono" placeholder="e.g. 3.50 or 95.30">
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 text-xs font-mono border-t border-slate-800/40 text-slate-300">
                    <div class="relative">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-slate-300">Gold Billed:</span>
                            <button type="button" onclick="toggleExtraPure(${rowCount})" class="w-6 h-6 rounded-lg bg-[#d8a735]/20 border border-[#d8a735]/40 text-[#d8a735] hover:bg-[#d8a735]/30 flex items-center justify-center font-bold text-xs tap-target" title="Add Extra Pure Gold">
                                <span id="extraPureIcon_${rowCount}" class="material-symbols-rounded text-xs">${isExtraPureActive ? 'remove' : 'add'}</span>
                            </button>
                        </div>
                        <span id="kajfine_${rowCount}" class="font-bold text-[#d8a735] text-sm">0.000</span> g
                    </div>
                    <div><span class="font-bold text-slate-300">Profit Gold:</span> <span id="profit_${rowCount}" class="font-bold text-emerald-400 text-sm">0.000</span> g</div>
                </div>

                <!-- Extra Pure Input Section -->
                <div id="extraPureSection_${rowCount}" class="${isExtraPureActive ? '' : 'hidden'} p-3.5 rounded-xl bg-slate-950/80 border border-[#d8a735]/30 mt-2">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-[#d8a735] mb-1">Extra Pure Gold (g)</label>
                    <input type="number" step="0.001" id="extraPure_${rowCount}" name="items[${rowCount}][extra_pure]" value="${extraPure}" oninput="calculateRow(${rowCount})" class="premium-input text-xs font-mono" placeholder="0.000">
                </div>
            `;
            container.appendChild(div);
            calculateRow(rowCount);
            rowCount++;
        }
        
        function removeRow(id) {
            var row = document.getElementById('row_' + id);
            row.parentNode.removeChild(row);
            updateTotalFine();
        }
        
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

        function onPart1Input(idx) {
            const gross = parseFloat(document.getElementById(`gross_${idx}`).value) || 0;
            const less = parseFloat(document.getElementById(`less_${idx}`).value) || 0;
            const totalNet = Math.max(0, gross - less);
            
            const p1Val = parseFloat(document.getElementById(`netPart1_${idx}`).value);
            if (!isNaN(p1Val) && totalNet > 0) {
                const p2Val = Math.max(0, totalNet - p1Val);
                document.getElementById(`netPart2_${idx}`).value = p2Val.toFixed(3);
            }
            calculateRow(idx);
        }

        function onPart2Input(idx) {
            const gross = parseFloat(document.getElementById(`gross_${idx}`).value) || 0;
            const less = parseFloat(document.getElementById(`less_${idx}`).value) || 0;
            const totalNet = Math.max(0, gross - less);
            
            const p2Val = parseFloat(document.getElementById(`netPart2_${idx}`).value);
            if (!isNaN(p2Val) && totalNet > 0) {
                const p1Val = Math.max(0, totalNet - p2Val);
                document.getElementById(`netPart1_${idx}`).value = p1Val.toFixed(3);
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
            }
            calculateRow(idx);
        }

        function calculateRow(id, source = '') {
            var grossEl = document.getElementById('gross_' + id);
            if (!grossEl) return;
            var gross = parseFloat(grossEl.value) || 0;
            var less = parseFloat(document.getElementById('less_' + id).value) || 0;
            var milting = parseFloat(document.getElementById('milting_' + id).value) || 0;
            var wastage = parseFloat(document.getElementById('wastage_' + id).value) || 0;
            
            var net = Math.max(0, gross - less);
            var hisab = milting + wastage;
            
            const netSplitSec = document.getElementById(`netSplitSection_${id}`);
            const isSplitActive = netSplitSec && !netSplitSec.classList.contains('hidden');
            
            var kajFine = 0;
            var profitFine = 0;
            
            if (isSplitActive) {
                const p1El = document.getElementById(`netPart1_${id}`);
                const p2El = document.getElementById(`netPart2_${id}`);
                
                if (source === 'main' && net > 0) {
                    p1El.value = net.toFixed(3);
                    p2El.value = (0).toFixed(3);
                }
                
                const part1Net = parseFloat(p1El?.value) || 0;
                const part2Net = parseFloat(p2El?.value) || 0;
                
                if (part1Net > 0 || part2Net > 0) {
                    net = part1Net + part2Net;
                }
                
                const rawWst1 = parseFloat(document.getElementById(`wastage1_${id}`)?.value);
                const rawWst2 = parseFloat(document.getElementById(`wastage2_${id}`)?.value);
                
                let hisab1 = milting + wastage;
                let wstVal1 = wastage;
                if (!isNaN(rawWst1) && rawWst1 > 0) {
                    if (rawWst1 > 50) {
                        hisab1 = rawWst1;
                        wstVal1 = Math.max(0, rawWst1 - milting);
                    } else {
                        wstVal1 = rawWst1;
                        hisab1 = milting + rawWst1;
                    }
                }
                
                let hisab2 = milting + wastage;
                let wstVal2 = wastage;
                if (!isNaN(rawWst2) && rawWst2 > 0) {
                    if (rawWst2 > 50) {
                        hisab2 = rawWst2;
                        wstVal2 = Math.max(0, rawWst2 - milting);
                    } else {
                        wstVal2 = rawWst2;
                        hisab2 = milting + rawWst2;
                    }
                }
                
                kajFine = (part1Net * hisab1 / 100) + (part2Net * hisab2 / 100);
                profitFine = (part1Net * wstVal1 / 100) + (part2Net * wstVal2 / 100);
            } else {
                kajFine = (net * hisab) / 100;
                profitFine = (wastage * net) / 100;
            }
            
            const extraPureSec = document.getElementById(`extraPureSection_${id}`);
            const isExtraPureActive = extraPureSec && !extraPureSec.classList.contains('hidden');
            if (isExtraPureActive) {
                const extraPure = parseFloat(document.getElementById(`extraPure_${id}`).value) || 0;
                kajFine += extraPure;
                
                // Autofill remark if empty
                const remarkInput = document.getElementsByName('remark')[0];
                if (remarkInput) {
                    const autoText = `Incl. Extra Pure: ${extraPure.toFixed(3)}g`;
                    if (extraPure > 0) {
                        if (!remarkInput.value || remarkInput.value.startsWith('Incl. Extra Pure:')) {
                            remarkInput.value = autoText;
                        }
                    } else if (remarkInput.value.startsWith('Incl. Extra Pure:')) {
                        remarkInput.value = '';
                    }
                }
            }
            
            document.getElementById(`netLabel_${id}`).innerText = net.toFixed(3) + ' g';
            document.getElementById(`hisabLabel_${id}`).innerText = hisab.toFixed(3) + '%';
            document.getElementById('kajfine_' + id).innerText = kajFine.toFixed(3);
            document.getElementById('profit_' + id).innerText = profitFine.toFixed(3);
            
            updateTotalFine();
        }
        
        function updateTotalFine() {
            var total = 0;
            var spans = document.querySelectorAll('[id^="kajfine_"]');
            spans.forEach(function(s) {
                total += parseFloat(s.innerText) || 0;
            });
            document.getElementById('totalFineDisp').innerText = total.toFixed(3) + ' g';
        }
        
        // Add default rows on load
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
    <!-- Standard list view fallback -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white flex items-center">
                <span class="material-symbols-rounded text-[#F4B400] mr-2 text-3xl">construction</span> Kaarigari Jobs
            </h1>
            <p class="text-slate-400 text-xs mt-1">Logs of jewelry manufacturing and metal wastage calculations.</p>
        </div>
        <?php if (!$isReadOnly): ?>
            <a href="kaj.php?action=new" class="btn-gold inline-flex items-center text-xs px-3.5 py-2 shadow-md">
                <span class="material-symbols-rounded text-sm mr-1">add</span> Add Job
            </a>
        <?php endif; ?>
    </div>

    <!-- Redesigned Jobs Mobile Cards Stack -->
    <div class="space-y-4">
        <?php if (empty($kajEntries)): ?>
            <div class="premium-card text-center py-12 flex flex-col items-center justify-center">
                <span class="material-symbols-rounded text-5xl text-slate-600 mb-3">folder_open</span>
                <h3 class="text-sm font-semibold text-slate-300">No Job Work Found</h3>
                <p class="text-xs text-slate-500 mt-1">Record a new Kaarigari Job to calculate metal weight outcomes.</p>
            </div>
        <?php else: ?>
            <?php foreach ($kajEntries as $k): ?>
                <div id="entry_<?= $k['id'] ?>" class="premium-card">
                    <div class="flex items-start justify-between border-b border-slate-800/80 pb-3 mb-3">
                        <div>
                            <span class="text-[10px] text-slate-500 font-semibold font-mono"><?= date('d/m/Y', strtotime($k['date'])) ?></span>
                            <h3 class="font-bold text-white text-base mt-0.5"><?= htmlspecialchars($k['bapari_name']) ?></h3>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-slate-500 block">Majdoori Bill</span>
                            <span class="font-mono text-rose-400 font-bold text-sm"><?= $k['cash_bill'] > 0 ? '₹' . number_format($k['cash_bill'], 2) : '--' ?></span>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <span class="text-[10px] text-slate-500 uppercase block font-semibold">Gold Billed</span>
                            <span class="font-mono font-bold text-rose-400 text-base">-<?= number_format($k['total_kaj_fine'], 3) ?> g</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-500 uppercase block font-semibold">Profit Gold</span>
                            <span class="font-mono font-bold text-pink-400 text-base">+<?= number_format($k['total_profit_fine'], 3) ?> g</span>
                        </div>
                    </div>

                    <?php if ($k['remark']): ?>
                        <div class="bg-slate-900/50 p-2.5 rounded-xl border border-slate-800/80 text-[11px] text-slate-400 mt-3 flex items-start space-x-1">
                            <span class="material-symbols-rounded text-sm text-slate-500 mt-0.5">sticky_note</span>
                            <span class="truncate"><?= htmlspecialchars($k['remark']) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isReadOnly): ?>
                        <div class="flex items-center justify-end space-x-2 mt-4 pt-3 border-t border-slate-800/40">
                            <a href="kaj.php?action=edit&id=<?= $k['id'] ?>&return_url=<?= urlencode("kaj.php#entry_{$k['id']}") ?>" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 flex items-center justify-center text-slate-300 transition-colors tap-target" title="Edit">
                                <span class="material-symbols-rounded text-base">edit</span>
                            </a>
                            <a href="kaj.php?delete=<?= $k['id'] ?>" onclick="return confirm('Are you sure you want to delete this Kaarigari Job entry? This will also delete all of its items.')" class="w-8 h-8 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target" title="Delete">
                                <span class="material-symbols-rounded text-base">delete</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require_once 'footer.php';
?>

