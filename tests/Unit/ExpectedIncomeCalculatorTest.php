<?php

namespace Tests\Unit;

use App\Support\ExpectedIncomeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Conversion Rate, Gross Sales, Cancelled, Returns, and Delivered are
 * confirmed EXACT against the real "EXPECTED INCOME 2026" tab's own
 * Clearsight row (Number of Leads 647, Conversion Rate 17.31%, Number of
 * Orders 112, Average Order Value 804.46, Gross Sales 90,100.00, Cancelled
 * 4,505.00/5%, Projected Returns 22,525.00/25%, Projected Delivered
 * 63,070.00/70% — all matched to the real sheet exactly). Tax Allocation
 * and Product Cost are manual entry, NOT derived — verified during
 * development that Tax Allocation repeats the identical peso figure
 * (7,465.28) across unrelated products (a fixed pool, not a %-of-Gross-
 * Sales rate) and Product Cost varies uniquely per product with no common
 * rate at all, so neither could be trusted as a formula. Team Eyecare's own
 * sum (Gross Sales 518,795.00 = Clearsight 90,100 + Pterygium 377,497 +
 * Taguro Oil 51,198, confirmed exact) verifies sum()'s own dollar-summing.
 */
class ExpectedIncomeCalculatorTest extends TestCase
{
    private const CLEARSIGHT = [
        'number_of_leads' => 647,
        'number_of_orders' => 112,
        'average_order_value' => 804.46,
        'tax_allocation' => 7465.28,
        'product_cost' => 7454.00,
    ];

    public function test_conversion_rate_matches_the_real_sheet_exactly(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(0.1731, $d['conversion_rate'], 0.001);
    }

    public function test_gross_sales_matches_the_real_sheet_exactly(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(90100.00, $d['gross_sales'], 1.0);
    }

    public function test_cancelled_returns_delivered_match_the_real_sheet_exactly(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(4505.00, $d['cancelled'], 1.0);
        $this->assertEqualsWithDelta(22525.00, $d['returns'], 1.0);
        $this->assertEqualsWithDelta(63070.00, $d['delivered'], 1.0);
    }

    public function test_gross_profit_subtracts_cancelled_returns_tax_and_product_cost_only(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        // Delivered is informational only, never subtracted — same
        // convention as ProjectionCalculator's own gross_profit.
        $expected = $d['gross_sales'] - $d['cancelled'] - $d['returns'] - $d['tax_allocation'] - $d['product_cost'];
        $this->assertEqualsWithDelta($expected, $d['gross_profit'], 0.01);
    }

    public function test_cod_fee_and_fulfillment_fee_use_projections_own_formulas(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta($d['delivered'] * 0.0224, $d['selling_lines']['cod_fee'], 0.01);
        $this->assertEqualsWithDelta(112 * 25.0, $d['selling_lines']['fulfillment_fee'], 0.01);
    }

    public function test_a_row_with_zero_leads_does_not_divide_by_zero(): void
    {
        $d = ExpectedIncomeCalculator::derive([]);

        $this->assertSame(0.0, $d['conversion_rate']);
        $this->assertSame(0.0, $d['gross_sales']);
        $this->assertSame(0.0, $d['net_income']);
    }

    public function test_sum_matches_team_eyecare_against_the_real_sheet_exactly(): void
    {
        $clearsight = self::CLEARSIGHT;
        $pterygium = [
            'number_of_leads' => 1767, 'number_of_orders' => 433, 'average_order_value' => 871.82,
            'tax_allocation' => 7465.28, 'product_cost' => 30746.00,
        ];
        $taguroOil = [
            'number_of_leads' => 332, 'number_of_orders' => 75, 'average_order_value' => 682.64,
            'tax_allocation' => 7465.28, 'product_cost' => 6198.00,
        ];

        $summed = ExpectedIncomeCalculator::sum([
            ExpectedIncomeCalculator::derive($clearsight),
            ExpectedIncomeCalculator::derive($pterygium),
            ExpectedIncomeCalculator::derive($taguroOil),
        ]);

        $this->assertEqualsWithDelta(518795.00, $summed['gross_sales'], 1.0);
        $this->assertEqualsWithDelta(22395.83, $summed['tax_allocation'], 0.5);
    }

    public function test_sum_averages_roas_and_actual_cost_per_lead_across_rows(): void
    {
        $rowA = ExpectedIncomeCalculator::derive(['roas' => 10.0, 'actual_cost_per_lead' => 20.0]);
        $rowB = ExpectedIncomeCalculator::derive(['roas' => 20.0, 'actual_cost_per_lead' => 40.0]);

        $summed = ExpectedIncomeCalculator::sum([$rowA, $rowB]);

        $this->assertEqualsWithDelta(15.0, $summed['roas'], 0.01);
        $this->assertEqualsWithDelta(30.0, $summed['actual_cost_per_lead'], 0.01);
    }
}
