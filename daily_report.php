<?php
// ============================================================
//  daily_report.php — Daily Store Report Input (CRUD)
//  Extra expenses stored as JSON in extra_expenses column
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$pdo  = getPDO();
$user = currentUser();

// ── Handle POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $report_date  = $_POST['report_date'] ?? date('Y-m-d');
        // Branch accounts always use their own branch name; management can set freely
        $store_name   = isBranch() ? currentBranch() : trim($_POST['store_name'] ?? 'Recovery');

        $gross_sales        = (float)($_POST['gross_sales']        ?? 0);
        $sales_discount     = (float)($_POST['sales_discount']     ?? 0);
        $gulay_commissary   = (float)($_POST['gulay_commissary']   ?? 0);
        $direct_purchases   = (float)($_POST['direct_purchases']   ?? 0);
        $other_expenses     = (float)($_POST['other_expenses']     ?? 0);
        $kitchen_manpower   = (float)($_POST['kitchen_manpower']   ?? 0);
        $frontline_manpower = (float)($_POST['frontline_manpower'] ?? 0);
        $overtime           = (float)($_POST['overtime']           ?? 0);
        $undertime          = (float)($_POST['undertime']          ?? 0);
        $quota_target       = (float)($_POST['quota_target']       ?? 9000);
        $cogs_threshold     = (float)($_POST['cogs_threshold']     ?? 30);
        $mp_threshold       = (float)($_POST['mp_threshold']       ?? 35);
        $np_threshold       = (float)($_POST['np_threshold']       ?? 20);

        // Build extra expenses JSON
        $extraItems   = [];
        $extraLabels  = $_POST['extra_label']  ?? [];
        $extraAmounts = $_POST['extra_amount'] ?? [];
        $extraTypes   = $_POST['extra_type']   ?? [];
        foreach ($extraLabels as $i => $lbl) {
            $lbl = trim($lbl);
            $amt = (float)($extraAmounts[$i] ?? 0);
            $typ = in_array($extraTypes[$i] ?? '', ['cogs','non_cogs']) ? $extraTypes[$i] : 'non_cogs';
            if ($lbl !== '' || $amt > 0) {
                $extraItems[] = ['label' => $lbl ?: 'Expense '.($i+1), 'amount' => $amt, 'type' => $typ];
            }
        }
        $extraJson = json_encode($extraItems);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE daily_reports SET
                report_date=?,store_name=?,gross_sales=?,sales_discount=?,
                gulay_commissary=?,direct_purchases=?,other_expenses=?,
                kitchen_manpower=?,frontline_manpower=?,overtime=?,undertime=?,
                quota_target=?,cogs_threshold=?,mp_threshold=?,np_threshold=?,
                extra_expenses=? WHERE id=?");
            $stmt->execute([$report_date,$store_name,$gross_sales,$sales_discount,
                $gulay_commissary,$direct_purchases,$other_expenses,
                $kitchen_manpower,$frontline_manpower,$overtime,$undertime,
                $quota_target,$cogs_threshold,$mp_threshold,$np_threshold,$extraJson,$id]);
            flashSet('success','Daily report updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO daily_reports
                (report_date,store_name,gross_sales,sales_discount,
                 gulay_commissary,direct_purchases,other_expenses,
                 kitchen_manpower,frontline_manpower,overtime,undertime,
                 quota_target,cogs_threshold,mp_threshold,np_threshold,
                 extra_expenses,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                store_name=VALUES(store_name),gross_sales=VALUES(gross_sales),
                sales_discount=VALUES(sales_discount),gulay_commissary=VALUES(gulay_commissary),
                direct_purchases=VALUES(direct_purchases),other_expenses=VALUES(other_expenses),
                kitchen_manpower=VALUES(kitchen_manpower),frontline_manpower=VALUES(frontline_manpower),
                overtime=VALUES(overtime),undertime=VALUES(undertime),
                quota_target=VALUES(quota_target),cogs_threshold=VALUES(cogs_threshold),
                mp_threshold=VALUES(mp_threshold),np_threshold=VALUES(np_threshold),
                extra_expenses=VALUES(extra_expenses)");
            $stmt->execute([$report_date,$store_name,$gross_sales,$sales_discount,
                $gulay_commissary,$direct_purchases,$other_expenses,
                $kitchen_manpower,$frontline_manpower,$overtime,$undertime,
                $quota_target,$cogs_threshold,$mp_threshold,$np_threshold,
                $extraJson,$user['name']]);
            flashSet('success','Daily report saved.');
        }
        header('Location: daily_report.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM daily_reports WHERE id=?")->execute([$id]);
        flashSet('success','Report deleted.');
        header('Location: daily_report.php'); exit;
    }
}

// ── Load for edit ──────────────────────────────────────────
$editRow = null;
if (isset($_GET['edit'])) {
    [$bClause, $bParams] = branchFilter('store_name');
    $s = $pdo->prepare("SELECT * FROM daily_reports WHERE id=? AND $bClause");
    $s->execute(array_merge([(int)$_GET['edit']], $bParams));
    $editRow = $s->fetch();
}

// ── Filters ───────────────────────────────────────────────
$fYear  = (int)($_GET['year']  ?? date('Y'));
$fMonth = (int)($_GET['month'] ?? date('n'));
$monthNames = ['','January','February','March','April','May','June',
               'July','August','September','October','November','December'];

[$bClause, $bParams] = branchFilter('store_name');
$rows = $pdo->prepare("SELECT * FROM daily_reports WHERE YEAR(report_date)=? AND MONTH(report_date)=? AND $bClause ORDER BY report_date DESC");
$rows->execute(array_merge([$fYear, $fMonth], $bParams));
$reports = $rows->fetchAll();

// ── Metrics helper ────────────────────────────────────────
function calcMetrics(array $r): array {
    $gross = (float)$r['gross_sales'];
    $disc  = (float)$r['sales_discount'];
    $gp    = $gross - $disc;
    $gulay  = (float)$r['gulay_commissary'];
    $direct = (float)$r['direct_purchases'];
    $other  = (float)$r['other_expenses'];
    $totalCogs = $gulay + $direct;

    $extras = json_decode($r['extra_expenses'] ?? '[]', true) ?: [];
    $extraCogs = $extraNonCogs = 0;
    foreach ($extras as $e) {
        if (($e['type']??'') === 'cogs') $extraCogs    += (float)($e['amount']??0);
        else                             $extraNonCogs += (float)($e['amount']??0);
    }
    $totalExp = $totalCogs + $extraCogs + $other + $extraNonCogs;

    $kitchen = (float)$r['kitchen_manpower'];
    $front   = (float)$r['frontline_manpower'];
    $ot      = (float)$r['overtime'];
    $ut      = (float)$r['undertime'];
    $totalMp = $kitchen + $front + $ot + $ut;

    $totalAllExp = $totalExp + $totalMp;
    $profitLoss  = $gp - $totalAllExp;

    $cogsP     = $gp > 0 ? round(($totalExp    / $gp) * 100, 1) : 0;
    $mpP       = $gp > 0 ? round(($totalMp     / $gp) * 100, 1) : 0;
    $totalExpP = $gp > 0 ? round(($totalAllExp / $gp) * 100, 1) : 0;
    $npP       = $gp > 0 ? round(($profitLoss  / $gp) * 100, 1) : 0;

    $quotaPassed = $gross >= (float)($r['quota_target']    ?? 9000);
    $cogsPassed  = $cogsP <= (float)($r['cogs_threshold']  ?? 30);
    $mpPassed    = $mpP   <= (float)($r['mp_threshold']    ?? 35);
    $npPassed    = $npP   >= (float)($r['np_threshold']    ?? 20);

    return compact('gp','totalCogs','other','totalExp','totalMp',
                   'totalAllExp','profitLoss','cogsP','mpP','totalExpP','npP',
                   'quotaPassed','cogsPassed','mpPassed','npPassed','extras');
}

$pageTitle  = 'Daily Report';
$activePage = 'daily';
include 'layout.php';
?>

<div class="section-header">
  <div>
    <div class="section-title">Daily <span>Report</span></div>
    <div class="section-subtitle">Store performance — <?=$monthNames[$fMonth]?> <?=$fYear?></div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ New Entry</button>
</div>

<!-- Month / Year tabs -->
<div class="card" style="padding:12px 18px;margin-bottom:18px">
  <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
    <?php for($m=1;$m<=12;$m++): ?>
    <a href="?month=<?=$m?>&year=<?=$fYear?>"
       style="padding:5px 13px;border-radius:6px;font-size:.74rem;font-weight:600;text-decoration:none;transition:all .15s;
              <?=$fMonth===$m?'background:var(--accent);color:#031a12;':'color:var(--subtext2);'?>">
      <?=substr($monthNames[$m],0,3)?>
    </a>
    <?php endfor; ?>
    <div style="margin-left:auto">
      <select onchange="window.location='?month=<?=$fMonth?>&year='+this.value"
              class="form-control" style="padding:5px 10px;font-size:.74rem;font-weight:600;max-width:90px;
                     background:var(--surf2);border:1px solid rgba(34,211,165,.3);color:var(--accent)">
        <?php for($y=2050;$y>=2020;$y--): ?>
        <option value="<?=$y?>" <?=$fYear===$y?'selected':''?>><?=$y?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>
</div>

<!-- Records list -->
<?php if ($reports): ?>
<div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px">
  <?php foreach ($reports as $r):
    $m = calcMetrics($r);
    $extras = $m['extras'];
  ?>
  <div class="card" style="padding:0;overflow:hidden">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 20px;
                background:var(--surf2);border-bottom:1px solid var(--border)">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="font-size:.92rem;font-weight:700"><?=date('F j, Y',strtotime($r['report_date']))?></div>
        <span style="font-family:var(--font-m);font-size:.64rem;color:var(--subtext);
                     background:var(--bg);padding:3px 9px;border-radius:20px;border:1px solid var(--border)">
          <?=htmlspecialchars($r['store_name'])?>
        </span>
      </div>
      <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
        <?php foreach([['QUOTA',$m['quotaPassed']],['COGS',$m['cogsPassed']],['MP',$m['mpPassed']],['NP',$m['npPassed']]] as [$lbl,$pass]): ?>
        <span style="font-family:var(--font-m);font-size:.62rem;padding:3px 9px;border-radius:20px;
              background:<?=$pass?'rgba(34,211,165,.12)':'rgba(248,113,113,.12)'?>;
              color:<?=$pass?'var(--accent)':'var(--accent2)'?>;
              border:1px solid <?=$pass?'rgba(34,211,165,.2)':'rgba(248,113,113,.2)'?>">
          <?=$lbl?> <?=$pass?'✓ PASSED':'✗ FAILED'?>
        </span>
        <?php endforeach; ?>
        <a href="?edit=<?=$r['id']?>&month=<?=$fMonth?>&year=<?=$fYear?>" class="btn btn-ghost btn-sm">Edit</a>
        <form method="POST" onsubmit="return confirm('Delete this report?')" style="display:inline">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <button class="btn btn-danger btn-sm">Del</button>
        </form>
      </div>
    </div>

    <!-- Top 5 metrics -->
    <div style="display:grid;grid-template-columns:repeat(5,1fr);border-bottom:1px solid var(--border)">
      <?php
        $cells = [
          ['Gross Sales',    '₱'.number_format($r['gross_sales'],2),     'var(--text)',   null],
          ['Sales Discount', '₱'.number_format($r['sales_discount'],2),  'var(--accent2)',null],
          ['Gross Profit',   '₱'.number_format($m['gp'],2),              'var(--accent2)',$r['gross_sales']>0?round(($m['gp']/$r['gross_sales'])*100,1).'% of sales':null],
          ['Total Expenses', '₱'.number_format($m['totalAllExp'],2),     'var(--accent3)',$m['totalExpP'].'% of GP'],
          ['Profit / Loss',  ($m['profitLoss']<0?'−':'').'₱'.number_format(abs($m['profitLoss']),2),
                             $m['profitLoss']>=0?'var(--accent)':'var(--accent2)',
                             $m['npP'].'% · NP '.($m['npPassed']?'PASSED':'FAILED')],
        ];
        foreach ($cells as $i=>[$lbl,$val,$clr,$sub]):
      ?>
      <div style="padding:13px 16px;<?=$i<4?'border-right:1px solid var(--border);':''?>
                  <?=$i===4?($m['profitLoss']>=0?'background:rgba(34,211,165,.03)':'background:rgba(248,113,113,.03)'):''?>">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.08em;color:var(--subtext);margin-bottom:4px"><?=$lbl?></div>
        <div style="font-size:1rem;font-weight:700;color:<?=$clr?>"><?=$val?></div>
        <?php if($sub): ?><div style="font-family:var(--font-m);font-size:.66rem;color:var(--subtext);margin-top:2px"><?=$sub?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Detail breakdown -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr">
      <!-- COGS -->
      <div style="padding:12px 16px;border-right:1px solid var(--border)">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;
                    color:var(--subtext);margin-bottom:7px">
          COGS &nbsp;<span style="color:<?=$m['cogsPassed']?'var(--accent)':'var(--accent2)'?>"><?=$m['cogsP']?>%</span>
        </div>
        <?php foreach([['Gulay/Commissary',$r['gulay_commissary']],['Direct COGS',$r['direct_purchases']]] as [$n,$a]): ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--subtext2)"><?=$n?></span><span>₱<?=number_format($a,2)?></span>
        </div>
        <?php endforeach; ?>
        <?php foreach($extras as $ex): if(($ex['type']??'')!=='cogs') continue; ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--accent3)"><?=htmlspecialchars($ex['label'])?></span><span>₱<?=number_format($ex['amount'],2)?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Non-COGS -->
      <div style="padding:12px 16px;border-right:1px solid var(--border)">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;color:var(--subtext);margin-bottom:7px">Other Expenses</div>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--subtext2)">Other Non-COGS</span><span>₱<?=number_format($r['other_expenses'],2)?></span>
        </div>
        <?php foreach($extras as $ex): if(($ex['type']??'')!=='non_cogs') continue; ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--blue)"><?=htmlspecialchars($ex['label'])?></span><span>₱<?=number_format($ex['amount'],2)?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Manpower -->
      <div style="padding:12px 16px;border-right:1px solid var(--border)">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;
                    color:var(--subtext);margin-bottom:7px">
          Manpower &nbsp;<span style="color:<?=$m['mpPassed']?'var(--accent)':'var(--accent2)'?>"><?=$m['mpP']?>%</span>
        </div>
        <?php foreach([['Kitchen',$r['kitchen_manpower']],['Frontline',$r['frontline_manpower']],['Overtime',$r['overtime']],['Undertime',$r['undertime']]] as [$n,$a]): ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--subtext2)"><?=$n?></span><span>₱<?=number_format($a,2)?></span>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;font-weight:600;margin-top:4px;padding-top:4px;border-top:1px solid var(--border)">
          <span>Total MP</span><span>₱<?=number_format($m['totalMp'],2)?></span>
        </div>
      </div>

      <!-- Summary -->
      <div style="padding:12px 16px;background:<?=$m['profitLoss']>=0?'rgba(34,211,165,.03)':'rgba(248,113,113,.03)'?>">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;color:var(--subtext);margin-bottom:7px">Summary</div>
        <?php foreach([['Total Expenses','₱'.number_format($m['totalAllExp'],2)],['Exp % of GP',$m['totalExpP'].'%']] as [$n,$v]): ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--subtext2)"><?=$n?></span><span><?=$v?></span>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.74rem;font-weight:700;margin-top:6px;padding-top:5px;border-top:1px solid var(--border)">
          <span style="color:<?=$m['profitLoss']>=0?'var(--accent)':'var(--accent2)'?>">
            <?=$m['profitLoss']>=0?'PROFIT':'LOSS'?> <?=$m['npP']?>%
          </span>
          <span style="color:<?=$m['profitLoss']>=0?'var(--accent)':'var(--accent2)'?>">
            ₱<?=number_format(abs($m['profitLoss']),2)?>
          </span>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:48px">
  <div style="font-size:2rem;margin-bottom:12px;opacity:.3">📋</div>
  <div style="font-family:var(--font-m);font-size:.8rem;color:var(--subtext)">No reports for <?=$monthNames[$fMonth]?> <?=$fYear?></div>
  <button class="btn btn-primary" style="margin-top:16px" onclick="openModal('addModal')">+ Add First Entry</button>
</div>
<?php endif; ?>

<!-- ══ ADD MODAL ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:620px">
    <div class="modal-title">📋 New Daily Report</div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group">
          <label>Date *</label>
          <input name="report_date" type="date" class="form-control" value="<?=date('Y-m-d')?>" required>
        </div>
        <div class="form-group">
          <label>Store Name</label>
          <?php if (isBranch()): ?>
          <input name="store_name" class="form-control" value="<?= htmlspecialchars(currentBranch()) ?>" readonly
                 style="opacity:.6;cursor:not-allowed">
          <?php else: ?>
          <input name="store_name" class="form-control" value="Recovery">
          <?php endif; ?>
        </div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent)">💰 Sales</div>
      <div class="form-row" style="margin-bottom:18px">
        <div class="form-group">
          <label>Gross Sales (₱) *</label>
          <input name="gross_sales" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" required>
        </div>
        <div class="form-group">
          <label>Sales Discount (₱)</label>
          <input name="sales_discount" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0">
        </div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent3)">🛒 COGS &amp; Expenses</div>
      <div class="dr-exp-list">
        <div class="dr-fixed-row">
          <div class="dr-badges"><span class="dr-badge-cogs">COGS</span></div>
          <span class="dr-row-name">Gulay / Commissary</span>
          <input name="gulay_commissary" type="number" step="0.01" min="0" class="form-control dr-amt" placeholder="0.00" value="0">
        </div>
        <div class="dr-fixed-row">
          <div class="dr-badges"><span class="dr-badge-cogs">COGS</span></div>
          <span class="dr-row-name">Direct Purchases (COGS)</span>
          <input name="direct_purchases" type="number" step="0.01" min="0" class="form-control dr-amt" placeholder="0.00" value="0">
        </div>
        <div class="dr-fixed-row">
          <div class="dr-badges"><span class="dr-badge-ncogs">NON-COGS</span></div>
          <span class="dr-row-name">Other Expenses (Non-COGS)</span>
          <input name="other_expenses" type="number" step="0.01" min="0" class="form-control dr-amt" placeholder="0.00" value="0">
        </div>
      </div>
      <!-- Dynamic rows -->
      <div id="dynAdd" class="dr-dyn-container"></div>
      <button type="button" class="dr-add-btn" onclick="addExpRow('dynAdd')">＋ Add Expense Row</button>

      <div class="dr-section-hdr" style="--sc:var(--blue);margin-top:18px">👥 Manpower</div>
      <div class="form-row">
        <div class="form-group"><label>Kitchen Manpower (₱)</label><input name="kitchen_manpower" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0"></div>
        <div class="form-group"><label>Frontline Manpower (₱)</label><input name="frontline_manpower" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0"></div>
      </div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>Overtime (₱)</label><input name="overtime" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0"></div>
        <div class="form-group"><label>Undertime (₱)</label><input name="undertime" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0"></div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--subtext)">⚙ Thresholds</div>
      <div class="form-row">
        <div class="form-group"><label>Quota Target (₱)</label><input name="quota_target" type="number" step="0.01" min="0" class="form-control" value="9000"></div>
        <div class="form-group"><label>COGS Max (%)</label><input name="cogs_threshold" type="number" step="0.1" min="0" max="100" class="form-control" value="30"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>MP Max (%)</label><input name="mp_threshold" type="number" step="0.1" min="0" max="100" class="form-control" value="35"></div>
        <div class="form-group"><label>NP Min (%)</label><input name="np_threshold" type="number" step="0.1" min="0" max="100" class="form-control" value="20"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Report</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<?php if ($editRow): ?>
<?php $d=$editRow; $em=calcMetrics($d); $editExtras=$em['extras']; ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal" style="max-width:620px">
    <div class="modal-title">✏️ Edit — <?=date('F j, Y',strtotime($d['report_date']))?></div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?=$d['id']?>">
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>Date *</label><input name="report_date" type="date" class="form-control" value="<?=$d['report_date']?>" required></div>
        <div class="form-group"><label>Store Name</label>
          <?php if (isBranch()): ?>
          <input name="store_name" class="form-control" value="<?= htmlspecialchars(currentBranch()) ?>" readonly
                 style="opacity:.6;cursor:not-allowed">
          <?php else: ?>
          <input name="store_name" class="form-control" value="<?=htmlspecialchars($d['store_name'])?>">
          <?php endif; ?>
        </div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent)">💰 Sales</div>
      <div class="form-row" style="margin-bottom:18px">
        <div class="form-group"><label>Gross Sales (₱) *</label><input name="gross_sales" type="number" step="0.01" min="0" class="form-control" value="<?=$d['gross_sales']?>" required></div>
        <div class="form-group"><label>Sales Discount (₱)</label><input name="sales_discount" type="number" step="0.01" min="0" class="form-control" value="<?=$d['sales_discount']?>"></div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent3)">🛒 COGS &amp; Expenses</div>
      <div class="dr-exp-list">
        <div class="dr-fixed-row">
          <div class="dr-badges"><span class="dr-badge-cogs">COGS</span></div>
          <span class="dr-row-name">Gulay / Commissary</span>
          <input name="gulay_commissary" type="number" step="0.01" min="0" class="form-control dr-amt" value="<?=$d['gulay_commissary']?>">
        </div>
        <div class="dr-fixed-row">
          <div class="dr-badges"><span class="dr-badge-cogs">COGS</span></div>
          <span class="dr-row-name">Direct Purchases (COGS)</span>
          <input name="direct_purchases" type="number" step="0.01" min="0" class="form-control dr-amt" value="<?=$d['direct_purchases']?>">
        </div>
        <div class="dr-fixed-row">
          <div class="dr-badges"><span class="dr-badge-ncogs">NON-COGS</span></div>
          <span class="dr-row-name">Other Expenses (Non-COGS)</span>
          <input name="other_expenses" type="number" step="0.01" min="0" class="form-control dr-amt" value="<?=$d['other_expenses']?>">
        </div>
      </div>

      <!-- Pre-filled extra rows -->
      <div id="dynEdit" class="dr-dyn-container">
        <?php foreach($editExtras as $ex): ?>
        <div class="dr-dyn-row">
          <select name="extra_type[]" class="form-control dr-type-sel">
            <option value="cogs"     <?=($ex['type']??'')==='cogs'?'selected':''?>>COGS</option>
            <option value="non_cogs" <?=($ex['type']??'')==='non_cogs'?'selected':''?>>Non-COGS</option>
          </select>
          <input name="extra_label[]" type="text" class="form-control" placeholder="Expense name…" value="<?=htmlspecialchars($ex['label']??'')?>">
          <input name="extra_amount[]" type="number" step="0.01" min="0" class="form-control dr-amt" placeholder="0.00" value="<?=number_format($ex['amount']??0,2,'.','')?>">
          <button type="button" class="dr-remove-btn" onclick="this.closest('.dr-dyn-row').remove()">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="dr-add-btn" onclick="addExpRow('dynEdit')">＋ Add Expense Row</button>

      <div class="dr-section-hdr" style="--sc:var(--blue);margin-top:18px">👥 Manpower</div>
      <div class="form-row">
        <div class="form-group"><label>Kitchen Manpower (₱)</label><input name="kitchen_manpower" type="number" step="0.01" min="0" class="form-control" value="<?=$d['kitchen_manpower']?>"></div>
        <div class="form-group"><label>Frontline Manpower (₱)</label><input name="frontline_manpower" type="number" step="0.01" min="0" class="form-control" value="<?=$d['frontline_manpower']?>"></div>
      </div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>Overtime (₱)</label><input name="overtime" type="number" step="0.01" min="0" class="form-control" value="<?=$d['overtime']?>"></div>
        <div class="form-group"><label>Undertime (₱)</label><input name="undertime" type="number" step="0.01" min="0" class="form-control" value="<?=$d['undertime']?>"></div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--subtext)">⚙ Thresholds</div>
      <div class="form-row">
        <div class="form-group"><label>Quota Target (₱)</label><input name="quota_target" type="number" step="0.01" min="0" class="form-control" value="<?=$d['quota_target']?>"></div>
        <div class="form-group"><label>COGS Max (%)</label><input name="cogs_threshold" type="number" step="0.1" class="form-control" value="<?=$d['cogs_threshold']?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>MP Max (%)</label><input name="mp_threshold" type="number" step="0.1" class="form-control" value="<?=$d['mp_threshold']?>"></div>
        <div class="form-group"><label>NP Min (%)</label><input name="np_threshold" type="number" step="0.1" class="form-control" value="<?=$d['np_threshold']?>"></div>
      </div>
      <div class="modal-actions">
        <a href="daily_report.php?month=<?=$fMonth?>&year=<?=$fYear?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

  </div></div>

<style>
/* Section headers in modal */
.dr-section-hdr {
  font-family: var(--font-m); font-size: .62rem; font-weight: 500;
  text-transform: uppercase; letter-spacing: .1em;
  color: var(--sc, var(--subtext));
  margin-bottom: 10px; padding-bottom: 7px;
  border-bottom: 1px solid var(--border);
}
/* Fixed expense rows */
.dr-exp-list { display: flex; flex-direction: column; gap: 5px; }
.dr-fixed-row {
  display: grid; grid-template-columns: 80px 1fr 130px; gap: 10px;
  align-items: center; background: var(--bg);
  border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px;
}
.dr-badges { display: flex; }
.dr-badge-cogs, .dr-badge-ncogs {
  font-family: var(--font-m); font-size: .58rem; font-weight: 600;
  padding: 2px 7px; border-radius: 4px; letter-spacing: .03em;
}
.dr-badge-cogs  { background: rgba(251,191,36,.12); color: var(--accent3); border: 1px solid rgba(251,191,36,.2); }
.dr-badge-ncogs { background: rgba(96,165,250,.12);  color: var(--blue);    border: 1px solid rgba(96,165,250,.2); }
.dr-row-name { font-size: .8rem; color: var(--text); }
.dr-amt { text-align: right; }
/* Dynamic rows */
.dr-dyn-container { display: flex; flex-direction: column; gap: 5px; margin-top: 5px; }
.dr-dyn-row {
  display: grid; grid-template-columns: 110px 1fr 120px 30px;
  gap: 8px; align-items: center;
}
.dr-type-sel { font-size: .74rem; padding: 7px 8px; }
.dr-remove-btn {
  width: 30px; height: 30px; border-radius: 7px; flex-shrink: 0;
  border: 1px solid rgba(248,113,113,.2); background: rgba(248,113,113,.08);
  color: var(--accent2); cursor: pointer; font-size: .85rem;
  display: flex; align-items: center; justify-content: center;
}
.dr-remove-btn:hover { background: rgba(248,113,113,.2); }
/* Add button */
.dr-add-btn {
  margin-top: 8px; width: 100%; padding: 8px;
  background: rgba(34,211,165,.05); border: 1px dashed rgba(34,211,165,.25);
  color: var(--accent); border-radius: 8px; cursor: pointer;
  font-family: var(--font-m); font-size: .74rem; letter-spacing: .02em;
  transition: background .15s; display: flex; align-items: center; justify-content: center; gap: 6px;
}
.dr-add-btn:hover { background: rgba(34,211,165,.1); }
</style>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => {
  if (e.target === o) o.classList.remove('open');
}));

function addExpRow(containerId) {
  const container = document.getElementById(containerId);
  const row = document.createElement('div');
  row.className = 'dr-dyn-row';
  row.innerHTML = `
    <select name="extra_type[]" class="form-control dr-type-sel">
      <option value="non_cogs" selected>Non-COGS</option>
      <option value="cogs">COGS</option>
    </select>
    <input name="extra_label[]" type="text" class="form-control" placeholder="Expense name (editable)…">
    <input name="extra_amount[]" type="number" step="0.01" min="0" class="form-control dr-amt" placeholder="0.00" value="0">
    <button type="button" class="dr-remove-btn" onclick="this.closest('.dr-dyn-row').remove()">✕</button>
  `;
  container.appendChild(row);
  row.querySelector('input[name="extra_label[]"]').focus();
}
</script>
</body>
</html>