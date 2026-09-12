<?php

namespace Tests\Feature;

use Tests\TestCase;

class CronControllerTest extends TestCase
{
    public function test_missing_token_is_rejected(): void
    {
        config(['services.cron.secret' => 'the-real-secret']);

        $this->get('/cron/run')->assertForbidden();
    }

    public function test_wrong_token_is_rejected(): void
    {
        config(['services.cron.secret' => 'the-real-secret']);

        $this->get('/cron/run?token=wrong')->assertForbidden();
    }

    public function test_no_configured_secret_rejects_every_request(): void
    {
        config(['services.cron.secret' => null]);

        $this->get('/cron/run?token=anything')->assertForbidden();
    }

    public function test_correct_token_runs_the_scheduler(): void
    {
        config(['services.cron.secret' => 'the-real-secret']);

        $this->get('/cron/run?token=the-real-secret')->assertOk();
    }

    /**
     * Explicit request, 2026-09-12: "make it like realtime... i want dont
     * have to wait minutes" — Laravel's own scheduler can't express
     * anything below one-minute resolution at all, so pancake:sync-leads
     * is now fired directly on EVERY /cron/run hit, independent of
     * schedule:run's own once-a-minute cadence — the external pinger's
     * configured interval becomes the real ceiling instead. Confirms this
     * second exec() call actually fires alongside schedule:run, not that
     * it replaced it (both must still run every hit — a genuinely new
     * dedicated log file for this specific process is the observable
     * proof, same reasoning cron-schedule-run.log already serves for the
     * scheduler's own backgrounded process).
     */
    public function test_correct_token_also_directly_triggers_the_lead_sync(): void
    {
        config(['services.cron.secret' => 'the-real-secret']);

        $logFile = storage_path('logs/cron-sync-leads.log');
        @unlink($logFile);

        $this->get('/cron/run?token=the-real-secret')->assertOk();

        // The backgrounded process is detached (exec ... &) — this request
        // returns before it necessarily finishes, so poll briefly for the
        // log file it writes to rather than asserting immediately.
        $deadline = microtime(true) + 5;
        while (!file_exists($logFile) && microtime(true) < $deadline) {
            usleep(100_000);
        }

        $this->assertFileExists($logFile, 'pancake:sync-leads should run directly on every /cron/run hit, not just via schedule:run.');
    }
}
