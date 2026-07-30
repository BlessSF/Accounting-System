<?php
// ============================================================
//  demic_daily_report.php — Demic Lab Daily Report Input (CRUD)
//  Payables stored as JSON in payables_expenses column
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();
enforceCashierAccess();

$pdo  = getPDO();
$user = currentUser();

// ── Create table ─────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `demic_daily_reports` (
    `id`                       int(11) NOT NULL AUTO_INCREMENT,
    `report_date`              date NOT NULL,
    `store_name`               varchar(50) NOT NULL DEFAULT 'Demic Lab',
    `sales_revenue`            decimal(12,2) NOT NULL DEFAULT 0.00,
    `sales_discount`           decimal(12,2) NOT NULL DEFAULT 0.00,
    `frontline_medical_staff`  decimal(12,2) NOT NULL DEFAULT 0.00,
    `pf_fee`                   decimal(12,2) NOT NULL DEFAULT 0.00,
    `cash`                     decimal(12,2) NOT NULL DEFAULT 0.00,
    `hmo`                      decimal(12,2) NOT NULL DEFAULT 0.00,
    `charge_to_company`        decimal(12,2) NOT NULL DEFAULT 0.00,
    `dr_cr`                    decimal(12,2) NOT NULL DEFAULT 0.00,
    `hmo_withholding_pct`      decimal(5,2)  NOT NULL DEFAULT 2.00,
    `quota_target`             decimal(12,2) NOT NULL DEFAULT 50000.00,
    `payables_threshold`       decimal(5,2)  NOT NULL DEFAULT 45.00,
    `mp_threshold`             decimal(5,2)  NOT NULL DEFAULT 10.00,
    `payables_expenses`        text,
    `created_by`               varchar(100) DEFAULT NULL,
    `updated_at`               timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

try { $pdo->exec("ALTER TABLE `demic_daily_reports` ADD UNIQUE KEY `ux_date_store` (`report_date`,`store_name`)"); } catch(Throwable $ignored){}

// ── Handle POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $report_date = $_POST['report_date'] ?? date('Y-m-d');
        // Branch accounts always use their own branch name; management can set freely
        $store_name  = isBranch() ? currentBranch() : trim($_POST['store_name'] ?? 'Demic Lab');

        $frontline_medical_staff = (float)($_POST['frontline_medical_staff'] ?? 0);
        $pf_fee                  = (float)($_POST['pf_fee']                  ?? 0);
        $cash                    = (float)($_POST['cash']                    ?? 0);
        $hmo                     = (float)($_POST['hmo']                     ?? 0);
        $charge_to_company       = (float)($_POST['charge_to_company']       ?? 0);
        $dr_cr                   = (float)($_POST['dr_cr']                   ?? 0);
        $hmo_withholding_pct     = (float)($_POST['hmo_withholding_pct']     ?? 2);
        $quota_target            = (float)($_POST['quota_target']            ?? 50000);
        $payables_threshold      = (float)($_POST['payables_threshold']      ?? 45);
        $mp_threshold            = (float)($_POST['mp_threshold']            ?? 10);

        // Sales Revenue = total of the Payment Method Breakdown (Cash + HMO + Charge to Company + DR/CR)
        // Sales Discount / EW = HMO withholding tax = HMO * (HMO Withholding % / 100)
        // Both are derived, not typed in directly — matches the sheet's formulas.
        $sales_revenue  = round($cash + $hmo + $charge_to_company + $dr_cr, 2);
        $sales_discount = round($hmo * ($hmo_withholding_pct / 100), 2);

        // Build payables JSON (dynamic line items)
        $payItems   = [];
        $payLabels  = $_POST['pay_label']  ?? [];
        $payAmounts = $_POST['pay_amount'] ?? [];
        foreach ($payLabels as $i => $lbl) {
            $lbl = trim($lbl);
            $amt = (float)($payAmounts[$i] ?? 0);
            if ($lbl !== '' || $amt > 0) {
                $payItems[] = ['label' => $lbl ?: 'Payable '.($i+1), 'amount' => $amt];
            }
        }
        $payJson = json_encode($payItems);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE demic_daily_reports SET
                report_date=?,store_name=?,sales_revenue=?,sales_discount=?,
                frontline_medical_staff=?,pf_fee=?,cash=?,hmo=?,charge_to_company=?,dr_cr=?,
                hmo_withholding_pct=?,quota_target=?,payables_threshold=?,mp_threshold=?,
                payables_expenses=? WHERE id=?");
            $stmt->execute([$report_date,$store_name,$sales_revenue,$sales_discount,
                $frontline_medical_staff,$pf_fee,$cash,$hmo,$charge_to_company,$dr_cr,
                $hmo_withholding_pct,$quota_target,$payables_threshold,$mp_threshold,
                $payJson,$id]);
            flashSet('success','Demic Lab report updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO demic_daily_reports
                (report_date,store_name,sales_revenue,sales_discount,
                 frontline_medical_staff,pf_fee,cash,hmo,charge_to_company,dr_cr,
                 hmo_withholding_pct,quota_target,payables_threshold,mp_threshold,
                 payables_expenses,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                store_name=VALUES(store_name),sales_revenue=VALUES(sales_revenue),
                sales_discount=VALUES(sales_discount),
                frontline_medical_staff=VALUES(frontline_medical_staff),pf_fee=VALUES(pf_fee),
                cash=VALUES(cash),hmo=VALUES(hmo),charge_to_company=VALUES(charge_to_company),dr_cr=VALUES(dr_cr),
                hmo_withholding_pct=VALUES(hmo_withholding_pct),quota_target=VALUES(quota_target),
                payables_threshold=VALUES(payables_threshold),mp_threshold=VALUES(mp_threshold),
                payables_expenses=VALUES(payables_expenses)");
            $stmt->execute([$report_date,$store_name,$sales_revenue,$sales_discount,
                $frontline_medical_staff,$pf_fee,$cash,$hmo,$charge_to_company,$dr_cr,
                $hmo_withholding_pct,$quota_target,$payables_threshold,$mp_threshold,
                $payJson,$user['name']]);
            flashSet('success','Demic Lab report saved.');
        }
        header('Location: demic_daily_report.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM demic_daily_reports WHERE id=?")->execute([$id]);
        flashSet('success','Report deleted.');
        header('Location: demic_daily_report.php'); exit;
    }
}

// ── Load for edit ──────────────────────────────────────────
$editRow = null;
if (isset($_GET['edit'])) {
    [$bClause, $bParams] = branchFilter('store_name');
    $s = $pdo->prepare("SELECT * FROM demic_daily_reports WHERE id=? AND $bClause");
    $s->execute(array_merge([(int)$_GET['edit']], $bParams));
    $editRow = $s->fetch();
}

// ── Filters ───────────────────────────────────────────────
$fYear  = (int)($_GET['year']  ?? date('Y'));
$fMonth = (int)($_GET['month'] ?? date('n'));
$monthNames = ['','January','February','March','April','May','June',
               'July','August','September','October','November','December'];

[$bClause, $bParams] = branchFilter('store_name');
$rows = $pdo->prepare("SELECT * FROM demic_daily_reports WHERE YEAR(report_date)=? AND MONTH(report_date)=? AND $bClause ORDER BY report_date DESC");
$rows->execute(array_merge([$fYear, $fMonth], $bParams));
$reports = $rows->fetchAll();

// ── Metrics helper ────────────────────────────────────────
function calcDemicMetrics(array $r): array {
    $revenue = (float)$r['sales_revenue'];
    $disc    = (float)$r['sales_discount'];
    $gross   = $revenue - $disc;

    $payables = json_decode($r['payables_expenses'] ?? '[]', true) ?: [];
    $payablesTotal = 0;
    foreach ($payables as $p) { $payablesTotal += (float)($p['amount'] ?? 0); }

    $frontline = (float)$r['frontline_medical_staff'];
    $pfFee     = (float)$r['pf_fee'];
    $mpTotal   = $frontline + $pfFee;

    $totalExp   = $payablesTotal + $mpTotal;
    $profitLoss = $gross - $totalExp;

    $cash   = (float)$r['cash'];
    $hmo    = (float)$r['hmo'];
    $charge = (float)$r['charge_to_company'];
    $drCr   = (float)$r['dr_cr'];
    $paymentTotal = $cash + $hmo + $charge + $drCr;
    $paymentMismatch = abs($paymentTotal - $revenue) > 0.01;

    $hmoWithholdPct = (float)($r['hmo_withholding_pct'] ?? 2);
    $hmoWithholding = round($hmo * ($hmoWithholdPct / 100), 2);

    $discP     = $revenue > 0 ? round(($disc        / $revenue) * 100, 1) : 0;
    $payP      = $revenue > 0 ? round(($payablesTotal/ $revenue) * 100, 1) : 0;
    $mpP       = $revenue > 0 ? round(($mpTotal      / $revenue) * 100, 1) : 0;
    $totalExpP = $revenue > 0 ? round(($totalExp     / $revenue) * 100, 1) : 0;
    $npP       = $revenue > 0 ? round(($profitLoss   / $revenue) * 100, 1) : 0;

    $quotaPassed = $revenue > (float)($r['quota_target']       ?? 0);
    $payPassed   = $payP    < (float)($r['payables_threshold'] ?? 0);
    $mpPassed    = $mpP     < (float)($r['mp_threshold']       ?? 0);

    return compact('gross','payablesTotal','mpTotal','totalExp','profitLoss',
                   'discP','payP','mpP','totalExpP','npP',
                   'cash','hmo','charge','drCr','paymentTotal','paymentMismatch',
                   'hmoWithholdPct','hmoWithholding',
                   'quotaPassed','payPassed','mpPassed','payables');
}

$pageTitle  = 'Demic Lab Daily Report';
$activePage = 'demic_daily';
include 'layout.php';
?>

<div class="section-header">
  <div>
    <div class="section-title">Demic Lab Daily <span>Report</span></div>
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
    $m = calcDemicMetrics($r);
    $payables = $m['payables'];
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
        <?php foreach([['QUOTA',$m['quotaPassed']],['COGS/PAYABLES',$m['payPassed']],['MP',$m['mpPassed']]] as [$lbl,$pass]): ?>
        <span style="font-family:var(--font-m);font-size:.62rem;padding:3px 9px;border-radius:20px;
              background:<?=$pass?'rgba(34,211,165,.12)':'rgba(248,113,113,.12)'?>;
              color:<?=$pass?'var(--accent)':'var(--accent2)'?>;
              border:1px solid <?=$pass?'rgba(34,211,165,.2)':'rgba(248,113,113,.2)'?>">
          <?=$lbl?> <?=$pass?'✓ PASSED':'✗ FAILED'?>
        </span>
        <?php endforeach; ?>
        <?php if ($m['paymentMismatch']): ?>
        <span style="font-family:var(--font-m);font-size:.62rem;padding:3px 9px;border-radius:20px;
              background:rgba(251,191,36,.12);color:var(--accent3);border:1px solid rgba(251,191,36,.2)">
          ⚠ PAYMENTS MISMATCH
        </span>
        <?php endif; ?>
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
          ['Sales Revenue',  '₱'.number_format($r['sales_revenue'],2),    'var(--text)',   null],
          ['Sales Discount', '₱'.number_format($r['sales_discount'],2),   'var(--accent2)',$m['discP'].'% of revenue'],
          ['Gross Sales',    '₱'.number_format($m['gross'],2),            'var(--accent2)',null],
          ['Total Expenses', '₱'.number_format($m['totalExp'],2),         'var(--accent3)',$m['totalExpP'].'% of revenue'],
          ['Profit / Loss',  ($m['profitLoss']<0?'−':'').'₱'.number_format(abs($m['profitLoss']),2),
                             $m['profitLoss']>=0?'var(--accent)':'var(--accent2)',
                             $m['npP'].'% of revenue'],
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
      <!-- Payables -->
      <div style="padding:12px 16px;border-right:1px solid var(--border)">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;
                    color:var(--subtext);margin-bottom:7px">
          PAYABLES &nbsp;<span style="color:<?=$m['payPassed']?'var(--accent)':'var(--accent2)'?>"><?=$m['payP']?>%</span>
        </div>
        <?php if ($payables): foreach($payables as $p): ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--subtext2)"><?=htmlspecialchars($p['label'])?></span><span>₱<?=number_format($p['amount'],2)?></span>
        </div>
        <?php endforeach; else: ?>
        <div style="font-family:var(--font-m);font-size:.7rem;color:var(--subtext)">No payables listed</div>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;font-weight:600;margin-top:4px;padding-top:4px;border-top:1px solid var(--border)">
          <span>Total</span><span>₱<?=number_format($m['payablesTotal'],2)?></span>
        </div>
      </div>

      <!-- Manpower -->
      <div style="padding:12px 16px;border-right:1px solid var(--border)">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;color:var(--subtext);margin-bottom:7px">
          MANPOWER ON DUTY &nbsp;<span style="color:<?=$m['mpPassed']?'var(--accent)':'var(--accent2)'?>"><?=$m['mpP']?>%</span>
        </div>
        <?php foreach([['Frontline / Medical Staff',$r['frontline_medical_staff']],['PF Fee',$r['pf_fee']]] as [$n,$a]): ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--subtext2)"><?=$n?></span><span>₱<?=number_format($a,2)?></span>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;font-weight:600;margin-top:4px;padding-top:4px;border-top:1px solid var(--border)">
          <span>Total MP</span><span>₱<?=number_format($m['mpTotal'],2)?></span>
        </div>
      </div>

      <!-- Payment breakdown -->
      <div style="padding:12px 16px;border-right:1px solid var(--border)">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;color:var(--subtext);margin-bottom:7px">
          Payment Method
        </div>
        <?php foreach([['Cash',$m['cash']],['HMO',$m['hmo']],['Charge to Company',$m['charge']],['DR/CR',$m['drCr']]] as [$n,$a]): ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;margin-bottom:2px">
          <span style="color:var(--subtext2)"><?=$n?></span><span>₱<?=number_format($a,2)?></span>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;font-family:var(--font-m);font-size:.7rem;font-weight:600;margin-top:4px;padding-top:4px;border-top:1px solid var(--border);
                    <?=$m['paymentMismatch']?'color:var(--accent2)':''?>">
          <span>Total</span><span>₱<?=number_format($m['paymentTotal'],2)?></span>
        </div>
        <div style="font-family:var(--font-m);font-size:.62rem;color:var(--subtext);margin-top:4px">
          HMO WH Tax (<?=rtrim(rtrim(number_format($m['hmoWithholdPct'],2),'0'),'.')?>%): ₱<?=number_format($m['hmoWithholding'],2)?>
        </div>
      </div>

      <!-- Summary -->
      <div style="padding:12px 16px;background:<?=$m['profitLoss']>=0?'rgba(34,211,165,.03)':'rgba(248,113,113,.03)'?>">
        <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.07em;color:var(--subtext);margin-bottom:7px">Summary</div>
        <?php foreach([['Total Expenses','₱'.number_format($m['totalExp'],2)],['Exp % of Revenue',$m['totalExpP'].'%']] as [$n,$v]): ?>
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
    <div class="modal-title">📋 New Demic Lab Report</div>
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
          <input name="store_name" class="form-control" value="Demic Lab">
          <?php endif; ?>
        </div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent3)">🧾 Payables</div>
      <div id="payAdd" class="dr-dyn-container"></div>
      <button type="button" class="dr-add-btn" onclick="addPayRow('payAdd')">＋ Add Payable Row</button>

      <div class="dr-section-hdr" style="--sc:var(--blue);margin-top:18px">👥 Manpower on Duty</div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>Frontline / Medical Staff (₱)</label><input name="frontline_medical_staff" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0"></div>
        <div class="form-group"><label>PF Fee (IT, Patho, Doctor, Xray, ECG, 2D Echo, Ultrasound) (₱)</label><input name="pf_fee" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0"></div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent)">💳 Payment Method Breakdown</div>
      <div class="form-row">
        <div class="form-group"><label>Cash (₱)</label><input name="cash" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0" oninput="calcSalesSummary(this)"></div>
        <div class="form-group"><label>HMO (₱)</label><input name="hmo" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0" oninput="calcSalesSummary(this)"></div>
      </div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>Charge to Company (₱)</label><input name="charge_to_company" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0" oninput="calcSalesSummary(this)"></div>
        <div class="form-group"><label>DR/CR (₱)</label><input name="dr_cr" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" value="0" oninput="calcSalesSummary(this)"></div>
      </div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>HMO Withholding Tax (%)</label><input name="hmo_withholding_pct" type="number" step="0.01" min="0" max="100" class="form-control" value="2" oninput="calcSalesSummary(this)"></div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent)">💰 Sales Summary <span style="opacity:.6;font-weight:400">(auto-calculated)</span></div>
      <div class="form-row" style="margin-bottom:6px">
        <div class="form-group">
          <label>Sales Revenue / POS Reading (₱)</label>
          <input type="number" class="form-control sr-revenue" value="0.00" readonly tabindex="-1" style="background:var(--surf3);color:var(--subtext2);font-weight:600">
        </div>
        <div class="form-group">
          <label>Sales Discount / EW (₱)</label>
          <input type="number" class="form-control sr-discount" value="0.00" readonly tabindex="-1" style="background:var(--surf3);color:var(--subtext2);font-weight:600">
        </div>
      </div>
      <div class="form-row" style="margin-bottom:18px">
        <div class="form-group">
          <label>Gross Sales (₱)</label>
          <input type="number" class="form-control sr-gross" value="0.00" readonly tabindex="-1" style="background:var(--surf3);color:var(--accent);font-weight:700">
        </div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--subtext)">⚙ Thresholds</div>
      <div class="form-row">
        <div class="form-group"><label>Quota Target (₱)</label><input name="quota_target" type="number" step="0.01" min="0" class="form-control" value="50000"></div>
        <div class="form-group"><label>COGS/Payables Max (%)</label><input name="payables_threshold" type="number" step="0.1" min="0" max="100" class="form-control" value="45"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>MP Max (%)</label><input name="mp_threshold" type="number" step="0.1" min="0" max="100" class="form-control" value="10"></div>
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
<?php $d=$editRow; $em=calcDemicMetrics($d); $editPayables=$em['payables']; ?>
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

      <div class="dr-section-hdr" style="--sc:var(--accent3)">🧾 Payables</div>
      <div id="payEdit" class="dr-dyn-container">
        <?php foreach($editPayables as $p): ?>
        <div class="dr-dyn-row-2">
          <input name="pay_label[]" type="text" class="form-control" placeholder="Payable name…" value="<?=htmlspecialchars($p['label']??'')?>">
          <input name="pay_amount[]" type="number" step="0.01" min="0" class="form-control dr-amt" placeholder="0.00" value="<?=number_format($p['amount']??0,2,'.','')?>">
          <button type="button" class="dr-remove-btn" onclick="this.closest('.dr-dyn-row-2').remove()">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="dr-add-btn" onclick="addPayRow('payEdit')">＋ Add Payable Row</button>

      <div class="dr-section-hdr" style="--sc:var(--blue);margin-top:18px">👥 Manpower on Duty</div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>Frontline / Medical Staff (₱)</label><input name="frontline_medical_staff" type="number" step="0.01" min="0" class="form-control" value="<?=$d['frontline_medical_staff']?>"></div>
        <div class="form-group"><label>PF Fee (IT, Patho, Doctor, Xray, ECG, 2D Echo, Ultrasound) (₱)</label><input name="pf_fee" type="number" step="0.01" min="0" class="form-control" value="<?=$d['pf_fee']?>"></div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent)">💳 Payment Method Breakdown</div>
      <div class="form-row">
        <div class="form-group"><label>Cash (₱)</label><input name="cash" type="number" step="0.01" min="0" class="form-control" value="<?=$d['cash']?>" oninput="calcSalesSummary(this)"></div>
        <div class="form-group"><label>HMO (₱)</label><input name="hmo" type="number" step="0.01" min="0" class="form-control" value="<?=$d['hmo']?>" oninput="calcSalesSummary(this)"></div>
      </div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>Charge to Company (₱)</label><input name="charge_to_company" type="number" step="0.01" min="0" class="form-control" value="<?=$d['charge_to_company']?>" oninput="calcSalesSummary(this)"></div>
        <div class="form-group"><label>DR/CR (₱)</label><input name="dr_cr" type="number" step="0.01" min="0" class="form-control" value="<?=$d['dr_cr']?>" oninput="calcSalesSummary(this)"></div>
      </div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group"><label>HMO Withholding Tax (%)</label><input name="hmo_withholding_pct" type="number" step="0.01" min="0" max="100" class="form-control" value="<?=$d['hmo_withholding_pct']?>" oninput="calcSalesSummary(this)"></div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--accent)">💰 Sales Summary <span style="opacity:.6;font-weight:400">(auto-calculated)</span></div>
      <div class="form-row" style="margin-bottom:6px">
        <div class="form-group">
          <label>Sales Revenue / POS Reading (₱)</label>
          <input type="number" class="form-control sr-revenue" value="<?=$d['sales_revenue']?>" readonly tabindex="-1" style="background:var(--surf3);color:var(--subtext2);font-weight:600">
        </div>
        <div class="form-group">
          <label>Sales Discount / EW (₱)</label>
          <input type="number" class="form-control sr-discount" value="<?=$d['sales_discount']?>" readonly tabindex="-1" style="background:var(--surf3);color:var(--subtext2);font-weight:600">
        </div>
      </div>
      <div class="form-row" style="margin-bottom:18px">
        <div class="form-group">
          <label>Gross Sales (₱)</label>
          <input type="number" class="form-control sr-gross" value="<?=number_format($d['sales_revenue']-$d['sales_discount'],2,'.','')?>" readonly tabindex="-1" style="background:var(--surf3);color:var(--accent);font-weight:700">
        </div>
      </div>

      <div class="dr-section-hdr" style="--sc:var(--subtext)">⚙ Thresholds</div>
      <div class="form-row">
        <div class="form-group"><label>Quota Target (₱)</label><input name="quota_target" type="number" step="0.01" min="0" class="form-control" value="<?=$d['quota_target']?>"></div>
        <div class="form-group"><label>COGS/Payables Max (%)</label><input name="payables_threshold" type="number" step="0.1" class="form-control" value="<?=$d['payables_threshold']?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>MP Max (%)</label><input name="mp_threshold" type="number" step="0.1" class="form-control" value="<?=$d['mp_threshold']?>"></div>
      </div>
      <div class="modal-actions">
        <a href="demic_daily_report.php?month=<?=$fMonth?>&year=<?=$fYear?>" class="btn btn-ghost">Cancel</a>
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
.dr-amt { text-align: right; }
/* Dynamic rows (label + amount only, no type selector) */
.dr-dyn-container { display: flex; flex-direction: column; gap: 5px; margin-top: 5px; }
.dr-dyn-row-2 {
  display: grid; grid-template-columns: 1fr 130px 30px;
  gap: 8px; align-items: center;
}
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

// Sales Revenue = Cash + HMO + Charge to Company + DR/CR
// Sales Discount / EW = HMO * (HMO Withholding % / 100)
// Gross Sales = Sales Revenue - Sales Discount
function calcSalesSummary(fromEl) {
  const form = fromEl.closest('form');
  const gv = name => parseFloat(form.querySelector(`[name="${name}"]`)?.value) || 0;
  const cash   = gv('cash');
  const hmo    = gv('hmo');
  const charge = gv('charge_to_company');
  const drcr   = gv('dr_cr');
  const pct    = gv('hmo_withholding_pct');

  const revenue  = cash + hmo + charge + drcr;
  const discount = hmo * (pct / 100);
  const gross    = revenue - discount;

  form.querySelector('.sr-revenue').value  = revenue.toFixed(2);
  form.querySelector('.sr-discount').value = discount.toFixed(2);
  form.querySelector('.sr-gross').value    = gross.toFixed(2);
}
// Compute once on load for the Edit modal (in case values were pre-filled server-side but need re-derivation)
document.querySelectorAll('#editModal form, #addModal form').forEach(f => {
  const hmoField = f.querySelector('[name="hmo"]');
  if (hmoField) calcSalesSummary(hmoField);
});

function addPayRow(containerId) {
  const container = document.getElementById(containerId);
  const row = document.createElement('div');
  row.className = 'dr-dyn-row-2';
  row.innerHTML = `
    <input name="pay_label[]" type="text" class="form-control" placeholder="Payable name…">
    <input name="pay_amount[]" type="number" step="0.01" min="0" class="form-control dr-amt" placeholder="0.00" value="0">
    <button type="button" class="dr-remove-btn" onclick="this.closest('.dr-dyn-row-2').remove()">✕</button>
  `;
  container.appendChild(row);
  row.querySelector('input[name="pay_label[]"]').focus();
}
</script>
</body>
</html>