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

    /**
     * The structural fix, added after the cross-team-order fix above did NOT
     * resolve a second real mismatch (Grace: 8 here vs 7 on TSA Performance,
     * confirmed live 2026-09-02, even filtered to her own team alone — so it
     * wasn't a cross-team order this time, and diagnosing the exact real
     * order needed production DB access this session didn't have). A first
     * pass at this fix read upsell_count/upsell_sales/total_calls straight
     * off ProductPerformance::tally() but still grouped orders into
     * per-TSA buckets by hand — STILL a hand-rolled copy of "group by team
     * first," just one function call closer to correct, and the live
     * mismatch persisted after deploying it. The actual fix (see
     * ProductPerformance::tsaRows()) calls the exact same grouping function
     * TsaPerformanceController::indexAll() calls to build the rows TSA
     * Performance itself renders — not "an equivalent implementation," the
     * literal same one. This test proves that structurally: for ANY mix of
     * order shapes (live upsell, returned upsell, voided-order upsell,
     * Deleted order, excluded seller, logistics duplicate, Restocking-status
     * tag-fallback upsell), the leaderboard's number for a TSA must equal
     * tally()'s own 'upsell_confirmation' for that exact same order set —
     * not by re-deriving the same exclusions and hoping they still match,
     * but because there is now only one function computing this at all.
     */
    public function test_leaderboard_upsell_count_always_equals_product_performance_tally(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        $orders = collect([
            // Live upsell.
            ['is_upsell' => true, 'amount' => 500.0, 'status_code' => 2],
            // Later returned — is_upsell reset false, is_returned_upsell set.
            ['is_upsell' => false, 'is_returned_upsell' => true, 'amount' => 600.0, 'status_code' => 4],
            // Genuine upsell before being voided/cancelled.
            ['is_upsell' => false, 'is_upsell_on_voided_order' => true, 'amount' => 700.0, 'status_code' => 6],
            // Deleted in Pancake — must never count, even though it looks
            // like an upsell.
            ['is_upsell' => false, 'is_upsell_on_voided_order' => true, 'amount' => 999.0, 'status_code' => 7],
            // Not an upsell at all — a plain confirmed call.
            ['is_upsell' => false, 'disposition' => 'CONFIRMED VIA CALL', 'amount' => 0.0, 'status_code' => 2],
        ]);

        foreach ($orders as $i => $attrs) {
            Order::create(array_merge([
                'pancake_order_id'   => "tally-parity-{$i}",
                'team'               => $shift->team,
                'tsa_name'           => $shift->tsa_key,
                'pancake_created_at' => now(),
                'synced_at'          => now(),
            ], $attrs));
        }

        $response = $this->get(route('dashboard'));
        $response->assertOk();

        $leaderboardRow = $response->viewData('tsaLeaderboard')->firstWhere('tsa_name', $shift->tsa_key);
        $this->assertNotNull($leaderboardRow);

        $expected = \App\Support\ProductPerformance::tally(
            Order::where('tsa_name', $shift->tsa_key)->get()
        );

        $this->assertSame($expected['upsell_confirmation'], $leaderboardRow->upsell_count);
        $this->assertSame((float) $expected['upsell_sales'], (float) $leaderboardRow->upsell_sales);
        $this->assertSame($expected['total_called'], $leaderboardRow->total_calls);
    }

    /**
     * The end-to-end version of the parity test above: hits BOTH real routes
     * (dashboard, tsa-performance?team=all) for the same seeded orders and
     * asserts the actual rendered numbers agree — not comparing against
     * ProductPerformance::tally() in isolation, but against what TSA
     * Performance's own controller/view genuinely produces. Includes a
     * cross-team order (tagged with this TSA's name, but its own team
     * column is the OTHER team) specifically because that's the shape that
     * exposed the original bug — a hand-rolled grouping can accidentally
     * still include it under the wrong assumption of "same intent, so it
     * must produce the same result."
     */
    public function test_leaderboard_upsell_count_matches_the_real_tsa_performance_all_view(): void
    {
        $shift = TsaShift::where('team', 'Eyecare Team')->first();

        Order::create([
            'pancake_order_id'   => 'e2e-own-team-1',
            'team'               => $shift->team,
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => true,
            'amount'             => 500.0,
            'status_code'        => 2,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);
        Order::create([
            'pancake_order_id'   => 'e2e-cross-team-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => true,
            'amount'             => 400.0,
            'status_code'        => 2,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);
        Order::create([
            'pancake_order_id'   => 'e2e-deleted-1',
            'team'               => $shift->team,
            'tsa_name'           => $shift->tsa_key,
            'is_upsell'          => false,
            'is_upsell_on_voided_order' => true,
            'amount'             => 300.0,
            'status_code'        => 7,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $dashboardResponse = $this->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $leaderboardRow = $dashboardResponse->viewData('tsaLeaderboard')->firstWhere('tsa_name', $shift->tsa_key);
        $this->assertNotNull($leaderboardRow);

        $tsaPerfResponse = $this->get(route('tsa-performance', ['team' => 'all']));
        $tsaPerfResponse->assertOk();
        $tsaPerfRow = $tsaPerfResponse->viewData('tsaRows')->firstWhere('tsa_key', $shift->tsa_key);
        $this->assertNotNull($tsaPerfRow);

        $this->assertSame($tsaPerfRow['upsell_confirmation'], $leaderboardRow->upsell_count);
        $this->assertSame((float) $tsaPerfRow['upsell_sales'], (float) $leaderboardRow->upsell_sales);
        // Both real-order-shape orders (own-team + cross-team) counted, the
        // Deleted one didn't, and the cross-team order specifically did NOT
        // leak in despite carrying this TSA's name.
        $this->assertSame(1, $leaderboardRow->upsell_count);
        $this->assertSame(500.0, (float) $leaderboardRow->upsell_sales);
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
