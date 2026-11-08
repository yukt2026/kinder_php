<?php
require_once 'db.php';

$error = '';
$success = '';
$activeTab = $_GET['tab'] ?? 'deposit';

// Fetch Baparis for dropdowns
$stmt = $pdo->prepare("SELECT id, name FROM baparis WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$baparis = $stmt->fetchAll();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isReadOnly) {
        $error = 'View-Only Mode: Administrators cannot modify user data.';
    } elseif (isset($_POST['submit_deposit'])) {
        $date = $_POST['date'] ?? date('Y-m-d');
        $bapariId = intval($_POST['bapari_id'] ?? 0);
        $fineWeight = floatval($_POST['fine_weight'] ?? 0);
        $purity = floatval($_POST['purity'] ?? 100);
        $cashReceived = floatval($_POST['cash_received'] ?? 0);
        $remark = trim($_POST['remark'] ?? '');

        // Calculate fine gold jama
        $jamaFine = round($fineWeight * ($purity / 100), 3);

        if ($bapariId <= 0 || ($fineWeight <= 0 && $cashReceived <= 0)) {
            $error = 'Please select a Bapari and enter a valid Gold weight or Cash amount!';
        } else {
            $confirmDuplicate = isset($_POST['confirm_duplicate']) && $_POST['confirm_duplicate'] == 1;
            $dup = findDuplicateDeposit($pdo, $userId, $bapariId, $date, $fineWeight, $jamaFine, $cashReceived);

            if ($dup && !$confirmDuplicate) {
                $dupAge = time() - strtotime($dup['created_at']);
                if ($dupAge < 300) {
                    $error = "Double Entry Blocked: An identical Fine Deposit (#ID: {$dup['id']}) of {$fineWeight}g was already saved just moments ago (" . date('H:i:s', strtotime($dup['created_at'])) . ")! Duplicate submission prevented.";
                } else {
                    $error = "Duplicate Entry Detected: An identical Deposit (#ID: {$dup['id']}) of {$fineWeight}g (Cash: ₹{$cashReceived}) already exists for this Bapari on {$date}.<br><label class='inline-flex items-center space-x-1.5 mt-2 cursor-pointer text-amber-400 font-bold'><input type='checkbox' name='confirm_duplicate' value='1' class='rounded'> <span>Yes, save this duplicate entry anyway</span></label>";
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO fine_deposits (user_id, date, bapari_id, fine_weight, purity, jama_fine, cash_received, remark) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $date, $bapariId, $fineWeight, $purity, $jamaFine, $cashReceived, $remark]);
                $success = 'Fine Deposit added successfully!';
            }
        }
    } elseif (isset($_POST['submit_kaj'])) {
        $date = $_POST['date'] ?? date('Y-m-d');
        $bapariId = intval($_POST['bapari_id'] ?? 0);
        $cashBill = floatval($_POST['cash_bill'] ?? 0);
        $remark = trim($_POST['remark'] ?? '');

        // Items arrays from POST
        $items = $_POST['items'] ?? [];
        
        if ($bapariId <= 0 || empty($items)) {
            $error = 'Please select a Bapari and add at least one ornament item!';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Calculate totals
                $totalKajFine = 0.0;
                $totalProfitFine = 0.0;
                
                foreach ($items as $it) {
                    $gross = floatval($it['gross'] ?? 0);
                    $less = floatval($it['less'] ?? 0);
                    $net = max(0, $gross - $less);
                    $milting = floatval($it['milting'] ?? 0);
                    $wastage = floatval($it['wastage'] ?? 0);
                    
                    $netPart1 = floatval($it['net_part1'] ?? 0);
                    $netPart2 = floatval($it['net_part2'] ?? 0);
                    $wst1Raw = floatval($it['wastage1'] ?? 0);
                    $wst2Raw = floatval($it['wastage2'] ?? 0);
                    $extraPure = floatval($it['extra_pure'] ?? 0);
                    
                    if ($netPart1 > 0 || $netPart2 > 0) {
                        $hisab1 = ($wst1Raw > 50) ? $wst1Raw : (($wst1Raw > 0) ? ($milting + $wst1Raw) : ($milting + $wastage));
                        $wstVal1 = ($wst1Raw > 50) ? max(0, $wst1Raw - $milting) : (($wst1Raw > 0) ? $wst1Raw : $wastage);
                        
                        $hisab2 = ($wst2Raw > 50) ? $wst2Raw : (($wst2Raw > 0) ? ($milting + $wst2Raw) : ($milting + $wastage));
                        $wstVal2 = ($wst2Raw > 50) ? max(0, $wst2Raw - $milting) : (($wst2Raw > 0) ? $wst2Raw : $wastage);

                        $kajFine = round(($netPart1 * ($hisab1 / 100)) + ($netPart2 * ($hisab2 / 100)) + $extraPure, 3);
                        $profitFine = round(($netPart1 * ($wstVal1 / 100)) + ($netPart2 * ($wstVal2 / 100)), 3);
                    } else {
                        $hisab = $milting + $wastage;
                        $kajFine = round(($net * ($hisab / 100)) + $extraPure, 3);
                        $profitFine = round($net * ($wastage / 100), 3);
                    }
                    
                    $totalKajFine += $kajFine;
                    $totalProfitFine += $profitFine;
                }

                // Auto append extra pure note if missing in remark
                $extraPureTotal = 0.0;
                foreach ($items as $it) {
                    $extraPureTotal += floatval($it['extra_pure'] ?? 0);
                }
                if ($extraPureTotal > 0 && empty($remark)) {
                    $remark = "Incl. Extra Pure: " . number_format($extraPureTotal, 3) . "g";
                }

                // Duplicate Check for Kaj Outward
                $confirmDuplicate = isset($_POST['confirm_duplicate']) && $_POST['confirm_duplicate'] == 1;
                $dup = findDuplicateKaj($pdo, $userId, $bapariId, $date, $totalKajFine, $cashBill);

                if ($dup && !$confirmDuplicate) {
                    $pdo->rollBack();
                    $dupAge = time() - strtotime($dup['created_at']);
                    if ($dupAge < 300) {
                        $error = "Double Entry Blocked: An identical Kaarigari Job (#ID: {$dup['id']}) was already saved just moments ago (" . date('H:i:s', strtotime($dup['created_at'])) . ")! Duplicate submission prevented.";
                    } else {
                        $error = "Duplicate Entry Detected: An identical Kaarigari Job (#ID: {$dup['id']}) of total fine " . number_format($totalKajFine, 3) . "g already exists for this Bapari on {$date}.<br><label class='inline-flex items-center space-x-1.5 mt-2 cursor-pointer text-amber-400 font-bold'><input type='checkbox' name='confirm_duplicate' value='1' class='rounded'> <span>Yes, save this duplicate entry anyway</span></label>";
                    }
                } else {
                    // Insert into main table
                $stmt = $pdo->prepare("
                    INSERT INTO kaj_entries (user_id, date, bapari_id, total_kaj_fine, total_profit_fine, cash_bill, remark) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $date, $bapariId, $totalKajFine, $totalProfitFine, $cashBill, $remark]);
                $kajEntryId = $pdo->lastInsertId();

                // Insert individual items
                $stmtItem = $pdo->prepare("
                    INSERT INTO kaj_items (kaj_entry_id, item, gross, less, net, milting, wastage, hisab, kaj_fine, profit_fine, net_part1, net_part2, wastage1, wastage2, extra_pure) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($items as $it) {
                    $item = trim($it['item'] ?? 'Ornament');
                    $gross = floatval($it['gross'] ?? 0);
                    $less = floatval($it['less'] ?? 0);
                    $net = max(0, $gross - $less);
                    $milting = floatval($it['milting'] ?? 0);
                    $wastage = floatval($it['wastage'] ?? 0);
                    
                    $netPart1 = floatval($it['net_part1'] ?? 0);
                    $netPart2 = floatval($it['net_part2'] ?? 0);
                    $wst1Raw = floatval($it['wastage1'] ?? 0);
                    $wst2Raw = floatval($it['wastage2'] ?? 0);
                    $extraPure = floatval($it['extra_pure'] ?? 0);
                    
                    if ($netPart1 > 0 || $netPart2 > 0) {
                        $hisab1 = ($wst1Raw > 50) ? $wst1Raw : (($wst1Raw > 0) ? ($milting + $wst1Raw) : ($milting + $wastage));
                        $wstVal1 = ($wst1Raw > 50) ? max(0, $wst1Raw - $milting) : (($wst1Raw > 0) ? $wst1Raw : $wastage);
                        
                        $hisab2 = ($wst2Raw > 50) ? $wst2Raw : (($wst2Raw > 0) ? ($milting + $wst2Raw) : ($milting + $wastage));
                        $wstVal2 = ($wst2Raw > 50) ? max(0, $wst2Raw - $milting) : (($wst2Raw > 0) ? $wst2Raw : $wastage);

                        $hisab = $hisab1;
                        $net = $netPart1 + $netPart2;
                        $kajFine = round(($netPart1 * ($hisab1 / 100)) + ($netPart2 * ($hisab2 / 100)) + $extraPure, 3);
                        $profitFine = round(($netPart1 * ($wstVal1 / 100)) + ($netPart2 * ($wstVal2 / 100)), 3);
                    } else {
                        $hisab = $milting + $wastage;
                        $kajFine = round(($net * ($hisab / 100)) + $extraPure, 3);
                        $profitFine = round($net * ($wastage / 100), 3);
                    }
                    
                    $stmtItem->execute([$kajEntryId, $item, $gross, $less, $net, $milting, $wastage, $hisab, $kajFine, $profitFine, $netPart1, $netPart2, $wst1Raw, $wst2Raw, $extraPure]);
                }
                
                    $pdo->commit();
                    $success = 'Kaarigari Job entry recorded successfully!';
                    $activeTab = 'kaj';
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed saving Kaj entry: ' . $e->getMessage();
            }
        }
    }
}

// Fetch last 5 Fine Deposits (Ordered by recent entry creation)
$stmtDep = $pdo->prepare("SELECT fd.*, b.name as bapari_name FROM fine_deposits fd JOIN baparis b ON fd.bapari_id = b.id WHERE fd.user_id = ? ORDER BY fd.id DESC LIMIT 5");
$stmtDep->execute([$userId]);
$recentDeposits = $stmtDep->fetchAll();

// Fetch last 5 Kaj Entries (Ordered by recent entry creation)
$stmtKaj = $pdo->prepare("SELECT k.*, b.name as bapari_name FROM kaj_entries k JOIN baparis b ON k.bapari_id = b.id WHERE k.user_id = ? ORDER BY k.id DESC LIMIT 5");
$stmtKaj->execute([$userId]);
$recentKaj = $stmtKaj->fetchAll();


require_once 'header.php';
?>

<!-- Title Heading -->
<div class="mb-6 mt-2 text-center">
    <h1 class="text-2xl font-extrabold tracking-wider uppercase text-white">
        ENTRY
    </h1>
</div>

<!-- ENTRY CATEGORIES CARDS -->
<div class="space-y-6 mb-8 max-w-xl mx-auto">
    <!-- Card 1: Bapari Entry -->
    <div class="premium-card bg-[#121212]/90 border border-white/[0.08] p-5 shadow-xl">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-bold text-slate-200 flex items-center">
                <span class="text-base mr-2">📦</span> Bapari Entry
            </h2>
            <a href="baparis.php?action=new" class="text-[10px] text-[#d8a735] hover:underline font-bold flex items-center">
                <span class="material-symbols-rounded text-xs mr-0.5">add</span> Add Bapari
            </a>
        </div>
        <div class="space-y-3">
            <a href="deposits.php?action=new" class="w-full py-3.5 px-4 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold text-center block shadow-lg transition-all tap-target">
                Fine Deposit
            </a>
            <a href="kaj.php?action=new" class="w-full py-3.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold text-center block shadow-lg transition-all tap-target">
                Kaj Entry
            </a>
        </div>
    </div>

    <!-- Card 2: Karigor Entry -->
    <div class="premium-card bg-[#121212]/90 border border-white/[0.08] p-5 shadow-xl">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-bold text-slate-200 flex items-center">
                <span class="text-base mr-2">👨‍🏭</span> Karigor Entry
            </h2>
            <a href="karigors.php?action=new" class="text-[10px] text-[#d8a735] hover:underline font-bold flex items-center">
                <span class="material-symbols-rounded text-xs mr-0.5">add</span> Add Karigor
            </a>
        </div>
        <div class="space-y-3">
            <a href="karigor_issue.php?action=new" class="w-full py-3.5 px-4 rounded-xl bg-orange-600 hover:bg-orange-500 text-white text-xs font-bold text-center block shadow-lg transition-all tap-target">
                Issue Material
            </a>
            <a href="karigor_receive.php?action=new" class="w-full py-3.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold text-center block shadow-lg transition-all tap-target">
                Kaj Receive
            </a>
        </div>
    </div>
</div>


<!-- Tab Switcher & Quick Form Below -->
<div class="flex items-center space-x-0 bg-slate-900/60 p-1 rounded-2xl border border-white/[0.04] mb-6">
    <button onclick="switchTab('deposit')" id="tabBtnDeposit" class="flex-1 py-3 text-center text-sm font-bold rounded-xl transition-all tap-target <?= $activeTab == 'deposit' ? 'bg-[#d8a735] text-slate-950 shadow-md' : 'text-slate-400' ?>">
        Quick Fine Deposit
    </button>
    <button onclick="switchTab('kaj')" id="tabBtnKaj" class="flex-1 py-3 text-center text-sm font-bold rounded-xl transition-all tap-target <?= $activeTab == 'kaj' ? 'bg-[#d8a735] text-slate-950 shadow-md' : 'text-slate-400' ?>">
        Quick Kaj Entry
    </button>
</div>

<!-- Feedback Messages -->
<?php if ($error): ?>
    <div class="mb-5 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs flex items-center space-x-2">
        <span class="material-symbols-rounded text-lg">error</span> <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="mb-5 p-4 rounded-2xl bg-[#d8a735]/10 border border-[#d8a735]/20 text-[#d8a735] text-xs flex items-center space-x-2">
        <span class="material-symbols-rounded text-lg">check_circle</span> <span><?= htmlspecialchars($success) ?></span>
    </div>
<?php endif; ?>

<?php if ($isReadOnly): ?>
    <div class="mb-5 p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs flex items-center space-x-2 no-print animate-scale-up">
        <span class="material-symbols-rounded text-lg">info</span>
        <span><strong>View-Only Mode:</strong> Administrators cannot create or modify transactions on this account.</span>
    </div>
<?php endif; ?>

<!-- 1. Fine Deposit Content -->
<div id="tabContentDeposit" class="<?= $activeTab == 'deposit' ? '' : 'hidden' ?>">

    <form method="POST" class="space-y-5">
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Date</label>
            <input type="date" name="date" required value="<?= date('Y-m-d') ?>" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm">
        </div>
        
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Select Bapari</label>
            <select name="bapari_id" required <?= $isReadOnly ? 'disabled' : '' ?> class="searchable-select premium-input text-sm">
                <option value="">Select Bapari</option>
                <?php foreach ($baparis as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Fine Weight (GM)</label>
            <input type="number" step="0.001" id="fineWeight" name="fine_weight" <?= $isReadOnly ? 'disabled' : '' ?> oninput="calcDepositFine()" class="premium-input text-sm" placeholder="0.000">
        </div>
        
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Purity %</label>
            <input type="number" step="0.01" id="purity" name="purity" value="100" <?= $isReadOnly ? 'disabled' : '' ?> oninput="calcDepositFine()" class="premium-input text-sm">
        </div>
        
        <!-- Calculated Jama Fine Display Block (Matching Image 4) -->
        <div class="premium-card bg-transparent border-[#d8a735]/30">
            <span class="text-slate-500 text-[10px] uppercase font-bold block mb-1">Jama Fine</span>
            <div class="text-2xl font-bold text-[#d8a735] font-mono leading-none" id="jamaFineLabel">0.000 g</div>
        </div>
        
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Cash Received (₹)</label>
            <input type="number" step="0.01" name="cash_received" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm" placeholder="0">
        </div>
        
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Remark</label>
            <input type="text" name="remark" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm" placeholder="Add optional remarks">
        </div>
        
        <button type="submit" name="submit_deposit" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full btn-gold tracking-wide mt-2 <?= $isReadOnly ? 'opacity-50 cursor-not-allowed' : '' ?>">
            ADD FINE ENTRY
        </button>
    </form>
</div>

<!-- 2. Kaj Entry Content -->
<div id="tabContentKaj" class="<?= $activeTab == 'kaj' ? '' : 'hidden' ?>">
    <form method="POST" class="space-y-5">
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Date</label>
            <input type="date" name="date" required value="<?= date('Y-m-d') ?>" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm">
        </div>
        
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Select Bapari</label>
            <select name="bapari_id" required <?= $isReadOnly ? 'disabled' : '' ?> class="searchable-select premium-input text-sm">
                <option value="">Select Bapari</option>
                <?php foreach ($baparis as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Dynamic Items Container -->
        <div id="kajItemsContainer" class="space-y-5">
            <!-- Item Card Block Template (Matching Image 5 Layout) -->
            <div class="premium-card item-card border-slate-800" id="itemBlock_0">
                <div class="flex items-center justify-between mb-4 border-b border-slate-800 pb-2">
                    <span class="text-xs font-bold text-[#d8a735] item-header">Item Details</span>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-[9px] font-bold uppercase text-slate-500 mb-1">Item Name</label>
                        <input type="text" name="items[0][item]" required <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm" placeholder="Enter item name">
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="block text-[9px] font-bold uppercase text-slate-500 mb-1">Gross</label>
                            <input type="number" step="0.001" name="items[0][gross]" id="gross_0" oninput="calcKajItem(0, 'main')" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm gross-input" placeholder="0.000" required>
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold uppercase text-slate-500 mb-1">Less</label>
                            <input type="number" step="0.001" name="items[0][less]" id="less_0" value="0" oninput="calcKajItem(0, 'main')" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm less-input" placeholder="0">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="block text-[9px] font-bold uppercase text-slate-500 mb-1">Milting %</label>
                            <input type="number" step="0.01" name="items[0][milting]" id="milting_0" value="91.80" oninput="calcKajItem(0)" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm milting-input">
                        </div>
                        <div>
                            <label class="block text-[9px] font-bold uppercase text-slate-500 mb-1">Wastage %</label>
                            <input type="number" step="0.01" name="items[0][wastage]" id="wastage_0" value="0" oninput="calcKajItem(0)" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm wastage-input">
                        </div>
                    </div>
                    
                    <!-- Dynamic Output Result Boxes Grid (Matching Image 5) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                        <div class="bg-slate-950/60 p-2.5 rounded-xl border border-white/[0.03] flex items-center justify-between">
                            <div>
                                <span class="text-slate-500 text-[8px] uppercase font-bold block">Net</span>
                                <div class="text-sm font-bold text-white font-mono mt-0.5" id="netLabel_0">0.000 g</div>
                            </div>
                            <button type="button" onclick="toggleNetSplit(0)" class="w-7 h-7 rounded-lg bg-[#d8a735]/15 border border-[#d8a735]/30 text-[#d8a735] hover:bg-[#d8a735]/25 flex items-center justify-center font-bold text-xs tap-target" title="Split Net into 2 parts">
                                <span id="netSplitIcon_0" class="material-symbols-rounded text-sm">add</span>
                            </button>
                        </div>
                        
                        <div class="bg-slate-950/60 p-2.5 rounded-xl border border-white/[0.03]">
                            <span class="text-slate-500 text-[8px] uppercase font-bold block">Hisab %</span>
                            <div class="text-sm font-bold text-white font-mono mt-0.5" id="hisabLabel_0">95.30%</div>
                        </div>
                    </div>

                    <!-- Split Net Container (Hidden by default, toggled via + button) -->
                    <div id="netSplitSection_0" class="hidden p-3 rounded-xl bg-slate-950/40 border border-[#d8a735]/20 space-y-3">
                        <div class="text-[9px] font-bold uppercase tracking-wider text-[#d8a735]">Split Net Calculation</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Part 1 Net (g)</label>
                                <input type="number" step="0.001" id="netPart1_0" name="items[0][net_part1]" oninput="onPart1Input(0)" class="premium-input text-xs font-mono" placeholder="0.000">
                            </div>
                            <div>
                                <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Part 1 Hisab / Wastage (%)</label>
                                <input type="number" step="0.01" id="wastage1_0" name="items[0][wastage1]" oninput="calcKajItem(0)" class="premium-input text-xs font-mono" placeholder="Default Mel+Wst">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Part 2 Net (g)</label>
                                <input type="number" step="0.001" id="netPart2_0" name="items[0][net_part2]" oninput="onPart2Input(0)" class="premium-input text-xs font-mono" placeholder="0.000">
                            </div>
                            <div>
                                <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Part 2 Hisab / Wastage (%)</label>
                                <input type="number" step="0.01" id="wastage2_0" name="items[0][wastage2]" oninput="calcKajItem(0)" class="premium-input text-xs font-mono" placeholder="e.g. 3.50 or 95.30">
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="premium-card bg-transparent border-[#d8a735]/20 p-2.5 relative">
                            <div class="flex items-center justify-between">
                                <span class="text-[#d8a735] text-[8px] uppercase font-bold block">Kaj Fine</span>
                                <button type="button" onclick="toggleExtraPure(0)" class="w-6 h-6 rounded-md bg-[#d8a735]/15 border border-[#d8a735]/30 text-[#d8a735] hover:bg-[#d8a735]/25 flex items-center justify-center font-bold text-xs tap-target" title="Add Extra Pure Gold">
                                    <span id="extraPureIcon_0" class="material-symbols-rounded text-xs">add</span>
                                </button>
                            </div>
                            <div class="text-base font-bold text-[#d8a735] font-mono mt-0.5" id="kajFineLabel_0">0.000 g</div>
                        </div>
                        
                        <div class="premium-card bg-transparent border-[#d8a735]/20 p-2.5">
                            <span class="text-[#d8a735] text-[8px] uppercase font-bold block">Profit Fine</span>
                            <div class="text-base font-bold text-[#d8a735] font-mono mt-0.5" id="profitLabel_0">0.000 g</div>
                        </div>
                    </div>

                    <!-- Extra Pure Gold Input Section (Hidden by default, toggled via + button) -->
                    <div id="extraPureSection_0" class="hidden p-3 rounded-xl bg-slate-950/40 border border-[#d8a735]/20">
                        <label class="block text-[8px] font-bold uppercase text-[#d8a735] mb-1">Extra Pure Gold (g)</label>
                        <input type="number" step="0.001" id="extraPure_0" name="items[0][extra_pure]" oninput="calcKajItem(0)" class="premium-input text-xs font-mono" placeholder="0.000">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Add more button removed to restrict to single item -->
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Cash Bill (₹)</label>
            <input type="number" step="0.01" name="cash_bill" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm" placeholder="0">
        </div>
        
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Remark</label>
            <input type="text" name="remark" id="kajRemarkInput" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input text-sm" placeholder="Add optional remarks">
        </div>
        
        <!-- Total aggregate indicator footer boxes -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <div class="premium-card bg-transparent border-[#d8a735]/30">
                <span class="text-slate-500 text-[9px] uppercase font-bold block mb-1">Σ Kaj Fine</span>
                <div class="text-lg font-bold text-[#d8a735] font-mono leading-none" id="totalKajFineLabel">0.000 g</div>
            </div>
            
            <div class="premium-card bg-transparent border-[#d8a735]/30">
                <span class="text-slate-500 text-[9px] uppercase font-bold block mb-1">Σ Profit Fine</span>
                <div class="text-lg font-bold text-[#d8a735] font-mono leading-none" id="totalProfitLabel">0.000 g</div>
            </div>
        </div>
        
        <button type="submit" name="submit_kaj" <?= $isReadOnly ? 'disabled' : '' ?> class="w-full btn-gold tracking-wide mt-2 <?= $isReadOnly ? 'opacity-50 cursor-not-allowed' : '' ?>">
            ADD KAJ ENTRY
        </button>
    </form>
</div>

<!-- Recent Entries Section -->
<div class="mt-8 mb-6">
    <h2 class="text-lg font-extrabold tracking-tight text-white mb-4">Recent Entries</h2>
    
    <!-- Recent Deposits -->
    <div id="recentDepositList" class="<?= $activeTab == 'deposit' ? '' : 'hidden' ?> space-y-3">
        <?php if (empty($recentDeposits)): ?>
            <div class="text-center p-6 text-slate-500 text-xs bg-[#121212]/80 rounded-2xl">No recent deposits found.</div>
        <?php else: ?>
            <?php foreach ($recentDeposits as $r): ?>
                <div class="premium-card bg-[#121212]/80 flex justify-between items-center p-3">
                    <div class="min-w-0 flex-1 pr-2">
                        <span class="text-xs font-bold text-white block truncate"><?= htmlspecialchars($r['bapari_name']) ?></span>
                        <span class="text-[10px] text-slate-500 block"><?= date('d M Y', strtotime($r['date'])) ?></span>
                    </div>
                    <div class="flex items-center space-x-3 shrink-0">
                        <div class="text-right font-mono">
                            <?php if ($r['jama_fine'] > 0): ?>
                                <span class="text-sm font-bold text-[#d8a735] block">+<?= number_format($r['jama_fine'], 3) ?> g</span>
                            <?php endif; ?>
                            <?php if ($r['cash_received'] > 0): ?>
                                <span class="text-xs font-bold text-emerald-400 block">+₹<?= number_format($r['cash_received'], 2) ?></span>
                            <?php endif; ?>
                            <?php if ($r['jama_fine'] <= 0 && $r['cash_received'] <= 0): ?>
                                <span class="text-xs text-slate-500 block">0.000 g</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isReadOnly): ?>
                            <div class="flex items-center space-x-1 border-l border-white/[0.06] pl-2.5 ml-1">
                                <a href="deposits.php?action=edit&id=<?= $r['id'] ?>" class="w-7 h-7 rounded-lg bg-slate-900 hover:bg-slate-800 border border-white/[0.05] flex items-center justify-center text-slate-400 hover:text-white transition-colors tap-target" title="Edit Entry">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </a>
                                <a href="ledger.php?bapari_id=<?= $r['bapari_id'] ?>&delete_deposit=<?= $r['id'] ?>" onclick="return confirm('Are you sure you want to delete this deposit entry?')" class="w-7 h-7 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target" title="Delete Entry">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="text-center mt-3">
                <a href="deposits.php" class="text-[10px] text-[#d8a735] font-bold uppercase tracking-wider hover:underline">View All Deposits &rarr;</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Kaj Entries -->
    <div id="recentKajList" class="<?= $activeTab == 'kaj' ? '' : 'hidden' ?> space-y-3">
        <?php if (empty($recentKaj)): ?>
            <div class="text-center p-6 text-slate-500 text-xs bg-[#121212]/80 rounded-2xl">No recent kaj entries found.</div>
        <?php else: ?>
            <?php foreach ($recentKaj as $r): ?>
                <div class="premium-card bg-[#121212]/80 flex justify-between items-center p-3">
                    <div class="min-w-0 flex-1 pr-2">
                        <span class="text-xs font-bold text-white block truncate"><?= htmlspecialchars($r['bapari_name']) ?></span>
                        <span class="text-[10px] text-slate-500 block"><?= date('d M Y', strtotime($r['date'])) ?></span>
                    </div>
                    <div class="flex items-center space-x-3 shrink-0">
                        <div class="text-right font-mono">
                            <?php if ($r['total_kaj_fine'] > 0): ?>
                                <span class="text-sm font-bold text-rose-400 block">-<?= number_format($r['total_kaj_fine'], 3) ?> g</span>
                            <?php endif; ?>
                            <?php if ($r['cash_bill'] > 0): ?>
                                <span class="text-xs font-bold text-rose-400 block">-₹<?= number_format($r['cash_bill'], 2) ?></span>
                            <?php endif; ?>
                            <?php if ($r['total_profit_fine'] > 0): ?>
                                <span class="text-[10px] text-[#d8a735] block">Profit: <?= number_format($r['total_profit_fine'], 3) ?> g</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isReadOnly): ?>
                            <div class="flex items-center space-x-1 border-l border-white/[0.06] pl-2.5 ml-1">
                                <a href="kaj.php?action=edit&id=<?= $r['id'] ?>" class="w-7 h-7 rounded-lg bg-slate-900 hover:bg-slate-800 border border-white/[0.05] flex items-center justify-center text-slate-400 hover:text-white transition-colors tap-target" title="Edit Entry">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </a>
                                <a href="ledger.php?bapari_id=<?= $r['bapari_id'] ?>&delete_kaj=<?= $r['id'] ?>" onclick="return confirm('Are you sure you want to delete this job entry?')" class="w-7 h-7 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target" title="Delete Entry">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="text-center mt-3">
                <a href="kaj.php" class="text-[10px] text-[#d8a735] font-bold uppercase tracking-wider hover:underline">View All Kaj Entries &rarr;</a>
            </div>
        <?php endif; ?>
    </div>
</div>


<script>
    var isReadOnlyActive = <?= $isReadOnly ? 'true' : 'false' ?>;

    // Tab switching handler
    function switchTab(tab) {
        const btnDeposit = document.getElementById('tabBtnDeposit');
        const btnKaj = document.getElementById('tabBtnKaj');
        const contentDeposit = document.getElementById('tabContentDeposit');
        const contentKaj = document.getElementById('tabContentKaj');
        
        if (tab === 'deposit') {
            btnDeposit.className = "flex-1 py-3 text-center text-sm font-bold rounded-xl transition-all tap-target bg-[#d8a735] text-slate-950 shadow-md";
            btnKaj.className = "flex-1 py-3 text-center text-sm font-bold rounded-xl transition-all tap-target text-slate-400";
            contentDeposit.classList.remove('hidden');
            contentKaj.classList.add('hidden');
            
            const rd = document.getElementById('recentDepositList');
            if(rd) rd.classList.remove('hidden');
            const rk = document.getElementById('recentKajList');
            if(rk) rk.classList.add('hidden');
            
        } else {
            btnKaj.className = "flex-1 py-3 text-center text-sm font-bold rounded-xl transition-all tap-target bg-[#d8a735] text-slate-950 shadow-md";
            btnDeposit.className = "flex-1 py-3 text-center text-sm font-bold rounded-xl transition-all tap-target text-slate-400";
            contentKaj.classList.remove('hidden');
            contentDeposit.classList.add('hidden');
            
            const rk = document.getElementById('recentKajList');
            if(rk) rk.classList.remove('hidden');
            const rd = document.getElementById('recentDepositList');
            if(rd) rd.classList.add('hidden');
        }
    }

    // Dynamic calculator for deposits
    function calcDepositFine() {
        const wt = parseFloat(document.getElementById('fineWeight').value) || 0;
        const purity = parseFloat(document.getElementById('purity').value) || 0;
        const fine = (wt * (purity / 100)).toFixed(3);
        document.getElementById('jamaFineLabel').textContent = fine + " g";
    }

    // Dynamic multi-item logic removed to enforce single-item entry

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
            
            // Auto populate Part 1 with Total Net and Part 2 with 0 if both are empty
            if (p1 && p2 && !p1.value && !p2.value) {
                p1.value = net.toFixed(3);
                p2.value = (0).toFixed(3);
            }
        } else {
            sec.classList.add('hidden');
            if (icon) icon.textContent = 'add';
        }
        calcKajItem(idx);
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
        calcKajItem(idx);
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
        calcKajItem(idx);
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
        calcKajItem(idx);
    }

    // Dynamic calculators for individual items
    function calcKajItem(idx, source = '') {
        const gross = parseFloat(document.getElementById(`gross_${idx}`).value) || 0;
        const less = parseFloat(document.getElementById(`less_${idx}`).value) || 0;
        let net = Math.max(0, gross - less);
        
        const milting = parseFloat(document.getElementById(`milting_${idx}`).value) || 0;
        const wastage = parseFloat(document.getElementById(`wastage_${idx}`).value) || 0;
        
        const hisab = milting + wastage;
        
        // Net Split Calculation Check
        const netSplitSec = document.getElementById(`netSplitSection_${idx}`);
        const isSplitActive = netSplitSec && !netSplitSec.classList.contains('hidden');
        
        let kajFine = 0;
        let profitFine = 0;
        
        if (isSplitActive) {
            const p1El = document.getElementById(`netPart1_${idx}`);
            const p2El = document.getElementById(`netPart2_${idx}`);
            
            // If main Gross/Less changes, update Part 1 and Part 2 proportionally
            if (source === 'main' && net > 0) {
                p1El.value = net.toFixed(3);
                p2El.value = (0).toFixed(3);
            }
            
            const part1Net = parseFloat(p1El?.value) || 0;
            const part2Net = parseFloat(p2El?.value) || 0;
            
            // When Split is active, Total Net is sum of Part 1 Net + Part 2 Net
            if (part1Net > 0 || part2Net > 0) {
                net = part1Net + part2Net;
            }
            
            const rawWst1 = parseFloat(document.getElementById(`wastage1_${idx}`)?.value);
            const rawWst2 = parseFloat(document.getElementById(`wastage2_${idx}`)?.value);
            
            // Determine Hisab 1 (%): if > 50 treat as total Hisab %, else add to milting
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
            
            // Determine Hisab 2 (%): if > 50 treat as total Hisab %, else add to milting
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
            
            const fine1 = part1Net * (hisab1 / 100);
            const fine2 = part2Net * (hisab2 / 100);
            
            kajFine = fine1 + fine2;
            profitFine = (part1Net * (wstVal1 / 100)) + (part2Net * (wstVal2 / 100));
        } else {
            kajFine = net * (hisab / 100);
            profitFine = net * (wastage / 100);
        }
        
        // Extra Pure Gold Check
        const extraPureSec = document.getElementById(`extraPureSection_${idx}`);
        const isExtraPureActive = extraPureSec && !extraPureSec.classList.contains('hidden');
        if (isExtraPureActive) {
            const extraPure = parseFloat(document.getElementById(`extraPure_${idx}`).value) || 0;
            kajFine += extraPure;
            
            const remarkInput = document.getElementById('kajRemarkInput');
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
        
        document.getElementById(`netLabel_${idx}`).textContent = net.toFixed(3) + " g";
        document.getElementById(`hisabLabel_${idx}`).textContent = hisab.toFixed(3) + "%";
        document.getElementById(`kajFineLabel_${idx}`).textContent = kajFine.toFixed(3) + " g";
        document.getElementById(`profitLabel_${idx}`).textContent = profitFine.toFixed(3) + " g";
        
        calcTotals();
    }

    // Dynamic totals calculation
    function calcTotals() {
        let grandKajFine = 0;
        let grandProfitFine = 0;
        
        const cards = document.querySelectorAll('#kajItemsContainer .item-card');
        cards.forEach(card => {
            const idxStr = card.id.split('_')[1];
            const kajFineLabelText = document.getElementById(`kajFineLabel_${idxStr}`)?.textContent || '0';
            const profitLabelText = document.getElementById(`profitLabel_${idxStr}`)?.textContent || '0';
            
            grandKajFine += parseFloat(kajFineLabelText) || 0;
            grandProfitFine += parseFloat(profitLabelText) || 0;
        });
        
        document.getElementById('totalKajFineLabel').textContent = grandKajFine.toFixed(3) + " g";
        document.getElementById('totalProfitLabel').textContent = grandProfitFine.toFixed(3) + " g";
    }
</script><?php
require_once 'footer.php';
?>

