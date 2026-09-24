<?php

namespace App\Console\Commands;

use App\Models\TsaShift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Auto-expires Wrap Up back to Ready to Call after 1 minute (explicit
 * request, 2026-09-24: "after call it will be wrap up and then after 1min
 * wrap up it will be ready to call"). This is a genuine policy reversal —
 * an earlier version of this same command was removed 2026-09-01 because
 * call-end detection (the MacroDroid phone webhook) isn't always reliable,
 * and silently bouncing a TSA out of Wrap Up on a timer risked masking that
 * unreliability rather than reflecting their real state. Reinstated anyway
 * per explicit 2026-09-24 request, now pointed at Ready to Call instead of
 * Login (see TsaShift::STATUSES' own doc comment on why Ready to Call, not
 * Login, is the "available again" target from 2026-09-24 on).
 *
 * Targets status_changed_at, not created_at on some other row — that column
 * is stamped by applyStatusChange() every time a status changes (including
 * into Wrap Up), so "been in Wrap Up for >= 1 minute" is just "now minus
 * status_changed_at >= 60s" on any TSA whose CURRENT status is still Wrap
 * Up.
 *
 * Reuses TsaShift::applyStatusChange(STATUS_READY_TO_CALL) — same real path
 * a manual status change already uses — so this gets a genuine TsaStatusLog
 * row (visible in TSA Logs) rather than a silent backend write, same
 * reasoning LogoutAllTsasAtMidnight's own doc comment already applies to
 * its own use of applyStatusChange().
 */
class ExpireWrapUpStatuses extends Command
{
    protected $signature   = 'calls:expire-wrap-up-statuses';
    protected $description = 'Auto-switches any TSA who has been in Wrap Up for 1+ minute to Ready to Call';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subMinute();

        $tsas = TsaShift::where('status', TsaShift::STATUS_WRAP_UP)
            ->where('status_changed_at', '<=', $cutoff)
            ->get();

        if ($tsas->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($tsas as $tsa) {
            $tsa->applyStatusChange(TsaShift::STATUS_READY_TO_CALL);
            $this->line("{$tsa->display_name}: Wrap Up -> Ready to Call (1 min elapsed).");
        }

        $this->info("Expired Wrap Up for {$tsas->count()} TSA(s).");
        return self::SUCCESS;
    }
}
