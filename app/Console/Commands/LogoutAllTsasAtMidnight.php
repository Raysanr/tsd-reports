<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\TsaShift;
use Illuminate\Console\Command;

/**
 * Force every still-logged-in TSA to Logout at Manila midnight — explicit
 * request, 2026-09-21, directly tied to a real production incident the same
 * day: Hannah forgot to log out the previous day, stayed in Login status
 * overnight, kept absorbing new round-robin leads unattended, and the
 * cleanup (manually transferring her backlog to Marisol/Marsha) is what led
 * to the "same lead visible to two TSAs" confusion reported that morning.
 * Logging every TSA out at day's end closes this at the source instead of
 * relying on each TSA to remember, or an admin to notice and clean up after
 * the fact.
 *
 * Reuses TsaShift::applyStatusChange(STATUS_LOGOUT) — the SAME real logout
 * path a TSA's own topbar dropdown uses — rather than a raw ->update(), so
 * this gets every side effect a genuine logout already has for free and
 * exactly once: TsaStatusLog::log() records it as a real status change (not
 * a silent backend write invisible to TSA Logs), and
 * RedistributeLoggedOutTsaLeads::dispatch()->afterResponse() hands off
 * whatever's still uncalled in that TSA's queue to her online teammates.
 *
 * Confirmed this genuinely fires here, not silently dropped: ->afterResponse()
 * registers a container "terminating" callback (Illuminate\Bus\Dispatcher::
 * dispatchAfterResponse()) — that only ever runs when something calls
 * $app->terminate(). A scheduled command isn't an HTTP request, but
 * Schedule::command() still shells out to a genuinely separate `php artisan
 * <command>` PROCESS per run (Schedule::exec(), not an in-process call
 * inside schedule:work's own long-lived loop) — and every such process goes
 * through Application::handleCommand()'s own handle()-then-terminate()
 * sequence, exactly like running this command by hand would. So the
 * redistribution reliably fires at the end of THIS process's own lifecycle.
 * (LogoutLeadRedistributorTest's own tests trigger it manually instead — that
 * PHPUnit-only limitation is because a single test process never calls
 * $app->terminate() at all mid-suite, not evidence this breaks for a real
 * scheduled/console invocation.)
 *
 * Skips STATUS_LOCKED deliberately — an admin explicitly locked that TSA
 * into their current status (Pancake's own admin-only conversation-receive-
 * mode equivalent, see TsaStatusController's own doc comment); a scheduled
 * job silently overriding that deliberate admin action would undermine the
 * whole point of Lock existing, and could surprise the admin the next
 * morning. Already-logged-out TSAs are excluded by the query itself — no
 * redundant write, no duplicate TsaStatusLog row for someone who genuinely
 * already signed off on their own.
 *
 * Gated by a global on/off Setting (explicit same-day follow-up: "can be on
 * and off like has toggle in the tsa management") — same
 * Setting::get(..., default)-plus-a-toggle-button pattern
 * pos_auto_tagging_enabled already established (see
 * TsaManagementController::toggleAutoTagging()'s own doc comment), reused
 * here rather than inventing a second convention for the same shape of
 * feature. Defaults to enabled (true) — this command exists specifically
 * because someone forgetting to log out caused a real incident the day it
 * was built, so "on" has to be the default an admin who never touches the
 * toggle actually gets, not something they'd need to remember to opt into.
 */
class LogoutAllTsasAtMidnight extends Command
{
    protected $signature   = 'calls:logout-all-tsas-at-midnight';
    protected $description = 'Forces every TSA still logged in (not already Logout, not admin-Locked) to Logout at day\'s end, redistributing their uncalled leads the same way a real logout already does — no-ops entirely when turned off in TSA Management';

    public function handle(): int
    {
        if (!Setting::get('midnight_auto_logout_enabled', true)) {
            $this->info('Midnight auto-logout is turned off in TSA Management — skipping.');
            return self::SUCCESS;
        }

        $tsas = TsaShift::whereNotIn('status', [TsaShift::STATUS_LOGOUT, TsaShift::STATUS_LOCKED])->get();

        if ($tsas->isEmpty()) {
            $this->info('No TSA needs to be logged out — everyone is already Logout or admin-Locked.');
            return self::SUCCESS;
        }

        foreach ($tsas as $tsa) {
            $previousStatus = $tsa->status;
            $tsa->applyStatusChange(TsaShift::STATUS_LOGOUT);
            $this->line("Logged out {$tsa->display_name} (was {$previousStatus}).");
        }

        $this->info("Logged out {$tsas->count()} TSA(s) at day's end.");
        return self::SUCCESS;
    }
}
