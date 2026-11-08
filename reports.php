<?php
require_once 'db.php';

// Handle CSV export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $bapariId = intval($_GET['bapari_id'] ?? 0);
    $karigorId = intval($_GET['karigor_id'] ?? 0);
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    if ($bapariId > 0) {
        // Fetch Bapari
        $stmt = $pdo->prepare("SELECT name FROM baparis WHERE id = ? AND user_id = ?");
        $stmt->execute([$bapariId, $userId]);
        $bapari = $stmt->fetch();
        $filename = ($bapari ? str_replace(' ', '_', $bapari['name']) : 'Bapari') . "_Statement_" . date('Ymd') . ".csv";

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Type', 'Gold Weight (g)', 'Purity (%)', 'Jama/Kaj Fine (g)', 'Cash Amount (Rs)', 'Remark']);

        $filterType = $_GET['filter_type'] ?? 'all';
        $deposits = [];
        $kajs = [];

        // Fetch Deposits if all or metal
        if ($filterType === 'all' || $filterType === 'metal') {
            $depQuery = "SELECT date, 'Gold Jama (Metal)' as type, fine_weight, purity, jama_fine as fine, cash_received as cash, remark FROM fine_deposits WHERE bapari_id = ? AND user_id = ?";
            $params = [$bapariId, $userId];
            if ($from) { $depQuery .= " AND date >= ?"; $params[] = $from; }
            if ($to) { $depQuery .= " AND date <= ?"; $params[] = $to; }
            $stmt = $pdo->prepare($depQuery);
            $stmt->execute($params);
            $deposits = $stmt->fetchAll();
        }

        // Fetch Kaj Entries if all or kaam
        if ($filterType === 'all' || $filterType === 'kaam') {
            $kajQuery = "SELECT date, 'Kaarigari Job (Kaam)' as type, 0.0 as fine_weight, 0.0 as purity, total_kaj_fine as fine, cash_bill as cash, remark FROM kaj_entries WHERE bapari_id = ? AND user_id = ?";
            $paramsK = [$bapariId, $userId];
            if ($from) { $kajQuery .= " AND date >= ?"; $paramsK[] = $from; }
            if ($to) { $kajQuery .= " AND date <= ?"; $paramsK[] = $to; }
            $stmt = $pdo->prepare($kajQuery);
            $stmt->execute($paramsK);
            $kajs = $stmt->fetchAll();
        }

        $entries = array_merge($deposits, $kajs);
        usort($entries, function($a, $b) { return strcmp($a['date'], $b['date']); });

        foreach ($entries as $e) {
            fputcsv($output, [
                date('d/m/Y', strtotime($e['date'])),
                $e['type'],
                $e['fine_weight'] > 0 ? $e['fine_weight'] : '--',
                $e['purity'] > 0 ? $e['purity'] . '%' : '--',
                $e['fine'],
                $e['cash'] > 0 ? $e['cash'] : '--',
                $e['remark']
            ]);
        }
        fclose($output);
        exit();
    } elseif ($karigorId > 0) {
        // Fetch Karigor
        $stmt = $pdo->prepare("SELECT name FROM karigors WHERE id = ? AND user_id = ?");
        $stmt->execute([$karigorId, $userId]);
        $karigor = $stmt->fetch();
        $filename = ($karigor ? str_replace(' ', '_', $karigor['name']) : 'Karigor') . "_Statement_" . date('Ymd') . ".csv";

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Type', 'Gold Weight (g)', 'Purity (%)', 'Issue/Receive Fine (g)', 'Cash Amount (Rs)', 'Remark']);

        $filterType = $_GET['filter_type'] ?? 'all';
        $issues = [];
        $receives = [];

        // Fetch Issues if all or metal
        if ($filterType === 'all' || $filterType === 'metal') {
            $issueQuery = "SELECT date, 'Material Issue (OUT/Metal)' as type, fine_weight, purity, issue_fine as fine, cash_paid as cash, remark FROM karigor_material_issues WHERE karigor_id = ? AND user_id = ?";
            $params = [$karigorId, $userId];
            if ($from) { $issueQuery .= " AND date >= ?"; $params[] = $from; }
            if ($to) { $issueQuery .= " AND date <= ?"; $params[] = $to; }
            $stmt = $pdo->prepare($issueQuery);
            $stmt->execute($params);
            $issues = $stmt->fetchAll();
        }

        // Fetch Receives if all or kaam
        if ($filterType === 'all' || $filterType === 'kaam') {
            $recQuery = "SELECT date, 'Kaj Receive (IN/Kaam)' as type, 0.0 as fine_weight, 0.0 as purity, total_receive_fine as fine, cash_paid as cash, remark FROM karigor_kaj_receives WHERE karigor_id = ? AND user_id = ?";
            $paramsK = [$karigorId, $userId];
            if ($from) { $recQuery .= " AND date >= ?"; $paramsK[] = $from; }
            if ($to) { $recQuery .= " AND date <= ?"; $paramsK[] = $to; }
            $stmt = $pdo->prepare($recQuery);
            $stmt->execute($paramsK);
            $receives = $stmt->fetchAll();
        }

        $entries = array_merge($issues, $receives);
        usort($entries, function($a, $b) { return strcmp($a['date'], $b['date']); });

        foreach ($entries as $e) {
            fputcsv($output, [
                date('d/m/Y', strtotime($e['date'])),
                $e['type'],
                $e['fine_weight'] > 0 ? $e['fine_weight'] : '--',
                $e['purity'] > 0 ? $e['purity'] . '%' : '--',
                $e['fine'],
                $e['cash'] > 0 ? $e['cash'] : '--',
                $e['remark']
            ]);
        }
        fclose($output);
        exit();
    }
}

// Fetch baparis for selector
$stmt = $pdo->prepare("SELECT id, name FROM baparis WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$baparis = $stmt->fetchAll();

// Fetch karigors for selector
$stmt = $pdo->prepare("SELECT id, name FROM karigors WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([$userId]);
$karigors = $stmt->fetchAll();

require_once 'header.php';
?>

<!-- Title (Matching Image 1) -->
<div class="mb-5 mt-2 flex items-center justify-between">
    <h1 class="text-3xl font-extrabold tracking-tight text-[#d8a735]">
        Ledger Statement
    </h1>
</div>

<!-- Mode Selector (Bapari vs Karigor) -->
<div class="flex items-center space-x-2 mb-6">
    <button type="button" id="btnTypeBapari" onclick="switchLedgerType('bapari')" class="flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-[#d8a735] text-slate-950 shadow-md">
        Bapari Ledger
    </button>
    <button type="button" id="btnTypeKarigor" onclick="switchLedgerType('karigor')" class="flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white">
        Karigor Ledger
    </button>
</div>

<form id="ledgerForm" method="GET" action="ledger.php" class="space-y-6">
    <!-- Select Bapari Dropdown -->
    <div id="bapariSelectGroup" class="relative">
        <select name="bapari_id" id="bapariId" class="searchable-select premium-input text-sm" placeholder="Select Bapari">
            <option value="">Select Bapari</option>
            <?php foreach ($baparis as $b): ?>
                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Select Karigor Dropdown (Hidden initially) -->
    <div id="karigorSelectGroup" class="relative hidden">
        <select name="karigor_id" id="karigorId" class="searchable-select premium-input text-sm" placeholder="Select Karigor">
            <option value="">Select Karigor</option>
            <?php foreach ($karigors as $k): ?>
                <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>


    <!-- Statement Filter (Input / Output: All vs Only Metal vs Only Kaam) -->
    <div>
        <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5 flex items-center justify-between">
            <span>Transaction Filter (Input / Output)</span>
            <span id="filterLabelDetail" class="text-[#d8a735] font-semibold text-[10px]">All Transactions</span>
        </label>
        <div class="grid grid-cols-3 gap-2">
            <button type="button" id="optFilterAll" onclick="setFilterType('all')" class="py-2.5 px-2 rounded-xl text-xs font-bold transition-all bg-[#d8a735] text-slate-950 shadow-md flex items-center justify-center text-center">
                All (Sab)
            </button>
            <button type="button" id="optFilterMetal" onclick="setFilterType('metal')" class="py-2.5 px-2 rounded-xl text-xs font-bold transition-all bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white flex items-center justify-center text-center">
                Only Metal
            </button>
            <button type="button" id="optFilterKaam" onclick="setFilterType('kaam')" class="py-2.5 px-2 rounded-xl text-xs font-bold transition-all bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white flex items-center justify-center text-center">
                Only Kaam
            </button>
        </div>
        <input type="hidden" name="filter_type" id="filterType" value="all">
    </div>

    <!-- From and To Date Pickers Side by Side -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">From Date</label>
            <input type="date" name="from" id="fromDate" class="premium-input text-sm">
        </div>
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1.5">To Date</label>
            <input type="date" name="to" id="toDate" class="premium-input text-sm">
        </div>
    </div>

    <!-- Action Buttons Row (View, Send Photo, PDF and CSV) -->
    <div class="space-y-3 pt-2">
        <button type="submit" class="w-full py-4 rounded-xl bg-[#d8a735] hover:bg-[#d8a735]/90 text-sm font-bold text-slate-950 flex items-center justify-center space-x-1.5 shadow-lg shadow-[#d8a735]/10 tap-target">
            <span class="material-symbols-rounded text-lg">visibility</span>
            <span>View Ledger Statement</span>
        </button>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
            <button type="button" onclick="generatePhoto()" class="w-full py-3 px-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-bold flex items-center justify-center space-x-1 shadow-lg shadow-emerald-900/30 tap-target">
                <span class="material-symbols-rounded text-base">photo_camera</span>
                <span>Send Photo</span>
            </button>

            <button type="button" onclick="generatePDF()" class="w-full py-3 px-2 rounded-xl border border-[#d8a735]/40 bg-transparent text-xs font-semibold text-[#d8a735] hover:bg-[#d8a735]/5 flex items-center justify-center space-x-1 tap-target">
                <span class="material-symbols-rounded text-base">picture_as_pdf</span>
                <span>PDF Print</span>
            </button>
            
            <button type="button" onclick="generateCSV()" class="w-full py-3 px-2 rounded-xl border border-white/10 bg-slate-900 text-xs font-semibold text-slate-300 hover:text-white flex items-center justify-center space-x-1 tap-target">
                <span class="material-symbols-rounded text-base">grid_view</span>
                <span>CSV Export</span>
            </button>
        </div>
    </div>
</form>

<script>
    let currentLedgerType = 'bapari';

    function switchLedgerType(type) {
        currentLedgerType = type;
        const form = document.getElementById('ledgerForm');
        const bGroup = document.getElementById('bapariSelectGroup');
        const kGroup = document.getElementById('karigorSelectGroup');
        const bBtn = document.getElementById('btnTypeBapari');
        const kBtn = document.getElementById('btnTypeKarigor');

        if (type === 'bapari') {
            form.action = 'ledger.php';
            bGroup.classList.remove('hidden');
            kGroup.classList.add('hidden');
            bBtn.className = "flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-[#d8a735] text-slate-950 shadow-md";
            kBtn.className = "flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white";
        } else {
            form.action = 'karigor_ledger.php';
            kGroup.classList.remove('hidden');
            bGroup.classList.add('hidden');
            kBtn.className = "flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-[#d8a735] text-slate-950 shadow-md";
            bBtn.className = "flex-1 py-3 rounded-xl text-xs font-bold transition-all bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white";
        }
    }

    let currentFilterType = 'all';

    function setFilterType(type) {
        currentFilterType = type;
        document.getElementById('filterType').value = type;

        const btnAll = document.getElementById('optFilterAll');
        const btnMetal = document.getElementById('optFilterMetal');
        const btnKaam = document.getElementById('optFilterKaam');
        const detail = document.getElementById('filterLabelDetail');

        const activeClass = "py-2.5 px-2 rounded-xl text-xs font-bold transition-all bg-[#d8a735] text-slate-950 shadow-md flex items-center justify-center text-center";
        const inactiveClass = "py-2.5 px-2 rounded-xl text-xs font-bold transition-all bg-slate-900 text-slate-400 border border-white/[0.04] hover:text-white flex items-center justify-center text-center";

        btnAll.className = inactiveClass;
        btnMetal.className = inactiveClass;
        btnKaam.className = inactiveClass;

        if (type === 'metal') {
            btnMetal.className = activeClass;
            detail.innerText = currentLedgerType === 'bapari' ? 'Only Metal (Inward / Jama)' : 'Only Metal (Material Issue)';
        } else if (type === 'kaam') {
            btnKaam.className = activeClass;
            detail.innerText = currentLedgerType === 'bapari' ? 'Only Kaam (Outward / Kaj)' : 'Only Kaam (Kaj Receive)';
        } else {
            btnAll.className = activeClass;
            detail.innerText = 'All Transactions';
        }
    }

    function generatePhoto() {
        const from = document.getElementById('fromDate').value;
        const to = document.getElementById('toDate').value;
        const filterType = document.getElementById('filterType').value;

        if (currentLedgerType === 'bapari') {
            const bapariId = document.getElementById('bapariId').value;
            if (!bapariId) {
                alert('Please select a Bapari first!');
                return;
            }
            let url = `ledger.php?bapari_id=${bapariId}&print=1&auto=photo`;
            if (from) url += `&from=${from}`;
            if (to) url += `&to=${to}`;
            if (filterType && filterType !== 'all') url += `&filter_type=${filterType}`;
            const w = window.open(url, '_blank');
            if (!w || w.closed || typeof w.closed === 'undefined') {
                window.location.href = url;
            } else {
                try { w.focus(); } catch (e) {}
            }
        } else {
            const karigorId = document.getElementById('karigorId').value;
            if (!karigorId) {
                alert('Please select a Karigor first!');
                return;
            }
            let url = `karigor_ledger.php?karigor_id=${karigorId}&print=1&auto=photo`;
            if (from) url += `&from=${from}`;
            if (to) url += `&to=${to}`;
            if (filterType && filterType !== 'all') url += `&filter_type=${filterType}`;
            const w = window.open(url, '_blank');
            if (!w || w.closed || typeof w.closed === 'undefined') {
                window.location.href = url;
            } else {
                try { w.focus(); } catch (e) {}
            }
        }
    }

    function generatePDF() {
        const from = document.getElementById('fromDate').value;
        const to = document.getElementById('toDate').value;
        const filterType = document.getElementById('filterType').value;

        if (currentLedgerType === 'bapari') {
            const bapariId = document.getElementById('bapariId').value;
            if (!bapariId) {
                alert('Please select a Bapari first!');
                return;
            }
            let url = `ledger.php?bapari_id=${bapariId}&print=1&auto=pdf`;
            if (from) url += `&from=${from}`;
            if (to) url += `&to=${to}`;
            if (filterType && filterType !== 'all') url += `&filter_type=${filterType}`;
            const w = window.open(url, '_blank');
            if (!w || w.closed || typeof w.closed === 'undefined') {
                window.location.href = url;
            } else {
                try { w.focus(); } catch (e) {}
            }
        } else {
            const karigorId = document.getElementById('karigorId').value;
            if (!karigorId) {
                alert('Please select a Karigor first!');
                return;
            }
            let url = `karigor_ledger.php?karigor_id=${karigorId}&print=1&auto=pdf`;
            if (from) url += `&from=${from}`;
            if (to) url += `&to=${to}`;
            if (filterType && filterType !== 'all') url += `&filter_type=${filterType}`;
            const w = window.open(url, '_blank');
            if (!w || w.closed || typeof w.closed === 'undefined') {
                window.location.href = url;
            } else {
                try { w.focus(); } catch (e) {}
            }
        }
    }

    function generateCSV() {
        const from = document.getElementById('fromDate').value;
        const to = document.getElementById('toDate').value;
        const filterType = document.getElementById('filterType').value;
        
        if (currentLedgerType === 'bapari') {
            const bapariId = document.getElementById('bapariId').value;
            if (!bapariId) {
                alert('Please select a Bapari first!');
                return;
            }
            let url = `reports.php?export=csv&bapari_id=${bapariId}`;
            if (from) url += `&from=${from}`;
            if (to) url += `&to=${to}`;
            if (filterType && filterType !== 'all') url += `&filter_type=${filterType}`;
            window.location.href = url;
        } else {
            const karigorId = document.getElementById('karigorId').value;
            if (!karigorId) {
                alert('Please select a Karigor first!');
                return;
            }
            let url = `reports.php?export=csv&karigor_id=${karigorId}`;
            if (from) url += `&from=${from}`;
            if (to) url += `&to=${to}`;
            if (filterType && filterType !== 'all') url += `&filter_type=${filterType}`;
            window.location.href = url;
        }
    }
</script>

<?php
require_once 'footer.php';
?>


