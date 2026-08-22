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
 * printing") field — 215 such orders existed in the shop's recent history,
 * all still counted as real leads/upsells everywhere (Leads Report, TSA
 * Performance, Dashboard) despite being a duplicate of an already-counted
 * order, not a second real lead.
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

    public function test_a_note_print_flagged_as_duplicated_by_logistics_is_never_a_real_lead_or_upsell(): void
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
        $this->assertTrue($order->is_duplicated_by_logistics);
        $this->assertFalse($order->is_upsell);
        $this->assertFalse($order->is_cancelled_upsell);
        $this->assertFalse($order->is_returned_upsell);
        $this->assertFalse($order->is_restocking_upsell);
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
