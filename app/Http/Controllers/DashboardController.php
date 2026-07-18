<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\TsaShift;
use App\Support\ProductPerformance;
use App\Support\SyncHealth;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Filters persist per page: reopening the Dashboard via the sidebar (no query
        // string) restores the last date range used here. Page-specific session key so it
        // never collides with the other reports' remembered filters.
        $fromInput = $request->input('date_from', session('filters.dashboard.date_from', now()->toDateString()));
        $toInput   = $request->input('date_to',   session('filters.dashboard.date_to', $fromInput));
        session(['filters.dashboard.date_from' => $fromInput, 'filters.dashboard.date_to' => $toInput]);

        $dateFrom = Carbon::parse($fromInput)->startOfDay();
        $dateTo   = Carbon::parse($toInput)->endOfDay();

        $apiConnected          = !empty(Setting::get('pancake_api_key', config('services.pancake.api_key')));
        $dbError               = null;
        $hasSyncedData         = false;
        $reconciliationIssues  = json_decode(Setting::get('reconciliation_issues', '[]'), true) ?: [];

        $stats          = ['total_sales' => 0, 'total_orders' => 0, 'restocking_count' => 0, 'restocking_value' => 0, 'cancelled_count' => 0, 'cancelled_value' => 0, 'cancelled_unknown_count' => 0, 'last_synced' => null, 'sync_interval' => 2, 'sync_stale' => true, 'total_leads' => 0, 'pick_up_rate' => null, 'upselling_rate' => null, 'aov' => 0];
        $recentOrders   = collect();
        $syncRuns       = collect();
        $tsaLeaderboard   = collect();
        $topProducts      = collect();
        $hourlyActivity   = collect();
        $teamComparison   = collect();
        $restockingByTsa  = collect();
        $restockingByTeam = collect();
        $topTsa           = null;

        try {
            $hasSyncedData = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])->exists();

            $upsells = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])->where('is_upsell', true);

            $totalOrders = (clone $upsells)->count();
            $grossSales  = (clone $upsells)->sum('amount');

            // Show every order attributed to a known TSA — not just is_upsell=true —
            // so orders excluded from gross sales (e.g. status "Restocking") are still
            // visible here with their status label, instead of silently disappearing.
            $recentOrders = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereNotNull('tsa_name')
                ->orderByDesc('pancake_created_at')
                ->limit(10)
                ->get();

            // Orders currently sitting in "Restocking" (awaiting stock) — excluded from
            // gross sales above, surfaced here so it's clear how much revenue is pending
            // rather than lost.
            $restocking = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->where('status_code', 11);

            // Cancelled upsells — different from Restocking: the customer cancelled just
            // the TSA's upsell add-on while their primary order kept going (is_upsell is
            // already forced false for these by SyncTodayOrders, so they're automatically
            // excluded from $grossSales/$totalOrders above with no separate subtraction
            // needed here). cancelled_upsell_amount preserves what the add-on would have
            // been worth, captured before Pancake's own data dropped that line item —
            // but only when a prior sync had already seen the order as a live upsell.
            // When that never happened, cancelled_upsell_amount is NULL (not 0): Pancake's
            // own histories log never retains an items/price snapshot, so that value is
            // genuinely unrecoverable, not just missing — SUM() below ignores those NULLs
            // rather than silently treating them as a confirmed ₱0.
            $cancelledUpsells = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->where('is_cancelled_upsell', true);

            // Extracted into App\Support\SyncHealth so this page and the dedicated
            // Sync Health page (SyncHealthController) can never drift out of sync
            // on what counts as "stale".
            $syncHealth = SyncHealth::status();

            $stats = [
                'total_sales'      => $grossSales,
                'total_orders'     => $totalOrders,
                'restocking_count' => (clone $restocking)->count(),
                'restocking_value' => (clone $restocking)->sum('amount'),
                'cancelled_count'         => (clone $cancelledUpsells)->count(),
                'cancelled_value'         => (clone $cancelledUpsells)->sum('cancelled_upsell_amount'),
                'cancelled_unknown_count' => (clone $cancelledUpsells)->whereNull('cancelled_upsell_amount')->count(),
                'last_synced'      => $syncHealth['last_synced'],
                'sync_interval'    => $syncHealth['sync_interval'],
                'sync_stale'       => $syncHealth['sync_stale'],
            ];

            // Company-wide lead/call funnel — same counting logic as the Leads Report
            // and TSA Performance "ALL" view (ProductPerformance::tally), just run over
            // every team's orders in this range instead of one team, so Total Leads /
            // Pick-up Rate / Upselling Rate on the Dashboard can never drift from how
            // those same metrics are defined everywhere else in the app.
            $leadTally = ProductPerformance::tally(
                Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])->get()
            );

            $stats['total_leads']    = $leadTally['total'];
            $stats['pick_up_rate']   = $leadTally['pick_up_rate'];
            $stats['upselling_rate'] = $leadTally['upselling_rate'];
            $stats['aov']            = $totalOrders > 0 ? $grossSales / $totalOrders : 0;

            // Last 20 runs, oldest→newest, for the sync activity trend below.
            $syncRuns = SyncRun::orderByDesc('ran_at')->limit(20)->get()->reverse()->values();

            // Today's TSA leaderboard — ranked by upsells (the metric the rest of this
            // app is built around), total calls and rate alongside it for context.
            $shiftsByKey = TsaShift::all()->keyBy('tsa_key');
            $teamNames   = collect(config('teams'))->pluck('name', 'order_team');

            $tsaLeaderboard = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereNotNull('tsa_name')
                ->selectRaw('tsa_name, COUNT(*) as total_calls, SUM(CASE WHEN is_upsell THEN 1 ELSE 0 END) as upsell_count, SUM(CASE WHEN is_upsell THEN amount ELSE 0 END) as upsell_sales')
                ->groupBy('tsa_name')
                ->orderByDesc('upsell_count')
                ->orderByDesc('total_calls')
                ->get()
                ->map(function ($row) use ($shiftsByKey, $teamNames) {
                    $shift = $shiftsByKey->get($row->tsa_name);
                    $row->display_name = $shift->display_name ?? $row->tsa_name;
                    $row->team_name    = $shift ? ($teamNames[$shift->team] ?? $shift->team) : null;
                    $row->upsell_rate  = $row->total_calls > 0 ? round($row->upsell_count / $row->total_calls * 100, 1) : 0.0;
                    return $row;
                });

            // Top TSA by upsells — same ranking as the leaderboard below, surfaced as a
            // KPI-row spotlight so it's visible without scrolling.
            $topTsa = $tsaLeaderboard->first();

            // Top upsell products — which items are actually driving today's cross-sell
            // revenue, not just which TSA is closing them.
            $topProducts = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->where('is_upsell', true)
                ->whereNotNull('product')
                ->selectRaw('product, COUNT(*) as upsell_count, SUM(amount) as total_sales')
                ->groupBy('product')
                ->orderByDesc('upsell_count')
                ->limit(6)
                ->get();

            // Hourly activity — total calls per hour across the whole day, so shift-timing
            // gaps or coverage holes (the kind we found by hand this session) are visible
            // from the dashboard directly instead of requiring a manual DB dig.
            // HOUR() is MySQL-only; Postgres needs EXTRACT(HOUR FROM ...) for the
            // same thing — pick the right expression for whichever DB is connected
            // so this works unchanged on either.
            $hourExpr = DB::connection()->getDriverName() === 'pgsql'
                ? 'EXTRACT(HOUR FROM pancake_created_at)'
                : 'HOUR(pancake_created_at)';

            $hourlyCounts = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->selectRaw("{$hourExpr} as hour, COUNT(*) as total")
                ->groupByRaw($hourExpr)
                ->pluck('total', 'hour');

            $hourlyActivity = collect(range(0, 23))->map(fn($h) => (int) ($hourlyCounts[$h] ?? 0));

            // Team comparison — orders, upsell rate and revenue side by side, replacing
            // the old Shop Lines panel (which only showed revenue) with the metric this
            // whole app is actually built around: upsell rate, not just raw sales.
            $teamComparison = collect(config('teams'))->map(function ($teamConfig) use ($dateFrom, $dateTo) {
                $base  = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])->where('team', $teamConfig['order_team']);
                $total = (clone $base)->count();
                $upsellCount = (clone $base)->where('is_upsell', true)->count();

                return [
                    'name'         => $teamConfig['name'],
                    'total_calls'  => $total,
                    'upsell_count' => $upsellCount,
                    'upsell_rate'  => $total > 0 ? round($upsellCount / $total * 100, 1) : 0.0,
                    'revenue'      => (clone $base)->where('is_upsell', true)->sum('amount'),
                ];
            })->values();

            // Restocking breakdown — same "Restocking" orders behind the Total Restocking
            // KPI tile above, broken out per TSA and per brand instead of one lump sum.
            $restockingByTsa = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->where('status_code', 11)
                ->whereNotNull('tsa_name')
                ->selectRaw('tsa_name, COUNT(*) as restocking_count, SUM(amount) as restocking_value')
                ->groupBy('tsa_name')
                ->orderByDesc('restocking_value')
                ->get()
                ->map(function ($row) use ($shiftsByKey, $teamNames) {
                    $shift = $shiftsByKey->get($row->tsa_name);
                    $row->display_name = $shift->display_name ?? $row->tsa_name;
                    $row->team_name    = $shift ? ($teamNames[$shift->team] ?? $shift->team) : null;
                    return $row;
                });

            $restockingByTeam = collect(config('teams'))->map(function ($teamConfig) use ($dateFrom, $dateTo) {
                $base = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                    ->where('team', $teamConfig['order_team'])
                    ->where('status_code', 11);

                return [
                    'name'             => $teamConfig['name'],
                    'restocking_count' => (clone $base)->count(),
                    'restocking_value' => (clone $base)->sum('amount'),
                ];
            })->values();

        } catch (QueryException $e) {
            $dbError = 'Database schema not ready — run: php artisan migrate';
            Log::error('DashboardController DB error: ' . $e->getMessage());
        }

        return view('dashboard', compact(
            'stats', 'recentOrders', 'apiConnected', 'dbError',
            'dateFrom', 'dateTo', 'hasSyncedData', 'syncRuns',
            'tsaLeaderboard', 'topProducts', 'hourlyActivity', 'teamComparison',
            'restockingByTsa', 'restockingByTeam', 'topTsa', 'reconciliationIssues'
        ));
    }

    public function sync(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->toDateString());
        $dateTo   = $request->input('date_to',   $dateFrom);

        $from = Carbon::parse($dateFrom);
        $to   = Carbon::parse($dateTo);

        // Every Artisan::call below writes exactly one new SyncRun row
        // (SyncTodayOrders::recordRun) — remember the high-water mark first so
        // the rows created by THIS request can be picked out afterward without
        // guessing how many days were in range.
        // NOT safe against concurrent /sync requests: two overlapping requests
        // (e.g. two users syncing at once) can each pick up rows the other one
        // wrote, inflating one request's reported counts. Acceptable for now on
        // this low-traffic internal admin tool; would need per-request locking
        // or a request-scoped marker column to fix properly.
        $lastRunIdBeforeSync = SyncRun::max('id') ?? 0;

        while ($from->lte($to)) {
            \Artisan::call('pancake:sync-today', ['--date' => $from->toDateString()]);
            $from->addDay();
        }

        $runsFromThisSync = SyncRun::where('id', '>', $lastRunIdBeforeSync)->orderBy('id')->get();
        $firstFailure      = $runsFromThisSync->first(fn (SyncRun $run) => !$run->success);

        return response()->json([
            'success'       => $firstFailure === null,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'new_orders'    => (int) $runsFromThisSync->sum('new_orders'),
            'upsell_count'  => (int) $runsFromThisSync->sum('upsell_count'),
            'upsell_sales'  => (float) $runsFromThisSync->sum('upsell_sales'),
            'error_message' => $firstFailure ? SyncHealth::redactSecrets($firstFailure->error_message) : null,
            // JSON_PRESERVE_ZERO_FRACTION: without it, PHP's json_encode renders a
            // float that happens to be a whole number (e.g. upsell_sales = 0.0) as
            // the bare integer `0`, not `0.0` — which silently turns this field
            // into a mixed int/float type depending on the data instead of always
            // being a float on the wire.
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
