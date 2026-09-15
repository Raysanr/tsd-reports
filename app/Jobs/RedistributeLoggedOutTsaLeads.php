<?php

namespace App\Jobs;

use App\Models\TsaShift;
use App\Support\LogoutLeadRedistributor;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Explicit request (2026-09-16): "i want to make it it will not
 * automatically redistribute to othere's online tsa, it will take minutes
 * to redistribute because when someone is logout why is it like it is lag
 * or it is loading" — root-caused: TsaShift::applyStatusChange() ran
 * LogoutLeadRedistributor::redistribute() INLINE, before the logout
 * request's own HTTP response returned. Each redistributed lead makes a
 * real live Pancake API call (LeadController::tagTsaOnPancakeOrder()), so
 * a TSA logging out with a large uncalled backlog held the request open —
 * and the same-named entrypoint.sh comment on PHP_CLI_SERVER_WORKERS
 * documents this exact class of bug already happened once before (a slow
 * admin action starving every other concurrent user, since the built-in
 * PHP server only serves a handful of requests at once).
 *
 * Deliberately NOT `implements ShouldQueue` — this app has no queue worker
 * running anywhere in its Railway deployment (QUEUE_CONNECTION=database
 * would just leave the job stuck in the jobs table forever, silently never
 * redistributing anything). dispatch(...)->afterResponse() runs this in
 * the SAME process, right after the HTTP response is already flushed to
 * the browser — the logout button responds instantly, and the teammate
 * handoff (plus its Pancake tagging calls) happens a moment later without
 * blocking anyone.
 */
class RedistributeLoggedOutTsaLeads
{
    use Dispatchable;

    public function __construct(private readonly TsaShift $tsa)
    {
    }

    public function handle(): void
    {
        LogoutLeadRedistributor::redistribute($this->tsa);
    }
}
