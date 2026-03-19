<?php
/**
 * BIR Reports — Main dashboard
 * Form 2316, 1604-C Alphalist, Monthly Remittances
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
require_module_access('reports', 'bir_reports', 'read');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);
$currentYear = (int)date('Y');
$filterYear = (int)($_GET['year'] ?? $currentYear);
$tab = $_GET['tab'] ?? '2316';

// ---- Stats ----
$statPayslips = 0;
$statEmployees = 0;
$statTotalTax = 0;
$statReportsGenerated = 0;

try {
    // Total released/locked payslips for the year
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) AS cnt, COUNT(DISTINCT p.employee_id) AS emp_cnt,
               COALESCE(SUM(pi.amount), 0) AS total_tax
        FROM payslips p
        LEFT JOIN payslip_items pi ON pi.payslip_id = p.id AND pi.code = 'TAX'
        WHERE p.status IN ('locked','released')
          AND EXTRACT(YEAR FROM p.period_start) = :yr
    ");
    $stmt->execute([':yr' => $filterYear]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $statPayslips = (int)($row['cnt'] ?? 0);
    $statEmployees = (int)($row['emp_cnt'] ?? 0);
    $statTotalTax = (float)($row['total_tax'] ?? 0);

    // Reports generated count
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM bir_report_logs WHERE EXTRACT(YEAR FROM created_at) = :yr");
    $stmt2->execute([':yr' => $filterYear]);
    $statReportsGenerated = (int)$stmt2->fetchColumn();
} catch (Throwable $e) {
    sys_log('BIR-STATS', 'BIR stats query failed: ' . $e->getMessage(), ['module' => 'bir-reports']);
}

// ---- Monthly breakdown for remittances tab ----
$monthlyData = [];
if ($tab === 'remittance') {
    try {
        $stmt = $pdo->prepare("
            SELECT EXTRACT(MONTH FROM p.period_start)::int AS mo,
                   COALESCE(SUM(CASE WHEN pi.code = 'SSS_EE' THEN pi.amount ELSE 0 END), 0) AS sss_ee,
                   COALESCE(SUM(CASE WHEN pi.code = 'SSS_ER' THEN pi.amount ELSE 0 END), 0) AS sss_er,
                   COALESCE(SUM(CASE WHEN pi.code = 'PHIC_EE' THEN pi.amount ELSE 0 END), 0) AS phic_ee,
                   COALESCE(SUM(CASE WHEN pi.code = 'PHIC_ER' THEN pi.amount ELSE 0 END), 0) AS phic_er,
                   COALESCE(SUM(CASE WHEN pi.code = 'HDMF_EE' THEN pi.amount ELSE 0 END), 0) AS hdmf_ee,
                   COALESCE(SUM(CASE WHEN pi.code = 'HDMF_ER' THEN pi.amount ELSE 0 END), 0) AS hdmf_er,
                   COALESCE(SUM(CASE WHEN pi.code = 'TAX' THEN pi.amount ELSE 0 END), 0) AS tax
            FROM payslips p
            JOIN payslip_items pi ON pi.payslip_id = p.id
            WHERE p.status IN ('locked','released')
              AND EXTRACT(YEAR FROM p.period_start) = :yr
              AND pi.code IN ('SSS_EE','SSS_ER','PHIC_EE','PHIC_ER','HDMF_EE','HDMF_ER','TAX')
            GROUP BY mo ORDER BY mo
        ");
        $stmt->execute([':yr' => $filterYear]);
        $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        sys_log('BIR-MONTHLY', 'Monthly remittance query failed: ' . $e->getMessage(), ['module' => 'bir-reports']);
    }
}

// ---- Annual totals per employee for 2316 / alphalist tabs ----
$annualData = [];
if ($tab === '2316' || $tab === 'alphalist') {
    try {
        $stmt = $pdo->prepare("
            SELECT e.id AS employee_id, e.employee_code, e.first_name, e.last_name,
                   e.tin,
                   COALESCE(SUM(CASE WHEN pi.code = 'BASIC' THEN pi.amount ELSE 0 END), 0) AS basic_pay,
                   COALESCE(SUM(CASE WHEN pi.type = 'earning' THEN pi.amount ELSE 0 END), 0) AS gross_income,
                   COALESCE(SUM(CASE WHEN pi.code = 'SSS_EE' THEN pi.amount ELSE 0 END), 0) AS sss_ee,
                   COALESCE(SUM(CASE WHEN pi.code = 'PHIC_EE' THEN pi.amount ELSE 0 END), 0) AS phic_ee,
                   COALESCE(SUM(CASE WHEN pi.code = 'HDMF_EE' THEN pi.amount ELSE 0 END), 0) AS hdmf_ee,
                   COALESCE(SUM(CASE WHEN pi.code = 'TAX' THEN pi.amount ELSE 0 END), 0) AS tax_withheld,
                   COALESCE(SUM(p.net_pay), 0) AS net_pay
            FROM employees e
            JOIN payslips p ON p.employee_id = e.id AND p.status IN ('locked','released')
                AND EXTRACT(YEAR FROM p.period_start) = :yr
            LEFT JOIN payslip_items pi ON pi.payslip_id = p.id
            WHERE e.status = 'active'
            GROUP BY e.id, e.employee_code, e.first_name, e.last_name, e.tin
            ORDER BY e.last_name, e.first_name
        ");
        $stmt->execute([':yr' => $filterYear]);
        $annualData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        sys_log('BIR-ANNUAL', 'Annual employee data query failed: ' . $e->getMessage(), ['module' => 'bir-reports']);
    }
}

// ---- Recent report logs ----
$recentLogs = [];
try {
    $stmt = $pdo->prepare("
        SELECT brl.*, u.username AS generated_by_name
        FROM bir_report_logs brl
        LEFT JOIN users u ON u.id = brl.generated_by
        ORDER BY brl.created_at DESC LIMIT 10
    ");
    $stmt->execute();
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Non-critical
}

$availableYears = range($currentYear, $currentYear - 5);
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

$pageTitle = 'BIR Reports';
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="space-y-6">
  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
      <h1 class="text-xl font-bold text-slate-900">BIR Reports</h1>
      <p class="text-sm text-slate-500 mt-0.5">Generate BIR compliance reports — Form 2316, 1604-C Alphalist, Monthly Remittances</p>
    </div>
    <div class="flex items-center gap-2">
      <form method="get" class="flex items-center gap-2">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <select name="year" class="input-text text-sm" onchange="this.form.submit()">
          <?php foreach ($availableYears as $yr): ?>
            <option value="<?= $yr ?>" <?= $yr === $filterYear ? 'selected' : '' ?>><?= $yr ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <!-- Stat Cards -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card card-body flex items-center gap-4">
      <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87M15 11a4 4 0 10-6 0m6 0a4 4 0 11-6 0"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900"><?= number_format($statEmployees) ?></div>
        <div class="text-xs text-slate-500">Employees with Payslips</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-4">
      <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900"><?= number_format($statPayslips) ?></div>
        <div class="text-xs text-slate-500">Processed Payslips</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-4">
      <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-3 0-5 1.5-5 4s2 4 5 4 5-1.5 5-4-2-4-5-4zm0-5v5m0 8v5"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900">&#8369;<?= number_format($statTotalTax, 2) ?></div>
        <div class="text-xs text-slate-500">Total Tax Withheld</div>
      </div>
    </div>
    <div class="card card-body flex items-center gap-4">
      <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-slate-900"><?= number_format($statReportsGenerated) ?></div>
        <div class="text-xs text-slate-500">Reports Generated</div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="border-b border-slate-200 mb-6">
    <nav class="flex gap-4 -mb-px" aria-label="Tabs">
      <?php
        $tabs = [
          '2316' => 'Form 2316',
          'alphalist' => '1604-C Alphalist',
          'remittance' => 'Monthly Remittances',
        ];
        foreach ($tabs as $key => $label):
          $isActive = ($tab === $key);
          $href = BASE_URL . '/modules/admin/bir-reports/index?tab=' . $key . '&year=' . $filterYear;
      ?>
        <a href="<?= $href ?>" class="spa px-3 py-2 text-sm font-medium border-b-2 <?= $isActive ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
          <?= $label ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if ($tab === '2316'): ?>
  <!-- Form 2316 — Annual Certificate of Compensation Payment / Tax Withheld -->
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <span>BIR Form 2316 — Annual Tax Summary (<?= $filterYear ?>)</span>
      <?php if (user_has_access($uid, 'reports', 'bir_reports', 'write')): ?>
        <a href="<?= BASE_URL ?>/modules/admin/bir-reports/export-2316?year=<?= $filterYear ?>" class="btn btn-primary text-sm" data-no-loader>
          <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Export CSV
        </a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (empty($annualData)): ?>
        <p class="text-sm text-slate-500 py-8 text-center">No payslip data found for <?= $filterYear ?>.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table-basic">
            <thead>
              <tr>
                <th>Employee</th>
                <th>TIN</th>
                <th class="text-right">Gross Income</th>
                <th class="text-right">SSS</th>
                <th class="text-right">PhilHealth</th>
                <th class="text-right">Pag-IBIG</th>
                <th class="text-right">Tax Withheld</th>
                <th class="text-right">Net Pay</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($annualData as $emp): ?>
              <tr>
                <td>
                  <div class="font-medium text-slate-900"><?= htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']) ?></div>
                  <div class="text-xs text-slate-400"><?= htmlspecialchars($emp['employee_code'] ?? '') ?></div>
                </td>
                <td class="text-sm"><?= htmlspecialchars($emp['tin'] ?? 'N/A') ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$emp['gross_income'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$emp['sss_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$emp['phic_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$emp['hdmf_ee'], 2) ?></td>
                <td class="text-right font-semibold text-red-600">&#8369;<?= number_format((float)$emp['tax_withheld'], 2) ?></td>
                <td class="text-right font-semibold">&#8369;<?= number_format((float)$emp['net_pay'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="font-semibold bg-slate-50">
                <td colspan="2">Totals (<?= count($annualData) ?> employees)</td>
                <td class="text-right">&#8369;<?= number_format(array_sum(array_column($annualData, 'gross_income')), 2) ?></td>
                <td class="text-right">&#8369;<?= number_format(array_sum(array_column($annualData, 'sss_ee')), 2) ?></td>
                <td class="text-right">&#8369;<?= number_format(array_sum(array_column($annualData, 'phic_ee')), 2) ?></td>
                <td class="text-right">&#8369;<?= number_format(array_sum(array_column($annualData, 'hdmf_ee')), 2) ?></td>
                <td class="text-right text-red-600">&#8369;<?= number_format(array_sum(array_column($annualData, 'tax_withheld')), 2) ?></td>
                <td class="text-right">&#8369;<?= number_format(array_sum(array_column($annualData, 'net_pay')), 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif ($tab === 'alphalist'): ?>
  <!-- 1604-C Alphalist -->
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <span>BIR Form 1604-C — Alphalist of Employees (<?= $filterYear ?>)</span>
      <?php if (user_has_access($uid, 'reports', 'bir_reports', 'write')): ?>
        <a href="<?= BASE_URL ?>/modules/admin/bir-reports/export-alphalist?year=<?= $filterYear ?>" class="btn btn-primary text-sm" data-no-loader>
          <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Export CSV
        </a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (empty($annualData)): ?>
        <p class="text-sm text-slate-500 py-8 text-center">No payslip data found for <?= $filterYear ?>.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table-basic">
            <thead>
              <tr>
                <th>#</th>
                <th>TIN</th>
                <th>Last Name</th>
                <th>First Name</th>
                <th class="text-right">Gross Compensation</th>
                <th class="text-right">Non-Taxable (SSS+PHIC+HDMF)</th>
                <th class="text-right">Taxable Income</th>
                <th class="text-right">Tax Withheld</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($annualData as $i => $emp):
                $totalContrib = (float)$emp['sss_ee'] + (float)$emp['phic_ee'] + (float)$emp['hdmf_ee'];
                $taxableIncome = (float)$emp['gross_income'] - $totalContrib;
              ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td class="text-sm"><?= htmlspecialchars($emp['tin'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($emp['last_name']) ?></td>
                <td><?= htmlspecialchars($emp['first_name']) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$emp['gross_income'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($totalContrib, 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($taxableIncome, 2) ?></td>
                <td class="text-right font-semibold text-red-600">&#8369;<?= number_format((float)$emp['tax_withheld'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <?php
                $totalGross = array_sum(array_column($annualData, 'gross_income'));
                $totalSSS = array_sum(array_column($annualData, 'sss_ee'));
                $totalPHIC = array_sum(array_column($annualData, 'phic_ee'));
                $totalHDMF = array_sum(array_column($annualData, 'hdmf_ee'));
                $totalNonTax = $totalSSS + $totalPHIC + $totalHDMF;
                $totalTaxable = $totalGross - $totalNonTax;
                $totalTaxW = array_sum(array_column($annualData, 'tax_withheld'));
              ?>
              <tr class="font-semibold bg-slate-50">
                <td colspan="4">Totals (<?= count($annualData) ?> employees)</td>
                <td class="text-right">&#8369;<?= number_format($totalGross, 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($totalNonTax, 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($totalTaxable, 2) ?></td>
                <td class="text-right text-red-600">&#8369;<?= number_format($totalTaxW, 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif ($tab === 'remittance'): ?>
  <!-- Monthly Remittances -->
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <span>Monthly Statutory Remittances (<?= $filterYear ?>)</span>
      <?php if (user_has_access($uid, 'reports', 'bir_reports', 'write')): ?>
        <a href="<?= BASE_URL ?>/modules/admin/bir-reports/export-remittance?year=<?= $filterYear ?>" class="btn btn-primary text-sm" data-no-loader>
          <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Export CSV
        </a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (empty($monthlyData)): ?>
        <p class="text-sm text-slate-500 py-8 text-center">No remittance data found for <?= $filterYear ?>.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="table-basic">
            <thead>
              <tr>
                <th>Month</th>
                <th class="text-right">SSS (EE)</th>
                <th class="text-right">SSS (ER)</th>
                <th class="text-right">PhilHealth (EE)</th>
                <th class="text-right">PhilHealth (ER)</th>
                <th class="text-right">Pag-IBIG (EE)</th>
                <th class="text-right">Pag-IBIG (ER)</th>
                <th class="text-right">Tax Withheld</th>
                <th class="text-right font-semibold">Total Remittance</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $grandTotals = ['sss_ee'=>0,'sss_er'=>0,'phic_ee'=>0,'phic_er'=>0,'hdmf_ee'=>0,'hdmf_er'=>0,'tax'=>0];
                foreach ($monthlyData as $m):
                  $mo = (int)$m['mo'];
                  $totalRow = (float)$m['sss_ee'] + (float)$m['sss_er'] + (float)$m['phic_ee'] + (float)$m['phic_er'] + (float)$m['hdmf_ee'] + (float)$m['hdmf_er'] + (float)$m['tax'];
                  foreach ($grandTotals as $k => &$v) $v += (float)$m[$k];
                  unset($v);
              ?>
              <tr>
                <td class="font-medium"><?= $months[$mo - 1] ?? $mo ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$m['sss_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$m['sss_er'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$m['phic_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$m['phic_er'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$m['hdmf_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$m['hdmf_er'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format((float)$m['tax'], 2) ?></td>
                <td class="text-right font-semibold">&#8369;<?= number_format($totalRow, 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <?php $grandTotal = array_sum($grandTotals); ?>
              <tr class="font-semibold bg-slate-50">
                <td>Annual Totals</td>
                <td class="text-right">&#8369;<?= number_format($grandTotals['sss_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($grandTotals['sss_er'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($grandTotals['phic_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($grandTotals['phic_er'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($grandTotals['hdmf_ee'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($grandTotals['hdmf_er'], 2) ?></td>
                <td class="text-right">&#8369;<?= number_format($grandTotals['tax'], 2) ?></td>
                <td class="text-right text-indigo-600">&#8369;<?= number_format($grandTotal, 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent Export Logs -->
  <?php if (!empty($recentLogs)): ?>
  <div class="card">
    <div class="card-header"><span>Recent Report Exports</span></div>
    <div class="card-body">
      <table class="table-basic">
        <thead>
          <tr>
            <th>Report Type</th>
            <th>Period</th>
            <th>Rows</th>
            <th>Generated By</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentLogs as $log): ?>
          <tr>
            <td>
              <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700">
                <?= htmlspecialchars($log['report_type']) ?>
              </span>
            </td>
            <td class="text-sm"><?= htmlspecialchars($log['period_start'] . ' – ' . $log['period_end']) ?></td>
            <td class="text-sm"><?= number_format((int)$log['row_count']) ?></td>
            <td class="text-sm"><?= htmlspecialchars($log['generated_by_name'] ?? 'Unknown') ?></td>
            <td class="text-sm text-slate-500"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
