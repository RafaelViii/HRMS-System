<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Comprehensive tests for payroll computation functions.
 *
 * All functions tested here are pure (no DB calls) and live in includes/payroll.php.
 * Covers: SSS, PhilHealth, Pag-IBIG, withholding tax (TRAIN law),
 *         rate calculations, profile overrides, overtime multiplier,
 *         weekday counting, and full payslip component assembly.
 */
class PayrollCalculationTest extends TestCase
{
    // =====================================================================
    // SSS MSC Resolution — payroll__resolve_sss_msc()
    // =====================================================================

    public function testSssMscZeroSalary(): void
    {
        $this->assertSame(0.0, payroll__resolve_sss_msc(0));
    }

    public function testSssMscNegativeSalary(): void
    {
        $this->assertSame(0.0, payroll__resolve_sss_msc(-5000));
    }

    public function testSssMscMinimumClamp(): void
    {
        // Salary of 1000 → ceil(1000/500)*500 = 2000, but min is 3250
        $this->assertSame(3250.0, payroll__resolve_sss_msc(1000));
    }

    public function testSssMscBelowMinimumEdge(): void
    {
        // Salary of 3000 → ceil(3000/500)*500 = 3000 < 3250 → clamped to 3250
        $this->assertSame(3250.0, payroll__resolve_sss_msc(3000));
    }

    public function testSssMscAtMinimum(): void
    {
        // Salary of 3250 → ceil(3250/500)*500 = 3500, which is > 3250
        $this->assertSame(3500.0, payroll__resolve_sss_msc(3250));
    }

    public function testSssMscExactMultipleOf500(): void
    {
        // Salary of 15000 → ceil(15000/500)*500 = 15000
        $this->assertSame(15000.0, payroll__resolve_sss_msc(15000));
    }

    public function testSssMscRoundsUpToNearest500(): void
    {
        // Salary of 15001 → ceil(15001/500)*500 = 15500
        $this->assertSame(15500.0, payroll__resolve_sss_msc(15001));
    }

    public function testSssMscMaximumClamp(): void
    {
        // Salary of 50000 → ceil(50000/500)*500 = 50000, but max is 35000
        $this->assertSame(35000.0, payroll__resolve_sss_msc(50000));
    }

    public function testSssMscAtMaximum(): void
    {
        // Salary of 35000 → ceil(35000/500)*500 = 35000 = max
        $this->assertSame(35000.0, payroll__resolve_sss_msc(35000));
    }

    public function testSssMscJustBelowMax(): void
    {
        // Salary of 34999 → ceil(34999/500)*500 = 35000
        $this->assertSame(35000.0, payroll__resolve_sss_msc(34999));
    }

    public function testSssMscMidRange(): void
    {
        // Salary of 20750 → ceil(20750/500)*500 = 21000
        $this->assertSame(21000.0, payroll__resolve_sss_msc(20750));
    }

    // =====================================================================
    // SSS Contribution — payroll_calculate_sss_contribution()
    // =====================================================================

    public function testSssContributionZeroSalary(): void
    {
        $result = payroll_calculate_sss_contribution(0);
        $this->assertSame(0.0, $result['monthly']);
        $this->assertSame(0.0, $result['per_period']);
        $this->assertSame(0, $result['details']['msc']);
    }

    public function testSssContributionMinimumSalary(): void
    {
        // MSC = 3250 (minimum)
        $result = payroll_calculate_sss_contribution(1000);
        $msc = 3250;
        $sss = round($msc * 0.045, 2); // 146.25
        $wisp = round($msc * 0.005, 2); // 16.25
        $monthly = round($sss + $wisp, 2); // 162.50
        $this->assertSame($monthly, $result['monthly']);
        $this->assertSame(round($monthly / 2, 2), $result['per_period']);
        $this->assertSame((float)$msc, $result['details']['msc']);
        $this->assertSame($sss, $result['details']['sss_employee']);
        $this->assertSame($wisp, $result['details']['wisp_employee']);
    }

    public function testSssContributionTypicalSalary(): void
    {
        // 25000 → MSC = 25000
        $result = payroll_calculate_sss_contribution(25000);
        $msc = 25000;
        $sss = round($msc * 0.045, 2); // 1125.00
        $wisp = round($msc * 0.005, 2); // 125.00
        $monthly = round($sss + $wisp, 2); // 1250.00
        $this->assertSame(1250.0, $result['monthly']);
        $this->assertSame(625.0, $result['per_period']);
    }

    public function testSssContributionMaxSalary(): void
    {
        // 100000 → MSC capped at 35000
        $result = payroll_calculate_sss_contribution(100000);
        $msc = 35000;
        $sss = round($msc * 0.045, 2); // 1575.00
        $wisp = round($msc * 0.005, 2); // 175.00
        $monthly = round($sss + $wisp, 2); // 1750.00
        $this->assertSame(1750.0, $result['monthly']);
        $this->assertSame(875.0, $result['per_period']);
        $this->assertSame(35000.0, $result['details']['msc']);
    }

    public function testSssContributionRatesAreCorrect(): void
    {
        // Verify 4.5% + 0.5% = 5% total employee share
        $result = payroll_calculate_sss_contribution(20000);
        $msc = 20000.0;
        $this->assertSame($msc, $result['details']['msc']);
        $this->assertSame(round($msc * 0.045, 2), $result['details']['sss_employee']);
        $this->assertSame(round($msc * 0.005, 2), $result['details']['wisp_employee']);
    }

    // =====================================================================
    // PhilHealth Contribution — payroll_calculate_philhealth_contribution()
    // =====================================================================

    public function testPhilhealthZeroSalary(): void
    {
        $result = payroll_calculate_philhealth_contribution(0);
        $this->assertSame(0.0, $result['monthly']);
        $this->assertSame(0.0, $result['per_period']);
    }

    public function testPhilhealthBelowMinimumBase(): void
    {
        // Salary 5000 → basis clamped to 10000
        $result = payroll_calculate_philhealth_contribution(5000);
        $basis = 10000;
        $premium = round($basis * 0.05, 2); // 500
        $employeeMonthly = round($premium / 2, 2); // 250
        $this->assertSame(250.0, $result['monthly']);
        $this->assertSame(125.0, $result['per_period']);
        $this->assertEquals(10000, $result['details']['basis']);
        $this->assertSame(500.0, $result['details']['premium']);
    }

    public function testPhilhealthAtMinimumBase(): void
    {
        $result = payroll_calculate_philhealth_contribution(10000);
        $this->assertEquals(10000, $result['details']['basis']);
        $this->assertSame(250.0, $result['monthly']);
    }

    public function testPhilhealthMidRange(): void
    {
        // Salary 30000 → basis = 30000 (within range)
        $result = payroll_calculate_philhealth_contribution(30000);
        $premium = round(30000 * 0.05, 2); // 1500
        $this->assertSame(30000.0, $result['details']['basis']);
        $this->assertSame(1500.0, $result['details']['premium']);
        $this->assertSame(750.0, $result['monthly']);
        $this->assertSame(375.0, $result['per_period']);
    }

    public function testPhilhealthAtMaximumBase(): void
    {
        $result = payroll_calculate_philhealth_contribution(90000);
        $this->assertSame(90000.0, $result['details']['basis']);
        $premium = round(90000 * 0.05, 2); // 4500
        $this->assertSame($premium, $result['details']['premium']);
    }

    public function testPhilhealthAboveMaximumBase(): void
    {
        // Salary 150000 → clamped to 90000
        $result = payroll_calculate_philhealth_contribution(150000);
        $this->assertEquals(90000, $result['details']['basis']);
        $premium = round(90000 * 0.05, 2); // 4500
        $this->assertSame($premium, $result['details']['premium']);
        $employeeMonthly = round($premium / 2, 2); // 2250
        $this->assertSame($employeeMonthly, $result['monthly']);
        $this->assertSame(round($employeeMonthly / 2, 2), $result['per_period']);
    }

    public function testPhilhealthPremiumIs5Percent(): void
    {
        // Verify the 5% premium rate
        $salary = 50000;
        $result = payroll_calculate_philhealth_contribution($salary);
        $this->assertSame(50000.0, $result['details']['basis']);
        $this->assertSame(2500.0, $result['details']['premium']); // 5% of 50k
        $this->assertSame(1250.0, $result['monthly']); // employee half
    }

    // =====================================================================
    // Pag-IBIG Contribution — payroll_calculate_pagibig_contribution()
    // =====================================================================

    public function testPagibigZeroSalary(): void
    {
        $result = payroll_calculate_pagibig_contribution(0);
        $this->assertSame(0.0, $result['monthly']);
        $this->assertSame(0.0, $result['per_period']);
    }

    public function testPagibigLowSalary(): void
    {
        // 2% of 3000 = 60 (under 100 cap)
        $result = payroll_calculate_pagibig_contribution(3000);
        $this->assertSame(60.0, $result['monthly']);
        $this->assertSame(60.0, $result['per_period']); // full monthly taken each pay run
    }

    public function testPagibigAtCapBoundary(): void
    {
        // 2% of 5000 = 100 (exactly at cap)
        $result = payroll_calculate_pagibig_contribution(5000);
        $this->assertSame(100.0, $result['monthly']);
        $this->assertSame(100.0, $result['per_period']);
    }

    public function testPagibigAboveCap(): void
    {
        // 2% of 20000 = 400, but capped at 100
        $result = payroll_calculate_pagibig_contribution(20000);
        $this->assertSame(100.0, $result['monthly']);
        $this->assertSame(100.0, $result['per_period']);
    }

    public function testPagibigHighSalary(): void
    {
        // 2% of 100000 = 2000, but capped at 100
        $result = payroll_calculate_pagibig_contribution(100000);
        $this->assertSame(100.0, $result['monthly']);
    }

    public function testPagibigRateIs2Percent(): void
    {
        $result = payroll_calculate_pagibig_contribution(3000);
        $this->assertSame(0.02, $result['details']['rate']);
    }

    public function testPagibigPerPeriodEqualsMonthly(): void
    {
        // Per spec: employer policy takes full monthly each pay run
        $result = payroll_calculate_pagibig_contribution(4000);
        $this->assertSame($result['monthly'], $result['per_period']);
    }

    // =====================================================================
    // Withholding Tax — TRAIN Law Brackets
    // payroll__compute_annual_withholding_tax()
    // =====================================================================

    public function testAnnualTaxZeroIncome(): void
    {
        $this->assertSame(0.0, payroll__compute_annual_withholding_tax(0));
    }

    public function testAnnualTaxNegativeIncome(): void
    {
        $this->assertSame(0.0, payroll__compute_annual_withholding_tax(-10000));
    }

    public function testAnnualTaxBracket1Under250k(): void
    {
        // ≤250,000 → 0%
        $this->assertSame(0.0, payroll__compute_annual_withholding_tax(200000));
        $this->assertSame(0.0, payroll__compute_annual_withholding_tax(250000));
    }

    public function testAnnualTaxBracket2Boundary(): void
    {
        // 250,001 to 400,000 → 15% of excess over 250k
        $tax = (250001 - 250000) * 0.15;
        $this->assertEqualsWithDelta($tax, payroll__compute_annual_withholding_tax(250001), 0.01);
    }

    public function testAnnualTaxBracket2At400k(): void
    {
        // At 400,000: (400000 - 250000) * 0.15 = 22,500
        $this->assertEqualsWithDelta(22500.0, payroll__compute_annual_withholding_tax(400000), 0.01);
    }

    public function testAnnualTaxBracket3(): void
    {
        // 400,001 to 800,000 → 22,500 + 20% of excess over 400k
        $salary = 600000;
        $expected = 22500 + (600000 - 400000) * 0.20;
        $this->assertEqualsWithDelta($expected, payroll__compute_annual_withholding_tax($salary), 0.01);
    }

    public function testAnnualTaxBracket3At800k(): void
    {
        $expected = 22500 + (800000 - 400000) * 0.20; // 22500 + 80000 = 102500
        $this->assertEqualsWithDelta(102500.0, payroll__compute_annual_withholding_tax(800000), 0.01);
    }

    public function testAnnualTaxBracket4(): void
    {
        // 800,001 to 2,000,000 → 102,500 + 25% of excess over 800k
        $salary = 1500000;
        $expected = 102500 + (1500000 - 800000) * 0.25;
        $this->assertEqualsWithDelta($expected, payroll__compute_annual_withholding_tax($salary), 0.01);
    }

    public function testAnnualTaxBracket4At2M(): void
    {
        $expected = 102500 + (2000000 - 800000) * 0.25; // 102500 + 300000 = 402500
        $this->assertEqualsWithDelta(402500.0, payroll__compute_annual_withholding_tax(2000000), 0.01);
    }

    public function testAnnualTaxBracket5(): void
    {
        // 2,000,001 to 8,000,000 → 402,500 + 30% of excess over 2M
        $salary = 5000000;
        $expected = 402500 + (5000000 - 2000000) * 0.30;
        $this->assertEqualsWithDelta($expected, payroll__compute_annual_withholding_tax($salary), 0.01);
    }

    public function testAnnualTaxBracket5At8M(): void
    {
        $expected = 402500 + (8000000 - 2000000) * 0.30; // 402500 + 1800000 = 2202500
        $this->assertEqualsWithDelta(2202500.0, payroll__compute_annual_withholding_tax(8000000), 0.01);
    }

    public function testAnnualTaxBracket6Over8M(): void
    {
        // >8,000,000 → 2,202,500 + 35% of excess over 8M
        $salary = 10000000;
        $expected = 2202500 + (10000000 - 8000000) * 0.35;
        $this->assertEqualsWithDelta($expected, payroll__compute_annual_withholding_tax($salary), 0.01);
    }

    // =====================================================================
    // Withholding Tax with Deductions — payroll_calculate_withholding_tax()
    // =====================================================================

    public function testWithholdingTaxBasic(): void
    {
        $salary = 25000;
        $sss = payroll_calculate_sss_contribution($salary)['monthly'];
        $phil = payroll_calculate_philhealth_contribution($salary)['monthly'];
        $pagibig = payroll_calculate_pagibig_contribution($salary)['monthly'];

        $result = payroll_calculate_withholding_tax($salary, $sss, $phil, $pagibig);

        // Verify structure
        $this->assertArrayHasKey('annual', $result);
        $this->assertArrayHasKey('monthly', $result);
        $this->assertArrayHasKey('per_period', $result);
        $this->assertArrayHasKey('details', $result);
        $this->assertFalse($result['override_applied']);

        // Taxable = salary - contributions
        $taxableMonthly = max(0, $salary - ($sss + $phil + $pagibig));
        $this->assertEqualsWithDelta($taxableMonthly, $result['details']['taxable_monthly'], 0.01);
    }

    public function testWithholdingTaxPercentageOverride(): void
    {
        $salary = 30000;
        $result = payroll_calculate_withholding_tax($salary, 0, 0, 0, 10.0);

        $this->assertTrue($result['override_applied']);
        $expectedMonthly = round($salary * 0.10, 2); // 3000
        $this->assertEqualsWithDelta($expectedMonthly, $result['monthly'], 0.01);
        $this->assertEqualsWithDelta(round($expectedMonthly / 2, 2), $result['per_period'], 0.01);
    }

    public function testWithholdingTaxZeroSalary(): void
    {
        $result = payroll_calculate_withholding_tax(0, 0, 0, 0);
        $this->assertSame(0.0, $result['annual']);
        $this->assertEqualsWithDelta(0.0, $result['monthly'], 0.01);
    }

    public function testWithholdingTaxPerPeriodIsAnnualDividedBy24(): void
    {
        $salary = 50000;
        $result = payroll_calculate_withholding_tax($salary, 1750, 2250, 100);
        $this->assertEqualsWithDelta(round($result['annual'] / 24, 2), $result['per_period'], 0.01);
    }

    public function testWithholdingTaxBelowThreshold(): void
    {
        // Very low salary that produces annual taxable ≤ 250k → 0 tax
        $salary = 15000; // 15k/mo → annual taxable ~180k after deductions → bracket 1 (0%)
        $sss = payroll_calculate_sss_contribution($salary)['monthly'];
        $phil = payroll_calculate_philhealth_contribution($salary)['monthly'];
        $pagibig = payroll_calculate_pagibig_contribution($salary)['monthly'];
        $result = payroll_calculate_withholding_tax($salary, $sss, $phil, $pagibig);

        $taxableMonthly = $salary - ($sss + $phil + $pagibig);
        $annualTaxable = $taxableMonthly * 12;
        if ($annualTaxable <= 250000) {
            $this->assertEqualsWithDelta(0.0, $result['annual'], 0.01);
        }
    }

    public function testWithholdingTaxOverrideClampedTo100(): void
    {
        // Override percentage should be clamped between 0 and 100
        $result = payroll_calculate_withholding_tax(30000, 0, 0, 0, 150.0);
        // Clamped to 100%, so monthly = salary * 1.0 = 30000
        $this->assertEqualsWithDelta(30000.0, $result['monthly'], 0.01);
    }

    // =====================================================================
    // Rate Calculations — payroll_calculate_rates()
    // =====================================================================

    public function testRatesBasicCalculation(): void
    {
        $employee = ['salary' => 22000, 'position_base_salary' => 0];
        $rates = payroll_calculate_rates($employee);

        $this->assertSame(22000.0, $rates['monthly']);
        $this->assertSame(11000.0, $rates['bi_monthly']);
        $this->assertSame(22, $rates['working_days_per_month']);
        $this->assertSame(8, $rates['hours_per_day']);

        // daily = 22000 / 22 = 1000
        $this->assertSame(1000.0, $rates['daily']);
        // hourly = 1000 / 8 = 125
        $this->assertSame(125.0, $rates['hourly']);
        // per_minute = 125 / 60
        $this->assertEqualsWithDelta(2.083, $rates['per_minute'], 0.001);
    }

    public function testRatesFallsBackToPositionSalary(): void
    {
        $employee = ['salary' => 0, 'position_base_salary' => 30000];
        $rates = payroll_calculate_rates($employee);
        $this->assertSame(30000.0, $rates['monthly']);
    }

    public function testRatesEmployeeSalaryTakesPrecedence(): void
    {
        $employee = ['salary' => 25000, 'position_base_salary' => 30000];
        $rates = payroll_calculate_rates($employee);
        $this->assertSame(25000.0, $rates['monthly']);
    }

    public function testRatesZeroSalary(): void
    {
        $employee = ['salary' => 0, 'position_base_salary' => 0];
        $rates = payroll_calculate_rates($employee);
        $this->assertSame(0.0, $rates['monthly']);
        $this->assertSame(0.0, $rates['daily']);
        $this->assertSame(0.0, $rates['hourly']);
    }

    public function testRatesCustomWorkingDays(): void
    {
        $employee = ['salary' => 26000];
        $rates = payroll_calculate_rates($employee, [], ['working_days_per_month' => 26]);
        $this->assertSame(26, $rates['working_days_per_month']);
        $this->assertSame(1000.0, $rates['daily']); // 26000 / 26
    }

    public function testRatesCustomHoursPerDay(): void
    {
        $employee = ['salary' => 22000];
        $rates = payroll_calculate_rates($employee, [], ['hours_per_day' => 10]);
        $this->assertSame(10, $rates['hours_per_day']);
        // daily = 22000/22 = 1000, hourly = 1000/10 = 100
        $this->assertSame(100.0, $rates['hourly']);
    }

    public function testRatesWithRateConfigs(): void
    {
        $employee = ['salary' => 22000];
        $rateConfigs = [
            'working_days_per_month' => ['override_value' => null, 'default_value' => 20],
            'hours_per_day' => ['override_value' => null, 'default_value' => 8],
        ];
        $rates = payroll_calculate_rates($employee, $rateConfigs);
        $this->assertSame(20, $rates['working_days_per_month']);
        $this->assertSame(1100.0, $rates['daily']); // 22000 / 20
    }

    public function testRatesBiMonthlyIsHalfOfMonthly(): void
    {
        $employee = ['salary' => 33333];
        $rates = payroll_calculate_rates($employee);
        $this->assertSame(round(33333 / 2, 2), $rates['bi_monthly']);
    }

    // =====================================================================
    // Profile Rate Overrides — payroll_apply_profile_rate_overrides()
    // =====================================================================

    public function testProfileOverridesNull(): void
    {
        $rates = ['hourly' => 125.0, 'daily' => 1000.0, 'per_minute' => 2.083, 'hours_per_day' => 8];
        $result = payroll_apply_profile_rate_overrides($rates, null);
        $this->assertSame(125.0, $result['hourly']);
        $this->assertSame(1000.0, $result['daily']);
    }

    public function testProfileOverridesCustomHourlyRate(): void
    {
        $rates = ['hourly' => 125.0, 'daily' => 1000.0, 'per_minute' => 2.083, 'hours_per_day' => 8];
        $overrides = ['custom_hourly_rate' => 150];
        $result = payroll_apply_profile_rate_overrides($rates, $overrides);
        $this->assertSame(150.0, $result['hourly']);
        $this->assertSame(1200.0, $result['daily']); // 150 * 8
        $this->assertEqualsWithDelta(2.5, $result['per_minute'], 0.001); // 150 / 60
    }

    public function testProfileOverridesCustomDailyRate(): void
    {
        $rates = ['hourly' => 125.0, 'daily' => 1000.0, 'per_minute' => 2.083, 'hours_per_day' => 8];
        $overrides = ['custom_daily_rate' => 1500];
        $result = payroll_apply_profile_rate_overrides($rates, $overrides);
        $this->assertSame(1500.0, $result['daily']);
        $this->assertSame(187.5, $result['hourly']); // 1500 / 8
    }

    public function testProfileOverridesHourlyTakesPrecedenceOverDaily(): void
    {
        $rates = ['hourly' => 125.0, 'daily' => 1000.0, 'per_minute' => 2.083, 'hours_per_day' => 8];
        $overrides = ['custom_hourly_rate' => 200, 'custom_daily_rate' => 1500];
        $result = payroll_apply_profile_rate_overrides($rates, $overrides);
        // Hourly should win
        $this->assertSame(200.0, $result['hourly']);
        $this->assertSame(1600.0, $result['daily']); // 200 * 8, not 1500
    }

    public function testProfileOverridesCustomHoursPerDay(): void
    {
        $rates = ['hourly' => 125.0, 'daily' => 1000.0, 'per_minute' => 2.083, 'hours_per_day' => 8];
        $overrides = ['custom_hourly_rate' => 100, 'hours_per_day' => 10];
        $result = payroll_apply_profile_rate_overrides($rates, $overrides);
        $this->assertSame(100.0, $result['hourly']);
        $this->assertSame(1000.0, $result['daily']); // 100 * 10
    }

    public function testProfileOverridesZeroHourlyRateIgnored(): void
    {
        $rates = ['hourly' => 125.0, 'daily' => 1000.0, 'per_minute' => 2.083, 'hours_per_day' => 8];
        $overrides = ['custom_hourly_rate' => 0];
        $result = payroll_apply_profile_rate_overrides($rates, $overrides);
        // Zero rate should be ignored
        $this->assertSame(125.0, $result['hourly']);
    }

    // =====================================================================
    // Overtime Multiplier — payroll_resolve_overtime_multiplier()
    // =====================================================================

    public function testOvertimeMultiplierDefault(): void
    {
        $this->assertSame(1.25, payroll_resolve_overtime_multiplier());
    }

    public function testOvertimeMultiplierNull(): void
    {
        $this->assertSame(1.25, payroll_resolve_overtime_multiplier(null));
    }

    public function testOvertimeMultiplierCustom(): void
    {
        $overrides = ['overtime_multiplier' => 1.5];
        $this->assertSame(1.5, payroll_resolve_overtime_multiplier($overrides));
    }

    public function testOvertimeMultiplierZeroFallsBackToDefault(): void
    {
        $overrides = ['overtime_multiplier' => 0];
        $this->assertSame(1.25, payroll_resolve_overtime_multiplier($overrides));
    }

    public function testOvertimeMultiplierNegativeFallsBackToDefault(): void
    {
        $overrides = ['overtime_multiplier' => -2.0];
        $this->assertSame(1.25, payroll_resolve_overtime_multiplier($overrides));
    }

    public function testOvertimeMultiplierNoKey(): void
    {
        $overrides = ['some_other_key' => 'value'];
        $this->assertSame(1.25, payroll_resolve_overtime_multiplier($overrides));
    }

    public function testOvertimeMultiplierRoundedTo3Decimals(): void
    {
        $overrides = ['overtime_multiplier' => 1.33333];
        $this->assertSame(1.333, payroll_resolve_overtime_multiplier($overrides));
    }

    // =====================================================================
    // Weekday Counter — payroll__count_weekdays()
    // =====================================================================

    public function testCountWeekdaysSingleMonday(): void
    {
        // 2026-02-09 is a Monday
        $this->assertSame(1, payroll__count_weekdays('2026-02-09', '2026-02-09'));
    }

    public function testCountWeekdaysSingleSaturday(): void
    {
        // 2026-02-07 is a Saturday
        $this->assertSame(0, payroll__count_weekdays('2026-02-07', '2026-02-07'));
    }

    public function testCountWeekdaysSingleSunday(): void
    {
        // 2026-02-08 is a Sunday
        $this->assertSame(0, payroll__count_weekdays('2026-02-08', '2026-02-08'));
    }

    public function testCountWeekdaysFullWeek(): void
    {
        // Mon 2026-02-09 to Sun 2026-02-15 → 5 weekdays
        $this->assertSame(5, payroll__count_weekdays('2026-02-09', '2026-02-15'));
    }

    public function testCountWeekdaysTwoWeeks(): void
    {
        // Mon 2026-02-09 to Fri 2026-02-20 → 10 weekdays
        $this->assertSame(10, payroll__count_weekdays('2026-02-09', '2026-02-20'));
    }

    public function testCountWeekdaysEndBeforeStart(): void
    {
        $this->assertSame(0, payroll__count_weekdays('2026-02-15', '2026-02-09'));
    }

    public function testCountWeekdaysSameWeekend(): void
    {
        // Sat to Sun
        $this->assertSame(0, payroll__count_weekdays('2026-02-07', '2026-02-08'));
    }

    public function testCountWeekdaysTypicalPayPeriod(): void
    {
        // Feb 1-15, 2026 → Sun Feb 1 to Sun Feb 15
        // Need to count: Mon 2, Tue 3, Wed 4, Thu 5, Fri 6, Mon 9, Tue 10, Wed 11, Thu 12, Fri 13 = 10
        $this->assertSame(10, payroll__count_weekdays('2026-02-01', '2026-02-15'));
    }

    public function testCountWeekdaysInvalidDates(): void
    {
        $this->assertSame(0, payroll__count_weekdays('invalid', '2026-02-15'));
    }

    // =====================================================================
    // Full Payslip Components — payroll_compute_payslip_components()
    // =====================================================================

    public function testPayslipComponentsBasicStructure(): void
    {
        $employee = [
            'id' => 1,
            'salary' => 25000,
            'position_base_salary' => 0,
        ];
        $settings = [
            'rate_computation_defaults' => [
                'config' => [
                    'working_days_per_month' => 22,
                    'hours_per_day' => 8,
                ],
            ],
        ];

        $result = payroll_compute_payslip_components(
            $employee,
            $settings,
            '2026-02-01',
            '2026-02-15'
        );

        // Verify the return structure
        $this->assertArrayHasKey('earnings', $result);
        $this->assertArrayHasKey('deductions', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('meta', $result);

        // Totals should have gross, deductions, net
        $this->assertArrayHasKey('gross', $result['totals']);
        $this->assertArrayHasKey('deductions', $result['totals']);
        $this->assertArrayHasKey('net', $result['totals']);

        // net = gross - deductions
        $this->assertEqualsWithDelta(
            $result['totals']['gross'] - $result['totals']['deductions'],
            $result['totals']['net'],
            0.01
        );
    }

    public function testPayslipComponentsBasicPayIsBiMonthly(): void
    {
        $employee = ['id' => 1, 'salary' => 30000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $result = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');

        // First earning should be BASIC pay
        $basicEarning = $result['earnings'][0] ?? null;
        $this->assertNotNull($basicEarning);
        $this->assertSame('BASIC', $basicEarning['code']);
        $this->assertSame(15000.0, $basicEarning['amount']); // 30000 / 2
    }

    public function testPayslipComponentsWithAllowances(): void
    {
        $employee = ['id' => 1, 'salary' => 25000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];
        $compProfile = [
            'allowances' => [
                ['code' => 'RICE', 'label' => 'Rice Allowance', 'amount' => 2000],
                ['code' => 'TRANS', 'label' => 'Transportation', 'amount' => 1500],
            ],
            'deductions' => [],
        ];

        $result = payroll_compute_payslip_components(
            $employee, $settings, '2026-02-01', '2026-02-15',
            [], [], $compProfile
        );

        // Should have BASIC + 2 allowances = 3 earnings
        $earningCodes = array_column($result['earnings'], 'code');
        $this->assertContains('BASIC', $earningCodes);
        $this->assertContains('RICE', $earningCodes);
        $this->assertContains('TRANS', $earningCodes);

        // Gross should include allowances
        $totalEarnings = array_sum(array_column($result['earnings'], 'amount'));
        $this->assertEqualsWithDelta($result['totals']['gross'], $totalEarnings, 0.01);
    }

    public function testPayslipComponentsWithCustomDeductions(): void
    {
        $employee = ['id' => 1, 'salary' => 25000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];
        $compProfile = [
            'allowances' => [],
            'deductions' => [
                ['code' => 'LOAN', 'label' => 'SSS Loan', 'amount' => 500],
            ],
        ];

        $result = payroll_compute_payslip_components(
            $employee, $settings, '2026-02-01', '2026-02-15',
            [], [], $compProfile
        );

        $deductionCodes = array_column($result['deductions'], 'code');
        $this->assertContains('LOAN', $deductionCodes);
    }

    public function testPayslipComponentsStatutoryDeductions(): void
    {
        $employee = ['id' => 1, 'salary' => 25000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $result = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');

        // Verify statutory deductions are present
        $deductionCodes = array_column($result['deductions'], 'code');
        $this->assertContains('SSS', $deductionCodes);
        $this->assertContains('PHIC', $deductionCodes);
        $this->assertContains('HDMF', $deductionCodes);
        $this->assertContains('TAX', $deductionCodes);
    }

    public function testPayslipComponentsStatutoryAmountsMatchStandalone(): void
    {
        $salary = 30000;
        $employee = ['id' => 1, 'salary' => $salary, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $result = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');

        // Get individual deductions from result
        $deductionsByCode = [];
        foreach ($result['deductions'] as $d) {
            $deductionsByCode[$d['code']] = $d['amount'];
        }

        // Compare with standalone calculations (per_period = semi-monthly)
        $sss = payroll_calculate_sss_contribution($salary);
        $phil = payroll_calculate_philhealth_contribution($salary);
        $pagibig = payroll_calculate_pagibig_contribution($salary);

        $this->assertEqualsWithDelta($sss['per_period'], $deductionsByCode['SSS'] ?? 0, 0.01, 'SSS per_period mismatch');
        $this->assertEqualsWithDelta($phil['per_period'], $deductionsByCode['PHIC'] ?? 0, 0.01, 'PhilHealth per_period mismatch');
        $this->assertEqualsWithDelta($pagibig['per_period'], $deductionsByCode['HDMF'] ?? 0, 0.01, 'Pag-IBIG per_period mismatch');
    }

    public function testPayslipComponentsNetPayCalculation(): void
    {
        $employee = ['id' => 1, 'salary' => 25000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $result = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');

        $gross = $result['totals']['gross'];
        $deductions = $result['totals']['deductions'];
        $net = $result['totals']['net'];

        $this->assertGreaterThan(0, $gross);
        $this->assertGreaterThan(0, $deductions);
        $this->assertEqualsWithDelta($gross - $deductions, $net, 0.01);
    }

    public function testPayslipComponentsZeroSalary(): void
    {
        $employee = ['id' => 1, 'salary' => 0, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $result = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');

        $this->assertEqualsWithDelta(0.0, $result['totals']['gross'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['totals']['net'], 0.01);
    }

    public function testPayslipComponentsWithTaxOverride(): void
    {
        $employee = ['id' => 1, 'salary' => 50000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];
        $compProfile = [
            'allowances' => [],
            'deductions' => [],
            'tax_percentage' => ['value' => 5.0, 'source' => 'employee_override'],
        ];

        $result = payroll_compute_payslip_components(
            $employee, $settings, '2026-02-01', '2026-02-15',
            [], [], $compProfile
        );

        // TAX deduction should reflect the 5% override
        $taxDeduction = null;
        foreach ($result['deductions'] as $d) {
            if ($d['code'] === 'TAX') {
                $taxDeduction = $d;
                break;
            }
        }
        $this->assertNotNull($taxDeduction, 'TAX deduction should exist');
    }

    public function testPayslipComponentsWithOvertimeSummary(): void
    {
        $employee = ['id' => 1, 'salary' => 22000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        // 2 hours of approved overtime at default 1.25x
        // hourly = 22000/22/8 = 125, OT per hour = 125 * 1.25 = 156.25
        $overtimeSummary = [
            'total_hours' => 2.0,
            'total_amount' => 312.50, // 2 * 156.25
            'multiplier' => 1.25,
            'base_hourly_rate' => 125.0,
            'requests' => [
                ['id' => 1, 'hours' => 2.0, 'amount' => 312.50],
            ],
        ];

        $result = payroll_compute_payslip_components(
            $employee, $settings, '2026-02-01', '2026-02-15',
            [], [], null, null, $overtimeSummary
        );

        // Should have an overtime earning
        $otEarning = null;
        foreach ($result['earnings'] as $e) {
            if (($e['code'] ?? '') === 'OT' || stripos($e['label'] ?? '', 'overtime') !== false) {
                $otEarning = $e;
                break;
            }
        }
        // If overtime summary is provided, it should appear in earnings
        if ($overtimeSummary['total_amount'] > 0) {
            $this->assertNotNull($otEarning, 'Overtime earning should exist');
            $this->assertEqualsWithDelta(312.50, $otEarning['amount'], 0.01);
        }
    }

    public function testPayslipComponentsWithQueuedAdjustments(): void
    {
        $employee = ['id' => 1, 'salary' => 25000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $adjustments = [
            ['adjustment_type' => 'earning', 'code' => 'BONUS', 'label' => 'Performance Bonus', 'amount' => 5000],
            ['adjustment_type' => 'deduction', 'code' => 'CASH_ADV', 'label' => 'Cash Advance', 'amount' => 2000],
        ];

        $result = payroll_compute_payslip_components(
            $employee, $settings, '2026-02-01', '2026-02-15',
            [], [], null, null, [], $adjustments
        );

        $earningCodes = array_column($result['earnings'], 'code');
        $deductionCodes = array_column($result['deductions'], 'code');

        $this->assertContains('BONUS', $earningCodes);
        $this->assertContains('CASH_ADV', $deductionCodes);
    }

    public function testPayslipComponentsMetaContainsRates(): void
    {
        $employee = ['id' => 1, 'salary' => 25000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $result = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');

        $this->assertArrayHasKey('meta', $result);
        $meta = $result['meta'];
        $this->assertArrayHasKey('rates', $meta);
        $this->assertArrayHasKey('monthly', $meta['rates']);
        $this->assertSame(25000.0, $meta['rates']['monthly']);
    }

    public function testPayslipComponentsWithProfileOverrides(): void
    {
        $employee = ['id' => 1, 'salary' => 22000, 'position_base_salary' => 0];
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];

        $profileOverrides = [
            'custom_hourly_rate' => 150, // Override default ~125/hr
        ];

        $result = payroll_compute_payslip_components(
            $employee, $settings, '2026-02-01', '2026-02-15',
            [], [], null, $profileOverrides
        );

        // The rates in meta should reflect the override
        $this->assertSame(150.0, $result['meta']['rates']['hourly']);
    }

    // =====================================================================
    // Integration: Full Payroll Flow for Typical Employee
    // =====================================================================

    public function testFullPayrollFlowTypicalEmployee(): void
    {
        $salary = 25000;

        // Step 1: Calculate rates
        $employee = ['salary' => $salary, 'position_base_salary' => 0];
        $rates = payroll_calculate_rates($employee);
        $this->assertSame(25000.0, $rates['monthly']);
        $this->assertSame(12500.0, $rates['bi_monthly']);

        // Step 2: SSS
        $sss = payroll_calculate_sss_contribution($salary);
        $this->assertSame(25000.0, $sss['details']['msc']); // 25000 exact multiple of 500
        $this->assertSame(round(25000 * 0.045, 2), $sss['details']['sss_employee']);

        // Step 3: PhilHealth
        $phil = payroll_calculate_philhealth_contribution($salary);
        $this->assertSame(25000.0, $phil['details']['basis']);

        // Step 4: Pag-IBIG
        $pagibig = payroll_calculate_pagibig_contribution($salary);
        $this->assertSame(100.0, $pagibig['monthly']); // 2% of 25000 = 500, capped at 100

        // Step 5: Tax
        $tax = payroll_calculate_withholding_tax($salary, $sss['monthly'], $phil['monthly'], $pagibig['monthly']);
        $taxableMonthly = $salary - ($sss['monthly'] + $phil['monthly'] + $pagibig['monthly']);
        $this->assertEqualsWithDelta($taxableMonthly, $tax['details']['taxable_monthly'], 0.01);
        $this->assertFalse($tax['override_applied']);

        // Step 6: Full payslip
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];
        $payslip = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');

        // Verify all pieces add up
        $this->assertGreaterThan(0, $payslip['totals']['net']);
        $this->assertEqualsWithDelta(
            $payslip['totals']['gross'] - $payslip['totals']['deductions'],
            $payslip['totals']['net'],
            0.01
        );
    }

    public function testFullPayrollFlowHighEarner(): void
    {
        $salary = 100000;
        $employee = ['salary' => $salary, 'position_base_salary' => 0];

        // SSS capped at 35000 MSC
        $sss = payroll_calculate_sss_contribution($salary);
        $this->assertSame(35000.0, $sss['details']['msc']);

        // PhilHealth capped at 90000 basis
        $phil = payroll_calculate_philhealth_contribution($salary);
        $this->assertEquals(90000, $phil['details']['basis']);

        // Pag-IBIG capped at 100
        $pagibig = payroll_calculate_pagibig_contribution($salary);
        $this->assertSame(100.0, $pagibig['monthly']);

        // Tax should be in a higher bracket
        $tax = payroll_calculate_withholding_tax($salary, $sss['monthly'], $phil['monthly'], $pagibig['monthly']);
        $this->assertGreaterThan(0, $tax['annual']);
        $taxableAnnual = $tax['details']['taxable_annual'];
        $this->assertGreaterThan(250000, $taxableAnnual); // Should be taxable

        // Full payslip
        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];
        $payslip = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');
        $this->assertGreaterThan(0, $payslip['totals']['net']);
    }

    public function testFullPayrollFlowMinimumWage(): void
    {
        $salary = 12000; // Low salary
        $employee = ['salary' => $salary, 'position_base_salary' => 0];

        $sss = payroll_calculate_sss_contribution($salary);
        $this->assertSame(12000.0, $sss['details']['msc']); // ceil(12000/500)*500 = 12000

        $phil = payroll_calculate_philhealth_contribution($salary);
        $this->assertSame(12000.0, $phil['details']['basis']); // Above 10k min

        $pagibig = payroll_calculate_pagibig_contribution($salary);
        $this->assertSame(100.0, $pagibig['monthly']); // 2% of 12000 = 240, capped at 100

        // Tax should be 0 (annual taxable well under 250k)
        $tax = payroll_calculate_withholding_tax($salary, $sss['monthly'], $phil['monthly'], $pagibig['monthly']);
        $this->assertEqualsWithDelta(0.0, $tax['annual'], 0.01, 'Minimum wage earner should have 0 tax');

        $settings = ['rate_computation_defaults' => ['config' => ['working_days_per_month' => 22, 'hours_per_day' => 8]]];
        $payslip = payroll_compute_payslip_components($employee, $settings, '2026-02-01', '2026-02-15');
        $this->assertGreaterThan(0, $payslip['totals']['net']);
    }
}
