<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\Product;
use App\Models\TsaShift;
use App\Support\CostBreakdownCalculator;
use App\Support\ProductGrouping;
use App\Support\TsaDailyRateService;
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
 *      drives a real formula here (her own % share of every shared cost
 *      pool); base_salary is manual (her RAW base salary only — her own
 *      displayed TOTAL in the top salary section is a separate LIVE
 *      FORMULA, base_salary + her applicable overhead refs, confirmed from
 *      the user's own formula-bar screenshot 2026-09-30 — see
 *      CostBreakdownCalculator::tsaTotal()'s own doc comment) but
 *      base_salary doesn't feed this bottom table's own formulas at all.
 *      The 21 shared monthly pools (CostBreakdownPool) split
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
        // dividing) — each Supervisor has her OWN overhead_group (not a
        // shared "supervisor" one), so her own merged cell never spans
        // into the other shift's own rows. Divisor is the sheet's OWN
        // fixed headcount (explicit decision, 2026-09-29: match the sheet
        // exactly, not this app's own smaller real roster) — 12 total for
        // 'total', 6 for 'team' — see
        // CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS and
        // CostBreakdownCalculator::overheadPerTsa()'s own doc comment.
        $overheadByRoleId = $this->overheadByRoleId($roles);
        $overheadRefsByTeam = $this->overheadRefsByTeam($roles, $overheadByRoleId);

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
        $rolesByGroup = $roles->whereNotNull('overhead_group')->groupBy('overhead_group');
        $rowspanRemaining = 0;
        $seenGroups = [];
        $salaryRows = $roles->flatMap(function (CostBreakdownRole $role) use ($tsaRows, $overheadByRoleId, $rolesByGroup, $overheadRefsByTeam, &$rowspanRemaining, &$seenGroups) {
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
                $overheadRefs = $overheadRefsByTeam->get($role->team) ?? [];
                $rows = $rows->concat(
                    $tsaRows->filter(fn ($row) => $row['tsa']->team === $role->team)
                        ->map(fn ($row) => [
                            'type' => 'tsa',
                            'tsa' => $row['tsa'],
                            'entry' => $row['entry'],
                            'total' => CostBreakdownCalculator::tsaTotal($row['entry']->base_salary, $overheadRefs),
                        ])
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

        // Product columns on the bottom cost-allocation table (explicit
        // request, 2026-09-30: "add the products ... analyze the formula
        // in the sheets") — every real product's own COLUMN always shows
        // (with its own checkbox), same roster Expected Income shows
        // (ProductGrouping-merged card count, not a bare Product::count()),
        // but only a FLAGGED product's own column gets a real dollar value
        // and counts toward the divisor (explicit follow-up, 2026-09-30:
        // "user only can identify what product that has cost" — matches
        // the real sheet's own template, which only ever filled in 7 of
        // its product columns, leaving the rest blank). Confirmed against
        // the real sheet's own xlsx formulas (AA45=Z45/7, AB45=Z45/7 for
        // Julie Francisco — see TsaDailyRateService's own doc comment):
        // every FLAGGED product's column shows the SAME figure, her own
        // Daily Rate ÷ flagged-product count — the sheet's own formula
        // never varies per product.
        $products = Product::orderBy('team')->orderBy('sort_order')->get();
        $productRows = ProductGrouping::rows($products, fn () => null);
        $flaggedProductCount = TsaDailyRateService::productCount();

        $totalDays = $tsaRows->sum(fn ($row) => $row['entry']->days);
        $poolAmounts = $pools->pluck('amount', 'key')->all();

        // "Daily Cost per product" / "Daily Cost" mini-table sitting above
        // the per-TSA rows (explicit request, 2026-09-30, real sheet
        // screenshot) — NOT scoped to any specific TSA, each pool's own
        // amount ÷ the app's own REAL TSA count (explicit correction,
        // 2026-09-30: "the 12 is number of the tsa" — not the sheet's own
        // fixed 12-TSA headcount) ÷ 24 working days, then that same figure
        // split across every FLAGGED product. See CostBreakdownCalculator::
        // dailyCostRow()'s own doc comment for the confirmed-exact formula.
        $dailyCostRow = CostBreakdownCalculator::dailyCostRow($poolAmounts, $tsas->count());
        $dailyCostPerProductRow = CostBreakdownCalculator::dailyCostPerProductRow($dailyCostRow, $flaggedProductCount);

        // Each TSA's own % share + her own dollar amount per pool + her
        // own row TOTAL — computed once here so the view never has to call
        // into the calculator itself (same "controller computes, view only
        // renders" separation as every other report page in this module).
        // Her own Daily Rate / Product (same figure repeated into every
        // FLAGGED product column only — see doc comment above) is appended
        // onto 'derived' under a 'products' key, keyed by that row's own
        // label; an unflagged product's own key is simply absent, and the
        // view shows a blank cell for it.
        $tsaRows = $tsaRows->map(function ($row) use ($totalDays, $poolAmounts, $productRows, $flaggedProductCount) {
            $share = CostBreakdownCalculator::shareOfDays($row['entry']->days, $totalDays);
            $row['share'] = $share;
            $derived = CostBreakdownCalculator::rowForShare($share, $poolAmounts);
            $dailyRate = CostBreakdownCalculator::tsaDailyRate($derived['total']);
            $dailyRatePerProduct = CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, $flaggedProductCount);
            // A grouped product row's own checkbox (the view's own) only
            // ever toggles the group's FIRST member — same "the group's
            // first member owns the edit" convention Expected Income's own
            // grouped cards already use — so gate on that same member here
            // too, rather than "any member flagged," to keep the checkbox
            // and the column's own value from ever disagreeing.
            $derived['products'] = $productRows
                ->filter(fn ($row) => $row['products']->first()->has_cost_allocation)
                ->pluck('label')
                ->mapWithKeys(fn ($label) => [$label => $dailyRatePerProduct])
                ->all();
            $row['derived'] = $derived;
            return $row;
        });

        // The bottom plain totals list — Salaries here is NOT a manual
        // CostBreakdownPool row; it's TOTAL SALARY OF TSD's own real number
        // (see totalSalaryOfTsd() below), always in sync with the top
        // section rather than a second, independently-typed figure that
        // could drift from it.
        $totalSalaryOfTsd = $this->totalSalaryOfTsd($salaryRows);
        $poolTotalsRow = $pools->reduce(function ($carry, CostBreakdownPool $pool) {
            $carry[$pool->key] = $pool->amount;
            return $carry;
        }, []);
        $grandTotal = array_sum($poolTotalsRow);

        return view('data.cost-breakdown', [
            'roles' => $roles,
            'salaryRows' => $salaryRows,
            'pools' => $pools,
            'productRows' => $productRows,
            'tsaRows' => $tsaRows,
            'totalDays' => $totalDays,
            'totalSalaryOfTsd' => $totalSalaryOfTsd,
            'poolTotalsRow' => $poolTotalsRow,
            'grandTotal' => $grandTotal,
            'rowGrandTotal' => $tsaRows->sum(fn ($row) => $row['derived']['total']),
            'flaggedProductCount' => $flaggedProductCount,
            'dailyRatePerProductByTsaId' => TsaDailyRateService::perProductByTsaId(),
            'dailyCostRow' => $dailyCostRow,
            'dailyCostPerProductRow' => $dailyCostPerProductRow,
        ]);
    }

    /** TOTAL SALARY OF TSD — sum of every real TSA's own freshly-computed
     *  TOTAL (base_salary + her applicable overhead refs — see
     *  CostBreakdownCalculator::tsaTotal()'s own doc comment), NOT her raw
     *  base_salary alone (explicit reversal, 2026-09-30, from the user's
     *  own formula-bar screenshot proving base_salary is her raw base
     *  only). Confirmed against the sheet's own raw CSV to exclude the
     *  CEO/Sales Director/Telesales Manager/QA Specialist/Junior AI
     *  Engineer/Supervisor rows entirely — those are shown on the same
     *  page for context, but this one figure is Telesales-ASSOCIATE-only,
     *  same "TSD" (Telesales Department staff, not the wider leadership/
     *  support team) framing the sheet's own label implies. $salaryRows is
     *  the SAME flat sequence the top section renders from — reused here
     *  rather than recomputed so this total can never drift from what's
     *  actually displayed. */
    private function totalSalaryOfTsd($salaryRows): float
    {
        return $salaryRows->where('type', 'tsa')->sum('total');
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

    /** Product count for the salary table's own "Daily Rate / Product"
     *  column (explicit request, 2026-09-30: "divided be all product like
     *  how many product in the cards") — same card count Expected Income's
    /** Every role's own freshly-recomputed overhead-per-TSA figure, keyed
     *  by role id — shared by updateRole() above since editing one role's
     *  base_salary can change every OTHER role in the same overhead_group's
     *  own displayed figure too (they all show the SAME summed-then-divided
     *  number, per the real sheet's own merged cell). */
    private function recomputeAllRoleOverhead(): array
    {
        return $this->overheadByRoleId(CostBreakdownRole::all())->all();
    }

    /** Every role's own overhead-per-TSA figure, keyed by role id — null
     *  for a role with no overhead_group at all (CostBreakdownRole::
     *  OVERHEAD_DIVISOR_COUNTS' own doc comment has the full formula).
     *  Shared by index() and recomputeAllRoleOverhead() so this app never
     *  computes it two different ways. */
    private function overheadByRoleId($roles)
    {
        $rolesByGroup = $roles->whereNotNull('overhead_group')->groupBy('overhead_group');

        return $roles->mapWithKeys(function (CostBreakdownRole $role) use ($rolesByGroup) {
            if (!$role->overhead_group) {
                return [$role->id => null];
            }

            $groupBaseSalaries = $rolesByGroup->get($role->overhead_group)->pluck('base_salary')->all();
            $tsaCount = CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS[$role->overhead_divisor] ?? 0;

            return [$role->id => CostBreakdownCalculator::overheadPerTsa($groupBaseSalaries, $tsaCount)];
        });
    }

    /** Every team's own applicable overhead refs for CostBreakdownCalculator
     *  ::tsaTotal() — the executive group's figure (company-wide), the
     *  support group's figure (company-wide), and THAT team's own
     *  Supervisor's figure, keyed by team. Confirmed a LIVE FORMULA from
     *  the user's own formula-bar screenshot, 2026-09-30:
     *  "=D5+D9+D12+C13" for Julie Francisco — every TSA on the SAME team
     *  sees the SAME 3 refs, only her own base_salary differs. */
    private function overheadRefsByTeam($roles, $overheadByRoleId)
    {
        $companyWideRefs = $roles->whereNull('team')->whereNotNull('overhead_group')
            ->unique('overhead_group')
            ->map(fn (CostBreakdownRole $role) => $overheadByRoleId->get($role->id))
            ->values()->all();

        return $roles->whereNotNull('team')->mapWithKeys(
            fn (CostBreakdownRole $supervisor) => [$supervisor->team => array_merge($companyWideRefs, [$overheadByRoleId->get($supervisor->id)])]
        );
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
     *  RAW base salary only (her own displayed TOTAL is computed fresh from
     *  base_salary + her applicable overhead refs — see
     *  CostBreakdownCalculator::tsaTotal()'s own doc comment). Days is the
     *  only field here that changes every OTHER TSA's own % share too (see
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

        $roles = CostBreakdownRole::all();
        $overheadRefs = $this->overheadRefsByTeam($roles, $this->overheadByRoleId($roles))->get($tsaShift->team) ?? [];
        $total = CostBreakdownCalculator::tsaTotal($entry->base_salary, $overheadRefs);
        $dailyRate = CostBreakdownCalculator::tsaDailyRate($total);

        // Daily Rate / Product = $dailyRate above ($total ÷ 24) split
        // across every CHECKED product — see TsaDailyRateService's own doc
        // comment. Recomputed fresh here (not reused from before this
        // save) since editing base_salary just moved $total/$dailyRate.
        $dailyRatePerProduct = TsaDailyRateService::perProductByTsaId()[$tsaShift->id] ?? 0.0;

        return response()->json([
            'success' => true,
            'total' => $total,
            'dailyRate' => $dailyRate,
            'dailyRatePerProduct' => $dailyRatePerProduct,
            'recomputed' => $this->recomputeAllTsaRows(),
        ]);
    }

    /** Auto-save for one product's own "has cost" checkbox (explicit
     *  request, 2026-09-30: "is it possible that can be select which
     *  product will be divided? ... user only can identify what product
     *  that has cost") — toggling it changes every REAL TSA's own Daily
     *  Rate / Product at once (the divisor itself changed), same "one
     *  shared value change, every dependent row refreshed" convention as
     *  updatePool()/updateTsaEntry() above, just for the product columns
     *  instead of the pool columns. */
    public function updateProductHasCostAllocation(Request $request, Product $product)
    {
        $data = $request->validate([
            'has_cost_allocation' => ['required', 'boolean'],
        ]);

        $product->update($data);

        return response()->json([
            'success' => true,
            'hasCostAllocation' => $product->has_cost_allocation,
            'recomputed' => $this->recomputeAllTsaRows(),
        ]);
    }

    /** Every real TSA's own freshly-recomputed % share + per-pool row +
     *  row TOTAL + product columns, keyed by tsa_id — shared by
     *  updatePool()/updateTsaEntry()/updateProductHasCostAllocation() above
     *  since any of those changes every row's own figures, not just the
     *  one just-saved cell's row. Also carries each TSA's own TOP salary
     *  table "Daily Rate / Product" figure under 'salaryDailyRatePerProduct'
     *  (explicit request, 2026-09-30: "i want it will auto to that changed
     *  like i should not reload whole page to reflect" — a product
     *  checkbox toggle changes the divisor for BOTH tables at once, but
     *  they're deliberately different SOURCE totals — see
     *  TsaDailyRateService's own doc comment — so this key is intentionally
     *  separate from 'derived.products' above, never conflated). */
    private function recomputeAllTsaRows(): array
    {
        $tsas = TsaShift::orderBy('team')->orderBy('sort_order')->get();
        $entriesByTsaId = CostBreakdownTsaEntry::whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');
        $poolAmounts = CostBreakdownPool::pluck('amount', 'key')->all();
        $flaggedProductRows = TsaDailyRateService::flaggedProductRows();
        $flaggedProductCount = $flaggedProductRows->count();
        $salaryDailyRatePerProductByTsaId = TsaDailyRateService::perProductByTsaId();

        $totalDays = $tsas->sum(fn (TsaShift $tsa) => ($entriesByTsaId->get($tsa->id)?->days) ?? 30);

        return $tsas->mapWithKeys(function (TsaShift $tsa) use ($entriesByTsaId, $totalDays, $poolAmounts, $flaggedProductRows, $flaggedProductCount, $salaryDailyRatePerProductByTsaId) {
            $entry = $entriesByTsaId->get($tsa->id);
            $days = $entry?->days ?? 30;
            $share = CostBreakdownCalculator::shareOfDays($days, $totalDays);
            $derived = CostBreakdownCalculator::rowForShare($share, $poolAmounts);
            $dailyRate = CostBreakdownCalculator::tsaDailyRate($derived['total']);
            $dailyRatePerProduct = CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, $flaggedProductCount);
            $derived['products'] = $flaggedProductRows->pluck('label')->mapWithKeys(fn ($label) => [$label => $dailyRatePerProduct])->all();

            return [$tsa->id => [
                'days' => $days,
                'share' => $share,
                'derived' => $derived,
                'salaryDailyRatePerProduct' => $salaryDailyRatePerProductByTsaId[$tsa->id] ?? 0.0,
            ]];
        })->all();
    }
}
