<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\TsaSalesEntry;
use App\Models\TsaSalesGroup;
use App\Models\TsaSalesRow;
use App\Support\TsaSalesCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TSD Data Management — Summary Sales Report (explicit request, 2026-09-24:
 * "add page summary sales report in data management like this in the
 * sheets ... analyze the all formula too every cells"). Replicates the
 * source sheet's own "Summary - Sales Report" tab: 3 fixed groups (Team
 * Opening Shift, Team Closing Shift, Tiktok Upsell), each holding
 * admin-named TSA rows with 7 typed-in numbers per day — see
 * TsaSalesCalculator's own doc comment for which 2 are derived (NI%, AOV)
 * and why Pick-up Rate/Upselling Rate stay raw manual entry.
 *
 * Same single-filterable-date-range + 7-day-chunked-tables shape as
 * DsPprReportController, for the same reasons (see that controller's own
 * doc comment).
 */
class TsaSalesReportController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from') ?: today()->startOfWeek()->toDateString();
        $dateTo   = $request->input('date_to') ?: today()->toDateString();

        $groups = TsaSalesGroup::orderBy('sort_order')->with('rows')->get();
        $allRowIds = $groups->pluck('rows')->flatten()->pluck('id');

        $entries = TsaSalesEntry::whereIn('tsa_sales_row_id', $allRowIds)
            ->whereBetween('entry_date', [$dateFrom, $dateTo])
            ->get()
            ->groupBy('tsa_sales_row_id');

        // One row per TSA, summed across the whole selected range — the
        // sheet's own MTD running total is the same idea, just always
        // MTD there where this page lets any range be picked.
        $groupSummaries = $groups->map(function (TsaSalesGroup $group) use ($entries) {
            $rowSummaries = $group->rows->map(function (TsaSalesRow $row) use ($entries) {
                $rowEntries = $entries->get($row->id, collect());
                return [
                    'row'     => $row,
                    'derived' => TsaSalesCalculator::sum($rowEntries->map(fn (TsaSalesEntry $e) => $e->toArray())->all()),
                ];
            });

            return [
                'group'        => $group,
                'rows'         => $rowSummaries,
                'groupTotal'   => TsaSalesCalculator::sum($rowSummaries->pluck('derived')->all()),
            ];
        });

        $overallTotal = TsaSalesCalculator::sum($groupSummaries->pluck('rows')->flatten(1)->pluck('derived')->all());

        // Per-day entries, keyed "rowId:date" — same convention as
        // DsPprReportController's own $dailyByKey.
        $dailyByKey = TsaSalesEntry::whereIn('tsa_sales_row_id', $allRowIds)
            ->whereBetween('entry_date', [$dateFrom, $dateTo])
            ->get()
            ->keyBy(fn (TsaSalesEntry $e) => $e->tsa_sales_row_id . ':' . $e->entry_date->toDateString());

        $dates = collect(iterator_to_array(Carbon::parse($dateFrom)->daysUntil(Carbon::parse($dateTo)->addDay())));
        $dateChunks = $dates->chunk(7)->values();

        return view('data.tsa-sales', [
            'groupSummaries' => $groupSummaries,
            'overallTotal'   => $overallTotal,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'dateChunks'     => $dateChunks,
            'dailyByKey'     => $dailyByKey,
        ]);
    }

    /** Auto-save, one (row, date, field) at a time — same
     *  upsert-via-whereDate() convention as DsPprReportController::update()
     *  (see that method's own doc comment for why whereDate(), not a bare
     *  attribute match, is required on SQLite). */
    public function updateEntry(Request $request, TsaSalesRow $tsaSalesRow, string $date)
    {
        $data = $request->validate([
            'gross_sales'    => ['sometimes', 'numeric'],
            'net_income'     => ['sometimes', 'numeric'],
            'ads_spent'      => ['sometimes', 'numeric', 'min:0'],
            'total_orders'   => ['sometimes', 'integer', 'min:0'],
            'catered_leads'  => ['sometimes', 'integer', 'min:0'],
            'pickup_rate'    => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'upselling_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $entryDate = Carbon::parse($date)->toDateString();

        $entry = TsaSalesEntry::where('tsa_sales_row_id', $tsaSalesRow->id)->whereDate('entry_date', $entryDate)->first()
            ?? new TsaSalesEntry(['tsa_sales_row_id' => $tsaSalesRow->id, 'entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'derived' => TsaSalesCalculator::derive($entry->toArray()),
        ]);
    }

    /** Adds a new named TSA row to a group (explicit request, 2026-09-24:
     *  "admin adds/names TSA rows freely per group") — appended to the
     *  end of that group's own list. */
    public function storeRow(Request $request, TsaSalesGroup $tsaSalesGroup)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $nextSort = $tsaSalesGroup->rows()->max('sort_order') + 1;
        $row = $tsaSalesGroup->rows()->create(['name' => $data['name'], 'sort_order' => $nextSort]);

        return response()->json(['success' => true, 'row' => $row]);
    }

    /** Renames a TSA row — same debounced-auto-save convention as every
     *  other editable field on this page. */
    public function updateRow(Request $request, TsaSalesRow $tsaSalesRow)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $tsaSalesRow->update($data);

        return response()->json(['success' => true]);
    }

    /** Removes a TSA row and its own entries (cascade-deleted via the
     *  foreign key) — e.g. a row added by mistake, or a TSA no longer on
     *  this shift. */
    public function destroyRow(TsaSalesRow $tsaSalesRow)
    {
        $tsaSalesRow->delete();

        return response()->json(['success' => true]);
    }
}
