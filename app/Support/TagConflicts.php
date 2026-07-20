<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Finds the tag-conflict cases ProductPerformance::buildRow()'s guard silently
 * excludes from every product's counts — surfaced here for the tag-conflict
 * review queue, since excluding a mismatched order from the report is not the
 * same as anyone actually noticing and fixing it in Pancake POS.
 *
 * Deliberately built on ProductPerformance::conflictingProduct() (not a
 * reimplementation of it) so this page and the report counts can never
 * silently disagree about what counts as a conflict.
 */
class TagConflicts
{
    /** Every same-team product whose keyword matches one of the order's raw
     *  tags — the reverse lookup buildRow() never needs (it already knows
     *  which single product it's counting FOR) but the review queue does: it
     *  has to work out which product an order's tags point to in the first
     *  place, before checking that against the cart item. */
    public static function productsMatchingTags(Order $order, Collection $teamProducts): Collection
    {
        return $teamProducts->filter(function (Product $product) use ($order) {
            if ($product->team !== $order->team) return false;
            foreach ($order->raw_tags ?? [] as $tag) {
                if ($product->matchesText($tag)) return true;
            }
            return false;
        })->values();
    }

    /** Full tag-vs-cart-item conflict check for a single order. Returns null
     *  for the common case (no conflict) — either no product matches by tag
     *  at all, or the tag product's own keyword also matches the cart item
     *  (a normal, if redundant, tag).
     *
     * @return array{tagProduct: Product, cartProduct: Product}|null */
    public static function findConflict(Order $order, Collection $products): ?array
    {
        if ($order->team === null || $order->product === null) return null;

        $teamProducts = $products->where('team', $order->team)->values();

        foreach (self::productsMatchingTags($order, $teamProducts) as $tagProduct) {
            $cartProduct = ProductPerformance::conflictingProduct($tagProduct, $order, $teamProducts);
            if ($cartProduct) {
                return ['tagProduct' => $tagProduct, 'cartProduct' => $cartProduct];
            }
        }

        return null;
    }
}
