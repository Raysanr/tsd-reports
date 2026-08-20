<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Console\Command;

/**
 * Monitor TSA (explicit request, 2026-08-20): Wrap Up is a timed, system-
 * only status — CallEventController flips a TSA into it the moment a call
 * ends, and this is what flips them back out again after
 * Setting::get('wrap_up_duration_seconds', 60) has passed, same as a real
 * call center's after-call-work timer expiring. Runs every minute (the
 * scheduler's finest grain), so an expiry can land up to ~60s later than
 * the exact configured duration in the worst case — acceptable here, this
 * isn't a billing-accuracy timer, just "don't leave someone stuck in Wrap
 * Up forever if they walk away."
 *
 * Goes to Login, not back to whatever they were doing before the call —
 * Login is the "ready for the next lead" state, matching what happens right
 * after any other status change today (nothing in this app remembers a
 * "previous" status to restore).
 */
class ExpireWrapUpStatuses extends Command
{
    protected $signature = 'calls:expire-wrap-up';
    protected $description = 'Auto-return TSAs stuck in Wrap Up back to Login once the configured duration has passed';

    public function handle(): int
    {
        $seconds = max(1, (int) Setting::get('wrap_up_duration_seconds', 60));

        $stale = TsaShift::where('status', TsaShift::STATUS_WRAP_UP)
            ->where('status_changed_at', '<=', now()->subSeconds($seconds))
            ->get();

        foreach ($stale as $tsa) {
            $tsa->applyStatusChange(TsaShift::STATUS_LOGIN);
        }

        if ($stale->isNotEmpty()) {
            $this->info("Expired Wrap Up for {$stale->count()} TSA(s) back to Login.");
        }

        return self::SUCCESS;
    }
}
