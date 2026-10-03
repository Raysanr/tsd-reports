<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TsaSalesEntry;
use App\Models\TsaShift;
use App\Support\DateRangeFilter;
use App\Support\ProductPerformance;
use App\Support\TsaSalesCalculator;
use App\Support\Teams;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TSD Data Management — Summary Sales Report (explicit request, 2026-09-24:
 * "add page summary sales report in data management like this in the
 * sheets ... i want to make it like auto based on the current team and
 * tsa"). TSA rows are the app's own real TsaShift records, grouped by
 * TsaShift.team (SH Naturals / Eyecare — the app's real 2 teams), NOT the
 * sheet's own Opening Shift / Closing Shift split, which has no real
 * backing data yet (shift_start/shift_end are null for every current
 * TSA — confirmed live before this decision was made). No admin-managed
 * add/rename/remove UI anymore — the roster IS TsaShift, managed via TSA
 * Management like everywhere else in this app.
 *
 * Same single-filterable-date-range + 7-day-chunked-tables shape as
 * DsPprReportController, for the same reasons (see that controller's own
 * doc comment).
 *
 * Total Orders, Catered Leads, Pick-up Rate and Upselling Rate are NOT
 * manual entry (explicit request, 2026-10-03: "i want to make this
 * automated that is the data is from TSD LEADS REPORT - TSA PERFORMANCE
 * PAGE", confirmed read-only/fully-automated, then "the total orders is
 * confirmation w/ upsell data" — Total Orders = upsell_confirmation, not
 * a plain order count) — all 4 are computed fresh per TSA per day from
 * the exact same ProductPerformance::tally() the TSA Performance page
 * itself uses, via perTsaPerDayPerformance() below, and OVERRIDE
 * whatever is stored on TsaSalesEntry for these columns (those columns
 * stay on the model/migration for now — not worth a destructive migration
 * for a report page — but are no longer written to or read from). Gross
 * Sales/Net Income/Ads Spent remain manual entry; AOV stays derived from
 * the now-automated Total Orders via TsaSalesCalculator::derive().
 */
class TsaSalesReportController extends Controller
{
    /** Default shape for a TSA/date with no real orders at all — used
     *  anywhere $performanceByKey might miss a key. */
    private const EMPTY_AUTO_FIELDS = [
        'total_orders' => 0, 'catered_leads' => 0, 'pickup_rate' => 0, 'upselling_rate' => 0,
        'upsell_sales' => 0, 'upsell_confirmation' => 0,
    ];

    /** Every TSA's per-day Total Orders/Catered Leads/Pick-up Rate/
     *  Upselling Rate for $dateFrom..$dateTo, keyed "tsaId:date" — same
     *  shape TsaPerformanceController's own $ordersByTsaNameAcrossTeams
     *  produces (credit a TSA by tsa_name across EVERY team's orders, not
     *  just her own team's Order.team column, since a TSA can close a
     *  lead that landed under a different team — see that controller's
     *  own doc comment, 2026-09-07, for the full reasoning this reuses
     *  verbatim). Scoped here to one day at a time (DATE(), not BETWEEN)
     *  so each day's own entry row gets that day's own tally, not the
     *  whole range's.
     */
    private function perTsaPerDayPerformance(Collection $tsas, string $dateFrom, string $dateTo): Collection
    {
        // whereDate() twice (>= and <=) rather than a single BETWEEN —
        // same lexicographic-string-comparison SQLite bug called out
        // throughout this codebase (see index()'s own whereDate() calls
        // below) applies here too once COALESCE is involved.
        $orders = Order::whereRaw(
            "DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?",
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsas->pluck('tsa_key'))->get();

        $byTsaAndDate = $orders->groupBy(fn (Order $o) => $o->tsa_name . ':' . Carbon::parse(
            $o->pancake_inserted_at ?? $o->pancake_created_at
        )->toDateString());

        $tsaKeyToId = $tsas->pluck('id', 'tsa_key');

        return $byTsaAndDate->mapWithKeys(function (Collection $dayOrders, string $groupKey) use ($tsaKeyToId) {
            [$tsaKey, $date] = explode(':', $groupKey, 2);
            $tsaId = $tsaKeyToId->get($tsaKey);
            if ($tsaId === null) return [];

            $tally = ProductPerformance::tally($dayOrders);

            return [$tsaId . ':' . $date => [
                // Explicit confirmation, 2026-10-03: "the total orders is
                // confirmation w/ upsell data" — upsell_confirmation, not
                // $tally['total'] (which would include every non-upsell
                // disposition too, overcounting what this column means
                // on this sheet).
                'total_orders'   => $tally['upsell_confirmation'],
                'catered_leads'  => $tally['catered'],
                // tally()/rates() returns these as a 0-100 percentage (or
                // null when there were no calls) — TsaSalesEntry's own
                // pickup_rate/upselling_rate columns and this page's
                // $fmtPct/parsePercentInput convention both store a 0-1
                // fraction (see updateEntry()'s own validation, 'max:1'),
                // so divide by 100 here, once, at the source.
                'pickup_rate'    => ($tally['pick_up_rate'] ?? 0) / 100,
                'upselling_rate' => ($tally['upselling_rate'] ?? 0) / 100,
                // AOV override inputs — see index()'s own $withAov,
                // 2026-10-03: "also automate AOV like TSA Performance's"
                // (explicit follow-up after noticing AOV stayed 0.00
                // while Total Orders was already real — AOV was still
                // Gross Sales ÷ Total Orders, and Gross Sales is still
                // manual entry). Same formula as
                // TsaPerformanceController::buildRow()'s own AOV:
                // upsell_sales ÷ upsell_confirmation, real tracked
                // upsell revenue per upsell order, nothing to do with
                // the manually-typed Gross Sales figure.
                'upsell_sales'        => $tally['upsell_sales'],
                'upsell_confirmation' => $tally['upsell_confirmation'],
            ]];
        });
    }

    /** AOV = upsell_sales ÷ upsell_confirmation — same formula as
     *  TsaPerformanceController::buildRow()'s own AOV, fully automated
     *  (explicit request, 2026-10-03). Overrides whatever
     *  TsaSalesCalculator::derive()/sum() computed for 'aov' from
     *  Gross Sales ÷ Total Orders — that formula stays correct and
     *  unit-tested against the real source sheet for every OTHER
     *  caller of TsaSalesCalculator, so it's overridden here at the
     *  call site instead of changed at its source. */
    private function withAutomatedAov(array $derived, array $rawRows): array
    {
        $upsellSales = array_sum(array_column($rawRows, 'upsell_sales'));
        $upsellConfirmation = array_sum(array_column($rawRows, 'upsell_confirmation'));
        $derived['aov'] = $upsellConfirmation > 0 ? $upsellSales / $upsellConfirmation : 0.0;
        // Carried forward (not just consumed) so a CALLER one level up
        // (group total summing several TSAs' own 'derived' rows, or the
        // overall total summing every group) can re-sum these two and
        // recompute ITS OWN aov fresh from the totals, same "recompute
        // the ratio from summed raw numbers, never average a ratio"
        // convention TsaSalesCalculator::sum() already uses for
        // ni_pct/aov.
        $derived['upsell_sales'] = $upsellSales;
        $derived['upsell_confirmation'] = $upsellConfirmation;
        return $derived;
    }

    public function index(Request $request)
    {
        // See DateRangeFilter's own doc comment — remembers the last range
        // picked on THIS page across separate visits, defaulting to "today
        // only" on a brand-new session (changed 2026-09-30 from "this
        // week" — explicit decision to make every report page consistent).
        $range = DateRangeFilter::resolve($request, 'tsa-sales');
        $dateFrom = $range['from'];
        $dateTo   = $range['to'];

        // Explicit request, 2026-09-28: "make the opening is in first and
        // the closing is in last" — config('teams') itself lists
        // 'sh-naturals' before 'eyecare' (confirmed live: those slugs are
        // currently DISPLAYED as "Team Closing"/"Team Opening" via the
        // editable team-name-history feature — see Teams::nameFor()'s own
        // doc comment for why the slug and its shown label are two
        // separate things). Reordered HERE, scoped to this one page only —
        // the shared config itself stays untouched, so every other page
        // that reads Teams::config() (Call Tracker, Dashboard, Leads
        // Report, Settings, etc.) keeps its own existing order, per
        // explicit confirmation this shouldn't change app-wide. Sorted by
        // the fixed slug, never the editable display label, so a future
        // rename can never silently break this ordering.
        $teamOrder = ['eyecare', 'sh-naturals'];
        $teams = collect(Teams::config())
            ->sortBy(fn ($team, $slug) => array_search($slug, $teamOrder, true) ?? PHP_INT_MAX);
        $tsas  = TsaShift::orderBy('sort_order')->get();

        // whereDate() >=/<=, not a raw whereBetween() on the date-cast
        // column — same SQLite lexicographic-comparison bug found and
        // fixed 2026-09-26 in DsPprReportController/ExpectedIncomeController
        // (a plain whereBetween() bound silently drops the LAST day of any
        // selected range, since '2026-09-26 00:00:00' sorts after the bare
        // '2026-09-26' bound). whereDate() correctly extracts just the
        // date part on every driver (SQLite included).
        // daysUntil() is already INCLUSIVE of its own end date — see the
        // identical fix in DsPprReportController's own doc comment for the
        // full root cause (confirmed 2026-09-27: picking "To: Sep 30" was
        // silently rendering an extra Oct 1 column). Needed here (moved
        // up from its old spot below $overallTotal) to build $dailyByKey
        // for every date in the range, not just dates with a saved entry.
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));
        $dateChunks = $dates->chunk(7)->values();

        $rawEntriesByKey = TsaSalesEntry::whereIn('tsa_shift_id', $tsas->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (TsaSalesEntry $e) => $e->tsa_shift_id . ':' . $e->entry_date->toDateString());

        // "tsaId:date" -> ['total_orders' => ..., 'catered_leads' => ...,
        // 'pickup_rate' => ..., 'upselling_rate' => ...], overriding
        // whatever TsaSalesEntry itself has stored for these 4 columns —
        // see perTsaPerDayPerformance()'s own doc comment.
        $performanceByKey = $this->perTsaPerDayPerformance($tsas, $dateFrom, $dateTo);

        // Per-day entries, keyed "tsaId:date" — same convention as
        // DsPprReportController's own $dailyByKey, built for EVERY
        // TSA/date in the selected range (not just dates with a saved
        // TsaSalesEntry row) so a TSA with real orders but no manual
        // entry ever typed in for that day still shows her real
        // automated figures instead of silently falling through to all-
        // zeros — this exact gap was caught live via Playwright,
        // 2026-10-03, after the entries-only version first shipped.
        $emptyManualFields = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0];
        $dailyByKey = collect();
        foreach ($tsas as $tsa) {
            foreach ($dates as $date) {
                $key = $tsa->id . ':' . $date->toDateString();
                $manual = $rawEntriesByKey->has($key) ? $rawEntriesByKey->get($key)->toArray() : $emptyManualFields;
                $auto = $performanceByKey->get($key, self::EMPTY_AUTO_FIELDS);
                $dailyByKey->put($key, array_merge($manual, $auto));
            }
        }

        // One row per TSA, summed across the whole selected range,
        // grouped by their real team — the sheet's own MTD running
        // total is the same idea, just always MTD there where this page
        // lets any range be picked. Sums every date in range via
        // $dailyByKey (built above), not just dates with a saved entry —
        // same reasoning as $dailyByKey's own doc comment.
        $groupSummaries = $teams->map(function (array $team, string $slug) use ($tsas, $dates, $dailyByKey) {
            $teamTsas = $tsas->where('team', $team['order_team'] ?? '__none__')->values();

            $rowSummaries = $teamTsas->map(function (TsaShift $tsa) use ($dates, $dailyByKey) {
                $tsaRows = $dates->map(fn ($date) => $dailyByKey->get($tsa->id . ':' . $date->toDateString()))->all();
                return [
                    'tsa'     => $tsa,
                    'derived' => $this->withAutomatedAov(TsaSalesCalculator::sum($tsaRows), $tsaRows),
                ];
            });

            return [
                'label'      => $team['name'] ?? $slug,
                'tsas'       => $teamTsas,
                'rows'       => $rowSummaries,
                'groupTotal' => $this->withAutomatedAov(
                    TsaSalesCalculator::sum($rowSummaries->pluck('derived')->all()),
                    $rowSummaries->pluck('derived')->all()
                ),
            ];
        })->values();

        $overallRows = $groupSummaries->pluck('rows')->flatten(1)->pluck('derived')->all();
        $overallTotal = $this->withAutomatedAov(TsaSalesCalculator::sum($overallRows), $overallRows);

        return view('data.tsa-sales', [
            'groupSummaries' => $groupSummaries,
            'overallTotal'   => $overallTotal,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'dateChunks'     => $dateChunks,
            'dailyByKey'     => $dailyByKey,
        ]);
    }

    /** Auto-save, one (TSA, date, field) at a time — same
     *  upsert-via-whereDate() convention as DsPprReportController::update()
     *  (see that method's own doc comment for why whereDate(), not a bare
     *  attribute match, is required on SQLite). */
    public function updateEntry(Request $request, TsaShift $tsaShift, string $date)
    {
        // Total Orders/Catered Leads/Pick-up Rate/Upselling Rate are no
        // longer accepted here — see this class's own doc comment, 2026-
        // 10-03: they're fully automated from TSA Performance's own data
        // now, not manual entry.
        $data = $request->validate([
            'gross_sales' => ['sometimes', 'numeric'],
            'net_income'  => ['sometimes', 'numeric'],
            'ads_spent'   => ['sometimes', 'numeric', 'min:0'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        $entry = TsaSalesEntry::where('tsa_shift_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first()
            ?? new TsaSalesEntry(['tsa_shift_id' => $tsaShift->id, 'entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        $performance = $this->perTsaPerDayPerformance(collect([$tsaShift]), $entryDate, $entryDate)
            ->get($tsaShift->id . ':' . $entryDate, self::EMPTY_AUTO_FIELDS);

        $raw = array_merge($entry->toArray(), $performance);
        $derived = $this->withAutomatedAov(TsaSalesCalculator::derive($raw), [$raw]);

        return response()->json([
            'success' => true,
            'derived' => $derived,
        ]);
    }
}
