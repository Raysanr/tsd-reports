<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Unassigned leads alert (Dashboard, 2026-11-14). Leads that came in with
 * no TSA on the order: counted in Leads Report's and the API's totals but
 * owned by nobody (Nov 1: 124 of 267). Same lead set as those totals
 * (COALESCE(inserted, created) date, Deleted / excluded sellers / hidden
 * products out), split by why there's no TSA, using Call Tracker's lead
 * for the same Pancake order:
 * - waiting: still unassigned in Call Tracker — assign them there;
 * - notTagged: assigned in Call Tracker, but the TSA tag never reached
 *   the Pancake order (auto-tagging off or the tag failed);
 * - notInTracker: never reached Call Tracker (no product match, sync gap).
 */
class UnassignedLeads
{
    private const TOP_PRODUCTS = 5;

    public static function summary(Carbon $from, Carbon $to, array $orderTeams): array
    {
        $orders = ProductPerformance::withoutHiddenProductOrders(
            Order::whereIn('team', $orderTeams)
                ->whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
                ->where(fn ($q) => $q->whereNull('tsa_name')->orWhere('tsa_name', ''))
                ->where('status_code', '!=', 7)
                ->get()
        )->reject(fn (Order $o) => $o->excluded_upsell_seller)->values();

        $leads = $orders->isEmpty() ? collect() : Lead::whereIn('pancake_order_id', $orders->pluck('pancake_order_id'))
            ->get(['pancake_order_id', 'status', 'tsa_id'])
            ->keyBy('pancake_order_id');

        $reason = function (Order $o) use ($leads) {
            $lead = $leads->get($o->pancake_order_id);

            return match (true) {
                $lead === null                    => 'notInTracker',
                $lead->status === 'unassigned'
                    || $lead->tsa_id === null     => 'waiting',
                default                           => 'notTagged',
            };
        };
        $byReason = $orders->groupBy($reason);

        return [
            'total'        => $orders->count(),
            'waiting'      => $byReason->get('waiting', collect())->count(),
            'notTagged'    => $byReason->get('notTagged', collect())->count(),
            'notInTracker' => $byReason->get('notInTracker', collect())->count(),
            'byProduct'    => $orders->groupBy(fn (Order $o) => $o->product ?: 'Unknown product')
                ->map(fn ($group, $name) => ['product' => $name, 'count' => $group->count()])
                ->sortByDesc('count')->take(self::TOP_PRODUCTS)->values()->all(),
        ];
    }
}
