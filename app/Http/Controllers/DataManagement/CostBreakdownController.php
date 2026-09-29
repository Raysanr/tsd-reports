<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\TsaShift;
use App\Support\CostBreakdownCalculator;
use Illuminate\Http\Request;

/**
 * TSD Data Management — Cost Breakdown (explicit request, 2026-09-29: "add
 * new page in data management (COST BREAKDOWN) ... like this sheets", a
 * real "TSD Payroll" spreadsheet). Two independent sections on one page:
 *
 *   1. A top salary/org section (CostBreakdownRole — CEO, Sales Director,
 *      Telesales Manager, QA Specialist, Junior AI Engineer, each shift's
 *      own Supervisor) — every field manual, "TOTAL SALARY OF TSD" sums
 *      only the real TSAs' own totals (confirmed against the sheet's own
 *      raw CSV: that figure excludes the CEO/Sales Director/Manager/QA/
 *      Junior AI/Supervisor rows entirely, matching only the 12 real
 *      Telesales Associate rows' own sum).
 *
 *   2. A bottom per-TSA cost-allocation table (CostBreakdownTsaEntry, keyed
 *      to the app's REAL TsaShift roster) — Days is the only field that
 *      drives a real formula (her own % share of every shared cost pool);
 *      base_salary/tsa_bonus are manual but don't feed anything past their
 *      own row. The 21 shared monthly pools (CostBreakdownPool) split
 *      across every TSA by her own % share — see CostBreakdownCalculator's
 *      own doc comment for the full formula chain and the real sheet's own
 *      genuine off-by-one formula bug this app deliberately does NOT
 *      replicate.
 *
 * Uses the app's real TsaShift roster, not the sheet's own static 12-name
 * snapshot (explicit decision, 2026-09-29, same reasoning as Projections/
 * Expected Income/DSPPR's own real-roster/real-product convention) — a TSA
 * added later automatically gets her own row here with zero extra step.
 */
class CostBreakdownController extends Controller
{
    public function index()
    {
        // Self-heals an empty table the same way ProjectionColumn::
        // ensureSeeded() does — a migrated-but-empty table is a real
        // failure mode already seen in this app (a dev DB reset after the
        // migration already ran).
        CostBreakdownRole::ensureSeeded();
        CostBreakdownPool::ensureSeeded();
        CostBreakdownTsaEntry::ensureSeeded();

        $roles = CostBreakdownRole::orderBy('sort_order')->get();
        $pools = CostBreakdownPool::orderBy('sort_order')->get();

        $tsas = TsaShift::orderBy('team')->orderBy('sort_order')->get();
        $entriesByTsaId = CostBreakdownTsaEntry::whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');

        // One row per real TSA, seeded from her own saved entry (or a
        // fresh all-zero one with the default 30 days — same "a cell with
        // nothing typed yet still renders, just at 0" convention as every
        // other page in this module).
        $tsaRows = $tsas->map(function (TsaShift $tsa) use ($entriesByTsaId) {
            $entry = $entriesByTsaId->get($tsa->id) ?? new CostBreakdownTsaEntry(['tsa_id' => $tsa->id, 'days' => 30]);
            return ['tsa' => $tsa, 'entry' => $entry];
        });

        $totalDays = $tsaRows->sum(fn ($row) => $row['entry']->days);
        $poolAmounts = $pools->pluck('amount', 'key')->all();

        // Each TSA's own % share + her own dollar amount per pool + her
        // own row TOTAL — computed once here so the view never has to call
        // into the calculator itself (same "controller computes, view only
        // renders" separation as every other report page in this module).
        $tsaRows = $tsaRows->map(function ($row) use ($totalDays, $poolAmounts) {
            $share = CostBreakdownCalculator::shareOfDays($row['entry']->days, $totalDays);
            $row['share'] = $share;
            $row['derived'] = CostBreakdownCalculator::rowForShare($share, $poolAmounts);
            return $row;
        });

        // The bottom plain totals list — Salaries here is NOT a manual
        // CostBreakdownPool row; it's TOTAL SALARY OF TSD's own real number
        // (see totalSalaryOfTsd() below), always in sync with the top
        // section rather than a second, independently-typed figure that
        // could drift from it.
        $totalSalaryOfTsd = $this->totalSalaryOfTsd($tsaRows);
        $poolTotalsRow = $pools->reduce(function ($carry, CostBreakdownPool $pool) {
            $carry[$pool->key] = $pool->amount;
            return $carry;
        }, []);
        $grandTotal = array_sum($poolTotalsRow);

        return view('data.cost-breakdown', [
            'roles' => $roles,
            'pools' => $pools,
            'tsaRows' => $tsaRows,
            'totalDays' => $totalDays,
            'totalSalaryOfTsd' => $totalSalaryOfTsd,
            'poolTotalsRow' => $poolTotalsRow,
            'grandTotal' => $grandTotal,
            'rowGrandTotal' => $tsaRows->sum(fn ($row) => $row['derived']['total']),
        ]);
    }

    /** TOTAL SALARY OF TSD — sum of every real TSA's own monthly total
     *  (base_salary + tsa_bonus), confirmed against the sheet's own raw
     *  CSV to exclude the CEO/Sales Director/Telesales Manager/QA
     *  Specialist/Junior AI Engineer/Supervisor rows entirely — those are
     *  shown on the same page for context, but this one figure is
     *  Telesales-ASSOCIATE-only, same "TSD" (Telesales Department staff,
     *  not the wider leadership/support team) framing the sheet's own
     *  label implies. */
    private function totalSalaryOfTsd($tsaRows): float
    {
        return $tsaRows->sum(fn ($row) => CostBreakdownCalculator::tsaMonthlyTotal($row['entry']->base_salary, $row['entry']->tsa_bonus));
    }

    /** Auto-save for one role's own field (explicit request, 2026-09-29,
     *  same debounced-PATCH-per-field convention as every other page in
     *  this module). */
    public function updateRole(Request $request, CostBreakdownRole $costBreakdownRole)
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'shared_bonus' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $costBreakdownRole->update($data);

        return response()->json([
            'success' => true,
            'total' => CostBreakdownCalculator::roleTotal($costBreakdownRole->base_salary, $costBreakdownRole->shared_bonus),
        ]);
    }

    /** Auto-save for one shared pool's own monthly amount. Returns every
     *  TSA's own freshly-recomputed row (a pool amount change affects
     *  everyone's own dollar figure for that column, not just one row) —
     *  same "one shared value change, every dependent row refreshed"
     *  convention as ProjectionController::updateRates(). */
    public function updatePool(Request $request, CostBreakdownPool $costBreakdownPool)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $costBreakdownPool->update($data);

        return response()->json([
            'success' => true,
            'pool' => ['key' => $costBreakdownPool->key, 'amount' => $costBreakdownPool->amount],
            'recomputed' => $this->recomputeAllTsaRows(),
        ]);
    }

    /** Auto-save for one TSA's own Days/base_salary/tsa_bonus. Days is the
     *  only field here that changes every OTHER TSA's own % share too
     *  (see CostBreakdownCalculator's own doc comment) — same "shared
     *  total, every row recomputed" reasoning as updatePool() above. */
    public function updateTsaEntry(Request $request, TsaShift $tsaShift)
    {
        $data = $request->validate([
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'tsa_bonus' => ['sometimes', 'numeric', 'min:0'],
            'days' => ['sometimes', 'integer', 'min:0'],
        ]);

        $entry = CostBreakdownTsaEntry::where('tsa_id', $tsaShift->id)->first()
            ?? new CostBreakdownTsaEntry(['tsa_id' => $tsaShift->id, 'days' => 30]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'monthlyTotal' => CostBreakdownCalculator::tsaMonthlyTotal($entry->base_salary, $entry->tsa_bonus),
            'dailyRate' => CostBreakdownCalculator::tsaDailyRate(CostBreakdownCalculator::tsaMonthlyTotal($entry->base_salary, $entry->tsa_bonus)),
            'recomputed' => $this->recomputeAllTsaRows(),
        ]);
    }

    /** Every real TSA's own freshly-recomputed % share + per-pool row +
     *  row TOTAL, keyed by tsa_id — shared by updatePool()/updateTsaEntry()
     *  above since either one changes every row's own figures, not just
     *  the one just-saved cell's row. */
    private function recomputeAllTsaRows(): array
    {
        $tsas = TsaShift::orderBy('team')->orderBy('sort_order')->get();
        $entriesByTsaId = CostBreakdownTsaEntry::whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');
        $poolAmounts = CostBreakdownPool::pluck('amount', 'key')->all();

        $totalDays = $tsas->sum(fn (TsaShift $tsa) => ($entriesByTsaId->get($tsa->id)?->days) ?? 30);

        return $tsas->mapWithKeys(function (TsaShift $tsa) use ($entriesByTsaId, $totalDays, $poolAmounts) {
            $entry = $entriesByTsaId->get($tsa->id);
            $days = $entry?->days ?? 30;
            $share = CostBreakdownCalculator::shareOfDays($days, $totalDays);

            return [$tsa->id => [
                'days' => $days,
                'share' => $share,
                'derived' => CostBreakdownCalculator::rowForShare($share, $poolAmounts),
            ]];
        })->all();
    }
}
