<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTodayOrdersBacklogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Real-world case discovered 2026-07-04: a lead created 8 days ago (e.g. a
     * Facebook lead that sat unworked) finally gets called and tagged today.
     * The old sync paginated/cut off by inserted_at, so this order would never
     * even be fetched — it's buried deep past the "reached previous day" stop
     * point. The sync must instead query Pancake by updated_at so backlog leads
     * worked today are actually pulled in.
     */
    public function test_backlog_lead_created_days_ago_but_worked_today_is_synced(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Marisol is already seeded (with tag_keywords=MARISOL) by the tsa_shifts
        // migrations, so no fixture setup needed here.

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 1323805,
                        'status' => 0,
                        'inserted_at' => '2026-06-26T23:54:52.931992',
                        'updated_at' => '2026-07-04T06:01:29.560937',
                        'tags' => [
                            ['id' => 385, 'name' => 'PTERYGIUM'],
                            ['id' => 315, 'name' => 'MARISOL'],
                            ['id' => 269, 'name' => 'DFR'],
                        ],
                        'items' => [],
                        'histories' => [
                            [
                                'tags' => [
                                    'old' => [],
                                    'new' => [['id' => 315, 'name' => 'MARISOL'], ['id' => 269, 'name' => 'DFR']],
                                ],
                                'updated_at' => '2026-07-03T22:01:00',
                            ],
                        ],
                    ]],
                ])
                ->push(['data' => []]),
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-07-04']);

        $order = Order::where('pancake_order_id', '1323805')->first();

        $this->assertNotNull($order, 'Backlog order should have been synced at all');
        $this->assertSame('Marisol', $order->tsa_name);
        $this->assertSame('2026-07-04', $order->pancake_created_at->setTimezone('Asia/Manila')->toDateString());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pos.pages.fm/api/v1/shops/30037101/orders'
                || str_starts_with($request->url(), 'https://pos.pages.fm/api/v1/shops/30037101/orders');
        });

        $firstRequest = Http::recorded()[0][0];
        $this->assertSame('updated_at', $firstRequest['updateStatus']);
        $this->assertArrayHasKey('startDateTime', $firstRequest->data());
        $this->assertArrayHasKey('endDateTime', $firstRequest->data());
    }
}
