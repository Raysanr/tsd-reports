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
        $teamsConfig  = config('teams', []);
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

        $stats          = ['total_sales' => 0, 'total_orders' => 0, 'restocking_count' => 0, 'restocking_value' => 0, 'cancelled_count' => 0, 'cancelled_value' => 0, 'cancelled_unknown_count' => 0, 'last_synced' => null, 'sync_interval' => 2, 'sync_stale' => true, 'total_leads' => 0, 'catered_leads' => 0, 'pick_up_rate' => null, 'upselling_rate' => null, 'aov' => 0];
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

            // realUpsell(), not a bare is_upsell=true — see Order::scopeRealUpsell()'s
            // own doc comment: is_upsell alone undercounts once a genuine upsell is
            // later returned/cancelled in Pancake. Confirmed live against real Pancake
            // POS + the logistics system, both of which still count a later-returned
            // upsell toward the day's total.
            //
            // COALESCE(pancake_inserted_at, pancake_created_at), not plain
            // pancake_created_at (root-caused 2026-08-16: Total Cross-Sell Sales
            // disagreed with the TSA Leaderboard below by exactly one order's amount
            // on a real production day — the 2026-08-11 change documented on
            // $dayOrders below switched the Leaderboard/Total Leads/Hourly Activity
            // to this POS-matching expression but missed this separate query, so an
            // order whose pancake_inserted_at fell on a different calendar day than
            // its pancake_created_at counted toward one card's total but not the
            // other's for the same picked date).
            //
            // whereNotNull('tsa_name') (root-caused 2026-08-21: this card disagreed
            // with the TSA Leaderboard below by exactly one order's amount again — a
            // real upsell (upsell tag present) whose order carries no TSA name tag and
            // no assigning_seller account match, so SyncTodayOrders::extractTsaInfo()
            // resolves the order's team but leaves tsa_name null. This card had no
            // tsa_name filter and counted it; the Leaderboard's own
            // ->whereNotNull('tsa_name')->groupBy('tsa_name') structurally has no row
            // to put it in and silently dropped it. Matching this card to the
            // Leaderboard's own requirement keeps both scoped to the same order set).
            $upsells = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)->whereNotNull('tsa_name')->realUpsell();

            $totalOrders = (clone $upsells)->count();
            $grossSales  = (clone $upsells)->sum('amount');

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
            // Same COALESCE date basis as $upsells above, for the same reason —
            // Restocking's contribution to Total Cross-Sell Sales (via the Include
            // Restocking toggle below) must agree with which day the Leaderboard
            // credits it to. Same whereNotNull('tsa_name') as $upsells above too, for
            // the same 2026-08-21 root cause — the Leaderboard folds restocking rows
            // into a TSA's own bucket, so an unattributed restocking row has nowhere
            // to land there either.
            $restocking = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)
                ->whereNotNull('tsa_name')
                ->where('is_restocking_upsell', true);

            // Include Restocking toggle (see $includeRestocking's own comment above):
            // folds Restocking's revenue/count into Total Cross-Sell Sales instead of
            // leaving it excluded. restocking_upsell_amount (not amount) is the same
            // isolated add-on price $stats['restocking_value'] below already uses —
            // adding it here can never double it, since $upsells (is_upsell=true)
            // structurally never overlaps with is_restocking_upsell=true rows (the
            // comment above this block explains why those two are mutually exclusive).
            if ($includeRestocking) {
                $totalOrders += (clone $restocking)->count();
                $grossSales  += (clone $restocking)->sum('restocking_upsell_amount');
            }

            // Cancelled upsells — different from Restocking: the customer cancelled just
            // the TSA's upsell add-on while their primary order kept going (is_upsell is
            // already forced false for these by SyncTodayOrders, so they're automatically
            // excluded from $grossSales/$totalOrders above with no separate subtraction
            // needed here). cancelled_upsell_amount preserves what the add-on would have
            // been worth, captured before Pancake's own data dropped that line item —
            // but only when a prior sync had already seen the order as a live upsell.
            // When that never happened, cancelled_upsell_amount is NULL (not 0): Pancake's
            // own histories log never retains an items/price snapshot, so that value is
            // genuinely unrecoverable, not just missing — SUM() below ignores those NULLs
            // rather than silently treating them as a confirmed ₱0.
            // Same COALESCE date basis as $upsells above, for consistency with the
            // rest of this card's numbers.
            $cancelledUpsells = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)
                ->where('is_cancelled_upsell', true);

            // Extracted into App\Support\SyncHealth so this page and the dedicated
            // Sync Health page (SyncHealthController) can never drift out of sync
            // on what counts as "stale".
            $syncHealth = SyncHealth::status();

            $stats = [
                'total_sales'      => $grossSales,
                'total_orders'     => $totalOrders,
                'restocking_count' => (clone $restocking)->count(),
                'restocking_value' => (clone $restocking)->sum('restocking_upsell_amount'),
                'cancelled_count'         => (clone $cancelledUpsells)->count(),
                'cancelled_value'         => (clone $cancelledUpsells)->sum('cancelled_upsell_amount'),
                'cancelled_unknown_count' => (clone $cancelledUpsells)->whereNull('cancelled_upsell_amount')->count(),
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
            $dayOrders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dateFrom, $dateTo])
                ->whereIn('team', $orderTeams)->get();

            $leadTally = ProductPerformance::tally($dayOrders);

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
            // (not literally every product) — otherwise, on a single-team
            // view, the OTHER team's products would also get matched below
            // and bleed their own counts into this team's Total Leads.
            // $matchPool, unlike $dayOrders, is deliberately NOT team-scoped
            // even on a single-team view — same reason Leads Report's own
            // $matchPool isn't (LeadsReportController::index()'s comment): a
            // cross-team combo order (e.g. an Eyecare-owned order bundling
            // SH Naturals' Sinuxyl) needs to be visible to SINUXYL's own row
            // even though its `team` column is Eyecare, not SH Naturals —
            // $dayOrders alone (already team-filtered) would never surface it,
            // which is exactly why Dashboard's single-team Total Leads used to
            // disagree with Leads Report's for a range containing one.
            $allProducts = $selectedTeam === 'all'
                ? Product::orderBy('sort_order')->get()
                : Product::where('team', $orderTeams[0])->orderBy('sort_order')->get();
            $matchPool = $selectedTeam === 'all'
                ? $dayOrders
                : Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$dateFrom, $dateTo])
                    ->whereIn('team', collect($teamsConfig)->pluck('order_team')->all())->get();
            $productRows     = $allProducts->map(fn (Product $p) => ProductPerformance::buildRow($p, $matchPool, $allProducts));
            $leadsGrandTotal = ProductPerformance::sumRows($productRows);

            $stats['total_leads']    = $leadsGrandTotal['total'];
            $stats['catered_leads']  = $leadsGrandTotal['catered'];
            $stats['pick_up_rate']   = $leadTally['pick_up_rate'];
            $stats['upselling_rate'] = $leadTally['upselling_rate'];
            $stats['aov']            = $totalOrders > 0 ? $grossSales / $totalOrders : 0;

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
            $teamNames   = collect(config('teams'))->pluck('name', 'order_team');

            $tsaLeaderboard = $dayOrders
                ->whereNotNull('tsa_name')
                ->groupBy('tsa_name')
                ->map(function (Collection $orders, string $tsaName) use ($includeRestocking) {
                    // Order::isRealUpsell() — see its own doc comment. Leads Report/
                    // TSA Performance already counted both is_upsell and
                    // is_returned_upsell; this leaderboard was the one place that
                    // never got the same fix — confirmed live against real Pancake
                    // POS + the logistics system, both of which count a
                    // later-returned upsell toward the day's total same as any other.
                    $realUpsells = $orders->filter(fn ($o) => Order::isRealUpsell($o));
                    $upsellCount = $realUpsells->count();
                    $upsellSales = (float) $realUpsells->sum('amount');

                    // Same Include Restocking toggle as Total Cross-Sell Sales above —
                    // restocking_upsell_amount is the isolated add-on price, same
                    // convention as everywhere else Restocking is counted.
                    if ($includeRestocking) {
                        $upsellCount += $orders->where('is_restocking_upsell', true)->count();
                        $upsellSales += (float) $orders->where('is_restocking_upsell', true)->sum('restocking_upsell_amount');
                    }

                    return (object) [
                        'tsa_name'     => $tsaName,
                        'total_calls'  => ProductPerformance::tally($orders)['total_called'],
                        'upsell_count' => $upsellCount,
                        'upsell_sales' => $upsellSales,
                    ];
                })
                ->values()
                ->sort(fn ($a, $b) => [$b->upsell_sales, $b->upsell_count] <=> [$a->upsell_sales, $a->upsell_count])
                ->values()
                ->map(function ($row) use ($shiftsByKey, $teamNames) {
                    $shift = $shiftsByKey->get($row->tsa_name);
                    $row->display_name = $shift->display_name ?? $row->tsa_name;
                    $row->team_name    = $shift ? ($teamNames[$shift->team] ?? $shift->team) : null;
                    $row->upsell_rate  = $row->total_calls > 0 ? round($row->upsell_count / $row->total_calls * 100, 1) : 0.0;
                    return $row;
                });

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
                    'name'         => $teamConfig['name'],
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
                ->map(function ($row) use ($shiftsByKey, $teamNames) {
                    $shift = $shiftsByKey->get($row->tsa_name);
                    $row->display_name = $shift->display_name ?? $row->tsa_name;
                    $row->team_name    = $shift ? ($teamNames[$shift->team] ?? $shift->team) : null;
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
                        'name'             => $teamConfig['name'],
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
