<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real-world case confirmed via live Pancake histories data (SH Naturals/
 * Sinuxyl order #1338492, 2026-07-29): the TSA's own name tag (MARISOL here)
 * can land in a tight automated assignment batch well before her shift even
 * starts, while the real disposition (CONFIRMED VIA CALL/Not answering/etc.)
 * only lands hours later, once she's actually worked the lead. Before this
 * fix, SyncTodayOrders anchored pancake_created_at to the name tag's own
 * add-time — putting a genuine 9 AM call in a "7:47 AM" hour bucket on TSA
 * Performance, before the TSA had even logged in. See resolveWorkedAt() and
 * its caller in SyncTodayOrders::handle().
 */
class SyncTodayOrdersDispositionAnchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pancake_created_at_anchors_to_the_disposition_tag_not_the_earlier_assignment_tag(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 1338492,
                        'status' => 1,
                        'inserted_at' => '2026-07-03T20:00:00',
                        'updated_at' => '2026-07-04T01:00:00',
                        'tags' => [
                            ['id' => 385, 'name' => 'PTERYGIUM'],
                            ['id' => 315, 'name' => 'MARISOL'],
                            ['id' => 270, 'name' => 'CONFIRMED VIA CALL'],
                        ],
                        'items' => [],
                        'histories' => [
                            // Automated assignment batch — 7:47 AM Manila, before an 8 AM shift.
                            [
                                'tags' => [
                                    'old' => [],
                                    'new' => [['id' => 385, 'name' => 'PTERYGIUM'], ['id' => 315, 'name' => 'MARISOL']],
                                ],
                                'updated_at' => '2026-07-03T23:47:00',
                            ],
                            // The real call outcome — 9:00 AM Manila, during her actual shift.
                            [
                                'tags' => [
                                    'old' => [['id' => 385, 'name' => 'PTERYGIUM'], ['id' => 315, 'name' => 'MARISOL']],
                                    'new' => [['id' => 385, 'name' => 'PTERYGIUM'], ['id' => 315, 'name' => 'MARISOL'], ['id' => 270, 'name' => 'CONFIRMED VIA CALL']],
                                ],
                                'updated_at' => '2026-07-04T01:00:00',
                            ],
                        ],
                    ]],
                ])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-04']);

        $order = Order::where('pancake_order_id', '1338492')->first();

        $this->assertNotNull($order);
        $this->assertSame('CONFIRMED VIA CALL', $order->disposition);
        $this->assertSame(
            '2026-07-04 09:00:00',
            $order->pancake_created_at->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'Expected the disposition tag\'s own add-time (9 AM), not the earlier assignment tag\'s (7:47 AM).'
        );
    }

    public function test_pancake_created_at_still_uses_the_assignment_tag_when_no_disposition_exists_yet(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 1338999,
                        'status' => 0,
                        'inserted_at' => '2026-07-03T20:00:00',
                        'updated_at' => '2026-07-03T23:47:00',
                        'tags' => [
                            ['id' => 385, 'name' => 'PTERYGIUM'],
                            ['id' => 315, 'name' => 'MARISOL'],
                        ],
                        'items' => [],
                        'histories' => [
                            [
                                'tags' => [
                                    'old' => [],
                                    'new' => [['id' => 385, 'name' => 'PTERYGIUM'], ['id' => 315, 'name' => 'MARISOL']],
                                ],
                                'updated_at' => '2026-07-03T23:47:00',
                            ],
                        ],
                    ]],
                ])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-04']);

        $order = Order::where('pancake_order_id', '1338999')->first();

        $this->assertNotNull($order);
        $this->assertNull($order->disposition);
        $this->assertSame(
            '2026-07-04 07:47:00',
            $order->pancake_created_at->setTimezone('Asia/Manila')->format('Y-m-d H:i:s')
        );
    }
}
