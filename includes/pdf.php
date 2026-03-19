<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

/**
 * Try to require FPDF from common locations.
 */
function pdf_require_fpdf(): bool {
    $candidates = [
        __DIR__ . '/../assets/fpdf186/fpdf.php',   // bundled in repo
        __DIR__ . '/../vendor/fpdf.php',           // flat vendor
        __DIR__ . '/../vendor/FPDF/fpdf.php',      // case variant
    __DIR__ . '/../vendor/fpdf/fpdf.php',      // composer-like folder
    __DIR__ . '/../vendor/setasign/fpdf/fpdf.php', // composer (setasign/fpdf)
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            // Ensure fonts path is correct when using bundled/relative installs
            $fontDir = dirname($path) . DIRECTORY_SEPARATOR . 'font' . DIRECTORY_SEPARATOR;
            if (!defined('FPDF_FONTPATH') && is_dir($fontDir)) {
                define('FPDF_FONTPATH', $fontDir);
            }
            require_once $path;
            return true;
        }
    }
    return false;
}

/**
 * Try to require TCPDF from common locations.
 */
function pdf_require_tcpdf(): bool {
    $candidates = [
        __DIR__ . '/../vendor/tcpdf.php',
        __DIR__ . '/../vendor/TCPDF/tcpdf.php',
        __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) { require_once $path; return true; }
    }
    return false;
}

/**
 * Determine preferred vendor based on optional PDF_VENDOR constant and availability.
 */
function pdf_vendor_available(): string {
    // Explicit preference via config constant if defined and available
    if (defined('PDF_VENDOR')) {
        $pref = strtolower((string)PDF_VENDOR);
        if ($pref === 'fpdf' && pdf_require_fpdf()) return 'fpdf';
        if ($pref === 'tcpdf' && pdf_require_tcpdf()) return 'tcpdf';
    }
    // Prefer FPDF by default (lightweight), else TCPDF
    if (pdf_require_fpdf()) return 'fpdf';
    if (pdf_require_tcpdf()) return 'tcpdf';
    return '';
}

function pdf_get_template(string $reportKey): array {
    $pdo = get_db_conn();
    // Defaults
    $defaults = [
        'title' => '',
        'header' => [ 'show_company' => true, 'company_name' => COMPANY_NAME, 'company_address' => COMPANY_ADDRESS ?? '', 'logo_path' => '' ],
        'footer' => [ 'show_page_numbers' => true ],
        'signatories' => [
            // [ 'name' => 'Jane Doe', 'title' => 'HR Manager' ]
        ],
    ];
    // Try DB
    if ($pdo) {
        try {
            $stmt = $pdo->prepare('SELECT settings FROM pdf_templates WHERE report_key = :key');
            $stmt->execute([':key' => $reportKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $cfg = json_decode($row['settings'], true) ?: [];
                return array_replace_recursive($defaults, $cfg);
            }
        } catch (Throwable $e) {
            sys_log('DB2702', 'Execute failed: pdf_templates fetch - ' . $e->getMessage(), ['module'=>'pdf','file'=>__FILE__,'line'=>__LINE__]);
        }
    } else { sys_log('DB2700', 'DB connection unavailable for PDF template', ['module'=>'pdf','file'=>__FILE__,'line'=>__LINE__]); }
    return $defaults;
}

/**
 * Basic CP1252 encoder for FPDF (which doesn't handle UTF-8 by default with core fonts)
 */
function pdf_to_cp1252(string $s): string {
    // Pre-normalize currency symbols and other characters outside CP1252 so FPDF won't crash.
    static $manualMap = [
        '₱' => 'PHP ',
        '₩' => 'KRW ',
        '₹' => 'INR ',
    ];
    if ($manualMap) {
        $s = strtr($s, $manualMap);
    }

    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    if ($out !== false) {
        return $out;
    }

    // Last resort: strip remaining multibyte characters so FPDF receives ASCII-safe text.
    return preg_replace('/[^\x20-\x7E]/', '?', $s);
}

/**
 * Resolve a possibly-relative logo path into a readable filesystem path.
 */
function pdf_resolve_logo_path(?string $logo): ?string {
    if (empty($logo)) return null;
    // Absolute path?
    if (is_file($logo)) return $logo;
    // Try relative to app root
    $cand = __DIR__ . '/../' . ltrim($logo, '/\\');
    if (is_file($cand)) return $cand;
    return null;
}

// HR_FPDF will be defined inside the FPDF branch after the library is loaded

function pdf_output_report(string $reportKey, string $title, array $lines, string $filename = 'report.pdf') {
    $tpl = pdf_get_template($reportKey);
    $vendor = pdf_vendor_available();
    $companyBlock = '';
    if (!empty($tpl['header']['show_company'])) {
        $companyBlock = '<div style="text-align:center">'
            . '<div style="font-size:18px;font-weight:bold;">' . htmlspecialchars($tpl['header']['company_name'] ?: COMPANY_NAME) . '</div>'
            . (!empty($tpl['header']['company_address']) ? '<div style="font-size:11px;color:#666">' . htmlspecialchars($tpl['header']['company_address']) . '</div>' : '')
            . '</div>';
    }
    $sigBlock = '';
    if (!empty($tpl['signatories']) && is_array($tpl['signatories'])) {
        $sigBlock .= '<br/><table width="100%" cellspacing="0" cellpadding="6"><tr>';
        foreach ($tpl['signatories'] as $sig) {
            $nm = htmlspecialchars($sig['name'] ?? '');
            $tt = htmlspecialchars($sig['title'] ?? '');
            $sigBlock .= '<td style="text-align:center; vertical-align:bottom; height:80px;">'
              . '<div style="border-top:1px solid #000; margin:0 12px; padding-top:4px;">'
              . '<div style="font-weight:bold">' . $nm . '</div>'
              . '<div style="font-size:11px;color:#555">' . $tt . '</div>'
              . '</div></td>';
        }
        $sigBlock .= '</tr></table>';
    }
    if ($vendor === 'tcpdf') {
        $pdf = new TCPDF();
        $pdf->AddPage();
        $html = $companyBlock . '<h3 style="text-align:center;">' . htmlspecialchars($title ?: ($tpl['title'] ?? '')) . '</h3><hr/>';
        $html .= '<pre style="font-size:12px; line-height:1.5">' . htmlspecialchars(implode("\n", $lines)) . '</pre>';
        $html .= $sigBlock;
        $pdf->writeHTML($html);
        if (!empty($tpl['footer']['show_page_numbers'])) {
            $pdf->setFooterFont(['helvetica','',8]);
            $pdf->setPrintFooter(true);
        }
        $pdf->Output($filename, 'I');
        exit;
    } elseif ($vendor === 'fpdf') {
        // Define wrapper now that FPDF is loaded
        if (!class_exists('HR_FPDF') && class_exists('FPDF')) {
            class HR_FPDF extends FPDF {
                public array $tpl = [];
                public string $reportTitle = '';
                protected ?string $logoAbsPath = null;

                public function setTemplate(array $tpl): void {
                    $this->tpl = $tpl;
                    $this->logoAbsPath = pdf_resolve_logo_path($tpl['header']['logo_path'] ?? null);
                }

                function Header(): void {
                    $showCompany = !empty($this->tpl['header']['show_company']);
                    if ($showCompany) {
                        if ($this->logoAbsPath) {
                            @$this->Image($this->logoAbsPath, 10, 10, 20);
                            $this->SetXY(10, 10);
                        }
                        $this->SetFont('Arial', 'B', 14);
                        $this->Cell(0, 7, pdf_to_cp1252(($this->tpl['header']['company_name'] ?? COMPANY_NAME)), 0, 1, 'C');
                        $addr = trim((string)($this->tpl['header']['company_address'] ?? ''));
                        if ($addr !== '') {
                            $this->SetFont('Arial', '', 10);
                            $this->Cell(0, 6, pdf_to_cp1252($addr), 0, 1, 'C');
                        }
                        $this->Ln(2);
                    }
                    if ($this->reportTitle !== '') {
                        $this->SetFont('Arial', '', 12);
                        $this->Cell(0, 8, pdf_to_cp1252($this->reportTitle), 0, 1, 'C');
                    }
                    $this->SetLineWidth(0.1);
                    $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
                    $this->Ln(3);
                }

                function Footer(): void {
                    if (!empty($this->tpl['footer']['show_page_numbers'])) {
                        $this->SetY(-15);
                        $this->SetFont('Arial', 'I', 8);
                        $txt = 'Page ' . $this->PageNo() . '/{nb}';
                        $this->Cell(0, 10, $txt, 0, 0, 'C');
                    }
                }
            }
        }

        // Use our wrapper with proper header/footer and encoding
        $pdf = class_exists('HR_FPDF') ? new HR_FPDF() : new FPDF();
        if ($pdf instanceof HR_FPDF) {
            $pdf->AliasNbPages();
            $pdf->setTemplate($tpl);
            $pdf->reportTitle = (string)($title ?: ($tpl['title'] ?? ''));
        }
        $pdf->SetMargins(10, 20, 10);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        // If base FPDF (no custom Header), render a simple heading
        if (!($pdf instanceof HR_FPDF)) {
            if (!empty($tpl['header']['show_company'])) {
                $pdf->SetFont('Arial','B',14);
                $pdf->Cell(0,8, pdf_to_cp1252($tpl['header']['company_name'] ?: COMPANY_NAME), 0, 1, 'C');
                if (!empty($tpl['header']['company_address'])) {
                    $pdf->SetFont('Arial','',10);
                    $pdf->Cell(0,6, pdf_to_cp1252($tpl['header']['company_address']), 0, 1, 'C');
                }
            }
            $pdf->SetFont('Arial','',12);
            $pdf->Cell(0,8, pdf_to_cp1252(($title ?: ($tpl['title'] ?? ''))), 0, 1, 'C');
            $pdf->Ln(3);
        }

        // Body lines
        $pdf->SetFont('Arial','',11);
        foreach ($lines as $line) {
            $pdf->MultiCell(0, 6, pdf_to_cp1252((string)$line));
        }

        // Signatories
        if (!empty($tpl['signatories'])) {
            $pdf->Ln(10);
            $count = max(1, (int)count($tpl['signatories']));
            $usableWidth = $pdf->GetPageWidth() - $pdf->lMargin - $pdf->rMargin;
            $w = $usableWidth / $count;
            // Signature lines
            for ($i=0; $i<$count; $i++) { $pdf->Cell($w, 10, '', 0, 0, 'C'); }
            $pdf->Ln(12);
            for ($i=0; $i<$count; $i++) { $pdf->Cell($w, 0, '', 'T', 0, 'C'); }
            $pdf->Ln(6);
            $pdf->SetFont('Arial','B',11);
            foreach ($tpl['signatories'] as $sig) {
                $pdf->Cell($w, 6, pdf_to_cp1252((string)($sig['name'] ?? '')), 0, 0, 'C');
            }
            $pdf->Ln(6);
            $pdf->SetFont('Arial','',9);
            foreach ($tpl['signatories'] as $sig) {
                $pdf->Cell($w, 6, pdf_to_cp1252((string)($sig['title'] ?? '')), 0, 0, 'C');
            }
        }

        $pdf->Output('I', $filename);
        exit;
    } else {
        sys_log('GEN4701', 'No PDF vendor available, using plain text fallback', ['module'=>'pdf','file'=>__FILE__,'line'=>__LINE__,'context'=>['reportKey'=>$reportKey]]);
        header('Content-Type: text/plain');
        echo ($tpl['header']['company_name'] ?: COMPANY_NAME) . "\n" . ($title ?: ($tpl['title'] ?? '')) . "\n\n" . implode("\n", $lines);
        if (!empty($tpl['signatories'])) {
            echo "\n\nSignatories:\n";
            foreach ($tpl['signatories'] as $sig) {
                echo '- ' . ($sig['name'] ?? '') . ' (' . ($sig['title'] ?? '') . ")\n";
            }
        }
        exit;
    }
}

// Backwards compatibility
function pdf_output_simple(string $title, array $lines, string $filename = 'report.pdf') {
    return pdf_output_report('generic', $title, $lines, $filename);
}
