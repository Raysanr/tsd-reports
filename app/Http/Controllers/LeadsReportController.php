<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use Illuminate\Support\Carbon;

class LeadsReportController extends Controller
{
    public function index()
    {
        // Window mode: 'last24h' (rolling last 24 hours ending NOW) or 'dates'
        // (explicit calendar range from the picker; the picker's Apply flips the
        // form's hidden range field to 'dates' — see date-picker.blade). Falls back
        // to session, same as team/dates below, so a picked range survives a sidebar
        // click away and back instead of silently resetting to Last 24h every visit
        // (confirmed real-world confusion: users picking "Yesterday" then bouncing to
        // another tab and back). The explicit "Last 24h" button in the topbar (see
        // leads-report.blade.php) is what submits range=last24h to escape back out
        // of a sticky dates-mode session — without it there'd be no way back.
        $mode = request('range', session('filters.leads_report.mode', 'last24h'));
        if (!in_array($mode, ['last24h', 'dates'], true)) {
            $mode = 'last24h';
        }

        $selectedTeam = request('team', session('filters.leads_report.team', 'sh-naturals'));
        $teamsConfig  = config('teams', []);
        // 'all' prepended, same convention as TSA Performance's team-button row —
        // NOT a real key in $teamsConfig, so it's handled as its own branch below
        // before the "unknown team → default" guard would otherwise stomp on it.
        $teams        = ['all' => 'ALL'] + array_map(fn($t) => $t['name'], $teamsConfig);

        if ($mode === 'last24h') {
            // now() is Asia/Manila (app timezone) and pancake_created_at is stored as
            // Manila-naive, so this window lines up with the stored timestamps as-is.
            // Anchored to the top of the current hour (not now-exactly-24h) so every
            // hour-of-day appears exactly once — that's what lets the rows read as one
            // 12am → 11pm day below instead of a two-day chronological list.
            $to       = now();
            $from     = now()->startOfHour()->subHours(23);
            $dateFrom = $from->toDateString();
            $dateTo   = $to->toDateString();
        } else {
            $dateFrom = request('date_from', session('filters.leads_report.date_from', now()->format('Y-m-d')));
            $dateTo   = request('date_to',   session('filters.leads_report.date_to', $dateFrom));
            $from     = Carbon::parse($dateFrom)->startOfDay();
            $to       = Carbon::parse($dateTo)->endOfDay();
        }

        // Human label for the active window, shown on every card header. Computed
        // here (not team-specific) so both the per-team view below and the
        // cross-team "ALL" view can share it without recomputing.
        $rangeLabel = $mode === 'last24h'
            ? 'Last 24h · ' . $from->format('M j g:iA') . ' → ' . $to->format('M j g:iA')
            : ($dateFrom === $dateTo ? $dateFrom : $dateFrom . ' → ' . $dateTo);

        session([
            'filters.leads_report.date_from' => $mode === 'dates' ? $dateFrom : session('filters.leads_report.date_from', now()->format('Y-m-d')),
            'filters.leads_report.date_to'   => $mode === 'dates' ? $dateTo : session('filters.leads_report.date_to', now()->format('Y-m-d')),
            'filters.leads_report.team'      => $selectedTeam,
            'filters.leads_report.mode'      => $mode,
        ]);

        // ALL — every team's product breakdown combined into one table (moved here
        // from TSA Performance's old "ALL" view, which now shows the per-TSA
        // equivalent of this same table instead — see TsaPerformanceController::indexAll()).
        if ($selectedTeam === 'all') {
            return $this->indexAll($dateFrom, $dateTo, $from, $to, $mode, $rangeLabel, $teamsConfig, $teams);
        }

        if (!array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'sh-naturals';
            session(['filters.leads_report.team' => $selectedTeam]);
        }

        $orderTeam = $teamsConfig[$selectedTeam]['order_team'];

        // Filtered by the real order-creation date (falling back to worked-at for
        // older rows synced before pancake_inserted_at existed — see Order::
        // getEffectiveCreatedAtAttribute()), NOT pancake_created_at directly, so a
        // day's total here matches what POS's own Created-At filter shows for the
        // same day. TSA Performance/Charts deliberately still use pancake_created_at
        // (worked-at) — that's what makes a backlog lead count toward the TSA who
        // actually worked it, on the day they worked it.
        $ordersQuery = Order::where('team', $orderTeam)
            ->whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to]);

        // Hour slots for the breakdown rows. 'dates' mode: hour-of-day buckets 0–23,
        // so a multi-day range aggregates each hour's activity across every day (the
        // original behavior). 'last24h' mode: one slot per REAL hour in the window,
        // keyed by date+hour so yesterday-4pm and today-4pm never merge — but ordered
        // by hour-of-day (12am → 11pm) rather than chronologically, so the table reads
        // like one normal day; the day prefix on each label shows which rows are
        // yesterday's evening vs today's.
        $slots = [];
        if ($mode === 'last24h') {
            $currentHour = (int) $to->format('G');
            for ($hour = 0; $hour <= 23; $hour++) {
                // The window covers each hour-of-day exactly once: hours up to and
                // including the current one fall today, later ones fall yesterday.
                $day = $hour <= $currentHour ? $to : $from;
                $slots[] = [
                    'key'   => $day->format('Y-m-d') . ' ' . $hour,
                    'label' => $day->format('M j') . ' · ' . HourFormatter::rangeLabel($hour),
                ];
            }
            $slotKeyOf = fn($o) => $o->effective_created_at->format('Y-m-d G');
        } else {
            for ($hour = 0; $hour <= 23; $hour++) {
                $slots[] = ['key' => $hour, 'label' => HourFormatter::rangeLabel($hour)];
            }
            $slotKeyOf = fn($o) => (int) $o->effective_created_at->format('G');
        }

        // Per-product hourly breakdown — one table per product (matches the source sheet:
        // a separate CANPRO/GINSENG/SINUXYL/AUDICURE tab each). Fetch all of this team's
        // orders for the window ONCE; ProductPerformance::buildRow re-matches from whatever
        // slice it's given, so passing it the whole window vs. one hour's subset both work
        // correctly and consistently with how TSA Performance counts the same data.
        $allOrders    = (clone $ordersQuery)->get();
        $ordersBySlot = $allOrders->groupBy($slotKeyOf);

        // Product-matching pool, separate from $allOrders above: a combo SKU can
        // bundle products from TWO different teams under one order (e.g. a
        // Pterygium order — Eyecare's own team — bundling 10 Sinuxyl units, an SH
        // Naturals product), but an order only ever carries the ONE team its
        // primary item belongs to. Team-scoping the pool buildRow() matches
        // against (like $allOrders does) would make that whole cross-team half of
        // the bundle invisible to SH Naturals' own SINUXYL row — confirmed in
        // production (order 1333736: 89 in POS vs 88 here). ProductPerformance::
        // buildRow() itself already trusts an explicit product/bundle_description
        // text match across team lines; it just needs a pool that isn't
        // pre-filtered down to one team to find it in. Grand Total and Recent
        // Orders deliberately still use the team-scoped $allOrders/$ordersQuery
        // above — a cross-team combo stays owned by its own team for THOSE.
        $matchPool       = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->whereIn('team', collect($teamsConfig)->pluck('order_team')->all())
            ->get();
        $matchPoolBySlot = $matchPool->groupBy($slotKeyOf);

        $products = Product::where('team', $orderTeam)->orderBy('sort_order')->get();

        $productTables = $products->map(function ($product) use ($slots, $matchPoolBySlot, $matchPool, $products) {
            $hourlyRows = [];
            foreach ($slots as $slot) {
                $hourOrders = $matchPoolBySlot->get($slot['key'], collect());
                if ($hourOrders->isEmpty()) continue;

                $row = ProductPerformance::buildRow($product, $hourOrders, $products);
                // Skip hours where THIS product had no leads at all (other products may
                // still have had activity that hour — $hourOrders holds every product's
                // orders, and buildRow's matching already scoped it down to this one).
                if ($row['total'] === 0) continue;

                $hourlyRows[] = ['label' => $slot['label'], 'row' => $row];
            }

            return [
                'product'    => $product,
                'hourlyRows' => $hourlyRows,
                'total'      => ProductPerformance::buildRow($product, $matchPool, $products),
            ];
        })->values();

        // Hidden products (Product Management's hide toggle) drop out of this list
        // once they have nothing to show for the selected range — but a hidden
        // product's table still renders for a range where it actually had leads, so
        // looking back at an old month it was still active in isn't affected.
        $productTables = $productTables->reject(
            fn($table) => $table['product']->is_hidden && $table['total']['total'] === 0
        )->values();

        // Grand total across ALL of this team's orders — tally() directly (no
        // product-matching filter), so every order counts exactly once even when its
        // tags match several products' keywords or none at all; summing the
        // per-product totals above would double-count the former and drop the latter.
        $grandTotal = ProductPerformance::tally($allOrders);

        // Same per-hour breakdown as each product table above, but tally() directly
        // per slot (no product-matching) — the Grand Total table's own hourly rows,
        // for the exact same "every order counts exactly once" reason as the
        // all-range $grandTotal above.
        $grandTotalHourlyRows = [];
        foreach ($slots as $slot) {
            $hourOrders = $ordersBySlot->get($slot['key'], collect());
            if ($hourOrders->isEmpty()) continue;

            $grandTotalHourlyRows[] = ['label' => $slot['label'], 'row' => ProductPerformance::tally($hourOrders)];
        }

        $currentOrders = (clone $ordersQuery)
            ->orderByRaw('COALESCE(pancake_inserted_at, pancake_created_at) DESC')
            ->get();
        $metricCols    = ProductPerformance::METRIC_COLUMNS;

        return view('leads-report', compact(
            'dateFrom', 'dateTo', 'selectedTeam', 'teams', 'mode', 'rangeLabel',
            'currentOrders', 'productTables', 'metricCols', 'grandTotal', 'grandTotalHourlyRows'
        ));
    }

    /** ALL — one row per product, combined across every team, for the whole window
     *  (no hourly split). This is the table that used to live on TSA Performance's
     *  "ALL" view; it moved here since it's a product breakdown, not a TSA one —
     *  TSA Performance's ALL view now shows the per-TSA equivalent instead. */
    private function indexAll(
        string $dateFrom, string $dateTo, Carbon $from, Carbon $to,
        string $mode, string $rangeLabel, array $teamsConfig, array $teams
    ) {
        $orderTeams = collect($teamsConfig)->pluck('order_team')->all();

        // See the per-team branch above for why this reads the effective
        // (creation-date-first) column instead of pancake_created_at directly.
        $orders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->whereIn('team', $orderTeams)
            ->get();

        // orderBy('team') alone would sort alphabetically ("Eyecare Team" < "SH
        // Naturals"), putting Eyecare first — wrong. Sort by each product's team's
        // position in $orderTeams (config order) instead, keeping sort_order as the
        // tie-breaker within a team (sortBy() is a stable sort, so pre-sorting by
        // sort_order first preserves that order within each team group).
        $products = Product::orderBy('sort_order')->get()
            ->sortBy(fn($p) => array_search($p->team, $orderTeams))
            ->values();

        // Same hidden-product rule as the per-team view above: dropped only when
        // there's genuinely nothing to show for the selected range.
        $productRows = $products
            ->map(fn($product) => ['product' => $product, 'row' => ProductPerformance::buildRow($product, $orders, $products)])
            ->reject(fn($item) => $item['product']->is_hidden && $item['row']['total'] === 0)
            ->pluck('row')
            ->values();

        // Grand Total — tally() directly over every order in range, same reasoning
        // as the per-team Grand Total above: summing the per-product rows would
        // double-count an order matching several products' tags and drop one
        // matching none.
        $grandTotal = ProductPerformance::tally($orders);

        return view('leads-report-all', [
            'dateFrom'    => $dateFrom, 'dateTo' => $dateTo, 'mode' => $mode, 'rangeLabel' => $rangeLabel,
            'productRows' => $productRows, 'grandTotal' => $grandTotal,
            'teams'       => $teams, 'selectedTeam' => 'all', 'metricCols' => ProductPerformance::METRIC_COLUMNS,
        ]);
    }
}
