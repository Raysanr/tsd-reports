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
        Order::create([
            'pancake_order_id' => 'restock-1', 'team' => 'SH Naturals', 'tsa_name' => 'Gemma',
            'is_upsell' => false, 'is_restocking_upsell' => true, 'amount' => 5000.0,
            'restocking_upsell_amount' => 300.0, 'status_code' => 11,
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

    public function test_todays_tsa_leaderboard_also_folds_in_restocking_when_on(): void
    {
        $this->seedOrders(); // Gemma: 1 confirmed upsell (₱1000) + 1 restocking (₱300)

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
}
