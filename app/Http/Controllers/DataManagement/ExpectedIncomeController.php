<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
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

        // One row (or product GROUP row — explicit request, 2026-09-26:
        // "it will reflect it to the expected income") per product, summed
        // across the whole selected range — the sheet's own "TELESALES
        // EXPECTED PERFORMANCE" is the same idea (a range total), just
        // always MTD there where this page lets any range be picked, same
        // convention as DsPprReportController::index(). A grouped row
        // pools every member product's own entries together before
        // summing, same as DSPPR's own identical grouping call.
        $summaryCards = ProductGrouping::rows($products, function ($groupProducts) use ($entries) {
            $pooledEntries = $groupProducts->flatMap(fn (Product $p) => $entries->get($p->id, collect()));
            return ExpectedIncomeCalculator::sum($pooledEntries->map(fn (ExpectedIncomeEntry $e) => $e->toArray())->all());
        });
        $summaryOverallTotal = ExpectedIncomeCalculator::sum($summaryCards->pluck('derived')->all());

        // Per-day entries for the currently selected products, keyed
        // "productId:date" — the view's own inline-editable cards need to
        // seed each field from whatever's already saved for that exact
        // product+day, same convention as DsPprReportController::index().
        $dailyByKey = $entries->flatten()->keyBy(fn (ExpectedIncomeEntry $e) => $e->product_id . ':' . $e->entry_date->toDateString());

        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo)->addDay())));

        // Per-day display rows (product OR group), same shape as
        // $summaryCards above — built once here rather than inside the
        // view's own @foreach so the view never has to know about
        // ProductGrouping at all, same "controller owns the row plan"
        // convention as DSPPR's own $rows.
        $dailyRows = $dates->mapWithKeys(function ($date) use ($products, $dailyByKey) {
            $dateStr = $date->toDateString();
            $rows = ProductGrouping::rows($products, function ($groupProducts) use ($dailyByKey, $dateStr) {
                $pooled = $groupProducts->map(fn (Product $p) => $dailyByKey->get($p->id . ':' . $dateStr)?->toArray() ?? []);
                return ExpectedIncomeCalculator::sum($pooled->all());
            });
            return [$dateStr => $rows];
        });

        return view('data.expected-income', [
            'products'            => $products,
            'summaryCards'        => $summaryCards,
            'summaryOverallTotal' => $summaryOverallTotal,
            'dailyRows'           => $dailyRows,
            'dailyByKey'          => $dailyByKey,
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
            'roas'                 => ['sometimes', 'numeric', 'min:0'],
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
            'derived' => ExpectedIncomeCalculator::derive($entry->toArray()),
        ]);
    }
}
