<?php

namespace App\Support;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\Product;
use App\Models\ProjectionColumn;
use App\Models\TsaShift;

/**
 * Shared "what's this TSA's own Daily Rate / Product, right now" lookup —
 * extracted from CostBreakdownController so Expected Income's own Salaries
 * row can read the exact same figure Cost Breakdown shows (explicit
 * request, 2026-09-30: "the salaries row is based to the Daily Rate /
 * Product"), never a second, independently-computed number that could
 * drift from it.
 *
 * Source TOTAL is the TOP "Salary Breakdown" table's own row TOTAL
 * (CostBreakdownCalculator::tsaTotal() — base_salary + every applicable
 * overhead ref), ÷ 24 = her own Daily Rate (e.g. Julie Francisco: 19,500 +
 * 10,341.13 + 4,833.33 + 5,714.29 = 40,388.75 ÷ 24 = 1,682.86), then split
 * across every CHECKED product. Reverted, 2026-09-30 ("revert this ...
 * 1,682.86 / product = 240.41"), from a same-day attempt to instead source
 * this from the BOTTOM "Cost Allocation Per TSA" table's own pool-share
 * row TOTAL (174.78) after that seemed to match the real sheet's own xlsx
 * cell formulas more closely — that read was wrong per explicit user
 * correction; this file's own base_salary+overhead source is the intended
 * one, not the sheet's.
 *
 * Only products with has_cost_allocation = true divide the cost (explicit
 * request, 2026-09-30: "user only can identify what product that has
 * cost") — matches the real sheet's own template, which only ever filled
 * in 7 of its product columns rather than dividing by every product
 * automatically. Toggled per-product on Cost Breakdown's own Cost
 * Allocation Per TSA table.
 */
class TsaDailyRateService
{
    /** Every real TSA's own Daily Rate / Product, keyed by tsa_id — her
     *  own TOTAL ÷ 24 ÷ checked-product count (e.g. 243.31). Used by her
     *  own "[TSA NAME]" overview card's own Salaries figure — same role
     *  Cost Breakdown's own Salary Breakdown table "Daily Rate / Product"
     *  column plays (explicit request, 2026-09-30: "the salaries row is
     *  based to the Daily Rate / Product"). Each individual PRODUCT card
     *  does NOT use this directly — see perProductByTsaIdTwice() below
     *  (explicit correction, 2026-10-01: "the 243.31 is the TSA card and
     *  in the products, it should be 243.31 / 7 like the other costs" —
     *  same two-tier pattern the pool rows already follow, dailyCostRow()
     *  → dailyCostPerProductRow()). Computed once per request (not per
     *  card) since it's the same figure for every one of a TSA's own
     *  cards on a given day. */
    public static function perProductByTsaId(): array
    {
        $dailyRateByTsaId = self::dailyRateByTsaId();
        $productCount = self::productCount();

        return collect($dailyRateByTsaId)
            ->map(fn (float $dailyRate) => CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, $productCount))
            ->all();
    }

    /** Same perProductByTsaId() figure above, split a SECOND time across
     *  every checked product (e.g. 243.31 ÷ 7 ≈ 34.76) — used by each
     *  individual PRODUCT card's own Salaries figure (explicit
     *  correction, 2026-10-01: "the 243.31 is the TSA card and in the
     *  products, it should be 243.31 / 7 like the other costs" — same
     *  two-tier division the pool rows already go through,
     *  dailyCostRow() → dailyCostPerProductRow()). */
    public static function perProductByTsaIdTwice(): array
    {
        $perProductByTsaId = self::perProductByTsaId();
        $productCount = self::productCount();

        return collect($perProductByTsaId)
            ->map(fn (float $perProduct) => CostBreakdownCalculator::tsaDailyRatePerProduct($perProduct, $productCount))
            ->all();
    }

    /** Every real TSA's own plain Daily Rate (her own TOTAL ÷ 24), keyed
     *  by tsa_id — NOT divided by product count at all. Building block
     *  for perProductByTsaId() above; not used directly by any Expected
     *  Income card itself. */
    public static function dailyRateByTsaId(): array
    {
        $roles = CostBreakdownRole::all();
        $overheadByRoleId = self::overheadByRoleId($roles);
        $overheadRefsByTeam = self::overheadRefsByTeam($roles, $overheadByRoleId);

        $tsas = TsaShift::all();
        $entriesByTsaId = CostBreakdownTsaEntry::whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');

        return $tsas->mapWithKeys(function (TsaShift $tsa) use ($entriesByTsaId, $overheadRefsByTeam) {
            $entry = $entriesByTsaId->get($tsa->id);
            $baseSalary = $entry?->base_salary ?? 0.0;
            $overheadRefs = $overheadRefsByTeam->get($tsa->team) ?? [];

            $total = CostBreakdownCalculator::tsaTotal($baseSalary, $overheadRefs);

            return [$tsa->id => CostBreakdownCalculator::tsaDailyRate($total)];
        })->all();
    }

    /** Every shared pool's own "Daily Cost" figure — pool amount ÷ real TSA
     *  count ÷ 24. NOT scoped to any specific TSA — see
     *  CostBreakdownCalculator::dailyCostRow()'s own doc comment for the
     *  confirmed-exact formula. Keyed by pool key (e.g.
     *  'communication_allowance'). Used by dailyCostPerProductRow() below
     *  as its own starting row — Expected Income's own locked Operating
     *  Costs rows source THAT method, not this one directly (see its doc
     *  comment). */
    public static function dailyCostRow(): array
    {
        $poolAmounts = CostBreakdownPool::pluck('amount', 'key')->all();

        return CostBreakdownCalculator::dailyCostRow($poolAmounts, TsaShift::count());
    }

    /** Same "Daily Cost" row from dailyCostRow() above, split further
     *  across every CHECKED (has_cost_allocation) product — used by Cost
     *  Breakdown's own "Daily Cost per product" table row AND by Expected
     *  Income's own locked Operating Costs rows (explicit correction,
     *  2026-09-30: "for example this / 13th Month Allowance / it should
     *  divided by number of product" — reverses a same-day earlier
     *  correction that had those rows source plain dailyCostRow() above
     *  instead, with no product division at all; that reading is now
     *  superseded). Salaries alone separately divides by product count
     *  too, via perProductByTsaId() above — the two rows share the same
     *  divisor by coincidence (both use productCount() below), not
     *  because either sources the other. */
    public static function dailyCostPerProductRow(): array
    {
        return CostBreakdownCalculator::dailyCostPerProductRow(self::dailyCostRow(), self::productCount());
    }

    /** Only products marked has_cost_allocation divide the cost (explicit
     *  request, 2026-09-30: "is it possible that can be select which
     *  product will be divided? ... user only can identify what product
     *  that has cost") — matches the real sheet's own template, which only
     *  ever filled in 7 of its product columns, leaving the rest blank
     *  rather than dividing by every product automatically. A product
     *  GROUP still counts as ONE card here (ProductGrouping::rows(), same
     *  as Expected Income's own product cards), but only if flagged. */
    public static function productCount(): int
    {
        return self::flaggedProductRows()->count();
    }

    /** Every flagged product's own display row, same shape
     *  ProductGrouping::rows() returns for Expected Income's cards —
     *  shared by CostBreakdownController so the Cost Allocation Per TSA
     *  table's own product COLUMNS are exactly this same flagged set, never
     *  a different list than what the divisor above actually counts. Groups
     *  EVERY product first (ProductGrouping::rows() needs every member
     *  present to resolve a group at all), then filters — a grouped row's
     *  own checkbox only ever toggles the group's FIRST member (same "the
     *  group's first member owns the edit" convention Expected Income's own
     *  grouped cards already use), so gating on that same member here keeps
     *  the checkbox and this list from ever disagreeing. */
    public static function flaggedProductRows()
    {
        $products = Product::orderBy('team')->orderBy('sort_order')->get();

        return ProductGrouping::rows($products, fn () => null)
            ->filter(fn ($row) => $row['products']->first()->has_cost_allocation)
            ->values();
    }

    /** Every real TSA's own Daily Tax figure, keyed by tsa_id — used by her
     *  own "[TSA NAME]" overview card's own LOCKED Tax Allocation line
     *  (explicit request, 2026-10-02: "the tax allocation in tsa cards is
     *  should be not editable" — same lock Salaries/the 20 shared pools
     *  already have on a TSA-scoped Expected Income card). Extracted from
     *  CostBreakdownController::taxFigures() so both pages read the exact
     *  same figure Cost Breakdown's own Salary Breakdown table shows in its
     *  "Monthly Tax"/"Daily Tax" columns, never a second independently-
     *  computed number.
     *
     *  Confirmed DIRECTLY against the real sheet's own formula bar,
     *  2026-10-02 — "Telesales Department"'s own Tax Allocation (Projections)
     *  splits evenly across the 2 shifts first, then each shift's own
     *  figure splits across THAT TEAM'S OWN real TSA count (dynamic — "when
     *  they add new tsa it will be 7"), ÷ 24 for the daily figure. Each
     *  individual PRODUCT card does NOT use this directly — see
     *  perProductTaxAllocationByTsaId() below (same two-tier "overview
     *  undivided, product divided again by product count" pattern
     *  Salaries/the pool rows already use). */
    public static function taxAllocationByTsaId(): array
    {
        $tsas = TsaShift::all();
        $perShift = self::departmentTaxAllocationPerShift();

        $tsaCountByTeam = $tsas->groupBy('team')->map->count();

        return $tsas->mapWithKeys(function (TsaShift $tsa) use ($perShift, $tsaCountByTeam) {
            $teamCount = $tsaCountByTeam->get($tsa->team, 0);
            $monthly = $teamCount > 0 ? $perShift / $teamCount : 0.0;

            return [$tsa->id => $monthly / 24];
        })->all();
    }

    /** Same taxAllocationByTsaId() figure above, split a SECOND time across
     *  every CHECKED product — used by each individual PRODUCT card's own
     *  Tax Allocation figure, same two-tier division the Salaries/pool rows
     *  already go through. */
    public static function perProductTaxAllocationByTsaId(): array
    {
        $productCount = self::productCount();

        return collect(self::taxAllocationByTsaId())
            ->map(fn (float $daily) => CostBreakdownCalculator::tsaDailyRatePerProduct($daily, $productCount))
            ->all();
    }

    /** Projections' own "Telesales Department" card's Tax Allocation line
     *  (Gross Sales × the shared tax_allocation rate, same figure the
     *  Expected Income P&L already calls "Tax Allocation"), split evenly
     *  across the 2 shifts (Opening Shift's own tab and Closing Shift's own
     *  tab both show the SAME figure — confirmed against 2 real sheet
     *  screenshots). Building block for taxAllocationByTsaId() above AND
     *  for CostBreakdownController's own Monthly Tax column (the 2
     *  Supervisor rows show this SAME raw per-shift figure, undivided by
     *  any TSA count at all). */
    public static function departmentTaxAllocationPerShift(): float
    {
        $columns = ProjectionColumn::orderBy('sort_order')->get();
        $rates = ProjectionCalculator::allRates();
        $all = ProjectionCalculator::forAllColumns($columns, $rates);

        $departmentTaxAllocation = $all['telesales_department']['pnl']['tax_allocation'] ?? 0.0;

        return $departmentTaxAllocation / 2;
    }

    /** Identical to CostBreakdownController::overheadByRoleId(). */
    private static function overheadByRoleId($roles)
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

    /** Identical to CostBreakdownController::overheadRefsByTeam(). */
    private static function overheadRefsByTeam($roles, $overheadByRoleId)
    {
        $companyWideRefs = $roles->whereNull('team')->whereNotNull('overhead_group')
            ->unique('overhead_group')
            ->map(fn (CostBreakdownRole $role) => $overheadByRoleId->get($role->id))
            ->values()->all();

        return $roles->whereNotNull('team')->mapWithKeys(
            fn (CostBreakdownRole $supervisor) => [$supervisor->team => array_merge($companyWideRefs, [$overheadByRoleId->get($supervisor->id)])]
        );
    }
}
