<?php

namespace App\Support;

use App\Models\Order;
use App\Models\PerformanceSnapshot;
use App\Models\Product;
use App\Models\Scopes\TsaRosterScope;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;

/**
 * Freezes every TSA's numbers for one month into performance_snapshots.
 * Leads/called/upsells/sales use the Dashboard leaderboard's rules
 * (ProductPerformance::tsaRows, COALESCE date basis, all teams, hidden
 * products dropped); delivered/RTS use the RTS/Delivered report's rules
 * (upsell + Received / is_returned_upsell, order team = TSA team,
 * pancake_created_at). Re-running a month overwrites that month only.
 */
class PerformanceSnapshotter
{
    public static function generate(Carbon $month, ?int $userId = null): int
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        // Every TSA on the roster at any point during the month.
        $shifts = TsaShift::withoutGlobalScope(TsaRosterScope::class)
            ->where('created_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $from))
            ->orderBy('sort_order')->get();
        if ($shifts->isEmpty()) {
            return 0;
        }

        $orders = ProductPerformance::withoutHiddenProductOrders(
            Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
                ->whereIn('team', collect(config('teams'))->pluck('order_team')->all())
                ->get(),
            Product::orderBy('sort_order')->get()
        );
        $tallies = ProductPerformance::tsaRows($orders, $shifts);

        $base = fn () => Order::whereIn('tsa_name', $shifts->pluck('tsa_key'))
            ->whereBetween('pancake_created_at', [$from, $to])
            ->groupBy('tsa_name', 'team');
        $delivered = $base()->where('is_upsell', true)->where('status_code', LiveTsaBoard::DELIVERED_STATUS)
            ->selectRaw('tsa_name, team, SUM(amount) as total')->get();
        $rts = $base()->where('is_returned_upsell', true)
            ->selectRaw('tsa_name, team, SUM(returned_upsell_amount) as total')->get();
        $sumFor = fn ($rows, TsaShift $s) => (float) $rows->where('tsa_name', $s->tsa_key)->where('team', $s->team)->sum('total');

        foreach ($shifts as $shift) {
            $t = $tallies->get($shift->tsa_key, []);
            // whereDate, not updateOrCreate's exact match: the date cast stores
            // "Y-m-d 00:00:00" on SQLite, so an exact '2026-09-01' lookup misses
            // and the unique index then rejects the re-insert.
            $snapshot = PerformanceSnapshot::where('tsa_shift_id', $shift->id)->whereDate('month', $from->toDateString())->first()
                ?? new PerformanceSnapshot(['tsa_shift_id' => $shift->id, 'month' => $from->toDateString()]);
            $snapshot->fill([
                    'tsa_name'        => $shift->display_name ?: $shift->tsa_key,
                    'team'            => $shift->team ? Teams::nameForOrderTeam($shift->team, $to) : null,
                    'leads'           => (int) ($t['total'] ?? 0),
                    'called'          => (int) ($t['total_called'] ?? 0),
                    'upsells'         => (int) ($t['upsell_confirmation'] ?? 0),
                    'upsell_sales'    => (float) ($t['upsell_sales'] ?? 0),
                    'conversion_rate' => $t['conversion_rate'] ?? null,
                    'delivered'       => $sumFor($delivered, $shift),
                    'rts'             => $sumFor($rts, $shift),
                    'generated_by'    => $userId,
                ])->save();
        }

        return $shifts->count();
    }
}
