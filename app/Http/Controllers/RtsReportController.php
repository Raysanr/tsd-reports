<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;

class RtsReportController extends Controller
{
    /** Pancake status counted as Delivered: 3 = Received by the customer. */
    private const DELIVERED_STATUS = 3;

    /**
     * Percentage helper for the rate columns below — null (rendered as "—")
     * rather than 0 when there's nothing to divide by, so an idle TSA/team
     * reads as "no data" instead of a misleading 0%.
     */
    private function rate(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 1) : null;
    }

    public function index()
    {
        $dateFrom = request('date_from', session('filters.rts_report.date_from', now()->format('Y-m-d')));
        $dateTo   = request('date_to',   session('filters.rts_report.date_to', $dateFrom));

        session([
            'filters.rts_report.date_from' => $dateFrom,
            'filters.rts_report.date_to'   => $dateTo,
        ]);

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();

        // Both teams shown side by side (matches the source report), not filtered to
        // one team at a time like Leads Report / TSA Performance.
        $teamTables = collect(config('teams', []))->map(function ($teamConfig) use ($from, $to) {
            $shifts = TsaShift::where('team', $teamConfig['order_team'])
                ->orderBy('sort_order')->get();

            $rows = $shifts->map(function ($shift) use ($teamConfig, $from, $to) {
                // Upsell-only, per the request — a TSA's RTS/Delivered numbers here
                // reflect just their upsell add-on sales, not the customer's whole order.
                $scoped = fn() => Order::where('team', $teamConfig['order_team'])
                    ->where('tsa_name', $shift->tsa_key)
                    ->whereBetween('pancake_created_at', [$from, $to]);

                // RTS reads from is_returned_upsell/returned_upsell_amount, NOT
                // is_upsell + status — Order::VOID_STATUSES forces is_upsell false for
                // any Returning/Returned order (same as Cancelled/Restocking/Deleted), so
                // that combination can never exist. These two columns exist specifically
                // to preserve "this was an upsell" and its true add-on-only amount for
                // that case (see SyncTodayOrders' $isReturnedUpsell).
                $rtsAmount = (float) $scoped()->where('is_returned_upsell', true)->sum('returned_upsell_amount');

                $deliveredAmount = (float) $scoped()
                    ->where('is_upsell', true)
                    ->where('status_code', self::DELIVERED_STATUS)
                    ->sum('amount');

                // Total Sales — every upsell this TSA has EVER placed in range,
                // not just the ones that have reached a final Delivered/RTS
                // outcome (so it's always >= rts_amount + delivered_amount;
                // the gap is whatever's still pending/in transit). Same
                // is_upsell/is_returned_upsell split as the two sums above —
                // 'amount' only holds the correct isolated add-on value while
                // is_upsell is true; once Pancake marks an order Returning/
                // Returned, VOID_STATUSES flips is_upsell to false and the
                // add-on value lives in returned_upsell_amount instead (see
                // the RTS comment above) — summing both scoped queries covers
                // every upsell regardless of which side of that flip it's on.
                // Cancelled upsells are excluded from both (is_upsell and
                // is_returned_upsell are both false for those), same as
                // every other "real upsell" total elsewhere in the app.
                $totalSales = $rtsAmount + (float) $scoped()->where('is_upsell', true)->sum('amount');

                return [
                    'display_name'     => $shift->display_name,
                    'rts_amount'       => $rtsAmount,
                    'delivered_amount' => $deliveredAmount,
                    'total_sales'      => $totalSales,
                    'act_del_rate'     => $this->rate($deliveredAmount, $totalSales),
                    'act_rts_rate'     => $this->rate($rtsAmount, $totalSales),
                    'run_del_rate'     => $this->rate($deliveredAmount, $deliveredAmount + $rtsAmount),
                ];
            })->values();

            $totalRts       = $rows->sum('rts_amount');
            $totalDelivered = $rows->sum('delivered_amount');
            $totalSales     = $rows->sum('total_sales');

            return [
                'name'            => $teamConfig['name'],
                'rows'            => $rows,
                'total_rts'       => $totalRts,
                'total_delivered' => $totalDelivered,
                'total_sales'     => $totalSales,
                // Team-level rates from the SUMMED amounts, not an average of
                // each TSA's own rate — averaging per-TSA percentages would
                // let a low-volume TSA's outlier rate skew the team figure
                // out of proportion to how much of the team's actual sales
                // they represent.
                'act_del_rate'    => $this->rate($totalDelivered, $totalSales),
                'act_rts_rate'    => $this->rate($totalRts, $totalSales),
                'run_del_rate'    => $this->rate($totalDelivered, $totalDelivered + $totalRts),
            ];
        })->values();

        $grandTotalRts       = $teamTables->sum('total_rts');
        $grandTotalDelivered = $teamTables->sum('total_delivered');
        $grandTotalSales     = $teamTables->sum('total_sales');
        $grandActDelRate     = $this->rate($grandTotalDelivered, $grandTotalSales);
        $grandActRtsRate     = $this->rate($grandTotalRts, $grandTotalSales);
        $grandRunDelRate     = $this->rate($grandTotalDelivered, $grandTotalDelivered + $grandTotalRts);

        return view('rts-report', compact(
            'dateFrom', 'dateTo', 'teamTables', 'grandTotalRts', 'grandTotalDelivered',
            'grandTotalSales', 'grandActDelRate', 'grandActRtsRate', 'grandRunDelRate'
        ));
    }
}
