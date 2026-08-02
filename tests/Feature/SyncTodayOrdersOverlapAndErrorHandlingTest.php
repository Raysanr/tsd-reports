<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncRun;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTodayOrdersOverlapAndErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: page 1 is fetched as a single, non-pooled request — unlike
     * page 2+ (fetched via Http::pool(), which returns a rejected request as
     * a Throwable value instead of throwing), a connection-level failure
     * here used to propagate straight out of handle() as an uncaught
     * exception instead of the graceful {success:false, error_message} every
     * caller (the manual Sync button, Sync Health) depends on — and it never
     * even reached recordRun(), so no failed SyncRun was logged either.
     */
    public function test_a_connection_failure_on_page_1_is_recorded_as_a_failed_run_instead_of_crashing(): void
    {
        Setting::set('pancake_api_key', 'some-secret-value');
        Setting::set('shop_id', '30037101');

        Http::fake(function ($request) {
            $urlWithSecret = $request->url();

            return Create::rejectionFor(new ConnectException(
                "cURL error 6: could not resolve host for {$urlWithSecret}",
                new Psr7Request('GET', $urlWithSecret)
            ));
        });

        $this->artisan('pancake:sync-today', ['--date' => '2026-07-10'])->assertSuccessful();

        $run = SyncRun::latest('id')->first();
        $this->assertNotNull($run);
        $this->assertFalse($run->success);
        $this->assertStringContainsString('cURL error 6', $run->error_message);
        // Written already redacted (SyncTodayOrders::recordRun) — proves this
        // is the real leaked-URL path, not a synthetic error string.
        $this->assertStringContainsString('api_key=REDACTED', $run->error_message);
        $this->assertStringNotContainsString('some-secret-value', $run->error_message);
    }

    /**
     * Regression: withoutOverlapping() on the schedule registration
     * (routes/console.php) only guards the SCHEDULER from launching a second
     * instance of its own — it does nothing for the manual Sync button,
     * which spawns this command via a raw detached exec(), bypassing the
     * scheduler entirely. Same bug already fixed once for Drive syncs
     * (SyncCallRecordings' drive_sync_running guard) but never applied here
     * until now.
     */
    public function test_skips_when_a_sync_is_already_running_and_not_stale(): void
    {
        Setting::set('pancake_sync_running', '1');
        Setting::set('pancake_sync_last_run', now()->toIso8601String());
        Setting::set('pancake_api_key', 'test-key');
        Setting::set('shop_id', '30037101');
        $countBefore = SyncRun::count();

        $this->artisan('pancake:sync-today', ['--date' => '2026-07-10'])->assertSuccessful();

        $this->assertSame($countBefore, SyncRun::count());
    }

    /** Simulates a container crash mid-run — pancake_sync_running never got
     *  cleared, but it's from over 10 minutes ago, so a new attempt must not
     *  be permanently blocked by it. */
    public function test_treats_a_stuck_running_flag_as_stale_and_proceeds_anyway(): void
    {
        Setting::set('pancake_sync_running', '1');
        Setting::set('pancake_sync_last_run', now()->subMinutes(30)->toIso8601String());

        // No credentials configured — fails fast, but the point is it didn't
        // skip due to the stale overlap flag.
        $this->artisan('pancake:sync-today', ['--date' => '2026-07-10'])->assertFailed();

        $run = SyncRun::latest('id')->first();
        $this->assertNotNull($run);
        $this->assertStringContainsString('not configured', $run->error_message);
    }

    public function test_clears_the_running_flag_after_finishing_so_the_next_run_is_not_permanently_blocked(): void
    {
        // No credentials configured — fails fast.
        $this->artisan('pancake:sync-today', ['--date' => '2026-07-10'])->assertFailed();

        $this->assertSame('', Setting::get('pancake_sync_running', ''));
    }
}
