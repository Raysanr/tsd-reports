<?php

namespace Tests\Unit;

use App\Support\DsPprCalculator;
use PHPUnit\Framework\TestCase;

/**
 * NI%, AOV, Actual Cost Per Lead, and Excess Leads are confirmed EXACT
 * against the real source sheet's own Sep 1 Clearsight row (Gross Sales
 * 3,800.00, Net Income -8,208.10, Ads Spent 3,024.31, Total Orders 6,
 * Total Leads 44, Catered Leads 33 → NI% -216.00%, AOV 633.33, Actual
 * Cost Per Lead 68.73, Excess Leads 11 — all matched to the screenshot
 * exactly). Pick-up Rate / Conversion Rate / Upselling Rate are BEST-
 * EFFORT formulas (explicit decision, 2026-09-24, after the screenshot's
 * own Pick-up Rate of 57.58% didn't match Catered÷Total's 75% and
 * Conversion Rate's 31.58% didn't match Orders÷Catered's 18.18% —
 * pixel-reading the sheet further wasn't reliable enough to trust) — if
 * a real mismatch is ever spotted against the live sheet, only these 3
 * need correcting.
 */
class DsPprCalculatorTest extends TestCase
{
    private const SEP_1_CLEARSIGHT = [
        'gross_sales' => 3800.00,
        'net_income' => -8208.10,
        'ads_spent' => 3024.31,
        'total_orders' => 6,
        'total_leads' => 44,
        'catered_leads' => 33,
    ];

    public function test_ni_percent_matches_the_source_sheet_exactly(): void
    {
        $d = DsPprCalculator::derive(self::SEP_1_CLEARSIGHT);

        $this->assertEqualsWithDelta(-2.1600, $d['ni_pct'], 0.0001);
    }

    public function test_aov_matches_the_source_sheet_exactly(): void
    {
        $d = DsPprCalculator::derive(self::SEP_1_CLEARSIGHT);

        $this->assertEqualsWithDelta(633.33, $d['aov'], 0.01);
    }

    public function test_actual_cost_per_lead_matches_the_source_sheet_exactly(): void
    {
        $d = DsPprCalculator::derive(self::SEP_1_CLEARSIGHT);

        $this->assertEqualsWithDelta(68.73, $d['actual_cost_per_lead'], 0.01);
    }

    public function test_excess_leads_matches_the_source_sheet_exactly(): void
    {
        $d = DsPprCalculator::derive(self::SEP_1_CLEARSIGHT);

        $this->assertSame(11.0, $d['excess_leads']);
    }

    public function test_a_row_with_zero_gross_sales_does_not_divide_by_zero(): void
    {
        $d = DsPprCalculator::derive(['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0]);

        $this->assertSame(0.0, $d['ni_pct']);
        $this->assertSame(0.0, $d['aov']);
        $this->assertSame(0.0, $d['pickup_rate']);
    }

    public function test_sum_recomputes_ratios_from_summed_totals_not_an_average_of_percents(): void
    {
        $rowA = ['gross_sales' => 1000, 'net_income' => 100, 'ads_spent' => 0, 'total_orders' => 1, 'total_leads' => 10, 'catered_leads' => 10];
        $rowB = ['gross_sales' => 3000, 'net_income' => -300, 'ads_spent' => 0, 'total_orders' => 1, 'total_leads' => 10, 'catered_leads' => 10];

        $summed = DsPprCalculator::sum([$rowA, $rowB]);

        // (100 + -300) / (1000 + 3000) = -0.05, NOT the average of
        // +10% and -10% (which would wrongly cancel out to 0).
        $this->assertEqualsWithDelta(-0.05, $summed['ni_pct'], 0.0001);
    }

    /**
     * Root-caused 2026-09-24 (/systematic-debugging) against the real
     * source sheet's own Sep 14 TOTAL row: Pick-up Rate 55.17% is the
     * plain average of that day's 8 real per-product Pick-up Rates
     * (46.15%, 54.22%, 66.67%, 57.69%, 62.96%, 63.64%, 40%, 50%), NOT
     * catered÷leads on the summed totals (194÷200 = 97%, nowhere close).
     * This is the one exception to "recompute from summed totals" that
     * every other derived field in this class follows.
     */
    public function test_sum_averages_pickup_conversion_and_upselling_rate_across_rows(): void
    {
        // total_leads/catered_leads/total_orders are deliberately
        // inconsistent with the individual rows' own ratios here — if
        // sum() were still recomputing Pick-up Rate from summed leads,
        // this would fail loudly instead of silently passing by
        // coincidence.
        $rowA = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 2, 'total_leads' => 10, 'catered_leads' => 5];
        $rowB = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 8, 'total_leads' => 10, 'catered_leads' => 10];

        $summed = DsPprCalculator::sum([$rowA, $rowB]);

        // Row A: pickup 5/10=0.5, conv 2/5=0.4. Row B: pickup 10/10=1.0, conv 8/10=0.8.
        $this->assertEqualsWithDelta(0.75, $summed['pickup_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $summed['conversion_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $summed['upselling_rate'], 0.0001);
    }
}
