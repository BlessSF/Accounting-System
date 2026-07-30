<?php
// ============================================================
//  stella_sales_report.php — Stella Branch Daily Sales Report
//  DINE IN detail rows + sub-section detail rows (Marketing
//  Pullout, GRAB, Expenses, Late Payment, Advance Payment,
//  GC Sponsorship, GC Sold) — totals feed Net Sales / Short/Over
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();

if (isBranch() && currentBranch() !== 'Stella') {
    header('Location: dashboard.php'); exit;
}

$pdo  = getPDO();
$user = currentUser();

// ── Main summary table — Stella-specific fields ────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `stella_sales_report` (
    `id`                    int(11) NOT NULL AUTO_INCREMENT,
    `store_name`            varchar(50) NOT NULL DEFAULT 'Stella',
    `report_date`           date NOT NULL,
    `gross_sales`           decimal(12,2) NOT NULL DEFAULT 0.00,
    `service_charge`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `pos_z_reading`         decimal(12,2) NOT NULL DEFAULT 0.00,
    `total_swipe`           decimal(12,2) NOT NULL DEFAULT 0.00,
    `deposit_swipe_card`    decimal(12,2) NOT NULL DEFAULT 0.00,
    `late_payment_card`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `maya_swipe`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `unpaid_med_credit`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `paid_med_card`         decimal(12,2) NOT NULL DEFAULT 0.00,
    `advance_paid_appigo`   decimal(12,2) NOT NULL DEFAULT 0.00,
    `grab_sales`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `gcash`                 decimal(12,2) NOT NULL DEFAULT 0.00,
    `gift_card`             decimal(12,2) NOT NULL DEFAULT 0.00,
    `marketing_pull_out`    decimal(12,2) NOT NULL DEFAULT 0.00,
    `discount`              decimal(12,2) NOT NULL DEFAULT 0.00,
    `bank_transfer_cheque`  decimal(12,2) NOT NULL DEFAULT 0.00,
    `cash_out_interest`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `gift_cert_sold_tips`   decimal(12,2) NOT NULL DEFAULT 0.00,
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

// Migrate new columns safely (ignore if already exists)
foreach (['paid_med_card','advance_paid_appigo','cash_out_interest','gift_cert_sold_tips','pos_z_reading'] as $col) {
    try { $pdo->exec("ALTER TABLE `stella_sales_report` ADD COLUMN `$col` decimal(12,2) NOT NULL DEFAULT 0.00"); }
    catch (Throwable $ignored) {}
}
try { $pdo->exec("ALTER TABLE `stella_sales_report` ADD COLUMN `cashier_name` varchar(150) DEFAULT NULL"); }
catch (Throwable $ignored) {}

// ── DINE IN detail rows table ──────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `stella_dinein_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'Stella',
    `report_date`   date NOT NULL,
    `cash`          decimal(12,2) DEFAULT 0.00,
    `palawan_pay`   decimal(12,2) DEFAULT 0.00,
    `card_swipe_qr` decimal(12,2) DEFAULT 0.00,
    `unpaid_credit_name`   varchar(100) DEFAULT NULL,
    `unpaid_credit_amount` decimal(12,2) DEFAULT 0.00,
    `discount`      decimal(12,2) DEFAULT 0.00,
    `gift_card`     decimal(12,2) DEFAULT 0.00,
    `bank_transfer_cheque` decimal(12,2) DEFAULT 0.00,
    `cancelled_transactions` decimal(12,2) DEFAULT 0.00,
    `sort_order`    int(4) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Migrate new column onto dine-in rows table safely (ignore if already exists)
try { $pdo->exec("ALTER TABLE `stella_dinein_rows` ADD COLUMN `gift_card` decimal(12,2) DEFAULT 0.00"); }
catch (Throwable $ignored) {}

// ── Sub-section detail rows table ─────────────────────────
// section: marketing_pullout | grab | expenses | late_payment |
//          advance_payment | gc_sponsorship | gc_sold |
//          paid_med | deposit_card | cash_out_interest
$pdo->exec("CREATE TABLE IF NOT EXISTS `stella_sales_detail_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'Stella',
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
        $NUM_COLS = ['gross_sales','service_charge','pos_z_reading','total_swipe',
            'deposit_swipe_card','late_payment_card','maya_swipe',
            'unpaid_med_credit','paid_med_card','advance_paid_appigo',
            'grab_sales','gcash','gift_card','marketing_pull_out','discount',
            'bank_transfer_cheque','cash_out_interest','gift_cert_sold_tips',
            'pcf_expenses','other_expenses','coh'];
        $vals = [];
        foreach ($NUM_COLS as $f) $vals[$f] = (float)($_POST[$f] ?? 0);

        // Authoritative DINE IN sums (Gift Card, PCF/Expenses, Other Expenses excluded here on purpose)
        $diSumStmt = $pdo->prepare("SELECT
                COALESCE(SUM(unpaid_credit_amount),0) AS unpaid_med_credit,
                COALESCE(SUM(discount),0)             AS discount,
                COALESCE(SUM(gift_card),0)             AS gift_card,
                COALESCE(SUM(bank_transfer_cheque),0) AS bank_transfer_cheque,
                COALESCE(SUM(palawan_pay),0)           AS gcash
            FROM stella_dinein_rows WHERE store_name='Stella' AND report_date=?");
        $diSumStmt->execute([$reportDate]);
        $diSums = $diSumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['unpaid_med_credit','discount','gift_card','bank_transfer_cheque','gcash'] as $f) {
            $vals[$f] = (float)($diSums[$f] ?? 0);
        }

        // Authoritative sub-section sums
        $secSumStmt = $pdo->prepare("SELECT section, COALESCE(SUM(amount),0) AS total
            FROM stella_sales_detail_rows WHERE store_name='Stella' AND report_date=? GROUP BY section");
        $secSumStmt->execute([$reportDate]);
        $secSums = [];
        foreach ($secSumStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $secSums[$r['section']] = (float)$r['total'];
        $vals['grab_sales']          = $secSums['grab'] ?? 0;
        $vals['marketing_pull_out']  = $secSums['marketing_pullout'] ?? 0;
        $vals['paid_med_card']       = $secSums['paid_med'] ?? 0;
        $vals['advance_paid_appigo'] = $secSums['advance_payment'] ?? 0;
        $vals['gift_cert_sold_tips'] = $secSums['gc_sold'] ?? 0;
        $vals['cash_out_interest']   = $secSums['cash_out_interest'] ?? 0;

        // Total Swipe = Deposit Swipe + Late Payment + Maya Swipe + Paid Med (CARD)
        $vals['total_swipe'] = $vals['deposit_swipe_card'] + $vals['late_payment_card']
                              + $vals['maya_swipe'] + $vals['paid_med_card'];

        // Total Dine In = sum of DINE IN rows just saved for this date
        $tdiStmt = $pdo->prepare("SELECT COALESCE(SUM(cash+palawan_pay+card_swipe_qr+discount+gift_card+bank_transfer_cheque),0) AS total
                                   FROM stella_dinein_rows WHERE store_name='Stella' AND report_date=?");
        $tdiStmt->execute([$reportDate]);
        $totalDineIn = (float)$tdiStmt->fetchColumn();

        // Gross Sales = Grab + Cash out Interest + Gift Cert Sold + Advance Paid
        //             + Paid Med + Unpaid Med + Total Dine In - Service Charge
        // NOTE: G-Cash is NOT added separately — it's the Dine In "Palawan Pay"
        // column, which is already included inside Total Dine In. Adding it
        // again here double-counts it.
        $vals['gross_sales'] = $vals['grab_sales'] + $vals['cash_out_interest']
                              + $vals['gift_cert_sold_tips'] + $vals['advance_paid_appigo']
                              + $vals['paid_med_card'] + $vals['unpaid_med_credit']
                              + $totalDineIn - $vals['service_charge'];

        // POS Z Reading = Gross Sales + Marketing Pull Out
        // (the physical register tape total includes promo/comp value that Gross Sales nets back out)
        $vals['pos_z_reading'] = $vals['gross_sales'] + $vals['marketing_pull_out'];

        // Net Sales = Gross Sales - all deductions
        $netSales = $vals['gross_sales']
                  - $vals['maya_swipe']
                  - $vals['unpaid_med_credit']
                  - $vals['paid_med_card']
                  - $vals['advance_paid_appigo']
                  - $vals['grab_sales']
                  - $vals['gcash']
                  - $vals['gift_card']
                  - $vals['discount']
                  - $vals['bank_transfer_cheque']
                  - $vals['cash_out_interest']
                  - $vals['gift_cert_sold_tips']
                  - $vals['pcf_expenses']
                  - $vals['other_expenses'];
        $shortOver = $vals['coh'] - $netSales;

        $cashierName = trim((string)($_POST['cashier_name'] ?? ''));

        $fields    = array_merge(['store_name','report_date'], $NUM_COLS, ['net_sales','short_over','saved_by','cashier_name']);
        $data      = array_merge(['Stella', $reportDate], array_values($vals), [$netSales, $shortOver, $user['name'], $cashierName]);
        $dupUpdate = implode(',', array_map(fn($f) => "`$f`=VALUES(`$f`)", array_merge($NUM_COLS, ['net_sales','short_over','saved_by','cashier_name'])));
        $sql = "INSERT INTO stella_sales_report (" . implode(',', array_map(fn($f)=>"`$f`",$fields)) . ")
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
        $pdo->prepare("DELETE FROM stella_dinein_rows WHERE store_name='Stella' AND report_date=?")->execute([$reportDate]);
        $ins = $pdo->prepare("INSERT INTO stella_dinein_rows (store_name,report_date,cash,palawan_pay,card_swipe_qr,unpaid_credit_name,unpaid_credit_amount,discount,gift_card,bank_transfer_cheque,cancelled_transactions,sort_order) VALUES ('Stella',?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$reportDate, (float)($r['cash']??0), (float)($r['palawan_pay']??0),
                (float)($r['card_swipe_qr']??0), $r['unpaid_credit_name']??null,
                (float)($r['unpaid_credit_amount']??0), (float)($r['discount']??0),
                (float)($r['gift_card']??0),
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
        $pdo->prepare("DELETE FROM stella_sales_detail_rows WHERE store_name='Stella' AND report_date=? AND section=?")->execute([$reportDate,$section]);
        $ins = $pdo->prepare("INSERT INTO stella_sales_detail_rows (store_name,report_date,section,item_name,amount,sort_order) VALUES ('Stella',?,?,?,?,?)");
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
    $stmt = $pdo->prepare("SELECT * FROM stella_sales_report WHERE store_name='Stella' AND report_date=?");
    $stmt->execute([$reportDate]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $g = fn($k) => number_format((float)($r[$k] ?? 0), 2, '.', '');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Stella_SalesReport_'.$reportDate.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['SUMMARY REPORT — Stella Branch', date('F j, Y', strtotime($reportDate))]);
    fputcsv($out, ['Cashier', (string)($r['cashier_name'] ?? '')]);
    fputcsv($out, []);
    fputcsv($out, ['Gross Sales', $g('gross_sales')]);
    fputcsv($out, ['Service Charge', $g('service_charge')]);
    fputcsv($out, ['Z Reading Gross', $g('z_reading_gross')]);
    fputcsv($out, ['Total Swipe', $g('total_swipe')]);
    fputcsv($out, ['Deposit Swipe (Card)', $g('deposit_swipe_card')]);
    fputcsv($out, ['Late Payment (Card)', $g('late_payment_card')]);
    fputcsv($out, ['Sales of the day Swipe (MAYA)', $g('maya_swipe')]);
    fputcsv($out, ['Unpaid Med (Credit)', $g('unpaid_med_credit')]);
    fputcsv($out, ['Paid Med/Corp. (Card)', $g('paid_med_card')]);
    fputcsv($out, ['Advance Paid (Appigo)', $g('advance_paid_appigo')]);
    fputcsv($out, ['Grab Sales', $g('grab_sales')]);
    fputcsv($out, ['G-Cash', $g('gcash')]);
    fputcsv($out, ['Gift Card', $g('gift_card')]);
    fputcsv($out, ['Marketing Pull Out', $g('marketing_pull_out')]);
    fputcsv($out, ['Discount', $g('discount')]);
    fputcsv($out, ['Bank Transfer/Cheque', $g('bank_transfer_cheque')]);
    fputcsv($out, ['Cash out Interest (Booky)', $g('cash_out_interest')]);
    fputcsv($out, ['Gift Cert. Sold (Tips)', $g('gift_cert_sold_tips')]);
    fputcsv($out, ['PCF/Expenses', $g('pcf_expenses')]);
    fputcsv($out, ['Other Expenses', $g('other_expenses')]);
    fputcsv($out, ['Net Sales', $g('net_sales')]);
    fputcsv($out, ['COH (Cash on Hand)', $g('coh')]);
    fputcsv($out, ['Short/Over', $g('short_over')]);
    fclose($out); exit;
}

// ── Fetch data for display ─────────────────────────────────
$fDate = $_GET['date'] ?? date('Y-m-d');
$stmt  = $pdo->prepare("SELECT * FROM stella_sales_report WHERE store_name='Stella' AND report_date=?");
$stmt->execute([$fDate]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$v   = fn($k) => (float)($row[$k] ?? 0);
$vs  = fn($k) => (string)($row[$k] ?? '');
$fmt = fn($n)  => number_format((float)$n, 2);

// Fetch DINE IN rows
$diStmt = $pdo->prepare("SELECT * FROM stella_dinein_rows WHERE store_name='Stella' AND report_date=? ORDER BY sort_order ASC");
$diStmt->execute([$fDate]);
$dineinRows = $diStmt->fetchAll(PDO::FETCH_ASSOC);
$diSum = fn($col) => array_sum(array_column($dineinRows, $col));

// Fetch sub-section detail rows
$detailStmt = $pdo->prepare("SELECT * FROM stella_sales_detail_rows WHERE store_name='Stella' AND report_date=? ORDER BY section, sort_order ASC");
$detailStmt->execute([$fDate]);
$allDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
$details = [];
foreach ($allDetails as $d) $details[$d['section']][] = $d;
$secSum = fn($sec) => array_sum(array_column($details[$sec] ?? [], 'amount'));

// Auto-calculated pink-section values (Gift Card and Other Expenses stay manual)
$unpaidMedCalc     = $diSum('unpaid_credit_amount');
$discountCalc      = $diSum('discount');
$giftCardCalc      = $diSum('gift_card');
$bankTransferCalc  = $diSum('bank_transfer_cheque');
$gcashCalc         = $diSum('palawan_pay');
$grabCalc          = $secSum('grab');
$marketingCalc     = $secSum('marketing_pullout');
$pcfCalc           = $secSum('expenses');
$paidMedCalc       = $secSum('paid_med');
$depositCardCalc   = $secSum('deposit_card');
$cashOutCalc       = $secSum('cash_out_interest');
$advancePaidCalc   = $secSum('advance_payment');
$giftCertSoldCalc  = $secSum('gc_sold');

$totalDineInCalc = $diSum('cash') + $diSum('palawan_pay') + $diSum('card_swipe_qr')
                 + $diSum('discount') + $diSum('gift_card') + $diSum('bank_transfer_cheque');

// Gross Sales = Grab + Cash out Interest + Gift Cert Sold + Advance Paid
//             + Paid Med + Unpaid Med + Total Dine In - Service Charge
// NOTE: G-Cash is NOT added separately — it's the Dine In "Palawan Pay"
// column, which is already included inside Total Dine In.
$grossSalesCalc = $grabCalc + $cashOutCalc + $giftCertSoldCalc
                 + $advancePaidCalc + $paidMedCalc + $unpaidMedCalc
                 + $totalDineInCalc - $v('service_charge');

// POS Z Reading = Gross Sales + Marketing Pull Out
$posZReadingCalc = $grossSalesCalc + $marketingCalc;

$pageTitle  = 'Stella Sales Report';
$activePage = 'stella_sales_report';
include 'layout.php';
?>

<style>
.sr-header-card {
  background: linear-gradient(135deg, #1e3060 0%, #0f2045 100%);
  border-radius: var(--radius); padding: 20px 26px 16px;
  margin-bottom: 18px; display: flex; align-items: flex-start;
  justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.sr-header-card .eyebrow { font-family:var(--font-m); font-size:.58rem; text-transform:uppercase; letter-spacing:.14em; color:rgba(255,255,255,.45); margin-bottom:4px; }
.sr-header-card .title   { font-size:1.2rem; font-weight:800; color:#fff; letter-spacing:-.02em; }
.sr-header-card .subtitle{ font-family:var(--font-m); font-size:.67rem; color:rgba(255,255,255,.5); margin-top:4px; }

.sr-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:20px; }
.sr-cashier-bar { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid var(--border); border-radius:10px; padding:12px 16px; margin-bottom:14px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.sr-cashier-bar label { font-family:var(--font-m); font-size:.85rem; font-weight:800; color:var(--text); white-space:nowrap; }
.sr-cashier-bar input { flex:1; min-width:0; padding:10px 14px; font-family:var(--font-m); font-size:.95rem; font-weight:700; text-align:left; color:var(--text); background:#fff; border:1px solid var(--border); border-radius:8px; outline:none; transition:border-color .15s; }
.sr-cashier-bar input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(15,123,92,.08); }
.sr-save-status { font-family:var(--font-m); font-size:.72rem; color:var(--subtext); }

/* ── Section blocks ── */
.sr-section {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); box-shadow:0 2px 12px rgba(0,0,0,.06);
  overflow:hidden; margin-bottom:22px;
}
.sr-section-title {
  padding:10px 16px; font-family:var(--font-m); font-size:.75rem;
  font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#fff;
}
.sr-section-title.dark-red { background:#7b1a1a; }
.sr-section-title.dark-green { background:#1a4d1a; }

/* ── DINE IN table ── */
.di-table { width:100%; border-collapse:collapse; }
.di-table th {
  background:#8b2020; color:#fff; padding:7px 8px;
  font-family:var(--font-m); font-size:.62rem; text-transform:uppercase;
  letter-spacing:.06em; text-align:center; white-space:nowrap; border:1px solid #6b1818;
}
.di-table th.sub { background:#5a5a5a; font-size:.58rem; }
.di-table td { padding:5px 7px; border:1px solid #e5e7eb; font-size:.77rem; vertical-align:middle; }
.di-table tfoot td { background:#f5c542; font-family:var(--font-m); font-weight:800; font-size:.78rem; padding:7px 8px; border:1px solid #d4a017; }
.di-table tfoot td.total-label { background:#1a3a8a; color:#fff; font-size:.78rem; }
.di-table tfoot td.grand-total { background:#1a3a8a; color:#fff; font-family:var(--font-m); font-weight:800; }

.di-inp { width:100%; border:none; background:transparent; font-family:var(--font-m); font-size:.77rem; text-align:right; outline:none; }
.di-inp:focus { background:#fffbeb; border-radius:3px; }
.di-inp.txt { text-align:left; }
.di-inp-name { width:90px; }
.di-inp-amt  { width:70px; }
.btn-add-row { margin:8px 12px; padding:4px 12px; background:#1a4d1a; color:#fff; border:none; border-radius:5px; font-size:.7rem; font-weight:700; cursor:pointer; }
.btn-add-row:hover { background:#155231; }
.btn-del-row { background:#fee2e2; border:none; color:#991b1b; border-radius:4px; padding:2px 6px; font-size:.65rem; cursor:pointer; }

/* ── Sub-section grid ── */
.sub-grid { display:grid; grid-template-columns:repeat(4,1fr); border-top:1px solid var(--border); }
.sub-grid.three-col { grid-template-columns:repeat(3,1fr); }
.sub-grid.four-col  { grid-template-columns:repeat(4,1fr); }
.sub-col { border-right:1px solid #e5e7eb; }
.sub-col:last-child { border-right:none; }
.sub-col-title {
  background:#7b1a1a; color:#fff; padding:7px 10px;
  font-family:var(--font-m); font-size:.68rem; font-weight:800;
  text-transform:uppercase; letter-spacing:.06em; text-align:center;
}
.sub-col-hdr {
  display:grid; grid-template-columns:1fr 90px 28px;
  background:#d9d9d9; border-bottom:1px solid #bbb;
}
.sub-col-hdr span { padding:5px 8px; font-family:var(--font-m); font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#333; border-right:1px solid #bbb; }
.sub-col-hdr span:last-child { border-right:none; }
.sub-row { display:grid; grid-template-columns:1fr 90px 28px; border-bottom:1px solid #f0f2f5; align-items:center; }
.sub-row:hover { background:#fafbfc; }
.sub-inp { width:100%; border:1px solid #e0e0e0; background:#fafafa; border-radius:4px; font-family:var(--font-m); font-size:.75rem; outline:none; padding:5px 6px; cursor:text; }
.sub-inp.num { text-align:right; }
.sub-inp:focus { background:#fffbeb; border-color:#f5c542; box-shadow:0 0 0 2px rgba(245,197,66,.15); }
.sub-footer { display:grid; grid-template-columns:1fr 90px; background:#f5c542; border-top:2px solid #d4a017; }
.sub-footer span { padding:6px 8px; font-family:var(--font-m); font-size:.72rem; font-weight:800; border-right:1px solid #d4a017; }
.sub-footer span:last-child { text-align:right; border-right:none; }

/* ── Summary card (existing) ── */
.sr-wrap { max-width:680px; margin:0 auto; }
.sr-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:0 2px 12px rgba(0,0,0,.06); overflow:hidden; }
.sr-title-row { background:#2e7d32; padding:14px 28px; text-align:center; }
.sr-title-row .sr-main-title { font-size:1rem; font-weight:800; color:#fff; letter-spacing:.06em; text-transform:uppercase; font-family:var(--font-m); }
.sr-row { display:flex; align-items:center; padding:10px 28px; border-bottom:1px solid #f0f2f5; min-height:44px; gap:10px; }
.sr-row.green    { background:#4a7a4d; }
.sr-row.green .sr-label { color:#fff; font-weight:700; }
.sr-row.gray-hdr { background:#d9d9d9; }
.sr-row.gray-hdr .sr-label { color:#333; font-weight:700; }
.sr-row.pink-val .sr-value-wrap input { background:#f3d9e4; border-color:#e3bccf; }
.sr-row.net      { background:#f5c542; }
.sr-row.net .sr-label { color:#3e2f00; font-weight:800; font-style:italic; }
.sr-row.coh      { background:#263449; }
.sr-row.coh .sr-label { color:#fff; font-weight:800; font-style:italic; }
.sr-row.short    { background:#e53935; }
.sr-row.short .sr-label { color:#fff; font-weight:800; }
.sr-label { flex:1; font-size:.84rem; color:var(--text); font-weight:600; text-align:right; padding-right:14px; }
.sr-note  { font-family:var(--font-h); font-style:italic; font-size:.68rem; color:var(--subtext); white-space:nowrap; }
.sr-value-wrap { width:160px; flex-shrink:0; }
.sr-input { width:100%; padding:7px 12px; text-align:center; font-family:var(--font-m); font-size:.84rem; font-weight:700; color:var(--text); background:#fff; border:1px solid var(--border); border-radius:6px; outline:none; transition:border-color .15s; }
input[type=number] { -moz-appearance:textfield; appearance:textfield; }
input[type=number]::-webkit-outer-spin-button,
input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.sr-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(15,123,92,.08); }
.sr-input.readout { background:rgba(255,255,255,.9); font-size:1rem; font-weight:800; cursor:default; color:#1a1d23; border-color:transparent; }
.sr-row.net   .sr-input.readout { background:#fff; color:#7a5c00; }
.sr-row.coh   .sr-input          { background:#fff; }
.sr-row.short .sr-input.readout { background:#fff; color:#000; -webkit-text-fill-color:#000; opacity:1; font-weight:900; }
.toast { position:fixed; top:68px; right:22px; z-index:9999; max-width:320px; animation:fadeSlideDown .3s ease; }
</style>

<!-- Header -->
<div class="sr-header-card">
  <div>
    <div class="eyebrow">Stella Branch · Sales</div>
    <div class="title">Daily Sales Report</div>
    <div class="subtitle">DINE IN detail + sub-sections · Net Sales &amp; Short/Over auto-calculated</div>
  </div>
  <span style="background:rgba(255,255,255,.1);color:#fff;padding:5px 14px;border-radius:20px;font-family:var(--font-m);font-size:.65rem;font-weight:600;align-self:flex-start">📌 Stella</span>
</div>

<!-- Cashier -->
<div class="sr-cashier-bar">
  <label for="cashier_name">Cashier:</label>
  <input type="text" id="cashier_name" style="text-transform:uppercase" value="<?= htmlspecialchars($vs('cashier_name')) ?>" placeholder="e.g. CHRISTINE AND JUSTINE">
</div>

<!-- Controls -->
<div class="sr-controls">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($fDate) ?>" onchange="this.form.submit()">
    <button type="button" class="btn btn-primary" onclick="saveAll()">💾 Save All</button>
    <a href="stella_sales_report.php?export_csv=1&date=<?= htmlspecialchars($fDate) ?>" class="btn btn-ghost">⬇ Download CSV</a>
    <span id="saveStatus" class="sr-save-status"></span>
  </form>
</div>

<!-- ══════════════════════════════════════════════════════════
     DINE IN SECTION
════════════════════════════════════════════════════════════ -->
<div class="sr-section">
  <div class="sr-section-title dark-red">DINE IN</div>
  <div style="overflow-x:auto">
  <table class="di-table" id="di-table">
    <thead>
      <tr>
        <th>Cash</th>
        <th>Palawan Pay</th>
        <th>Card/Swipe/QR</th>
        <th colspan="2">Unpaid / Credit</th>
        <th>Discount</th>
        <th>Gift Cert.</th>
        <th>Bank Transfer/Cheque</th>
        <th>Cancelled Transactions</th>
        <th style="width:40px"></th>
      </tr>
      <tr>
        <th class="sub"></th><th class="sub"></th><th class="sub"></th>
        <th class="sub">NAME</th><th class="sub">AMOUNT</th>
        <th class="sub"></th><th class="sub"></th><th class="sub"></th><th class="sub"></th><th class="sub"></th>
      </tr>
    </thead>
    <tbody id="di-body">
      <?php foreach ($dineinRows as $dr): ?>
      <tr>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['cash'] ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['palawan_pay'] ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['card_swipe_qr'] ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp txt di-inp-name" type="text" value="<?= htmlspecialchars($dr['unpaid_credit_name'] ?? '') ?>" placeholder="Name"></td>
        <td><input class="di-inp di-inp-amt" type="number" step="0.01" value="<?= (float)$dr['unpaid_credit_amount'] ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['discount'] ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)($dr['gift_card'] ?? 0) ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['bank_transfer_cheque'] ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" value="<?= (float)$dr['cancelled_transactions'] ?: '' ?>" placeholder="" oninput="diChanged()"></td>
        <td><button class="btn-del-row" onclick="delDiRow(this)">✕</button></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($dineinRows)): ?>
      <tr>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp txt di-inp-name" type="text" placeholder="Name"></td>
        <td><input class="di-inp di-inp-amt" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
        <td><button class="btn-del-row" onclick="delDiRow(this)">✕</button></td>
      </tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td id="di-tot-cash">0.00</td>
        <td id="di-tot-palawan">0.00</td>
        <td id="di-tot-card">0.00</td>
        <td colspan="2" id="di-tot-unpaid">0.00</td>
        <td id="di-tot-disc">0.00</td>
        <td id="di-tot-gift">0.00</td>
        <td id="di-tot-bank">0.00</td>
        <td id="di-tot-cancelled">0.00</td>
        <td></td>
      </tr>
      <tr>
        <td class="total-label" colspan="3">TOTAL</td>
        <td class="grand-total" colspan="7" id="di-grand-total">0.00</td>
      </tr>
    </tfoot>
  </table>
  </div>
  <button class="btn-add-row" onclick="addDiRow()">+ Add Row</button>
</div>

<!-- ══════════════════════════════════════════════════════════
     SUB-SECTIONS ROW 1: Marketing Pullout | GRAB | Expenses | Late Payment
════════════════════════════════════════════════════════════ -->
<div class="sr-section">
  <div class="sub-grid" id="subsec-row1">

    <?php
    $subsec1 = [
        'marketing_pullout' => 'Marketing Pullout',
        'grab'              => 'GRAB',
        'expenses'          => 'EXPENSES',
        'late_payment'      => 'LATE PAYMENT',
    ];
    foreach ($subsec1 as $sec => $label):
        $secRows = $details[$sec] ?? [['item_name'=>'','amount'=>0]];
    ?>
    <div class="sub-col" data-section="<?= $sec ?>">
      <div class="sub-col-title"><?= $label ?></div>
      <div class="sub-col-hdr"><span>Name</span><span>Amount</span><span></span></div>
      <div class="sub-rows">
        <?php foreach ($secRows as $sr): ?>
        <div class="sub-row">
          <input class="sub-inp" type="text" placeholder="Enter name…" value="<?= htmlspecialchars($sr['item_name'] ?? '') ?>" oninput="subChanged('<?= $sec ?>')">
          <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" value="<?= (float)$sr['amount'] ?: '' ?>" oninput="subChanged('<?= $sec ?>')">
          <button class="btn-del-row" onclick="delSubRow(this,'<?= $sec ?>')" style="margin:2px 4px">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn-add-row" onclick="addSubRow('<?= $sec ?>')">+ Add</button>
      <div class="sub-footer">
        <span>Total</span>
        <span id="sub-tot-<?= $sec ?>">P<?= number_format(array_sum(array_column($secRows,'amount')),2) ?></span>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SUB-SECTIONS ROW 2: Advance Payment | GC Sponsorship | GC Sold
════════════════════════════════════════════════════════════ -->
<div class="sr-section">
  <div class="sub-grid three-col" id="subsec-row2">

    <?php
    $subsec2 = [
        'advance_payment'  => 'ADVANCE PAYMENT',
        'gc_sponsorship'   => 'GC SPONSORSHIP',
        'gc_sold'          => 'GC SOLD',
    ];
    foreach ($subsec2 as $sec => $label):
        $secRows = $details[$sec] ?? [['item_name'=>'','amount'=>0]];
    ?>
    <div class="sub-col" data-section="<?= $sec ?>">
      <div class="sub-col-title"><?= $label ?></div>
      <div class="sub-col-hdr"><span>Name</span><span>Amount</span><span></span></div>
      <div class="sub-rows">
        <?php foreach ($secRows as $sr): ?>
        <div class="sub-row">
          <input class="sub-inp" type="text" placeholder="Enter name…" value="<?= htmlspecialchars($sr['item_name'] ?? '') ?>" oninput="subChanged('<?= $sec ?>')">
          <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" value="<?= (float)$sr['amount'] ?: '' ?>" oninput="subChanged('<?= $sec ?>')">
          <button class="btn-del-row" onclick="delSubRow(this,'<?= $sec ?>')" style="margin:2px 4px">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn-add-row" onclick="addSubRow('<?= $sec ?>')">+ Add</button>
      <div class="sub-footer">
        <span>Total</span>
        <span id="sub-tot-<?= $sec ?>">P<?= number_format(array_sum(array_column($secRows,'amount')),2) ?></span>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SUB-SECTIONS ROW 3: Paid Med/Corp. (Card) | Deposit (Card) | Gift Cert Sold (TIPS) | Cash out Interest (Booky)
════════════════════════════════════════════════════════════ -->
<div class="sr-section">
  <div class="sub-grid four-col" id="subsec-row3">

    <?php
    $subsec3 = [
        'paid_med'          => 'PAID MED/CORP. (CARD)',
        'deposit_card'      => 'DEPOSIT (CARD)',
        'gift_cert_tips'    => 'GIFT CERT SOLD (TIPS)',
        'cash_out_interest' => 'CASH OUT INTEREST (BOOKY)',
    ];
    foreach ($subsec3 as $sec => $label):
        $secRows = $details[$sec] ?? [['item_name'=>'','amount'=>0]];
    ?>
    <div class="sub-col" data-section="<?= $sec ?>">
      <div class="sub-col-title"><?= $label ?></div>
      <div class="sub-col-hdr"><span>Name</span><span>Amount</span><span></span></div>
      <div class="sub-rows">
        <?php foreach ($secRows as $sr): ?>
        <div class="sub-row">
          <input class="sub-inp" type="text" placeholder="Enter name…" value="<?= htmlspecialchars($sr['item_name'] ?? '') ?>" oninput="subChanged('<?= $sec ?>')">
          <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" value="<?= (float)$sr['amount'] ?: '' ?>" oninput="subChanged('<?= $sec ?>')">
          <button class="btn-del-row" onclick="delSubRow(this,'<?= $sec ?>')" style="margin:2px 4px">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn-add-row" onclick="addSubRow('<?= $sec ?>')">+ Add</button>
      <div class="sub-footer">
        <span>Total</span>
        <span id="sub-tot-<?= $sec ?>">P<?= number_format(array_sum(array_column($secRows,'amount')),2) ?></span>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SUMMARY REPORT (existing fields — auto-fed from above)
════════════════════════════════════════════════════════════ -->
<!-- ══════════════════════════════════════════════════════════
     SUMMARY REPORT — Stella Branch
════════════════════════════════════════════════════════════ -->
<div class="sr-wrap">
<div class="sr-card">
  <div class="sr-title-row"><div class="sr-main-title">Summary Report</div></div>

  <div class="sr-row" style="background:#f8f9fb">
    <div class="sr-label" style="text-align:left;flex:1">For</div>
    <div class="sr-value-wrap"><span class="sr-input readout" style="font-size:.8rem"><?= date('n/j/Y', strtotime($fDate)) ?></span></div>
  </div>

  <!-- Green rows -->
  <div class="sr-row green">
    <div class="sr-label">Gross Sales <span style="font-size:.65rem;opacity:.8">(auto: Grab + Cash out Interest + Gift Cert Sold + Advance Paid + Paid Med + Unpaid Med + Total Dine In − Service Charge)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="gross_sales" value="<?= $fmt($grossSalesCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">Service Charge</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="service_charge" value="<?= $v('service_charge') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">POS Z reading <span style="font-size:.65rem;opacity:.8">(auto: Gross Sales + Marketing Pull Out)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="pos_z_reading" value="<?= $fmt($posZReadingCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">Total Swipe: <span style="font-size:.65rem;opacity:.8">(auto: Deposit + Late Payment + Maya + Paid Med)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="total_swipe" value="<?= $fmt($v('deposit_swipe_card')+$v('late_payment_card')+$v('maya_swipe')+$paidMedCalc) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Gray sub-rows under Total Swipe -->
  <div class="sr-row gray-hdr">
    <div class="sr-label">DEPOSIT SWIPE (CARD) <span style="font-size:.65rem;opacity:.7">(auto: Deposit (Card) + Gift Cert Sold (TIPS))</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="deposit_swipe_card" value="<?= $fmt($v('deposit_swipe_card')) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row gray-hdr">
    <div class="sr-label">LATE PAYMENT (CARD) <span style="font-size:.65rem;opacity:.7">(auto from Late Payment sub-section)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="late_payment_card" value="<?= $fmt($v('late_payment_card')) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- Green Maya row -->
  <div class="sr-row green">
    <div class="sr-label">Sales of the day Swipe: (MAYA) <span style="font-size:.65rem;opacity:.7">(auto from Card/Swipe/QR total)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="maya_swipe" value="<?= $fmt($v('maya_swipe')) ?>" readonly tabindex="-1" style="color:#dc2626;font-weight:800"></div>
  </div>

  <!-- Pink deduction rows -->
  <div class="sr-row pink-val">
    <div class="sr-label">Unpaid Med (CREDIT) <span style="font-size:.65rem;opacity:.7">(auto from Unpaid/Credit)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="unpaid_med_credit" value="<?= $fmt($unpaidMedCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Paid Med (CARD) <span style="font-size:.65rem;opacity:.7">(auto from Paid Med/Corp. sub-section)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="paid_med_card" value="<?= $fmt($paidMedCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Advance Paid (APPIGO) <span style="font-size:.65rem;opacity:.7">(auto from Advance Payment)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="advance_paid_appigo" value="<?= $fmt($advancePaidCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Grab Sales: <span style="font-size:.65rem;opacity:.7">(auto from GRAB sub-section)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="grab_sales" value="<?= $fmt($grabCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">G-Cash <span style="font-size:.65rem;opacity:.7">(auto from Palawan Pay)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="gcash" value="<?= $fmt($gcashCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Gift Card <span style="font-size:.65rem;opacity:.7">(auto from DINE IN Gift Cert.)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="gift_card" value="<?= $fmt($giftCardCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Marketing Pull Out <span style="font-size:.65rem;opacity:.7">(auto from Marketing Pullout)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="marketing_pull_out" value="<?= $fmt($marketingCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Discount <span style="font-size:.65rem;opacity:.7">(auto from DINE IN Discount)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="discount" value="<?= $fmt($discountCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Bank Transfer/Cheque <span style="font-size:.65rem;opacity:.7">(auto from DINE IN)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="bank_transfer_cheque" value="<?= $fmt($bankTransferCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Cash out Interest (BOOKY) <span style="font-size:.65rem;opacity:.7">(auto from Cash out Interest sub-section)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="cash_out_interest" value="<?= $fmt($cashOutCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Gift Cert. Sold (TIPS) <span style="font-size:.65rem;opacity:.7">(auto from GC Sold)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="gift_cert_sold_tips" value="<?= $fmt($giftCertSoldCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">PCF/Expenses <span style="font-size:.65rem;opacity:.7">(auto from Expenses)</span></div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="pcf_expenses" value="<?= $v('pcf_expenses') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Other Expenses</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="other_expenses" value="<?= $v('other_expenses') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
  </div>

  <!-- Net Sales -->
  <div class="sr-row net">
    <div class="sr-label">Net Sales</div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="net_sales" value="<?= $fmt($v('net_sales')) ?>" readonly tabindex="-1"></div>
  </div>

  <!-- COH -->
  <div class="sr-row coh">
    <div class="sr-label">COH(Cash on Hand)</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="coh" value="<?= $v('coh') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
    <div class="sr-note" style="color:#cfd8ea">(cash sales only except SC)</div>
  </div>

  <!-- Short/Over -->
  <div class="sr-row short">
    <div class="sr-label">Short/Over</div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="short_over" value="<?= $fmt($v('short_over')) ?>" readonly tabindex="-1"></div>
    <div class="sr-note" style="color:#ffd8d8">(if "-" sign means short)</div>
  </div>
</div>
</div>


<script>
const FDATE  = '<?= $fDate ?>';
const fmt    = n => Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const gv     = id => parseFloat(String(document.getElementById(id)?.value ?? '').replace(/,/g,'')) || 0;
const setVal = (id,v) => { const el=document.getElementById(id); if(el) el.value = v; };

// ── DINE IN helpers ────────────────────────────────────────
function diCols(tr) {
  const inps = tr.querySelectorAll('input');
  return {
    cash:                (float(inps[0])),
    palawan_pay:         (float(inps[1])),
    card_swipe_qr:       (float(inps[2])),
    unpaid_credit_name:  inps[3]?.value || '',
    unpaid_credit_amount:(float(inps[4])),
    discount:            (float(inps[5])),
    gift_card:           (float(inps[6])),
    bank_transfer_cheque:(float(inps[7])),
    cancelled_transactions:(float(inps[8])),
  };
}
function float(inp) { return parseFloat(inp?.value) || 0; }

function diChanged() {
  let totCash=0,totPal=0,totCard=0,totUnpaid=0,totDisc=0,totGift=0,totBank=0,totCan=0;
  document.querySelectorAll('#di-body tr').forEach(tr => {
    const c = diCols(tr);
    totCash   += c.cash; totPal += c.palawan_pay; totCard += c.card_swipe_qr;
    totUnpaid += c.unpaid_credit_amount; totDisc += c.discount; totGift += c.gift_card;
    totBank   += c.bank_transfer_cheque; totCan += c.cancelled_transactions;
  });
  const grand = totCash + totPal + totCard + totDisc + totGift + totBank;
  document.getElementById('di-tot-cash').textContent      = fmt(totCash);
  document.getElementById('di-tot-palawan').textContent   = fmt(totPal);
  document.getElementById('di-tot-card').textContent      = fmt(totCard);
  document.getElementById('di-tot-unpaid').textContent    = fmt(totUnpaid);
  document.getElementById('di-tot-disc').textContent      = fmt(totDisc);
  document.getElementById('di-tot-gift').textContent      = fmt(totGift);
  document.getElementById('di-tot-bank').textContent      = fmt(totBank);
  document.getElementById('di-tot-cancelled').textContent = fmt(totCan);
  document.getElementById('di-grand-total').textContent   = fmt(grand);

  // Auto-feed summary fields
  setVal('unpaid_med_credit',    fmt(totUnpaid));
  setVal('discount',             fmt(totDisc));
  setVal('gift_card',            fmt(totGift));
  setVal('bank_transfer_cheque', fmt(totBank));
  setVal('gcash',                fmt(totPal));
  setVal('maya_swipe',           fmt(totCard));
  // NOTE: COH (Cash on Hand) is NOT auto-filled — it is the actual physical
  // cash counted by the cashier and must be entered manually.
  recalc();
}

function addDiRow() {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp txt di-inp-name" type="text" placeholder="Name"></td>
    <td><input class="di-inp di-inp-amt" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><input class="di-inp" type="number" step="0.01" placeholder="" oninput="diChanged()"></td>
    <td><button class="btn-del-row" onclick="delDiRow(this)">✕</button></td>`;
  document.getElementById('di-body').appendChild(tr);
}

function delDiRow(btn) {
  btn.closest('tr').remove();
  diChanged();
}

// ── Sub-section helpers ────────────────────────────────────
function subChanged(sec) {
  const col = document.querySelector(`.sub-col[data-section="${sec}"]`);
  let tot = 0;
  col.querySelectorAll('input[type=number]').forEach(i => tot += parseFloat(i.value)||0);
  const totEl = document.getElementById(`sub-tot-${sec}`);
  if (totEl) totEl.textContent = 'P' + fmt(tot);

  // Auto-feed summary
  if (sec === 'grab')              { setVal('grab_sales',         fmt(tot)); recalc(); }
  if (sec === 'marketing_pullout') { setVal('marketing_pull_out', fmt(tot)); recalc(); }
  if (sec === 'expenses')          { setVal('pcf_expenses',        fmt(tot)); recalc(); }
  if (sec === 'late_payment')      { setVal('late_payment_card',   fmt(tot)); recalc(); }
  if (sec === 'advance_payment')   { setVal('advance_paid_appigo', fmt(tot)); recalc(); }
  if (sec === 'gc_sold')           { setVal('gift_cert_sold_tips', fmt(tot)); recalc(); }
  if (sec === 'gift_cert_tips')    { setVal('gift_cert_sold_tips', fmt(tot)); recalcDepositSwipe(); }
  if (sec === 'paid_med')          { setVal('paid_med_card',       fmt(tot)); recalc(); }
  if (sec === 'deposit_card')      { recalcDepositSwipe(); }
  if (sec === 'cash_out_interest') { setVal('cash_out_interest',   fmt(tot)); recalc(); }
}

function recalcDepositSwipe() {
  // DEPOSIT SWIPE (CARD) = DEPOSIT (CARD) sub-section + GIFT CERT SOLD (TIPS) sub-section
  let depTot = 0, gcTot = 0;
  const depCol = document.querySelector('.sub-col[data-section="deposit_card"]');
  if (depCol) depCol.querySelectorAll('input[type=number]').forEach(i => depTot += parseFloat(i.value)||0);
  const gcCol = document.querySelector('.sub-col[data-section="gift_cert_tips"]');
  if (gcCol) gcCol.querySelectorAll('input[type=number]').forEach(i => gcTot += parseFloat(i.value)||0);
  setVal('deposit_swipe_card', fmt(depTot + gcTot));
  recalc();
}

function addSubRow(sec) {
  const col  = document.querySelector(`.sub-col[data-section="${sec}"]`);
  const rows = col.querySelector('.sub-rows');
  const div  = document.createElement('div');
  div.className = 'sub-row';
  div.innerHTML = `
    <input class="sub-inp" type="text" placeholder="Enter name…" oninput="subChanged('${sec}')">
    <input class="sub-inp num" type="number" step="0.01" placeholder="0.00" oninput="subChanged('${sec}')">
    <button class="btn-del-row" onclick="delSubRow(this,'${sec}')" style="margin:2px 4px">✕</button>`;
  rows.appendChild(div);
}

function delSubRow(btn, sec) {
  btn.closest('.sub-row').remove();
  subChanged(sec);
}

// ── Summary recalc ─────────────────────────────────────────
function recalc() {
  // Total Swipe = Deposit + Late Payment + Maya + Paid Med (CARD)
  const totalSwipe = gv('deposit_swipe_card') + gv('late_payment_card') + gv('maya_swipe') + gv('paid_med_card');
  document.getElementById('total_swipe').value = fmt(totalSwipe);

  // Total Dine In (grand total from DINE IN table)
  const totalDineIn = parseFloat(String(document.getElementById('di-grand-total')?.textContent ?? '0').replace(/,/g,'')) || 0;

  // Gross Sales = Grab + Cash out Interest + Gift Cert Sold + Advance Paid + Paid Med + Unpaid Med + Total Dine In - Service Charge
  // NOTE: G-Cash is NOT added separately — it's the Dine In "Palawan Pay" column,
  // already included inside Total Dine In. Adding it again would double-count it.
  const grossSales = gv('grab_sales') + gv('cash_out_interest') + gv('gift_cert_sold_tips')
                    + gv('advance_paid_appigo') + gv('paid_med_card') + gv('unpaid_med_credit')
                    + totalDineIn - gv('service_charge');
  document.getElementById('gross_sales').value = fmt(grossSales);

  // POS Z Reading = Gross Sales + Marketing Pull Out
  const posZReading = grossSales + gv('marketing_pull_out');
  document.getElementById('pos_z_reading').value = fmt(posZReading);

  // Net Sales = Gross Sales - Maya Swipe - all deductions (Marketing Pull Out already inside Gross Sales)
  const netSales = gv('gross_sales')
                 - gv('maya_swipe')
                 - gv('unpaid_med_credit')
                 - gv('paid_med_card')
                 - gv('advance_paid_appigo')
                 - gv('grab_sales')
                 - gv('gcash')
                 - gv('gift_card')
                 - gv('discount')
                 - gv('bank_transfer_cheque')
                 - gv('cash_out_interest')
                 - gv('gift_cert_sold_tips')
                 - gv('pcf_expenses')
                 - gv('other_expenses');

  const shortOver = gv('coh') - netSales;
  document.getElementById('net_sales').value = fmt(netSales);
  const soEl = document.getElementById('short_over');
  soEl.value = fmt(shortOver);
  soEl.style.color = shortOver < 0 ? '#ffd54f' : '#fff';
}

// ── Save All ───────────────────────────────────────────────
async function saveAll() {
  const status = document.getElementById('saveStatus');
  status.textContent = 'Saving…';

  // 1. Save DINE IN rows
  const diRows = [];
  document.querySelectorAll('#di-body tr').forEach(tr => diRows.push(diCols(tr)));
  const fd1 = new FormData();
  fd1.append('ajax_save_dinein','1');
  fd1.append('report_date', FDATE);
  fd1.append('rows', JSON.stringify(diRows));
  await fetch('stella_sales_report.php', {method:'POST',body:fd1});

  // 2. Save each sub-section
  const allSecs = ['marketing_pullout','grab','expenses','late_payment','advance_payment','gc_sponsorship','gc_sold','paid_med','deposit_card','gift_cert_tips','cash_out_interest'];
  for (const sec of allSecs) {
    const col = document.querySelector(`.sub-col[data-section="${sec}"]`);
    if (!col) continue;
    const rows = [];
    col.querySelectorAll('.sub-row').forEach(row => {
      const inps = row.querySelectorAll('input');
      rows.push({ name: inps[0]?.value||'', amount: parseFloat(inps[1]?.value)||0 });
    });
    const fd2 = new FormData();
    fd2.append('ajax_save_detail','1');
    fd2.append('report_date', FDATE);
    fd2.append('section', sec);
    fd2.append('rows', JSON.stringify(rows));
    await fetch('stella_sales_report.php', {method:'POST',body:fd2});
  }

  // 3. Save main summary
  const fd3 = new FormData();
  fd3.append('ajax_save','1');
  fd3.append('report_date', FDATE);
  fd3.append('cashier_name', document.getElementById('cashier_name')?.value || '');
  ['gross_sales','service_charge','pos_z_reading','total_swipe',
   'deposit_swipe_card','late_payment_card','maya_swipe',
   'unpaid_med_credit','paid_med_card','advance_paid_appigo',
   'grab_sales','gcash','gift_card','marketing_pull_out','discount',
   'bank_transfer_cheque','cash_out_interest','gift_cert_sold_tips',
   'pcf_expenses','other_expenses','coh'].forEach(id => fd3.append(id, gv(id)));

  const res  = await fetch('stella_sales_report.php', {method:'POST',body:fd3});
  const data = await res.json();
  if (data.ok) {
    status.textContent = '✓ Saved'; status.style.color = 'var(--accent)';
    showToast('✓ Sales report saved', 'success');
  } else {
    showToast('❌ ' + data.msg, 'error'); status.textContent = '❌ Error';
  }
  setTimeout(() => { status.textContent = ''; }, 4000);
}

function showToast(msg, type) {
  const t = document.createElement('div');
  t.className = 'flash flash-'+(type||'success')+' toast';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

document.addEventListener('DOMContentLoaded', () => { diChanged(); recalcDepositSwipe(); recalc(); });
</script>
</body>
</html>