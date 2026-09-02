<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed live (2026-08-07): Gemma showed 18 upsells on the Dashboard's TSA
 * Leaderboard for Aug 3, but 20 in both real Pancake POS and the logistics
 * system. Root cause: the leaderboard only checked is_upsell = true, which
 * gets reset to false once a genuine upsell is later returned/cancelled in
 * Pancake (is_returned_upsell is set instead — see Order's sync-time flag
 * logic). Leads Report/TSA Performance already count both via
 * ProductPerformance's isRealUpsell; the leaderboard was the one place that
 * never got the same fix. The 3 missing orders were all later-returned
 * upsells (status codes 4/4/5) — 17 (is_upsell only) + 3 (returned) = 20,
 * exactly matching POS and the logistics system.
 */
class DashboardTsaLeaderboardReturnedUpsellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_leaderboard_upsell_count_includes_later_returned_upsells(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        // A genuine, still-live upsell.
        Order::create([
            'pancake_order_id'   => 'live-upsell-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => true,
            'is_returned_upsell' => false,
            'amount'             => 800.0,
            'status_code'        => 2,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        // Was a genuine upsell, later returned/cancelled in Pancake — is_upsell
        // reset to false, is_returned_upsell set instead. Must still count.
        Order::create([
            'pancake_order_id'   => 'returned-upsell-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => false,
            'is_returned_upsell' => true,
            'amount'             => 1200.0,
            'status_code'        => 4,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('tsaLeaderboard', function ($leaderboard) use ($shift) {
            $row = $leaderboard->firstWhere('tsa_name', $shift->tsa_key);
            return $row
                && $row->upsell_count === 2
                && (float) $row->upsell_sales === 2000.0;
        });
    }

    /**
     * Confirmed live (2026-09-02): Katherine showed 16 upsells on this
     * leaderboard for a given day, but 15 on her own TSA Performance page.
     * Root cause: TSA Performance (ProductPerformance::tally()) drops any
     * order with status_code 7 (Deleted — "no longer exists in Pancake at
     * all") before counting anything; this leaderboard's upsell count/sales
     * never applied that same exclusion, so a Deleted order that had been
     * genuinely tagged as an upsell before deletion (order #1362095,
     * is_upsell_on_voided_order=true) still inflated the count here.
     */
    public function test_leaderboard_upsell_count_excludes_a_deleted_order(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'           => 'live-upsell-2',
            'team'                       => 'SH Naturals',
            'tsa_name'                   => $shift->tsa_key,
            'is_upsell'                  => true,
            'amount'                     => 900.0,
            'status_code'                => 2,
            'pancake_created_at'         => now(),
            'synced_at'                  => now(),
        ]);

        // Deleted in Pancake (status_code 7), but was genuinely tagged as an
        // upsell before deletion — same shape as real order #1362095.
        Order::create([
            'pancake_order_id'           => 'deleted-upsell-1',
            'team'                       => 'SH Naturals',
            'tsa_name'                   => $shift->tsa_key,
            'is_upsell'                  => false,
            'is_upsell_on_voided_order'  => true,
            'amount'                     => 1000.0,
            'status_code'                => 7,
            'pancake_created_at'         => now(),
            'synced_at'                  => now(),
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('tsaLeaderboard', function ($leaderboard) use ($shift) {
            $row = $leaderboard->firstWhere('tsa_name', $shift->tsa_key);
            return $row
                && $row->upsell_count === 1
                && (float) $row->upsell_sales === 900.0;
        });
    }

    /**
     * Confirmed live (2026-09-02): Grace showed 8 upsells on this leaderboard
     * for a given day, but 7 on TSA Performance's ALL view for the same day.
     * Root cause: this leaderboard groups $dayOrders by tsa_name alone, with
     * no check that an order's OWN team column actually matches that TSA's
     * roster team — TsaPerformanceController::indexAll() already guards
     * against exactly this (see its own comment: it groups by order.team
     * FIRST, then tsa_name within that team's own subset), added specifically
     * because a combo order can carry another team's product yet still be
     * tagged with a TSA's name. This leaderboard never got the equivalent
     * fix, so a cross-team order (tsa_name = this TSA, but team = the OTHER
     * team) still inflated her count here.
     */
    public function test_leaderboard_upsell_count_excludes_a_cross_team_order(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'own-team-upsell-1',
            'team'               => $shift->team,
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => true,
            'amount'             => 700.0,
            'status_code'        => 2,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        // Tagged with this TSA's name, but its OWN team column is the OTHER
        // team — a real order shape confirmed live (a combo SKU bundling
        // another team's product can still carry this TSA's tag).
        Order::create([
            'pancake_order_id'   => 'cross-team-upsell-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => true,
            'amount'             => 500.0,
            'status_code'        => 2,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('dashboard', ['team' => 'all']));

        $response->assertOk();
        $response->assertViewHas('tsaLeaderboard', function ($leaderboard) use ($shift) {
            $row = $leaderboard->firstWhere('tsa_name', $shift->tsa_key);
            return $row
                && $row->upsell_count === 1
                && (float) $row->upsell_sales === 700.0;
        });
    }

    public function test_top_tsa_spotlight_also_includes_returned_upsells(): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'   => 'returned-upsell-2',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => false,
            'is_returned_upsell' => true,
            'amount'             => 500.0,
            'status_code'        => 5,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('topTsa', fn ($top) => $top && $top->tsa_name === $shift->tsa_key && $top->upsell_count === 1);
    }
}
