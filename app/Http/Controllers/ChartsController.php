<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use App\Support\Teams;
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

        $teamsConfig = Teams::config();
        $orderTeams  = collect($teamsConfig)->pluck('order_team')->all();
        $teamNames   = collect($teamsConfig)->pluck('name', 'order_team');

        // Fetch every order in range ONCE; every chart below slices this same
        // in-memory collection (by day, by team, by hour) rather than re-querying —
        // same pattern already established in Leads Report / ProductPerformance.
        //
        // Explicit select(): this page can hydrate thousands of orders at once (the
        // default range alone is 14 days), and every column below is the full list
        // actually read anywhere in this method or by ProductPerformance's
        // tally()/matchingOrders()/buildRow()/conflictingProduct() — tsa_name,
        // is_restocking_upsell/restocking_upsell_amount, cancelled_upsell_amount,
        // and the 3 unused timestamp columns (each one a real Carbon object once
        // hydrated) were dead weight on every single row. Confirmed live: this was
        // a real contributor to Render's free-tier instance (0.1 CPU/512MB) OOM-
        // crashing specifically on this page — see the investigation that led here.
        $orders = Order::whereBetween('pancake_created_at', [$from, $to])
            ->whereIn('team', $orderTeams)
            ->select([
                'id', 'pancake_created_at', 'team', 'status_code', 'is_upsell',
                'is_returned_upsell', 'is_cancelled_upsell', 'raw_tags', 'disposition',
                'amount', 'product', 'base_product', 'bundle_description', 'returned_upsell_amount',
                'tsa_name', 'excluded_upsell_seller', 'is_duplicated_by_logistics', 'is_upsell_on_voided_order',
            ])
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
                // Cross-Sell Sales" definition (Order::isRealUpsell(), not a bare
                // is_upsell — see that method's own doc comment), NOT Leads Report's
                // full-realized-revenue one. Confirmed with the user: the full-revenue
                // figure (base product + upsells) reads as implausibly large here
                // compared to the Dashboard number they're used to, even though it
                // isn't a miscount — it's just answering a bigger question. This chart
                // answers the Dashboard's question, so it needs the same fix that
                // question's own number got.
                $salesSeries[$team][] = (float) $teamDayOrders->filter(fn ($o) => Order::isRealUpsell($o))->sum('amount');
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
            ->map(fn($p) => ['product' => $p, 'row' => ProductPerformance::buildRow($p, $orders, $products)])
            ->reject(fn($item) => $item['product']->is_hidden && $item['row']['total'] === 0)
            ->pluck('row')
            ->sortByDesc('upselling_rate')
            ->values();

        // --- TSA Rankings: Pick-up/Conversion/Upselling Rate per TSA across the
        // whole range — explicit request, 2026-09-03. ProductPerformance::tsaRows()
        // (the same shared per-TSA grouping the Dashboard leaderboard and TSA
        // Performance both already use — see that method's own doc comment) so
        // this ranking can never drift out of sync with what those pages already
        // show for the same TSA/range. Dropped entirely when a TSA has no called
        // leads in range (total_called === 0) — every rate is null in that case, a
        // meaningless 0%/dash row rather than a real ranking position.
        $shifts  = TsaShift::whereIn('team', $orderTeams)->orderBy('sort_order')->get()
            ->sortBy(fn($s) => array_search($s->team, $orderTeams))
            ->values();
        $tsaTallyByKey = ProductPerformance::tsaRows($orders, $shifts);
        $tsaRankings = $shifts
            ->map(fn($shift) => array_merge($tsaTallyByKey[$shift->tsa_key], [
                'tsa_key'      => $shift->tsa_key,
                'display_name' => $shift->display_name,
                'team'         => $teamNames[$shift->team] ?? $shift->team,
            ]))
            ->reject(fn($row) => $row['total_called'] === 0)
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
            'productRows', 'tsaRankings', 'hourlyLabels', 'hourlyLeads', 'hourlyExcess',
            'orderTeams', 'teamNames'
        ));
    }
}
