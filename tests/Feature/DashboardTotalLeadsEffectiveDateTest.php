<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-11, "accurate from POS"): reverses the 2026-08-08
 * decision this test file used to encode. Reported live: Leads Report showed
 * 256 new leads for Eyecare/2026-08-10 against Dashboard's 255 — Leads Report
 * counts by COALESCE(pancake_inserted_at, pancake_created_at) (POS's own
 * Created-At), Dashboard counted by pancake_created_at alone (actually the
 * worked-at timestamp, despite the name — see SyncTodayOrders::
 * resolveWorkedAt()). Dashboard now uses the same POS-accurate expression
 * Leads Report always has, and TSA Performance's Grand Total / date-range
 * filters were switched to match in the same change (see
 * TsaPerformanceController::index()'s comment) — so this doesn't reintroduce
 * the 2026-08-08 disagreement between Dashboard and TSA Performance, it just
 * moves where the tally point sits, with both still agreeing.
 *
 * Unaffected by this change: TSA Performance's HOURLY breakdown still buckets
 * by pancake_created_at (worked-at) — which hour a call landed in still needs
 * the real work time, not the arrival time. Only WHICH DAY an order counts
 * under moved to the POS-accurate expression.
 */
class DashboardTotalLeadsEffectiveDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_order_created_today_but_worked_yesterday_counts_in_todays_total_leads(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'    => 'backlog-1',
            'team'                => 'SH Naturals',
            'tsa_name'            => $shift->tsa_key,
            'disposition'         => 'CONFIRMED VIA CALL',
            'product'             => 'Sinuxyl',
            'raw_tags'            => ['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_inserted_at' => now(),              // created today (POS truth)
            'pancake_created_at'  => now()->subDay(),    // but worked yesterday
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 1);
    }

    public function test_an_order_created_yesterday_but_worked_today_does_not_count_in_todays_total_leads(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'    => 'backlog-2',
            'team'                => 'SH Naturals',
            'tsa_name'            => $shift->tsa_key,
            'disposition'         => 'CONFIRMED VIA CALL',
            'product'             => 'Sinuxyl',
            'raw_tags'            => ['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'           => false,
            'status_code'         => 1,
            'pancake_inserted_at' => now()->subDay(),   // created yesterday (POS truth)
            'pancake_created_at'  => now(),              // but worked today
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 0);
    }

    public function test_a_pre_backfill_order_with_no_pancake_inserted_at_still_counts_by_worked_at(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'    => 'legacy-1',
            'team'                => 'SH Naturals',
            'tsa_name'            => $shift->tsa_key,
            'disposition'         => 'CONFIRMED VIA CALL',
            'product'             => 'Sinuxyl',
            'raw_tags'            => ['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'           => false,
            'status_code'         => 1,
            // Never backfilled — COALESCE falls back to pancake_created_at,
            // same as it always has for rows synced before pancake_inserted_at
            // existed. Still true under the 2026-08-11 change; only rows that
            // actually HAVE both timestamps can disagree.
            'pancake_inserted_at' => null,
            'pancake_created_at'  => now(),
            'synced_at'           => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('dashboard', ['date_from' => $today, 'date_to' => $today]));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_leads'] === 1);
    }
}
