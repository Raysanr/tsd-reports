<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;

class RtsReportController extends Controller
{
    /** Pancake status counted as Delivered: 3 = Received by the customer. */
    private const DELIVERED_STATUS = 3;

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

                return [
                    'display_name'     => $shift->display_name,
                    'rts_amount'       => $rtsAmount,
                    'delivered_amount' => $deliveredAmount,
                ];
            })->values();

            return [
                'name'            => $teamConfig['name'],
                'rows'            => $rows,
                'total_rts'       => $rows->sum('rts_amount'),
                'total_delivered' => $rows->sum('delivered_amount'),
            ];
        })->values();

        $grandTotalRts       = $teamTables->sum('total_rts');
        $grandTotalDelivered = $teamTables->sum('total_delivered');

        return view('rts-report', compact(
            'dateFrom', 'dateTo', 'teamTables', 'grandTotalRts', 'grandTotalDelivered'
        ));
    }
}
