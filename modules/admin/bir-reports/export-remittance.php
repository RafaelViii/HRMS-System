<?php
/**
 * BIR Monthly Remittances — CSV Export
 * SSS, PhilHealth, Pag-IBIG, Withholding Tax monthly breakdown
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_login();
require_module_access('reports', 'bir_reports', 'write');

$pdo = get_db_conn();
$uid = (int)($_SESSION['user_id'] ?? 0);
$filterYear = (int)($_GET['year'] ?? date('Y'));

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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sys_log('BIR-EXPORT-REMIT', 'Remittance export failed: ' . $e->getMessage(), ['module' => 'bir-reports']);
    flash_error('Failed to generate Monthly Remittance export.');
    header('Location: ' . BASE_URL . '/modules/admin/bir-reports/index?tab=remittance&year=' . $filterYear);
    exit;
}

// Log the export
try {
    $logStmt = $pdo->prepare("
        INSERT INTO bir_report_logs (report_type, period_start, period_end, generated_by, row_count, parameters)
        VALUES ('monthly_remittance', :ps, :pe, :uid, :cnt, :params)
    ");
    $logStmt->execute([
        ':ps' => $filterYear . '-01-01',
        ':pe' => $filterYear . '-12-31',
        ':uid' => $uid,
        ':cnt' => count($rows),
        ':params' => json_encode(['year' => $filterYear]),
    ]);
} catch (Throwable $e) { /* Non-critical */ }

action_log('bir-reports', 'export', 'success', ['report' => 'monthly_remittance', 'year' => $filterYear, 'rows' => count($rows)]);

$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// Output CSV
$filename = "BIR_Monthly_Remittance_{$filterYear}_" . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['BIR Monthly Statutory Remittances']);
fputcsv($out, ['Year: ' . $filterYear, 'Generated: ' . date('M d, Y h:i A')]);
fputcsv($out, []);
fputcsv($out, [
    'Month', 'SSS (EE)', 'SSS (ER)', 'SSS Total',
    'PhilHealth (EE)', 'PhilHealth (ER)', 'PhilHealth Total',
    'Pag-IBIG (EE)', 'Pag-IBIG (ER)', 'Pag-IBIG Total',
    'Withholding Tax', 'Grand Total',
]);

$grandTotals = ['sss_ee'=>0,'sss_er'=>0,'phic_ee'=>0,'phic_er'=>0,'hdmf_ee'=>0,'hdmf_er'=>0,'tax'=>0];
foreach ($rows as $r) {
    $mo = (int)$r['mo'];
    $sssTotal = (float)$r['sss_ee'] + (float)$r['sss_er'];
    $phicTotal = (float)$r['phic_ee'] + (float)$r['phic_er'];
    $hdmfTotal = (float)$r['hdmf_ee'] + (float)$r['hdmf_er'];
    $rowTotal = $sssTotal + $phicTotal + $hdmfTotal + (float)$r['tax'];

    foreach ($grandTotals as $k => &$v) $v += (float)$r[$k];
    unset($v);

    fputcsv($out, [
        $months[$mo - 1] ?? $mo,
        number_format((float)$r['sss_ee'], 2, '.', ''),
        number_format((float)$r['sss_er'], 2, '.', ''),
        number_format($sssTotal, 2, '.', ''),
        number_format((float)$r['phic_ee'], 2, '.', ''),
        number_format((float)$r['phic_er'], 2, '.', ''),
        number_format($phicTotal, 2, '.', ''),
        number_format((float)$r['hdmf_ee'], 2, '.', ''),
        number_format((float)$r['hdmf_er'], 2, '.', ''),
        number_format($hdmfTotal, 2, '.', ''),
        number_format((float)$r['tax'], 2, '.', ''),
        number_format($rowTotal, 2, '.', ''),
    ]);
}

// Grand total row
$grandSSS = $grandTotals['sss_ee'] + $grandTotals['sss_er'];
$grandPHIC = $grandTotals['phic_ee'] + $grandTotals['phic_er'];
$grandHDMF = $grandTotals['hdmf_ee'] + $grandTotals['hdmf_er'];
$grandAll = $grandSSS + $grandPHIC + $grandHDMF + $grandTotals['tax'];

fputcsv($out, [
    'ANNUAL TOTAL',
    number_format($grandTotals['sss_ee'], 2, '.', ''),
    number_format($grandTotals['sss_er'], 2, '.', ''),
    number_format($grandSSS, 2, '.', ''),
    number_format($grandTotals['phic_ee'], 2, '.', ''),
    number_format($grandTotals['phic_er'], 2, '.', ''),
    number_format($grandPHIC, 2, '.', ''),
    number_format($grandTotals['hdmf_ee'], 2, '.', ''),
    number_format($grandTotals['hdmf_er'], 2, '.', ''),
    number_format($grandHDMF, 2, '.', ''),
    number_format($grandTotals['tax'], 2, '.', ''),
    number_format($grandAll, 2, '.', ''),
]);

fclose($out);
exit;
