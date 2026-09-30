<?php

namespace App\Support;

use App\Models\CostBreakdownPool;
use App\Models\CostBreakdownRole;
use App\Models\CostBreakdownTsaEntry;
use App\Models\Product;
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
    /** Every real TSA's own Daily Rate / Product, keyed by tsa_id — the
     *  single call site Expected Income's controller needs, computed once
     *  per request (not per card) since it's the same figure for every one
     *  of a TSA's own product cards on a given day. */
    public static function perProductByTsaId(): array
    {
        $roles = CostBreakdownRole::all();
        $overheadByRoleId = self::overheadByRoleId($roles);
        $overheadRefsByTeam = self::overheadRefsByTeam($roles, $overheadByRoleId);
        $productCount = self::productCount();

        $tsas = TsaShift::all();
        $entriesByTsaId = CostBreakdownTsaEntry::whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');

        return $tsas->mapWithKeys(function (TsaShift $tsa) use ($entriesByTsaId, $overheadRefsByTeam, $productCount) {
            $entry = $entriesByTsaId->get($tsa->id);
            $baseSalary = $entry?->base_salary ?? 0.0;
            $overheadRefs = $overheadRefsByTeam->get($tsa->team) ?? [];

            $total = CostBreakdownCalculator::tsaTotal($baseSalary, $overheadRefs);
            $dailyRate = CostBreakdownCalculator::tsaDailyRate($total);

            return [$tsa->id => CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, $productCount)];
        })->all();
    }

    /** Every shared pool's own "Daily Cost" figure — pool amount ÷ ONE
     *  TEAM's own real TSA count ÷ 24, NOT divided further by product
     *  count (explicit correction, 2026-09-30: "it should be DAILY COST
     *  ROW will reflect no change of label" — Expected Income's own
     *  locked Operating Costs rows source THIS row, never
     *  CostBreakdownCalculator::dailyCostPerProductRow(), which Salaries
     *  alone still gets via perProductByTsaId() above; Cost Breakdown's
     *  own page-wide "Daily Cost per product" mini-table calls
     *  CostBreakdownCalculator::dailyCostRow()/dailyCostPerProductRow()
     *  directly instead of through this service, deliberately unscoped by
     *  team — see CostBreakdownController::index()'s own doc comment).
     *  $team is a real order_team value (e.g.
     *  CostBreakdownController's own $teamConfig['order_team']) — the
     *  divisor is THAT team's own TsaShift::where('team', $team)->count(),
     *  NOT the site-wide TsaShift::count() across every team (explicit
     *  correction, 2026-09-30: "the Operating Costs of per product is
     *  divided by number of tsa of which team ... Operating Costs of
     *  gemma so the product cards is the operating costs is divided by 6
     *  because they are 6 in the team" — reverses the earlier "same figure
     *  on every TSA's own card" reading; that screenshot happened to show
     *  two TSAs on the SAME team, so the figures matched only coincidentally).
     *  Keyed by pool key (e.g. 'communication_allowance'), same keys
     *  ExpectedIncomeCalculator::OPERATING_COST_ROWS uses. */
    public static function dailyCostRow(string $team): array
    {
        $poolAmounts = CostBreakdownPool::pluck('amount', 'key')->all();
        $teamTsaCount = TsaShift::where('team', $team)->count();

        return CostBreakdownCalculator::dailyCostRow($poolAmounts, $teamTsaCount);
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
