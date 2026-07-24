<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Drive recordings sync previously had zero observability — a production
 * failure (missing credentials, bad refresh token, etc.) was silent, showing
 * only as "AHT still no data" with no way to tell why. This locks in that
 * every run records its outcome to Settings, surfaced on the Settings page.
 */
class SyncCallRecordingsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_credentials_records_a_failed_status_with_a_message(): void
    {
        $this->artisan('calls:sync-recordings')->assertFailed();

        $this->assertNotNull(Setting::get('drive_sync_last_run'));
        $this->assertSame('failed', Setting::get('drive_sync_last_status'));
        $this->assertStringContainsString('not configured', Setting::get('drive_sync_last_message'));
    }

    /**
     * The manual "Sync Now" button spawns this command via a raw detached
     * exec(), bypassing the scheduler's own withoutOverlapping() mutex
     * entirely — a click while the 2-hourly schedule is mid-run (a full run
     * has taken several minutes) could otherwise run two heavy Drive-
     * downloading processes at once on one resource-constrained container.
     */
    public function test_skips_running_when_a_sync_is_already_in_progress(): void
    {
        Setting::set('drive_sync_running', '1');
        Setting::set('drive_sync_last_run', now()->toIso8601String()); // started just now — not stale
        Setting::set('drive_sync_last_status', 'success');
        Setting::set('drive_sync_last_message', 'a previous, unrelated success message');

        $this->artisan('calls:sync-recordings')->assertSuccessful();

        // Left untouched — this run was skipped, not a fresh success.
        $this->assertSame('success', Setting::get('drive_sync_last_status'));
        $this->assertSame('a previous, unrelated success message', Setting::get('drive_sync_last_message'));
    }

    public function test_treats_a_stuck_running_flag_as_stale_and_proceeds_anyway(): void
    {
        // Simulates a container crash mid-run — drive_sync_running never got
        // cleared, but it's from over 20 minutes ago, so a new attempt must
        // not be permanently blocked by it.
        Setting::set('drive_sync_running', '1');
        Setting::set('drive_sync_last_run', now()->subMinutes(30)->toIso8601String());

        $this->artisan('calls:sync-recordings')->assertFailed(); // fails fast: no credentials configured

        $this->assertStringContainsString('not configured', Setting::get('drive_sync_last_message'));
    }

    public function test_clears_the_running_flag_after_finishing_so_the_next_run_is_not_permanently_blocked(): void
    {
        $this->artisan('calls:sync-recordings')->assertFailed(); // fails fast: no credentials configured

        $this->assertSame('', Setting::get('drive_sync_running', ''));
    }
}
