<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request: a toggle so Total Cross-Sell Sales can optionally include
 * Restocking orders' revenue instead of always excluding it — once folded in,
 * the Total Restocking card blanks its own amount instead of double-showing it.
 */
class DashboardIncludeRestockingToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function seedOrders(): void
    {
        Order::create([
            'pancake_order_id'   => 'confirmed-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell'          => true, 'amount' => 1000.0, 'status_code' => 1,
            'pancake_created_at' => '2026-07-22 10:00:00', 'pancake_inserted_at' => '2026-07-22 10:00:00',
            'synced_at'          => now(),
        ]);
        // raw_tags set (2026-09-06 fix) — matches the real shape
        // is_restocking_upsell can actually take via the sync
        // (SyncTodayOrders::handle()'s own $isRestockingUpsell assignment
        // is status===11 AND hasUpsellTag, so a Restocking order flagged
        // this way always carries a real upsell tag in production).
        Order::create([
            'pancake_order_id' => 'restock-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 5000.0,
            'restocking_upsell_amount' => 300.0, 'status_code' => 11,
            'raw_tags' => ['UPSELL TSD - SINUXYL'],
            'pancake_created_at' => '2026-07-22 11:00:00', 'pancake_inserted_at' => '2026-07-22 11:00:00',
            'synced_at' => now(),
        ]);
    }

    public function test_defaults_off_excluding_restocking_from_cross_sell_sales(): void
    {
        $this->seedOrders();

        $response = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));

        $response->assertOk();
        $response->assertViewHas('includeRestocking', false);
        $response->assertViewHas('stats', function ($stats) {
            return (float) $stats['total_sales'] === 1000.0
                && $stats['total_orders'] === 1
                // Total Restocking's own number is unaffected either way.
                && (float) $stats['restocking_value'] === 300.0
                && $stats['restocking_count'] === 1;
        });
    }

    public function test_when_on_folds_restocking_revenue_and_count_into_cross_sell_sales(): void
    {
        $this->seedOrders();

        $response = $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $response->assertOk();
        $response->assertViewHas('includeRestocking', true);
        $response->assertViewHas('stats', function ($stats) {
            return (float) $stats['total_sales'] === 1300.0 // 1000 confirmed + 300 restocking
                && $stats['total_orders'] === 2
                // Total Restocking still shows its own real number, not zeroed out.
                && (float) $stats['restocking_value'] === 300.0
                && $stats['restocking_count'] === 1;
        });
    }

    public function test_toggle_state_persists_in_session_across_requests(): void
    {
        $this->seedOrders();

        $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]))->assertOk();

        // No include_restocking param this time — should remember the ON choice.
        $response = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));

        $response->assertOk();
        $response->assertViewHas('includeRestocking', true);
        $response->assertViewHas('stats', fn ($stats) => (float) $stats['total_sales'] === 1300.0);
    }

    /** Bug fix (2026-09-06): tally()'s own 'upsell_confirmation' is ALWAYS
     *  inclusive of a genuinely-tagged Restocking order — it has no
     *  concept of this toggle at all. OFF must now genuinely SUBTRACT that
     *  order back out (1 upsell, ₱1000 — the confirmed one only), not just
     *  fail to add anything extra on top; ON matches tally()'s own
     *  inclusive number (2 upsells, ₱1300), which is also exactly what
     *  POS's own tag filter would show for the same TSA/day. */
    public function test_todays_tsa_leaderboard_toggle_genuinely_excludes_restocking_when_off(): void
    {
        $this->seedOrders(); // Gemma: 1 confirmed upsell (₱1000) + 1 tagged restocking upsell (₱300)

        $off = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));
        $off->assertViewHas('tsaLeaderboard', function ($rows) {
            $gemma = $rows->firstWhere('tsa_name', 'Gemma');
            return $gemma && $gemma->upsell_count === 1 && (float) $gemma->upsell_sales === 1000.0;
        });

        $on = $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));
        $on->assertViewHas('tsaLeaderboard', function ($rows) {
            $gemma = $rows->firstWhere('tsa_name', 'Gemma');
            return $gemma && $gemma->upsell_count === 2 && (float) $gemma->upsell_sales === 1300.0;
        });
        // Top TSA Today spotlight is just the leaderboard's own top row — should follow too.
        $on->assertViewHas('topTsa', fn ($top) => $top && $top->tsa_name === 'Gemma' && (float) $top->upsell_sales === 1300.0);
    }

    public function test_the_total_restocking_card_blanks_its_amount_once_folded_into_cross_sell_sales(): void
    {
        $this->seedOrders();

        $off = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));
        $off->assertDontSee('Included in Cross-Sell Sales above');

        $on = $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));
        $on->assertSee('Included in Cross-Sell Sales above');
    }

    /** Bug fix (2026-09-06, round 1): a Restocking order that carries a
     *  genuine upsell tag (real production shape — is_restocking_upsell is
     *  only ever set true alongside a real tag, see
     *  SyncTodayOrders::handle()'s own $isRestockingUpsell assignment) is
     *  already counted once inside ProductPerformance::tally()'s
     *  upsell_confirmation via Order::isBroadRealUpsell()'s tag-fallback
     *  branch — status 11 isn't excluded from tally()'s reject() filter.
     *  ON must show exactly tally()'s own inclusive number (1 here) — no
     *  addition happens on the ON path anymore at all (round 2 of this fix
     *  removed the "add every is_restocking_upsell order on top" logic
     *  entirely, since it could only ever double-count a tagged order —
     *  see the OFF-side test below for where the real toggle behavior now
     *  lives). Confirmed live: Joana showed 8 upsells here vs. TSA
     *  Performance's correct 7 for the same day before round 1's fix. */
    public function test_a_tagged_restocking_upsell_is_counted_once_when_the_toggle_is_on(): void
    {
        Order::create([
            'pancake_order_id' => 'joana-with-upsell-56', 'team' => 'Eyecare Team', 'tsa_name' => 'Joana',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'status_code' => 11,
            'amount' => 5000.0, 'restocking_upsell_amount' => 1000.0,
            'raw_tags' => ['UPSELL TSD - GINSENG SERUM'],
            'pancake_created_at' => '2026-07-22 10:00:00', 'pancake_inserted_at' => '2026-07-22 10:00:00',
            'synced_at' => now(),
        ]);

        $on = $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));

        $on->assertOk();
        $on->assertViewHas('tsaLeaderboard', function ($rows) {
            $joana = $rows->firstWhere('tsa_name', 'Joana');
            return $joana && $joana->upsell_count === 1 && (float) $joana->upsell_sales === 1000.0;
        });
    }

    /** Bug fix (2026-09-06, round 2): round 1 above stopped ON from
     *  double-counting a tagged Restocking upsell, but left OFF unable to
     *  ever actually EXCLUDE it — tally()'s own upsell_confirmation always
     *  includes it regardless of the toggle, so OFF kept showing the exact
     *  same number as ON with nothing subtracted. Confirmed live: Joana's
     *  real Sep 5 data had 7 upsells total in POS's own tag filter
     *  (including exactly 1 Restocking-tagged one) — ON correctly showed
     *  7 (matching tally()'s inclusive count), but OFF also showed 7
     *  instead of the expected 6. This test reproduces that exact shape:
     *  1 confirmed upsell + 1 Restocking-tagged upsell, same as Joana's
     *  real data (scaled down to 2 orders instead of 7 for a minimal
     *  fixture) — OFF must now genuinely subtract the tagged Restocking
     *  order back out. */
    public function test_off_genuinely_excludes_a_tagged_restocking_upsell_not_just_skips_adding_it(): void
    {
        Order::create([
            'pancake_order_id' => 'joana-confirmed-1', 'team' => 'Eyecare Team', 'tsa_name' => 'Joana',
            'is_upsell' => true, 'amount' => 500.0, 'status_code' => 1,
            'pancake_created_at' => '2026-07-22 09:00:00', 'pancake_inserted_at' => '2026-07-22 09:00:00',
            'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'joana-with-upsell-56', 'team' => 'Eyecare Team', 'tsa_name' => 'Joana',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'status_code' => 11,
            'amount' => 5000.0, 'restocking_upsell_amount' => 1000.0,
            'raw_tags' => ['UPSELL TSD - GINSENG SERUM'],
            'pancake_created_at' => '2026-07-22 10:00:00', 'pancake_inserted_at' => '2026-07-22 10:00:00',
            'synced_at' => now(),
        ]);

        // OFF first (the toggle's own value persists in session — see
        // DashboardController::index()'s own comment on that — so this
        // must run BEFORE the ON request below, not after, or it would
        // just re-read ON's still-sticky session value instead of a real
        // OFF request).
        $off = $this->get(route('dashboard', ['date_from' => '2026-07-22', 'date_to' => '2026-07-22']));
        $off->assertViewHas('tsaLeaderboard', function ($rows) {
            $joana = $rows->firstWhere('tsa_name', 'Joana');
            return $joana && $joana->upsell_count === 1 && (float) $joana->upsell_sales === 500.0;
        });

        $on = $this->get(route('dashboard', [
            'include_restocking' => '1', 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
        ]));
        $on->assertViewHas('tsaLeaderboard', function ($rows) {
            $joana = $rows->firstWhere('tsa_name', 'Joana');
            return $joana && $joana->upsell_count === 2 && (float) $joana->upsell_sales === 1500.0;
        });
    }
}
