<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bug fix (2026-09-07): Order.team switched from "which TSA/product
 * handled it" to "what hour it was created" (TeamShiftWindow::forHour()) —
 * replaces the old product-keyword-inference test this file used to
 * contain, since that mechanism (inferTeamFromProduct()) no longer
 * exists. team is derived from pancake_created_at's own hour, which is
 * itself populated from resolveWorkedAt()'s result (the tag-add time when
 * one exists, otherwise the raw insertion time) — NOT the literal Pancake
 * order-creation timestamp. tsa_name/matched_tag (who worked the order)
 * are completely unaffected by this change — see
 * SyncTodayOrdersAccountBasedTsaAttributionTest for that coverage, none
 * of which needed updating for this fix.
 */
class SyncTodayOrdersProductTeamInferenceTest extends TestCase
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

    public function test_an_order_worked_at_10am_is_opening_team(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // No tags at all -> resolveWorkedAt() falls back to inserted_at
        // directly (no tag-add timestamp to prefer) -> pancake_created_at
        // ends up as the raw insertion time itself, 2026-09-05 10:00 PHT.
        $this->fakeOnePage([
            'id' => 9101, 'status' => 0, 'total_price' => 500,
            'inserted_at' => '2026-09-05T02:00:00', // UTC -> 10:00 AM Asia/Manila
            'updated_at'  => '2026-09-05T02:00:00',
            'tags' => [], 'items' => [['variation_info' => ['name' => 'Anything', 'retail_price' => 500], 'quantity' => 1]],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-05']);

        $order = Order::where('pancake_order_id', '9101')->first();
        $this->assertNotNull($order);
        $this->assertSame('Eyecare Team', $order->team);
        $this->assertNull($order->tsa_name, 'nobody claimed this lead, so no TSA name should be invented');
    }

    public function test_an_order_worked_at_8pm_is_closing_team(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        $this->fakeOnePage([
            'id' => 9102, 'status' => 0, 'total_price' => 500,
            'inserted_at' => '2026-09-05T12:00:00', // UTC -> 8:00 PM Asia/Manila
            'updated_at'  => '2026-09-05T12:00:00',
            'tags' => [], 'items' => [['variation_info' => ['name' => 'Anything', 'retail_price' => 500], 'quantity' => 1]],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-05']);

        $order = Order::where('pancake_order_id', '9102')->first();
        $this->assertNotNull($order);
        $this->assertSame('SH Naturals', $order->team);
    }

    public function test_team_follows_the_workedat_tag_time_not_the_raw_insertion_time(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Order inserted at 1:00 AM Sep 5 (would be Opening if raw
        // insertion time were used), but the TSA's own name tag wasn't
        // actually added until 4:00 PM the same day per histories ->
        // resolveWorkedAt() anchors to that tag-add time instead ->
        // Closing, not Opening. updated_at is set to the tag-add time too,
        // so the order genuinely falls within pancake:sync-today's own
        // --date=2026-09-05 activity window (see SyncTodayOrders::
        // flushOrders()'s own updated_at-based "which day is this order's
        // activity" filter) — using the 1am insertion time for updated_at
        // instead would put the order outside that window entirely.
        $this->fakeOnePage([
            'id' => 9103, 'status' => 0, 'total_price' => 500,
            'inserted_at' => '2026-09-04T17:00:00', // UTC -> 1:00 AM Asia/Manila on 2026-09-05
            'updated_at'  => '2026-09-05T08:00:00', // UTC -> 4:00 PM Asia/Manila on 2026-09-05 (same day as the sync's --date, so it's picked up)
            'tags' => [['id' => 1, 'name' => 'GEMMA']],
            'items' => [['variation_info' => ['name' => 'Anything', 'retail_price' => 500], 'quantity' => 1]],
            'histories' => [[
                'tags' => ['old' => [], 'new' => [['id' => 1, 'name' => 'GEMMA']]],
                'updated_at' => '2026-09-05T08:00:00', // UTC -> 4:00 PM Asia/Manila
                'editor_id' => 555,
            ]],
        ]);

        Artisan::call('pancake:sync-today', ['--date' => '2026-09-05']);

        $order = Order::where('pancake_order_id', '9103')->first();
        $this->assertNotNull($order);
        $this->assertSame('Gemma', $order->tsa_name);
        $this->assertSame('SH Naturals', $order->team, 'team must follow the tag-add time (4pm, Closing), not the raw insertion time (which this fixture deliberately set to a different hour)');
    }
}
