<?php

namespace App\Jobs;

use App\Models\TsaShift;
use App\Support\LogoutLeadRedistributor;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Closes the login-side gap in lead redistribution (root-caused 2026-09-28
 * from Grace Olivo's Leads page showing a pile of never-dialed, never-
 * redistributed leads): LogoutLeadRedistributor::redistribute() only ever
 * runs at the moment a TSA logs out, and only checks who's online right
 * then. If the entire outgoing shift logs out before anyone from the
 * incoming shift logs in, that check finds nobody online and silently
 * no-ops — by design, so she isn't left orphaned mid-check — but nothing
 * ever re-checks later once someone finally does log in, so the backlog
 * sits stranded indefinitely. Dispatched from TsaShift::applyStatusChange()
 * on the LOGIN transition (moving OUT of STATUS_LOGOUT), this re-runs the
 * exact same redistribute() logic for every TSA who is currently logged
 * out — see LogoutLeadRedistributor::sweepStrandedBacklogs().
 *
 * Same afterResponse()-only pattern as RedistributeLoggedOutTsaLeads (see
 * that job's own doc comment) and for the same reason: no queue worker
 * runs anywhere in this app's Railway deployment, so this must never be
 * ShouldQueue. dispatch(...)->afterResponse() runs it in the same process
 * right after the login request's response is already flushed, so a TSA
 * with a large stranded backlog to inherit never has her own login held up
 * waiting for local DB writes.
 */
class RedistributeStrandedTsaLeads
{
    use Dispatchable;

    public function __construct(private readonly TsaShift $tsa)
    {
    }

    public function handle(): void
    {
        LogoutLeadRedistributor::sweepStrandedBacklogs($this->tsa);
    }
}
