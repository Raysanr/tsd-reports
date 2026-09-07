<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use App\Support\Teams;
use App\Support\TeamShiftWindow;
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

        // Shift-window exclusion (explicit request, revised 2026-09-07 —
        // twice; see history below): hours outside this team's own
        // time-based window (before it starts, or after it ends) show
        // NOTHING for this team's hourly table — not even New Leads, since a
        // "New Lead" at e.g. 6am on Closing's page cannot be a Closing lead
        // at all once team is purely hour-derived. Each hour INSIDE the
        // window shows only its own real orders — a genuine 3:10pm order
        // shows only in the 3:00pm-4:00pm row, nowhere else. Only meaningful
        // for a single calendar day's hourly view: a multi-day 'dates' range
        // aggregates every day's same hour-of-day into one row, where "the
        // window hasn't started/ended yet" no longer has one answer —
        // skipped there (dateFrom !== dateTo), unchanged behavior.
        //
        // History: this used to fold every outside-window order into the
        // window's nearest edge hour instead of excluding it, reasoning that
        // a same-team-owned PRODUCT can still legitimately sell during the
        // other team's hours (ProductPerformance::matchingOrders() trusts an
        // order's own item over its hour-derived team) and those orders
        // needed somewhere to go rather than vanishing. That broke in
        // practice: "outside the window" isn't a small backlog on a real
        // day, it's the OTHER team's entire ordinary working day, so the
        // fold just relocated the same "whole day dumped into one row" bug
        // to a single hour instead of fixing it (confirmed live: 295 orders
        // across Opening's working hours all landing in Closing's own
        // 3:00pm-4:00pm row as "67 New Leads"). Simplified to straight
        // exclusion — those orders still count in this team's own Grand
        // Total day-sum (via matchingOrders()'s existing cross-team trust)
        // and still show on other views like TSA Performance; they just
        // don't appear in THIS table's hourly breakdown.
        //
        // Window bounds are TeamShiftWindow's own fixed boundary (0-14 for
        // Eyecare/Opening, 15-23 for SH Naturals/Closing) — 2026-09-07,
        // replacing an older per-TSA shift_start-based cutoff (each TSA's
        // own configured clock-in time) that no longer matched reality:
        // Order.team is purely hour-derived now, so e.g. an 8am order can
        // NEVER be SH Naturals' regardless of when its earliest-starting TSA
        // clocks in, and showing "New Leads" rows for 1am-2pm on Closing's
        // own page (as the old shift_start-based cutoff did, since SH
        // Naturals' TSAs could be configured to start well before 3pm) was
        // exactly this confusion, confirmed live — as was Opening's mirrored
        // "3:00pm-4:00pm" row from a same-team product sold during Closing's
        // hours.
        $applyShiftCutoff = $mode === 'last24h' || $dateFrom === $dateTo;
        $shiftCutoffHour  = TeamShiftWindow::startHourFor($orderTeam);

        // Mirror of the start cutoff at the other edge (2026-09-07): a
        // same-team-owned product can still legitimately be sold during the
        // OTHER team's window (ProductPerformance::matchingOrders() trusts
        // an order's own item over its hour-derived team), which used to
        // surface as an impossible post-window row — e.g. Opening's table
        // showing a "3:00pm-4:00pm" row. Folded into the window's last real
        // hour instead (2pm for Opening, 11pm for Closing), same as the
        // start-of-window backlog lump.
        $shiftEndHour = TeamShiftWindow::endHourFor($orderTeam);

        $slotHourOf = $mode === 'last24h'
            ? fn($slot) => (int) explode(' ', $slot['key'])[1]
            : fn($slot) => (int) $slot['key'];

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
        // Every product is browsable on every team's page (2026-09-07,
        // fourth revision — see this method's own shift-window comment for
        // the full history): explicit request, every product's row must
        // stay visible per team. This is now safe to combine with "Grand
        // Total always equals the row sum" because the match pool below is
        // ALREADY scoped to this team's own hour window ($matchPool/
        // $dayOrders use where('team', $orderTeam), not the old cross-team
        // whereIn) — a foreign-team product (e.g. CLEAR SIGHT on SH
        // Naturals' own page) simply has nothing in that pool to match, so
        // its row is a real, correct 0, not a hidden nonzero number. No
        // separate Grand Total scoping needed: the row sum IS Grand Total.
        $products = Product::orderBy('sort_order')->get();

        // Exactly one of these two ends up populated, matching $applyShiftCutoff
        // below — declared here so the closure that reads both further down
        // (via `use`) always has a defined variable regardless of which branch
        // ran, instead of an "undefined variable" fatal on whichever branch
        // didn't set it.
        $matchPoolTotal            = null;
        $dailyTotalRowsByProductId = null;

        // Scoped to THIS team's own orders only (2026-09-07, both branches
        // below) — since Order.team is now purely derived from
        // pancake_created_at's hour (TeamShiftWindow), "this team's own
        // orders" and "orders created inside this team's own hour window"
        // are the exact same set, so this single filter enforces both at
        // once: a product's Total Leads/Grand Total on this page now only
        // counts leads actually created in this team's window, matching the
        // hourly table exactly (explicit request — a 9am GINSENG SERUM
        // sale, though a real SH Naturals product, should no longer count
        // on Closing's page at all, only on Opening's, since 9am is
        // Opening's hour). This DROPS the older cross-team combo pool
        // (whereIn both teams) that used to let e.g. a noon Pterygium
        // order's bundled Sinuxyl half count toward SH Naturals' SINUXYL
        // row too — accepted trade-off of this same decision, confirmed
        // explicitly: team-hour is now the only rule, no bundle exception
        // survives it on this page.
        if ($applyShiftCutoff) {
            $matchPool       = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
                ->where('team', $orderTeam)
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
                    ->where('team', $orderTeam)
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
            $applyShiftCutoff, $shiftCutoffHour, $shiftEndHour, $slotHourOf
        ) {
            $hourlyRows = $applyShiftCutoff
                ? $this->buildHourlyRows(
                    $slots, $matchPoolBySlot,
                    fn(Collection $orders) => ProductPerformance::buildRow($product, $orders, $products),
                    $applyShiftCutoff, $shiftCutoffHour, $shiftEndHour, $slotHourOf
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
        //
        // Every product's row is visible (browsable), and Grand Total is
        // simply their sum — explicit request (2026-09-07, fifth revision —
        // see this method's own shift-window comment for the full history):
        // "when the per row is added it is still not accurate... it should
        // be [the full row sum]". A genuine cross-team combo order (e.g. an
        // Eyecare-hour order bundling Pterygium + Sinuxyl) can count TWICE
        // here — once under PTERYGIUM (its own item) and again under
        // SINUXYL (the bundled item, via ProductPerformance's own explicit
        // cross-team match) — confirmed accepted: same "a combo legitimately
        // counts toward more than one row" trade-off Dashboard/TSA
        // Performance's own distinct-order tallies already live with
        // elsewhere, not treated as double-counting here since each row's
        // own number is independently correct for that product.
        $grandTotal = ProductPerformance::sumRows($productTables->pluck('total'));

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
        //
        $grandTotalHourlyRows = $applyShiftCutoff
            ? $this->buildHourlyRows(
                $slots, $matchPoolBySlot,
                fn (Collection $orders) => ProductPerformance::sumRows(
                    $products->map(fn ($product) => ProductPerformance::buildRow($product, $orders, $products))
                ),
                $applyShiftCutoff, $shiftCutoffHour, $shiftEndHour, $slotHourOf
            )
            : collect($slots)->map(function ($slot) use ($matchPoolBySlot, $products) {
                $rows = $products->map(fn ($product) => $matchPoolBySlot[$slot['key']]->firstWhere('product_id', $product->id))->filter();
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
     *  false, or window-bounded (blank outside [$shiftCutoffHour, $shiftEndHour])
     *  otherwise. See the shift-window comment in index() for the full reasoning. */
    private function buildHourlyRows(
        array $slots, Collection $ordersBySlot, \Closure $computeRow,
        bool $applyShiftCutoff, int $shiftCutoffHour, int $shiftEndHour,
        \Closure $slotHourOf
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

        // $shiftCutoffHour/$shiftEndHour are fixed constants (0/14 for
        // Eyecare/Opening, 15/23 for SH Naturals/Closing — see
        // TeamShiftWindow::startHourFor()/endHourFor()), not a per-date/
        // per-TSA lookup: Opening's start-cutoff of 0 means every early hour
        // is inside the window, and Closing's start-cutoff of 15 excludes
        // every pre-3pm hour on every date uniformly.
        $rows = [];
        foreach ($slots as $slot) {
            $hourOrders = $ordersBySlot->get($slot['key'], collect());
            $hour       = $slotHourOf($slot);

            // Outside this team's own window (before it starts, or after it
            // ends) that day: this row doesn't belong to this team's table
            // at all under the hour-based team rule (2026-09-07) — fully
            // blanked, INCLUDING New Leads. A same-team-owned PRODUCT can
            // still legitimately sell during the other team's hours
            // (ProductPerformance::matchingOrders() trusts an order's own
            // item over its hour-derived team), but that's real, ordinary
            // per-hour activity for the OTHER team's own window, not a
            // pre-shift backlog waiting to be worked — lumping every such
            // hour into one edge row (an earlier version of this fix) just
            // relocated the same "whole day showing at one hour" bug instead
            // of fixing it (confirmed live: 295 orders across Opening's
            // working hours all landing in Closing's 3pm row as "67 New
            // Leads"). Each hour inside the window shows only its own real
            // orders; hours outside it show nothing, full stop — a genuine
            // 3:10pm order shows only in the 3:00pm-4:00pm row, nowhere else.
            if ($hour < $shiftCutoffHour || $hour > $shiftEndHour) {
                continue;
            }

            $row = $computeRow($hourOrders);
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

        // Per-team breakdown built FIRST (2026-09-07, sixth revision — see
        // index()'s own shift-window comment for the full history): each
        // team's own section here must match its own dedicated page
        // (index()) exactly, which shows EVERY product (browsable cross-team
        // sales) matched against ONLY that team's own hour-scoped pool
        // (where('team', $orderTeam)) — e.g. PTERYGIUM (an Eyecare product)
        // can show a real, nonzero row under Team Closing if a Pterygium
        // unit was genuinely sold during Closing's own hours, separately
        // from PTERYGIUM's own (different) total under Team Opening.
        // Fetched and matched one calendar day at a time, not the whole
        // range at once — same memory-crash fix already applied to
        // Dashboard's own wide-range Grand Total (2026-08-28), for the
        // identical reason (see this method's git history for the original
        // comment on that root cause).
        $teamTables = collect($teamsConfig)
            ->map(function ($t, $teamSlug) use ($products, $from, $to) {
                $rowsByProductId = $products->mapWithKeys(fn ($p) => [$p->id => collect()]);
                for ($cursor = $from->copy()->startOfDay(); $cursor->lte($to); $cursor->addDay()) {
                    $dayOrders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$cursor->copy()->startOfDay(), $cursor->copy()->endOfDay()])
                        ->where('team', $t['order_team'])
                        ->get();
                    foreach ($products as $product) {
                        $rowsByProductId[$product->id]->push(ProductPerformance::buildRow($product, $dayOrders, $products));
                    }
                }

                $rows = $products
                    ->map(fn ($product) => [
                        'product' => $product,
                        'row'     => array_merge(
                            ProductPerformance::sumRows($rowsByProductId[$product->id]),
                            ['product_id' => $product->id, 'display_name' => $product->display_name, 'team' => $product->team]
                        ),
                    ])
                    ->reject(fn ($item) => $item['product']->is_hidden && $item['row']['total'] === 0)
                    ->pluck('row')
                    ->values();

                return [
                    // Dated (explicit follow-up request, 2026-09-04: "backtrack
                    // the data like yesterday it is sh naturals and eyecare") —
                    // this whole page's rows are scoped to $from/$to above, so
                    // each team section's own label must match what it was
                    // actually called across that range, not today's name.
                    'label'    => Teams::nameForOrderTeamRange($t['order_team'], $from, $to),
                    'rows'     => $rows,
                    // The $teamsConfig key (e.g. 'sh-naturals'), not order_team
                    // — this is what drilldown()'s own `team` query param
                    // expects (2026-09-07), so a click on this section's row
                    // matches its own hour-scoped pool, not the cross-team one.
                    'teamSlug' => $teamSlug,
                ];
            })
            ->filter(fn ($t) => $t['rows']->isNotEmpty() && $t['rows']->sum('total') > 0)
            ->map(fn ($t) => $t + ['grandTotal' => ProductPerformance::sumRows($t['rows'])])
            ->values();

        // Combined table above the per-team sections — one row per product,
        // its total being the SUM of that same product's row across both
        // team sections above (2026-09-07, sixth revision): a product can
        // appear in both sections with two different numbers (its own real
        // sales, plus any cross-hour sales counted on the other team's own
        // page), so the combined view's own number for that product is
        // their sum — not a separate, independently-matched total, which
        // would disagree with $teamTables (confirmed live: this used to be
        // computed as "each product matches only its own team's pool,"
        // silently dropping the cross-hour half $teamTables now shows).
        // Keyed by product_id, not display_name — two different Product
        // rows could theoretically share a display_name, product_id never
        // collides.
        $rowsByProductId = collect();
        foreach ($teamTables as $teamTable) {
            foreach ($teamTable['rows'] as $row) {
                $rowsByProductId[$row['product_id']] = ($rowsByProductId[$row['product_id']] ?? collect())->push($row);
            }
        }

        $productRowsWithProduct = $products
            ->map(fn ($product) => [
                'product' => $product,
                'row' => array_merge(
                    ProductPerformance::sumRows($rowsByProductId->get($product->id, collect())),
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
        // Equals the sum of both $teamTables' own Grand Totals by
        // construction (SH Naturals + Eyecare == ALL, an enforced invariant —
        // see LeadsReportGrandTotalTest), since $productRows is itself built
        // from exactly those two sections' own rows.
        $grandTotal = ProductPerformance::sumRows($productRows);

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

        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to', $dateFrom);
        abort_if(!$dateFrom, 422);
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();

        $column = $request->query('column');

        // Scoped to the requesting page's own team only (2026-09-07 — same
        // decision as index()/indexAll()/Dashboard, see LeadsReportController's
        // shift-window comment for the full history): a per-team page's cell
        // counts are now team-scoped (Order.team is purely hour-derived, so
        // "this team's own orders" and "orders created inside this team's own
        // hour window" are the same set), so this drilldown must match that
        // exact pool or it lists MORE orders than the cell it's explaining
        // ever counted (confirmed live: a "5" cell opening a list of orders
        // spanning far more than 5, since this used to ignore team entirely).
        // The ALL view's own team param is 'all' (or omitted/unrecognized),
        // which keeps the original cross-team pool — correct there, since
        // indexAll() itself matches every team's orders combined.
        $requestedTeam = $request->query('team');
        $orderTeam     = $teamsConfig[$requestedTeam]['order_team'] ?? null;

        $matchPoolQuery = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to]);
        if ($orderTeam) {
            $matchPoolQuery->where('team', $orderTeam);
        } else {
            $matchPoolQuery->whereIn('team', collect($teamsConfig)->pluck('order_team')->all());
        }
        $matchPool = $matchPoolQuery->get();

        // No `product` param = the Grand Total row's own cell (2026-09-07,
        // fifth revision — see index()'s own shift-window comment for the
        // full history): Grand Total is the PLAIN sum of every visible
        // product's row (not deduped by distinct order), so a genuine
        // cross-team combo order legitimately counts twice toward it — once
        // per matching product. To keep "which orders" from drifting off
        // what the cell's own number counted, this lists that same order
        // once per product it matched too, rather than deduping it down to
        // one entry. Uses every VISIBLE product (every product, not just
        // this team's own — 2026-09-07, "every product is browsable on
        // every team's page"), matching $products in index()/indexAll()
        // exactly. Built as one (order, matchedProduct) pair per row up
        // front — rather than reusing $matchingOrders()'s shared Eloquent
        // instances across products and tagging them with a mutable
        // attribute — since the SAME order object can be the result of
        // matching two different products here, and a later product's tag
        // would silently overwrite an earlier one on that same instance.
        if ($productId) {
            $product      = Product::findOrFail($productId);
            $teamProducts = Product::where('team', $product->team)->get();
            $pairs        = ProductPerformance::matchingOrders($product, $matchPool, $teamProducts)
                ->map(fn ($o) => ['order' => $o, 'product' => $product]);
        } else {
            $allProducts = Product::all();
            $pairs       = collect();
            foreach ($allProducts as $p) {
                foreach (ProductPerformance::matchingOrders($p, $matchPool, $allProducts) as $o) {
                    $pairs->push(['order' => $o, 'product' => $p]);
                }
            }
        }

        // A disposition/count column (Called Leads, Confirmed via Call, Excess,
        // etc.) — same categorization ProductPerformance::tally() itself uses,
        // so "which orders" can never drift from what the cell's own number
        // counted. Omitted entirely = the plain product Total cell.
        if ($column) {
            $pairs = $pairs->filter(function ($pair) use ($column) {
                return ProductPerformance::ordersForColumn(collect([$pair['order']]), (string) $column)->isNotEmpty();
            });
        } else {
            // Same exclusions ordersForColumn() already applies for every
            // other column — see this method's own docblock for why the
            // Total cell now matches instead of being the one exception.
            // Canceled (6) carve-out (2026-08-24) matches
            // ProductPerformance::tally()'s own fix — a genuine upsell that
            // happened before an order was later canceled still counts, see
            // that method's own comment.
            $pairs = $pairs->reject(fn ($pair) => $pair['order']->status_code === 7
                || ($pair['order']->status_code === 6 && !Order::isBroadRealUpsell($pair['order']))
                || $pair['order']->excluded_upsell_seller
                || $pair['order']->is_duplicated_by_logistics);
        }

        $result = $pairs
            ->sortByDesc(fn ($pair) => $pair['order']->effective_created_at)
            ->values()
            ->map(fn ($pair) => [
                'id'         => $pair['order']->pancake_order_id,
                'status'     => $pair['order']->status_label ?? "Unknown ({$pair['order']->status_code})",
                'product'    => $pair['order']->product,
                'time'       => optional($pair['order']->effective_created_at)->format('M j, g:i A'),
                // Diagnostic only, same reasoning as this method's own docblock:
                // shows WHICH signal (ID/cart item/base item/bundle/tag) actually
                // matched this order to $pair['product'], so a false positive is
                // visible right in the popover instead of needing a manual
                // Pancake lookup.
                'matched_via' => ProductPerformance::matchReason($pair['product'], $pair['order']),
            ]);

        return response()->json($result);
    }
}
