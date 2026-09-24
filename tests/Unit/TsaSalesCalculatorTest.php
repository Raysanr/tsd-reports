<?php

namespace Tests\Unit;

use App\Support\TsaSalesCalculator;
use PHPUnit\Framework\TestCase;

/**
 * NI% and AOV confirmed exact against the real source sheet's own Tue
 * Sep 1 Marisol Lagarde row (Gross Sales 5,500.00, Net Income -1,379.95,
 * Total Orders 6 → NI% -25.09%, AOV 916.67 — matched to the cent).
 * Pick-up Rate / Upselling Rate are raw manual-entry fields on this
 * sheet (no formula to verify — see TsaSalesCalculator's own doc
 * comment).
 */
class TsaSalesCalculatorTest extends TestCase
{
    private const SEP_1_MARISOL = [
        'gross_sales' => 5500.00,
        'net_income' => -1379.95,
        'ads_spent' => 1426.33,
        'total_orders' => 6,
        'catered_leads' => 32,
        'pickup_rate' => 0.50,
        'upselling_rate' => 0.50,
    ];

    public function test_ni_percent_matches_the_source_sheet_exactly(): void
    {
        $d = TsaSalesCalculator::derive(self::SEP_1_MARISOL);

        $this->assertEqualsWithDelta(-0.2509, $d['ni_pct'], 0.0001);
    }

    public function test_aov_matches_the_source_sheet_exactly(): void
    {
        $d = TsaSalesCalculator::derive(self::SEP_1_MARISOL);

        $this->assertEqualsWithDelta(916.67, $d['aov'], 0.01);
    }

    public function test_pickup_and_upselling_rate_pass_through_unchanged(): void
    {
        $d = TsaSalesCalculator::derive(self::SEP_1_MARISOL);

        $this->assertSame(0.50, $d['pickup_rate']);
        $this->assertSame(0.50, $d['upselling_rate']);
    }

    public function test_a_row_with_zero_gross_sales_does_not_divide_by_zero(): void
    {
        $d = TsaSalesCalculator::derive(['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'catered_leads' => 0, 'pickup_rate' => 0, 'upselling_rate' => 0]);

        $this->assertSame(0.0, $d['ni_pct']);
        $this->assertSame(0.0, $d['aov']);
    }

    public function test_sum_recomputes_ni_pct_and_aov_from_summed_totals(): void
    {
        $rowA = ['gross_sales' => 1000, 'net_income' => 100, 'ads_spent' => 0, 'total_orders' => 1, 'catered_leads' => 10, 'pickup_rate' => 0.5, 'upselling_rate' => 0.5];
        $rowB = ['gross_sales' => 3000, 'net_income' => -300, 'ads_spent' => 0, 'total_orders' => 1, 'catered_leads' => 10, 'pickup_rate' => 0.9, 'upselling_rate' => 0.3];

        $summed = TsaSalesCalculator::sum([$rowA, $rowB]);

        $this->assertEqualsWithDelta(-0.05, $summed['ni_pct'], 0.0001);
        // Pick-up/Upselling Rate averaged across rows, not recomputed
        // from a summed denominator (there isn't one — both are raw
        // fields on this sheet, unlike DSPPR's own Pick-up Rate).
        $this->assertEqualsWithDelta(0.70, $summed['pickup_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.40, $summed['upselling_rate'], 0.0001);
    }
}
