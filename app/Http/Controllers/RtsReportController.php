<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TsaShift;
use App\Support\Teams;
use Illuminate\Support\Carbon;

class RtsReportController extends Controller
{
    /** Pancake status counted as Delivered: 3 = Received by the customer. */
    private const DELIVERED_STATUS = 3;

    public function index()
    {
        $dateFrom = request('date_from', session('filters.rts_report.date_from', now()->format('Y-m-d')));
        \App\Models\TsaShift::showDeletedFrom($dateFrom); // removed TSAs still show for days up to their removal
        $dateTo   = request('date_to',   session('filters.rts_report.date_to', $dateFrom));

        session([
            'filters.rts_report.date_from' => $dateFrom,
            'filters.rts_report.date_to'   => $dateTo,
        ]);

        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->endOfDay();

        // Both teams shown side by side (matches the source report), not filtered to
        // one team at a time like Leads Report / TSA Performance.
        $teamTables = collect(Teams::config())->map(function ($teamConfig) use ($from, $to) {
            $shifts = TsaShift::where('team', $teamConfig['order_team'])
                ->orderBy('sort_order')->get();

            // Same order set and status rules as Actual Income (ActualSplit,
            // 2026-10-21 "make it all accurate"): every order of this team's
            // TSAs, RTS = Returning/Partial return/Returned, Delivered =
            // Received + Collected money.
            $byTsa = \App\Support\ActualSplit::ordersFor($shifts->pluck('tsa_key'), $from->toDateString(), $to->toDateString())->groupBy('tsa_name');
            $rows = $shifts->map(function ($shift) use ($byTsa) {
                $split = \App\Support\ActualSplit::fromOrders($byTsa->get($shift->tsa_key, collect()));
                $rtsAmount = $split['returns'];
                $deliveredAmount = $split['delivered'];
                $shippedOrders = \App\Support\ActualSplit::ordersInBucket($byTsa->get($shift->tsa_key, collect()), 'fulfilled');
                return [
                    'tsa_key'          => $shift->tsa_key,
                    'display_name'     => $shift->display_name,
                    'rts_amount'       => $rtsAmount,
                    'delivered_amount' => $deliveredAmount,
                    'shipped_qty'      => $shippedOrders->count(),
                    'shipped_amount'   => $split['fulfilled'],
                ];
            })->values();

            return [
                'team_key'        => $teamConfig['order_team'],
                // Dated (explicit follow-up request, 2026-09-04: "backtrack
                // the data like yesterday it is sh naturals and eyecare") —
                // scoped to $from/$to above.
                'name'            => Teams::nameForOrderTeamRange($teamConfig['order_team'], $from, $to),
                'rows'            => $rows,
                'total_rts'       => $rows->sum('rts_amount'),
                'total_delivered' => $rows->sum('delivered_amount'),
                'total_shipped_qty'    => $rows->sum('shipped_qty'),
                'total_shipped_amount' => $rows->sum('shipped_amount'),
            ];
        })->values();

        $grandTotalRts       = $teamTables->sum('total_rts');
        $grandTotalDelivered = $teamTables->sum('total_delivered');
        $grandShippedQty     = $teamTables->sum('total_shipped_qty');
        $grandShippedAmount  = $teamTables->sum('total_shipped_amount');

        return view('rts-report', compact(
            'dateFrom', 'dateTo', 'teamTables', 'grandTotalRts', 'grandTotalDelivered', 'grandShippedQty', 'grandShippedAmount'
        ));
    }

    /** Order IDs behind one RTS/Delivered cell — click-to-see popover. */
    public function drilldown(\Illuminate\Http\Request $request)
    {
        $dateFrom = $request->query('date_from');
        abort_if(!$dateFrom, 422);
        TsaShift::showDeletedFrom($dateFrom); // same roster as index(), so removed TSAs' orders still list
        $dateTo = $request->query('date_to', $dateFrom);
        $bucket = match ($request->query('column')) {
            'rts'     => 'returns',
            'shipped' => 'fulfilled',
            default   => 'delivered',
        };

        $shifts = TsaShift::query();
        if ($tsa = $request->query('tsa')) {
            $shifts->where('tsa_key', $tsa);
        } elseif (($team = $request->query('team')) && $team !== 'all') {
            $shifts->where('team', $team);
        } else {
            $shifts->whereIn('team', collect(Teams::config())->pluck('order_team')->all());
        }

        $orders = \App\Support\ActualSplit::ordersFor($shifts->pluck('tsa_key'), $dateFrom, $dateTo);

        return \App\Support\ActualSplit::ordersInBucket($orders, $bucket)
            ->sortByDesc(fn ($o) => $o->pancake_inserted_at ?? $o->pancake_created_at)
            ->values()
            ->map(fn ($o) => [
                'id'     => $o->pancake_order_id,
                'status' => ($o->status_label ?? "Unknown ({$o->status_code})") . ' · ₱' . number_format($o->realUpsellAmount(), 2),
                'time'   => Carbon::parse($o->pancake_inserted_at ?? $o->pancake_created_at)->format('M j, g:i A'),
            ]);
    }
}
