<?php

namespace App\Support;

use App\Models\CostBreakdownPool;
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
 * Source TOTAL is the BOTTOM "Cost Allocation Per TSA" table's own row
 * TOTAL (CostBreakdownCalculator::rowForShare()'s own 'total' — the sum of
 * her % share of all 21 shared cost pools), confirmed against the real
 * sheet's own xlsx formulas (Y45=sum(D45:X45), Z45=Y45/24, AA45=Z45/7 for
 * Julie Francisco: pool-share sum 4,194.82 ÷ 24 = 174.78 ÷ 7 = 24.97,
 * matching exactly) — root-caused 2026-09-30 after an earlier version of
 * this file used tsaTotal() (the TOP "Salary Breakdown" table's own
 * base_salary + overhead-refs figure, e.g. 40,388.75 for Julie) instead,
 * which the real sheet's own formula never references at all for this
 * column. base_salary/overhead refs feed a COMPLETELY SEPARATE formula
 * chain (tsaTotal()) that has no bearing on this one.
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
        $productCount = self::productCount();
        $poolAmounts = CostBreakdownPool::pluck('amount', 'key')->all();

        $tsas = TsaShift::all();
        $entriesByTsaId = CostBreakdownTsaEntry::whereIn('tsa_id', $tsas->pluck('id'))->get()->keyBy('tsa_id');
        $totalDays = $tsas->sum(fn (TsaShift $tsa) => ($entriesByTsaId->get($tsa->id)?->days) ?? 30);

        return $tsas->mapWithKeys(function (TsaShift $tsa) use ($entriesByTsaId, $totalDays, $poolAmounts, $productCount) {
            $days = $entriesByTsaId->get($tsa->id)?->days ?? 30;
            $share = CostBreakdownCalculator::shareOfDays($days, $totalDays);
            $rowTotal = CostBreakdownCalculator::rowForShare($share, $poolAmounts)['total'];
            $dailyRate = CostBreakdownCalculator::tsaDailyRate($rowTotal);

            return [$tsa->id => CostBreakdownCalculator::tsaDailyRatePerProduct($dailyRate, $productCount)];
        })->all();
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
}
