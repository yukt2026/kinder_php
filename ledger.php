<?php
require_once 'db.php';

$bapariId = intval($_GET['bapari_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$isPrintMode = isset($_GET['print']) && $_GET['print'] == 1;
$filterType = $_GET['filter_type'] ?? 'all';

if ($bapariId <= 0) {
    echo "Invalid Customer ID";
    exit();
}

// Fetch Bapari Details
$stmt = $pdo->prepare("SELECT * FROM baparis WHERE id = ? AND user_id = ?");
$stmt->execute([$bapariId, $userId]);
$bapari = $stmt->fetch();

if (!$bapari) {
    echo "Customer not found";
    exit();
}

$editSettlement = null;
if (isset($_GET['edit_settlement'])) {
    $esId = intval($_GET['edit_settlement']);
    $stmt = $pdo->prepare("SELECT * FROM ledger_settlements WHERE id = ? AND user_id = ?");
    $stmt->execute([$esId, $userId]);
    $editSettlement = $stmt->fetch();
}

// 1. Handle Ledger Settlement Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_ledger'])) {
    $settleDate = $_POST['settlement_date'] ?? date('Y-m-d');
    $closeGold = floatval($_POST['closing_gold']);
    $closeCash = floatval($_POST['closing_cash']);
    
    if (!empty($_POST['settlement_id'])) {
        // UPDATE existing settlement
        $settleId = intval($_POST['settlement_id']);
        $stmt = $pdo->prepare("UPDATE ledger_settlements SET settlement_date = ?, closing_gold = ?, closing_cash = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$settleDate, $closeGold, $closeCash, $settleId, $userId]);
    } else {
        // INSERT new settlement
        $stmt = $pdo->prepare("INSERT INTO ledger_settlements (user_id, bapari_id, settlement_date, closing_gold, closing_cash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $bapariId, $settleDate, $closeGold, $closeCash]);
    }
    
    header("Location: ledger.php?bapari_id=" . $bapariId);
    exit();
}

// Handle Delete Gold Deposit directly from Ledger
if (isset($_GET['delete_deposit'])) {
    if ($isReadOnly) die("Access Denied.");
    $id = intval($_GET['delete_deposit']);
    
    $stmt = $pdo->prepare("SELECT date FROM fine_deposits WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $origTxn = $stmt->fetch();
    if ($origTxn && isSettled($pdo, $bapariId, $userId, $origTxn['date']) && !$isAdmin) {
        die("Access Denied: This transaction is settled.");
    }
    
    $stmt = $pdo->prepare("DELETE FROM fine_deposits WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    header("Location: ledger.php?bapari_id=" . $bapariId . ($from ? "&from=".$from : "") . ($to ? "&to=".$to : ""));
    exit();
}

// Handle Delete Kaj Entry directly from Ledger
if (isset($_GET['delete_kaj'])) {
    if ($isReadOnly) die("Access Denied.");
    $id = intval($_GET['delete_kaj']);
    
    $stmt = $pdo->prepare("SELECT date FROM kaj_entries WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    $origTxn = $stmt->fetch();
    if ($origTxn && isSettled($pdo, $bapariId, $userId, $origTxn['date']) && !$isAdmin) {
        die("Access Denied: This transaction is settled.");
    }
    
    $stmt = $pdo->prepare("DELETE FROM kaj_entries WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    header("Location: ledger.php?bapari_id=" . $bapariId . ($from ? "&from=".$from : "") . ($to ? "&to=".$to : ""));
    exit();
}

// Handle Delete Settlement directly from Ledger
if (isset($_GET['delete_settlement'])) {
    if ($isReadOnly || !$isAdmin) die("Access Denied: Only Admins can delete settlements.");
    $id = intval($_GET['delete_settlement']);
    $stmt = $pdo->prepare("DELETE FROM ledger_settlements WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    header("Location: ledger.php?bapari_id=" . $bapariId . ($from ? "&from=".$from : "") . ($to ? "&to=".$to : ""));
    exit();
}

// 2. Fetch Latest Settlement Checkpoint
$stmt = $pdo->prepare("SELECT * FROM ledger_settlements WHERE bapari_id = ? AND user_id = ? ORDER BY settlement_date DESC LIMIT 1");
$stmt->execute([$bapariId, $userId]);
$latestSettle = $stmt->fetch();

// 3. Setup Default Dates (Keep all entries visible by default)
// $from remains empty unless user explicitly selects a date filter

// 4. Calculate Opening Balance before $from Date
$openingGold = 0.0;
$openingCash = 0.0;

if (!empty($from) && $filterType === 'all') {
    // Find the most recent settlement dated *before* the filtered From Date
    $settleStmt = $pdo->prepare("SELECT * FROM ledger_settlements WHERE bapari_id = ? AND user_id = ? AND settlement_date < ? ORDER BY settlement_date DESC LIMIT 1");
    $settleStmt->execute([$bapariId, $userId, $from]);
    $prevSettle = $settleStmt->fetch();

    $startCalculationFromDate = '';
    if ($prevSettle) {
        $openingGold = floatval($prevSettle['closing_gold']);
        $openingCash = floatval($prevSettle['closing_cash']);
        $startCalculationFromDate = $prevSettle['settlement_date'];
    }

    // Calculate intermediate transactions between the last settlement (or beginning) and the $from Date
    $depParamsBefore = [$userId, $bapariId, $from];
    $depQueryBefore = "SELECT SUM(jama_fine) as g, SUM(cash_received) as c FROM fine_deposits WHERE user_id = ? AND bapari_id = ? AND date < ?";
    if (!empty($startCalculationFromDate)) {
        $depQueryBefore .= " AND (date > ? OR (date = ? AND created_at > ?))";
        $depParamsBefore[] = $startCalculationFromDate;
        $depParamsBefore[] = $startCalculationFromDate;
        $depParamsBefore[] = $prevSettle['created_at'];
    }
    $stmt = $pdo->prepare($depQueryBefore);
    $stmt->execute($depParamsBefore);
    $depBefore = $stmt->fetch();

    $kajParamsBefore = [$userId, $bapariId, $from];
    $kajQueryBefore = "SELECT SUM(total_kaj_fine) as g, SUM(cash_bill) as c FROM kaj_entries WHERE user_id = ? AND bapari_id = ? AND date < ?";
    if (!empty($startCalculationFromDate)) {
        $kajQueryBefore .= " AND (date > ? OR (date = ? AND created_at > ?))";
        $kajParamsBefore[] = $startCalculationFromDate;
        $kajParamsBefore[] = $startCalculationFromDate;
        $kajParamsBefore[] = $prevSettle['created_at'];
    }
    $stmt = $pdo->prepare($kajQueryBefore);
    $stmt->execute($kajParamsBefore);
    $kajBefore = $stmt->fetch();

    $openingGold += floatval($depBefore['g'] ?? 0) - floatval($kajBefore['g'] ?? 0);
    $openingCash += floatval($depBefore['c'] ?? 0) - floatval($kajBefore['c'] ?? 0);
}

// 5. Fetch Deposits within Date Range (Only if all or metal)
$deposits = [];
if ($filterType === 'all' || $filterType === 'metal') {
    $depQuery = "SELECT id, date, 'deposit' as type, fine_weight, purity, jama_fine, cash_received, remark, created_at FROM fine_deposits WHERE user_id = ? AND bapari_id = ?";
    $depParams = [$userId, $bapariId];
    if (!empty($from)) { $depQuery .= " AND date >= ?"; $depParams[] = $from; }
    if (!empty($to)) { $depQuery .= " AND date <= ?"; $depParams[] = $to; }
    $stmt = $pdo->prepare($depQuery);
    $stmt->execute($depParams);
    $deposits = $stmt->fetchAll();
}

// 6. Fetch Kaj Entries within Date Range (Only if all or kaam)
$kajs = [];
if ($filterType === 'all' || $filterType === 'kaam') {
    $kajQuery = "SELECT id, date, 'kaj' as type, total_kaj_fine, total_profit_fine, cash_bill, remark, created_at FROM kaj_entries WHERE user_id = ? AND bapari_id = ?";
    $kajParams = [$userId, $bapariId];
    if (!empty($from)) { $kajQuery .= " AND date >= ?"; $kajParams[] = $from; }
    if (!empty($to)) { $kajQuery .= " AND date <= ?"; $kajParams[] = $to; }
    $stmt = $pdo->prepare($kajQuery);
    $stmt->execute($kajParams);
    $kajs = $stmt->fetchAll();
}

// 6b. Fetch Settlements within Date Range (Only if full ledger)
$settlements = [];
if ($filterType === 'all') {
    $settleQuery = "SELECT id, settlement_date as date, 'settlement' as type, closing_gold, closing_cash, created_at FROM ledger_settlements WHERE user_id = ? AND bapari_id = ?";
    $settleParams = [$userId, $bapariId];
    if (!empty($from)) { $settleQuery .= " AND settlement_date >= ?"; $settleParams[] = $from; }
    if (!empty($to)) { $settleQuery .= " AND settlement_date <= ?"; $settleParams[] = $to; }
    $stmt = $pdo->prepare($settleQuery);
    $stmt->execute($settleParams);
    $settlements = $stmt->fetchAll();
}

// Merge and sort ascending by date & creation time
$entries = array_merge($deposits, $kajs, $settlements);
usort($entries, function($a, $b) {
    $cmp = strcmp($a['date'], $b['date']);
    if ($cmp === 0) {
        return strcmp($a['created_at'], $b['created_at']);
    }
    return $cmp;
});

// Fetch subitems for Kaj entries
foreach ($entries as &$e) {
    if ($e['type'] === 'kaj') {
        $stmtItems = $pdo->prepare("SELECT item, gross, less, net, milting, wastage, hisab, kaj_fine, profit_fine, net_part1, net_part2, wastage1, wastage2, extra_pure FROM kaj_items WHERE kaj_entry_id = ?");
        $stmtItems->execute([$e['id']]);
        $e['items'] = $stmtItems->fetchAll();
    }
}
unset($e);

// Map Inward (fine_deposits), Outward (kaj_entries) and Settlements to sequential numbers per Bapari
$allDepStmt = $pdo->prepare("SELECT id FROM fine_deposits WHERE user_id = ? AND bapari_id = ? ORDER BY date ASC, created_at ASC, id ASC");
$allDepStmt->execute([$userId, $bapariId]);
$allDepIds = $allDepStmt->fetchAll(PDO::FETCH_COLUMN);
$inwardSerialMap = [];
foreach ($allDepIds as $idx => $depId) {
    $inwardSerialMap[$depId] = $idx + 1;
}

$allKajStmt = $pdo->prepare("SELECT id FROM kaj_entries WHERE user_id = ? AND bapari_id = ? ORDER BY date ASC, created_at ASC, id ASC");
$allKajStmt->execute([$userId, $bapariId]);
$allKajIds = $allKajStmt->fetchAll(PDO::FETCH_COLUMN);
$outwardSerialMap = [];
foreach ($allKajIds as $idx => $kId) {
    $outwardSerialMap[$kId] = $idx + 1;
}

$allSettleStmt = $pdo->prepare("SELECT id FROM ledger_settlements WHERE user_id = ? AND bapari_id = ? ORDER BY settlement_date ASC, created_at ASC, id ASC");
$allSettleStmt->execute([$userId, $bapariId]);
$allSettleIds = $allSettleStmt->fetchAll(PDO::FETCH_COLUMN);
$settleSerialMap = [];
foreach ($allSettleIds as $idx => $sId) {
    $settleSerialMap[$sId] = $idx + 1;
}

// 7. Flat map transactions into individual Ledger rows (Tally layout)
$ledgerRows = [];
$calcFineTracker = $openingGold;
$calcCashTracker = $openingCash;

// Insert Opening Balance Row if a date filter is set OR if non-zero opening balance exists
if ($filterType === 'all' && (!empty($from) || $openingGold != 0 || $openingCash != 0)) {
    $ledgerRows[] = [
        'date' => $from ?: (!empty($entries) ? $entries[0]['date'] : date('Y-m-d')),
        'no' => '',
        'name' => 'Opening Balance',
        'gross' => 0.0,
        'less' => 0.0,
        'net' => 0.0,
        'tch' => 0.0,
        'wst' => 0.0,
        'fine' => $openingGold,
        'cash' => $openingCash,
        'remark' => 'Opening Balance',
        'is_opening' => true,
        'is_settlement' => false,
        'net_part1' => 0.0,
        'net_part2' => 0.0,
        'wastage1' => 0.0,
        'wastage2' => 0.0,
        'extra_pure' => 0.0
    ];
}

foreach ($entries as $e) {
    if ($e['type'] === 'deposit') {
        $hasMetal = floatval($e['fine_weight']) > 0 || floatval($e['jama_fine']) > 0;
        $hasCash = floatval($e['cash_received']) > 0;

        if ($hasMetal) {
            $fineVal = floatval($e['jama_fine']);
            $cashVal = $hasCash ? floatval($e['cash_received']) : 0.0;
            $calcFineTracker += $fineVal;
            $calcCashTracker += $cashVal;
            $inwardNum = $inwardSerialMap[$e['id']] ?? $e['id'];

            $ledgerRows[] = [
                'date' => $e['date'],
                'no' => 'Inward No : ' . $inwardNum,
                'name' => floatval($e['purity']) === 100.0 ? 'Fine' : '995 metal',
                'gross' => floatval($e['fine_weight']),
                'less' => 0.0,
                'net' => floatval($e['fine_weight']),
                'tch' => floatval($e['purity']),
                'wst' => 0.0,
                'fine' => $fineVal,
                'cash' => $cashVal,
                'remark' => $e['remark'],
                'is_opening' => false,
                'is_settlement' => false,
                'type' => 'deposit',
                'id' => $e['id'],
                'net_part1' => 0.0,
                'net_part2' => 0.0,
                'wastage1' => 0.0,
                'wastage2' => 0.0,
                'extra_pure' => 0.0
            ];
        } elseif ($hasCash) {
            $cashVal = floatval($e['cash_received']);
            $calcCashTracker += $cashVal;
            $inwardNum = $inwardSerialMap[$e['id']] ?? $e['id'];

            $ledgerRows[] = [
                'date' => $e['date'],
                'no' => 'Inward No : ' . $inwardNum,
                'name' => 'Cash',
                'gross' => 0.0,
                'less' => 0.0,
                'net' => 0.0,
                'tch' => 0.0,
                'wst' => 0.0,
                'fine' => 0.0,
                'cash' => $cashVal,
                'remark' => $e['remark'],
                'is_opening' => false,
                'is_settlement' => false,
                'type' => 'deposit',
                'id' => $e['id'],
                'net_part1' => 0.0,
                'net_part2' => 0.0,
                'wastage1' => 0.0,
                'wastage2' => 0.0,
                'extra_pure' => 0.0
            ];
        }
    } elseif ($e['type'] === 'kaj') {
        $items = $e['items'] ?? [];
        $hasItems = !empty($items);
        $cashVal = floatval($e['cash_bill']);
        $outwardNum = $outwardSerialMap[$e['id']] ?? $e['id'];

        if ($hasItems) {
            $first = true;
            foreach ($items as $it) {
                $fineVal = -floatval($it['kaj_fine']);
                $cVal = $first ? -$cashVal : 0.0;
                $calcFineTracker += $fineVal;
                $calcCashTracker += $cVal;

                $ledgerRows[] = [
                    'date' => $e['date'],
                    'no' => 'Outward No : ' . $outwardNum,
                    'name' => $it['item'],
                    'gross' => floatval($it['gross']),
                    'less' => floatval($it['less']),
                    'net' => floatval($it['net']),
                    'tch' => floatval($it['milting']),
                    'wst' => floatval($it['wastage']),
                    'fine' => $fineVal,
                    'cash' => $cVal,
                    'remark' => $e['remark'],
                    'is_opening' => false,
                    'is_settlement' => false,
                    'type' => 'kaj',
                    'id' => $e['id'],
                    'net_part1' => floatval($it['net_part1'] ?? 0),
                    'net_part2' => floatval($it['net_part2'] ?? 0),
                    'wastage1' => floatval($it['wastage1'] ?? 0),
                    'wastage2' => floatval($it['wastage2'] ?? 0),
                    'extra_pure' => floatval($it['extra_pure'] ?? 0)
                ];
                $first = false;
            }
        } elseif ($cashVal > 0) {
            $cVal = -$cashVal;
            $calcCashTracker += $cVal;

            $ledgerRows[] = [
                'date' => $e['date'],
                'no' => 'Outward No : ' . $outwardNum,
                'name' => 'Cash',
                'gross' => 0.0,
                'less' => 0.0,
                'net' => 0.0,
                'tch' => 0.0,
                'wst' => 0.0,
                'fine' => 0.0,
                'cash' => $cVal,
                'remark' => $e['remark'],
                'is_opening' => false,
                'is_settlement' => false,
                'type' => 'kaj',
                'id' => $e['id'],
                'net_part1' => 0.0,
                'net_part2' => 0.0,
                'wastage1' => 0.0,
                'wastage2' => 0.0,
                'extra_pure' => 0.0
            ];
        }
    } elseif ($e['type'] === 'settlement') {
        $targetGold = floatval($e['closing_gold']);
        $targetCash = floatval($e['closing_cash']);

        $adjGold = round($targetGold - $calcFineTracker, 3);
        $adjCash = round($targetCash - $calcCashTracker, 2);

        $calcFineTracker = $targetGold;
        $calcCashTracker = $targetCash;
        $settleNum = $settleSerialMap[$e['id']] ?? $e['id'];

        $ledgerRows[] = [
            'date' => $e['date'],
            'no' => 'Settle No : ' . $settleNum,
            'name' => 'Ledger Settlement',
            'gross' => 0.0,
            'less' => 0.0,
            'net' => 0.0,
            'tch' => 0.0,
            'wst' => 0.0,
            'fine' => $adjGold,
            'cash' => $adjCash,
            'remark' => 'Closing Balance Settled to ' . number_format($targetGold, 3) . 'g' . ($targetCash != 0 ? ' (₹' . number_format($targetCash, 2) . ')' : ''),
            'is_opening' => false,
            'is_settlement' => true,
            'type' => 'settlement',
            'id' => $e['id'],
            'net_part1' => 0.0,
            'net_part2' => 0.0,
            'wastage1' => 0.0,
            'wastage2' => 0.0,
            'extra_pure' => 0.0
        ];
    }
}

// 8. Calculate cumulative running balance columns
$fineRunning = 0.0;
$cashRunning = 0.0;

$totGross = 0.0;
$totLess = 0.0;
$totNet = 0.0;
$totFine = 0.0;
$totCash = 0.0;

foreach ($ledgerRows as &$row) {
    $fineRunning += $row['fine'];
    $cashRunning += $row['cash'];
    $row['running_fine'] = $fineRunning;
    $row['running_cash'] = $cashRunning;

    if (!$row['is_opening']) {
        $totGross += $row['gross'];
        $totLess += $row['less'];
        $totNet += $row['net'];
        $totFine += $row['fine'];
        $totCash += $row['cash'];
    }
}
unset($row);

// Outstanding aggregates for display
$currentOutstandingGold = $fineRunning;
$currentOutstandingCash = $cashRunning;
$lastTransactionDate = !empty($entries) ? end($entries)['date'] : '--';

// 9. Fetch Settlement History list
$stmt = $pdo->prepare("SELECT * FROM ledger_settlements WHERE bapari_id = ? AND user_id = ? ORDER BY settlement_date DESC");
$stmt->execute([$bapariId, $userId]);
$settlementsHistory = $stmt->fetchAll();

if ($isPrintMode) {
    // Prepare company logo: ONLY use the logo set in Settings ($currentUser['company_logo'])
    $companyLogoSrc = '';
    if (!empty($currentUser['company_logo'])) {
        $rawLogo = trim($currentUser['company_logo']);
        if (strpos($rawLogo, 'data:') === 0) {
            $companyLogoSrc = $rawLogo;
        } else {
            // Try to resolve file on server and base64-encode for instant canvas capture
            $cleanPath = ltrim(parse_url($rawLogo, PHP_URL_PATH) ?? '', '/\\');
            $cleanPath = preg_replace('#^kinder_php/#i', '', $cleanPath);
            $potentialPaths = [
                __DIR__ . '/' . $cleanPath,
                __DIR__ . '/assets/' . $cleanPath,
                __DIR__ . '/assets/uploads/' . basename($cleanPath),
                __DIR__ . '/uploads/' . basename($cleanPath),
            ];
            foreach ($potentialPaths as $p) {
                if (file_exists($p) && is_file($p)) {
                    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                    $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : (($ext === 'webp') ? 'image/webp' : 'image/png');
                    $companyLogoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
                    break;
                }
            }
            // If not found as a local file, use the exact logo path set in Settings
            if (empty($companyLogoSrc)) {
                $companyLogoSrc = $rawLogo;
            }
        }
    }

    // Print & Photo View
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Account Detail - <?= htmlspecialchars($bapari['name']) ?></title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: 'Times New Roman', Times, serif;
                font-size: 11px;
                color: #000000;
                margin: 0;
                padding: 0;
                background-color: #f1f5f9;
            }
            .action-toolbar {
                position: sticky;
                top: 0;
                z-index: 9999;
                background: #0f172a;
                padding: 10px 14px;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                box-shadow: 0 4px 15px rgba(0,0,0,0.3);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            }
            .toolbar-inner {
                max-width: 900px;
                margin: 0 auto;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
            }
            .toolbar-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 8px 14px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
                text-decoration: none;
                border: none;
                transition: all 0.15s ease;
                user-select: none;
                -webkit-tap-highlight-color: transparent;
            }
            .toolbar-btn:active {
                transform: scale(0.96);
            }
            .btn-photo {
                background: linear-gradient(135deg, #059669 0%, #10b981 100%);
                color: #ffffff;
                box-shadow: 0 2px 10px rgba(5,150,105,0.4);
            }
            .btn-photo:hover { background: #047857; }
            .btn-dl {
                background: #2563eb;
                color: #ffffff;
            }
            .btn-dl:hover { background: #1d4ed8; }
            .btn-pdf {
                background: #d97706;
                color: #ffffff;
            }
            .btn-pdf:hover { background: #b45309; }
            .btn-back {
                background: rgba(255,255,255,0.12);
                color: #f8fafc;
            }
            .btn-back:hover { background: rgba(255,255,255,0.22); }
            .status-msg {
                max-width: 900px;
                margin: 4px auto 0 auto;
                color: #34d399;
                font-size: 11px;
                font-weight: 600;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                text-align: right;
                min-height: 16px;
            }

            .scroll-wrapper {
                width: 100%;
                overflow-x: auto;
                padding: 16px 8px;
                -webkit-overflow-scrolling: touch;
            }
            .paper-sheet {
                width: 860px;
                margin: 0 auto;
                background: #ffffff;
                padding: 22px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
                border-radius: 4px;
            }

            .title-block {
                text-align: center;
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 25px;
            }
            .header-info {
                font-size: 13px;
                font-weight: bold;
                margin-bottom: 10px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            th, td {
                border: 1px solid #777777;
                padding: 5px 6px;
                text-align: left;
                vertical-align: top;
            }
            th {
                background-color: #e2e8f0;
                font-weight: bold;
                text-align: center;
            }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .font-mono { font-family: monospace; }
            .text-green { color: #047857; font-weight: bold; }
            .text-red { color: #b91c1c; font-weight: bold; }
            .stacked-val {
                display: block;
                font-size: 9.5px;
                color: #555555;
            }

                        @media (max-width: 640px) {
                .action-toolbar {
                    padding: 8px 10px;
                }
                .toolbar-inner {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 8px;
                }
                .toolbar-inner > div {
                    width: 100%;
                    justify-content: space-between;
                }
                .toolbar-btn {
                    flex: 1;
                    padding: 8px 6px;
                    font-size: 11px;
                }
            }
            @media print {
                .no-print {
                    display: none !important;
                }
                body {
                    background: #ffffff !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }
                .scroll-wrapper {
                    padding: 0 !important;
                    overflow: visible !important;
                }
                .paper-sheet {
                    width: 100% !important;
                    padding: 0 !important;
                    margin: 0 !important;
                    box-shadow: none !important;
                    border-radius: 0 !important;
                }
                @page {
                    size: auto;
                    margin: 10mm;
                }
                #statementLogoCanvas {
                    display: inline-block !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
        <script src="assets/js/html2canvas.min.js"></script>
        <script>
            if (typeof html2canvas === 'undefined') {
                document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"><\/script>');
            }
        </script>
    </head>
    <body>
        <!-- Top Action Toolbar (Hidden during print) -->
        <div class="action-toolbar no-print">
            <div class="toolbar-inner">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <a href="ledger.php?bapari_id=<?= $bapariId ?>&from=<?= $from ?>&to=<?= $to ?>&filter_type=<?= $filterType ?>" class="toolbar-btn btn-back">
                        ← Back to Ledger
                    </a>
                    <span style="color: #94a3b8; font-size: 12px;">
                        Party: <strong style="color: #fff;"><?= htmlspecialchars($bapari['name']) ?></strong>
                    </span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    <!-- Send as Photo (WhatsApp / Share Sheet) -->
                    <button type="button" id="btnSharePhoto" onclick="generateAndSharePhoto(false)" class="toolbar-btn btn-photo">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                        Send as Photo
                    </button>
                    <!-- Download Photo -->
                    <button type="button" id="btnDlPhoto" onclick="generateAndSharePhoto(true)" class="toolbar-btn btn-dl">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download Photo
                    </button>
                    <!-- Save as PDF -->
                    <button type="button" onclick="window.print()" class="toolbar-btn btn-pdf">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Save as PDF
                    </button>
                </div>
            </div>
            <div id="photoStatus" class="status-msg"></div>
        </div>

        <div class="scroll-wrapper">
            <div id="statementContent" class="paper-sheet">
                <!-- Company Header Block for Printed Statements -->
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 12px; margin-bottom: 15px;">
                    <div style="display: flex; align-items: center;">
                        <?php if (!empty($companyLogoSrc)): ?>
                            <div style="width: 55px; height: 55px; margin-right: 14px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; position: relative;">
                                <canvas id="statementLogoCanvas" width="110" height="110" style="width: 55px; height: 55px; max-height: 55px; max-width: 55px; border-radius: 6px; display: block;"></canvas>
                                <img id="statementLogoImg" src="<?= $companyLogoSrc ?>" alt="Company Logo" style="display: none;">
                            </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-size: 16px; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars($currentUser['company_name'] ?: 'Dasgold') ?></div>
                            <div style="font-size: 10px; color: #555; margin-top: 2px;">
                                <?= htmlspecialchars($currentUser['company_address'] ?: '') ?>
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right; font-size: 10px; color: #333; line-height: 1.4;">
                        <?php if (!empty($currentUser['company_mobile'])): ?>
                            <div><strong>Mobile:</strong> <?= htmlspecialchars($currentUser['company_mobile']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($currentUser['company_gst'])): ?>
                            <div><strong>GSTIN:</strong> <?= htmlspecialchars($currentUser['company_gst']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 12px;">
                    <div>
                        <div style="font-size: 10px; color: #555; text-transform: uppercase; font-weight: bold;">Statement For:</div>
                        <div style="font-size: 14px; font-weight: bold; color: #000;"><?= htmlspecialchars($bapari['name']) ?></div>
                        <?php if ($bapari['mobile']): ?>
                            <div style="font-size: 10px; color: #555; margin-top: 1px;">Mobile: <?= htmlspecialchars($bapari['mobile']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: right;">
                        <?php
$stmtTitle = 'LEDGER STATEMENT';
if ($filterType === 'metal') $stmtTitle = 'STATEMENT - ONLY METAL (INPUT / JAMA)';
if ($filterType === 'kaam') $stmtTitle = 'STATEMENT - ONLY KAAM (OUTPUT / KAJ)';
?>
<div style="font-size: 12px; font-weight: bold;"><?= $stmtTitle ?></div>
                        <?php if ($from || $to): ?>
                            <div style="font-size: 10px; color: #555; margin-top: 1px;">
                                Period: <strong><?= $from ? date('d/m/Y', strtotime($from)) : 'Start' ?></strong> to <strong><?= $to ? date('d/m/Y', strtotime($to)) : 'Today' ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th style="width: 75px;">Rec Dt.</th>
                            <th style="width: 85px;">No</th>
                            <th>Name</th>
                            <th style="width: 65px;">Gross<br><span style="font-size:9px; font-weight:normal;">Less</span></th>
                            <th style="width: 65px;">Net</th>
                            <th style="width: 55px;">Tch<br><span style="font-size:9px; font-weight:normal;">Wst</span></th>
                            <th style="width: 80px;">Fine</th>
                            <th style="width: 60px;">Amt Pcs</th>
                            <th style="width: 75px;">Cash</th>
                            <th>Narration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledgerRows as $r): ?>
                            <tr>
                                <td class="text-center"><?= date('d/m/Y', strtotime($r['date'])) ?></td>
                                <td class="text-center"><?= htmlspecialchars($r['no']) ?></td>
                                <?php 
                                $p1 = floatval($r['net_part1'] ?? 0);
                                $p2 = floatval($r['net_part2'] ?? 0);
                                $ex = floatval($r['extra_pure'] ?? 0);
                                $hasSplit = ($p1 > 0 || $p2 > 0);
                                
                                if ($hasSplit) {
                                    $w1 = floatval($r['wastage1'] ?? 0);
                                    $w2 = floatval($r['wastage2'] ?? 0);
                                    $mel = floatval($r['tch'] ?? 0);
                                    $wstDef = floatval($r['wst'] ?? 0);
                                    
                                    $eff1 = ($w1 > 50) ? $w1 : (($w1 > 0) ? ($mel + $w1) : ($mel + $wstDef));
                                    $eff2 = ($w2 > 50) ? $w2 : (($w2 > 0) ? ($mel + $w2) : ($mel + $wstDef));
                                }
                                ?>
                                <td>
                                    <strong><?= htmlspecialchars($r['name']) ?></strong>
                                </td>
                                <td class="text-right font-mono">
                                    <?php if (!$r['is_opening'] && $r['name'] !== 'Cash'): ?>
                                        <?php if ($hasSplit): ?>
                                            <span style="font-size: 9px; font-weight: bold;">P1: <?= number_format($p1, 3) ?></span><br>
                                            <span class="stacked-val" style="font-weight: bold;">P2: <?= number_format($p2, 3) ?></span>
                                        <?php else: ?>
                                            <?= number_format($r['gross'], 3) ?><br>
                                            <span class="stacked-val"><?= number_format($r['less'], 3) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-mono">
                                    <?php if (!$r['is_opening'] && $r['name'] !== 'Cash'): ?>
                                        <?= number_format($r['net'], 3) ?>
                                        <?php if ($ex > 0): ?>
                                            <br><span class="stacked-val" style="color: #b45309; font-weight: bold;">+<?= number_format($ex, 3) ?> Ex</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-mono">
                                    <?php if (!$r['is_opening'] && $r['name'] !== 'Cash'): ?>
                                        <?php if ($hasSplit): ?>
                                            <span style="font-size: 9px; font-weight: bold; color: #b45309;"><?= number_format($eff1, 2) ?>%</span><br>
                                            <span class="stacked-val" style="font-weight: bold; color: #b45309;"><?= number_format($eff2, 2) ?>%</span>
                                        <?php else: ?>
                                            <?= number_format($r['tch'], 2) ?><br>
                                            <span class="stacked-val"><?= number_format($r['wst'], 2) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-mono">
                                    <?php 
                                    if ($r['fine'] != 0) {
                                        $isCredit = $r['fine'] >= 0;
                                        $displayFine = abs($r['fine']);
                                        $colorClass = $isCredit ? 'text-green' : 'text-red';
                                        echo "<span class='{$colorClass}'>" . number_format($displayFine, 3) . ($isCredit ? ' Cr' : ' Db') . "</span>";
                                    } else {
                                        echo '0.000';
                                    }
                                    ?>
                                </td>
                                <td class="text-right font-mono">
                                    0
                                </td>
                                <td class="text-right font-mono">
                                    <?php 
                                    if ($r['cash'] != 0) {
                                        $isCredit = $r['cash'] >= 0;
                                        $displayCash = abs($r['cash']);
                                        $colorClass = $isCredit ? 'text-green' : 'text-red';
                                        echo "<span class='{$colorClass}'>" . number_format($displayCash, 0) . ($isCredit ? ' Cr' : ' Db') . "</span>";
                                    } else {
                                        echo '0';
                                    }
                                    ?>
                                </td>
                                <td style="font-size: 10px;"><?= htmlspecialchars($r['remark']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <tr style="font-weight: bold; background-color: #f8fafc;">
                            <td colspan="3" class="text-center">Total</td>
                            <td class="text-right font-mono">
                                <?= number_format($totGross, 3) ?><br>
                                <span class="stacked-val"><?= number_format($totLess, 3) ?></span>
                            </td>
                            <td class="text-right font-mono"><?= number_format($totNet, 3) ?></td>
                            <td></td>
                            <td class="text-right font-mono">
                                <?php 
                                if ($filterType === 'all') {
                                    $isCr = $currentOutstandingGold >= 0;
                                    $colorClass = $isCr ? 'text-green' : 'text-red';
                                    echo "<span class='{$colorClass}'>" . number_format(abs($currentOutstandingGold), 3) . ($isCr ? ' Cr' : ' Db') . "</span>";
                                } elseif ($filterType === 'metal') {
                                    echo "<span class='text-green'>" . number_format($totFine, 3) . " Cr</span>";
                                } else { // kaam
                                    echo "<span class='text-red'>" . number_format(abs($totFine), 3) . " Db</span>";
                                }
                                ?>
                            </td>
                            <td class="text-right font-mono">0</td>
                            <td class="text-right font-mono">
                                <?php 
                                if ($filterType === 'all') {
                                    $isCr = $currentOutstandingCash >= 0;
                                    $colorClass = $isCr ? 'text-green' : 'text-red';
                                    echo "<span class='{$colorClass}'>" . number_format(abs($currentOutstandingCash), 0) . ($isCr ? ' Cr' : ' Db') . "</span>";
                                } elseif ($filterType === 'metal') {
                                    echo "<span class='text-green'>" . number_format($totCash, 0) . " Cr</span>";
                                } else { // kaam
                                    echo "<span class='text-red'>" . number_format(abs($totCash), 0) . " Db</span>";
                                }
                                ?>
                            </td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            function drawStatementLogo() {
                const canvas = document.getElementById('statementLogoCanvas');
                const img = document.getElementById('statementLogoImg');
                if (!canvas || !img) return;
                const ctx = canvas.getContext('2d');
                if (!ctx) return;

                const render = () => {
                    const iw = img.naturalWidth || img.width;
                    const ih = img.naturalHeight || img.height;
                    if (!iw || !ih) return;

                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    // Smooth rounded corner clip
                    const r = 12;
                    ctx.save();
                    ctx.beginPath();
                    ctx.moveTo(r, 0);
                    ctx.lineTo(canvas.width - r, 0);
                    ctx.quadraticCurveTo(canvas.width, 0, canvas.width, r);
                    ctx.lineTo(canvas.width, canvas.height - r);
                    ctx.quadraticCurveTo(canvas.width, canvas.height, canvas.width - r, canvas.height);
                    ctx.lineTo(r, canvas.height);
                    ctx.quadraticCurveTo(0, canvas.height, 0, canvas.height - r);
                    ctx.lineTo(0, r);
                    ctx.quadraticCurveTo(0, 0, r, 0);
                    ctx.closePath();
                    ctx.clip();

                    // Object-fit: contain calculation
                    const scale = Math.min(canvas.width / iw, canvas.height / ih);
                    const dw = iw * scale;
                    const dh = ih * scale;
                    const dx = (canvas.width - dw) / 2;
                    const dy = (canvas.height - dh) / 2;
                    ctx.drawImage(img, dx, dy, dw, dh);
                    ctx.restore();
                };

                if (img.complete && (img.naturalWidth || img.width) > 0) {
                    render();
                } else {
                    img.onload = render;
                }
            }
            drawStatementLogo();

            let isPhotoBusy = false;
            async function generateAndSharePhoto(isDownloadOnly) {
                if (isPhotoBusy) return;
                isPhotoBusy = true;
                const btn = document.getElementById('btnSharePhoto');
                const btnDl = document.getElementById('btnDlPhoto');
                const status = document.getElementById('photoStatus');
                const origBtnText = btn ? btn.innerHTML : '';
                
                if (btn && !isDownloadOnly) btn.innerHTML = '⏳ Generating...';
                if (btnDl && isDownloadOnly) btnDl.innerHTML = '⏳ Generating...';
                if (status) status.innerText = 'Creating high-resolution photo...';

                try {
                    const sheet = document.getElementById('statementContent');
                    if (!sheet) throw new Error("Statement content not found");

                    // Ensure statement logo is drawn on live canvas
                    const logoImg = document.getElementById('statementLogoImg');
                    if (logoImg && (!logoImg.complete || !(logoImg.naturalWidth || logoImg.width))) {
                        await new Promise(resolve => {
                            logoImg.onload = resolve;
                            logoImg.onerror = resolve;
                            setTimeout(resolve, 500);
                        });
                    }
                    drawStatementLogo();

                    const scrollWrapper = sheet.closest('.scroll-wrapper');
                    const prevScrollLeft = scrollWrapper ? scrollWrapper.scrollLeft : 0;
                    if (scrollWrapper) scrollWrapper.scrollLeft = 0;

                    // Ensure all other visible images are fully loaded and rendered before html2canvas runs
                    const sheetImages = Array.from(sheet.querySelectorAll('img')).filter(i => i.style.display !== 'none');
                    await Promise.all(sheetImages.map(img => {
                        if (img.complete && img.naturalWidth !== 0) return Promise.resolve();
                        return new Promise(resolve => {
                            img.onload = img.onerror = resolve;
                        });
                    }));

                    await new Promise(r => setTimeout(r, 100));

                    const canvas = await html2canvas(sheet, {
                        scale: 2,
                        backgroundColor: '#ffffff',
                        useCORS: true,
                        allowTaint: true,
                        logging: false,
                        scrollX: 0,
                        scrollY: 0,
                        windowWidth: 860,
                        onclone: function(clonedDoc) {
                            const origCanvas = document.getElementById('statementLogoCanvas');
                            const clonedCanvas = clonedDoc.getElementById('statementLogoCanvas');
                            if (origCanvas && clonedCanvas) {
                                const ctx = clonedCanvas.getContext('2d');
                                if (ctx) {
                                    ctx.drawImage(origCanvas, 0, 0);
                                }
                            }
                        }
                    });

                    const safeName = <?= json_encode(preg_replace('/[^a-zA-Z0-9_-]/', '_', $bapari['name'] ?: 'customer')) ?>;
                    const dateStr = <?= json_encode(date('d_m_Y')) ?>;
                    if (scrollWrapper) scrollWrapper.scrollLeft = prevScrollLeft;
                    const fileName = `Ledger_${safeName}_${dateStr}.png`;

                    if (isDownloadOnly) {
                        downloadFallback(canvas, fileName);
                        if (status) status.innerText = '✓ Photo downloaded!';
                        setTimeout(() => { if (status) status.innerText = ''; }, 3500);
                        return;
                    }

                    canvas.toBlob(async function(blob) {
                        if (!blob) {
                            alert("Image creation failed. Downloading directly instead.");
                            downloadFallback(canvas, fileName);
                            return;
                        }

                        const file = new File([blob], fileName, { type: 'image/png' });

                        if (navigator.canShare && navigator.canShare({ files: [file] })) {
                            try {
                                if (status) status.innerText = 'Opening WhatsApp / Share Sheet...';
                                await navigator.share({
                                    files: [file],
                                    title: `Ledger Statement - ${safeName}`,
                                    text: `Ledger Statement for <?= addslashes(htmlspecialchars($bapari['name'])) ?>`
                                });
                                if (status) status.innerText = '✓ Photo shared successfully!';
                                setTimeout(() => { if (status) status.innerText = ''; }, 3000);
                                return;
                            } catch (e) {
                                if (e.name === 'AbortError') {
                                    if (status) status.innerText = '';
                                    return;
                                }
                                console.warn("Share failed:", e);
                            }
                        }

                        // Fallback for PC or browsers without file sharing
                        let copied = false;
                        if (navigator.clipboard && window.ClipboardItem) {
                            try {
                                await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
                                copied = true;
                            } catch (clipErr) {}
                        }

                        downloadFallback(canvas, fileName);

                        if (copied) {
                            alert("Photo copied to clipboard & downloaded! You can paste (Ctrl+V) directly into WhatsApp.");
                        } else {
                            alert("Photo downloaded! You can send it directly to the Bapari.");
                        }
                        if (status) status.innerText = '✓ Photo downloaded!';
                        setTimeout(() => { if (status) status.innerText = ''; }, 3500);
                    }, 'image/png', 0.95);

                } catch (err) {
                    console.error(err);
                    alert("Error: " + err.message);
                    if (status) status.innerText = 'Failed to generate photo.';
                } finally {
                    isPhotoBusy = false;
                    if (btn) btn.innerHTML = origBtnText;
                    if (btnDl) btnDl.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Download Photo`;
                }
            }

            function downloadFallback(canvas, fileName) {
                const a = document.createElement('a');
                a.download = fileName;
                a.href = canvas.toDataURL('image/png');
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }

            window.addEventListener('DOMContentLoaded', function() {
                const params = new URLSearchParams(window.location.search);
                const auto = params.get('auto');
                if (auto === 'photo') {
                    setTimeout(function() {
                        generateAndSharePhoto(false);
                    }, 600);
                } else if (auto === 'pdf') {
                    setTimeout(function() {
                        window.print();
                    }, 400);
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit();
}
require_once 'header.php';
?>

<!-- Title & Navigation -->
<div class="mb-5 mt-2 flex items-center justify-between no-print">
    <div class="flex items-center space-x-3">
        <a href="reports.php" class="w-9 h-9 rounded-xl bg-slate-900 border border-white/[0.04] flex items-center justify-center text-slate-400 hover:text-white transition-colors tap-target">
            <span class="material-symbols-rounded text-lg">arrow_back</span>
        </a>
        <div class="min-w-0">
            <h1 class="text-xl font-bold text-white truncate leading-tight"><?= htmlspecialchars($bapari['name']) ?></h1>
            <p class="text-[10px] text-slate-500 mt-0.5 truncate"><?= htmlspecialchars($bapari['mobile'] ?: 'No mobile') ?> | <?= htmlspecialchars($bapari['address'] ?: 'No address') ?></p>
        </div>
    </div>
</div>

<!-- Outstanding Balance Panel (Tally Style aggregates top display) -->
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <span class="text-[#d8a735] text-[10px] uppercase font-bold tracking-wider block">Outstanding Balance</span>
        <!-- Settlement Button Trigger Scroll -->
        <?php if (!$isReadOnly): ?>
            <button onclick="document.getElementById('settlementSection').scrollIntoView({ behavior: 'smooth' })" class="px-3 py-1.5 rounded-lg bg-[#d8a735]/15 border border-[#d8a735]/25 text-[10px] text-[#d8a735] font-bold tracking-wider hover:bg-[#d8a735]/20 tap-target no-print flex items-center space-x-1">
                <span class="material-symbols-rounded text-xs">done_all</span>
                <span>Settle Ledger</span>
            </button>
        <?php endif; ?>
    </div>
    
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="premium-card bg-[#121212]/70 border-white/[0.03]">
            <span class="text-slate-500 text-[9px] uppercase font-bold block mb-1">Gold Outstanding</span>
            <div class="text-lg font-bold font-mono <?= $currentOutstandingGold >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                <?= number_format(abs($currentOutstandingGold), 3) ?> g <?= $currentOutstandingGold >= 0 ? 'Cr' : 'Db' ?>
            </div>
        </div>
        <div class="premium-card bg-[#121212]/70 border-white/[0.03]">
            <span class="text-slate-500 text-[9px] uppercase font-bold block mb-1">Cash Outstanding</span>
            <div class="text-lg font-bold font-mono <?= $currentOutstandingCash >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                ₹<?= number_format(abs($currentOutstandingCash), 2) ?> <?= $currentOutstandingCash >= 0 ? 'Cr' : 'Db' ?>
            </div>
        </div>
    </div>
    <div class="flex items-center justify-between text-[9.5px] text-slate-500 mt-2.5 font-mono px-1">
        <span>Last Transaction Date: <span class="text-slate-400 font-bold"><?= $lastTransactionDate ?></span></span>
        <?php if ($latestSettle): ?>
            <span>Last Settlement Date: <span class="text-[#d8a735] font-bold"><?= date('d/m/Y', strtotime($latestSettle['settlement_date'])) ?></span></span>
        <?php endif; ?>
    </div>
</div>

<!-- Filter Form with Transaction Mode (All / Only Metal / Only Kaam) -->
<form method="GET" class="premium-card bg-[#121212]/80 p-4 mb-6 space-y-4 no-print">
    <input type="hidden" name="bapari_id" value="<?= $bapariId ?>">
    
    <!-- Mode Pills -->
    <div>
        <div class="flex items-center justify-between mb-1.5">
            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-400">Transaction Filter (Input / Output)</label>
            <span class="text-[#d8a735] font-semibold text-[10px]"><?= $filterType === 'metal' ? 'Only Metal (Inward / Jama)' : ($filterType === 'kaam' ? 'Only Kaam (Outward / Kaj)' : 'All Transactions (Sab)') ?></span>
        </div>
        <div class="grid grid-cols-3 gap-2">
            <a href="ledger.php?bapari_id=<?= $bapariId ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&filter_type=all" class="py-2.5 px-2 rounded-xl text-xs font-bold transition-all text-center flex items-center justify-center <?= $filterType === 'all' ? 'bg-[#d8a735] text-slate-950 shadow-md font-extrabold' : 'bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white' ?>">
                All (Sab)
            </a>
            <a href="ledger.php?bapari_id=<?= $bapariId ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&filter_type=metal" class="py-2.5 px-2 rounded-xl text-xs font-bold transition-all text-center flex items-center justify-center <?= $filterType === 'metal' ? 'bg-[#d8a735] text-slate-950 shadow-md font-extrabold' : 'bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white' ?>">
                Only Metal
            </a>
            <a href="ledger.php?bapari_id=<?= $bapariId ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&filter_type=kaam" class="py-2.5 px-2 rounded-xl text-xs font-bold transition-all text-center flex items-center justify-center <?= $filterType === 'kaam' ? 'bg-[#d8a735] text-slate-950 shadow-md font-extrabold' : 'bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white' ?>">
                Only Kaam
            </a>
        </div>
        <input type="hidden" name="filter_type" id="pageFilterType" value="<?= htmlspecialchars($filterType) ?>">
    </div>

    <!-- Date Range -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
        <div>
            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">From Date</label>
            <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="premium-input text-xs">
        </div>
        <div>
            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">To Date</label>
            <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="premium-input text-xs">
        </div>
    </div>

    <!-- Submit buttons -->
    <div class="flex items-center space-x-2 pt-1">
        <button type="submit" class="flex-1 btn-gold text-xs py-3 font-bold tracking-wider flex items-center justify-center space-x-1.5">
            <span class="material-symbols-rounded text-sm">filter_alt</span>
            <span>Apply Date Filter</span>
        </button>
        <a href="ledger.php?bapari_id=<?= $bapariId ?>" class="px-4 py-3 rounded-xl bg-slate-900 border border-white/[0.04] text-[10px] font-bold text-slate-400 hover:text-white flex items-center justify-center">
            Reset
        </a>
    </div>
</form>

<!-- Print PDF & Photo Share Action row -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mb-6 no-print">
    <a href="ledger.php?bapari_id=<?= $bapariId ?>&from=<?= $from ?>&to=<?= $to ?>&filter_type=<?= $filterType ?>&print=1&auto=photo" target="_blank" class="w-full py-3 px-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-lg shadow-emerald-900/30 text-xs font-bold flex items-center justify-center space-x-1.5 tap-target">
        <span class="material-symbols-rounded text-base">photo_camera</span>
        <span>Send as Photo</span>
    </a>

    <a href="ledger.php?bapari_id=<?= $bapariId ?>&from=<?= $from ?>&to=<?= $to ?>&filter_type=<?= $filterType ?>&print=1&auto=pdf" target="_blank" class="w-full py-3 px-2 rounded-xl border border-[#d8a735]/40 bg-transparent text-xs font-bold text-[#d8a735] hover:bg-[#d8a735]/10 flex items-center justify-center space-x-1.5 tap-target">
        <span class="material-symbols-rounded text-base">picture_as_pdf</span>
        <span>Download PDF</span>
    </a>
    
    <button onclick="shareLedgerText()" class="w-full py-3 px-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/10 text-xs font-bold text-slate-200 flex items-center justify-center space-x-1.5 tap-target">
        <span class="material-symbols-rounded text-base">chat</span>
        <span>WhatsApp Text</span>
    </button>
</div>
<!-- Statement List (Exposing both edit and delete options) -->
<div class="mb-6" id="transactionsLog">
    <span class="text-slate-400 text-[10px] uppercase font-bold tracking-wider block mb-4">Transactions Log</span>
    
    <div class="space-y-4">
        <?php 
        $activeRows = array_filter($ledgerRows, function($r) { return !$r['is_opening']; });
        
        if (empty($activeRows)): ?>
            <div class="premium-card text-center py-10">
                <span class="material-symbols-rounded text-4xl text-slate-700 mb-2">receipt</span>
                <p class="text-xs text-slate-500">No transactions recorded in this date range.</p>
            </div>
        <?php else: ?>
            <?php 
            $screenRows = array_reverse($activeRows);
            foreach ($screenRows as $row): 
                $isDeposit = ($row['type'] === 'deposit');
            ?>
                <div id="entry_<?= $row['type'] ?>_<?= $row['id'] ?>" class="premium-card bg-[#111111]/90">
                    <div class="flex items-start justify-between border-b border-white/[0.04] pb-2.5 mb-2.5">
                        <div>
                            <span class="text-[9px] text-slate-500 font-mono"><?= date('d/m/Y', strtotime($row['date'])) ?></span>
                            <?php if (!empty($row['no'])): ?>
                                <span class="text-[9px] text-[#d8a735] font-mono ml-1.5 font-bold">• <?= htmlspecialchars($row['no']) ?></span>
                            <?php endif; ?>
                            <div class="mt-0.5">
                                <?php if ($row['is_settlement']): ?>
                                    <span class="bg-[#d8a735]/10 text-[#d8a735] px-2 py-0.5 rounded-lg border border-[#d8a735]/20 text-[8px] font-bold uppercase tracking-wider">Ledger Settlement</span>
                                <?php elseif ($isDeposit): ?>
                                    <span class="bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded-lg border border-emerald-500/20 text-[8px] font-bold uppercase tracking-wider">Fine Deposit</span>
                                <?php else: ?>
                                    <span class="bg-rose-500/10 text-rose-400 px-2 py-0.5 rounded-lg border border-rose-500/20 text-[8px] font-bold uppercase tracking-wider">Kaj Entry</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="text-right">
                            <span class="text-[8px] text-slate-500 uppercase block font-semibold">Running Balance</span>
                            <span class="font-mono text-white text-xs block"><?= number_format($row['running_fine'], 3) ?> g</span>
                            <span class="font-mono text-slate-500 text-[9px] block">₹<?= number_format($row['running_cash'], 2) ?></span>
                        </div>
                    </div>

                    <!-- Details of change -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs my-2">
                        <div>
                            <span class="text-[8px] text-slate-500 uppercase block">Gold Weight</span>
                            <span class="font-mono font-bold text-sm <?= $row['fine'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                <?= $row['fine'] >= 0 ? '+' : '-' ?><?= number_format(abs($row['fine']), 3) ?> g
                            </span>
                        </div>
                        <div>
                            <span class="text-[8px] text-slate-500 uppercase block">Cash Amount</span>
                            <span class="font-mono font-bold text-sm <?= $row['cash'] >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                <?= $row['cash'] != 0 ? ($row['cash'] >= 0 ? '+' : '-') . '₹' . number_format(abs($row['cash']), 0) : '--' ?>
                            </span>
                        </div>
                    </div>

                    <div class="text-[10px] text-slate-400 mt-2 font-mono bg-slate-950/40 p-2.5 rounded-xl border border-white/[0.02]">
                        <span class="font-sans font-bold text-slate-500 block mb-0.5">Details (<?= htmlspecialchars($row['name']) ?>):</span>
                        <?php if ($row['name'] !== 'Cash'): ?>
                            Gross: <?= number_format($row['gross'], 3) ?>g | Less: <?= number_format($row['less'], 3) ?>g<br>
                            Purity: <?= number_format($row['tch'], 2) ?>% | Wastage: <?= number_format($row['wst'], 2) ?>%
                            <?php 
                            $splitNotes = [];
                            if (!empty($row['net_part1']) && floatval($row['net_part1']) > 0) {
                                $splitNotes[] = "P1 Net: " . number_format($row['net_part1'], 3) . "g (" . number_format($row['wastage1'], 2) . "%)";
                            }
                            if (!empty($row['net_part2']) && floatval($row['net_part2']) > 0) {
                                $splitNotes[] = "P2 Net: " . number_format($row['net_part2'], 3) . "g (" . number_format($row['wastage2'], 2) . "%)";
                            }
                            if (!empty($row['extra_pure']) && floatval($row['extra_pure']) > 0) {
                                $splitNotes[] = "Extra Pure: " . number_format($row['extra_pure'], 3) . "g";
                            }
                            if (!empty($splitNotes)) {
                                echo "<br><span class='text-[#d8a735] font-bold'>" . htmlspecialchars(implode(" | ", $splitNotes)) . "</span>";
                            }
                            ?>
                        <?php else: ?>
                            Cash Entry
                        <?php endif; ?>
                        <?php if ($row['remark']): ?>
                            <div class="mt-1 text-slate-500 font-sans">Narration: <?= htmlspecialchars($row['remark']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php
                    $txShareData = [
                        'date' => date('d/m/Y', strtotime($row['date'])),
                        'no' => $row['no'] ?? '',
                        'typeName' => $row['is_settlement'] ? 'Ledger Settlement' : ($isDeposit ? 'Fine Deposit' : 'Kaj Entry'),
                        'name' => $row['name'],
                        'gross' => number_format($row['gross'], 3),
                        'less' => number_format($row['less'], 3),
                        'net' => number_format($row['net'], 3),
                        'tch' => number_format($row['tch'], 2),
                        'wst' => number_format($row['wst'], 2),
                        'fine' => ($row['fine'] >= 0 ? '+' : '-') . number_format(abs($row['fine']), 3),
                        'cash' => $row['cash'] != 0 ? (($row['cash'] >= 0 ? '+' : '-') . '₹' . number_format(abs($row['cash']), 0)) : '--',
                        'remark' => $row['remark'] ?? '',
                        'runningGold' => number_format($row['running_fine'], 3) . ' g',
                        'runningCash' => '₹' . number_format($row['running_cash'], 2),
                        'splitNotes' => $splitNotes ?? []
                    ];
                    ?>
                    <div class="flex items-center justify-end space-x-2.5 mt-3 pt-2.5 border-t border-white/[0.03] no-print">
                        <button type="button" onclick='shareSingleTx(<?= json_encode($txShareData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="w-8 h-8 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/20 flex items-center justify-center transition-colors tap-target" title="Share Voucher on WhatsApp">
                            <span class="material-symbols-rounded text-base">share</span>
                        </button>
                        <?php if (!$isReadOnly): ?>
                            <?php if ($row['is_settlement']): ?>
                                <a href="ledger.php?bapari_id=<?= $bapariId ?>&edit_settlement=<?= $row['id'] ?>" class="w-8 h-8 rounded-lg bg-slate-900 hover:bg-slate-800 border border-white/[0.05] flex items-center justify-center text-slate-400 transition-colors tap-target" title="Edit Settlement">
                                    <span class="material-symbols-rounded text-base">edit</span>
                                </a>
                                <a href="ledger.php?bapari_id=<?= $bapariId ?>&delete_settlement=<?= $row['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" onclick="return confirm('Are you sure you want to delete this settlement?')" class="w-8 h-8 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target" title="Delete Settlement">
                                    <span class="material-symbols-rounded text-base">delete</span>
                                </a>
                            <?php elseif ($isDeposit): ?>
                                <a href="deposits.php?action=edit&id=<?= $row['id'] ?>&return_url=<?= urlencode("ledger.php?bapari_id={$bapariId}&from={$from}&to={$to}#entry_deposit_{$row['id']}") ?>" class="w-8 h-8 rounded-lg bg-slate-900 hover:bg-slate-800 border border-white/[0.05] flex items-center justify-center text-slate-400 transition-colors tap-target" title="Edit Entry">
                                    <span class="material-symbols-rounded text-base">edit</span>
                                </a>
                                <a href="ledger.php?bapari_id=<?= $bapariId ?>&delete_deposit=<?= $row['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" onclick="return confirm('Are you sure you want to delete this deposit entry?')" class="w-8 h-8 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target" title="Delete Entry">
                                    <span class="material-symbols-rounded text-base">delete</span>
                                </a>
                            <?php else: ?>
                                <a href="kaj.php?action=edit&id=<?= $row['id'] ?>&return_url=<?= urlencode("ledger.php?bapari_id={$bapariId}&from={$from}&to={$to}#entry_kaj_{$row['id']}") ?>" class="w-8 h-8 rounded-lg bg-slate-900 hover:bg-slate-800 border border-white/[0.05] flex items-center justify-center text-slate-400 transition-colors tap-target" title="Edit Entry">
                                    <span class="material-symbols-rounded text-base">edit</span>
                                </a>
                                <a href="ledger.php?bapari_id=<?= $bapariId ?>&delete_kaj=<?= $row['id'] ?>&from=<?= $from ?>&to=<?= $to ?>" onclick="return confirm('Are you sure you want to delete this job entry?')" class="w-8 h-8 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 flex items-center justify-center transition-colors tap-target" title="Delete Entry">
                                    <span class="material-symbols-rounded text-base">delete</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- SETTLEMENT HISTORY SECTION -->
<div class="mb-8 no-print">
    <span class="text-slate-400 text-[10px] uppercase font-bold tracking-wider block mb-3">Settlement History</span>
    <?php if (empty($settlementsHistory)): ?>
        <div class="premium-card text-center py-6 text-xs text-slate-500 bg-[#121212]/40 border-dashed">
            No settlements completed yet for this customer.
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($settlementsHistory as $sh): ?>
                <div class="premium-card bg-[#121212]/60 p-3.5 flex items-center justify-between text-xs">
                    <div>
                        <span class="text-[9px] text-slate-500 font-mono"><?= date('d/m/Y', strtotime($sh['settlement_date'])) ?></span>
                        <div class="font-bold text-white mt-0.5">Gold Checkpoint: <?= number_format($sh['closing_gold'], 3) ?> g</div>
                        <div class="text-[10px] text-slate-400">Cash Checkpoint: ₹<?= number_format($sh['closing_cash'], 0) ?></div>
                    </div>
                    <!-- Download PDF representing state at this checkpoint -->
                    <a href="ledger.php?bapari_id=<?= $bapariId ?>&to=<?= $sh['settlement_date'] ?>&print=1" target="_blank" class="px-2.5 py-1.5 bg-[#d8a735]/15 hover:bg-[#d8a735]/25 border border-[#d8a735]/25 rounded-lg text-[9px] text-[#d8a735] font-bold flex items-center space-x-1 transition-colors tap-target">
                        <span class="material-symbols-rounded text-xs">print</span>
                        <span>Statement PDF</span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- INLINE SETTLE LEDGER FORM AT THE BOTTOM -->
<div id="settlementSection" class="premium-card border-[#d8a735]/20 bg-[#121212]/80 mt-6 no-print">
    <h2 class="text-sm font-bold text-[#d8a735] mb-3 flex items-center">
        <span class="material-symbols-rounded text-lg mr-1.5"><?= $editSettlement ? 'edit' : 'done_all' ?></span> 
        <?= $editSettlement ? 'Edit Ledger Settlement' : 'Settle Ledger Account' ?>
    </h2>
    
    <p class="text-[11px] text-slate-400 mb-4 leading-relaxed">
        <?= $editSettlement ? 'Update the selected settlement checkpoint.' : 'Settling this ledger registers the current outstanding gold and cash balances as a fixed checkpoint. Future statements will start with these values as their opening balances.' ?>
    </p>
    
    <?php 
        $sDate = $editSettlement ? $editSettlement['settlement_date'] : date('Y-m-d');
        $sGold = $editSettlement ? $editSettlement['closing_gold'] : $currentOutstandingGold;
        $sCash = $editSettlement ? $editSettlement['closing_cash'] : $currentOutstandingCash;
    ?>
    
    <form method="POST" action="ledger.php?bapari_id=<?= $bapariId ?>" class="space-y-4" onsubmit="prepareSettleSubmit()">
        <input type="hidden" name="settle_ledger" value="1">
        <?php if ($editSettlement): ?>
            <input type="hidden" name="settlement_id" value="<?= $editSettlement['id'] ?>">
        <?php endif; ?>
        
        <div>
            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Settlement Date</label>
            <input type="date" name="settlement_date" value="<?= $sDate ?>" required class="premium-input text-xs">
        </div>
        
        <div>
            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Closing Gold Balance (gm) *</label>
            <div class="flex space-x-2 mb-2">
                <button type="button" id="goldTypeJama" onclick="setGoldType('jama')" class="flex-1 py-2 rounded-xl text-[10px] font-bold transition-all border">
                    Jama / Credit (Customer ka hamare paas)
                </button>
                <button type="button" id="goldTypeKaj" onclick="setGoldType('kaj')" class="flex-1 py-2 rounded-xl text-[10px] font-bold transition-all border">
                    Lena / Debit (Hamara customer par)
                </button>
            </div>
            <input type="hidden" name="closing_gold" id="finalClosingGold" value="<?= round($sGold, 3) ?>">
            <input type="number" step="0.001" id="inputGoldVal" value="<?= abs(round($sGold, 3)) ?>" required class="premium-input text-xs font-mono" placeholder="0.000">
        </div>
        
        <div>
            <label class="block text-[9px] font-bold uppercase tracking-wider text-slate-400 mb-1.5">Closing Cash Balance (₹) *</label>
            <div class="flex space-x-2 mb-2">
                <button type="button" id="cashTypeJama" onclick="setCashType('jama')" class="flex-1 py-2 rounded-xl text-[10px] font-bold transition-all border">
                    Jama / Credit (Customer ka hamare paas)
                </button>
                <button type="button" id="cashTypeKaj" onclick="setCashType('kaj')" class="flex-1 py-2 rounded-xl text-[10px] font-bold transition-all border">
                    Lena / Debit (Hamara customer par)
                </button>
            </div>
            <input type="hidden" name="closing_cash" id="finalClosingCash" value="<?= round($sCash, 2) ?>">
            <input type="number" step="0.01" id="inputCashVal" value="<?= abs(round($sCash, 2)) ?>" required class="premium-input text-xs font-mono" placeholder="0.00">
        </div>
        
        <div class="pt-3 border-t border-slate-800 flex justify-end space-x-3">
            <?php if ($editSettlement): ?>
                <a href="ledger.php?bapari_id=<?= $bapariId ?>" class="px-6 py-3 rounded-xl bg-slate-900 border border-white/[0.04] text-xs font-bold text-slate-400 hover:text-white transition-colors">Cancel</a>
            <?php endif; ?>
            <button type="submit" class="btn-gold text-xs py-3 px-6"><?= $editSettlement ? 'Update Settlement' : 'Confirm Settle' ?></button>
        </div>
    </form>

    <script>
        let currentGoldType = <?= $sGold >= 0 ? "'jama'" : "'kaj'" ?>;
        let currentCashType = <?= $sCash >= 0 ? "'jama'" : "'kaj'" ?>;

        function updateTypeButtons() {
            const gj = document.getElementById('goldTypeJama');
            const gk = document.getElementById('goldTypeKaj');
            if (gj && gk) {
                if (currentGoldType === 'jama') {
                    gj.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 shadow-sm";
                    gk.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-slate-950 text-slate-500 border border-white/[0.04] hover:bg-slate-900";
                } else {
                    gk.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-rose-500/20 text-rose-400 border border-rose-500/40 shadow-sm";
                    gj.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-slate-950 text-slate-500 border border-white/[0.04] hover:bg-slate-900";
                }
            }

            const cj = document.getElementById('cashTypeJama');
            const ck = document.getElementById('cashTypeKaj');
            if (cj && ck) {
                if (currentCashType === 'jama') {
                    cj.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-emerald-500/20 text-emerald-400 border border-emerald-500/40 shadow-sm";
                    ck.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-slate-950 text-slate-500 border border-white/[0.04] hover:bg-slate-900";
                } else {
                    ck.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-rose-500/20 text-rose-400 border border-rose-500/40 shadow-sm";
                    cj.className = "flex-1 py-2 rounded-xl text-[10px] font-bold transition-all bg-slate-950 text-slate-500 border border-white/[0.04] hover:bg-slate-900";
                }
            }
        }

        function setGoldType(type) {
            currentGoldType = type;
            updateTypeButtons();
        }

        function setCashType(type) {
            currentCashType = type;
            updateTypeButtons();
        }

        function prepareSettleSubmit() {
            const gVal = Math.abs(parseFloat(document.getElementById('inputGoldVal').value) || 0);
            const cVal = Math.abs(parseFloat(document.getElementById('inputCashVal').value) || 0);

            document.getElementById('finalClosingGold').value = (currentGoldType === 'jama' ? gVal : -gVal);
            document.getElementById('finalClosingCash').value = (currentCashType === 'jama' ? cVal : -cVal);
            return true;
        }

        // Init buttons state on load
        document.addEventListener('DOMContentLoaded', () => {
            updateTypeButtons();
            
            // Auto-scroll to form ONLY if editing settlement
            if (window.location.search.includes('edit_settlement=')) {
                const section = document.getElementById('settlementSection');
                if (section) {
                    setTimeout(() => {
                        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                }
            } else if (!window.location.hash) {
                // Ensure page always opens at the very top so Outstanding Balance is immediately visible
                if ('scrollRestoration' in history) {
                    history.scrollRestoration = 'manual';
                }
                window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
            }
        });
    </script>
</div>

<script>
    function shareSingleTx(tx) {
        const company = <?= json_encode($currentUser['company_name'] ?: 'Dasgold') ?>;
        const party = <?= json_encode($bapari['name'] ?? '') ?>;
        
        let text = `*${company}*\n`;
        text += `*Transaction Voucher*\n`;
        text += `--------------------------------\n`;
        text += `*Customer:* ${party}\n`;
        text += `*Date:* ${tx.date}\n`;
        if (tx.no) {
            text += `*Voucher:* ${tx.no}\n`;
        }
        text += `*Type:* ${tx.typeName}\n`;
        text += `--------------------------------\n`;
        
        if (tx.name && tx.name !== 'Cash') {
            text += `*Item:* ${tx.name}\n`;
            text += `*Gross:* ${tx.gross} g | *Less:* ${tx.less} g\n`;
            text += `*Net:* ${tx.net} g\n`;
            text += `*Purity:* ${tx.tch}% | *Wastage:* ${tx.wst}%\n`;
            if (tx.splitNotes && tx.splitNotes.length > 0) {
                text += `*Split:* ${tx.splitNotes.join(' | ')}\n`;
            }
        }
        
        text += `*Gold Fine:* ${tx.fine} g\n`;
        if (tx.cash && tx.cash !== '--') {
            text += `*Cash:* ${tx.cash}\n`;
        }
        if (tx.remark) {
            text += `*Narration:* ${tx.remark}\n`;
        }
        
        text += `--------------------------------\n`;
        text += `*Balance After Entry:*\n`;
        text += `*Gold:* ${tx.runningGold}\n`;
        text += `*Cash:* ${tx.runningCash}\n`;

        if (navigator.share) {
            navigator.share({
                title: `${company} - ${tx.no || 'Voucher'}`,
                text: text
            }).catch(err => {
                if (err.name !== 'AbortError') {
                    window.open("https://api.whatsapp.com/send?text=" + encodeURIComponent(text), "_blank");
                }
            });
        } else {
            window.open("https://api.whatsapp.com/send?text=" + encodeURIComponent(text), "_blank");
        }
    }

    function shareLedgerText() {
        let text = "*Dasgold Ledger Statement*\n";
        text += "*Customer:* <?= htmlspecialchars($bapari['name']) ?>\n";
        text += "*Gold Balance:* <?= number_format($fineRunning, 3) ?> g <?= $fineRunning >= 0 ? 'Cr' : 'Db' ?>\n";
        text += "*Cash Balance:* ₹<?= number_format($cashRunning, 2) ?> <?= $cashRunning >= 0 ? 'Cr' : 'Db' ?>\n\n";
        text += "*Recent Ledger Entries:*\n";
        
        <?php 
        $shareCount = 0;
        foreach (array_reverse($activeRows) as $row) {
            if ($shareCount >= 5) break; 
            $dateStr = date('d/m/Y', strtotime($row['date']));
            $sign = $row['fine'] >= 0 ? '+' : '-';
            $fineVal = number_format(abs($row['fine']), 3);
            $cashStr = $row['cash'] != 0 ? " (Cash: " . ($row['cash'] >= 0 ? '+' : '-') . "₹" . number_format(abs($row['cash']), 0) . ")" : "";
            
            echo "text += '• {$dateStr}: {$row['name']} {$sign}{$fineVal}g{$cashStr}\\n';\n";
            $shareCount++;
        }
        ?>
        const encodedText = encodeURIComponent(text);
        window.open("https://api.whatsapp.com/send?text=" + encodedText, "_blank");
    }
</script>

<?php
require_once 'footer.php';
?>

