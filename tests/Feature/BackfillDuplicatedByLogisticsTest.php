<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One-off backfill (2026-08-22) for the 215 orders already synced before
 * Order::isDuplicatedByLogistics() existed — SyncTodayOrders now sets the
 * flag going forward on its own, but rows synced before that check existed
 * need a live re-check to catch up.
 */
class BackfillDuplicatedByLogisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '4');
    }

    public function test_flags_and_clears_upsell_data_on_an_order_found_to_be_a_duplicate(): void
    {
        Order::factory()->create([
            'pancake_order_id'           => '1354614',
            'status_code'                => 2,
            'is_upsell'                  => true,
            'amount'                     => 400.0,
            'is_duplicated_by_logistics' => false,
            'pancake_inserted_at'        => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/1354614*' => Http::response(['data' => [
                'id' => 1354614, 'note' => 'nagpapacancel ng order', 'note_print' => 'DUPLICATED BY LOGISTICS',
            ]], 200),
        ]);

        Artisan::call('pancake:backfill-duplicated-logistics');

        $order = Order::where('pancake_order_id', '1354614')->first();
        $this->assertTrue($order->is_duplicated_by_logistics);
        $this->assertFalse($order->is_upsell);
    }

    public function test_leaves_an_ordinary_order_untouched(): void
    {
        Order::factory()->create([
            'pancake_order_id'           => '1354620',
            'status_code'                => 2,
            'is_upsell'                  => true,
            'is_duplicated_by_logistics' => false,
            'pancake_inserted_at'        => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/1354620*' => Http::response(['data' => [
                'id' => 1354620, 'note' => '', 'note_print' => '',
            ]], 200),
        ]);

        Artisan::call('pancake:backfill-duplicated-logistics');

        $order = Order::where('pancake_order_id', '1354620')->first();
        $this->assertFalse($order->is_duplicated_by_logistics);
        $this->assertTrue($order->is_upsell);
    }

    public function test_skips_an_order_already_flagged(): void
    {
        Order::factory()->create([
            'pancake_order_id'           => '1354621',
            'is_duplicated_by_logistics' => true,
            'pancake_inserted_at'        => now(),
        ]);

        Http::fake();

        Artisan::call('pancake:backfill-duplicated-logistics');

        Http::assertNothingSent();
    }

    public function test_a_connection_failure_on_one_order_does_not_stop_others_in_the_same_batch(): void
    {
        Order::factory()->create(['pancake_order_id' => '1354622', 'is_duplicated_by_logistics' => false, 'pancake_inserted_at' => now()]);
        Order::factory()->create(['pancake_order_id' => '1354623', 'is_duplicated_by_logistics' => false, 'pancake_inserted_at' => now()]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/4/orders/1354622*' => Http::response('boom', 500),
            'pos.pages.fm/api/v1/shops/4/orders/1354623*' => Http::response(['data' => [
                'id' => 1354623, 'note' => '', 'note_print' => 'DUPLICATED BY LOGISTICS',
            ]], 200),
        ]);

        $this->artisan('pancake:backfill-duplicated-logistics')->assertSuccessful();

        $this->assertFalse(Order::where('pancake_order_id', '1354622')->first()->is_duplicated_by_logistics);
        $this->assertTrue(Order::where('pancake_order_id', '1354623')->first()->is_duplicated_by_logistics);
    }
}
