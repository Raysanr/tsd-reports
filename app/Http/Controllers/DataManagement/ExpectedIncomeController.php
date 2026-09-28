<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ExpectedIncomeCustomValue;
use App\Models\ExpectedIncomeEntry;
use App\Models\Product;
use App\Support\ExpectedIncomeCalculator;
use App\Support\ProductGrouping;
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
 * Each row's overall figure is a single rollup card across EVERY product,
 * NOT split into separate per-team cards — explicit decision, 2026-09-26,
 * even though the real sheet also shows Team Eyecare/Team SH Naturals
 * cards ("i want exactly like in the sheets but i want to make it like no
 * per team", reconfirmed after being shown that screenshot).
 *
 * Uses the app's real Product table, not a fixed list matching the sheet's
 * own product names — explicit decision, 2026-09-26, same reasoning as
 * Summary Sales Report's own rework earlier that day (the sheet's product
 * list doesn't exactly match this app's real roster, and a page that
 * reflects the real roster needs no manual sync).
 */
class ExpectedIncomeController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from') ?: today()->startOfMonth()->toDateString();
        $dateTo   = $request->input('date_to') ?: today()->toDateString();

        $products = Product::orderBy('team')->orderBy('sort_order')->get();

        // Computed ONCE here (this controller has DB access) and passed
        // explicitly into every derive()/sum() call below — see
        // ExpectedIncomeCalculator::derive()'s own doc comment for why
        // those methods never query ProjectionCustomRow themselves
        // (they're pure functions, verified against real spreadsheet
        // numbers in a plain PHPUnit\Framework\TestCase with no DB at all).
        $sellingKeys = array_keys(ExpectedIncomeCalculator::sellingCostRows());
        $operatingKeys = array_keys(ExpectedIncomeCalculator::operatingCostRows());

        // whereDate() >=/<=, not a raw whereBetween() on the date-cast
        // column — root-caused 2026-09-26: entry_date is stored as a full
        // 'Y-m-d H:i:s' datetime string, and SQLite compares whereBetween's
        // plain date-only bounds LEXICOGRAPHICALLY, so '2026-09-26 00:00:00'
        // (the LAST day of a range) sorts AFTER the bound '2026-09-26' and
        // gets silently dropped — the exact same date-cast pitfall already
        // documented on this controller's own update()'s whereDate() call,
        // just hit here too since whereBetween() doesn't get that same
        // treatment. whereDate() correctly extracts just the date part on
        // every driver (SQLite included), so this can't drop the last day.
        $entries = ExpectedIncomeEntry::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->groupBy('product_id');

        // Every custom row's own typed-in value for this range, keyed
        // "productId:date:customRowKey" — merged onto each entry's own
        // array below before it ever reaches ExpectedIncomeCalculator, same
        // "controller assembles the full row, calculator just reads array
        // keys" convention the built-in fields already use. See
        // create_expected_income_custom_values_table's own doc comment for
        // why this lives in a separate table instead of a real column.
        $customValuesByKey = ExpectedIncomeCustomValue::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (ExpectedIncomeCustomValue $v) => $v->product_id . ':' . $v->entry_date->toDateString() . ':' . $v->custom_row_key);

        // Keyed off (productId, dateStr) directly, NOT off an existing
        // ExpectedIncomeEntry — root-caused live, 2026-09-28: typing ONLY
        // into a custom row's own field (never any built-in field) for a
        // given product+day creates an ExpectedIncomeCustomValue but no
        // ExpectedIncomeEntry at all for that cell, so a version of this
        // keyed off $entry (skipped entirely when null) silently discarded
        // an otherwise-real, already-saved custom value on every page
        // reload — it showed 0.00 immediately after typing it in, even
        // though the database genuinely had the value. A product/day with
        // NEITHER a real entry nor a custom value still returns a row of
        // all-zero built-ins plus its custom-row keys, same as before.
        $customValuesFor = function (int $productId, string $dateStr) use ($customValuesByKey) {
            $row = [];
            foreach (ExpectedIncomeCalculator::customRowKeys() as $key) {
                $row[$key] = (float) ($customValuesByKey->get("{$productId}:{$dateStr}:{$key}")?->value ?? 0);
            }
            return $row;
        };

        // Per-day entries for the currently selected products, keyed
        // "productId:date" — the view's own inline-editable cards need to
        // seed each field from whatever's already saved for that exact
        // product+day, same convention as DsPprReportController::index().
        $dailyByKey = $entries->flatten()->keyBy(fn (ExpectedIncomeEntry $e) => $e->product_id . ':' . $e->entry_date->toDateString());

        // daysUntil() is already INCLUSIVE of its own end date — see the
        // identical fix in DsPprReportController's own doc comment for the
        // full root cause (confirmed 2026-09-27: picking "To: Sep 30" was
        // silently rendering an extra Oct 1 card row).
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        // One raw row per (product, day) in the selected range, ALWAYS —
        // even a product/day with no ExpectedIncomeEntry AND no custom
        // value at all still gets an all-zero row. Root-caused live,
        // 2026-09-28: typing ONLY into a custom row's own field (never any
        // built-in field) for a given product+day creates an
        // ExpectedIncomeCustomValue but no ExpectedIncomeEntry — every
        // earlier version of this controller iterated $entries (or
        // $dailyByKey) directly and simply never visited that product/day
        // at all, so its own already-saved custom value was silently
        // dropped from every rollup that read it, and reappeared as 0.00
        // on the very next page load despite being genuinely saved.
        // Iterating $products × $dates directly, rather than $entries,
        // guarantees every cell in the selected range is represented
        // regardless of which table (or neither) actually has a row for it.
        $rawByProductAndDate = $products->mapWithKeys(function (Product $p) use ($dates, $dailyByKey, $customValuesFor) {
            return [$p->id => $dates->mapWithKeys(function ($date) use ($p, $dailyByKey, $customValuesFor) {
                $dateStr = $date->toDateString();
                $entry = $dailyByKey->get($p->id . ':' . $dateStr);
                $row = $entry ? $entry->toArray() : [];
                return [$dateStr => array_merge($row, $customValuesFor($p->id, $dateStr))];
            })];
        });

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

        // Per-day display rows (product OR group), same shape as
        // $summaryCards above — built once here rather than inside the
        // view's own @foreach so the view never has to know about
        // ProductGrouping at all, same "controller owns the row plan"
        // convention as DSPPR's own $rows.
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
        // Used to live in the Blade view itself (business logic that
        // belongs here, not in a template) as
        // `ExpectedIncomeCalculator::sum($dailyRows[$dateStr]->pluck(
        // 'derived')->all())`, which had this exact bug.
        $dailyOverallTotals = $dates->mapWithKeys(function ($date) use ($products, $rawByProductAndDate, $sellingKeys, $operatingKeys) {
            $dateStr = $date->toDateString();
            $dayRaw = $products->map(fn (Product $p) => $rawByProductAndDate->get($p->id)->get($dateStr))->all();
            return [$dateStr => ExpectedIncomeCalculator::sum($dayRaw, $sellingKeys, $operatingKeys)];
        });

        return view('data.expected-income', [
            'products'            => $products,
            'summaryCards'        => $summaryCards,
            'summaryOverallTotal' => $summaryOverallTotal,
            'dailyRows'           => $dailyRows,
            'dailyOverallTotals'  => $dailyOverallTotals,
            'dailyByKey'          => $dailyByKey,
            'customValuesByKey'   => $customValuesByKey,
            'dates'               => $dates,
            'dateFrom'            => $dateFrom,
            'dateTo'              => $dateTo,
        ]);
    }

    /** Auto-save (same debounced-PATCH-per-field convention as Projections'
     *  own updateColumn() / DSPPR's own update()) — one (product, date,
     *  field) at a time. Upserts via updateOrCreate() since a cell with
     *  nothing typed into it yet has no row to PATCH onto. */
    public function update(Request $request, Product $product, string $date)
    {
        $data = $request->validate([
            'roas'                       => ['sometimes', 'numeric', 'min:0'],
            'standard_cost_per_message'  => ['sometimes', 'numeric', 'min:0'],
            'actual_cost_per_lead' => ['sometimes', 'numeric', 'min:0'],
            'number_of_leads'      => ['sometimes', 'integer', 'min:0'],
            'number_of_orders'     => ['sometimes', 'integer', 'min:0'],
            'average_order_value'  => ['sometimes', 'numeric', 'min:0'],
            'tax_allocation'       => ['sometimes', 'numeric', 'min:0'],
            'product_cost'         => ['sometimes', 'numeric', 'min:0'],
            'advertising_cost'     => ['sometimes', 'numeric', 'min:0'],
            'ads_vat'              => ['sometimes', 'numeric', 'min:0'],
            'ai_expense'           => ['sometimes', 'numeric', 'min:0'],
            'ad_account_rental_fee' => ['sometimes', 'numeric', 'min:0'],
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

        // whereDate(), not a plain ['entry_date' => $entryDate] attribute
        // match on firstOrNew() — same SQLite date-cast pitfall as
        // DsPprReportController::update().
        $entry = ExpectedIncomeEntry::where('product_id', $product->id)->whereDate('entry_date', $entryDate)->first()
            ?? new ExpectedIncomeEntry(['product_id' => $product->id, 'entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'derived' => ExpectedIncomeCalculator::derive(
                $this->withCustomRowValues($entry, $entryDate),
                array_keys(ExpectedIncomeCalculator::sellingCostRows()),
                array_keys(ExpectedIncomeCalculator::operatingCostRows())
            ),
        ]);
    }

    /** Separate endpoint for one custom row's own value (explicit request,
     *  2026-09-28 — see routes/web.php's own comment on this route for why
     *  it's split from update() above: a custom row's key is dynamic, not
     *  one of update()'s fixed whitelisted field names). Same "validate the
     *  key against the real allowed set, upsert by (product, date, key)"
     *  convention as ProjectionController::updateRates(). */
    public function updateCustomRow(Request $request, Product $product, string $date)
    {
        $data = $request->validate([
            'key'   => ['required', 'string', 'in:' . implode(',', ExpectedIncomeCalculator::customRowKeys())],
            'value' => ['required', 'numeric', 'min:0'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        // whereDate() lookup, not a plain ['entry_date' => $entryDate]
        // match inside updateOrCreate()'s own conditions — same SQLite
        // date-cast pitfall as every other whereDate() comment in this
        // controller (root-caused live in this table too: entry_date is
        // stored as a full 'Y-m-d H:i:s' datetime, so a bare string-equals
        // match against a date-only bound never finds the existing row —
        // updateOrCreate() then tried to INSERT a second time and hit the
        // table's own unique constraint instead of updating).
        $customValue = ExpectedIncomeCustomValue::where('product_id', $product->id)
            ->where('custom_row_key', $data['key'])
            ->whereDate('entry_date', $entryDate)
            ->first()
            ?? new ExpectedIncomeCustomValue(['product_id' => $product->id, 'entry_date' => $entryDate, 'custom_row_key' => $data['key']]);
        $customValue->value = $data['value'];
        $customValue->save();

        // The built-in fields' own row still needs deriving alongside the
        // just-saved custom value — a custom row participates in Total
        // Selling/Operating Costs and Net Income exactly like a built-in
        // one, so the frontend needs the SAME full derived payload update()
        // above returns, not just the one changed field.
        $entry = ExpectedIncomeEntry::where('product_id', $product->id)->whereDate('entry_date', $entryDate)->first()
            ?? new ExpectedIncomeEntry(['product_id' => $product->id, 'entry_date' => $entryDate]);

        return response()->json([
            'success' => true,
            'derived' => ExpectedIncomeCalculator::derive(
                $this->withCustomRowValues($entry, $entryDate),
                array_keys(ExpectedIncomeCalculator::sellingCostRows()),
                array_keys(ExpectedIncomeCalculator::operatingCostRows())
            ),
        ]);
    }

    /** Merges every custom row's own currently-saved value onto $entry's
     *  raw array, keyed the same way ExpectedIncomeCalculator::derive()
     *  reads any other field — shared by update() and updateCustomRow()
     *  above so both return a derived payload that reflects EVERY custom
     *  row's value, not just whichever single field either endpoint itself
     *  just changed. */
    private function withCustomRowValues(ExpectedIncomeEntry $entry, string $entryDate): array
    {
        $row = $entry->toArray();
        $customValues = ExpectedIncomeCustomValue::where('product_id', $entry->product_id)
            ->whereDate('entry_date', $entryDate)
            ->get()
            ->keyBy('custom_row_key');

        foreach (ExpectedIncomeCalculator::customRowKeys() as $key) {
            $row[$key] = (float) ($customValues->get($key)?->value ?? 0);
        }

        return $row;
    }
}
