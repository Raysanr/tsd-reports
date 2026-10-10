<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\DsPprEntry;
use App\Models\DsPprLockedDate;
use App\Models\DsPprTiktokEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\ActivityLogger;
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

        // Gross Sales/Net Income are no longer manually typed for a real
        // product (explicit request, 2026-10-10: "i want to make it the
        // gross sales and net income is automated and the basis is from
        // the expected income") — reuses Expected Income's own per-
        // product-per-day derived figures (the exact same numbers its own
        // "Telesales Expected Performance" cards show), not a second,
        // independently re-derived calculation. $onlyOrderTeam mirrors
        // $ordersByDate's own team scoping directly above (same
        // `$teams[$teamSlug]['order_team']` resolution) — null for the
        // ALL-teams view. TikTok Orders stays fully manual, untouched
        // (same confirmed scope boundary as every other DSPPR/Expected
        // Income automation — no real Product/Order data backs that row).
        $onlyOrderTeam = ($teamSlug && $teams->has($teamSlug)) ? ($teams[$teamSlug]['order_team'] ?? null) : null;
        $expectedIncomeByProductAndDate = \App\Http\Controllers\DataManagement\ExpectedIncomeController::grossSalesAndNetIncomeByProductAndDate($dateFrom, $dateTo, $onlyOrderTeam);

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
        $rows = ProductGrouping::rows($products, function ($groupProducts) use ($entries, $dates, $ordersByDate, $expectedIncomeByProductAndDate) {
            $entriesByDate = $groupProducts->flatMap(fn (Product $p) => $entries->get($p->id, collect()))
                ->keyBy(fn (DsPprEntry $e) => $e->entry_date->toDateString());

            $merged = $dates->map(function ($date) use ($entriesByDate, $groupProducts, $ordersByDate, $expectedIncomeByProductAndDate) {
                $dateStr = $date->toDateString();
                $base = $entriesByDate->get($dateStr)?->toArray() ?? [];
                $real = ProductPerformance::dsPprRow($groupProducts, $ordersByDate[$dateStr]);
                // Gross Sales/Net Income (2026-10-10) — same override
                // pattern as $real just above: Expected Income's own
                // figure for the group's FIRST member product on this day
                // wins over $base's stored (now unused) DsPprEntry value.
                // The first member only, not summed across every group
                // member — grossSalesAndNetIncomeByProductAndDate() stores
                // the group's already-pooled figure under EVERY member's
                // own id (see that method's own doc comment), so reading
                // any one member here is equivalent to reading the whole
                // group's total once; summing all members would double
                // (or N-tuple) count it.
                $expectedIncomeFigure = $expectedIncomeByProductAndDate->get($groupProducts->first()->id . ':' . $dateStr, ['gross_sales' => 0.0, 'net_income' => 0.0]);
                return array_merge($base, $real, $expectedIncomeFigure);
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
            // toRawRowWithOverrides(), not a plain ->toArray() — a day
            // with one of the 4 rate columns manually overridden (explicit
            // request, 2026-10-07) folds that straight onto the matching
            // derived key, same "DsPprCalculator::sum()'s own rateOf()
            // already prefers an already-present key over re-deriving it"
            // mechanism real per-product rows use for Pick-up/Conversion/
            // Upselling Rate (see sum()'s own doc comment) — no new
            // special-case needed in the calculator for the daily table.
            $tiktokDailyByKey->put($dateStr, $tiktokEntriesByDate->has($dateStr)
                ? $tiktokEntriesByDate->get($dateStr)->toRawRowWithOverrides()
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
            $firstMemberId = $row['products']->first()->id;
            foreach ($dates as $date) {
                $dateStr = $date->toDateString();
                $real = ProductPerformance::dsPprRow($row['products'], $ordersByDate[$dateStr]);
                // Gross Sales/Net Income (2026-10-10) — same source as the
                // range-summed $rows above (Expected Income's own
                // per-product-per-day figures), merged in here too so the
                // DAILY detail table's own read-only cells (dsppr.blade.php
                // reads these via $realByRowKeyAndDate, NOT $dailyByKey —
                // see that view's own per-column editable/read-only split)
                // show the identical automated figure, not the no-longer-
                // written DsPprEntry value $dailyByKey still carries.
                $real = array_merge($real, $expectedIncomeByProductAndDate->get($firstMemberId . ':' . $dateStr, ['gross_sales' => 0.0, 'net_income' => 0.0]));
                $realByRowKeyAndDate[$rowKey . ':' . $dateStr] = $real;
            }
        }

        // $overallTotal's own Pick-up/Conversion/Upselling Rate are
        // recomputed from the SUMMED raw tally() counts across every
        // display row's own every-date cell — FINAL decision, 2026-10-09
        // (this exact point flip-flopped twice the same day — see this
        // method's own git history / Decisions.md for the full back-
        // and-forth): explicitly confirmed by direct instruction ("it is
        // still not tally, it should be tally the total percentage" /
        // "because it is same data") that DSPPR's own OVERALL TOTAL must
        // equal Leads Report's own Grand Total for the identical range —
        // overriding the earlier 2026-09-24 spreadsheet-average
        // convention, which is no longer the desired behavior even though
        // it was once verified against the real source sheet. Same
        // definition Leads Report's own Grand Total uses
        // (`ProductPerformance::sumRows()`: "rate fields are recomputed
        // from the summed totals rather than averaged"). A useful side
        // effect: this is ALSO immune to the zero-activity-dilution bug a
        // plain average has (a product with 0 total_leads contributes 0/0
        // to the sum, not a diluting literal 0%) — every product getting
        // a card regardless of activity (2026-10-06) no longer skews this
        // total at all.
        $rawCountTotals = array_fill_keys(['answered', 'unanswered', 'confirmed_via_call', 'upsell_confirmation'], 0);
        foreach ($realByRowKeyAndDate as $real) {
            foreach ($rawCountTotals as $key => $value) {
                $rawCountTotals[$key] += $real[$key] ?? 0;
            }
        }
        // TikTok Orders rows have no real tally() behind them (every field
        // manual, including its own pickup_rate/conversion_rate/
        // upselling_rate OVERRIDES — see DsPprTiktokEntry::
        // toRawRowWithOverrides()) — there's no raw answered/unanswered
        // breakdown an override like "Upselling Rate = 100%" could be
        // decomposed back into. TikTok's own total_leads/total_orders
        // still fold in as answered/upsell_confirmation counts (so its
        // volume correctly shifts the Overall Total's percentage), but a
        // TikTok rate OVERRIDE specifically is not reflected in this one
        // blended percentage — it still works everywhere else (TikTok's
        // own card).
        $tiktokRawCounts = $tiktokDailyByKey->values()->reduce(function ($carry, $row) {
            $carry['answered'] += (float) ($row['total_leads'] ?? 0);
            $carry['upsell_confirmation'] += (float) ($row['total_orders'] ?? 0);
            return $carry;
        }, ['answered' => 0, 'upsell_confirmation' => 0]);
        $rawCountTotals['answered'] += $tiktokRawCounts['answered'];
        $rawCountTotals['upsell_confirmation'] += $tiktokRawCounts['upsell_confirmation'];
        $sumThenRates = ProductPerformance::rates($rawCountTotals);
        $overallTotal['pickup_rate']     = $sumThenRates['pick_up_rate'] !== null ? $sumThenRates['pick_up_rate'] / 100 : 0.0;
        $overallTotal['conversion_rate'] = $sumThenRates['conversion_rate'] !== null ? $sumThenRates['conversion_rate'] / 100 : 0.0;
        $overallTotal['upselling_rate']  = $sumThenRates['upselling_rate'] !== null ? $sumThenRates['upselling_rate'] / 100 : 0.0;

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

        // Per-date lock (explicit follow-up, 2026-10-07: "i want to make
        // it per date like the lock icon is in the dates right side" —
        // reverses the earlier same-day "one lock for the whole table"
        // decision) — a set of every locked date STRING in the selected
        // range, so the view's own in_array() check is O(1)-ish per date
        // header without a query per date. See DsPprLockedDate's own doc
        // comment for why this is a separate table keyed by date alone.
        $lockedDates = DsPprLockedDate::whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->pluck('entry_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

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
            // Keyed by date, the real models (not $tiktokDailyByKey's own
            // plain raw arrays) — the view's own 4 rate-override inputs
            // (explicit request, 2026-10-07) need the real *_override
            // columns directly to seed each input's own saved value,
            // same "$entry seeds inputs, $d/derived seeds display" split
            // every other editable page in this app already follows.
            'tiktokEntriesByDate' => $tiktokEntriesByDate,
            'lockedDates' => $lockedDates,
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
        // gross_sales/net_income REMOVED the same way (explicit request,
        // 2026-10-10: "i want to make it the gross sales and net income
        // is automated and the basis is from the expected income") — the
        // DB columns still exist but are no longer written; a stray POST
        // for either is silently dropped, same convention.
        $data = $request->validate([
            'ads_spent'     => ['sometimes', 'numeric', 'min:0'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        // Never trust the frontend's own `disabled` attribute alone — a
        // locked date refuses a direct PATCH too (explicit follow-up,
        // 2026-10-07 — same guard Expected Income's own per-card
        // is_locked already has).
        if (DsPprLockedDate::whereDate('entry_date', $entryDate)->exists()) {
            return response()->json(['success' => false, 'message' => 'This date is locked.'], 422);
        }

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
        $old = $entry->only(array_keys($data));
        $entry->fill($data);
        $entry->save();

        // Every activity recorded (explicit request, 2026-10-07: "all
        // activites in every page should be recorded ... what she
        // edited") — one entry per field actually changed, same pattern
        // every other Data Management controller's own autosave endpoint
        // now follows.
        $context = $product->display_name . ' on ' . Carbon::parse($entryDate)->format('M j, Y');
        foreach ($data as $field => $newValue) {
            if ((float) ($old[$field] ?? 0) == (float) $newValue) continue;
            ActivityLogger::logFieldUpdate('dsppr.field_updated', $entry, $field, $old[$field] ?? 0, $newValue, $context, fn ($v) => number_format((float) $v, 2));
        }

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

        // Gross Sales/Net Income (2026-10-10) — same automation basis as
        // index()'s own $expectedIncomeByProductAndDate, looked up fresh
        // here (one product/day, not the whole page's range) so a live
        // PATCH response (e.g. editing Ads Spent) reflects the real
        // automated figure instead of falling back to $entry's own
        // no-longer-written stored value. Applied AFTER sum()/derive()
        // above, NOT merged onto $real before pooling — that method
        // already stores the group's single pooled figure under EVERY
        // member's own id (see its own doc comment), so merging it onto
        // $real first would have DsPprCalculator::sum() add that same
        // figure once per group member, N-tupling it (root-caused via a
        // failing test: a 2-member group returned 3,000 instead of the
        // real 1,500).
        $expectedIncomeFigure = \App\Http\Controllers\DataManagement\ExpectedIncomeController::grossSalesAndNetIncomeByProductAndDate($entryDate, $entryDate)->get($product->id . ':' . $entryDate, ['gross_sales' => 0.0, 'net_income' => 0.0]);
        $derived = array_merge($derived, $expectedIncomeFigure);
        $derived['ni_pct'] = $derived['gross_sales'] > 0 ? $derived['net_income'] / $derived['gross_sales'] : 0.0;
        $derived['aov'] = $derived['total_orders'] > 0 ? $derived['gross_sales'] / $derived['total_orders'] : 0.0;

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
            // Rate overrides (explicit request, 2026-10-07: "make it
            // editable" — Excess Leads/Pick-up Rate/Conversion Rate/
            // Upselling Rate are formulas everywhere else on this page,
            // but TIKTOK ORDERS' own manual row gets a direct override
            // for each — see DsPprTiktokEntry::toRawRowWithOverrides()'s
            // own doc comment). A blank/null value clears the override
            // and reverts that cell to the formula — 'nullable', not
            // 'sometimes', so the frontend can explicitly send an empty
            // value to clear one (sometimes alone would just never touch
            // the column at all on a blank submit).
            'excess_leads_override'    => ['nullable', 'integer', 'min:0'],
            'pickup_rate_override'     => ['nullable', 'numeric'],
            'conversion_rate_override' => ['nullable', 'numeric'],
            'upselling_rate_override'  => ['nullable', 'numeric'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        if (DsPprLockedDate::whereDate('entry_date', $entryDate)->exists()) {
            return response()->json(['success' => false, 'message' => 'This date is locked.'], 422);
        }

        $entry = DsPprTiktokEntry::whereDate('entry_date', $entryDate)->first()
            ?? new DsPprTiktokEntry(['entry_date' => $entryDate]);
        $old = $entry->only(array_keys($data));
        $entry->fill($data);
        $entry->save();

        $context = 'TIKTOK ORDERS on ' . Carbon::parse($entryDate)->format('M j, Y');
        $isPctOverride = fn (string $field) => in_array($field, ['pickup_rate_override', 'conversion_rate_override', 'upselling_rate_override'], true);
        foreach ($data as $field => $newValue) {
            if ((string) ($old[$field] ?? '') === (string) $newValue) continue;
            $formatter = match (true) {
                $isPctOverride($field) => fn ($v) => number_format((float) $v * 100, 2) . '%',
                is_numeric($newValue)  => fn ($v) => number_format((float) $v, 2),
                default                 => null,
            };
            ActivityLogger::logFieldUpdate('dsppr.field_updated', $entry, $field, $old[$field] ?? null, $newValue, $context, $formatter);
        }

        $derived = DsPprCalculator::derive($entry->toRawRowWithOverrides());

        return response()->json([
            'success' => true,
            'derived' => $derived,
        ]);
    }

    /** Flips the daily-entry table's own whole-table lock on/off (explicit
     *  request, 2026-10-07) — see LOCK_SETTING_KEY's own doc comment for
     *  why this is one Setting flag rather than per-7-day-chunk/per-row. */
    /** Flips ONE date's own lock on/off (explicit follow-up, 2026-10-07:
     *  "i want to make it per date like the lock icon is in the dates
     *  right side") — freezes every product's own Gross Sales/Net Income
     *  input for that one date, plus TIKTOK ORDERS' own fields for that
     *  same date, across every 7-day chunk that date happens to render
     *  in. See DsPprLockedDate's own doc comment for why existence of a
     *  row is the flag (no boolean column needed). */
    public function toggleLock(Request $request, string $date)
    {
        $locked = $request->boolean('locked');
        $entryDate = Carbon::parse($date)->toDateString();

        if ($locked) {
            DsPprLockedDate::firstOrCreate(['entry_date' => $entryDate]);
        } else {
            DsPprLockedDate::whereDate('entry_date', $entryDate)->delete();
        }
        ActivityLogger::log($locked ? 'dsppr.date_locked' : 'dsppr.date_unlocked', null, ($locked ? 'Locked' : 'Unlocked') . ' the daily entry table for ' . Carbon::parse($entryDate)->format('M j, Y') . '.');

        return response()->json(['success' => true, 'date' => $entryDate, 'locked' => $locked]);
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
        ActivityLogger::log('dsppr.products_combined', $group, "Combined " . Product::whereIn('id', $data['product_ids'])->pluck('display_name')->implode(' + ') . " into \"{$data['label']}\".");

        return response()->json(['success' => true, 'group' => $group->load('products')]);
    }

    /** Splits a combined row back into its own separate products
     *  (explicit request, 2026-09-26: a small ungroup control on the
     *  combined row) — deletes the group and its membership rows only;
     *  every real DsPprEntry/ExpectedIncomeEntry the member products ever
     *  had stays exactly as it was, since grouping never touched them. */
    public function destroyGroup(ProductGroup $productGroup)
    {
        $label = $productGroup->label;
        $productGroup->delete();
        ActivityLogger::log('dsppr.products_ungrouped', null, "Split \"{$label}\" back into its own separate products.");

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
        $addedName = Product::find($data['product_id'])?->display_name;
        ActivityLogger::log('dsppr.products_combined', $productGroup, "Added \"{$addedName}\" to the combined row \"{$productGroup->label}\".");

        return response()->json(['success' => true, 'group' => $productGroup->load('products')]);
    }
}
