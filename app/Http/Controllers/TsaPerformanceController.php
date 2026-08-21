<?php

namespace App\Http\Controllers;

use App\Models\CallRecordingHour;
use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TsaPerformanceController extends Controller
{
    /** Full-day window: covers overnight shifts (e.g. Marisol's 10PM–5PM) that start before 8AM. */
    private const START_HOUR = 0;
    private const END_HOUR   = 23;

    /** Fixed policy constant for the "Productivity Time" figure on the individual TSA
     *  page — every full shift is assumed to yield this many productive minutes after
     *  a 1-hour break plus incidental bathroom time, regardless of the TSA's actual
     *  configured shift length (confirmed against the source sheet, which uses the
     *  same flat 440 for every TSA). */
    private const DEFAULT_SHIFT_PRODUCTIVITY_MINUTES = 440;

    /** Accumulator keys for per-row / per-block / grand totals. 'total_called' is the
     *  single "Total Called Leads" column shown in the hourly view (matches the source
     *  sheet) — it's the sum of the 13 disposition columns, i.e. Answered + Unanswered.
     *  'total'/'catered' are still accumulated (used for the Excess column and internal
     *  checks) but are no longer displayed there. */
    private const COLUMNS = [
        'total', 'catered', 'total_called', 'answered', 'unanswered',
        'confirmed_via_call', 'upsell_confirmation', 'call_back', 'call_dropped',
        'repeat_order_upsell', 'rude_customer', 'relatives_confirmation',
        'dfr', 'double_order', 'fsd_uncleared', 'not_answering', 'unattended', 'invalid_number',
        'excess',
    ];

    /** Display metadata for the disposition columns — shared with the Team Report
     *  per-product breakdown via App\Support\ProductPerformance, so both pages can
     *  never render the same column under two different labels/groupings. */
    private const METRIC_COLUMNS = ProductPerformance::METRIC_COLUMNS;

    public function index()
    {
        // Filters persist per page: reopening TSA Performance via the sidebar (no query
        // string) restores the last team/date/product used HERE — stored under a
        // page-specific session key so it never collides with Team Report's filters. A
        // fresh session with no prior visit uses the defaults.
        $dateFrom     = request('date_from', session('filters.tsa_performance.date_from', now('Asia/Manila')->format('Y-m-d')));
        $dateTo       = request('date_to',   session('filters.tsa_performance.date_to', $dateFrom));
        $from         = Carbon::parse($dateFrom)->startOfDay();
        $to           = Carbon::parse($dateTo)->endOfDay();
        $selectedTeam = request('team', session('filters.tsa_performance.team', 'sh-naturals'));
        $teamsConfig  = config('teams', []);

        session([
            'filters.tsa_performance.date_from' => $dateFrom,
            'filters.tsa_performance.date_to'   => $dateTo,
        ]);

        if ($selectedTeam === 'all') {
            session(['filters.tsa_performance.team' => 'all']);
            return $this->indexAll($dateFrom, $dateTo, $from, $to, $teamsConfig);
        }

        if (!array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'sh-naturals';
        }
        session(['filters.tsa_performance.team' => $selectedTeam]);

        // Per-product toggle (the sheets split each team's report into one tab per
        // pack/product — this mirrors that). 'all' means no filtering, same as before.
        // Sourced from the products table (Product Management page) instead of
        // config/teams.php — see docs/superpowers/specs/2026-07-06-product-management-design.md.
        // Hidden products never appear in this filter dropdown, regardless of date
        // range — it's a picker shortcut, not a data view, and "All Products" (the
        // default) already includes their data correctly either way.
        $availableProducts = Product::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->where('is_hidden', false)
            ->orderBy('sort_order')->get();
        $selectedProduct   = request('product', session('filters.tsa_performance.product', 'all'));

        // A remembered product from a different team won't match this team's list — reset
        // to 'all' in that case (same guard already applied to an out-of-team URL param).
        $selectedProductModel = $availableProducts->firstWhere('display_name', $selectedProduct);
        if ($selectedProduct !== 'all' && !$selectedProductModel) {
            $selectedProduct = 'all';
        }
        session(['filters.tsa_performance.product' => $selectedProduct]);

        // Load the roster for the selected team only
        $shifts = TsaShift::where('team', $teamsConfig[$selectedTeam]['order_team'])
            ->orderBy('sort_order')->get()->keyBy('tsa_key');

        // Every currently-known tsa_key, across EVERY team — not just this one.
        // Root-caused 2026-08-21: Order.tsa_name is a snapshot written at sync
        // time, not a live foreign key. If the TSA it named is later renamed or
        // removed from tsa_shifts, that order's tsa_name no longer matches any
        // current roster key — but it's still non-null, so it didn't qualify
        // for the "unclaimed" branch below either, and just vanished from this
        // report entirely (not even landing in Unassigned) while Dashboard/
        // Leads Report — which never look at tsa_name at all — kept counting
        // it, so Grand Total here ran lower than theirs for the same range.
        // Checked against every team's roster (not just $shifts, this team's
        // own), not just null: a tsa_name that's a real, CURRENT TSA on a
        // DIFFERENT team legitimately belongs on that team's own page, not
        // mislabeled Unassigned here.
        $allKnownKeys = TsaShift::pluck('tsa_key');

        // All orders for the selected date: either assigned to a TSA on this team's
        // roster, OR never claimed by any TSA at all but still attributable to this
        // team by product (Fix #15 in SyncTodayOrders — a lead can have a real product
        // in its cart with no TSA tag at all, e.g. swept by the midnight "UNCATERED
        // LEADS" bulk action before anyone touched it; `whereIn(...)` alone would
        // silently drop these since SQL IN never matches NULL, hiding them from every
        // team's report entirely instead of counting them as that team's Excess) —
        // OR tagged with a tsa_name that's orphaned (see $allKnownKeys above), same
        // "attributable to this team, nobody current to credit it to" treatment.
        // Date scope: which DAY an order counts under now matches POS (explicit
        // request, 2026-08-11 — "accurate from POS", after Leads Report showed 256
        // for Eyecare/Aug 10 against Dashboard's 255) — same COALESCE(pancake_
        // inserted_at, pancake_created_at) expression Leads Report already uses
        // (see Order::getEffectiveCreatedAtAttribute()'s doc comment), not a new
        // convention invented here. Deliberately does NOT touch $ordersByHour
        // below, which still buckets by pancake_created_at (the worked-at
        // timestamp) — an hourly CALL ACTIVITY view needs the hour work actually
        // happened in, not the hour the lead first arrived in Pancake; changing
        // that would make the hourly table stop meaning what its own columns say.
        $orders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->where(function ($q) use ($shifts, $teamsConfig, $selectedTeam, $allKnownKeys) {
                $q->whereIn('tsa_name', $shifts->keys())
                  ->orWhere(function ($q2) use ($teamsConfig, $selectedTeam, $allKnownKeys) {
                      $q2->where('team', $teamsConfig[$selectedTeam]['order_team'])
                         ->where(fn ($q3) => $q3->whereNull('tsa_name')->orWhereNotIn('tsa_name', $allKnownKeys));
                  });
            })
            ->get();

        if ($selectedProduct !== 'all') {
            $matchKeyword = $selectedProductModel->effective_keyword;
            $orders = $orders->filter(function ($order) use ($matchKeyword) {
                foreach ($order->raw_tags ?? [] as $tag) {
                    if (stripos($tag, $matchKeyword) !== false) return true;
                }
                return false;
            })->values();
        }

        // Flat day-total summary — explicit request: the same one-row-per-TSA +
        // Grand Total table indexAll() shows, displayed above this page's own
        // hourly breakdown rather than replacing it. Built from the exact same
        // $orders this page already fetched (respects the product filter above
        // too, so it never disagrees with the hourly blocks below it) — same
        // shift/tally/team shape as indexAll()'s own $tsaRows/$grandTotal, just
        // scoped to the one selected team instead of looping every team.
        // Same orphaned-tsa_name fallback as the $orders query above — $orders
        // is already correctly scoped to this team (a real TSA on a different
        // team's roster was excluded there, not swept in here), so anything
        // left with a tsa_name not matching one of THIS team's own $shifts is,
        // by construction, either null or orphaned — safe to lump into
        // Unassigned either way.
        $ordersByTsaFlat = $orders->groupBy(fn($o) => ($o->tsa_name !== null && $shifts->has($o->tsa_name)) ? $o->tsa_name : '__unassigned__');
        $tsaRows = $shifts->map(function ($shift) use ($ordersByTsaFlat, $selectedTeam, $teamsConfig) {
            $row = ProductPerformance::tally($ordersByTsaFlat->get($shift->tsa_key, collect()));
            $row['display_name'] = $shift->display_name;
            $row['team']         = $teamsConfig[$selectedTeam]['name'];
            $row['team_key']     = $selectedTeam;
            $row['tsa_key']      = $shift->tsa_key;
            return $row;
        })->values();

        // Explicit request (2026-08-11): rows + this row must sum back to Grand
        // Total — before this, leads matching the team's products but claimed by
        // no TSA at all (see the $orders query comment above — Fix #15's "swept
        // by the midnight UNCATERED LEADS bulk action" case) counted toward Grand
        // Total but had nowhere to show up, so e.g. 73+43+0 could silently read
        // "117" with no visible source for the extra 1. tsa_key stays the literal
        // string 'unassigned' (not null) so drilldown() below still resolves it
        // (it already has an explicit case for that exact value) — the blade
        // views key off tsa_key === 'unassigned' specifically to skip the
        // individual-TSA-page link this row has no real page for.
        $unassignedOrders = $ordersByTsaFlat->get('__unassigned__', collect());
        if ($unassignedOrders->isNotEmpty()) {
            $unassignedRow = ProductPerformance::tally($unassignedOrders);
            $unassignedRow['display_name'] = 'Unassigned';
            $unassignedRow['team']         = $teamsConfig[$selectedTeam]['name'];
            $unassignedRow['team_key']     = $selectedTeam;
            $unassignedRow['tsa_key']      = 'unassigned';
            $tsaRows->push($unassignedRow);
        }

        $grandTotal = ProductPerformance::tally($orders);

        $allKeys        = $shifts->keys();
        $ordersByHour   = $orders->groupBy(fn($o) => (int) $o->pancake_created_at->format('G'));

        $hourBlocks = [];
        $totals     = array_fill_keys(self::COLUMNS, 0);

        for ($hour = self::START_HOUR; $hour <= self::END_HOUR; $hour++) {
            $hourOrders = $ordersByHour->get($hour, collect());

            if ($hourOrders->isEmpty()) {
                continue;
            }

            // '__unassigned__' sentinel (not literal null) so groupBy's key comparison
            // is unambiguous — bucket for orders with no tsa_name at all.
            $ordersByTsa  = $hourOrders->groupBy(fn($o) => $o->tsa_name ?? '__unassigned__');
            $rows         = [];
            $blockTotals  = array_fill_keys(self::COLUMNS, 0);

            foreach ($allKeys as $key) {
                $shift     = $shifts->get($key);
                $tsaOrders = $ordersByTsa->get($key, collect());
                $row       = $this->buildRow($shift, $key, $tsaOrders);

                foreach (self::COLUMNS as $col) {
                    $blockTotals[$col] += $row[$col];
                    $totals[$col]      += $row[$col];
                }

                $rows[] = $row;
            }

            // Never-claimed-by-any-TSA orders (see the query comment above) — folded
            // into the hour/Grand Total row AND rendered as its own visible row
            // (2026-08-11: restored — this view's rows must sum back to Grand Total,
            // see index()'s flat-summary comment for the "73+43+0 ≠ 117" report that
            // prompted it). Was previously folded into totals only, with no visible
            // row, per an earlier explicit request ("a row with nothing visible in
            // any column was just noise") — that reasoning doesn't apply here: if
            // this bucket is non-empty, total_called > 0 for it same as any other
            // row, so it's never actually blank.
            $unassignedOrders = $ordersByTsa->get('__unassigned__', collect());
            if ($unassignedOrders->isNotEmpty()) {
                $row = $this->buildRow(null, 'unassigned', $unassignedOrders, 'Unassigned');

                foreach (self::COLUMNS as $col) {
                    $blockTotals[$col] += $row[$col];
                    $totals[$col]      += $row[$col];
                }

                $rows[] = $row;
            }

            // UI/UX review finding: an hour where leads arrived but nobody had
            // called any of them yet (e.g. before the first shift starts) still
            // has non-empty $hourOrders, so the isEmpty() check above doesn't
            // catch it — every displayed column here is total_called's own
            // components (Confirmed/Not Answering/etc.), so total_called === 0
            // means every column in this block renders blank regardless of how
            // many raw leads came in. Confirmed in production: SH Naturals'
            // 12am–7am hours repeated all 3 TSAs as fully blank rows plus a
            // blank TOTAL row, every hour, before Gemma's 8am shift start —
            // over 30 rows of nothing. $totals above already accumulated this
            // hour's real (zero) contribution, so skipping it here only omits
            // an all-blank block from the display, it doesn't change any total.
            if ($blockTotals['total_called'] === 0) {
                continue;
            }

            // Pick-up/Conversion/Upselling Rate for this hour block — same shared
            // formula as buildRow()'s per-row rates and the ALL view, merged flat
            // alongside 'label'/'rows'/'totals' so the blade reads $block['pick_up_rate']
            // etc. the same way it already reads $row['pick_up_rate'].
            $hourBlocks[] = array_merge([
                'hour'   => $hour,
                'label'  => HourFormatter::rangeLabel($hour),
                'rows'   => $rows,
                'totals' => $blockTotals,
            ], $this->productRates($blockTotals));
        }

        $teams       = $this->teamsMenu($teamsConfig);
        $metricCols  = self::METRIC_COLUMNS;
        $totalRates  = $this->productRates($totals);
        $totalPickUpRate     = $totalRates['pick_up_rate'];
        $totalConversionRate = $totalRates['conversion_rate'];
        $totalUpsellingRate  = $totalRates['upselling_rate'];

        return view('tsa-performance', compact(
            'dateFrom', 'dateTo', 'hourBlocks', 'totals',
            'teams', 'selectedTeam', 'metricCols',
            'totalPickUpRate', 'totalConversionRate', 'totalUpsellingRate',
            'availableProducts', 'selectedProduct',
            'tsaRows', 'grandTotal'
        ));
    }

    /** Individual TSA drill-down: a single TSA's day totals (Total Called Leads, the
     *  13 disposition columns, Pick-up/Conversion/Upselling rates) plus that same
     *  breakdown hour-by-hour, so their trend across the day is visible without
     *  scanning every hour block in the main table for their one row. */
    public function showTsa(string $team, string $tsaKey)
    {
        $teamsConfig = config('teams', []);
        abort_if(!array_key_exists($team, $teamsConfig), 404);

        $shift = TsaShift::where('team', $teamsConfig[$team]['order_team'])
            ->where('tsa_key', $tsaKey)
            ->firstOrFail();

        // Same date range, same session keys as the main hourly view — this page is a
        // drill-down of it, not an independent filter context.
        $dateFrom = request('date_from', session('filters.tsa_performance.date_from', now('Asia/Manila')->format('Y-m-d')));
        $dateTo   = request('date_to',   session('filters.tsa_performance.date_to', $dateFrom));
        $from     = Carbon::parse($dateFrom)->startOfDay();
        $to       = Carbon::parse($dateTo)->endOfDay();

        // Only meaningful when viewing exactly one date — a multi-day range could
        // span both rest and working days, so a single banner would be misleading.
        $isRestDay = $dateFrom === $dateTo && $shift->isOffOn($from);

        // Same POS-accurate date scope as index()/indexAll() (see that comment) —
        // hour-bucketing further down in this method deliberately still uses
        // pancake_created_at (worked-at), unchanged.
        $orders = Order::where('tsa_name', $tsaKey)
            ->whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->get();

        $summary = $this->buildRow($shift, $tsaKey, $orders);
        // Pick-up/Conversion rates need 'answered'/'unanswered' subtotals (same
        // arithmetic as ProductPerformance::tally() computes — this row comes from
        // the older per-TSA buildRow() below instead, so it doesn't share that
        // method directly).
        $summary['answered'] = $summary['confirmed_via_call'] + $summary['upsell_confirmation'] + $summary['call_back']
            + $summary['call_dropped'] + $summary['repeat_order_upsell'] + $summary['rude_customer'] + $summary['relatives_confirmation'];
        $summary['unanswered'] = $summary['dfr'] + $summary['double_order'] + $summary['fsd_uncleared']
            + $summary['not_answering'] + $summary['unattended'] + $summary['invalid_number'];
        $summary = array_merge($summary, $this->productRates($summary));

        $ordersByHour = $orders->groupBy(fn($o) => (int) $o->pancake_created_at->format('G'));
        $hourlyRows   = [];
        for ($hour = self::START_HOUR; $hour <= self::END_HOUR; $hour++) {
            $hourOrders = $ordersByHour->get($hour, collect());
            if ($hourOrders->isEmpty()) continue;

            $row = $this->buildRow($shift, $tsaKey, $hourOrders);
            // Same reasoning as the main hourly view above: leads can arrive
            // before this TSA has called any of them (e.g. before shift
            // start), which leaves every displayed column blank even though
            // $hourOrders isn't empty — skip those rather than show a row of
            // nothing but a label.
            if ($row['total_called'] === 0) continue;

            $hourlyRows[] = [
                'label' => HourFormatter::rangeLabel($hour),
                'hour'  => $hour,
                'row'   => $row,
            ];
        }

        // Whole-shift Productivity Time (the "440" header figure on the source sheet) —
        // a fixed policy constant, not derived from each TSA's own shift length: every
        // full shift is assumed to yield 440 productive minutes after a 1-hour break
        // plus incidental bathroom time, regardless of whether the configured shift
        // itself is 7, 9, or 10 hours long.
        $shiftMinutes = $shift->shift_start && $shift->shift_end && $shift->shift_start !== $shift->shift_end
            ? self::DEFAULT_SHIFT_PRODUCTIVITY_MINUTES
            : null;

        // Per-product-per-hour grid — one column per this team's product, matching
        // the sheet layout: how many of THIS TSA's leads that hour were each product.
        // Reuses ProductPerformance::buildRow (same product-tag matching as Team
        // Report's per-product breakdown) so a lead is never counted differently here
        // than anywhere else in the app.
        $products = Product::where('team', $teamsConfig[$team]['order_team'])->orderBy('sort_order')->get();

        // Real per-hour call-duration totals synced from Google Drive (see
        // SyncCallRecordings) — keyed by hour-of-day the same way $ordersByHour is,
        // so a multi-day range sums same-hour recordings across every day in it,
        // matching how the rest of this method already treats the hour axis.
        $recordingsByHour = CallRecordingHour::where('tsa_key', $tsaKey)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->get()
            ->groupBy(fn($r) => (int) $r->hour);

        $productHourlyRows  = [];
        $productTotals      = $products->mapWithKeys(fn($p) => [$p->id => 0]);
        $productAmountTotals = $products->mapWithKeys(fn($p) => [$p->id => 0.0]);
        $grandRowTotal      = 0;
        $grandTsaLeads      = 0;
        $grandAnswered      = 0;
        $grandUnanswered    = 0;
        $grandOpt           = 0;
        $grandUnproductive  = 0;
        // Day-total AHT — accumulated separately from $grandOpt (minutes, already
        // rounded per hour) since averaging rounded per-hour minutes would drift
        // from the real answer; these stay in raw seconds/call-count until the
        // single division at the end, after the loop.
        $grandBlendedSeconds = 0;
        $grandCallsForAvg    = 0;
        for ($hour = self::START_HOUR; $hour <= self::END_HOUR; $hour++) {
            $hourOrders = $ordersByHour->get($hour, collect());
            if ($hourOrders->isEmpty()) continue;

            // Computed once per product per hour and split into three keys below,
            // rather than calling buildRow() twice — same underlying row, just
            // reading different keys off it.
            $productRows = $products->mapWithKeys(fn($product) => [$product->id => ProductPerformance::buildRow($product, $hourOrders, $products)]);

            // 'catered' (== total_called) feeds ONLY the separate "Total Catered
            // Leads" column below (must match tsa_leads/total_called there) — 'total'
            // counts every matching lead including ones with no worked disposition
            // yet, which let this column read higher than the actual called-leads
            // count for the same hour (confirmed live: a lead still sitting New/
            // un-called inflated the per-product sum by 1 past the real total
            // called that hour).
            $catered  = $productRows->map(fn($row) => $row['catered']);
            $rowTotal = $catered->sum();

            // Explicit request (2026-08-11): each per-product cell's qty must be
            // how many of that hour's leads for this product were an actual
            // upsell — not every catered lead for it. 'catered' included every
            // called lead regardless of outcome, so a product with real call
            // volume but no upsells that hour showed a bare count with no ₱
            // amount next to it (confusing — reads as "4 upsells" when it's
            // really "4 leads called, 0 upsold"). 'upsell_confirmation' is the
            // same $isRealUpsell-filtered count buildRow()'s own upsell_sales
            // amount is derived from, so qty and ₱ never disagree on which
            // orders they're counting.
            $counts  = $productRows->map(fn($row) => $row['upsell_confirmation']);
            $amounts = $productRows->map(fn($row) => $row['upsell_sales']);

            // Same per-hour disposition tally already used for the hourly breakdown
            // table above — reused here (not recomputed differently) so "answered"/
            // "unanswered" can never drift between the two tables. buildRow() itself
            // doesn't include these two combined sums (see $summary below in this same
            // method for the identical formula) — derived here the same way.
            $hourRow    = $this->buildRow($shift, $tsaKey, $hourOrders);
            $answered   = $hourRow['confirmed_via_call'] + $hourRow['upsell_confirmation'] + $hourRow['call_back']
                + $hourRow['call_dropped'] + $hourRow['repeat_order_upsell'] + $hourRow['rude_customer'] + $hourRow['relatives_confirmation'];
            $unanswered = $hourRow['dfr'] + $hourRow['double_order'] + $hourRow['fsd_uncleared']
                + $hourRow['not_answering'] + $hourRow['unattended'] + $hourRow['invalid_number'];

            // OPT — real call-recording data (synced from Google Drive) is used
            // whenever it exists for this hour: OPT becomes the actual summed call
            // duration, and AHT is its average per call. Hours with no synced
            // recordings yet (not uploaded, or before this feature existed) fall back
            // to the original reverse-engineered formula from the source sheet
            // (verified against 7 real rows, exact match every time): OPT assumes 3
            // minutes per answered call, and AHT stays blank (no formula for it exists
            // in the source sheet either).
            $recording   = $recordingsByHour->get($hour);
            $realSeconds = $recording?->sum('total_seconds') ?? 0;
            $realCalls   = $recording?->sum('call_count') ?? 0;

            if ($realCalls > 0) {
                // Blend: real duration for the calls that have a synced recording,
                // plus the same 3-min/call estimate as the no-data fallback for any
                // OTHER answered calls this hour that don't have one yet — otherwise
                // a partially-synced hour (e.g. 1 of 5 answered calls recorded) would
                // report only that one call's total as if it were the whole hour's
                // OPT, understating it badly (confirmed against real data: an hour
                // with 5 answered calls and 1 synced recording showed OPT=0.6min
                // instead of anywhere near the ~15min the other 4 calls implied).
                $callsForAvg    = max($answered, $realCalls);
                $unmatched      = max(0, $answered - $realCalls);
                $blendedSeconds = $realSeconds + $unmatched * 180;
                $opt       = round($blendedSeconds / 60, 1);
                $aht       = (int) round($blendedSeconds / $callsForAvg);
                $optIsReal = true;
            } else {
                $opt       = $answered * 3;
                $aht       = null;
                $optIsReal = false;
                // Pure 3-min/call estimate, same as $opt above — kept in raw
                // seconds/call-count so the day total below can blend this hour
                // in on equal footing with real-recording hours.
                $callsForAvg    = $answered;
                $blendedSeconds = $answered * 180;
            }

            // Unproductive Time: flat 2 minutes per unanswered call, independent of
            // OPT/answered calls — confirmed directly against real data (2026-07-24).
            $unproductive = $unanswered * 2;

            // Same reasoning as the hourly breakdown table above: every column here
            // derives from 'catered'/total_called, so a hour with leads but no
            // called activity yet renders entirely blank anyway — skip it.
            if ($hourRow['total_called'] === 0) continue;

            $productTotals       = $productTotals->map(fn($v, $id) => $v + $counts[$id]);
            $productAmountTotals = $productAmountTotals->map(fn($v, $id) => $v + $amounts[$id]);
            $grandRowTotal      += $rowTotal;
            $grandTsaLeads      += $hourRow['total_called'];
            $grandAnswered      += $answered;
            $grandUnanswered    += $unanswered;
            $grandOpt            += $opt;
            $grandUnproductive   += $unproductive;
            $grandBlendedSeconds += $blendedSeconds;
            $grandCallsForAvg    += $callsForAvg;

            $productHourlyRows[] = [
                'label'       => HourFormatter::rangeLabel($hour),
                'counts'      => $counts,
                'amounts'     => $amounts,
                'row_total'   => $rowTotal,
                'tsa_leads'   => $hourRow['total_called'],
                'answered'    => $answered,
                'unanswered'  => $unanswered,
                'opt'         => $opt,
                'opt_is_real' => $optIsReal,
                'aht'         => $aht,
                'unproductive'=> $unproductive,
            ];
        }

        // Day-total AHT: blended seconds ÷ calls-for-avg across the whole day, same
        // blend-real-with-3-min-estimate approach as each hour's own AHT/OPT above —
        // this is why it's never blank the way a single hour's AHT can be, even on a
        // date where only some hours have a synced recording.
        $grandAht = $grandCallsForAvg > 0 ? (int) round($grandBlendedSeconds / $grandCallsForAvg) : null;

        return view('tsa-performance-individual', [
            'dateFrom'          => $dateFrom,
            'dateTo'            => $dateTo,
            'products'          => $products,
            'productHourlyRows' => $productHourlyRows,
            'productTotals'     => $productTotals,
            'productAmountTotals' => $productAmountTotals,
            'grandRowTotal'     => $grandRowTotal,
            'grandTsaLeads'     => $grandTsaLeads,
            'grandAnswered'     => $grandAnswered,
            'grandUnanswered'   => $grandUnanswered,
            'grandOpt'          => $grandOpt,
            'grandAht'          => $grandAht,
            'grandUnproductive' => $grandUnproductive,
            'shiftMinutes'      => $shiftMinutes,
            'team'              => $team,
            'teamName'          => $teamsConfig[$team]['name'],
            'tsaKey'            => $tsaKey,
            'displayName'       => $shift->display_name,
            'isRestDay'         => $isRestDay,
            'summary'           => $summary,
            'hourlyRows'        => $hourlyRows,
            'metricCols'        => self::METRIC_COLUMNS,
        ]);
    }

    /** Drill-down: given the same team/date/product/hour/TSA a cell on the
     *  hourly table was built from, plus which column was clicked, returns the
     *  exact orders that number came from (id + creation time) — so a click on
     *  e.g. "Upsell w/ Confirmation: 3" can show which 3 orders those are.
     *  'tsa' omitted/'__all__' = every TSA on the team (an hour-block TOTAL row
     *  or the Grand Total row); 'hour' omitted = every hour (Grand Total). */
    public function drilldown(Request $request)
    {
        $teamsConfig = config('teams', []);
        $team        = $request->query('team');
        abort_if(!array_key_exists($team, $teamsConfig), 404);

        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to', $dateFrom);
        abort_if(!$dateFrom, 422);
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();

        $tsaKey  = $request->query('tsa');
        $hour    = $request->query('hour');
        $column  = $request->query('column');
        $product = $request->query('product', 'all');

        // Same order-scoping AND date scope (POS-accurate, see index()'s comment)
        // as index()/indexAll() above — drilldown popovers must show the exact
        // same order set the row/cell they were opened from was built from.
        // Same orphaned-tsa_name fallback as index() too (see that method's own
        // comment) — otherwise a click on an Unassigned row that DOES correctly
        // include an orphaned order (post-fix) would open a popover missing it.
        $shifts       = TsaShift::where('team', $teamsConfig[$team]['order_team'])->get()->keyBy('tsa_key');
        $allKnownKeys = TsaShift::pluck('tsa_key');

        $orders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->where(function ($q) use ($shifts, $teamsConfig, $team, $allKnownKeys) {
                $q->whereIn('tsa_name', $shifts->keys())
                  ->orWhere(function ($q2) use ($teamsConfig, $team, $allKnownKeys) {
                      $q2->where('team', $teamsConfig[$team]['order_team'])
                         ->where(fn ($q3) => $q3->whereNull('tsa_name')->orWhereNotIn('tsa_name', $allKnownKeys));
                  });
            })
            ->get();

        if ($product && $product !== 'all') {
            $productModel = Product::where('team', $teamsConfig[$team]['order_team'])
                ->where('display_name', $product)->first();
            if ($productModel) {
                $keyword = $productModel->effective_keyword;
                $orders = $orders->filter(fn($o) => collect($o->raw_tags ?? [])
                    ->contains(fn($t) => stripos($t, $keyword) !== false))->values();
            }
        }

        if ($hour !== null && $hour !== '') {
            $orders = $orders->filter(fn($o) => (int) $o->pancake_created_at->format('G') === (int) $hour)->values();
        }

        if ($tsaKey && $tsaKey !== '__all__') {
            // Same orphaned-tsa_name-counts-as-Unassigned rule as index()/
            // indexAll()'s own grouping — $orders is already correctly scoped
            // above, so anything left with a tsa_name not in $shifts is, by
            // construction, either null or orphaned.
            $orders = $tsaKey === 'unassigned'
                ? $orders->filter(fn($o) => $o->tsa_name === null || !$shifts->has($o->tsa_name))->values()
                : $orders->where('tsa_name', $tsaKey)->values();
        }

        $matching = ProductPerformance::ordersForColumn($orders, (string) $column);

        return response()->json(
            $matching
                ->sortBy(fn($o) => $o->effective_created_at)
                ->map(fn($o) => [
                    'id'   => $o->pancake_order_id,
                    // effective_created_at (pancake_inserted_at, Pancake's raw
                    // untouched timestamp), NOT pancake_created_at — that column
                    // deliberately holds when the TSA's tag was actually added
                    // (see SyncTodayOrders::flushOrders' "Root-cause fix" comment),
                    // so a backlog lead attributes to the hour it was WORKED, not
                    // created. That's correct for which block the order counts
                    // under, but confusing here: the popover is meant to show what
                    // POS itself calls "Created at" for this specific order, which
                    // is pancake_inserted_at — confirmed live against POS showing
                    // 12:21 PM for an order this had been showing 12:46 PM for.
                    'time' => $o->effective_created_at?->format('g:i A'),
                ])
                ->values()
        );
    }

    /** Team-button menu shared by the hourly view and the ALL view, so they can
     *  never drift into showing different button sets. */
    private function teamsMenu(array $teamsConfig): Collection
    {
        $teams = collect($teamsConfig)->map(fn($t) => $t['name']);
        $teams->prepend('ALL', 'all');

        return $teams;
    }

    /** ALL — one row per TSA, combined across every team, for the whole window (no
     *  hourly split). Used to show one row per PRODUCT instead (identical table
     *  shape); that view moved to the Leads Report (LeadsReportController::indexAll),
     *  since it's a product breakdown, not a TSA one — this is its TSA-rows
     *  counterpart, reusing the exact same ProductPerformance::tally() shape so the
     *  view (tsa-performance-all.blade.php) barely had to change. */
    private function indexAll(string $dateFrom, string $dateTo, Carbon $from, Carbon $to, array $teamsConfig)
    {
        $orderTeams = collect($teamsConfig)->pluck('order_team')->all();

        // Order->team stores each config entry's order_team literal (e.g. "SH
        // Naturals"), not the slug key (e.g. "sh-naturals") the individual-TSA route
        // needs — this reverses that lookup so each row can still link out.
        $teamKeyByOrderTeam = collect($teamsConfig)->mapWithKeys(fn($t, $key) => [$t['order_team'] => $key]);

        // Same POS-accurate date scope as index() (see that comment).
        $orders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->whereIn('team', $orderTeams)
            ->get();

        // Every TSA across every team, sorted team-then-sort_order — same convention
        // as the product ALL view this replaces (orderBy('team') alone would sort
        // alphabetically, wrongly putting Eyecare before SH Naturals).
        $shifts = TsaShift::whereIn('team', $orderTeams)->orderBy('sort_order')->get()
            ->sortBy(fn($s) => array_search($s->team, $orderTeams))
            ->values();

        // Same orphaned-tsa_name fallback as index() (see that method's own
        // comment) — a tsa_name that no longer matches any current TsaShift
        // row (renamed/removed since the order synced) falls back to
        // Unassigned instead of grouping under a key nothing below ever reads,
        // which used to leave it counted in Grand Total (built from the same
        // unfiltered $orders) but invisible in every row, so the rows summed
        // to LESS than Grand Total on this exact page.
        $knownKeys        = $shifts->pluck('tsa_key');
        $ordersByTsa      = $orders->groupBy(fn($o) => ($o->tsa_name !== null && $knownKeys->contains($o->tsa_name)) ? $o->tsa_name : '__unassigned__');
        $unassignedOrders = $ordersByTsa->get('__unassigned__', collect());

        // Built team-by-team (not a flat ->map() over $shifts) so each team's
        // "Unassigned" row (leads matching that team's products but claimed by no
        // TSA — see index()'s identical addition, 2026-08-11, for the "73+43+0 ≠
        // 117" report that prompted it) lands directly after that team's own
        // roster rows, same grouping $shifts is already sorted into.
        $tsaRows = collect();
        foreach ($orderTeams as $orderTeam) {
            foreach ($shifts->where('team', $orderTeam) as $shift) {
                $row     = ProductPerformance::tally($ordersByTsa->get($shift->tsa_key, collect()));
                $teamKey = $teamKeyByOrderTeam[$shift->team] ?? null;

                $row['display_name'] = $shift->display_name;
                $row['team']         = $teamKey ? $teamsConfig[$teamKey]['name'] : $shift->team;
                $row['team_key']     = $teamKey;
                $row['tsa_key']      = $shift->tsa_key;

                $tsaRows->push($row);
            }

            $teamUnassigned = $unassignedOrders->filter(fn($o) => $o->team === $orderTeam);
            if ($teamUnassigned->isNotEmpty()) {
                $teamKey = $teamKeyByOrderTeam[$orderTeam] ?? null;
                $row     = ProductPerformance::tally($teamUnassigned);

                $row['display_name'] = 'Unassigned';
                $row['team']         = $teamKey ? $teamsConfig[$teamKey]['name'] : $orderTeam;
                $row['team_key']     = $teamKey;
                $row['tsa_key']      = 'unassigned';

                $tsaRows->push($row);
            }
        }
        $tsaRows = $tsaRows->values();

        // Grand Total — explicit request: this used to be scoped to only orders
        // claimed by one of the TSAs shown above, excluding leads with no TSA at
        // all, so ALL's total ran LOWER than SH Naturals' total + Eyecare's total
        // (each team's own page already folds unclaimed leads into ITS total —
        // no visible "Unassigned" row since an earlier request removed that, but
        // the leads still count). Now uses the exact same $orders this view
        // already fetched (every order for these teams, claimed or not), so the
        // three filters (ALL / SH Naturals / Eyecare) always tally: ALL's total
        // equals the sum of the per-team pages' totals, the same way Catered
        // Leads and Total Called Leads are already the same underlying number
        // (see buildRow()'s 'catered' => 'total_called' below) just labeled
        // differently per view.
        $grandTotal = ProductPerformance::tally($orders);

        $teams = $this->teamsMenu($teamsConfig);

        return view('tsa-performance-all', [
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'tsaRows'      => $tsaRows,
            'grandTotal'   => $grandTotal,
            'teams'        => $teams,
            'selectedTeam' => 'all',
            'metricCols'   => self::METRIC_COLUMNS,
        ]);
    }

    /** Pick-up / Conversion / Upselling rates from a row with 'answered', 'unanswered',
     *  'upsell_confirmation', and 'confirmed_via_call' keys — used by showTsa()'s
     *  summary row above, which builds those subtotals itself rather than going
     *  through ProductPerformance::tally() (which already includes these rates). */
    private function productRates(array $row): array
    {
        return ProductPerformance::rates($row);
    }

    private function buildRow(?TsaShift $shift, ?string $key, Collection $orders, ?string $displayNameOverride = null): array
    {
        // Drop orders Pancake itself no longer has (Order::DELETED_STATUSES:
        // Canceled or Deleted recently) before counting anything — same
        // exclusion ProductPerformance::tally() applies (fixed 2026-08-18:
        // this hourly per-TSA breakdown has its own separate accumulator,
        // kept in sync by hand, and never got this one when it was added to
        // the shared tally() — Leads Report's own hourly table was already
        // correct the whole time since it flows through tally() directly;
        // only this hand-rolled path was missing it). excluded_upsell_seller
        // rejected the same way (2026-08-21) — see ProductPerformance::tally()'s
        // own comment.
        $orders = $orders->reject(fn ($o) => in_array($o->status_code, Order::DELETED_STATUSES, true)
            || $o->excluded_upsell_seller);

        // Same "real upsell" definition and non-upsell exclusivity guard as
        // ProductPerformance::tally() — now the SAME shared method
        // (Order::isBroadRealUpsell()), not a hand-copied closure, so this
        // per-TSA breakdown can no longer drift out of sync with it (it had,
        // twice, before this — see that method's own doc comment).
        $isRealUpsell = fn($o) => Order::isBroadRealUpsell($o);
        $nonUpsell = $orders->reject($isRealUpsell);

        $row = [
            'display_name'           => $displayNameOverride ?? $shift?->display_name ?? ucfirst($key),
            // Carried through so the view can link a real TSA's name to their individual
            // performance page (route('tsa-performance.individual')) — except when this
            // IS the 'unassigned' bucket (2026-08-11: now rendered as a real row, see
            // index()'s flat-summary comment), which the blade views specifically check
            // for by value to skip that link (no real tsa_shifts row exists for it, so
            // linking would 404) while still letting cell drilldowns resolve it (
            // drilldown() already has an explicit 'unassigned' case).
            'tsa_key'                => $key,
            'total'                  => $orders->count(),
            'confirmed_via_call'     => $this->count($nonUpsell, 'confirmed via call'),
            'upsell_confirmation'    => $orders->filter($isRealUpsell)->count(),
            'call_back'              => $this->count($nonUpsell, 'call back'),
            'call_dropped'           => $this->count($nonUpsell, 'call dropped'),
            'repeat_order_upsell'    => $this->count($nonUpsell, 'repeat order'),
            'rude_customer'          => $this->count($nonUpsell, 'rude customer'),
            'relatives_confirmation' => $this->count($nonUpsell, 'relatives'),
            'dfr'                    => $this->count($nonUpsell, 'dfr'),
            'double_order'           => $this->count($nonUpsell, 'double order'),
            'fsd_uncleared'          => $this->count($nonUpsell, 'fsd'),
            'not_answering'          => $this->count($nonUpsell, 'not answering'),
            'unattended'             => $this->count($nonUpsell, 'unattended'),
            'invalid_number'         => $this->count($nonUpsell, 'invalid number'),
        ];

        $row['answered'] = $row['confirmed_via_call'] + $row['upsell_confirmation'] + $row['call_back']
            + $row['call_dropped'] + $row['repeat_order_upsell'] + $row['rude_customer'] + $row['relatives_confirmation'];
        $row['unanswered'] = $row['dfr'] + $row['double_order'] + $row['fsd_uncleared']
            + $row['not_answering'] + $row['unattended'] + $row['invalid_number'];

        // "Total Called Leads" — the single column shown in the hourly view: Answered
        // + Unanswered, i.e. every lead that was actually called.
        $row['total_called'] = $row['answered'] + $row['unanswered'];

        // Catered = Answered + Unanswered (total_called); Excess = Total - Catered.
        // Kept in sync by hand with the identical formula on ProductPerformance::
        // tally() — see that one for the full reasoning. Order::EXCESS_DISPOSITIONS
        // is no longer read here at all; a mid-call/not-yet-dispositioned lead now
        // falls into Excess rather than Catered, same as the shared version.
        $row['catered'] = $row['total_called'];
        $row['excess']  = $row['total'] - $row['catered'];

        // Pick-up/Conversion/Upselling Rate — same shared formula as the ALL view and
        // Leads Report (ProductPerformance::rates()), so the hourly breakdown can't
        // drift from how these are defined everywhere else in the app.
        $row = array_merge($row, $this->productRates($row));

        // AOV — same definition as the Dashboard's own AOV card (gross upsell
        // revenue ÷ upsell order count, "average per upsell order"), scoped to
        // just this TSA/slice's own upsell orders instead of the whole company's.
        // Order::realUpsellAmount(), not a bare 'amount' — a Restocking-status
        // row recovered by $isRealUpsell's tag-fallback branch has 'amount'
        // sitting at the raw order total, not the isolated add-on (see that
        // method's own doc comment, fixed 2026-08-13, same bug as
        // ProductPerformance::tally()'s identical sum).
        $row['upsell_sales'] = (float) $orders->filter($isRealUpsell)->sum(fn (Order $o) => $o->realUpsellAmount());
        $row['aov'] = $row['upsell_confirmation'] > 0 ? $row['upsell_sales'] / $row['upsell_confirmation'] : 0.0;

        return $row;
    }

    private function count(Collection $orders, string $keyword): int
    {
        return $this->countAny($orders, [$keyword]);
    }

    /** True if disposition matches ANY of the given keywords (case-insensitive substring). */
    private function countAny(Collection $orders, array $keywords): int
    {
        // Fix #10: disposition is stored as the raw tag text, which for SH Naturals'
        // "RELATIVE'S CONFIRMATION-<product>" tags includes an apostrophe that would
        // otherwise break this substring match against the apostrophe-free keyword.
        return $orders->filter(function ($o) use ($keywords) {
            $disposition = str_replace("'", '', $o->disposition ?? '');
            foreach ($keywords as $kw) {
                if (stripos($disposition, $kw) !== false) return true;
            }
            return false;
        })->count();
    }
}
