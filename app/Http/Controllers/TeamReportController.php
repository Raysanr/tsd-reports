<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\HourFormatter;
use Illuminate\Support\Carbon;

class TeamReportController extends Controller
{
    public function index()
    {
        $selectedDate = request('date', now()->format('Y-m-d'));
        $selectedTeam = request('team', 'sh-naturals');
        $teamsConfig  = config('teams', []);
        $teams        = array_map(fn($t) => $t['name'], $teamsConfig);

        if (!array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'sh-naturals';
        }

        $date = Carbon::parse($selectedDate);

        // All orders for this team/date
        $ordersQuery = Order::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->whereBetween('pancake_created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);

        // Group by hour. total_orders = every lead handled that hour; total_sales = realized
        // revenue only (is_upsell = true) — same convention as the Dashboard and TSA Performance.
        $byHour = (clone $ordersQuery)
            ->selectRaw('HOUR(pancake_created_at) as hour, COUNT(*) as total_orders, SUM(CASE WHEN is_upsell THEN amount ELSE 0 END) as total_sales')
            ->groupByRaw('HOUR(pancake_created_at)')
            ->orderByRaw('HOUR(pancake_created_at)')
            ->get()
            ->keyBy('hour');

        // Group by disposition
        $byDisposition = (clone $ordersQuery)
            ->selectRaw('disposition, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('disposition')
            ->orderByDesc('count')
            ->get();

        // Group by TSA
        $byTsa = (clone $ordersQuery)
            ->selectRaw('tsa_name, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('tsa_name')
            ->orderByDesc('count')
            ->get();

        // 'orders' = every lead handled; 'sales' = realized revenue only (is_upsell = true),
        // matching the Dashboard's "Total Cross-Sell Sales" definition.
        $totals = [
            'orders' => (clone $ordersQuery)->count(),
            'sales'  => (clone $ordersQuery)->where('is_upsell', true)->sum('amount'),
        ];

        // Build hourly rows for the table (8 AM – 9 PM)
        $hourlyRows = [];
        for ($h = 8; $h <= 21; $h++) {
            $row = $byHour->get($h);
            $hourlyRows[] = [
                'hour'         => HourFormatter::label($h),
                'total_orders' => $row?->total_orders ?? 0,
                'total_sales'  => $row?->total_sales  ?? 0,
            ];
        }

        $currentOrders = (clone $ordersQuery)->orderByDesc('pancake_created_at')->get();

        return view('team-report', compact(
            'selectedDate', 'selectedTeam', 'teams',
            'currentOrders', 'hourlyRows', 'byDisposition', 'byTsa', 'totals'
        ));
    }
}
