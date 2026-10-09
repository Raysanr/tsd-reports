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

    /** Explicit follow-up, 2026-10-09, same session as the averaging
     *  convention above was re-confirmed: "when 0.00% it is not included
     *  to the total percentage" — a row with zero total_leads never had
     *  any real activity that day/range, so it must not count toward the
     *  rate average at all (its own implicit 0% would otherwise drag the
     *  average down). Became newly visible once every product started
     *  getting a card regardless of activity (2026-10-06 decision) —
     *  $rows passed into sum() can now genuinely contain zero-activity
     *  rows where it never used to. */
    public function test_sum_excludes_zero_activity_rows_from_the_rate_average(): void
    {
        $active = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 2, 'total_leads' => 10, 'catered_leads' => 10, 'pickup_rate' => 1.0, 'conversion_rate' => 0.2, 'upselling_rate' => 0.2];
        $inactive = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0, 'pickup_rate' => 0.0, 'conversion_rate' => 0.0, 'upselling_rate' => 0.0];

        $summed = DsPprCalculator::sum([$active, $inactive, $inactive]);

        // 100% (the one active row's own real rate), NOT (100%+0%+0%)/3 =
        // 33.3% — the 2 inactive rows must be excluded entirely, not
        // averaged in as genuine zeros.
        $this->assertEqualsWithDelta(1.0, $summed['pickup_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $summed['conversion_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $summed['upselling_rate'], 0.0001);
    }

    /** Every row inactive (a day/range with no real activity at all) must
     *  not divide by zero — falls back to 0%, same as derive()'s own
     *  empty-row behavior. */
    public function test_sum_with_every_row_inactive_does_not_divide_by_zero(): void
    {
        $inactive = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0];

        $summed = DsPprCalculator::sum([$inactive, $inactive]);

        $this->assertSame(0.0, $summed['pickup_rate']);
        $this->assertSame(0.0, $summed['conversion_rate']);
        $this->assertSame(0.0, $summed['upselling_rate']);
    }

    /**
     * Real bug, root-caused 2026-10-05: the summary table's Pick-up/
     * Conversion/Upselling Rate never matched the daily table for the
     * same date and identical raw counts. Cause: every row the
     * controller/view actually passes into sum() already carries the
     * REAL, Leads-Report-matching rate (merged in from
     * ProductPerformance::dsPprRow() — Answered÷Total,
     * Upsell-Confirmation÷Answered), which the daily table displays
     * as-is. sum() used to ignore that and silently RE-DERIVE each row's
     * rate from this class's own placeholder ratio
     * (catered÷leads/orders÷catered) before averaging — a different
     * formula than the one already sitting on the row. This row
     * deliberately sets pickup_rate/conversion_rate/upselling_rate to
     * values derive() would NOT produce from the same raw counts, so a
     * silent re-derive fails loudly instead of coincidentally matching.
     */
    public function test_sum_averages_each_rows_own_already_present_rate_instead_of_rederiving_it(): void
    {
        // derive() would compute pickup 5/10=0.5, conv 2/5=0.4 from these
        // raw counts — but the REAL (Leads-Report) rate already on the
        // row is deliberately different, simulating the $real merge.
        $rowA = [
            'gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0,
            'total_orders' => 2, 'total_leads' => 10, 'catered_leads' => 5,
            'pickup_rate' => 0.90, 'conversion_rate' => 0.70, 'upselling_rate' => 0.70,
        ];
        $rowB = [
            'gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0,
            'total_orders' => 8, 'total_leads' => 10, 'catered_leads' => 10,
            'pickup_rate' => 0.30, 'conversion_rate' => 0.20, 'upselling_rate' => 0.20,
        ];

        $summed = DsPprCalculator::sum([$rowA, $rowB]);

        // Average of the REAL rates (0.90+0.30)/2 and (0.70+0.20)/2 —
        // NOT the average of derive()'s 0.5/1.0 and 0.4/0.8.
        $this->assertEqualsWithDelta(0.60, $summed['pickup_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.45, $summed['conversion_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.45, $summed['upselling_rate'], 0.0001);
    }

    /** A row with no real rate merged in (e.g. a pure manual/TikTok row)
     *  still falls back to deriving it from raw counts, same as before. */
    public function test_sum_falls_back_to_deriving_the_rate_when_a_row_has_no_real_rate_present(): void
    {
        $rowA = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 2, 'total_leads' => 10, 'catered_leads' => 5];
        $rowB = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 8, 'total_leads' => 10, 'catered_leads' => 10];

        $summed = DsPprCalculator::sum([$rowA, $rowB]);

        $this->assertEqualsWithDelta(0.75, $summed['pickup_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $summed['conversion_rate'], 0.0001);
    }
}
