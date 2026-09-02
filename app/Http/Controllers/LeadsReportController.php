<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use App\Support\Teams;
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
        $teamsConfig  = Teams::config();
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
        // Product-matching pool, cross-team (not scoped to $orderTeam): a combo SKU
        // can bundle products from TWO different teams under one order (e.g. a
        // Pterygium order — Eyecare's own team — bundling 10 Sinuxyl units, an SH
        // Naturals product), but an order only ever carries the ONE team its
        // primary item belongs to. Team-scoping this pool would make that whole
        // cross-team half of the bundle invisible to SH Naturals' own SINUXYL row —
        // confirmed in production (order 1333736: 89 in POS vs 88 here).
        // ProductPerformance::buildRow() itself already trusts an explicit product/
        // bundle_description text match across team lines; it just needs a pool that
        // isn't pre-filtered down to one team to find it in. Grand Total below is
        // built from the SAME pool (a straight sum of these product rows).
        // Fetched one calendar day at a time, not the whole range in one
        // Collection — same memory-crash fix already applied to Dashboard's
        // own wide-range Grand Total (2026-08-28) and this page's ALL view
        // (indexAll(), above), for the identical reason: reproduced live,
        // fetching the whole range at once alone used 126.5MB of a 128MB
        // limit on a 31-day range, and the per-product matching pass on top
        // of it (buildRow() called per product per hour slot, PLUS once more
        // per product for the whole-range total) pushed peak usage to
        // 138.5MB — over the edge, which is why this per-team view 500'd on
        // "Last month" while the ALL view (no hourly breakdown, so ~10MB
        // lighter) happened to still survive at 128.5MB, itself right at the
        // same edge (see indexAll()'s own comment).
        //
        // $applyShiftCutoff is false for every multi-day range (the crash
        // scenario — see its own definition above: only true for last24h or
        // a single selected day), which means buildHourlyRows()'s ONLY
        // active branch here is the simple per-slot one — it never reads
        // back multiple hours' worth of raw orders for the shift-cutoff
        // backlog merge, it just tally()s whatever's in one slot and moves
        // on. That means each day's contribution to each hour-of-day slot
        // can be computed (via buildRow(), already correctly matched/
        // exclusion-applied) and SUMMED across days immediately, without
        // ever holding more than one day's raw Order models in memory at
        // once — unlike keeping every raw order in a merged $matchPoolBySlot
        // Collection, which (confirmed live) still ends up holding the
        // exact same ~16k total order count as a single whole-range fetch,
        // just accumulated incrementally instead of in one query — no
        // actual memory saved. A single-day range (last24h or one calendar
        // day) still fetches its one day's orders directly below since the
        // cutoff logic genuinely needs real orders then, and one day's
        // worth was never the memory risk to begin with.
        $orderTeams = collect($teamsConfig)->pluck('order_team')->all();
        $products   = Product::where('team', $orderTeam)->orderBy('sort_order')->get();

        // Exactly one of these two ends up populated, matching $applyShiftCutoff
        // below — declared here so the closure that reads both further down
        // (via `use`) always has a defined variable regardless of which branch
        // ran, instead of an "undefined variable" fatal on whichever branch
        // didn't set it.
        $matchPoolTotal            = null;
        $dailyTotalRowsByProductId = null;

        if ($applyShiftCutoff) {
            $matchPool       = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
                ->whereIn('team', $orderTeams)
                ->get();
            $matchPoolBySlot = $matchPool->groupBy($slotKeyOf);
            $matchPoolTotal  = $matchPool;
        } else {
            // Per product, per hour-of-day slot: a list of that DAY's own row,
            // one per day in range — summed into one row per (product, slot)
            // once every day's been walked. Keyed by product id, never
            // positional index (same reasoning as indexAll()'s own fix above).
            // Plain nested PHP arrays here, not Collections — indexing two
            // levels deep into a Collection via [$key][$key2][] triggers
            // PHP's "indirect modification of overloaded element has no
            // effect" notice (Collection's ArrayAccess doesn't guarantee a
            // nested offsetGet() result is a live reference back into its
            // own storage); plain arrays don't have that hazard.
            $dailySlotRows  = []; // [productId][slotKey] => [row, row, ...]
            $dailyTotalRows = []; // [productId] => [row, row, ...]

            for ($cursor = $from->copy()->startOfDay(); $cursor->lte($to); $cursor->addDay()) {
                $dayOrders    = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$cursor->copy()->startOfDay(), $cursor->copy()->endOfDay()])
                    ->whereIn('team', $orderTeams)
                    ->get();
                $dayOrdersBySlot = $dayOrders->groupBy($slotKeyOf);

                foreach ($products as $product) {
                    foreach ($slots as $slot) {
                        $slotOrders = $dayOrdersBySlot->get($slot['key'], collect());
                        if ($slotOrders->isEmpty()) continue;
                        $dailySlotRows[$product->id][$slot['key']][] = ProductPerformance::buildRow($product, $slotOrders, $products);
                    }
                    $dailyTotalRows[$product->id][] = ProductPerformance::buildRow($product, $dayOrders, $products);
                }
            }

            // sumRows() only sums the fixed additive $keys list it knows about
            // — it drops product_id/display_name/team, which buildRow()
            // normally adds after tally(). Re-attached here since every day's
            // already-built row already carried the same values (they don't
            // vary per day/slot, only per product) — product_id specifically
            // is required by the firstWhere('product_id', ...) lookups below
            // and in the Grand Total hourly section further down; without it
            // every slot lookup would silently return null (same class of
            // bug indexAll()'s own fix caught via its own test failure).
            $matchPoolBySlot = collect($slots)->mapWithKeys(fn ($slot) => [$slot['key'] => collect()]);
            foreach ($products as $product) {
                foreach ($dailySlotRows[$product->id] ?? [] as $slotKey => $dayRows) {
                    $summed = array_merge(
                        ProductPerformance::sumRows(collect($dayRows)),
                        ['product_id' => $product->id, 'display_name' => $product->display_name, 'team' => $product->team]
                    );
                    $matchPoolBySlot[$slotKey] = $matchPoolBySlot[$slotKey]->push($summed);
                }
            }

            $dailyTotalRowsByProductId = $products->mapWithKeys(
                fn ($p) => [$p->id => collect($dailyTotalRows[$p->id] ?? [])]
            );
        }

        $productTables = $products->map(function ($product) use (
            $slots, $matchPoolBySlot, $matchPoolTotal, $dailyTotalRowsByProductId, $products,
            $applyShiftCutoff, $teamShifts, $slotHourOf, $slotDateOf, $slotKeyForHour
        ) {
            $hourlyRows = $applyShiftCutoff
                ? $this->buildHourlyRows(
                    $slots, $matchPoolBySlot,
                    fn(Collection $orders) => ProductPerformance::buildRow($product, $orders, $products),
                    $applyShiftCutoff, $teamShifts, $slotHourOf, $slotDateOf, $slotKeyForHour
                )
                : collect($slots)->map(function ($slot) use ($matchPoolBySlot, $product) {
                    $row = $matchPoolBySlot[$slot['key']]->firstWhere('product_id', $product->id);
                    return ($row && $row['total'] !== 0) ? ['label' => $slot['label'], 'row' => $row] : null;
                })->filter()->values()->all();

            return [
                'product'    => $product,
                'hourlyRows' => $hourlyRows,
                'total'      => $applyShiftCutoff
                    ? ProductPerformance::buildRow($product, $matchPoolTotal, $products)
                    : array_merge(
                        ProductPerformance::sumRows($dailyTotalRowsByProductId[$product->id]),
                        ['product_id' => $product->id, 'display_name' => $product->display_name, 'team' => $product->team]
                    ),
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
        $grandTotal       = ProductPerformance::sumRows($productTables->pluck('total'));

        // Same per-hour breakdown as each product table above, but summing
        // that hour's per-product rows (same reasoning as the all-range
        // $grandTotal just above) instead of tally()-ing the hour's raw
        // orders directly. Sums over $matchPool (cross-team pool), matching
        // what the per-product hourly rows themselves are built from.
        //
        // Non-cutoff branch (wide/multi-day range): $matchPoolBySlot already
        // holds one pre-summed row PER PRODUCT per slot (see the day-bucketed
        // build above) rather than raw orders, so this sums those rows
        // directly instead of re-deriving from a Collection of orders that no
        // longer exists in that shape — buildHourlyRows() itself still
        // expects raw orders, so it's only used on the cutoff branch, which
        // is always a single day and never the memory risk.
        $grandTotalHourlyRows = $applyShiftCutoff
            ? $this->buildHourlyRows(
                $slots, $matchPoolBySlot,
                fn (Collection $orders) => ProductPerformance::sumRows(
                    $visibleProducts->map(fn ($product) => ProductPerformance::buildRow($product, $orders, $products))
                ),
                $applyShiftCutoff, $teamShifts, $slotHourOf, $slotDateOf, $slotKeyForHour
            )
            : collect($slots)->map(function ($slot) use ($matchPoolBySlot, $visibleProducts) {
                $rows = $visibleProducts->map(fn ($product) => $matchPoolBySlot[$slot['key']]->firstWhere('product_id', $product->id))->filter();
                if ($rows->isEmpty()) return null;
                $row = ProductPerformance::sumRows($rows);
                return $row['total'] !== 0 ? ['label' => $slot['label'], 'row' => $row] : null;
            })->filter()->values()->all();

        $metricCols = ProductPerformance::METRIC_COLUMNS;

        return view('leads-report', compact(
            'dateFrom', 'dateTo', 'selectedTeam', 'teams', 'mode', 'rangeLabel',
            'productTables', 'metricCols', 'grandTotal', 'grandTotalHourlyRows'
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

        // orderBy('team') alone would sort alphabetically ("Eyecare Team" < "SH
        // Naturals"), putting Eyecare first — wrong. Sort by each product's team's
        // position in $orderTeams (config order) instead, keeping sort_order as the
        // tie-breaker within a team (sortBy() is a stable sort, so pre-sorting by
        // sort_order first preserves that order within each team group).
        $products = Product::orderBy('sort_order')->get()
            ->sortBy(fn($p) => array_search($p->team, $orderTeams))
            ->values();

        // Fetched and matched one calendar day at a time, not the whole range at
        // once — same memory-crash fix already applied to Dashboard's own wide-
        // range Grand Total (2026-08-28) and this exact page's per-team view
        // (below), for the identical reason: reproduced live, this single
        // unbucketed fetch alone used 126.5MB of a 128MB limit on a 31-day ALL-
        // teams range, with the per-product matching pass on top of it pushing
        // peak usage to 128.5MB — right at the edge, one busier day or a
        // slightly wider range away from the same fatal-error 500 the per-team
        // view was already hitting. Mathematically identical result to a single
        // whole-range fetch: sumRows() defines Grand Total as literally "sum of
        // the rows," and summing each day's per-product sums equals summing
        // everything at once (addition is associative) — see the per-team
        // view's own comment on this same property. See the effective
        // (creation-date-first) column reasoning in the per-team branch above.
        // Keyed by each product's own id throughout, never positional index —
        // safer against $products/$dailyProductRows ever drifting out of sync
        // in count or order.
        $rowsByProductId = $products->mapWithKeys(fn ($p) => [$p->id => collect()]);
        for ($cursor = $from->copy()->startOfDay(); $cursor->lte($to); $cursor->addDay()) {
            $dayOrders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$cursor->copy()->startOfDay(), $cursor->copy()->endOfDay()])
                ->whereIn('team', $orderTeams)
                ->get();
            foreach ($products as $product) {
                $rowsByProductId[$product->id]->push(ProductPerformance::buildRow($product, $dayOrders, $products));
            }
        }

        // Same hidden-product rule as the per-team view above: dropped only when
        // there's genuinely nothing to show for the selected range — summed
        // across every day first so a product with leads on SOME days isn't
        // dropped just because any single day had none.
        //
        // sumRows() only sums the fixed additive $keys list it knows about —
        // it drops product_id/display_name/team, which buildRow() normally
        // adds after tally() (see buildRow()'s own lines). Re-attached here
        // since every day's already-built row already carried the same
        // values (they don't vary per day, only per product) — real bug
        // caught by the test suite: the view crashed with "Undefined array
        // key display_name" without this.
        $productRowsWithProduct = $products
            ->map(fn ($product) => [
                'product' => $product,
                'row' => array_merge(
                    ProductPerformance::sumRows($rowsByProductId[$product->id]),
                    ['product_id' => $product->id, 'display_name' => $product->display_name, 'team' => $product->team]
                ),
            ])
            ->reject(fn ($item) => $item['product']->is_hidden && $item['row']['total'] === 0);

        $products    = $productRowsWithProduct->pluck('product')->values();
        $productRows = $productRowsWithProduct->pluck('row')->values();

        // Grand Total — the sum of the product rows above, full stop. Explicit
        // request (2026-08-21) — see index()'s matching comment for the full
        // reasoning/history (a distinct-order tally() to match Dashboard/TSA
        // Performance, then an "Other/Unmatched Product" row, both reverted
        // in favor of this simpler definition). An untracked-product order is
        // simply not counted here; can disagree with Dashboard/TSA
        // Performance's own tally() whenever one exists in range — accepted.
        $grandTotal = ProductPerformance::sumRows($productRows);

        // Per-team breakdown below the combined table above — same $productRows
        // already computed, just grouped by each row's own 'team' (buildRow()
        // sets this from Product::team, e.g. "Eyecare Team" — the raw order_team
        // string, NOT $teams' short display label "Eyecare"), so this is a free
        // regrouping of data that exists already rather than a second query/
        // tally pass. $teamsConfig carries both, keyed the same as $teamsConfig
        // itself; ordered to match config order, skipping a team with nothing
        // to show for this range.
        $teamTables = collect($teamsConfig)
            ->map(fn ($t) => [
                'label' => $t['name'],
                'rows'  => $productRows->where('team', $t['order_team'])->values(),
            ])
            ->filter(fn ($t) => $t['rows']->isNotEmpty())
            ->map(fn ($t) => $t + ['grandTotal' => ProductPerformance::sumRows($t['rows'])])
            ->values();

        return view('leads-report-all', [
            'dateFrom'    => $dateFrom, 'dateTo' => $dateTo, 'mode' => $mode, 'rangeLabel' => $rangeLabel,
            'productRows' => $productRows, 'grandTotal' => $grandTotal, 'teamTables' => $teamTables,
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
        $teamsConfig = Teams::config();
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
