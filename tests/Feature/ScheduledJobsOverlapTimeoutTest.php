<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Root-caused twice now (2026-08-18, delta sync + pancake:sync-leads; 2026-08-21,
 * every remaining job below): withoutOverlapping() with no argument defaults to a
 * 1440-minute (24h) mutex. A scheduler-service redeploy that interrupts a run mid-
 * flight leaves that mutex held for up to a full day — every subsequent tick
 * silently finds the job "already running elsewhere" and skips it (confirmed live
 * via `railway logs`: zero pancake:sync-today runs for over an hour after one such
 * redeploy). Every scheduled job in routes/console.php must pass an explicit,
 * generous-but-bounded timeout to withoutOverlapping() so an interrupted run
 * self-heals in minutes, not up to a day. This test guards against a future job
 * being added without one.
 */
class ScheduledJobsOverlapTimeoutTest extends TestCase
{
    /** Laravel's own tell-tale for "nobody passed an explicit value". */
    private const UNSET_DEFAULT_MINUTES = 1440;

    public function test_no_scheduled_job_uses_the_default_24_hour_overlap_timeout(): void
    {
        $schedule = app(Schedule::class);

        $offenders = collect($schedule->events())
            ->filter(fn ($event) => ($event->withoutOverlapping ?? false) === true)
            ->filter(fn ($event) => ($event->expiresAt ?? null) === self::UNSET_DEFAULT_MINUTES)
            ->map(fn ($event) => $event->command ?? $event->description ?? 'unknown');

        $this->assertCount(
            0,
            $offenders,
            "These scheduled jobs use withoutOverlapping()'s 24h default instead of an explicit timeout:\n"
                . $offenders->implode("\n")
        );
    }

    public function test_every_overlapping_guarded_job_has_a_sane_upper_bound(): void
    {
        $schedule = app(Schedule::class);

        // 60 minutes is generous headroom over every real job's actual duration
        // (seconds to a few minutes per their own doc comments) while still being
        // far short of a full day — a job stuck past this for a genuine reason
        // (not just an interrupted redeploy) should be investigated, not silently
        // blocked until tomorrow.
        $tooLong = collect($schedule->events())
            ->filter(fn ($event) => ($event->withoutOverlapping ?? false) === true)
            ->filter(fn ($event) => ($event->expiresAt ?? 0) > 60)
            ->map(fn ($event) => ($event->command ?? $event->description ?? 'unknown') . ' (expiresAt=' . $event->expiresAt . ')');

        $this->assertCount(0, $tooLong, "These scheduled jobs have an overlap timeout over 60 minutes:\n" . $tooLong->implode("\n"));
    }
}
