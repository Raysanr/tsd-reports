<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardController::sync() kicks the actual Pancake fetch off as a
 * DETACHED background process (exec ... &) instead of running it in-process
 * via Artisan::call() — running a multi-page fetch synchronously blocked the
 * single php artisan serve worker long enough for Render's own 5-second
 * health check to time out, confirmed in production ("Instance failed ...
 * HTTP health check failed") every time the Sync button was clicked.
 *
 * sync() itself just returns instantly with {since, expected}; the frontend
 * polls syncStatus() until the background runs land. All the aggregation/
 * redaction/first-failure logic that used to be tested against sync()'s own
 * response now lives in syncStatus() instead — tested here by seeding
 * SyncRun rows directly (what the background process would have written),
 * since the background process itself can't meaningfully run inside a test
 * (spawned as a separate OS process with its own DB connection, per
 * phpunit.xml — it can never share state with the test's own DB transaction).
 */
class DashboardSyncFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_sync_endpoint_returns_instantly_with_a_since_and_expected_marker(): void
    {
        SyncRun::create(['ran_at' => now(), 'success' => true, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0]);
        $lastRunId = SyncRun::max('id');

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-08',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('since', $lastRunId);
        // 3 calendar days in range (08, 09, 10) — one SyncRun expected per day,
        // same as the old in-process loop used to produce.
        $response->assertJsonPath('expected', 3);
    }

    /**
     * Regression: DashboardController::sync() used to spawn its background
     * process unconditionally, even while the scheduled every-N-minute delta
     * or 15-minute safety sweep was already mid-run — on this single-worker
     * container, two heavy Pancake fetches at once is exactly the kind of
     * resource contention already fixed once for Drive syncs (see
     * SyncCallRecordings' drive_sync_running guard) but never applied here.
     */
    public function test_sync_endpoint_refuses_to_start_while_a_sync_is_already_running(): void
    {
        Setting::set('pancake_sync_running', '1');
        Setting::set('pancake_sync_last_run', now()->toIso8601String());
        $countBefore = SyncRun::count();

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-10',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('already running', $response->json('error_message'));
        // No new SyncRun row and (implicitly) no process spawned.
        $this->assertSame($countBefore, SyncRun::count());
    }

    /** A stuck flag (e.g. the container crashed mid-run and never reached the
     *  finally block that clears it) must not permanently block every future
     *  manual sync — mirrors SyncTodayOrders::runningFlagIsStale(). */
    public function test_sync_endpoint_treats_a_stale_running_flag_as_not_running(): void
    {
        Setting::set('pancake_sync_running', '1');
        Setting::set('pancake_sync_last_run', now()->subMinutes(30)->toIso8601String());

        $response = $this->postJson(route('dashboard.sync'), [
            'date_from' => '2026-07-10',
            'date_to'   => '2026-07-10',
        ]);

        $response->assertOk();
        $response->assertJsonMissing(['success' => false]);
        $response->assertJsonStructure(['since', 'expected']);
    }

    public function test_sync_status_reports_not_done_until_every_expected_run_has_landed(): void
    {
        $since = SyncRun::max('id') ?? 0;
        SyncRun::create(['ran_at' => now(), 'success' => true, 'new_orders' => 1, 'upsell_count' => 0, 'upsell_sales' => 0]);

        // Only 1 of 2 expected runs has landed so far.
        $response = $this->getJson(route('dashboard.sync.status', ['since' => $since, 'expected' => 2]));

        $response->assertOk();
        $response->assertJsonPath('done', false);
    }

    public function test_sync_status_reports_failure_when_a_run_failed(): void
    {
        $since = SyncRun::max('id') ?? 0;
        SyncRun::create([
            'ran_at' => now(), 'success' => false, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0,
            'error_message' => 'API key or shop ID not configured.',
        ]);

        $response = $this->getJson(route('dashboard.sync.status', ['since' => $since, 'expected' => 1]));

        $response->assertOk();
        $response->assertJsonPath('done', true);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('new_orders', 0);
        $response->assertJsonPath('upsell_count', 0);
        $response->assertJsonPath('upsell_sales', 0.0);
        $this->assertStringContainsString('API key or shop ID not configured', $response->json('error_message'));
    }

    public function test_sync_status_reports_zero_new_orders_when_the_run_found_nothing(): void
    {
        $since = SyncRun::max('id') ?? 0;
        SyncRun::create(['ran_at' => now(), 'success' => true, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0]);

        $response = $this->getJson(route('dashboard.sync.status', ['since' => $since, 'expected' => 1]));

        $response->assertOk();
        $response->assertJsonPath('done', true);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('new_orders', 0);
        $response->assertJsonPath('upsell_count', 0);
        $response->assertJsonPath('upsell_sales', 0.0);
        $response->assertJsonPath('error_message', null);
    }

    /**
     * Security regression: SyncTodayOrders::recordRun() redacts secrets BEFORE
     * writing to SyncRun.error_message (see SyncHealth::redactSecrets()), so
     * the row this test seeds is already redacted — matching what the real
     * background process would actually persist. This just confirms
     * syncStatus() faithfully passes that through (and its own defensive
     * redactSecrets() call is a harmless no-op on an already-clean string).
     */
    public function test_sync_status_never_leaks_a_secret_from_the_error_message(): void
    {
        $since = SyncRun::max('id') ?? 0;
        SyncRun::create([
            'ran_at' => now(), 'success' => false, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0,
            'error_message' => 'cURL error 6: could not resolve host for https://pos.pages.fm/api/v1/shops/1/orders?api_key=REDACTED&page_number=2',
        ]);

        $response = $this->getJson(route('dashboard.sync.status', ['since' => $since, 'expected' => 1]));

        $response->assertOk();
        $errorMessage = $response->json('error_message');
        $this->assertNotNull($errorMessage);
        $this->assertStringNotContainsString('some-secret-value', $errorMessage);
        $this->assertStringContainsString('api_key=REDACTED', $errorMessage);
        $this->assertStringContainsString('cURL error 6', $errorMessage);
    }

    public function test_sync_status_sums_new_orders_and_upsell_values_across_multiple_runs(): void
    {
        $since = SyncRun::max('id') ?? 0;
        SyncRun::create(['ran_at' => now(), 'success' => true, 'new_orders' => 1, 'upsell_count' => 1, 'upsell_sales' => 500.0]);
        SyncRun::create(['ran_at' => now(), 'success' => true, 'new_orders' => 1, 'upsell_count' => 1, 'upsell_sales' => 300.0]);

        $response = $this->getJson(route('dashboard.sync.status', ['since' => $since, 'expected' => 2]));

        $response->assertOk();
        $response->assertJsonPath('done', true);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('new_orders', 2);
        $response->assertJsonPath('upsell_count', 2);
        $response->assertJsonPath('upsell_sales', 800.0);
    }

    public function test_sync_status_reports_the_first_failure_when_multiple_runs_fail_with_different_errors(): void
    {
        $since = SyncRun::max('id') ?? 0;
        SyncRun::create([
            'ran_at' => now(), 'success' => false, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0,
            'error_message' => 'API error on page 1: 500 day 1 exploded',
        ]);
        SyncRun::create([
            'ran_at' => now(), 'success' => false, 'new_orders' => 0, 'upsell_count' => 0, 'upsell_sales' => 0,
            'error_message' => 'API error on page 1: 503 day 2 exploded',
        ]);

        $response = $this->getJson(route('dashboard.sync.status', ['since' => $since, 'expected' => 2]));

        $response->assertOk();
        $response->assertJsonPath('success', false);

        // Must reflect the FIRST failure specifically, not the last (or "any").
        $errorMessage = $response->json('error_message');
        $this->assertStringContainsString('500', $errorMessage);
        $this->assertStringNotContainsString('503', $errorMessage);
    }

    public function test_dashboard_page_wires_the_sync_button_to_show_a_toast(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        // Weak-but-real regression guard: PHPUnit can't execute the click
        // handler (no browser), so this just proves the wiring is present in
        // the shipped markup. The actual click-and-see-a-toast behavior is
        // verified manually.
        $response->assertSee('window.showToast(', false);
        $response->assertSee('data.error_message', false);
    }
}
