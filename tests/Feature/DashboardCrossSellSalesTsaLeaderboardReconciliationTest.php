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
        Order::create([
            'pancake_order_id' => 'restock-attributed', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 5000.0,
            'restocking_upsell_amount' => 300.0, 'status_code' => 11,
            'pancake_created_at' => '2026-07-22 11:00:00', 'pancake_inserted_at' => '2026-07-22 11:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'restock-unclaimed', 'team' => 'SH Naturals', 'tsa_name' => null,
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 5000.0,
            'restocking_upsell_amount' => 250.0, 'status_code' => 11,
            'pancake_created_at' => '2026-07-22 12:00:00', 'pancake_inserted_at' => '2026-07-22 12:00:00',
            'synced_at' => now(),
        ]);

        $response = $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            return (float) $stats['total_sales'] === 300.0
                && $stats['total_orders'] === 1
                && (float) $stats['restocking_value'] === 300.0
                && $stats['restocking_count'] === 1;
        });
    }
}
