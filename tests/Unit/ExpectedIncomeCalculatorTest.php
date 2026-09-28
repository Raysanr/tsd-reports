<?php

namespace Tests\Unit;

use App\Support\ExpectedIncomeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Gross Sales, Cancelled, Projected Returns, and Projected Delivered used
 * to be derived (Orders × AOV, then fixed 5%/25%/70% rates of that) but are
 * now plain manual inputs (explicit request, 2026-09-28: "in the expected i
 * want you to make all is manually input") — same convention as Tax
 * Allocation/Product Cost, which were never derived either (verified during
 * development that Tax Allocation repeats the identical peso figure
 * (7,465.28) across unrelated products — a fixed pool, not a rate — and
 * Product Cost varies uniquely per product with no common rate at all).
 * Clearsight's own real "EXPECTED INCOME 2026" sheet numbers (Number of
 * Leads 647, Conversion Rate 17.31%, Number of Orders 112, Gross Sales
 * 90,100.00, Cancelled 4,505.00, Projected Returns 22,525.00, Projected
 * Delivered 63,070.00) are kept as fixtures here, just passed in directly
 * now instead of re-derived from AOV/fixed rates. Team Eyecare's own sum
 * (Gross Sales 518,795.00 = Clearsight 90,100 + Pterygium 377,497 + Taguro
 * Oil 51,198, confirmed exact) verifies sum()'s own dollar-summing.
 */
class ExpectedIncomeCalculatorTest extends TestCase
{
    private const CLEARSIGHT = [
        'number_of_leads' => 647,
        'number_of_orders' => 112,
        'average_order_value' => 804.46,
        'gross_sales' => 90100.00,
        'cancelled' => 4505.00,
        'returns' => 22525.00,
        'delivered' => 63070.00,
        'tax_allocation' => 7465.28,
        'product_cost' => 7454.00,
    ];

    public function test_conversion_rate_matches_the_real_sheet_exactly(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(0.1731, $d['conversion_rate'], 0.001);
    }

    public function test_gross_sales_cancelled_returns_delivered_are_passed_through_as_manual_inputs(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(90100.00, $d['gross_sales'], 1.0);
        $this->assertEqualsWithDelta(4505.00, $d['cancelled'], 1.0);
        $this->assertEqualsWithDelta(22525.00, $d['returns'], 1.0);
        $this->assertEqualsWithDelta(63070.00, $d['delivered'], 1.0);
    }

    /** A row with nothing typed into Gross Sales/Cancelled/Returns/Delivered
     *  yet shows plain zeroes — no fallback formula kicks in. */
    public function test_a_row_with_no_manual_sales_breakdown_yet_shows_zeroes(): void
    {
        $d = ExpectedIncomeCalculator::derive(['number_of_orders' => 112, 'average_order_value' => 804.46]);

        $this->assertSame(0.0, $d['gross_sales']);
        $this->assertSame(0.0, $d['cancelled']);
        $this->assertSame(0.0, $d['returns']);
        $this->assertSame(0.0, $d['delivered']);
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
            'gross_sales' => 377497.00, 'tax_allocation' => 7465.28, 'product_cost' => 30746.00,
        ];
        $taguroOil = [
            'number_of_leads' => 332, 'number_of_orders' => 75, 'average_order_value' => 682.64,
            'gross_sales' => 51198.00, 'tax_allocation' => 7465.28, 'product_cost' => 6198.00,
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

    /** Explicit request, 2026-09-28 (real template screenshot): a new
     *  "Income Before OPEX" subtotal, sitting between Total Selling Costs
     *  and Operating Costs — Gross Profit minus Selling & Marketing costs
     *  only, before Operating Costs are subtracted. */
    public function test_income_before_opex_subtracts_selling_costs_only(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $expected = $d['gross_profit'] - $d['total_selling_costs'];
        $this->assertEqualsWithDelta($expected, $d['income_before_opex'], 0.01);

        // And Net Income continues from THAT subtotal, not independently
        // recomputed — Income Before OPEX minus Total Operating Costs.
        $this->assertEqualsWithDelta($d['income_before_opex'] - $d['total_operating_costs'], $d['net_income'], 0.01);
    }

    /** Same template — a new plain manual input alongside ROAS/Actual Cost
     *  Per Message, averaged across rows the same way as those two. */
    public function test_sum_averages_standard_cost_per_message_across_rows(): void
    {
        $rowA = ExpectedIncomeCalculator::derive(['standard_cost_per_message' => 40.0]);
        $rowB = ExpectedIncomeCalculator::derive(['standard_cost_per_message' => 60.0]);

        $summed = ExpectedIncomeCalculator::sum([$rowA, $rowB]);

        $this->assertEqualsWithDelta(50.0, $summed['standard_cost_per_message'], 0.01);
    }
}
