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
    /** Status codes that land an upsell in each non-delivered bucket. Anything
     *  else (Received, Collected money) is Delivered. Same codes tally() sums
     *  for cancelled_upsell_sales and ACTUAL_STATUS_BUCKETS. */
    private static function bucketCodes(): array
    {
        $buckets = ExpectedIncomeCalculator::ACTUAL_STATUS_BUCKETS;

        return [
            'cancelled'   => [6, 11],
            'unfulfilled' => $buckets['unfulfilled'],
            'fulfilled'   => $buckets['fulfilled'],
            'returns'     => $buckets['actual_returns'],
        ];
    }

    /** The real-upsell orders every amount here is summed over — tally()'s
     *  upsell_sales basis (Deleted and excluded sellers out, Order::isBroadRealUpsell),
     *  with TikTok upsells and hidden products removed first. */
    public static function realUpsells(Collection $orders): Collection
    {
        return ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($orders))
            ->reject(fn ($o) => $o->status_code === 7 || $o->excluded_upsell_seller)
            ->filter(fn ($o) => Order::isBroadRealUpsell($o))
            ->values();
    }

    /** Which bucket one real-upsell order falls in, by its current Pancake status. */
    private static function bucketOf(Order $order): string
    {
        foreach (self::bucketCodes() as $bucket => $codes) {
            if (in_array($order->status_code, $codes, true)) {
                return $bucket;
            }
        }

        return 'delivered';
    }

    /** @param Collection<int, Order> $orders already scoped to the TSAs/date range */
    public static function fromOrders(Collection $orders): array
    {
        $real = self::realUpsells($orders);
        $byBucket = $real->groupBy(fn (Order $o) => self::bucketOf($o));
        $sum = fn (Collection $os) => (float) $os->sum(fn (Order $o) => $o->realUpsellAmount());

        $split = ['gross_sales' => $sum($real)];
        foreach (array_keys(self::bucketCodes()) as $bucket) {
            $split[$bucket] = $sum($byBucket->get($bucket, collect()));
        }
        $split['delivered'] = $split['gross_sales'] - $split['cancelled'] - $split['unfulfilled'] - $split['fulfilled'] - $split['returns'];

        return $split;
    }

    /** The orders behind one of fromOrders()'s amounts ('returns', 'fulfilled' =
     *  Shipped, 'delivered', …) — same classifier, so the list sums to the cell. */
    public static function ordersInBucket(Collection $orders, string $bucket): Collection
    {
        return self::realUpsells($orders)->filter(fn (Order $o) => self::bucketOf($o) === $bucket)->values();
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
