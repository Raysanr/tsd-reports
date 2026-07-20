<?php

namespace App\Http\Controllers;

use App\Models\ReconciliationRun;

class ReconciliationController extends Controller
{
    public function index()
    {
        $runs = ReconciliationRun::orderByDesc('ran_at')->paginate(30)->withQueryString();

        // Same aggregate-over-ALL-runs pattern as Sync Health's totalRuns/failedRuns —
        // computed independently of the current page so paginating doesn't change these.
        $totalRuns = ReconciliationRun::count();
        $runsWithIssues = ReconciliationRun::where('has_issues', true)->count();
        $lastRun = ReconciliationRun::orderByDesc('ran_at')->first();

        return view('reconciliation', compact('runs', 'totalRuns', 'runsWithIssues', 'lastRun'));
    }

    public function show(ReconciliationRun $reconciliationRun)
    {
        return view('reconciliation-show', ['run' => $reconciliationRun]);
    }
}
