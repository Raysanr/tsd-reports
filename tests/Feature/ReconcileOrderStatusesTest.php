<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real, live-confirmed bug: Pancake's list-orders endpoint excludes canceled/
 * deleted orders by default, so once an order is removed there, no future
 * date-scoped sync ever sees it again — its local copy keeps whatever status
 * it had at last sync forever. Confirmed against 2026-07-25 production data:
 * 33 of the first 100 Canceled/Deleted orders in just the last 30 days had a
 * stale local status_code. This command explicitly asks for removed orders
 * (filter_status[]=6,7 + include_removed=1) and corrects any local mismatch.
 */
class ReconcileOrderStatusesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');
    }

    public function test_corrects_a_local_order_whose_status_pancake_has_since_changed_to_deleted(): void
    {
        Order::factory()->create(['pancake_order_id' => '1335548', 'status_code' => 0]); // still "New" locally

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [
                    ['id' => 1335548, 'status' => 7], // Pancake: Deleted recently
                ],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertSame(7, Order::where('pancake_order_id', '1335548')->first()->status_code);
    }

    public function test_leaves_a_local_order_alone_when_its_status_already_matches(): void
    {
        Order::factory()->create(['pancake_order_id' => '1335001', 'status_code' => 6]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [
                    ['id' => 1335001, 'status' => 6],
                ],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertSame(0, (int) Setting::get('order_status_reconcile_last_corrected'));
    }

    public function test_ignores_a_pancake_removed_order_that_was_never_synced_locally_at_all(): void
    {
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [
                    ['id' => 'never-synced-order', 'status' => 7],
                ],
            ], 200),
        ]);

        $this->artisan('pancake:reconcile-statuses')->assertSuccessful();

        $this->assertSame(0, (int) Setting::get('order_status_reconcile_last_corrected'));
    }

    public function test_paginates_through_multiple_pages(): void
    {
        Order::factory()->create(['pancake_order_id' => 'page-1-order', 'status_code' => 0]);
        Order::factory()->create(['pancake_order_id' => 'page-2-order', 'status_code' => 0]);

        $page1 = array_fill(0, 100, ['id' => 'filler', 'status' => 7]);
        $page1[0] = ['id' => 'page-1-order', 'status' => 7];

        Http::fake(function ($request) use ($page1) {
            $page = $request->data()['page_number'] ?? 1;
            if ($page == 1) {
                return Http::response(['data' => $page1], 200);
            }
            return Http::response(['data' => [['id' => 'page-2-order', 'status' => 7]]], 200);
        });

        Artisan::call('pancake:reconcile-statuses');

        $this->assertSame(7, Order::where('pancake_order_id', 'page-1-order')->first()->status_code);
        $this->assertSame(7, Order::where('pancake_order_id', 'page-2-order')->first()->status_code);
    }

    public function test_records_last_run_status_and_counts(): void
    {
        Order::factory()->create(['pancake_order_id' => '1335548', 'status_code' => 0]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [['id' => 1335548, 'status' => 7]],
            ], 200),
        ]);

        Artisan::call('pancake:reconcile-statuses');

        $this->assertNotNull(Setting::get('order_status_reconcile_last_run'));
        $this->assertSame(1, (int) Setting::get('order_status_reconcile_last_checked'));
        $this->assertSame(1, (int) Setting::get('order_status_reconcile_last_corrected'));
    }

    public function test_fails_gracefully_without_credentials(): void
    {
        Setting::set('pancake_api_key', '');
        Setting::set('shop_id', '');

        $this->artisan('pancake:reconcile-statuses')->assertFailed();
    }
}
