<?php
/**
 * BIR Form 2316 — CSV Export
 * Annual Certificate of Compensation Payment / Tax Withheld
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
require_module_access('reports', 'bir_reports', 'write');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);
$filterYear = (int)($_GET['year'] ?? date('Y'));

try {
    $stmt = $pdo->prepare("
        SELECT e.employee_code, e.last_name, e.first_name, e.tin,
               COALESCE(SUM(CASE WHEN pi.type = 'earning' THEN pi.amount ELSE 0 END), 0) AS gross_income,
               COALESCE(SUM(CASE WHEN pi.code = 'BASIC' THEN pi.amount ELSE 0 END), 0) AS basic_pay,
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
        GROUP BY e.employee_code, e.last_name, e.first_name, e.tin
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([':yr' => $filterYear]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('BIR-EXPORT-2316', 'Export failed: ' . $e->getMessage(), ['module' => 'bir-reports']);
    flash_error('Failed to generate Form 2316 export.');
    header('Location: ' . BASE_URL . '/modules/admin/bir-reports/index?tab=2316&year=' . $filterYear);
    exit;
}

// Log the export
try {
    $logStmt = $pdo->prepare("
        INSERT INTO bir_report_logs (report_type, period_start, period_end, generated_by, row_count, parameters)
        VALUES ('form_2316', :ps, :pe, :uid, :cnt, :params)
    ");
    $logStmt->execute([
        ':ps' => $filterYear . '-01-01',
        ':pe' => $filterYear . '-12-31',
        ':uid' => $uid,
        ':cnt' => count($rows),
        ':params' => json_encode(['year' => $filterYear]),
    ]);
} catch (Throwable $e) {
    // Non-critical
}

action_log('bir-reports', 'export', 'success', ['report' => 'form_2316', 'year' => $filterYear, 'rows' => count($rows)]);

// Output CSV
$filename = "BIR_Form2316_{$filterYear}_" . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
// BOM for Excel
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['BIR Form 2316 — Certificate of Compensation Payment / Tax Withheld']);
fputcsv($out, ['Tax Year: ' . $filterYear, 'Generated: ' . date('M d, Y h:i A')]);
fputcsv($out, []);
fputcsv($out, [
    'Employee Code', 'Last Name', 'First Name', 'TIN',
    'Gross Income', 'Basic Pay', 'SSS (EE)', 'PhilHealth (EE)', 'Pag-IBIG (EE)',
    'Tax Withheld', 'Net Pay',
]);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['employee_code'],
        $r['last_name'],
        $r['first_name'],
        $r['tin'] ?? '',
        number_format((float)$r['gross_income'], 2, '.', ''),
        number_format((float)$r['basic_pay'], 2, '.', ''),
        number_format((float)$r['sss_ee'], 2, '.', ''),
        number_format((float)$r['phic_ee'], 2, '.', ''),
        number_format((float)$r['hdmf_ee'], 2, '.', ''),
        number_format((float)$r['tax_withheld'], 2, '.', ''),
        number_format((float)$r['net_pay'], 2, '.', ''),
    ]);
}

fclose($out);
exit;
