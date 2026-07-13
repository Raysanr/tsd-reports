<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTodayOrdersFailedDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOrder(array $tags, array $overrides = []): array
    {
        return array_merge([
            'id'          => 1328510,
            'status'      => 2, // Shipped — not one of Order::VOID_STATUSES
            'inserted_at' => '2026-07-11T05:27:14.000000',
            'updated_at'  => '2026-07-11T05:27:14.000000',
            'tags'        => array_map(fn($name) => ['id' => 1, 'name' => $name], $tags),
            'items'       => [
                ['product_name' => 'Pterygium', 'variation_info' => ['name' => 'Pterygium', 'retail_price' => 200], 'quantity' => 1],
                ['product_name' => 'Lumicare Oil', 'variation_info' => ['name' => 'Lumicare Oil', 'retail_price' => 1000], 'quantity' => 1],
            ],
            'total_price' => 1200,
            'histories'   => [],
        ], $overrides);
    }

    /**
     * Root-caused 2026-07-13: Dashboard "Total Cross-Sell Sales" for Jul 11 2026
     * showed 69 orders/₱51,400 vs. the team's manually reconciled tally of 49
     * orders/₱34,701. 20 of the 69 carried Pancake's own "Unreconciled" or
     * "Undeliverable" tag (added after the upsell tag once delivery/COD is
     * attempted) while sitting at an ordinary status (Shipped/Received, not one
     * of Order::VOID_STATUSES) — so the old is_upsell logic counted them as
     * completed cross-sells anyway.
     */
    public function test_order_tagged_unreconciled_is_excluded_from_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [$this->fakeOrder(['PTERYGIUM', 'MARISOL', 'UPSELL TSD - PTERYGIUM + LUMICARE', 'Unreconciled'])]])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-11']);

        $order = Order::where('pancake_order_id', '1328510')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
    }

    public function test_order_tagged_undeliverable_after_being_synced_as_live_upsell_carries_forward_amount(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Each sync call only ever issues one request here (page 1 returns fewer
        // than page_size, so the loop stops before requesting page 2) — both
        // Artisan::call()s below share this single sequence, one item per call.
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [$this->fakeOrder(['PTERYGIUM', 'MARISOL', 'UPSELL TSD - PTERYGIUM + LUMICARE'])]])
                ->push(['data' => [$this->fakeOrder(['PTERYGIUM', 'MARISOL', 'UPSELL TSD - PTERYGIUM + LUMICARE', 'Undeliverable'])]]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-11']);

        $order = Order::where('pancake_order_id', '1328510')->first();
        $this->assertTrue($order->is_upsell);
        $this->assertSame(1000.0, (float) $order->amount);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-11']);

        $order->refresh();
        $this->assertFalse($order->is_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(1000.0, (float) $order->cancelled_upsell_amount);
    }
}
