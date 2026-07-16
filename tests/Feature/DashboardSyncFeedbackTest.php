<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardSyncFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_sync_endpoint_reports_failure_when_pancake_is_not_configured(): void
    {
        // No pancake_api_key / shop_id Setting — SyncTodayOrders::handle()
        // bails immediately and records a failed SyncRun (see
        // app/Console/Commands/SyncTodayOrders.php:62-66).
        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-10',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('new_orders', 0);
        $response->assertJsonPath('upsell_count', 0);
        $response->assertJsonPath('upsell_sales', 0.0);
        $this->assertStringContainsString(
            'API key or shop ID not configured',
            $response->json('error_message')
        );
    }

    public function test_sync_endpoint_reports_zero_new_orders_when_pancake_returns_nothing(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::response(['data' => []]),
        ]);

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-10',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('new_orders', 0);
        $response->assertJsonPath('upsell_count', 0);
        $response->assertJsonPath('upsell_sales', 0.0);
        $response->assertJsonPath('error_message', null);
    }

    public function test_sync_endpoint_creates_and_aggregates_one_run_per_day_in_range(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::response(['data' => []]),
        ]);

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-08',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        // One SyncRun row per day (07-08, 07-09, 07-10) — proves the loop in
        // sync() still runs once per day, and the aggregation below counts
        // every one of them, not just the last.
        $this->assertSame(3, SyncRun::count());
    }

    /**
     * Security regression: SyncTodayOrders builds the Pancake request with
     * api_key as a *query-string* parameter (see
     * app/Console/Commands/SyncTodayOrders.php:~104), and a connection-level
     * failure (timeout, DNS blip) on a pooled page fetch surfaces via
     * `$response->getMessage()` (~line 140-141), which — per documented
     * Guzzle behavior — includes the full request URI, api_key and all. That
     * string is persisted verbatim to SyncRun.error_message. Before this
     * task, error_message was never returned outside the DB; sync() is the
     * first place that puts it in an HTTP JSON response, and /sync is
     * reachable by 'normal'-role users (routes/web.php) — the same tier the
     * Settings page deliberately masks this exact key from. This test
     * reproduces the real failure path (not a synthetic string): page 1
     * returns a full page of orders so the sync paginates into
     * Http::pool()'s concurrent-fetch branch, and page 2 there rejects with
     * a ConnectException whose message embeds the live api_key, exactly as
     * Guzzle would report a real DNS/timeout failure.
     */
    public function test_sync_endpoint_redacts_api_key_from_error_message_on_connection_failure(): void
    {
        Setting::set('pancake_api_key', 'some-secret-value');
        Setting::set('shop_id', '30037101');

        // A full page (page_size = 100) of orders with no updated_at is
        // enough to push the sync past page 1 without any of them being
        // parsed/counted (SyncTodayOrders::flushOrders skips any order
        // whose activity date doesn't match the day being synced) — it
        // exists purely to trigger pagination into the pooled page-2+ fetch.
        $fullPageOfOrders = array_map(fn (int $i) => ['id' => $i], range(1, 100));

        Http::fake(function ($request) use ($fullPageOfOrders) {
            $page = (int) ($request->data()['page_number'] ?? 1);

            if ($page === 1) {
                return Http::response(['data' => $fullPageOfOrders]);
            }

            if ($page === 2) {
                $urlWithSecret = $request->url();

                return Create::rejectionFor(new ConnectException(
                    "cURL error 6: could not resolve host for {$urlWithSecret}",
                    new Psr7Request('GET', $urlWithSecret)
                ));
            }

            return Http::response(['data' => []]);
        });

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-10',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);

        $errorMessage = $response->json('error_message');
        $this->assertNotNull($errorMessage);
        $this->assertStringNotContainsString('some-secret-value', $errorMessage);
        $this->assertStringContainsString('REDACTED', $errorMessage);
        $this->assertStringContainsString('cURL error 6', $errorMessage);

        // The raw secret really was in the underlying SyncRun row (proving
        // this test exercised the real leak path, not a no-op) — redaction
        // happens in the controller response, not in storage.
        $this->assertStringContainsString('api_key=some-secret-value', SyncRun::first()->error_message);
    }

    public function test_sync_endpoint_sums_new_orders_and_upsell_values_across_multiple_days(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // Both days contribute a distinct, non-zero upsell order (500.0 on
        // day 1, 300.0 on day 2). Neither day is all-zero — if the
        // aggregation only used one run's numbers (e.g. sum() swapped for
        // first()) instead of actually summing both, the totals below
        // (new_orders=2, upsell_count=2, upsell_sales=800.0) could not be
        // produced: they only come out right if BOTH days' contributions
        // were added together.
        $day1Order = [
            'id'          => 555001,
            'status'      => 0,
            'inserted_at' => '2026-07-08T05:00:00',
            'updated_at'  => '2026-07-08T06:00:00',
            'tags'        => [
                ['id' => 1, 'name' => 'UPSELL TSD - ADDON'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Base Product', 'retail_price' => 900], 'quantity' => 1],
                ['variation_info' => ['name' => 'Addon Product', 'retail_price' => 500], 'quantity' => 1],
            ],
        ];
        $day2Order = [
            'id'          => 555002,
            'status'      => 0,
            'inserted_at' => '2026-07-09T05:00:00',
            'updated_at'  => '2026-07-09T06:00:00',
            'tags'        => [
                ['id' => 1, 'name' => 'UPSELL TSD - ADDON'],
            ],
            'items' => [
                ['variation_info' => ['name' => 'Base Product', 'retail_price' => 900], 'quantity' => 1],
                ['variation_info' => ['name' => 'Addon Product', 'retail_price' => 300], 'quantity' => 1],
            ],
        ];

        Http::fake(function ($request) use ($day1Order, $day2Order) {
            $startDateTime = $request->data()['startDateTime'] ?? null;

            // Day 1's window starts at 2026-07-08 00:00 PHT; day 2's at
            // 2026-07-09 00:00 PHT — distinguish the two Artisan::call runs
            // by which day's window this request belongs to.
            $day1Start = \Illuminate\Support\Carbon::parse('2026-07-08', 'Asia/Manila')->startOfDay()->timestamp;
            $day2Start = \Illuminate\Support\Carbon::parse('2026-07-09', 'Asia/Manila')->startOfDay()->timestamp;

            return match (true) {
                $startDateTime == $day1Start => Http::response(['data' => [$day1Order]]),
                $startDateTime == $day2Start => Http::response(['data' => [$day2Order]]),
                default                      => Http::response(['data' => []]),
            };
        });

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-08',
            'date_to'   => '2026-07-09',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('new_orders', 2);
        $response->assertJsonPath('upsell_count', 2);
        $response->assertJsonPath('upsell_sales', 800.0);
    }

    public function test_sync_endpoint_reports_the_first_failure_when_multiple_days_fail_with_different_errors(): void
    {
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');

        // BOTH days fail, with distinguishably different error text (500 vs
        // 503). With only one failing run, "first failure" and "last
        // failure" are indistinguishable — this needs two different
        // failures so that reporting day 2's instead of day 1's would
        // actually be detectable. Consumed in call order, matching the date
        // loop in DashboardController::sync() (day 1's Artisan::call runs
        // first).
        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders*' => Http::sequence()
                ->push(['error' => 'day 1 exploded'], 500)
                ->push(['error' => 'day 2 exploded'], 503),
        ]);

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-08',
            'date_to'   => '2026-07-09',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);

        // Must reflect day 1's failure specifically, not day 2's — proves
        // first(fn ($run) => !$run->success) is really picking out the
        // FIRST failure among several, not the last (or "any").
        $errorMessage = $response->json('error_message');
        $this->assertStringContainsString('API error on page 1: 500', $errorMessage);
        $this->assertStringNotContainsString('503', $errorMessage);

        $runs = SyncRun::orderBy('id')->get();
        $this->assertSame(2, $runs->count());
        $this->assertFalse($runs[0]->success);
        $this->assertStringContainsString('500', $runs[0]->error_message);
        $this->assertFalse($runs[1]->success);
        $this->assertStringContainsString('503', $runs[1]->error_message);
    }
}
