<?php
/**
 * BIR 1604-C Alphalist — CSV Export
 * Annual Information Return of Income Taxes Withheld on Compensation
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
require_module_access('reports', 'bir_reports', 'write');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);
$filterYear = (int)($_GET['year'] ?? date('Y'));

try {
    $stmt = $pdo->prepare("
        SELECT e.employee_code, e.last_name, e.first_name, e.middle_name, e.tin,
               COALESCE(SUM(CASE WHEN pi.type = 'earning' THEN pi.amount ELSE 0 END), 0) AS gross_compensation,
               COALESCE(SUM(CASE WHEN pi.code = 'SSS_EE' THEN pi.amount ELSE 0 END), 0) AS sss_ee,
               COALESCE(SUM(CASE WHEN pi.code = 'PHIC_EE' THEN pi.amount ELSE 0 END), 0) AS phic_ee,
               COALESCE(SUM(CASE WHEN pi.code = 'HDMF_EE' THEN pi.amount ELSE 0 END), 0) AS hdmf_ee,
               COALESCE(SUM(CASE WHEN pi.code = 'TAX' THEN pi.amount ELSE 0 END), 0) AS tax_withheld
        FROM employees e
        JOIN payslips p ON p.employee_id = e.id AND p.status IN ('locked','released')
            AND EXTRACT(YEAR FROM p.period_start) = :yr
        LEFT JOIN payslip_items pi ON pi.payslip_id = p.id
        WHERE e.status = 'active'
        GROUP BY e.employee_code, e.last_name, e.first_name, e.middle_name, e.tin
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([':yr' => $filterYear]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('BIR-EXPORT-ALPHA', 'Alphalist export failed: ' . $e->getMessage(), ['module' => 'bir-reports']);
    flash_error('Failed to generate 1604-C Alphalist export.');
    header('Location: ' . BASE_URL . '/modules/admin/bir-reports/index?tab=alphalist&year=' . $filterYear);
    exit;
}

// Log the export
try {
    $logStmt = $pdo->prepare("
        INSERT INTO bir_report_logs (report_type, period_start, period_end, generated_by, row_count, parameters)
        VALUES ('alphalist_1604c', :ps, :pe, :uid, :cnt, :params)
    ");
    $logStmt->execute([
        ':ps' => $filterYear . '-01-01',
        ':pe' => $filterYear . '-12-31',
        ':uid' => $uid,
        ':cnt' => count($rows),
        ':params' => json_encode(['year' => $filterYear]),
    ]);
} catch (Throwable $e) { /* Non-critical */ }

action_log('bir-reports', 'export', 'success', ['report' => 'alphalist_1604c', 'year' => $filterYear, 'rows' => count($rows)]);

// Output CSV
$filename = "BIR_1604C_Alphalist_{$filterYear}_" . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['BIR Form 1604-C — Alphalist of Employees with Compensation and Tax Withheld']);
fputcsv($out, ['Tax Year: ' . $filterYear, 'Generated: ' . date('M d, Y h:i A')]);
fputcsv($out, []);
fputcsv($out, [
    'Seq No', 'TIN', 'Last Name', 'First Name', 'Middle Name',
    'Gross Compensation', 'Non-Taxable (SSS+PHIC+HDMF)',
    'Taxable Compensation', 'Tax Withheld',
]);

$seq = 0;
foreach ($rows as $r) {
    $seq++;
    $nonTaxable = (float)$r['sss_ee'] + (float)$r['phic_ee'] + (float)$r['hdmf_ee'];
    $taxable = (float)$r['gross_compensation'] - $nonTaxable;
    fputcsv($out, [
        $seq,
        $r['tin'] ?? '',
        $r['last_name'],
        $r['first_name'],
        $r['middle_name'] ?? '',
        number_format((float)$r['gross_compensation'], 2, '.', ''),
        number_format($nonTaxable, 2, '.', ''),
        number_format($taxable, 2, '.', ''),
        number_format((float)$r['tax_withheld'], 2, '.', ''),
    ]);
}

fclose($out);
exit;
