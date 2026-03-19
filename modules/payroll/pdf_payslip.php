<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_once __DIR__ . '/../../includes/payroll.php';
require_once __DIR__ . '/../../includes/pdf.php';

$pdo = get_db_conn();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
  http_response_code(404);
  echo 'Payslip not found';
  exit;
}

// Fetch payslip using helper to stay aligned with new schema
$payslip = payroll_get_payslip($pdo, $id);
if (!$payslip) {
  http_response_code(404);
  echo 'Payslip not found';
  exit;
}

// Access: owner or allowed roles
$ownerUserId = (int)($payslip['owner_user_id'] ?? 0);
$role = strtolower($user['role'] ?? '');
$allowedRoles = ['admin', 'hr', 'accountant', 'manager', 'hr_supervisor', 'hr_payroll'];
$canAdmin = in_array($role, $allowedRoles, true) || user_has_access($uid, 'payroll', 'payslips', 'read');
if (!$canAdmin && $ownerUserId !== $uid) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

$items = payroll_get_payslip_items($pdo, [$id]);
$itemRows = $items[$id] ?? [];
$earnings = [];
$deductions = [];
foreach ($itemRows as $item) {
  $type = strtolower((string)($item['type'] ?? ''));
  $entry = [
    'label' => $item['label'] ?? ($item['code'] ?? 'Item'),
    'amount' => (float)($item['amount'] ?? 0),
  ];
  if ($type === 'earning') {
    $earnings[] = $entry;
  } elseif ($type === 'deduction') {
    $deductions[] = $entry;
  }
}

// Fallback to JSON rollups if no items were materialized yet
if (!$earnings && !empty($payslip['earnings_json'])) {
  $earningsJson = json_decode((string)$payslip['earnings_json'], true);
  if (is_array($earningsJson)) {
    foreach ($earningsJson as $row) {
      if (!is_array($row)) { continue; }
      $earnings[] = [
        'label' => $row['label'] ?? ($row['code'] ?? 'Item'),
        'amount' => (float)($row['amount'] ?? 0),
      ];
    }
  }
}
if (!$deductions && !empty($payslip['deductions_json'])) {
  $deductionsJson = json_decode((string)$payslip['deductions_json'], true);
  if (is_array($deductionsJson)) {
    foreach ($deductionsJson as $row) {
      if (!is_array($row)) { continue; }
      $deductions[] = [
        'label' => $row['label'] ?? ($row['code'] ?? 'Item'),
        'amount' => (float)($row['amount'] ?? 0),
      ];
    }
  }
}

$breakdown = [];
if (!empty($payslip['breakdown'])) {
  $decoded = json_decode((string)$payslip['breakdown'], true);
  if (is_array($decoded)) {
    $breakdown = $decoded;
  }
}
$attendanceMeta = is_array($breakdown['attendance'] ?? null) ? $breakdown['attendance'] : [];
$attendanceAdjustments = is_array($breakdown['adjustments'] ?? null) ? $breakdown['adjustments'] : [];
$compensationMeta = is_array($breakdown['compensation'] ?? null) ? $breakdown['compensation'] : [];
$allowanceMeta = is_array($compensationMeta['allowances'] ?? null) ? $compensationMeta['allowances'] : [];
$allowanceDetails = [];
$allowanceTotal = 0.0;
foreach ($allowanceMeta as $allowanceRow) {
  $amount = (float)($allowanceRow['amount'] ?? 0);
  if ($amount <= 0) {
    continue;
  }
  $code = strtoupper(trim((string)($allowanceRow['code'] ?? '')));
  $label = trim((string)($allowanceRow['label'] ?? ''));
  if ($label === '' && $code !== '') {
    $label = $code;
  }
  if ($code !== '') {
    $label = '[' . $code . '] ' . $label;
  }
  $source = trim((string)($allowanceRow['source'] ?? ''));
  if ($source !== '') {
    $sourceLabel = $source === 'default' ? 'Default' : ucwords(str_replace('_', ' ', strtolower($source)));
    $label .= ' (' . $sourceLabel . ')';
  }
  $allowanceDetails[] = [
    'label' => $label,
    'amount' => $amount,
  ];
  $allowanceTotal += $amount;
}
$ratesMeta = is_array($breakdown['rates'] ?? null) ? $breakdown['rates'] : [];

$formatCurrency = static function (float $value): string {
  return number_format($value, 2);
};

$formatDate = static function ($value): string {
  if (!$value) {
    return '—';
  }
  try {
    $dt = new DateTime((string)$value);
    return $dt->format('M d, Y');
  } catch (Throwable $e) {
    return (string)$value;
  }
};

$employeeName = trim(($payslip['last_name'] ?? '') . ', ' . ($payslip['first_name'] ?? ''));
$employeeCode = trim((string)($payslip['employee_code'] ?? ''));
$departmentName = trim((string)($payslip['department_name'] ?? '')) ?: 'Unassigned';
$periodStart = $payslip['period_start'] ?? null;
$periodEnd = $payslip['period_end'] ?? null;
$periodLabel = trim($formatDate($periodStart) . ' to ' . $formatDate($periodEnd));
$expectedDays = (int)($attendanceMeta['expected_days'] ?? 0);
$presentDays = (int)($attendanceMeta['present_days'] ?? 0);
$absentDays = (int)($attendanceMeta['absent_days'] ?? max(0, $expectedDays - $presentDays));
$generatedAt = $formatDate($payslip['created_at'] ?? null);
$startDateLabel = $formatDate($periodStart);
$endDateLabel = $formatDate($periodEnd);
$payDateLabel = $formatDate($payslip['released_at'] ?? $payslip['release_date'] ?? $payslip['pay_date'] ?? $periodEnd);

$formatDayCount = static function (int $value): string {
  $value = max(0, $value);
  return $value . ' day' . ($value === 1 ? '' : 's');
};
$formatMinuteCount = static function (int $value): string {
  $value = max(0, $value);
  return $value . ' minute' . ($value === 1 ? '' : 's');
};
$formatSourceLabel = static function (string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  return ucwords(str_replace('_', ' ', strtolower($value)));
};

$attendanceSummaryRows = [];
if ($expectedDays > 0 || $presentDays > 0) {
  $attendanceSummaryRows[] = ['label' => 'Attendance Days', 'value' => $formatDayCount($presentDays) . ' / ' . $formatDayCount($expectedDays)];
}

$absenceAdjustment = null;
$tardyAdjustment = null;
$undertimeAdjustment = null;
foreach ($attendanceAdjustments as $adjustmentRow) {
  $code = strtoupper((string)($adjustmentRow['code'] ?? ''));
  if ($code === 'ABS' && $absenceAdjustment === null) {
    $absenceAdjustment = $adjustmentRow;
  } elseif ($code === 'TARDY' && $tardyAdjustment === null) {
    $tardyAdjustment = $adjustmentRow;
  } elseif ($code === 'UT' && $undertimeAdjustment === null) {
    $undertimeAdjustment = $adjustmentRow;
  }
}

if ($absenceAdjustment) {
  $days = max(0, (int)($absenceAdjustment['days'] ?? $absentDays));
  $amount = (float)($absenceAdjustment['amount'] ?? 0);
  $valueParts = [$formatDayCount($days)];
  if ($amount > 0) {
    $valueParts[] = 'PHP -' . $formatCurrency($amount);
  }
  $attendanceSummaryRows[] = ['label' => 'Absence Impact', 'value' => implode(' • ', $valueParts)];
}

if ($tardyAdjustment) {
  $minutes = max(0, (int)($tardyAdjustment['minutes'] ?? 0));
  if ($minutes > 0) {
    $amount = (float)($tardyAdjustment['amount'] ?? 0);
    $valueParts = [$formatMinuteCount($minutes)];
    if ($amount > 0) {
      $valueParts[] = 'PHP -' . $formatCurrency($amount);
    }
    $attendanceSummaryRows[] = ['label' => 'Tardiness Impact', 'value' => implode(' • ', $valueParts)];
  }
}

if ($undertimeAdjustment) {
  $minutes = max(0, (int)($undertimeAdjustment['minutes'] ?? 0));
  if ($minutes > 0) {
    $amount = (float)($undertimeAdjustment['amount'] ?? 0);
    $valueParts = [$formatMinuteCount($minutes)];
    if ($amount > 0) {
      $valueParts[] = 'PHP -' . $formatCurrency($amount);
    }
    $attendanceSummaryRows[] = ['label' => 'Undertime Impact', 'value' => implode(' • ', $valueParts)];
  }
}

$attendanceSource = $formatSourceLabel((string)($attendanceMeta['source'] ?? ''));
if ($attendanceSource !== '') {
  $attendanceSummaryRows[] = ['label' => 'Attendance Source', 'value' => $attendanceSource];
}

$rateSummaryRows = [];
$basicPay = (float)($payslip['basic_pay'] ?? 0);
if ($basicPay > 0) {
  $rateSummaryRows[] = ['label' => 'Basic Pay (This Period)', 'value' => 'PHP ' . $formatCurrency($basicPay), 'align' => 'R'];
}
if ($allowanceTotal > 0) {
  $rateSummaryRows[] = ['label' => 'Allowance Total', 'value' => 'PHP ' . $formatCurrency($allowanceTotal), 'align' => 'R'];
}
if (isset($ratesMeta['monthly'])) {
  $rateSummaryRows[] = ['label' => 'Monthly Salary', 'value' => 'PHP ' . $formatCurrency((float)$ratesMeta['monthly']), 'align' => 'R'];
}
if (isset($ratesMeta['bi_monthly'])) {
  $rateSummaryRows[] = ['label' => 'Semi-Monthly Rate', 'value' => 'PHP ' . $formatCurrency((float)$ratesMeta['bi_monthly']), 'align' => 'R'];
}
if (isset($ratesMeta['daily'])) {
  $rateSummaryRows[] = ['label' => 'Daily Rate', 'value' => 'PHP ' . $formatCurrency((float)$ratesMeta['daily']), 'align' => 'R'];
}
if (isset($ratesMeta['hourly'])) {
  $rateSummaryRows[] = ['label' => 'Hourly Rate', 'value' => 'PHP ' . $formatCurrency((float)$ratesMeta['hourly']), 'align' => 'R'];
}
if (isset($ratesMeta['per_minute'])) {
  $rateSummaryRows[] = ['label' => 'Per Minute Rate', 'value' => 'PHP ' . number_format((float)$ratesMeta['per_minute'], 3), 'align' => 'R'];
}
$grossPay = isset($payslip['gross_pay']) ? (float)$payslip['gross_pay'] : (float)($payslip['total_earnings'] ?? 0);
if ($grossPay > 0) {
  $rateSummaryRows[] = ['label' => 'Gross Pay (Before Deductions)', 'value' => 'PHP ' . $formatCurrency($grossPay), 'align' => 'R'];
}
$workingDaysPerMonth = (int)($ratesMeta['working_days_per_month'] ?? 0);
$hoursPerDay = (int)($ratesMeta['hours_per_day'] ?? 0);
if ($workingDaysPerMonth > 0 || $hoursPerDay > 0) {
  $basisParts = [];
  if ($workingDaysPerMonth > 0) {
    $basisParts[] = $workingDaysPerMonth . ' days/month';
  }
  if ($hoursPerDay > 0) {
    $basisParts[] = $hoursPerDay . ' hours/day';
  }
  $rateSummaryRows[] = ['label' => 'Payroll Basis', 'value' => implode(' | ', $basisParts)];
}

$earningsExtrasPdfRows = [];
$earningsExtrasTextRows = [];

$maxAllowanceExtras = 4;
if ($allowanceDetails) {
  $allowanceSlice = array_slice($allowanceDetails, 0, $maxAllowanceExtras);
  foreach ($allowanceSlice as $row) {
    $label = trim((string)($row['label'] ?? 'Allowance'));
    $amount = (float)($row['amount'] ?? 0);
    $displayLabel = $label !== '' ? $label : 'Allowance';
    $earningsExtrasPdfRows[] = [
      'label' => 'Allowance - ' . $displayLabel,
      'amount_text' => 'PHP ' . $formatCurrency($amount),
      'is_extra' => true,
      'align' => 'R',
    ];
    $earningsExtrasTextRows[] = 'Allowance - ' . $displayLabel . ': PHP ' . $formatCurrency($amount);
  }
  if (count($allowanceDetails) > $maxAllowanceExtras) {
    $remaining = count($allowanceDetails) - $maxAllowanceExtras;
    $earningsExtrasPdfRows[] = [
      'label' => 'Allowance - +' . $remaining . ' more',
      'amount_text' => 'See portal',
      'is_extra' => true,
      'align' => 'L',
    ];
    $earningsExtrasTextRows[] = 'Allowance - +' . $remaining . ' more: See portal';
  }
}

if ($attendanceSummaryRows) {
  $attendanceSlice = array_slice($attendanceSummaryRows, 0, 4);
  foreach ($attendanceSlice as $row) {
    $label = (string)($row['label'] ?? 'Attendance');
    $value = trim((string)($row['value'] ?? ''));
    if ($value === '') {
      continue;
    }
    $earningsExtrasPdfRows[] = [
      'label' => 'Attendance - ' . $label,
      'amount_text' => $value,
      'is_extra' => true,
      'align' => 'L',
    ];
    $earningsExtrasTextRows[] = 'Attendance - ' . $label . ': ' . $value;
  }
}

if ($rateSummaryRows) {
  $preferredRates = ['Monthly Salary', 'Daily Rate', 'Hourly Rate', 'Payroll Basis'];
  foreach ($preferredRates as $targetLabel) {
    foreach ($rateSummaryRows as $row) {
      if (($row['label'] ?? null) === $targetLabel) {
        $value = trim((string)($row['value'] ?? ''));
        if ($value === '') {
          continue 2;
        }
        $earningsExtrasPdfRows[] = [
          'label' => 'Rate - ' . $targetLabel,
          'amount_text' => $value,
          'is_extra' => true,
          'align' => 'R',
        ];
        $earningsExtrasTextRows[] = 'Rate - ' . $targetLabel . ': ' . $value;
        continue 2;
      }
    }
  }
}

$filename = 'payslip_' . preg_replace('/[^A-Za-z0-9_-]/', '', $employeeCode ?: 'employee') . '_' . preg_replace('/[^0-9A-Za-z_-]/', '', $payslip['period_start'] ?? '') . '.pdf';

sys_log('REPORT-GEN', 'Generated payslip PDF', ['module' => 'payroll', 'file' => __FILE__, 'line' => __LINE__, 'context' => ['payslip_id' => $payslip['id'] ?? null, 'employee_code' => $employeeCode]]);
audit('export_pdf', 'payslip:' . ($payslip['id'] ?? 0));

$mixColor = static function (array $base, float $ratio): array {
  $ratio = max(0.0, min(1.0, $ratio));
  $mixed = [];
  for ($i = 0; $i < 3; $i++) {
    $channel = $base[$i] ?? 0;
    $mixed[] = (int)round($channel + ((255 - $channel) * $ratio));
  }
  return $mixed;
};

$vendor = pdf_vendor_available();
if ($vendor === 'fpdf' && class_exists('FPDF')) {
  if (!class_exists('PayslipPDF')) {
    class PayslipPDF extends FPDF {
      public array $companyInfo = [];
      public array $accentColor = [39, 73, 204];

      public function setCompanyInfo(array $info): void {
        $this->companyInfo = $info;
      }

      public function setAccentColor(array $color): void {
        if (count($color) === 3) {
          $this->accentColor = array_map('intval', $color);
        }
      }

      public function getAccentColor(): array {
        return $this->accentColor;
      }

      public function Header(): void {
        $accent = $this->getAccentColor();
        $textMuted = [90, 90, 90];

    $this->SetY(5);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor($accent[0], $accent[1], $accent[2]);
        $title = strtoupper((string)($this->companyInfo['name'] ?? 'Company Name'));
        $this->Cell(0, 8, pdf_to_cp1252($title), 0, 1, 'C');

        $this->SetFont('Arial', '', 10);
        $this->SetTextColor($textMuted[0], $textMuted[1], $textMuted[2]);
        if (!empty($this->companyInfo['address'])) {
          $this->Cell(0, 6, pdf_to_cp1252($this->companyInfo['address']), 0, 1, 'C');
        }
        if (!empty($this->companyInfo['phone']) || !empty($this->companyInfo['mobile'])) {
          $contactLine = trim(($this->companyInfo['phone'] ?? '') . '  ' . ($this->companyInfo['mobile'] ?? ''));
          if ($contactLine !== '') {
            $this->Cell(0, 5, pdf_to_cp1252($contactLine), 0, 1, 'C');
          }
        }
        if (!empty($this->companyInfo['email'])) {
          $this->Cell(0, 5, pdf_to_cp1252($this->companyInfo['email']), 0, 1, 'C');
        }

        $this->Ln(3);
        $this->SetDrawColor($accent[0], $accent[1], $accent[2]);
        $this->SetLineWidth(0.6);
        $this->Line($this->lMargin, $this->GetY(), $this->GetPageWidth() - $this->rMargin, $this->GetY());
        $this->Ln(8);
      }

      public function Footer(): void {
        // Intentionally left blank to omit page numbering
      }

      }
    }

  try {
    $pdf = new PayslipPDF('L', 'mm', [215.9, 139.7]);
    $pdf->AliasNbPages();
    $pdf->setCompanyInfo([
      'name' => COMPANY_NAME,
      'address' => COMPANY_ADDRESS,
      'phone' => '',
      'mobile' => '',
      'email' => '',
    ]);
    $pdf->setAccentColor([24, 48, 140]);
    $leftMargin = 6;
    $rightMargin = 6;
    $topMargin = 4;
    $pdf->SetMargins($leftMargin, $topMargin, $rightMargin);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $accent = $pdf->getAccentColor();
    $textDark = [32, 32, 32];
    $borderColor = [198, 206, 220];
    $headerFill = $mixColor($accent, 0.78);
    $softFill = [246, 248, 252];

    $notesRaw = [];
    if (!empty($breakdown['notes']) && is_array($breakdown['notes'])) {
      $notesRaw = array_values(array_filter($breakdown['notes'], static function ($note) {
        return $note !== null && $note !== '';
      }));
    }
    $maxBaseNotes = 3;
    $notesDisplay = $notesRaw ? array_slice($notesRaw, 0, $maxBaseNotes) : [];
    if ($notesRaw && count($notesRaw) > $maxBaseNotes) {
      $notesDisplay[] = 'Additional notes available in HR portal.';
    }

  $contentWidth = (float)$pdf->GetPageWidth() - $leftMargin - $rightMargin;
    $colGap = 4;
    $columnWidth = ($contentWidth - $colGap) / 2;

  $currentY = (float)$pdf->GetY();
  $pageHeight = (float)$pdf->GetPageHeight();
    $bottomMargin = 4;
    $availableHeight = max(0.0, $pageHeight - $bottomMargin - $currentY);

    $panelRowsLeft = 3;
    $panelRowsRight = 3;
    $earningsRowEstimate = count($earnings) + count($earningsExtrasPdfRows);
    $maxRows = max($earningsRowEstimate, count($deductions));
    if ($maxRows === 0) { $maxRows = 1; }
    $notesDisplayCount = count($notesDisplay);

    $basePanelHeaderHeight = 8.0;
    $basePanelRowHeight = 6.4;
    $basePanelSpacing = 8.0;
    $panelHeightEstimate = max(
      $basePanelHeaderHeight + ($panelRowsLeft * $basePanelRowHeight),
      $basePanelHeaderHeight + ($panelRowsRight * $basePanelRowHeight)
    );

    $baseTableHeaderHeight = 8.0;
    $baseTableRowHeight = 6.6;
    $baseTableTotalsHeight = 7.4;
    $baseTableSpacing = 6.0;
    $tableHeightEstimate = $baseTableHeaderHeight + ($maxRows * $baseTableRowHeight) + $baseTableTotalsHeight;

    $baseNetBoxHeight = 10.0;

    $notesHeightEstimate = 0.0;
    if ($notesDisplayCount > 0) {
      $notesHeightEstimate = 6.0 + 6.0 + ($notesDisplayCount * 5.6);
    }

    $estimatedHeight = $panelHeightEstimate + $basePanelSpacing + $tableHeightEstimate + $baseTableSpacing + $baseNetBoxHeight + $notesHeightEstimate;

    $scale = 1.0;
    if ($estimatedHeight > 0 && $availableHeight > 0 && $estimatedHeight > $availableHeight) {
      $scale = max(0.5, $availableHeight / $estimatedHeight);
    }

    $panelHeaderHeight = max(5.2, round($basePanelHeaderHeight * $scale, 2));
    $panelRowHeight = max(4.0, round($basePanelRowHeight * $scale, 2));
    $panelHeaderFontSize = max(8, round(10 * $scale));
    $panelBodyFontSize = max(7, round(9 * $scale));
    $panelSpacing = max(4.0, round($basePanelSpacing * $scale, 2));

    $tableHeaderHeight = max(5.2, round($baseTableHeaderHeight * $scale, 2));
    $tableRowHeight = max(4.0, round($baseTableRowHeight * $scale, 2));
    $tableTotalsHeight = max(5.0, round($baseTableTotalsHeight * $scale, 2));
    $tableHeaderFontSize = max(8, round(10 * $scale));
    $tableBodyFontSize = max(7, round(9 * $scale));
    $tableTotalsFontSize = max(8, round(10 * $scale));
    $tableSpacing = max(4.0, round($baseTableSpacing * $scale, 2));

    $netBoxHeight = max(7.0, round($baseNetBoxHeight * $scale, 2));
    $netLabelFontSize = max(9, round(11 * $scale));
    $netValueFontSize = max(9, round(11 * $scale));

    $notesSpacingBefore = max(4.0, round(6.0 * $scale, 2));
    $notesHeaderHeight = max(5.0, round(6.0 * $scale, 2));
    $notesLineHeight = max(4.0, round(5.6 * $scale, 2));
    $notesHeaderFontSize = max(8, round(10 * $scale));
    $notesBodyFontSize = max(7, round(9 * $scale));

    $drawInfoPanel = static function ($pdfInstance, float $x, float $y, float $w, string $title, callable $bodyRenderer) use ($headerFill, $accent, $panelHeaderHeight, $panelHeaderFontSize, $panelBodyFontSize, $panelRowHeight): float {
      $pdfInstance->SetXY($x, $y);
      $pdfInstance->SetFillColor($headerFill[0], $headerFill[1], $headerFill[2]);
      $pdfInstance->SetTextColor($accent[0], $accent[1], $accent[2]);
      $pdfInstance->SetFont('Arial', 'B', $panelHeaderFontSize);
      $pdfInstance->Cell($w, $panelHeaderHeight, pdf_to_cp1252($title), 'LTR', 1, 'L', true);

      $pdfInstance->SetTextColor(40, 40, 40);
      $pdfInstance->SetFont('Arial', '', $panelBodyFontSize);
      $bodyRenderer($pdfInstance, $x, $w, $panelRowHeight);

      $pdfInstance->SetX($x);
      $pdfInstance->Cell($w, 0, '', 'T');
      return (float)$pdfInstance->GetY();
    };

    $leftBottom = $drawInfoPanel($pdf, $leftMargin, $currentY, $columnWidth, 'EMPLOYEE DETAILS', function ($pdfInstance, float $x, float $w, float $rowHeight) use ($employeeName, $employeeCode, $departmentName) {
      $rows = [
        ['Employee', $employeeName ?: '—'],
        ['Employee No.', $employeeCode ?: '—'],
        ['Department', $departmentName ?: '—'],
      ];
      $labelWidth = max($w - 48, $w * 0.45);
      $valueWidth = $w - $labelWidth;
      $count = count($rows);
      foreach ($rows as $idx => $row) {
        $borderLeft = 'L';
        $borderRight = 'R';
        if ($idx === $count - 1) {
          $borderLeft = 'LB';
          $borderRight = 'RB';
        }
        $pdfInstance->SetX($x);
        $pdfInstance->Cell($labelWidth, $rowHeight, pdf_to_cp1252($row[0] . ':'), $borderLeft, 0, 'L');
        $pdfInstance->Cell($valueWidth, $rowHeight, pdf_to_cp1252($row[1]), $borderRight, 1, 'L');
      }
    });

    $rightBottom = $drawInfoPanel($pdf, $leftMargin + $columnWidth + $colGap, $currentY, $columnWidth, 'PAYSLIP SUMMARY', function ($pdfInstance, float $x, float $w, float $rowHeight) use ($generatedAt, $payDateLabel, $periodLabel) {
      $labelWidth = max($w - 48, $w * 0.5);
      $valueWidth = $w - $labelWidth;
      $rows = [
        ['Payslip Period', $periodLabel ?: '—'],
        ['Generated On', $generatedAt ?: '—'],
        ['Released On', $payDateLabel ?: '—'],
      ];
      $count = count($rows);
      foreach ($rows as $idx => $row) {
        $borderLeft = 'L';
        $borderRight = 'R';
        if ($idx === $count - 1) {
          $borderLeft = 'LB';
          $borderRight = 'RB';
        }
        $pdfInstance->SetX($x);
        $pdfInstance->Cell($labelWidth, $rowHeight, pdf_to_cp1252($row[0] . ':'), $borderLeft, 0, 'L');
        $pdfInstance->Cell($valueWidth, $rowHeight, pdf_to_cp1252($row[1]), $borderRight, 1, 'L');
      }
    });

    $panelBottom = max($leftBottom, $rightBottom);
    $pdf->SetY($panelBottom);
    $panelGapBeforeLine = max(1.5, round($panelSpacing * 0.4, 2));
    $panelGapAfterLine = max(2.5, round($panelSpacing * 0.6, 2));
    $pdf->Ln($panelGapBeforeLine);
    $pdf->SetDrawColor($borderColor[0], $borderColor[1], $borderColor[2]);
    $pdf->Line($leftMargin, $pdf->GetY(), $pdf->GetPageWidth() - $rightMargin, $pdf->GetY());
    $pdf->Ln($panelGapAfterLine);

    $drawBreakdownColumn = static function ($pdfInstance, float $x, float $y, float $w, string $title, array $items, string $totalLabel, float $totalValue, array $extras = []) use ($headerFill, $softFill, $textDark, $tableHeaderHeight, $tableHeaderFontSize, $tableBodyFontSize, $tableRowHeight, $tableTotalsHeight, $tableTotalsFontSize, $scale, $accent) {
      $pdfInstance->SetXY($x, $y);
      $pdfInstance->SetFillColor($headerFill[0], $headerFill[1], $headerFill[2]);
      $pdfInstance->SetTextColor($accent[0], $accent[1], $accent[2]);
      $pdfInstance->SetFont('Arial', 'B', $tableHeaderFontSize);
      $pdfInstance->Cell($w, $tableHeaderHeight, pdf_to_cp1252($title), 'LTR', 1, 'L', true);

      $pdfInstance->SetTextColor($textDark[0], $textDark[1], $textDark[2]);
      $pdfInstance->SetFont('Arial', '', $tableBodyFontSize);

      $amountWidth = max(22.0, min(32.0, $w * (0.28 + (0.04 * $scale))));
      $labelWidth = $w - $amountWidth;
      if (!$items && !$extras) {
        $pdfInstance->SetX($x);
        $pdfInstance->Cell($labelWidth, $tableRowHeight, pdf_to_cp1252('—'), 'L', 0, 'L');
        $pdfInstance->Cell($amountWidth, $tableRowHeight, '', 'R', 1, 'R');
      } else {
        $altFill = false;
        $count = count($items);
        foreach ($items as $idx => $row) {
          $fill = $altFill ? $softFill : [255, 255, 255];
          $pdfInstance->SetFillColor($fill[0], $fill[1], $fill[2]);
          $pdfInstance->SetX($x);
          $pdfInstance->Cell($labelWidth, $tableRowHeight, pdf_to_cp1252($row['label'] ?? ''), 'L', 0, 'L', true);
          $pdfInstance->Cell($amountWidth, $tableRowHeight, pdf_to_cp1252(number_format((float)($row['amount'] ?? 0), 2)), 'R', 1, 'R', true);
          $altFill = !$altFill;
        }

        if ($extras) {
          foreach ($extras as $extraRow) {
            $fill = $altFill ? $softFill : [255, 255, 255];
            $pdfInstance->SetFillColor($fill[0], $fill[1], $fill[2]);
            $pdfInstance->SetX($x);
            $labelText = pdf_to_cp1252((string)($extraRow['label'] ?? ''));
            $amountText = (string)($extraRow['amount_text'] ?? null);
            if ($amountText === '') {
              $amountText = number_format((float)($extraRow['amount'] ?? 0), 2);
            }
            $amountText = pdf_to_cp1252($amountText);
            $align = strtoupper((string)($extraRow['align'] ?? 'L'));
            if (!in_array($align, ['L', 'C', 'R'], true)) {
              $align = 'L';
            }
            $pdfInstance->Cell($labelWidth, $tableRowHeight, $labelText, 'L', 0, 'L', true);
            $pdfInstance->Cell($amountWidth, $tableRowHeight, $amountText, 'R', 1, $align, true);
            $altFill = !$altFill;
          }
          $pdfInstance->SetFillColor(255, 255, 255);
        }
      }

      $pdfInstance->SetFont('Arial', 'B', $tableTotalsFontSize);
      $pdfInstance->SetX($x);
      $pdfInstance->Cell($labelWidth, $tableTotalsHeight, pdf_to_cp1252($totalLabel), 'LB', 0, 'L');
      $pdfInstance->Cell($amountWidth, $tableTotalsHeight, pdf_to_cp1252(number_format($totalValue, 2)), 'RB', 1, 'R');

      return (float)$pdfInstance->GetY();
    };
    $breakdownTop = (float)$pdf->GetY();
    $leftColEnd = $drawBreakdownColumn($pdf, $leftMargin, $breakdownTop, $columnWidth, 'EARNINGS', $earnings, 'Total Earnings', (float)($payslip['total_earnings'] ?? 0), $earningsExtrasPdfRows);
    $rightColEnd = $drawBreakdownColumn($pdf, $leftMargin + $columnWidth + $colGap, $breakdownTop, $columnWidth, 'DEDUCTIONS', $deductions, 'Total Deductions', (float)($payslip['total_deductions'] ?? 0));

    $afterBreakdownY = max($leftColEnd, $rightColEnd) + $tableSpacing;
    $pdf->SetY($afterBreakdownY);

    $netFill = [236, 244, 236];
    $pdf->SetFillColor($netFill[0], $netFill[1], $netFill[2]);
    $pdf->SetDrawColor(190, 200, 190);
  $netStartY = (float)$pdf->GetY();
    $pdf->Rect($leftMargin, $netStartY, $contentWidth, $netBoxHeight, 'DF');
    $netTextOffset = max(1.0, round($netBoxHeight * 0.2, 2));
    $netTextHeight = max(5.0, round($netBoxHeight - (2 * $netTextOffset), 2));
    $pdf->SetXY($leftMargin + 4, $netStartY + $netTextOffset);
    $pdf->SetFont('Arial', 'B', $netLabelFontSize);
    $pdf->SetTextColor($textDark[0], $textDark[1], $textDark[2]);
    $pdf->Cell($contentWidth * 0.5, $netTextHeight, pdf_to_cp1252('NET PAY'), 0, 0, 'L');
    $pdf->SetTextColor(22, 120, 60);
    $pdf->SetFont('Arial', 'B', $netValueFontSize);
    $pdf->Cell($contentWidth * 0.5 - 4, $netTextHeight, pdf_to_cp1252(number_format(abs((float)($payslip['net_pay'] ?? 0)), 2)), 0, 1, 'R');
    $pdf->SetTextColor($textDark[0], $textDark[1], $textDark[2]);
    $pdf->SetY($netStartY + $netBoxHeight);

    if ($notesDisplay) {
      $pdf->Ln($notesSpacingBefore);
      $pdf->SetFont('Arial', 'B', $notesHeaderFontSize);
      $pdf->Cell(0, $notesHeaderHeight, pdf_to_cp1252('Notes'), 0, 1, 'L');
      $pdf->SetFont('Arial', '', $notesBodyFontSize);
      foreach ($notesDisplay as $note) {
        $pdf->MultiCell(0, $notesLineHeight, pdf_to_cp1252('- ' . (string)$note));
      }
    }
    $pdf->Output('I', $filename);
    exit;
  } catch (Throwable $e) {
    sys_log('PAYROLL-PAYSLIP-PDF', 'FPDF generation failed: ' . $e->getMessage(), [
      'module' => 'payroll',
      'file' => __FILE__,
      'line' => __LINE__,
      'context' => ['payslip_id' => $payslip['id'] ?? null],
    ]);
  }
}

// Fallback to generic output if no PDF vendor is available
$lines = [];
$lines[] = 'Company: ' . COMPANY_NAME;
$lines[] = 'Address: ' . COMPANY_ADDRESS;
$lines[] = 'Employee: ' . $employeeName . ' (' . $employeeCode . ')';
$lines[] = 'Department: ' . $departmentName;
$lines[] = 'Period: ' . $periodLabel;
$lines[] = '';
$lines[] = 'EARNINGS';
foreach ($earnings as $line) {
  $lines[] = '  - ' . ($line['label'] ?? 'Item') . ': ' . $formatCurrency((float)($line['amount'] ?? 0));
}
foreach ($earningsExtrasTextRows as $extraText) {
  $lines[] = '  - ' . $extraText;
}
$lines[] = '';
$lines[] = 'DEDUCTIONS';
foreach ($deductions as $line) {
  $lines[] = '  - ' . ($line['label'] ?? 'Item') . ': ' . $formatCurrency((float)($line['amount'] ?? 0));
}
$lines[] = '';
$lines[] = 'Net Pay: ' . $formatCurrency((float)($payslip['net_pay'] ?? 0));
$lines[] = 'Generated On: ' . $generatedAt;
$lines[] = 'Released On: ________';

pdf_output_report('payslip', 'Payslip', $lines, $filename);
exit;
?>
