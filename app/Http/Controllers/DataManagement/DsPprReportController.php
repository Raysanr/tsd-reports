<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\DsPprEntry;
use App\Models\Product;
use App\Support\DsPprCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TSD Data Management — DSPPR - TSM Report (explicit request, 2026-09-24:
 * "create this page like DSPPR - TSM REPORT ... exactly like in the
 * sheets ... it is next to the projections page"). Replicates the source
 * sheet's own "Daily Sales per Product Report (SH - Telesales)" block:
 * one row per product per day, 6 typed-in numbers, everything else
 * derived — see DsPprCalculator's own doc comment for the confirmed
 * formulas.
 *
 * A single filterable date-range table, not the sheet's own repeating-
 * daily-block layout (explicit decision, 2026-09-24) — matches how every
 * other report page in this app (Leads Report, RTS Report) already
 * works, rather than an ever-growing page of stacked daily mini-tables.
 */
class DsPprReportController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from') ?: today()->startOfWeek()->toDateString();
        $dateTo   = $request->input('date_to') ?: today()->toDateString();
        $teamSlug = $request->input('team');

        $teams = collect(\App\Support\Teams::config());

        $products = Product::query()
            ->when($teamSlug && $teams->has($teamSlug), fn ($q) => $q->where('team', $teams[$teamSlug]['order_team'] ?? '__none__'))
            ->orderBy('sort_order')
            ->get();

        // whereDate() >=/<=, not a raw whereBetween() on the date-cast
        // column — root-caused 2026-09-26 (found while building Expected
        // Income's own identical query): entry_date is stored as a full
        // 'Y-m-d H:i:s' datetime string, and SQLite compares whereBetween's
        // plain date-only bounds LEXICOGRAPHICALLY, so '2026-09-26
        // 00:00:00' (the LAST day of a range) sorts AFTER the bound
        // '2026-09-26' and gets silently dropped from both the summary row
        // above and every TOTAL row below — this page had been quietly
        // undercounting by one day for any selected range this whole time.
        // whereDate() correctly extracts just the date part on every
        // driver (SQLite included), so this can't drop the last day.
        $entries = DsPprEntry::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->groupBy('product_id');

        // One row per product, summed across the whole selected range —
        // "Monthly Running Sales per Product" on the source sheet is the
        // same idea (a range total, not a single day), just always MTD
        // there where this page lets any range be picked.
        $rows = $products->map(function (Product $product) use ($entries) {
            $productEntries = $entries->get($product->id, collect());
            $summed = DsPprCalculator::sum($productEntries->map(fn (DsPprEntry $e) => $e->toArray())->all());

            return [
                'product' => $product,
                'derived' => $summed,
            ];
        });

        $overallTotal = DsPprCalculator::sum($rows->pluck('derived')->all());

        // Per-day entries for the currently selected products, keyed
        // "productId:date" — the view's own inline-editable inputs need
        // to seed each cell from whatever's already saved for that exact
        // product+day, one calendar day at a time (explicit request,
        // 2026-09-24: replicate the sheet's own per-day granularity),
        // even though the table above only ever shows range TOTALS.
        $dailyByKey = DsPprEntry::whereIn('product_id', $products->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (DsPprEntry $e) => $e->product_id . ':' . $e->entry_date->toDateString());

        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo)->addDay())));

        // Chunked into groups of 7 (explicit request, 2026-09-24: "make it
        // too only 7 days that is like can be drag to right and after
        // that the next is in the down part") — each chunk becomes its
        // own horizontally-scrollable table stacked below the previous
        // one, rather than one arbitrarily-wide table spanning the whole
        // selected range.
        $dateChunks = $dates->chunk(7)->values();

        return view('data.dsppr', [
            'rows'         => $rows,
            'overallTotal' => $overallTotal,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
            'teams'        => $teams,
            'selectedTeam' => $teamSlug,
            'dateChunks'   => $dateChunks,
            'dailyByKey'   => $dailyByKey,
        ]);
    }

    /** Auto-save (same debounced-PATCH-per-field convention as
     *  Projections' own updateColumn()) — one (product, date, field) at a
     *  time. Upserts via updateOrCreate() since a cell with nothing typed
     *  into it yet has no row to PATCH onto. */
    public function update(Request $request, Product $product, string $date)
    {
        $data = $request->validate([
            'gross_sales'   => ['sometimes', 'numeric'],
            'net_income'    => ['sometimes', 'numeric'],
            'ads_spent'     => ['sometimes', 'numeric', 'min:0'],
            'total_orders'  => ['sometimes', 'integer', 'min:0'],
            'total_leads'   => ['sometimes', 'integer', 'min:0'],
            'catered_leads' => ['sometimes', 'integer', 'min:0'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        // whereDate(), not a plain ['entry_date' => $entryDate] attribute
        // match on firstOrNew() — SQLite (this app's local driver, see
        // CLAUDE.md) stores a date-cast column as 'Y-m-d H:i:s' on write
        // but compares it as a plain string on read, so a bare date-only
        // value here silently never matches an already-saved row and
        // firstOrNew() would try to INSERT a second row for the same
        // (product, date), hitting the table's own unique constraint.
        // whereDate() correctly extracts just the date part on every
        // driver (SQLite included), so this finds the real existing row.
        $entry = DsPprEntry::where('product_id', $product->id)->whereDate('entry_date', $entryDate)->first()
            ?? new DsPprEntry(['product_id' => $product->id, 'entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'derived' => DsPprCalculator::derive($entry->toArray()),
        ]);
    }
}
