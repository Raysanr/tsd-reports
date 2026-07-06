<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TsaPerformanceProductFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_products_come_from_the_database_not_config(): void
    {
        Product::create(['display_name' => 'Brand New Product', 'team' => 'SH Naturals', 'sort_order' => 99]);

        $response = $this->get(route('tsa-performance', ['team' => 'sh-naturals']));

        $response->assertOk();
        $response->assertViewHas('availableProducts', function ($products) {
            return $products->pluck('display_name')->contains('Brand New Product');
        });
    }

    public function test_product_filter_matches_via_the_products_table_match_keyword(): void
    {
        // CANPRO JUICE DRINK's match_keyword is "CANPRO" — an order tagged with the
        // longer real product name should still be found when filtering by it.
        $shift = TsaShift::where('team', 'SH Naturals')->first();

        Order::create([
            'pancake_order_id'   => 'test-canpro-1',
            'team'               => 'SH Naturals',
            'tsa_name'           => $shift->tsa_key,
            'disposition'        => 'CONFIRMED VIA CALL',
            'raw_tags'           => ['CANPRO JUICE DRINK', strtoupper($shift->tsa_key), 'CONFIRMED VIA CALL'],
            'is_upsell'          => false,
            'status_code'        => 1,
            'pancake_created_at' => now(),
            'synced_at'          => now(),
        ]);

        $response = $this->get(route('tsa-performance', [
            'team'    => 'sh-naturals',
            'product' => 'CANPRO JUICE DRINK',
            'date'    => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('totals', fn($totals) => $totals['total'] === 1);
    }
}
