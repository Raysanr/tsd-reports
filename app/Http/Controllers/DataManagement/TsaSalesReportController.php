<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TsaSalesEntry;
use App\Models\TsaSalesLockedDate;
use App\Models\TsaShift;
use App\Models\TsaTiktokEntry;
use App\Support\ActivityLogger;
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
 * for a report page — but are no longer written to or read from). AOV
 * stays derived from the now-automated Total Orders via
 * TsaSalesCalculator::derive().
 *
 * Gross Sales and Net Income are ALSO no longer manual entry (explicit
 * request, 2026-10-10: "i want you to make the Data Management module is
 * automated / the gross sales and net income / the net income the basis
 * is in the expected income page / and the gross sales is the basis is
 * the assignee tsa of items"). Gross Sales = upsell_sales, the same real
 * per-item-assignee-attributed revenue perTsaPerDayPerformance() already
 * computes for AOV (ProductPerformance::tally()['upsell_sales'] — traces
 * back to Pancake's own per-item assigning_seller via
 * SyncTodayOrders::extractTsaInfo(), the "assignee TSA of items" the
 * request names; this app has no broader "full order amount by assignee"
 * figure anywhere, confirmed before building this, so the existing
 * upsell-attribution figure is the real one, not a new one). Net Income =
 * ExpectedIncomeController::netIncomeByTsaAndDate()'s own figure for that
 * TSA/day — the exact same number her read-only "[TSA NAME]" overview row
 * already shows on the Expected Income page itself (her own product
 * rows, summed, Operating Costs/Tax Allocation overridden to Cost
 * Breakdown's real per-TSA figures), not a second, independently-derived
 * P&L. Both columns are now plain read-only [data-out] cells, same
 * pattern as Total Orders/Pick-up Rate above — TsaSalesEntry.gross_sales/
 * net_income stay on the model/migration for history only, no longer
 * written to or read from for a real team's own rows. TikTok Upsell's own
 * section is UNCHANGED — still fully manual (no Expected Income/Pancake
 * assignee data backs that roster), same confirmed scope boundary every
 * earlier automation on this page already drew.
 */
class TsaSalesReportController extends Controller
{
    /** Default shape for a TSA/date with no real orders at all — used
     *  anywhere $performanceByKey might miss a key. */
    private const EMPTY_AUTO_FIELDS = [
        'total_orders' => 0, 'catered_leads' => 0, 'pickup_rate' => 0, 'upselling_rate' => 0,
        'upsell_sales' => 0, 'upsell_confirmation' => 0,
        'answered' => 0, 'unanswered' => 0, 'confirmed_via_call' => 0,
        'gross_sales' => 0.0, 'net_income' => 0.0,
    ];

    /** Every TSA's per-day Total Orders/Catered Leads/Pick-up Rate/
     *  Upselling Rate/Gross Sales/Net Income for $dateFrom..$dateTo, keyed
     *  "tsaId:date" — same shape TsaPerformanceController's own
     *  $ordersByTsaNameAcrossTeams produces (credit a TSA by tsa_name
     *  across EVERY team's orders, not just her own team's Order.team
     *  column, since a TSA can close a lead that landed under a different
     *  team — see that controller's own doc comment, 2026-09-07, for the
     *  full reasoning this reuses verbatim). Scoped here to one day at a
     *  time (DATE(), not BETWEEN) so each day's own entry row gets that
     *  day's own tally, not the whole range's.
     *
     *  Gross Sales/Net Income added 2026-10-10 — see this class's own doc
     *  comment above for the full reasoning. Net Income is looked up ONCE
     *  for the whole range via ExpectedIncomeController::
     *  netIncomeByTsaAndDate() (not per-TSA-per-day here) since that
     *  method already loops every date/TSA/product internally the same
     *  way this method's own $orders query does.
     */
    private function perTsaPerDayPerformance(Collection $tsas, string $dateFrom, string $dateTo): Collection
    {
        $netIncomeByKey = ExpectedIncomeController::netIncomeByTsaAndDate($dateFrom, $dateTo);

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

        $performance = $byTsaAndDate->mapWithKeys(function (Collection $dayOrders, string $groupKey) use ($tsaKeyToId, $netIncomeByKey) {
            [$tsaKey, $date] = explode(':', $groupKey, 2);
            $tsaId = $tsaKeyToId->get($tsaKey);
            if ($tsaId === null) return [];

            $tally = ProductPerformance::tally($dayOrders);
            $key = $tsaId . ':' . $date;

            return [$key => [
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
                //
                // Gross Sales (2026-10-10) reuses this exact figure — see
                // this class's own doc comment above for why: the only
                // real "revenue attributed to this TSA via Pancake's
                // per-item assignee" figure anywhere in this app.
                'upsell_sales'        => $tally['upsell_sales'],
                'gross_sales'         => $tally['upsell_sales'],
                'upsell_confirmation' => $tally['upsell_confirmation'],
                // Net Income (2026-10-10) — looked up from the
                // independent $netIncomeByKey map, NOT derived from this
                // day's own $tally — a TSA can have a real Expected Income
                // Net Income (her own Salaries/Operating Costs apply
                // whether or not she sold anything that day, same
                // "staffed regardless of sales" rule
                // addActiveTsasOverviewOperatingCosts() already
                // documents) independent of whether she has any orders
                // today at all. See the union merge below this map for
                // the TSA/date combinations that have Net Income but NO
                // matching order-based key here at all.
                'net_income' => $netIncomeByKey->get($key, 0.0),
                // Raw tally() counts (added 2026-10-09, same fix as
                // ProductPerformance::dsPprRow()'s own identical addition
                // same day) — needed so index()'s own $overallTotal/group
                // totals can recompute Pick-up/Upselling Rate from SUMMED
                // counts instead of TsaSalesCalculator::sum()'s own plain
                // per-row average. See index()'s own doc comment on
                // $overallTotal for why: these 2 fields were automated
                // from real tally() data 2026-10-03, but the averaging
                // convention justified when they were still manual
                // (TsaSalesCalculator::sum()'s own doc comment — "neither
                // has a summed denominator to recompute against") was
                // never updated to match, causing a large, confirmed-live
                // mismatch against TSA Performance's own Grand Total
                // (44.65% vs the real 63.6%).
                'answered'           => $tally['answered'],
                'unanswered'         => $tally['unanswered'],
                'confirmed_via_call' => $tally['confirmed_via_call'],
            ]];
        });

        // Union in every TSA/date that has a real Expected Income Net
        // Income figure but NO key above at all (no orders that day) —
        // same "missing entry ≠ zero" gap this whole module keeps hitting
        // whenever a field moves from manual to automated (see
        // leadCountsByProductAndDate()'s/totalRealLeads()'s own 2026-10-09
        // doc comments on ExpectedIncomeController for the identical class
        // of bug). Without this, a TSA fully staffed but with zero orders
        // today would silently show 0.00 Net Income here instead of her
        // real (possibly negative, from Salaries/Operating Costs with no
        // Gross Sales to offset them) Expected Income figure.
        foreach ($netIncomeByKey as $key => $netIncome) {
            if ($performance->has($key)) continue;
            $performance->put($key, array_merge(self::EMPTY_AUTO_FIELDS, ['net_income' => $netIncome]));
        }

        return $performance;
    }

    /** AOV = upsell_sales ÷ upsell_confirmation — same formula as
     *  TsaPerformanceController::buildRow()'s own AOV, fully automated
     *  (explicit request, 2026-10-03). Overrides whatever
     *  TsaSalesCalculator::derive()/sum() computed for 'aov' from
     *  Gross Sales ÷ Total Orders — that formula stays correct and
     *  unit-tested against the real source sheet for every OTHER
     *  caller of TsaSalesCalculator, so it's overridden here at the
     *  call site instead of changed at its source.
     *
     *  ALSO overrides Pick-up Rate/Upselling Rate the same way, added
     *  2026-10-09 (root-caused live, screenshot: Summary Sales Report's
     *  own OVERALL TOTAL showed Pick-up 44.65%/Upselling 44.35% while TSA
     *  Performance's own Grand Total — the exact same underlying data,
     *  same range — showed 63.6%/64.1%). TsaSalesCalculator::sum()'s own
     *  averaging of these 2 fields was a deliberate, correct design
     *  choice back when they were raw manual entry with no underlying
     *  count columns (see that method's own doc comment) — but they were
     *  automated from real ProductPerformance::tally() data 2026-10-03,
     *  and the averaging convention was never updated to match. Real
     *  counts now exist (answered/unanswered/confirmed_via_call, added to
     *  perTsaPerDayPerformance()'s own output same day as this fix), so
     *  this method now recomputes both rates from SUMMED counts via
     *  ProductPerformance::rates() instead — same "recompute the ratio
     *  from summed raw numbers, never average a ratio" convention every
     *  OTHER field on this page already follows, and the exact same fix
     *  already applied to DsPprReportController's own identical bug,
     *  same session. The TikTok Upsell section's own group total is
     *  DELIBERATELY NOT routed through this method (see index()'s own
     *  $tiktokRowSummaries/$tiktokSummary) — every one of its fields,
     *  Pick-up/Upselling Rate included, is still genuinely manual with no
     *  tally() data behind it, so TsaSalesCalculator::sum()'s own
     *  averaging remains the correct, unchanged behavior there. */
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

        $rawCountTotals = [
            'answered'           => array_sum(array_column($rawRows, 'answered')),
            'unanswered'         => array_sum(array_column($rawRows, 'unanswered')),
            'confirmed_via_call' => array_sum(array_column($rawRows, 'confirmed_via_call')),
            'upsell_confirmation' => $upsellConfirmation,
        ];
        $rates = ProductPerformance::rates($rawCountTotals);
        $derived['pickup_rate']    = $rates['pick_up_rate'] !== null ? $rates['pick_up_rate'] / 100 : 0.0;
        $derived['upselling_rate'] = $rates['upselling_rate'] !== null ? $rates['upselling_rate'] / 100 : 0.0;
        // Carried forward too, same reason as upsell_sales/
        // upsell_confirmation above — a caller one level up re-sums these
        // raw counts from this row's own 'derived' array rather than
        // needing its own separate $rawRows.
        $derived['answered'] = $rawCountTotals['answered'];
        $derived['unanswered'] = $rawCountTotals['unanswered'];
        $derived['confirmed_via_call'] = $rawCountTotals['confirmed_via_call'];

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

        // Real team-level pooled Net Income per date, keyed "orderTeam:date"
        // — the exact figure Expected Income's own "TELESALES" card shows
        // for one real team (ExpectedIncomeController::
        // teamNetIncomeByDate()'s own doc comment has the full story:
        // explicit reversal, 2026-10-10, same day — "i WANT THE NET INCOME
        // SHOULD BE 3,840.30" / "BECAUSE THAT IS THE NET INCOME" — a real
        // POOLED team total, NOT TsaSalesCalculator::sum()'s own sum of
        // individually-costed TSA rows, which double-counts shared
        // Operating Costs/Salaries/Tax Allocation across TSAs).
        $teamNetIncomeByDate = \App\Http\Controllers\DataManagement\ExpectedIncomeController::teamNetIncomeByDate($dateFrom, $dateTo);

        // One row per TSA, summed across the whole selected range,
        // grouped by their real team — the sheet's own MTD running
        // total is the same idea, just always MTD there where this page
        // lets any range be picked. Sums every date in range via
        // $dailyByKey (built above), not just dates with a saved entry —
        // same reasoning as $dailyByKey's own doc comment.
        $groupSummaries = $teams->map(function (array $team, string $slug) use ($tsas, $dates, $dailyByKey, $teamNetIncomeByDate) {
            $teamTsas = $tsas->where('team', $team['order_team'] ?? '__none__')->values();

            $rowSummaries = $teamTsas->map(function (TsaShift $tsa) use ($dates, $dailyByKey) {
                $tsaRows = $dates->map(fn ($date) => $dailyByKey->get($tsa->id . ':' . $date->toDateString()))->all();
                return [
                    'tsa'     => $tsa,
                    'derived' => $this->withAutomatedAov(TsaSalesCalculator::sum($tsaRows), $tsaRows),
                ];
            });

            $groupTotal = $this->withAutomatedAov(
                TsaSalesCalculator::sum($rowSummaries->pluck('derived')->all()),
                $rowSummaries->pluck('derived')->all()
            );
            // Net Income/NI% overridden to the real pooled team total —
            // every OTHER field (Gross Sales, Total Orders, Pick-up Rate,
            // etc.) stays the sum-of-TSAs figure TsaSalesCalculator::sum()
            // already computed above, untouched by this.
            $orderTeam = $team['order_team'] ?? null;
            if ($orderTeam !== null) {
                $pooledNetIncome = (float) $dates->sum(fn ($date) => $teamNetIncomeByDate->get("{$orderTeam}:{$date->toDateString()}", 0.0));
                $groupTotal['net_income'] = $pooledNetIncome;
                $groupTotal['ni_pct'] = $groupTotal['gross_sales'] > 0 ? $pooledNetIncome / $groupTotal['gross_sales'] : 0.0;
            }

            return [
                'label'      => $team['name'] ?? $slug,
                'orderTeam'  => $orderTeam,
                'tsas'       => $teamTsas,
                'rows'       => $rowSummaries,
                'groupTotal' => $groupTotal,
            ];
        })->values();

        // TikTok Upsell — a manually-run section (explicit request,
        // 2026-10-05), entirely separate from the real team system above:
        // its own roster (TsaShift.tiktok_upsell flag, managed via TSA
        // Management, NOT TsaShift.team — see the add_tiktok_upsell_to_
        // tsa_shifts_table migration's own doc comment) and its own raw
        // numbers (TsaTiktokEntry, NOT TsaSalesEntry) — every field here
        // is manual, including Total Orders/Catered Leads/Pick-up Rate/
        // Upselling Rate, which stay automated everywhere else on this
        // page. Reuses TsaSalesCalculator::sum()/derive() as-is since the
        // column shape (gross_sales/net_income/total_orders/
        // catered_leads/pickup_rate/upselling_rate -> ni_pct/aov) is
        // identical — just every input is manual instead of some being
        // derived from Order/ProductPerformance data.
        $tiktokTsas = $tsas->where('tiktok_upsell', true)->values();
        $tiktokRawEntriesByKey = TsaTiktokEntry::whereIn('tsa_shift_id', $tiktokTsas->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (TsaTiktokEntry $e) => $e->tsa_shift_id . ':' . $e->entry_date->toDateString());

        $emptyTiktokFields = ['gross_sales' => 0, 'net_income' => 0, 'total_orders' => 0, 'catered_leads' => 0, 'pickup_rate' => 0, 'upselling_rate' => 0];
        $tiktokDailyByKey = collect();
        foreach ($tiktokTsas as $tsa) {
            foreach ($dates as $date) {
                $key = $tsa->id . ':' . $date->toDateString();
                $tiktokDailyByKey->put($key, $tiktokRawEntriesByKey->has($key)
                    ? $tiktokRawEntriesByKey->get($key)->toArray()
                    : $emptyTiktokFields);
            }
        }

        $tiktokRowSummaries = $tiktokTsas->map(function (TsaShift $tsa) use ($dates, $tiktokDailyByKey) {
            $tsaRows = $dates->map(fn ($date) => $tiktokDailyByKey->get($tsa->id . ':' . $date->toDateString()))->all();
            return ['tsa' => $tsa, 'derived' => TsaSalesCalculator::sum($tsaRows)];
        });

        $tiktokSummary = [
            'label'      => 'TikTok Upsell',
            'tsas'       => $tiktokTsas,
            'rows'       => $tiktokRowSummaries,
            'groupTotal' => TsaSalesCalculator::sum($tiktokRowSummaries->pluck('derived')->all()),
        ];

        // $overallTotal's own Pick-up/Upselling Rate (via withAutomatedAov()
        // below) are recomputed from SUMMED answered/unanswered/
        // confirmed_via_call/upsell_confirmation counts, carried on every
        // REAL team TSA's own 'derived' row — see withAutomatedAov()'s own
        // doc comment. TikTok Upsell's own rows have no such counts (every
        // field there, including Pick-up/Upselling Rate, is still raw
        // manual entry with nothing to decompose into answered/unanswered
        // — unlike DSPPR's own TikTok row, which at least has real
        // total_leads/total_orders counts to approximate from) —
        // array_column() inside withAutomatedAov() simply skips a row
        // missing those keys, so TikTok's own rows are silently excluded
        // from THIS ONE blended percentage specifically (confirmed
        // acceptable scope, 2026-10-09 — TikTok Upsell stays fully
        // separate from the real-team automation everywhere else on this
        // page too). TikTok's Gross Sales/Net Income/Total Orders/Catered
        // Leads still fold into every OTHER field of $overallTotal
        // normally via TsaSalesCalculator::sum() below.
        $overallRows = $groupSummaries->pluck('rows')->flatten(1)->pluck('derived')
            ->merge($tiktokRowSummaries->pluck('derived'))->all();
        $overallTotal = $this->withAutomatedAov(TsaSalesCalculator::sum($overallRows), $overallRows);
        // Net Income/NI% overridden the same way each group's own
        // groupTotal was above — the real SUM of both real teams' own
        // pooled TELESALES figures (not TsaSalesCalculator::sum()'s own
        // sum-of-every-individual-row total, same double-counted-shared-
        // costs issue). TikTok Upsell's own Net Income stays genuinely
        // manual and folds in as a plain addition, same as it already did
        // via $overallRows above.
        $pooledNetIncome = (float) $groupSummaries->sum(fn ($g) => $g['groupTotal']['net_income'])
            + (float) $tiktokRowSummaries->pluck('derived')->sum('net_income');
        $overallTotal['net_income'] = $pooledNetIncome;
        $overallTotal['ni_pct'] = $overallTotal['gross_sales'] > 0 ? $pooledNetIncome / $overallTotal['gross_sales'] : 0.0;

        // Per-date lock (explicit request, 2026-10-07: "add lock icon
        // like in the dsppr") — same set-of-locked-date-strings shape as
        // DsPprReportController::index()'s own $lockedDates, see
        // TsaSalesLockedDate's own doc comment.
        $lockedDates = TsaSalesLockedDate::whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->pluck('entry_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        return view('data.tsa-sales', [
            'groupSummaries' => $groupSummaries,
            'tiktokSummary'  => $tiktokSummary,
            'overallTotal'   => $overallTotal,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'dateChunks'     => $dateChunks,
            'dailyByKey'     => $dailyByKey,
            'tiktokDailyByKey' => $tiktokDailyByKey,
            'lockedDates'    => $lockedDates,
            // Real pooled per-team Net Income, keyed "orderTeam:date" — the
            // daily table's own SEPARATE TOTAL/OVERALL TOTAL rows (built
            // directly in the view from $dailyGroups, not through
            // $groupSummaries' own groupTotal above) need this too, same
            // "two independent rendering paths for the same total" pattern
            // this page has hit before — see ExpectedIncomeController::
            // teamNetIncomeByDate()'s own doc comment for the full story.
            'teamNetIncomeByDate' => $teamNetIncomeByDate,
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
        // now, not manual entry. Gross Sales/Net Income REMOVED 2026-10-10
        // (same reasoning, same pattern as Expected Income's own
        // number_of_leads removal) — both are now fully automated too (see
        // this class's own doc comment above); a stray PATCH for either is
        // now silently dropped rather than overwriting a value nothing on
        // this page reads any more. Every TsaSalesEntry row and its own
        // gross_sales/net_income columns stay on the model/migration for
        // history only.
        $data = $request->validate([
            'ads_spent' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        // Never trust the frontend's own `disabled` attribute alone — a
        // locked date refuses a direct PATCH too (explicit request,
        // 2026-10-07 — same guard DsPprReportController::update() already
        // has).
        if (TsaSalesLockedDate::whereDate('entry_date', $entryDate)->exists()) {
            return response()->json(['success' => false, 'message' => 'This date is locked.'], 422);
        }

        $entry = TsaSalesEntry::where('tsa_shift_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first()
            ?? new TsaSalesEntry(['tsa_shift_id' => $tsaShift->id, 'entry_date' => $entryDate]);
        $old = $entry->only(array_keys($data));
        $entry->fill($data);
        $entry->save();

        $context = $tsaShift->display_name . ' on ' . Carbon::parse($entryDate)->format('M j, Y');
        foreach ($data as $field => $newValue) {
            if ((float) ($old[$field] ?? 0) == (float) $newValue) continue;
            ActivityLogger::logFieldUpdate('tsa_sales.field_updated', $entry, $field, $old[$field] ?? 0, $newValue, $context, fn ($v) => number_format((float) $v, 2));
        }

        $performance = $this->perTsaPerDayPerformance(collect([$tsaShift]), $entryDate, $entryDate)
            ->get($tsaShift->id . ':' . $entryDate, self::EMPTY_AUTO_FIELDS);

        $raw = array_merge($entry->toArray(), $performance);
        $derived = $this->withAutomatedAov(TsaSalesCalculator::derive($raw), [$raw]);

        return response()->json([
            'success' => true,
            'derived' => $derived,
        ]);
    }

    /** Same auto-save shape as updateEntry() above, for the TikTok Upsell
     *  section's own TsaTiktokEntry — every field here is manual entry
     *  (see create_tsa_tiktok_entries_table migration's own doc comment),
     *  so unlike updateEntry() this accepts Total Orders/Catered Leads/
     *  Pick-up Rate/Upselling Rate directly instead of deriving them from
     *  TSA Performance data. */
    public function updateTiktokEntry(Request $request, TsaShift $tsaShift, string $date)
    {
        $data = $request->validate([
            'gross_sales'    => ['sometimes', 'numeric'],
            'net_income'     => ['sometimes', 'numeric'],
            'total_orders'   => ['sometimes', 'integer', 'min:0'],
            'catered_leads'  => ['sometimes', 'integer', 'min:0'],
            'pickup_rate'    => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'upselling_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        if (TsaSalesLockedDate::whereDate('entry_date', $entryDate)->exists()) {
            return response()->json(['success' => false, 'message' => 'This date is locked.'], 422);
        }

        $entry = TsaTiktokEntry::where('tsa_shift_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first()
            ?? new TsaTiktokEntry(['tsa_shift_id' => $tsaShift->id, 'entry_date' => $entryDate]);
        $old = $entry->only(array_keys($data));
        $entry->fill($data);
        $entry->save();

        $context = $tsaShift->display_name . "'s TikTok Upsell on " . Carbon::parse($entryDate)->format('M j, Y');
        $isPctField = fn (string $field) => in_array($field, ['pickup_rate', 'upselling_rate'], true);
        foreach ($data as $field => $newValue) {
            if ((float) ($old[$field] ?? 0) == (float) $newValue) continue;
            $formatter = $isPctField($field)
                ? fn ($v) => number_format((float) $v * 100, 2) . '%'
                : fn ($v) => number_format((float) $v, 2);
            ActivityLogger::logFieldUpdate('tsa_sales.field_updated', $entry, $field, $old[$field] ?? 0, $newValue, $context, $formatter);
        }

        $derived = TsaSalesCalculator::derive($entry->toArray());

        return response()->json([
            'success' => true,
            'derived' => $derived,
        ]);
    }

    /** Flips ONE date's own lock on/off (explicit request, 2026-10-07:
     *  "add lock icon like in the dsppr") — same per-date mechanism as
     *  DsPprReportController::toggleLock(): freezes every TSA's own real
     *  entry AND her TikTok Upsell entry for that one date, across every
     *  7-day chunk that date happens to render in. See
     *  TsaSalesLockedDate's own doc comment for why existence of a row is
     *  the flag. */
    public function toggleLock(Request $request, string $date)
    {
        $locked = $request->boolean('locked');
        $entryDate = Carbon::parse($date)->toDateString();

        if ($locked) {
            TsaSalesLockedDate::firstOrCreate(['entry_date' => $entryDate]);
        } else {
            TsaSalesLockedDate::whereDate('entry_date', $entryDate)->delete();
        }
        ActivityLogger::log($locked ? 'tsa_sales.date_locked' : 'tsa_sales.date_unlocked', null, ($locked ? 'Locked' : 'Unlocked') . ' the daily entry table for ' . Carbon::parse($entryDate)->format('M j, Y') . '.');

        return response()->json(['success' => true, 'date' => $entryDate, 'locked' => $locked]);
    }
}
