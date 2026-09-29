<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ExpectedIncomeCustomValue;
use App\Models\ExpectedIncomeEntry;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TsaShift;
use App\Support\ExpectedIncomeCalculator;
use App\Support\ProductGrouping;
use App\Support\Teams;
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
 *       - selectedTeam = 'all' (default): each day's own "TELESALES —
 *         [date]" overall card + every product's own card, tsa_id NULL —
 *         unchanged in substance from before this feature. See
 *         buildAllDailyRows().
 *       - selectedTeam = a real team slug: the "TELESALES — [date]" card
 *         is replaced by ONE block PER REAL TSA on that team ("in the down
 *         the yellow is only TSA NAMES") — her own name where the date
 *         card's title used to be, followed by her own product cards, for
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
        $dateFrom = $request->input('date_from') ?: today()->startOfMonth()->toDateString();
        $dateTo   = $request->input('date_to') ?: today()->toDateString();

        $selectedTeam = $request->input('team', 'all');
        $teamsConfig = Teams::config();
        if ($selectedTeam !== 'all' && !isset($teamsConfig[$selectedTeam])) {
            $selectedTeam = 'all';
        }
        $teams = ['all' => 'ALL'] + array_map(fn ($t) => $t['name'], $teamsConfig);

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
        // every product's own card) ALWAYS shows the same product-level
        // totals regardless of which team is selected (confirmed live,
        // 2026-09-30, from a screenshot: "the top is still like that" — the
        // team filter only changes the DAILY rows below it). Built once
        // here, unconditionally, so switching teams never touches it.
        $summaryData = $this->buildSummary($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys);

        // The DAILY rows below the summary DO change with the team filter
        // (confirmed by the same screenshot: "in the down the yellow is
        // only TSA NAMES" when a team is picked) — one row per calendar
        // day either way, but each day's own card set is either every
        // product (ALL) or one block per real TSA on that team, her own
        // name where "TELESALES — [date]" used to be, each followed by her
        // own product cards.
        $dailyData = $selectedTeam === 'all'
            ? $this->buildAllDailyRows($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $teamsConfig)
            : $this->buildTeamDailyRows($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys, $teamsConfig[$selectedTeam]);

        return view('data.expected-income', array_merge($summaryData, $dailyData, [
            'products' => $products,
            'dates'    => $dates,
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
            'teams'    => $teams,
            'selectedTeam' => $selectedTeam,
        ]));
    }

    /** The top range-summary row's own data — one overall rollup card
     *  ("TELESALES EXPECTED PERFORMANCE") plus every product's own card,
     *  summed across the WHOLE selected range. Always product-level
     *  (tsa_id NULL), regardless of the team filter — confirmed live,
     *  2026-09-30, from a screenshot showing this row unchanged after
     *  picking a team ("the top is still like that"). Read-only (this row
     *  has never been an editable-inputs card, even before per-TSA rows
     *  existed). */
    private function buildSummary($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys): array
    {
        ['raw' => $rawByProductAndDate] = $this->rawByProductAndDate($products, null, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys);

        // One row (or product GROUP row — explicit request, 2026-09-26:
        // "it will reflect it to the expected income") per product, summed
        // across the whole selected range — the sheet's own "TELESALES
        // EXPECTED PERFORMANCE" is the same idea (a range total), just
        // always MTD there where this page lets any range be picked, same
        // convention as DsPprReportController::index(). A grouped row
        // pools every member product's own entries together before
        // summing, same as DSPPR's own identical grouping call.
        $summaryCards = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $sellingKeys, $operatingKeys) {
            $pooledRaw = $groupProducts->flatMap(fn (Product $p) => $rawByProductAndDate->get($p->id)->values());
            return ExpectedIncomeCalculator::sum($pooledRaw->all(), $sellingKeys, $operatingKeys);
        });
        // Every product/day's own RAW row (not $summaryCards's already-
        // derived output) — root-caused live, 2026-09-28: sum()'s own
        // $row[$key] lookups only ever find a Selling/Operating line as a
        // TOP-LEVEL array key, but a derive()d row nests every one of those
        // under selling_lines/operating_lines instead, so re-summing
        // already-derived rows silently read 0 for every manually-entered
        // line (confirmed live: a 500 Advertising Cost vanished from this
        // overall total while still showing correctly on every individual
        // product card). Every OTHER real total on this page (Gross Sales,
        // Cancelled, etc.) escaped this bug only because derive() also
        // returns those as top-level keys, purely by coincidence of which
        // keys happen to be duplicated at both levels.
        $allRaw = $rawByProductAndDate->flatMap(fn ($byDate) => $byDate->values())->all();
        $summaryOverallTotal = ExpectedIncomeCalculator::sum($allRaw, $sellingKeys, $operatingKeys);

        return [
            'summaryCards'        => $summaryCards,
            'summaryOverallTotal' => $summaryOverallTotal,
        ];
    }

    /** The ALL view's own daily rows — one row of cards PER calendar day,
     *  every product's own card, tsa_id NULL — unchanged in substance from
     *  before this feature. */
    private function buildAllDailyRows($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys): array
    {
        ['raw' => $rawByProductAndDate, 'entriesByKey' => $entriesByKey] = $this->rawByProductAndDate($products, null, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys);

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

        // Each day's own "TELESALES — [date]" rollup card, summed from
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
        ];
    }

    /** A specific team's own daily rows — confirmed live, 2026-09-30, from
     *  a screenshot: "in the down the yellow is only TSA NAMES" — the
     *  SAME per-day stacking as buildAllDailyRows() above, just with the
     *  overall "TELESALES — [date]" card replaced by ONE block PER REAL
     *  TSA on that team (her own name, her own product cards), repeated
     *  for every date. Every card here reads/writes ExpectedIncomeEntry
     *  rows with a real tsa_id — completely independent numbers from the
     *  "ALL" view's own tsa_id-NULL rows. */
    private function buildTeamDailyRows($products, $dates, string $dateFrom, string $dateTo, array $sellingKeys, array $operatingKeys, array $teamConfig): array
    {
        $tsas = TsaShift::where('team', $teamConfig['order_team'])->orderBy('sort_order')->get();

        $tsaRows = $tsas->map(function (TsaShift $tsa) use ($products, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys) {
            ['raw' => $rawByProductAndDate, 'entriesByKey' => $entriesByKey] = $this->rawByProductAndDate($products, $tsa->id, $dates, $dateFrom, $dateTo, $sellingKeys, $operatingKeys);

            $dailyRows = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys) {
                $dateStr = $date->toDateString();
                $rows = ProductGrouping::rows($products, function ($groupProducts) use ($rawByProductAndDate, $dateStr, $sellingKeys, $operatingKeys) {
                    $pooled = $groupProducts->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr));
                    return ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
                });
                return [$dateStr => $rows];
            });

            // Her own "[TSA NAME]" overview card — a read-only rollup of
            // HER OWN product cards for that one day, same role
            // "TELESALES — [date]" plays in the ALL view (confirmed live,
            // 2026-09-30, from a screenshot: "the yellow is stil has this,
            // it is over all of the individual tsa ... but it is not
            // editable") — summed from her own RAW rows for that day
            // (ungrouped, all her own products flattened), NOT from
            // $dailyRows' own already-derived output, same root cause/fix
            // as buildAllDailyRows()'s own $dailyOverallTotals.
            $dailyOverallTotals = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys) {
                $dateStr = $date->toDateString();
                $dayRaw = $products->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr))->all();
                return [$dateStr => ExpectedIncomeCalculator::sum($dayRaw, $sellingKeys, $operatingKeys)];
            });

            return [
                'tsa' => $tsa,
                'dailyRows' => $dailyRows,
                'dailyOverallTotals' => $dailyOverallTotals,
                'dailyByKey' => $entriesByKey,
            ];
        });

        return [
            'dailyRows'          => collect(),
            'dailyOverallTotals' => collect(),
            'dailyByKey'         => collect(),
            'tsaRows'            => $tsaRows,
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
            'returns'              => ['sometimes', 'numeric', 'min:0'],
            'delivered'            => ['sometimes', 'numeric', 'min:0'],
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
        $tsaId = $tsaShift?->id;

        // whereDate(), not a plain ['entry_date' => $entryDate] attribute
        // match on firstOrNew() — same SQLite date-cast pitfall as
        // DsPprReportController::update().
        $entry = ExpectedIncomeEntry::where('product_id', $product->id)->where('tsa_id', $tsaId)->whereDate('entry_date', $entryDate)->first()
            ?? new ExpectedIncomeEntry(['product_id' => $product->id, 'tsa_id' => $tsaId, 'entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'derived' => $this->derivedForProductOrGroup($product, $tsaId, $entryDate),
        ]);
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

            return ExpectedIncomeCalculator::derive($this->withCustomRowValues($entry, $tsaId, $entryDate), $sellingKeys, $operatingKeys);
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

        return ExpectedIncomeCalculator::sum($pooled->all(), $sellingKeys, $operatingKeys);
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
