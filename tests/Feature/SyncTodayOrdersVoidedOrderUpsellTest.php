<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real production case (2026-08-22), order #1353632, Angelica: a genuine
 * upsell (Ear Relief Balm, tagged "Upsell TSD (Ear Relief Balm)") on an
 * AudiCure order whose Pancake status went to Canceled afterward — Pancake
 * still shows the order and both line items intact, the upsell clearly
 * happened, but is_upsell is forced false for any VOID_STATUSES status and
 * Canceled wasn't one of the two exceptions (Returning/Returned, Restocking)
 * that already preserve a real upsell through a void status. Confirmed with
 * the user this should still count. Same shape/precedent as
 * SyncTodayOrdersReturnedUpsellAmountTest — see that file too.
 */
class SyncTodayOrdersVoidedOrderUpsellTest extends TestCase
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

    public function test_a_canceled_orders_genuine_upsell_still_counts_with_the_isolated_add_on_amount(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1353632,
            'status'      => 6, // Canceled
            'total_price' => 1000, // base (500) + add-on (500)
            'cod'         => 1000,
            'inserted_at' => '2026-08-20T08:26:00', // UTC — 16:26 Manila, same calendar day
            'updated_at'  => '2026-08-20T08:26:00',
            'tags' => [
                ['id' => 1, 'name' => 'ANGEL'],
                ['id' => 2, 'name' => 'Upsell TSD (Ear Relief Balm)'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 500], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-20']);

        $order = Order::where('pancake_order_id', '1353632')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell); // forced false by the void status, as always
        $this->assertFalse($order->is_returned_upsell);
        $this->assertTrue($order->is_upsell_on_voided_order);
        // The bug: this used to be 1000.0 (total_price) — the whole order,
        // not the isolated ₱500 upsell add-on.
        $this->assertSame(500.0, (float) $order->amount);
        $this->assertTrue(Order::isRealUpsell($order));
        $this->assertTrue(Order::isBroadRealUpsell($order));
    }

    /** A genuinely non-upsell order that happens to be Canceled must still
     *  get the whole order's total, not the isolated-amount treatment. */
    public function test_a_non_upsell_canceled_order_still_gets_the_whole_order_total(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1353999,
            'status'      => 6, // Canceled, but no upsell tag at all
            'total_price' => 900,
            'cod'         => 900,
            'inserted_at' => '2026-08-20T11:13:00',
            'updated_at'  => '2026-08-20T11:13:00',
            'tags' => [
                ['id' => 1, 'name' => 'ANGEL'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 900], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-20']);

        $order = Order::where('pancake_order_id', '1353999')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_upsell_on_voided_order);
        $this->assertSame(900.0, (float) $order->amount);
        $this->assertFalse(Order::isRealUpsell($order));
    }

    /** A Returned (not Canceled) upsell must still resolve through its own
     *  existing is_returned_upsell path, not the new Canceled-only flag —
     *  the two must never both end up true for the same order. */
    public function test_a_returned_upsell_is_not_also_flagged_as_voided_order_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1354000,
            'status'      => 4, // Returning
            'total_price' => 1300,
            'cod'         => 1300,
            'inserted_at' => '2026-08-20T11:13:00',
            'updated_at'  => '2026-08-20T11:13:00',
            'tags' => [
                ['id' => 1, 'name' => 'ANGEL'],
                ['id' => 2, 'name' => 'UPSELL TSD - Sinuxyl Inhaler'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Sinuxyl', 'retail_price' => 800], 'quantity' => 1],
                ['variation_info' => ['name' => 'Sinuxyl Inhaler', 'retail_price' => 500], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-20']);

        $order = Order::where('pancake_order_id', '1354000')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_returned_upsell);
        $this->assertFalse($order->is_upsell_on_voided_order);
    }

    /** Excluded-seller gate still wins even for a Canceled order — a false
     *  upsell signal from a known non-TSA account shouldn't be resurrected
     *  just because the order later went Canceled. */
    public function test_an_excluded_seller_canceled_order_is_still_not_a_real_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        config(['excluded_upsell_sellers' => ['ralph cruz']]);

        $this->fakeOnePage([
            'id'          => 1354001,
            'status'      => 6, // Canceled
            'total_price' => 1000,
            'cod'         => 1000,
            'inserted_at' => '2026-08-20T11:13:00',
            'updated_at'  => '2026-08-20T11:13:00',
            'tags' => [
                ['id' => 1, 'name' => 'ANGEL'],
                ['id' => 2, 'name' => 'Upsell TSD (Ear Relief Balm)'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1],
                [
                    'variation_info'  => ['name' => 'Ear Relief Balm', 'retail_price' => 500],
                    'quantity'        => 1,
                    'assigning_seller' => ['name' => 'Ralph Cruz'],
                ],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-20']);

        $order = Order::where('pancake_order_id', '1354001')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->excluded_upsell_seller);
        $this->assertFalse($order->is_upsell_on_voided_order);
        $this->assertFalse(Order::isRealUpsell($order));
        $this->assertFalse(Order::isBroadRealUpsell($order));
    }
}
