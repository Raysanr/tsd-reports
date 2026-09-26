<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\TsaSalesEntry;
use App\Models\TsaShift;
use App\Support\TsaSalesCalculator;
use App\Support\Teams;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TSD Data Management — Summary Sales Report (explicit request, 2026-09-24:
 * "add page summary sales report in data management like this in the
 * sheets ... i want to make it like auto based on the current team and
 * tsa"). TSA rows are the app's own real TsaShift records, grouped by
 * TsaShift.team (SH Naturals / Eyecare — the app's real 2 teams), NOT the
 * sheet's own Opening Shift / Closing Shift split, which has no real
 * backing data yet (shift_start/shift_end are null for every current
 * TSA — confirmed live before this decision was made). No admin-managed
 * add/rename/remove UI anymore — the roster IS TsaShift, managed via TSA
 * Management like everywhere else in this app.
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

        $teams = collect(Teams::config());
        $tsas  = TsaShift::orderBy('sort_order')->get();

        // whereDate() >=/<=, not a raw whereBetween() on the date-cast
        // column — same SQLite lexicographic-comparison bug found and
        // fixed 2026-09-26 in DsPprReportController/ExpectedIncomeController
        // (a plain whereBetween() bound silently drops the LAST day of any
        // selected range, since '2026-09-26 00:00:00' sorts after the bare
        // '2026-09-26' bound). whereDate() correctly extracts just the
        // date part on every driver (SQLite included).
        $entries = TsaSalesEntry::whereIn('tsa_shift_id', $tsas->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->groupBy('tsa_shift_id');

        // One row per TSA, summed across the whole selected range,
        // grouped by their real team — the sheet's own MTD running
        // total is the same idea, just always MTD there where this page
        // lets any range be picked.
        $groupSummaries = $teams->map(function (array $team, string $slug) use ($tsas, $entries) {
            $teamTsas = $tsas->where('team', $team['order_team'] ?? '__none__')->values();

            $rowSummaries = $teamTsas->map(function (TsaShift $tsa) use ($entries) {
                $tsaEntries = $entries->get($tsa->id, collect());
                return [
                    'tsa'     => $tsa,
                    'derived' => TsaSalesCalculator::sum($tsaEntries->map(fn (TsaSalesEntry $e) => $e->toArray())->all()),
                ];
            });

            return [
                'label'      => $team['name'] ?? $slug,
                'tsas'       => $teamTsas,
                'rows'       => $rowSummaries,
                'groupTotal' => TsaSalesCalculator::sum($rowSummaries->pluck('derived')->all()),
            ];
        })->values();

        $overallTotal = TsaSalesCalculator::sum($groupSummaries->pluck('rows')->flatten(1)->pluck('derived')->all());

        // Per-day entries, keyed "tsaId:date" — same convention as
        // DsPprReportController's own $dailyByKey.
        $dailyByKey = TsaSalesEntry::whereIn('tsa_shift_id', $tsas->pluck('id'))
            ->whereDate('entry_date', '>=', $dateFrom)
            ->whereDate('entry_date', '<=', $dateTo)
            ->get()
            ->keyBy(fn (TsaSalesEntry $e) => $e->tsa_shift_id . ':' . $e->entry_date->toDateString());

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

    /** Auto-save, one (TSA, date, field) at a time — same
     *  upsert-via-whereDate() convention as DsPprReportController::update()
     *  (see that method's own doc comment for why whereDate(), not a bare
     *  attribute match, is required on SQLite). */
    public function updateEntry(Request $request, TsaShift $tsaShift, string $date)
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

        $entry = TsaSalesEntry::where('tsa_shift_id', $tsaShift->id)->whereDate('entry_date', $entryDate)->first()
            ?? new TsaSalesEntry(['tsa_shift_id' => $tsaShift->id, 'entry_date' => $entryDate]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'derived' => TsaSalesCalculator::derive($entry->toArray()),
        ]);
    }
}
