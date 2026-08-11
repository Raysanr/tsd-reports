<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real production bug (2026-08-11), Mariel/Aug 1: a Returned/Returning
 * upsell order (#1340544) synced with amount=1300 — the WHOLE order's
 * total_price — instead of 500, the real isolated add-on value already
 * sitting correctly in returned_upsell_amount. Every SUM(amount) query
 * scoped to Order::scopeRealUpsell() (Dashboard's Total Cross-Sell Sales,
 * Charts, TsaPerformanceController's upsell_sales) silently inherited that
 * ₱800 overcount for every order that was ever a real upsell later
 * returned. See SyncTodayOrders::handle()'s own comment on the fix.
 */
class SyncTodayOrdersReturnedUpsellAmountTest extends TestCase
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

    public function test_a_returned_upsell_orders_amount_is_the_isolated_add_on_price_not_the_whole_order_total(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1340544,
            'status'      => 4, // Returning — is_returned_upsell territory
            'total_price' => 1300, // base (800) + add-on (500)
            'cod'         => 1300,
            'inserted_at' => '2026-08-01T11:13:00',
            'updated_at'  => '2026-08-01T11:13:00',
            'tags' => [
                ['id' => 1, 'name' => 'MARIEL'],
                ['id' => 2, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1],
                ['variation_info' => ['name' => 'Sinuxyl Inhaler', 'retail_price' => 500], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-01']);

        $order = Order::where('pancake_order_id', '1340544')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell); // forced false by the void status, as always
        $this->assertTrue($order->is_returned_upsell);
        // The bug: this used to be 1300.0 (total_price).
        $this->assertSame(500.0, (float) $order->amount);
        $this->assertSame(500.0, (float) $order->returned_upsell_amount);
    }

    /** A genuinely non-upsell order in the same void status must still get
     *  the whole order's total — this fix only changes rows that actually
     *  carry an upsell tag (is_returned_upsell), not every Returning order. */
    public function test_a_non_upsell_order_in_the_same_void_status_still_gets_the_whole_order_total(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1340999,
            'status'      => 4, // Returning, but no upsell tag at all
            'total_price' => 900,
            'cod'         => 900,
            'inserted_at' => '2026-08-01T11:13:00',
            'updated_at'  => '2026-08-01T11:13:00',
            'tags' => [
                ['id' => 1, 'name' => 'MARIEL'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 900], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-01']);

        $order = Order::where('pancake_order_id', '1340999')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_returned_upsell);
        $this->assertSame(900.0, (float) $order->amount);
    }
}
