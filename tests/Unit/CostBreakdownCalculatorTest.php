<?php

namespace Tests\Unit;

use App\Support\CostBreakdownCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Every number here is confirmed against the real "TSD Payroll" sheet's
 * own raw CSV export (2026-09-29), not a screenshot read — see
 * CostBreakdownCalculator's own doc comment for the full formula chain and
 * the genuine off-by-one formula bug found (and deliberately NOT
 * replicated) in the real sheet's own per-TSA table starting at
 * "Consultants."
 */
class CostBreakdownCalculatorTest extends TestCase
{
    public function test_share_of_days_is_days_over_total_days(): void
    {
        // 12 TSAs, 30 days each = 360 total — the sheet's own real starting
        // state, giving every TSA an identical 8.33% (1/12) share.
        $this->assertEqualsWithDelta(1 / 12, CostBreakdownCalculator::shareOfDays(30, 360), 0.0001);
    }

    public function test_share_of_days_shifts_when_a_tsa_works_fewer_days(): void
    {
        // One TSA worked 15 days instead of 30 (11 others still at 30) —
        // her share drops, everyone else's share rises to compensate.
        $totalDays = 15 + (11 * 30);
        $this->assertEqualsWithDelta(15 / $totalDays, CostBreakdownCalculator::shareOfDays(15, $totalDays), 0.0001);
        $this->assertEqualsWithDelta(30 / $totalDays, CostBreakdownCalculator::shareOfDays(30, $totalDays), 0.0001);
    }

    public function test_share_of_days_is_zero_when_nobody_has_logged_any_days(): void
    {
        $this->assertSame(0.0, CostBreakdownCalculator::shareOfDays(0, 0));
    }

    /** Julie Francisco's real row, confirmed exact against the sheet's own
     *  raw CSV for every one of the first 11 columns (the sheet's own
     *  formula bug only starts AFTER Maintenance Expenses — this app's
     *  calculator never reproduces that bug for any column, confirmed
     *  below through the full 21). */
    public function test_row_for_share_matches_the_real_sheets_own_numbers(): void
    {
        $share = 30 / 360; // 8.33%, Julie Francisco's real share
        $pools = [
            'communication_allowance' => 500.42,
            'thirteenth_month_allowance' => 5801.19,
            'sil' => 2668.92,
            'government_benefits' => 5922.47,
            'miscellaneous_expenses' => 3760.38,
            'product_research' => 625.00,
            'magic_fund' => 416.67,
            'company_assets' => 2522.68,
            'executive_benefits' => 2416.67,
            'office_miscellaneous' => 1216.67,
            'maintenance_expenses' => 2241.67,
            'consultants' => 645.83,
            'managers_allowance' => 833.33,
            'birthday_cake_allowance' => 75.00,
            'water_bill' => 25.42,
            'internet' => 566.54,
            'rent' => 4706.25,
            'electricity' => 4500.00,
            'business_development_fund' => 2083.33,
            'geniusmakers_management_fee' => 4166.67,
            'hmo_expense' => 4642.75,
        ];

        $row = CostBreakdownCalculator::rowForShare($share, $pools);

        $this->assertEqualsWithDelta(41.70, $row['communication_allowance'], 0.01);
        $this->assertEqualsWithDelta(483.43, $row['thirteenth_month_allowance'], 0.01);
        $this->assertEqualsWithDelta(222.41, $row['sil'], 0.01);
        $this->assertEqualsWithDelta(493.54, $row['government_benefits'], 0.01);
        $this->assertEqualsWithDelta(313.37, $row['miscellaneous_expenses'], 0.01);
        $this->assertEqualsWithDelta(52.08, $row['product_research'], 0.01);
        $this->assertEqualsWithDelta(34.72, $row['magic_fund'], 0.01);
        $this->assertEqualsWithDelta(210.22, $row['company_assets'], 0.01);
        $this->assertEqualsWithDelta(201.39, $row['executive_benefits'], 0.01);
        $this->assertEqualsWithDelta(101.39, $row['office_miscellaneous'], 0.01);
        $this->assertEqualsWithDelta(186.81, $row['maintenance_expenses'], 0.01);
        // Own-pool values from here on (this app does NOT replicate the
        // real sheet's own off-by-one bug past Maintenance Expenses).
        $this->assertEqualsWithDelta(645.83 / 12, $row['consultants'], 0.01);
        $this->assertEqualsWithDelta(833.33 / 12, $row['managers_allowance'], 0.01);
        $this->assertEqualsWithDelta(75.00 / 12, $row['birthday_cake_allowance'], 0.01);
        $this->assertEqualsWithDelta(25.42 / 12, $row['water_bill'], 0.01);
        $this->assertEqualsWithDelta(566.54 / 12, $row['internet'], 0.01);
        $this->assertEqualsWithDelta(4706.25 / 12, $row['rent'], 0.01);
        $this->assertEqualsWithDelta(4500.00 / 12, $row['electricity'], 0.01);
        $this->assertEqualsWithDelta(2083.33 / 12, $row['business_development_fund'], 0.01);
        $this->assertEqualsWithDelta(4166.67 / 12, $row['geniusmakers_management_fee'], 0.01);
        $this->assertEqualsWithDelta(4642.75 / 12, $row['hmo_expense'], 0.01);
    }

    public function test_row_total_sums_every_pools_own_value(): void
    {
        $row = CostBreakdownCalculator::rowForShare(0.5, ['a' => 100, 'b' => 200]);

        $this->assertEqualsWithDelta(50.0, $row['a'], 0.01);
        $this->assertEqualsWithDelta(100.0, $row['b'], 0.01);
        $this->assertEqualsWithDelta(150.0, $row['total'], 0.01);
    }

    public function test_role_total_adds_the_shared_bonus_when_present(): void
    {
        // CEO — confirmed against the sheet's own raw CSV export, the
        // 10,341.13 bonus sits in the CEO's own row, not a neighboring one.
        $this->assertEqualsWithDelta(54436.72, CostBreakdownCalculator::roleTotal(44095.59, 10341.13), 0.01);
    }

    public function test_role_total_is_just_the_base_salary_when_there_is_no_bonus(): void
    {
        // Sales Director — confirmed no bonus column value for this role.
        $this->assertEqualsWithDelta(19998.00, CostBreakdownCalculator::roleTotal(19998.00, null), 0.01);
    }

    /** Every real TSA gets the exact same flat 20,888.75 bonus on top of
     *  her own base salary, confirmed across every one of the 12 real
     *  names in the sheet regardless of shift or base amount. */
    public function test_tsa_monthly_total_matches_the_real_sheet(): void
    {
        $this->assertEqualsWithDelta(40388.75, CostBreakdownCalculator::tsaMonthlyTotal(19500.00, 20888.75), 0.01);
        $this->assertEqualsWithDelta(38308.75, CostBreakdownCalculator::tsaMonthlyTotal(17420.00, 20888.75), 0.01);
        $this->assertEqualsWithDelta(36488.75, CostBreakdownCalculator::tsaMonthlyTotal(15600.00, 20888.75), 0.01);
    }

    public function test_tsa_daily_rate_divides_the_monthly_total_by_24(): void
    {
        $this->assertEqualsWithDelta(1682.86, CostBreakdownCalculator::tsaDailyRate(40388.75), 0.01);
        $this->assertEqualsWithDelta(1596.20, CostBreakdownCalculator::tsaDailyRate(38308.75), 0.01);
        $this->assertEqualsWithDelta(1520.36, CostBreakdownCalculator::tsaDailyRate(36488.75), 0.01);
    }
}
