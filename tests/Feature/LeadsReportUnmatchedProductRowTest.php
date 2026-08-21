<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Explicit request (2026-08-21): the visible product rows must sum to
 * exactly Grand Total, no exceptions — "the total should be the plus of
 * all this". Root cause: Grand Total counts every order in the team+date
 * range (matching Dashboard), but a product row only shows orders that
 * actually match one of the products configured in Product Management —
 * an order for a genuinely untracked product (no Product row, or its
 * text/tags don't match any configured keyword/alias) counted toward
 * Grand Total with nowhere to appear, the same class of gap TSA
 * Performance's "Unassigned" row already solves for orders with no TSA.
 * An "Other / Unmatched Product" row now catches these the same way, so
 * summing every visible row (real products + this one) always equals
 * Grand Total exactly — and clicking into it shows exactly which orders
 * and product names need adding to Product Management.
 */
class LeadsReportUnmatchedProductRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_single_team_view_surfaces_an_unmatched_order_in_its_own_row(): void
    {
        Order::create([
            'pancake_order_id' => 'matched-1', 'team' => 'SH Naturals', 'product' => 'Sinuxyl',
            'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'unmatched-1', 'team' => 'SH Naturals', 'product' => 'Scar Erase',
            'base_product' => 'Scar Erase', 'raw_tags' => ['CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $productTables = $response->viewData('productTables');
        $grandTotal    = $response->viewData('grandTotal');

        $other = $productTables->first(fn ($t) => $t['product']->id === 'unmatched');
        $this->assertNotNull($other, 'expected an Other/Unmatched Product row');
        $this->assertSame(1, $other['total']['total']);

        $this->assertSame($grandTotal['total'], $productTables->sum(fn ($t) => $t['total']['total']));
        $this->assertSame(2, $grandTotal['total']);
    }

    public function test_all_view_surfaces_an_unmatched_order_and_rows_still_sum_to_grand_total(): void
    {
        Order::create([
            'pancake_order_id' => 'matched-all-1', 'team' => 'SH Naturals', 'product' => 'Sinuxyl',
            'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'unmatched-all-1', 'team' => 'Eyecare Team', 'product' => 'Random Untracked Thing',
            'base_product' => 'Random Untracked Thing', 'raw_tags' => ['CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $productRows = $response->viewData('productRows');
        $grandTotal  = $response->viewData('grandTotal');

        $other = $productRows->firstWhere('product_id', 'unmatched');
        $this->assertNotNull($other, 'expected an Other/Unmatched Product row');
        $this->assertSame(1, $other['total']);

        $this->assertSame($grandTotal['total'], $productRows->sum('total'));
        $this->assertSame(2, $grandTotal['total']);
    }

    public function test_drilldown_on_the_unmatched_row_returns_exactly_that_order(): void
    {
        Order::create([
            'pancake_order_id' => 'matched-dd-1', 'team' => 'SH Naturals', 'product' => 'Sinuxyl',
            'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);
        Order::create([
            'pancake_order_id' => 'unmatched-dd-1', 'team' => 'SH Naturals', 'product' => 'Scar Erase',
            'base_product' => 'Scar Erase', 'raw_tags' => ['CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->getJson(route('leads-report.drilldown', [
            'team' => 'sh-naturals', 'product' => 'unmatched', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertSame(['unmatched-dd-1'], $ids->all());
    }

    public function test_no_unmatched_row_when_every_order_matches_a_product(): void
    {
        Order::create([
            'pancake_order_id' => 'matched-only-1', 'team' => 'SH Naturals', 'product' => 'Sinuxyl',
            'raw_tags' => ['SINUXYL', 'CONFIRMED VIA CALL'], 'disposition' => 'CONFIRMED VIA CALL',
            'is_upsell' => false, 'status_code' => 1, 'pancake_created_at' => now(), 'synced_at' => now(),
        ]);

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $productTables = $response->viewData('productTables');
        $this->assertNull($productTables->first(fn ($t) => $t['product']->id === 'unmatched'));
    }
}
