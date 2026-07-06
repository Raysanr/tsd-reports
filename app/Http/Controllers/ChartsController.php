<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\HourFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChartsController extends Controller
{
    /**
     * "Accepted" = a lead that converted. orders.disposition stores the raw display
     * text (e.g. "CONFIRMED VIA CALL"), not the underscored keys from config/dispositions.php,
     * so we match against that raw text directly. Upsells are tracked separately via is_upsell.
     */
    private const ACCEPTED_DISPOSITIONS = [
        'CONFIRMED VIA CALL',
        'REPEAT ORDER WITH UPSELL STOCKS',
        'RELATIVES CONFIRMATION',
    ];

    public function index()
    {
        $selectedDate = request('date', now()->format('Y-m-d'));
        $date         = Carbon::parse($selectedDate);
        $dayRange     = [$date->copy()->startOfDay(), $date->copy()->endOfDay()];

        // --- Hourly line chart (Leads vs Accepted) ---
        $hourlyData = Order::whereBetween('pancake_created_at', $dayRange)
            ->selectRaw('HOUR(pancake_created_at) as hour, COUNT(*) as leads')
            ->groupByRaw('HOUR(pancake_created_at)')
            ->get()
            ->keyBy('hour');

        $acceptedData = Order::whereBetween('pancake_created_at', $dayRange)
            ->where(function ($q) {
                $q->whereIn(DB::raw('UPPER(disposition)'), self::ACCEPTED_DISPOSITIONS)
                  ->orWhere('is_upsell', true);
            })
            ->selectRaw('HOUR(pancake_created_at) as hour, COUNT(*) as accepted')
            ->groupByRaw('HOUR(pancake_created_at)')
            ->get()
            ->keyBy('hour');

        $hourlyLabels   = [];
        $hourlyLeads    = [];
        $hourlyAccepted = [];

        for ($h = 8; $h <= 21; $h++) {
            $hourlyLabels[]   = HourFormatter::label($h);
            $hourlyLeads[]    = (int) ($hourlyData->get($h)?->leads    ?? 0);
            $hourlyAccepted[] = (int) ($acceptedData->get($h)?->accepted ?? 0);
        }

        // --- Bar chart: sales per TSA (realized revenue only, is_upsell = true) ---
        $agentRows = Order::whereBetween('pancake_created_at', $dayRange)
            ->whereNotNull('tsa_name')
            ->where('is_upsell', true)
            ->selectRaw('tsa_name, SUM(amount) as total_sales')
            ->groupBy('tsa_name')
            ->orderByDesc('total_sales')
            ->get();

        $tsaConfig    = config('tsas', []);
        $displayNames = array_flip($tsaConfig);

        $agentNames = $agentRows->map(fn($r) => $displayNames[$r->tsa_name] ?? ucfirst($r->tsa_name))->values()->toArray();
        $agentSales = $agentRows->pluck('total_sales')->map(fn($v) => (float) $v)->values()->toArray();

        // --- Donut chart: group (team) sales (realized revenue only, is_upsell = true) ---
        // orders.team stores the literal team name (e.g. "SH Naturals"), not the config slug,
        // so group by that same literal value via each team's order_team.
        $groupRows = Order::whereBetween('pancake_created_at', $dayRange)
            ->whereNotNull('team')
            ->where('is_upsell', true)
            ->selectRaw('team, SUM(amount) as total_sales')
            ->groupBy('team')
            ->get()
            ->keyBy('team');

        $teams       = config('teams', []);
        $groupLabels = [];
        $groupSales  = [];

        foreach ($teams as $teamConfig) {
            $groupLabels[] = $teamConfig['name'];
            $groupSales[]  = (float) ($groupRows->get($teamConfig['order_team'])?->total_sales ?? 0);
        }

        return view('charts', compact(
            'hourlyLabels', 'hourlyLeads', 'hourlyAccepted',
            'agentNames', 'agentSales',
            'groupLabels', 'groupSales',
            'selectedDate'
        ));
    }
}
