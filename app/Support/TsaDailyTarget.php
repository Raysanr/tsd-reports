<?php

namespace App\Support;

use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\ProjectionColumn;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;

/**
 * Daily Target countdown for one TSA (explicit request, 2026-11-27: "the TSA
 * target everyday gross sales is for example 14,000 ... every add upsell
 * product it will be subtract"). Decisions, same day: the TSA's OWN gross
 * sales, the target is Projections' "Individual TSA: Daily Target" Gross
 * Sales for her shift, an upsell counts at once ("pending") and is replaced
 * by the synced figure once Pancake confirms it.
 *
 * - confirmed: today's Gross Sales from synced orders — the same order set
 *   and tally() figure as the Summary Sales Report (LiveTsaBoard::
 *   salesAndNetIncome(), TikTok left out), so a cancelled/returned order
 *   drops out of it by itself and the remaining amount goes back up.
 * - pending: in-app upsells added today whose order has not been synced
 *   since (orders.synced_at earlier than the upsell, or not synced at all).
 */
class TsaDailyTarget
{
    /** null when this TSA has no Daily Target set for this month. */
    public static function for(TsaShift $tsa, ?Carbon $date = null): ?array
    {
        $date ??= today();
        $target = self::targetFor($tsa, $date);
        if ($target === null || $target <= 0) {
            return null;
        }

        $confirmed = self::confirmedSales($tsa, $date);
        $pending   = self::pendingSales($tsa, $date);
        $achieved  = $confirmed + $pending;

        return [
            'target'    => round($target, 2),
            'confirmed' => round($confirmed, 2),
            'pending'   => round($pending, 2),
            'achieved'  => round($achieved, 2),
            'remaining' => round(max(0, $target - $achieved), 2),
            'over'      => round(max(0, $achieved - $target), 2),
            'percent'   => (int) min(100, floor($achieved / $target * 100)),
            'hit'       => $achieved >= $target,
        ];
    }

    /** Projections' Gross Sales for "<shift> — Individual TSA: Daily Target"
     *  of $date's month. Team → shift by position: the first configured team
     *  (TEAM OPENING) is the Opening shift, the second (TEAM CLOSING) Closing. */
    public static function targetFor(TsaShift $tsa, Carbon $date): ?float
    {
        $shiftKey = self::shiftKeyFor($tsa);
        if ($shiftKey === null) {
            return null;
        }
        [, , $dailyKey] = ProjectionColumn::shiftRowKeys($shiftKey);
        $month = $date->format('Y-m');

        return RequestMemo::remember("tsa_daily_target.{$month}.{$dailyKey}", function () use ($month, $dailyKey) {
            $columns = ProjectionColumn::where('month', $month)->orderBy('sort_order')->get();
            if ($columns->isEmpty()) {
                return null;
            }

            return ProjectionMonth::with($month, function () use ($columns, $dailyKey) {
                $all = ProjectionCalculator::forAllColumns($columns, ProjectionCalculator::allRates());

                return isset($all[$dailyKey]) ? (float) $all[$dailyKey]['pnl']['gross_sales'] : null;
            });
        });
    }

    private static function shiftKeyFor(TsaShift $tsa): ?string
    {
        $slug = $tsa->team ? Teams::slugForOrderTeam($tsa->team) : null;
        if ($slug === null) {
            return null;
        }
        $index = array_search($slug, array_keys(config('teams', [])), true);

        return ProjectionColumn::shiftKeys()[$index] ?? null;
    }

    private static function confirmedSales(TsaShift $tsa, Carbon $date): float
    {
        $orders = Order::whereRaw('DATE(COALESCE(pancake_inserted_at, pancake_created_at)) = ?', [$date->toDateString()])
            ->where('tsa_name', $tsa->tsa_key)->get()
            ->pipe(fn ($o) => ProductPerformance::withoutHiddenProductOrders(Order::withoutTiktokUpsells($o)));

        return (float) ProductPerformance::tally($orders)['upsell_sales'];
    }

    private static function pendingSales(TsaShift $tsa, Carbon $date): float
    {
        $activities = LeadActivity::with('lead:id,tsa_id,pancake_order_id')
            ->where('type', 'upsell_added')->whereNotNull('amount')
            ->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->whereHas('lead', fn ($q) => $q->where('tsa_id', $tsa->id))
            ->get();
        if ($activities->isEmpty()) {
            return 0.0;
        }

        $syncedAt = Order::whereIn('pancake_order_id', $activities->pluck('lead.pancake_order_id')->filter()->unique())
            ->pluck('synced_at', 'pancake_order_id');

        return (float) $activities->filter(function (LeadActivity $a) use ($syncedAt) {
            $synced = $syncedAt->get($a->lead?->pancake_order_id);

            return $synced === null || Carbon::parse($synced)->lt($a->created_at);
        })->sum(fn (LeadActivity $a) => (float) $a->amount);
    }
}
