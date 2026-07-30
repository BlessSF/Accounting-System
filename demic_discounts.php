<?php
// ============================================================
//  demic_discounts.php — Demic Lab Discounts Log
//  DATE / TEST / DISC / TXN / PRICE / AMOUNT
//  AMOUNT = PRICE * TXN * (DISC% / 100)  →  peso value of the discount given
// ============================================================
require_once 'config.php';
require_once 'auth.php';
requireLogin();

if (isBranch() && !in_array(currentBranch(), ['DemicLab-Main','DemicLab-Jaro'], true)) {
    header('Location: dashboard.php'); exit;
}

$pdo  = getPDO();
$user = currentUser();

// ── Create table ─────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `demic_discounts` (
    `id`          int(11) NOT NULL AUTO_INCREMENT,
    `entry_date`  date NOT NULL,
    `store_name`  varchar(50) NOT NULL DEFAULT 'Demic Lab',
    `test_name`   varchar(200) NOT NULL DEFAULT '',
    `disc_pct`    decimal(5,2) NOT NULL DEFAULT 0.00,
    `txn`         int(11) NOT NULL DEFAULT 0,
    `price`       decimal(12,2) NOT NULL DEFAULT 0.00,
    `amount`      decimal(12,2) NOT NULL DEFAULT 0.00,
    `sort_order`  int(4) NOT NULL DEFAULT 0,
    `saved_by`    varchar(100) DEFAULT NULL,
    `updated_at`  timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── Master test list ────────────────────────────────────────
$TEST_LIST = [
'CBC ONLY','CBC W/ PLATELET COUNT','CBC PLATELET W/ SAVE SLIDE','ESR',
'BLOOD TYPING (ABO & RH TYPING)','PROTIME (INR)','APTT','CTBT','CLOTTING TIME','BLEEDING TIME',
'CRP (QUALI)','CRP (QUANTI)','CA-1-99','PERIPHERAL BLOOD SMEAR','RHEUMATOID FACTOR',
'URINALYSIS 4-PARAMETER','URINALYSIS 11-PARAMETER','FECALYSIS','FECLYSIS (CONCENTRATION)',
'ANTIGEN H-PYLORI','FOBT (FECAL OCCULT BLOOD TEST)','FBS (FASTING BLOOD SUGAR)',
'RBS (RANDOM BLOOD SUGAR)','OGTT w/ GLUCOSE LOAD','HPBG','HbA1C','LIPID PROFILE',
'CHOLESTEROL ONLY','TRIGLYCERIDES ONLY','HDL ONLY','CREATININE','CRETININE WITH EGFR',
'BLOOD URIC ACID (BUA)','BUN (BLOOD UREA NITROGEN)','SGPT','SGOT','SODIUM','POTASSIUM',
'TOTAL CALCIUM','IONIZED CALCIUM','CHLORIDE','MAGNESIUM','PHOSPHORUS',
'ALP (ALKALINE PHOSPHATASE)','AMYLASE','PSA (TOTAL)','PSA (FREE)','GGT','RERRITIN TEST',
'CEA (CARCINOEMBRYONIC Ag)','AFP (ALPHA- FETOPROTEIN)','TPAG (TOTAL PROTEIN, ALBUMIN/GLOBULIN)',
'BILIRUBIN TOTAL','TOTAL PROTEIN','ALBUMIN','TSH TEST','T3 TEST','T4 TEST','FT3 TEST','FT4 TEST',
'DENGUE NS1 Ag ONLY','DENGUE IgM/IgG','Anti HAV IgG/IgM (QUALITATIVE) Hepa A','HBsAg (hepa B)',
'HBsAg (hepa B) QUANTITATIVE','HEPATITIS B CORE IGM','ANTI HBC TOTAL','Anti HBs','ANTI HVC',
'ANTI HBs QUANTITATIVE','ANTI HBs QUALITATIVE','H. PYLORI TEST (QUALITATIVE)','GRAM STAIN',
'VIT. D3 ASSAY','PREGNANCY TEST (hCg)','SYPHILIS TEST (ANTI-TP)','SERUM PROLACTIN',
'SERUM CORTISOL','RUBELLA IGG','ASO TITER (ANTISTREPTOLYSIN 0)','SALMONELLA IgG/IgM (TYPHIDOT)',
'INTACT PTH','DRUG TEST','CHEST PA/AP','CHEST PA/AP (PEDIA)','CHEST LATERAL R/L -ADULT',
'CHEST PAL/APL RANDL PEDIA','CHEST PAL/APL (ADULT)','CHEST PAL/APL (PEDIA)',
'CALCANEUS LATERAL R/L','CHEST BUCKY APL R/L','CHEST OBLIQUE','APICOLORDOTIC VIEW',
'LUMBOSACRAL APL R/L','LUMBOSACRAL AP/PA','THORACOLUMBAR APL R/L',
'THORACOLUMBOSACRAL SPINE APL UPRIGHT','CERVICOTHORASIC APL R/L','THORACIC APL R/L',
'HIP/PELVIS AP','WRIST APL R&L','FEMUR','SHOULDER APL R/L','HAND APO R/L','HAND APL R/L',
'FINGER AND THUMB APL R/L','HIP/PELVIS APO','ELBOW APL RAND L','CERVICAL OBLIQUE R/L',
'ANKLE APL R/L','ANKLE AP R/L','FOOT APO R/L','FOOT OBLIQUE R/L','KNEE APL R/L',
'KNEE APL R & L','KNEE AP RAND L','LEG APL R/L','FEMUR/THIGH APL R/L','NASAL DONE SOFT TISSUE',
'PARANASAL SINUSES (CALDWELLS,Lateral)','PARANASAL SINUS WATER VIEWS',
'PARANASAL SINUS (WATER,CALDWELLS,LATERAL)','ECG WITH READING','ECG WITH OUT READING',
'2D ECHO HMO','2D ECHO','WHOLE ABD UTZ','WHOLE ABD HMO','UPPER ABDOMEN','LOWER ABDOMEN',
'HBT UTZ','KUB UTZ','LIVER UTZ','KUBP UTZ','UTZ PELVIS TRANSABDOMINAL','UTZ FETAL EVALUATION',
'BREAST ULTRASOUND','THYROID ULTRASOUND','SPECIMEN CUP','X-RAY FILM (PHOTOPAPER)',
'CONSULTATION FEE','INCIDENTAL FINGS','COMPANY CONSULTATION','CONSULTATION FEE HMO',
'FF UP W/ CERTIFICATE','MEDICAL CERT/FIT TO WORK','HOME SERVICE MABUHAII MANDURRIAO',
'HOME SERVICE FEE','HOME SERVICE FEE (OUT OF TOWN)','HEALTH CARD PACKAGE','HEALTH CARD W/ DT',
'HOME SERVICE WITH CONSULTATION','COMPANY HEALTH CARD PACKAGE (hmo)','APE PACKAGE (BASIC 5)',
"SEATTLE'S BEST PACKAGE",'EXECUTIVE PACKAGE','EXECUTIVE PACKAGE 1','BASIC CHEM 1',
'BASIC CHEM 2 PACKAGE','BASIC 5 PACKEGE + HEPA SCREENING','BASIC 5 PACKAGE + DRUG TEST',
'BASIC 5 PACKAGE + DRUG TEST + PT','BASIC 5 + PREGNANCY TEST','CITI HARDWARE PACKAGE FEMALE',
'CITI HARDWARE PACKAGE MALE','SOLID GOLD FOOD INC./ MCDONALDS A',
'SOLID GOLD FOOD INC./ MCDONALDS B','ANDOKS PACKAGE A','ANDOKS PACKAGE B','CEBU GENERAL',
'BLUE COLLAR','BLUE COLLAR B','BLUE COLLAR HEALTHCARD','BFP','GO GROUP','GO GROUP MANAGERIAL',
'MANAGERIAL MALE','XTEND OPS MALE','XTEND OPS FEMALE','SAN AGUSTIN FACULTY',
'SAN AGUSTIN PACKAGE A','SAN AGUSTIN PACKAGE B','WVSU- LAW','SCHIERMAN MANPOWER',
'Dep Ed Package','ZURI HOTEL','LIFE LINE','CNT AGENCY','XOOM MOBILE','J&T ILOILO',
'J & T ILOILO','DAVAO MANPOWER PACKAGE 4','STAR BOARD PACKAGE A','STAR BOARD PACKAGE B',
'S&R ILOILO','STAR BOARD PACKAGE','La Filipina (Uy Gongco)','MANDAUE FOAM ILOILO',
'PARAMOUNT HUMAN RESOURCES','COMMONWEALTH APE','COMMONWEALTH PAPSMEAR','PAPSMEAR',
'JOLLY MANAGEMENT SOLUTION INC.','WALK IN',"SAM'S 21/ DISTRICT 21 HOLTE",'ICK MANPOWER SRE',
'TESDA PACKAGE','RONIN','MCR','KTKM',"BAGUIO INT'L AGENCY",'RE PRINT LAB RESULT',
'RE PRINT X-RAY RESULT','CHARGE TO COMPANY','COVID RAPID Ag TEST',
];

$DISC_OPTIONS = [20, 15, 10, 5];

// ── AJAX: Save row ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'save_row') {
    header('Content-Type: application/json');
    try {
        $id         = (int)($_POST['id'] ?? 0);
        $store_name = isBranch() ? currentBranch() : trim($_POST['store_name'] ?? 'Demic Lab');
        $entryDate  = $_POST['entry_date'] ?? date('Y-m-d');
        $testName   = trim($_POST['test_name'] ?? '');
        $discPct    = (float)($_POST['disc_pct'] ?? 0);
        $txn        = (int)($_POST['txn']   ?? 0);
        $price      = (float)($_POST['price'] ?? 0);
        $so         = (int)($_POST['sort_order'] ?? 0);
        $amount     = round($price * $txn * ($discPct / 100), 2);

        if ($id > 0) {
            $pdo->prepare("UPDATE demic_discounts SET entry_date=?,store_name=?,test_name=?,disc_pct=?,txn=?,price=?,amount=?,sort_order=?,saved_by=? WHERE id=?")
                ->execute([$entryDate,$store_name,$testName,$discPct,$txn,$price,$amount,$so,$user['name'],$id]);
        } else {
            $pdo->prepare("INSERT INTO demic_discounts (entry_date,store_name,test_name,disc_pct,txn,price,amount,sort_order,saved_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$entryDate,$store_name,$testName,$discPct,$txn,$price,$amount,$so,$user['name']]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id,'amount'=>$amount]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// ── AJAX: Delete row ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'delete_row') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM demic_discounts WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Filters ───────────────────────────────────────────────
$months = ['January','February','March','April','May','June',
           'July','August','September','October','November','December'];
$fYear  = (int)($_GET['year']  ?? date('Y'));
$fMonth = (int)($_GET['month'] ?? date('n'));
$fMonth = max(1, min(12, $fMonth));

[$bClause, $bParams] = branchFilter('store_name');
$stmt = $pdo->prepare("SELECT * FROM demic_discounts WHERE YEAR(entry_date)=? AND MONTH(entry_date)=? AND $bClause ORDER BY entry_date,sort_order,id");
$stmt->execute(array_merge([$fYear, $fMonth], $bParams));
$rows = $stmt->fetchAll();

$totalAmount = 0; $totalTxn = 0;
foreach ($rows as $r) { $totalAmount += (float)$r['amount']; $totalTxn += (int)$r['txn']; }

// ── CSV export ────────────────────────────────────────────
if (($_GET['export_csv'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="demic_discounts_' . $fYear . '-' . str_pad($fMonth,2,'0',STR_PAD_LEFT) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Test','Discount %','TXN','Price','Amount']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['entry_date'], $r['test_name'], $r['disc_pct'], $r['txn'], $r['price'], $r['amount']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', $totalTxn, '', $totalAmount]);
    fclose($out);
    exit;
}

$pageTitle  = 'Demic Discounts';
$activePage = 'demic_discounts';
include 'layout.php';
?>

<div class="section-header">
  <div>
    <div class="section-title">Demic <span>Discounts</span></div>
    <div class="section-subtitle">Discount log — <?=$months[$fMonth-1]?> <?=$fYear?></div>
  </div>
</div>

<!-- Month / Year tabs -->
<div class="card" style="padding:12px 18px;margin-bottom:18px">
  <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
    <?php for($m=1;$m<=12;$m++): ?>
    <a href="?month=<?=$m?>&year=<?=$fYear?>"
       style="padding:5px 13px;border-radius:6px;font-size:.74rem;font-weight:600;text-decoration:none;transition:all .15s;
              <?=$fMonth===$m?'background:var(--accent);color:#031a12;':'color:var(--subtext2);'?>">
      <?=substr($months[$m-1],0,3)?>
    </a>
    <?php endfor; ?>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <a href="?month=<?=$fMonth?>&year=<?=$fYear?>&export_csv=1" class="btn-csv">⬇ Download CSV</a>
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

<!-- Summary -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:18px">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr">
    <div style="padding:13px 16px;border-right:1px solid var(--border)">
      <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.08em;color:var(--subtext);margin-bottom:4px">Entries</div>
      <div style="font-size:1rem;font-weight:700"><?=count($rows)?></div>
    </div>
    <div style="padding:13px 16px;border-right:1px solid var(--border)">
      <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.08em;color:var(--subtext);margin-bottom:4px">Total TXN</div>
      <div style="font-size:1rem;font-weight:700"><?=$totalTxn?></div>
    </div>
    <div style="padding:13px 16px">
      <div style="font-family:var(--font-m);font-size:.58rem;text-transform:uppercase;letter-spacing:.08em;color:var(--subtext);margin-bottom:4px">Total Discount Amount</div>
      <div style="font-size:1rem;font-weight:700;color:var(--accent2)">₱<?=number_format($totalAmount,2)?></div>
    </div>
  </div>
</div>

<!-- Table -->
<div class="card" style="padding:0">
  <div style="overflow-x:auto">
  <table class="inv-tbl" id="discTbl">
    <thead>
      <tr>
        <th style="min-width:130px">DATE</th>
        <th style="min-width:260px;text-align:left">TEST</th>
        <th style="min-width:90px">DISC</th>
        <th style="min-width:70px">TXN</th>
        <th style="min-width:100px">PRICE</th>
        <th style="min-width:110px">AMOUNT</th>
        <th style="min-width:110px">ACTION</th>
      </tr>
    </thead>
    <tbody id="discTbody">
      <?php foreach ($rows as $r): ?>
      <tr data-id="<?=$r['id']?>">
        <td><input class="ci" type="date" value="<?=$r['entry_date']?>" oninput="rowChanged(this)"></td>
        <td class="item-td">
          <select class="ci test-sel" oninput="rowChanged(this)">
            <option value="">— Select test —</option>
            <?php foreach ($TEST_LIST as $t): ?>
            <option value="<?=htmlspecialchars($t)?>" <?=($r['test_name']===$t)?'selected':''?>><?=htmlspecialchars($t)?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <select class="ci disc-sel" oninput="calcRow(this)">
            <option value="0" <?=((float)$r['disc_pct']===0.0)?'selected':''?>>—</option>
            <?php foreach ($DISC_OPTIONS as $d): ?>
            <option value="<?=$d?>" <?=((float)$r['disc_pct']===(float)$d)?'selected':''?>><?=$d?>%</option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input class="ci" type="number" step="1" min="0" value="<?=$r['txn']?>" placeholder="0" oninput="calcRow(this)" onfocus="this.select()"></td>
        <td><input class="ci" type="number" step="0.01" min="0" value="<?=$r['price']?>" placeholder="0.00" oninput="calcRow(this)" onfocus="this.select()"></td>
        <td><input class="ci calc" type="number" value="<?=$r['amount']?>" readonly tabindex="-1"></td>
        <td class="act-td">
          <button class="btn-sv" onclick="saveRow(this)">Save</button>
          <button class="btn-dl" onclick="deleteRow(this)">✕</button>
          <div class="row-st"></div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <button class="btn-add" onclick="addRow()">+ Add Row</button>
</div>

<style>
.inv-tbl { width:100%; border-collapse:collapse; font-family:var(--font-m); font-size:.78rem; }
.inv-tbl thead th {
  background:var(--surf2); color:var(--subtext); text-transform:uppercase;
  font-size:.62rem; letter-spacing:.06em; padding:9px 8px; border-bottom:1px solid var(--border);
  text-align:center; white-space:nowrap;
}
.inv-tbl tbody td { padding:6px 8px; border-bottom:1px solid var(--border); text-align:center; }
.inv-tbl .item-td { text-align:left; }
.inv-tbl .ci {
  width:100%; padding:6px 7px; border:1px solid var(--border); border-radius:6px;
  background:var(--surface); color:var(--text); font-family:var(--font-m); font-size:.76rem;
  text-align:center;
}
.inv-tbl .item-td .ci { text-align:left; }
.inv-tbl .ci.calc { background:var(--surf3); color:var(--subtext2); font-weight:600; }
.inv-tbl .act-td { white-space:nowrap; }
.btn-sv, .btn-dl {
  font-family:var(--font-m); font-size:.68rem; padding:5px 9px; border-radius:6px;
  border:1px solid var(--border); cursor:pointer; margin-right:4px;
}
.btn-sv { background:var(--accent); color:#031a12; border-color:var(--accent); font-weight:600; }
.btn-dl { background:rgba(248,113,113,.08); color:var(--accent2); border-color:rgba(248,113,113,.2); }
.row-st { display:none; font-size:.68rem; color:var(--accent); margin-top:3px; }
.btn-add {
  margin:12px 16px 16px; padding:9px 14px; width:calc(100% - 32px);
  background:rgba(34,211,165,.05); border:1px dashed rgba(34,211,165,.25);
  color:var(--accent); border-radius:8px; cursor:pointer;
  font-family:var(--font-m); font-size:.74rem; letter-spacing:.02em;
}
.btn-add:hover { background:rgba(34,211,165,.1); }

/* ── CSV export button ── */
.btn-csv {
  font-family:var(--font-m); font-size:.74rem; font-weight:600; padding:6px 13px;
  border-radius:6px; cursor:pointer; text-decoration:none; white-space:nowrap;
  background:var(--surf2); color:var(--subtext2); border:1px solid var(--border);
}
.btn-csv:hover { background:var(--surf3); }
</style>

<script>
const gv = el => parseFloat(el?.value) || 0;

function calcRow(inp) {
  const tr = inp.closest('tr');
  const ci = tr.querySelectorAll('.ci');
  // ci[0]=date ci[1]=test ci[2]=disc ci[3]=txn ci[4]=price ci[5]=amount(calc)
  const disc  = gv(ci[2]);
  const txn   = gv(ci[3]);
  const price = gv(ci[4]);
  const amount = price * txn * (disc / 100);
  ci[5].value = amount.toFixed(2);
}
function rowChanged(inp) { /* date/test edits don't need recalculation */ }

function addRow() {
  const tbody = document.getElementById('discTbody');
  const tr = document.createElement('tr');
  tr.dataset.id = '0';
  tr.innerHTML = `
    <td><input class="ci" type="date" value="<?=date('Y-m-d')?>" oninput="rowChanged(this)"></td>
    <td class="item-td">
      <select class="ci test-sel" oninput="rowChanged(this)">
        <option value="">— Select test —</option>
        <?php foreach ($TEST_LIST as $t): ?>
        <option value="<?=htmlspecialchars($t)?>"><?=htmlspecialchars($t)?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td>
      <select class="ci disc-sel" oninput="calcRow(this)">
        <option value="0" selected>—</option>
        <?php foreach ($DISC_OPTIONS as $d): ?>
        <option value="<?=$d?>"><?=$d?>%</option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input class="ci" type="number" step="1" min="0" placeholder="0" oninput="calcRow(this)" onfocus="this.select()"></td>
    <td><input class="ci" type="number" step="0.01" min="0" placeholder="0.00" oninput="calcRow(this)" onfocus="this.select()"></td>
    <td><input class="ci calc" type="number" value="0.00" readonly tabindex="-1"></td>
    <td class="act-td">
      <button class="btn-sv" onclick="saveRow(this)">Save</button>
      <button class="btn-dl" onclick="deleteRow(this)">✕</button>
      <div class="row-st"></div>
    </td>`;
  tbody.appendChild(tr);
  tr.querySelector('.test-sel').focus();
}

async function saveRow(btn) {
  const tr = btn.closest('tr');
  const st = tr.querySelector('.row-st');
  const ci = tr.querySelectorAll('.ci');
  const fd = new FormData();
  fd.append('ajax_action','save_row');
  fd.append('id', tr.dataset.id||0);
  fd.append('entry_date', ci[0].value);
  fd.append('test_name',  ci[1].value);
  fd.append('disc_pct',   gv(ci[2]));
  fd.append('txn',        gv(ci[3]));
  fd.append('price',      gv(ci[4]));
  fd.append('sort_order', Array.from(tr.parentElement.children).indexOf(tr));
  btn.textContent='…'; btn.disabled=true;
  try {
    const r = await fetch('demic_discounts.php',{method:'POST',body:fd});
    const d = await r.json();
    if (d.ok) {
      tr.dataset.id = d.id;
      ci[5].value = Number(d.amount).toFixed(2);
      st.textContent='✓'; st.style.display='block';
      setTimeout(()=>st.style.display='none',2000);
    } else { alert('Error: '+d.msg); }
  } catch(e){ alert('Network error'); }
  btn.textContent='Save'; btn.disabled=false;
}

async function deleteRow(btn) {
  const tr = btn.closest('tr');
  const id = parseInt(tr.dataset.id)||0;
  if (id>0 && !confirm('Delete this entry?')) return;
  if (id>0) {
    const fd=new FormData(); fd.append('ajax_action','delete_row'); fd.append('id',id);
    await fetch('demic_discounts.php',{method:'POST',body:fd});
  }
  tr.remove();
}
</script>

  </div></div>
</body>
</html>