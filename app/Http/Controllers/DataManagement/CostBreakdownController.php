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
 *      base_salary is manual (her real full monthly total — no separate
 *      bonus field, explicit follow-up 2026-09-29: "there's no bonus on
 *      the sheets") but doesn't feed anything past its own row. The 21
 *      shared monthly pools (CostBreakdownPool) split
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

        // Overhead-per-TSA — confirmed a LIVE FORMULA in the real sheet
        // (explicit follow-up, 2026-09-29: "look at this formula ... it is
        // all divided of all 12 tsa"), not a manually-typed number as this
        // app originally modeled it. Grouped by overhead_group (roles
        // sharing the same group sum their own base_salary together before
        // dividing), each group's own divisor is either the TOTAL real TSA
        // headcount or, for a Supervisor (overhead_divisor === 'team'),
        // just HER OWN team's real TSA count — see
        // CostBreakdownCalculator::overheadPerTsa()'s own doc comment for
        // the exact 3 real numbers this reproduces.
        $totalTsaCount = $tsas->count();
        $tsaCountByTeam = $tsas->countBy(fn (TsaShift $tsa) => $tsa->team);
        $rolesByGroup = $roles->whereNotNull('overhead_group')->groupBy('overhead_group');
        $overheadByRoleId = $roles->mapWithKeys(function (CostBreakdownRole $role) use ($rolesByGroup, $totalTsaCount, $tsaCountByTeam) {
            if (!$role->overhead_group) {
                return [$role->id => null];
            }

            $groupBaseSalaries = $rolesByGroup->get($role->overhead_group)->pluck('base_salary')->all();
            $tsaCount = $role->overhead_divisor === 'team' ? ($tsaCountByTeam->get($role->team) ?? 0) : $totalTsaCount;

            return [$role->id => CostBreakdownCalculator::overheadPerTsa($groupBaseSalaries, $tsaCount)];
        });

        // Salary section render list (explicit follow-up, 2026-09-29:
        // "the supervisor of opening and closing is in the rows of their
        // TSA's") — a flat sequence of ['type' => 'role'|'tsa', ...]
        // entries, built ONCE here so the view never has to know which
        // role owns which team's TSAs itself. A Supervisor role
        // (role->team set) is immediately followed by every real TsaShift
        // whose own `team` column matches hers, in that team's existing
        // roster order — every other role has no TSAs nested under it at
        // all, same as the real sheet.
        //
        // rowspan (role rows only): how many rows this role's own overhead
        // figure visually covers — every OTHER role sharing the same
        // overhead_group AFTER this one in sort order, +1 for itself (the
        // FIRST role in a group anchors the merged cell, same convention
        // as a real spreadsheet's own merged region). A role covered by an
        // EARLIER role's own rowspan renders no "Overhead / TSA" cell of
        // its own at all — tracked with a running counter as roles are
        // walked in order.
        $rowspanRemaining = 0;
        $seenGroups = [];
        $salaryRows = $roles->flatMap(function (CostBreakdownRole $role) use ($tsaRows, $overheadByRoleId, $rolesByGroup, &$rowspanRemaining, &$seenGroups) {
            $coveredByRowspan = $rowspanRemaining > 0;
            $rowspan = 0;

            if (!$coveredByRowspan && $role->overhead_group && !in_array($role->overhead_group, $seenGroups, true)) {
                $seenGroups[] = $role->overhead_group;
                $rowspan = $rolesByGroup->get($role->overhead_group)->count();
                $rowspanRemaining = $rowspan - 1;
            } elseif ($rowspanRemaining > 0) {
                $rowspanRemaining--;
            }

            $rows = collect([[
                'type' => 'role',
                'role' => $role,
                'overhead' => $overheadByRoleId->get($role->id),
                'rowspan' => $rowspan,
                'covered_by_rowspan' => $coveredByRowspan,
            ]]);

            if ($role->team) {
                $rows = $rows->concat(
                    $tsaRows->filter(fn ($row) => $row['tsa']->team === $role->team)
                        ->map(fn ($row) => ['type' => 'tsa', 'tsa' => $row['tsa'], 'entry' => $row['entry']])
                );
            }

            return $rows;
        })->values();

        // group_end (view-only, purely visual): true on the LAST row of
        // each cluster — {CEO, Sales Director, Telesales Manager}, {QA
        // Specialist, Junior AI Engineer}, {each Supervisor + her own
        // team's real TSAs} — so the view can draw a gap AFTER it instead
        // of a divider line under every single row (explicit follow-up,
        // 2026-09-29: "no row line ... make it look clean like there's a
        // group"). A new cluster starts at the next 'role' row that isn't
        // itself covered by an earlier row's rowspan, so the row right
        // before it is that cluster's own last row.
        $salaryRows = $salaryRows->map(function ($row, $index) use ($salaryRows) {
            $next = $salaryRows->get($index + 1);
            $row['group_end'] = !$next || ($next['type'] === 'role' && !$next['covered_by_rowspan']);
            return $row;
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
            'salaryRows' => $salaryRows,
            'pools' => $pools,
            'tsaRows' => $tsaRows,
            'totalDays' => $totalDays,
            'totalSalaryOfTsd' => $totalSalaryOfTsd,
            'poolTotalsRow' => $poolTotalsRow,
            'grandTotal' => $grandTotal,
            'rowGrandTotal' => $tsaRows->sum(fn ($row) => $row['derived']['total']),
        ]);
    }

    /** TOTAL SALARY OF TSD — sum of every real TSA's own base_salary
     *  (already her real full monthly total — explicit follow-up,
     *  2026-09-29: "there's no bonus on the sheets"), confirmed against
     *  the sheet's own raw CSV to exclude the CEO/Sales Director/Telesales
     *  Manager/QA Specialist/Junior AI Engineer/Supervisor rows entirely —
     *  those are shown on the same page for context, but this one figure
     *  is Telesales-ASSOCIATE-only, same "TSD" (Telesales Department
     *  staff, not the wider leadership/support team) framing the sheet's
     *  own label implies. */
    private function totalSalaryOfTsd($tsaRows): float
    {
        return $tsaRows->sum(fn ($row) => $row['entry']->base_salary);
    }

    /** Auto-save for one role's own base_salary (explicit request,
     *  2026-09-29, same debounced-PATCH-per-field convention as every other
     *  page in this module). overhead_group/overhead_divisor are read-only
     *  from this endpoint's own perspective (structural fields with no UI
     *  control — see rework_shared_bonus_into_overhead_group_on_cost_
     *  breakdown_roles_table's own doc comment). Editing base_salary here
     *  can change OTHER roles' own overhead-per-TSA figure too (a role
     *  sharing this one's own overhead_group sums their base_salary
     *  together before dividing) — returns every role's own freshly-
     *  recomputed overhead figure, same "one shared value change, every
     *  dependent row refreshed" convention as updatePool() below. */
    public function updateRole(Request $request, CostBreakdownRole $costBreakdownRole)
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'person_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $costBreakdownRole->update($data);

        return response()->json([
            'success' => true,
            'total' => $costBreakdownRole->base_salary,
            'recomputedOverhead' => $this->recomputeAllRoleOverhead(),
        ]);
    }

    /** Every role's own freshly-recomputed overhead-per-TSA figure, keyed
     *  by role id — shared by updateRole() above since editing one role's
     *  base_salary can change every OTHER role in the same overhead_group's
     *  own displayed figure too (they all show the SAME summed-then-divided
     *  number, per the real sheet's own merged cell). */
    private function recomputeAllRoleOverhead(): array
    {
        $roles = CostBreakdownRole::all();
        $totalTsaCount = TsaShift::count();
        $tsaCountByTeam = TsaShift::all()->countBy(fn (TsaShift $tsa) => $tsa->team);
        $rolesByGroup = $roles->whereNotNull('overhead_group')->groupBy('overhead_group');

        return $roles->mapWithKeys(function (CostBreakdownRole $role) use ($rolesByGroup, $totalTsaCount, $tsaCountByTeam) {
            if (!$role->overhead_group) {
                return [$role->id => null];
            }

            $groupBaseSalaries = $rolesByGroup->get($role->overhead_group)->pluck('base_salary')->all();
            $tsaCount = $role->overhead_divisor === 'team' ? ($tsaCountByTeam->get($role->team) ?? 0) : $totalTsaCount;

            return [$role->id => CostBreakdownCalculator::overheadPerTsa($groupBaseSalaries, $tsaCount)];
        })->all();
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

    /** Auto-save for one TSA's own Days/base_salary — base_salary is her
     *  real full monthly total already (no separate bonus field, see
     *  CostBreakdownTsaEntry's own doc comment). Days is the only field
     *  here that changes every OTHER TSA's own % share too (see
     *  CostBreakdownCalculator's own doc comment) — same "shared total,
     *  every row recomputed" reasoning as updatePool() above. */
    public function updateTsaEntry(Request $request, TsaShift $tsaShift)
    {
        $data = $request->validate([
            'base_salary' => ['sometimes', 'numeric', 'min:0'],
            'days' => ['sometimes', 'integer', 'min:0'],
        ]);

        $entry = CostBreakdownTsaEntry::where('tsa_id', $tsaShift->id)->first()
            ?? new CostBreakdownTsaEntry(['tsa_id' => $tsaShift->id, 'days' => 30]);
        $entry->fill($data);
        $entry->save();

        return response()->json([
            'success' => true,
            'dailyRate' => CostBreakdownCalculator::tsaDailyRate($entry->base_salary),
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
