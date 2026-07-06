<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\TsaShift;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', now()->toDateString()))->startOfDay();
        $dateTo   = Carbon::parse($request->input('date_to',   $dateFrom->toDateString()))->endOfDay();

        $apiConnected  = !empty(Setting::get('pancake_api_key', config('services.pancake.api_key')));
        $dbError       = null;
        $hasSyncedData = false;

        $stats          = ['total_sales' => 0, 'total_orders' => 0, 'restocking_count' => 0, 'restocking_value' => 0, 'last_synced' => null, 'sync_interval' => 2, 'sync_stale' => true];
        $recentOrders   = collect();
        $syncRuns       = collect();
        $tsaLeaderboard = collect();
        $topProducts    = collect();
        $hourlyActivity = collect();
        $teamComparison = collect();

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

            $lastSynced     = Setting::get('last_synced');
            $syncIntervalMin = max(1, min(60, (int) Setting::get('sync_interval', 2)));

            // The scheduler (routes/console.php) re-syncs every $syncIntervalMin minutes.
            // If nothing landed for 3x that interval, the cron has likely stopped firing
            // (server down, schedule:run not wired up, API key revoked, etc.) — a plain
            // timestamp can't tell you that at a glance, so surface it as a health flag
            // instead of just "X minutes ago", which reads the same whether that's normal
            // or a silent outage.
            $syncStale = !$lastSynced
                || Carbon::parse($lastSynced)->diffInMinutes(now()) > ($syncIntervalMin * 3);

            $stats = [
                'total_sales'      => $grossSales,
                'total_orders'     => $totalOrders,
                'restocking_count' => (clone $restocking)->count(),
                'restocking_value' => (clone $restocking)->sum('amount'),
                'last_synced'      => $lastSynced,
                'sync_interval'    => $syncIntervalMin,
                'sync_stale'       => $syncStale,
            ];

            // Last 20 runs, oldest→newest, for the sync activity trend below.
            $syncRuns = SyncRun::orderByDesc('ran_at')->limit(20)->get()->reverse()->values();

            // Today's TSA leaderboard — ranked by upsells (the metric the rest of this
            // app is built around), total calls and rate alongside it for context.
            $shiftsByKey = TsaShift::all()->keyBy('tsa_key');
            $teamNames   = collect(config('teams'))->pluck('name', 'order_team');

            $tsaLeaderboard = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereNotNull('tsa_name')
                ->selectRaw('tsa_name, COUNT(*) as total_calls, SUM(CASE WHEN is_upsell THEN 1 ELSE 0 END) as upsell_count')
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
            $hourlyCounts = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->selectRaw('HOUR(pancake_created_at) as hour, COUNT(*) as total')
                ->groupByRaw('HOUR(pancake_created_at)')
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

        } catch (QueryException $e) {
            $dbError = 'Database schema not ready — run: php artisan migrate';
            Log::error('DashboardController DB error: ' . $e->getMessage());
        }

        return view('dashboard', compact(
            'stats', 'recentOrders', 'apiConnected', 'dbError',
            'dateFrom', 'dateTo', 'hasSyncedData', 'syncRuns',
            'tsaLeaderboard', 'topProducts', 'hourlyActivity', 'teamComparison'
        ));
    }

    public function sync(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->toDateString());
        $dateTo   = $request->input('date_to',   $dateFrom);

        $from = Carbon::parse($dateFrom);
        $to   = Carbon::parse($dateTo);

        while ($from->lte($to)) {
            \Artisan::call('pancake:sync-today', ['--date' => $from->toDateString()]);
            $from->addDay();
        }

        return response()->json(['success' => true, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
    }
}
