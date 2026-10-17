<?php

namespace App\Console\Scheduling;

use Illuminate\Console\Scheduling\Event;

/**
 * Hard-kills a withoutOverlapping() job 30s before its own overlap lock
 * expires, so a hung run can never outlive its lock and have a fresh copy
 * stacked on top of it. See routes/console.php's own comment for the
 * 2026-10-17 Railway cost incident this exists for.
 */
class ScheduleRuntimeCap
{
    /** @param iterable<Event> $events */
    public static function apply(iterable $events, string $timeoutBin = '/usr/bin/timeout'): void
    {
        foreach ($events as $event) {
            if (!$event->withoutOverlapping || !$event->command || str_starts_with($event->command, $timeoutBin . ' ')) {
                continue;
            }

            $seconds = max(60, $event->expiresAt * 60 - 30);
            $event->command = "{$timeoutBin} -k 15 {$seconds} " . $event->command;
        }
    }
}
