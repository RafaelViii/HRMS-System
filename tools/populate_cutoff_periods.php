<?php
/**
 * Populate Cutoff Periods
 * 
 * Automatically generates cutoff periods based on standard Philippine payroll schedule:
 * - 21st of month to 5th of next month → Pay on 15th of next month
 * - 6th to 20th of month → Pay on 30th/last day of month
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_conn();

// Get start and end dates from command line or use defaults
$startYear = (int)($argv[1] ?? date('Y'));
$startMonth = (int)($argv[2] ?? date('n'));
$monthsToGenerate = (int)($argv[3] ?? 12);

echo "Generating cutoff periods...\n";
echo "Start: $startYear-" . str_pad($startMonth, 2, '0', STR_PAD_LEFT) . "\n";
echo "Months: $monthsToGenerate\n\n";

$createdCount = 0;
$skippedCount = 0;

for ($i = 0; $i < $monthsToGenerate * 2; $i++) {
    $currentMonth = $startMonth + floor($i / 2);
    $currentYear = $startYear + floor(($currentMonth - 1) / 12);
    $currentMonth = (($currentMonth - 1) % 12) + 1;
    
    $isFirstCutoff = ($i % 2) === 0; // 6th-20th
    
    if ($isFirstCutoff) {
        // First cutoff: 6th to 20th of current month
        $periodStart = sprintf('%04d-%02d-06', $currentYear, $currentMonth);
        $periodEnd = sprintf('%04d-%02d-20', $currentYear, $currentMonth);
        
        // Pay date: 30th or last day of same month
        $lastDay = date('t', strtotime($periodStart));
        $payDate = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, min(30, $lastDay));
        
        // Cutoff date: 22nd (2 days after period end)
        $cutoffDate = sprintf('%04d-%02d-22', $currentYear, $currentMonth);
        
        $periodName = date('F', strtotime($periodStart)) . ' 6-20, ' . $currentYear;
        
    } else {
        // Second cutoff: 21st to 5th of next month
        $nextMonth = $currentMonth + 1;
        $nextYear = $currentYear;
        if ($nextMonth > 12) {
            $nextMonth = 1;
            $nextYear++;
        }
        
        $periodStart = sprintf('%04d-%02d-21', $currentYear, $currentMonth);
        $periodEnd = sprintf('%04d-%02d-05', $nextYear, $nextMonth);
        
        // Pay date: 15th of next month
        $payDate = sprintf('%04d-%02d-15', $nextYear, $nextMonth);
        
        // Cutoff date: 7th of next month (2 days after period end)
        $cutoffDate = sprintf('%04d-%02d-07', $nextYear, $nextMonth);
        
        $periodName = date('F', strtotime($periodStart)) . ' 21 - ' . 
                      date('F', strtotime($periodEnd)) . ' 5, ' . $currentYear;
    }
    
    // Check if period already exists
    $checkStmt = $pdo->prepare('
        SELECT id FROM cutoff_periods 
        WHERE start_date = :start AND end_date = :end
    ');
    $checkStmt->execute([
        ':start' => $periodStart,
        ':end' => $periodEnd
    ]);
    
    if ($checkStmt->fetch()) {
        echo "⏭️  SKIP: $periodName ($periodStart to $periodEnd) - Already exists\n";
        $skippedCount++;
        continue;
    }
    
    // Insert the period
    try {
        $insertStmt = $pdo->prepare('
            INSERT INTO cutoff_periods 
            (period_name, start_date, end_date, cutoff_date, pay_date, status, is_locked, notes, created_by, created_at, updated_at)
            VALUES (:name, :start, :end, :cutoff, :pay, :status, :locked, :notes, :created_by, NOW(), NOW())
        ');
        
        $insertStmt->execute([
            ':name' => $periodName,
            ':start' => $periodStart,
            ':end' => $periodEnd,
            ':cutoff' => $cutoffDate,
            ':pay' => $payDate,
            ':status' => 'active',
            ':locked' => false,
            ':notes' => 'Auto-generated standard Philippine payroll cutoff',
            ':created_by' => 1 // System user
        ]);
        
        echo "✅ CREATE: $periodName\n";
        echo "   Period: $periodStart to $periodEnd\n";
        echo "   Cutoff: $cutoffDate | Pay: $payDate\n\n";
        
        $createdCount++;
        
    } catch (Throwable $e) {
        echo "❌ ERROR: Failed to create $periodName\n";
        echo "   " . $e->getMessage() . "\n\n";
    }
}

echo "\n";
echo "=================================\n";
echo "Summary:\n";
echo "  Created: $createdCount periods\n";
echo "  Skipped: $skippedCount periods\n";
echo "=================================\n";

?>
