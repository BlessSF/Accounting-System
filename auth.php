<?php
// ============================================================
//  auth.php — Session & authentication helpers
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function currentUser(): array {
    return [
        'id'      => $_SESSION['user_id']      ?? 0,
        'name'    => $_SESSION['user_name']    ?? 'Guest',
        'role'    => $_SESSION['user_role']    ?? 'staff',
        'email'   => $_SESSION['user_email']   ?? '',
        'subrole' => $_SESSION['user_subrole'] ?? null,
    ];
}

function isAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function isBranch(): bool {
    return ($_SESSION['user_role'] ?? '') === 'branch';
}

function isManagement(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['manager', 'accounting', 'oic', 'admin']);
}

// A "cashier" is a branch login made through the Cashier icon on the
// login page. Their session still has user_role === 'branch' (so every
// existing isBranch()/currentBranch() check elsewhere keeps working
// untouched) but user_subrole === 'cashier' marks the restriction.
// Cashiers may only ever reach the Dashboard and their own branch's
// Sales Report page.
function isCashier(): bool {
    return ($_SESSION['user_subrole'] ?? '') === 'cashier';
}

function currentBranch(): ?string {
    return $_SESSION['user_branch'] ?? null;
}

// Branch → Sales Report page, keyed by the exact branch label stored
// in $_SESSION['user_branch']. Lives here (not layout.php) because
// it's an access-control concern, not a UI concern.
function cashierSalesReportMap(): array {
    return [
        'Stella'        => ['file' => 'stella_sales_report.php',        'key' => 'stella_sales_report'],
        'Dois'          => ['file' => 'dois_sales_report.php',          'key' => 'dois_sales_report'],
        'H'             => ['file' => 'h_sales_report.php',             'key' => 'h_sales_report'],
        'Pub Express'   => ['file' => 'pub_express_sales_report.php',   'key' => 'pub_express_sales_report'],
        'Commissary'    => ['file' => 'commissary_sales_report.php',    'key' => 'commissary_sales_report'],
        'Recovery'      => ['file' => 'recovery_sales_report.php',      'key' => 'recovery_sales_report'],
        'DemicLab-Main' => ['file' => 'demiclab_sales_report.php',      'key' => 'demiclab_sales_report'],
        'DemicLab-Jaro' => ['file' => 'demiclab_jaro_sales_report.php', 'key' => 'demiclab_jaro_sales_report'],
    ];
}

// Pages a Cashier is allowed to land on regardless of branch — the
// Dashboard, plus the correct Daily Report page for their branch.
// Demic branches use demic_daily_report.php (which has its own fields
// and formulas); every other branch uses the generic daily_report.php.
// Their branch Sales Report page is checked separately via
// cashierSalesReportMap() since it depends on branch.
function cashierAllowedSharedPages(): array {
    if (in_array(currentBranch(), ['DemicLab-Main', 'DemicLab-Jaro'], true)) {
        return ['dashboard.php', 'demic_daily_report.php'];
    }
    return ['dashboard.php', 'daily_report.php'];
}

// ── Real enforcement, callable BEFORE any AJAX handler runs ──────────
// requireLogin() only checks whether someone is logged in at all — it
// has no idea which page it's on, so it can't stop a Cashier session
// from POSTing straight to another page's ajax_save/ajax_delete handler
// and bypassing the sidebar/redirect restriction entirely (those AJAX
// blocks `exit;` long before layout.php's redirect ever runs). Call
// this immediately after requireLogin(), before any AJAX handling, on
// every page — it redirects (GET) or hard-stops with 403 (POST/AJAX)
// if a Cashier tries to touch anything but the Dashboard or their own
// Sales Report page.
function enforceCashierAccess(): void {
    if (!isCashier()) return;

    $map = cashierSalesReportMap();
    $csr = $map[currentBranch()] ?? null;
    $thisFile = basename($_SERVER['SCRIPT_NAME'] ?? '');

    if (in_array($thisFile, cashierAllowedSharedPages(), true)) return; // Dashboard — always fine
    if ($csr && $thisFile === $csr['file']) return; // on their own allowed page — fine

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Mid-AJAX-handler request from a disallowed page — block outright,
        // don't redirect (a redirect response is useless to a fetch() call
        // and would otherwise let the original handler's code keep running).
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Access restricted to your branch\'s Sales Report.']);
        exit;
    }

    header('Location: ' . ($csr['file'] ?? 'index.php'));
    exit;
}

// Returns a SQL WHERE clause fragment and optional param to restrict data to the
// current branch. For management accounts returns an always-true fragment with no param.
// Usage: [$clause, $params] = branchFilter('store_name');
function branchFilter(string $column = 'store_name'): array {
    if (isBranch()) {
        return ["`$column` = ?", [currentBranch()]];
    }
    return ['1=1', []];
}

function flashSet(string $key, string $msg): void {
    $_SESSION['flash'][$key] = $msg;
}

function flashGet(string $key): string {
    $msg = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $msg;
}