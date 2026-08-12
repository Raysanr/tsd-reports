<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\LeadSyncRun;

/** Ported from call-tracker (merged into one app 2026-08-12) — reads the
 *  renamed LeadSyncRun (that app's own SyncRun; tsd-reports' own SyncRun
 *  model, a different Order-sync concept, is untouched). */
class SyncHealthController extends Controller
{
    public function index()
    {
        $runs = LeadSyncRun::orderByDesc('ran_at')->paginate(30);

        return view('calls.sync-health', ['runs' => $runs]);
    }
}
