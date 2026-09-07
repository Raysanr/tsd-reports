<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-caused 2026-08-21 (user report: Total Cross-Sell Sales showed
 * ₱33,001.00 for SH Naturals/yesterday while the TSA Leaderboard's own rows
 * summed to only ₱32,201.00). Cause: a real upsell (upsell tag present, e.g.
 * "UPSELL TSD - Sinuxyl Inhaler") whose order carries no TSA name tag and no
 * assigning_seller account match ends up with tsa_name = null
 * (SyncTodayOrders::extractTsaInfo()'s last-resort branch recovers the team
 * from the product but explicitly returns name => null — "nobody ever
 * claimed this lead"). The card's $upsells/$restocking queries had no
 * tsa_name filter, so this revenue was counted there; the Leaderboard's
 * ->whereNotNull('tsa_name')->groupBy('tsa_name') structurally has nowhere
 * to put it, so it never appeared in any TSA's row. Fix: the card now also
 * requires tsa_name to be set, so both figures describe the same set of
 * orders (TSA-attributed real upsells) and always reconcile.
 *
 * Superseded 2026-09-07 (user report, unrelated day: card showed ₱40,052 while
 * summing the leaderboard's own visible rows gave ₱33,301 — explicit request:
 * "i want only the total of all tsa upsells will be equal to the Total
 * Cross-Sell Sales", any date, not just "should usually match"). The card no
 * longer runs its own realUpsell()-scoped query at all — DashboardController::
 * index() now derives total_sales/total_orders as a straight sum over
 * $tsaLeaderboard's own upsell_sales/upsell_count, so it is structurally the
 * same number, not a separately-computed one that happens to agree. This
 * makes the Leaderboard's own upsell definition (Order::isBroadRealUpsell(),
 * via ProductPerformance::tally()/tsaRows()) the sole source of truth
 * everywhere — including for Restocking rows, which is why
 * test_restocking_toggle_also_excludes_unattributed_restocking_orders() below
 * was rewritten: a bare is_restocking_upsell=true row with no upsell tag/flag
 * proving it (isBroadRealUpsell's tag-fallback) never counted in the
 * Leaderboard, so it now correctly contributes ₱0 to the card too, even with
 * the Include Restocking toggle on — the old version of this test asserted
 * the opposite (any is_restocking_upsell=true row counts once the toggle is
 * on, tag or not), which is exactly the kind of independent-definition drift
 * this fix eliminates.
 */
class DashboardCrossSellSalesTsaLeaderboardReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_total_cross_sell_sales_excludes_a_real_upsell_with_no_tsa_attribution(): void
    {
        Order::create([
            'pancake_order_id'   => 'attributed-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell'          => true, 'amount' => 1000.0, 'status_code' => 1,
            'pancake_created_at' => '2026-07-22 10:00:00', 'pancake_inserted_at' => '2026-07-22 10:00:00',
            'synced_at'          => now(),
        ]);
        // Carries an upsell tag (is_upsell = true, real revenue) but nobody's
        // name tag/seller account was ever matched to it — same shape as
        // production order #1352836.
        Order::create([
            'pancake_order_id'   => 'unclaimed-1', 'team' => 'SH Naturals', 'tsa_name' => null,
            'is_upsell'          => true, 'amount' => 800.0, 'status_code' => 1,
            'pancake_created_at' => '2026-07-22 11:00:00', 'pancake_inserted_at' => '2026-07-22 11:00:00',
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            return (float) $stats['total_sales'] === 1000.0
                && $stats['total_orders'] === 1;
        });

        // The card and the Leaderboard now describe the same order set —
        // summing every leaderboard row's upsell_sales must equal the card.
        $response->assertViewHas('tsaLeaderboard', function ($rows) use ($response) {
            $leaderboardSum = (float) $rows->sum('upsell_sales');
            $cardTotal      = (float) $response->viewData('stats')['total_sales'];
            return $leaderboardSum === $cardTotal && $leaderboardSum === 1000.0;
        });
    }

    public function test_restocking_toggle_also_excludes_unattributed_restocking_orders(): void
    {
        // Genuinely tagged upsell (Order::hasUpsellTag()'s fallback branch,
        // via Order::isBroadRealUpsell()) sitting in Restocking status —
        // the Leaderboard's own tally()/tsaRows() recovers this one, so the
        // card (now a straight sum over the Leaderboard) must too.
        Order::create([
            'pancake_order_id' => 'restock-attributed', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 5000.0,
            'restocking_upsell_amount' => 300.0, 'status_code' => 11,
            'raw_tags' => ['UPSELL TSD - Sinuxyl Inhaler'],
            'pancake_created_at' => '2026-07-22 11:00:00', 'pancake_inserted_at' => '2026-07-22 11:00:00',
            'synced_at' => now(),
        ]);
        // No tsa_name — same "nobody ever claimed this lead" shape as the
        // first test above, so the Leaderboard has no row to put it in.
        Order::create([
            'pancake_order_id' => 'restock-unclaimed', 'team' => 'SH Naturals', 'tsa_name' => null,
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 5000.0,
            'restocking_upsell_amount' => 250.0, 'status_code' => 11,
            'raw_tags' => ['UPSELL TSD - Lumicare Oil'],
            'pancake_created_at' => '2026-07-22 12:00:00', 'pancake_inserted_at' => '2026-07-22 12:00:00',
            'synced_at' => now(),
        ]);
        // Bare is_restocking_upsell=true with NO upsell tag/flag at all —
        // isBroadRealUpsell() doesn't recognize this as a real upsell, so it
        // never reaches any Leaderboard row either; the card (2026-09-07:
        // "i want only the total of all tsa upsells will be equal to the
        // Total Cross-Sell Sales") must now agree and also show ₱0 for it,
        // even with the toggle on — this is a deliberate behavior change
        // from this test's own prior version, which asserted the opposite.
        Order::create([
            'pancake_order_id' => 'restock-untagged', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 5000.0,
            'restocking_upsell_amount' => 999.0, 'status_code' => 11,
            'pancake_created_at' => '2026-07-22 13:00:00', 'pancake_inserted_at' => '2026-07-22 13:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            // restocking_count/restocking_value come from a separate,
            // unrelated query ($restocking in DashboardController::index())
            // that also requires whereNotNull('tsa_name') — restock-unclaimed
            // (tsa_name=null) is excluded from these two same as it's
            // excluded from the Leaderboard, leaving just restock-attributed
            // (300) + restock-untagged (999) = 1299, count 2.
            return (float) $stats['total_sales'] === 300.0
                && $stats['total_orders'] === 1
                && (float) $stats['restocking_value'] === 1299.0
                && $stats['restocking_count'] === 2;
        });

        $response->assertViewHas('tsaLeaderboard', function ($rows) use ($response) {
            $leaderboardSum = (float) $rows->sum('upsell_sales');
            $cardTotal      = (float) $response->viewData('stats')['total_sales'];
            return $leaderboardSum === $cardTotal && $leaderboardSum === 300.0;
        });
    }
}
