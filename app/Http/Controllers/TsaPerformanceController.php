<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\HourFormatter;
use App\Support\ProductPerformance;
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

        // All orders for the selected date: either assigned to a TSA on this team's
        // roster, OR never claimed by any TSA at all but still attributable to this
        // team by product (Fix #15 in SyncTodayOrders — a lead can have a real product
        // in its cart with no TSA tag at all, e.g. swept by the midnight "UNCATERED
        // LEADS" bulk action before anyone touched it; `whereIn(...)` alone would
        // silently drop these since SQL IN never matches NULL, hiding them from every
        // team's report entirely instead of counting them as that team's Excess).
        // whereBetween (not whereDate) keeps the query sargable against the pancake_created_at index.
        $orders = Order::whereBetween('pancake_created_at', [$from, $to])
            ->where(function ($q) use ($shifts, $teamsConfig, $selectedTeam) {
                $q->whereIn('tsa_name', $shifts->keys())
                  ->orWhere(function ($q2) use ($teamsConfig, $selectedTeam) {
                      $q2->whereNull('tsa_name')->where('team', $teamsConfig[$selectedTeam]['order_team']);
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

            // Never-claimed-by-any-TSA orders (see the query comment above) — still
            // folded into the hour/Grand Total row so this team's Excess isn't silently
            // absorbed into nothing, but no longer rendered as its own visible "Unassigned"
            // row (removed per request — the Excess Leads column that row existed to
            // surface is no longer shown in this view either, so a row with nothing
            // visible in any column was just noise).
            $unassignedOrders = $ordersByTsa->get('__unassigned__', collect());
            if ($unassignedOrders->isNotEmpty()) {
                $row = $this->buildRow(null, 'unassigned', $unassignedOrders, 'Unassigned');

                foreach (self::COLUMNS as $col) {
                    $blockTotals[$col] += $row[$col];
                    $totals[$col]      += $row[$col];
                }
            }

            // Pick-up/Conversion/Upselling Rate for this hour block — same shared
            // formula as buildRow()'s per-row rates and the ALL view, merged flat
            // alongside 'label'/'rows'/'totals' so the blade reads $block['pick_up_rate']
            // etc. the same way it already reads $row['pick_up_rate'].
            $hourBlocks[] = array_merge([
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
            'availableProducts', 'selectedProduct'
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

        $orders = Order::where('tsa_name', $tsaKey)
            ->whereBetween('pancake_created_at', [$from, $to])
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

            $hourlyRows[] = [
                'label' => HourFormatter::rangeLabel($hour),
                'row'   => $this->buildRow($shift, $tsaKey, $hourOrders),
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
        $productHourlyRows = [];
        $productTotals     = $products->mapWithKeys(fn($p) => [$p->id => 0]);
        $grandRowTotal      = 0;
        $grandTsaLeads      = 0;
        $grandAnswered      = 0;
        $grandUnanswered    = 0;
        $grandOpt           = 0;
        for ($hour = self::START_HOUR; $hour <= self::END_HOUR; $hour++) {
            $hourOrders = $ordersByHour->get($hour, collect());
            if ($hourOrders->isEmpty()) continue;

            // 'catered' (== total_called), not 'total' — this column is labeled
            // "Total Catered Leads" and must match tsa_leads/total_called below.
            // 'total' counts every matching lead including ones with no worked
            // disposition yet, which let this column read higher than the actual
            // called-leads count for the same hour (confirmed live: a lead still
            // sitting New/un-called inflated the per-product sum by 1 past the
            // real total called that hour).
            $counts   = $products->mapWithKeys(function ($product) use ($hourOrders, $products) {
                return [$product->id => ProductPerformance::buildRow($product, $hourOrders, $products)['catered']];
            });
            $rowTotal = $counts->sum();

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

            // OPT and Unproductive Time — reverse-engineered from the source sheet's
            // own numbers (verified against 7 real rows, exact match every time):
            // OPT assumes 3 minutes per answered call; Unproductive Time is whatever's
            // left of the hour after OPT and one minute per unanswered call. AHT has
            // no formula — it's blank in every row of the source sheet too (no call-
            // duration data exists anywhere to compute it from), so it stays blank here.
            $opt          = $answered * 3;
            $unproductive = max(0, 60 - $opt - $unanswered);

            $productTotals    = $productTotals->map(fn($v, $id) => $v + $counts[$id]);
            $grandRowTotal   += $rowTotal;
            $grandTsaLeads   += $hourRow['total_called'];
            $grandAnswered   += $answered;
            $grandUnanswered += $unanswered;
            $grandOpt        += $opt;

            $productHourlyRows[] = [
                'label'       => HourFormatter::rangeLabel($hour),
                'counts'      => $counts,
                'row_total'   => $rowTotal,
                'tsa_leads'   => $hourRow['total_called'],
                'answered'    => $answered,
                'unanswered'  => $unanswered,
                'opt'         => $opt,
                'unproductive'=> $unproductive,
            ];
        }

        return view('tsa-performance-individual', [
            'dateFrom'          => $dateFrom,
            'dateTo'            => $dateTo,
            'products'          => $products,
            'productHourlyRows' => $productHourlyRows,
            'productTotals'     => $productTotals,
            'grandRowTotal'     => $grandRowTotal,
            'grandTsaLeads'     => $grandTsaLeads,
            'grandAnswered'     => $grandAnswered,
            'grandUnanswered'   => $grandUnanswered,
            'grandOpt'          => $grandOpt,
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

        $orders = Order::whereBetween('pancake_created_at', [$from, $to])
            ->whereIn('team', $orderTeams)
            ->get();

        // Every TSA across every team, sorted team-then-sort_order — same convention
        // as the product ALL view this replaces (orderBy('team') alone would sort
        // alphabetically, wrongly putting Eyecare before SH Naturals).
        $shifts = TsaShift::whereIn('team', $orderTeams)->orderBy('sort_order')->get()
            ->sortBy(fn($s) => array_search($s->team, $orderTeams))
            ->values();

        $ordersByTsa = $orders->groupBy(fn($o) => $o->tsa_name ?? '__unassigned__');

        $tsaRows = $shifts->map(function ($shift) use ($ordersByTsa, $teamsConfig, $teamKeyByOrderTeam) {
            $row     = ProductPerformance::tally($ordersByTsa->get($shift->tsa_key, collect()));
            $teamKey = $teamKeyByOrderTeam[$shift->team] ?? null;

            $row['display_name'] = $shift->display_name;
            $row['team']         = $teamKey ? $teamsConfig[$teamKey]['name'] : $shift->team;
            $row['team_key']     = $teamKey;
            $row['tsa_key']      = $shift->tsa_key;

            return $row;
        })->values();

        // Grand Total — scoped to orders actually claimed by one of the TSAs shown
        // above (not every order in range), so this number always equals the sum of
        // the visible rows instead of silently running higher with no row to explain
        // the gap. This deliberately does NOT count leads with no TSA at all — that's
        // a real choice (they're excluded from this page's total entirely, not just
        // hidden), made after trying a visible "Unassigned" row and being asked to
        // remove it in favor of this instead.
        $grandTotal = ProductPerformance::tally(
            $orders->whereIn('tsa_name', $shifts->pluck('tsa_key'))
        );

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
        // Same "real upsell" definition and non-upsell exclusivity guard as
        // ProductPerformance::tally() — kept in sync by hand since this per-TSA
        // breakdown has its own separate accumulator. Without the $nonUpsell filter
        // here (missing until now), an upsell order that also carried a stale
        // disposition tag double-counted into both its disposition column AND
        // Upsell w/ Confirmation.
        $isRealUpsell = fn($o) => $o->is_upsell
            || $o->is_returned_upsell
            || (!$o->is_cancelled_upsell && Order::hasUpsellTag($o->raw_tags ?? []));
        $nonUpsell = $orders->reject($isRealUpsell);

        $row = [
            'display_name'           => $displayNameOverride ?? $shift?->display_name ?? ucfirst($key),
            // Carried through so the view can link a real TSA's name to their individual
            // performance page (route('tsa-performance.individual')). Null for rows with
            // no real key (there are none rendered currently, but keeps this safe if one
            // is ever added back).
            'tsa_key'                => $shift ? $key : null,
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
