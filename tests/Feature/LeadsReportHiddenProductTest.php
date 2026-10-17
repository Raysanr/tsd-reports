<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsReportHiddenProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_hidden_product_with_no_leads_in_range_is_dropped_from_the_team_view(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', function ($tables) {
            return $tables->doesntContain(fn($t) => $t['product']->display_name === 'SINUXYL');
        });
    }

    private function seedSinuxylOrder(string $id, array $extraTags = [], string $product = 'Sinuxyl'): void
    {
        $shift = TsaShift::where('team', 'SH Naturals')->first();
        Order::create([
            'pancake_order_id'   => $id,
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'product'            => $product,
            'raw_tags'           => array_merge(['SINUXYL', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'], $extraTags),
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);
    }

    // Explicit request, 2026-10-17: hiding a product removes it from every
    // report even for a range where it HAD leads, and its leads drop out of
    // every total too (replaces the old "still shows when it has leads" rule).
    public function test_hidden_product_with_leads_in_range_is_dropped_from_the_team_view_and_its_total(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();
        $this->seedSinuxylOrder('hidden-sinuxyl-1');

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', fn ($tables) => $tables->doesntContain(fn($t) => $t['product']->display_name === 'SINUXYL'));
        $response->assertViewHas('grandTotal', fn ($total) => $total['total'] === 0);
    }

    public function test_hidden_product_with_leads_in_range_is_dropped_from_the_all_view_and_its_total(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();
        $this->seedSinuxylOrder('hidden-sinuxyl-all-1');

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productRows', fn ($rows) => $rows->doesntContain(fn($r) => $r['display_name'] === 'SINUXYL'));
        $response->assertViewHas('grandTotal', fn ($total) => $total['total'] === 0);
    }

    public function test_unhidden_product_with_leads_counts_normally(): void
    {
        $this->seedSinuxylOrder('visible-sinuxyl-1');

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'sh-naturals', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productTables', fn ($tables) => $tables->contains(fn($t) => $t['product']->display_name === 'SINUXYL'));
        $response->assertViewHas('grandTotal', fn ($total) => $total['total'] === 1);
    }

    public function test_hidden_product_with_no_orders_in_range_is_dropped_from_the_all_view(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->doesntContain(fn($r) => $r['display_name'] === 'SINUXYL');
        });
    }

    public function test_normal_product_with_no_orders_still_shows_on_the_all_view(): void
    {
        // SINUXYL is left visible (is_hidden stays false) and has zero orders in
        // range — only is_hidden drops a row, so a merely-quiet-but-visible
        // product must never be dropped.
        $today = now()->toDateString();
        $response = $this->get(route('leads-report', [
            'team' => 'all', 'range' => 'dates', 'date_from' => $today, 'date_to' => $today,
        ]));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->contains(fn($r) => $r['display_name'] === 'SINUXYL');
        });
    }
}
