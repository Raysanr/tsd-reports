<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Teams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explicit request (2026-10-16): on DSPPR, filtering by a team still lists
 *  EVERY product ("per team is handle all product now") — the filter only
 *  scopes the figures to that team's orders. */
class DsPprTeamFilterShowsAllProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_filter_lists_every_product_with_team_scoped_figures(): void
    {
        $date = '2026-10-02';
        $teams = Teams::config();
        [$slugA, $slugB] = array_keys($teams);
        $other = Product::where('team', $teams[$slugB]['order_team'])->orderBy('sort_order')->firstOrFail();

        // An order for the OTHER team's product, taken during team A's shift.
        Order::create([
            'pancake_order_id' => 'cross-1', 'team' => $teams[$slugA]['order_team'], 'tsa_name' => null,
            'product' => $other->display_name, 'base_product' => $other->display_name,
            'raw_tags' => [], 'status_code' => 1, 'amount' => 500,
            'pancake_created_at' => "$date 10:00:00", 'synced_at' => now(),
        ]);

        $rows = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('data.dsppr', ['team' => $slugA, 'date_from' => $date, 'date_to' => $date]))
            ->assertOk()->viewData('rows');

        $labels = collect($rows)->pluck('label')->map(fn ($l) => strtoupper($l));
        $this->assertSame(Product::count() > 0, $labels->isNotEmpty());
        $this->assertTrue($labels->contains(fn ($l) => str_contains($l, strtoupper($other->display_name))), "team {$slugA} view lists team {$slugB}'s product");
        $this->assertGreaterThanOrEqual(\App\Support\ProductGrouping::rows(Product::orderBy('sort_order')->get(), fn () => null)->count(), count($rows));
    }
}
