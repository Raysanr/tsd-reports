<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\PerformanceSnapshot;
use App\Support\ActivityLogger;
use App\Support\PerformanceSnapshotter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Performance History — frozen monthly snapshots per TSA. */
class PerformanceController extends Controller
{
    public function index(Request $request)
    {
        $months = PerformanceSnapshot::select('month')->distinct()->orderByDesc('month')->pluck('month')->map(fn ($m) => $m->format('Y-m'));
        $month = $this->parseMonth($request->input('month')) ?? ($months->first() ? Carbon::parse($months->first() . '-01') : now()->subMonthNoOverflow()->startOfMonth());

        $rows = PerformanceSnapshot::whereDate('month', $month->toDateString())
            ->orderByDesc('delivered')->orderByDesc('upsell_sales')->get();

        return view('archive.performance', [
            'month' => $month,
            'months' => $months,
            'rows' => $rows,
            'generatedAt' => $rows->max('updated_at'),
            'totals' => [
                'leads' => $rows->sum('leads'), 'called' => $rows->sum('called'), 'upsells' => $rows->sum('upsells'),
                'upsell_sales' => $rows->sum('upsell_sales'), 'delivered' => $rows->sum('delivered'), 'rts' => $rows->sum('rts'),
            ],
        ]);
    }

    public function generate(Request $request)
    {
        $month = $this->parseMonth($request->input('month'));
        abort_unless($month && $month->lte(now()->startOfMonth()), 422, 'Pick a month up to the current one.');

        $count = PerformanceSnapshotter::generate($month, auth()->id());
        ActivityLogger::log('archive.performance.snapshot', null, "Generated performance snapshot for {$month->format('F Y')} ({$count} TSAs)");

        return redirect()->route('archive.performance', ['month' => $month->format('Y-m')])
            ->with('success', "Snapshot saved for {$month->format('F Y')} — {$count} TSAs.");
    }

    private function parseMonth(?string $value): ?Carbon
    {
        return $value && preg_match('/^\d{4}-\d{2}$/', $value) ? Carbon::createFromFormat('Y-m-d', $value . '-01')->startOfDay() : null;
    }
}
