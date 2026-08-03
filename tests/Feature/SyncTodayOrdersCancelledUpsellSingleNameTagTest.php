<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirmed in production (order #1341487, 2026-08-02): remainingItemIsJustTheBase()
 * only recognized "UPSELL TSD - Base + Addon" tags (both names, joined by "+") as
 * proof the add-on was removed from a single-item order. Most real upsell tags name
 * ONLY the add-on — "UPSELL TSD - <Addon>" (no "+") or "Upsell TSD (<Addon>)" — so
 * that check silently never fired for them, and a ₱500 base-only order tagged
 * "UPSELL TSD - Sinuxyl Inhaler" (the actual add-on was never added, or was later
 * removed — Pancake's own order only ever showed the one ₱500 "Sinuxyl" item) got
 * counted as a live ₱500 upsell instead of a cancelled one.
 */
class SyncTodayOrdersCancelledUpsellSingleNameTagTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOnePage(array $order): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['data' => [$order]])
                ->push(['data' => []]),
        ]);
    }

    public function test_single_item_order_with_dash_addon_only_tag_is_not_counted_as_a_live_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1341487,
            'status'      => 8,
            'total_price' => 500,
            'cod'         => 500,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'MARIEL'],
                ['id' => 2, 'name' => 'Call in Progress (Sinuxyl Inhaler)'],
                ['id' => 3, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 500], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '1341487')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell, 'A single-item order whose only item is the base product must not count as a live upsell');
        $this->assertTrue($order->is_cancelled_upsell);
        $this->assertSame(500.0, (float) $order->amount);
    }

    public function test_single_item_order_with_parenthetical_addon_only_tag_is_not_counted_as_a_live_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1341488,
            'status'      => 8,
            'total_price' => 800,
            'cod'         => 800,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'KATHLEEN'],
                ['id' => 2, 'name' => 'Call in Progress (Audicure)'],
                ['id' => 3, 'name' => 'Upsell TSD (Ear Relief Balm)'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 800], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '1341488')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell);
        $this->assertTrue($order->is_cancelled_upsell);
    }

    /** A genuinely live single-item upsell order — the one item IS the add-on
     *  itself (no base item at all in the cart) — must still count normally.
     *  Guards against the new "does the sole item match the addon name" check
     *  being backwards. */
    public function test_single_item_order_where_the_item_is_genuinely_the_addon_still_counts_as_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1341999,
            'status'      => 2,
            'total_price' => 1000,
            'inserted_at' => '2026-08-02T11:13:00',
            'updated_at'  => '2026-08-02T11:13:00',
            'tags'        => [
                ['id' => 1, 'name' => 'MARIEL'],
                ['id' => 2, 'name' => 'Call in Progress (Sinuxyl Inhaler)'],
                ['id' => 3, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl Inhaler', 'retail_price' => 1000], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-02']);

        $order = Order::where('pancake_order_id', '1341999')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_upsell);
        $this->assertFalse($order->is_cancelled_upsell);
    }
}
