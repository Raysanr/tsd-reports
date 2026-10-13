<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Confirmed live (2026-08-22): when the warehouse/logistics side creates a
 * second, duplicate order for the same real lead, Pancake staff flag it by
 * writing "DUPLICATED BY LOGISTICS" into the order's note_print ("For
 * printing") field — 215 such orders existed in the shop's recent history.
 *
 * REVERSED 2026-10-13 (explicit request: "when there's now duplicated by
 * logistics note make it included to the data") — is_duplicated_by_logistics
 * is still recorded (for history/future reference) but no longer suppresses
 * $hasUpsellTag at sync time, same reversal already shipped for every
 * READ-time exclusion (ProductPerformance's own reject() calls) the same
 * day. Root-caused live, order #1375008/#1375013 (Marsha Arnecilla): this
 * sync-time half of the exclusion was the one piece the earlier same-day fix
 * missed — it silently kept is_upsell_on_voided_order/is_restocking_upsell
 * false for a duplicated-by-logistics Cancelled/Restocking order, which in
 * turn meant `amount` never got isolated to just the add-on price via
 * extractUpsellAmount() (fell back to the full order total instead),
 * directly inflating Expected Income's new automated Cancelled row.
 */
class SyncTodayOrdersDuplicatedByLogisticsTest extends TestCase
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

    public function test_a_note_print_flagged_as_duplicated_by_logistics_still_counts_as_a_real_upsell(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1354614,
            'status'      => 2,
            'total_price' => 900,
            'inserted_at' => '2026-08-19T06:00:00',
            'updated_at'  => '2026-08-19T06:00:00',
            'note'        => 'nagpapacancel ng order si cx',
            'note_print'  => 'DUPLICATED BY LOGISTICS',
            'tags'        => [
                ['id' => 1, 'name' => 'GEMMA'],
                ['id' => 2, 'name' => 'UPSELL TSD - Ear Relief Balm'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1, 'assigning_seller' => ['name' => 'Gemma De Guzman']],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 400], 'quantity' => 1, 'assigning_seller' => ['name' => 'Gemma De Guzman']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-19']);

        $order = Order::where('pancake_order_id', '1354614')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_duplicated_by_logistics, 'still recorded, for history/future reference');
        $this->assertTrue($order->is_upsell, 'a duplicated-by-logistics note no longer suppresses a genuine upsell');
        $this->assertFalse($order->is_cancelled_upsell);
        $this->assertFalse($order->is_returned_upsell);
        $this->assertFalse($order->is_restocking_upsell);
    }

    /** Same note, but the order is Cancelled(6) instead of active — the
     *  isolated add-on price must still be computed (extractUpsellAmount(),
     *  not the full order total) and is_upsell_on_voided_order must be set,
     *  same as any other genuine Cancelled upsell. Root-caused live, order
     *  #1375008 (Marsha): before this fix, amount stored the FULL 1,500
     *  order total (3 Pterylief + 1 Haplunas Balm) instead of just the
     *  500 Haplunas Balm add-on. */
    public function test_a_duplicated_by_logistics_cancelled_order_still_isolates_its_upsell_amount(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1375008,
            'status'      => 6,
            'total_price' => 1500,
            'inserted_at' => '2026-10-02T08:19:00',
            'updated_at'  => '2026-10-02T08:19:00',
            'note'        => 'UPSELL 500',
            'note_print'  => 'HIGH RTS. DUPLICATED BY LOGISTICS.',
            'tags'        => [
                ['id' => 1, 'name' => 'MARSHA'],
                ['id' => 2, 'name' => 'PTERYLIEF'],
                ['id' => 3, 'name' => 'UPSELL TSD - PTERYLIEF + HAPLUNAS'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Pterylief', 'retail_price' => 1000], 'quantity' => 1],
                ['variation_info' => ['name' => 'Haplunas Balm', 'retail_price' => 500], 'quantity' => 1],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-10-02']);

        $order = Order::where('pancake_order_id', '1375008')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_duplicated_by_logistics);
        $this->assertTrue($order->is_upsell_on_voided_order);
        $this->assertSame(500.0, (float) $order->amount, 'only the upsold add-on (Haplunas Balm) should count, not the full 1,500 order total');
    }

    public function test_the_phrase_in_the_internal_note_field_also_counts(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1354615,
            'status'      => 2,
            'total_price' => 500,
            'inserted_at' => '2026-08-19T06:00:00',
            'updated_at'  => '2026-08-19T06:00:00',
            'note'        => 'duplicated by logistics, see original #1354600',
            'note_print'  => '',
            'tags'        => [],
            'items'       => [['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1]],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-19']);

        $order = Order::where('pancake_order_id', '1354615')->first();

        $this->assertNotNull($order);
        $this->assertTrue($order->is_duplicated_by_logistics);
    }

    public function test_an_ordinary_order_with_no_such_note_is_unaffected(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id'          => 1354616,
            'status'      => 2,
            'total_price' => 900,
            'inserted_at' => '2026-08-19T06:00:00',
            'updated_at'  => '2026-08-19T06:00:00',
            'note'        => 'Customer wants callback tomorrow.',
            'note_print'  => 'REPEAT ORDER',
            'tags'        => [
                ['id' => 1, 'name' => 'GEMMA'],
                ['id' => 2, 'name' => 'UPSELL TSD - Ear Relief Balm'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1, 'assigning_seller' => ['name' => 'Gemma De Guzman']],
                ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 400], 'quantity' => 1, 'assigning_seller' => ['name' => 'Gemma De Guzman']],
            ],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-08-19']);

        $order = Order::where('pancake_order_id', '1354616')->first();

        $this->assertNotNull($order);
        $this->assertFalse($order->is_duplicated_by_logistics);
        $this->assertTrue($order->is_upsell);
    }
}
