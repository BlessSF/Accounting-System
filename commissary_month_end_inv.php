<?php
// ============================================================
//  commissary_month_end_inv.php — Commissary Branch Month End Inventory
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();

if (isBranch() && currentBranch() !== 'Commissary') {
    header('Location: dashboard.php');
    exit;
}

$pdo  = getPDO();
$user = currentUser();

// ── Create table if not exists ────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `commissary_month_end_inv` (
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `inv_date`      date NOT NULL,
    `inv_year`      int(4) NOT NULL,
    `inv_month`     tinyint(2) NOT NULL,
    `store_name`    varchar(50) NOT NULL DEFAULT 'Commissary',
    `category`      varchar(50) NOT NULL DEFAULT '',
    `sort_order`    int(4) NOT NULL DEFAULT 0,
    `item_desc`     varchar(200) NOT NULL DEFAULT '',
    `unit`          varchar(20) NOT NULL DEFAULT 'BOTTLE',
    `supplier_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
    `end_inv_num`   decimal(12,4) NOT NULL DEFAULT 0.0000,
    `total_amount`  decimal(12,2) NOT NULL DEFAULT 0.00,
    `saved_by`      varchar(100) DEFAULT NULL,
    `created_at`    timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at`    timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── Categories (custom sections like MEAT, BEVERAGE, etc.) ─
$pdo->exec("CREATE TABLE IF NOT EXISTS `commissary_categories` (
    `id`          int(11) NOT NULL AUTO_INCREMENT,
    `store_name`  varchar(50) NOT NULL DEFAULT 'Commissary',
    `name`        varchar(100) NOT NULL,
    `sort_order`  int(4) NOT NULL DEFAULT 0,
    `created_at`  timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_store_name` (`store_name`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// One-time self-heal: any legacy rows saved before sections existed
// (category = '') get bucketed into an UNCATEGORIZED section instead
// of disappearing.
$legacyCount = (int)$pdo->query("SELECT COUNT(*) FROM commissary_month_end_inv WHERE store_name='Commissary' AND (category='' OR category IS NULL)")->fetchColumn();
if ($legacyCount > 0) {
    $pdo->exec("INSERT IGNORE INTO commissary_categories (store_name,name,sort_order) VALUES ('Commissary','UNCATEGORIZED',9999)");
    $pdo->exec("UPDATE commissary_month_end_inv SET category='UNCATEGORIZED' WHERE store_name='Commissary' AND (category='' OR category IS NULL)");
}

// One-time self-heal: any non-empty category names already used inside
// commissary_month_end_inv (from before this Sections feature existed)
// get auto-registered as sections too, so their items don't silently
// disappear from view — they still exist in the data either way.
$knownNames = $pdo->query("SELECT name FROM commissary_categories WHERE store_name='Commissary'")->fetchAll(PDO::FETCH_COLUMN);
$usedNames  = $pdo->query("SELECT DISTINCT category FROM commissary_month_end_inv WHERE store_name='Commissary' AND category<>''")->fetchAll(PDO::FETCH_COLUMN);
$missing    = array_diff($usedNames, $knownNames);
if (!empty($missing)) {
    $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),-1) FROM commissary_categories WHERE store_name='Commissary'")->fetchColumn();
    $ins = $pdo->prepare("INSERT IGNORE INTO commissary_categories (store_name,name,sort_order) VALUES ('Commissary',?,?)");
    foreach (array_values($missing) as $i => $name) {
        $ins->execute([$name, $maxOrd + 1 + $i]);
    }
}

// Load current section list (custom, user-created)
$catStmt = $pdo->query("SELECT name FROM commissary_categories WHERE store_name='Commissary' ORDER BY sort_order,id");
$CATEGORIES = array_column($catStmt->fetchAll(PDO::FETCH_ASSOC), 'name');

$UNITS  = ['BOTTLE','PCS','PACKS','ROLL','CAN','KILO','BOX','JAR','GRAMS','KG','ML','BARS','TUB','GAL','LITER','REAM','CONTAINER','STICK'];
$months = ['January','February','March','April','May','June',
           'July','August','September','October','November','December'];

// ── Filters ───────────────────────────────────────────────
$fYear  = (int)($_GET['year']  ?? date('Y'));
$fMonth = (int)($_GET['month'] ?? date('n'));
$fMonth = max(1, min(12, $fMonth));

$daysInMonth  = (int)date('t', mktime(0,0,0,$fMonth,1,$fYear));
$fDay         = (int)($_GET['day'] ?? $daysInMonth);
$fDay         = max(1, min($daysInMonth, $fDay));
$selectedDate = date('Y-m-d', mktime(0,0,0,$fMonth,$fDay,$fYear));
$displayDate  = $months[$fMonth-1].' '.str_pad($fDay,2,'0',STR_PAD_LEFT).', '.$fYear;

// ── AJAX: Save row ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');
    try {
        $rowId     = (int)($_POST['row_id']       ?? 0);
        $category  = trim($_POST['category']      ?? '');
        $sortOrder = (int)($_POST['sort_order']   ?? 0);
        $itemDesc  = trim($_POST['item_desc']     ?? '');
        $unit      = trim($_POST['unit']          ?? 'BOTTLE');
        $suppCost  = (float)($_POST['supplier_cost'] ?? 0);
        $endInvNum = (float)($_POST['end_inv_num']   ?? 0);
        $total     = round($suppCost * $endInvNum, 2);
        $yr        = (int)date('Y', strtotime($selectedDate));
        $mo        = (int)date('n', strtotime($selectedDate));

        if ($rowId > 0) {
            $pdo->prepare("UPDATE commissary_month_end_inv SET inv_date=?,inv_year=?,inv_month=?,category=?,sort_order=?,item_desc=?,unit=?,supplier_cost=?,end_inv_num=?,total_amount=?,saved_by=? WHERE id=? AND store_name='Commissary'")
                ->execute([$selectedDate,$yr,$mo,$category,$sortOrder,$itemDesc,$unit,$suppCost,$endInvNum,$total,$user['name'],$rowId]);
            echo json_encode(['ok'=>true,'id'=>$rowId,'total'=>$total]);
        } else {
            $pdo->prepare("INSERT INTO commissary_month_end_inv (inv_date,inv_year,inv_month,store_name,category,sort_order,item_desc,unit,supplier_cost,end_inv_num,total_amount,saved_by) VALUES (?,?,?,'Commissary',?,?,?,?,?,?,?,?)")
                ->execute([$selectedDate,$yr,$mo,$category,$sortOrder,$itemDesc,$unit,$suppCost,$endInvNum,$total,$user['name']]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'total'=>$total]);
        }
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Delete row ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');
    try {
        $pdo->prepare("DELETE FROM commissary_month_end_inv WHERE id=? AND store_name='Commissary'")
            ->execute([(int)($_POST['row_id'] ?? 0)]);
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Add a new section (category) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_category'])) {
    header('Content-Type: application/json');
    try {
        $name = strtoupper(trim($_POST['name'] ?? ''));
        if ($name === '') { echo json_encode(['ok'=>false,'msg'=>'Section name cannot be empty.']); exit; }
        $exists = $pdo->prepare("SELECT id FROM commissary_categories WHERE store_name='Commissary' AND name=?");
        $exists->execute([$name]);
        if ($exists->fetch()) { echo json_encode(['ok'=>false,'msg'=>'A section named "'.$name.'" already exists.']); exit; }
        $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),-1) FROM commissary_categories WHERE store_name='Commissary'")->fetchColumn();
        $pdo->prepare("INSERT INTO commissary_categories (store_name,name,sort_order) VALUES ('Commissary',?,?)")
            ->execute([$name, $maxOrd+1]);
        echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'name'=>$name]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Remove a section (blocks unless empty, or forced) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_category'])) {
    header('Content-Type: application/json');
    try {
        $name  = trim($_POST['name'] ?? '');
        $force = !empty($_POST['force']);
        $chk   = $pdo->prepare("SELECT COUNT(*) FROM commissary_month_end_inv WHERE store_name='Commissary' AND category=?");
        $chk->execute([$name]);
        $itemCount = (int)$chk->fetchColumn();

        if ($itemCount > 0 && !$force) {
            echo json_encode(['ok'=>false,'needsForce'=>true,'itemCount'=>$itemCount,
                'msg'=>"This section still has $itemCount saved item(s) across one or more dates."]);
            exit;
        }
        if ($itemCount > 0 && $force) {
            $pdo->prepare("DELETE FROM commissary_month_end_inv WHERE store_name='Commissary' AND category=?")->execute([$name]);
        }
        $pdo->prepare("DELETE FROM commissary_categories WHERE store_name='Commissary' AND name=?")->execute([$name]);
        echo json_encode(['ok'=>true,'deletedItems'=>$itemCount]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Duplicate this date's items into other day(s) ────
// Lets a user build the item list once and reuse it across the month
// instead of retyping every item description / unit / cost each day.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_duplicate_day'])) {
    header('Content-Type: application/json');
    try {
        $srcDate = trim($_POST['src_date'] ?? '');
        $targets = json_decode($_POST['target_dates'] ?? '[]', true);
        $copyQty = !empty($_POST['copy_qty']);
        $mode    = ($_POST['mode'] ?? 'skip'); // skip | append | replace

        if (!$srcDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $srcDate)) {
            echo json_encode(['ok'=>false,'msg'=>'Missing or invalid source date.']); exit;
        }
        if (!is_array($targets) || !count($targets)) {
            echo json_encode(['ok'=>false,'msg'=>'Pick at least one day to duplicate into.']); exit;
        }

        $srcStmt = $pdo->prepare("SELECT * FROM commissary_month_end_inv WHERE store_name='Commissary' AND inv_date=? ORDER BY sort_order,id");
        $srcStmt->execute([$srcDate]);
        $srcRows = $srcStmt->fetchAll();
        if (!$srcRows) {
            echo json_encode(['ok'=>false,'msg'=>'No items on '.$srcDate.' to duplicate yet.']); exit;
        }

        $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM commissary_month_end_inv WHERE store_name='Commissary' AND inv_date=?");
        $delStmt = $pdo->prepare("DELETE FROM commissary_month_end_inv WHERE store_name='Commissary' AND inv_date=?");
        $insStmt = $pdo->prepare("INSERT INTO commissary_month_end_inv (inv_date,inv_year,inv_month,store_name,category,sort_order,item_desc,unit,supplier_cost,end_inv_num,total_amount,saved_by) VALUES (?,?,?,'Commissary',?,?,?,?,?,?,?,?)");

        $summary = ['copied'=>[], 'skipped'=>[]];
        foreach ($targets as $tDate) {
            $tDate = trim($tDate);
            if (!$tDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tDate)) continue;
            if ($tDate === $srcDate) continue; // duplicating a date onto itself would just double it up

            $chkStmt->execute([$tDate]);
            $existing = (int)$chkStmt->fetchColumn();

            if ($existing > 0) {
                if ($mode === 'skip')   { $summary['skipped'][] = $tDate; continue; }
                if ($mode === 'replace') $delStmt->execute([$tDate]);
                // mode === 'append' just inserts on top of what's there
            }

            $yr = (int)date('Y', strtotime($tDate));
            $mo = (int)date('n', strtotime($tDate));
            foreach ($srcRows as $r) {
                $endInv   = $copyQty ? (float)$r['end_inv_num'] : 0;
                $suppCost = (float)$r['supplier_cost'];
                $total    = $copyQty ? round($suppCost * $endInv, 2) : 0;
                $insStmt->execute([$tDate,$yr,$mo,$r['category'],$r['sort_order'],$r['item_desc'],$r['unit'],$suppCost,$endInv,$total,$user['name']]);
            }
            $summary['copied'][] = $tDate;
        }

        echo json_encode(['ok'=>true,'summary'=>$summary,'itemCount'=>count($srcRows)]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: Item counts for an arbitrary month/year ───
// Powers the "Duplicate to Other Days" modal when the user browses to
// a month other than the one currently on screen (e.g. next month).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_month_counts'])) {
    header('Content-Type: application/json');
    try {
        $yr = (int)($_POST['year'] ?? 0);
        $mo = (int)($_POST['month'] ?? 0);
        if ($yr < 2000 || $yr > 2100 || $mo < 1 || $mo > 12) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid month/year.']); exit;
        }
        $cStmt = $pdo->prepare("SELECT DAY(inv_date) as d, COUNT(*) as c FROM commissary_month_end_inv WHERE store_name='Commissary' AND inv_year=? AND inv_month=? GROUP BY DAY(inv_date)");
        $cStmt->execute([$yr, $mo]);
        $counts = array_column($cStmt->fetchAll(PDO::FETCH_ASSOC), 'c', 'd');
        $days   = (int)date('t', mktime(0,0,0,$mo,1,$yr));
        echo json_encode(['ok'=>true,'counts'=>(object)$counts,'days'=>$days]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── CSV Export ────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    $rows = $pdo->prepare("SELECT * FROM commissary_month_end_inv WHERE store_name='Commissary' AND inv_date=? ORDER BY sort_order,id");
    $rows->execute([$selectedDate]);
    $rows = $rows->fetchAll();
    $grouped = [];
    foreach ($rows as $r) $grouped[$r['category']][] = $r;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="commissary_month_end_inv_'.$selectedDate.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out,['Commissary Branch — Month End Inventory']);
    fputcsv($out,['DATE: '.$displayDate]);
    fputcsv($out,[]);

    $grandTotal = 0;
    foreach ($CATEGORIES as $cat) {
        $catRows = $grouped[$cat] ?? [];
        if (empty($catRows)) continue; // skip empty sections in the export
        fputcsv($out,[$cat]);
        fputcsv($out,['PARTICULAR','UNIT','COST','END','TOTAL COST']);
        $catTotal = 0;
        foreach ($catRows as $r) {
            fputcsv($out,[$r['item_desc'],$r['unit'],
                number_format((float)$r['supplier_cost'],2,'.',''),
                number_format((float)$r['end_inv_num'],4,'.',''),
                number_format((float)$r['total_amount'],2,'.','')]);
            $catTotal += (float)$r['total_amount'];
        }
        fputcsv($out,['','','','TOTAL', number_format($catTotal,2,'.','')]);
        $grandTotal += $catTotal;
        fputcsv($out,[]);
    }
    fputcsv($out,['','','','GRAND TOTAL',number_format($grandTotal,2,'.','')]);
    fclose($out); exit;
}

// ── Load rows ─────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM commissary_month_end_inv WHERE store_name='Commissary' AND inv_date=? ORDER BY sort_order,id");
$stmt->execute([$selectedDate]);
$allRows = $stmt->fetchAll();

$grouped = [];
foreach ($allRows as $r) $grouped[$r['category']][] = $r;

$catTotals  = [];
$grandTotal = 0;
foreach ($CATEGORIES as $cat) {
    $t = array_sum(array_column($grouped[$cat] ?? [], 'total_amount'));
    $catTotals[$cat] = $t;
    $grandTotal += $t;
}

// Item counts per day-of-month, for the "Duplicate to Other Days" modal
// (lets us warn when a target day already has data before overwriting it).
$dayCountStmt = $pdo->prepare("SELECT DAY(inv_date) as d, COUNT(*) as c FROM commissary_month_end_inv WHERE store_name='Commissary' AND inv_year=? AND inv_month=? GROUP BY DAY(inv_date)");
$dayCountStmt->execute([$fYear, $fMonth]);
$dayCounts = array_column($dayCountStmt->fetchAll(PDO::FETCH_ASSOC), 'c', 'd');

$pageTitle  = 'Month End Inv.';
$activePage = 'commissary_month_end_inv';
include 'layout.php';
?>

<style>
.inv-header {
  background: linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);
  border-radius:var(--radius); padding:20px 26px 16px;
  margin-bottom:18px; display:flex; align-items:flex-start;
  justify-content:space-between; flex-wrap:wrap; gap:12px;
}
.inv-header .eyebrow  { font-family:var(--font-m); font-size:.58rem; text-transform:uppercase; letter-spacing:.14em; color:rgba(255,255,255,.4); margin-bottom:4px; }
.inv-header .title    { font-size:1.15rem; font-weight:800; color:#fff; letter-spacing:-.02em; }
.inv-header .subtitle { font-family:var(--font-m); font-size:.67rem; color:rgba(255,255,255,.45); margin-top:3px; }

.inv-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
.inv-controls select { font-family:var(--font-m); font-size:.8rem; padding:7px 10px; border:1px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); }

.inv-search { display:flex; gap:8px; align-items:center; margin-bottom:14px; }
.inv-search input { flex:1; max-width:280px; font-family:var(--font-m); font-size:.8rem; padding:7px 12px; border:1px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); outline:none; }
.inv-search input:focus { border-color:var(--accent); }

.grand-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:12px 20px; margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; }
.grand-bar .gb-label { font-family:var(--font-m); font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:var(--subtext); }
.grand-bar .gb-val   { font-family:var(--font-m); font-size:1.3rem; font-weight:800; color:var(--accent); }

.inv-table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.07); }
.inv-table { width:100%; border-collapse:collapse; }
.inv-table thead th {
  background:#1a1d23; color:#fff; padding:10px 14px;
  font-family:var(--font-m); font-size:.65rem; text-transform:uppercase;
  letter-spacing:.08em; text-align:left; white-space:nowrap;
}
.inv-table thead th.num { text-align:right; }
.inv-table tbody tr { border-bottom:1px solid #f0f2f5; transition:background .1s; }
.inv-table tbody tr:hover { background:#fafbfc; }
.inv-table tbody td { padding:7px 12px; font-size:.8rem; color:var(--text); vertical-align:middle; }
.inv-table tbody td.num { text-align:right; }
.inv-table tfoot td { background:#1a1d23; color:#fff; font-family:var(--font-m); font-size:.82rem; font-weight:800; padding:10px 14px; }
.inv-table tfoot td.num { text-align:right; color:#a5f3c0; }

.inv-inp { width:100%; border:1px solid transparent; background:transparent; font-family:var(--font-m); font-size:.79rem; color:var(--text); padding:4px 6px; border-radius:5px; outline:none; }
.inv-inp:focus { border-color:var(--accent); background:#fff; box-shadow:0 0 0 3px rgba(15,123,92,.08); }
.inv-inp.num { text-align:right; }
.inv-unit-sel { font-family:var(--font-m); font-size:.75rem; border:1px solid transparent; background:transparent; border-radius:5px; padding:3px 4px; outline:none; width:100%; }
.inv-unit-sel:focus { border-color:var(--accent); background:#fff; }
.inv-inp.total-inp { color:var(--accent); font-weight:700; background:rgba(15,123,92,.04); border-color:rgba(15,123,92,.12); cursor:default; }

.btn-inv-save { padding:4px 12px; background:var(--accent); color:#fff; border:none; border-radius:6px; font-size:.7rem; font-weight:700; cursor:pointer; }
.btn-inv-save:hover { background:#0a6b50; }
.btn-inv-del  { padding:4px 9px; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; font-size:.7rem; cursor:pointer; margin-left:4px; }
.btn-inv-del:hover { background:#fecaca; }
.row-status   { font-size:.65rem; display:none; margin-top:2px; }

/* ── Modals ── */
.modal-overlay {
  position:fixed; inset:0; background:rgba(10,12,20,.55);
  backdrop-filter:blur(2px); display:none;
  align-items:center; justify-content:center; z-index:9999; padding:16px;
}
.modal-overlay.active { display:flex; }
.modal-box {
  background:var(--surface); width:420px; max-width:100%;
  border-radius:14px; overflow:hidden;
  box-shadow:0 20px 60px rgba(0,0,0,.35);
  animation:modalPop .15s ease;
}
@keyframes modalPop { from{transform:scale(.96);opacity:0} to{transform:scale(1);opacity:1} }
.modal-header {
  background:#1a1a2e; color:#fff; padding:16px 20px;
  font-family:var(--font-m); font-size:.85rem; font-weight:800; letter-spacing:.02em;
}
.modal-header.danger { background:#3a1414; }
.modal-body { padding:20px; font-family:var(--font-m); font-size:.8rem; color:var(--text); line-height:1.6; }
.modal-input {
  width:100%; margin-top:12px; padding:10px 14px;
  font-family:var(--font-m); font-size:.85rem; text-transform:uppercase;
  border:1px solid var(--border); border-radius:8px; outline:none;
  background:#fff; color:var(--text); transition:border-color .15s;
}
.modal-input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(15,123,92,.1); }
.modal-error { color:#b91c1c; font-size:.72rem; margin-top:8px; display:none; }
.modal-footer {
  padding:14px 20px; display:flex; justify-content:flex-end; gap:8px;
  border-top:1px solid var(--border); background:#fafbfc;
}
.modal-btn { padding:9px 16px; border-radius:8px; font-family:var(--font-m); font-size:.75rem; font-weight:700; cursor:pointer; border:none; }
.modal-btn-cancel  { background:#eef0f3; color:var(--text); }
.modal-btn-cancel:hover  { background:#e2e5ea; }
.modal-btn-primary { background:var(--accent); color:#fff; }
.modal-btn-primary:hover { background:#0a6b50; }
.modal-btn-danger  { background:#b91c1c; color:#fff; }
.modal-btn-danger:hover  { background:#991616; }

/* ── Sections (custom categories) ── */
.inv-section { margin-bottom:24px; }
.inv-section-header {
  display:flex; align-items:center; justify-content:space-between;
  background:#1a1a2e; color:#fff; padding:10px 16px;
  border-radius:10px 10px 0 0;
}
.inv-section-title { font-family:var(--font-m); font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
.inv-section-total { font-family:var(--font-m); font-size:.85rem; font-weight:800; color:#a5f3c0; }
.inv-section .inv-table-wrap { border-radius:0 0 var(--radius) var(--radius); border-top:none; box-shadow:none; }
.btn-inv-del-section {
  background:rgba(255,255,255,.12); border:none; color:#fff;
  width:22px; height:22px; border-radius:6px; cursor:pointer;
  font-size:.7rem; line-height:1; flex-shrink:0;
}
.btn-inv-del-section:hover { background:rgba(255,90,90,.4); }
.inv-section-empty-note {
  text-align:center; padding:40px 20px; font-family:var(--font-m);
  font-size:.8rem; color:var(--muted); background:var(--surface);
  border:1px dashed var(--border); border-radius:var(--radius);
}

/* ── Duplicate-to-other-days modal ── */
.modal-box.wide { width: 480px; }
.dup-day-grid {
  display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px;
  margin: 10px 0 14px;
}
.dup-day-chip {
  position: relative; display: flex; align-items: center; justify-content: center;
  height: 34px; border: 1px solid var(--border); border-radius: 7px;
  font-family: var(--font-m); font-size: .72rem; font-weight: 700;
  cursor: pointer; user-select: none; background: #fff; color: var(--text);
  transition: all .12s;
}
.dup-day-chip:hover { border-color: var(--accent); }
.dup-day-chip.checked { background: var(--accent); border-color: var(--accent); color: #fff; }
.dup-day-chip.has-data::after {
  content: ''; position: absolute; top: 3px; right: 3px;
  width: 6px; height: 6px; border-radius: 50%; background: #f59e0b;
}
.dup-day-chip.has-data.checked::after { background: #fff; }
.dup-day-chip.is-source { opacity: .35; cursor: not-allowed; text-decoration: line-through; }
.dup-month-nav {
  display: flex; align-items: center; gap: 6px; margin-top: 12px;
}
.dup-nav-btn {
  flex: none; height: 30px; padding: 0 10px; border: 1px solid var(--border);
  border-radius: 7px; background: #fff; color: var(--text); cursor: pointer;
  font-family: var(--font-m); font-size: .72rem; font-weight: 700;
}
.dup-nav-btn:hover { border-color: var(--accent); background: #f0fdf4; }
.dup-nav-today { margin-left: auto; color: var(--accent3); }
.dup-month-nav .dup-select { flex: 1; }
.dup-legend {
  display: flex; align-items: center; gap: 14px; font-family: var(--font-m);
  font-size: .64rem; color: var(--subtext); margin-bottom: 10px;
}
.dup-legend span { display: flex; align-items: center; gap: 5px; }
.dup-legend .dot { width: 6px; height: 6px; border-radius: 50%; background: #f59e0b; display: inline-block; }
.dup-quicklinks { display: flex; gap: 12px; margin-bottom: 4px; }
.dup-quicklinks a { font-family: var(--font-m); font-size: .68rem; color: var(--accent3); cursor: pointer; text-decoration: underline; }
.dup-option-row {
  display: flex; align-items: flex-start; gap: 8px; margin-top: 12px;
  padding-top: 12px; border-top: 1px solid var(--border);
}
.dup-option-row label { font-family: var(--font-m); font-size: .74rem; color: var(--text); line-height: 1.4; }
.dup-option-row .hint { display: block; font-size: .65rem; color: var(--subtext); margin-top: 2px; }
.dup-select {
  width: 100%; margin-top: 6px; padding: 7px 10px;
  font-family: var(--font-m); font-size: .76rem;
  border: 1px solid var(--border); border-radius: 7px; background: #fff; color: var(--text);
}
#dupSummary {
  margin-top: 12px; padding: 10px 12px; border-radius: 8px;
  background: #f0fdf4; border: 1px solid #bbf7d0;
  font-family: var(--font-m); font-size: .72rem; color: #166534; display: none;
}
</style>

<!-- Header -->
<div class="inv-header">
  <div>
    <div class="eyebrow">Commissary Branch · Inventory</div>
    <div class="title">Month End Inventory</div>
    <div class="subtitle"><?= $displayDate ?></div>
  </div>
  <span style="background:rgba(255,255,255,.1);color:#fff;padding:5px 14px;border-radius:20px;font-family:var(--font-m);font-size:.65rem;font-weight:600">📍 Commissary</span>
</div>

<!-- Controls -->
<div class="inv-controls">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <select name="month" onchange="this.form.submit()">
      <?php foreach($months as $i=>$mn): ?>
        <option value="<?=$i+1?>" <?=$i+1===$fMonth?'selected':''?>><?=$mn?></option>
      <?php endforeach; ?>
    </select>
    <select name="day" onchange="this.form.submit()">
      <?php for($d=1;$d<=$daysInMonth;$d++): ?>
        <option value="<?=$d?>" <?=$d===$fDay?'selected':''?>><?=str_pad($d,2,'0',STR_PAD_LEFT)?></option>
      <?php endfor; ?>
    </select>
    <select name="year" onchange="this.form.submit()">
      <?php for($y=date('Y')+1;$y>=2023;$y--): ?>
        <option value="<?=$y?>" <?=$y===$fYear?'selected':''?>><?=$y?></option>
      <?php endfor; ?>
    </select>
  </form>
  <button class="btn btn-primary btn-sm" onclick="addSection()">+ Add Section</button>
  <a href="commissary_month_end_inv.php?export_csv=1&month=<?=$fMonth?>&day=<?=$fDay?>&year=<?=$fYear?>"
     class="btn btn-ghost btn-sm" style="color:var(--accent3);border-color:rgba(251,191,36,.25);background:rgba(251,191,36,.06)">⬇ Download CSV</a>
  <button type="button" class="btn btn-ghost btn-sm" onclick="openDuplicateModal()"
          <?= empty($allRows) ? 'disabled title="No items on this date yet to duplicate"' : '' ?>>
    📋 Duplicate to Other Days
  </button>
</div>

<!-- Search -->
<div class="inv-search">
  <input type="text" id="searchInput" placeholder="Search items…" oninput="filterRows(this.value)">
  <button class="btn btn-ghost btn-sm" onclick="document.getElementById('searchInput').value='';filterRows('')">Clear</button>
</div>

<!-- Grand total -->
<div class="grand-bar">
  <div class="gb-label">Grand Total</div>
  <div class="gb-val" id="grand-total-display"><?= number_format($grandTotal,2) ?></div>
</div>

<!-- Sections (custom categories) -->
<div id="sections-wrap">
<?php foreach ($CATEGORIES as $catIdx => $cat):
    $catRows = $grouped[$cat] ?? [];
?>
<div class="inv-section" id="section_<?= $catIdx ?>" data-cat="<?= htmlspecialchars($cat) ?>">
  <div class="inv-section-header">
    <span class="inv-section-title"><?= htmlspecialchars($cat) ?></span>
    <div style="display:flex;align-items:center;gap:12px">
      <span class="inv-section-total" id="cat_total_<?= $catIdx ?>"><?= number_format($catTotals[$cat] ?? 0,2) ?></span>
      <button class="btn-inv-del-section" onclick="removeSection(<?= $catIdx ?>,'<?= addslashes($cat) ?>')" title="Remove this section (only if empty)">✕</button>
    </div>
  </div>
  <div class="inv-table-wrap">
    <table class="inv-table">
      <thead>
        <tr>
          <th style="width:42%">Particular</th>
          <th style="width:9%">Unit</th>
          <th class="num" style="width:12%">Cost</th>
          <th class="num" style="width:12%">End</th>
          <th class="num" style="width:13%">Total Cost</th>
          <th style="width:12%">Action</th>
        </tr>
      </thead>
      <tbody id="tbody_<?= $catIdx ?>">

      <?php foreach ($catRows as $r): ?>
        <tr class="inv-row" data-id="<?= $r['id'] ?>">
          <td><input class="inv-inp" type="text" value="<?= htmlspecialchars($r['item_desc']) ?>" placeholder="Particular…" oninput="markChanged(this)"></td>
          <td>
            <select class="inv-unit-sel" onchange="markChanged(this)">
              <?php foreach($UNITS as $u): ?>
                <option value="<?=$u?>" <?=$u===$r['unit']?'selected':''?>><?=$u?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input class="inv-inp num" type="number" step="0.01"   value="<?= (float)$r['supplier_cost'] ?: '' ?>" placeholder="0.00"   oninput="calcTotal(this)"></td>
          <td><input class="inv-inp num" type="number" step="0.0001" value="<?= (float)$r['end_inv_num']   ?: '' ?>" placeholder="0.0000" oninput="calcTotal(this)"></td>
          <td><input class="inv-inp num total-inp" type="number" step="0.01" value="<?= number_format((float)$r['total_amount'],2,'.','') ?>" readonly tabindex="-1"></td>
          <td>
            <button class="btn-inv-save" onclick="saveRow(this,'<?= addslashes($cat) ?>',<?= $catIdx ?>)">Save</button>
            <button class="btn-inv-del"  onclick="deleteRow(this,<?= $catIdx ?>)">✕</button>
            <div class="row-status"></div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (empty($catRows)): ?>
        <tr class="empty-row-<?= $catIdx ?>">
          <td colspan="6" style="text-align:center;padding:20px;font-family:var(--font-m);font-size:.75rem;color:var(--muted)">
            No items yet — click <strong>+ Add Item</strong> below
          </td>
        </tr>
      <?php endif; ?>

      </tbody>
    </table>
  </div>
  <div style="margin:10px 0 0">
    <button class="btn btn-primary btn-sm" onclick="addRow(<?= $catIdx ?>,'<?= addslashes($cat) ?>')">+ Add Item</button>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($CATEGORIES)): ?>
  <div class="inv-section-empty-note">
    No sections yet — click <strong>+ Add Section</strong> above to create your first category (e.g. MEAT, BEVERAGE, DRY GOODS).
  </div>
<?php endif; ?>
</div>

<!-- Add Section Modal -->
<div class="modal-overlay" id="addSectionModal">
  <div class="modal-box">
    <div class="modal-header">New Section</div>
    <div class="modal-body">
      Name this new section (e.g. MEAT, BEVERAGE, DRY GOODS):
      <input type="text" class="modal-input" id="addSectionInput" placeholder="SECTION NAME" maxlength="100">
      <div class="modal-error" id="addSectionError"></div>
    </div>
    <div class="modal-footer">
      <button class="modal-btn modal-btn-cancel" onclick="closeModal('addSectionModal')">Cancel</button>
      <button class="modal-btn modal-btn-primary" onclick="confirmAddSection()">Create Section</button>
    </div>
  </div>
</div>

<!-- Duplicate-to-Other-Days Modal -->
<div class="modal-overlay" id="duplicateModal">
  <div class="modal-box wide">
    <div class="modal-header">📋 Duplicate <?= htmlspecialchars($displayDate) ?> to Other Days</div>
    <div class="modal-body">
      Copy all <strong><?= count($allRows) ?> item(s)</strong> from this date into any day(s) you pick below —
      including next month or any other month/year. You'll only need to adjust the counts for each day afterward.

      <div class="dup-month-nav">
        <button type="button" class="dup-nav-btn" onclick="dupGoToMonth(-1)" title="Previous month">◀</button>
        <select id="dupBrowseMonth" class="dup-select" style="margin:0" onchange="dupBrowseSelectChange()"></select>
        <select id="dupBrowseYear" class="dup-select" style="margin:0;max-width:90px" onchange="dupBrowseSelectChange()"></select>
        <button type="button" class="dup-nav-btn" onclick="dupGoToMonth(1)" title="Next month">▶</button>
        <button type="button" class="dup-nav-btn dup-nav-today" onclick="dupBrowseTo(INV_YEAR, INV_MONTH)">This Month</button>
      </div>

      <div class="dup-legend" style="margin-top:10px">
        <span><span class="dot"></span> already has items</span>
        <span style="opacity:.6">crossed out = current date</span>
      </div>
      <div class="dup-quicklinks">
        <a onclick="dupSelectAll()">Select all remaining days (this view)</a>
        <a onclick="dupClearAll()">Clear all selections</a>
      </div>
      <div class="dup-day-grid" id="dupDayGrid"></div>
      <div id="dupSelectedNote" style="font-family:var(--font-m);font-size:.66rem;color:var(--subtext);margin-top:2px"></div>

      <div class="dup-option-row">
        <input type="checkbox" id="dupCopyQty" style="margin-top:3px">
        <label for="dupCopyQty">
          Also copy quantities &amp; totals
          <span class="hint">Leave unchecked to copy just the item list (description, unit, cost) with blank counts — best for a fresh daily tally.</span>
        </label>
      </div>

      <div class="dup-option-row" style="border-top:none;padding-top:0;flex-direction:column;align-items:stretch">
        <label for="dupMode">If a picked day already has items:</label>
        <select id="dupMode" class="dup-select">
          <option value="skip">Skip that day (safest)</option>
          <option value="append">Add on top (may create duplicates)</option>
          <option value="replace">Replace its items first</option>
        </select>
      </div>

      <div id="dupSummary"></div>
    </div>
    <div class="modal-footer">
      <button class="modal-btn modal-btn-cancel" onclick="closeModal('duplicateModal')">Cancel</button>
      <button class="modal-btn modal-btn-primary" id="dupConfirmBtn" onclick="confirmDuplicate()">Duplicate</button>
    </div>
  </div>
</div>

<!-- Generic Confirm / Danger Modal (reused for remove-section flows) -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal-box">
    <div class="modal-header" id="confirmModalHeader">Confirm</div>
    <div class="modal-body" id="confirmModalMsg"></div>
    <div class="modal-footer">
      <button class="modal-btn modal-btn-cancel" onclick="closeModal('confirmModal')">Cancel</button>
      <button class="modal-btn" id="confirmModalBtn"></button>
    </div>
  </div>
</div>

<script>
const INV_MONTH = <?= $fMonth ?>;
const INV_YEAR  = <?= $fYear ?>;
const INV_DAY   = <?= $fDay ?>;
const INV_DATE  = <?= json_encode($selectedDate) ?>;
const DAYS_IN_MONTH = <?= $daysInMonth ?>;
const DAY_COUNTS    = <?= json_encode($dayCounts, JSON_FORCE_OBJECT) ?>; // day-of-month => item count, for INV_YEAR/INV_MONTH
const MONTH_NAMES   = <?= json_encode($months) ?>;

const UNIT_OPTS = <?= json_encode(implode('', array_map(fn($u) => "<option value=\"$u\">$u</option>", $UNITS))) ?>;

const fmt = n => n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
const gv  = el => parseFloat(el?.value) || 0;

function calcTotal(inp) {
  const row   = inp.closest('tr');
  const nums  = row.querySelectorAll('input[type=number]');
  const total = Math.round(gv(nums[0]) * gv(nums[1]) * 100) / 100;
  nums[2].value = total > 0 ? total.toFixed(2) : '';
  const catIdx = parseInt(row.closest('.inv-section').id.replace('section_',''));
  refreshCatTotal(catIdx);
  refreshGrandTotal();
  markChanged(inp);
}

function refreshCatTotal(catIdx) {
  let tot = 0;
  document.querySelectorAll(`#tbody_${catIdx} .total-inp`).forEach(el => tot += gv(el));
  const el = document.getElementById(`cat_total_${catIdx}`);
  if (el) el.textContent = fmt(tot);
}

function refreshGrandTotal() {
  let grand = 0;
  document.querySelectorAll('.total-inp').forEach(el => grand += gv(el));
  document.getElementById('grand-total-display').textContent = fmt(grand);
}

function markChanged(el) {
  const status = el.closest('tr')?.querySelector('.row-status');
  if (status) { status.textContent = ''; status.style.display = 'none'; }
}

function filterRows(q) {
  q = q.trim().toLowerCase();
  document.querySelectorAll('.inv-row[data-id]').forEach(tr => {
    const desc = tr.querySelector('input[type=text]')?.value?.toLowerCase() || '';
    tr.style.display = (!q || desc.includes(q)) ? '' : 'none';
  });
}

function addRow(catIdx, catName) {
  const ph = document.querySelector(`.empty-row-${catIdx}`);
  if (ph) ph.remove();

  const tr = document.createElement('tr');
  tr.className  = 'inv-row';
  tr.dataset.id = '0';
  tr.innerHTML = `
    <td><input class="inv-inp" type="text" placeholder="Particular…" oninput="markChanged(this)"></td>
    <td><select class="inv-unit-sel" onchange="markChanged(this)">${UNIT_OPTS}</select></td>
    <td><input class="inv-inp num" type="number" step="0.01"   placeholder="0.00"   oninput="calcTotal(this)"></td>
    <td><input class="inv-inp num" type="number" step="0.0001" placeholder="0.0000" oninput="calcTotal(this)"></td>
    <td><input class="inv-inp num total-inp" type="number" step="0.01" placeholder="0.00" readonly tabindex="-1"></td>
    <td>
      <button class="btn-inv-save" onclick="saveRow(this,'${catName.replace(/'/g,"\\'")}',${catIdx})">Save</button>
      <button class="btn-inv-del"  onclick="deleteRow(this,${catIdx})">✕</button>
      <div class="row-status"></div>
    </td>`;
  document.getElementById(`tbody_${catIdx}`).appendChild(tr);
  tr.querySelector('input[type=text]').focus();
}

async function saveRow(btn, catName, catIdx) {
  const row    = btn.closest('tr');
  const status = row.querySelector('.row-status');
  const rowId  = parseInt(row.dataset.id) || 0;
  const nums   = row.querySelectorAll('input[type=number]');
  const desc   = row.querySelector('input[type=text]').value.trim();
  const unit   = row.querySelector('.inv-unit-sel').value;
  const sortOrd= Array.from(document.querySelectorAll(`#tbody_${catIdx} tr[data-id]`)).indexOf(row);

  btn.textContent = '…'; btn.disabled = true;

  const fd = new FormData();
  fd.append('ajax_save',     '1');
  fd.append('row_id',        rowId);
  fd.append('category',      catName);
  fd.append('sort_order',    sortOrd);
  fd.append('item_desc',     desc);
  fd.append('unit',          unit);
  fd.append('supplier_cost', gv(nums[0]));
  fd.append('end_inv_num',   gv(nums[1]));

  try {
    const res  = await fetch('commissary_month_end_inv.php', {method:'POST', body:fd});
    const json = await res.json();
    if (json.ok) {
      row.dataset.id  = json.id;
      nums[2].value   = parseFloat(json.total).toFixed(2);
      refreshCatTotal(catIdx);
      refreshGrandTotal();
      status.textContent = '✓ Saved';
      status.style.color = 'var(--accent)';
      status.style.display = 'block';
      setTimeout(() => { status.style.display = 'none'; }, 2500);
    } else {
      status.textContent = '❌ Error';
      status.style.color = '#b91c1c';
      status.style.display = 'block';
      alert('Error: ' + json.msg);
    }
  } catch(e) { alert('Network error'); }

  btn.textContent = 'Save'; btn.disabled = false;
}

async function deleteRow(btn, catIdx) {
  const row = btn.closest('tr');
  const id  = parseInt(row.dataset.id) || 0;
  if (id > 0 && !confirm('Delete this item?')) return;
  if (id > 0) {
    const fd = new FormData();
    fd.append('ajax_delete', '1');
    fd.append('row_id', id);
    try { await fetch('commissary_month_end_inv.php', {method:'POST', body:fd}); } catch(e) {}
  }
  row.remove();
  refreshCatTotal(catIdx);
  refreshGrandTotal();
  if (!document.querySelectorAll(`#tbody_${catIdx} tr[data-id]`).length) {
    const ph = document.createElement('tr');
    ph.className = `empty-row-${catIdx}`;
    ph.innerHTML = `<td colspan="6" style="text-align:center;padding:20px;font-family:var(--font-m);font-size:.75rem;color:var(--muted)">No items yet — click <strong>+ Add Item</strong> below</td>`;
    document.getElementById(`tbody_${catIdx}`).appendChild(ph);
  }
}

// ── Modal helpers ─────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// Close on backdrop click or Escape
document.querySelectorAll('.modal-overlay').forEach(ov => {
  ov.addEventListener('click', e => { if (e.target === ov) closeModal(ov.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(ov => closeModal(ov.id));
});

function showConfirm({title, message, btnLabel, btnClass, danger, onConfirm}) {
  const header = document.getElementById('confirmModalHeader');
  header.textContent = title;
  header.className = 'modal-header' + (danger ? ' danger' : '');
  document.getElementById('confirmModalMsg').innerHTML = message;
  const btn = document.getElementById('confirmModalBtn');
  btn.textContent = btnLabel;
  btn.className = 'modal-btn ' + (btnClass || 'modal-btn-primary');
  btn.onclick = async () => { closeModal('confirmModal'); await onConfirm(); };
  openModal('confirmModal');
}

function addSection() {
  const input = document.getElementById('addSectionInput');
  const err   = document.getElementById('addSectionError');
  input.value = '';
  err.style.display = 'none';
  openModal('addSectionModal');
  setTimeout(() => input.focus(), 50);
}

document.getElementById('addSectionInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') confirmAddSection();
});

async function confirmAddSection() {
  const input = document.getElementById('addSectionInput');
  const err   = document.getElementById('addSectionError');
  const trimmed = input.value.trim();
  if (!trimmed) { err.textContent = 'Section name cannot be empty.'; err.style.display = 'block'; return; }

  const fd = new FormData();
  fd.append('ajax_add_category', '1');
  fd.append('name', trimmed);
  try {
    const res  = await fetch('commissary_month_end_inv.php', {method:'POST', body:fd});
    const json = await res.json();
    if (json.ok) {
      location.reload();
    } else {
      err.textContent = json.msg || 'Could not add section.';
      err.style.display = 'block';
    }
  } catch(e) {
    err.textContent = 'Network error';
    err.style.display = 'block';
  }
}

async function removeSection(catIdx, catName) {
  showConfirm({
    title: 'Remove Section',
    message: `Remove the "<strong>${catName}</strong>" section?`,
    btnLabel: 'Remove',
    btnClass: 'modal-btn-primary',
    onConfirm: async () => {
      const tryDelete = async (force) => {
        const fd = new FormData();
        fd.append('ajax_delete_category', '1');
        fd.append('name', catName);
        if (force) fd.append('force', '1');
        const res = await fetch('commissary_month_end_inv.php', {method:'POST', body:fd});
        return await res.json();
      };

      let json = await tryDelete(false);
      if (!json.ok && json.needsForce) {
        showConfirm({
          title: 'Section Not Empty',
          danger: true,
          message: `"<strong>${catName}</strong>" still has <strong>${json.itemCount}</strong> saved item(s) across one or more dates.`
                  + `<br><br>Delete the section <strong>and permanently delete all ${json.itemCount} of those items too</strong>?`
                  + `<br><br>This cannot be undone.`,
          btnLabel: 'Delete Section & Items',
          btnClass: 'modal-btn-danger',
          onConfirm: async () => {
            const json2 = await tryDelete(true);
            if (json2.ok) location.reload();
            else alert(json2.msg || 'Could not remove section.');
          }
        });
        return;
      }
      if (json.ok) location.reload();
      else alert(json.msg || 'Could not remove section.');
    }
  });
}

// ── Duplicate to Other Days ────────────────────────────────
// dupSelectedDates holds full 'YYYY-MM-DD' strings, so selections can
// span any month/year, not just the one currently on screen.
let dupSelectedDates = new Set();
let dupBrowseYear    = INV_YEAR;
let dupBrowseMonth   = INV_MONTH;

function openDuplicateModal() {
  dupSelectedDates = new Set();
  dupBrowseYear  = INV_YEAR;
  dupBrowseMonth = INV_MONTH;
  document.getElementById('dupCopyQty').checked = false;
  document.getElementById('dupMode').value = 'skip';
  document.getElementById('dupSummary').style.display = 'none';
  populateDupMonthYearSelects();
  renderDupGrid(DAY_COUNTS, DAYS_IN_MONTH); // current month's data is already on the page — no fetch needed
  updateDupConfirmBtn();
  openModal('duplicateModal');
}

function populateDupMonthYearSelects() {
  const monthSel = document.getElementById('dupBrowseMonth');
  const yearSel  = document.getElementById('dupBrowseYear');
  if (!monthSel.options.length) {
    MONTH_NAMES.forEach((name, i) => monthSel.appendChild(new Option(name, i + 1)));
  }
  if (!yearSel.options.length) {
    for (let y = INV_YEAR - 5; y <= INV_YEAR + 10; y++) yearSel.appendChild(new Option(y, y));
  }
  monthSel.value = dupBrowseMonth;
  yearSel.value  = dupBrowseYear;
}

function dupGoToMonth(offset) {
  let m = dupBrowseMonth + offset, y = dupBrowseYear;
  if (m < 1)  { m = 12; y--; }
  if (m > 12) { m = 1;  y++; }
  dupBrowseTo(y, m);
}

function dupBrowseSelectChange() {
  const y = parseInt(document.getElementById('dupBrowseYear').value);
  const m = parseInt(document.getElementById('dupBrowseMonth').value);
  dupBrowseTo(y, m);
}

async function dupBrowseTo(year, month) {
  dupBrowseYear = year; dupBrowseMonth = month;
  document.getElementById('dupBrowseYear').value  = year;
  document.getElementById('dupBrowseMonth').value = month;

  // The currently-viewed page's month is already loaded — skip the round trip.
  if (year === INV_YEAR && month === INV_MONTH) {
    renderDupGrid(DAY_COUNTS, DAYS_IN_MONTH);
    return;
  }

  const grid = document.getElementById('dupDayGrid');
  grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:14px;color:var(--subtext);font-family:var(--font-m);font-size:.72rem">Loading…</div>';

  const fd = new FormData();
  fd.append('ajax_month_counts', '1');
  fd.append('year', year);
  fd.append('month', month);
  try {
    const res  = await fetch('commissary_month_end_inv.php', {method:'POST', body:fd});
    const json = await res.json();
    if (json.ok) {
      renderDupGrid(json.counts, json.days);
    } else {
      grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:14px;color:#b91c1c;font-family:var(--font-m);font-size:.72rem">Could not load that month.</div>';
    }
  } catch (e) {
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:14px;color:#b91c1c;font-family:var(--font-m);font-size:.72rem">Network error.</div>';
  }
}

function renderDupGrid(counts, daysInMonth) {
  const grid = document.getElementById('dupDayGrid');
  const isSourceMonth = dupBrowseYear === INV_YEAR && dupBrowseMonth === INV_MONTH;
  let html = '';
  for (let d = 1; d <= daysInMonth; d++) {
    const iso      = `${dupBrowseYear}-${String(dupBrowseMonth).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const isSource = isSourceMonth && d === INV_DAY;
    const cnt      = counts[String(d)] || 0;
    const hasData  = cnt > 0 && !isSource;
    const checked  = dupSelectedDates.has(iso);
    const tip      = isSource ? 'This is the source date' : (hasData ? cnt + ' item(s) already here' : 'No items yet');
    html += `<div class="dup-day-chip${hasData ? ' has-data' : ''}${isSource ? ' is-source' : ''}${checked ? ' checked' : ''}"
                  data-iso="${iso}" title="${tip}"
                  onclick="${isSource ? '' : `toggleDupDate('${iso}')`}">${String(d).padStart(2,'0')}</div>`;
  }
  grid.innerHTML = html;
  updateDupConfirmBtn();
}

function toggleDupDate(iso) {
  const chip = document.querySelector(`.dup-day-chip[data-iso="${iso}"]`);
  if (dupSelectedDates.has(iso)) {
    dupSelectedDates.delete(iso);
    chip.classList.remove('checked');
  } else {
    dupSelectedDates.add(iso);
    chip.classList.add('checked');
  }
  updateDupConfirmBtn();
}

function dupSelectAll() {
  document.querySelectorAll('.dup-day-chip:not(.is-source)').forEach(chip => {
    dupSelectedDates.add(chip.dataset.iso);
    chip.classList.add('checked');
  });
  updateDupConfirmBtn();
}

function dupClearAll() {
  dupSelectedDates.clear();
  document.querySelectorAll('.dup-day-chip').forEach(chip => chip.classList.remove('checked'));
  updateDupConfirmBtn();
}

function updateDupConfirmBtn() {
  const btn  = document.getElementById('dupConfirmBtn');
  const note = document.getElementById('dupSelectedNote');
  const n    = dupSelectedDates.size;
  btn.textContent = n ? `Duplicate to ${n} Day${n > 1 ? 's' : ''}` : 'Duplicate';
  btn.disabled = n === 0;
  if (note) note.textContent = n ? `${n} date(s) selected in total (across all months browsed)` : '';
}

async function confirmDuplicate() {
  if (!dupSelectedDates.size) return;

  const targetDates = Array.from(dupSelectedDates).sort();

  const btn = document.getElementById('dupConfirmBtn');
  const origLabel = btn.textContent;
  btn.disabled = true; btn.textContent = 'Duplicating…';

  const fd = new FormData();
  fd.append('ajax_duplicate_day', '1');
  fd.append('src_date', INV_DATE);
  fd.append('target_dates', JSON.stringify(targetDates));
  fd.append('copy_qty', document.getElementById('dupCopyQty').checked ? '1' : '');
  fd.append('mode', document.getElementById('dupMode').value);

  try {
    const res  = await fetch('commissary_month_end_inv.php', {method:'POST', body:fd});
    const json = await res.json();
    if (json.ok) {
      const s = json.summary;
      const box = document.getElementById('dupSummary');
      let msg = `✓ Copied ${json.itemCount} item(s) into ${s.copied.length} day(s).`;
      if (s.skipped.length) msg += ` Skipped ${s.skipped.length} day(s) that already had items.`;
      box.textContent = msg;
      box.style.display = 'block';
      btn.textContent = 'Done ✓';
      setTimeout(() => { closeModal('duplicateModal'); location.reload(); }, 1200);
    } else {
      alert('Error: ' + (json.msg || 'Could not duplicate.'));
      btn.disabled = false; btn.textContent = origLabel;
    }
  } catch (e) {
    alert('Network error while duplicating.');
    btn.disabled = false; btn.textContent = origLabel;
  }
}

document.addEventListener('DOMContentLoaded', refreshGrandTotal);
</script>
</body>
</html>