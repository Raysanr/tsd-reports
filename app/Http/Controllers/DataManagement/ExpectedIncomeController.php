<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\ExpectedIncomeEntry;
use App\Models\Product;
use App\Support\ExpectedIncomeCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TSD Data Management — Expected Income 2026 (explicit request, 2026-09-26:
 * "analyze this expected income and add it to the data management module
 * ... i want exactly like this like in the sheets like every product is
 * has card"). Replicates the source sheet's own "EXPECTED INCOME 2026"
 * tab's real layout: one card per product, side by side (same visual
 * pattern as Projections' own _column.blade.php cards), preceded by one
 * overall "TELESALES" rollup card summing EVERY product — NOT split into
 * separate per-team blocks (explicit decision, 2026-09-26: "i want exactly
 * like in the sheets but i want to make it like no per team").
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
        $month = $request->input('month')
            ? Carbon::parse($request->input('month') . '-01')->startOfMonth()
            : today()->startOfMonth();

        $products = Product::orderBy('team')->orderBy('sort_order')->get();

        $entriesByProduct = ExpectedIncomeEntry::whereIn('product_id', $products->pluck('id'))
            ->whereDate('month', $month->toDateString())
            ->get()
            ->keyBy('product_id');

        $productCards = $products->map(function (Product $product) use ($entriesByProduct) {
            $entry = $entriesByProduct->get($product->id);

            return [
                'product' => $product,
                'entry'   => $entry,
                'derived' => ExpectedIncomeCalculator::derive($entry?->toArray() ?? []),
            ];
        });

        $overallTotal = ExpectedIncomeCalculator::sum($productCards->pluck('derived')->all());

        return view('data.expected-income', [
            'productCards' => $productCards,
            'overallTotal' => $overallTotal,
            'month'        => $month,
        ]);
    }

    /** Auto-save (same debounced-PATCH-per-field convention as Projections'
     *  own updateColumn() / DSPPR's own update()) — one (product, month,
     *  field) at a time. Upserts via updateOrCreate() since a cell with
     *  nothing typed into it yet has no row to PATCH onto. */
    public function update(Request $request, Product $product, string $month)
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

        $monthDate = Carbon::parse($month . '-01')->startOfMonth()->toDateString();

        // whereDate(), not a plain ['month' => $monthDate] attribute match
        // on firstOrNew() — same SQLite date-cast pitfall as DsPprReportController::update().
        $entry = ExpectedIncomeEntry::where('product_id', $product->id)->whereDate('month', $monthDate)->first()
            ?? new ExpectedIncomeEntry(['product_id' => $product->id, 'month' => $monthDate]);
        $entry->fill($data);
        $entry->save();

        // Every OTHER product (not just this one's team — the rollup card
        // is a single "TELESALES" total across ALL products, explicit
        // decision 2026-09-26), so the frontend can refresh that one
        // overall card without a full page reload — same "return every
        // affected figure, not just this row's own" convention as
        // Projections' updateColumn().
        $allProducts = Product::all();
        $allEntries = ExpectedIncomeEntry::whereIn('product_id', $allProducts->pluck('id'))
            ->whereDate('month', $monthDate)
            ->get()
            ->keyBy('product_id');

        $allDerived = $allProducts->map(
            fn (Product $p) => ExpectedIncomeCalculator::derive($allEntries->get($p->id)?->toArray() ?? [])
        );

        return response()->json([
            'success'       => true,
            'derived'       => ExpectedIncomeCalculator::derive($entry->toArray()),
            'overall_total' => ExpectedIncomeCalculator::sum($allDerived->all()),
        ]);
    }
}
