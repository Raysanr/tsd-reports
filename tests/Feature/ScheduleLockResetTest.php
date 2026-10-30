<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** A redeploy left withoutOverlapping() locks behind (2026-10-30); the
 *  scheduler clears them when it starts. Other cache locks are untouched. */
class ScheduleLockResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_start_clears_leftover_overlap_locks_only(): void
    {
        config(['cache.default' => 'database']);
        $event = app(Schedule::class)->command('pancake:sync-leads')->withoutOverlapping(10);
        $this->assertTrue(Cache::store('database')->lock($event->mutexName(), 600)->get());
        $this->assertTrue(Cache::store('database')->lock('something-else', 600)->get());

        $this->assertSame(1, AppServiceProvider::clearScheduleMutexes());

        $this->assertTrue(Cache::store('database')->lock($event->mutexName(), 600)->get());
        $this->assertFalse(Cache::store('database')->lock('something-else', 600)->get());
    }
}
