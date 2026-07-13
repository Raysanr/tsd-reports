<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use Illuminate\Support\Carbon;

class ChartsController extends Controller
{
    public function index()
    {
        // Filters persist per page: reopening Analytics via the sidebar (no query
        // string) restores the last range used here (page-specific session key).
        // Default range is the last 14 days ending today — long enough to show a
        // real trend, short enough to stay readable on a line chart.
        $dateFrom = request('date_from', session('filters.charts.date_from', now()->subDays(13)->toDateString()));
        $dateTo   = request('date_to',   session('filters.charts.date_to',   now()->toDateString()));
        session(['filters.charts.date_from' => $dateFrom, 'filters.charts.date_to' => $dateTo]);

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();

        $teamsConfig = config('teams', []);
        $orderTeams  = collect($teamsConfig)->pluck('order_team')->all();
        $teamNames   = collect($teamsConfig)->pluck('name', 'order_team');

        // Fetch every order in range ONCE; every chart below slices this same
        // in-memory collection (by day, by team, by hour) rather than re-querying —
        // same pattern already established in Leads Report / ProductPerformance.
        $orders = Order::whereBetween('pancake_created_at', [$from, $to])
            ->whereIn('team', $orderTeams)
            ->get();

        $ordersByDate = $orders->groupBy(fn($o) => $o->pancake_created_at->toDateString());

        $days = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $days[] = $cursor->copy();
        }

        $dailyLabels     = [];
        $rateSeries      = ['pick_up_rate' => [], 'conversion_rate' => [], 'upselling_rate' => []];
        $calledSeries    = [];
        $salesSeries     = [];
        $excessSeries    = [];
        $answeredSeries  = [];
        $unansweredSeries = [];
        $deliveredSeries = [];
        $rtsSeries       = [];

        foreach ($orderTeams as $team) {
            foreach ($rateSeries as $rate => $_) $rateSeries[$rate][$team] = [];
            $calledSeries[$team] = [];
            $salesSeries[$team]  = [];
        }

        foreach ($days as $day) {
            $dailyLabels[] = $day->format('M d');
            $dayOrders     = $ordersByDate->get($day->toDateString(), collect());

            foreach ($orderTeams as $team) {
                $teamDayOrders = $dayOrders->where('team', $team);
                $tally         = ProductPerformance::tally($teamDayOrders);

                foreach (array_keys($rateSeries) as $rate) {
                    $rateSeries[$rate][$team][] = $tally[$rate];
                }
                // Volume trend to pair with the 3 rate trends above — "how many leads
                // were actually called" alongside "how well were they handled".
                $calledSeries[$team][] = $tally['total_called'];

                // Cross-sell/upsell revenue only — matches the Dashboard's "Total
                // Cross-Sell Sales" definition, NOT Leads Report's full-realized-revenue
                // one. Confirmed with the user: the full-revenue figure (base product +
                // upsells) reads as implausibly large here compared to the Dashboard
                // number they're used to, even though it isn't a miscount — it's just
                // answering a bigger question. This chart answers the Dashboard's question.
                $salesSeries[$team][] = (float) $teamDayOrders->where('is_upsell', true)->sum('amount');
            }

            // Combined (both teams) disposition mix for that day.
            $combined = ProductPerformance::tally($dayOrders);
            $excessSeries[]     = $combined['excess'];
            $answeredSeries[]   = $combined['answered'];
            $unansweredSeries[] = $combined['unanswered'];

            // Delivered vs RTS upsell revenue, both teams combined — the trend
            // behind the RTS/Delivered report. Delivered = upsell orders the
            // customer received (status 3); RTS reads returned_upsell_amount, NOT
            // amount + is_upsell, because VOID_STATUSES forces is_upsell false on
            // Returning/Returned orders and leaves amount holding the whole
            // shipment's price (see RtsReportController / SyncTodayOrders).
            $deliveredSeries[] = (float) $dayOrders->where('is_upsell', true)->where('status_code', 3)->sum('amount');
            $rtsSeries[]       = (float) $dayOrders->where('is_returned_upsell', true)->sum('returned_upsell_amount');
        }

        // --- Product comparison: Upselling Rate per product across the whole range ---
        $products = Product::orderBy('sort_order')->get()
            ->sortBy(fn($p) => array_search($p->team, $orderTeams))
            ->values();
        // Same hidden-product rule as Leads Report: dropped only when there's
        // genuinely nothing to show for the selected range.
        $productRows = $products
            ->map(fn($p) => ['product' => $p, 'row' => ProductPerformance::buildRow($p, $orders)])
            ->reject(fn($item) => $item['product']->is_hidden && $item['row']['total'] === 0)
            ->pluck('row')
            ->sortByDesc('upselling_rate')
            ->values();

        // --- Hourly aggregate (0–23) across the whole range, both teams combined ---
        $ordersByHour = $orders->groupBy(fn($o) => (int) $o->pancake_created_at->format('G'));
        $hourlyLabels = [];
        $hourlyLeads  = [];
        $hourlyExcess = [];
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourOrders = $ordersByHour->get($hour, collect());
            if ($hourOrders->isEmpty()) continue;

            $tally          = ProductPerformance::tally($hourOrders);
            $hourlyLabels[] = HourFormatter::label($hour);
            $hourlyLeads[]  = $tally['total'];
            $hourlyExcess[] = $tally['excess'];
        }

        return view('charts', compact(
            'dateFrom', 'dateTo', 'dailyLabels',
            'rateSeries', 'calledSeries', 'salesSeries', 'excessSeries', 'answeredSeries', 'unansweredSeries',
            'deliveredSeries', 'rtsSeries',
            'productRows', 'hourlyLabels', 'hourlyLeads', 'hourlyExcess',
            'orderTeams', 'teamNames'
        ));
    }
}
