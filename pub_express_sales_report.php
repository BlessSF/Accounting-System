<?php
// ============================================================
//  pub_express_sales_report.php — Pub Express Branch Daily Sales Report
//  DINE IN detail rows + sub-section detail rows (Marketing
//  Pullout, GRAB, Expenses, Late Payment, Advance Payment,
//  GC Sponsorship, GC Sold) — totals feed Net Sales / Short/Over
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();

if (isBranch() && currentBranch() !== 'Pub Express') {
    header('Location: dashboard.php'); exit;
}

$pdo  = getPDO();
$user = currentUser();

// ── Main summary table (unchanged columns) ─────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `pub_express_sales_report` (
    `id`                    int(11) NOT NULL AUTO_INCREMENT,
    `store_name`            varchar(50) NOT NULL DEFAULT 'Pub Express',
    `report_date`           date NOT NULL,
    `gross_sales`           decimal(12,2) NOT NULL DEFAULT 0.00,
    `service_charge`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `z_reading_gross`       decimal(12,2) NOT NULL DEFAULT 0.00,
    `undeclared`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `total_swipe`           decimal(12,2) NOT NULL DEFAULT 0.00,
    `deposit_swipe_card`    decimal(12,2) NOT NULL DEFAULT 0.00,
    `late_payment_card`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `maya_swipe`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `unpaid_med_credit`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `grab_sales`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `gcash`                 decimal(12,2) NOT NULL DEFAULT 0.00,
    `gift_card`             decimal(12,2) NOT NULL DEFAULT 0.00,
    `marketing_pull_out`    decimal(12,2) NOT NULL DEFAULT 0.00,
    `discount`              decimal(12,2) NOT NULL DEFAULT 0.00,
    `bank_transfer_cheque`  decimal(12,2) NOT NULL DEFAULT 0.00,
    `pcf_expenses`          decimal(12,2) NOT NULL DEFAULT 0.00,
    `other_expenses`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `coh`                   decimal(12,2) NOT NULL DEFAULT 0.00,
    `net_sales`             decimal(12,2) NOT NULL DEFAULT 0.00,
    `short_over`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `saved_by`              varchar(100) DEFAULT NULL,
    `created_at`            timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at`            timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_store_date` (`store_name`,`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── DINE IN detail rows table ──────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `pub_express_dinein_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'Pub Express',
    `report_date`   date NOT NULL,
    `cash`          decimal(12,2) DEFAULT 0.00,
    `palawan_pay`   decimal(12,2) DEFAULT 0.00,
    `card_swipe_qr` decimal(12,2) DEFAULT 0.00,
    `unpaid_credit_name`   varchar(100) DEFAULT NULL,
    `unpaid_credit_amount` decimal(12,2) DEFAULT 0.00,
    `discount`      decimal(12,2) DEFAULT 0.00,
    `bank_transfer_cheque` decimal(12,2) DEFAULT 0.00,
    `cancelled_transactions` decimal(12,2) DEFAULT 0.00,
    `sort_order`    int(4) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── Sub-section detail rows table ─────────────────────────
// section: marketing_pullout | grab | expenses | late_payment |
//          advance_payment | gc_sponsorship | gc_sold
$pdo->exec("CREATE TABLE IF NOT EXISTS `pub_express_sales_detail_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'Pub Express',
    `report_date`   date NOT NULL,
    `section`       varchar(40) NOT NULL,
    `item_name`     varchar(150) DEFAULT NULL,
    `amount`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `sort_order`    int(4) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── AJAX: Save main summary ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    try {
        $reportDate = $_POST['report_date'] ?? date('Y-m-d');
        $NUM_COLS = ['gross_sales','service_charge','z_reading_gross','undeclared','total_swipe',
            'deposit_swipe_card','late_payment_card','maya_swipe','unpaid_med_credit',
            'grab_sales','gcash','gift_card','marketing_pull_out','discount',
            'bank_transfer_cheque','pcf_expenses','other_expenses','coh'];
        $vals = [];
        foreach ($NUM_COLS as $f) $vals[$f] = (float)($_POST[$f] ?? 0);

        $secSumStmt = $pdo->prepare("SELECT section, COALESCE(SUM(amount),0) AS total
            FROM pub_express_sales_detail_rows WHERE store_name='Pub Express' AND report_date=? GROUP BY section");
        $secSumStmt->execute([$reportDate]);
        $secSums = [];
        foreach ($secSumStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $secSums[$r['section']] = (float)$r['total'];
        $vals['grab_sales']         = $secSums['grab'] ?? 0;
        $vals['marketing_pull_out'] = $secSums['marketing_pullout'] ?? 0;
        $vals['pcf_expenses']       = $secSums['expenses'] ?? 0;
        $unpaidsVal                 = $secSums['unpaids'] ?? 0;

        // GROSS SALES = Declared + Undeclared + Walk-in/POS
        $vals['gross_sales'] = $vals['z_reading_gross'] + $vals['undeclared'] + $vals['total_swipe'];

        // DISCOUNTS = Walk-in/POS + Grab Discounts (Grab Discounts mirrors Grab Sales — see subChanged() in JS)
        $vals['discount'] = $vals['total_swipe'] + $vals['grab_sales'];

        // Net Sales = Gross - Marketing Pull-out - Unpaids - Grab - Discounts - Maya Card - Maya QR - QR PH - Expenses
        $netSales = $vals['gross_sales']
                  - $vals['marketing_pull_out']
                  - $unpaidsVal
                  - $vals['grab_sales']
                  - $vals['discount']
                  - $vals['maya_swipe']
                  - $vals['gcash']
                  - $vals['deposit_swipe_card']
                  - $vals['pcf_expenses'];
        $shortOver = $vals['coh'] - $netSales;

        $fields    = array_merge(['store_name','report_date'], $NUM_COLS, ['net_sales','short_over','saved_by']);
        $data      = array_merge(['Pub Express', $reportDate], array_values($vals), [$netSales, $shortOver, $user['name']]);
        $dupUpdate = implode(',', array_map(fn($f) => "`$f`=VALUES(`$f`)", array_merge($NUM_COLS, ['net_sales','short_over','saved_by'])));
        $sql = "INSERT INTO pub_express_sales_report (" . implode(',', array_map(fn($f)=>"`$f`",$fields)) . ")
                VALUES (" . implode(',', array_fill(0,count($fields),'?')) . ")
                ON DUPLICATE KEY UPDATE $dupUpdate";
        $pdo->prepare($sql)->execute($data);
        echo json_encode(['ok'=>true,'net_sales'=>$netSales,'short_over'=>$shortOver]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Save DINE IN rows ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_dinein'])) {
    header('Content-Type: application/json');
    try {
        $reportDate = $_POST['report_date'];
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        $pdo->prepare("DELETE FROM pub_express_dinein_rows WHERE store_name='Pub Express' AND report_date=?")->execute([$reportDate]);
        $ins = $pdo->prepare("INSERT INTO pub_express_dinein_rows (store_name,report_date,cash,palawan_pay,card_swipe_qr,unpaid_credit_name,unpaid_credit_amount,discount,bank_transfer_cheque,cancelled_transactions,sort_order) VALUES ('Pub Express',?,?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$reportDate, (float)($r['cash']??0), (float)($r['palawan_pay']??0),
                (float)($r['card_swipe_qr']??0), $r['unpaid_credit_name']??null,
                (float)($r['unpaid_credit_amount']??0), (float)($r['discount']??0),
                (float)($r['bank_transfer_cheque']??0), (float)($r['cancelled_transactions']??0), $i]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Save sub-section detail rows ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_detail'])) {
    header('Content-Type: application/json');
    try {
        $reportDate = $_POST['report_date'];
        $section    = $_POST['section'];
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        $pdo->prepare("DELETE FROM pub_express_sales_detail_rows WHERE store_name='Pub Express' AND report_date=? AND section=?")->execute([$reportDate,$section]);
        $ins = $pdo->prepare("INSERT INTO pub_express_sales_detail_rows (store_name,report_date,section,item_name,amount,sort_order) VALUES ('Pub Express',?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$reportDate, $section, $r['name']??null, (float)($r['amount']??0), $i]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── CSV Export ─────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    $reportDate = $_GET['date'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM pub_express_sales_report WHERE store_name='Pub Express' AND report_date=?");
    $stmt->execute([$reportDate]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $g = fn($k) => number_format((float)($r[$k] ?? 0), 2, '.', '');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Pub_Express_SalesReport_'.$reportDate.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['SUMMARY REPORT — Pub Express Branch', date('F j, Y', strtotime($reportDate))]);
    fputcsv($out, []);
    fputcsv($out, ['Gross Sales', $g('gross_sales')]);
    fputcsv($out, ['Service Charge', $g('service_charge')]);
    fputcsv($out, ['Z Reading Gross', $g('z_reading_gross')]);
    fputcsv($out, ['Undeclared', $g('undeclared')]);
    fputcsv($out, ['Total Swipe', $g('total_swipe')]);
    fputcsv($out, ['Deposit Swipe (Card)', $g('deposit_swipe_card')]);
    fputcsv($out, ['Late Payment (Card)', $g('late_payment_card')]);
    fputcsv($out, ['Sales of the day Swipe (MAYA)', $g('maya_swipe')]);
    fputcsv($out, ['Unpaid Med (Credit)', $g('unpaid_med_credit')]);
    fputcsv($out, ['Grab Sales', $g('grab_sales')]);
    fputcsv($out, ['G-Cash', $g('gcash')]);
    fputcsv($out, ['Gift Card', $g('gift_card')]);
    fputcsv($out, ['Marketing Pull Out', $g('marketing_pull_out')]);
    fputcsv($out, ['Discount', $g('discount')]);
    fputcsv($out, ['Bank Transfer/Cheque', $g('bank_transfer_cheque')]);
    fputcsv($out, ['PCF/Expenses', $g('pcf_expenses')]);
    fputcsv($out, ['Other Expenses', $g('other_expenses')]);
    fputcsv($out, ['Net Sales', $g('net_sales')]);
    fputcsv($out, ['COH (Cash on Hand)', $g('coh')]);
    fputcsv($out, ['Short/Over', $g('short_over')]);
    fclose($out); exit;
}

// ── Fetch data for display ─────────────────────────────────
$fDate = $_GET['date'] ?? date('Y-m-d');
$stmt  = $pdo->prepare("SELECT * FROM pub_express_sales_report WHERE store_name='Pub Express' AND report_date=?");
$stmt->execute([$fDate]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$v   = fn($k) => (float)($row[$k] ?? 0);
$fmt = fn($n)  => number_format((float)$n, 2);

// Fetch DINE IN rows
$diStmt = $pdo->prepare("SELECT * FROM pub_express_dinein_rows WHERE store_name='Pub Express' AND report_date=? ORDER BY sort_order ASC");
$diStmt->execute([$fDate]);
$dineinRows = $diStmt->fetchAll(PDO::FETCH_ASSOC);
$diSum = fn($col) => array_sum(array_column($dineinRows, $col));

// Fetch sub-section detail rows
$detailStmt = $pdo->prepare("SELECT * FROM pub_express_sales_detail_rows WHERE store_name='Pub Express' AND report_date=? ORDER BY section, sort_order ASC");
$detailStmt->execute([$fDate]);
$allDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
$details = [];
foreach ($allDetails as $d) $details[$d['section']][] = $d;
$secSum = fn($sec) => array_sum(array_column($details[$sec] ?? [], 'amount'));

$pageTitle  = 'Pub Express Sales Report';
$activePage = 'pub_express_sales_report';
include 'layout.php';
?>

<style>
/* ── Base layout ── */
.sr-page { font-family: var(--font-h, 'Inter', sans-serif); }
.sr-header-card {
  background: linear-gradient(135deg,#1e3060,#0f2045);
  border-radius:var(--radius,8px); padding:18px 24px;
  margin-bottom:16px; display:flex; align-items:center;
  justify-content:space-between; flex-wrap:wrap; gap:10px;
}
.sr-header-card .eyebrow { font-family:var(--font-m,'Inter',sans-serif); font-size:.56rem; text-transform:uppercase; letter-spacing:.14em; color:rgba(255,255,255,.45); margin-bottom:3px; }
.sr-header-card .title   { font-size:1.1rem; font-weight:800; color:#fff; }
.sr-header-card .subtitle{ font-size:.65rem; color:rgba(255,255,255,.5); margin-top:3px; }
.sr-controls { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
.toast { position:fixed; top:68px; right:22px; z-index:9999; max-width:320px; animation:fadeSlideDown .3s ease; }

/* ── Two-column layout ── */
.sr-two-col { display:grid; grid-template-columns:360px 1fr; gap:16px; margin-bottom:16px; }
@media(max-width:900px){ .sr-two-col { grid-template-columns:1fr; } }

/* ── Card wrapper ── */
.sr-card {
  background:#fff; border:1px solid #dde3ee;
  border-radius:8px; overflow:hidden;
  box-shadow:0 2px 10px rgba(0,0,0,.07);
}
.sr-card-title {
  padding:9px 14px; font-size:.65rem; font-weight:800;
  text-transform:uppercase; letter-spacing:.08em;
  color:#fff; background:#1a3a8a;
}
.sr-card-title.green  { background:#1b5e20; }
.sr-card-title.orange { background:#e65100; }
.sr-card-title.red    { background:#7b1a1a; }
.sr-card-title.gold   { background:#827717; }

/* ── Denomination table ── */
.denom-table { width:100%; border-collapse:collapse; font-size:.75rem; }
.denom-table th {
  background:#1a3a8a; color:#fff;
  padding:7px 10px; font-size:.62rem; text-transform:uppercase;
  letter-spacing:.05em; border:1px solid #1230a0; text-align:center;
}
.denom-table td { padding:5px 8px; border:1px solid #e5e9f0; text-align:right; }
.denom-table td:first-child { text-align:center; }
.denom-table td.denom-label { text-align:right; color:#555; font-weight:500; background:#f9fafb; }
.denom-table tfoot td { background:#f5c542; font-weight:800; border:1px solid #d4a017; font-size:.78rem; }
.denom-table tfoot td.total-lbl { background:#1a3a8a; color:#fff; text-align:left; }
.denom-inp { width:60px; border:1px solid #dde; background:#fafafa; border-radius:3px; padding:3px 6px; font-size:.74rem; text-align:center; font-family:var(--font-m,'Inter',sans-serif); outline:none; }
.denom-inp:focus { background:#fffbeb; border-color:#f5c542; }
.denom-total-cell { font-family:var(--font-m,'Inter',sans-serif); font-weight:700; }

/* ── Sub-section (right col) ── */
.sub-sections { display:flex; flex-direction:column; gap:12px; }
.sub-sec-card { background:#fff; border:1px solid #dde3ee; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.sub-sec-title {
  padding:8px 14px; font-size:.63rem; font-weight:800;
  text-transform:uppercase; letter-spacing:.08em; color:#fff;
  display:flex; align-items:center; justify-content:space-between;
}
.sub-sec-title.blue   { background:#1a3a8a; }
.sub-sec-title.gold   { background:#b8860b; }
.sub-sec-title.red    { background:#7b1a1a; }
.sub-sec-hdr { display:grid; grid-template-columns:1fr 120px 30px; background:#d9d9d9; border-bottom:1px solid #bbb; }
.sub-sec-hdr span { padding:5px 8px; font-size:.58rem; font-weight:700; text-transform:uppercase; color:#444; border-right:1px solid #ccc; }
.sub-sec-hdr span:last-child { border-right:none; }
.sub-sec-rows { max-height:180px; overflow-y:auto; }
.sub-sec-row { display:grid; grid-template-columns:1fr 120px 30px; border-bottom:1px solid #f0f2f5; align-items:center; }
.sub-sec-row:hover { background:#fafbfc; }
.sub-inp { width:100%; border:1px solid #e0e0e0; background:#fafafa; border-radius:4px; font-size:.73rem; padding:5px 7px; outline:none; }
.sub-inp.num { text-align:right; }
.sub-inp:focus { background:#fffbeb; border-color:#f5c542; }
.sub-sec-footer { display:grid; grid-template-columns:1fr 120px; background:#f5c542; border-top:2px solid #d4a017; }
.sub-sec-footer span { padding:6px 10px; font-size:.72rem; font-weight:800; border-right:1px solid #d4a017; font-family:var(--font-m,'Inter',sans-serif); }
.sub-sec-footer span:last-child { text-align:right; border-right:none; }
.btn-add-sub { margin:6px 10px; padding:3px 10px; background:#1a4d1a; color:#fff; border:none; border-radius:5px; font-size:.65rem; font-weight:700; cursor:pointer; }
.btn-add-sub:hover { background:#155231; }
.btn-del-sub { background:#fee2e2; border:none; color:#991b1b; border-radius:4px; padding:2px 5px; font-size:.62rem; cursor:pointer; margin:2px; }

/* ── Summary Report ── */
.sr-summary { max-width:700px; margin:0 auto 20px; }
.sr-sum-card { background:#fff; border:1px solid #dde3ee; border-radius:8px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.07); }
.sr-sum-title { background:#2e7d32; padding:13px 28px; text-align:center; font-size:.95rem; font-weight:800; color:#fff; letter-spacing:.06em; text-transform:uppercase; font-family:var(--font-m,'Inter',sans-serif); }
.sr-row { display:flex; align-items:center; padding:8px 22px; border-bottom:1px solid #f0f2f5; min-height:42px; gap:10px; }
.sr-row:last-child { border-bottom:none; }
.sr-row.green    { background:#2e7d32; }
.sr-row.green .sr-lbl { color:#fff; }
.sr-row.blue-sub { background:#1a3a8a; }
.sr-row.blue-sub .sr-lbl { color:#fff; font-weight:700; }
.sr-row.gray-hdr { background:#d9d9d9; }
.sr-row.gray-hdr .sr-lbl { color:#333; font-weight:700; }
.sr-row.pink-val { background:#fce4ec; }
.sr-row.net      { background:#f5c542; }
.sr-row.net .sr-lbl { color:#3e2f00; font-weight:800; font-style:italic; }
.sr-row.coh-row  { background:#1e3060; }
.sr-row.coh-row .sr-lbl { color:#fff; font-weight:800; font-style:italic; }
.sr-row.short-row { background:#e53935; }
.sr-row.short-row .sr-lbl { color:#fff; font-weight:800; }
.sr-row.divider { background:#f0f4ff; padding:4px 22px; }
.sr-lbl { flex:1; font-size:.82rem; color:#1a1d23; font-weight:600; text-align:right; padding-right:14px; }
.sr-note { font-size:.65rem; color:rgba(255,255,255,.65); white-space:nowrap; font-style:italic; }
.sr-note.dark { color:#777; }
.sr-val-wrap { width:155px; flex-shrink:0; }
.sr-inp {
  width:100%; padding:6px 10px; text-align:center;
  font-family:var(--font-m,'Inter',sans-serif); font-size:.82rem; font-weight:700;
  color:#1a1d23; background:#fff; border:1px solid #dde; border-radius:6px;
  outline:none; transition:border-color .15s;
}
.sr-inp:focus { border-color:#1a3a8a; box-shadow:0 0 0 3px rgba(26,58,138,.08); }
.sr-inp.ro { background:rgba(255,255,255,.9); cursor:default; border-color:transparent; font-size:.9rem; font-weight:800; }
.sr-row.net   .sr-inp.ro { background:#fff; color:#7a5c00; }
.sr-row.coh-row .sr-inp  { background:#fff; }
.sr-row.short-row .sr-inp.ro { background:#fff; color:#b71c1c; }
.sr-row.green .sr-inp.ro { color:#1b5e20; }

/* indented sub-rows */
.sr-row.sub-row { padding-left:40px; background:#f5f8ff; }
.sr-row.sub-row .sr-lbl { color:#374151; font-weight:500; }
</style>

<!-- Page header -->
<div class="sr-page">
<div class="sr-header-card">
  <div>
    <div class="eyebrow">Pub Express Branch · Sales</div>
    <div class="title">Daily Sales Report</div>
    <div class="subtitle">Denomination count · Marketing Pull-out · Unpaids · Expenses · Full Summary</div>
  </div>
  <span style="background:rgba(255,255,255,.12);color:#fff;padding:5px 14px;border-radius:20px;font-size:.63rem;font-weight:600">📌 Pub Express</span>
</div>

<!-- Controls -->
<div class="sr-controls">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($fDate) ?>" onchange="this.form.submit()">
    <button type="button" class="btn btn-primary" onclick="saveAll()">💾 Save All</button>
    <a href="pub_express_sales_report.php?export_csv=1&date=<?= htmlspecialchars($fDate) ?>" class="btn btn-ghost">⬇ Download CSV</a>
    <span id="saveStatus" style="font-size:.72rem;color:var(--subtext)"></span>
  </form>
</div>

<!-- ══════ TWO-COLUMN: Denomination  |  Right sub-sections ══════ -->
<div class="sr-two-col">

  <!-- LEFT: Cash denomination count -->
  <div class="sr-card">
    <div class="sr-card-title">💵 Cash Count – Denomination</div>
    <table class="denom-table" id="denom-table">
      <thead>
        <tr>
          <th>QTY</th>
          <th>DENOMINATION</th>
          <th>TOTAL COLLECTION</th>
        </tr>
      </thead>
      <tbody id="denom-body">
        <?php
        $denominations = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.25, 0.10];
        $denomData = [];
        foreach (($details['denomination'] ?? []) as $d) {
            $denomData[(string)(float)$d['item_name']] = (float)$d['amount'];
        }
        foreach ($denominations as $denom):
            $qty = $denomData[(string)$denom] ?? 0;
            $tot = $qty * $denom;
        ?>
        <tr data-denom="<?= $denom ?>">
          <td><input class="denom-inp" type="number" min="0" step="1" value="<?= $qty ?: '' ?>" placeholder="0" oninput="denomChanged()"></td>
          <td class="denom-label"><?= number_format($denom, $denom < 1 ? 2 : 0) ?></td>
          <td class="denom-total-cell" id="denom-tot-<?= str_replace('.','_',$denom) ?>"><?= $tot > 0 ? number_format($tot,2) : '0.00' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="total-lbl" colspan="2">TOTAL</td>
          <td id="denom-grand-total" style="text-align:right"><?= number_format(array_sum(array_map(fn($d)=>($denomData[(string)$d]??0)*$d,$denominations)),2) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- RIGHT: Marketing Pull-out, Unpaids, Expenses -->
  <div class="sub-sections">

    <!-- Marketing Pull-out -->
    <div class="sub-sec-card">
      <div class="sub-sec-title blue">
        <span>📋 Marketing Pull-out</span>
        <button class="btn-add-sub" onclick="addSubRow('marketing_pullout')">+ Add</button>
      </div>
      <div class="sub-sec-hdr"><span>Name</span><span>Amount</span><span></span></div>
      <div class="sub-sec-rows" id="rows-marketing_pullout">
        <?php foreach (($details['marketing_pullout'] ?? [['item_name'=>'','amount'=>0]]) as $sr): ?>
        <div class="sub-sec-row">
          <input class="sub-inp" type="text" placeholder="Name…" value="<?= htmlspecialchars($sr['item_name']??'') ?>" oninput="subChanged('marketing_pullout')">
          <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" value="<?= (float)$sr['amount'] ?: '' ?>" oninput="subChanged('marketing_pullout')">
          <button class="btn-del-sub" onclick="delSubRow(this,'marketing_pullout')">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="sub-sec-footer">
        <span><em>Total</em></span>
        <span id="sub-tot-marketing_pullout"><?= number_format($secSum('marketing_pullout'),2) ?></span>
      </div>
    </div>

    <!-- Unpaids -->
    <div class="sub-sec-card">
      <div class="sub-sec-title gold">
        <span>📄 Unpaids</span>
        <button class="btn-add-sub" onclick="addSubRow('unpaids')">+ Add</button>
      </div>
      <div class="sub-sec-hdr"><span>Name</span><span>Amount</span><span></span></div>
      <div class="sub-sec-rows" id="rows-unpaids">
        <?php foreach (($details['unpaids'] ?? [['item_name'=>'','amount'=>0]]) as $sr): ?>
        <div class="sub-sec-row">
          <input class="sub-inp" type="text" placeholder="Name…" value="<?= htmlspecialchars($sr['item_name']??'') ?>" oninput="subChanged('unpaids')">
          <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" value="<?= (float)$sr['amount'] ?: '' ?>" oninput="subChanged('unpaids')">
          <button class="btn-del-sub" onclick="delSubRow(this,'unpaids')">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="sub-sec-footer">
        <span><em>Total</em></span>
        <span id="sub-tot-unpaids"><?= number_format($secSum('unpaids'),2) ?></span>
      </div>
    </div>

    <!-- Expenses -->
    <div class="sub-sec-card">
      <div class="sub-sec-title red">
        <span>💸 Expenses</span>
        <button class="btn-add-sub" onclick="addSubRow('expenses')">+ Add</button>
      </div>
      <div class="sub-sec-hdr"><span>Particular</span><span>Amount</span><span></span></div>
      <div class="sub-sec-rows" id="rows-expenses">
        <?php foreach (($details['expenses'] ?? [['item_name'=>'','amount'=>0]]) as $sr): ?>
        <div class="sub-sec-row">
          <input class="sub-inp" type="text" placeholder="Item…" value="<?= htmlspecialchars($sr['item_name']??'') ?>" oninput="subChanged('expenses')">
          <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" value="<?= (float)$sr['amount'] ?: '' ?>" oninput="subChanged('expenses')">
          <button class="btn-del-sub" onclick="delSubRow(this,'expenses')">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="sub-sec-footer">
        <span><em>Total</em></span>
        <span id="sub-tot-expenses"><?= number_format($secSum('expenses'),2) ?></span>
      </div>
    </div>

  </div><!-- /sub-sections -->
</div><!-- /sr-two-col -->

<!-- ══════ SUMMARY REPORT ══════ -->
<?php
// Compute initial values for summary
$declaredCalc   = $v('z_reading_gross');
$undeclaredCalc = $row && isset($row['undeclared']) ? $v('undeclared') : ($v('gross_sales') - $v('z_reading_gross'));
$grabCalcV      = $secSum('grab');
$walkInPosCalc  = $v('total_swipe'); // manual — Walk-in / POS
$grabDiscCalc   = $grabCalcV;

// GROSS SALES = Declared + Undeclared + Walk-in / POS  (read-only, auto)
$grossSalesCalc = $declaredCalc + $undeclaredCalc + $walkInPosCalc;
// DISCOUNTS = Walk-in / POS + Grab Discounts  (read-only, auto)
$discountsCalc  = $walkInPosCalc + $grabDiscCalc;

$mayaCardCalc   = $v('maya_swipe');
$mayaQrCalc     = $v('gcash');
$qrPhCalc       = $v('deposit_swipe_card');
$marketingCalcV = $secSum('marketing_pullout');
$unpaidsCalcV   = $secSum('unpaids');
$expensesCalcV  = $secSum('expenses');
$totalTcCalc    = $v('coh');            // from denomination
$declaredTcCalc = $v('coh');
$netSalesCalc   = $grossSalesCalc - $marketingCalcV - $unpaidsCalcV - $grabCalcV - $discountsCalc - $mayaCardCalc - $mayaQrCalc - $qrPhCalc - $expensesCalcV;
$cohCalc        = array_sum(array_map(fn($d)=>($denomData[(string)$d]??0)*$d,$denominations));
$shortOverCalc  = $cohCalc - $netSalesCalc;
?>
<div class="sr-summary">
<div class="sr-sum-card">
  <div class="sr-sum-title">Summary Report</div>

  <!-- Date -->
  <div class="sr-row" style="background:#f5f8ff">
    <div class="sr-lbl" style="text-align:left">For</div>
    <div class="sr-val-wrap"><span class="sr-inp ro" style="display:block;background:#eef2ff;border-radius:6px;text-align:center;padding:6px 10px"><?= date('n/j/Y', strtotime($fDate)) ?></span></div>
  </div>

  <!-- Gross Sales -->
  <div class="sr-row green">
    <div class="sr-lbl">GROSS SALES <span style="font-size:.62rem;color:#999">(auto: Declared + Undeclared + Walk-in/POS)</span></div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="gross_sales" value="<?= $fmt($grossSalesCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <!-- Declared -->
  <div class="sr-row sub-row">
    <div class="sr-lbl">Declared</div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="declared" value="<?= $v('z_reading_gross') ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
    <div class="sr-note dark">(Z Reading)</div>
  </div>
  <!-- Undeclared (editable, defaults to Gross − Declared until overridden) -->
  <div class="sr-row sub-row">
    <div class="sr-lbl">Undeclared <span style="font-size:.62rem;color:#999">(editable)</span></div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="undeclared"
         value="<?= $fmt($undeclaredCalc) ?>"
         placeholder="0.00" oninput="recalc()"></div>
  </div>
  <!-- Grab Sales (auto) -->
  <div class="sr-row sub-row">
    <div class="sr-lbl">Grab Sales <span style="font-size:.62rem;color:#999">(auto from Grab section)</span></div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="grab_sales" value="<?= $fmt($grabCalcV) ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>
  <!-- Discounts -->
  <div class="sr-row sub-row">
    <div class="sr-lbl">Discounts <span style="font-size:.62rem;color:#999">(auto: Walk-in/POS + Grab Discounts)</span></div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="discount" value="<?= $fmt($discountsCalc) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Divider -->
  <div class="sr-row divider"><div style="font-size:.6rem;color:#8899cc;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Payment Channels</div></div>

  <!-- Walk-in / POS -->
  <div class="sr-row gray-hdr">
    <div class="sr-lbl">Walk-in / POS</div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="walkin_pos" value="<?= $v('total_swipe') ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>
  <!-- Grab Discounts (sub) -->
  <div class="sr-row sub-row">
    <div class="sr-lbl">Grab Discounts <span style="font-size:.62rem;color:#999">(auto from Grab)</span></div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="grab_discounts" value="<?= $fmt($grabCalcV) ?>" readonly tabindex="-1"></div>
  </div>
  <!-- Maya Card -->
  <div class="sr-row gray-hdr">
    <div class="sr-lbl">Maya Card</div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="maya_card" value="<?= $v('maya_swipe') ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>
  <!-- Maya QR -->
  <div class="sr-row gray-hdr">
    <div class="sr-lbl">Maya QR</div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="maya_qr" value="<?= $v('gcash') ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>
  <!-- QR PH -->
  <div class="sr-row gray-hdr">
    <div class="sr-lbl">QR PH</div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="qr_ph" value="<?= $v('deposit_swipe_card') ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>

  <!-- Divider -->
  <div class="sr-row divider"><div style="font-size:.6rem;color:#8899cc;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Deductions</div></div>

  <!-- Marketing Pull-out (auto) -->
  <div class="sr-row pink-val">
    <div class="sr-lbl">Marketing Pull-out <span style="font-size:.62rem;color:#999">(auto)</span></div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="marketing_pull_out" value="<?= $fmt($marketingCalcV) ?>" readonly tabindex="-1"></div>
  </div>
  <!-- Unpaids (auto) -->
  <div class="sr-row pink-val">
    <div class="sr-lbl">Unpaids <span style="font-size:.62rem;color:#999">(auto)</span></div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="unpaids" value="<?= $fmt($unpaidsCalcV) ?>" readonly tabindex="-1"></div>
  </div>
  <!-- Expenses (auto) -->
  <div class="sr-row pink-val">
    <div class="sr-lbl">Expenses <span style="font-size:.62rem;color:#999">(auto)</span></div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="pcf_expenses" value="<?= $fmt($expensesCalcV) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Divider -->
  <div class="sr-row divider"></div>

  <!-- Total TC -->
  <div class="sr-row" style="background:#e8f5e9">
    <div class="sr-lbl" style="font-weight:800;color:#1b5e20">Total TC</div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="total_tc" value="<?= $v('coh') ?: $fmt($cohCalc) ?>" readonly tabindex="-1" style="color:#1b5e20;font-size:.92rem"></div>
    <div class="sr-note dark">(from denomination count)</div>
  </div>
  <!-- Declared TC -->
  <div class="sr-row" style="background:#fff9c4">
    <div class="sr-lbl" style="font-weight:700;color:#3e2f00">Declared TC</div>
    <div class="sr-val-wrap"><input type="number" step="0.01" class="sr-inp" id="declared_tc" value="<?= $v('coh') ?: '' ?>" placeholder="0.00" oninput="recalc()" style="border:2px dashed #f5c542"></div>
  </div>

  <!-- Divider -->
  <div class="sr-row divider"></div>

  <!-- Net Sales (auto) -->
  <div class="sr-row net">
    <div class="sr-lbl">Net Sales</div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="net_sales" value="<?= $fmt($netSalesCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <!-- COH -->
  <div class="sr-row coh-row">
    <div class="sr-lbl">COH (Cash on Hand) <span class="sr-note">(denomination total)</span></div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="coh" value="<?= $fmt($cohCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <!-- Short/Over -->
  <div class="sr-row short-row">
    <div class="sr-lbl">Short / Over</div>
    <div class="sr-val-wrap"><input type="text" class="sr-inp ro" id="short_over" value="<?= $fmt($shortOverCalc) ?>" readonly tabindex="-1"></div>
    <div class="sr-note">(if "−" sign means short)</div>
  </div>

</div>
</div><!-- /sr-summary -->
</div><!-- /sr-page -->

<script>
const FDATE = '<?= $fDate ?>';
const fmt   = n => Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const gv    = id => parseFloat(String(document.getElementById(id)?.value??'').replace(/,/g,''))||0;
const sv    = (id,v) => { const e=document.getElementById(id); if(e) e.value=v; };

// ── Denomination ─────────────────────────────────────────
const DENOMS = [1000,500,200,100,50,20,10,5,1,0.25,0.10];
function denomChanged() {
  let grand = 0;
  document.querySelectorAll('#denom-body tr[data-denom]').forEach(tr => {
    const denom = parseFloat(tr.dataset.denom);
    const qty   = parseFloat(tr.querySelector('input').value) || 0;
    const tot   = qty * denom;
    const key   = String(denom).replace('.','_');
    const cell  = document.getElementById('denom-tot-'+key);
    if (cell) cell.textContent = fmt(tot);
    grand += tot;
  });
  document.getElementById('denom-grand-total').textContent = fmt(grand);
  sv('coh', fmt(grand));
  recalc();
}

// ── Sub-sections (right col) ──────────────────────────────
function subChanged(sec) {
  const container = document.getElementById('rows-'+sec);
  let tot = 0;
  container.querySelectorAll('input[type=number]').forEach(i => tot += parseFloat(i.value)||0);
  const el = document.getElementById('sub-tot-'+sec);
  if (el) el.textContent = fmt(tot);
  // Feed summary
  if (sec==='marketing_pullout') { sv('marketing_pull_out', fmt(tot)); recalc(); }
  if (sec==='unpaids')           { sv('unpaids', fmt(tot)); recalc(); }
  if (sec==='expenses')          { sv('pcf_expenses', fmt(tot)); recalc(); }
  if (sec==='grab')              { sv('grab_sales', fmt(tot)); sv('grab_discounts',fmt(tot)); recalc(); }
}

function addSubRow(sec) {
  const container = document.getElementById('rows-'+sec);
  const div = document.createElement('div');
  div.className = 'sub-sec-row';
  div.innerHTML = `
    <input class="sub-inp" type="text" placeholder="Name…" oninput="subChanged('${sec}')">
    <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" oninput="subChanged('${sec}')">
    <button class="btn-del-sub" onclick="delSubRow(this,'${sec}')">✕</button>`;
  container.appendChild(div);
}

function delSubRow(btn, sec) {
  btn.closest('.sub-sec-row').remove();
  subChanged(sec);
}

// ── Summary recalc ────────────────────────────────────────
function recalc() {
  const declared   = gv('declared');
  const undeclared = gv('undeclared');
  const walkin     = gv('walkin_pos');
  const grab       = gv('grab_sales');
  const grabDisc   = gv('grab_discounts');
  const mayaCard   = gv('maya_card');
  const mayaQr     = gv('maya_qr');
  const qrPh       = gv('qr_ph');
  const marketing  = gv('marketing_pull_out');
  const unpaids    = gv('unpaids');
  const expenses   = gv('pcf_expenses');
  const coh        = gv('coh');

  // GROSS SALES = Declared + Undeclared + Walk-in/POS
  const gross = declared + undeclared + walkin;
  sv('gross_sales', fmt(gross));

  // DISCOUNTS = Walk-in/POS + Grab Discounts
  const discount = walkin + grabDisc;
  sv('discount', fmt(discount));

  // Net Sales = Gross - Marketing Pull-out - Unpaids - Grab - Discounts - Maya Card - Maya QR - QR PH - Expenses
  const netSales = gross - marketing - unpaids - grab - discount - mayaCard - mayaQr - qrPh - expenses;
  const shortOver = coh - netSales;

  sv('net_sales',  fmt(netSales));
  sv('total_tc', fmt(gv('declared_tc')));
  sv('short_over', fmt(shortOver));

  const soEl = document.getElementById('short_over');
  if (soEl) soEl.style.color = shortOver < 0 ? '#ffd54f' : '#b71c1c';
}

// ── Save All ──────────────────────────────────────────────
async function saveAll() {
  const status = document.getElementById('saveStatus');
  status.textContent = 'Saving…';

  // 1. Save denomination as sub-section rows
  const denomRows = [];
  document.querySelectorAll('#denom-body tr[data-denom]').forEach(tr => {
    const qty = parseFloat(tr.querySelector('input').value)||0;
    if (qty > 0) denomRows.push({ name: tr.dataset.denom, amount: qty });
  });
  const fd0 = new FormData();
  fd0.append('ajax_save_detail','1'); fd0.append('report_date',FDATE);
  fd0.append('section','denomination'); fd0.append('rows',JSON.stringify(denomRows));
  await fetch('pub_express_sales_report.php',{method:'POST',body:fd0});

  // 2. Save each right-col sub-section
  for (const sec of ['marketing_pullout','unpaids','expenses','grab']) {
    const container = document.getElementById('rows-'+sec);
    if (!container) continue;
    const rows = [];
    container.querySelectorAll('.sub-sec-row').forEach(row => {
      const inps = row.querySelectorAll('input');
      rows.push({ name: inps[0]?.value||'', amount: parseFloat(inps[1]?.value)||0 });
    });
    const fd = new FormData();
    fd.append('ajax_save_detail','1'); fd.append('report_date',FDATE);
    fd.append('section',sec); fd.append('rows',JSON.stringify(rows));
    await fetch('pub_express_sales_report.php',{method:'POST',body:fd});
  }

  // 3. Save main summary
  const fd3 = new FormData();
  fd3.append('ajax_save','1'); fd3.append('report_date',FDATE);
  const fieldMap = {
    gross_sales: 'gross_sales', z_reading_gross: 'declared', undeclared: 'undeclared',
    total_swipe: 'walkin_pos', maya_swipe: 'maya_card',
    gcash: 'maya_qr', deposit_swipe_card: 'qr_ph',
    grab_sales: 'grab_sales', marketing_pull_out: 'marketing_pull_out',
    discount: 'discount', pcf_expenses: 'pcf_expenses',
    coh: 'coh', net_sales: 'net_sales', declared_tc: 'declared_tc',
    late_payment_card: 'walkin_pos', service_charge: 'service_charge',
  };
  for (const [dbField, domId] of Object.entries(fieldMap)) {
    fd3.append(dbField, gv(domId));
  }
  fd3.append('other_expenses', 0);
  const res  = await fetch('pub_express_sales_report.php',{method:'POST',body:fd3});
  const data = await res.json();
  if (data.ok) {
    status.textContent='✓ Saved'; status.style.color='var(--accent)';
    showToast('✓ Sales report saved','success');
  } else {
    showToast('❌ '+(data.msg||'Error'),'error'); status.textContent='❌ Error';
  }
  setTimeout(()=>{status.textContent='';},4000);
}

function showToast(msg,type) {
  const t=document.createElement('div');
  t.className='flash flash-'+(type||'success')+' toast';
  t.textContent=msg;
  document.body.appendChild(t);
  setTimeout(()=>t.remove(),4000);
}

document.addEventListener('DOMContentLoaded',()=>{ denomChanged(); recalc(); });
</script>
</body>
</html>