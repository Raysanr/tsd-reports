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
 * "analyze this expected income and add it to the data management module").
 * Replicates the source sheet's own "EXPECTED INCOME 2026" tab: one
 * month-to-date P&L summary row per product, plus a derived TEAM row per
 * team (sum of that team's own products — verified exact against the real
 * sheet, e.g. Team Eyecare = Clearsight + Pterygium + every other Eyecare
 * product, same convention as ProjectionColumn's Telesales Department).
 *
 * Uses the app's real Product table grouped by its real `team` column, not
 * a fixed list matching the sheet's own product names — explicit decision,
 * 2026-09-26, same reasoning as Summary Sales Report's own rework earlier
 * that day (the sheet's product list doesn't exactly match this app's real
 * roster, and a page that reflects the real roster needs no manual sync).
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

        $productRows = $products->map(function (Product $product) use ($entriesByProduct) {
            $entry = $entriesByProduct->get($product->id);

            return [
                'product' => $product,
                'entry'   => $entry,
                'derived' => ExpectedIncomeCalculator::derive($entry?->toArray() ?? []),
            ];
        });

        // One team block per real team, each product's row plus a derived
        // TEAM total row summing that team's own products — mirrors the
        // sheet's own "TEAM EYECARE" / "TEAM SH NATURALS" rollups.
        $teamBlocks = $productRows->groupBy(fn ($row) => $row['product']->team)
            ->map(function ($rows, $team) {
                return [
                    'team'  => $team,
                    'rows'  => $rows->values(),
                    'total' => ExpectedIncomeCalculator::sum($rows->pluck('derived')->all()),
                ];
            })
            ->values();

        return view('data.expected-income', [
            'teamBlocks' => $teamBlocks,
            'month'      => $month,
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

        // Every OTHER product in this product's own team, so the frontend
        // can refresh that team's TEAM total row without a full page
        // reload — same "return every affected figure, not just this
        // row's own" convention as Projections' updateColumn().
        $teamProducts = Product::where('team', $product->team)->get();
        $teamEntries = ExpectedIncomeEntry::whereIn('product_id', $teamProducts->pluck('id'))
            ->whereDate('month', $monthDate)
            ->get()
            ->keyBy('product_id');

        $teamDerived = $teamProducts->map(
            fn (Product $p) => ExpectedIncomeCalculator::derive($teamEntries->get($p->id)?->toArray() ?? [])
        );

        return response()->json([
            'success'      => true,
            'derived'      => ExpectedIncomeCalculator::derive($entry->toArray()),
            'team_total'   => ExpectedIncomeCalculator::sum($teamDerived->all()),
        ]);
    }
}
