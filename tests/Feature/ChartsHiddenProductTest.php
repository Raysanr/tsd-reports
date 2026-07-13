<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartsHiddenProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_hidden_product_with_no_orders_in_range_is_dropped_from_the_comparison_chart(): void
    {
        $product = Product::where('display_name', 'SINUXYL')->first();
        $product->is_hidden = true;
        $product->save();

        $response = $this->get(route('charts'));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->doesntContain(fn($r) => $r['display_name'] === 'SINUXYL');
        });
    }

    public function test_normal_product_with_no_orders_still_shows_on_the_comparison_chart(): void
    {
        // AUDICURE is left visible (is_hidden stays false) and has zero orders in
        // range — the reject() condition requires BOTH is_hidden and a zero total,
        // so a merely-quiet-but-visible product must never be dropped.
        $response = $this->get(route('charts'));

        $response->assertOk();
        $response->assertViewHas('productRows', function ($rows) {
            return $rows->contains(fn($r) => $r['display_name'] === 'AUDICURE');
        });
    }
}
