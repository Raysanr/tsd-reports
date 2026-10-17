<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ExpectedIncomeCustomValue;
use App\Models\ExpectedIncomeEntry;
use App\Models\ExpectedIncomeTiktokEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TsaShift;
use App\Support\ActivityLogger;
use App\Support\DateRangeFilter;
use App\Support\ExpectedIncomeCalculator;
use App\Support\ProductGrouping;
use App\Support\ProductPerformance;
use App\Support\Teams;
use App\Support\TiktokUpsellTotals;
use App\Support\TsaDailyRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TSD Data Management — Expected Income 2026 (explicit request, 2026-09-26:
 * "analyze this expected income and add it to the data management module
 * ... i want exactly like this like in the sheets like every product is
 * has card ... in the top there's expected sales and after that it is
 * dates going down"). Replicates the source sheet's own "EXPECTED INCOME
 * 2026" tab's real layout: one card per product, side by side (same visual
 * pattern as Projections' own _column.blade.php cards) — a top row summing
 * the whole selected range (the sheet's own "TELESALES EXPECTED
 * PERFORMANCE"), then one full row of cards per calendar DAY stacking
 * downward, same range-summary + daily-blocks structure as DSPPR.
 *
 * Uses the app's real Product table, not a fixed list matching the sheet's
 * own product names — explicit decision, 2026-09-26, same reasoning as
 * Summary Sales Report's own rework earlier that day (the sheet's product
 * list doesn't exactly match this app's real roster, and a page that
 * reflects the real roster needs no manual sync).
 *
 * TEAM FILTER + PER-TSA DAILY ROWS (explicit request, 2026-09-30: "change
 * the dates into per TSA and per team ... i want to add team filter ...
 * and the date [row] will be now for example TSA NAME and the still the
 * products like that"). This is a real new data-entry axis, not just a
 * display regrouping — each real TSA now gets her OWN numbers per product
 * per day, editable independently. Confirmed live via a screenshot,
 * 2026-09-30, exactly which parts of the page the team filter touches:
 *
 *   - The TOP range-summary row ("TELESALES EXPECTED PERFORMANCE" + every
 *     product's own card, summed across the whole selected range) is
 *     COMPLETELY UNCHANGED by the team filter ("the top is still like
 *     that") — always product-level (tsa_id NULL), same figures no matter
 *     which team pill is selected. See buildSummary().
 *
 *   - The DAILY rows below it (one row per calendar day, stacking
 *     downward) are what the team filter actually changes:
 *       - selectedTeam = 'all' (default): each day's own "TELESALES"
 *         overall card + every product's own card, tsa_id NULL —
 *         unchanged in substance from before this feature. See
 *         buildAllDailyRows().
 *       - selectedTeam = a real team slug: the "TELESALES" card
 *         is replaced by ONE block PER REAL TSA on that team ("in the down
 *         the yellow is only TSA NAMES") — her own name where that card's
 *         title used to be, followed by her own product cards, for
 *         EVERY date in the range (not a single range-summed row — the
 *         exact same per-day stacking as the ALL view, just repeated once
 *         per TSA). Every card here reads/writes ExpectedIncomeEntry rows
 *         with a real tsa_id — completely independent numbers from the
 *         "ALL" view's own tsa_id-NULL rows. See buildTeamDailyRows().
 *
 * Widening the date-range picker does the same thing it always did on
 * this page (explicit confirmation, 2026-09-30: "the only change is the
 * number it will become large based on the date filter") — more days
 * stack into the summary's own range total and more day-blocks render
 * below; the team filter is completely orthogonal to that.
 */
class ExpectedIncomeController extends Controller
{
    public function index(Request $request)
    {
        // See DateRangeFilter's own doc comment — remembers the last range
        // picked on THIS page across separate visits (a fresh sidebar-link
        // navigation has no query string of its own to fall back to),
        // defaulting to "today only" on a brand-new session.
        $range = DateRangeFilter::resolve($request, 'expected-income');
        $dateFrom = $range['from'];
        \App\Models\TsaShift::showDeletedFrom($dateFrom); // removed TSAs still show for days up to their removal
        $dateTo   = $range['to'];

        $teamsConfig = Teams::config();
        $selectedTeam = $this->resolveSelectedTeam($request, $teamsConfig);
        // TIKTOK TEAM sits right after the real teams (explicit request,
        // 2026-10-06: "next to the team opening is TIKTOK TEAM") — not a
        // real Teams::config() entry, see resolveSelectedTeam()'s own doc
        // comment for why. Only shown at all when at least one TSA is
        // actually flagged tiktok_upsell, same "don't show a filter with
        // nothing behind it" convention as every other conditional UI
        // piece on this page.
        $teams = ['all' => 'ALL'] + array_map(fn ($t) => $t['name'], $teamsConfig);
        if (TsaShift::where('tiktok_upsell', true)->exists()) {
            $teams['tiktok'] = 'TIKTOK TEAM';
        }

        // EVERY product gets a card (reversed 2026-10-06, explicit
        // request: "it should be all products has product card in the
        // expected income but it has no cost like in the Operating Costs
        // row" — supersedes the narrower 2026-10-03 decision that only
        // rendered a card for FLAGGED products at all). has_cost_allocation
        // still correctly gates the Operating Costs DIVISOR
        // (TsaDailyRateService::flaggedProductRows()/productCount() —
        // unchanged, unaffected by this) — an unflagged product's own card
        // still renders, just with no share of Operating Costs allocated
        // to it, same as a flagged product's card already shows real
        // Operating Costs.
        $products = self::reportProducts();

        // Computed ONCE here (this controller has DB access) and passed
        // explicitly into every derive()/sum() call below — see
        // ExpectedIncomeCalculator::derive()'s own doc comment for why
        // those methods never query ProjectionCustomRow themselves
        // (they're pure functions, verified against real spreadsheet
        // numbers in a plain PHPUnit\Framework\TestCase with no DB at all).
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());

        // daysUntil() is already INCLUSIVE of its own end date — see the
        // identical fix in DsPprReportController's own doc comment for the
        // full root cause (confirmed 2026-09-27: picking "To: Sep 30" was
        // silently rendering an extra Oct 1 card row).
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        // The top range-summary row ("Telesales Expected Performance" +
        // every product's own card) scopes to the selected team's own real
        // TSAs (explicit correction, 2026-09-30: "when per team filter the
        // Telesales Expected Performance is per team only") — null (every
        // TSA, site-wide) only in the ALL view. TIKTOK TEAM has no real
        // product cards of its own at all (explicit scope, 2026-10-06:
        // "only tiktok-flagged tsas... just their 2 tiktok cards, not
        // their normal product cards"), so this whole summary row is
        // skipped there entirely (see buildSummary()'s own doc comment for
        // why it only ever makes sense for a real team or ALL) — the
        // $teamsConfig[$selectedTeam] lookup below never runs for
        // 'tiktok' since that branch short-circuits first. A TikTok-flagged
        // TSA (tiktok_upsell) is NO LONGER excluded from her real team's own
        // id list here (REVERSED 2026-10-13, explicit request: a TikTok-
        // flagged TSA genuinely does both jobs — real Pancake orders as a
        // normal TSA AND separate manual TikTok upsells, two genuinely
        // independent income streams for the same person, not a duplicate
        // of the same money — see buildSummary()'s own doc comment for the
        // fuller story, superseding the original 2026-10-07 decision).
        $summaryData = $selectedTeam === 'tiktok'
            ? ['summaryCards' => collect(), 'summaryOverallTotal' => ExpectedIncomeCalculator::derive([]), 'teamSummaryRows' => collect()]
            : $this->buildSummary($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $selectedTeam === 'all' ? null : TsaShift::where('team', $teamsConfig[$selectedTeam]['order_team'])->pluck('id')->all(), $selectedTeam === 'all' ? null : $teamsConfig[$selectedTeam]['order_team']);

        // The DAILY rows below the summary DO change with the team filter
        // (confirmed by the same screenshot: "in the down the yellow is
        // only TSA NAMES" when a team is picked) — one row per calendar
        // day either way, but each day's own card set is either every
        // product (ALL), one block per real TSA on that team (her own
        // name where "TELESALES" used to be, each followed by her own
        // product cards), or — TIKTOK TEAM (explicit request, 2026-10-06:
        // "separate the tiktok team ... next to the team opening is TIKTOK
        // TEAM") — one block per TikTok-flagged TSA showing ONLY her 2
        // TikTok cards, no overview/product cards at all. No separate
        // builder needed for this third case: buildTiktokRows() below
        // already computes everything a TikTok block needs
        // ($tiktokTsaRows), so $dailyData stays empty here and the view's
        // own @if($selectedTeam === 'tiktok') branch reads $tiktokTsaRows
        // directly instead of $tsaRows.
        $dailyData = match ($selectedTeam) {
            'all'    => $this->buildAllDailyRows($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys),
            'tiktok' => ['isRangeSummed' => $dates->count() > 1, 'dailyRows' => collect(), 'dailyOverallTotals' => collect(), 'dailyByKey' => collect(), 'tsaRows' => collect(), 'rangeRows' => null, 'rangeOverallTotal' => null],
            default  => $this->buildTeamDailyRows($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $teamsConfig[$selectedTeam]),
        };

        // TikTok's own 2 fixed cards (explicit request, 2026-10-05) — see
        // buildTiktokRows()'s own doc comment. Computed unconditionally
        // (not scoped to $selectedTeam) since $tiktokOverallTotal always
        // shows in the TOTAL section regardless of team filter, same as
        // $summaryOverallTotal above — and TIKTOK TEAM's own daily blocks
        // (above) read straight from this same $tiktokTsaRows, not a
        // separate query.
        $tiktokData = $this->buildTiktokRows($dates, $dateFrom, $dateTo);

        // TELESALES folds TikTok's own site-wide total in on top of every
        // real product's own total (explicit follow-up, 2026-10-06: "it
        // will be remove this card TIKTOK TOTAL because the overall total
        // is will be TELESALES") — removes the standalone TIKTOK TOTAL
        // card; TELESALES becomes the true site-wide figure instead. Only
        // on ALL/TIKTOK TEAM, same scope the removed card itself used to
        // have (a REAL team's own summary row never included TikTok).
        //
        // Every field folds in, Salaries included — see
        // foldTiktokIntoTelesales()'s own doc comment for the history.
        $summaryData['summaryOverallTotal'] = $this->foldTiktokIntoTelesales(
            $summaryData['summaryOverallTotal'], $tiktokData['tiktokOverallTotal'], $selectedTeam, $sellingKeys, $operatingKeys
        );

        return view('data.expected-income', array_merge($summaryData, $dailyData, $tiktokData, [
            'products' => $products,
            'dates'    => $dates,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'teams'    => $teams,
            'selectedTeam' => $selectedTeam,
        ]));
    }

    /** Summary Sales Report's own Net Income source of truth (explicit
     *  request, 2026-10-10: "the net income the basis is in the expected
     *  income page" / follow-up, same date, after a screenshot comparison:
     *  "the net income that will display to the summary sales report is
     *  the net income in the tsa cards in the expected income" — confirming
     *  the real "[TSA NAME]" OVERVIEW card, not a product card) — every
     *  real (non-TikTok) TSA's own Net Income for one day, keyed
     *  "tsaId:date", computed the exact same way as her own read-only
     *  "[TSA NAME]" overview row on THIS page (buildTeamDailyRows()'s own
     *  $dailyOverallTotals, 1-day case): every one of her product rows
     *  that day, summed raw, derived once, then her real UNDIVIDED
     *  Operating-Costs/Tax-Allocation override applied directly via
     *  ExpectedIncomeCalculator::withOverriddenOperatingCosts()/
     *  withOverriddenTaxAllocation() — NOT withOperatingCostOverridesIfTsaScoped()
     *  (that method is a PRODUCT-CARD helper, own doc comment: "product
     *  cards only, never the overview card" — it divides Salaries/every
     *  pool by product count again, which this method used at first,
     *  2026-10-10, and was wrong: confirmed via a live screenshot
     *  comparison, Gemma's real overview card showed NET INCOME -3,390.37
     *  while the first version of this method, product-card-scaled,
     *  produced a far smaller-magnitude number for the same TSA/day —
     *  root cause was reusing the ÷productCount Salaries/pool figures
     *  instead of the undivided ones the overview card actually renders).
     *
     *  Loops every real team internally (not scoped to one) so Summary
     *  Sales Report — which shows both teams at once — can read both in a
     *  single call; a TikTok-flagged TSA is excluded (tiktok_upsell=true),
     *  same scope boundary buildTeamDailyRows() and Summary Sales Report's
     *  own TikTok Upsell section already agree on (her Net Income there
     *  stays fully manual, untouched by this).
     *
     *  Every product counts toward her total regardless of
     *  has_cost_allocation (same as buildTeamDailyRows()'s own
     *  $dailyOverallTotals, which applies no such gate) — only the
     *  Operating Costs/Tax Allocation SHARE a flagged product receives is
     *  conditional; an unflagged product's own manually-entered/automated
     *  Gross Sales/Cancelled/Selling Costs still count toward her Net
     *  Income.
     *
     *  Deliberately per-DAY, not range-summed — this is the right grain
     *  for a caller building one row per (TSA, day) (Summary Sales
     *  Report's own daily table), unlike buildTeamDailyRows()'s own
     *  $isRangeSummed branch (its $rangeOverallTotal collapses N days into
     *  ONE lump-sum figure with overrides pre-multiplied by day count,
     *  built for a >1-day card stack that can no longer save per-day
     *  edits — a concept this method has no use for, since each day here
     *  gets its own override at its own flat daily rate, never multiplied
     *  by a day count). A caller that needs a range total sums this
     *  method's own per-day values itself, same as Summary Sales Report's
     *  own TsaSalesCalculator::sum() already does for every other
     *  per-day field on that page. */
    public static function netIncomeByTsaAndDate(string $dateFrom, string $dateTo, ?\Illuminate\Support\Collection $precomputedLeadCounts = null, ?array $precomputedGrossSalesAndOrders = null): \Illuminate\Support\Collection
    {
        $products = self::reportProducts();
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        // tiktok_upsell=true TSAs are no longer excluded here (REVERSED
        // 2026-10-13 — see buildSummary()'s own doc comment for the full
        // "she genuinely does both jobs" reasoning) — a flagged TSA's real
        // per-day Net Income is now computed and available the same as any
        // other TSA.
        $tsas = TsaShift::all();
        $controller = new self();

        // Same overview-card sources buildTeamDailyRows() itself reads
        // (lines ~848-880 there) — UNDIVIDED by product count, unlike the
        // product-card lookups withOperatingCostOverridesIfTsaScoped()
        // reads. Computed once for the whole call, not once per TSA/day —
        // same N+1 guard TsaDailyRateService::allTsas()'s own doc comment
        // already explains for this exact lookup chain.
        // Cost figures are resolved per (TSA, day) below via
        // tsaCardOverrides() (monthly Cost Breakdown, 2026-10-15).

        // $precomputedLeadCounts/$precomputedGrossSalesAndOrders (perf fix,
        // 2026-10-11, PART 4 — see Decisions.md's PART 3 entry for the
        // index fix that preceded this, and this method's OWN prior
        // "computed once per TSA, not per TSA/day" fix, PART 2). Root-
        // caused live AGAIN after both those fixes shipped: Oct 1-5 now
        // loaded (slow, but under 30s) while Oct 1-9 still timed out — a
        // clean linear-scaling signature. The remaining redundancy:
        // TsaSalesReportController::index() calls BOTH this method AND
        // teamNetIncomeByDate() in the SAME request, and each independently
        // computed its own fresh copy of both maps for the WHOLE range —
        // 2 full passes per page load. Callers that already have these
        // maps (TsaSalesReportController::index(), computing them once for
        // both calls) now pass them straight through instead of letting
        // this method redo the work; a caller with no precomputed maps
        // (e.g. a direct/standalone call) still gets correct behavior via
        // the fallback. NOT a static-property cache — this app runs
        // Laravel Octane in WORKER mode (see Dockerfile's own doc comment,
        // "keeps the whole Laravel app booted in memory between
        // requests"), where a static property would persist and leak
        // across unrelated requests instead of resetting per-request like
        // classic PHP-FPM; explicit parameters avoid that correctness risk
        // entirely.
        $leadCounts = $precomputedLeadCounts ?? $controller->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo);
        $grossSalesAndOrders = $precomputedGrossSalesAndOrders ?? $controller->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo);

        // Same unmatched-order top-up the TSA's own Expected Income card
        // applies (buildTeamDailyRows()'s $dailyOverallTotals) — bug fix,
        // 2026-10-15, explicit report: Summary Sales Report's Team Closing
        // rows summed to 61,214.56 but its TEAM TOTAL (pooled, which DOES
        // include unmatched orders) read 60,945.15. One query for the whole
        // range, tallied per TSA per day.
        $realByTsaAndDate = $controller->upsellTotalsByTsaKeyAndDate($tsas->pluck('tsa_key'), $dateFrom, $dateTo);

        $result = collect();
        foreach ($tsas as $tsa) {
            ['raw' => $rawByProductAndDate] = $controller->rawByProductAndDate(
                $products, $tsa->id, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys,
                $leadCounts, $grossSalesAndOrders
            );

            // Same "0-day TSA's own share of every shared pool cost is
            // zeroed, but Salaries always applies regardless of Days"
            // rule buildTeamDailyRows() itself follows (2026-10-06 — see
            // that method's own doc comment) — and the same direct-fallback
            // for a TSA missing from the pre-built maps (2026-10-03 fix).
            // A TikTok-flagged TSA's own Salaries is excluded here too
            // (narrowed 2026-10-14 — see buildTeamDailyRows()'s own
            // matching doc comment).
            foreach ($dates as $date) {
                $dateStr = $date->toDateString();
                // That day's own month/roster (2026-10-15) — see tsaCardOverrides().
                ['o' => $overviewCardOverrides, 'ot' => $overviewCardTaxAllocation]
                    = TsaDailyRateService::withDate($dateStr, fn () => self::tsaCardOverrides($tsa));
                $dayRaw = $products->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr))->all();
                $real = $realByTsaAndDate->get($tsa->tsa_key . ':' . $dateStr, ['gross_sales' => 0.0, 'number_of_orders' => 0, 'cancelled' => 0.0]);
                $gap = [
                    'gross_sales'      => $real['gross_sales'] - (float) array_sum(array_column($dayRaw, 'gross_sales')),
                    'number_of_orders' => $real['number_of_orders'] - (float) array_sum(array_column($dayRaw, 'number_of_orders')),
                    'cancelled'        => $real['cancelled'] - (float) array_sum(array_column($dayRaw, 'cancelled')),
                ];
                if (abs($gap['gross_sales']) > 0.005 || abs($gap['number_of_orders']) > 0.005 || abs($gap['cancelled']) > 0.005) {
                    $dayRaw[] = $gap;
                }
                $summed = ExpectedIncomeCalculator::sum($dayRaw, $sellingKeys, $operatingKeys);
                $summed = ExpectedIncomeCalculator::withOverriddenOperatingCosts($summed, $overviewCardOverrides);
                $summed = ExpectedIncomeCalculator::withOverriddenTaxAllocation($summed, $overviewCardTaxAllocation);
                $result[$tsa->id . ':' . $dateStr] = $summed['net_income'];
            }
        }

        return $result;
    }

    /** Summary Sales Report's own TikTok Upsell section's source of truth
     *  (explicit request, 2026-10-11: after confirming the Gross Sales gap
     *  between the two pages' TikTok totals was two independently-typed
     *  manual tables — ExpectedIncomeTiktokEntry vs TsaTiktokEntry — with
     *  no shared computation, "do it" — Expected Income's own TikTok cards
     *  become the one real data-entry point, same pattern already used for
     *  the real-team rows on this page via netIncomeByTsaAndDate()).
     *
     *  Every flagged (tiktok_upsell=true) TSA's own pooled-across-her-2-
     *  cards (SH NATURALS + NATUREVA) Gross Sales/Net Income/Number of
     *  Orders for ONE day, keyed "tsaId:date" — same per-day grain as
     *  netIncomeByTsaAndDate() above (a caller needing a range total sums
     *  these per-day values itself, same convention).
     *
     *  Salaries is her real, UNDIVIDED Daily Rate here (TsaDailyRateService::
     *  dailyRateByTsaId(), same overview-card scale buildTiktokRows()'s own
     *  $overallTotal uses — see that method's own doc comment) — reversed
     *  back from a 0.0 override, 2026-10-12 (explicit instruction: "not
     *  just salaries, include all of the tiktok all data"). This method
     *  feeds BOTH Expected Income's own TELESALES fold-in (index()/
     *  summary()) AND TsaSalesReportController's OVERALL TOTAL, so both
     *  consumers must agree — Expected Income's TELESALES card now folds
     *  in TikTok's REAL, unmodified Operating Costs (see index()'s own
     *  doc comment), so this method must match that, not the old
     *  zeroed-Salaries convention from the 2026-10-10 reversal it briefly
     *  held between 2026-10-11 and this commit.
     *
     *  Catered Leads/Pick-up Rate/Upselling Rate have NO equivalent in
     *  ExpectedIncomeTiktokEntry at all (that table only ever tracked
     *  number_of_leads/number_of_orders, no call-disposition breakdown) —
     *  returned as 0 for those 3 fields, same "field this source table
     *  can't represent stays 0" convention TsaTiktokEntry itself already
     *  uses for fields it has no formula for. */
    public static function tiktokTotalsByTsaAndDate(string $dateFrom, string $dateTo): \Illuminate\Support\Collection
    {
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));
        $flaggedTsas = TsaShift::where('tiktok_upsell', true)->get();

        if ($flaggedTsas->isEmpty()) {
            return collect();
        }

        $entriesByKey = ExpectedIncomeTiktokEntry::whereIn('tsa_id', $flaggedTsas->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (ExpectedIncomeTiktokEntry $e) => $e->tsa_id . ':' . $e->card_key . ':' . $e->entry_date->toDateString());

        $autoByKey = TiktokUpsellTotals::byTsaCardAndDate($dateFrom, $dateTo);

        $result = collect();
        foreach ($flaggedTsas as $tsa) {
            foreach ($dates as $date) {
                $dateStr = $date->toDateString();
                $rawRows = collect(ExpectedIncomeTiktokEntry::CARDS)->keys()->map(
                    fn ($cardKey) => self::withTiktokAuto($entriesByKey->get("{$tsa->id}:{$cardKey}:{$dateStr}")?->toArray() ?? [], $autoByKey, $tsa->id, $cardKey, $dateStr)
                )->all();

                $derived = ExpectedIncomeCalculator::withOverriddenOperatingCosts(
                    ExpectedIncomeCalculator::sum($rawRows),
                    // That day's own Daily Rate (monthly Cost Breakdown, 2026-10-15).
                    ['salaries' => TsaDailyRateService::withDate($dateStr, fn () => TsaDailyRateService::roster()->contains('id', $tsa->id) ? (self::costLookups('rollup')[0][$tsa->id] ?? 0.0) : 0.0)]
                );

                $result[$tsa->id . ':' . $dateStr] = [
                    'gross_sales'    => (float) $derived['gross_sales'],
                    'net_income'     => (float) $derived['net_income'],
                    'total_orders'   => (int) $derived['number_of_orders'],
                    'catered_leads'  => 0,
                    'pickup_rate'    => 0,
                    'upselling_rate' => 0,
                ];
            }
        }

        return $result;
    }

    /** Summary Sales Report's own TEAM TOTAL row — the real, POOLED
     *  team-level Net Income (the exact figure this page's own TELESALES
     *  card shows for one real team), NOT a sum of individually-costed
     *  TSA overview cards. Explicit reversal, 2026-10-10, same day as
     *  netIncomeByTsaAndDate() above: the user first confirmed "sum of
     *  individual tsa" was correct, then corrected again minutes later —
     *  "i WANT THE NET INCOME SHOULD BE 3,840.30" / "BECAUSE THAT IS THE
     *  NET INCOME" — pointing at a live screenshot of this page's own
     *  per-team "TELESALES" card (buildSummary()'s own $teamSummaryRows),
     *  confirming the TEAM TOTAL row must match that pooled figure, not
     *  TsaSalesCalculator::sum()'s own per-TSA-row total (20,923.62 in
     *  that same screenshot — the two differ because Operating Costs/
     *  Salaries/Tax Allocation are real per-TSA costs: summing 6
     *  individually-complete P&Ls double-counts shared-pool costs a
     *  single team-level pooling only ever subtracts once).
     *
     *  Reuses buildSummaryRow() itself — the EXACT method
     *  $teamSummaryRows already calls (buildSummary(), line ~467) — one
     *  call per real team PER DAY (not range-summed in one call, so the
     *  per-day grain matches netIncomeByTsaAndDate()'s own above; a
     *  caller needing a range total sums these per-day values itself,
     *  same convention that method's own doc comment already settled).
     *  Keyed "{orderTeam}:{date}" (the real `order_team` string, e.g. "SH
     *  Naturals" — NOT the config slug, since TsaSalesReportController's
     *  own $team['order_team'] is what's in scope there). A TikTok-
     *  flagged TSA is excluded from her real team's own pool here too
     *  (same $teamTsaIds exclusion buildSummary() itself applies), same
     *  scope boundary Summary Sales Report's own TikTok Upsell section
     *  already agrees on. */
    public static function teamNetIncomeByDate(string $dateFrom, string $dateTo, ?\Illuminate\Support\Collection $precomputedLeadCounts = null, ?array $precomputedGrossSalesAndOrders = null): \Illuminate\Support\Collection
    {
        $products = self::reportProducts();
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        $controller = new self();

        // Computed ONCE for the WHOLE requested range here, then sliced
        // per (team, date) below via buildSummaryRow()'s own precomputed-
        // map parameters — perf fix, 2026-10-11 (root-caused live: Summary
        // Sales Report 500'd, "Maximum execution time of 30 seconds
        // exceeded", on a 9-day range). Before this fix, the loop below
        // called buildSummaryRow() once per (team, date) — e.g. 2 teams x
        // 9 days = 18 calls — and each call independently re-ran
        // leadCountsByProductAndDate()/grossSalesByProductAndDate() from
        // scratch for its own single day, with EACH of those internally
        // re-matching BOTH real teams' orders regardless of which one
        // team's result the caller actually kept. That's ~36x the real
        // matching cost for what should be one pass over the range. Both
        // maps are pure functions of (products, dates, dateFrom, dateTo)
        // with no team-scoping of their own (see their own doc comments —
        // every real team's results are already computed and separately
        // keyed internally), so hoisting them out of the loop changes
        // nothing about correctness, only how many times the same work
        // gets done.
        //
        // $precomputedLeadCounts/$precomputedGrossSalesAndOrders (PART 4,
        // same day) — accepts an already-computed pair from the caller
        // (TsaSalesReportController::index() computes these once and
        // passes the SAME pair to both this method and
        // netIncomeByTsaAndDate(), which previously each computed their
        // own copy — 2 full passes per page load instead of 1). See
        // netIncomeByTsaAndDate()'s own identical parameters' doc comment
        // for why this is explicit parameters, not a static cache (Octane
        // worker-mode cross-request leak risk).
        $leadCounts = $precomputedLeadCounts ?? $controller->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo);
        $grossSalesAndOrders = $precomputedGrossSalesAndOrders ?? $controller->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo);

        $result = collect();

        foreach (Teams::config() as $teamConfig) {
            $orderTeam = $teamConfig['order_team'];
            // tiktok_upsell=true no longer excluded (REVERSED 2026-10-13 —
            // see buildSummary()'s own doc comment) — her real pooled
            // revenue now counts toward her own team's TOTAL too, same as
            // any other TSA on the team.
            $teamTsaIds = TsaShift::where('team', $orderTeam)->pluck('id')->all();

            foreach ($dates as $date) {
                $dateStr = $date->toDateString();
                $oneDay = collect([$date]);
                ['overallTotal' => $overallTotal] = $controller->buildSummaryRow(
                    $products, $oneDay, $dateStr, $dateStr, $sellingKeys, $operatingKeys, $teamTsaIds, $orderTeam,
                    $leadCounts, $grossSalesAndOrders
                );
                $result["{$orderTeam}:{$dateStr}"] = $overallTotal['net_income'];
            }
        }

        return $result;
    }

    /** DSPPR - TSM Report's own Gross Sales/Net Income automation basis
     *  (explicit request, 2026-10-10: "i want to make it the gross sales
     *  and net income is automated and the basis is from the expected
     *  income") — the SAME per-product/group figures already shown on
     *  this page's own "Telesales Expected Performance" summary cards for
     *  that one day (buildSummaryRow()'s own $cards, one call PER DATE),
     *  not a second, independently re-derived number. Per-day, not
     *  range-summed — DSPPR's own daily detail table stores one Gross
     *  Sales/Net Income PER (product, calendar day), same shape as
     *  Expected Income's own per-day product cards, so a single
     *  range-summed figure wouldn't fit its own per-date columns; DSPPR's
     *  own range TOTALS are computed by summing these per-day values
     *  itself, the same way it already sums Total Orders/Leads per day
     *  (see DsPprCalculator::sum()).
     *
     *  Keyed `"{productId}:{dateStr}"` — mirrors DsPprReportController's
     *  own `$dailyByKey`/`$realByRowKeyAndDate` convention directly, so the
     *  caller can look this up the exact same way. A grouped row's figure
     *  is stored under EVERY member product's own id (not just the
     *  group's first member) — DSPPR's own per-day table reads a group's
     *  row via `$row['products']->first()->id` only (same "first member
     *  is the row's real editable identity" convention its own daily-table
     *  comment documents), but Expected Income has no concept of DSPPR's
     *  product groups at all (ProductGroup is itself shared, but
     *  buildSummaryRow() groups independently) — storing under every
     *  member avoids a silent mismatch if the two pages' own grouping
     *  ever diverges for some reason.
     *
     *  $onlyOrderTeam: one real team's own `order_team` string to match
     *  DSPPR's own per-team filter, or null for DSPPR's own ALL view
     *  (every real TSA site-wide, mirroring buildSummaryRow()'s own
     *  main/team-independent row). */
    public static function grossSalesAndNetIncomeByProductAndDate(string $dateFrom, string $dateTo, ?string $onlyOrderTeam = null): \Illuminate\Support\Collection
    {
        $products = self::reportProducts();
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        // tiktok_upsell=true no longer excluded (REVERSED 2026-10-13 — see
        // buildSummary()'s own doc comment) — DSPPR now tallies a flagged
        // TSA's real revenue consistently with Expected Income/Summary
        // Sales Report.
        $onlyTsaIds = $onlyOrderTeam === null
            ? null
            : TsaShift::where('team', $onlyOrderTeam)->pluck('id')->all();

        $controller = new self();

        // Computed ONCE for the whole requested range — same perf fix as
        // teamNetIncomeByDate() above, same root cause (this method also
        // calls buildSummaryRow() once per date in the loop below; without
        // hoisting these out, a 9-day DSPPR range would re-run full
        // team x product matchingOrders() 9 separate times instead of
        // once). See teamNetIncomeByDate()'s own doc comment for the full
        // story.
        $leadCounts = $controller->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo);
        $grossSalesAndOrders = $controller->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo);

        $result = collect();

        foreach ($dates as $date) {
            $dateStr = $date->toDateString();
            $oneDay = collect([$date]);
            ['cards' => $cards] = $controller->buildSummaryRow(
                $products, $oneDay, $dateStr, $dateStr, $sellingKeys, $operatingKeys, $onlyTsaIds, $onlyOrderTeam,
                $leadCounts, $grossSalesAndOrders
            );

            foreach ($cards as $row) {
                $value = [
                    'gross_sales' => (float) $row['derived']['gross_sales'],
                    'net_income'  => (float) $row['derived']['net_income'],
                ];
                foreach ($row['products'] as $memberProduct) {
                    $result["{$memberProduct->id}:{$dateStr}"] = $value;
                }
            }
        }

        return $result;
    }

    /** Remembers the last team filter picked on this page across separate
     *  visits, same "session, keyed per page" convention as
     *  DateRangeFilter::resolve() (explicit request, 2026-10-01: "the
     *  expected income team filter is when i wilck other page in sidebar
     *  is when i go back to the expected income it is staying to that
     *  what i filter" — a fresh sidebar-link navigation has no query
     *  string of its own, so without this the filter silently reset to
     *  ALL every time). Resolution order: the URL's own ?team= (the user
     *  just picked a pill, or followed a link/bookmark) always wins and
     *  gets saved to session; otherwise whatever was last saved; otherwise
     *  'all' on a brand-new session. An invalid/stale team slug (a team
     *  renamed or removed since it was saved) falls back to 'all' rather
     *  than a broken filter.
     *
     *  'tiktok' (explicit request, 2026-10-06: "i want you to separate the
     *  tiktok team so it will be like next to the team opening is TIKTOK
     *  TEAM") is accepted here as a 4th pill value alongside 'all' and the
     *  real $teamsConfig keys — not a real Teams::config() entry (TikTok-
     *  flagged TSAs, TsaShift.tiktok_upsell, aren't a real team; they can
     *  belong to either real team), so it's special-cased rather than
     *  added there. */
    /** Every product this module reports on: hidden ones (Product
     *  Management's eye toggle) are left out entirely — no card, no share
     *  of any total, and their saved ExpectedIncomeEntry rows (manual
     *  costs included) are never fetched, since every entry query here is
     *  whereIn('product_id', $products). Explicit request, 2026-10-17
     *  ("hide everything"). Their orders are dropped from every order
     *  fetch too (withoutHiddenProductOrders()), so product-agnostic
     *  totals (teamUpsellTotals() etc.) and the narrowed matching roster
     *  both stay consistent with this list. */
    private static function reportProducts(): \Illuminate\Support\Collection
    {
        return Product::where('is_hidden', false)->orderBy('team')->orderBy('sort_order')->get();
    }

    private function resolveSelectedTeam(Request $request, array $teamsConfig): string
    {
        $team = $request->input('team');

        if ($team !== null) {
            session(['expected-income.team' => $team]);
        } else {
            $team = session('expected-income.team', 'all');
        }

        return in_array($team, ['all', 'tiktok'], true) || isset($teamsConfig[$team]) ? $team : 'all';
    }

    /** Live refresh for the top "Telesales Expected Performance" row
     *  (explicit request, 2026-09-30: "why should i fully reload the page
     *  to reflect that" — typing into any TSA's card saves via
     *  update()/updateCustomRow() but neither of those repaints THIS row,
     *  since it pools every TSA/team's own entries rather than just the
     *  cards sitting in the same scroller as the field that changed — see
     *  buildSummary()'s own doc comment). Re-resolves the same date range
     *  + team the page is currently showing (same DateRangeFilter::resolve()
     *  call as index(), so this never drifts from what's on screen) and
     *  returns the summary section re-rendered as an HTML fragment — same
     *  partial index() itself includes, so the two can never visually
     *  diverge. */
    public function summary(Request $request)
    {
        $range = DateRangeFilter::resolve($request, 'expected-income');
        $dateFrom = $range['from'];
        \App\Models\TsaShift::showDeletedFrom($dateFrom); // removed TSAs still show for days up to their removal
        $dateTo   = $range['to'];

        $teamsConfig = Teams::config();
        $selectedTeam = $this->resolveSelectedTeam($request, $teamsConfig);

        // Same (now unfiltered — 2026-10-06, see index()'s own doc
        // comment) product list as index() above — this AJAX live-refresh
        // endpoint must never show a different product list than the
        // page's own initial render.
        $products = self::reportProducts();
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        // TIKTOK TEAM has no real product cards (see index()'s own
        // identical branch/doc comment, 2026-10-06) — same empty/zeroed
        // shape here so this AJAX fragment never disagrees with the
        // initial page render.
        // Same as index() (tiktok_upsell=true no longer excluded,
        // REVERSED 2026-10-13 — see buildSummary()'s own doc comment) —
        // this AJAX fragment must never disagree with the initial page
        // render.
        $summaryData = $selectedTeam === 'tiktok'
            ? ['summaryCards' => collect(), 'summaryOverallTotal' => ExpectedIncomeCalculator::derive([]), 'teamSummaryRows' => collect()]
            : $this->buildSummary($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $selectedTeam === 'all' ? null : TsaShift::where('team', $teamsConfig[$selectedTeam]['order_team'])->pluck('id')->all(), $selectedTeam === 'all' ? null : $teamsConfig[$selectedTeam]['order_team']);

        // TikTok's own site-wide total (explicit request, 2026-10-05) —
        // this fragment is re-rendered after every autosave, including a
        // save on a TikTok card itself, so it needs the same fresh total
        // index() computes via buildTiktokRows(). 'tiktokTsaRows' is
        // unused here (this fragment never renders per-TSA cards) but
        // cheap enough to not bother stripping out.
        $tiktokData = $this->buildTiktokRows($dates, $dateFrom, $dateTo);

        // TELESALES folds TikTok's own site-wide total in on top of every
        // real product's own total (2026-10-06 — see index()'s own
        // identical fold/doc comment; this AJAX fragment must never
        // disagree with the initial page render) — same shared helper, so
        // the two can no longer drift (they did: this copy kept folding
        // Salaries in unmodified after index()'s 2026-10-13 change, so the
        // ALL view's TELESALES Salaries changed after the first autosave).
        $summaryData['summaryOverallTotal'] = $this->foldTiktokIntoTelesales(
            $summaryData['summaryOverallTotal'], $tiktokData['tiktokOverallTotal'], $selectedTeam, $sellingKeys, $operatingKeys
        );

        return view('data.expected-income._summary-section', array_merge($summaryData, $tiktokData, [
            'sellingRows'   => ExpectedIncomeCalculator::sellingCostRows(),
            'operatingRows' => ExpectedIncomeCalculator::operatingCostRows(),
            'customRowKeys' => ExpectedIncomeCalculator::customRowKeys(),
            'fmtMoney'      => fn ($n) => number_format((float) $n, 2),
            'fmtPct'        => fn ($n) => number_format(((float) $n) * 100, 2) . '%',
            'selectedTeam'  => $selectedTeam,
            // table-actions' own PNG snapshot subtitle (explicit request,
            // 2026-10-05) — same label index() computes, kept in sync here
            // so a live AJAX refresh never shows a stale date range.
            'snapshotDateLabel' => Carbon::parse($dateFrom)->format('F j, Y')
                . ($dateFrom === $dateTo ? '' : ' – ' . Carbon::parse($dateTo)->format('F j, Y')),
        ]));
    }

    /** The top range-summary row's own data — one overall rollup card
     *  ("TELESALES EXPECTED PERFORMANCE") plus every product's own card,
     *  summed across the WHOLE selected range. Pools EVERY tsa_id for each
     *  product/day — the product-level (tsa_id NULL) row, if any, plus
     *  every real TSA's own entry — so typing a number into any TSA's card
     *  actually moves this total. Changed 2026-09-30 (explicit request,
     *  screenshot: "why in the top LUMIEYES/CLEAR SIGHT is not reflecting,
     *  it is per team") from an earlier tsa_id-NULL-only design that made
     *  the top card look broken once TSAs started entering their own
     *  numbers under their own tsa_id instead.
     *
     *  Now DOES change with the team filter (explicit correction,
     *  2026-09-30: "when per team filter the Telesales Expected
     *  Performance is per team only" — reverses the SAME DAY's earlier
     *  "always every TSA across every team" decision recorded above; that
     *  decision is superseded, not this one). $onlyTsaIds is null for the
     *  ALL view (site-wide total, unchanged) or that team's own real TSA
     *  ids when a specific team is selected — same parameter
     *  buildSummaryRow() already forwards to rawByProductAndDateAllTsas()
     *  for the per-team black-header rows below. */
    /** TELESALES = real products' total + TikTok's site-wide total, on
     *  ALL/TIKTOK TEAM only (a real team's own TELESALES never included
     *  TikTok). Every field folds in, Salaries included, on both views
     *  (explicit report, 2026-10-14: "in the ALL the costs of tiktok ...
     *  down to salaries why is it not included to the overall?"). The
     *  2026-10-13 ALL-view Salaries drop existed only to avoid double-
     *  counting a TikTok-flagged TSA's Daily Rate via her real team card;
     *  since that card's Salaries is forced to 0.00 (2026-10-14), the drop
     *  left her Daily Rate out of the ALL total entirely. Her TikTok card
     *  is now its only path in, so it must fold in unmodified. */
    private function foldTiktokIntoTelesales(array $summaryTotal, array $tiktokTotal, string $selectedTeam, array $sellingKeys, array $operatingKeys): array
    {
        if (!in_array($selectedTeam, ['all', 'tiktok'], true)) {
            return $summaryTotal;
        }

        return ExpectedIncomeCalculator::addDerivedTotals($summaryTotal, $tiktokTotal, $sellingKeys, $operatingKeys);
    }

    private function buildSummary($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, ?array $onlyTsaIds = null, ?string $onlyOrderTeam = null): array
    {
        ['cards' => $summaryCards, 'overallTotal' => $summaryOverallTotal] =
            $this->buildSummaryRow($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $onlyTsaIds, $onlyOrderTeam);

        // One row per real team (explicit request, 2026-09-30, from the
        // sheet's own screenshot: "TEAM OPENING SHIFT" / "TEAM CLOSING
        // SHIFT" cards, each followed by that team's OWN set of product
        // cards) — reverses the earlier 2026-09-26 "no per-team cards"
        // decision recorded in the view; this is a newer, explicit,
        // screenshot-confirmed request superseding it. Each team's row is
        // restricted to that team's own real TSAs (plus the shared
        // product-level entries — explicit decision, 2026-09-30: "team's
        // TSAs plus the shared product-level entries"), but uses the FULL
        // product list, not Product.team-scoped (explicit correction,
        // 2026-09-30: "it should have all products per team ... all tsa
        // they handle all products" — a Product's own team assignment in
        // Product Management is unrelated to which products a TSA
        // actually enters numbers for; buildTeamDailyRows()'s per-TSA
        // cards below already use the unfiltered $products for exactly
        // this reason, so this row now matches them instead of silently
        // dropping every product NOT assigned to this team).
        $teamSummaryRows = collect(Teams::config())->map(function (array $teamConfig, string $teamSlug) use ($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys) {
            // A TikTok-flagged TSA (TsaShift.tiktok_upsell) is NO LONGER
            // excluded from her real team's own summary row (REVERSED
            // 2026-10-13, explicit request: "anne is catering in the pos
            // and tiktok too so it will be still separate her orders in
            // pos and in tiktok because tiktok is manual input" — she
            // genuinely does both jobs; her real Pancake-order revenue and
            // her manually-typed TikTok figures are two independent income
            // streams, not a duplicate of the same money, so hiding her
            // real data here — the original 2026-10-07 decision — was
            // wrong, not an intentional trade-off). Her Cost Breakdown
            // salary figure is untouched by this either way — that page
            // doesn't go through TsaShift::where('team', ...) at all.
            $teamTsaIds = TsaShift::where('team', $teamConfig['order_team'])->pluck('id')->all();

            ['cards' => $cards, 'overallTotal' => $overallTotal] =
                $this->buildSummaryRow($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $teamTsaIds, $teamConfig['order_team']);

            return [
                'label'        => $teamConfig['name'],
                'cards'        => $cards,
                'overallTotal' => $overallTotal,
            ];
        })->values();

        return [
            'summaryCards'        => $summaryCards,
            'summaryOverallTotal' => $summaryOverallTotal,
            'teamSummaryRows'     => $teamSummaryRows,
        ];
    }

    /** Shared by buildSummary() for both the main team-independent row and
     *  each per-team row — builds one overall rollup total plus one card
     *  per product (or product GROUP — explicit request, 2026-09-26: "it
     *  will reflect it to the expected income"), all summed across the
     *  WHOLE selected range. $onlyTsaIds is forwarded as-is to
     *  rawByProductAndDateAllTsas() — see that method's own doc comment
     *  for exactly what it restricts. $onlyOrderTeam is the matching
     *  team's own real `order_team` string (e.g. "SH Naturals") or null
     *  for the ALL view — see leadCountsByProductAndDate()'s own doc
     *  comment for why Number of Leads needs this SEPARATELY from
     *  $onlyTsaIds. */
    private function buildSummaryRow($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, ?array $onlyTsaIds, ?string $onlyOrderTeam = null, ?\Illuminate\Support\Collection $precomputedLeadCounts = null, ?array $precomputedGrossSalesAndOrders = null): array
    {
        // Threaded straight through to rawByProductAndDateAllTsas() below
        // (perf fix, 2026-10-12, PART 5 — see that method's own doc
        // comment) — this is computed/resolved again just below via
        // $leadCounts/$grossSalesAndOrders for the REST of this method's
        // own use, but rawByProductAndDateAllTsas() needs its own copy
        // BEFORE that point, so both are passed here first, unresolved,
        // to avoid computing the fallback twice when neither is precomputed.
        $rawByProductAndDate = $this->rawByProductAndDateAllTsas($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $onlyTsaIds, $precomputedLeadCounts, $precomputedGrossSalesAndOrders);

        // Computed ONCE here with the FULL page $products roster — root-
        // caused live, 2026-10-09 (screenshot): SINUXYL/SINUXYL 2.0's own
        // combined card showed 22 leads when Leads Report showed 15+2=17.
        // matchingOrders()'s own $teamProducts argument (its 3rd) needs the
        // FULL roster to correctly run its stale-tag conflict guard
        // (conflictingProduct() — an order whose real item is actually a
        // DIFFERENT product outside this group, e.g. a sibling "Sinuxyl
        // Steam Pack", can only be excluded if that sibling is visible in
        // $teamProducts) — passing just $groupProducts (2 products) to
        // leadCountsByProductAndDate() per-card, as an earlier version of
        // this fix did, silently broke that guard and over-counted. See
        // totalRealLeads()'s own doc comment for how a group's own subset
        // is now summed from this single full-roster map instead.
        //
        // $onlyOrderTeam (explicit 3rd fix, same day, root-caused live via
        // screenshot: PTERYLIEF/PTERYGIUM showed 58 vs Leads Report's
        // 15+35=50 on TEAM CLOSING) — leadCountsByProductAndDate() must
        // pre-filter its own candidate Order pool by `team` BEFORE ever
        // running matchingOrders(), exactly like LeadsReportController's
        // own `->where('team', $orderTeam)` on its $dayOrders/$matchPool
        // queries. $onlyTsaIds alone (a post-hoc "which TSA worked it"
        // filter) is NOT equivalent — a TSA whose own historical orders
        // carry a DIFFERENT Order.team than her currently-displayed team
        // (a real, legitimate case: a TSA working a cross-team product, or
        // one who switched teams) gets her other-team orders swept into
        // this team's own count too, since nothing here ever excluded them
        // by Order.team the way Leads Report's own candidate pool does.
        // $precomputedLeadCounts/$precomputedGrossSalesAndOrders (perf fix,
        // 2026-10-11 — root-caused live: Summary Sales Report 500'd with
        // "Maximum execution time of 30 seconds exceeded" on any multi-day
        // range) — leadCountsByProductAndDate()/grossSalesByProductAndDate()
        // each re-run a full team x day x product matchingOrders() pass
        // EVERY call, computing BOTH real teams' results even though a
        // single-team caller only keeps one. teamNetIncomeByDate() and
        // grossSalesAndNetIncomeByProductAndDate() both call this method
        // once per (team, date) pair — for a 9-day/2-team range that's 18
        // calls, each silently redoing the other 17 calls' own matching
        // work from scratch (effectively ~36x the real cost of one pass
        // over the whole range). Those two callers now compute both maps
        // ONCE for the FULL requested range and pass them in here instead
        // of letting this method recompute its own single-day slice every
        // time — this method still computes them itself (unchanged
        // behavior/cost) for its two OTHER callers (buildSummary()'s own
        // whole-range-in-one-call usage), which were never part of this
        // bug.
        $leadCounts = $precomputedLeadCounts ?? $this->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo);
        $grossSalesAndOrders = $precomputedGrossSalesAndOrders ?? $this->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo);
        ['grossSales' => $grossSalesMap, 'numberOfOrders' => $numberOfOrdersMap, 'cancelled' => $cancelledMap] = $grossSalesAndOrders;

        // Computed ONCE per request (not once per product card/group) —
        // see addActiveTsasOverviewOperatingCosts()'s own doc comment for
        // the N+1 performance bug this fixes (root-caused live, 2026-10-02:
        // a full-month per-team filter 500'd on Railway, timing out from
        // re-running this whole lookup chain once per product card).
        // perProductByTsaIdTwice() -> perProductByTsaId() (2026-10-06,
        // further correction right after the overview-card fix above:
        // "in the products it should be divided by 7 like other cost
        // too" — confirmed live, 1,682.86 ÷ 7 ≈ 240.41 expected, not
        // ÷7÷7 ≈ 34.34) — a PRODUCT card's own Salaries now divides the
        // real, undivided Daily Rate by product count ONCE, same single
        // division every other Operating Costs row already gets
        // (dailyCostRow() -> dailyCostPerProductRow(), also one
        // division). perProductByTsaIdTwice() is no longer used for
        // Salaries anywhere on this page — it stays defined on
        // TsaDailyRateService in case Tax Allocation's own analogous
        // method (perProductTaxAllocationByTsaId()) ever needs the same
        // correction, but that wasn't part of this request.
        // Lookups are now resolved PER DAY inside
        // addActiveTsasOverviewOperatingCosts() (monthly Cost Breakdown,
        // 2026-10-15) — see costLookups().
        $productCardLookups = 'product';
        // perProductByTsaId() -> dailyRateByTsaId() (2026-10-06, same
        // reversal as buildTeamDailyRows()'s own overview-card fix, same
        // day — see that one's own doc comment) — the TELESALES/per-team
        // rollup card sums every active TSA's own REAL, undivided Daily
        // Rate now, matching Cost Breakdown's own figure, instead of each
        // TSA's ÷7 product-divided one.
        $rollupLookups = 'rollup';

        // Every TSA-owned row's own per-product-DIVIDED Operating Costs
        // override is stripped back to 0 here, then added back via
        // addActiveTsasOverviewOperatingCosts() — same "EVERY real TSA
        // scoped in always counts, multiplied by the full range day-count"
        // rule as the overall rollup, applied to every product card too
        // (explicit confirmation, 2026-10-02: "the per product i want it
        // will total too" — every real TSA on the team contributes her own
        // per-product share here regardless of whether she has an entry on
        // THIS specific product, same as the rollup regardless of whether
        // she has an entry at all).
        $cards = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $sellingKeys, $operatingKeys, $dates, $dateFrom, $dateTo, $onlyTsaIds, $onlyOrderTeam, $productCardLookups, $leadCounts, $grossSalesMap, $numberOfOrdersMap, $cancelledMap) {
            $pooledRaw = $groupProducts->flatMap(fn (Product $p) => $rawByProductAndDate->get($p->id)->flatMap(fn ($rowsForDate) => $rowsForDate))
                ->map(fn ($row) => isset($row['tsa_id']) && $row['tsa_id'] !== null ? array_merge($row, array_fill_keys($operatingKeys, 0.0)) : $row);

            // Gross Sales/Number of Orders (2026-10-10) — top up
            // $pooledRaw's own summed gross_sales/number_of_orders BEFORE
            // sum()/derive() ever runs, not a post-hoc field overwrite
            // afterward like totalRealLeads() does for Number of Leads:
            // both cascade into derive()'s whole formula chain (Returns/
            // Delivered/Gross Profit/Net Income for Gross Sales;
            // conversion_rate/fulfillment_fee for Number of Orders), so
            // patching either onto an already-derived row would leave
            // every one of those stale. Same "missing entry ≠ zero" gap
            // as Number of Leads (a TSA with real matched upsell orders
            // but NO saved ExpectedIncomeEntry row anywhere never appears
            // in $pooledRaw at all — rawByProductAndDateAllTsas()'s own
            // empty-cell early return) — totalRealGrossSales() computes
            // the group's REAL total straight from matched orders;
            // whatever $pooledRaw already summed (from real, saved entry
            // rows) is the FLOOR that total can never be below, so the gap
            // (if any) is folded in as one extra synthetic row rather than
            // replacing the real summed figure outright — preserves every
            // other field a real entry row might carry (Selling Costs,
            // etc.) exactly as entered.
            $realGrossSales = $this->totalRealGrossSales($grossSalesMap, $groupProducts, $onlyOrderTeam, $dateFrom, $dateTo);
            $pooledGrossSales = (float) $pooledRaw->sum('gross_sales');
            $grossSalesGap = $realGrossSales - $pooledGrossSales;
            $realOrders = $this->totalRealGrossSales($numberOfOrdersMap, $groupProducts, $onlyOrderTeam, $dateFrom, $dateTo);
            $pooledOrders = (float) $pooledRaw->sum('number_of_orders');
            $ordersGap = $realOrders - $pooledOrders;
            // Cancelled (2026-10-13) — same pre-sum() top-up, same reason
            // (cascades into Delivered/Gross Profit). See
            // ProductPerformance::tally()'s own cancelled_upsell_sales doc
            // comment for the basis.
            $realCancelled = $this->totalRealGrossSales($cancelledMap, $groupProducts, $onlyOrderTeam, $dateFrom, $dateTo);
            $pooledCancelled = (float) $pooledRaw->sum('cancelled');
            $cancelledGap = $realCancelled - $pooledCancelled;
            if (abs($grossSalesGap) > 0.005 || abs($ordersGap) > 0.005 || abs($cancelledGap) > 0.005) {
                $pooledRaw = $pooledRaw->push(['gross_sales' => $grossSalesGap, 'number_of_orders' => $ordersGap, 'cancelled' => $cancelledGap]);
            }

            $derived = ExpectedIncomeCalculator::sum($pooledRaw->all(), $sellingKeys, $operatingKeys);
            // Every product now gets a card (2026-10-06 — see index()'s
            // own doc comment), but the per-TSA Operating Costs/Tax
            // Allocation SHARE this method adds back on is only a real
            // number for a FLAGGED product (TsaDailyRateService::
            // flaggedProductRows()'s own divisor only counts flagged
            // products at all) — an unflagged one has no share of that
            // cost to begin with, so it must show its raw (manually-saved,
            // un-allocated) operating_lines/tax_allocation instead of
            // silently inheriting the exact same figure every flagged
            // card shows (confirmed live, 2026-10-06: "it should be all
            // products has product card ... but it has no cost like in
            // the Operating Costs row" — same gate as buildTeamDailyRows()'s
            // identical fix, same day).
            $derived = $groupProducts->first()->has_cost_allocation
                ? $this->addActiveTsasOverviewOperatingCosts($derived, $dates, $onlyTsaIds, $productCardLookups)
                : $derived;

            // Number of Leads on a (grouped-or-not) summary card must be
            // the real tally for EVERY member product, independent of
            // whether any ExpectedIncomeEntry row exists at all — root-
            // caused live, 2026-10-09 (screenshot): CLEAR SIGHT showed 20
            // on Leads Report but the LUMIEYES/CLEAR SIGHT combined card
            // only showed 13, because $pooledRaw above only ever sees a
            // lead count on a (product, tsa, day) cell that ALREADY has a
            // saved entry row — a TSA with real matched orders but no
            // Expected Income entry yet for that product/day was invisible
            // to this card entirely, same class of gap
            // addActiveTsasOverviewOperatingCosts() already solves for
            // Salaries/Operating Costs/Tax Allocation. Summed directly
            // here instead, from the full-roster $leadCounts map computed
            // once above (see buildSummaryRow()'s own doc comment on why
            // matchingOrders() needs that full roster, not just this
            // group's own 2 members). Scoped by $onlyOrderTeam now (4th
            // fix — see leadCountsByProductAndDate()'s own doc comment),
            // NOT $onlyTsaIds — the two are different restrictions
            // ($onlyTsaIds still scopes Salaries/Operating Costs above,
            // unrelated to which Order.team an order carries).
            $derived['number_of_leads'] = $this->totalRealLeads($leadCounts, $groupProducts, $onlyOrderTeam, $dateFrom, $dateTo);

            return $derived;
        });

        // Every product/day's own RAW row (not $cards's own already-derived
        // output) — root-caused live, 2026-09-28: sum()'s own $row[$key]
        // lookups only ever find a Selling/Operating line as a TOP-LEVEL
        // array key, but a derive()d row nests every one of those under
        // selling_lines/operating_lines instead, so re-summing already-
        // derived rows silently reads 0 for every manually-entered line
        // (confirmed live: a 500 Advertising Cost vanished from this
        // overall total while still showing correctly on every individual
        // product card). Every OTHER real total on this page (Gross Sales,
        // Cancelled, etc.) escaped this bug only because derive() also
        // returns those as top-level keys, purely by coincidence of which
        // keys happen to be duplicated at both levels.
        // Every TSA-owned row's own operating-cost keys are stripped back
        // to 0 here (undoing rawByProductAndDateAllTsas()'s own per-product
        // DIVIDED override) before summing for the ROLLUP total only — root-
        // caused live, 2026-10-01 (screenshot): Mariel's own overview card
        // showed Salaries 243.31 but the TELESALES rollup only showed
        // 69.52. That divided override is the exact same figure repeated on
        // EVERY one of her product cards for display purposes (confirmed
        // live), not an additive slice of her total — summing it once per
        // product row that happens to exist is correct only when she has a
        // row on EVERY flagged product that day, silently under-counting
        // her otherwise (1 entry out of 7 flagged products pooled only
        // ~2 products' worth). $cards above is built from the UNSTRIPPED
        // $rawByProductAndDate on purpose — only the rollup's own role
        // ("the totals of the per-TSA cards", explicit confirmation,
        // 2026-10-01) requires her UNDIVIDED daily total added back once
        // per TSA per day instead, via addActiveTsasOverviewOperatingCosts()
        // below — completely independent of how many product rows exist.
        $allRaw = $rawByProductAndDate->flatMap(fn ($byDate) => $byDate->flatMap(fn ($rowsForDate) => $rowsForDate))
            ->map(fn ($row) => isset($row['tsa_id']) && $row['tsa_id'] !== null ? array_merge($row, array_fill_keys($operatingKeys, 0.0)) : $row);
        // Gross Sales (2026-10-10, CORRECTED same day — see
        // teamUpsellGrossSales()'s own doc comment for the full root
        // cause/screenshot story): the ROLLUP's own Gross Sales no longer
        // reuses totalRealGrossSales($grossSalesMap, ...) — that map is
        // built from grossSalesByProductAndDate()'s own per-PRODUCT
        // matchingOrders() step, which silently drops any real upsell
        // order that doesn't cleanly match a configured Product's own
        // keyword/ID roster. The rollup has no per-product concept to
        // begin with, so it now mirrors Summary Sales Report's own
        // product-agnostic tally() basis exactly via
        // teamUpsellGrossSales() — guaranteed to match that page's own
        // TEAM TOTAL figure, not just approximate it.
        // Gross Sales + Number of Orders fetched/tallied together in ONE
        // Order query (perf fix, 2026-10-12, PART 5 — see
        // rawByProductAndDateAllTsas()'s own doc comment for the sibling
        // fix same pass) — teamUpsellGrossSales()/teamUpsellOrderCount()
        // used to each independently re-fetch and re-tally() the exact
        // same team-roster-scoped order set for the exact same
        // ($onlyOrderTeam, $dateFrom, $dateTo), called back-to-back right
        // here with identical arguments. teamNetIncomeByDate() calls
        // buildSummaryRow() once PER TEAM PER DAY, so this doubled query
        // was itself doubled again across the whole range (9 days × 2
        // teams = 18 calls × 2 queries = 36 Order-table round trips just
        // for this one pair of figures). Both values still come from the
        // SAME tally() call teamUpsellGrossSales()/teamUpsellOrderCount()
        // each made separately — no behavior change, just one fetch
        // instead of two.
        ['grossSales' => $realGrossSales, 'orders' => $realOrders, 'cancelled' => $realCancelled] = $this->teamUpsellTotals($onlyOrderTeam, $dateFrom, $dateTo);
        $pooledGrossSales = (float) $allRaw->sum('gross_sales');
        $grossSalesGap = $realGrossSales - $pooledGrossSales;
        $pooledOrders = (float) $allRaw->sum('number_of_orders');
        $ordersGap = $realOrders - $pooledOrders;
        // Cancelled (2026-10-13) — same pre-sum() top-up as Gross Sales/
        // Number of Orders directly above, same reasoning (cascades into
        // Delivered/Gross Profit, can't be patched onto an already-derived
        // row). See ProductPerformance::tally()'s own cancelled_upsell_sales
        // doc comment for the basis.
        $pooledCancelled = (float) $allRaw->sum('cancelled');
        $cancelledGap = $realCancelled - $pooledCancelled;
        if (abs($grossSalesGap) > 0.005 || abs($ordersGap) > 0.005 || abs($cancelledGap) > 0.005) {
            $allRaw = $allRaw->push(['gross_sales' => $grossSalesGap, 'number_of_orders' => $ordersGap, 'cancelled' => $cancelledGap]);
        }
        $derived = ExpectedIncomeCalculator::sum($allRaw->all(), $sellingKeys, $operatingKeys);
        $overallTotal = $this->addActiveTsasOverviewOperatingCosts($derived, $dates, $onlyTsaIds, $rollupLookups);
        // Same real-tally fix as $cards above — the TELESALES/per-team
        // rollup's own Number of Leads must be every real lead across
        // every product on the page, not just whatever happened to have a
        // saved entry row. $products here is already the FULL page
        // roster, so this is really just leadCounts->sum() restricted to
        // $onlyOrderTeam — totalRealLeads() still goes through its own
        // product-id filter for consistency with the $cards call above.
        $overallTotal['number_of_leads'] = $this->totalRealLeads($leadCounts, $products, $onlyOrderTeam, $dateFrom, $dateTo);

        return ['cards' => $cards, 'overallTotal' => $overallTotal];
    }

    /** See buildSummaryRow()'s own doc comment above for the bug this
     *  fixes. Adds each active TSA's own daily Operating Costs (Salaries +
     *  the 20 shared pools) on top of $derived's own already-summed
     *  operating costs, ONCE PER DAY IN THE WHOLE SELECTED RANGE for every
     *  TSA who has AT LEAST ONE entry ANYWHERE in that range (explicit
     *  correction, 2026-10-01: "every day is different gross sell right?
     *  ... it should be all cards will be multiplied" / confirmed
     *  explicitly: a TSA active on even ONE day of a picked range is
     *  assumed staffed for the WHOLE range, same as Gross Sales genuinely
     *  varying per day while her daily rate doesn't — reverses this
     *  method's own earlier "only days she actually has a row" design,
     *  which under-multiplied her cost whenever she hadn't logged a sale on
     *  every single day in the range).
     *
     *  Which lookup array is passed in by the caller decides overview vs
     *  product-card scope (both via $dailyRateByTsaId's own parameter —
     *  see buildSummaryRow()'s own $rollupLookups/$productCardLookups):
     *  the overall "TELESALES" rollup passes her REAL, UNDIVIDED Daily
     *  Rate (dailyRateByTsaId() / dailyCostRow() — corrected 2026-10-06,
     *  was perProductByTsaId() before that) — the rollup's own role is
     *  "the totals of the per-TSA cards" (explicit confirmation,
     *  2026-10-01). An individual product card (called with $products
     *  already narrowed to just that card's own group — explicit
     *  correction, 2026-10-01: "it should be all cards will be
     *  multiplied") passes her PRODUCT-card figure instead
     *  (perProductByTsaId() / dailyCostPerProductRow() — divided ONCE by
     *  the page's own flagged-product count, same single division every
     *  other Operating Costs row gets; corrected 2026-10-06 from
     *  perProductByTsaIdTwice()'s own double division, confirmed live:
     *  "in the products it should be divided by 7 like other cost too"),
     *  scoped to only the TSAs who touched ONE OF THIS GROUP'S OWN
     *  products somewhere in the range (the $products passed in IS the
     *  group's own member list in that case, not the whole page's).
     *
     *  $onlyTsaIds restricts which real TSAs get totaled in — null
     *  (site-wide) means EVERY real TSA company-wide, an array means one
     *  team's own real TSA ids.
     *
     *  EVERY real TSA scoped in always counts, for BOTH the rollup AND
     *  every individual product card alike (explicit confirmation,
     *  2026-10-02, after briefly trying "product cards keep the old
     *  'only TSAs who touched this product' scoping" — reversed again,
     *  same day: "the per product i want it will total too") — NOT just
     *  whoever happens to have a saved ExpectedIncomeEntry row (confirmed
     *  live via screenshot: 6 real TSAs, every one of them showing 0.00
     *  Gross Sales/no saved entry at all, yet each one's own Salaries/Tax
     *  Allocation is a real STAFFING cost that applies regardless of
     *  whether she's typed in any sales numbers yet — this now holds
     *  whether $products is the whole page's own list (the rollup) or one
     *  product group's own narrower list (a single product card), since
     *  $onlyTsaIds already carries the real team-scoping either way).
     *
     *  $dailyRateByTsaId/$dailyCostRow/$taxAllocationByTsaId are computed
     *  ONCE by the caller (buildSummaryRow()), not per call here — this is
     *  called once per product CARD (every product group, via
     *  ProductGrouping::rows()) plus once more for the overall rollup, and
     *  each of TsaDailyRateService's own lookups queries CostBreakdownRole/
     *  CostBreakdownPool/CostBreakdownTsaEntry/ProjectionColumn/Setting
     *  itself — recomputing them inside this method made a page with N
     *  product cards re-run that whole chain N+1 times per request
     *  (root-caused live, 2026-10-02: a full-month, per-team filter 500'd
     *  on Railway's real roster/product count, timing out under that
     *  N+1 cost — a 1-day/few-product local test never surfaced it). */
    /** [dailyRateByTsaId, dailyCostRow (no 'total'), taxAllocationByTsaId]
     *  for the CURRENT TsaDailyRateService::contextDate() — 'rollup' = the
     *  undivided overview figures, 'product' = the per-product-divided ones
     *  (see buildSummaryRow()'s own doc comments). Memoized per (scope, day). */
    private static function costLookups(string $scope): array
    {
        $date = TsaDailyRateService::contextDate();

        return \App\Support\RequestMemo::remember("expected_income.cost_lookups.{$scope}.{$date}", fn () => $scope === 'product'
            ? [
                TsaDailyRateService::perProductByTsaId(),
                collect(TsaDailyRateService::dailyCostPerProductRow())->except('total')->all(),
                TsaDailyRateService::perProductTaxAllocationByTsaId(),
                TsaDailyRateService::dailyCostPerProductRow(), // with 'total', as rawByProductAndDateAllTsas() always merged it
            ]
            : [
                TsaDailyRateService::dailyRateByTsaId(),
                collect(TsaDailyRateService::dailyCostRow())->except('total')->all(),
                TsaDailyRateService::taxAllocationByTsaId(),
                TsaDailyRateService::dailyCostRow(), // with 'total'
            ]);
    }

    /** One TSA's own card overrides for the CURRENT context day — product
     *  card ('p') / overview card ('o') Operating Costs, and their Tax
     *  Allocation ('pt'/'ot'). All zero on a day she's not on the roster
     *  (removed before it). Same rules buildTeamDailyRows() always applied
     *  (0-day pools zeroed, TikTok-flagged Salaries 0, tax-map fallback). */
    private static function tsaCardOverrides(TsaShift $tsa): array
    {
        [$perProductRate, , $perProductTax, $costPerProductRow] = self::costLookups('product');
        [$dailyRate, , $tax, $costRow] = self::costLookups('rollup');

        $result = [
            'p'  => array_merge(
                TsaDailyRateService::zeroOperatingCostsIfZeroDay($tsa->id, $costPerProductRow),
                ['salaries' => $tsa->tiktok_upsell ? 0.0 : ($perProductRate[$tsa->id] ?? 0.0)]
            ),
            'o'  => array_merge(
                TsaDailyRateService::zeroOperatingCostsIfZeroDay($tsa->id, $costRow),
                ['salaries' => $tsa->tiktok_upsell ? 0.0 : ($dailyRate[$tsa->id] ?? 0.0)]
            ),
            'pt' => $perProductTax[$tsa->id] ?? TsaDailyRateService::perProductTaxAllocationForTsa($tsa),
            'ot' => $tax[$tsa->id] ?? TsaDailyRateService::taxAllocationForTsa($tsa),
        ];

        if (!TsaDailyRateService::roster()->contains('id', $tsa->id)) {
            $result['p'] = array_map(fn () => 0.0, $result['p']);
            $result['o'] = array_map(fn () => 0.0, $result['o']);
            $result['pt'] = $result['ot'] = 0.0;
        }

        return $result;
    }

    /** tsaCardOverrides() summed day by day across $dates. */
    private static function tsaCardOverridesForDates(TsaShift $tsa, $dates): array
    {
        $sum = ['p' => [], 'o' => [], 'pt' => 0.0, 'ot' => 0.0];
        foreach ($dates as $date) {
            $day = TsaDailyRateService::withDate($date->toDateString(), fn () => self::tsaCardOverrides($tsa));
            foreach (['p', 'o'] as $k) {
                foreach ($day[$k] as $key => $v) {
                    $sum[$k][$key] = ($sum[$k][$key] ?? 0.0) + $v;
                }
            }
            $sum['pt'] += $day['pt'];
            $sum['ot'] += $day['ot'];
        }

        return $sum;
    }

    private function addActiveTsasOverviewOperatingCosts(array $derived, $dates, ?array $onlyTsaIds, string $lookupScope): array
    {
        // $onlyTsaIds === null fallback NO LONGER excludes tiktok_upsell=
        // true TSAs (REVERSED 2026-10-13 — see buildSummary()'s own doc
        // comment). Each day's own figures (monthly Cost Breakdown,
        // 2026-10-15) — that day's month's costs, that day's roster: a TSA
        // removed before a day adds nothing to it, and the shared splits
        // already go to the TSAs still there. Replaces the old "one day ×
        // number of days" multiplication.
        $added = TsaDailyRateService::sumOverDates($dates, function () use ($onlyTsaIds, $lookupScope) {
            [$dailyRateByTsaId, $dailyCostRow, $taxAllocationByTsaId] = self::costLookups($lookupScope);
            $roster = TsaDailyRateService::roster()->keyBy('id');
            $activeTsaIds = $onlyTsaIds !== null
                ? collect($onlyTsaIds)->filter(fn ($id) => $roster->has($id))
                : $roster->keys();

            $day = array_fill_keys(array_merge(array_keys($dailyCostRow), ['salaries']), 0.0);
            $day['__tax'] = 0.0;
            $day['__count'] = (float) $activeTsaIds->count();
            foreach ($activeTsaIds as $tsaId) {
                $tsa = $roster->get($tsaId);
                // A TikTok-flagged TSA's own Salaries is excluded here
                // (narrowed 2026-10-14 — see buildTeamDailyRows()).
                if (!$tsa->tiktok_upsell) {
                    $day['salaries'] += $dailyRateByTsaId[$tsaId] ?? 0.0;
                }
                // A 0-day TSA contributes none of her own pool share
                // (2026-10-06 — see zeroOperatingCostsIfZeroDay()).
                foreach (TsaDailyRateService::zeroOperatingCostsIfZeroDay($tsaId, $dailyCostRow) as $key => $amount) {
                    $day[$key] += $amount;
                }
                $day['__tax'] += $taxAllocationByTsaId[$tsaId] ?? TsaDailyRateService::taxAllocationForTsa($tsa);
            }

            return $day;
        });

        if (!is_array($added) || ($added['__count'] ?? 0) == 0) {
            return $derived;
        }
        $addedTaxAllocation = $added['__tax'] ?? 0.0;
        unset($added['__tax'], $added['__count']);
        $addedOperatingCosts = $added;

        $operatingLines = collect($derived['operating_lines'])->map(fn ($value, $key) => $value + ($addedOperatingCosts[$key] ?? 0.0));
        $totalOperatingCosts = $operatingLines->sum();
        $grossSales = $derived['gross_sales'];
        $netIncome = $derived['income_before_opex'] - $totalOperatingCosts;

        $derived = array_merge($derived, [
            'operating_lines' => $operatingLines,
            'total_operating_costs' => $totalOperatingCosts,
            'total_operating_costs_pct' => $grossSales > 0 ? $totalOperatingCosts / $grossSales : 0.0,
            'net_income' => $netIncome,
            'net_income_pct' => $grossSales > 0 ? $netIncome / $grossSales : 0.0,
        ]);

        return ExpectedIncomeCalculator::withOverriddenTaxAllocation($derived, $derived['tax_allocation'] + $addedTaxAllocation);
    }

    /** The ALL view's own daily rows — one row of cards PER calendar day,
     *  every product's own card, tsa_id NULL — unchanged in substance from
     *  before this feature. */
    private function buildAllDailyRows($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys): array
    {
        ['raw' => $rawByProductAndDate, 'entriesByKey' => $entriesByKey] = $this->rawByProductAndDate($products, null, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys);

        // A multi-day range sums into ONE read-only block instead of one
        // editable block per day (explicit decision, 2026-10-02: "it
        // should be adding oct 1 and 2 data right?" — the per-day stacking
        // below predates this and was never meant to look like a "the
        // range isn't applying" dead end on a >1-day filter; see
        // isRangeSummed's own doc comment on buildTeamDailyRows() for why
        // this can't just ALSO stay editable). A single-day range (the
        // common case, including every autosave day-to-day) is completely
        // unaffected — same per-day editable cards as always.
        if ($dates->count() > 1) {
            $rangeRows = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $dates, $sellingKeys, $operatingKeys) {
                $pooled = $groupProducts->flatMap(fn (Product $p) => $dates->map(fn ($date) => $rawByProductAndDate->get($p->id)->get($date->toDateString())));
                return ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
            });
            $rangeOverallTotal = ExpectedIncomeCalculator::sum(
                $products->flatMap(fn (Product $p) => $dates->map(fn ($date) => $rawByProductAndDate->get($p->id)->get($date->toDateString())))->all(),
                $sellingKeys, $operatingKeys
            );

            return [
                'dailyRows'          => collect(),
                'dailyOverallTotals' => collect(),
                'dailyByKey'         => $entriesByKey,
                'tsaRows'            => null,
                'isRangeSummed'      => true,
                'rangeRows'          => $rangeRows,
                'rangeOverallTotal'  => $rangeOverallTotal,
            ];
        }

        // Per-day display rows (product OR group) — built once here rather
        // than inside the view's own @foreach so the view never has to
        // know about ProductGrouping at all, same "controller owns the row
        // plan" convention as DSPPR's own $rows.
        $dailyRows = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys) {
            $dateStr = $date->toDateString();
            $rows = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $dateStr, $sellingKeys, $operatingKeys) {
                $pooled = $groupProducts->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr));
                return ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
            });
            return [$dateStr => $rows];
        });

        // Each day's own "TELESALES" rollup card, summed from
        // every product's own RAW row for that day (ungrouped, all
        // products flattened) — NOT from $dailyRows' own already-derived
        // output, same root cause and fix as $summaryOverallTotal above.
        $dailyOverallTotals = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys) {
            $dateStr = $date->toDateString();
            $dayRaw = $products->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr))->all();
            return [$dateStr => ExpectedIncomeCalculator::sum($dayRaw, $sellingKeys, $operatingKeys)];
        });

        return [
            'dailyRows'          => $dailyRows,
            'dailyOverallTotals' => $dailyOverallTotals,
            'dailyByKey'         => $entriesByKey,
            'tsaRows'            => null,
            'isRangeSummed'      => false,
            'rangeRows'          => null,
            'rangeOverallTotal'  => null,
        ];
    }

    /** A specific team's own daily rows — confirmed live, 2026-09-30, from
     *  a screenshot: "in the down the yellow is only TSA NAMES" — the
     *  SAME per-day stacking as buildAllDailyRows() above, just with the
     *  overall "TELESALES" card replaced by ONE block PER REAL
     *  TSA on that team (her own name, her own product cards), repeated
     *  for every date. Every card here reads/writes ExpectedIncomeEntry
     *  rows with a real tsa_id — completely independent numbers from the
     *  "ALL" view's own tsa_id-NULL rows. */
    private function buildTeamDailyRows($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, array $teamConfig): array
    {
        // A TikTok-flagged TSA now ALSO gets her own real card block on
        // her real team's own filter (REVERSED 2026-10-13, explicit
        // request — see buildSummary()'s own doc comment for the full "she
        // genuinely does both jobs" reasoning) — she gets BOTH this block
        // (her real Pancake-order revenue) AND her own editable card stack
        // under the TIKTOK TEAM filter (buildTiktokRows()), since the two
        // are independent income streams.
        $tsas = TsaShift::where('team', $teamConfig['order_team'])->orderBy('sort_order')->get();

        // Every real TSA's own Daily Rate / Product (explicit request,
        // 2026-09-30: "the salaries row is based to the Daily Rate /
        // Product") — the SAME figure Cost Breakdown's own salary table
        // shows, used for her own "[TSA NAME]" overview card's own
        // Salaries. Each individual PRODUCT card instead divides that
        // SAME figure by product count (explicit correction, 2026-10-01:
        // "the 243.31 is the TSA card and in the products, it should be
        // 243.31 / 7 like the other costs" — same two-tier pattern as the
        // pool rows below, dailyCostRow() → dailyCostPerProductRow()).
        //
        // REVERSED 2026-10-06 (explicit follow-up, from a direct Cost
        // Breakdown-vs-Expected-Income comparison screenshot: "the
        // salaries per tsa card is wrong it should be the Daily Rate
        // (÷24) in the cost breakdown page") — her own "[TSA NAME]"
        // overview card now shows her REAL, undivided Daily Rate
        // (dailyRateByTsaId(), ÷24 only — matches Cost Breakdown's own
        // column exactly) instead of perProductByTsaId()'s own ÷7 figure,
        // which the 2026-10-01 decision above had it showing instead.
        // Individual PRODUCT cards ALSO corrected, immediately after
        // (same day, explicit follow-up: "in the products it should be
        // divided by 7 like other cost too" — confirmed live, 1,682.86 ÷
        // 7 ≈ 240.41 expected) — a product card's own Salaries now
        // divides the real Daily Rate by product count ONCE
        // (perProductByTsaId()), same single division every other
        // Operating Costs row already gets, not perProductByTsaIdTwice()'s
        // two (that double-division only made sense when the overview
        // card itself was ALSO divided once — now that it isn't, dividing
        // the product card twice from the true rate was dividing by 49,
        // not 7). Both computed once per request (not per card) since
        // they're identical for every one of a TSA's own cards on a given
        // day.
        // (per-day cost lookups now live in tsaCardOverrides(), 2026-10-15)

        // Her own Tax Allocation (explicit request, 2026-10-02: "the tax
        // allocation in tsa cards is should be not editable") — same
        // two-tier "overview undivided, product divided again by product
        // count" pattern as Salaries above, sourced from Cost Breakdown's
        // own Monthly Tax/Daily Tax columns via TsaDailyRateService (see
        // its own doc comment for the confirmed formula). Unlike Operating
        // Costs, this isn't merged into $productCardOverrides/
        // $overviewCardOverrides below — it's a top-level field, not one of
        // operating_lines' own keys, so it needs its own override call
        // (withOverriddenTaxAllocation(), not withOverriddenOperatingCosts()).
        // (per-day cost lookups now live in tsaCardOverrides(), 2026-10-15)

        // Every OTHER Operating Costs row (Communication Allowance, 13th
        // Month Allowance, SIL, ... every shared pool except Salaries —
        // explicit follow-up, 2026-09-30: "the daily cost is it is this
        // [pool list]") sources Cost Breakdown's own "Daily Cost per
        // product" row on each PRODUCT card — pool amount ÷ real TSA count
        // ÷ 24 ÷ checked-product count (explicit correction, 2026-09-30:
        // "13th Month Allowance / it should divided by number of
        // product"). Her own "[TSA NAME]" overview card does NOT divide by
        // product count (explicit follow-up, same day: "in the product
        // cards only okay? ... not in tsa name card") — it sources the
        // plain "Daily Cost" row instead, one level up the chain. Neither
        // is scoped to any specific TSA (same figure on every card,
        // confirmed live via screenshot: two different TSAs' cards both
        // showed the same figures), so both are computed once for the
        // whole request rather than per TSA.
        // (per-day cost lookups now live in tsaCardOverrides(), 2026-10-15)

        // Computed ONCE for the whole requested range, then reused for
        // EVERY TSA's own card stack below — perf fix, 2026-10-11, same
        // root cause/fix as netIncomeByTsaAndDate()'s own identical
        // change (see that method's own doc comment): without this,
        // rawByProductAndDate() below would recompute both maps from
        // scratch per TSA (~12 real TSAs = ~12 full team x product
        // matchingOrders() passes over the whole range instead of 1).
        $leadCounts = $this->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo);
        $grossSalesAndOrders = $this->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo);

        // A multi-day range sums into ONE read-only block per TSA instead
        // of one editable block per day (explicit decision, 2026-10-02:
        // "it should be adding oct 1 and 2 data right?"). Can't just ALSO
        // stay editable here — every input's data-action autosaves to one
        // specific (product, tsa, DATE) row, so a card summing 2+ days has
        // no single date left to save an edit into; same reason the top
        // range-summary row (buildSummary()) has always been read-only.
        // Pick a 1-day range to get editable inputs back, same as the ALL
        // view (see buildAllDailyRows()'s own doc comment on this).
        $isRangeSummed = $dates->count() > 1;

        $tsaRows = $tsas->map(function (TsaShift $tsa) use ($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $isRangeSummed, $leadCounts, $grossSalesAndOrders) {
            ['raw' => $rawByProductAndDate, 'entriesByKey' => $entriesByKey] = $this->rawByProductAndDate($products, $tsa->id, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $leadCounts, $grossSalesAndOrders);
            // A 0-day TSA's own share of every shared pool cost is zeroed
            // here too (2026-10-06, follow-up to the Salaries-only fix
            // above — "the salaries is will stay but the other costs will
            // be 0.00 when 0 days"); her Salaries is already 0.0 via
            // dailyRatePerProductByTsaId/dailyRateByTsaId on their own.
            //
            // A TikTok-flagged TSA's own Salaries is forced to 0.0 here
            // too (narrowed 2026-10-14, explicit request: "it should be
            // in closing team anne salaries row is 0.00 because she has
            // salaries in the tiktok" — follow-up to the 2026-10-13
            // reversal that restored her real team CARD, showing her real
            // Gross Sales/revenue again. Her Daily Rate cost is a single
            // real cost for the person; it belongs on her TikTok card
            // specifically (buildTiktokRows()'s own $totalOverviewSalaries/
            // $totalCardSalaries, unaffected by this), never duplicated
            // onto her real team card too — same scope the earlier same-
            // day fix already applied to the ALL-view TELESALES fold-in,
            // just extended to her own individual card here.
            // Per day (monthly Cost Breakdown + removed-TSA roster,
            // 2026-10-15) — see tsaCardOverrides(). A 1-day card reads its
            // own day; a range sums each day's own figures (below).
            ['p' => $productCardOverrides, 'o' => $overviewCardOverrides, 'pt' => $productCardTaxAllocation, 'ot' => $overviewCardTaxAllocation]
                = TsaDailyRateService::withDate($dates->first()->toDateString(), fn () => self::tsaCardOverrides($tsa));

            if ($isRangeSummed) {
                // $productCardOverrides/$overviewCardOverrides/the tax
                // allocation figures are all DAILY rates (one day's own
                // Salaries/pool share/Tax Allocation) — correct as-is for
                // a 1-day card, but a range summing N days needs each one
                // multiplied by N first, same "× $dayCount" rule
                // addActiveTsasOverviewOperatingCosts() already applies to
                // the top summary row (root-caused live, 2026-10-02: "why
                // is the costs is not doubling when i filter the oct 1 to
                // 2?" — these were being applied at their flat 1-day rate
                // no matter how many days were actually summed).
                // Each day's own figures summed (2026-10-15) instead of
                // "one day × N" — exact across a month change or a TSA
                // removal inside the range.
                ['p' => $productCardOverridesForRange, 'o' => $overviewCardOverridesForRange, 'pt' => $productCardTaxAllocationForRange, 'ot' => $overviewCardTaxAllocationForRange]
                    = self::tsaCardOverridesForDates($tsa, $dates);

                $rangeRows = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $dates, $sellingKeys, $operatingKeys, $productCardOverridesForRange, $productCardTaxAllocationForRange) {
                    $pooled = $groupProducts->flatMap(fn (Product $p) => $dates->map(fn ($date) => $rawByProductAndDate->get($p->id)->get($date->toDateString())));
                    $summed = ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
                    // Every product now gets a card (2026-10-06), but the
                    // per-product Operating Costs/Tax Allocation share only
                    // applies to a FLAGGED product — see buildSummaryRow()'s
                    // identical gate/doc comment for the full reasoning.
                    if (!$groupProducts->first()->has_cost_allocation) {
                        return $summed;
                    }
                    $summed = ExpectedIncomeCalculator::withOverriddenOperatingCosts($summed, $productCardOverridesForRange);
                    return ExpectedIncomeCalculator::withOverriddenTaxAllocation($summed, $productCardTaxAllocationForRange);
                });
                // Gross Sales (2026-10-10) — same pre-sum() top-up as
                // $dailyOverallTotals' own identical fix below (the
                // single-day case), same reasoning, just for the whole
                // selected range at once via a plain BETWEEN query instead
                // of one call per date.
                $rangeOverallTotal = (function () use ($products, $dates, $rawByProductAndDate, $sellingKeys, $operatingKeys, $overviewCardOverridesForRange, $overviewCardTaxAllocationForRange, $tsa, $dateFrom, $dateTo) {
                    $rangeRaw = $products->flatMap(fn (Product $p) => $dates->map(fn ($date) => $rawByProductAndDate->get($p->id)->get($date->toDateString())))->all();
                    $pooledGrossSales = (float) array_sum(array_column($rangeRaw, 'gross_sales'));
                    $grossSalesGap = $this->tsaUpsellGrossSales($tsa, $dateFrom, $dateTo) - $pooledGrossSales;
                    // Cancelled (2026-10-13) — same pre-sum() top-up, same
                    // reason.
                    $pooledCancelled = (float) array_sum(array_column($rangeRaw, 'cancelled'));
                    $cancelledGap = $this->tsaUpsellCancelled($tsa, $dateFrom, $dateTo) - $pooledCancelled;
                    // Number of Orders too — see the single-day block's
                    // own comment below (2026-10-14).
                    $pooledOrders = (float) array_sum(array_column($rangeRaw, 'number_of_orders'));
                    $ordersGap = $this->tsaUpsellOrderCount($tsa, $dateFrom, $dateTo) - $pooledOrders;
                    if (abs($grossSalesGap) > 0.005 || abs($cancelledGap) > 0.005 || abs($ordersGap) > 0.005) {
                        $rangeRaw[] = ['gross_sales' => $grossSalesGap, 'cancelled' => $cancelledGap, 'number_of_orders' => $ordersGap];
                    }
                    $summed = ExpectedIncomeCalculator::sum($rangeRaw, $sellingKeys, $operatingKeys);
                    $summed = ExpectedIncomeCalculator::withOverriddenOperatingCosts($summed, $overviewCardOverridesForRange);
                    return ExpectedIncomeCalculator::withOverriddenTaxAllocation($summed, $overviewCardTaxAllocationForRange);
                })();

                return [
                    'tsa' => $tsa,
                    'rangeRows' => $rangeRows,
                    'rangeOverallTotal' => $rangeOverallTotal,
                ];
            }

            $dailyRows = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys, $productCardOverrides, $productCardTaxAllocation) {
                $dateStr = $date->toDateString();
                $rows = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $dateStr, $sellingKeys, $operatingKeys, $productCardOverrides, $productCardTaxAllocation) {
                    $pooled = $groupProducts->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr));
                    $summed = ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
                    // Every product now gets a card (2026-10-06), but the
                    // per-product Operating Costs/Tax Allocation share only
                    // applies to a FLAGGED product — see buildSummaryRow()'s
                    // identical gate/doc comment for the full reasoning.
                    if (!$groupProducts->first()->has_cost_allocation) {
                        return $summed;
                    }
                    $summed = ExpectedIncomeCalculator::withOverriddenOperatingCosts($summed, $productCardOverrides);
                    return ExpectedIncomeCalculator::withOverriddenTaxAllocation($summed, $productCardTaxAllocation);
                });
                return [$dateStr => $rows];
            });

            // Her own "[TSA NAME]" overview card — a read-only rollup of
            // HER OWN product cards for that one day, same role
            // "TELESALES" plays in the ALL view (confirmed live,
            // 2026-09-30, from a screenshot: "the yellow is stil has this,
            // it is over all of the individual tsa ... but it is not
            // editable") — summed from her own RAW rows for that day
            // (ungrouped, all her own products flattened), NOT from
            // $dailyRows' own already-derived output, same root cause/fix
            // as buildAllDailyRows()'s own $dailyOverallTotals. Uses
            // $overviewCardOverrides, NOT $productCardOverrides — see this
            // method's own doc comment above on why the two diverge.
            //
            // Gross Sales (2026-10-10, explicit fix, root-caused live via
            // screenshot: Katherine Chua's own overview card showed
            // 3,638.00 while Dashboard/Summary Sales Report both showed
            // her real 2,319.00) — same pre-sum() top-up as
            // buildSummaryRow()'s own TEAM rollup fix earlier the same
            // day: $dayRaw's own per-product rows (via
            // grossSalesByProductAndDate()'s own per-product
            // matchingOrders() step) can silently drop a real order that
            // doesn't cleanly match any of her flagged products' own
            // keyword/ID roster — invisible to EVERY one of her product
            // cards, and therefore to their sum here too. Her real,
            // product-agnostic total (tsaUpsellGrossSales(), same
            // tally()['upsell_sales'] basis Dashboard/Summary Sales Report
            // already use) is the floor that total can never be below;
            // the gap (if any) is folded in as one extra synthetic row
            // before sum()/derive() runs, same "can't patch Gross Sales
            // onto an already-derived row, it cascades into the whole P&L"
            // reasoning as the team rollup fix. Computed PER DATE inside
            // the loop below (not once for the whole range) since each
            // date needs its own day-specific real total, same "DATE(),
            // not BETWEEN, for a single day's own figure" convention this
            // page already follows elsewhere.
            $dailyOverallTotals = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys, $overviewCardOverrides, $overviewCardTaxAllocation, $tsa) {
                $dateStr = $date->toDateString();
                $dayRaw = $products->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr))->all();
                $pooledGrossSales = (float) array_sum(array_column($dayRaw, 'gross_sales'));
                $grossSalesGap = $this->tsaUpsellGrossSales($tsa, $dateStr, $dateStr) - $pooledGrossSales;
                // Cancelled (2026-10-13) — same pre-sum() top-up, same
                // reason.
                $pooledCancelled = (float) array_sum(array_column($dayRaw, 'cancelled'));
                $cancelledGap = $this->tsaUpsellCancelled($tsa, $dateStr, $dateStr) - $pooledCancelled;
                // Number of Orders too (bug fix, 2026-10-14, explicit report:
                // Lika's Oct 5 card read 7 orders / ₱7,003 — the ₱1,700
                // SEPARATE PARCEL order #1376764 matched no product card, so
                // its sales arrived via this gap but its order never did; the
                // team-level rollup's own gap already topped up all three).
                $pooledOrders = (float) array_sum(array_column($dayRaw, 'number_of_orders'));
                $ordersGap = $this->tsaUpsellOrderCount($tsa, $dateStr, $dateStr) - $pooledOrders;
                if (abs($grossSalesGap) > 0.005 || abs($cancelledGap) > 0.005 || abs($ordersGap) > 0.005) {
                    $dayRaw[] = ['gross_sales' => $grossSalesGap, 'cancelled' => $cancelledGap, 'number_of_orders' => $ordersGap];
                }
                $summed = ExpectedIncomeCalculator::sum($dayRaw, $sellingKeys, $operatingKeys);
                $summed = ExpectedIncomeCalculator::withOverriddenOperatingCosts($summed, $overviewCardOverrides);
                return [$dateStr => ExpectedIncomeCalculator::withOverriddenTaxAllocation($summed, $overviewCardTaxAllocation)];
            });

            return [
                'tsa' => $tsa,
                'dailyRows' => $dailyRows,
                'dailyOverallTotals' => $dailyOverallTotals,
                'dailyByKey' => $entriesByKey,
            ];
        });

        return [
            'isRangeSummed'      => $isRangeSummed,
            'dailyRows'          => collect(),
            'dailyOverallTotals' => collect(),
            'dailyByKey'         => collect(),
            // Eye-hidden in TSA Management (2026-10-15) — display only; the
            // team summary/totals are computed separately and still count her.
            'tsaRows'            => $tsaRows->reject(fn ($row) => $row['tsa']->is_hidden)->values(),
            'rangeRows'          => null,
            'rangeOverallTotal'  => null,
        ];
    }

    /** Expected Income's own 2 fixed TikTok cards — "TIKTOK: SH NATURALS"
     *  and "TIKTOK: NATUREVA" (explicit request, 2026-10-05, real sheet
     *  screenshot) — shown ONLY in a TikTok-flagged TSA's own card stack
     *  (TsaShift.tiktok_upsell) and folded into the page's overall TOTAL.
     *  Deliberately independent of ProductGrouping/TsaDailyRateService/
     *  the lock-toggle machinery every real product card goes through —
     *  no real Product or team backs either card, and every field is
     *  manual (explicit confirmation: no Cost Breakdown automation here),
     *  so reusing that machinery would be wiring complexity this doesn't
     *  need. Returns, per TikTok-flagged TSA: her own per-day raw rows
     *  (one per card) for the editable 1-day case, or her own range-summed
     *  derived totals for a >1-day range — same isRangeSummed split as
     *  buildTeamDailyRows() above, for the same reason (an editable input
     *  needs exactly one date to save into). Also returns the SITE-WIDE
     *  total (every flagged TSA, every date in range, both cards summed
     *  together) for the TOTAL section — see buildTiktokOverallTotal(). */
    /** A TikTok card's raw row with Gross Sales / Number of Orders /
     *  Cancelled replaced by the TikTok-tagged POS order totals — always,
     *  zeros included, so a manually-typed figure can never leak through —
     *  from TiktokUpsellTotals::AUTOMATED_FROM on; earlier days keep their
     *  typed figures untouched. */
    private static function withTiktokAuto(array $raw, \Illuminate\Support\Collection $autoByKey, int $tsaId, string $cardKey, string $dateStr): array
    {
        if (!TiktokUpsellTotals::isAutomated($dateStr)) {
            return $raw;
        }

        return array_merge($raw, $autoByKey->get("{$tsaId}:{$cardKey}:{$dateStr}", array_fill_keys(TiktokUpsellTotals::FIELDS, 0)));
    }

    private function buildTiktokRows($dates, string $dateFrom, string $dateTo): array
    {
        $flaggedTsas = TsaShift::where('tiktok_upsell', true)->orderBy('sort_order')->get();
        if ($flaggedTsas->isEmpty()) {
            $emptyCards = collect(ExpectedIncomeTiktokEntry::CARDS)->map(
                fn ($label, $cardKey) => ['key' => $cardKey, 'label' => $label, 'derived' => ExpectedIncomeCalculator::derive([])]
            )->values();

            return ['tiktokTsaRows' => collect(), 'tiktokOverallTotal' => ExpectedIncomeCalculator::derive([]), 'tiktokCardTotals' => $emptyCards];
        }

        $entriesByKey = ExpectedIncomeTiktokEntry::whereIn('tsa_id', $flaggedTsas->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (ExpectedIncomeTiktokEntry $e) => $e->tsa_id . ':' . $e->card_key . ':' . $e->entry_date->toDateString());

        $emptyRaw = fn (int $tsaId, string $cardKey, string $dateStr) => ['tsa_id' => $tsaId, 'card_key' => $cardKey, 'entry_date' => $dateStr];

        // Gross Sales / Number of Orders / Cancelled come from TikTok-tagged
        // POS orders, never a manual entry (explicit request, 2026-10-14 —
        // see TiktokUpsellTotals' own doc comment). Always overrides,
        // zeros included, so a stale manually-typed figure can't linger.
        $autoByKey = TiktokUpsellTotals::byTsaCardAndDate($dateFrom, $dateTo);

        $isRangeSummed = $dates->count() > 1;
        $dayCount = $dates->count();
        $allRawRows = [];

        // Salaries — the ONLY cost figure a TikTok card shows (explicit
        // request, 2026-10-07: "the only will have cost in this is the
        // salaries row that is Daily Rate (÷24) in the cost breakdown") —
        // every other Operating Costs row stays 0.00, same as derive()
        // already produces for a manual TikTok raw row with no Cost
        // Breakdown wiring (see this method's own class-level doc comment
        // on why TikTok cards are deliberately independent of
        // TsaDailyRateService's automation). Same two-tier split as a real
        // team's own cards (buildTeamDailyRows()): the overview card shows
        // her REAL, undivided Daily Rate; each of her own TikTok cards
        // shows that SAME figure divided by her card count (2 — "TIKTOK:
        // SH NATURALS" / "TIKTOK: NATUREVA"), matching
        // TsaDailyRateService::perProductByTsaId()'s identical "overview
        // whole, each card its own slice" rule for real product cards. A
        // multi-day range multiplies both by day count first, same
        // "DAILY rate × N" rule buildTeamDailyRows() already applies.
        // Daily Rate summed day by day (monthly Cost Breakdown + removed-TSA
        // roster, 2026-10-15) instead of "one day × N".
        $rangeRateByTsaId = $flaggedTsas->mapWithKeys(fn (TsaShift $tsa) => [$tsa->id => (float) TsaDailyRateService::sumOverDates(
            $dates,
            fn () => TsaDailyRateService::roster()->contains('id', $tsa->id) ? (self::costLookups('rollup')[0][$tsa->id] ?? 0.0) : 0.0
        )])->all();
        $tiktokCardCount = count(ExpectedIncomeTiktokEntry::CARDS);

        $tiktokTsaRows = $flaggedTsas->map(function (TsaShift $tsa) use ($dates, $entriesByKey, $emptyRaw, $autoByKey, $isRangeSummed, $rangeRateByTsaId, $tiktokCardCount, &$allRawRows) {
            $overviewSalaries = $rangeRateByTsaId[$tsa->id] ?? 0.0;
            $cardSalaries = $overviewSalaries / $tiktokCardCount;
            // Every (card, date) combo for this one TSA — entriesByKey
            // misses a (card, date) with nothing ever saved, so $emptyRaw
            // fills the gap rather than silently skipping that date out
            // of the sum (same "always one row per cell, real or empty"
            // convention as rawByProductAndDate()'s own doc comment).
            $rowsByCard = collect(ExpectedIncomeTiktokEntry::CARDS)->mapWithKeys(function ($label, $cardKey) use ($tsa, $dates, $entriesByKey, $emptyRaw, $autoByKey, &$allRawRows) {
                $rows = $dates->map(function ($date) use ($tsa, $cardKey, $entriesByKey, $emptyRaw, $autoByKey, &$allRawRows) {
                    $dateStr = $date->toDateString();
                    $entry = $entriesByKey->get("{$tsa->id}:{$cardKey}:{$dateStr}");
                    $raw = self::withTiktokAuto($entry?->toArray() ?? $emptyRaw($tsa->id, $cardKey, $dateStr), $autoByKey, $tsa->id, $cardKey, $dateStr);
                    $allRawRows[] = $raw;
                    return ['raw' => $raw, 'entry' => $entry];
                });
                return [$cardKey => $rows];
            });

            // Her own "[TSA NAME]" overview card for the TIKTOK TEAM filter
            // (explicit follow-up, 2026-10-06: "in the tiktok it should be
            // have tsa card too") — same role as buildTeamDailyRows()'s own
            // overview card (a read-only rollup sitting above her real
            // cards, same visual anchor a real team filter already has),
            // but pooling only HER OWN 2 TikTok cards here, not her real
            // product cards (those stay out of scope for this filter — see
            // this method's own class-level doc comment). Flattens both
            // cards' raw rows across every date in range either way, so a
            // multi-day range sums correctly the same way $cards above does.
            $overallTotal = ExpectedIncomeCalculator::withOverriddenOperatingCosts(
                ExpectedIncomeCalculator::sum($rowsByCard->flatMap(fn ($rows) => $rows->pluck('raw'))->all()),
                ['salaries' => $overviewSalaries]
            );

            if ($isRangeSummed) {
                $cards = $rowsByCard->map(fn ($rows, $cardKey) => [
                    'key' => $cardKey, 'label' => ExpectedIncomeTiktokEntry::CARDS[$cardKey],
                    'derived' => ExpectedIncomeCalculator::withOverriddenOperatingCosts(
                        ExpectedIncomeCalculator::sum($rows->pluck('raw')->all()),
                        ['salaries' => $cardSalaries]
                    ),
                ])->values();

                return ['tsa' => $tsa, 'isRangeSummed' => true, 'cards' => $cards, 'overallTotal' => $overallTotal];
            }

            $dateStr = $dates->first()->toDateString();
            $cards = $rowsByCard->map(function ($rows, $cardKey) use ($cardSalaries) {
                $only = $rows->first();
                return [
                    'key' => $cardKey, 'label' => ExpectedIncomeTiktokEntry::CARDS[$cardKey],
                    'derived' => ExpectedIncomeCalculator::withOverriddenOperatingCosts(
                        ExpectedIncomeCalculator::derive($only['raw']),
                        ['salaries' => $cardSalaries]
                    ),
                    'entry' => $only['entry'],
                    // Lock toggle (explicit request, 2026-10-07: "i want to
                    // have like lock icon too") — one lock PER CARD, same
                    // granularity as a real product card's own lock, not
                    // per-TSA (her SH NATURALS card can be locked
                    // independently of her NATUREVA one).
                    'locked' => (bool) ($only['entry']?->is_locked ?? false),
                ];
            })->values();

            return ['tsa' => $tsa, 'isRangeSummed' => false, 'dateStr' => $dateStr, 'cards' => $cards, 'overallTotal' => $overallTotal];
        });

        // TIKTOK TOTAL's own 2 card-level breakdowns (explicit follow-up,
        // 2026-10-06: "this too is should be change with 2 cards TIKTOK:
        // SH NATURALS and TIKTOK: NATUREVA like total in all tsa inputs")
        // — every flagged TSA's own SAME card summed together, one card
        // per CARD KEY instead of per TSA, same "overall total + its own
        // breakdown cards" shape every other summary row on this page
        // already has (TELESALES + product cards, a real TEAM + its own
        // product cards). $allRawRows already carries every (tsa, card,
        // date) row tagged with its own card_key, so grouping by that key
        // needs no extra query. Salaries folds in here too (explicit
        // request, 2026-10-07 — see $dailyRateByTsaId's own doc comment
        // above) so a card-key total/the site-wide TELESALES figure never
        // disagrees with the per-TSA cards they're summed from: every
        // flagged TSA contributes her own card's slice (Daily Rate ÷ card
        // count × day count) to EACH card-key total, and her full
        // undivided Daily Rate × day count to the overall total — same
        // "overview whole, each card its own slice" split used above.
        $totalCardSalaries = $flaggedTsas->sum(fn (TsaShift $tsa) => ($rangeRateByTsaId[$tsa->id] ?? 0.0) / $tiktokCardCount);
        $totalOverviewSalaries = $flaggedTsas->sum(fn (TsaShift $tsa) => $rangeRateByTsaId[$tsa->id] ?? 0.0);

        $tiktokCardTotals = collect($allRawRows)->groupBy('card_key')->map(
            fn ($rows, $cardKey) => [
                'key' => $cardKey, 'label' => ExpectedIncomeTiktokEntry::CARDS[$cardKey],
                'derived' => ExpectedIncomeCalculator::withOverriddenOperatingCosts(
                    ExpectedIncomeCalculator::sum($rows->all()),
                    ['salaries' => $totalCardSalaries]
                ),
            ]
        )->values();

        return [
            // Keyed by tsa_id (not a plain list) — the view looks this up
            // once per real TSA it's already iterating ($tsaRows in
            // buildTeamDailyRows()'s own output), so a flat lookup avoids
            // a linear search through every flagged TSA per render.
            // Eye-hidden TSAs dropped AFTER the totals below/above (display only).
            'tiktokTsaRows'      => $tiktokTsaRows->reject(fn ($row) => $row['tsa']->is_hidden)->keyBy(fn ($row) => $row['tsa']->id),
            'tiktokOverallTotal' => ExpectedIncomeCalculator::withOverriddenOperatingCosts(
                ExpectedIncomeCalculator::sum($allRawRows),
                ['salaries' => $totalOverviewSalaries]
            ),
            'tiktokCardTotals'   => $tiktokCardTotals,
        ];
    }

    /** Returns ['raw' => ..., 'entriesByKey' => ...]. 'raw': one raw row
     *  per (product, day) in the selected range, ALWAYS — even a
     *  product/day with no ExpectedIncomeEntry AND no custom value at all
     *  still gets an all-zero row. 'entriesByKey': the real
     *  ExpectedIncomeEntry models themselves (or absent, for a never-saved
     *  cell), keyed the same "productId:date" way — the view's own
     *  _card-body.blade.php needs the real MODEL (not the merged raw
     *  array) to seed each editable input's saved value, same "$entry
     *  seeds inputs, $d/derived seeds display" split the page has always
     *  used. $tsaId is null for the "ALL" view's own product-level rows,
     *  or a real TSA's own id for her own rows — see the class's own doc
     *  comment for what each means. Root-caused live, 2026-09-28: typing
     *  ONLY into a custom row's own field (never any built-in field) for a
     *  given product+day creates an ExpectedIncomeCustomValue but no
     *  ExpectedIncomeEntry — iterating $products × $dates directly, rather
     *  than whatever rows happen to exist, guarantees every cell in the
     *  selected range is represented regardless of which table (or
     *  neither) actually has a row for it. */
    private function rawByProductAndDate($products, ?int $tsaId, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, ?\Illuminate\Support\Collection $precomputedLeadCounts = null, ?array $precomputedGrossSalesAndOrders = null)
    {
        // whereDate() >=/<=, not a raw whereBetween() on the date-cast
        // column — root-caused 2026-09-26: entry_date is stored as a full
        // 'Y-m-d H:i:s' datetime string, and SQLite compares whereBetween's
        // plain date-only bounds LEXICOGRAPHICALLY, so '2026-09-26 00:00:00'
        // (the LAST day of a range) sorts AFTER the bound '2026-09-26' and
        // gets silently dropped.
        $entries = ExpectedIncomeEntry::whereIn('product_id', $products->pluck('id'))
            ->where('tsa_id', $tsaId)
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (ExpectedIncomeEntry $e) => $e->product_id . ':' . $e->entry_date->toDateString());

        // Every custom row's own typed-in value for this range, keyed
        // "productId:date:customRowKey" — merged onto each entry's own
        // array below before it ever reaches ExpectedIncomeCalculator, same
        // "controller assembles the full row, calculator just reads array
        // keys" convention the built-in fields already use.
        $customValuesByKey = ExpectedIncomeCustomValue::whereIn('product_id', $products->pluck('id'))
            ->where('tsa_id', $tsaId)
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (ExpectedIncomeCustomValue $v) => $v->product_id . ':' . $v->entry_date->toDateString() . ':' . $v->custom_row_key);

        // Hoisted out of $customValuesFor's own per-cell loop below — perf
        // fix, 2026-10-12, PART 5 (same pass as rawByProductAndDateAllTsas()'s
        // and buildSummaryRow()'s own fixes above). customRowKeys() queries
        // ProjectionCustomRow fresh every call, by design (no static cache
        // across REQUESTS — see that method's own doc comment, a stale
        // cross-request cache risks missing a freshly-added row) — but
        // $customValuesFor used to call it once per (product, date) CELL
        // within this one call, not once per request: for a 9-day/16-product
        // range that's 144 identical queries from ONE rawByProductAndDate()
        // call alone, repeated again per TSA. Computing it once here, before
        // the loop, changes nothing about freshness within this single
        // request/response cycle — every cell in this one call still reads
        // the same already-fetched row list the uncached call would have
        // returned anyway.
        $customRowKeys = ExpectedIncomeCalculator::customRowKeys();

        $customValuesFor = function (int $productId, string $dateStr) use ($customValuesByKey, $customRowKeys) {
            $row = [];
            foreach ($customRowKeys as $key) {
                $row[$key] = (float) ($customValuesByKey->get("{$productId}:{$dateStr}:{$key}")?->value ?? 0);
            }
            return $row;
        };

        // Number of Leads is no longer a manually-typed figure (explicit
        // request, 2026-10-09: "automate it to tally to the overall number
        // of leads per product per tsa" / "tally to the leads report
        // page") — overridden here with the same real Order-based count
        // Leads Report's own TOTAL LEADS column shows for this TSA/product/
        // day, regardless of whatever was last stored on
        // ExpectedIncomeEntry.number_of_leads itself (that column is kept
        // for history/rollback only; see leadCountsByProductAndDate()'s own
        // doc comment for exactly what's counted). $tsaId is null for the
        // ALL view's own product-level rows — same "all TSAs" meaning
        // leadCountsByProductAndDate() already uses elsewhere on this page.
        // $precomputedLeadCounts/$precomputedGrossSalesAndOrders (perf fix,
        // 2026-10-11 — see buildSummaryRow()'s own identical parameters'
        // doc comment for the full story). netIncomeByTsaAndDate() and
        // buildTeamDailyRows() each call this method once PER TSA — with
        // ~12 real TSAs, that's 12 full team x product matchingOrders()
        // passes over the whole range instead of 1, on EVERY Summary Sales
        // Report page load (not just wide ranges) since
        // perTsaPerDayPerformance() always calls netIncomeByTsaAndDate().
        // This is what was still timing out even after the
        // teamNetIncomeByDate()/buildSummaryRow() fix — that fix addressed
        // a DIFFERENT call chain; this is the one perTsaPerDayPerformance()
        // actually goes through on every page load.
        $leadCounts = $precomputedLeadCounts ?? $this->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo);
        $grossSalesAndOrders = $precomputedGrossSalesAndOrders ?? $this->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo);
        ['grossSales' => $grossSalesMap, 'numberOfOrders' => $numberOfOrdersMap, 'cancelled' => $cancelledMap] = $grossSalesAndOrders;

        $raw = $products->mapWithKeys(function (Product $p) use ($dates, $entries, $customValuesFor, $leadCounts, $grossSalesMap, $numberOfOrdersMap, $cancelledMap, $tsaId) {
            return [$p->id => $dates->mapWithKeys(function ($date) use ($p, $entries, $customValuesFor, $leadCounts, $grossSalesMap, $numberOfOrdersMap, $cancelledMap, $tsaId) {
                $dateStr = $date->toDateString();
                $entry = $entries->get($p->id . ':' . $dateStr);
                $row = $entry ? $entry->toArray() : [];
                $row = array_merge($row, $customValuesFor($p->id, $dateStr));
                $row['number_of_leads'] = $this->leadsForTsaProductDate($leadCounts, $tsaId, $p->id, $dateStr);
                $row['gross_sales'] = $this->grossSalesForTsaProductDate($grossSalesMap, $tsaId, $p->id, $dateStr);
                $row['number_of_orders'] = (int) $this->grossSalesForTsaProductDate($numberOfOrdersMap, $tsaId, $p->id, $dateStr);
                $row['cancelled'] = $this->grossSalesForTsaProductDate($cancelledMap, $tsaId, $p->id, $dateStr);
                return [$dateStr => $row];
            })];
        });

        return ['raw' => $raw, 'entriesByKey' => $entries];
    }

    /** Real lead counts per (tsa, product, day) — keyed "tsaId:productId:date"
     *  ('null' in place of tsaId for an order matched to a product but with
     *  no recognized tsa_name), 0 for any combination with no leads at all
     *  rather than a missing key, since every caller merges this straight
     *  onto a raw row without checking isset() first.
     *
     *  Reuses Leads Report's OWN counting logic exactly (explicit request,
     *  2026-10-09, after an earlier Lead-table-based version was flagged as
     *  diverging and explicitly rejected in favor of this: "so the Number
     *  of Leads row will be tally to the leads report page") —
     *  ProductPerformance::matchingOrders() + tally()['total'] per product
     *  per day, the SAME two calls LeadsReportController/TSA Performance/
     *  DSPPR all go through, so a given TSA/product/day here can never
     *  silently drift from what Leads Report's own TOTAL LEADS column shows
     *  for that same cell. Candidate orders per day are pulled the same way
     *  LeadsReportController does (COALESCE(pancake_inserted_at,
     *  pancake_created_at) within that day's bounds) — NOT team-scoped here
     *  (unlike Leads Report's own per-team hourly table), since this method
     *  serves every team's cards on this page from one shared lookup;
     *  matchingOrders() already trusts an order's own item/tag over which
     *  team's hours it fell in anyway (see that method's own doc comment).
     *  A matched order whose own tsa_name doesn't resolve to any real
     *  TsaShift.tsa_key falls into the 'null' bucket — read by the ALL view's
     *  own product-level rows only; a real TSA's own card never reads that
     *  bucket (see rawByProductAndDate()/rawByProductAndDateAllTsas()'s own
     *  $tsaKey lookups), so an unattributed order is visible on the
     *  company-wide product card but correctly absent from every
     *  individual TSA's own card, same as it would be invisible on a
     *  single-TSA filter of Leads Report itself.
     *
     *  $onlyOrderTeam (added same day as a 3rd fix, root-caused live via
     *  screenshot — PTERYLIEF/PTERYGIUM's own merged card showed 58 on
     *  TEAM CLOSING vs Leads Report's real 50): when given a real
     *  `order_team` string (e.g. "SH Naturals"), $dayOrders itself is
     *  pre-filtered to `where('team', $onlyOrderTeam)` BEFORE
     *  matchingOrders() ever runs — the exact same candidate-pool
     *  restriction LeadsReportController always applies
     *  (`$matchPool`/`$dayOrders` queries, both `->where('team',
     *  $orderTeam)`). Filtering by $onlyTsaIds alone (a caller restricting
     *  the OUTPUT to a team's own TSA ids, downstream of this method) is
     *  NOT equivalent and was the actual bug: a TSA whose own historical
     *  orders carry a DIFFERENT Order.team than her currently-filtered
     *  team (a real case — a TSA legitimately working a cross-team
     *  product, matchingOrders()'s own lenient explicit-text-match/combo
     *  rules bypass the team gate for exactly this reason) had those
     *  other-team orders swept into this team's own count, since nothing
     *  upstream of the TSA-id filter ever excluded them by team the way
     *  Leads Report's own candidate pool does.
     *
     *  REBUILT 2026-10-09 (4th same-day fix, after 3 narrower patches each
     *  missed a different angle of the same underlying mismatch — see
     *  this file's own class doc comment for the full history) around
     *  Leads Report's OWN real architecture, confirmed by reading
     *  LeadsReportController::indexAll() directly rather than guessing
     *  again: a product's ALL-view total there is NOT one unscoped
     *  cross-team query — it's the SUM of that product's own count under
     *  EACH real team's own SEPARATELY team-scoped candidate pool
     *  ($teamTables, one per Teams::config() entry, each with its own
     *  `where('team', $orderTeam)` BEFORE matchingOrders() runs), added
     *  together via ProductPerformance::sumRows() (see indexAll()'s own
     *  "Combined table above the per-team sections" comment). A single
     *  team's own page (index()) is just ONE of those same per-team
     *  computations in isolation. Running matchingOrders() ONCE against
     *  an unscoped (or differently-scoped) pool is NOT equivalent to
     *  summing N independently-team-scoped-then-matched runs, because
     *  matchingOrders()'s own team-gate
     *  (`if ($o->team !== $product->team && !$explicitMatch) return
     *  false`) and stale-tag conflict guard behave differently depending
     *  on which orders are even IN the candidate pool being evaluated —
     *  this is why every earlier single-query attempt (unscoped, then
     *  TSA-id-scoped, then single-team-scoped) kept landing on a
     *  different wrong number instead of converging.
     *
     *  This method therefore ALWAYS loops every real team
     *  (Teams::config()), scoping candidate orders to that team's own
     *  `order_team` each time — exactly mirroring indexAll()'s own
     *  $teamTables loop — and keys every count by team too:
     *  "{orderTeam}:{tsaId}:{productId}:{date}". totalRealLeads() below
     *  sums either ONE team's own bucket (a single-team filter) or EVERY
     *  team's own bucket together (the ALL view), matching
     *  index()/indexAll()'s own exact two cases. */
    /** Public entry point for an EXTERNAL caller (outside this class) that
     *  needs BOTH leadCountsByProductAndDate()'s and
     *  grossSalesByProductAndDate()'s own maps computed ONCE for a date
     *  range, to pass into multiple of this class's own methods that each
     *  accept a $precomputedLeadCounts/$precomputedGrossSalesAndOrders
     *  pair (netIncomeByTsaAndDate(), teamNetIncomeByDate(),
     *  buildSummaryRow(), rawByProductAndDate()) — perf fix, 2026-10-11,
     *  PART 4. Root-caused live: TsaSalesReportController::index() calls
     *  BOTH netIncomeByTsaAndDate() AND teamNetIncomeByDate() in the same
     *  request, and even after both of THOSE methods were fixed to compute
     *  these maps only ONCE internally (PARTS 1/2), the two methods still
     *  each computed their OWN separate copy — 2 full O(days) passes per
     *  page load. Confirmed live: Oct 1-5 loaded (slow, under 30s), Oct 1-9
     *  still timed out — consistent with still-redundant work on top of
     *  the non-sargable-query fix (PART 3, see Decisions.md). Lets
     *  TsaSalesReportController::index() compute this pair exactly once
     *  and pass the SAME pair into both calls, collapsing 2 passes to 1.
     *  Uses reportProducts() — the same roster every internal caller
     *  already uses — so the returned maps are interchangeable with
     *  whatever any internal caller would have computed for itself. */
    public static function leadCountsAndGrossSalesForRange(string $dateFrom, string $dateTo): array
    {
        $products = self::reportProducts();
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));
        $controller = new self();

        return [
            'leadCounts' => $controller->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo),
            'grossSalesAndOrders' => $controller->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo),
        ];
    }

    /** $dayOrdersByTeamAndDate: ONE query for the whole range (see
     *  ordersByTeamAndDate()'s own doc comment) — perf fix, 2026-10-11,
     *  PART 5 (THE real dominant cost; PARTS 1-4 in Decisions.md all
     *  addressed real, measured redundancy but Oct 1-9 still 500'd after
     *  every one of them shipped). Root cause: this method and
     *  grossSalesByProductAndDate() below each ran ONE Order query PER
     *  (day × team) — a 9-day/2-team range issuing 18 separate queries
     *  EACH, 36 total, every one pulling a full day's worth of rows just
     *  to immediately re-filter by team in PHP. PARTS 1/2/4 eliminated
     *  redundant CALLS to these two methods across sibling call chains;
     *  PART 3 fixed the non-sargable COALESCE() those per-day queries
     *  used. None of them touched the fact that each call still issued
     *  O(days × teams) queries of its own. Grouping once into
     *  $dayOrdersByTeamAndDate (a single whereRaw() BETWEEN the whole
     *  range, grouped by "{team}:{date}" in PHP afterward) turns that
     *  into exactly 1 query regardless of range length, matching the
     *  same "fetch once, group in memory" shape PART 1's hoisting
     *  already established for the surrounding per-day loops. */
    private function leadCountsByProductAndDate($products, $dates, string $dateFrom, string $dateTo)
    {
        return self::memoPerRequest('leadCounts', $products, $dates, $dateFrom, $dateTo,
            fn () => $this->computeLeadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo));
    }

    private function computeLeadCountsByProductAndDate($products, $dates, string $dateFrom, string $dateTo)
    {
        $tsaKeyToId = TsaShift::pluck('id', 'tsa_key');
        $orderTeams = collect(Teams::config())->pluck('order_team')->unique()->values();
        $dayOrdersByTeamAndDate = $this->ordersByTeamAndDate($orderTeams, $dates, $dateFrom, $dateTo);
        $counts = [];

        foreach ($dates as $date) {
            $dateStr = $date->toDateString();

            foreach ($orderTeams as $orderTeam) {
                $dayOrders = $dayOrdersByTeamAndDate->get("{$orderTeam}:{$dateStr}", collect());

                foreach ($products as $product) {
                    // Same exclusion filter tally() itself applies before
                    // counting 'total' (Deleted orders, excluded-seller
                    // upsells) — kept in sync by hand, same convention
                    // countedOrdersFor() already follows for the identical
                    // reason (see that method's own doc comment).
                    // duplicated-by-logistics is NOT excluded here either
                    // (2026-10-13 reversal, see ProductPerformance::tally()'s
                    // own doc comment).
                    $matchedOrders = ProductPerformance::matchingOrders($product, $dayOrders, $products)
                        ->reject(fn ($o) => $o->status_code === 7 || $o->excluded_upsell_seller);

                    foreach ($matchedOrders->groupBy('tsa_name') as $tsaName => $ordersForTsa) {
                        $tsaId = $tsaKeyToId->get($tsaName, 'null');
                        $key = "{$orderTeam}:{$tsaId}:{$product->id}:{$dateStr}";
                        $counts[$key] = ($counts[$key] ?? 0) + $ordersForTsa->count();
                    }
                }
            }
        }

        return collect($counts);
    }

    /** Shared by leadCountsByProductAndDate()/grossSalesByProductAndDate()
     *  above/below — every real team's own Order rows for the WHOLE
     *  requested range, fetched in ONE query (not one per day per team),
     *  then grouped into day-sized, team-scoped buckets in PHP so the
     *  per-day/per-team loop in each caller reads an already-sliced
     *  Collection instead of re-querying. Keyed "{orderTeam}:{date}" to
     *  match those callers' own loop variables directly. Still uses
     *  whereRaw(COALESCE(...)) — PART 3's own expression index
     *  (migration add_coalesce_order_date_index) makes that sargable
     *  again, so one big BETWEEN scan here costs about the same per-row
     *  as the N small ones it replaces, just without the N multiplier. */
    /** Every non-TikTok order in the range, fetched once per request and
     *  shared by ordersByTeamAndDate()/ordersByTsaTeamAndDate() — the SAME
     *  Order objects, so ProductPerformance::matchingOrders()' per-order
     *  memo carries over between the two (perf fix, 2026-10-16). */
    private static function rangeOrders(Carbon $rangeStart, Carbon $rangeEnd): \Illuminate\Support\Collection
    {
        // Hidden products' orders are dropped here (and in every other
        // order fetch in this file) — see reportProducts().
        return \App\Support\RequestMemo::remember(
            'expected_income.range_orders.' . $rangeStart->toDateTimeString() . '.' . $rangeEnd->toDateTimeString(),
            fn () => Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$rangeStart, $rangeEnd])
                ->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders)))
        );
    }

    private function ordersByTeamAndDate($orderTeams, $dates, string $dateFrom, string $dateTo): \Illuminate\Support\Collection
    {
        $rangeStart = Carbon::parse($dateFrom)->startOfDay();
        $rangeEnd = Carbon::parse($dateTo)->endOfDay();

        return self::rangeOrders($rangeStart, $rangeEnd)
            ->filter(fn (Order $o) => $orderTeams->contains($o->team))
            ->groupBy(function (Order $o) {
                $ts = $o->pancake_inserted_at ?? $o->pancake_created_at;
                return $o->team . ':' . ($ts instanceof \Illuminate\Support\Carbon ? $ts->toDateString() : \Illuminate\Support\Carbon::parse($ts)->toDateString());
            });
    }

    /** Sibling of ordersByTeamAndDate() above, for grossSalesByProductAndDate()
     *  only — root-caused live, 2026-10-12 (user screenshot: TEAM CLOSING's
     *  own Gross Sales card showed 40,721.00, the real team-agnostic total
     *  confirmed against Leads Report/Dashboard, while summing every
     *  product card under it only reached 34,420.00, a 6,301.00 gap).
     *
     *  Order.team is NOT "which team's TSA worked this order" — it's the
     *  order's own ARRIVAL HOUR window (TeamShiftWindow::forHour(),
     *  explicit 2026-09-07 redesign: Opening/Eyecare Team = 00:00-14:59,
     *  Closing/SH Naturals = 15:00-23:59), completely independent of which
     *  TSA actually closed the sale. ordersByTeamAndDate()'s own
     *  Order.team-filtered candidate pool is CORRECT for
     *  leadCountsByProductAndDate() (Number of Leads deliberately mirrors
     *  LeadsReportController::indexAll()'s own hour-windowed per-team
     *  pooling, confirmed via that method's own 2026-10-09 doc comment —
     *  NOT changed here) but WRONG for Gross Sales specifically, which has
     *  no such Leads-Report-parity requirement and whose own team-level
     *  rollup (teamUpsellTotals()) already scopes by TSA roster, not
     *  Order.team. Confirmed live via a production query: 7 real orders
     *  worked by SH Naturals TSAs (Angelica/Kathleen/Lika/Mariel) arrived
     *  before 3pm, so Order.team recorded "Eyecare Team" for them — every
     *  one of grossSalesByProductAndDate()'s own product cards excluded
     *  them from its candidate pool before matching ever ran, since that
     *  pool was built from Order.team = 'SH Naturals' only. Fixed by
     *  scoping the candidate pool to each TSA's own real team (TsaShift.team)
     *  instead — same roster basis teamUpsellTotals()/teamUpsellOrderCount()
     *  already use, which is exactly why they never had this gap.
     *
     *  Falls back to the order's own Order.team (hour window) whenever
     *  tsa_name doesn't match any real TsaShift — bug caught by
     *  test_saving_gross_sales_recomputes_returns_and_delivered() (ALL
     *  view's own product-level cards seed an order with no recognized
     *  tsa_name at all, e.g. 'unrecognized-tsa'/null): an EARLIER version
     *  of this method pre-filtered candidates to whereIn('tsa_name',
     *  $realTsaKeys) ONLY, silently dropping every order with no
     *  recognized TSA — $tsaTeamByKey->get() returns null for those, so
     *  without this fallback they'd vanish from every team's own bucket
     *  entirely instead of landing in whichever bucket Order.team already
     *  correctly placed them (same behavior ordersByTeamAndDate() always
     *  had for exactly this case). */
    private function ordersByTsaTeamAndDate($dates, string $dateFrom, string $dateTo): \Illuminate\Support\Collection
    {
        $rangeStart = Carbon::parse($dateFrom)->startOfDay();
        $rangeEnd = Carbon::parse($dateTo)->endOfDay();

        $tsaTeamByKey = TsaShift::pluck('team', 'tsa_key');

        return self::rangeOrders($rangeStart, $rangeEnd)
            ->groupBy(function (Order $o) use ($tsaTeamByKey) {
                $ts = $o->pancake_inserted_at ?? $o->pancake_created_at;
                $dateStr = $ts instanceof \Illuminate\Support\Carbon ? $ts->toDateString() : \Illuminate\Support\Carbon::parse($ts)->toDateString();
                $team = $tsaTeamByKey->get($o->tsa_name) ?? $o->team;
                return $team . ':' . $dateStr;
            });
    }

    /** Expected Income's own per-(TSA, product, day) Gross Sales AND
     *  Number of Orders — same "assignee TSA of items" real-revenue/real-
     *  order basis Summary Sales Report's own Gross Sales/Total Orders
     *  columns already use (explicit request, 2026-10-10: "in the
     *  expected income the gross sales is make it automated too", then
     *  "the number of orders is the upsell"), additionally scoped to ONE
     *  product here (Expected Income's own cards are per-product, unlike
     *  Summary Sales Report's single per-TSA-per-day figure). Returns BOTH
     *  from the SAME matched-order loop pass (one extra accumulator, not a
     *  second query/matching pass) — Number of Orders was added to an
     *  already-existing Gross Sales method rather than built as its own
     *  sibling, specifically to avoid tripling matchingOrders() cost on
     *  top of leadCountsByProductAndDate()'s own identical team×day×
     *  product loop.
     *
     *  STRUCTURALLY IDENTICAL to leadCountsByProductAndDate() above — same
     *  team loop, same day-scoping, same matchingOrders()/exclusion
     *  filter, same 4-segment key shape — kept as a SEPARATE method rather
     *  than folded into THAT one's own loop so an edit to either one's own
     *  matching rules can never accidentally change the other's, and so
     *  this method's own history doesn't get tangled into
     *  leadCountsByProductAndDate()'s already-long 4-same-day-fix doc
     *  comment.
     *
     *  Gross Sales: sums `Order::realUpsellAmount()` over the
     *  isBroadRealUpsell-filtered subset of each (team, product, day)'s
     *  own matched orders — EXACTLY `ProductPerformance::tally()`'s own
     *  `upsell_sales` formula (`tally()`'s own `$isRealUpsell`/exclusion
     *  filter, line-for-line). Number of Orders: COUNTS that same
     *  filtered subset — EXACTLY `tally()`'s own `upsell_confirmation`
     *  formula (`$orders->filter($isRealUpsell)->count()`) — just
     *  additionally scoped to one product via matchingOrders() instead of
     *  tallying a whole team's pool, same relationship Gross Sales already
     *  has to `tally()`'s own `upsell_sales`.
     *
     *  Returns ['grossSales' => Collection, 'numberOfOrders' => Collection],
     *  both keyed "{orderTeam}:{tsaId}:{productId}:{date}". */
    /** $dayOrdersByTeamAndDate — same ordersByTeamAndDate()-powered "one
     *  query for the whole range" fix as leadCountsByProductAndDate()'s
     *  own identical doc comment above (PART 5) — this method had the
     *  exact same per-(day × team) query loop, independently. */
    private function grossSalesByProductAndDate($products, $dates, string $dateFrom, string $dateTo): array
    {
        return self::memoPerRequest('grossSales', $products, $dates, $dateFrom, $dateTo,
            fn () => $this->computeGrossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo));
    }

    /** Per-request memo for the two heaviest pure functions on this page
     *  (perf, explicit request 2026-10-15: "every page load is so slow") —
     *  index() alone used to recompute both ~6 times for the SAME inputs
     *  (buildSummary()'s 3 buildSummaryRow() calls, each also via
     *  rawByProductAndDateAllTsas(), plus the daily rows). Keyed by the
     *  exact inputs; see RequestMemo for why it can never serve a stale
     *  figure. */
    private static function memoPerRequest(string $name, $products, $dates, string $dateFrom, string $dateTo, \Closure $compute)
    {
        $key = 'ei.' . $name . '|' . $dateFrom . '|' . $dateTo
            . '|' . collect($products)->pluck('id')->implode(',')
            . '|' . collect($dates)->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)->implode(',');

        return \App\Support\RequestMemo::remember($key, $compute);
    }

    private function computeGrossSalesByProductAndDate($products, $dates, string $dateFrom, string $dateTo): array
    {
        $tsaKeyToId = TsaShift::pluck('id', 'tsa_key');
        $orderTeams = collect(Teams::config())->pluck('order_team')->unique()->values();
        // TSA-roster-scoped candidate pool, NOT ordersByTeamAndDate()'s own
        // Order.team (arrival-hour-window) one — see ordersByTsaTeamAndDate()'s
        // own doc comment for the full root cause/fix story, 2026-10-12.
        $dayOrdersByTsaTeamAndDate = $this->ordersByTsaTeamAndDate($dates, $dateFrom, $dateTo);
        $sales = [];
        $orderCounts = [];
        // Cancelled (2026-10-13) — built in the SAME loop as Gross Sales/
        // Number of Orders above, off the same already-matched
        // $matchedOrders, rather than a separate pass over the same data
        // (no extra query, no drift risk between the two). See
        // ProductPerformance::tally()'s own cancelled_upsell_sales doc
        // comment for the full basis.
        $cancelled = [];

        foreach ($dates as $date) {
            $dateStr = $date->toDateString();

            foreach ($orderTeams as $orderTeam) {
                $dayOrders = $dayOrdersByTsaTeamAndDate->get("{$orderTeam}:{$dateStr}", collect());

                foreach ($products as $product) {
                    // Same exclusion filter tally() itself applies before
                    // computing upsell_sales/upsell_confirmation (see
                    // leadCountsByProductAndDate()'s own identical filter,
                    // directly above). duplicated-by-logistics is NOT
                    // excluded (2026-10-13 reversal, see tally()'s own doc
                    // comment).
                    $matchedOrders = ProductPerformance::matchingOrders($product, $dayOrders, $products)
                        ->reject(fn ($o) => $o->status_code === 7 || $o->excluded_upsell_seller)
                        ->filter(fn ($o) => Order::isBroadRealUpsell($o));

                    foreach ($matchedOrders->groupBy('tsa_name') as $tsaName => $ordersForTsa) {
                        $tsaId = $tsaKeyToId->get($tsaName, 'null');
                        $key = "{$orderTeam}:{$tsaId}:{$product->id}:{$dateStr}";
                        $sales[$key] = ($sales[$key] ?? 0.0) + $ordersForTsa->sum(fn (Order $o) => $o->realUpsellAmount());
                        $orderCounts[$key] = ($orderCounts[$key] ?? 0) + $ordersForTsa->count();
                        $cancelledForTsa = $ordersForTsa->filter(fn (Order $o) => in_array($o->status_code, [6, 11], true));
                        if ($cancelledForTsa->isNotEmpty()) {
                            $cancelled[$key] = ($cancelled[$key] ?? 0.0) + $cancelledForTsa->sum(fn (Order $o) => $o->realUpsellAmount());
                        }
                    }
                }
            }
        }

        return ['grossSales' => collect($sales), 'numberOfOrders' => collect($orderCounts), 'cancelled' => collect($cancelled)];
    }

    /** The TELESALES/per-team rollup's own Gross Sales — explicit
     *  correction, 2026-10-10, root-caused live via screenshot: Expected
     *  Income's own TEAM OPENING TELESALES card showed 44,501.00 (ALL
     *  teams) / a lower per-team figure, while Summary Sales Report's own
     *  TEAM OPENING TOTAL showed the real 38,700.00 for the exact same
     *  TSAs/date — "it is opening team" / "it should be all 38,700.00".
     *
     *  Root cause: grossSalesByProductAndDate()'s own per-PRODUCT
     *  matchingOrders() step (used for each individual product card's own
     *  Gross Sales, which genuinely needs to know which product an order
     *  was for) silently DROPS a real upsell order whenever its Pancake
     *  item text/tags don't cleanly match any configured Product's own
     *  match_keyword/pancake_product_ids — same class of bug
     *  leadCountsByProductAndDate() already went through 4 rounds of
     *  fixes for (see that method's own doc comment). The TELESALES
     *  rollup has NO reason to pay that cost — unlike a product card, it
     *  doesn't need to know WHICH product an order was for, only whether
     *  it's a real upsell order for one of this team's own TSAs — so this
     *  method mirrors TsaSalesReportController::perTsaPerDayPerformance()
     *  exactly instead: no product matching, no Order.team dependency
     *  (avoids the second gap that method's own doc comment documents —
     *  a team-prefiltered query silently skipping any order whose
     *  Order.team came back null from a timestamp-less Pancake payload),
     *  just every order for this team's own real tsa_key roster, summed
     *  via the SAME ProductPerformance::tally()['upsell_sales'] formula
     *  Summary Sales Report's own Gross Sales already uses — guaranteeing
     *  the two pages' TEAM TOTAL rows can never structurally diverge
     *  again, the same way netIncomeByTsaAndDate()/teamNetIncomeByDate()
     *  already guarantee for Net Income.
     *
     *  $onlyOrderTeam: the real `order_team` string (e.g. "Eyecare Team")
     *  for a single team's own rollup, or null for the ALL view (every
     *  real TSA site-wide). Deliberately does NOT take a caller-supplied
     *  TSA id list — resolves its OWN roster here instead, same as
     *  TsaSalesReportController's own `$tsas = TsaShift::orderBy(
     *  'sort_order')->get()` (NO tiktok_upsell filter anywhere in that
     *  controller's real-team grouping). This was a second, independent
     *  bug found live, 2026-10-10, same day — after the per-product-
     *  matching fix above shipped, the user reported a NEW, smaller gap
     *  (Expected Income's TEAM CLOSING TELESALES card showing 40,604.00
     *  vs Summary Sales Report's real 41,604.00 — short by exactly
     *  1,000): the first version of this method took its $tsaIds straight
     *  from buildSummaryRow()'s own $onlyTsaIds, which buildSummary()
     *  deliberately pre-filters to tiktok_upsell=false (explicit
     *  2026-10-07 decision — "when she is in the tiktok the team opening
     *  or closing in the expected income the tsa card ... will be gone").
     *  That decision was scoped to the per-TSA CARD (hide HER card from
     *  the real-team view, she gets her own card under the TIKTOK filter
     *  instead) — it was never meant to also exclude her real revenue
     *  from the TEAM TOTAL, and Summary Sales Report's own team total
     *  never excludes her. Resolving the roster independently here keeps
     *  that 2026-10-07 per-card decision untouched (still governs
     *  $onlyTsaIds/$rollupLookups/every OTHER field) while making Gross
     *  Sales specifically immune to it, same as it's already immune to
     *  the per-product matching gap.
     *
     *  Investigated-and-REJECTED fix, 2026-10-11: briefly excluded
     *  tiktok_upsell=true TSAs from the $onlyOrderTeam===null (ALL view)
     *  branch, theorizing her real orders were double-counted against her
     *  separately-folded-in TikTok card. Reverted after checking Summary
     *  Sales Report's OWN basis directly: its `perTsaPerDayPerformance()`
     *  has NO tiktok_upsell filter at all — it already includes a
     *  TikTok-flagged TSA's real orders in its OVERALL TOTAL (confirmed
     *  live, Oct 1: her real orders tally EXACTLY 74,703 site-wide,
     *  INCLUDING her own, and Summary Sales Report's own OVERALL TOTAL of
     *  76,303.00 = that 74,703 + its own separate TsaTiktokEntry total of
     *  1,600.00). So excluding her here would have made this method
     *  diverge FROM Summary Sales Report, not converge with it — the two
     *  pages are supposed to both include her real orders once; any
     *  remaining gap between them is `ExpectedIncomeTiktokEntry` (this
     *  page's own TikTok manual figure) vs `TsaTiktokEntry` (Summary
     *  Sales Report's own, independently-typed TikTok manual figure)
     *  simply holding two different manually-entered numbers for the same
     *  TSA/day — a known, already-documented, ACCEPTED gap (see
     *  Decisions.md, 2026-10-10: "two entirely separate tables with zero
     *  shared computation... two independently human-maintained
     *  numbers"), not a code bug to chase further. */
    private function teamUpsellGrossSales(?string $onlyOrderTeam, string $dateFrom, string $dateTo): float
    {
        $tsaKeys = $onlyOrderTeam === null
            ? TsaShift::pluck('tsa_key')
            : TsaShift::where('team', $onlyOrderTeam)->pluck('tsa_key');

        return $this->upsellGrossSalesForTsaKeys($tsaKeys, $dateFrom, $dateTo);
    }

    /** Number of Orders equivalent of teamUpsellGrossSales() above — same
     *  product-agnostic, team-roster-scoped tally() basis (this time its
     *  'upsell_confirmation' figure instead of 'upsell_sales'), applied
     *  the SAME way and for the SAME reason: explicit fix, 2026-10-12,
     *  root-caused live via screenshot comparison against Dashboard's own
     *  "Total Cross-Sell Sales" card — TEAM CLOSING showed 37 here vs
     *  Dashboard's real 47 for the same date/team (an UNDER-count), while
     *  TEAM OPENING showed 58 here vs Dashboard's real 49 (an OVER-count)
     *  — both directions confirm the same root cause as Gross Sales'
     *  original 2026-10-10 fix: totalRealGrossSales($numberOfOrdersMap,
     *  ...)'s per-PRODUCT matchingOrders() basis can both silently drop a
     *  real order that doesn't cleanly match any configured product
     *  (undercount) and double-count one order across more than one
     *  product's own match (overcount) — neither failure mode is
     *  possible with a single team-roster-scoped tally() call, which
     *  counts each real order exactly once regardless of product.
     *  Deliberately NOT applied to the per-PRODUCT card's own Number of
     *  Orders (totalRealGrossSales() itself, still used there) — a
     *  product card's whole purpose is per-product attribution, same
     *  reasoning tsaUpsellGrossSales()'s own doc comment already
     *  established for Gross Sales on an individual TSA's product cards
     *  vs her overview card. */
    private function teamUpsellOrderCount(?string $onlyOrderTeam, string $dateFrom, string $dateTo): int
    {
        $tsaKeys = $onlyOrderTeam === null
            ? TsaShift::pluck('tsa_key')
            : TsaShift::where('team', $onlyOrderTeam)->pluck('tsa_key');

        return $this->upsellOrderCountForTsaKeys($tsaKeys, $dateFrom, $dateTo);
    }

    /** Combined teamUpsellGrossSales() + teamUpsellOrderCount() — same
     *  team-roster-scoped tally() basis as both, one Order fetch instead
     *  of two (perf fix, 2026-10-12, PART 5). buildSummaryRow() calls
     *  these back-to-back with identical ($onlyOrderTeam, $dateFrom,
     *  $dateTo) every time it runs — and teamNetIncomeByDate() calls
     *  buildSummaryRow() once PER TEAM PER DAY, so the pre-existing
     *  2-queries-per-call cost was itself multiplied by every (team, day)
     *  pair in the range. Returns both figures from the one tally() call
     *  each of the two original methods already made separately — no
     *  change to either figure's own formula/scope. */
    private function teamUpsellTotals(?string $onlyOrderTeam, string $dateFrom, string $dateTo): array
    {
        $tsaKeys = $onlyOrderTeam === null
            ? TsaShift::pluck('tsa_key')
            : TsaShift::where('team', $onlyOrderTeam)->pluck('tsa_key');

        if ($tsaKeys->isEmpty()) {
            return ['grossSales' => 0.0, 'orders' => 0, 'cancelled' => 0.0];
        }

        $orders = Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsaKeys)->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders)));

        $tally = ProductPerformance::tally($orders);

        return [
            'grossSales' => (float) $tally['upsell_sales'],
            'orders' => (int) $tally['upsell_confirmation'],
            'cancelled' => (float) $tally['cancelled_upsell_sales'],
        ];
    }

    /** Public wrapper around teamUpsellTotals() — built for
     *  TallyCheckerController (explicit request, 2026-10-12: "add TALLY
     *  CHECKER BUTTON ... show is all tally in the expected income,
     *  summary sales report, leads report module"). This is the single
     *  source-of-truth figure every other page's own team-level Gross
     *  Sales/Total Orders SHOULD already equal by construction — Summary
     *  Sales Report's own perTsaPerDayPerformance() and Dashboard's own
     *  Total Cross-Sell Sales both independently tally() the same
     *  TSA-roster-scoped order set (see this class's own 2026-10-10/
     *  2026-10-12 doc comments for the history of making sure they do) —
     *  kept as a thin public wrapper rather than making teamUpsellTotals()
     *  itself public, so that private method's own calling contract
     *  (called from inside buildSummaryRow() with $onlyOrderTeam already
     *  resolved) stays unchanged for its existing callers. */
    public static function teamGrossSalesAndOrders(?string $onlyOrderTeam, string $dateFrom, string $dateTo): array
    {
        return (new self())->teamUpsellTotals($onlyOrderTeam, $dateFrom, $dateTo);
    }

    /** ONE TSA's own real, product-agnostic Gross Sales for a date range —
     *  same tally()['upsell_sales'] basis as teamUpsellGrossSales() above,
     *  just scoped to a single TSA's own tsa_key instead of a whole
     *  team's roster. Explicit fix, 2026-10-10, same day — root-caused
     *  live via screenshot: Katherine Chua's own "[TSA NAME]" overview
     *  card in Expected Income showed Gross Sales 3,638.00 while
     *  Dashboard's own Leaderboard AND Summary Sales Report's own per-TSA
     *  row both showed her real 2,319.00. Her own overview card
     *  (buildTeamDailyRows()'s own $dailyOverallTotals/$rangeOverallTotal)
     *  was built by SUMMING her own per-PRODUCT cards' raw rows — each one
     *  individually subject to grossSalesByProductAndDate()'s own
     *  per-product matchingOrders() drop (same root cause already fixed
     *  for the TEAM rollup, 2026-10-10 earlier the same day) — so any real
     *  order whose item text didn't cleanly match one of HER flagged
     *  products' own keyword/ID roster was invisible to every one of her
     *  product cards, and therefore to their sum too. Her own PRODUCT
     *  cards correctly keep per-product matching (that's the whole point
     *  of a product-scoped card) — only her OVERVIEW card (a plain rollup,
     *  not itself product-scoped) needed this product-agnostic basis
     *  instead, same reasoning teamUpsellGrossSales() already established
     *  for the team-level rollup. */
    private function tsaUpsellGrossSales(TsaShift $tsa, string $dateFrom, string $dateTo): float
    {
        return $this->upsellGrossSalesForTsaKeys(collect([$tsa->tsa_key]), $dateFrom, $dateTo);
    }

    /** Shared by teamUpsellGrossSales()/tsaUpsellGrossSales() above — real
     *  upsell revenue for whichever tsa_key(s) are given, product-
     *  agnostic (ProductPerformance::tally()'s own upsell_sales formula,
     *  no matchingOrders() involved at all). */
    private function upsellGrossSalesForTsaKeys($tsaKeys, string $dateFrom, string $dateTo): float
    {
        if ($tsaKeys->isEmpty()) {
            return 0.0;
        }

        $orders = Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsaKeys)->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders)));

        return (float) ProductPerformance::tally($orders)['upsell_sales'];
    }

    /** Number of Orders equivalent of upsellGrossSalesForTsaKeys() directly
     *  above — identical query/scope, just reads tally()'s own
     *  'upsell_confirmation' count instead of 'upsell_sales'. Shared by
     *  teamUpsellOrderCount() above. */
    private function upsellOrderCountForTsaKeys($tsaKeys, string $dateFrom, string $dateTo): int
    {
        if ($tsaKeys->isEmpty()) {
            return 0;
        }

        $orders = Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsaKeys)->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders)));

        return (int) ProductPerformance::tally($orders)['upsell_confirmation'];
    }

    /** Cancelled equivalent of teamUpsellGrossSales() above — same
     *  product-agnostic, team-roster-scoped tally() basis (this time its
     *  'cancelled_upsell_sales' figure), for the same reason: a per-product
     *  matchingOrders() pass can silently drop a real order that doesn't
     *  cleanly match any configured product, which would undercount a
     *  rollup built by summing per-product Cancelled figures. Explicit
     *  request, 2026-10-13: "i want the cancelled row make it automated
     *  and the function of that is the cancelled status and awaiting
     *  stock status will be combine to the cancelled status row" / "the
     *  only cancelled and awaiting stock status that has tsa real
     *  upsell." */
    private function teamUpsellCancelled(?string $onlyOrderTeam, string $dateFrom, string $dateTo): float
    {
        $tsaKeys = $onlyOrderTeam === null
            ? TsaShift::pluck('tsa_key')
            : TsaShift::where('team', $onlyOrderTeam)->pluck('tsa_key');

        return $this->upsellCancelledForTsaKeys($tsaKeys, $dateFrom, $dateTo);
    }

    /** ONE TSA's own real, product-agnostic Cancelled figure — same
     *  tally()['cancelled_upsell_sales'] basis as teamUpsellCancelled()
     *  above, just scoped to a single TSA's own tsa_key, same reasoning
     *  tsaUpsellGrossSales() already established for Gross Sales on an
     *  overview (non-product-scoped) card. */
    /** Real upsell Gross Sales / Number of Orders / Cancelled per
     *  "{tsa_key}:{Y-m-d}" in ONE query — same tally() definitions and
     *  date basis as tsaUpsellGrossSales()/tsaUpsellOrderCount()/
     *  tsaUpsellCancelled(), for callers that need every TSA × day. */
    private function upsellTotalsByTsaKeyAndDate($tsaKeys, string $dateFrom, string $dateTo): \Illuminate\Support\Collection
    {
        if ($tsaKeys->isEmpty()) {
            return collect();
        }

        return Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsaKeys)->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders)))
            ->groupBy(fn (Order $o) => $o->tsa_name . ':' . Carbon::parse($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString())
            ->map(function ($orders) {
                $tally = ProductPerformance::tally($orders);

                return [
                    'gross_sales'      => (float) $tally['upsell_sales'],
                    'number_of_orders' => (int) $tally['upsell_confirmation'],
                    'cancelled'        => (float) $tally['cancelled_upsell_sales'],
                ];
            });
    }

    private function tsaUpsellOrderCount(TsaShift $tsa, string $dateFrom, string $dateTo): int
    {
        return $this->upsellOrderCountForTsaKeys(collect([$tsa->tsa_key]), $dateFrom, $dateTo);
    }

    private function tsaUpsellCancelled(TsaShift $tsa, string $dateFrom, string $dateTo): float
    {
        return $this->upsellCancelledForTsaKeys(collect([$tsa->tsa_key]), $dateFrom, $dateTo);
    }

    /** Shared by teamUpsellCancelled()/tsaUpsellCancelled() above — same
     *  query shape as upsellGrossSalesForTsaKeys(), reading tally()'s own
     *  'cancelled_upsell_sales' figure instead of 'upsell_sales'. */
    private function upsellCancelledForTsaKeys($tsaKeys, string $dateFrom, string $dateTo): float
    {
        if ($tsaKeys->isEmpty()) {
            return 0.0;
        }

        $orders = Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsaKeys)->get()->pipe(fn ($orders) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders)));

        return (float) ProductPerformance::tally($orders)['cancelled_upsell_sales'];
    }

    /** Gross Sales/Number of Orders equivalent of totalRealLeads() directly
     *  below — same filter/sum shape, works for either map
     *  (grossSalesByProductAndDate()'s own 'grossSales'/'numberOfOrders'
     *  return keys, same 4-segment key format) — see that method's own
     *  doc comment for the full reasoning (team-scoped vs ALL-view
     *  summing, group-aware via $products). STILL used for every
     *  individual PRODUCT card's own Gross Sales (teamUpsellGrossSales()
     *  above is ONLY for the TELESALES/per-team rollup, which has no
     *  per-product concept to begin with).
     *
     *  $dateFrom/$dateTo (bug fix, 2026-10-11): the map's own 4th key
     *  segment (date) was previously dropped by `explode(':', $key, 4)`
     *  destructuring into only 3 variables, so this summed EVERY date
     *  present in $valueMap, not just the caller's own date window. Not
     *  a problem before the same-day precomputed-map perf fix (each
     *  buildSummaryRow() call built its own single-day map internally,
     *  so the map only ever held one date's keys) — but
     *  teamNetIncomeByDate()/grossSalesAndNetIncomeByProductAndDate() now
     *  pass a precomputed map spanning the WHOLE requested range into
     *  every one of their own per-day buildSummaryRow() calls, so every
     *  day silently summed the full range's total instead of its own day
     *  (root-caused live, 2026-10-11: Summary Sales Report's per-day
     *  columns went flat/identical across every date once a 2+ day range
     *  was selected). Filtered the same way
     *  grossSalesForTsaProductDate()/leadsForTsaProductDate() below
     *  already correctly do. */
    private function totalRealGrossSales($valueMap, $products, ?string $onlyOrderTeam, string $dateFrom, string $dateTo): float
    {
        $productIds = $products->pluck('id')->map(fn ($id) => (string) $id);

        return (float) $valueMap->filter(function ($value, $key) use ($productIds, $onlyOrderTeam, $dateFrom, $dateTo) {
            [$orderTeam, $tsaKey, $productId, $dateStr] = explode(':', $key, 4);
            if (!$productIds->contains($productId)) {
                return false;
            }
            if ($dateStr < $dateFrom || $dateStr > $dateTo) {
                return false;
            }
            return $onlyOrderTeam === null || $orderTeam === $onlyOrderTeam;
        })->sum();
    }

    /** Gross Sales/Number of Orders equivalent of leadsForTsaProductDate()
     *  directly below — same filter shape, works for either map — see
     *  that method's own doc comment for the full reasoning (one TSA's
     *  own figure, summed across every team's own bucket). */
    private function grossSalesForTsaProductDate($valueMap, ?int $tsaId, int $productId, string $dateStr): float
    {
        $tsaKey = (string) ($tsaId ?? 'null');

        return (float) $valueMap->filter(function ($value, $key) use ($tsaKey, $productId, $dateStr) {
            [$orderTeam, $keyTsa, $keyProductId, $keyDate] = explode(':', $key, 4);
            return $keyTsa === $tsaKey && $keyProductId === (string) $productId && $keyDate === $dateStr;
        })->sum();
    }

    /** The real lead total for a SUMMARY card (a product, a product GROUP's
     *  combined members, or the whole-page rollup) — sums $leadCounts (the
     *  FULL-roster, FULL-team map from leadCountsByProductAndDate() — see
     *  that method's own doc comment for why it always computes every real
     *  team's own separately-scoped pool) down to just the given $products
     *  subset's own product ids, further restricted to either ONE team
     *  (when $onlyOrderTeam is a real `order_team` string — a single-team
     *  filter, matching LeadsReportController::index()'s own single-pool
     *  total) or EVERY team summed together (when null — the ALL view,
     *  matching LeadsReportController::indexAll()'s own
     *  sum-of-per-team-sections total). Takes the pre-computed map rather
     *  than querying itself — this is called once per card (potentially
     *  several products deep for a group) plus once more for the rollup,
     *  so a fresh query per call here would reintroduce the exact N+1
     *  cost addActiveTsasOverviewOperatingCosts()'s own doc comment
     *  already root-caused on this page (2026-10-02).
     *
     *  $dateFrom/$dateTo (bug fix, 2026-10-11) — same dropped-4th-segment
     *  bug and same fix as totalRealGrossSales()'s own identical doc
     *  comment above; see that one for the full root cause. */
    private function totalRealLeads($leadCounts, $products, ?string $onlyOrderTeam, string $dateFrom, string $dateTo): int
    {
        $productIds = $products->pluck('id')->map(fn ($id) => (string) $id);

        return (int) $leadCounts->filter(function ($count, $key) use ($productIds, $onlyOrderTeam, $dateFrom, $dateTo) {
            [$orderTeam, $tsaKey, $productId, $dateStr] = explode(':', $key, 4);
            if (!$productIds->contains($productId)) {
                return false;
            }
            if ($dateStr < $dateFrom || $dateStr > $dateTo) {
                return false;
            }
            return $onlyOrderTeam === null || $orderTeam === $onlyOrderTeam;
        })->sum();
    }

    /** One TSA's (or null for the ALL view's own product-level rows) real
     *  lead count for ONE product on ONE day — summed across every real
     *  team's own bucket in $leadCounts, since a specific TSA's own card
     *  must show ALL her real leads regardless of which team's hour
     *  window an individual order fell into (a TSA legitimately working a
     *  cross-team sale still owns that lead on her own card — this is a
     *  DIFFERENT rule from totalRealLeads()'s own team-scoped summary
     *  total, deliberately; see leadCountsByProductAndDate()'s own doc
     *  comment for why the two differ). Used by rawByProductAndDate() and
     *  withCustomRowValues(), the two raw-row paths that key by a single
     *  real TSA (or null) rather than by team. */
    private function leadsForTsaProductDate($leadCounts, ?int $tsaId, int $productId, string $dateStr): int
    {
        $tsaKey = (string) ($tsaId ?? 'null');

        return (int) $leadCounts->filter(function ($count, $key) use ($tsaKey, $productId, $dateStr) {
            [$orderTeam, $keyTsa, $keyProductId, $keyDate] = explode(':', $key, 4);
            return $keyTsa === $tsaKey && $keyProductId === (string) $productId && $keyDate === $dateStr;
        })->sum();
    }

    /** Same idea as rawByProductAndDate(), but for the top summary's own
     *  "pool every tsa_id together" need — returns one LIST of raw rows per
     *  (product, day), one entry per distinct tsa_id that has a row (the
     *  product-level tsa_id-NULL row, if any, plus every real TSA's own
     *  row), so the caller can sum() across all of them rather than merge
     *  a single one. A product/day with no rows at all still gets an empty
     *  list, never a missing key — every SUM caller downstream expects
     *  every (product, day) pair to be present.
     *
     *  $onlyTsaIds restricts which real TSAs' rows get pooled in — null
     *  (the main "TELESALES" overall card) means every NON-TIKTOK TSA
     *  (explicit fix, 2026-10-10 — see $effectiveTsaIds' own doc comment
     *  just below for the full root cause; this null case used to mean
     *  LITERALLY every TSA including tiktok_upsell=true ones), an array
     *  of ids (one team's own summary card, added 2026-09-30 per the
     *  sheet's own "TEAM OPENING SHIFT"/"TEAM CLOSING SHIFT" cards) means
     *  only those TSAs. The product-level (tsa_id NULL) row is ALWAYS
     *  included either way — explicit decision, 2026-09-30: a team card
     *  is "that team's own TSAs plus the shared product-level entries",
     *  the same shared figure folded into every team's own card, not
     *  divided between them. */
    private function rawByProductAndDateAllTsas($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, ?array $onlyTsaIds = null, ?\Illuminate\Support\Collection $precomputedLeadCounts = null, ?array $precomputedGrossSalesAndOrders = null)
    {
        // $onlyTsaIds === null ("every TSA") NO LONGER excludes
        // tiktok_upsell TSAs (REVERSED 2026-10-13, explicit request — see
        // buildSummary()'s own doc comment for the full "she genuinely
        // does both jobs" reasoning). A 2026-10-10 fix once excluded her
        // real ExpectedIncomeEntry rows here on the premise that she's
        // represented in the ALL-view EXCLUSIVELY via her separate,
        // fully-manual TikTok Upsell section — that premise no longer
        // holds now that her real card data counts consistently
        // everywhere else on this page.
        $effectiveTsaIds = $onlyTsaIds ?? TsaShift::pluck('id')->all();

        $entries = ExpectedIncomeEntry::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->where(fn ($q2) => $q2->whereNull('tsa_id')->orWhereIn('tsa_id', $effectiveTsaIds))
            ->get()
            ->groupBy(fn (ExpectedIncomeEntry $e) => $e->product_id . ':' . $e->entry_date->toDateString());

        $customValuesByEntryKey = ExpectedIncomeCustomValue::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->groupBy(fn (ExpectedIncomeCustomValue $v) => $v->product_id . ':' . $v->entry_date->toDateString() . ':' . ($v->tsa_id ?? 'null'));

        // Hoisted out of $customValuesFor's own per-cell loop below — same
        // perf fix as rawByProductAndDate()'s own identical change, same
        // pass (see that method's own doc comment for the full reasoning).
        $customRowKeys = ExpectedIncomeCalculator::customRowKeys();

        $customValuesFor = function (int $productId, string $dateStr, ?int $tsaId) use ($customValuesByEntryKey, $customRowKeys) {
            $values = $customValuesByEntryKey->get("{$productId}:{$dateStr}:" . ($tsaId ?? 'null'), collect())->keyBy('custom_row_key');
            $row = [];
            foreach ($customRowKeys as $key) {
                $row[$key] = (float) ($values->get($key)?->value ?? 0);
            }
            return $row;
        };

        // Every TSA-owned row's own Operating Costs columns (Salaries +
        // the 20 shared pools) are LOCKED to Cost Breakdown's own figures
        // on her own product card (explicit request, 2026-09-30) — never
        // actually written back to ExpectedIncomeEntry's own stored
        // columns, which stay whatever they last were (usually 0) since
        // the lock is applied at render/response time only. Pooling raw
        // DB rows here without ALSO applying that same lock meant the top
        // "Telesales Expected Performance" summary silently read 0 for
        // every TSA's own locked Operating Costs, even though each of her
        // own individual cards correctly showed real figures (root-caused
        // live, 2026-10-01: "why is it, it did not reflecting the total in
        // TELESALES card all of the costs in the tsa's"). Computed ONCE
        // here (not per row) since every TSA's own Salaries figure is the
        // same across every one of her own product/day rows, and the 20
        // pools are the same for every TSA company-wide.
        // perProductByTsaIdTwice() -> perProductByTsaId() (2026-10-06 —
        // see buildSummaryRow()'s own $productCardLookups comment for the
        // full reasoning: a product card's own Salaries divides the real
        // Daily Rate by product count ONCE, same as every other Operating
        // Costs row, not twice).
        // A 0-day TSA's own share of every shared pool cost is zeroed here
        // too (2026-10-06 — see TsaDailyRateService::
        // zeroOperatingCostsIfZeroDay()'s own doc comment). Per DAY
        // (monthly Cost Breakdown, 2026-10-15): that day's month/roster.
        $operatingOverridesFor = fn (int $tsaId, string $dateStr) => TsaDailyRateService::withDate($dateStr, fn () => array_merge(
            TsaDailyRateService::zeroOperatingCostsIfZeroDay($tsaId, self::costLookups('product')[3]),
            ['salaries' => self::costLookups('product')[0][$tsaId] ?? 0.0]
        ));

        // Same real-tally override as rawByProductAndDate() (2026-10-09 —
        // see leadCountsByProductAndDate()'s own doc comment). Looked up
        // per REAL tsa_id here (not a single pooled figure) since this
        // method returns one row per distinct tsa_id per cell, each summed
        // together by the caller — the shared tsa_id-NULL row is
        // deliberately given 0 leads rather than any pooled total, since
        // every real TSA's own row here already carries her own real count;
        // giving the NULL row a pooled total on top would double it.
        // $precomputedLeadCounts/$precomputedGrossSalesAndOrders (perf fix,
        // 2026-10-12, PART 5 — root-caused live AGAIN after PART 4: this
        // was the ONE call site on this page that never accepted these two
        // precomputed maps as parameters, so every one of buildSummaryRow()'s
        // own callers that pass precomputed maps down to IT (teamNetIncomeByDate(),
        // grossSalesAndNetIncomeByProductAndDate() — both calling
        // buildSummaryRow() once PER team PER day) still silently triggered
        // a full, whole-range leadCountsByProductAndDate()/
        // grossSalesByProductAndDate() recompute HERE, inside
        // rawByProductAndDateAllTsas(), on every single one of those calls
        // — a 9-day/2-team range still re-ran the entire team×day×product
        // matchingOrders() pass 18 times even after PART 4 shipped, since
        // PART 4 only hoisted these maps as far as buildSummaryRow()'s own
        // parameters, never threaded them the one level deeper into this
        // method. Same "explicit parameters, not a static cache" Octane
        // worker-mode reasoning as every other call site on this page.
        $leadCounts = $precomputedLeadCounts ?? $this->leadCountsByProductAndDate($products, $dates, $dateFrom, $dateTo);
        // Gross Sales/Number of Orders (2026-10-10) — same "real per-
        // tsa_id figure, NULL row gets 0" rule as Number of Leads directly
        // above, for the same reason. UNLIKE Number of Leads, these are
        // NOT redundant for buildSummaryRow()'s own summary-card path —
        // both cascade into derive()'s whole P&L chain, so that method
        // needs them summed into $pooledRaw BEFORE sum()/derive() ever
        // runs, not patched onto an already-derived row afterward the way
        // totalRealLeads() patches number_of_leads (a display-only stat
        // with nothing downstream) — see buildSummaryRow()'s own
        // $pooledGrossSales/$grossSalesGap doc comment for exactly how the
        // real totals (totalRealGrossSales(), same "missing entry ≠ zero"
        // gap this file already covers for leads) get folded in before
        // derive() runs.
        ['grossSales' => $grossSalesMap, 'numberOfOrders' => $numberOfOrdersMap, 'cancelled' => $cancelledMap] = $precomputedGrossSalesAndOrders ?? $this->grossSalesByProductAndDate($products, $dates, $dateFrom, $dateTo);

        return $products->mapWithKeys(function (Product $p) use ($dates, $entries, $customValuesFor, $operatingOverridesFor, $leadCounts, $grossSalesMap, $numberOfOrdersMap, $cancelledMap) {
            return [$p->id => $dates->mapWithKeys(function ($date) use ($p, $entries, $customValuesFor, $operatingOverridesFor, $leadCounts, $grossSalesMap, $numberOfOrdersMap, $cancelledMap) {
                $dateStr = $date->toDateString();
                $rowsForThisCell = $entries->get($p->id . ':' . $dateStr, collect());

                if ($rowsForThisCell->isEmpty()) {
                    return [$dateStr => collect([array_merge(['number_of_leads' => 0, 'gross_sales' => 0.0, 'number_of_orders' => 0, 'cancelled' => 0.0], $customValuesFor($p->id, $dateStr, null))])];
                }

                $rows = $rowsForThisCell->map(function (ExpectedIncomeEntry $entry) use ($p, $dateStr, $customValuesFor, $operatingOverridesFor, $leadCounts, $grossSalesMap, $numberOfOrdersMap, $cancelledMap) {
                    $row = array_merge($entry->toArray(), $customValuesFor($p->id, $dateStr, $entry->tsa_id));
                    $row['number_of_leads'] = $entry->tsa_id === null ? 0 : $this->leadsForTsaProductDate($leadCounts, $entry->tsa_id, $p->id, $dateStr);
                    $row['gross_sales'] = $entry->tsa_id === null ? 0.0 : $this->grossSalesForTsaProductDate($grossSalesMap, $entry->tsa_id, $p->id, $dateStr);
                    $row['number_of_orders'] = $entry->tsa_id === null ? 0 : (int) $this->grossSalesForTsaProductDate($numberOfOrdersMap, $entry->tsa_id, $p->id, $dateStr);
                    $row['cancelled'] = $entry->tsa_id === null ? 0.0 : $this->grossSalesForTsaProductDate($cancelledMap, $entry->tsa_id, $p->id, $dateStr);
                    return $entry->tsa_id === null ? $row : array_merge($row, $operatingOverridesFor($entry->tsa_id, $dateStr));
                });

                return [$dateStr => $rows];
            })];
        });
    }

    /** Auto-save (same debounced-PATCH-per-field convention as Projections'
     *  own updateColumn() / DSPPR's own update()) — one (product, tsa,
     *  date, field) at a time. Upserts via updateOrCreate() since a cell
     *  with nothing typed into it yet has no row to PATCH onto. $tsaShift
     *  is null for the "ALL" view's own product-level cards (route
     *  data.expected-income.update — no {tsaShift} segment) or a real TSA
     *  for her own card (route data.expected-income.update-tsa). */
    public function update(Request $request, Product $product, string $date, ?TsaShift $tsaShift = null)
    {
        $data = $request->validate([
            'roas'                       => ['sometimes', 'numeric', 'min:0'],
            'standard_cost_per_message'  => ['sometimes', 'numeric', 'min:0'],
            'actual_cost_per_lead' => ['sometimes', 'numeric', 'min:0'],
            // number_of_leads is no longer a manual field (explicit
            // request, 2026-10-09: "automate it to tally to the overall
            // number of leads per product per tsa") — deliberately not
            // accepted here any more, same "a stray POST can't write a
            // stale value the view no longer reflects" reasoning as
            // returns/delivered below; ExpectedIncomeController::
            // leadCountsByProductAndDate() is the only source for this
            // field now.
            // number_of_orders is ALSO no longer a manual field (explicit
            // request, 2026-10-10: "the number of orders is the upsell")
            // — same reasoning: deliberately not accepted here, same
            // ExpectedIncomeController::grossSalesByProductAndDate() is
            // the only source now (it also returns the Number of Orders
            // map, see that method's own doc comment).
            // average_order_value is ALSO no longer a manual field
            // (explicit request, 2026-10-10: "the average order value
            // (AOV) i want to make it automated too") — same reasoning:
            // deliberately not accepted here any more. Derived inside
            // ExpectedIncomeCalculator::derive() from Gross Sales ÷
            // Number of Orders, both already real/automated.
            // gross_sales is no longer a manual field either (explicit
            // request, 2026-10-10: "in the expected income the gross
            // sales is make it automated too") — same reasoning/pattern
            // as number_of_leads above: deliberately not accepted here so
            // a stray POST can't write a stale value the view no longer
            // reflects. ExpectedIncomeController::grossSalesByProductAndDate()
            // is the only source now.
            // cancelled is ALSO no longer a manual field (explicit
            // request, 2026-10-13: "i want the cancelled row make it
            // automated and the function of that is the cancelled status
            // and awaiting stock status will be combine to the cancelled
            // status row" / "the only cancelled and awaiting stock status
            // that has tsa real upsell") — same reasoning: deliberately
            // not accepted here any more. ExpectedIncomeController::
            // grossSalesByProductAndDate() is the only source now (it also
            // returns the Cancelled map, see that method's own doc
            // comment).
            // returns/delivered are no longer manual inputs — derived from
            // gross_sales/cancelled instead (explicit correction,
            // 2026-10-01: "the only auto is Projected Returns / Projected
            // Delivered") — deliberately not accepted here so a stray POST
            // can't write a stale value the view no longer reflects.
            'tax_allocation'       => ['sometimes', 'numeric', 'min:0'],
            'product_cost'         => ['sometimes', 'numeric', 'min:0'],
            'advertising_cost'     => ['sometimes', 'numeric', 'min:0'],
            'ads_vat'              => ['sometimes', 'numeric', 'min:0'],
            'ai_expense'           => ['sometimes', 'numeric', 'min:0'],
            'shipping_fee'          => ['sometimes', 'numeric', 'min:0'],
            'product_research'      => ['sometimes', 'numeric', 'min:0'],
            'salaries'                    => ['sometimes', 'numeric', 'min:0'],
            'communication_allowance'     => ['sometimes', 'numeric', 'min:0'],
            'thirteenth_month_allowance'  => ['sometimes', 'numeric', 'min:0'],
            'sil'                         => ['sometimes', 'numeric', 'min:0'],
            'government_benefits'         => ['sometimes', 'numeric', 'min:0'],
            'miscellaneous_expenses'      => ['sometimes', 'numeric', 'min:0'],
            'magic_fund'                  => ['sometimes', 'numeric', 'min:0'],
            'company_assets'              => ['sometimes', 'numeric', 'min:0'],
            'executive_benefits'          => ['sometimes', 'numeric', 'min:0'],
            'office_miscellaneous'        => ['sometimes', 'numeric', 'min:0'],
            'maintenance_expenses'        => ['sometimes', 'numeric', 'min:0'],
            'consultants'                 => ['sometimes', 'numeric', 'min:0'],
            'managers_allowance'          => ['sometimes', 'numeric', 'min:0'],
            'birthday_cake_allowance'     => ['sometimes', 'numeric', 'min:0'],
            'water_bill'                  => ['sometimes', 'numeric', 'min:0'],
            'internet'                    => ['sometimes', 'numeric', 'min:0'],
            'rent'                        => ['sometimes', 'numeric', 'min:0'],
            'electricity'                 => ['sometimes', 'numeric', 'min:0'],
            'geniusmakers_management_fee' => ['sometimes', 'numeric', 'min:0'],
            'business_development_fund'   => ['sometimes', 'numeric', 'min:0'],
            'hmo_expense'                 => ['sometimes', 'numeric', 'min:0'],
            // Lock toggle (explicit request, 2026-10-02: "can you create
            // lock icon too in this, like in the projections" — same "when
            // it is lock it can't edit" behavior Projections' own
            // Opening/Closing Shift cards already have). Only meaningful
            // on a TSA-scoped card ($tsaShift present) — the ALL view's own
            // product-level cards and the overall "TELESALES"/TSA overview
            // rollup card get no lock button at all, nothing to toggle
            // there. Freezes every editable field on this ONE product card
            // without touching its stored values.
            'is_locked'            => ['sometimes', 'boolean'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();
        $tsaId = $tsaShift?->id;

        // whereDate(), not a plain ['entry_date' => $entryDate] attribute
        // match on firstOrNew() — same SQLite date-cast pitfall as
        // DsPprReportController::update().
        $entry = ExpectedIncomeEntry::where('product_id', $product->id)->where('tsa_id', $tsaId)->whereDate('entry_date', $entryDate)->first()
            ?? new ExpectedIncomeEntry(['product_id' => $product->id, 'tsa_id' => $tsaId, 'entry_date' => $entryDate]);

        // A locked entry refuses every OTHER field (never trust the
        // frontend's own `disabled` attribute alone — that only stops a
        // real browser from submitting one, not a direct PATCH). Still
        // allows is_locked itself through, so the unlock action that
        // restores editing isn't also blocked by the lock it's lifting.
        if ($entry->is_locked) {
            $data = array_intersect_key($data, ['is_locked' => true]);
        }

        $old = $entry->only(array_keys($data));
        $entry->fill($data);
        $entry->save();

        // Every activity recorded (explicit request, 2026-10-07: "all
        // activites in every page should be recorded ... what she
        // edited"). is_locked gets a verb-phrased entry instead of the
        // generic field_updated phrasing.
        $context = $product->display_name . ($tsaShift ? ' — ' . $tsaShift->display_name : '') . ' on ' . Carbon::parse($entryDate)->format('M j, Y');
        foreach ($data as $field => $newValue) {
            if ($field === 'is_locked') {
                if ((bool) $old['is_locked'] === (bool) $newValue) continue;
                ActivityLogger::log($newValue ? 'expected_income.card_locked' : 'expected_income.card_unlocked', $entry, ($newValue ? 'Locked' : 'Unlocked') . " the \"{$context}\" card.");
                continue;
            }
            if ((float) ($old[$field] ?? 0) == (float) $newValue) continue;
            ActivityLogger::logFieldUpdate('expected_income.field_updated', $entry, $field, $old[$field] ?? 0, $newValue, $context, fn ($v) => number_format((float) $v, 2));
        }

        $response = [
            'success' => true,
            'derived' => $this->derivedForProductOrGroup($product, $tsaId, $entryDate),
        ];

        // The lock toggle swaps every field on this card between a real
        // <input> and the same input, now `disabled` — a structural change
        // applyComputed() can't express (it only updates an EXISTING
        // element's own text/value). Rendering the card's own fresh
        // _product-card partial server-side and returning it here lets the
        // frontend cross-fade the whole card in place (explicit request,
        // 2026-10-02: "has smooth transition too like in the projection"),
        // same convention as ProjectionController::updateColumn()'s own
        // 'cardHtml' — only rendered when this request actually touched
        // is_locked, every other per-field save still only needs the
        // cheap JSON path above.
        if (array_key_exists('is_locked', $data) && $tsaShift) {
            $response['cardHtml'] = $this->renderProductCardHtml($product, $tsaShift, $entryDate);
        }

        return response()->json($response);
    }

    /** Same auto-save shape as update() above, for one of the 2 fixed
     *  TikTok cards — see buildTiktokRows()'s own doc comment for why
     *  this is a separate table/endpoint (no real Product, no lock
     *  toggle, no Cost Breakdown override). Validates $cardKey against
     *  the fixed CARDS list rather than any dynamic lookup — there are
     *  only ever these 2 cards. */
    public function updateTiktok(Request $request, string $cardKey, TsaShift $tsaShift, string $date)
    {
        if (!array_key_exists($cardKey, ExpectedIncomeTiktokEntry::CARDS)) {
            abort(404);
        }

        $data = $request->validate([
            'roas'                       => ['sometimes', 'numeric', 'min:0'],
            'standard_cost_per_message'  => ['sometimes', 'numeric', 'min:0'],
            'actual_cost_per_lead' => ['sometimes', 'numeric', 'min:0'],
            'number_of_leads'      => ['sometimes', 'integer', 'min:0'],
            // number_of_orders/gross_sales/cancelled: automated from
            // TikTok-tagged POS orders from TiktokUpsellTotals::
            // AUTOMATED_FROM on (explicit request, 2026-10-14) — stripped
            // below for those days, still typed in for earlier ones.
            'number_of_orders'     => ['sometimes', 'integer', 'min:0'],
            'gross_sales'          => ['sometimes', 'numeric', 'min:0'],
            'cancelled'            => ['sometimes', 'numeric', 'min:0'],
            // average_order_value is no longer accepted here either
            // (explicit request, 2026-10-10: "the average order value
            // (AOV) i want to make it automated too") — unlike Gross
            // Sales/Number of Orders, which stay genuinely manual on
            // TikTok's own 2 fixed cards, AOV is now UNCONDITIONALLY
            // derived (Gross Sales ÷ Number of Orders) inside
            // ExpectedIncomeCalculator::derive() itself, real product
            // card or TikTok card alike — see that method's own doc
            // comment. A stray POST here would silently never be
            // reflected anywhere.
            'tax_allocation'       => ['sometimes', 'numeric', 'min:0'],
            'product_cost'         => ['sometimes', 'numeric', 'min:0'],
            'advertising_cost'     => ['sometimes', 'numeric', 'min:0'],
            'ads_vat'              => ['sometimes', 'numeric', 'min:0'],
            'ai_expense'           => ['sometimes', 'numeric', 'min:0'],
            'shipping_fee'          => ['sometimes', 'numeric', 'min:0'],
            'product_research'      => ['sometimes', 'numeric', 'min:0'],
            'salaries'                    => ['sometimes', 'numeric', 'min:0'],
            'communication_allowance'     => ['sometimes', 'numeric', 'min:0'],
            'thirteenth_month_allowance'  => ['sometimes', 'numeric', 'min:0'],
            'sil'                         => ['sometimes', 'numeric', 'min:0'],
            'government_benefits'         => ['sometimes', 'numeric', 'min:0'],
            'miscellaneous_expenses'      => ['sometimes', 'numeric', 'min:0'],
            'magic_fund'                  => ['sometimes', 'numeric', 'min:0'],
            'company_assets'              => ['sometimes', 'numeric', 'min:0'],
            'executive_benefits'          => ['sometimes', 'numeric', 'min:0'],
            'office_miscellaneous'        => ['sometimes', 'numeric', 'min:0'],
            'maintenance_expenses'        => ['sometimes', 'numeric', 'min:0'],
            'consultants'                 => ['sometimes', 'numeric', 'min:0'],
            'managers_allowance'          => ['sometimes', 'numeric', 'min:0'],
            'birthday_cake_allowance'     => ['sometimes', 'numeric', 'min:0'],
            'water_bill'                  => ['sometimes', 'numeric', 'min:0'],
            'internet'                    => ['sometimes', 'numeric', 'min:0'],
            'rent'                        => ['sometimes', 'numeric', 'min:0'],
            'electricity'                 => ['sometimes', 'numeric', 'min:0'],
            'geniusmakers_management_fee' => ['sometimes', 'numeric', 'min:0'],
            'business_development_fund'   => ['sometimes', 'numeric', 'min:0'],
            'hmo_expense'                 => ['sometimes', 'numeric', 'min:0'],
            // Lock toggle (explicit request, 2026-10-07: "i want to have
            // like lock icon too") — same one-lock-per-card semantics as
            // update()'s own is_locked (see that method's own doc comment).
            'is_locked'                   => ['sometimes', 'boolean'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();
        if (TiktokUpsellTotals::isAutomated($entryDate)) {
            $data = array_diff_key($data, array_flip(TiktokUpsellTotals::FIELDS));
        }

        $entry = ExpectedIncomeTiktokEntry::where('card_key', $cardKey)->where('tsa_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first()
            ?? new ExpectedIncomeTiktokEntry(['card_key' => $cardKey, 'tsa_id' => $tsaShift->id, 'entry_date' => $entryDate]);

        // Never trust the frontend's own `disabled` attribute alone — same
        // "a locked entry refuses every OTHER field" guard update() already
        // has against a direct PATCH bypassing a locked card's own inputs.
        if ($entry->is_locked) {
            $data = array_intersect_key($data, ['is_locked' => true]);
        }

        $old = $entry->only(array_keys($data));
        $entry->fill($data);
        $entry->save();

        $context = ExpectedIncomeTiktokEntry::CARDS[$cardKey] . ' — ' . $tsaShift->display_name . ' on ' . Carbon::parse($entryDate)->format('M j, Y');
        foreach ($data as $field => $newValue) {
            if ($field === 'is_locked') {
                if ((bool) $old['is_locked'] === (bool) $newValue) continue;
                ActivityLogger::log($newValue ? 'expected_income.card_locked' : 'expected_income.card_unlocked', $entry, ($newValue ? 'Locked' : 'Unlocked') . " the \"{$context}\" card.");
                continue;
            }
            if ((float) ($old[$field] ?? 0) == (float) $newValue) continue;
            ActivityLogger::logFieldUpdate('expected_income.field_updated', $entry, $field, $old[$field] ?? 0, $newValue, $context, fn ($v) => number_format((float) $v, 2));
        }

        $response = [
            'success' => true,
            'derived' => ExpectedIncomeCalculator::derive(self::withTiktokAuto(
                $entry->toArray(), TiktokUpsellTotals::byTsaCardAndDate($entryDate, $entryDate), $tsaShift->id, $cardKey, $entryDate
            )),
        ];

        // Locking swaps every input on this card for the same input, now
        // `disabled` — a structural change the frontend's generic
        // applyDerived() can't express in place, so (same convention as
        // update()'s own cardHtml) the server re-renders this one card's
        // fresh _tiktok-card partial and the frontend cross-fades the
        // swap (wireLockToggles() in expected-income.blade.php already
        // handles this generically — no JS change needed for this card
        // type specifically).
        if (array_key_exists('is_locked', $data)) {
            $response['cardHtml'] = $this->renderTiktokCardHtml($cardKey, $tsaShift, $entryDate);
        }

        return response()->json($response);
    }

    /** Re-renders ONE TikTok card's own fresh _tiktok-card partial — same
     *  role as renderProductCardHtml() below plays for a real product
     *  card, used by updateTiktok()'s own lock-toggle response above so
     *  the frontend can cross-fade the whole card in place. Recomputes
     *  her own Salaries split fresh (same formula buildTiktokRows() uses)
     *  rather than reusing a stale value, since a lock toggle itself
     *  never changes it but this helper needs to be correct on its own
     *  regardless of what triggered the re-render. */
    private function renderTiktokCardHtml(string $cardKey, TsaShift $tsaShift, string $entryDate): string
    {
        $entry = ExpectedIncomeTiktokEntry::where('card_key', $cardKey)->where('tsa_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first();
        $dailyRate = TsaDailyRateService::dailyRateByTsaId()[$tsaShift->id] ?? 0.0;
        $cardSalaries = $dailyRate / count(ExpectedIncomeTiktokEntry::CARDS);

        $raw = self::withTiktokAuto(
            $entry?->toArray() ?? ['tsa_id' => $tsaShift->id, 'card_key' => $cardKey, 'entry_date' => $entryDate],
            TiktokUpsellTotals::byTsaCardAndDate($entryDate, $entryDate), $tsaShift->id, $cardKey, $entryDate
        );
        $derived = ExpectedIncomeCalculator::withOverriddenOperatingCosts(
            ExpectedIncomeCalculator::derive($raw),
            ['salaries' => $cardSalaries]
        );

        $card = [
            'key' => $cardKey,
            'label' => ExpectedIncomeTiktokEntry::CARDS[$cardKey],
            'derived' => $derived,
            'entry' => $entry,
            'locked' => (bool) ($entry?->is_locked ?? false),
        ];

        return view('data.expected-income._tiktok-card', [
            'card' => $card, 'tsa' => $tsaShift, 'dateStr' => $entryDate,
            'fmtMoney' => fn ($n) => number_format((float) $n, 2),
            'fmtPct'   => fn ($n) => number_format(((float) $n) * 100, 2) . '%',
            'editable' => true,
        ])->render();
    }

    /** Re-renders ONE TSA-scoped product card's own fresh _product-card
     *  partial — used by update()'s own lock-toggle response above so the
     *  frontend can cross-fade the whole card in place instead of a full
     *  page reload. Rebuilds the same $row shape ProductGrouping::rows()
     *  produces (a grouped card re-renders with its own group label and
     *  every member's pooled total, not just $product alone), since the
     *  card partial needs 'label'/'products' to render its own title and
     *  data-action correctly. */
    private function renderProductCardHtml(Product $product, TsaShift $tsaShift, string $entryDate): string
    {
        $group = ProductGroup::whereHas('products', fn ($q) => $q->where('products.id', $product->id))->first();
        $groupProducts = $group ? $group->products()->where('is_hidden', false)->orderBy('sort_order')->get() : collect([$product]);

        $row = [
            'label'    => $group->label ?? $product->display_name,
            'products' => $groupProducts,
            'group'    => $group,
            'derived'  => $this->derivedForProductOrGroup($product, $tsaShift->id, $entryDate),
        ];
        // The lock button/disabled-input state reads the FIRST member's
        // own entry — same "writes land on the first member" convention
        // update()/updateCustomRow() already use, so a grouped card's own
        // lock state always matches whichever entry a save would touch.
        $entry = ExpectedIncomeEntry::where('product_id', $groupProducts->first()->id)->where('tsa_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first();

        return view('data.expected-income._product-card', [
            'row' => $row, 'tsa' => $tsaShift, 'dateStr' => $entryDate, 'entry' => $entry,
            'sellingRows' => ExpectedIncomeCalculator::sellingCostRows(),
            'operatingRows' => ExpectedIncomeCalculator::operatingCostRows(),
            'customRowKeys' => ExpectedIncomeCalculator::customRowKeys(),
            'fmtMoney' => fn ($n) => number_format((float) $n, 2),
            'fmtPct'   => fn ($n) => number_format(((float) $n) * 100, 2) . '%',
        ])->render();
    }

    /** Separate endpoint for one custom row's own value (explicit request,
     *  2026-09-28 — see routes/web.php's own comment on this route for why
     *  it's split from update() above: a custom row's key is dynamic, not
     *  one of update()'s fixed whitelisted field names). Same "validate the
     *  key against the real allowed set, upsert by (product, tsa, date,
     *  key)" convention as ProjectionController::updateRates(). */
    public function updateCustomRow(Request $request, Product $product, string $date, ?TsaShift $tsaShift = null)
    {
        $data = $request->validate([
            'key'   => ['required', 'string', 'in:' . implode(',', ExpectedIncomeCalculator::customRowKeys())],
            'value' => ['required', 'numeric', 'min:0'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();
        $tsaId = $tsaShift?->id;

        // A locked card's own custom row values are frozen too — same
        // "never trust the frontend's own disabled attribute alone" guard
        // update() applies to its built-in fields, checked against the
        // SAME entry a locked product card's lock state lives on.
        $isLocked = ExpectedIncomeEntry::where('product_id', $product->id)->where('tsa_id', $tsaId)->whereDate('entry_date', $entryDate)->value('is_locked') ?? false;
        if ($isLocked) {
            return response()->json([
                'success' => false,
                'message' => 'This card is locked.',
            ], 422);
        }

        // whereDate() lookup, not a plain ['entry_date' => $entryDate]
        // match inside updateOrCreate()'s own conditions — same SQLite
        // date-cast pitfall as every other whereDate() comment in this
        // controller.
        $customValue = ExpectedIncomeCustomValue::where('product_id', $product->id)
            ->where('tsa_id', $tsaId)
            ->where('custom_row_key', $data['key'])
            ->whereDate('entry_date', $entryDate)
            ->first()
            ?? new ExpectedIncomeCustomValue(['product_id' => $product->id, 'tsa_id' => $tsaId, 'entry_date' => $entryDate, 'custom_row_key' => $data['key']]);
        $oldValue = $customValue->value ?? 0;
        $customValue->value = $data['value'];
        $customValue->save();

        if ((float) $oldValue != (float) $data['value']) {
            $context = $product->display_name . ($tsaShift ? ' — ' . $tsaShift->display_name : '') . ' on ' . Carbon::parse($entryDate)->format('M j, Y');
            ActivityLogger::logFieldUpdate('expected_income.field_updated', $customValue, $data['key'], $oldValue, $data['value'], $context, fn ($v) => number_format((float) $v, 2));
        }

        // The built-in fields' own row still needs deriving alongside the
        // just-saved custom value — a custom row participates in Total
        // Selling/Operating Costs and Net Income exactly like a built-in
        // one, so the frontend needs the SAME full derived payload update()
        // above returns, not just the one changed field.
        return response()->json([
            'success' => true,
            'derived' => $this->derivedForProductOrGroup($product, $tsaId, $entryDate),
        ]);
    }

    /** Shared by update() and updateCustomRow() above — $product is always
     *  a group's own FIRST member when it's grouped (see
     *  expected-income.blade.php's own data-product-id), so a save always
     *  lands on that one real product's own ExpectedIncomeEntry, but the
     *  card DISPLAYING it must still show the FULL group total (explicit
     *  request, 2026-09-29: "in the side of users the merged products is
     *  only 1 product only", same fix already applied to DSPPR's own
     *  identical update()) — the frontend has no way to know the other
     *  member(s)' own stored numbers (never rendered anywhere once
     *  grouped), so the server resolves and returns the true summed
     *  figures here instead of just $product's own slice. $tsaId scopes
     *  every lookup here to the same TSA (or NULL) the save itself used. */
    private function derivedForProductOrGroup(Product $product, ?int $tsaId, string $entryDate): array
    {
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());

        $group = ProductGroup::whereHas('products', fn ($q) => $q->where('products.id', $product->id))->first();
        if (!$group) {
            $entry = ExpectedIncomeEntry::where('product_id', $product->id)->where('tsa_id', $tsaId)->whereDate('entry_date', $entryDate)->first()
                ?? new ExpectedIncomeEntry(['product_id' => $product->id, 'tsa_id' => $tsaId, 'entry_date' => $entryDate]);

            $derived = ExpectedIncomeCalculator::derive($this->withCustomRowValues($entry, $tsaId, $entryDate), $sellingKeys, $operatingKeys);
            // Same gate as buildTeamDailyRows()/buildSummaryRow()'s own
            // initial-page-render fix (2026-10-06) — an UNFLAGGED
            // product's live autosave response must also show NO
            // Operating Costs/Tax Allocation share, not just the initial
            // render (real bug caught live: editing Gross Sales on an
            // unflagged product's card was refreshing it back to the
            // locked/overridden figures via THIS endpoint, even after the
            // page-render fix already corrected the initial load).
            return $product->has_cost_allocation ? $this->withOperatingCostOverridesIfTsaScoped($derived, $tsaId) : $derived;
        }

        // Hidden members drop out, same as reportProducts().
        $members = $group->products->where('is_hidden', false)->values();
        $memberEntries = ExpectedIncomeEntry::whereIn('product_id', $members->pluck('id'))
            ->where('tsa_id', $tsaId)
            ->whereDate('entry_date', $entryDate)
            ->get()
            ->keyBy('product_id');
        $pooled = $members->map(function (Product $p) use ($memberEntries, $tsaId, $entryDate) {
            $memberEntry = $memberEntries->get($p->id) ?? new ExpectedIncomeEntry(['product_id' => $p->id, 'tsa_id' => $tsaId, 'entry_date' => $entryDate]);
            return $this->withCustomRowValues($memberEntry, $tsaId, $entryDate);
        });

        $summed = ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
        // Same gate — a grouped card's own flag is its FIRST member's
        // (same "the group's first member owns the edit" convention
        // ProductGrouping::rows()/TsaDailyRateService::flaggedProductRows()
        // already use).
        return ($members->first() ?? $group->products->first())->has_cost_allocation ? $this->withOperatingCostOverridesIfTsaScoped($summed, $tsaId) : $summed;
    }

    /** Every Operating Costs row AND Tax Allocation on a TSA-scoped card are
     *  locked to Cost Breakdown's own figures (explicit requests,
     *  2026-09-30 for Operating Costs: "the salaries row is based to the
     *  Daily Rate / Product", then "the daily cost is it is this
     *  [Communication Allowance, 13th Month Allowance, SIL, ...]"; 2026-10-02
     *  for Tax Allocation: "the tax allocation in tsa cards is should be
     *  not editable") — same overrides buildTeamDailyRows()'s own initial
     *  page render already applies (via $productCardOverrides/
     *  $productCardTaxAllocation there), reapplied here so a live
     *  autosave's returned 'derived' payload never shows a stale figure.
     *  Operating Costs uses the PRODUCT-divided figure, now a SINGLE
     *  division (perProductByTsaId() — corrected 2026-10-06, same day as
     *  the overview-card fix above: "in the products it should be
     *  divided by 7 like other cost too", confirmed live 1,682.86 ÷ 7 ≈
     *  240.41, not ÷7÷7 ≈ 34.34); Tax Allocation still uses its own
     *  perProductTaxAllocationByTsaId() (unaffected — that correction
     *  wasn't part of this request), matching this call site's own
     *  per-product-card scope — see derivedForProductOrGroup()'s own doc
     *  comment. A null $tsaId (the ALL view's own product-level cards) is
     *  unaffected by either lock. */
    private function withOperatingCostOverridesIfTsaScoped(array $derived, ?int $tsaId): array
    {
        if ($tsaId === null) {
            return $derived;
        }

        $dailyRatePerProductByTsaId = TsaDailyRateService::perProductByTsaId();
        // A 0-day TSA's own share of every shared pool cost is zeroed here
        // too (2026-10-06 — see TsaDailyRateService::
        // zeroOperatingCostsIfZeroDay()'s own doc comment); her Salaries
        // is already 0.0 via perProductByTsaId() on its own.
        // A TikTok-flagged TSA's own Salaries is excluded here too
        // (narrowed 2026-10-14 — see buildTeamDailyRows()'s own matching
        // doc comment) — this re-applies the SAME overrides a live
        // autosave response must match, so it can't skip this fix either.
        $isTiktokFlagged = TsaShift::where('id', $tsaId)->value('tiktok_upsell') ?? false;
        $operatingCostOverrides = array_merge(
            TsaDailyRateService::zeroOperatingCostsIfZeroDay($tsaId, TsaDailyRateService::dailyCostPerProductRow()),
            ['salaries' => $isTiktokFlagged ? 0.0 : ($dailyRatePerProductByTsaId[$tsaId] ?? 0.0)]
        );

        $derived = ExpectedIncomeCalculator::withOverriddenOperatingCosts($derived, $operatingCostOverrides);

        $taxAllocationPerProductByTsaId = TsaDailyRateService::perProductTaxAllocationByTsaId();
        // Fallback for a TSA missing from the pre-built map (root-caused
        // live, 2026-10-03: "why is it all last tsa has no tax?") — same
        // direct-compute fallback buildTeamDailyRows() uses for the
        // initial page render.
        $tax = $taxAllocationPerProductByTsaId[$tsaId] ?? (function () use ($tsaId) {
            $tsa = TsaShift::find($tsaId);
            return $tsa ? TsaDailyRateService::perProductTaxAllocationForTsa($tsa) : 0.0;
        })();
        return ExpectedIncomeCalculator::withOverriddenTaxAllocation($derived, $tax);
    }

    /** Merges every custom row's own currently-saved value onto $entry's
     *  raw array, keyed the same way ExpectedIncomeCalculator::derive()
     *  reads any other field — shared by update() and updateCustomRow()
     *  above so both return a derived payload that reflects EVERY custom
     *  row's value, not just whichever single field either endpoint itself
     *  just changed. */
    private function withCustomRowValues(ExpectedIncomeEntry $entry, ?int $tsaId, string $entryDate): array
    {
        $row = $entry->toArray();
        $customValues = ExpectedIncomeCustomValue::where('product_id', $entry->product_id)
            ->where('tsa_id', $tsaId)
            ->whereDate('entry_date', $entryDate)
            ->get()
            ->keyBy('custom_row_key');

        foreach (ExpectedIncomeCalculator::customRowKeys() as $key) {
            $row[$key] = (float) ($customValues->get($key)?->value ?? 0);
        }

        // Same real-tally override every other raw-row builder on this page
        // applies (2026-10-09 — see leadCountsByProductAndDate()'s own doc
        // comment) — this is the ONE raw-row assembly path that ISN'T
        // reached through rawByProductAndDate()/rawByProductAndDateAllTsas()
        // (derivedForProductOrGroup()'s own live-autosave/lock-toggle
        // response calls this directly per product), so without this
        // override a card's own cross-fade-in response after a lock toggle
        // would silently show the stale stored value (usually 0, since
        // that column is no longer written) instead of the same figure the
        // page's own initial render just showed.
        //
        // The FULL product roster, not just collect([$entry->product]), is
        // passed as leadCountsByProductAndDate()'s own matching context —
        // same over-counting bug as buildSummaryRow()'s own fix, same day
        // (see that method's doc comment): matchingOrders()'s stale-tag
        // conflict guard needs every sibling product visible to correctly
        // exclude an order that actually belongs to one of them.
        $leadCounts = $this->leadCountsByProductAndDate(self::reportProducts(), collect([Carbon::parse($entryDate)]), $entryDate, $entryDate);
        $row['number_of_leads'] = $this->leadsForTsaProductDate($leadCounts, $tsaId, $entry->product_id, Carbon::parse($entryDate)->toDateString());

        // Gross Sales/Number of Orders (2026-10-10) — same real-tally
        // override, same reasoning. Unlike buildSummaryRow()'s own
        // pre-sum() top-up (this row isn't pooled with any others — it's a
        // single cell — so a direct field assignment here, before this
        // row ever reaches derive(), is enough; derive() picks up the
        // corrected gross_sales/number_of_orders and computes every
        // cascading figure fresh from them).
        $dateStr = Carbon::parse($entryDate)->toDateString();
        ['grossSales' => $grossSalesMap, 'numberOfOrders' => $numberOfOrdersMap, 'cancelled' => $cancelledMap] = $this->grossSalesByProductAndDate(self::reportProducts(), collect([Carbon::parse($entryDate)]), $entryDate, $entryDate);
        $row['gross_sales'] = $this->grossSalesForTsaProductDate($grossSalesMap, $tsaId, $entry->product_id, $dateStr);
        $row['number_of_orders'] = (int) $this->grossSalesForTsaProductDate($numberOfOrdersMap, $tsaId, $entry->product_id, $dateStr);
        // Cancelled (2026-10-13) — same single-cell direct override as
        // Gross Sales/Number of Orders above, same reasoning.
        $row['cancelled'] = $this->grossSalesForTsaProductDate($cancelledMap, $tsaId, $entry->product_id, $dateStr);

        return $row;
    }
}
