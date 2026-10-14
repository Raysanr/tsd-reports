<?php

namespace App\Support;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Gross Sales / Number of Orders / Cancelled for TikTok-tagged orders
 *  (Order::tiktokCardKey()), per TSA, card and day — the automated source
 *  for Expected Income's TikTok cards and TSA Sales Report's TikTok Upsell
 *  rows (explicit request, 2026-10-14: "so the tiktok will be automated
 *  too"). Same ProductPerformance::tally() definitions every real product
 *  card uses (upsell_sales / upsell_confirmation / cancelled_upsell_sales),
 *  just scoped to TikTok-tagged orders — which every regular Data
 *  Management figure excludes (Order::withoutTiktokUpsells()), so each
 *  order is counted exactly once. */
class TiktokUpsellTotals
{
    public const FIELDS = ['gross_sales', 'number_of_orders', 'cancelled'];

    /** Keyed "{tsaId}:{cardKey}:{Y-m-d}" => ['gross_sales' => float,
     *  'number_of_orders' => int, 'cancelled' => float]. Only TSA/card/day
     *  combos with at least one TikTok-tagged order appear. */
    public static function byTsaCardAndDate(string $dateFrom, string $dateTo): Collection
    {
        $tsaIdByKey = TsaShift::pluck('id', 'tsa_key');

        return Order::whereRaw(
            'DATE(COALESCE(pancake_inserted_at, pancake_created_at)) BETWEEN ? AND ?',
            [$dateFrom, $dateTo]
        )->whereIn('tsa_name', $tsaIdByKey->keys())->get()
            ->filter(fn (Order $o) => Order::isTiktokUpsell($o))
            ->groupBy(fn (Order $o) => $tsaIdByKey->get($o->tsa_name) . ':' . Order::tiktokCardKey($o->raw_tags ?? [])
                . ':' . Carbon::parse($o->pancake_inserted_at ?? $o->pancake_created_at)->toDateString())
            ->map(function (Collection $orders) {
                $tally = ProductPerformance::tally($orders);

                return [
                    'gross_sales'      => (float) $tally['upsell_sales'],
                    'number_of_orders' => (int) $tally['upsell_confirmation'],
                    'cancelled'        => (float) $tally['cancelled_upsell_sales'],
                ];
            });
    }

    /** Sum of every day in the range for one TSA/card — zeros when none. */
    public static function forTsaAndCard(Collection $byKey, int $tsaId, string $cardKey, iterable $dates): array
    {
        $total = array_fill_keys(self::FIELDS, 0);
        foreach ($dates as $date) {
            $dateStr = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date;
            foreach ($byKey->get("{$tsaId}:{$cardKey}:{$dateStr}", []) as $field => $value) {
                $total[$field] += $value;
            }
        }

        return $total;
    }
}
