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
}
