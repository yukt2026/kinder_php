<?php
require_once 'db.php';

// Handle JSON Backup Trigger
if (isset($_GET['backup']) && $_GET['backup'] === 'json') {
    $backupData = [];
    
    // Fetch Baparis
    $stmt = $pdo->prepare("SELECT * FROM baparis WHERE user_id = ?");
    $stmt->execute([$userId]);
    $backupData['baparis'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Deposits
    $stmt = $pdo->prepare("SELECT * FROM fine_deposits WHERE user_id = ?");
    $stmt->execute([$userId]);
    $backupData['deposits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Kaj Entries
    $stmt = $pdo->prepare("SELECT * FROM kaj_entries WHERE user_id = ?");
    $stmt->execute([$userId]);
    $backupData['kaj_entries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Karigors & Karigor Transactions
    $stmt = $pdo->prepare("SELECT * FROM karigors WHERE user_id = ?");
    $stmt->execute([$userId]);
    $backupData['karigors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM karigor_material_issues WHERE user_id = ?");
    $stmt->execute([$userId]);
    $backupData['karigor_material_issues'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM karigor_kaj_receives WHERE user_id = ?");
    $stmt->execute([$userId]);
    $backupData['karigor_kaj_receives'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM karigor_ledger_settlements WHERE user_id = ?");
    $stmt->execute([$userId]);
    $backupData['karigor_ledger_settlements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="Dasgold_Backup_' . date('Ymd_His') . '.json"');
    echo json_encode($backupData, JSON_PRETTY_PRINT);
    exit();
}


// Handle Save Company Profile Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $companyName = trim($_POST['company_name'] ?? '');
    $companyMobile = trim($_POST['company_mobile'] ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $companyGst = trim($_POST['company_gst'] ?? '');
    
    $logoPath = $currentUser['company_logo'] ?? NULL;
    
    // Check if client-side compressed base64 was sent
    if (!empty($_POST['company_logo_base64']) && strpos($_POST['company_logo_base64'], 'data:image/') === 0) {
        $logoPath = $_POST['company_logo_base64'];
    } elseif (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['company_logo']['tmp_name'];
        $fileName = $_FILES['company_logo']['name'];
        $fileType = $_FILES['company_logo']['type'];
        
        // Verify file is an image
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
        if (in_array($fileType, $allowedTypes)) {
            $base64Image = base64_encode(file_get_contents($fileTmpPath));
            $logoPath = 'data:' . $fileType . ';base64,' . $base64Image;
        }
    }
    
    try {
        // Update details in DB
        $stmt = $pdo->prepare("UPDATE users SET company_name = ?, company_mobile = ?, company_address = ?, company_gst = ?, company_logo = ? WHERE id = ?");
        $stmt->execute([$companyName, $companyMobile, $companyAddress, $companyGst, $logoPath, $userId]);
        
        // Refresh $currentUser local variable
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch();
        
        $success = 'Company details updated successfully!';
    } catch (Exception $e) {
        $error = 'Failed to save company details: ' . $e->getMessage();
    }
}

// Handle Save Profile Name
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $newName = trim($_POST['profile_name'] ?? '');
    if (!empty($newName)) {
        $stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
        $stmt->execute([$newName, $userId]);
        $_SESSION['user_name'] = $newName;
        $success = 'Profile name updated successfully!';
    } else {
        $error = 'Profile name cannot be empty!';
    }
}

// Handle Save Metal Rates Config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rates'])) {
    $apiKeyInput = trim($_POST['gold_api_key'] ?? '');
    $r24k = floatval($_POST['rate_24k'] ?? 12565.0);
    $r22k = floatval($_POST['rate_22k'] ?? 11510.0);
    $rAg = floatval($_POST['rate_ag'] ?? 179.0);
    
    // Save rates directly from input fields (manual override / initial values)
    $ratesData = [
        'gold_api_key' => $apiKeyInput,
        'rate_24k' => $r24k,
        'rate_22k' => $r22k,
        'rate_ag' => $rAg,
        'last_updated' => time() // Cache for 5 minutes so manual edits are preserved
    ];
    saveRates($pdo, $userId, $ratesData);
    
    $rate24k = $r24k;
    $rate22k = $r22k;
    $rateAg = $rAg;
    
    $success = 'Precious metal rates saved successfully!';
}

// Get report filter parameters
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';
$filterMonth = $_GET['month'] ?? '';
$filterYear = $_GET['year'] ?? '';
$filterItem = trim($_GET['item'] ?? '');
$searchQuery = trim($_GET['search'] ?? '');

$accountTarget = $_GET['target'] ?? 'b_0';
if (isset($_GET['bapari_id']) && intval($_GET['bapari_id']) > 0) {
    $accountTarget = 'b_' . intval($_GET['bapari_id']);
} elseif (isset($_GET['karigor_id']) && intval($_GET['karigor_id']) > 0) {
    $accountTarget = 'k_' . intval($_GET['karigor_id']);
}

// Fetch Baparis for filter dropdown
$stmt = $pdo->prepare("SELECT id, name FROM baparis WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$filterBaparis = $stmt->fetchAll();

// Fetch Karigors for filter dropdown
$stmt = $pdo->prepare("SELECT id, name FROM karigors WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$filterKarigors = $stmt->fetchAll();

$isKarigorTarget = (strpos($accountTarget, 'k_') === 0);
$targetId = intval(substr($accountTarget, 2));

// 

if (!$isKarigorTarget) {
    // Bapari Filtering
    $filterBapariId = $targetId;

    $depQuery = "SELECT * FROM fine_deposits WHERE user_id = ?";
    $depParams = [$userId];
    if ($fromDate) { $depQuery .= " AND date >= ?"; $depParams[] = $fromDate; }
    if ($toDate) { $depQuery .= " AND date <= ?"; $depParams[] = $toDate; }
    if ($filterYear) { $depQuery .= " AND YEAR(date) = ?"; $depParams[] = $filterYear; }
    if ($filterMonth) { $depQuery .= " AND MONTH(date) = ?"; $depParams[] = $filterMonth; }
    if ($filterBapariId > 0) { $depQuery .= " AND bapari_id = ?"; $depParams[] = $filterBapariId; }
    if ($searchQuery) {
        $depQuery .= " AND (remark LIKE ? OR EXISTS (SELECT 1 FROM baparis b WHERE b.id = fine_deposits.bapari_id AND (b.name LIKE ? OR b.mobile LIKE ?)))";
        $depParams[] = '%' . $searchQuery . '%';
        $depParams[] = '%' . $searchQuery . '%';
        $depParams[] = '%' . $searchQuery . '%';
    }

    $stmt = $pdo->prepare($depQuery);
    $stmt->execute($depParams);
    $deposits = $stmt->fetchAll();

    $kajQuery = "SELECT k.* FROM kaj_entries k WHERE k.user_id = ?";
    $kajParams = [$userId];
    if ($fromDate) { $kajQuery .= " AND k.date >= ?"; $kajParams[] = $fromDate; }
    if ($toDate) { $kajQuery .= " AND k.date <= ?"; $kajParams[] = $toDate; }
    if ($filterYear) { $kajQuery .= " AND YEAR(k.date) = ?"; $kajParams[] = $filterYear; }
    if ($filterMonth) { $kajQuery .= " AND MONTH(k.date) = ?"; $kajParams[] = $filterMonth; }
    if ($filterBapariId > 0) { $kajQuery .= " AND k.bapari_id = ?"; $kajParams[] = $filterBapariId; }
    if ($filterItem) {
        $kajQuery .= " AND EXISTS (SELECT 1 FROM kaj_items ki WHERE ki.kaj_entry_id = k.id AND ki.item LIKE ?)";
        $kajParams[] = '%' . $filterItem . '%';
    }
    if ($searchQuery) {
        $kajQuery .= " AND (k.remark LIKE ? OR EXISTS (SELECT 1 FROM baparis b WHERE b.id = k.bapari_id AND (b.name LIKE ? OR b.mobile LIKE ?)) OR EXISTS (SELECT 1 FROM kaj_items ki WHERE ki.kaj_entry_id = k.id AND ki.item LIKE ?))";
        $kajParams[] = '%' . $searchQuery . '%';
        $kajParams[] = '%' . $searchQuery . '%';
        $kajParams[] = '%' . $searchQuery . '%';
        $kajParams[] = '%' . $searchQuery . '%';
    }

    $stmt = $pdo->prepare($kajQuery);
    $stmt->execute($kajParams);
    $kajEntries = $stmt->fetchAll();

    $totalJama = 0.0;
    $totalRec = 0.0;
    foreach ($deposits as $d) {
        $totalJama += floatval($d['jama_fine']);
        $totalRec += floatval($d['cash_received']);
    }

    $totalKajFine = 0.0;
    $totalProfitFine = 0.0;
    $totalBill = 0.0;
    foreach ($kajEntries as $k) {
        $totalKajFine += floatval($k['total_kaj_fine']);
        $totalProfitFine += floatval($k['total_profit_fine']);
        $totalBill += floatval($k['cash_bill']);
    }

    $totalNetKaj = 0.0;
    if (!empty($kajEntries)) {
        $kajEntryIds = array_column($kajEntries, 'id');
        $inClause = implode(',', array_fill(0, count($kajEntryIds), '?'));
        $stmtItems = $pdo->prepare("SELECT SUM(net) as total_net FROM kaj_items WHERE kaj_entry_id IN ($inClause)");
        $stmtItems->execute($kajEntryIds);
        $totalNetKaj = floatval($stmtItems->fetch()['total_net'] ?? 0.0);
    }

    if ($filterBapariId == 0) {
        $kpQuery = "SELECT SUM(total_profit_less) as tot FROM karigor_kaj_receives WHERE user_id = ?";
        $kpParams = [$userId];
        if ($fromDate) { $kpQuery .= " AND date >= ?"; $kpParams[] = $fromDate; }
        if ($toDate) { $kpQuery .= " AND date <= ?"; $kpParams[] = $toDate; }
        if ($filterYear) { $kpQuery .= " AND YEAR(date) = ?"; $kpParams[] = $filterYear; }
        if ($filterMonth) { $kpQuery .= " AND MONTH(date) = ?"; $kpParams[] = $filterMonth; }
        $stmtKp = $pdo->prepare($kpQuery);
        $stmtKp->execute($kpParams);
        $totalKarigorProfitLess = floatval($stmtKp->fetch()['tot'] ?? 0.0);
    } else {
        $totalKarigorProfitLess = 0.0;
    }

    // Calculate accurate running balances for Baparis
    $baparisToCalc = ($filterBapariId > 0) ? [$filterBapariId] : array_column($filterBaparis, 'id');
    $netFineBalance = 0.0;
    $netCashBalance = 0.0;
    
    $cutoffDate = '';
    if ($toDate) {
        $cutoffDate = $toDate;
    } elseif ($filterYear && $filterMonth) {
        $cutoffDate = date('Y-m-t', strtotime("$filterYear-" . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . "-01"));
    } elseif ($filterYear) {
        $cutoffDate = "$filterYear-12-31";
    }

    foreach ($baparisToCalc as $bid) {
        $settleQuery = "SELECT * FROM ledger_settlements WHERE bapari_id = ? AND user_id = ?";
        $settleParams = [$bid, $userId];
        if ($cutoffDate) {
            $settleQuery .= " AND settlement_date <= ?";
            $settleParams[] = $cutoffDate;
        }
        $settleQuery .= " ORDER BY settlement_date DESC, created_at DESC LIMIT 1";
        
        $stmt = $pdo->prepare($settleQuery);
        $stmt->execute($settleParams);
        $settlement = $stmt->fetch();
        
        $bGold = 0.0;
        $bCash = 0.0;
        
        if ($settlement) {
            $bGold = floatval($settlement['closing_gold']);
            $bCash = floatval($settlement['closing_cash']);
            
            $depQ = "SELECT SUM(jama_fine) as g, SUM(cash_received) as c FROM fine_deposits WHERE bapari_id = ? AND user_id = ? AND (date > ? OR (date = ? AND created_at > ?))";
            $depP = [$bid, $userId, $settlement['settlement_date'], $settlement['settlement_date'], $settlement['created_at']];
            if ($cutoffDate) {
                $depQ .= " AND date <= ?";
                $depP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($depQ);
            $stmt->execute($depP);
            $dep = $stmt->fetch();
            
            $kajQ = "SELECT SUM(total_kaj_fine) as g, SUM(cash_bill) as c FROM kaj_entries WHERE bapari_id = ? AND user_id = ? AND (date > ? OR (date = ? AND created_at > ?))";
            $kajP = [$bid, $userId, $settlement['settlement_date'], $settlement['settlement_date'], $settlement['created_at']];
            if ($cutoffDate) {
                $kajQ .= " AND date <= ?";
                $kajP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($kajQ);
            $stmt->execute($kajP);
            $kaj = $stmt->fetch();
        } else {
            $depQ = "SELECT SUM(jama_fine) as g, SUM(cash_received) as c FROM fine_deposits WHERE bapari_id = ? AND user_id = ?";
            $depP = [$bid, $userId];
            if ($cutoffDate) {
                $depQ .= " AND date <= ?";
                $depP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($depQ);
            $stmt->execute($depP);
            $dep = $stmt->fetch();
            
            $kajQ = "SELECT SUM(total_kaj_fine) as g, SUM(cash_bill) as c FROM kaj_entries WHERE bapari_id = ? AND user_id = ?";
            $kajP = [$bid, $userId];
            if ($cutoffDate) {
                $kajQ .= " AND date <= ?";
                $kajP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($kajQ);
            $stmt->execute($kajP);
            $kaj = $stmt->fetch();
        }
        
        $bGold += floatval($dep['g'] ?? 0) - floatval($kaj['g'] ?? 0);
        $bCash += floatval($dep['c'] ?? 0) - floatval($kaj['c'] ?? 0);
        
        $netFineBalance += $bGold;
        $netCashBalance += $bCash;
    }
    
    $netFineBalance = round($netFineBalance, 3);
    $netCashBalance = round($netCashBalance, 2);

} else {
    // Karigor Filtering
    $filterKarigorId = $targetId;

    $issueQuery = "SELECT * FROM karigor_material_issues WHERE user_id = ?";
    $issueParams = [$userId];
    if ($fromDate) { $issueQuery .= " AND date >= ?"; $issueParams[] = $fromDate; }
    if ($toDate) { $issueQuery .= " AND date <= ?"; $issueParams[] = $toDate; }
    if ($filterYear) { $issueQuery .= " AND YEAR(date) = ?"; $issueParams[] = $filterYear; }
    if ($filterMonth) { $issueQuery .= " AND MONTH(date) = ?"; $issueParams[] = $filterMonth; }
    if ($filterKarigorId > 0) { $issueQuery .= " AND karigor_id = ?"; $issueParams[] = $filterKarigorId; }
    if ($searchQuery) {
        $issueQuery .= " AND (remark LIKE ? OR EXISTS (SELECT 1 FROM karigors k WHERE k.id = karigor_material_issues.karigor_id AND (k.name LIKE ? OR k.mobile LIKE ?)))";
        $issueParams[] = '%' . $searchQuery . '%';
        $issueParams[] = '%' . $searchQuery . '%';
        $issueParams[] = '%' . $searchQuery . '%';
    }

    $stmt = $pdo->prepare($issueQuery);
    $stmt->execute($issueParams);
    $issues = $stmt->fetchAll();

    $recQuery = "SELECT r.* FROM karigor_kaj_receives r WHERE r.user_id = ?";
    $recParams = [$userId];
    if ($fromDate) { $recQuery .= " AND r.date >= ?"; $recParams[] = $fromDate; }
    if ($toDate) { $recQuery .= " AND r.date <= ?"; $recParams[] = $toDate; }
    if ($filterYear) { $recQuery .= " AND YEAR(r.date) = ?"; $recParams[] = $filterYear; }
    if ($filterMonth) { $recQuery .= " AND MONTH(r.date) = ?"; $recParams[] = $filterMonth; }
    if ($filterKarigorId > 0) { $recQuery .= " AND r.karigor_id = ?"; $recParams[] = $filterKarigorId; }
    if ($filterItem) {
        $recQuery .= " AND EXISTS (SELECT 1 FROM karigor_kaj_receive_items kri WHERE kri.karigor_receive_id = r.id AND kri.item LIKE ?)";
        $recParams[] = '%' . $filterItem . '%';
    }
    if ($searchQuery) {
        $recQuery .= " AND (r.remark LIKE ? OR EXISTS (SELECT 1 FROM karigors k WHERE k.id = r.karigor_id AND (k.name LIKE ? OR k.mobile LIKE ?)) OR EXISTS (SELECT 1 FROM karigor_kaj_receive_items kri WHERE kri.karigor_receive_id = r.id AND kri.item LIKE ?))";
        $recParams[] = '%' . $searchQuery . '%';
        $recParams[] = '%' . $searchQuery . '%';
        $recParams[] = '%' . $searchQuery . '%';
        $recParams[] = '%' . $searchQuery . '%';
    }

    $stmt = $pdo->prepare($recQuery);
    $stmt->execute($recParams);
    $recEntries = $stmt->fetchAll();

    $totalJama = 0.0;
    $totalRec = 0.0;
    $totalKarigorProfitLess = 0.0;
    foreach ($recEntries as $r) {
        $totalJama += floatval($r['total_receive_fine']);
        $totalRec += floatval($r['cash_paid']);
        $totalKarigorProfitLess += floatval($r['total_profit_less']);
    }

    $totalKajFine = 0.0;
    $totalBill = 0.0;
    foreach ($issues as $i) {
        $totalKajFine += floatval($i['issue_fine']);
        $totalBill += floatval($i['cash_paid']);
    }

    if ($filterKarigorId == 0) {
        $bpQuery = "SELECT SUM(total_profit_fine) as tot FROM kaj_entries WHERE user_id = ?";
        $bpParams = [$userId];
        if ($fromDate) { $bpQuery .= " AND date >= ?"; $bpParams[] = $fromDate; }
        if ($toDate) { $bpQuery .= " AND date <= ?"; $bpParams[] = $toDate; }
        if ($filterYear) { $bpQuery .= " AND YEAR(date) = ?"; $bpParams[] = $filterYear; }
        if ($filterMonth) { $bpQuery .= " AND MONTH(date) = ?"; $bpParams[] = $filterMonth; }
        $stmtBp = $pdo->prepare($bpQuery);
        $stmtBp->execute($bpParams);
        $totalProfitFine = floatval($stmtBp->fetch()['tot'] ?? 0.0);
    } else {
        $totalProfitFine = 0.0;
    }

    $totalNetKaj = 0.0;
    if (!empty($recEntries)) {
        $recEntryIds = array_column($recEntries, 'id');
        $inClause = implode(',', array_fill(0, count($recEntryIds), '?'));
        $stmtItems = $pdo->prepare("SELECT SUM(net) as total_net FROM karigor_kaj_receive_items WHERE karigor_receive_id IN ($inClause)");
        $stmtItems->execute($recEntryIds);
        $totalNetKaj = floatval($stmtItems->fetch()['total_net'] ?? 0.0);
    }

    // Calculate accurate running balances for Karigors
    $karigorsToCalc = ($filterKarigorId > 0) ? [$filterKarigorId] : array_column($filterKarigors, 'id');
    $netFineBalance = 0.0;
    $netCashBalance = 0.0;
    
    $cutoffDate = '';
    if ($toDate) {
        $cutoffDate = $toDate;
    } elseif ($filterYear && $filterMonth) {
        $cutoffDate = date('Y-m-t', strtotime("$filterYear-" . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . "-01"));
    } elseif ($filterYear) {
        $cutoffDate = "$filterYear-12-31";
    }

    foreach ($karigorsToCalc as $kid) {
        $settleQuery = "SELECT * FROM karigor_ledger_settlements WHERE karigor_id = ? AND user_id = ?";
        $settleParams = [$kid, $userId];
        if ($cutoffDate) {
            $settleQuery .= " AND settlement_date <= ?";
            $settleParams[] = $cutoffDate;
        }
        $settleQuery .= " ORDER BY settlement_date DESC, created_at DESC LIMIT 1";
        
        $stmt = $pdo->prepare($settleQuery);
        $stmt->execute($settleParams);
        $settlement = $stmt->fetch();
        
        $kGold = 0.0;
        $kCash = 0.0;
        
        if ($settlement) {
            $kGold = floatval($settlement['closing_gold']);
            $kCash = floatval($settlement['closing_cash']);
            
            $issQ = "SELECT SUM(issue_fine) as g, SUM(cash_paid) as c FROM karigor_material_issues WHERE karigor_id = ? AND user_id = ? AND (date > ? OR (date = ? AND created_at > ?))";
            $issP = [$kid, $userId, $settlement['settlement_date'], $settlement['settlement_date'], $settlement['created_at']];
            if ($cutoffDate) {
                $issQ .= " AND date <= ?";
                $issP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($issQ);
            $stmt->execute($issP);
            $iss = $stmt->fetch();
            
            $recQ = "SELECT SUM(total_receive_fine) as g, SUM(cash_paid) as c FROM karigor_kaj_receives WHERE karigor_id = ? AND user_id = ? AND (date > ? OR (date = ? AND created_at > ?))";
            $recP = [$kid, $userId, $settlement['settlement_date'], $settlement['settlement_date'], $settlement['created_at']];
            if ($cutoffDate) {
                $recQ .= " AND date <= ?";
                $recP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($recQ);
            $stmt->execute($recP);
            $rec = $stmt->fetch();
        } else {
            $issQ = "SELECT SUM(issue_fine) as g, SUM(cash_paid) as c FROM karigor_material_issues WHERE karigor_id = ? AND user_id = ?";
            $issP = [$kid, $userId];
            if ($cutoffDate) {
                $issQ .= " AND date <= ?";
                $issP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($issQ);
            $stmt->execute($issP);
            $iss = $stmt->fetch();
            
            $recQ = "SELECT SUM(total_receive_fine) as g, SUM(cash_paid) as c FROM karigor_kaj_receives WHERE karigor_id = ? AND user_id = ?";
            $recP = [$kid, $userId];
            if ($cutoffDate) {
                $recQ .= " AND date <= ?";
                $recP[] = $cutoffDate;
            }
            $stmt = $pdo->prepare($recQ);
            $stmt->execute($recP);
            $rec = $stmt->fetch();
        }
        
        $kGold += floatval($rec['g'] ?? 0) - floatval($iss['g'] ?? 0);
        $kCash += floatval($rec['c'] ?? 0) - floatval($iss['c'] ?? 0);
        
        $netFineBalance += $kGold;
        $netCashBalance += $kCash;
    }
    
    $netFineBalance = round($netFineBalance, 3);
    $netCashBalance = round($netCashBalance, 2);
}

require_once 'header.php';
?>


<!-- Title (Matching Image 2) -->
<div class="mb-5 mt-2">
    <h1 class="text-3xl font-extrabold tracking-tight text-white">
        Settings
    </h1>
</div>

<!-- ACCOUNT Section -->
<div class="mb-6">
    <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block mb-3">Account Settings</span>
    <div class="premium-card bg-[#121212]/80">
        <form method="POST" class="space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3 mb-2">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-full bg-[#d8a735]/15 border border-[#d8a735]/20 flex items-center justify-center font-bold text-[#d8a735]">
                        <?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold text-white leading-tight">Profile Details</h3>
                        <p class="text-[10px] text-slate-500 mt-0.5"><?= htmlspecialchars($_SESSION['user_email'] ?? 'admin@demo.com') ?></p>
                    </div>
                </div>
                <a href="logout.php" class="py-1.5 px-3.5 rounded-xl border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 text-[10px] font-bold text-rose-400 flex items-center space-x-1 transition-colors tap-target">
                    <span class="material-symbols-rounded text-sm">logout</span>
                    <span>Logout</span>
                </a>
            </div>
            
            <div>
                <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Your Name</label>
                <input type="text" name="profile_name" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" required class="premium-input text-xs" placeholder="Enter name">
            </div>
            
            <button type="submit" name="save_profile" class="w-full btn-gold text-xs font-bold py-3.5 tracking-wide mt-2">
                Save Profile Name
            </button>
        </form>
    </div>
</div>


<!-- COMPANY PROFILE Section -->
<div class="mb-6">
    <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block mb-3">Company Profile</span>
    <div class="premium-card bg-[#121212]/80">
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <div class="flex items-center space-x-4 mb-2">
                <!-- Clickable Image Container -->
                <div onclick="document.getElementById('logoFileInput').click()" class="w-14 h-14 rounded-xl border border-dashed border-slate-700 bg-slate-950 flex flex-col items-center justify-center text-slate-500 cursor-pointer hover:border-[#d8a735]/40 hover:text-slate-300 transition-colors overflow-hidden relative shrink-0">
                    <?php if (!empty($currentUser['company_logo'])): ?>
                        <img id="logoPreviewImage" src="<?= htmlspecialchars($currentUser['company_logo']) ?>" alt="Logo" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span id="logoCameraIcon" class="material-symbols-rounded text-lg">photo_camera</span>
                        <span id="logoTextLabel" class="text-[8px] font-bold mt-1 uppercase">Logo</span>
                    <?php endif; ?>
                    <!-- JS preview overlay -->
                    <img id="logoJsPreview" class="absolute inset-0 w-full h-full object-cover hidden">
                </div>
                
                <input type="hidden" name="company_logo_base64" id="companyLogoBase64">
                <input type="file" name="company_logo" id="logoFileInput" accept="image/*" class="hidden" onchange="previewLogo(event)">
                
                <div class="text-[10px] text-slate-500">Upload your gold shop logo to display on statements.</div>
            </div>
            
            <div>
                <input type="text" name="company_name" value="<?= htmlspecialchars($currentUser['company_name'] ?? '') ?>" class="premium-input text-xs" placeholder="Company Name">
            </div>
            <div>
                <input type="text" name="company_mobile" value="<?= htmlspecialchars($currentUser['company_mobile'] ?? '') ?>" class="premium-input text-xs" placeholder="Mobile">
            </div>
            <div>
                <input type="text" name="company_address" value="<?= htmlspecialchars($currentUser['company_address'] ?? '') ?>" class="premium-input text-xs" placeholder="Address">
            </div>
            <div>
                <input type="text" name="company_gst" value="<?= htmlspecialchars($currentUser['company_gst'] ?? '') ?>" class="premium-input text-xs" placeholder="GST Number">
            </div>
            
            <button type="submit" name="save_company" class="w-full btn-gold text-xs font-bold py-3.5 tracking-wide mt-2">
                Save Company
            </button>
        </form>
    </div>
</div>

<!-- PRECIOUS METAL RATES CONFIG Section -->
<div class="mb-6">
    <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block mb-3">Precious Metal Rates</span>
    <div class="premium-card bg-[#121212]/80">
        <form method="POST" class="space-y-4">
            <?php if ($isAdmin): ?>
                <div>
                    <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">Gold API Key (System Master Key)</label>
                    <input type="text" name="gold_api_key" value="<?= htmlspecialchars($ratesConfig['gold_api_key'] ?? '') ?>" class="premium-input text-xs" placeholder="Enter master gold-api.com key">
                    <span class="text-[8px] text-slate-500 block mt-1.5 leading-normal">As Admin, your API key will automatically provide live updates for all users in the system.</span>
                </div>
            <?php else: ?>
                <div class="p-3 bg-[#18181b] rounded-xl border border-white/[0.04] mb-2 text-[10px] text-slate-400 flex items-center space-x-2">
                    <span class="material-symbols-rounded text-[#d8a735] text-base">verified</span>
                    <span>Live metal rates are automatically synced using the System Admin Master Key.</span>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500">24K Gold /g</label>
                        <?php if (!empty($ratesConfig['gold_api_key'])): ?>
                            <span class="text-[7px] bg-[#d8a735]/15 text-[#d8a735] px-1 rounded uppercase font-bold tracking-wider">Auto</span>
                        <?php endif; ?>
                    </div>
                    <input type="number" step="0.01" name="rate_24k" value="<?= htmlspecialchars($rate24k) ?>" required class="premium-input text-xs font-mono">
                </div>
                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500">22K Gold /g</label>
                        <?php if (!empty($ratesConfig['gold_api_key'])): ?>
                            <span class="text-[7px] bg-[#d8a735]/15 text-[#d8a735] px-1 rounded uppercase font-bold tracking-wider">Auto</span>
                        <?php endif; ?>
                    </div>
                    <input type="number" step="0.01" name="rate_22k" value="<?= htmlspecialchars($rate22k) ?>" required class="premium-input text-xs font-mono">
                </div>
                <div>
                    <div class="flex justify-between items-center mb-1.5">
                        <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500">Silver /g</label>
                        <?php if (!empty($ratesConfig['gold_api_key'])): ?>
                            <span class="text-[7px] bg-[#d8a735]/15 text-[#d8a735] px-1 rounded uppercase font-bold tracking-wider">Auto</span>
                        <?php endif; ?>
                    </div>
                    <input type="number" step="0.01" name="rate_ag" value="<?= htmlspecialchars($rateAg) ?>" required class="premium-input text-xs font-mono">
                </div>
            </div>
            
            <button type="submit" name="save_rates" class="w-full btn-gold text-xs font-bold py-3.5 tracking-wide mt-2">
                Save Metal Rates
            </button>
        </form>
    </div>
</div>

<!-- REPORTS Filtering Panel -->
<div class="mb-6">
    <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block mb-3">Reports</span>
    
    <div class="premium-card bg-[#121212]/80 space-y-4">
        <form method="GET" id="reportFilterForm" action="settings.php#reports_area">
            <div class="mb-3">
                <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Select Bapari / Karigor</label>
                <select name="target" class="searchable-select premium-input text-xs" placeholder="Search Bapari or Karigor...">
                    <optgroup label="Bapari / Customers">
                        <option value="b_0" <?= $accountTarget === 'b_0' ? 'selected' : '' ?>>All Baparis (All Customers)</option>
                        <?php foreach ($filterBaparis as $b): ?>
                            <option value="b_<?= $b['id'] ?>" <?= $accountTarget === 'b_' . $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Karigor / Artisans">
                        <option value="k_0" <?= $accountTarget === 'k_0' ? 'selected' : '' ?>>All Karigors (All Artisans)</option>
                        <?php foreach ($filterKarigors as $k): ?>
                            <option value="k_<?= $k['id'] ?>" <?= $accountTarget === 'k_' . $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <!-- Search bar input -->
            <div class="mb-3">
                <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">Search Keywords</label>
                <div class="relative">
                    <span class="material-symbols-rounded absolute left-3 top-2.5 text-slate-500 text-sm">search</span>
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" class="premium-input text-xs pl-8" placeholder="Search customer, mobile, item, remark...">
                </div>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 mb-3">
                <div>
                    <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">From Date</label>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="premium-input text-xs">
                </div>
                <div>
                    <label class="block text-[8px] font-bold uppercase text-slate-400 mb-1">To Date</label>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="premium-input text-xs">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 mb-3">
                <input type="number" name="month" id="filterMonthInput" value="<?= $filterMonth ?>" class="premium-input text-xs" placeholder="Month (MM)">
                <input type="number" name="year" value="<?= $filterYear ?>" class="premium-input text-xs" placeholder="Year (YYYY)">
            </div>
            
            <!-- Horizontal month selector bar (Matching Image 2) -->
            <div class="flex items-center space-x-1.5 overflow-x-auto pb-2.5 mb-3 scrollbar-hide">
                <?php 
                $months = ['All' => '', 'Jan' => '1', 'Feb' => '2', 'Mar' => '3', 'Apr' => '4', 'May' => '5', 'Jun' => '6', 'Jul' => '7', 'Aug' => '8', 'Sep' => '9', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];
                foreach ($months as $label => $val):
                    $isActive = ($filterMonth === $val);
                ?>
                    <button type="button" onclick="selectMonthPill('<?= $val ?>')" class="px-3.5 py-1.5 rounded-full text-[10px] font-bold whitespace-nowrap transition-colors <?= $isActive ? 'bg-[#d8a735] text-slate-950' : 'bg-slate-900 border border-white/[0.04] text-slate-400' ?>">
                        <?= $label ?>
                    </button>
                <?php endforeach; ?>
            </div>
            
            <div class="mb-4">
                <input type="text" name="item" value="<?= htmlspecialchars($filterItem) ?>" class="premium-input text-xs" placeholder="Item filter (e.g. Ring, Chain)">
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <button type="submit" class="btn-gold text-xs py-3 font-bold flex items-center justify-center space-x-1.5">
                    <span class="material-symbols-rounded text-sm">search</span>
                    <span>Search & Filter</span>
                </button>
                <a href="settings.php" class="btn-secondary text-xs py-3 flex items-center justify-center font-bold">Clear</a>
            </div>
        </form>

        <!-- Dynamic aggregates results inside settings (Matching Image 2) -->
        <div id="reports_area" class="space-y-3 pt-3.5 border-t border-slate-800">
            <div class="bg-slate-950/60 p-3 rounded-xl border border-white/[0.03] flex justify-between items-center text-xs">
                <span class="text-slate-500 font-semibold uppercase text-[9px]"><?= $isKarigorTarget ? 'Total Kaj Joma' : 'Total Fine Deposit' ?></span>
                <span class="font-bold font-mono"><?= number_format($totalJama, 3) ?> g</span>
            </div>
            <div class="bg-slate-950/60 p-3 rounded-xl border border-white/[0.03] flex justify-between items-center text-xs">
                <span class="text-slate-500 font-semibold uppercase text-[9px]"><?= $isKarigorTarget ? 'Total Material Issue' : 'Total Kaj Fine' ?></span>
                <span class="font-bold font-mono"><?= number_format($totalKajFine, 3) ?> g</span>
            </div>
            <div class="bg-slate-950/60 p-3 rounded-xl border border-white/[0.03] flex justify-between items-center text-xs">
                <span class="text-slate-500 font-semibold uppercase text-[9px]">Total Net Kaj</span>
                <span class="font-bold font-mono"><?= number_format($totalNetKaj, 3) ?> g</span>
            </div>
            <?php if (!$isKarigorTarget): ?>
            <div class="bg-transparent p-3 rounded-xl border border-[#d8a735]/25 flex justify-between items-center text-xs">
                <span class="text-[#d8a735] font-semibold uppercase text-[9px]">Bapari Profit Fine</span>
                <span class="font-bold font-mono text-[#d8a735]"><?= number_format($totalProfitFine, 3) ?> g</span>
            </div>
            <?php else: ?>
            <div class="bg-transparent p-3 rounded-xl border border-emerald-500/25 flex justify-between items-center text-xs">
                <span class="text-emerald-400 font-semibold uppercase text-[9px]">Karigor Profit Less</span>
                <span class="font-bold font-mono text-emerald-400"><?= number_format($totalKarigorProfitLess, 3) ?> g</span>
            </div>
            <?php endif; ?>

            <div class="bg-slate-950/60 p-3 rounded-xl border border-white/[0.03] flex justify-between items-center text-xs">
                <span class="text-slate-500 font-semibold uppercase text-[9px]">Cash Received</span>
                <span class="font-bold font-mono">₹<?= number_format($totalRec, 0) ?></span>
            </div>
            <div class="bg-slate-950/60 p-3 rounded-xl border border-white/[0.03] flex justify-between items-center text-xs">
                <span class="text-slate-500 font-semibold uppercase text-[9px]">Cash Bill</span>
                <span class="font-bold font-mono">₹<?= number_format($totalBill, 0) ?></span>
            </div>
            <div class="bg-slate-950/60 p-3 rounded-xl border border-white/[0.03] flex justify-between items-center text-xs">
                <span class="text-slate-500 font-semibold uppercase text-[9px]">Fine Balance</span>
                <span class="font-bold font-mono text-emerald-400"><?= number_format($netFineBalance, 3) ?> g</span>
            </div>
            <div class="bg-slate-950/60 p-3 rounded-xl border border-white/[0.03] flex justify-between items-center text-xs">
                <span class="text-slate-500 font-semibold uppercase text-[9px]">Cash Balance</span>
                <span class="font-bold font-mono text-emerald-400">₹<?= number_format($netCashBalance, 0) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- EXPORTS Action Links -->
<div class="mb-6">
    <span class="text-slate-500 text-[10px] uppercase font-bold tracking-wider block mb-3">Exports</span>
    <div class="premium-card bg-[#121212]/80 p-0 overflow-hidden divide-y divide-white/[0.04] text-xs">
        <button onclick="window.print()" class="w-full px-4.5 py-4 flex items-center justify-between hover:bg-white/[0.02] text-left">
            <span class="flex items-center"><span class="material-symbols-rounded text-slate-500 mr-2.5">description</span> Download Report PDF</span>
            <span class="material-symbols-rounded text-slate-500">chevron_right</span>
        </button>
        
        <button onclick="alert('CSV download triggered!')" class="w-full px-4.5 py-4 flex items-center justify-between hover:bg-white/[0.02] text-left">
            <span class="flex items-center"><span class="material-symbols-rounded text-slate-500 mr-2.5">grid_view</span> Download Report CSV</span>
            <span class="material-symbols-rounded text-slate-500">chevron_right</span>
        </button>
        
        <a href="settings.php?backup=json" class="w-full px-4.5 py-4 flex items-center justify-between hover:bg-white/[0.02] text-left block">
            <span class="flex items-center"><span class="material-symbols-rounded text-[#d8a735] mr-2.5">cloud_download</span> Backup JSON</span>
            <span class="material-symbols-rounded text-slate-500">chevron_right</span>
        </a>
    </div>
</div>

<script>
    function selectMonthPill(monthVal) {
        document.getElementById('filterMonthInput').value = monthVal;
        document.getElementById('reportFilterForm').submit();
    }

    function previewLogo(event) {
        if (event.target.files && event.target.files[0]) {
            const file = event.target.files[0];
            const reader = new FileReader();
            reader.onload = function(e) {
                const imgObj = new Image();
                imgObj.onload = function() {
                    const canvas = document.createElement('canvas');
                    let width = imgObj.width;
                    let height = imgObj.height;
                    const maxDim = 400;
                    if (width > maxDim || height > maxDim) {
                        if (width > height) {
                            height = Math.round((height * maxDim) / width);
                            width = maxDim;
                        } else {
                            width = Math.round((width * maxDim) / height);
                            height = maxDim;
                        }
                    }
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(imgObj, 0, 0, width, height);
                    const compressedBase64 = canvas.toDataURL(file.type === 'image/png' ? 'image/png' : 'image/jpeg', 0.9);
                    
                    const hiddenInput = document.getElementById('companyLogoBase64');
                    if (hiddenInput) hiddenInput.value = compressedBase64;

                    const preview = document.getElementById('logoJsPreview');
                    preview.src = compressedBase64;
                    preview.classList.remove('hidden');
                    
                    // Hide fallback elements
                    const cam = document.getElementById('logoCameraIcon');
                    const lbl = document.getElementById('logoTextLabel');
                    const img = document.getElementById('logoPreviewImage');
                    if (cam) cam.classList.add('hidden');
                    if (lbl) lbl.classList.add('hidden');
                    if (img) img.classList.add('hidden');
                };
                imgObj.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (window.location.search.indexOf('target=') > -1) {
            const reportEl = document.getElementById('reportFilterForm');
            if (reportEl) {
                // Scroll slightly above the form to account for any sticky headers
                const y = reportEl.getBoundingClientRect().top + window.scrollY - 80;
                window.scrollTo({top: y, behavior: 'smooth'});
            }
        }
    });
</script>

<?php
require_once 'footer.php';
?>

