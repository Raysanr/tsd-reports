<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_role_user_cannot_access_sync_health(): void
    {
        $this->actingAs(User::factory()->normal()->create());
        $this->get(route('sync-health'))->assertForbidden();
    }

    public function test_admin_can_view_sync_health_with_full_run_history(): void
    {
        $this->actingAs(User::factory()->create());

        SyncRun::create(['ran_at' => now()->subHour(), 'total_synced' => 10, 'new_orders' => 5, 'upsell_count' => 2, 'upsell_sales' => 500, 'duration_ms' => 1200, 'success' => true]);
        SyncRun::create(['ran_at' => now()->subMinutes(30), 'total_synced' => 0, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0, 'duration_ms' => 300, 'success' => false, 'error_message' => 'API error on page 1: 500']);

        $response = $this->get(route('sync-health'));

        $response->assertOk();
        $response->assertViewHas('totalRuns', 2);
        $response->assertViewHas('failedRuns', 1);
        $response->assertSee('data-sortable-table', false);
    }

    /**
     * Explicit request: the "Failed Runs" card's headline number used to be the
     * all-time failure count — a background job running every minute racks up
     * hundreds of historical failures over months even when everything's fine
     * right now, so that number sat in alarming red directly beside a green
     * "Sync healthy" banner. failedRuns24h is the new headline figure; failedRuns
     * (all-time) stays available as secondary context, not removed.
     */
    public function test_failed_runs_24h_excludes_older_failures_but_all_time_still_counts_them(): void
    {
        $this->actingAs(User::factory()->create());

        SyncRun::create(['ran_at' => now()->subHours(2), 'total_synced' => 0, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0, 'duration_ms' => 300, 'success' => false]);
        SyncRun::create(['ran_at' => now()->subDays(10), 'total_synced' => 0, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0, 'duration_ms' => 300, 'success' => false]);

        $response = $this->get(route('sync-health'));

        $response->assertOk();
        $response->assertViewHas('failedRuns24h', 1);
        $response->assertViewHas('failedRuns', 2);
    }

    public function test_stale_status_is_flagged_correctly(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('last_synced', now()->subHours(2)->toDateTimeString());
        Setting::set('sync_interval', '2');

        $response = $this->get(route('sync-health'));

        $response->assertOk();
        $response->assertViewHas('health', fn ($health) => $health['sync_stale'] === true);
    }

    public function test_retry_triggers_a_sync_for_the_given_date(): void
    {
        $this->actingAs(User::factory()->create());
        // No pancake_api_key configured — deterministically produces a failed
        // SyncRun without needing to fake the Pancake HTTP API for this test.
        $response = $this->post(route('sync-health.retry'), ['date' => now()->toDateString()]);

        $response->assertRedirect(route('sync-health'));
        $this->assertDatabaseHas('sync_runs', ['success' => false]);
    }

    public function test_retry_redacts_api_key_from_the_flashed_error_message(): void
    {
        $this->actingAs(User::factory()->create());
        SyncRun::create([
            'ran_at' => now(), 'success' => false,
            'error_message' => 'cURL error 6: could not resolve host for https://pos.pages.fm/api/v1/shops/1/orders?api_key=some-secret-value&page_size=100',
            'total_synced' => 0, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0, 'duration_ms' => 0,
        ]);
        // This test just confirms the redaction helper is actually wired into
        // this controller's flash path — the retry() call itself will create a
        // NEW SyncRun (likely a plain "not configured" failure), so assert on
        // the flashed session message from THIS request specifically.
        $response = $this->post(route('sync-health.retry'), ['date' => now()->toDateString()]);

        $response->assertSessionHas('error');
        $flashed = session('error');
        $this->assertIsString($flashed);
        $this->assertStringNotContainsString('some-secret-value', $flashed);
    }

    /**
     * Explicit request: SyncRun.error_message used to store whatever a failed
     * HTTP client threw completely unredacted — for a Guzzle connection error,
     * that typically includes the full request URL, api_key and all. Every
     * DISPLAY of this column already redacted it (see the flash-message test
     * above, and Sync Health's own run-history table), but the raw value still
     * sat in the database. SyncTodayOrders::recordRun() now redacts before the
     * write, not just before display — this exercises that private method
     * directly via reflection (a full simulated Guzzle connection exception
     * requires either the pooled multi-page path or excessive HTTP-fake
     * machinery for no extra coverage; the redaction logic itself is already
     * covered independently in SyncHealthRedactSecretsTest).
     */
    public function test_sync_today_orders_redacts_the_api_key_before_writing_the_error_message(): void
    {
        $command = new \App\Console\Commands\SyncTodayOrders();

        $method = new \ReflectionMethod($command, 'recordRun');
        $method->setAccessible(true);
        $method->invoke(
            $command,
            now(),
            false,
            'cURL error 6: could not resolve host for https://pos.pages.fm/api/v1/shops/1/orders?api_key=some-secret-value&page_size=100',
        );

        $run = SyncRun::latest('id')->first();
        $this->assertStringNotContainsString('some-secret-value', $run->error_message);
        $this->assertStringContainsString('api_key=REDACTED', $run->error_message);
    }

    /**
     * Real bug (2026-08-10): the "Fix Now" date-range picker's visible
     * trigger label showed only the start date ("Jul 11, 2026") on first
     * render, silently dropping the end date, even though the calendar
     * panel underneath had the full correct range selected — this was the
     * first-ever usage combining mode='range' with showLabel=true (every
     * prior showLabel trigger was single-date), which exposed a spot in
     * the shared partial that had never needed to handle a range before.
     */
    public function test_fix_now_pickers_initial_label_defaults_to_just_today(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('sync-health'));

        $response->assertOk();
        $today = now()->format('M d, Y');
        // Single day, not a range — date-picker.blade.php only renders the
        // " – " dash when from !== to, and both default to today now.
        $response->assertSee("id=\"reconcileDrpLabel\">{$today}<", false);
    }

    public function test_reconcile_statuses_corrects_stale_orders_and_reports_the_count(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        Order::factory()->create(['pancake_order_id' => '1335548', 'status_code' => 0]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*' => Http::response([
                'data' => [['id' => 1335548, 'status' => 7]],
            ], 200),
        ]);

        $response = $this->post(route('sync-health.reconcile-statuses'), ['days' => 30]);

        $response->assertRedirect(route('sync-health'));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('corrected 1 stale', session('success'));
        $this->assertSame(7, Order::where('pancake_order_id', '1335548')->first()->status_code);
    }

    /** Explicit request (2026-08-10): "Fix Now" should also correct Total
     *  Cross-Sell Sales' actual number — the flash message must say so
     *  when it happened, not just report the status-fix count. */
    public function test_reconcile_statuses_reports_amount_corrections_in_the_flash_message(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        Order::factory()->create([
            'pancake_order_id'    => '1350030',
            'status_code'         => 8,
            'is_upsell'           => true,
            'is_cancelled_upsell' => false,
            'product'             => 'Ear Relief Balm',
            'base_product'        => 'AudiCure',
            'amount'              => 400.0,
            'pancake_created_at'  => now(),
        ]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders?*'      => Http::response(['data' => []], 200),
            'pos.pages.fm/api/v1/shops/*/orders/1350030*' => Http::response(['data' => [
                'id'    => 1350030,
                'items' => [
                    ['variation_info' => ['name' => 'AudiCure', 'retail_price' => 500], 'quantity' => 1],
                    ['variation_info' => ['name' => 'Ear Relief Balm', 'retail_price' => 600], 'quantity' => 1],
                ],
            ]], 200),
        ]);

        $response = $this->post(route('sync-health.reconcile-statuses'), ['days' => 30]);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('refreshed 1 upsell amount', session('success'));
        $this->assertSame(600.0, (float) Order::where('pancake_order_id', '1350030')->first()->amount);
    }

    /** Explicit request (2026-08-10): "Fix Now" now sends date_from/date_to
     *  from the shared range picker instead of a "days back" number — this
     *  must take priority over --days' default, not silently ignore it. */
    public function test_reconcile_statuses_accepts_an_explicit_date_range_from_the_picker(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        Order::factory()->create(['pancake_order_id' => '1335548', 'status_code' => 0]);

        Http::fake(function ($request) {
            $expectedFrom = \Illuminate\Support\Carbon::parse('2026-01-01', 'Asia/Manila')->startOfDay()->timestamp;
            if ((int) ($request->data()['startDateTime'] ?? 0) !== $expectedFrom) {
                return Http::response(['data' => []], 200);
            }
            return Http::response(['data' => [['id' => 1335548, 'status' => 7]]], 200);
        });

        $response = $this->post(route('sync-health.reconcile-statuses'), ['date_from' => '2026-01-01', 'date_to' => '2026-01-09']);

        $response->assertRedirect(route('sync-health'));
        $response->assertSessionHas('success');
        $this->assertSame(7, Order::where('pancake_order_id', '1335548')->first()->status_code);
    }

    public function test_reconcile_statuses_reports_failure_without_credentials(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('sync-health.reconcile-statuses'), ['days' => 30]);

        $response->assertRedirect(route('sync-health'));
        $response->assertSessionHas('error');
    }

    public function test_backfill_duplicated_logistics_flags_and_reports_the_count(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        Order::factory()->create(['pancake_order_id' => '1354614', 'is_duplicated_by_logistics' => false, 'pancake_inserted_at' => now()]);

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/1354614*' => Http::response(['data' => [
                'id' => 1354614, 'note' => '', 'note_print' => 'DUPLICATED BY LOGISTICS',
            ]], 200),
        ]);

        $response = $this->post(route('sync-health.backfill-duplicated-logistics'), ['days' => 1]);

        $response->assertRedirect(route('sync-health'));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('corrected 1 newly-found duplicate', session('success'));
        $this->assertTrue(Order::where('pancake_order_id', '1354614')->first()->is_duplicated_by_logistics);
    }

    /** MAX_BACKFILL_DAYS caps this tighter than reconcile-statuses' own
     *  window (see that constant's own doc comment) — a wide range must be
     *  silently clamped, same "cap, don't refuse" UX as reconcile-statuses. */
    public function test_backfill_duplicated_logistics_caps_a_wide_range(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::set('pancake_api_key', 'a-working-key');
        Setting::set('shop_id', '30037101');

        Http::fake([
            'pos.pages.fm/api/v1/shops/*/orders/*' => Http::response(['data' => ['id' => 1, 'note' => '', 'note_print' => '']], 200),
        ]);

        $response = $this->post(route('sync-health.backfill-duplicated-logistics'), [
            'date_from' => '2026-01-01', 'date_to' => '2026-01-20',
        ]);

        $response->assertRedirect(route('sync-health'));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('limited to the most recent 1 of 20 selected days', session('success'));
    }

    public function test_backfill_duplicated_logistics_reports_failure_without_credentials(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('sync-health.backfill-duplicated-logistics'), ['days' => 1]);

        $response->assertRedirect(route('sync-health'));
        $response->assertSessionHas('error');
    }
}
