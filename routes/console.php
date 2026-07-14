<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\SyncTodayOrders;
use App\Models\Setting;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Auto-sync today's Pancake orders on the interval configured in Settings
 * (minutes; defaults to 2). This makes re-tagged/backfilled orders (e.g. a
 * TSA tagging an order as upsell after the fact) show up on their own
 * without a manual "Sync" click.
 * Run in dev:  php artisan schedule:work
 * Run in prod: add to crontab → * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
 */
$interval = max(1, min(60, (int) Setting::get('sync_interval', 2)));

// Delta run: only orders updated since the last successful run (5-min overlap) —
// a fraction of the data the old full-day run pulled every interval.
Schedule::command(SyncTodayOrders::class, ['--delta'])->cron("*/{$interval} * * * *")->withoutOverlapping();

// Full-day safety sweep: catches anything a delta window could ever miss (clock
// skew, API hiccups). This is the same complete sync that used to run EVERY
// interval — now it only needs to run 4x/hour. Upserts are idempotent, so an
// occasional overlap with a delta run is harmless.
Schedule::command(SyncTodayOrders::class)->everyFifteenMinutes()->withoutOverlapping();
