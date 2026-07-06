<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTodayOrdersProductTeamInferenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test for the Fix #15 team-inference fallback, now reading from
     * the products table instead of config/teams.php. A newly-added product (via
     * Product Management) with a custom match_keyword must be picked up on the
     * very next sync, with no code change.
     */
    public function test_new_product_added_via_product_management_is_recognized_immediately(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Simulates adding a brand-new product through the Product Management UI —
        // no TSA/seller match exists for this lead, only the product in its cart.
        Product::create([
            'display_name'  => 'Nutrilay Herbal Blend',
            'match_keyword' => 'NUTRILAY',
            'team'          => 'SH Naturals',
            'sort_order'    => 99,
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 9999001,
                        'status' => 0,
                        'inserted_at' => '2026-07-05T10:00:00',
                        'updated_at'  => '2026-07-05T10:00:00',
                        'tags' => [],
                        'items' => [[
                            'quantity' => 1,
                            'product_name' => 'Nutrilay Herbal Blend',
                            'variation_info' => ['name' => 'Nutrilay Herbal Blend', 'retail_price' => 500],
                        ]],
                        'histories' => [],
                    ]],
                ])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-05']);

        $order = Order::where('pancake_order_id', '9999001')->first();

        $this->assertNotNull($order);
        $this->assertNull($order->tsa_name, 'Nobody claimed this lead, so no TSA name should be invented');
        $this->assertSame('SH Naturals', $order->team);
    }
}
