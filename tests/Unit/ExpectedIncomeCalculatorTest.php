<?php

namespace Tests\Unit;

use App\Support\ExpectedIncomeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Gross Sales and Cancelled are plain manual inputs (explicit request,
 * 2026-09-28: "in the expected i want you to make all is manually input")
 * — same convention as Tax Allocation/Product Cost, which were never
 * derived either (verified during development that Tax Allocation repeats
 * the identical peso figure (7,465.28) across unrelated products — a fixed
 * pool, not a rate — and Product Cost varies uniquely per product with no
 * common rate at all). Projected Returns and Projected Delivered are
 * DERIVED again (explicit correction, 2026-10-01, confirmed against the
 * real sheet's own live cell formulas: "the only auto is Projected Returns
 * / Projected Delivered") — Returns = Gross Sales × 25%, Delivered = Gross
 * Sales − Cancelled − Returns. CLEARSIGHT's own fixture numbers below
 * (Gross Sales 90,100.00, Cancelled 4,505.00) happen to produce EXACTLY
 * the real sheet's own Returns 22,525.00/Delivered 63,070.00 through this
 * formula — not a coincidence, since both sets of numbers came from the
 * same real "EXPECTED INCOME 2026" tab, confirming the formula is right.
 * Team Eyecare's own sum (Gross Sales 518,795.00 = Clearsight 90,100 +
 * Pterygium 377,497 + Taguro Oil 51,198, confirmed exact) verifies sum()'s
 * own dollar-summing.
 */
class ExpectedIncomeCalculatorTest extends TestCase
{
    private const CLEARSIGHT = [
        'number_of_leads' => 647,
        'number_of_orders' => 112,
        'average_order_value' => 804.46,
        'gross_sales' => 90100.00,
        'cancelled' => 4505.00,
        'tax_allocation' => 7465.28,
        'product_cost' => 7454.00,
    ];

    public function test_conversion_rate_matches_the_real_sheet_exactly(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(0.1731, $d['conversion_rate'], 0.001);
    }

    public function test_gross_sales_and_cancelled_are_passed_through_as_manual_inputs(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(90100.00, $d['gross_sales'], 1.0);
        $this->assertEqualsWithDelta(4505.00, $d['cancelled'], 1.0);
    }

    /** Returns = Gross Sales × 25%, Delivered = Gross Sales − Cancelled −
     *  Returns — confirmed exact against the real sheet's own numbers
     *  (even though a 'returns'/'delivered' key is present in the
     *  CLEARSIGHT fixture, derive() no longer reads it at all — these
     *  assertions would hold identically with those two keys removed from
     *  the fixture entirely). */
    public function test_returns_and_delivered_are_derived_from_gross_sales(): void
    {
        $d = ExpectedIncomeCalculator::derive(self::CLEARSIGHT);

        $this->assertEqualsWithDelta(22525.00, $d['returns'], 1.0);
        $this->assertEqualsWithDelta(63070.00, $d['delivered'], 1.0);
    }

    /** A row with nothing typed into Gross Sales/Cancelled yet shows plain
     *  zeroes for Gross Sales/Cancelled AND for the derived Returns/
     *  Delivered (0 × 25% = 0, 0 − 0 − 0 = 0) — no fallback formula reads
     *  anything else. */
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

    /** withOverriddenOperatingCosts() (explicit request, 2026-09-30: "the
     *  salaries row is based to the Daily Rate / Product", then "the daily
     *  cost is it is this [Communication Allowance, 13th Month Allowance,
     *  SIL, ...]") swaps any subset of Operating Costs lines and
     *  recomputes every figure that cascades from them — Total Operating
     *  Costs, Net Income, and their own _pct — while leaving every OTHER
     *  field (Gross Profit, Total Selling Costs, Income Before OPEX, and
     *  any operating line NOT in $overrides) completely untouched. */
    public function test_with_overridden_operating_costs_recomputes_only_the_opex_cascade(): void
    {
        $d = ExpectedIncomeCalculator::derive(array_merge(self::CLEARSIGHT, ['salaries' => 999.00, 'communication_allowance' => 100.00, 'sil' => 50.00]));

        $overridden = ExpectedIncomeCalculator::withOverriddenOperatingCosts($d, ['salaries' => 210.36, 'communication_allowance' => 25.00]);

        $this->assertEqualsWithDelta(210.36, $overridden['operating_lines']['salaries'], 0.01);
        $this->assertEqualsWithDelta(25.00, $overridden['operating_lines']['communication_allowance'], 0.01);
        // A line NOT in $overrides keeps its original value untouched.
        $this->assertEqualsWithDelta(50.00, $overridden['operating_lines']['sil'], 0.01);

        $expectedTotalOperating = $d['total_operating_costs'] - 999.00 + 210.36 - 100.00 + 25.00;
        $this->assertEqualsWithDelta($expectedTotalOperating, $overridden['total_operating_costs'], 0.01);
        $this->assertEqualsWithDelta($d['income_before_opex'] - $expectedTotalOperating, $overridden['net_income'], 0.01);

        // Untouched — Operating Costs has no bearing on the Gross
        // Profit/Selling side of the P&L at all.
        $this->assertSame($d['gross_profit'], $overridden['gross_profit']);
        $this->assertSame($d['total_selling_costs'], $overridden['total_selling_costs']);
        $this->assertSame($d['income_before_opex'], $overridden['income_before_opex']);
    }

    public function test_with_overridden_operating_costs_handles_zero_gross_sales_without_dividing_by_zero(): void
    {
        $d = ExpectedIncomeCalculator::derive(['salaries' => 500.00]);

        $overridden = ExpectedIncomeCalculator::withOverriddenOperatingCosts($d, ['salaries' => 0.0]);

        $this->assertSame(0.0, $overridden['total_operating_costs_pct']);
        $this->assertSame(0.0, $overridden['net_income_pct']);
    }

    /** Root-caused 2026-09-30: a caller passing through
     *  CostBreakdownCalculator::dailyCostRow()'s own 'total' key
     *  unfiltered (that array has 21 keys — 20 real pools plus its own
     *  'total' sum) crashed with a TypeError the first time this hit a
     *  real Collection-typed operating_lines, and before that would have
     *  silently injected a bogus 22nd "operating cost" line inflating
     *  Total Operating Costs by roughly double. A key not already present
     *  in operating_lines must be dropped, not merged in. */
    public function test_with_overridden_operating_costs_ignores_keys_not_already_in_operating_lines(): void
    {
        $d = ExpectedIncomeCalculator::derive(array_merge(self::CLEARSIGHT, ['salaries' => 100.00]));

        $overridden = ExpectedIncomeCalculator::withOverriddenOperatingCosts($d, ['salaries' => 50.00, 'total' => 999999.99]);

        $this->assertArrayNotHasKey('total', $overridden['operating_lines']);
        $expectedTotalOperating = $d['total_operating_costs'] - 100.00 + 50.00;
        $this->assertEqualsWithDelta($expectedTotalOperating, $overridden['total_operating_costs'], 0.01);
    }
}
