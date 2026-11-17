<?php

namespace App\Support;

use App\Models\ActualIncomeOverride;
use App\Models\Order;
use App\Models\ProductCost;

/**
 * Actual Income — "automated but editable" Number of Orders, Product Cost and
 * Fulfillment Fee (explicit request, 2026-11-17).
 *
 * Automated values:
 * - Number of Orders: the real upsell count (already automated).
 * - Fulfillment Fee: Number of Orders × FULFILLMENT_FEE_PER_ORDER (already).
 * - Product Cost (SH E-COM): every real upsell order's own variation
 *   (Order::bundle_description = Pancake variation display_id) looked up on
 *   the Product Costs page. Partners keep the cost their own system syncs.
 *
 * Overrides are applied as *_adj deltas on the raw row (typed − automated),
 * never by replacing number_of_orders/product_cost themselves: the rollup
 * cards' gap-fill compares the real order totals against the pooled raw
 * rows' automated values, so replacing them would make the gap row cancel
 * the override out again. ExpectedIncomeCalculator::derive()/sum() add the
 * deltas back in.
 */
class ActualIncomeOverrides
{
    /** True when Product Cost is computed from the Product Costs list here
     *  (Actual Income on SH E-COM). */
    public static function automatesProductCost(): bool
    {
        return ExpectedIncomeCalculator::$actual && ! DataSet::isPartners();
    }

    private static function normalize(?string $text): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $text)));
    }

    /** @return array<string, float> normalized variation → cost */
    public static function costLookup(): array
    {
        return RequestMemo::remember('actual_income.product_cost_lookup.' . (DataSet::isPartners() ? 'p' : 't'), fn () => ProductCost::all()
            ->mapWithKeys(fn (ProductCost $c) => [self::normalize($c->variation) => (float) $c->cost])
            ->all());
    }

    /** One order's product cost — 0 when its variation isn't on the list. */
    public static function productCostOf(Order $order): float
    {
        return self::costLookup()[self::normalize($order->bundle_description)] ?? 0.0;
    }

    /** Product cost of every real upsell order in $orders (ActualSplit's
     *  basis — same order set as Gross Sales / Number of Orders). */
    public static function productCostOfOrders($orders): float
    {
        return (float) ActualSplit::realUpsells(collect($orders))->sum(fn (Order $o) => self::productCostOf($o));
    }

    /** Overrides touching a date range, keyed "productId:tsaId:Y-m". Actual
     *  Income shows whole months, so one override covers a TSA's product
     *  card for that month (stored on the month's first day). */
    public static function forRange(string $dateFrom, string $dateTo): \Illuminate\Support\Collection
    {
        if (! ExpectedIncomeCalculator::$actual) {
            return collect();
        }
        $from = substr($dateFrom, 0, 7) . '-01';

        return RequestMemo::remember("actual_income.overrides.{$from}.{$dateTo}", fn () => ActualIncomeOverride::whereNotNull('tsa_id')
            ->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $dateTo)->get()
            ->keyBy(fn (ActualIncomeOverride $o) => self::key($o->product_id, $o->tsa_id, $o->entry_date->format('Y-m'))));
    }

    public static function key(int $productId, int $tsaId, string $month): string
    {
        return $productId . ':' . $tsaId . ':' . substr($month, 0, 7);
    }

    /** The *_adj deltas that turn a month's automated totals ($base:
     *  number_of_orders, product_cost) into the typed values. */
    public static function deltas(array $base, ?ActualIncomeOverride $override): array
    {
        return array_intersect_key(self::apply($base, $override), array_flip(['number_of_orders_adj', 'product_cost_adj', 'fulfillment_fee_adj']));
    }

    /** Adds *_adj deltas to one raw row whose number_of_orders/product_cost
     *  are already the automated values. */
    public static function apply(array $row, ?ActualIncomeOverride $override): array
    {
        if ($override === null) {
            return $row;
        }
        $orders = (float) ($row['number_of_orders'] ?? 0);
        if ($override->number_of_orders !== null) {
            $row['number_of_orders_adj'] = $override->number_of_orders - $orders;
            $orders = $override->number_of_orders;
        }
        if ($override->product_cost !== null) {
            $row['product_cost_adj'] = $override->product_cost - (float) ($row['product_cost'] ?? 0);
        }
        if ($override->fulfillment_fee !== null && ! DataSet::isPartners()) {
            $row['fulfillment_fee_adj'] = $override->fulfillment_fee - $orders * ExpectedIncomeCalculator::FULFILLMENT_FEE_PER_ORDER;
        }

        return $row;
    }
}
