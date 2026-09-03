<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-03: "modern UI... KPI cards with trend badges"
 * (TailAdmin-style reference) — 4 headline numbers (Total Called Leads,
 * Pick-up/Conversion/Upselling Rate) compared against the immediately-
 * preceding period of equal length.
 */
class ChartsKpiSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function order(string $id, Carbon $at, array $overrides = []): void
    {
        Order::create(array_merge([
            'pancake_order_id'   => $id,
            'team'               => 'SH Naturals',
            'tsa_name'           => 'Gemma',
            'disposition'        => 'CONFIRMED VIA CALL',
            'status_code'        => 2,
            'pancake_created_at' => $at,
            'synced_at'          => now(),
        ], $overrides));
    }

    public function test_total_called_leads_shows_a_positive_delta_vs_the_prior_period(): void
    {
        // Selected range: today only. Prior period: yesterday only (same
        // 1-day length, immediately before).
        $this->order('cur-1', now());
        $this->order('cur-2', now());
        $this->order('prev-1', now()->subDay());

        $response = $this->get(route('charts', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewHas('kpis', function ($kpis) {
            return $kpis['total_called']['value'] === 2
                && $kpis['total_called']['delta'] === 100.0; // 1 -> 2 is +100%
        });
    }

    public function test_a_rate_with_no_prior_period_data_shows_a_null_delta_not_a_misleading_zero(): void
    {
        $this->order('cur-1', now());

        $response = $this->get(route('charts', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]));

        $response->assertOk();
        $response->assertViewHas('kpis', fn ($kpis) => $kpis['pick_up_rate']['delta'] === null);
    }

    public function test_the_prior_period_matches_the_selected_ranges_own_length(): void
    {
        // A 3-day selected range (today, yesterday, day before) must compare
        // against the 3 days immediately before it, not a fixed window.
        $this->order('cur-1', now());
        $this->order('cur-2', now()->subDay());
        $this->order('cur-3', now()->subDays(2));
        // 4 days back — inside a correct 3-day prior window (days 3-5 back).
        $this->order('prev-1', now()->subDays(4));

        $response = $this->get(route('charts', [
            'date_from' => now()->subDays(2)->toDateString(),
            'date_to'   => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('kpis', fn ($kpis) => $kpis['total_called']['value'] === 3
            && $kpis['total_called']['delta'] === 200.0); // 1 -> 3 is +200%
    }
}
