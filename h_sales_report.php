<?php
// ============================================================
//  h_sales_report.php — H Branch Daily Sales Report
//  DINE IN detail rows + sub-section detail rows (Marketing
//  Pullout, GRAB, Expenses, Late Payment, Advance Payment,
//  GC Sponsorship, GC Sold) — totals feed Net Sales / Short/Over
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();

if (isBranch() && currentBranch() !== 'H') {
    header('Location: dashboard.php'); exit;
}

$pdo  = getPDO();
$user = currentUser();

// Only management (non-branch users) may lock/unlock a date's report.
$canLock = !isBranch();

// ── Main summary table (unchanged columns) ─────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `h_sales_report` (
    `id`                    int(11) NOT NULL AUTO_INCREMENT,
    `store_name`            varchar(50) NOT NULL DEFAULT 'H',
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
    `bar_sales`             decimal(12,2) NOT NULL DEFAULT 0.00,
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
    `cashier_name`          varchar(150) DEFAULT NULL,
    `created_at`            timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at`            timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_store_date` (`store_name`,`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

try { $pdo->exec("ALTER TABLE `h_sales_report` ADD COLUMN `cashier_name` varchar(150) DEFAULT NULL"); }
catch (Throwable $ignored) {}
try { $pdo->exec("ALTER TABLE `h_sales_report` ADD COLUMN `bar_sales` decimal(12,2) NOT NULL DEFAULT 0.00"); }
catch (Throwable $ignored) {}
try { $pdo->exec("ALTER TABLE `h_sales_report` ADD COLUMN `opening_cashier` varchar(150) DEFAULT NULL"); }
catch (Throwable $ignored) {}
try { $pdo->exec("ALTER TABLE `h_sales_report` ADD COLUMN `closing_cashier` varchar(150) DEFAULT NULL"); }
catch (Throwable $ignored) {}
try { $pdo->exec("ALTER TABLE `h_sales_report` ADD COLUMN `carwash_sales` decimal(12,2) NOT NULL DEFAULT 0.00"); }
catch (Throwable $ignored) {}

// ── DINE IN detail rows table ──────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `h_dinein_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'H',
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
$pdo->exec("CREATE TABLE IF NOT EXISTS `h_sales_detail_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'H',
    `report_date`   date NOT NULL,
    `section`       varchar(40) NOT NULL,
    `item_name`     varchar(150) DEFAULT NULL,
    `amount`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `sort_order`    int(4) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── Extra multi-amount sales sections: Advance Deposit | Bar Sales ──
$pdo->exec("CREATE TABLE IF NOT EXISTS `h_extra_sales_rows` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `store_name`    varchar(50) NOT NULL DEFAULT 'H',
    `report_date`   date NOT NULL,
    `section`       varchar(40) NOT NULL,
    `item_name`     varchar(150) DEFAULT NULL,
    `amount_card`   decimal(12,2) NOT NULL DEFAULT 0.00,
    `amount_cash`   decimal(12,2) NOT NULL DEFAULT 0.00,
    `amount_qr`     decimal(12,2) NOT NULL DEFAULT 0.00,
    `sort_order`    int(4) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Section => which amount columns it uses + labels (drives PHP render + JS)
$EXTRA_SECS = [
    'advance_deposit' => ['label' => 'ADVANCE DEPOSIT', 'cols' => [
        ['key' => 'amount_card', 'label' => 'Amount (Card)'],
        ['key' => 'amount_cash', 'label' => 'Amount (Cash)'],
    ]],
    'bar_sales' => ['label' => 'BAR SALES', 'cols' => [
        ['key' => 'amount_cash', 'label' => 'Cash'],
        ['key' => 'amount_card', 'label' => 'Card/Swipe/QR'],
    ]],
];

// ── Auto-create shared report_locks table (safe to run repeatedly) ─
// Shared with h_summary_report.php — a date locked from the Summary
// Report becomes read-only here too.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `report_locks` (
        `id`          int(11) NOT NULL AUTO_INCREMENT,
        `store_name`  varchar(50) NOT NULL,
        `report_date` date NOT NULL,
        `locked_by`   varchar(100) DEFAULT NULL,
        `locked_at`   timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_store_date` (`store_name`,`report_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Throwable $ignored) {}

function isDateLocked(PDO $pdo, string $store, string $date): bool {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM report_locks WHERE store_name=? AND report_date=? LIMIT 1");
        $stmt->execute([$store, $date]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

// ── AJAX: Lock / unlock a date ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_toggle_lock'])) {
    header('Content-Type: application/json');
    if (!$canLock) {
        echo json_encode(['ok' => false, 'msg' => 'Only management can lock or unlock reports.']);
        exit;
    }
    $reportDate = $_POST['report_date'] ?? '';
    $lock       = ($_POST['lock'] ?? '') === '1';
    if (!$reportDate) {
        echo json_encode(['ok' => false, 'msg' => 'Missing date.']);
        exit;
    }
    try {
        if ($lock) {
            $pdo->prepare("INSERT INTO report_locks (store_name, report_date, locked_by)
                VALUES ('H', ?, ?)
                ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), locked_at = CURRENT_TIMESTAMP")
                ->execute([$reportDate, $user['name']]);
        } else {
            $pdo->prepare("DELETE FROM report_locks WHERE store_name='H' AND report_date=?")
                ->execute([$reportDate]);
        }
        echo json_encode(['ok' => true, 'locked' => $lock]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Save main summary ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    try {
        $reportDate = $_POST['report_date'] ?? date('Y-m-d');
        if (isDateLocked($pdo, 'H', $reportDate)) {
            echo json_encode(['ok' => false, 'msg' => 'This date is locked. Ask management to unlock it before editing.']);
            exit;
        }
        $NUM_COLS = ['gross_sales','service_charge','z_reading_gross','total_swipe',
            'deposit_swipe_card','late_payment_card','maya_swipe','unpaid_med_credit',
            'grab_sales','bar_sales','gcash','gift_card','marketing_pull_out','discount',
            'bank_transfer_cheque','pcf_expenses','other_expenses','coh','carwash_sales'];
        $vals = [];
        foreach ($NUM_COLS as $f) $vals[$f] = (float)($_POST[$f] ?? 0);

        // DINE IN totals. Cash+Palawan Pay+Card/Swipe/QR+Unpaid/Credit Amount
        // is the exact set of columns the Excel's K26 ("DINE IN TOTAL") sums —
        // Discount, Bank Transfer/Cheque and Cancelled Transactions are
        // tracked in the table but are NOT part of that total.
        $diSumStmt = $pdo->prepare("SELECT
                COALESCE(SUM(cash),0)                  AS cash,
                COALESCE(SUM(palawan_pay),0)           AS gcash,
                COALESCE(SUM(card_swipe_qr),0)         AS card_swipe_qr,
                COALESCE(SUM(unpaid_credit_amount),0)  AS unpaid_med_credit,
                COALESCE(SUM(discount),0)              AS discount,
                COALESCE(SUM(bank_transfer_cheque),0)  AS bank_transfer_cheque
            FROM h_dinein_rows WHERE store_name='H' AND report_date=?");
        $diSumStmt->execute([$reportDate]);
        $diSums = $diSumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['unpaid_med_credit','discount','bank_transfer_cheque','gcash'] as $f) {
            $vals[$f] = (float)($diSums[$f] ?? 0);
        }
        $dineInTotal = (float)($diSums['cash'] ?? 0) + (float)($diSums['gcash'] ?? 0)
                     + (float)($diSums['card_swipe_qr'] ?? 0) + (float)($diSums['unpaid_med_credit'] ?? 0);

        $secSumStmt = $pdo->prepare("SELECT section, COALESCE(SUM(amount),0) AS total
            FROM h_sales_detail_rows WHERE store_name='H' AND report_date=? GROUP BY section");
        $secSumStmt->execute([$reportDate]);
        $secSums = [];
        foreach ($secSumStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $secSums[$r['section']] = (float)$r['total'];
        $vals['grab_sales']         = $secSums['grab'] ?? 0;
        $vals['marketing_pull_out'] = $secSums['marketing_pullout'] ?? 0;
        $vals['pcf_expenses']       = $secSums['expenses'] ?? 0;
        // Gift Card mirrors the Excel exactly: the summary's "Gift Card" row
        // is fed by the GC SPONSORSHIP detail table's total (not GC Sold).
        $vals['gift_card']          = $secSums['gc_sponsorship'] ?? 0;

        // Bar Sales gets its own Summary row (matching the Excel's Bar Sales
        // row) — sums all of its amount columns (cash+card). Advance Deposit
        // is memo-only: it does not appear anywhere in the Excel's Gross/Net
        // Sales formula.
        $extraSumStmt = $pdo->prepare("SELECT section, COALESCE(SUM(amount_card),0)+COALESCE(SUM(amount_cash),0)+COALESCE(SUM(amount_qr),0) AS total
            FROM h_extra_sales_rows WHERE store_name='H' AND report_date=? GROUP BY section");
        $extraSumStmt->execute([$reportDate]);
        $extraSums = [];
        foreach ($extraSumStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $extraSums[$r['section']] = (float)$r['total'];
        $vals['bar_sales']     = $extraSums['bar_sales'] ?? 0;

        $vals['total_swipe'] = $vals['deposit_swipe_card'] + $vals['late_payment_card'] + $vals['maya_swipe'];

        // Z READING GROSS (auto, mirrors Excel C8): DINE IN total + Marketing
        // Pullout + Grab + Gift Card (GC Sponsorship) + Bar Sales + Carwash Sales.
        $vals['z_reading_gross'] = $dineInTotal + $vals['marketing_pull_out'] + $vals['grab_sales']
                                  + $vals['gift_card'] + $vals['bar_sales'] + $vals['carwash_sales'];

        // GROSS SALES (mirrors Excel C6): Marketing Pullout is added inside
        // Z Reading Gross above and subtracted again here — it cancels out
        // by design, matching the spreadsheet exactly.
        $vals['gross_sales'] = $vals['z_reading_gross'] - $vals['service_charge']
                              + $vals['discount'] - $vals['marketing_pull_out'];

        // NET SALES (mirrors Excel C24). Marketing Pull Out and Bar Sales are
        // intentionally NOT subtracted here — neither is in the Excel's Net
        // Sales formula (Marketing already nets to zero above; Bar Sales is
        // simply left out of the deduction list in the spreadsheet).
        $netSales = $vals['gross_sales']
                  - $vals['maya_swipe']
                  - $vals['unpaid_med_credit']
                  - $vals['grab_sales']
                  - $vals['gcash']
                  - $vals['gift_card']
                  - $vals['bank_transfer_cheque']
                  - $vals['pcf_expenses']
                  - $vals['other_expenses']
                  - $vals['discount'];
        $shortOver = $vals['coh'] - $netSales;

        $openingCashier = trim((string)($_POST['opening_cashier'] ?? ''));
        $closingCashier = trim((string)($_POST['closing_cashier'] ?? ''));

        $fields    = array_merge(['store_name','report_date'], $NUM_COLS, ['net_sales','short_over','saved_by','opening_cashier','closing_cashier']);
        $data      = array_merge(['H', $reportDate], array_values($vals), [$netSales, $shortOver, $user['name'], $openingCashier, $closingCashier]);
        $dupUpdate = implode(',', array_map(fn($f) => "`$f`=VALUES(`$f`)", array_merge($NUM_COLS, ['net_sales','short_over','saved_by','opening_cashier','closing_cashier'])));
        $sql = "INSERT INTO h_sales_report (" . implode(',', array_map(fn($f)=>"`$f`",$fields)) . ")
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
        if (isDateLocked($pdo, 'H', $reportDate)) {
            echo json_encode(['ok' => false, 'msg' => 'This date is locked. Ask management to unlock it before editing.']);
            exit;
        }
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        $pdo->prepare("DELETE FROM h_dinein_rows WHERE store_name='H' AND report_date=?")->execute([$reportDate]);
        $ins = $pdo->prepare("INSERT INTO h_dinein_rows (store_name,report_date,cash,palawan_pay,card_swipe_qr,unpaid_credit_name,unpaid_credit_amount,discount,bank_transfer_cheque,cancelled_transactions,sort_order) VALUES ('H',?,?,?,?,?,?,?,?,?,?)");
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
        if (isDateLocked($pdo, 'H', $reportDate)) {
            echo json_encode(['ok' => false, 'msg' => 'This date is locked. Ask management to unlock it before editing.']);
            exit;
        }
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        $pdo->prepare("DELETE FROM h_sales_detail_rows WHERE store_name='H' AND report_date=? AND section=?")->execute([$reportDate,$section]);
        $ins = $pdo->prepare("INSERT INTO h_sales_detail_rows (store_name,report_date,section,item_name,amount,sort_order) VALUES ('H',?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$reportDate, $section, $r['name']??null, (float)($r['amount']??0), $i]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Save extra multi-amount section rows (Advance Deposit /
//          Bar Sales) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save_extra'])) {
    header('Content-Type: application/json');
    try {
        $reportDate = $_POST['report_date'];
        $section    = $_POST['section'];
        if (isDateLocked($pdo, 'H', $reportDate)) {
            echo json_encode(['ok' => false, 'msg' => 'This date is locked. Ask management to unlock it before editing.']);
            exit;
        }
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        $pdo->prepare("DELETE FROM h_extra_sales_rows WHERE store_name='H' AND report_date=? AND section=?")->execute([$reportDate,$section]);
        $ins = $pdo->prepare("INSERT INTO h_extra_sales_rows (store_name,report_date,section,item_name,amount_card,amount_cash,amount_qr,sort_order) VALUES ('H',?,?,?,?,?,?,?)");
        foreach ($rows as $i => $r) {
            $ins->execute([$reportDate, $section, $r['name']??null,
                (float)($r['amount_card']??0), (float)($r['amount_cash']??0), (float)($r['amount_qr']??0), $i]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── CSV Export ─────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    $reportDate = $_GET['date'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM h_sales_report WHERE store_name='H' AND report_date=?");
    $stmt->execute([$reportDate]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $g = fn($k) => number_format((float)($r[$k] ?? 0), 2, '.', '');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="H_SalesReport_'.$reportDate.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['SUMMARY REPORT — H Branch', date('F j, Y', strtotime($reportDate))]);
    fputcsv($out, ['Opening Cashier', (string)($r['opening_cashier'] ?? '')]);
    fputcsv($out, ['Closing Cashier', (string)($r['closing_cashier'] ?? '')]);
    fputcsv($out, []);
    fputcsv($out, ['Gross Sales', $g('gross_sales')]);
    fputcsv($out, ['Service Charge', $g('service_charge')]);
    fputcsv($out, ['Carwash Sales', $g('carwash_sales')]);
    fputcsv($out, ['Z Reading Gross', $g('z_reading_gross')]);
    $advDepStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_card),0)+COALESCE(SUM(amount_cash),0)+COALESCE(SUM(amount_qr),0) AS total FROM h_extra_sales_rows WHERE store_name='H' AND report_date=? AND section='advance_deposit'");
    $advDepStmt->execute([$reportDate]);
    fputcsv($out, ['Advance Deposit (memo only — not part of Gross/Net Sales)', number_format((float)($advDepStmt->fetchColumn() ?: 0), 2, '.', '')]);
    fputcsv($out, ['Total Swipe', $g('total_swipe')]);
    fputcsv($out, ['Deposit Swipe (Card)', $g('deposit_swipe_card')]);
    fputcsv($out, ['Late Payment (Card)', $g('late_payment_card')]);
    fputcsv($out, ['Sales of the day Swipe (MAYA)', $g('maya_swipe')]);
    fputcsv($out, ['Unpaid Med (Credit)', $g('unpaid_med_credit')]);
    fputcsv($out, ['Grab Sales', $g('grab_sales')]);
    fputcsv($out, ['Bar Sales', $g('bar_sales')]);
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
$fDate  = $_GET['date'] ?? date('Y-m-d');
$locked = isDateLocked($pdo, 'H', $fDate);
$stmt  = $pdo->prepare("SELECT * FROM h_sales_report WHERE store_name='H' AND report_date=?");
$stmt->execute([$fDate]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$v   = fn($k) => (float)($row[$k] ?? 0);
$vs  = fn($k) => (string)($row[$k] ?? '');
$fmt = fn($n)  => number_format((float)$n, 2);

// Fetch DINE IN rows
$diStmt = $pdo->prepare("SELECT * FROM h_dinein_rows WHERE store_name='H' AND report_date=? ORDER BY sort_order ASC");
$diStmt->execute([$fDate]);
$dineinRows = $diStmt->fetchAll(PDO::FETCH_ASSOC);
$diSum = fn($col) => array_sum(array_column($dineinRows, $col));

// Fetch sub-section detail rows
$detailStmt = $pdo->prepare("SELECT * FROM h_sales_detail_rows WHERE store_name='H' AND report_date=? ORDER BY section, sort_order ASC");
$detailStmt->execute([$fDate]);
$allDetails = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
$details = [];
foreach ($allDetails as $d) $details[$d['section']][] = $d;
$secSum = fn($sec) => array_sum(array_column($details[$sec] ?? [], 'amount'));

// Auto-calculated pink-section values (Other Expenses stays manual)
$cashCalc          = $diSum('cash');
$cardCalc          = $diSum('card_swipe_qr');
$unpaidMedCalc     = $diSum('unpaid_credit_amount');
$discountCalc      = $diSum('discount');
$bankTransferCalc  = $diSum('bank_transfer_cheque');
$gcashCalc         = $diSum('palawan_pay');
$grabCalc          = $secSum('grab');
$marketingCalc     = $secSum('marketing_pullout');
$pcfCalc           = $secSum('expenses');
$giftCardCalc      = $secSum('gc_sponsorship'); // Excel's "Gift Card" row is fed by GC Sponsorship

// Fetch Advance Deposit / Bar Sales rows
$extraStmt = $pdo->prepare("SELECT * FROM h_extra_sales_rows WHERE store_name='H' AND report_date=? ORDER BY section, sort_order ASC");
$extraStmt->execute([$fDate]);
$extraDetails = [];
foreach ($extraStmt->fetchAll(PDO::FETCH_ASSOC) as $er) $extraDetails[$er['section']][] = $er;
$extraSecTotal = function($sec) use ($extraDetails) {
    $rows = $extraDetails[$sec] ?? [];
    $t = 0;
    foreach ($rows as $r) $t += (float)$r['amount_card'] + (float)$r['amount_cash'] + (float)$r['amount_qr'];
    return $t;
};
// Bar Sales gets its own Summary row and feeds Z Reading Gross. Advance
// Deposit is memo-only — it's tracked in its own table but never appears
// in the Excel's Gross/Net Sales formula.
$barCalc = $extraSecTotal('bar_sales');

// Z READING GROSS (auto, mirrors Excel C8): DINE IN total (Cash+Palawan
// Pay+Card/Swipe/QR+Unpaid/Credit Amount) + Marketing Pullout + Grab +
// Gift Card (GC Sponsorship) + Bar Sales + Carwash Sales.
$dineInTotalCalc = $cashCalc + $gcashCalc + $cardCalc + $unpaidMedCalc;
$zReadingGrossCalc = $dineInTotalCalc + $marketingCalc + $grabCalc + $giftCardCalc + $barCalc + $v('carwash_sales');

// GROSS SALES (mirrors Excel C6) — Marketing Pullout is added inside Z
// Reading Gross above and subtracted again here, cancelling out exactly
// like the spreadsheet.
$grossSalesCalc = $zReadingGrossCalc - $v('service_charge') + $discountCalc - $marketingCalc;

$pageTitle  = 'H Sales Report';
$activePage = 'h_sales_report';
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
.sr-oc-bar { display:flex; align-items:center; gap:12px; background:#fff; border:1px solid var(--border); border-radius:10px; padding:12px 16px; margin-bottom:14px; box-shadow:0 1px 2px rgba(0,0,0,.04); flex-wrap:wrap; }
.sr-oc-bar .sr-oc-field { display:flex; align-items:center; gap:12px; flex:1; min-width:220px; }
.sr-oc-bar label { font-family:var(--font-m); font-size:.85rem; font-weight:800; color:var(--text); white-space:nowrap; }
.sr-oc-bar input { flex:1; min-width:0; padding:10px 14px; font-family:var(--font-m); font-size:.95rem; font-weight:700; text-align:left; color:var(--text); background:#fff; border:1px solid var(--border); border-radius:8px; outline:none; transition:border-color .15s; }
.sr-oc-bar input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(15,123,92,.08); }
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

/* ── Extra sections row (Advance Deposit / Bar side-by-side) ── */
.extra-sections-row {
  display:flex; gap:16px; margin-bottom:22px; align-items:flex-start;
}
.extra-sections-row .extra-section-col {
  flex:1 1 0; min-width:0; margin-bottom:0;
}
.extra-section-col .di-table th,
.extra-section-col .di-table td { padding:5px 6px; font-size:.7rem; }
.extra-section-col .di-inp { font-size:.7rem; }
.extra-section-col .di-table th:first-child,
.extra-section-col .di-table td:first-child { text-align:left; }
@media (max-width:900px) {
  .extra-sections-row { flex-direction:column; }
}

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
input[type=number] { appearance:textfield; -moz-appearance:textfield; }
input[type=number]::-webkit-outer-spin-button,
input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.sr-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(15,123,92,.08); }
.sr-input.readout { background:rgba(255,255,255,.9); font-size:1rem; font-weight:800; cursor:default; color:#1a1d23; border-color:transparent; }
.sr-row.net   .sr-input.readout { background:#fff; color:#7a5c00; }
.sr-row.coh   .sr-input          { background:#fff; }
.sr-row.short .sr-input.readout { background:#fff; color:#b71c1c; }
.toast { position:fixed; top:68px; right:22px; z-index:9999; max-width:320px; animation:fadeSlideDown .3s ease; }

/* ── Locked banner + read-only state ── */
.locked-banner {
  display:flex; align-items:center; gap:8px;
  background:#fff1f2; border:1px solid #fecdd3; color:#9f1239;
  padding:10px 16px; border-radius:8px; margin-bottom:16px;
  font-family:var(--font-m); font-size:.76rem; font-weight:600;
}
input[disabled], .sr-input[disabled] { opacity:.65; cursor:not-allowed !important; }

/* ── Lock button + popover ── */
.lock-wrap { position:relative; display:inline-block; }
.btn-lock {
  display:inline-flex; align-items:center; gap:6px;
  padding:9px 16px; border-radius:8px; cursor:pointer;
  font-family:var(--font-m); font-size:.78rem; font-weight:700;
  border:1px solid var(--border); background:#fff; color:var(--text);
  transition:all .15s;
}
.btn-lock:hover { background:#f8f9fb; }
.btn-lock.is-locked { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.btn-lock.is-locked:hover { background:#ffe4e6; }
.lock-badge-static {
  display:inline-flex; align-items:center; gap:6px;
  padding:9px 16px; border-radius:8px;
  font-family:var(--font-m); font-size:.78rem; font-weight:700;
  border:1px solid var(--border); background:#f8f9fb; color:var(--subtext);
}
.lock-popover {
  display:none; position:absolute; top:calc(100% + 8px); left:0; z-index:200;
  width:280px; background:#fff; border:1px solid var(--border); border-radius:10px;
  box-shadow:0 10px 30px rgba(0,0,0,.14); padding:16px; text-align:left;
}
.lock-popover-title { font-family:var(--font-m); font-size:.85rem; font-weight:800; color:var(--text); margin-bottom:6px; }
.lock-popover-date  { font-family:var(--font-m); font-size:.8rem; font-weight:700; color:var(--accent); margin-bottom:8px; }
.lock-popover-desc  { font-family:var(--font-h); font-size:.75rem; color:var(--subtext); line-height:1.45; margin-bottom:14px; }
.lock-popover-actions { display:flex; justify-content:flex-end; gap:8px; }
.lock-popover-actions button {
  padding:7px 14px; border-radius:6px; font-family:var(--font-m); font-size:.72rem; font-weight:700;
  cursor:pointer; border:1px solid var(--border); background:#fff; color:var(--text);
}
.lock-popover-actions .btn-confirm { background:#be123c; border-color:#be123c; color:#fff; }
.lock-popover-actions .btn-confirm:hover { background:#9f1239; }
.lock-popover-actions .btn-confirm.unlock { background:#0f7b5c; border-color:#0f7b5c; }
.lock-popover-actions .btn-confirm.unlock:hover { background:#0b5f47; }
.lock-popover-actions .btn-cancel:hover { background:#f8f9fb; }
</style>

<!-- Header -->
<div class="sr-header-card">
  <div>
    <div class="eyebrow">H Branch · Sales</div>
    <div class="title">Daily Sales Report</div>
    <div class="subtitle">DINE IN detail + sub-sections · Net Sales &amp; Short/Over auto-calculated</div>
  </div>
  <span style="background:rgba(255,255,255,.1);color:#fff;padding:5px 14px;border-radius:20px;font-family:var(--font-m);font-size:.65rem;font-weight:600;align-self:flex-start">📌 H</span>
</div>

<?php if ($locked): ?>
<div class="locked-banner">
  🔒 <?= date('M j, Y', strtotime($fDate)) ?> is locked by management. All fields on this page are read-only.
</div>
<?php endif; ?>

<!-- Opening / Closing Cashier -->
<div class="sr-oc-bar">
  <div class="sr-oc-field">
    <label for="opening_cashier">Opening Cashier:</label>
    <input type="text" id="opening_cashier" style="text-transform:uppercase" value="<?= htmlspecialchars($vs('opening_cashier')) ?>" placeholder="e.g. HENCEL">
  </div>
  <div class="sr-oc-field">
    <label for="closing_cashier">Closing Cashier:</label>
    <input type="text" id="closing_cashier" style="text-transform:uppercase" value="<?= htmlspecialchars($vs('closing_cashier')) ?>" placeholder="e.g. HENCEL">
  </div>
</div>

<!-- Controls -->
<div class="sr-controls">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($fDate) ?>" onchange="this.form.submit()">
    <button type="button" class="btn btn-primary" onclick="saveAll()">💾 Save All</button>
    <a href="h_sales_report.php?export_csv=1&date=<?= htmlspecialchars($fDate) ?>" class="btn btn-ghost">⬇ Download CSV</a>
    <span id="saveStatus" class="sr-save-status"></span>
  </form>

  <?php if ($canLock): ?>
  <div class="lock-wrap">
    <button type="button" class="btn-lock <?= $locked ? 'is-locked' : '' ?>" id="lockBtn" onclick="toggleLockPopover(event)"
      title="<?= $locked ? 'Unlock '.date('M j, Y', strtotime($fDate)) : 'Lock '.date('M j, Y', strtotime($fDate)) ?>">
      <?= $locked ? '🔒 Locked' : '🔓 Lock' ?>
    </button>
    <div class="lock-popover" id="lockPopover">
      <div class="lock-popover-title"><?= $locked ? 'Unlock this date?' : 'Lock this date?' ?></div>
      <div class="lock-popover-date">📅 <?= date('l, M j, Y', strtotime($fDate)) ?></div>
      <div class="lock-popover-desc">
        <?= $locked
            ? 'This will make the Sales Report editable again for this date.'
            : 'This will make the Sales Report read-only for this date. No one will be able to edit or save changes until it’s unlocked.' ?>
      </div>
      <div class="lock-popover-actions">
        <button type="button" class="btn-cancel" onclick="hideLockPopover()">Cancel</button>
        <button type="button" class="btn-confirm <?= $locked ? 'unlock' : '' ?>" onclick="confirmToggleLock()"><?= $locked ? 'Unlock' : 'Lock' ?></button>
      </div>
    </div>
  </div>
  <?php elseif ($locked): ?>
  <span class="lock-badge-static" title="Locked by management">🔒 Locked</span>
  <?php endif; ?>
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
        <th>Bank Transfer/Cheque</th>
        <th>Cancelled Transactions</th>
        <th style="width:40px"></th>
      </tr>
      <tr>
        <th class="sub"></th><th class="sub"></th><th class="sub"></th>
        <th class="sub">NAME</th><th class="sub">AMOUNT</th>
        <th class="sub"></th><th class="sub"></th><th class="sub"></th><th class="sub"></th>
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
        <td id="di-tot-bank">0.00</td>
        <td id="di-tot-cancelled">0.00</td>
        <td></td>
      </tr>
      <tr>
        <td class="total-label" colspan="3">TOTAL</td>
        <td class="grand-total" colspan="6" id="di-grand-total">0.00</td>
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
     EXTRA SECTIONS: Advance Deposit | Bar Sales
════════════════════════════════════════════════════════════ -->
<div class="extra-sections-row">
<?php foreach ($EXTRA_SECS as $sec => $cfg):
    $secRows = $extraDetails[$sec] ?? [];
    $ncols   = count($cfg['cols']);
?>
<div class="sr-section extra-section-col">
  <div class="sr-section-title dark-red"><?= $cfg['label'] ?></div>
  <div style="overflow-x:auto">
  <table class="di-table extra-table" data-section="<?= $sec ?>">
    <thead>
      <tr>
        <th>Name</th>
        <?php foreach ($cfg['cols'] as $c): ?><th><?= htmlspecialchars($c['label']) ?></th><?php endforeach; ?>
        <th style="width:40px"></th>
      </tr>
    </thead>
    <tbody id="extra-body-<?= $sec ?>">
      <?php if (empty($secRows)) $secRows = [['item_name'=>'','amount_card'=>0,'amount_cash'=>0,'amount_qr'=>0]]; ?>
      <?php foreach ($secRows as $er): ?>
      <tr>
        <td><input class="di-inp txt extra-inp-name" type="text" value="<?= htmlspecialchars($er['item_name'] ?? '') ?>" placeholder="Name"></td>
        <?php foreach ($cfg['cols'] as $c): ?>
        <td><input class="di-inp extra-inp num" type="number" step="0.01" value="<?= (float)($er[$c['key']] ?? 0) ?: '' ?>" placeholder="" oninput="extraChanged('<?= $sec ?>')"></td>
        <?php endforeach; ?>
        <td><button class="btn-del-row" onclick="delExtraRow(this,'<?= $sec ?>')">✕</button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="total-label">Total</td>
        <?php foreach ($cfg['cols'] as $c): ?>
        <td id="extra-tot-<?= $sec ?>-<?= $c['key'] ?>">0.00</td>
        <?php endforeach; ?>
        <td></td>
      </tr>
      <tr>
        <td class="total-label" colspan="1">TOTAL</td>
        <td class="grand-total" colspan="<?= $ncols + 1 ?>" id="extra-grand-<?= $sec ?>"><?= number_format($extraSecTotal($sec), 2) ?></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <button class="btn-add-row" onclick="addExtraRow('<?= $sec ?>')">+ Add Row</button>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     SUMMARY REPORT (existing fields — auto-fed from above)
════════════════════════════════════════════════════════════ -->
<div class="sr-wrap">
<div class="sr-card">
  <div class="sr-title-row"><div class="sr-main-title">Summary Report</div></div>

  <div class="sr-row" style="background:#f8f9fb">
    <div class="sr-label" style="text-align:left;flex:1">For</div>
    <div class="sr-value-wrap"><span class="sr-input readout" style="font-size:.8rem"><?= date('n/j/Y', strtotime($fDate)) ?></span></div>
  </div>

  <div class="sr-row green">
    <div class="sr-label">Gross Sales <span style="font-size:.65rem;opacity:.7">(auto: Z Reading + Additional Sales − Service Charge + Discount − Marketing Pull Out)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="gross_sales" value="<?= $fmt($grossSalesCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">Service Charge</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="service_charge" value="<?= $v('service_charge') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
    <div class="sr-note" style="color:#fff">(separate deposit slip)</div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">Carwash Sales</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="carwash_sales" value="<?= $v('carwash_sales') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">Z Reading Gross <span style="font-size:.65rem;opacity:.7">(auto: DINE IN + Marketing Pullout + Grab + GC Sponsorship + Bar Sales + Carwash Sales)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="z_reading_gross" value="<?= $fmt($zReadingGrossCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">Total Swipe <span style="font-size:.65rem;opacity:.7">(auto: Deposit + Late Payment + Maya)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="total_swipe" value="<?= $fmt($v('deposit_swipe_card')+$v('late_payment_card')+$v('maya_swipe')) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row gray-hdr">
    <div class="sr-label">Deposit Swipe (Card)</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="deposit_swipe_card" value="<?= $v('deposit_swipe_card') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
  </div>
  <div class="sr-row gray-hdr">
    <div class="sr-label">Late Payment (Card) <span style="font-size:.65rem;opacity:.7">(auto from Late Payment)</span></div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="late_payment_card" value="<?= $v('late_payment_card') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
  </div>
  <div class="sr-row green">
    <div class="sr-label">Sales of the day Swipe (MAYA)</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="maya_swipe" value="<?= $v('maya_swipe') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
    <div class="sr-note" style="color:#fff">(data input based on app)</div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Unpaid Med (Credit) <span style="font-size:.65rem;opacity:.7">(auto from Unpaid/Credit)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="unpaid_med_credit" value="<?= $fmt($unpaidMedCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Grab Sales <span style="font-size:.65rem;opacity:.7">(auto from GRAB)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="grab_sales" value="<?= $fmt($grabCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Bar Sales <span style="font-size:.65rem;opacity:.7">(auto from Bar Sales)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="bar_sales" value="<?= $fmt($barCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">G-Cash <span style="font-size:.65rem;opacity:.7">(auto from Palawan Pay)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="gcash" value="<?= $fmt($gcashCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Gift Card <span style="font-size:.65rem;opacity:.7">(auto from GC Sponsorship)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="gift_card" value="<?= $fmt($giftCardCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Marketing Pull Out <span style="font-size:.65rem;opacity:.7">(auto from Marketing Pullout)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="marketing_pull_out" value="<?= $fmt($marketingCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Discount <span style="font-size:.65rem;opacity:.7">(auto from Discount col)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="discount" value="<?= $fmt($discountCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Bank Transfer/Cheque <span style="font-size:.65rem;opacity:.7">(auto from DINE IN)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="bank_transfer_cheque" value="<?= $fmt($bankTransferCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">PCF/Expenses <span style="font-size:.65rem;opacity:.7">(auto from Expenses)</span></div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="pcf_expenses" value="<?= $fmt($pcfCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row pink-val">
    <div class="sr-label">Other Expenses</div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="other_expenses" value="<?= $v('other_expenses') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
  </div>

  <div class="sr-row net">
    <div class="sr-label">Net Sales</div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="net_sales" value="<?= $fmt($grossSalesCalc - $v('maya_swipe') - $unpaidMedCalc - $grabCalc - $gcashCalc - $giftCardCalc - $bankTransferCalc - $pcfCalc - $v('other_expenses') - $discountCalc) ?>" readonly tabindex="-1"></div>
  </div>
  <div class="sr-row coh">
    <div class="sr-label">COH (Cash on Hand) <span style="font-size:.65rem;opacity:.7">(manual — actual cash counted)</span></div>
    <div class="sr-value-wrap"><input type="number" step="0.01" class="sr-input" id="coh" value="<?= $v('coh') ?: '' ?>" oninput="recalc()" placeholder="0.00"></div>
    <div class="sr-note" style="color:#cfd8ea">(cash sales only except SC)</div>
  </div>
  <div class="sr-row short">
    <div class="sr-label">Short/Over</div>
    <div class="sr-value-wrap"><input type="text" class="sr-input readout" id="short_over" value="0.00" readonly tabindex="-1"></div>
    <div class="sr-note" style="color:#ffd8d8">(if "-" sign means short)</div>
  </div>
</div>
</div>

<script>
const FDATE  = '<?= $fDate ?>';
const REPORT_LOCKED = <?= $locked ? 'true' : 'false' ?>;
const fmt    = n => Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const gv     = id => parseFloat(String(document.getElementById(id)?.value ?? '').replace(/,/g,'')) || 0;
const setVal = (id,v) => { const el=document.getElementById(id); if(el) el.value = v; };

// DINE IN grand total = Cash+Palawan Pay+Card/Swipe/QR+Unpaid/Credit Amount
// (mirrors the Excel's K26 "DINE IN TOTAL" — Discount, Bank Transfer/Cheque
// and Cancelled Transactions are tracked but excluded from this total).
let DI_GRAND_TOTAL = 0;

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
    bank_transfer_cheque:(float(inps[6])),
    cancelled_transactions:(float(inps[7])),
  };
}
function float(inp) { return parseFloat(inp?.value) || 0; }

function diChanged() {
  let totCash=0,totPal=0,totCard=0,totUnpaid=0,totDisc=0,totBank=0,totCan=0;
  document.querySelectorAll('#di-body tr').forEach(tr => {
    const c = diCols(tr);
    totCash   += c.cash; totPal += c.palawan_pay; totCard += c.card_swipe_qr;
    totUnpaid += c.unpaid_credit_amount; totDisc += c.discount;
    totBank   += c.bank_transfer_cheque; totCan += c.cancelled_transactions;
  });
  const grand = totCash + totPal + totCard + totUnpaid;
  DI_GRAND_TOTAL = grand;
  document.getElementById('di-tot-cash').textContent      = fmt(totCash);
  document.getElementById('di-tot-palawan').textContent   = fmt(totPal);
  document.getElementById('di-tot-card').textContent      = fmt(totCard);
  document.getElementById('di-tot-unpaid').textContent    = fmt(totUnpaid);
  document.getElementById('di-tot-disc').textContent      = fmt(totDisc);
  document.getElementById('di-tot-bank').textContent      = fmt(totBank);
  document.getElementById('di-tot-cancelled').textContent = fmt(totCan);
  document.getElementById('di-grand-total').textContent   = fmt(grand);

  // Auto-feed summary fields
  setVal('unpaid_med_credit',   fmt(totUnpaid));
  setVal('discount',            fmt(totDisc));
  setVal('bank_transfer_cheque',fmt(totBank));
  setVal('gcash',               fmt(totPal));
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
  if (sec === 'grab')              { setVal('grab_sales', fmt(tot)); recalc(); }
  if (sec === 'marketing_pullout') { setVal('marketing_pull_out', fmt(tot)); recalc(); }
  if (sec === 'expenses')          { setVal('pcf_expenses', fmt(tot)); recalc(); }
  if (sec === 'late_payment')      { setVal('late_payment_card', fmt(tot)); recalc(); }
  if (sec === 'gc_sponsorship')    { setVal('gift_card', fmt(tot)); recalc(); }
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

// ── Extra sections helpers (Advance Deposit / Bar Sales) ──
const EXTRA_COLS = {
  advance_deposit: ['amount_card','amount_cash'],
  bar_sales:       ['amount_cash','amount_card'],
};

function extraChanged(sec) {
  const cols  = EXTRA_COLS[sec];
  const table = document.querySelector(`.extra-table[data-section="${sec}"]`);
  if (!table) return;
  const totals = {}; cols.forEach(c => totals[c] = 0);

  table.querySelectorAll('tbody tr').forEach(tr => {
    const amtInps = tr.querySelectorAll('.extra-inp.num');
    cols.forEach((c, i) => { totals[c] += parseFloat(amtInps[i]?.value) || 0; });
  });

  let secTotal = 0;
  cols.forEach(c => {
    secTotal += totals[c];
    const el = document.getElementById(`extra-tot-${sec}-${c}`);
    if (el) el.textContent = fmt(totals[c]);
  });
  const grandEl = document.getElementById(`extra-grand-${sec}`);
  if (grandEl) grandEl.textContent = fmt(secTotal);

  // Bar Sales feeds its own Summary row (mirrors the Excel's U22 reference
  // into Z Reading Gross). Advance Deposit is memo-only — it never appears
  // in the Excel's Gross/Net Sales formula.
  if (sec === 'bar_sales')     { setVal('bar_sales', fmt(secTotal)); recalc(); }
}

function addExtraRow(sec) {
  const cols = EXTRA_COLS[sec];
  const body = document.getElementById(`extra-body-${sec}`);
  const tr = document.createElement('tr');
  let cells = `<td><input class="di-inp txt extra-inp-name" type="text" placeholder="Name"></td>`;
  cols.forEach(() => { cells += `<td><input class="di-inp extra-inp num" type="number" step="0.01" placeholder="" oninput="extraChanged('${sec}')"></td>`; });
  cells += `<td><button class="btn-del-row" onclick="delExtraRow(this,'${sec}')">✕</button></td>`;
  tr.innerHTML = cells;
  body.appendChild(tr);
}

function delExtraRow(btn, sec) {
  btn.closest('tr').remove();
  extraChanged(sec);
}

// ── Summary recalc (mirrors the Excel's formula chain exactly) ──
function recalc() {
  const totalSwipe = gv('deposit_swipe_card') + gv('late_payment_card') + gv('maya_swipe');
  document.getElementById('total_swipe').value = fmt(totalSwipe);

  // Z READING GROSS (auto, mirrors Excel C8) = DINE IN total + Marketing
  // Pullout + Grab + Gift Card (GC Sponsorship) + Bar Sales + Carwash Sales.
  const zReadingGross = DI_GRAND_TOTAL + gv('marketing_pull_out') + gv('grab_sales')
                       + gv('gift_card') + gv('bar_sales') + gv('carwash_sales');
  document.getElementById('z_reading_gross').value = fmt(zReadingGross);

  // GROSS SALES (mirrors Excel C6) — Marketing Pullout is added inside Z
  // Reading Gross above and subtracted again here; it cancels out exactly
  // like the spreadsheet.
  const grossSales = zReadingGross - gv('service_charge') + gv('discount') - gv('marketing_pull_out');
  document.getElementById('gross_sales').value = fmt(grossSales);

  // NET SALES (mirrors Excel C24). Marketing Pull Out and Bar Sales are
  // intentionally NOT subtracted — neither is in the Excel's Net Sales
  // formula (Marketing already nets to zero above; Bar Sales is simply
  // left out of the deduction list in the spreadsheet).
  const netSales = grossSales
                 - gv('maya_swipe')
                 - gv('unpaid_med_credit')
                 - gv('grab_sales')
                 - gv('gcash')
                 - gv('gift_card')
                 - gv('bank_transfer_cheque')
                 - gv('pcf_expenses')
                 - gv('other_expenses')
                 - gv('discount');
  const shortOver = gv('coh') - netSales;
  document.getElementById('net_sales').value = fmt(netSales);
  const soEl = document.getElementById('short_over');
  soEl.value = fmt(shortOver);
  soEl.style.color = shortOver < 0 ? '#ffd54f' : '#b71c1c';
}

// ── Save All ───────────────────────────────────────────────
async function saveAll() {
  if (REPORT_LOCKED) {
    showToast('🔒 This date is locked and cannot be edited.', 'error');
    return;
  }
  const status = document.getElementById('saveStatus');
  status.textContent = 'Saving…';

  // 1. Save DINE IN rows
  const diRows = [];
  document.querySelectorAll('#di-body tr').forEach(tr => diRows.push(diCols(tr)));
  const fd1 = new FormData();
  fd1.append('ajax_save_dinein','1');
  fd1.append('report_date', FDATE);
  fd1.append('rows', JSON.stringify(diRows));
  await fetch('h_sales_report.php', {method:'POST',body:fd1});

  // 2. Save each sub-section
  const allSecs = ['marketing_pullout','grab','expenses','late_payment','advance_payment','gc_sponsorship','gc_sold'];
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
    await fetch('h_sales_report.php', {method:'POST',body:fd2});
  }

  // 2b. Save extra sections (Advance Deposit / Bar Sales)
  for (const sec of Object.keys(EXTRA_COLS)) {
    const table = document.querySelector(`.extra-table[data-section="${sec}"]`);
    if (!table) continue;
    const cols = EXTRA_COLS[sec];
    const rows = [];
    table.querySelectorAll('tbody tr').forEach(tr => {
      const nameInp = tr.querySelector('.extra-inp-name');
      const amtInps = tr.querySelectorAll('.extra-inp.num');
      const r = { name: nameInp?.value || '' };
      cols.forEach((c, i) => { r[c] = parseFloat(amtInps[i]?.value) || 0; });
      rows.push(r);
    });
    const fd2b = new FormData();
    fd2b.append('ajax_save_extra','1');
    fd2b.append('report_date', FDATE);
    fd2b.append('section', sec);
    fd2b.append('rows', JSON.stringify(rows));
    await fetch('h_sales_report.php', {method:'POST',body:fd2b});
  }

  // 3. Save main summary
  const fd3 = new FormData();
  fd3.append('ajax_save','1');
  fd3.append('report_date', FDATE);
  ['gross_sales','service_charge','z_reading_gross','total_swipe',
   'deposit_swipe_card','late_payment_card','maya_swipe','unpaid_med_credit',
   'grab_sales','bar_sales','gcash','gift_card','marketing_pull_out','discount',
   'bank_transfer_cheque','pcf_expenses','other_expenses','coh','carwash_sales'].forEach(id => fd3.append(id, gv(id)));
  fd3.append('opening_cashier', document.getElementById('opening_cashier')?.value || '');
  fd3.append('closing_cashier', document.getElementById('closing_cashier')?.value || '');

  const res  = await fetch('h_sales_report.php', {method:'POST',body:fd3});
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

// ── Lock popover ─────────────────────────────────────────
function toggleLockPopover(e) {
  e.stopPropagation();
  const pop = document.getElementById('lockPopover');
  if (!pop) return;
  pop.style.display = (pop.style.display === 'block') ? 'none' : 'block';
}
function hideLockPopover() {
  const pop = document.getElementById('lockPopover');
  if (pop) pop.style.display = 'none';
}
document.addEventListener('click', function(e) {
  const pop = document.getElementById('lockPopover');
  const btn = document.getElementById('lockBtn');
  if (!pop || pop.style.display !== 'block') return;
  if (!pop.contains(e.target) && e.target !== btn) hideLockPopover();
});

async function confirmToggleLock() {
  const willLock = !REPORT_LOCKED;
  hideLockPopover();

  const fd = new FormData();
  fd.append('ajax_toggle_lock', '1');
  fd.append('report_date', FDATE);
  fd.append('lock', willLock ? '1' : '0');

  try {
    const res  = await fetch('h_sales_report.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.ok) {
      showToast(willLock ? ('🔒 Locked ' + FDATE) : ('🔓 Unlocked ' + FDATE), 'success');
      setTimeout(function(){ location.reload(); }, 700);
    } else {
      showToast('❌ ' + (data.msg || 'Could not update lock.'), 'error');
    }
  } catch (e) {
    showToast('❌ Network error', 'error');
  }
}

// ── Lock the whole page (all fields read-only) ──────────────
function lockPage() {
  // Disable every editable input/select on the page EXCEPT the date
  // navigation picker in the controls bar — browsing to other dates
  // must still work while this date is locked.
  document.querySelectorAll('input, select, textarea').forEach(function(el) {
    if (el.name === 'date' && el.closest('.sr-controls')) return;
    el.disabled = true;
  });
  // Hide add/remove-row controls — nothing to add to a locked report.
  document.querySelectorAll('.btn-add-row, .btn-del-row').forEach(function(el) {
    el.style.display = 'none';
  });
  // Disable Save All / CSV-adjacent save button (keep CSV download working).
  document.querySelectorAll('.sr-controls button').forEach(function(btn) {
    if (btn.textContent.includes('Save')) {
      btn.disabled = true;
      btn.style.opacity = '.5';
      btn.style.cursor = 'not-allowed';
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  diChanged();
  Object.keys(EXTRA_COLS).forEach(sec => extraChanged(sec));
  recalc();
  if (REPORT_LOCKED) lockPage();
});
</script>
</body>
</html>