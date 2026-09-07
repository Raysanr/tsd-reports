<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\TsaShift;
use App\Support\ActivityLogger;
use App\Support\ProductPerformance;
use App\Support\SyncHealth;
use App\Support\Teams;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        // Same ALL/SH Naturals/Eyecare filter as Leads Report and TSA Performance
        // (LeadsReportController::index()) — 'all' isn't a real config('teams') key,
        // handled as its own branch below, same convention as those two pages.
        $selectedTeam = $request->input('team', session('filters.dashboard.team', 'all'));
        $teamsConfig  = Teams::config();
        $teams        = ['all' => 'ALL'] + array_map(fn($t) => $t['name'], $teamsConfig);
        if ($selectedTeam !== 'all' && !array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'all';
        }
        session(['filters.dashboard.team' => $selectedTeam]);

        // Toggle: Total Cross-Sell Sales excludes Restocking orders by default (they're
        // pending, not yet shipped/paid — see the "Total Cancelled Orders" card's own
        // comment below for why that exclusion happens automatically at sync time, not
        // via a subtraction here). ON adds Restocking's revenue/count into Total
        // Cross-Sell Sales instead of leaving it out — Total Restocking itself always
        // keeps showing its own real number either way, never zeroed out by this.
        $includeRestocking = filter_var(
            $request->input('include_restocking', session('filters.dashboard.include_restocking', false)),
            FILTER_VALIDATE_BOOLEAN
        );
        session(['filters.dashboard.include_restocking' => $includeRestocking]);

        $dateFrom = Carbon::parse($fromInput)->startOfDay();
        $dateTo   = Carbon::parse($toInput)->endOfDay();

        $apiConnected          = !empty(Setting::get('pancake_api_key', config('services.pancake.api_key')));
        $dbError               = null;
        $hasSyncedData         = false;
        $reconciliationIssues  = json_decode(Setting::get('reconciliation_issues', '[]'), true) ?: [];

        $stats          = ['total_sales' => 0, 'total_orders' => 0, 'restocking_count' => 0, 'restocking_value' => 0, 'cancelled_orders_count' => 0, 'cancelled_orders_value' => 0, 'last_synced' => null, 'sync_interval' => 2, 'sync_stale' => true, 'total_leads' => 0, 'catered_leads' => 0, 'pick_up_rate' => null, 'upselling_rate' => null, 'aov' => 0];
        $recentOrders   = collect();
        $syncRuns       = collect();
        $tsaLeaderboard   = collect();
        $topProducts      = collect();
        $hourlyActivity   = collect();
        $hourlyLeads      = collect();
        $teamComparison   = collect();
        $restockingByTsa  = collect();
        $restockingByTeam = collect();
        $topTsa           = null;

        // Scope every Dashboard query to only the teams/products actually configured
        // in Product Management — without this, "Total Leads" and every other KPI
        // silently included Unmatched Orders (team NULL: products like NutriLay/
        // VitaMax that were never set up as a Product), while Leads Report/TSA
        // Performance have always excluded them. Confirmed in production: Dashboard
        // showed 301 leads, Leads Report's Grand Total showed 292 for the identical
        // day — the 9-order gap was entirely Unmatched Orders, invisible anywhere
        // else in the app. Every query below now matches Leads Report's own scope.
        //
        // Narrowed further to just the ONE selected team's order_team when the ALL/
        // SH Naturals/Eyecare filter above picks a specific team — every widget below
        // reads $orderTeams already, so this one line is what makes the whole page
        // (KPIs, Recent Orders, Hourly Activity, TSA Leaderboard, Top Products,
        // Restocking by TSA) follow the filter with no separate per-widget change.
        $orderTeams = $selectedTeam === 'all'
            ? collect($teamsConfig)->pluck('order_team')->all()
            : [$teamsConfig[$selectedTeam]['order_team']];

        try {
            $hasSyncedData = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)->exists();

            // Show every order attributed to a known TSA — not just is_upsell=true —
            // so orders excluded from gross sales (e.g. status "Restocking") are still
            // visible here with their status label, instead of silently disappearing.
            //
            // 25, not 10: this card is height-matched to Hourly Activity's chart
            // (items-stretch on the parent grid) — 10 rows left a big empty gap
            // below the table on any date with real volume, since the card's
            // scrollable area (flex-1) was taller than 10 rows' worth of content.
            // 25 comfortably fills that space on a normal day; the table's own
            // overflow-y-auto still scrolls internally on a busier one instead of
            // growing the card past its sibling's height.
            $recentOrders = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)
                ->whereNotNull('tsa_name')
                ->orderByDesc('pancake_created_at')
                ->limit(25)
                ->get();

            // TSA upsells currently sitting in "Restocking" (awaiting stock) — excluded
            // from gross sales above, surfaced here so it's clear how much upsell
            // revenue is pending rather than lost. is_restocking_upsell-scoped, NOT
            // is_upsell (explicit request originally asked for is_upsell, but that's
            // structurally impossible to combine with status_code=11 — SyncTodayOrders
            // forces is_upsell false for every VOID_STATUSES entry, which includes
            // Restocking itself, so `is_upsell=true AND status_code=11` can never match
            // any row; confirmed live: 0 of 3553 real Restocking orders ever had
            // is_upsell=true, even though 271 of them genuinely carry an upsell tag.
            // is_restocking_upsell is captured separately at sync time for exactly
            // this reason — same pattern as is_returned_upsell for Returned orders).
            // restocking_upsell_amount already holds just the isolated add-on price
            // for these rows (see SyncTodayOrders' extractUpsellAmount()), not the
            // order's full total.
            // Same COALESCE date basis the KPI cards use throughout this method, for
            // the same reason — Restocking's own KPI card must agree with which day
            // the Leaderboard credits it to. whereNotNull('tsa_name') for the same
            // 2026-08-21 root cause described elsewhere in this file — the
            // Leaderboard folds restocking rows into a TSA's own bucket, so an
            // unattributed restocking row has nowhere to land there either.
            //
            // Total Cross-Sell Sales itself no longer computed here (root-caused
            // 2026-09-07: "it should be only the total upsells of tsa in the tsa
            // leaderboard will be only display to the Total Cross-Sell Sales in any
            // dates" — this card used to run its OWN realUpsell()-scoped query,
            // independently of the Leaderboard below, and the two silently used
            // different upsell definitions (this used Order::isRealUpsell()'s narrow
            // flags-only scope; the Leaderboard uses ProductPerformance::tally()'s
            // Order::isBroadRealUpsell(), which additionally recovers orders via a
            // raw-tag fallback) — so the two could disagree on a real production day.
            // Fixed by deriving $totalOrders/$grossSales as a straight sum over
            // $tsaLeaderboard's own rows further down instead, the same "call the one
            // shared implementation, don't hand-roll a second copy" fix already
            // applied to this exact leaderboard once before (see $tsaTallyByKey's own
            // comment) — not "should match," but structurally cannot disagree, since
            // there's only one number being computed now.
            $restocking = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)
                ->whereNotNull('tsa_name')
                ->where('is_restocking_upsell', true);

            // Cancelled UPSELLS (explicit follow-up request, 2026-09-04: "same
            // kpi card as cancelled upsells" — a TSA added an upsell add-on
            // that was LATER REMOVED from the cart, base order still ships,
            // real live example order #1362700: Sinuxyl base order, ₱800,
            // status still "Packing", tagged "UPSELL TSD - Sinuxyl Inhaler",
            // internal note literally reads "cancelled upsell"). This card
            // previously counted real order-level cancellations (Pancake
            // status_code 6) instead — deliberate reversal, not a bug fix:
            // that version's own history (root-caused 2026-08-28, then
            // 2026-09-02) is preserved in this file's git log for context.
            // Explicitly accepted tradeoff: a genuinely fully-cancelled order
            // with NO upsell ever involved (e.g. the customer just canceled
            // outright) no longer shows on this card at all — only the
            // upsell-added-then-removed scenario does, tracked via the same
            // is_cancelled_upsell flag/cancelled_upsell_amount column
            // reconcileStaleUpsellTags() already maintains (see
            // ReconcileOrderStatuses.php), rather than the order's own
            // current status. Scoped by $orderTeams same as every other card.
            //
            // cancelled_upsell_amount can itself be null — confirmed live,
            // orders #1362700/#1362151/#1362682/#1362185/#1362532: the
            // add-on was added and removed before any sync ever saw it as a
            // LIVE upsell (SyncTodayOrders' own carry-forward logic only
            // captures the real price at that exact transition — see its own
            // comment), and Pancake's current API response no longer has the
            // removed item's price anywhere once it's gone. Falls back to the
            // order's own base 'amount' in that case (explicit, accepted
            // approximation, 2026-09-04) — not the true lost upsell revenue,
            // just a non-zero stand-in so these orders don't silently
            // contribute ₱0 to the total.
            $cancelledOrdersAll = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)
                ->where('is_cancelled_upsell', true)
                ->get();

            // Extracted into App\Support\SyncHealth so this page and the dedicated
            // Sync Health page (SyncHealthController) can never drift out of sync
            // on what counts as "stale".
            $syncHealth = SyncHealth::status();

            $stats = [
                'restocking_count' => (clone $restocking)->count(),
                'restocking_value' => (clone $restocking)->sum('restocking_upsell_amount'),
                'cancelled_orders_count' => $cancelledOrdersAll->count(),
                'cancelled_orders_value' => $cancelledOrdersAll->sum(
                    fn (Order $o) => $o->cancelled_upsell_amount ?? $o->amount
                ),
                'last_synced'      => $syncHealth['last_synced'],
                'sync_interval'    => $syncHealth['sync_interval'],
                'sync_stale'       => $syncHealth['sync_stale'],
            ];

            // Company-wide lead/call funnel — same counting logic as the Leads Report
            // and TSA Performance "ALL" view (ProductPerformance::tally), just run over
            // every team's orders in this range instead of one team, so Total Leads /
            // Pick-up Rate / Upselling Rate on the Dashboard can never drift from how
            // those same metrics are defined everywhere else in the app. Fetched once
            // and reused below for Hourly Activity too, same as ChartsController's
            // single $orders fetch — avoids a second identical query.
            //
            // Date scope (2026-08-11, revised): back to COALESCE(pancake_inserted_at,
            // pancake_created_at) — same expression Leads Report uses — so WHICH DAY
            // an order counts under matches POS (explicit request, "accurate from
            // POS", after Leads Report showed 256 for Eyecare/Aug 10 against this
            // card's 255). This reverses the 2026-08-08 change described below, which
            // deliberately chose the opposite; that choice fixed a real disagreement
            // between this card and TSA Performance's Grand Total, and TSA
            // Performance's own date-range filters were switched to the same POS
            // expression in this same change, so the two still agree — see
            // TsaPerformanceController::index()'s matching comment. What's still
            // untouched: $ordersByHour below (Hourly Activity's hour buckets) keeps
            // reading pancake_created_at directly — the worked-at timestamp — same
            // reasoning as TSA Performance's own hourly breakdown: a "calls per hour"
            // chart needs the hour work actually happened in, not the hour a lead
            // first arrived in Pancake.
            // Computed one calendar day at a time, not the whole range fetched into
            // one Collection — same reasoning as $dayProductRows' own per-day loop
            // below (see that comment for the full 2026-08-28 memory-crash
            // root-cause writeup). This exact query used to fetch the WHOLE range
            // at once and was the one spot that loop's fix missed: confirmed live
            // 2026-09-01, a 31-day ALL-teams range fatal-errored here specifically
            // (128MB exhausted) — 16,619 Order models alone already used 126.5MB
            // before tally() even ran. ProductPerformance::sumRows() is safe to
            // combine per-day tally()s this way for the same reason $dayProductRows
            // is: addition is associative, so summing per-day sums equals summing
            // everything at once.
            $leadTallyByDay = collect();
            for ($cursor = $dateFrom->copy()->startOfDay(); $cursor->lte($dateTo); $cursor->addDay()) {
                $dayOrders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$cursor->copy()->startOfDay(), $cursor->copy()->endOfDay()])
                    ->whereIn('team', $orderTeams)->get();
                $leadTallyByDay->push(ProductPerformance::tally($dayOrders));
            }
            $leadTally = ProductPerformance::sumRows($leadTallyByDay);

            // Total Leads/Catered Leads use "sum of the per-product rows",
            // matching Leads Report's own Grand Total (2026-08-21, explicit
            // request — this exact definition was briefly reverted to
            // $leadTally's plain distinct-order count, then reverted back
            // here: see LeadsReportController::index()'s own comment for the
            // full back-and-forth). An order for a genuinely untracked
            // product (no Product row configured yet) isn't counted in
            // either figure — the fix for that is adding the missing product
            // in Product Management. Pick-up/Upselling rate stay off the
            // distinct-order tally() — only Total/Catered Leads were asked to
            // match Leads Report.
            //
            // $allProducts scoped to the SELECTED team's own product line
            // (not literally every product) on a single-team view, and
            // $dayMatchPool below is scoped the SAME way (2026-09-07, sixth
            // revision — see LeadsReportController::index()'s own
            // shift-window comment for the full history): a single-team
            // page's match pool is bounded to that team's own hour window
            // (where('team', $orderTeam)), the same restriction Leads
            // Report's own per-team page applies — a cross-team combo order
            // (e.g. an Eyecare-hour order bundling SH Naturals' Sinuxyl)
            // simply doesn't reach either single-team total at all; it only
            // ever counts on its own true team's page/total. Matching that
            // same restriction here is what keeps this number equal to
            // Leads Report's own Grand Total on a single-team view (an
            // enforced invariant — see DashboardTotalLeadsMatchesLeadsReportTest).
            $allProducts = $selectedTeam === 'all'
                ? Product::orderBy('sort_order')->get()
                : Product::where('team', $orderTeams[0])->orderBy('sort_order')->get();

            // Grand Total computed one calendar day at a time, not the whole range's
            // orders loaded and matched in a single pass. Root-caused 2026-08-28:
            // a wide range (e.g. Eyecare, Aug 1-27) loaded every matching order
            // across the WHOLE range into memory at once — on a single-team view,
            // TWICE (this $matchPool used to also load a second, company-wide copy
            // for cross-team combo matching) — then re-scanned that entire pool once
            // per product, and again per product-order pair inside
            // ProductPerformance::conflictingProduct()'s own O(products) scan. Fine
            // for a single day; a 27-day range crashed the PHP process outright
            // (confirmed live: every request 500'd with txBytes:0 in Railway's HTTP
            // logs — the process dying before Laravel could even render an error
            // page, not a catchable exception; APP_DEBUG=true showed no difference).
            // Bucketing by day bounds how many orders are ever held/matched in
            // memory at once to roughly one day's worth, no matter how wide a range
            // gets selected — mathematically identical result, since sumRows()
            // defines the Grand Total as literally "sum of the rows", and summing
            // per-day sums equals summing everything at once (addition is
            // associative). A single-day selection (the common case) still runs
            // this loop exactly once, same as before.
            $dailyTotals = collect();
            for ($cursor = $dateFrom->copy()->startOfDay(); $cursor->lte($dateTo); $cursor->addDay()) {
                $dayStart = $cursor->copy()->startOfDay();
                $dayEnd   = $cursor->copy()->endOfDay();

                $dayMatchPoolQuery = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dayStart, $dayEnd]);
                if ($selectedTeam === 'all') {
                    $dayMatchPoolQuery->whereIn('team', collect($teamsConfig)->pluck('order_team')->all());
                } else {
                    $dayMatchPoolQuery->where('team', $orderTeams[0]);
                }
                $dayMatchPool = $dayMatchPoolQuery->get();

                $dayProductRows = $allProducts->map(fn (Product $p) => ProductPerformance::buildRow($p, $dayMatchPool, $allProducts));
                $dailyTotals->push(ProductPerformance::sumRows($dayProductRows));
            }
            $leadsGrandTotal = ProductPerformance::sumRows($dailyTotals);

            $stats['total_leads']    = $leadsGrandTotal['total'];
            $stats['catered_leads']  = $leadsGrandTotal['catered'];
            $stats['pick_up_rate']   = $leadTally['pick_up_rate'];
            $stats['upselling_rate'] = $leadTally['upselling_rate'];

            // Last 20 runs, oldest→newest, for the sync activity trend below.
            $syncRuns = SyncRun::orderByDesc('ran_at')->limit(20)->get()->reverse()->values();

            // Today's TSA leaderboard — ranked by upsell SALES (₱), not upsell count,
            // per explicit request: a TSA who closed fewer but higher-value upsells
            // should outrank one with more but cheaper ones. Count and rate still
            // shown alongside for context, just not what determines the order.
            // total_calls uses ProductPerformance::tally()'s total_called (answered +
            // unanswered), NOT a raw COUNT(*) of every order tagged to the TSA —
            // confirmed in production: Katherine showed 42 "calls" here but 41 on her
            // own TSA Performance page, the gap being one lead still "Call in
            // progress" (no concluded disposition yet). Same reasoning, and the same
            // fix, as Hourly Activity's "calls per hour, not raw lead volume" above —
            // reusing $dayOrders (already fetched for that) instead of a second query.
            $shiftsByKey = TsaShift::all()->keyBy('tsa_key');

            // A TSA's Leaderboard row must include every order THEY closed,
            // even one Pancake/TeamShiftWindow attributed to a DIFFERENT
            // team's Order.team than the TSA's own configured team (root-
            // caused 2026-09-07, real production report: Angel Margallo,
            // Team Closing, showed 11 upsells instead of 14 for 2026-09-06 —
            // the missing 3 were leads she genuinely closed that landed with
            // Order.team = Eyecare, since "the team closing is can cater the
            // opening leads" and Order.team is computed from the order's own
            // hour window, independently of who actually closed it). $dayOrders
            // above is deliberately scoped to whereIn('team', $orderTeams) —
            // just the SELECTED team's filter — for the Total Leads funnel and
            // the Hourly Activity/Leads charts below, which legitimately
            // should reflect only that team's own hour window. The Leaderboard
            // needs the opposite: every team's orders in range, so
            // ProductPerformance::tsaRows()'s own tsa_name-first matching (see
            // that function's doc comment for the 2026-09-07 change) can find
            // a TSA's orders regardless of which team's bucket Order.team put
            // them in. Still scoped to the SELECTED team's own roster via
            // $shiftsByKey->filter() below though — passing $shiftsByKey-
            // >values() (every TSA on every team) here would put e.g. an
            // Eyecare TSA's own row onto a page filtered to Team Closing,
            // which isn't cross-team credit, it's the team filter silently
            // doing nothing (confirmed live via a failing test in
            // DashboardTeamFilterTest.php: filtering to sh-naturals with an
            // Eyecare-team-only order in play still counted it, because
            // that TSA's real TsaShift row exists globally and this used to
            // pass every one of them into tsaRows() regardless of filter).
            $dayOrdersAllTeams = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dateFrom, $dateTo])
                ->whereIn('team', collect($teamsConfig)->pluck('order_team')->all())
                ->get();

            $shiftsForLeaderboard = $selectedTeam === 'all'
                ? $shiftsByKey->values()
                : $shiftsByKey->filter(fn (TsaShift $s) => in_array($s->team, $orderTeams, true))->values();

            $tsaTallyByKey = ProductPerformance::tsaRows($dayOrdersAllTeams, $shiftsForLeaderboard);

            $tsaLeaderboard = $tsaTallyByKey
                ->map(function (array $tally, string $tsaKey) use ($includeRestocking, $dayOrdersAllTeams) {
                    // tally()'s own 'upsell_confirmation'/'upsell_sales' are
                    // ALWAYS inclusive of a genuinely-tagged Restocking order
                    // (Order::isBroadRealUpsell()'s tag-fallback branch — see
                    // its own doc comment — recovers it regardless of any
                    // toggle; status 11 was never excluded from tally()'s own
                    // reject() filter). This is the correct base for the
                    // toggle ON case: confirmed live, POS's own tag filter
                    // for Joana shows 7 upsells including exactly 1
                    // Restocking order, matching tally()'s own inclusive 7.
                    $upsellCount = $tally['upsell_confirmation'];
                    $upsellSales = $tally['upsell_sales'];

                    // Toggle OFF (bug fix, 2026-09-06): subtract back out
                    // whichever of THIS tally already-counted orders are
                    // Restocking-tagged upsells, so OFF genuinely excludes
                    // them (6 for Joana) instead of always matching ON (a
                    // previous fix on this same day stopped double-counting
                    // when ON, but left OFF unable to ever actually exclude
                    // anything, since tally() itself has no toggle
                    // awareness — this scopes the exclusion to just the
                    // Dashboard leaderboard's own two displayed numbers,
                    // without touching tally()'s shared internals, which
                    // Leads Report/TSA Performance/Analytics/Charts/Insights
                    // all also depend on with no toggle concept of their own).
                    if (!$includeRestocking) {
                        $tsaRestockingUpsells = $dayOrdersAllTeams->where('tsa_name', $tsaKey)
                            ->where('is_restocking_upsell', true)
                            ->filter(fn (Order $o) => Order::isBroadRealUpsell($o));
                        $upsellCount -= $tsaRestockingUpsells->count();
                        $upsellSales -= (float) $tsaRestockingUpsells->sum(fn (Order $o) => $o->realUpsellAmount());
                    }

                    return (object) [
                        'tsa_name'     => $tsaKey,
                        'total_calls'  => $tally['total_called'],
                        'upsell_count' => $upsellCount,
                        'upsell_sales' => $upsellSales,
                    ];
                })
                ->filter(fn ($row) => $row->total_calls > 0 || $row->upsell_count > 0)
                ->values()
                ->sort(fn ($a, $b) => [$b->upsell_sales, $b->upsell_count] <=> [$a->upsell_sales, $a->upsell_count])
                ->values()
                ->map(function ($row) use ($shiftsByKey, $dateFrom, $dateTo) {
                    $shift = $shiftsByKey->get($row->tsa_name);
                    $row->display_name = $shift->display_name ?? $row->tsa_name;
                    // Dated (explicit follow-up request, 2026-09-04:
                    // "backtrack the data like yesterday it is sh naturals
                    // and eyecare") — this leaderboard can show any picked
                    // $dateFrom/$dateTo range, not only today (see
                    // $leaderboardIsToday's own conditional label), so the
                    // team name must reflect what it was actually called
                    // across that range.
                    $row->team_name    = $shift ? Teams::nameForOrderTeamRange($shift->team, $dateFrom, $dateTo) : null;
                    $row->upsell_rate  = $row->total_calls > 0 ? round($row->upsell_count / $row->total_calls * 100, 1) : 0.0;
                    return $row;
                });

            // Total Cross-Sell Sales / total order count — a straight sum over the
            // leaderboard's own rows built above, not a separate query (see that
            // block's own 2026-09-07 comment for why: this card and the Leaderboard
            // used to be computed independently and could disagree). upsell_count/
            // upsell_sales already reflect the Include Restocking toggle per-row
            // (lines above), so summing them here carries that same toggle state
            // through automatically.
            //
            // PLUS a real upsell order whose tsa_name has no matching TsaShift row
            // at all (never configured — e.g. Angel/Grace/Hannah before their
            // roster row existed, or a plain typo) — tsaRows() can't build a row
            // for a TSA that isn't in $shiftsByKey, so it's structurally invisible
            // to the Leaderboard sum above. Explicit decision (2026-09-07, same
            // report as the cross-team fix above): this revenue is still real and
            // must still count on the card, just not attributed to anyone on the
            // Leaderboard — added back in here so a missing roster row can never
            // silently shrink Total Cross-Sell Sales, only hide WHO gets credit
            // for it. Scoped by $orderTeams (the selected team filter), same as
            // every other KPI on this page — unlike a known TSA's own cross-team
            // catering, an orphaned order has no TSA identity to look past that
            // filter with.
            $orphanedUpsells = $dayOrdersAllTeams
                ->whereIn('team', $orderTeams)
                ->whereNotNull('tsa_name')
                ->reject(fn (Order $o) => $shiftsByKey->has($o->tsa_name))
                ->filter(fn (Order $o) => Order::isBroadRealUpsell($o))
                ->when(!$includeRestocking, fn ($c) => $c->reject(fn (Order $o) => $o->is_restocking_upsell));

            $totalOrders = (int) $tsaLeaderboard->sum('upsell_count') + $orphanedUpsells->count();
            $grossSales  = (float) $tsaLeaderboard->sum('upsell_sales')
                + (float) $orphanedUpsells->sum(fn (Order $o) => $o->realUpsellAmount());
            $stats['total_sales']  = $grossSales;
            $stats['total_orders'] = $totalOrders;
            $stats['aov']          = $totalOrders > 0 ? $grossSales / $totalOrders : 0;

            // Top TSA by upsell sales — same ranking as the leaderboard below, surfaced
            // as a KPI-row spotlight so it's visible without scrolling.
            $topTsa = $tsaLeaderboard->first();

            // Top upsell products — which items are actually driving today's cross-sell
            // revenue, not just which TSA is closing them.
            $topProducts = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)
                ->realUpsell()
                ->whereNotNull('product')
                ->selectRaw('product, COUNT(*) as upsell_count, SUM(amount) as total_sales')
                ->groupBy('product')
                ->orderByDesc('upsell_count')
                ->limit(6)
                ->get();

            // Hourly activity — CALLS per hour, not raw lead volume: a lead Pancake
            // auto-creates from an inbound Facebook message counts here with zero calls
            // made on it (confirmed in production: 12am/5am/6am/7am buckets were all
            // fresh, disposition=NULL, tsa_name=NULL leads no one had touched yet, which
            // is what made this chart's "Calls per hour" label misleading before this
            // fix — it was really "Leads per hour"). total_called (answered + unanswered,
            // same definition ProductPerformance::tally() uses everywhere else in this
            // app) excludes both those never-worked leads (excess) and ones still
            // mid-call (in_progress), so this now matches what "calls" actually means on
            // every other page. Same per-hour tally() grouping ChartsController already
            // uses for its own hourly series — kept identical rather than reinventing it.
            $ordersByHour   = $dayOrders->groupBy(fn($o) => (int) $o->pancake_created_at->format('G'));
            $hourlyActivity = collect(range(0, 23))
                ->map(fn($h) => ProductPerformance::tally($ordersByHour->get($h, collect()))['total_called']);

            // Hourly Leads (explicit request, 2026-08-22) — real leads from Pancake,
            // same "New Leads" definition Leads Report's own hourly breakdown uses
            // (ProductPerformance::tally()'s 'total' — every real order EXCEPT ones
            // Pancake itself no longer has, Order::DELETED_STATUSES, and orders
            // closed under a known non-TSA account, excluded_upsell_seller — see
            // tally()'s own doc comment). A lead Pancake auto-creates from an
            // inbound message still belongs here even with zero calls made on it
            // yet — that's the whole point of this chart (when leads actually
            // arrive) — this only excludes rows tally() itself would never count
            // as a real lead anywhere else in this app. No shift-cutoff zeroing
            // either (unlike Hourly Activity above): an overnight arrival is real
            // data worth seeing, not a "nobody's working yet" artifact to hide.
            //
            // Bug fix (2026-08-22): this originally reused $ordersByHour, which is
            // keyed by pancake_created_at — despite the name, that column actually
            // holds the "worked at" timestamp (see Order::getEffectiveCreatedAtAttribute()'s
            // doc comment / SyncTodayOrders::resolveWorkedAt()), the moment a TSA's
            // tag was added, not when the lead arrived. An overnight lead nobody
            // touches until the first shift starts got its whole hour's backlog
            // dumped into the shift-start bucket instead — confirmed in production
            // (2026-08-22): Leads Report showed real leads 12am–9am while this chart
            // showed nothing before a single huge 10am spike. Leads Report's own
            // hourly breakdown avoids this by keying off effective_created_at
            // (pancake_inserted_at, the real arrival time) instead — same fix here.
            $leadsByHour = $dayOrders->groupBy(fn($o) => (int) $o->effective_created_at->format('G'));
            $hourlyLeads = collect(range(0, 23))
                ->map(fn($h) => ProductPerformance::tally($leadsByHour->get($h, collect()))['total']);

            // Shift-start cutoff — same reasoning as Leads Report's hourly breakdown
            // (LeadsReportController::buildHourlyRows): confirmed in production, an
            // order can carry a genuine upsell tag (Order::hasUpsellTag) timestamped
            // hours before that TSA's shift actually starts (e.g. a 6:47am order
            // tagged for a TSA whose shift starts 3pm) — Pancake's own automation
            // stamped the tag at order creation, not a human working that hour. Only
            // meaningful for a single calendar day: a multi-day range merges every
            // day's same hour-of-day together, where "the shift hasn't started yet"
            // no longer has one answer — skipped there, same restriction Leads
            // Report already applies.
            if ($dateFrom->isSameDay($dateTo)) {
                // Scoped to the selected team's own TSAs — otherwise filtering to
                // Eyecare alone could still pick up SH Naturals' earliest shift start
                // (e.g. Gemma's 8 AM) as the cutoff, even though no Eyecare TSA
                // actually starts that early.
                $activeStarts = $shiftsByKey
                    ->filter(fn($s) => in_array($s->team, $orderTeams, true))
                    ->reject(fn($s) => !$s->shift_start || $s->isOffOn($dateFrom))
                    ->map(fn($s) => (int) date('G', strtotime($s->shift_start)));

                if ($activeStarts->isNotEmpty()) {
                    $hourlyCutoff = $activeStarts->min();

                    for ($h = 0; $h < $hourlyCutoff; $h++) {
                        $hourlyActivity[$h] = 0;
                    }

                    // The cutoff hour itself absorbs the whole pre-shift backlog's
                    // calls, same as Leads Report's shift-start row — the first TSA
                    // to start working plausibly touches leads that piled up
                    // overnight, not just that hour's own.
                    $backlog = collect();
                    for ($h = 0; $h <= $hourlyCutoff; $h++) {
                        $backlog = $backlog->merge($ordersByHour->get($h, collect()));
                    }
                    $hourlyActivity[$hourlyCutoff] = ProductPerformance::tally($backlog)['total_called'];
                }
            }

            // Team comparison — orders, upsell rate and revenue side by side, replacing
            // the old Shop Lines panel (which only showed revenue) with the metric this
            // whole app is actually built around: upsell rate, not just raw sales.
            // Only meaningful with every team on the page at once — filtered down to
            // one team via the ALL/SH Naturals/Eyecare buttons above, there's nothing
            // left to compare, so this stays empty and the view's own
            // isNotEmpty() check hides the panel entirely.
            // total_calls uses ProductPerformance::tally()'s total_called (answered +
            // unanswered), NOT a raw COUNT(*) of every order created that day — same
            // fix, same reasoning, as the TSA Leaderboard and Hourly Activity above.
            // Confirmed live: July 31 showed "106 calls" here for SH Naturals with 0
            // upsells, which read as "TSAs worked all day and closed nothing" — the
            // real story was 0 calls actually made (no one worked that day) and 106
            // raw leads that arrived and sat undispositioned. A raw order count can't
            // tell those two situations apart; total_called can.
            $teamComparison = $selectedTeam !== 'all' ? collect() : collect($teamsConfig)->map(function ($teamConfig) use ($dateFrom, $dateTo) {
                $orders      = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])->where('team', $teamConfig['order_team'])->get();
                $totalCalled = ProductPerformance::tally($orders)['total_called'];
                // Order::isRealUpsell(), not a bare is_upsell — see its own doc
                // comment (same fix as Total Cross-Sell Sales/Top Upsell Products above).
                $realUpsells = $orders->filter(fn ($o) => Order::isRealUpsell($o));
                $upsellCount = $realUpsells->count();

                return [
                    // Dated (explicit follow-up request, 2026-09-04:
                    // "backtrack the data like yesterday it is sh naturals
                    // and eyecare") — this whole comparison is already
                    // scoped to $dateFrom/$dateTo above, so the label must
                    // match what this team was actually called across that
                    // exact range, not today's name.
                    'name'         => Teams::nameForOrderTeamRange($teamConfig['order_team'], $dateFrom, $dateTo),
                    'total_calls'  => $totalCalled,
                    'upsell_count' => $upsellCount,
                    'upsell_rate'  => $totalCalled > 0 ? round($upsellCount / $totalCalled * 100, 1) : 0.0,
                    'revenue'      => $realUpsells->sum('amount'),
                ];
            })->values();

            // Restocking breakdown — same "Restocking" upsells behind the Total
            // Restocking KPI tile above, broken out per TSA and per brand instead of
            // one lump sum.
            $restockingByTsa = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)
                ->where('is_restocking_upsell', true)
                ->whereNotNull('tsa_name')
                ->selectRaw('tsa_name, COUNT(*) as restocking_count, SUM(restocking_upsell_amount) as restocking_value')
                ->groupBy('tsa_name')
                ->orderByDesc('restocking_value')
                ->get()
                ->map(function ($row) use ($shiftsByKey, $dateFrom, $dateTo) {
                    $shift = $shiftsByKey->get($row->tsa_name);
                    $row->display_name = $shift->display_name ?? $row->tsa_name;
                    // Dated (explicit follow-up request, 2026-09-04) — same
                    // reasoning as the TSA Leaderboard's own team_name above.
                    $row->team_name    = $shift ? Teams::nameForOrderTeamRange($shift->team, $dateFrom, $dateTo) : null;
                    return $row;
                });

            // Scoped to $orderTeams (same as every other widget above) — 'By Brand'
            // naturally collapses to the one selected team's own row instead of
            // showing the other, filtered-out team's data alongside it.
            $restockingByTeam = collect($teamsConfig)
                ->filter(fn($teamConfig) => in_array($teamConfig['order_team'], $orderTeams, true))
                ->map(function ($teamConfig) use ($dateFrom, $dateTo) {
                    $base = Order::whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                        ->where('team', $teamConfig['order_team'])
                        ->where('is_restocking_upsell', true);

                    return [
                        // Dated (explicit follow-up request, 2026-09-04) —
                        // same reasoning as the Team Comparison panel above.
                        'name'             => Teams::nameForOrderTeamRange($teamConfig['order_team'], $dateFrom, $dateTo),
                        'restocking_count' => (clone $base)->count(),
                        'restocking_value' => (clone $base)->sum('restocking_upsell_amount'),
                    ];
                })->values();

        } catch (QueryException $e) {
            $dbError = 'Database schema not ready — run: php artisan migrate';
            Log::error('DashboardController DB error: ' . $e->getMessage());
        }

        return view('dashboard', compact(
            'stats', 'recentOrders', 'apiConnected', 'dbError',
            'dateFrom', 'dateTo', 'hasSyncedData', 'syncRuns',
            'tsaLeaderboard', 'topProducts', 'hourlyActivity', 'hourlyLeads', 'teamComparison',
            'restockingByTsa', 'restockingByTeam', 'topTsa', 'reconciliationIssues',
            'teams', 'selectedTeam', 'includeRestocking'
        ));
    }

    /**
     * Spawns the actual Pancake fetch as a DETACHED background process
     * (exec ... &), never Artisan::call() in-process — same fix already
     * applied to CronController::run() for the identical reason. This
     * container serves every request through a single php artisan serve
     * worker (see Dockerfile — no php-fpm, no worker pool). Running a
     * multi-page Pancake fetch synchronously here blocks that one worker for
     * its whole duration, during which Render's own 5-second health check can
     * time out and get the instance killed mid-sync — confirmed in
     * production ("Instance failed... HTTP health check failed") every time
     * Sync was clicked on a big day. The response now returns instantly with
     * a {since, expected} marker; the frontend (dashboard.blade.php) polls
     * syncStatus() below until the background runs it kicked off land.
     */
    public function sync(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->toDateString());
        $dateTo   = $request->input('date_to',   $dateFrom);

        $from = Carbon::parse($dateFrom);
        $to   = Carbon::parse($dateTo);

        // Mirrors SyncTodayOrders' own overlap guard (see that class for the
        // full reasoning) — checked here too so a click while a sync is
        // already running gets an immediate, honest answer instead of
        // silently spawning a process that will just skip itself and never
        // post a result the frontend's polling would ever see land.
        if ($this->pancakeSyncIsRunning()) {
            return response()->json([
                'success'       => false,
                'new_orders'    => 0,
                'upsell_count'  => 0,
                'upsell_sales'  => 0.0,
                'error_message' => 'A sync is already running — wait for it to finish before starting another.',
            ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
        }

        // Every pancake:sync-today run below writes exactly one new SyncRun row
        // (SyncTodayOrders::recordRun) — remember the high-water mark first so
        // syncStatus() can tell whether THIS request's runs have all landed
        // yet, and pick out just those rows once they have.
        // NOT safe against concurrent /sync requests: two overlapping requests
        // (e.g. two users syncing at once) can each pick up rows the other one
        // wrote, inflating one request's reported counts. Acceptable for now on
        // this low-traffic internal admin tool; would need per-request locking
        // or a request-scoped marker column to fix properly.
        $lastRunIdBeforeSync = SyncRun::max('id') ?? 0;
        $expectedRuns        = $from->diffInDays($to) + 1;

        $php     = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $logFile = escapeshellarg(storage_path('logs/manual-sync.log'));

        $commands = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $date = escapeshellarg($cursor->toDateString());
            $commands[] = "{$php} {$artisan} pancake:sync-today --date={$date}";
        }
        exec('(' . implode(' && ', $commands) . ") >> {$logFile} 2>&1 &");

        return response()->json([
            'since'    => $lastRunIdBeforeSync,
            'expected' => $expectedRuns,
        ]);
    }

    /** Mirrors SyncTodayOrders::runningFlagIsStale() — kept in sync manually
     *  since the two classes don't share a base; see that method for the full
     *  reasoning on why staleness (not just presence) is what's checked. */
    private function pancakeSyncIsRunning(): bool
    {
        if (Setting::get('pancake_sync_running') !== '1') {
            return false;
        }

        $lastRun = Setting::get('pancake_sync_last_run');
        return $lastRun && Carbon::parse($lastRun)->diffInMinutes(now()) <= 10;
    }

    /** Polled by the Sync button (dashboard.blade.php) after sync() above
     *  kicks the actual work off in the background — see that method's doc
     *  comment. 'done' stays false until every SyncRun row the request
     *  expects has landed; once it has, logs the same Activity Log entry the
     *  old synchronous version logged inline, and returns the same response
     *  shape it used to return directly from sync(). */
    public function syncStatus(Request $request)
    {
        $since    = (int) $request->input('since', 0);
        $expected = max(1, (int) $request->input('expected', 1));

        $runsFromThisSync = SyncRun::where('id', '>', $since)->orderBy('id')->get();

        if ($runsFromThisSync->count() < $expected) {
            return response()->json(['done' => false]);
        }

        $firstFailure = $runsFromThisSync->first(fn (SyncRun $run) => !$run->success);

        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to', $dateFrom);
        if ($dateFrom) {
            $rangeLabel = $dateFrom === $dateTo ? $dateFrom : "{$dateFrom} to {$dateTo}";
            ActivityLogger::log(
                'dashboard.sync',
                null,
                $firstFailure === null
                    ? "Manually synced {$rangeLabel} — {$runsFromThisSync->sum('new_orders')} new orders."
                    : "Manually synced {$rangeLabel} — failed."
            );
        }

        return response()->json([
            'done'          => true,
            'success'       => $firstFailure === null,
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
