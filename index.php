<?php
// ============================================================
//  index.php — Login page
//  Flow: pick Manager / OIC / Cashier  →  (OIC/Cashier only)
//        pick a branch  →  enter that branch's password
// ============================================================
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in? Go to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php'); exit;
}

$error = '';

// ── Manager password (management, full access, no branch) ──
$MANAGER_PASSWORD = 'manager123';

// ── Branch passwords — shared by both the OIC and Cashier
//    entry points. Which icon was clicked decides the access
//    level (subrole), not the password. ─────────────────────
$BRANCHES = [
    'stella'        => ['label' => 'Stella',        'password' => 'stella123'],
    'dois'          => ['label' => 'Dois',          'password' => 'dois123'],
    'h'             => ['label' => 'H',             'password' => 'h123'],
    'pub_express'   => ['label' => 'Pub Express',   'password' => 'pubexpress123'],
    'commissary'    => ['label' => 'Commissary',    'password' => 'commissary123'],
    'recovery'      => ['label' => 'Recovery',      'password' => 'recovery123'],
    'demiclab'      => ['label' => 'DemicLab-Main', 'password' => 'demic123'],
    'demiclab_jaro' => ['label' => 'DemicLab-Jaro', 'password' => 'demicjaro123'],
];

$mode      = $_POST['mode'] ?? '';   // 'manager' | 'oic' | 'cashier'
$branchKey = $_POST['branch'] ?? '';
$step      = 'icons';                 // which panel to (re)open on reload

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_login'])) {
    $password = $_POST['password'] ?? '';

    if ($mode === 'manager') {
        $step = 'password';
        if ($password === '') {
            $error = 'Please enter the manager password.';
        } elseif ($password !== $MANAGER_PASSWORD) {
            $error = 'Incorrect manager password.';
        } else {
            $_SESSION['user_id']      = 'manager';
            $_SESSION['user_name']    = 'Manager';
            $_SESSION['user_role']    = 'manager';
            $_SESSION['user_email']   = 'manager@multiplierscorp.com';
            $_SESSION['user_branch']  = null;
            $_SESSION['user_subrole'] = null;
            header('Location: dashboard.php'); exit;
        }
    } elseif ($mode === 'oic' || $mode === 'cashier') {
        if (!isset($BRANCHES[$branchKey])) {
            $error = 'Please select a branch.';
            $step  = 'branch';
        } elseif ($password === '') {
            $error = 'Please enter the branch password.';
            $step  = 'password';
        } elseif ($password !== $BRANCHES[$branchKey]['password']) {
            $error = 'Incorrect password for this branch.';
            $step  = 'password';
        } else {
            $b = $BRANCHES[$branchKey];
            $_SESSION['user_id']      = $branchKey . '_' . $mode;
            $_SESSION['user_name']    = $b['label'];
            $_SESSION['user_role']    = 'branch';   // same role model the rest of the app already expects
            $_SESSION['user_email']   = $branchKey . '@multiplierscorp.com';
            $_SESSION['user_branch']  = $b['label'];
            $_SESSION['user_subrole'] = $mode;       // 'oic' = full branch access, 'cashier' = Sales Report only
            header('Location: dashboard.php'); exit;
        }
    } else {
        $error = 'Please choose Manager, Accounting, or OIC/Cashier.';
        $step  = 'icons';
    }
} elseif ($mode === 'oic' || $mode === 'cashier') {
    $step = $branchKey !== '' ? 'password' : 'branch';
} elseif ($mode === 'manager') {
    $step = 'password';
}

$branchLabel = $BRANCHES[$branchKey]['label'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — SalesHub</title>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:      #f4f5f7;
  --surface: #ffffff;
  --surf2:   #f8f9fb;
  --border:  #e3e6ea;
  --border2: #c8cdd5;
  --accent:  #0f7b5c;
  --accent2: #dc3545;
  --text:    #1a1d23;
  --subtext: #6b7280;
  --subtext2:#4b5563;
  --font-h:  'Geist', sans-serif;
  --font-m:  'Geist Mono', monospace;
  --font-s:  'Instrument Serif', serif;
}

body {
  font-family: var(--font-h);
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  -webkit-font-smoothing: antialiased;
}

.login-wrap {
  position: relative; z-index: 10;
  width: 100%; max-width: 500px;
  padding: 20px;
  animation: slideUp .5s cubic-bezier(.22,1,.36,1) both;
}
@keyframes slideUp {
  from { opacity:0; transform:translateY(20px); }
  to   { opacity:1; transform:translateY(0); }
}

.login-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 48px 44px 44px;
  box-shadow: 0 4px 24px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.05);
}

.brand {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 38px;
}
.brand-icon {
  width: 42px; height: 42px; border-radius: 11px;
  background: linear-gradient(145deg, #0f7b5c 0%, #0a5c44 100%);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; color: #fff; font-size: 1rem;
  box-shadow: 0 2px 10px rgba(15,123,92,.3);
}
.brand-name { font-size: 1.08rem; font-weight: 600; letter-spacing: -.02em; color: var(--text); }
.brand-tag {
  font-family: var(--font-m); font-size: .66rem;
  color: var(--subtext); text-transform: uppercase; letter-spacing: .08em;
  margin-top: 2px;
}

.login-eyebrow {
  font-family: var(--font-m); font-size: .74rem; color: var(--accent);
  text-transform: uppercase; letter-spacing: .1em; margin-bottom: 10px;
}
.login-title {
  font-family: var(--font-s);
  font-size: 2.15rem; font-weight: 400; line-height: 1.2;
  letter-spacing: -.01em; margin-bottom: 8px;
  color: var(--text);
}
.login-title em { color: var(--accent); font-style: italic; }
.login-sub {
  font-size: .92rem; color: var(--subtext2); margin-bottom: 30px;
  line-height: 1.5;
}

.field { margin-bottom: 18px; }
label {
  display: block;
  font-size: .72rem; font-weight: 500; text-transform: uppercase;
  letter-spacing: .08em; color: var(--subtext);
  font-family: var(--font-m); margin-bottom: 8px;
}
input[type=password] {
  width: 100%; padding: 13px 16px;
  background: #fff; border: 1px solid var(--border);
  border-radius: 10px; color: var(--text);
  font-family: var(--font-m); font-size: .92rem;
  transition: border-color .15s, box-shadow .15s;
  outline: none;
  -webkit-font-smoothing: antialiased;
}
input[type=password]::placeholder { color: var(--subtext); opacity: .5; }
input:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(15,123,92,.1);
  background: #fff;
}

.btn-login {
  width: 100%; padding: 14px;
  background: var(--accent); color: #fff;
  border: none; border-radius: 10px;
  font-family: var(--font-h); font-size: .92rem; font-weight: 600;
  cursor: pointer; margin-top: 10px;
  transition: background .15s, transform .15s, box-shadow .15s;
  letter-spacing: -.01em;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-login:hover {
  background: #0a6649;
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(15,123,92,.28);
}
.btn-login:active { transform: translateY(0); }

.error-box {
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 9px; padding: 12px 15px;
  font-family: var(--font-m); font-size: .82rem;
  color: #be123c; margin-bottom: 20px;
  display: flex; align-items: center; gap: 8px;
}

/* ── Step panels ── */
.step-panel { display: none; }
.step-panel.active { display: block; animation: fadeIn .25s ease both; }
@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }

/* ── Icon choice (Manager / OIC / Cashier) ── */
.role-icons {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
}
.role-icon-btn {
  display: flex; flex-direction: column; align-items: center; gap: 12px;
  padding: 26px 12px;
  background: var(--surf2); border: 1px solid var(--border);
  border-radius: 14px; cursor: pointer;
  font-family: var(--font-h);
  transition: border-color .15s, background .15s, transform .15s;
}
.role-icon-btn:hover {
  border-color: var(--accent); background: #f0fdf4;
  transform: translateY(-2px);
}
.role-icon-glyph {
  width: 52px; height: 52px; border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.5rem;
  background: linear-gradient(145deg, #0f7b5c 0%, #0a5c44 100%);
  color: #fff;
}
.role-icon-btn:nth-child(2) .role-icon-glyph { background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%); }
.role-icon-btn:nth-child(3) .role-icon-glyph { background: linear-gradient(145deg, #d97706 0%, #b45309 100%); }
.role-icon-label { font-size: .92rem; font-weight: 600; color: var(--text); }
.role-icon-sub { font-size: .7rem; color: var(--subtext); font-family: var(--font-m); text-align: center; }

/* ── Branch grid ── */
.branch-grid {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
  margin-bottom: 6px;
}
.branch-btn {
  padding: 16px 12px;
  background: var(--surf2); border: 1px solid var(--border);
  border-radius: 11px; cursor: pointer;
  font-family: var(--font-h); font-size: .86rem; font-weight: 600;
  color: var(--text); text-align: center;
  transition: border-color .15s, background .15s;
}
.branch-btn:hover { border-color: var(--accent); background: #f0fdf4; }

.back-link {
  display: inline-flex; align-items: center; gap: 5px;
  font-family: var(--font-m); font-size: .74rem; color: var(--subtext);
  text-transform: uppercase; letter-spacing: .06em;
  cursor: pointer; margin-bottom: 18px; background: none; border: none;
}
.back-link:hover { color: var(--accent); }

.context-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: #f0fdf4; border: 1px solid #bbf7d0;
  border-radius: 20px; padding: 6px 14px;
  font-family: var(--font-m); font-size: .74rem; color: var(--accent);
  margin-bottom: 20px; font-weight: 600;
}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">

    <div class="brand">
      <div class="brand-icon">M</div>
      <div>
        <div class="brand-name">Multipliers Corp</div>
        <div class="brand-tag">Meritoni</div>
      </div>
    </div>

    <div class="login-eyebrow">Secure Access</div>
    <h1 class="login-title">Welcome <em>back</em></h1>
    <p class="login-sub">Choose how you're signing in</p>

    <?php if ($error): ?>
    <div class="error-box">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="index.php" id="loginForm">
      <input type="hidden" name="mode"   id="modeInput"   value="<?= htmlspecialchars($mode) ?>">
      <input type="hidden" name="branch" id="branchInput" value="<?= htmlspecialchars($branchKey) ?>">

      <!-- STEP 1: Manager / OIC / Cashier -->
      <div class="step-panel" id="panel-icons">
        <div class="role-icons">
          <div class="role-icon-btn" onclick="chooseMode('manager')">
            <div class="role-icon-glyph">👔</div>
            <div class="role-icon-label">Manager</div>
            <div class="role-icon-sub">Full access</div>
          </div>
          <div class="role-icon-btn" onclick="chooseMode('oic')">
            <div class="role-icon-glyph">🗝️</div>
            <div class="role-icon-label">Accounting</div>
            <div class="role-icon-sub">Pick a branch</div>
          </div>
          <div class="role-icon-btn" onclick="chooseMode('cashier')">
            <div class="role-icon-glyph">🧾</div>
            <div class="role-icon-label">OIC/Cashier</div>
            <div class="role-icon-sub">Dashboard &amp; Sales Report</div>
          </div>
        </div>
      </div>

      <!-- STEP 2 (OIC / Cashier only): pick a branch -->
      <div class="step-panel" id="panel-branch">
        <button type="button" class="back-link" onclick="goToIcons()">← Back</button>
        <div class="branch-grid">
          <?php foreach ($BRANCHES as $key => $b): ?>
          <div class="branch-btn" onclick="chooseBranch('<?= $key ?>','<?= htmlspecialchars($b['label'], ENT_QUOTES) ?>')">
            <?= htmlspecialchars($b['label']) ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- STEP 3: password -->
      <div class="step-panel" id="panel-password">
        <button type="button" class="back-link" id="passwordBackBtn" onclick="goBackFromPassword()">← Back</button>
        <div class="context-badge" id="contextBadge">Manager</div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password"
                 placeholder="••••••••"
                 autocomplete="current-password">
        </div>
        <button type="submit" name="submit_login" value="1" class="btn-login">Sign In →</button>
      </div>
    </form>

  </div>
</div>

<script>
function showPanel(id) {
  document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
  document.getElementById(id).classList.add('active');
}

function chooseMode(mode) {
  document.getElementById('modeInput').value = mode;
  document.getElementById('branchInput').value = '';
  if (mode === 'manager') {
    document.getElementById('contextBadge').textContent = 'Manager';
    document.getElementById('passwordBackBtn').onclick = goToIcons;
    showPanel('panel-password');
    document.getElementById('password').focus();
  } else {
    showPanel('panel-branch');
  }
}

function chooseBranch(key, label) {
  document.getElementById('branchInput').value = key;
  const mode = document.getElementById('modeInput').value;
  const modeLabel = mode === 'oic' ? 'Accounting' : 'OIC/Cashier';
  document.getElementById('contextBadge').textContent = label + ' — ' + modeLabel;
  document.getElementById('passwordBackBtn').onclick = goToBranch;
  showPanel('panel-password');
  document.getElementById('password').focus();
}

function goToIcons() {
  document.getElementById('modeInput').value = '';
  document.getElementById('branchInput').value = '';
  showPanel('panel-icons');
}

function goToBranch() { showPanel('panel-branch'); }

function goBackFromPassword() {
  const mode = document.getElementById('modeInput').value;
  if (mode === 'manager') goToIcons();
  else goToBranch();
}

// Restore state after a failed login attempt (server round-trip)
(function initState() {
  const step   = <?= json_encode($step) ?>;
  const mode   = <?= json_encode($mode) ?>;
  const branch = <?= json_encode($branchKey) ?>;
  const branchLabel = <?= json_encode($branchLabel) ?>;

  if (step === 'password') {
    if (mode === 'manager') {
      document.getElementById('contextBadge').textContent = 'Manager';
      document.getElementById('passwordBackBtn').onclick = goToIcons;
    } else {
      const modeLabel = mode === 'oic' ? 'Accounting' : 'OIC/Cashier';
      document.getElementById('contextBadge').textContent = branchLabel + ' — ' + modeLabel;
      document.getElementById('passwordBackBtn').onclick = goToBranch;
    }
    showPanel('panel-password');
  } else if (step === 'branch') {
    showPanel('panel-branch');
  } else {
    showPanel('panel-icons');
  }
})();
</script>
</body>
</html>