<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * One definition of "where did the upsell sales end up" (explicit request,
 * 2026-10-21: "make it all accurate") — shared by Actual Income, RTS /
 * Delivered, Analytics' Delivered/RTS chart and Insights' RTS-rate cards so
 * they can never disagree again.
 *
 * Same order set and amounts as Summary Sales Report / Expected Income:
 * COALESCE(inserted, created) date, real upsells (ProductPerformance::
 * tally()'s upsell_sales basis), TikTok upsells and hidden products out.
 * Split by each order's CURRENT Pancake status
 * (ExpectedIncomeCalculator::ACTUAL_STATUS_BUCKETS):
 *   returns   = Returning, Partial return, Returned
 *   delivered = what's left after Cancelled & Waiting, Unfulfilled,
 *               Fulfilled and Returns = Received + Collected money
 */
class ActualSplit
{
    /** @param Collection<int, Order> $orders already scoped to the TSAs/date range */
    public static function fromOrders(Collection $orders): array
    {
        $tally = ProductPerformance::tally(
            ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders))
        );
        $split = [
            'gross_sales' => (float) $tally['upsell_sales'],
            'cancelled'   => (float) $tally['cancelled_upsell_sales'],
            'unfulfilled' => (float) $tally['unfulfilled_upsell_sales'],
            'fulfilled'   => (float) $tally['fulfilled_upsell_sales'],
            'returns'     => (float) $tally['actual_returns_upsell_sales'],
        ];
        $split['delivered'] = $split['gross_sales'] - $split['cancelled'] - $split['unfulfilled'] - $split['fulfilled'] - $split['returns'];

        return $split;
    }

    /** Orders of these TSA keys whose COALESCE(inserted, created) date is in range. */
    public static function ordersFor(Collection|array $tsaKeys, string $dateFrom, string $dateTo): Collection
    {
        return Order::whereRaw('DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?', [$dateFrom, $dateTo])
            ->whereIn('tsa_name', collect($tsaKeys)->all())->get();
    }

    public static function forTsaKeys(Collection|array $tsaKeys, string $dateFrom, string $dateTo): array
    {
        return self::fromOrders(self::ordersFor($tsaKeys, $dateFrom, $dateTo));
    }

    /** The order's own date on the same COALESCE basis (for per-day splits). */
    public static function dateOf(Order $o): string
    {
        return \Illuminate\Support\Carbon::parse($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString();
    }
}
