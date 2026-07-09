<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use Illuminate\Support\Carbon;

class TeamReportController extends Controller
{
    public function index()
    {
        // Filters persist per page: when this page is reopened via the sidebar (no query
        // string), fall back to the last team/date range used HERE — stored under a
        // page-specific session key so it never collides with TSA Performance's remembered
        // filters. A fresh session with no prior visit uses the defaults (today / sh-naturals).
        $dateFrom     = request('date_from', session('filters.team_report.date_from', now()->format('Y-m-d')));
        $dateTo       = request('date_to',   session('filters.team_report.date_to', $dateFrom));
        $selectedTeam = request('team', session('filters.team_report.team', 'sh-naturals'));
        $teamsConfig  = config('teams', []);
        $teams        = array_map(fn($t) => $t['name'], $teamsConfig);

        if (!array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'sh-naturals';
        }

        session([
            'filters.team_report.date_from' => $dateFrom,
            'filters.team_report.date_to'   => $dateTo,
            'filters.team_report.team'      => $selectedTeam,
        ]);

        $from      = Carbon::parse($dateFrom)->startOfDay();
        $to        = Carbon::parse($dateTo)->endOfDay();
        $orderTeam = $teamsConfig[$selectedTeam]['order_team'];

        // All orders for this team across the selected date range. The per-hour grouping
        // below buckets by hour-of-day (0–23), so a multi-day range naturally aggregates
        // each hour's activity across every day in the span.
        $ordersQuery = Order::where('team', $orderTeam)
            ->whereBetween('pancake_created_at', [$from, $to]);

        // Per-product hourly breakdown — one table per product (matches the source sheet:
        // a separate CANPRO/GINSENG/SINUXYL/AUDICURE tab each). Fetch all of this team's
        // orders for the day ONCE; ProductPerformance::buildRow re-matches from whatever
        // slice it's given, so passing it the whole day vs. one hour's subset both work
        // correctly and consistently with how TSA Performance counts the same data.
        $allOrders    = (clone $ordersQuery)->get();
        $ordersByHour = $allOrders->groupBy(fn($o) => (int) $o->pancake_created_at->format('G'));

        $products = Product::where('team', $orderTeam)->orderBy('sort_order')->get();

        $productTables = $products->map(function ($product) use ($ordersByHour, $allOrders) {
            $hourlyRows = [];
            for ($hour = 0; $hour <= 23; $hour++) {
                $hourOrders = $ordersByHour->get($hour, collect());
                if ($hourOrders->isEmpty()) continue;

                $row = ProductPerformance::buildRow($product, $hourOrders);
                // Skip hours where THIS product had no leads at all (other products may
                // still have had activity that hour — $hourOrders holds every product's
                // orders, and buildRow's matching already scoped it down to this one).
                if ($row['total'] === 0) continue;

                $hourlyRows[] = ['label' => HourFormatter::rangeLabel($hour), 'row' => $row];
            }

            return [
                'product'    => $product,
                'hourlyRows' => $hourlyRows,
                'total'      => ProductPerformance::buildRow($product, $allOrders),
            ];
        })->values();

        $currentOrders = (clone $ordersQuery)->orderByDesc('pancake_created_at')->get();
        $metricCols    = ProductPerformance::METRIC_COLUMNS;

        return view('team-report', compact(
            'dateFrom', 'dateTo', 'selectedTeam', 'teams',
            'currentOrders', 'productTables', 'metricCols'
        ));
    }
}
