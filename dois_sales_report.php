<?php
// ============================================================
//  dois_sales_report.php — Dois Branch Daily Sales Report
//  DINE IN detail rows + sub-section detail rows (Marketing
//  Pullout, GRAB, Expenses, Late Payment, Advance Payment,
//  GC Sponsorship, GC Sold) — totals feed Net Sales / Short/Over
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();

if (isBranch() && currentBranch() !== 'Dois') {
    header('Location: dashboard.php'); exit;
}

$pdo  = getPDO();
$user = currentUser();

// ── Main summary table (unchanged columns) ─────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `dois_sales_report` (
    `id`                    int(11) NOT NULL AUTO_INCREMENT,
    `store_name`            varchar(50) NOT NULL DEFAULT 'Dois',
    `report_date`           date NOT NULL,
    `gross_sales`           decimal(12,2) NOT NULL DEFAULT 0.00,
    `service_charge`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `z_reading_gross`       decimal(12,2) NOT NULL DEFAULT 0.00,
    `total_swipe`           decimal(12,2) NOT NULL DEFAULT 0.00,
    `deposit_swipe_card`    decimal(12,2) NOT NULL DEFAULT 0.00,
    `late_payment_card`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `maya_swipe`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `unpaid_med_credit`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `grab_sales`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `gcash`                 decimal(12,2) NOT NULL DEFAULT 0.00,
    `gift_card`             decimal(12,2) NOT NULL DEFAULT 0.00,
    `marketing_pull_out`    decimal(12,2) NOT NULL DEFAULT 0.00,
    `paid_med`              decimal(12,2) NOT NULL DEFAULT 0.00,
    `advance_paid`          decimal(12,2) NOT NULL DEFAULT 0.00,
    `gc_sold`               decimal(12,2) NOT NULL DEFAULT 0.00,
    `gc_availed`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `personal_withdrawal`   decimal(12,2) NOT NULL DEFAULT 0.00,
    `discount`              decimal(12,2) NOT NULL DEFAULT 0.00,
    `bank_transfer_cheque`  decimal(12,2) NOT NULL DEFAULT 0.00,
    `pcf_expenses`          decimal(12,2) NOT NULL DEFAULT 0.00,
    `other_expenses`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `coh`                   decimal(12,2) NOT NULL DEFAULT 0.00,
    `net_sales`             decimal(12,2) NOT NULL DEFAULT 0.00,
    `short_over`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `saved_by`              varchar(100) DEFAULT NULL,
    `cashier_name`          varchar(150) DEFAULT NULL,
    `created_at`            timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at`            timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_store_date` (`store_name`,`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

try { $pdo->exec("ALTER TABLE `dois_sales_report` ADD COLUMN `cashier_name` varchar(150) DEFAULT NULL"); }
catch (Throwable $ignored) {}
foreach (['paid_med','advance_paid','gc_sold','gc_availed','personal_withdrawal'] as $col) {
    try { $pdo->exec("ALTER TABLE `dois_sales_report` ADD COLUMN `$col` decimal(12,2) NOT NULL DEFAULT 0.00"); }
    catch (Throwable $ignored) {}
}

// ── DINE IN detail rows table ──────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `dois_dinein_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'Dois',
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
$pdo->exec("CREATE TABLE IF NOT EXISTS `dois_sales_detail_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'Dois',
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
        $NUM_COLS = ['gross_sales','service_charge','z_reading_gross','total_swipe',
            'deposit_swipe_card','late_payment_card','maya_swipe','unpaid_med_credit',
            'grab_sales','gcash','gift_card','marketing_pull_out','discount',
            'bank_transfer_cheque','pcf_expenses','other_expenses','coh',
            'paid_med','advance_paid','gc_sold','gc_availed','personal_withdrawal'];
        $vals = [];
        foreach ($NUM_COLS as $f) $vals[$f] = (float)($_POST[$f] ?? 0);

        // Authoritative DINE IN sums (server recomputes, never trusts the client)
        $diSumStmt = $pdo->prepare("SELECT
                COALESCE(SUM(cash),0)                 AS cash,
                COALESCE(SUM(palawan_pay),0)           AS gcash,
                COALESCE(SUM(bank_transfer_cheque),0)  AS bank_transfer_cheque,
                COALESCE(SUM(unpaid_credit_amount),0)  AS gc_agogo,
                COALESCE(SUM(card_swipe_qr),0)         AS card_swipe,
                COALESCE(SUM(discount),0)              AS discount
            FROM dois_dinein_rows WHERE store_name='Dois' AND report_date=?");
        $diSumStmt->execute([$reportDate]);
        $di = $diSumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $cardSwipeTotal = (float)($di['card_swipe'] ?? 0);
        $vals['gcash']                = (float)($di['gcash'] ?? 0);
        $vals['bank_transfer_cheque'] = (float)($di['bank_transfer_cheque'] ?? 0);
        $vals['discount']             = (float)($di['discount'] ?? 0);
        $vals['maya_swipe']           = $cardSwipeTotal;
        // Total Swipe is now a manual input (user-editable) — not overridden here.

        // Total Dine-in = Cash + Gcash + Bank Transfer + Gift Cert (AGODA) + Card Swipe + Discount
        $totalDineIn = (float)($di['cash'] ?? 0) + $vals['gcash'] + $vals['bank_transfer_cheque']
                     + (float)($di['gc_agogo'] ?? 0) + $cardSwipeTotal + $vals['discount'];

        // Authoritative sub-section sums
        $secSumStmt = $pdo->prepare("SELECT section, COALESCE(SUM(amount),0) AS total
            FROM dois_sales_detail_rows WHERE store_name='Dois' AND report_date=? GROUP BY section");
        $secSumStmt->execute([$reportDate]);
        $secSums = [];
        foreach ($secSumStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $secSums[$r['section']] = (float)$r['total'];
        $vals['grab_sales']         = $secSums['grab'] ?? 0;
        $vals['deposit_swipe_card'] = $secSums['deposit'] ?? 0;
        $vals['late_payment_card']  = $secSums['late_payment'] ?? 0;
        $vals['unpaid_med_credit']  = $secSums['unpaid_med'] ?? 0;
        $vals['paid_med']           = $secSums['paid_med'] ?? 0;
        $vals['advance_paid']       = $secSums['advance_paid'] ?? 0;
        $vals['marketing_pull_out'] = $secSums['marketing_pullout'] ?? 0;
        $vals['gc_sold']            = $secSums['gc_sold'] ?? 0;
        $vals['gc_availed']         = $secSums['gc_availed'] ?? 0;

        // Gross Sales = Grab + G-Cash + Gift Cert Sold + Advance Paid + Paid Med + Unpaid Med
        //             + Total Dine-in - Service Charge
        $vals['gross_sales']     = $vals['grab_sales'] + $vals['gcash'] + $vals['gc_sold']
                                  + $vals['advance_paid'] + $vals['paid_med'] + $vals['unpaid_med_credit']
                                  + $totalDineIn - $vals['service_charge'];
        $vals['z_reading_gross'] = $vals['gross_sales'];

        // Net Sales = Gross Sales less every deduction line on the summary sheet.
        // NOTE: subtract today's card-swipe dine-in sales (cardSwipeTotal /
        // maya_swipe), NOT the combined `total_swipe` field — that field also
        // folds in Deposit Swipe + Late Payment, which are separate
        // bank-deposit-slip reconciliation items and must not be deducted
        // again here, or Short/Over comes out wrong by that amount.
        $netSales = $vals['gross_sales']
                  - $cardSwipeTotal
                  - $vals['unpaid_med_credit']
                  - $vals['paid_med']
                  - $vals['advance_paid']
                  - $vals['marketing_pull_out']
                  - $vals['grab_sales']
                  - $vals['gcash']
                  - $vals['gc_sold']
                  - $vals['gc_availed']
                  - $vals['gift_card']
                  - $vals['bank_transfer_cheque']
                  - $vals['discount']
                  - $vals['pcf_expenses']
                  - $vals['other_expenses']
                  - $vals['personal_withdrawal'];
        $shortOver = $vals['coh'] - $netSales;

        $fields    = array_merge(['store_name','report_date'], $NUM_COLS, ['net_sales','short_over','saved_by','cashier_name']);
        $data      = array_merge(['Dois', $reportDate], array_values($vals), [$netSales, $shortOver, $user['name'], $_POST['cashier_name'] ?? null]);
        $dupUpdate = implode(',', array_map(fn($f) => "`$f`=VALUES(`$f`)", array_merge($NUM_COLS, ['net_sales','short_over','saved_by','cashier_name'])));
        $sql = "INSERT INTO dois_sales_report (" . implode(',', array_map(fn($f)=>"`$f`",$fields)) . ")
                VALUES (" . implode(',', array_fill(0,count($fields),'?')) . ")
                ON DUPLICATE KEY UPDATE $dupUpdate";
        $pdo->prepare($sql)->execute($data);

        // ── Auto-sync PCF/Expenses & Other Expenses into the Expenses ledger ──
        // Keeps one auto-generated row per date in dois_expenses (tagged
        // voucher_no='AUTO-SR') matching the two manual fields on this page,
        // so staff never have to re-type them separately on the Expenses page.
        try { $pdo->exec("ALTER TABLE `dois_expenses` ADD COLUMN `pcf_expenses` decimal(12,2) DEFAULT 0.00 AFTER `miscellaneous`"); }
        catch (Throwable $ignored) {}

        $pcfVal   = $vals['pcf_expenses'];
        $otherVal = $vals['other_expenses'];
        $autoRow  = $pdo->prepare("SELECT id FROM dois_expenses WHERE expense_date=? AND voucher_no='AUTO-SR' LIMIT 1");
        $autoRow->execute([$reportDate]);
        $autoId = $autoRow->fetchColumn();

        if ($pcfVal == 0 && $otherVal == 0) {
            // Nothing to sync — remove a stale auto-row if one exists so
            // the ledger doesn't keep a leftover entry from an earlier save.
            if ($autoId) $pdo->prepare("DELETE FROM dois_expenses WHERE id=?")->execute([$autoId]);
        } else {
            $rowTotal = $pcfVal + $otherVal;
            if ($autoId) {
                $pdo->prepare("UPDATE dois_expenses SET pcf_expenses=?, miscellaneous=?, row_total=?, particulars=?, saved_by=? WHERE id=?")
                    ->execute([$pcfVal, $otherVal, $rowTotal, 'PCF/Expenses & Other Expenses (auto from Sales Report)', $user['name'], $autoId]);
            } else {
                $pdo->prepare("INSERT INTO dois_expenses (expense_date, voucher_no, particulars, pcf_expenses, miscellaneous, row_total, saved_by) VALUES (?,'AUTO-SR',?,?,?,?,?)")
                    ->execute([$reportDate, 'PCF/Expenses & Other Expenses (auto from Sales Report)', $pcfVal, $otherVal, $rowTotal, $user['name']]);
            }
        }

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
        $pdo->prepare("DELETE FROM dois_dinein_rows WHERE store_name='Dois' AND report_date=?")->execute([$reportDate]);
        $ins = $pdo->prepare("INSERT INTO dois_dinein_rows (store_name,report_date,cash,palawan_pay,card_swipe_qr,unpaid_credit_name,unpaid_credit_amount,discount,bank_transfer_cheque,sort_order) VALUES ('Dois',?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$reportDate, (float)($r['cash']??0), (float)($r['palawan_pay']??0),
                (float)($r['card_swipe_qr']??0), $r['unpaid_credit_name']??null,
                (float)($r['unpaid_credit_amount']??0), (float)($r['discount']??0),
                (float)($r['bank_transfer_cheque']??0), $i]);
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
        $pdo->prepare("DELETE FROM dois_sales_detail_rows WHERE store_name='Dois' AND report_date=? AND section=?")->execute([$reportDate,$section]);
        $ins = $pdo->prepare("INSERT INTO dois_sales_detail_rows (store_name,report_date,section,item_name,amount,sort_order) VALUES ('Dois',?,?,?,?,?)");
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
    $stmt = $pdo->prepare("SELECT * FROM dois_sales_report WHERE store_name='Dois' AND report_date=?");
    $stmt->execute([$reportDate]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $g = fn($k) => number_format((float)($r[$k] ?? 0), 2, '.', '');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Dois_SalesReport_'.$reportDate.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['SUMMARY REPORT — Dois Branch', date('F j, Y', strtotime($reportDate))]);
    fputcsv($out, []);
    fputcsv($out, ['Gross Sales', $g('gross_sales')]);
    fputcsv($out, ['Service Charge', $g('service_charge')]);
    fputcsv($out, ['Z Reading Gross', $g('z_reading_gross')]);
    fputcsv($out, ['Total Swipe', $g('total_swipe')]);
    fputcsv($out, ['Deposit Swipe (Card)', $g('deposit_swipe_card')]);
    fputcsv($out, ['Late Payment (Card)', $g('late_payment_card')]);
    fputcsv($out, ['Sales of the day Swipe (MAYA)', $g('maya_swipe')]);
    fputcsv($out, ['Unpaid Med (Credit)', $g('unpaid_med_credit')]);
    fputcsv($out, ['Paid Med/Corp.', $g('paid_med')]);
    fputcsv($out, ['Advance Paid (Appigo)', $g('advance_paid')]);
    fputcsv($out, ['Grab Sales', $g('grab_sales')]);
    fputcsv($out, ['G-Cash', $g('gcash')]);
    fputcsv($out, ['Gift Cert. Sold', $g('gc_sold')]);
    fputcsv($out, ['Gift Cert. Availed', $g('gc_availed')]);
    fputcsv($out, ['Gift Card', $g('gift_card')]);
    fputcsv($out, ['Marketing Pull Out', $g('marketing_pull_out')]);
    fputcsv($out, ['Discount', $g('discount')]);
    fputcsv($out, ['Bank Transfer/Cheque', $g('bank_transfer_cheque')]);
    fputcsv($out, ['Personal Withdrawal', $g('personal_withdrawal')]);
    fputcsv($out, ['PCF/Expenses', $g('pcf_expenses')]);
    fputcsv($out, ['Other Expenses', $g('other_expenses')]);
    fputcsv($out, ['Net Sales', $g('net_sales')]);
    fputcsv($out, ['COH (Cash on Hand)', $g('coh')]);
    fputcsv($out, ['Short/Over', $g('short_over')]);
    fclose($out); exit;
}

// ── Fetch data for display ─────────────────────────────────
$fDate = $_GET['date'] ?? date('Y-m-d');
$stmt  = $pdo->prepare("SELECT * FROM dois_sales_report WHERE store_name='Dois' AND report_date=?");
$stmt->execute([$fDate]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$v   = fn($k) => (float)($row[$k] ?? 0);
$fmt = fn($n)  => number_format((float)$n, 2);

// Fetch DINE IN rows
$diStmt = $pdo->prepare("SELECT * FROM dois_dinein_rows WHERE store_name='Dois' AND report_date=? ORDER BY sort_order ASC");
$diStmt->execute([$fDate]);
$dineinRows = $diStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch sub-section detail rows
$detailStmt = $pdo->prepare("SELECT * FROM dois_sales_detail_rows WHERE store_name='Dois' AND report_date=? ORDER BY section, sort_order ASC");
$detailStmt->execute([$fDate]);
$allDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
$details = [];
foreach ($allDetails as $d) $details[$d['section']][] = $d;

$pageTitle  = 'Dois Sales Report';
$activePage = 'dois_sales_report';
include 'layout.php';
?>
<style>
/* ────────────────────────────────────────────────────────────
   DOIS SALES REPORT – styles matching Excel layout
──────────────────────────────────────────────────────────── */
.sr-page { font-family:var(--font-h,'Inter',sans-serif); }

.sr-header-card {
  background:linear-gradient(135deg,#1e3060,#0f2045);
  border-radius:var(--radius,8px); padding:18px 24px;
  margin-bottom:16px; display:flex; align-items:center;
  justify-content:space-between; flex-wrap:wrap; gap:10px;
}
.sr-header-card .eyebrow { font-size:.56rem; text-transform:uppercase; letter-spacing:.14em; color:rgba(255,255,255,.45); margin-bottom:3px; }
.sr-header-card .title   { font-size:1.1rem; font-weight:800; color:#fff; }
.sr-header-card .subtitle{ font-size:.64rem; color:rgba(255,255,255,.5); margin-top:3px; }
.sr-controls { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
.toast { position:fixed; top:68px; right:22px; z-index:9999; max-width:320px; animation:fadeSlideDown .3s ease; }

/* ── 3-col sub-section grid (top area) ── */
.sub-grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:0; border:1px solid #ccc; border-radius:8px; overflow:hidden; margin-bottom:14px; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.07); }
.sub-grid-3 .sub-col { border-right:1px solid #ddd; }
.sub-grid-3 .sub-col:last-child { border-right:none; }

/* column title bar */
.sub-col-title {
  padding:8px 12px; font-size:.65rem; font-weight:800;
  text-transform:uppercase; letter-spacing:.07em; color:#fff; text-align:center;
}
.sub-col-title.orange  { background:#E65100; }
.sub-col-title.pink    { background:#c2185b; }
.sub-col-title.appigo  { background:#9c27b0; }
.sub-col-title.green   { background:#2e7d32; }
.sub-col-title.gcred   { background:#d32f2f; }
.sub-col-title.gccard  { background:#388e3c; }

/* column header row */
.sub-col-hdr { display:grid; grid-template-columns:1fr 100px 26px; background:#eee; border-bottom:1px solid #ccc; }
.sub-col-hdr span { padding:4px 7px; font-size:.58rem; font-weight:700; text-transform:uppercase; color:#444; border-right:1px solid #ccc; }
.sub-col-hdr span:last-child { border-right:none; }

/* data rows */
.sub-data-row { display:grid; grid-template-columns:1fr 100px 26px; border-bottom:1px solid #f0f2f5; align-items:center; min-height:30px; }
.sub-data-row:hover { background:#fafbfc; }
.sub-inp { width:100%; border:1px solid #e0e0e0; background:#fafafa; border-radius:3px; font-size:.73rem; padding:4px 6px; outline:none; }
.sub-inp.num { text-align:right; }
.sub-inp:focus { background:#fffbeb; border-color:#f5c542; }
.sub-footer-row { display:grid; grid-template-columns:1fr 100px; background:#f5c542; border-top:2px solid #d4a017; }
.sub-footer-row span { padding:5px 8px; font-size:.72rem; font-weight:800; border-right:1px solid #d4a017; font-family:var(--font-m,'Inter',sans-serif); }
.sub-footer-row span:last-child { text-align:right; border-right:none; }
.btn-add-sub { margin:5px 8px; padding:3px 9px; background:#1a4d1a; color:#fff; border:none; border-radius:4px; font-size:.63rem; font-weight:700; cursor:pointer; }
.btn-add-sub:hover { background:#155231; }
.btn-del-sub { background:#fee2e2; border:none; color:#991b1b; border-radius:3px; padding:2px 4px; font-size:.6rem; cursor:pointer; }

/* ── Dine-in table ── */
.dinein-wrap { background:#fff; border:1px solid #ccc; border-radius:8px; overflow:hidden; margin-bottom:14px; box-shadow:0 2px 10px rgba(0,0,0,.07); }
.dinein-title { background:#1a3a8a; color:#fff; padding:9px 14px; font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; }
.di-table { width:100%; border-collapse:collapse; font-size:.74rem; }
.di-table th { background:#d32f2f; color:#fff; padding:7px 8px; font-size:.6rem; text-transform:uppercase; letter-spacing:.05em; text-align:center; border:1px solid #b71c1c; white-space:nowrap; }
.di-table th.sub2 { background:#555; font-size:.57rem; }
.di-table td { padding:4px 6px; border:1px solid #e5e9f0; vertical-align:middle; }
.di-table tfoot td { background:#f5c542; font-weight:800; border:1px solid #d4a017; font-size:.76rem; padding:6px 8px; }
.di-table tfoot td.lbl { background:#1a3a8a; color:#fff; }
.di-table tfoot td.grand { background:#1a3a8a; color:#fff; font-weight:800; }
.di-inp { width:100%; border:none; background:transparent; font-family:var(--font-m,'Inter',sans-serif); font-size:.73rem; text-align:right; outline:none; }
.di-inp:focus { background:#fffbeb; border-radius:2px; }
.di-inp.txt { text-align:left; }
.btn-add-di { margin:6px 10px; padding:3px 10px; background:#1a4d1a; color:#fff; border:none; border-radius:4px; font-size:.65rem; font-weight:700; cursor:pointer; }
.btn-del-di { background:#fee2e2; border:none; color:#991b1b; border-radius:3px; padding:2px 5px; font-size:.62rem; cursor:pointer; }

/* ── Summary card ── */
.summary-wrap { max-width:640px; margin:0 auto 20px; }
.summary-card { background:#fff; border:1px solid #ccc; border-radius:8px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
.summary-title { background:#2e7d32; padding:13px 24px; text-align:center; font-size:.92rem; font-weight:800; color:#fff; letter-spacing:.06em; text-transform:uppercase; }
.sr-row { display:flex; align-items:center; padding:8px 20px; border-bottom:1px solid #f0f2f5; min-height:40px; gap:8px; }
.sr-row:last-child { border-bottom:none; }
.sr-row.orange-hdr  { background:#E65100; }
.sr-row.orange-hdr .sr-lbl { color:#fff; font-weight:800; }
.sr-row.orange-sub  { background:#FF7043; }
.sr-row.orange-sub .sr-lbl { color:#fff; font-weight:600; font-size:.78rem; }
.sr-row.green-row   { background:#2e7d32; }
.sr-row.green-row .sr-lbl { color:#fff; font-weight:700; }
.sr-row.white-row   { background:#fff; }
.sr-row.pink-row    { background:#fce4ec; }
.sr-row.gray-row    { background:#f0f0f0; }
.sr-row.net-row     { background:#f5c542; }
.sr-row.net-row .sr-lbl { color:#3e2f00; font-weight:800; font-style:italic; }
.sr-row.coh-row     { background:#1e3060; }
.sr-row.coh-row .sr-lbl { color:#fff; font-weight:800; font-style:italic; }
.sr-row.short-row   { background:#e53935; }
.sr-row.short-row .sr-lbl { color:#fff; font-weight:800; }
.sr-row.remarks-row { background:#f9f9f9; min-height:52px; align-items:flex-start; padding-top:10px; }
.sr-lbl { flex:1; font-size:.82rem; color:#1a1d23; font-weight:600; text-align:right; padding-right:12px; }
.sr-note { font-size:.63rem; color:rgba(255,255,255,.65); white-space:nowrap; font-style:italic; }
.sr-note.dk { color:#888; }
.sr-val { width:150px; flex-shrink:0; }
.sr-inp { width:100%; padding:6px 10px; text-align:center; font-family:var(--font-m,'Inter',sans-serif); font-size:.82rem; font-weight:700; color:#1a1d23; background:#fff; border:1px solid #dde; border-radius:5px; outline:none; }
.sr-inp:focus { border-color:#1a3a8a; box-shadow:0 0 0 2px rgba(26,58,138,.1); }
.sr-inp.ro { background:rgba(255,255,255,.9); cursor:default; border-color:transparent; font-size:.88rem; font-weight:800; }
.sr-row.net-row .sr-inp.ro { background:#fff; color:#7a5c00; }
.sr-row.coh-row .sr-inp   { background:#fff; }
.sr-row.short-row .sr-inp.ro { background:#fff; }
.sr-inp.remarks-inp { text-align:left; font-weight:400; font-size:.78rem; resize:vertical; }
</style>

<!-- ── PAGE HEADER ── -->
<div class="sr-page">
<div class="sr-header-card">
  <div>
    <div class="eyebrow">Dois Branch · Sales</div>
    <div class="title">Daily Sales Report</div>
    <div class="subtitle">Grab · Deposit · Late Payment · Unpaids · Paid Med · Advance Paid · Marketing Pullout · GC · Dine-in · Summary</div>
  </div>
  <span style="background:rgba(255,255,255,.12);color:#fff;padding:5px 14px;border-radius:20px;font-size:.63rem;font-weight:600">📌 Dois</span>
</div>

<!-- Controls -->
<div class="sr-controls">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($fDate) ?>" onchange="this.form.submit()">
    <label for="cashier_name" style="font-size:.72rem;font-weight:600;color:var(--subtext);white-space:nowrap;margin-left:8px">Cashier Name</label>
    <input type="text" class="form-control" id="cashier_name" value="<?= htmlspecialchars($row['cashier_name'] ?? '') ?>" placeholder="Enter cashier name…" style="width:280px">
    <button type="button" class="btn btn-primary" onclick="saveAll()">💾 Save All</button>
    <a href="dois_sales_report.php?export_csv=1&date=<?= htmlspecialchars($fDate) ?>" class="btn btn-ghost">⬇ Download CSV</a>
    <span id="saveStatus" style="font-size:.72rem;color:var(--subtext)"></span>
  </form>
</div>

<?php
// Helper: section rows or default empty row
$secRows = fn($sec) => $details[$sec] ?? [['item_name'=>'','amount'=>0]];
$secTot  = fn($sec) => array_sum(array_column($details[$sec] ?? [], 'amount'));

// Sub-section renderer
function renderSubCol(string $sec, string $label, string $titleClass, array $rows, float $total): string {
    $h  = "<div class=\"sub-col\" data-section=\"{$sec}\">";
    $h .= "<div class=\"sub-col-title {$titleClass}\">{$label}</div>";
    $h .= "<div class=\"sub-col-hdr\"><span>Particular</span><span>Amount</span><span></span></div>";
    $h .= "<div class=\"sub-data-rows\" id=\"rows-{$sec}\">";
    foreach ($rows as $r) {
        $name = htmlspecialchars($r['item_name'] ?? '');
        $amt  = (float)$r['amount'] ?: '';
        $h .= "<div class=\"sub-data-row\">
            <input class=\"sub-inp\" type=\"text\" placeholder=\"Name…\" value=\"{$name}\" oninput=\"subChanged('{$sec}')\">
            <input class=\"sub-inp num\" type=\"number\" step=\"0.01\" placeholder=\"0.00\" value=\"{$amt}\" oninput=\"subChanged('{$sec}')\">
            <button class=\"btn-del-sub\" onclick=\"delSubRow(this,'{$sec}')\">✕</button>
          </div>";
    }
    $h .= "</div>";
    $h .= "<button class=\"btn-add-sub\" onclick=\"addSubRow('{$sec}')\">+ Add</button>";
    $h .= "<div class=\"sub-footer-row\"><span><em>Total</em></span><span id=\"sub-tot-{$sec}\">" . number_format($total,2) . "</span></div>";
    $h .= "</div>";
    return $h;
}
?>

<!-- ══════ ROW 1: Grab | Deposit | Late Payment ══════ -->
<div class="sub-grid-3">
  <?= renderSubCol('grab',         'Grab',         'orange', $secRows('grab'),         $secTot('grab')) ?>
  <?= renderSubCol('deposit',      'Deposit',      'orange', $secRows('deposit'),      $secTot('deposit')) ?>
  <?= renderSubCol('late_payment', 'Late Payment', 'orange', $secRows('late_payment'), $secTot('late_payment')) ?>
</div>

<!-- ══════ ROW 2: UnpaidMed/Corp (CREDIT) | Paid Med/Corp | Advance Paid (APPIGO) ══════ -->
<div class="sub-grid-3">
  <?= renderSubCol('unpaid_med',     'UnpaidMed/Corp. (CREDIT)', 'pink',   $secRows('unpaid_med'),   $secTot('unpaid_med')) ?>
  <?= renderSubCol('paid_med',       'Paid Med/Corp.',            'pink',   $secRows('paid_med'),     $secTot('paid_med')) ?>
  <?= renderSubCol('advance_paid',   'Advance Paid (APPIGO)',     'appigo', $secRows('advance_paid'), $secTot('advance_paid')) ?>
</div>

<!-- ══════ ROW 3: Marketing Pullout | Sold Gift Cert (TIPS) | Availed/Bought GC (GIFT CARD) ══════ -->
<div class="sub-grid-3">
  <?= renderSubCol('marketing_pullout', 'Marketing Pullout',              'orange', $secRows('marketing_pullout'), $secTot('marketing_pullout')) ?>
  <?= renderSubCol('gc_sold',           'Sold Gift Cert (TIPS)',          'gcred',  $secRows('gc_sold'),           $secTot('gc_sold')) ?>
  <?= renderSubCol('gc_availed',        'Availed Bought GC (GIFT CARD)',  'gccard', $secRows('gc_availed'),        $secTot('gc_availed')) ?>
</div>

<!-- ══════ DINE-IN TABLE ══════ -->
<?php
$diSum = fn($col) => array_sum(array_column($dineinRows, $col));
$diCash     = $diSum('cash');
$diGcash    = $diSum('palawan_pay');   // G-Cash mapped to palawan_pay column
$diBankTx   = $diSum('bank_transfer_cheque');
$diGcAgoGo  = $diSum('unpaid_credit_amount'); // Gift Cert (AGOGO) → unpaid col
$diCardSwipe= $diSum('card_swipe_qr');
$diDiscount = $diSum('discount');
$diGrandTotal = $diCash + $diGcash + $diBankTx + $diGcAgoGo + $diCardSwipe + $diDiscount;
?>
<div class="dinein-wrap">
  <div class="dinein-title">🍽️ Dine-in</div>
  <div style="overflow-x:auto">
  <table class="di-table" id="di-table">
    <thead>
      <tr>
        <th>Cash</th>
        <th>Gcash</th>
        <th>Bank Transfer</th>
        <th>Gift Cert.<br><small>(AGODA)</small></th>
        <th>Card Swipe</th>
        <th>Discount</th>
        <th style="width:32px"></th>
      </tr>
    </thead>
    <tbody id="di-body">
      <?php if (empty($dineinRows)): ?>
      <tr>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><button class="btn-del-di" onclick="delDiRow(this)">✕</button></td>
      </tr>
      <?php else: foreach ($dineinRows as $dr): ?>
      <tr>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['cash'] ?: '' ?>" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['palawan_pay'] ?: '' ?>" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['bank_transfer_cheque'] ?: '' ?>" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['unpaid_credit_amount'] ?: '' ?>" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['card_swipe_qr'] ?: '' ?>" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['discount'] ?: '' ?>" oninput="diChanged()"></td>
        <td><button class="btn-del-di" onclick="delDiRow(this)">✕</button></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td id="di-tot-cash"><?= number_format($diCash,2) ?></td>
        <td id="di-tot-gcash"><?= number_format($diGcash,2) ?></td>
        <td id="di-tot-bank"><?= number_format($diBankTx,2) ?></td>
        <td id="di-tot-gcagogo"><?= number_format($diGcAgoGo,2) ?></td>
        <td id="di-tot-card"><?= number_format($diCardSwipe,2) ?></td>
        <td id="di-tot-disc"><?= number_format($diDiscount,2) ?></td>
        <td></td>
      </tr>
      <tr>
        <td class="lbl" colspan="2">TOTAL</td>
        <td class="grand" colspan="5" id="di-grand-total"><?= number_format($diGrandTotal,2) ?></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <button class="btn-add-di" onclick="addDiRow()">+ Add Row</button>
</div>

<!-- ══════ SUMMARY REPORT ══════ -->
<?php
$grabAmt     = $secTot('grab');
$depositAmt  = $secTot('deposit');
$latePayAmt  = $secTot('late_payment');
$unpaidAmt   = $secTot('unpaid_med');
$paidMedAmt  = $secTot('paid_med');
$advPaidAmt  = $secTot('advance_paid');
$mktPullAmt  = $secTot('marketing_pullout');
$gcSoldAmt   = $secTot('gc_sold');
$gcAvailAmt  = $secTot('gc_availed');

// Gross Sales = Grab + G-Cash + Gift Cert Sold + Advance Paid + Paid Med + Unpaid Med
//             + Total Dine-in - Service Charge
$scCalc     = $v('service_charge');
$grossCalc  = $grabAmt + $diGcash + $gcSoldAmt + $advPaidAmt + $paidMedAmt + $unpaidAmt
            + $diGrandTotal - $scCalc;
// Total Swipe is editable, but defaults to Card Swipe (dine-in) + Deposit
// + Late Payment. JS re-syncs this on load too (see updateTotalSwipeDefault),
// this server-side value just matches it for the very first paint.
$totalSwipe = $diCardSwipe + $depositAmt + $latePayAmt;
// Sales of day Swipe = card swipe from dine-in
$sodSwipe   = $diCardSwipe;
// CA/Payroll — manual
$caPayroll  = $v('gift_card'); // reusing gift_card DB col for CA/Payroll
// Discount — from dine-in
$discCalc   = $diDiscount;
// PCF/Expenses — manual
$pcfCalc    = $v('pcf_expenses');
// Other Expenses — manual
$otherExp   = $v('other_expenses');
// Personal Withdrawal — manual
$persWithCalc = $v('personal_withdrawal');

$netSalesCalc = $grossCalc
              - $sodSwipe
              - $unpaidAmt
              - $paidMedAmt
              - $advPaidAmt
              - $mktPullAmt
              - $grabAmt
              - $gcAvailAmt
              - $gcSoldAmt
              - $caPayroll
              - $diBankTx
              - $discCalc
              - $pcfCalc
              - $otherExp
              - $persWithCalc;
$cohCalc     = $v('coh');
$shortCalc   = $cohCalc - $netSalesCalc;
?>
<div class="summary-wrap">
<div class="summary-card">
  <div class="summary-title">Sales Report Summary</div>

  <!-- Date -->
  <div class="sr-row white-row">
    <div class="sr-lbl" style="text-align:left">Date</div>
    <div class="sr-val"><span class="sr-inp ro" style="display:block;background:#eef2ff;text-align:center;padding:6px 10px;border-radius:5px"><?= date('n/j/Y', strtotime($fDate)) ?></span></div>
  </div>

  <!-- Gross Sales (auto from Dine-in) -->
  <div class="sr-row orange-hdr">
    <div class="sr-lbl">Gross Sales <span style="font-size:.62rem;opacity:.8">(auto from Dine-in)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="gross_sales" value="<?= number_format($grossCalc,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Service Charge -->
  <div class="sr-row orange-sub">
    <div class="sr-lbl">SERVICE CHARGE</div>
    <div class="sr-val"><input type="number" step="0.01" class="sr-inp" id="service_charge" value="<?= $scCalc ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
    <div class="sr-note">(separate deposit slip)</div>
  </div>

  <!-- Total Swipe (manual, defaults to Card Swipe) -->
  <div class="sr-row orange-hdr">
    <div class="sr-lbl">TOTAL SWIPE <span style="font-size:.62rem;opacity:.8">(editable, default: Card Swipe)</span></div>
    <div class="sr-val"><input type="number" step="0.01" class="sr-inp" id="total_swipe" value="<?= $totalSwipe ?: '' ?>" placeholder="0.00" oninput="totalSwipeManuallyEdited=true; recalc();"></div>
  </div>

  <!-- Deposit Swipe sub-row -->
  <div class="sr-row gray-row">
    <div class="sr-lbl">Deposit Swipe <span style="font-size:.62rem;color:#777">(auto from Deposit)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="deposit_swipe_card" value="<?= number_format($depositAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Late Payment sub-row -->
  <div class="sr-row gray-row">
    <div class="sr-lbl">Late Payment <span style="font-size:.62rem;color:#777">(auto from Late Payment)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="late_payment_card" value="<?= number_format($latePayAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Sales of the Day Swipe (auto from Card Swipe col) -->
  <div class="sr-row orange-sub">
    <div class="sr-lbl">Sales of the day Swipe: <span style="font-size:.62rem;opacity:.85">(auto: Card Swipe)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="maya_swipe" value="<?= number_format($sodSwipe,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Unpaid Med (auto) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Unpaid Med <span style="font-size:.62rem;color:#999">(auto from UnpaidMed/Corp)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="unpaid_med_credit" value="<?= number_format($unpaidAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Paid Med (auto) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Paid Med <span style="font-size:.62rem;color:#999">(auto from Paid Med/Corp)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="paid_med" value="<?= number_format($paidMedAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Advance Paid (auto) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Advance Paid <span style="font-size:.62rem;color:#999">(auto from Advance Paid)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="advance_paid" value="<?= number_format($advPaidAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Marketing Pullout (auto) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Marketing Pullout <span style="font-size:.62rem;color:#999">(auto)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="marketing_pull_out" value="<?= number_format($mktPullAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Grab Sales (auto) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Grab Sales: <span style="font-size:.62rem;color:#999">(auto from Grab)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="grab_sales" value="<?= number_format($grabAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- G-Cash (auto from Dine-in Gcash col) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">G-Cash <span style="font-size:.62rem;color:#999">(auto from Dine-in Gcash)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="gcash" value="<?= number_format($diGcash,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Gift Cert. Sold (auto from GC Sold) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Gift Cert. (Sold) <span style="font-size:.62rem;color:#999">(auto from Sold GC)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="gc_sold" value="<?= number_format($gcSoldAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Gift Cert. Sponsorship (auto from GC Availed) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Gift Cert. (Sponsorship) <span style="font-size:.62rem;color:#999">(auto from Availed/Bought GC)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="gc_availed" value="<?= number_format($gcAvailAmt,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Bank Transfer (auto from Dine-in) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Bank Transfer <span style="font-size:.62rem;color:#999">(auto from Dine-in)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="bank_transfer_cheque" value="<?= number_format($diBankTx,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Personal Withdrawal — manual -->
  <div class="sr-row white-row">
    <div class="sr-lbl">Personal Withdrawal</div>
    <div class="sr-val"><input type="number" step="0.01" class="sr-inp" id="personal_withdrawal" value="<?= $persWithCalc ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>

  <!-- CA/Payroll — manual -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">CA/Payroll</div>
    <div class="sr-val"><input type="number" step="0.01" class="sr-inp" id="gift_card" value="<?= $caPayroll ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>

  <!-- Discount (auto from Dine-in) -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">Discount <span style="font-size:.62rem;color:#999">(auto from Dine-in Discount col)</span></div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="discount" value="<?= number_format($discCalc,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- PCF/Expenses — manual -->
  <div class="sr-row pink-row">
    <div class="sr-lbl">PCF/Expenses</div>
    <div class="sr-val"><input type="number" step="0.01" class="sr-inp" id="pcf_expenses" value="<?= $pcfCalc ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>

  <!-- Other Expenses — manual -->
  <div class="sr-row white-row">
    <div class="sr-lbl">Other Expenses</div>
    <div class="sr-val"><input type="number" step="0.01" class="sr-inp" id="other_expenses" value="<?= $otherExp ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>

  <!-- Net Sales (auto) -->
  <div class="sr-row net-row">
    <div class="sr-lbl">Net Sales</div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="net_sales" value="<?= number_format($netSalesCalc,2) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- COH -->
  <div class="sr-row coh-row">
    <div class="sr-lbl">COH (Cash on Hand) <span class="sr-note">(cash from dine-in)</span></div>
    <div class="sr-val"><input type="number" step="0.01" class="sr-inp" id="coh" value="<?= $cohCalc ?: '' ?>" placeholder="0.00" oninput="recalc()"></div>
  </div>

  <!-- Short/Over -->
  <div class="sr-row short-row">
    <div class="sr-lbl">Short/Over</div>
    <div class="sr-val"><input type="text" class="sr-inp ro" id="short_over" value="<?= number_format($shortCalc,2) ?>" readonly tabindex="-1"></div>
    <div class="sr-note">(if "−" sign means short)</div>
  </div>

  <!-- Remarks -->
  <div class="sr-row remarks-row">
    <div class="sr-lbl" style="align-self:flex-start;padding-top:4px">Remarks:</div>
    <div class="sr-val" style="width:300px"><textarea class="sr-inp remarks-inp" id="remarks" rows="2" placeholder="Enter remarks…" style="width:100%"><?= htmlspecialchars($row['saved_by'] ?? '') ?></textarea></div>
  </div>

</div><!-- /summary-card -->
</div><!-- /summary-wrap -->
</div><!-- /sr-page -->

<script>
const FDATE = '<?= $fDate ?>';
const fmt   = n => Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const flt   = v => parseFloat(String(v??'').replace(/,/g,''))||0;
const gv    = id => flt(document.getElementById(id)?.value);
const sv    = (id,v) => { const e=document.getElementById(id); if(e) e.value=v; };

// Total Swipe = Card Swipe (Dine-in) + Deposit Swipe + Late Payment.
// It stays editable (cashier can type the actual terminal total), but as
// long as nobody has typed into it directly, it auto-tracks its three
// components so adding/editing a Deposit or Late Payment row is reflected
// immediately in Net Sales / Short-Over instead of going stale.
// Always false on page load — even a previously-saved report should
// re-sync Total Swipe against its current Deposit/Late Payment/Card Swipe
// components. It only becomes "manual" once the user actually types
// into the Total Swipe box themselves during this session.
let totalSwipeManuallyEdited = false;
function updateTotalSwipeDefault() {
  if (totalSwipeManuallyEdited) return;
  const cardSwipe = flt(String(document.getElementById('di-tot-card')?.textContent ?? '0'));
  const deposit    = gv('deposit_swipe_card');
  const latePay    = gv('late_payment_card');
  sv('total_swipe', fmt(cardSwipe + deposit + latePay));
}

// ── Sub-section helpers ─────────────────────────────────
function subChanged(sec) {
  let tot = 0;
  document.querySelectorAll(`#rows-${sec} .sub-data-row input[type=number]`).forEach(i => tot += flt(i.value));
  const el = document.getElementById(`sub-tot-${sec}`);
  if (el) el.textContent = fmt(tot);
  // feed summary read-outs
  const map = {
    grab:              'grab_sales',
    deposit:           'deposit_swipe_card',
    late_payment:      'late_payment_card',
    unpaid_med:        'unpaid_med_credit',
    paid_med:          'paid_med',
    advance_paid:      'advance_paid',
    marketing_pullout: 'marketing_pull_out',
    gc_sold:           'gc_sold',
    gc_availed:        'gc_availed',
  };
  if (map[sec]) { sv(map[sec], fmt(tot)); }
  if (sec === 'deposit' || sec === 'late_payment') updateTotalSwipeDefault();
  recalc();
}

function addSubRow(sec) {
  const container = document.getElementById('rows-'+sec);
  const div = document.createElement('div');
  div.className = 'sub-data-row';
  div.innerHTML = `
    <input class="sub-inp" type="text" placeholder="Name…" oninput="subChanged('${sec}')">
    <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" oninput="subChanged('${sec}')">
    <button class="btn-del-sub" onclick="delSubRow(this,'${sec}')">✕</button>`;
  container.appendChild(div);
}

function delSubRow(btn, sec) {
  btn.closest('.sub-data-row').remove();
  subChanged(sec);
}

// ── Dine-in helpers ─────────────────────────────────────
function diCols(tr) {
  const inps = tr.querySelectorAll('input');
  return {
    cash:                    flt(inps[0]?.value),
    palawan_pay:             flt(inps[1]?.value),  // Gcash
    bank_transfer_cheque:    flt(inps[2]?.value),
    unpaid_credit_amount:    flt(inps[3]?.value),  // GC AGODA
    card_swipe_qr:           flt(inps[4]?.value),
    discount:                flt(inps[5]?.value),
    unpaid_credit_name: '',
  };
}

function diChanged() {
  let tCash=0, tGcash=0, tBank=0, tGcAgo=0, tCard=0, tDisc=0;
  document.querySelectorAll('#di-body tr').forEach(tr => {
    const c = diCols(tr);
    tCash  += c.cash;           tGcash += c.palawan_pay;
    tBank  += c.bank_transfer_cheque; tGcAgo += c.unpaid_credit_amount;
    tCard += c.card_swipe_qr;
    tDisc  += c.discount;
  });
  const grand = tCash + tGcash + tBank + tGcAgo + tCard + tDisc;
  document.getElementById('di-tot-cash').textContent    = fmt(tCash);
  document.getElementById('di-tot-gcash').textContent   = fmt(tGcash);
  document.getElementById('di-tot-bank').textContent    = fmt(tBank);
  document.getElementById('di-tot-gcagogo').textContent = fmt(tGcAgo);
  document.getElementById('di-tot-card').textContent    = fmt(tCard);
  document.getElementById('di-tot-disc').textContent    = fmt(tDisc);
  document.getElementById('di-grand-total').textContent = fmt(grand);
  // feed summary (Gross Sales itself is derived in recalc() from the full channel formula)
  sv('gcash',       fmt(tGcash));
  sv('bank_transfer_cheque', fmt(tBank));
  sv('maya_swipe',  fmt(tCard));
  sv('discount',    fmt(tDisc));
  updateTotalSwipeDefault();
  recalc();
}

function addDiRow() {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><button class="btn-del-di" onclick="delDiRow(this)">✕</button>`;
  document.getElementById('di-body').appendChild(tr);
}
function delDiRow(btn) { btn.closest('tr').remove(); diChanged(); }

// ── Summary recalc ───────────────────────────────────────
function recalc() {
  const totalDineIn = parseFloat(String(document.getElementById('di-grand-total')?.textContent ?? '0').replace(/,/g,'')) || 0;
  const sc       = gv('service_charge');
  // Net Sales removes today's card-swipe dine-in sales (maya_swipe) from
  // gross — NOT the combined Total Swipe field, which also folds in
  // Deposit Swipe + Late Payment (those are bank-deposit-slip reconciliation
  // items, not part of today's cash reconciliation, and must not be
  // double-deducted here).
  const swipe    = gv('maya_swipe');
  const unpaid   = gv('unpaid_med_credit');
  const paidMed  = gv('paid_med');
  const advPaid  = gv('advance_paid');
  const mktPull  = gv('marketing_pull_out');
  const grab     = gv('grab_sales');
  const gcash    = gv('gcash');
  const gcSold   = gv('gc_sold');
  const gcAvail  = gv('gc_availed');
  const caPayroll= gv('gift_card');
  const bankTx   = gv('bank_transfer_cheque');
  const disc     = gv('discount');
  const pcf      = gv('pcf_expenses');
  const other    = gv('other_expenses');
  const persWith = gv('personal_withdrawal');

  // Gross Sales = Grab + G-Cash + Gift Cert Sold + Advance Paid + Paid Med + Unpaid Med
  //             + Total Dine-in - Service Charge
  const gross = grab + gcash + gcSold + advPaid + paidMed + unpaid + totalDineIn - sc;
  sv('gross_sales', fmt(gross));

  const net = gross - swipe - unpaid - paidMed - advPaid - mktPull
            - grab - gcash - gcSold - gcAvail - caPayroll - bankTx - disc - pcf - other - persWith;
  const shortOver = gv('coh') - net;

  sv('net_sales', fmt(net));
  const soEl = document.getElementById('short_over');
  if (soEl) { soEl.value = fmt(shortOver); soEl.style.color = shortOver < 0 ? '#ffd54f' : '#b71c1c'; }
}

// ── Save All ─────────────────────────────────────────────
async function saveAll() {
  const status = document.getElementById('saveStatus');
  status.textContent = 'Saving…';

  // 1. Save all sub-sections
  const secs = ['grab','deposit','late_payment','unpaid_med','paid_med','advance_paid','marketing_pullout','gc_sold','gc_availed'];
  for (const sec of secs) {
    const rows = [];
    document.querySelectorAll(`#rows-${sec} .sub-data-row`).forEach(row => {
      const inps = row.querySelectorAll('input');
      rows.push({ name: inps[0]?.value||'', amount: flt(inps[1]?.value) });
    });
    const fd = new FormData();
    fd.append('ajax_save_detail','1'); fd.append('report_date',FDATE);
    fd.append('section',sec); fd.append('rows',JSON.stringify(rows));
    await fetch('dois_sales_report.php',{method:'POST',body:fd});
  }

  // 2. Save DINE IN rows
  const diRows = [];
  document.querySelectorAll('#di-body tr').forEach(tr => diRows.push(diCols(tr)));
  const fd2 = new FormData();
  fd2.append('ajax_save_dinein','1'); fd2.append('report_date',FDATE);
  fd2.append('rows',JSON.stringify(diRows));
  await fetch('dois_sales_report.php',{method:'POST',body:fd2});

  // 3. Save main summary
  const fd3 = new FormData();
  fd3.append('ajax_save','1'); fd3.append('report_date',FDATE);
  [['gross_sales','gross_sales'],['service_charge','service_charge'],
   ['z_reading_gross','gross_sales'],['total_swipe','total_swipe'],
   ['deposit_swipe_card','deposit_swipe_card'],['late_payment_card','late_payment_card'],
   ['maya_swipe','maya_swipe'],['unpaid_med_credit','unpaid_med_credit'],
   ['grab_sales','grab_sales'],['gcash','gcash'],['gift_card','gift_card'],
   ['marketing_pull_out','marketing_pull_out'],['discount','discount'],
   ['bank_transfer_cheque','bank_transfer_cheque'],['pcf_expenses','pcf_expenses'],
   ['other_expenses','other_expenses'],['coh','coh'],['personal_withdrawal','personal_withdrawal'],
  ].forEach(([dbCol, domId]) => fd3.append(dbCol, gv(domId)));
  fd3.append('cashier_name', document.getElementById('cashier_name')?.value || '');

  const res  = await fetch('dois_sales_report.php',{method:'POST',body:fd3});
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

document.addEventListener('DOMContentLoaded',()=>{ diChanged(); ['grab','deposit','late_payment','unpaid_med','paid_med','advance_paid','marketing_pullout','gc_sold','gc_availed'].forEach(s=>subChanged(s)); recalc(); });
</script>
</body>
</html>