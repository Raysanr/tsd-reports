<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\DsPprEntry;
use App\Models\DsPprTiktokEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\DateRangeFilter;
use App\Support\DsPprCalculator;
use App\Support\ProductGrouping;
use App\Support\ProductPerformance;
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
        // See DateRangeFilter's own doc comment — remembers the last range
        // picked on THIS page across separate visits, defaulting to "today
        // only" on a brand-new session (changed 2026-09-30 from "this
        // week" — explicit decision to make every report page consistent).
        $range = DateRangeFilter::resolve($request, 'dsppr');
        $dateFrom = $range['from'];
        $dateTo   = $range['to'];
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

        // daysUntil() is already INCLUSIVE of its own end date (confirmed
        // directly, 2026-09-27: 21→25 yields 5 days including the 25th) —
        // the ->addDay() here was based on the opposite (wrong) assumption
        // that it excludes the end date, so picking "To: Sep 30" was
        // silently rendering an extra Oct 1 column that was never part of
        // the selected range at all.
        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo))));

        // Every real Order for the selected range/team, fetched ONE
        // CALENDAR DAY AT A TIME (not the whole range in one query) and
        // grouped by date — same memory-safety pattern
        // LeadsReportController::indexAll() already uses for an identical
        // reason (a wide date range × every team's own orders is too
        // large to hold in memory at once). Total Orders/Total Leads/
        // Catered Leads/Excess Leads/Pick-up/Conversion/Upselling Rate are
        // no longer manual inputs — computed from this real data instead
        // (explicit request, 2026-10-01: "i want to make it automated
        // based on the leads report page in TSD LEADS REPORT").
        $ordersByDate = $dates->mapWithKeys(function ($date) use ($teamSlug, $teams) {
            $dayOrders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
                ->when($teamSlug && $teams->has($teamSlug), fn ($q) => $q->where('team', $teams[$teamSlug]['order_team'] ?? '__none__'))
                ->get();
            return [$date->toDateString() => $dayOrders];
        });

        // One row per product (or per product GROUP — explicit request,
        // 2026-09-26: "drag the TO-01 to TO-02 ... it is only combine"),
        // summed across the whole selected range — "Monthly Running Sales
        // per Product" on the source sheet is the same idea (a range
        // total, not a single day), just always MTD there where this page
        // lets any range be picked. See ProductGrouping::rows()'s own doc
        // comment for why a grouped row's $sumFn gets every member
        // product's own entries pooled together, not summed twice.
        //
        // Iterates $dates (not $entries) so every day in the range
        // contributes a real-data row even on a day nobody ever typed
        // Gross Sales/Net Income for — Total Orders/Leads no longer
        // depend on a DsPprEntry existing at all. A grouped row's own
        // real-data figures are recomputed FRESH per day from every
        // member at once (not summed per-member from
        // $realByProductIdAndDate above) — same "a cross-team combo order
        // only counts once" reasoning ProductPerformance::
        // countedOrdersFor()'s own doc comment gives; summing two
        // members' already-deduped counts could double-count one real
        // order matched to both.
        $rows = ProductGrouping::rows($products, function ($groupProducts) use ($entries, $dates, $ordersByDate) {
            $entriesByDate = $groupProducts->flatMap(fn (Product $p) => $entries->get($p->id, collect()))
                ->keyBy(fn (DsPprEntry $e) => $e->entry_date->toDateString());

            $merged = $dates->map(function ($date) use ($entriesByDate, $groupProducts, $ordersByDate) {
                $dateStr = $date->toDateString();
                $base = $entriesByDate->get($dateStr)?->toArray() ?? [];
                $real = ProductPerformance::dsPprRow($groupProducts, $ordersByDate[$dateStr]);
                return array_merge($base, $real);
            })->all();

            return DsPprCalculator::sum($merged);
        });

        // TikTok Orders — a manually-run row (explicit request, 2026-10-05),
        // NOT a real Product: no Pancake matching backs it, so every field
        // is manual, including Total Orders/Total Leads/Catered Leads
        // (automated for every real product above via
        // ProductPerformance::dsPprRow()). Keyed by date alone (see
        // create_dsppr_tiktok_entries_table migration's own doc comment),
        // reusing DsPprCalculator as-is since the 6-input/11-derived shape
        // is identical.
        $tiktokEntriesByDate = DsPprTiktokEntry::whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (DsPprTiktokEntry $e) => $e->entry_date->toDateString());

        $emptyTiktokRow = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0];
        $tiktokDailyByKey = collect();
        foreach ($dates as $date) {
            $dateStr = $date->toDateString();
            $tiktokDailyByKey->put($dateStr, $tiktokEntriesByDate->has($dateStr)
                ? $tiktokEntriesByDate->get($dateStr)->toArray()
                : $emptyTiktokRow);
        }

        $tiktokRow = ['derived' => DsPprCalculator::sum($tiktokDailyByKey->values()->all())];

        $overallTotal = DsPprCalculator::sum(
            $rows->pluck('derived')->push($tiktokRow['derived'])->all()
        );

        // Same real-data computation as $realByProductIdAndDate above, but
        // keyed by DISPLAY ROW (matches dsppr.blade.php's own $rowKey:
        // 'g{groupId}' or 'p{productId}') × date — a merged/grouped row's
        // own per-day Total Orders/Leads/Catered/Excess/rates must be the
        // GROUP's own single dedup'd figure (every member pooled at once),
        // never summed from each member's own already-computed row in
        // $realByProductIdAndDate, same "a cross-team combo order only
        // counts once" reasoning given above. The view has no DB access of
        // its own (same "controller computes, view renders" convention as
        // every other page in this app), so this has to be precomputed
        // here rather than re-derived per row inside the Blade loop.
        $realByRowKeyAndDate = [];
        foreach ($rows as $row) {
            $rowKey = $row['group'] ? 'g' . $row['group']->id : 'p' . $row['products']->first()->id;
            foreach ($dates as $date) {
                $realByRowKeyAndDate[$rowKey . ':' . $date->toDateString()] = ProductPerformance::dsPprRow($row['products'], $ordersByDate[$date->toDateString()]);
            }
        }

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
            'realByRowKeyAndDate' => $realByRowKeyAndDate,
            'ordersByDate' => $ordersByDate,
            'tiktokRow'       => $tiktokRow,
            'tiktokDailyByKey' => $tiktokDailyByKey,
        ]);
    }

    /** Auto-save (same debounced-PATCH-per-field convention as
     *  Projections' own updateColumn()) — one (product, date, field) at a
     *  time. Upserts via updateOrCreate() since a cell with nothing typed
     *  into it yet has no row to PATCH onto. */
    public function update(Request $request, Product $product, string $date)
    {
        // total_orders/total_leads/catered_leads deliberately NOT accepted
        // here any more — no longer manual inputs (explicit request,
        // 2026-10-01: "i want to make it automated based on the leads
        // report page in TSD LEADS REPORT"), computed fresh below from
        // real Order data instead. A stray POST carrying one of these
        // can't write a stale value the view no longer reflects.
        $data = $request->validate([
            'gross_sales'   => ['sometimes', 'numeric'],
            'net_income'    => ['sometimes', 'numeric'],
            'ads_spent'     => ['sometimes', 'numeric', 'min:0'],
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

        // A merged/grouped product is now editable AS IF it's one product
        // (explicit request, 2026-09-29: "in the side of users the merged
        // products is only 1 product only") — $product here is always the
        // group's own FIRST member (see dsppr.blade.php's own
        // data-product-id, always $row['products']->first()->id), so a
        // typed edit lands on that one product's own DsPprEntry same as
        // any ungrouped product. But this row's own READ-ONLY cells (NI %,
        // AOV, Total Orders/Leads/Catered/Excess/rates) must still reflect
        // the FULL group total, not just this one member — the frontend
        // has no way to know the other member(s)' own stored numbers (they
        // never rendered in the DOM at all once grouped), so the server
        // has to resolve and return the true combined figures here
        // instead.
        $group = ProductGroup::whereHas('products', fn ($q) => $q->where('products.id', $product->id))->first();
        $groupProducts = $group ? $group->products : collect([$product]);

        $dayOrders = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [
            Carbon::parse($entryDate)->startOfDay(), Carbon::parse($entryDate)->endOfDay(),
        ])->get();
        $real = ProductPerformance::dsPprRow($groupProducts, $dayOrders);

        if ($group) {
            $memberEntries = DsPprEntry::whereIn('product_id', $group->products->pluck('id'))
                ->whereDate('entry_date', $entryDate)
                ->get()
                ->keyBy('product_id');
            $pooled = $group->products->map(
                fn (Product $p) => array_merge($memberEntries->get($p->id)?->toArray() ?? ['product_id' => $p->id, 'entry_date' => $entryDate], $real)
            );
            $derived = DsPprCalculator::sum($pooled->all());
        } else {
            $derived = DsPprCalculator::derive(array_merge($entry->toArray(), $real));
        }

        return response()->json([
            'success' => true,
            'derived' => $derived,
        ]);
    }

    /** Same auto-save shape as update() above, for the TikTok Orders row
     *  — every field is manual here (see create_dsppr_tiktok_entries_table
     *  migration's own doc comment), so unlike update() this accepts
     *  Total Orders/Total Leads/Catered Leads directly instead of deriving
     *  them from real Order data. No product involved — keyed by date
     *  alone. */
    public function updateTiktok(Request $request, string $date)
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

        $entry = DsPprTiktokEntry::whereDate('entry_date', $entryDate)->first()
            ?? new DsPprTiktokEntry(['entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        $derived = DsPprCalculator::derive($entry->toArray());

        return response()->json([
            'success' => true,
            'derived' => $derived,
        ]);
    }

    /** Combines 2+ products into one display row (explicit request,
     *  2026-09-26: "drag the TO-01 to TO-02 ... pop up like new name") —
     *  DSPPR's own drag gesture is the only place a group is CREATED; both
     *  this page and Expected Income just reflect whatever groups exist
     *  once created here. A product already in another group can't join a
     *  second one (product_group_members' own DB-level unique constraint
     *  on product_id enforces this too — validated here first for a clean
     *  error message instead of a raw constraint-violation 500). */
    public function storeGroup(Request $request)
    {
        $data = $request->validate([
            'label'        => ['required', 'string', 'max:255'],
            'product_ids'  => ['required', 'array', 'min:2'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
        ]);

        $alreadyGrouped = \Illuminate\Support\Facades\DB::table('product_group_members')
            ->whereIn('product_id', $data['product_ids'])
            ->exists();
        if ($alreadyGrouped) {
            return response()->json(['success' => false, 'message' => 'One of these products is already in a group.'], 422);
        }

        $group = ProductGroup::create([
            'label' => $data['label'],
            'sort_order' => ProductGroup::max('sort_order') + 1,
        ]);
        $group->products()->attach($data['product_ids']);

        return response()->json(['success' => true, 'group' => $group->load('products')]);
    }

    /** Splits a combined row back into its own separate products
     *  (explicit request, 2026-09-26: a small ungroup control on the
     *  combined row) — deletes the group and its membership rows only;
     *  every real DsPprEntry/ExpectedIncomeEntry the member products ever
     *  had stays exactly as it was, since grouping never touched them. */
    public function destroyGroup(ProductGroup $productGroup)
    {
        $productGroup->delete();

        return response()->json(['success' => true]);
    }

    /** Adds ONE more product to an ALREADY-combined row (explicit
     *  follow-up, 2026-09-26: "what about more than 2 combine" — dragging
     *  a 3rd product onto an existing combined row joins the same group
     *  instead of opening the name-picker modal again, since the group
     *  already has a name). Same already-grouped guard as storeGroup()'s
     *  own — the DB-level unique constraint on product_id backs this up
     *  too either way. */
    public function addToGroup(Request $request, ProductGroup $productGroup)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $alreadyGrouped = \Illuminate\Support\Facades\DB::table('product_group_members')
            ->where('product_id', $data['product_id'])
            ->exists();
        if ($alreadyGrouped) {
            return response()->json(['success' => false, 'message' => 'This product is already in a group.'], 422);
        }

        $productGroup->products()->attach($data['product_id']);

        return response()->json(['success' => true, 'group' => $productGroup->load('products')]);
    }
}
