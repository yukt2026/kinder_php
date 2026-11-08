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
    } elseif (isset($_POST['add_issue'])) {
        $date = $_POST['date'] ?? date('Y-m-d');
        $karigorIdInput = intval($_POST['karigor_id']);
        $fineWeight = floatval($_POST['fine_weight']);
        $purity = floatval($_POST['purity'] ?? 100.0);
        $cashPaid = floatval($_POST['cash_paid'] ?? 0.0);
        $remark = trim($_POST['remark'] ?? '');

        if ($karigorIdInput <= 0 || ($fineWeight <= 0 && $cashPaid <= 0)) {
            $error = 'Invalid Karigor or Material Weight / Cash!';
        } else {
            if (isKarigorSettled($pdo, $karigorIdInput, $userId, $date)) {
                if (!$isAdmin) {
                    $error = 'Access Denied: You cannot add transactions to a settled period.';
                } else {
                    $warning = 'Bypassed settlement check as administrator.';
                }
            }
            
            if (empty($error)) {
                $issueFine = round(($fineWeight * $purity) / 100.0, 3);
                $confirmDuplicate = isset($_POST['confirm_duplicate']) && $_POST['confirm_duplicate'] == 1;
                $dup = findDuplicateKarigorIssue($pdo, $userId, $karigorIdInput, $date, $fineWeight, $issueFine, $cashPaid);

                if ($dup && !$confirmDuplicate) {
                    $dupAge = time() - strtotime($dup['created_at']);
                    if ($dupAge < 300) {
                        $error = "Double Entry Blocked: An identical Material Issue (#ID: {$dup['id']}) of {$fineWeight}g was already saved just moments ago (" . date('H:i:s', strtotime($dup['created_at'])) . ")! Duplicate submission prevented.";
                    } else {
                        $error = "Duplicate Entry Detected: An identical Material Issue (#ID: {$dup['id']}) already exists for this Karigor on {$date}.<br><label class='inline-flex items-center space-x-1.5 mt-2 cursor-pointer text-amber-400 font-bold'><input type='checkbox' name='confirm_duplicate' value='1' class='rounded'> <span>Yes, save this duplicate entry anyway</span></label>";
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO karigor_material_issues (user_id, date, karigor_id, fine_weight, purity, issue_fine, cash_paid, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $date, $karigorIdInput, $fineWeight, $purity, $issueFine, $cashPaid, $remark]);
                    
                    $success = 'Material Issue added successfully!';
                    $action = 'list';
                }
            }
        }
    } elseif (isset($_POST['edit_issue'])) {
        $id = intval($_POST['id']);
        $date = $_POST['date'] ?? date('Y-m-d');
        $karigorIdInput = intval($_POST['karigor_id']);
        $fineWeight = floatval($_POST['fine_weight']);
        $purity = floatval($_POST['purity'] ?? 100.0);
        $cashPaid = floatval($_POST['cash_paid'] ?? 0.0);
        $remark = trim($_POST['remark'] ?? '');

        $stmt = $pdo->prepare("SELECT karigor_id, date FROM karigor_material_issues WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $origTxn = $stmt->fetch();

        if (!$origTxn) {
            $error = 'Transaction not found!';
        } elseif ($karigorIdInput <= 0 || ($fineWeight < 0 && $cashPaid < 0)) {
            $error = 'Invalid Karigor or Material Weight!';
        } else {
            if (isKarigorSettled($pdo, $origTxn['karigor_id'], $userId, $origTxn['date']) || isKarigorSettled($pdo, $karigorIdInput, $userId, $date)) {
                if (!$isAdmin) {
                    $error = 'Access Denied: You are not authorized to edit transactions within settled periods.';
                }
            }
            
            if (empty($error)) {
                $issueFine = round(($fineWeight * $purity) / 100.0, 3);
                $stmt = $pdo->prepare("UPDATE karigor_material_issues SET date = ?, karigor_id = ?, fine_weight = ?, purity = ?, issue_fine = ?, cash_paid = ?, remark = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$date, $karigorIdInput, $fineWeight, $purity, $issueFine, $cashPaid, $remark, $id, $userId]);
                
                if (!empty($_POST['return_url'])) {
                    header("Location: " . $_POST['return_url']);
                    exit();
                }
                
                $success = 'Material Issue updated successfully!';
                $action = 'list';
            }
        }
    }
}

// Handle Delete Material Issue
if (isset($_GET['delete'])) {
    if ($isReadOnly) {
        die("Access Denied: View-Only Mode is active.");
    }
    
    $id = intval($_GET['delete']);
    
    $stmt = $pdo->prepare("SELECT karigor_id, date FROM karigor_material_issues WHERE id = ? AND user_id = ?");
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
            $stmt = $pdo->prepare("DELETE FROM karigor_material_issues WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $pdo->commit();
            $success = 'Material Issue entry deleted successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Failed to delete entry: " . $e->getMessage());
        }
    }
    header("Location: karigor_issue.php");
    exit();
}

// Fetch all material issues (Ordered by recent entry creation)
$stmt = $pdo->prepare("
    SELECT mi.*, k.name as karigor_name 
    FROM karigor_material_issues mi 
    JOIN karigors k ON mi.karigor_id = k.id 
    WHERE mi.user_id = ? 
    ORDER BY mi.id DESC
");
$stmt->execute([$userId]);
$issues = $stmt->fetchAll();


// Fetch karigors for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM karigors WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$karigorsList = $stmt->fetchAll();

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

<?php if ($action === 'new'): ?>
    <!-- Add Material Issue Form -->
    <div class="max-w-xl mx-auto premium-card">
        <h2 class="title-section text-white mb-6 flex items-center">
            <span class="material-symbols-rounded text-orange-400 mr-2">unarchive</span> Issue Material to Karigor
        </h2>
        
        <form method="POST" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#d8a735] mb-2">Date *</label>
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#d8a735] mb-2">Select Karigor *</label>
                      <select name="karigor_id" required <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input searchable-select" placeholder="-- Choose Karigor --">
                        <option value="">-- Choose Karigor --</option>
                        <?php foreach ($karigorsList as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= (isset($_GET['karigor_id']) && $_GET['karigor_id'] == $k['id']) ? 'selected' : '' ?>><?= htmlspecialchars($k['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Gold Weight (g) *</label>
                    <input type="number" step="0.001" name="fine_weight" id="fine_weight" required <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input" placeholder="0.000" oninput="calcIssueFine()">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Purity / Mel (%)</label>
                    <input type="number" step="0.01" name="purity" id="purity" value="100" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input" placeholder="100.00" oninput="calcIssueFine()">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-orange-400 mb-2">Calculated Issue Fine (g)</label>
                    <input type="text" id="calculated_issue" disabled class="premium-input bg-slate-900 border-none font-mono text-orange-400 font-bold" placeholder="0.000">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Cash Paid to Karigor (₹)</label>
                    <input type="number" step="0.01" name="cash_paid" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input" placeholder="0.00">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-200 mb-2">Remark / Narration</label>
                <input type="text" name="remark" <?= $isReadOnly ? 'disabled' : '' ?> class="premium-input" placeholder="e.g. Raw gold issued for 22k necklace job">
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <a href="karigor_issue.php" class="btn-secondary text-sm px-5 py-2.5">Cancel</a>
                <button type="submit" name="add_issue" <?= $isReadOnly ? 'disabled' : '' ?> class="btn-gold text-sm px-5 py-2.5 <?= $isReadOnly ? 'opacity-50 cursor-not-allowed' : '' ?>">Save Material Issue</button>
            </div>
        </form>
    </div>

    <script>
        function calcIssueFine() {
            var w = parseFloat(document.getElementById('fine_weight').value) || 0;
            var p = parseFloat(document.getElementById('purity').value) || 0;
            var fine = (w * p) / 100;
            document.getElementById('calculated_issue').value = fine.toFixed(3) + ' g';
        }
    </script>

<?php elseif ($action === 'edit'): 
    $editId = intval($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM karigor_material_issues WHERE id = ? AND user_id = ?");
    $stmt->execute([$editId, $userId]);
    $issue = $stmt->fetch();
    
    if (!$issue) {
        echo "<p class='text-center py-10 text-slate-400'>Material Issue entry not found.</p>";
        require_once 'footer.php';
        exit();
    }
    
    $isTxnSettled = isKarigorSettled($pdo, $issue['karigor_id'], $userId, $issue['date']);
    $blockForm = ($isTxnSettled && !$isAdmin) || $isReadOnly;
?>
    <!-- Edit Material Issue Form -->
    <div class="max-w-xl mx-auto premium-card">
        <h2 class="title-section text-white mb-6 flex items-center">
            <span class="material-symbols-rounded text-orange-400 mr-2">edit_note</span> Edit Material Issue
        </h2>
        
        <form method="POST" class="space-y-5">
            <input type="hidden" name="id" value="<?= $issue['id'] ?>">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_GET['return_url'] ?? '') ?>">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Date *</label>
                    <input type="date" name="date" value="<?= $issue['date'] ?>" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Select Karigor *</label>
                      <select name="karigor_id" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input searchable-select" placeholder="-- Choose Karigor --">
                        <?php foreach ($karigorsList as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= $issue['karigor_id'] == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Gold Weight (g) *</label>
                    <input type="number" step="0.001" name="fine_weight" id="fine_weight" value="<?= $issue['fine_weight'] ?>" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input" oninput="calcIssueFine()">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Purity / Mel (%)</label>
                    <input type="number" step="0.01" name="purity" id="purity" value="<?= $issue['purity'] ?>" required <?= $blockForm ? 'disabled' : '' ?> class="premium-input" oninput="calcIssueFine()">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Calculated Issue Fine (g)</label>
                    <input type="text" id="calculated_issue" disabled value="<?= $issue['issue_fine'] ?> g" class="premium-input bg-slate-900 border-none font-mono text-orange-400">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Cash Paid to Karigor (₹)</label>
                    <input type="number" step="0.01" name="cash_paid" value="<?= $issue['cash_paid'] ?>" <?= $blockForm ? 'disabled' : '' ?> class="premium-input">
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Remark / Narration</label>
                <input type="text" name="remark" value="<?= htmlspecialchars($issue['remark']) ?>" <?= $blockForm ? 'disabled' : '' ?> class="premium-input">
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <a href="<?= !empty($_GET['return_url']) ? htmlspecialchars($_GET['return_url']) : 'karigor_issue.php' ?>" class="btn-secondary text-sm px-5 py-2.5">Cancel</a>
                <?php if (!$blockForm): ?>
                    <button type="submit" name="edit_issue" class="btn-gold text-sm px-5 py-2.5">Update Material Issue</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script>
        function calcIssueFine() {
            var w = parseFloat(document.getElementById('fine_weight').value) || 0;
            var p = parseFloat(document.getElementById('purity').value) || 0;
            var fine = (w * p) / 100;
            document.getElementById('calculated_issue').value = fine.toFixed(3) + ' g';
        }
    </script>

<?php else: ?>
    <!-- Material Issues List View -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white flex items-center">
                <span class="material-symbols-rounded text-orange-400 mr-2 text-3xl">unarchive</span> Material Issue (Karigor)
            </h1>
        </div>
        <?php if (!$isReadOnly): ?>
            <a href="karigor_issue.php?action=new" class="btn-gold inline-flex items-center text-xs px-3.5 py-2 shadow-md">
                <span class="material-symbols-rounded text-sm mr-1">add</span> Issue Material
            </a>
        <?php endif; ?>
    </div>

    <div class="space-y-4">
        <?php if (empty($issues)): ?>
            <div class="premium-card text-center py-10 text-slate-500">No material issues recorded.</div>
        <?php else: ?>
            <?php foreach ($issues as $i): ?>
                <div id="entry_<?= $i['id'] ?>" class="premium-card bg-[#111111]/85 border-orange-500/10">
                    <div class="flex justify-between items-start border-b border-white/[0.04] pb-2.5 mb-2.5">
                        <div>
                            <span class="text-[9px] text-slate-500 font-mono"><?= date('d/m/Y', strtotime($i['date'])) ?></span>
                            <h3 class="font-bold text-white text-sm mt-0.5"><?= htmlspecialchars($i['karigor_name']) ?></h3>
                        </div>
                        <div class="text-right">
                            <span class="text-[8px] text-slate-500 block uppercase">Cash Paid</span>
                            <span class="font-mono text-rose-400 font-bold text-xs"><?= $i['cash_paid'] > 0 ? '₹' . number_format($i['cash_paid'], 0) : '--' ?></span>
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-[9px] text-slate-500 uppercase block">Material Fine Issued</span>
                            <span class="font-mono text-orange-400 text-sm font-bold">-<?= number_format($i['issue_fine'], 3) ?> g</span>
                            <span class="text-[9.5px] text-slate-500 font-mono block mt-1">Weight: <?= $i['fine_weight'] ?>g | Purity: <?= $i['purity'] ?>%</span>
                        </div>
                        <?php if (!$isReadOnly): ?>
                            <div class="flex items-center space-x-2">
                                <a href="karigor_issue.php?action=edit&id=<?= $i['id'] ?>&return_url=<?= urlencode("karigor_issue.php#entry_{$i['id']}") ?>" class="w-8 h-8 rounded-lg bg-slate-900 border border-white/[0.05] flex items-center justify-center text-slate-400 hover:text-white transition-colors tap-target">
                                    <span class="material-symbols-rounded text-sm">edit</span>
                                </a>
                                <a href="karigor_issue.php?delete=<?= $i['id'] ?>" onclick="return confirm('Are you sure you want to delete this issue entry?')" class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target">
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

