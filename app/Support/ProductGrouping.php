<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;

/**
 * DSPPR - TSM Report / Expected Income 2026 — shared "display rows" logic
 * for product combining (explicit request, 2026-09-26). Both report pages
 * need the exact same transformation: take the real Product list, replace
 * every grouped product with ONE combined display row per group (summing
 * whatever per-product data the caller already looked up), leave every
 * ungrouped product as its own row — so this lives here once rather than
 * being duplicated between DsPprReportController and
 * ExpectedIncomeController.
 */
class ProductGrouping
{
    /** Every display row for the given $products, in their existing sort
     *  order (a group's own position is wherever its FIRST member product
     *  would have sorted). $sumFn receives the list of real Products that
     *  make up one row (a single-item list for an ungrouped product) and
     *  must return that row's own already-derived figures — kept generic
     *  like this so DSPPR (sums DsPprEntry rows across a date range) and
     *  Expected Income (sums ExpectedIncomeEntry rows for a given day) can
     *  each plug in their own per-page derive/sum calculator without this
     *  class knowing about either one.
     *
     *  Returns a Collection of ['label' => string, 'products' => Collection<Product>,
     *  'group' => ?ProductGroup, 'derived' => mixed] — 'group' is null for
     *  an ungrouped row (nothing to ungroup), letting the view tell the
     *  two cases apart without checking products()->count(). */
    public static function rows(Collection $products, callable $sumFn): Collection
    {
        $groupsByProductId = ProductGroup::with('products')->get()
            ->flatMap(fn (ProductGroup $group) => $group->products->map(fn (Product $p) => [$p->id, $group]))
            ->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]]);

        $seenGroupIds = [];
        $rows = collect();

        foreach ($products as $product) {
            $group = $groupsByProductId->get($product->id);

            if ($group) {
                if (in_array($group->id, $seenGroupIds, true)) {
                    continue; // Already emitted this group's row from an earlier member product.
                }
                $seenGroupIds[] = $group->id;

                // Only the group's OWN members that are still in $products
                // (e.g. still matching the current team filter, if any) —
                // a member filtered out elsewhere shouldn't silently
                // resurrect itself inside a group's own sum.
                $memberIds = $group->products->pluck('id');
                $groupProducts = $products->whereIn('id', $memberIds)->values();

                $rows->push([
                    'label'    => $group->label,
                    'products' => $groupProducts,
                    'group'    => $group,
                    'derived'  => $sumFn($groupProducts),
                ]);
            } else {
                $rows->push([
                    'label'    => $product->display_name,
                    'products' => collect([$product]),
                    'group'    => null,
                    'derived'  => $sumFn(collect([$product])),
                ]);
            }
        }

        return $rows;
    }
}
