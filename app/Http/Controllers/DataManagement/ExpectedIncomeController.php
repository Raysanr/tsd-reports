<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ExpectedIncomeCustomValue;
use App\Models\ExpectedIncomeEntry;
use App\Models\ExpectedIncomeTiktokEntry;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TsaShift;
use App\Support\DateRangeFilter;
use App\Support\ExpectedIncomeCalculator;
use App\Support\ProductGrouping;
use App\Support\Teams;
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
        $products = Product::orderBy('team')->orderBy('sort_order')->get();

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
        // 'tiktok' since that branch short-circuits first.
        $summaryData = $selectedTeam === 'tiktok'
            ? ['summaryCards' => collect(), 'summaryOverallTotal' => ExpectedIncomeCalculator::derive([]), 'teamSummaryRows' => collect()]
            : $this->buildSummary($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $selectedTeam === 'all' ? null : TsaShift::where('team', $teamsConfig[$selectedTeam]['order_team'])->pluck('id')->all());

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

        return view('data.expected-income', array_merge($summaryData, $dailyData, $tiktokData, [
            'products' => $products,
            'dates'    => $dates,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'teams'    => $teams,
            'selectedTeam' => $selectedTeam,
        ]));
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
        $dateTo   = $range['to'];

        $teamsConfig = Teams::config();
        $selectedTeam = $this->resolveSelectedTeam($request, $teamsConfig);

        // Same (now unfiltered — 2026-10-06, see index()'s own doc
        // comment) product list as index() above — this AJAX live-refresh
        // endpoint must never show a different product list than the
        // page's own initial render.
        $products = Product::orderBy('team')->orderBy('sort_order')->get();
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        // TIKTOK TEAM has no real product cards (see index()'s own
        // identical branch/doc comment, 2026-10-06) — same empty/zeroed
        // shape here so this AJAX fragment never disagrees with the
        // initial page render.
        $summaryData = $selectedTeam === 'tiktok'
            ? ['summaryCards' => collect(), 'summaryOverallTotal' => ExpectedIncomeCalculator::derive([]), 'teamSummaryRows' => collect()]
            : $this->buildSummary($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $selectedTeam === 'all' ? null : TsaShift::where('team', $teamsConfig[$selectedTeam]['order_team'])->pluck('id')->all());

        // TikTok's own TOTAL card (explicit request, 2026-10-05) — this
        // fragment is re-rendered after every autosave, including a save
        // on a TikTok card itself, so it needs the same fresh total
        // index() computes via buildTiktokRows(). Only 'tiktokOverallTotal'
        // is used by _summary-section.blade.php; 'tiktokTsaRows' is unused
        // here (this fragment never renders per-TSA cards) but cheap
        // enough to not bother stripping out.
        $tiktokData = $this->buildTiktokRows($dates, $dateFrom, $dateTo);

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
    private function buildSummary($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, ?array $onlyTsaIds = null): array
    {
        ['cards' => $summaryCards, 'overallTotal' => $summaryOverallTotal] =
            $this->buildSummaryRow($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $onlyTsaIds);

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
            $teamTsaIds = TsaShift::where('team', $teamConfig['order_team'])->pluck('id')->all();

            ['cards' => $cards, 'overallTotal' => $overallTotal] =
                $this->buildSummaryRow($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $teamTsaIds);

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
     *  for exactly what it restricts. */
    private function buildSummaryRow($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, ?array $onlyTsaIds): array
    {
        $rawByProductAndDate = $this->rawByProductAndDateAllTsas($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $onlyTsaIds);

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
        $productCardLookups = [
            TsaDailyRateService::perProductByTsaId(),
            collect(TsaDailyRateService::dailyCostPerProductRow())->except('total')->all(),
            TsaDailyRateService::perProductTaxAllocationByTsaId(),
        ];
        // perProductByTsaId() -> dailyRateByTsaId() (2026-10-06, same
        // reversal as buildTeamDailyRows()'s own overview-card fix, same
        // day — see that one's own doc comment) — the TELESALES/per-team
        // rollup card sums every active TSA's own REAL, undivided Daily
        // Rate now, matching Cost Breakdown's own figure, instead of each
        // TSA's ÷7 product-divided one.
        $rollupLookups = [
            TsaDailyRateService::dailyRateByTsaId(),
            collect(TsaDailyRateService::dailyCostRow())->except('total')->all(),
            TsaDailyRateService::taxAllocationByTsaId(),
        ];

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
        $cards = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $sellingKeys, $operatingKeys, $dates, $onlyTsaIds, $productCardLookups) {
            $pooledRaw = $groupProducts->flatMap(fn (Product $p) => $rawByProductAndDate->get($p->id)->flatMap(fn ($rowsForDate) => $rowsForDate))
                ->map(fn ($row) => isset($row['tsa_id']) && $row['tsa_id'] !== null ? array_merge($row, array_fill_keys($operatingKeys, 0.0)) : $row);
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
            return $groupProducts->first()->has_cost_allocation
                ? $this->addActiveTsasOverviewOperatingCosts($derived, $dates, $onlyTsaIds, ...$productCardLookups)
                : $derived;
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
            ->map(fn ($row) => isset($row['tsa_id']) && $row['tsa_id'] !== null ? array_merge($row, array_fill_keys($operatingKeys, 0.0)) : $row)
            ->all();
        $derived = ExpectedIncomeCalculator::sum($allRaw, $sellingKeys, $operatingKeys);
        $overallTotal = $this->addActiveTsasOverviewOperatingCosts($derived, $dates, $onlyTsaIds, ...$rollupLookups);

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
    private function addActiveTsasOverviewOperatingCosts(array $derived, $dates, ?array $onlyTsaIds, array $dailyRateByTsaId, array $dailyCostRow, array $taxAllocationByTsaId): array
    {
        $activeTsaIds = $onlyTsaIds !== null ? collect($onlyTsaIds) : TsaShift::pluck('id');

        if ($activeTsaIds->isEmpty()) {
            return $derived;
        }

        $dayCount = $dates->count();
        $addedOperatingCosts = array_fill_keys(array_merge(array_keys($dailyCostRow), ['salaries']), 0.0);
        $addedTaxAllocation = 0.0;
        // Fallback for any TSA missing from $taxAllocationByTsaId's own
        // pre-built map (root-caused live, 2026-10-03: "why is it all
        // last tsa has no tax?" — a newly-added TSA showed 0.00 here
        // despite Cost Breakdown showing her correct Daily Tax) — only
        // loaded if at least one id is actually missing, so the common
        // case (every TSA present) costs nothing extra.
        $missingTaxTsaIds = $activeTsaIds->reject(fn ($id) => array_key_exists($id, $taxAllocationByTsaId));
        $fallbackTsasById = $missingTaxTsaIds->isNotEmpty()
            ? TsaShift::whereIn('id', $missingTaxTsaIds)->get()->keyBy('id')
            : collect();
        foreach ($activeTsaIds as $tsaId) {
            $addedOperatingCosts['salaries'] += ($dailyRateByTsaId[$tsaId] ?? 0.0) * $dayCount;
            foreach ($dailyCostRow as $key => $amount) {
                $addedOperatingCosts[$key] += $amount * $dayCount;
            }
            $tax = $taxAllocationByTsaId[$tsaId]
                ?? ($fallbackTsasById->get($tsaId) ? TsaDailyRateService::taxAllocationForTsa($fallbackTsasById->get($tsaId)) : 0.0);
            $addedTaxAllocation += $tax * $dayCount;
        }

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
        $dailyRateByTsaId = TsaDailyRateService::dailyRateByTsaId();
        $dailyRatePerProductByTsaId = TsaDailyRateService::perProductByTsaId();

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
        $taxAllocationByTsaId = TsaDailyRateService::taxAllocationByTsaId();
        $taxAllocationPerProductByTsaId = TsaDailyRateService::perProductTaxAllocationByTsaId();

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
        $dailyCostPerProductRow = TsaDailyRateService::dailyCostPerProductRow();
        $dailyCostRow = TsaDailyRateService::dailyCostRow();

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

        $tsaRows = $tsas->map(function (TsaShift $tsa) use ($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $dailyRatePerProductByTsaId, $dailyRateByTsaId, $dailyCostPerProductRow, $dailyCostRow, $taxAllocationByTsaId, $taxAllocationPerProductByTsaId, $isRangeSummed) {
            ['raw' => $rawByProductAndDate, 'entriesByKey' => $entriesByKey] = $this->rawByProductAndDate($products, $tsa->id, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys);
            $productCardOverrides = array_merge($dailyCostPerProductRow, ['salaries' => $dailyRatePerProductByTsaId[$tsa->id] ?? 0.0]);
            $overviewCardOverrides = array_merge($dailyCostRow, ['salaries' => $dailyRateByTsaId[$tsa->id] ?? 0.0]);
            // Direct fallback when $tsa is missing from the pre-built maps
            // (root-caused live, 2026-10-03: "why is it all last tsa has
            // no tax?" — a newly-added TSA showed 0.00 here despite Cost
            // Breakdown showing her correct Daily Tax) — computes her own
            // figure fresh from her own real team, independent of
            // whatever caused her to be missing from the map in the first
            // place.
            $productCardTaxAllocation = $taxAllocationPerProductByTsaId[$tsa->id] ?? TsaDailyRateService::perProductTaxAllocationForTsa($tsa);
            $overviewCardTaxAllocation = $taxAllocationByTsaId[$tsa->id] ?? TsaDailyRateService::taxAllocationForTsa($tsa);

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
                $dayCount = $dates->count();
                $productCardOverridesForRange = array_map(fn ($v) => $v * $dayCount, $productCardOverrides);
                $overviewCardOverridesForRange = array_map(fn ($v) => $v * $dayCount, $overviewCardOverrides);
                $productCardTaxAllocationForRange = $productCardTaxAllocation * $dayCount;
                $overviewCardTaxAllocationForRange = $overviewCardTaxAllocation * $dayCount;

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
                $rangeOverallTotal = (function () use ($products, $dates, $rawByProductAndDate, $sellingKeys, $operatingKeys, $overviewCardOverridesForRange, $overviewCardTaxAllocationForRange) {
                    $rangeRaw = $products->flatMap(fn (Product $p) => $dates->map(fn ($date) => $rawByProductAndDate->get($p->id)->get($date->toDateString())))->all();
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
            $dailyOverallTotals = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys, $overviewCardOverrides, $overviewCardTaxAllocation) {
                $dateStr = $date->toDateString();
                $dayRaw = $products->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr))->all();
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
            'tsaRows'            => $tsaRows,
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
    private function buildTiktokRows($dates, string $dateFrom, string $dateTo): array
    {
        $flaggedTsas = TsaShift::where('tiktok_upsell', true)->orderBy('sort_order')->get();
        if ($flaggedTsas->isEmpty()) {
            return ['tiktokTsaRows' => collect(), 'tiktokOverallTotal' => ExpectedIncomeCalculator::derive([])];
        }

        $entriesByKey = ExpectedIncomeTiktokEntry::whereIn('tsa_id', $flaggedTsas->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (ExpectedIncomeTiktokEntry $e) => $e->tsa_id . ':' . $e->card_key . ':' . $e->entry_date->toDateString());

        $emptyRaw = fn (int $tsaId, string $cardKey, string $dateStr) => ['tsa_id' => $tsaId, 'card_key' => $cardKey, 'entry_date' => $dateStr];

        $isRangeSummed = $dates->count() > 1;
        $allRawRows = [];

        $tiktokTsaRows = $flaggedTsas->map(function (TsaShift $tsa) use ($dates, $entriesByKey, $emptyRaw, $isRangeSummed, &$allRawRows) {
            // Every (card, date) combo for this one TSA — entriesByKey
            // misses a (card, date) with nothing ever saved, so $emptyRaw
            // fills the gap rather than silently skipping that date out
            // of the sum (same "always one row per cell, real or empty"
            // convention as rawByProductAndDate()'s own doc comment).
            $rowsByCard = collect(ExpectedIncomeTiktokEntry::CARDS)->mapWithKeys(function ($label, $cardKey) use ($tsa, $dates, $entriesByKey, $emptyRaw, &$allRawRows) {
                $rows = $dates->map(function ($date) use ($tsa, $cardKey, $entriesByKey, $emptyRaw, &$allRawRows) {
                    $dateStr = $date->toDateString();
                    $entry = $entriesByKey->get("{$tsa->id}:{$cardKey}:{$dateStr}");
                    $raw = $entry?->toArray() ?? $emptyRaw($tsa->id, $cardKey, $dateStr);
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
            $overallTotal = ExpectedIncomeCalculator::sum($rowsByCard->flatMap(fn ($rows) => $rows->pluck('raw'))->all());

            if ($isRangeSummed) {
                $cards = $rowsByCard->map(fn ($rows, $cardKey) => [
                    'key' => $cardKey, 'label' => ExpectedIncomeTiktokEntry::CARDS[$cardKey],
                    'derived' => ExpectedIncomeCalculator::sum($rows->pluck('raw')->all()),
                ])->values();

                return ['tsa' => $tsa, 'isRangeSummed' => true, 'cards' => $cards, 'overallTotal' => $overallTotal];
            }

            $dateStr = $dates->first()->toDateString();
            $cards = $rowsByCard->map(function ($rows, $cardKey) {
                $only = $rows->first();
                return [
                    'key' => $cardKey, 'label' => ExpectedIncomeTiktokEntry::CARDS[$cardKey],
                    'derived' => ExpectedIncomeCalculator::derive($only['raw']),
                    'entry' => $only['entry'],
                ];
            })->values();

            return ['tsa' => $tsa, 'isRangeSummed' => false, 'dateStr' => $dateStr, 'cards' => $cards, 'overallTotal' => $overallTotal];
        });

        return [
            // Keyed by tsa_id (not a plain list) — the view looks this up
            // once per real TSA it's already iterating ($tsaRows in
            // buildTeamDailyRows()'s own output), so a flat lookup avoids
            // a linear search through every flagged TSA per render.
            'tiktokTsaRows'      => $tiktokTsaRows->keyBy(fn ($row) => $row['tsa']->id),
            'tiktokOverallTotal' => ExpectedIncomeCalculator::sum($allRawRows),
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
    private function rawByProductAndDate($products, ?int $tsaId, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys)
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

        $customValuesFor = function (int $productId, string $dateStr) use ($customValuesByKey) {
            $row = [];
            foreach (ExpectedIncomeCalculator::customRowKeys() as $key) {
                $row[$key] = (float) ($customValuesByKey->get("{$productId}:{$dateStr}:{$key}")?->value ?? 0);
            }
            return $row;
        };

        $raw = $products->mapWithKeys(function (Product $p) use ($dates, $entries, $customValuesFor) {
            return [$p->id => $dates->mapWithKeys(function ($date) use ($p, $entries, $customValuesFor) {
                $dateStr = $date->toDateString();
                $entry = $entries->get($p->id . ':' . $dateStr);
                $row = $entry ? $entry->toArray() : [];
                return [$dateStr => array_merge($row, $customValuesFor($p->id, $dateStr))];
            })];
        });

        return ['raw' => $raw, 'entriesByKey' => $entries];
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
     *  (the main "TELESALES" overall card) means every TSA, an array of
     *  ids (one team's own summary card, added 2026-09-30 per the sheet's
     *  own "TEAM OPENING SHIFT"/"TEAM CLOSING SHIFT" cards) means only
     *  those TSAs. The product-level (tsa_id NULL) row is ALWAYS included
     *  either way — explicit decision, 2026-09-30: a team card is "that
     *  team's own TSAs plus the shared product-level entries", the same
     *  shared figure folded into every team's own card, not divided
     *  between them. */
    private function rawByProductAndDateAllTsas($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, ?array $onlyTsaIds = null)
    {
        $entries = ExpectedIncomeEntry::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->when($onlyTsaIds !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('tsa_id')->orWhereIn('tsa_id', $onlyTsaIds)))
            ->get()
            ->groupBy(fn (ExpectedIncomeEntry $e) => $e->product_id . ':' . $e->entry_date->toDateString());

        $customValuesByEntryKey = ExpectedIncomeCustomValue::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->groupBy(fn (ExpectedIncomeCustomValue $v) => $v->product_id . ':' . $v->entry_date->toDateString() . ':' . ($v->tsa_id ?? 'null'));

        $customValuesFor = function (int $productId, string $dateStr, ?int $tsaId) use ($customValuesByEntryKey) {
            $values = $customValuesByEntryKey->get("{$productId}:{$dateStr}:" . ($tsaId ?? 'null'), collect())->keyBy('custom_row_key');
            $row = [];
            foreach (ExpectedIncomeCalculator::customRowKeys() as $key) {
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
        $salariesByTsaId = TsaDailyRateService::perProductByTsaId();
        $poolsOverride = TsaDailyRateService::dailyCostPerProductRow();
        $operatingOverridesFor = fn (int $tsaId) => array_merge($poolsOverride, ['salaries' => $salariesByTsaId[$tsaId] ?? 0.0]);

        return $products->mapWithKeys(function (Product $p) use ($dates, $entries, $customValuesFor, $operatingOverridesFor) {
            return [$p->id => $dates->mapWithKeys(function ($date) use ($p, $entries, $customValuesFor, $operatingOverridesFor) {
                $dateStr = $date->toDateString();
                $rowsForThisCell = $entries->get($p->id . ':' . $dateStr, collect());

                if ($rowsForThisCell->isEmpty()) {
                    return [$dateStr => collect([array_merge([], $customValuesFor($p->id, $dateStr, null))])];
                }

                $rows = $rowsForThisCell->map(function (ExpectedIncomeEntry $entry) use ($p, $dateStr, $customValuesFor, $operatingOverridesFor) {
                    $row = array_merge($entry->toArray(), $customValuesFor($p->id, $dateStr, $entry->tsa_id));
                    return $entry->tsa_id === null ? $row : array_merge($row, $operatingOverridesFor($entry->tsa_id));
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
            'number_of_leads'      => ['sometimes', 'integer', 'min:0'],
            'number_of_orders'     => ['sometimes', 'integer', 'min:0'],
            'average_order_value'  => ['sometimes', 'numeric', 'min:0'],
            'gross_sales'          => ['sometimes', 'numeric', 'min:0'],
            'cancelled'            => ['sometimes', 'numeric', 'min:0'],
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

        $entry->fill($data);
        $entry->save();

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
            'number_of_orders'     => ['sometimes', 'integer', 'min:0'],
            'average_order_value'  => ['sometimes', 'numeric', 'min:0'],
            'gross_sales'          => ['sometimes', 'numeric', 'min:0'],
            'cancelled'            => ['sometimes', 'numeric', 'min:0'],
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
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        $entry = ExpectedIncomeTiktokEntry::where('card_key', $cardKey)->where('tsa_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first()
            ?? new ExpectedIncomeTiktokEntry(['card_key' => $cardKey, 'tsa_id' => $tsaShift->id, 'entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'derived' => ExpectedIncomeCalculator::derive($entry->toArray()),
        ]);
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
        $groupProducts = $group ? $group->products()->orderBy('sort_order')->get() : collect([$product]);

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
        $customValue->value = $data['value'];
        $customValue->save();

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

        $memberEntries = ExpectedIncomeEntry::whereIn('product_id', $group->products->pluck('id'))
            ->where('tsa_id', $tsaId)
            ->whereDate('entry_date', $entryDate)
            ->get()
            ->keyBy('product_id');
        $pooled = $group->products->map(function (Product $p) use ($memberEntries, $tsaId, $entryDate) {
            $memberEntry = $memberEntries->get($p->id) ?? new ExpectedIncomeEntry(['product_id' => $p->id, 'tsa_id' => $tsaId, 'entry_date' => $entryDate]);
            return $this->withCustomRowValues($memberEntry, $tsaId, $entryDate);
        });

        $summed = ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
        // Same gate — a grouped card's own flag is its FIRST member's
        // (same "the group's first member owns the edit" convention
        // ProductGrouping::rows()/TsaDailyRateService::flaggedProductRows()
        // already use).
        return $group->products->first()->has_cost_allocation ? $this->withOperatingCostOverridesIfTsaScoped($summed, $tsaId) : $summed;
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
        $operatingCostOverrides = array_merge(TsaDailyRateService::dailyCostPerProductRow(), [
            'salaries' => $dailyRatePerProductByTsaId[$tsaId] ?? 0.0,
        ]);

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

        return $row;
    }
}
