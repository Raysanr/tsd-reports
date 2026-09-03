<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request, 2026-09-03: TSA Rankings on the Analytics page —
 * Pick-up/Conversion/Upselling Rate per TSA, sourced from
 * ProductPerformance::tsaRows(), the same shared per-TSA grouping the
 * Dashboard leaderboard and TSA Performance both already use.
 */
class ChartsTsaRankingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_tsa_with_called_leads_shows_up_with_her_three_rates(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        // 2 confirmed via call, 1 upsell — answered = 3, catered = 3.
        // pick_up_rate = answered/total_called = 100%.
        // conversion_rate = upsell_confirmation/answered = 1/3 = 33.3%.
        // upselling_rate = upsell/(upsell+confirmed_via_call) = 1/3 = 33.3%.
        Order::create([
            'pancake_order_id'   => 'rank-1', 'team' => $shift->team, 'tsa_name' => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 2,
            'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id'   => 'rank-2', 'team' => $shift->team, 'tsa_name' => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 2,
            'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id'   => 'rank-3', 'team' => $shift->team, 'tsa_name' => $shift->tsa_key,
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
            'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $response = $this->get(route('charts'));

        $response->assertOk();
        $response->assertViewHas('tsaRankings', function ($rankings) use ($shift) {
            $row = $rankings->firstWhere('tsa_key', $shift->tsa_key);
            return $row
                && $row['pick_up_rate'] === 100.0
                && $row['conversion_rate'] === 33.3
                && $row['upselling_rate'] === 33.3;
        });
    }

    public function test_a_tsa_with_zero_called_leads_is_excluded_entirely(): void
    {
        // A roster TSA who genuinely has no orders in range at all — a
        // 0%/dash row would be a meaningless ranking position, not a real
        // "worst performer."
        $response = $this->get(route('charts'));

        $response->assertOk();
        $response->assertViewHas('tsaRankings', function ($rankings) {
            return $rankings->every(fn ($row) => $row['total_called'] > 0);
        });
    }

    public function test_rankings_are_sorted_by_upselling_rate_descending(): void
    {
        $low  = TsaShift::where('team', 'SH Naturals')->first();
        $high = TsaShift::where('team', 'SH Naturals')->skip(1)->first();

        // Low performer: 1 confirmed-via-call, 0 upsells -> 0% upselling rate.
        Order::create([
            'pancake_order_id'   => 'low-1', 'team' => $low->team, 'tsa_name' => $low->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL', 'is_upsell' => false, 'status_code' => 2,
            'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        // High performer: 1 upsell only -> 100% upselling rate.
        Order::create([
            'pancake_order_id'   => 'high-1', 'team' => $high->team, 'tsa_name' => $high->tsa_key,
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 2,
            'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $response = $this->get(route('charts'));

        $response->assertOk();
        $response->assertViewHas('tsaRankings', function ($rankings) use ($low, $high) {
            $keys = $rankings->pluck('tsa_key')->values()->all();
            return array_search($high->tsa_key, $keys) < array_search($low->tsa_key, $keys);
        });
    }
}
