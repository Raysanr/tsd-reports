<?php

namespace Tests\Unit;

use App\Support\OverallSalesSummary;
use PHPUnit\Framework\TestCase;

/** Figures are the sheet's Nov 2 column, and its Nov 1-3 range for NI %. */
class OverallSalesSummaryTest extends TestCase
{
    private function counts(int $answered = 0, int $unanswered = 0): array
    {
        return ['answered' => $answered, 'unanswered' => $unanswered, 'confirmed_via_call' => 0, 'upsell_confirmation' => 0];
    }

    private function partner(float $ib, float $shared, float $net, int $orders, float $gross): array
    {
        return ['2026-11-02' => [
            'gross_sales' => $gross, 'income_before_opex' => $ib, 'shared_income' => $shared, 'net_income' => $net,
            'total_orders' => $orders, 'total_leads' => 50, 'excess_leads' => 5, 'call_counts' => $this->counts(20, 20),
        ]];
    }

    private function summary(): array
    {
        return OverallSalesSummary::build(
            ['days' => ['2026-11-02' => ['gross_sales' => 23848.00, 'net_income' => -919.85, 'total_orders' => 35, 'call_counts' => $this->counts(60, 40)]],
             'teams' => ['A' => ['2026-11-02' => ['total_leads' => 100, 'excess_leads' => 7]], 'B' => ['2026-11-02' => ['total_leads' => 58, 'excess_leads' => 3]]]],
            [
                'PEAK.CO' => $this->partner(5582.20, 3907.54, -3118.63, 13, 8500),
                'M&J'     => $this->partner(6491.93, 4544.35, -5800.09, 13, 11500),
                'MLC'     => $this->partner(5654.40, 3958.08, -2998.05, 13, 10000),
            ],
        );
    }

    public function test_rows_follow_the_sheet_order(): void
    {
        $this->assertSame(['SH ECOMMERCE', 'M&J ENTERPRISE', 'PEAK ENTERPRISE', 'MLC'], array_column($this->summary()['rows'], 'label'));
    }

    public function test_ni_percent_uses_sales_for_sh_ecom_and_shared_income_for_partners(): void
    {
        $rows = $this->summary()['rows'];

        $this->assertEqualsWithDelta(-0.0386, $rows[0]['days']['2026-11-02']['ni_pct'], 0.0001);
        $this->assertEqualsWithDelta(-1.2763, $rows[1]['days']['2026-11-02']['ni_pct'], 0.0001);
    }

    public function test_total_matches_the_sheet_and_includes_sh_ecom_orders(): void
    {
        $day = $this->summary()['total']['days']['2026-11-02'];

        $this->assertEqualsWithDelta(12409.97, $day['shared_income'], 0.01);
        $this->assertEqualsWithDelta(-12836.62, $day['net_income'], 0.01);
        $this->assertEqualsWithDelta(-0.3540, $day['ni_pct'], 0.0001); // net ÷ (sales + shared)
        $this->assertSame(74.0, $day['total_orders']);
    }

    public function test_sh_ecom_shows_its_own_income_but_the_total_stays_partners_only(): void
    {
        $summary = OverallSalesSummary::build(
            ['days' => ['2026-11-02' => ['gross_sales' => 100.0, 'net_income' => 10.0, 'income_before_opex' => 60.0, 'total_orders' => 1, 'call_counts' => $this->counts()]], 'teams' => []],
            ['MLC' => $this->partner(50.0, 35.0, -5.0, 1, 80.0)],
        );

        $this->assertSame(60.0, $summary['rows'][0]['days']['2026-11-02']['own_income']);
        $this->assertSame(35.0, $summary['total']['days']['2026-11-02']['shared_income']);
        $this->assertEqualsWithDelta(5 / 135, $summary['total']['days']['2026-11-02']['ni_pct'], 0.0001);
    }

    public function test_excess_leads_add_both_sh_teams_and_every_partner(): void
    {
        $summary = $this->summary();

        $this->assertSame(10.0, (float) $summary['rows'][0]['days']['2026-11-02']['excess_leads']);
        $this->assertSame(5.0, (float) $summary['rows'][1]['days']['2026-11-02']['excess_leads']);
        $this->assertSame(25.0, (float) $summary['total']['days']['2026-11-02']['excess_leads']);
    }

    public function test_range_aov_and_rates_are_pooled_not_added(): void
    {
        $sh = ['gross_sales' => 1000.0, 'net_income' => 0.0, 'total_orders' => 10, 'call_counts' => $this->counts(50, 50)];
        $summary = OverallSalesSummary::build(['days' => [
            '2026-11-01' => $sh,
            '2026-11-02' => ['gross_sales' => 3000.0, 'total_orders' => 10, 'call_counts' => $this->counts(150, 50)] + $sh,
        ], 'teams' => []], []);

        $range = $summary['rows'][0]['range'];
        $this->assertEqualsWithDelta(200.0, $range['aov'], 0.001);
        $this->assertEqualsWithDelta(0.667, $range['pickup_rate'], 0.001);
    }

    public function test_page_still_builds_when_partners_could_not_load(): void
    {
        $summary = OverallSalesSummary::build(['days' => ['2026-11-02' => ['gross_sales' => 100.0, 'net_income' => 10.0, 'total_orders' => 1, 'call_counts' => $this->counts()]], 'teams' => []], []);

        $this->assertCount(1, $summary['rows']);
        $this->assertEqualsWithDelta(0.1, $summary['total']['days']['2026-11-02']['ni_pct'], 0.0001);
    }
}
