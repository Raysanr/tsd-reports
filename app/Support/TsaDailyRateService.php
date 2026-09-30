<?php

namespace App\Support;

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
 * drift from it. Same role/overhead/product-count plumbing
 * CostBreakdownController::index()/updateTsaEntry() already built —
 * duplicated here as a shared service rather than in either controller, so
 * neither page owns the other's formula.
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

    /** Same card count Expected Income's own product cards show
     *  (ProductGrouping::rows() merges a product GROUP into one combined
     *  card) — identical to CostBreakdownController::productCount()'s own
     *  doc comment. */
    private static function productCount(): int
    {
        $products = Product::orderBy('team')->orderBy('sort_order')->get();

        return ProductGrouping::rows($products, fn () => null)->count();
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
