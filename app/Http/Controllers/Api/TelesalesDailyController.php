<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TsaShift;
use App\Support\ActualSplit;
use App\Support\ProductPerformance;
use App\Support\Teams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GET /api/external/v1/telesales/daily?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Read-only daily Telesales (TSD, SH E-COM teams) figures for other
 * departments, built from the same definitions the app's own pages use:
 * - leads: Leads Report's lead count (Deleted / excluded sellers out,
 *   hidden products out), by COALESCE(inserted, created) date.
 * - orders / grossSales / cancelledSales / deliveredSales / returnedSales:
 *   ActualSplit (RTS Report, Actual Income) — real upsells, by order date,
 *   split by each order's CURRENT Pancake status.
 * - catered: Leads Report's Catered Leads (leads with a call result).
 * - agents: the same per TSA.
 */
class TelesalesDailyController extends Controller
{
    private const MAX_DAYS = 93;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return response()->json(['error' => 'Range too long (max ' . self::MAX_DAYS . ' days)'], 422);
        }

        $orderTeams = collect(Teams::config())->pluck('order_team')->all();
        TsaShift::showDeletedFrom($data['from']);
        $tsas = TsaShift::whereIn('team', $orderTeams)->get()->keyBy('tsa_key');

        $orders = ProductPerformance::withoutHiddenProductOrders(Order::whereIn('team', $orderTeams)
            ->whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) BETWEEN ? AND ?', [$from, $to])
            ->get())
            ->groupBy(fn (Order $o) => $o->effective_created_at->format('Y-m-d'));

        $days = [];
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $dayOrders = $orders->get($key, collect());
            $split = ActualSplit::fromOrders($dayOrders);

            $leads = self::leads($dayOrders);
            $days[] = [
                'date'           => $key,
                'leads'          => $leads['total'],
                'leadsFromFsd'   => null,
                'catered'        => $leads['catered'],
                'orders'         => ActualSplit::realUpsells($dayOrders)->count(),
                'grossSales'     => round($split['gross_sales'], 2),
                'cancelledSales' => round($split['cancelled'], 2),
                'deliveredSales' => round($split['delivered'], 2),
                'returnedSales'  => round($split['returns'], 2),
                'byStatus'       => self::byStatus($dayOrders),
                'upsellSales'    => round($split['gross_sales'], 2),
                'agents'         => self::agents($dayOrders, $tsas),
            ];
        }

        return response()->json([
            'department' => 'Telesales',
            'from'       => $data['from'],
            'to'         => $data['to'],
            'timezone'   => config('app.timezone'),
            'currency'   => 'PHP',
            'days'       => $days,
        ]);
    }

    /** Leads Report's Total Leads and Catered Leads for these orders. */
    private static function leads(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return ['total' => 0, 'catered' => 0];
        }
        $tally = ProductPerformance::tally($orders);

        return ['total' => $tally['total'], 'catered' => $tally['catered']];
    }

    /** {status, orders, sales} for every status the day's real upsells are in. */
    private static function byStatus(Collection $orders): array
    {
        return ActualSplit::realUpsells($orders)
            ->groupBy('status_code')
            ->map(fn (Collection $group, $code) => [
                'status' => Order::STATUS_LABELS[$code] ?? (string) $code,
                'orders' => $group->count(),
                'sales'  => round((float) $group->sum(fn (Order $o) => $o->realUpsellAmount()), 2),
            ])
            ->sortByDesc('sales')
            ->values()
            ->all();
    }

    /** Per TSA: leads, catered, orders, grossSales, byStatus. Orders with no TSA are left out. */
    private static function agents(Collection $orders, Collection $tsas): array
    {
        return $orders->filter(fn (Order $o) => filled($o->tsa_name))
            ->groupBy('tsa_name')
            ->map(function (Collection $group, string $tsaKey) use ($tsas) {
                $split = ActualSplit::fromOrders($group);
                $leads = self::leads($group);

                return [
                    'tsa'        => $tsaKey,
                    'name'       => $tsas->get($tsaKey)?->display_name ?? $tsaKey,
                    'team'       => $tsas->get($tsaKey)?->team,
                    'leads'      => $leads['total'],
                    'catered'    => $leads['catered'],
                    'orders'     => ActualSplit::realUpsells($group)->count(),
                    'grossSales' => round($split['gross_sales'], 2),
                    'byStatus'   => self::byStatus($group),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
