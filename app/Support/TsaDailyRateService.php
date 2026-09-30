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

    /** Same card count Expected Income's own product cards show
     *  (ProductGrouping::rows() merges a product GROUP into one combined
     *  card) — identical to CostBreakdownController::productCount()'s own
     *  doc comment. */
    public static function productCount(): int
    {
        $products = Product::orderBy('team')->orderBy('sort_order')->get();

        return ProductGrouping::rows($products, fn () => null)->count();
    }
}
