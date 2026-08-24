<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

        // Shift-start blanking (explicit request): hours before this team's
        // first working TSA's shift start that day show no Called/disposition/
        // rate/Excess data at all (New Leads is untouched — leads keep arriving
        // regardless of whether anyone's working yet), and the shift-start hour
        // itself absorbs the WHOLE day-so-far backlog's disposition breakdown
        // in one lump — a TSA starting their shift works through everything
        // that piled up overnight, not just that hour's own new leads, so
        // Called Leads can exceed that hour's New Leads and Excess can go
        // negative there by design. Hours after the shift starts are
        // unaffected. Only meaningful for a single calendar day's hourly view:
        // a multi-day 'dates' range aggregates every day's same hour-of-day
        // into one row, where "the shift hasn't started yet" no longer has one
        // answer — skipped there (dateFrom !== dateTo), unchanged behavior.
        $applyShiftCutoff = $mode === 'last24h' || $dateFrom === $dateTo;
        $teamShifts       = $applyShiftCutoff ? TsaShift::where('team', $orderTeam)->get() : collect();

        $slotHourOf = $mode === 'last24h'
            ? fn($slot) => (int) explode(' ', $slot['key'])[1]
            : fn($slot) => (int) $slot['key'];
        $slotDateOf = $mode === 'last24h'
            ? fn($slot) => Carbon::parse(explode(' ', $slot['key'])[0])
            : fn($slot) => Carbon::parse($dateFrom);
        $slotKeyForHour = $mode === 'last24h'
            ? fn(Carbon $date, int $hour) => $date->format('Y-m-d') . ' ' . $hour
            : fn(Carbon $date, int $hour) => $hour;

        // Per-product hourly breakdown — one table per product (matches the source sheet:
        // a separate CANPRO/GINSENG/SINUXYL/AUDICURE tab each). ProductPerformance::
        // buildRow re-matches from whatever slice it's given, so passing it the whole
        // window vs. one hour's subset both work correctly and consistently with how
        // TSA Performance counts the same data.
        //
        // Product-matching pool, cross-team (not scoped to $orderTeam like $ordersQuery
        // above): a combo SKU can bundle products from TWO different teams under one
        // order (e.g. a Pterygium order — Eyecare's own team — bundling 10 Sinuxyl
        // units, an SH Naturals product), but an order only ever carries the ONE team
        // its primary item belongs to. Team-scoping this pool would make that whole
        // cross-team half of the bundle invisible to SH Naturals' own SINUXYL row —
        // confirmed in production (order 1333736: 89 in POS vs 88 here).
        // ProductPerformance::buildRow() itself already trusts an explicit product/
        // bundle_description text match across team lines; it just needs a pool that
        // isn't pre-filtered down to one team to find it in. Grand Total below is
        // built from the SAME pool (a straight sum of these product rows), so a combo
        // order is never invisible to it either — Recent Orders ($currentOrders,
        // built from $ordersQuery further down) is the one thing that deliberately
        // stays team-scoped, since a combo order is only ever OWNED by its own team.
        $matchPool       = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->whereIn('team', collect($teamsConfig)->pluck('order_team')->all())
            ->get();
        $matchPoolBySlot = $matchPool->groupBy($slotKeyOf);

        $products = Product::where('team', $orderTeam)->orderBy('sort_order')->get();

        $productTables = $products->map(function ($product) use (
            $slots, $matchPoolBySlot, $matchPool, $products,
            $applyShiftCutoff, $teamShifts, $slotHourOf, $slotDateOf, $slotKeyForHour
        ) {
            $hourlyRows = $this->buildHourlyRows(
                $slots, $matchPoolBySlot,
                fn(Collection $orders) => ProductPerformance::buildRow($product, $orders, $products),
                $applyShiftCutoff, $teamShifts, $slotHourOf, $slotDateOf, $slotKeyForHour
            );

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

        // Grand Total — the sum of the product rows above (post hidden-product
        // rejection, so it matches exactly what's visibly shown), full stop.
        // Explicit request (2026-08-21, reverting the two attempts right above
        // this in git history — a distinct-order tally() to match Dashboard/
        // TSA Performance, then an "Other/Unmatched Product" row to reconcile
        // that against this page's own rows): "when you plus all of this it
        // should be [the row sum]... so do that only". An order for a
        // genuinely untracked product (no Product row configured yet, or no
        // matching keyword/alias) is simply not counted here at all — the fix
        // for that is adding the missing product in Product Management, not
        // reconciling it on this page. This can once again disagree with
        // Dashboard/TSA Performance's own distinct-order tally whenever an
        // untracked-product order exists in range; that's the accepted
        // trade-off of this explicit choice, not an oversight.
        $visibleProducts = $productTables->pluck('product');
        $teamOrders       = (clone $ordersQuery)->get();
        $grandTotal       = ProductPerformance::sumRows($productTables->pluck('total'));

        // Same per-hour breakdown as each product table above, but summing
        // that hour's per-product rows (same reasoning as the all-range
        // $grandTotal just above) instead of tally()-ing the hour's raw
        // orders directly. Sums over $matchPool (cross-team pool), matching
        // what the per-product hourly rows themselves are built from.
        $grandTotalHourlyRows = $this->buildHourlyRows(
            $slots, $matchPoolBySlot,
            fn (Collection $orders) => ProductPerformance::sumRows(
                $visibleProducts->map(fn ($product) => ProductPerformance::buildRow($product, $orders, $products))
            ),
            $applyShiftCutoff, $teamShifts, $slotHourOf, $slotDateOf, $slotKeyForHour
        );

        $currentOrders = $teamOrders
            ->sortByDesc(fn ($o) => $o->effective_created_at)
            ->values();
        $metricCols    = ProductPerformance::METRIC_COLUMNS;

        return view('leads-report', compact(
            'dateFrom', 'dateTo', 'selectedTeam', 'teams', 'mode', 'rangeLabel',
            'currentOrders', 'productTables', 'metricCols', 'grandTotal', 'grandTotalHourlyRows'
        ));
    }

    /** Builds the hourly rows for one product's table (or Grand Total, via a
     *  tally()-only $computeRow) — plain per-hour rows when $applyShiftCutoff is
     *  false, or blank-before-shift-start / lump-at-shift-start otherwise. See
     *  the shift-cutoff comment in index() for the full reasoning. */
    private function buildHourlyRows(
        array $slots, Collection $ordersBySlot, \Closure $computeRow,
        bool $applyShiftCutoff, Collection $teamShifts,
        \Closure $slotHourOf, \Closure $slotDateOf, \Closure $slotKeyForHour
    ): array {
        if (!$applyShiftCutoff) {
            $rows = [];
            foreach ($slots as $slot) {
                $hourOrders = $ordersBySlot->get($slot['key'], collect());
                if ($hourOrders->isEmpty()) continue;

                $row = $computeRow($hourOrders);
                // Skip hours with no leads at all for this row (other products
                // may still have had activity that hour — $hourOrders holds
                // every product's orders, and $computeRow's own matching
                // already scoped it down to this one).
                if ($row['total'] === 0) continue;

                $rows[] = ['label' => $slot['label'], 'row' => $row];
            }
            return $rows;
        }

        // Earliest active TSA's shift-start hour per calendar date, cached so
        // a multi-slot (last24h) window only computes it once per real day.
        $cutoffCache = [];
        $cutoffFor = function (Carbon $date) use ($teamShifts, &$cutoffCache) {
            $key = $date->toDateString();
            if (!array_key_exists($key, $cutoffCache)) {
                $starts = $teamShifts
                    ->reject(fn($s) => !$s->shift_start || $s->isOffOn($date))
                    ->map(fn($s) => (int) date('G', strtotime($s->shift_start)));
                // No active TSA that day (everyone off, or nobody configured
                // with a shift_start) — no cutoff, show every hour normally
                // rather than blanking a whole day with no rule to apply.
                $cutoffCache[$key] = $starts->isEmpty() ? null : $starts->min();
            }
            return $cutoffCache[$key];
        };

        $rows = [];
        foreach ($slots as $slot) {
            $hourOrders = $ordersBySlot->get($slot['key'], collect());
            $date       = $slotDateOf($slot);
            $hour       = $slotHourOf($slot);
            $cutoff     = $cutoffFor($date);

            $row = $computeRow($hourOrders);

            if ($cutoff !== null && $hour < $cutoff) {
                // Before the team's first shift starts that day: no calls have
                // happened yet — every disposition/rate/Excess field blanks
                // (tally/buildRow's own zero-orders shape already nulls rates
                // and zeroes every count), keeping only this hour's own real
                // New Leads total.
                $realTotal = $row['total'];
                $row = $computeRow(collect());
                $row['total'] = $realTotal;
            } elseif ($cutoff !== null && $hour === $cutoff) {
                $backlog = collect();
                for ($h = 0; $h <= $cutoff; $h++) {
                    $backlog = $backlog->merge($ordersBySlot->get($slotKeyForHour($date, $h), collect()));
                }
                $realTotal = $row['total'];
                $row = $computeRow($backlog);
                // New Leads stays just this hour's own count; every other
                // field (Called Leads, disposition breakdown, rates) reflects
                // the WHOLE backlog batch just caught up on — Excess is
                // recomputed against the real (smaller) total accordingly, so
                // it can go negative here by design.
                $row['total']  = $realTotal;
                $row['excess'] = $row['total'] - $row['catered'];
            }
            // else: hour > cutoff — normal per-hour row, unchanged.

            if ($row['total'] === 0) continue;
            $rows[] = ['label' => $slot['label'], 'row' => $row];
        }
        return $rows;
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
        $productRowsWithProduct = $products
            ->map(fn($product) => ['product' => $product, 'row' => ProductPerformance::buildRow($product, $orders, $products)])
            ->reject(fn($item) => $item['product']->is_hidden && $item['row']['total'] === 0);

        $productRows = $productRowsWithProduct->pluck('row')->values();

        // Grand Total — the sum of the product rows above, full stop. Explicit
        // request (2026-08-21) — see index()'s matching comment for the full
        // reasoning/history (a distinct-order tally() to match Dashboard/TSA
        // Performance, then an "Other/Unmatched Product" row, both reverted
        // in favor of this simpler definition). An untracked-product order is
        // simply not counted here; can disagree with Dashboard/TSA
        // Performance's own tally() whenever one exists in range — accepted.
        $grandTotal = ProductPerformance::sumRows($productRows);

        return view('leads-report-all', [
            'dateFrom'    => $dateFrom, 'dateTo' => $dateTo, 'mode' => $mode, 'rangeLabel' => $rangeLabel,
            'productRows' => $productRows, 'grandTotal' => $grandTotal,
            'teams'       => $teams, 'selectedTeam' => 'all', 'metricCols' => ProductPerformance::METRIC_COLUMNS,
        ]);
    }

    /**
     * Drill-down: given a product's Total Leads cell, returns the exact orders
     * ProductPerformance::buildRow() counted toward it — id, current LOCAL status,
     * cart item, and creation time.
     *
     * Excludes DELETED_STATUSES orders outright (explicit request, 2026-08-18)
     * — this used to deliberately still list them as a diagnostic aid (spot an
     * order Pancake deleted/cancelled whose local copy never got a later sync
     * to update it), with the popover marking those rows as excluded rather
     * than hiding them. Reversed: showing rows that visibly aren't part of the
     * Total Leads count, even clearly marked, still read as a counting bug
     * rather than a diagnostic view. Same exclusion a specific column
     * (ordersForColumn()) already applied — the plain Total cell now matches
     * that instead of being the one path that didn't.
     */
    public function drilldown(Request $request)
    {
        $teamsConfig = config('teams', []);
        $productId   = $request->query('product');
        abort_if(!$productId, 422);

        $product = Product::findOrFail($productId);

        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to', $dateFrom);
        abort_if(!$dateFrom, 422);
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();

        $column = $request->query('column');

        // Same cross-team match pool as index()/indexAll() — a combo can bundle
        // this product under a different team's primary order (see the
        // matchingOrders() docblock), so the pool can't be pre-filtered to one team.
        $orderTeams = collect($teamsConfig)->pluck('order_team')->all();
        $matchPool  = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->whereIn('team', $orderTeams)
            ->get();

        $teamProducts = Product::where('team', $product->team)->get();
        $matching     = ProductPerformance::matchingOrders($product, $matchPool, $teamProducts);

        // A disposition/count column (Called Leads, Confirmed via Call, Excess,
        // etc.) — same categorization ProductPerformance::tally() itself uses,
        // so "which orders" can never drift from what the cell's own number
        // counted. Omitted entirely = the plain product Total cell.
        if ($column) {
            $matching = ProductPerformance::ordersForColumn($matching, (string) $column);
        } else {
            // Same exclusions ordersForColumn() already applies for every
            // other column — see this method's own docblock for why the
            // Total cell now matches instead of being the one exception.
            // Canceled (6) carve-out (2026-08-24) matches
            // ProductPerformance::tally()'s own fix — a genuine upsell that
            // happened before an order was later canceled still counts, see
            // that method's own comment.
            $matching = $matching->reject(fn ($o) => $o->status_code === 7
                || ($o->status_code === 6 && !Order::isBroadRealUpsell($o))
                || $o->excluded_upsell_seller
                || $o->is_duplicated_by_logistics);
        }

        $result = $matching
            ->sortByDesc(fn($o) => $o->effective_created_at)
            ->values()
            ->map(fn($o) => [
                'id'         => $o->pancake_order_id,
                'status'     => $o->status_label ?? "Unknown ({$o->status_code})",
                'product'    => $o->product,
                'time'       => optional($o->effective_created_at)->format('M j, g:i A'),
                // Diagnostic only, same reasoning as this method's own docblock:
                // shows WHICH signal (ID/cart item/base item/bundle/tag) actually
                // matched this order to $product, so a false positive is visible
                // right in the popover instead of needing a manual Pancake lookup.
                'matched_via' => ProductPerformance::matchReason($product, $o),
            ]);

        return response()->json($result);
    }
}
