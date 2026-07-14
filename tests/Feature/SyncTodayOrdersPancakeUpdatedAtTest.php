<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTodayOrdersPancakeUpdatedAtTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deliberately constructs an order with NO tag-history match (so
     * pancake_created_at/workedAt falls back to insertion time — 2026-07-10) and a
     * raw `updated_at` on a completely different day (2026-07-14), so the two
     * columns can only match this test's assertions if pancake_updated_at is
     * genuinely storing the raw, unadjusted Pancake value rather than accidentally
     * mirroring pancake_created_at.
     */
    public function test_pancake_updated_at_stores_the_raw_value_independent_of_pancake_created_at(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 1400001,
                        'status' => 3,
                        'inserted_at' => '2026-07-10T01:00:00.000000',
                        'updated_at' => '2026-07-14T05:30:00.000000',
                        'tags' => [],
                        'items' => [],
                        'histories' => [],
                    ]],
                ])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-14']);

        $order = Order::where('pancake_order_id', '1400001')->first();

        $this->assertNotNull($order, 'Order should have been synced');
        $this->assertSame('2026-07-10', $order->pancake_created_at->setTimezone('Asia/Manila')->toDateString());
        $this->assertSame('2026-07-14', $order->pancake_updated_at->setTimezone('Asia/Manila')->toDateString());
    }
}
