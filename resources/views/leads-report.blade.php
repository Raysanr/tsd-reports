@extends('layouts.app')
@section('title', 'Leads Report')
@section('subtitle', 'Orders · Per-Product Hourly Breakdown')

@section('content')
{{-- $rangeLabel comes from the controller: "Last 24h · Jul 9 5:30PM → Jul 10 5:30PM"
     in rolling mode, or the plain date / "from → to" span in fixed-dates mode. --}}

{{-- PER-PRODUCT HOURLY BREAKDOWN — one table per product (matches the source
     sheet: a separate CANPRO/GINSENG/SINUXYL/AUDICURE tab each), replacing the old
     team-wide Hourly Breakdown + By Disposition panels with the full disposition/
     rate breakdown per product, per hour. --}}
@php
    $answeredCols   = collect($metricCols)->where('group', 'answered');
    $unansweredCols = collect($metricCols)->where('group', 'unanswered');

    // Disposition pie charts (grand total + one per product), matching the
    // source sheet's per-tab chart — built from the exact same
    // $grandTotal/$table['total'] rows already on the page, no extra query.
    // green = answered, orange/amber = unanswered, rose = excess; zero-value
    // slices are skipped, same as the table cells (`?: ''`) already do.
    $dispositionColors = [
        'confirmed_via_call'     => '#16a34a',
        'upsell_confirmation'    => '#15803d',
        'call_back'              => '#4ade80',
        'call_dropped'           => '#86efac',
        'repeat_order_upsell'    => '#059669',
        'rude_customer'          => '#10b981',
        'relatives_confirmation' => '#34d399',
        'dfr'                    => '#f59e0b',
        'double_order'           => '#fb923c',
        'fsd_uncleared'          => '#fbbf24',
        'not_answering'          => '#f97316',
        'unattended'             => '#fdba74',
        'invalid_number'         => '#fcd34d',
        'excess'                 => '#e11d48',
    ];
    $dispositionLabels = collect($metricCols)->pluck('label', 'key')
        ->map(fn($label) => strip_tags(str_replace('<br>', ' ', $label)));

    $buildChartData = function (array $row) use ($dispositionColors, $dispositionLabels) {
        $labels = $data = $colors = [];
        foreach ($dispositionColors as $key => $color) {
            if (($row[$key] ?? 0) > 0) {
                $labels[] = $dispositionLabels[$key];
                $data[]   = $row[$key];
                $colors[] = $color;
            }
        }
        return compact('labels', 'data', 'colors');
    };

    $chartsData = [];
    if ($grandTotal['total'] > 0) {
        $chartsData[] = ['id' => 'grandTotalChart', 'chart' => $buildChartData($grandTotal)];
    }
    foreach ($productTables as $i => $table) {
        if (empty($table['hourlyRows'])) continue;
        $chartsData[] = ['id' => 'productChart-' . $i, 'chart' => $buildChartData($table['total'])];
    }
@endphp

{{-- GRAND TOTAL — all products combined, whole range. One tally over every order
     (not a sum of the per-product totals below, which would double-count orders
     matching multiple product keywords and drop ones matching none). --}}
@if($grandTotal['total'] > 0)
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Grand Total — All Products</h2>
        <div class="flex items-center gap-3">
            <span class="text-xs font-mono text-slate-400">{{ $grandTotal['total'] }} {{ \Illuminate\Support\Str::plural('lead', $grandTotal['total']) }}</span>
            @include('partials.table-actions', ['target' => 'grandTotalTable', 'name' => 'grand-total-' . $selectedTeam])
        </div>
    </div>
    <div class="flex flex-col lg:flex-row">
    <div class="overflow-x-auto flex-1 min-w-0" id="grandTotalTable">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1300px">
        <thead>
            <tr>
                <th rowspan="2" class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap" style="min-width:110px">Time</th>
                <th rowspan="2" class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">New<br>Leads</th>
                <th rowspan="2" class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">Called<br>Leads</th>
                <th colspan="{{ $answeredCols->count() }}" class="bg-green-200 dark:bg-green-900/60 border border-slate-200 dark:border-slate-700 px-3 py-2 text-center text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide">Answered Called Leads</th>
                <th colspan="{{ $unansweredCols->count() }}" class="bg-red-200 dark:bg-red-900/60 border border-slate-200 dark:border-slate-700 px-3 py-2 text-center text-[11px] font-bold text-red-900 dark:text-red-200 uppercase tracking-wide">Unanswered Call Leads</th>
                <th rowspan="2" class="bg-rose-300 dark:bg-rose-900/70 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide whitespace-nowrap" style="min-width:80px">Excess<br>Leads</th>
                <th rowspan="2" class="bg-blue-100 dark:bg-blue-900/50 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-blue-900 dark:text-blue-200 uppercase tracking-wide leading-tight" style="min-width:90px">Pick-up<br>Rate</th>
                <th rowspan="2" class="bg-orange-100 dark:bg-orange-900/50 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 dark:text-orange-200 uppercase tracking-wide leading-tight" style="min-width:90px">Conversion<br>Rate</th>
                <th rowspan="2" class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 dark:text-yellow-200 uppercase tracking-wide leading-tight" style="min-width:90px">Upselling<br>Rate</th>
            </tr>
            <tr>
                @foreach($answeredCols as $col)
                <th class="bg-green-50 dark:bg-green-950/40 border border-slate-200 dark:border-slate-700 px-2 py-2 text-center text-[10px] font-semibold text-green-800 dark:text-green-400 uppercase tracking-wide leading-tight" style="min-width:{{ $col['min_width'] }}px">{!! $col['label'] !!}</th>
                @endforeach
                @foreach($unansweredCols as $col)
                <th class="bg-red-50 dark:bg-red-950/40 border border-slate-200 dark:border-slate-700 px-2 py-2 text-center text-[10px] font-semibold text-red-800 dark:text-red-400 uppercase tracking-wide leading-tight" style="min-width:{{ $col['min_width'] }}px">{!! $col['label'] !!}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($grandTotalHourlyRows as $hour)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">{{ $hour['label'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total'] ?: '' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total_called'] ?: '' }}</td>
                @foreach($answeredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }}">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                @foreach($unansweredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center text-slate-700 dark:text-slate-200">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['excess'] ? 'text-rose-700 dark:text-rose-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['excess'] ?: '' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['pick_up_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['pick_up_rate'] !== null ? $hour['row']['pick_up_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['conversion_rate'] !== null ? 'text-orange-700 dark:text-orange-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['conversion_rate'] !== null ? $hour['row']['conversion_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['upselling_rate'] !== null ? 'text-yellow-700 dark:text-yellow-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['upselling_rate'] !== null ? $hour['row']['upselling_rate'].'%' : '—' }}</td>
            </tr>
            @endforeach

            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['total'] ?: '' }}</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['total_called'] ?: '' }}</td>
                @foreach($answeredCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }}">{{ $grandTotal[$col['key']] ?: '' }}</td>
                @endforeach
                @foreach($unansweredCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center">{{ $grandTotal[$col['key']] ?: '' }}</td>
                @endforeach
                <td class="border border-slate-700 px-2 py-3 text-center text-rose-300">{{ $grandTotal['excess'] ?: '' }}</td>
                <td class="border border-slate-700 px-2 py-3 text-center text-blue-300">{{ $grandTotal['pick_up_rate'] !== null ? $grandTotal['pick_up_rate'].'%' : '—' }}</td>
                <td class="border border-slate-700 px-2 py-3 text-center text-orange-300">{{ $grandTotal['conversion_rate'] !== null ? $grandTotal['conversion_rate'].'%' : '—' }}</td>
                <td class="border border-slate-700 px-2 py-3 text-center text-yellow-300">{{ $grandTotal['upselling_rate'] !== null ? $grandTotal['upselling_rate'].'%' : '—' }}</td>
            </tr>
        </tbody>
    </table>
    </div>

    {{-- Disposition breakdown pie — matches the source sheet's per-tab chart.
         Built client-side (data-rerun script below) from this same $grandTotal
         data already on the page, not a separate query. --}}
    <div class="shrink-0 w-full lg:w-72 border-t lg:border-t-0 lg:border-l border-slate-100 dark:border-slate-700 p-4 flex flex-col items-center justify-center">
        <canvas id="grandTotalChart" width="260" height="220"></canvas>
    </div>
    </div>
</div>
@endif

@forelse($productTables as $table)
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $table['product']->display_name }}</h2>
        <div class="flex items-center gap-3">
            <span class="text-xs font-mono text-slate-400">{{ $table['total']['total'] }} {{ \Illuminate\Support\Str::plural('lead', $table['total']['total']) }}</span>
            @include('partials.table-actions', ['target' => 'productTable-' . $loop->index, 'name' => \Illuminate\Support\Str::slug($table['product']->display_name)])
        </div>
    </div>

    @if(empty($table['hourlyRows']))
    <div class="py-12 text-center font-mono text-xs text-slate-400">No leads for {{ $rangeLabel }}</div>
    @else
    <div class="flex flex-col lg:flex-row">
    <div class="overflow-x-auto flex-1 min-w-0" id="productTable-{{ $loop->index }}">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1300px">
        <thead>
            <tr>
                <th rowspan="2" class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap" style="min-width:110px">Time</th>
                <th rowspan="2" class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">New<br>Leads</th>
                <th rowspan="2" class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">Called<br>Leads</th>
                <th colspan="{{ $answeredCols->count() }}" class="bg-green-200 dark:bg-green-900/60 border border-slate-200 dark:border-slate-700 px-3 py-2 text-center text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide">Answered Called Leads</th>
                <th colspan="{{ $unansweredCols->count() }}" class="bg-red-200 dark:bg-red-900/60 border border-slate-200 dark:border-slate-700 px-3 py-2 text-center text-[11px] font-bold text-red-900 dark:text-red-200 uppercase tracking-wide">Unanswered Call Leads</th>
                <th rowspan="2" class="bg-rose-300 dark:bg-rose-900/70 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide whitespace-nowrap" style="min-width:80px">Excess<br>Leads</th>
                <th rowspan="2" class="bg-blue-100 dark:bg-blue-900/50 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-blue-900 dark:text-blue-200 uppercase tracking-wide leading-tight" style="min-width:90px">Pick-up<br>Rate</th>
                <th rowspan="2" class="bg-orange-100 dark:bg-orange-900/50 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 dark:text-orange-200 uppercase tracking-wide leading-tight" style="min-width:90px">Conversion<br>Rate</th>
                <th rowspan="2" class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 dark:text-yellow-200 uppercase tracking-wide leading-tight" style="min-width:90px">Upselling<br>Rate</th>
            </tr>
            <tr>
                @foreach($answeredCols as $col)
                <th class="bg-green-50 dark:bg-green-950/40 border border-slate-200 dark:border-slate-700 px-2 py-2 text-center text-[10px] font-semibold text-green-800 dark:text-green-400 uppercase tracking-wide leading-tight" style="min-width:{{ $col['min_width'] }}px">{!! $col['label'] !!}</th>
                @endforeach
                @foreach($unansweredCols as $col)
                <th class="bg-red-50 dark:bg-red-950/40 border border-slate-200 dark:border-slate-700 px-2 py-2 text-center text-[10px] font-semibold text-red-800 dark:text-red-400 uppercase tracking-wide leading-tight" style="min-width:{{ $col['min_width'] }}px">{!! $col['label'] !!}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($table['hourlyRows'] as $hour)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">{{ $hour['label'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total'] ?: '' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total_called'] ?: '' }}</td>
                @foreach($answeredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }}">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                @foreach($unansweredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center text-slate-700 dark:text-slate-200">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['excess'] ? 'text-rose-700 dark:text-rose-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['excess'] ?: '' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['pick_up_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['pick_up_rate'] !== null ? $hour['row']['pick_up_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['conversion_rate'] !== null ? 'text-orange-700 dark:text-orange-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['conversion_rate'] !== null ? $hour['row']['conversion_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['upselling_rate'] !== null ? 'text-yellow-700 dark:text-yellow-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['upselling_rate'] !== null ? $hour['row']['upselling_rate'].'%' : '—' }}</td>
            </tr>
            @endforeach

            {{-- TOTAL row --}}
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $table['total']['total'] ?: '' }}</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $table['total']['total_called'] ?: '' }}</td>
                @foreach($answeredCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }}">{{ $table['total'][$col['key']] ?: '' }}</td>
                @endforeach
                @foreach($unansweredCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center">{{ $table['total'][$col['key']] ?: '' }}</td>
                @endforeach
                <td class="border border-slate-700 px-2 py-3 text-center text-rose-300">{{ $table['total']['excess'] ?: '' }}</td>
                <td class="border border-slate-700 px-2 py-3 text-center text-blue-300">{{ $table['total']['pick_up_rate'] !== null ? $table['total']['pick_up_rate'].'%' : '—' }}</td>
                <td class="border border-slate-700 px-2 py-3 text-center text-orange-300">{{ $table['total']['conversion_rate'] !== null ? $table['total']['conversion_rate'].'%' : '—' }}</td>
                <td class="border border-slate-700 px-2 py-3 text-center text-yellow-300">{{ $table['total']['upselling_rate'] !== null ? $table['total']['upselling_rate'].'%' : '—' }}</td>
            </tr>
        </tbody>
    </table>
    </div>

    {{-- Disposition breakdown pie — matches the source sheet's per-product tab
         chart. Built client-side (data-rerun script below) from this same
         $table['total'] data already on the page, not a separate query. --}}
    <div class="shrink-0 w-full lg:w-72 border-t lg:border-t-0 lg:border-l border-slate-100 dark:border-slate-700 p-4 flex flex-col items-center justify-center">
        <canvas id="productChart-{{ $loop->index }}" width="260" height="220"></canvas>
    </div>
    </div>
    @endif
</div>
@empty
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 text-center font-mono text-xs text-slate-400 mb-6">
    No products configured for {{ $teams[$selectedTeam] ?? $selectedTeam }}.
</div>
@endforelse

{{-- ORDERS TABLE --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">
                Orders — {{ $teams[$selectedTeam] ?? $selectedTeam }}
            </h2>
            <p class="text-xs text-slate-400 mt-0.5 font-mono">{{ $rangeLabel }}</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="text" data-table-filter="ordersTable" placeholder="Filter…" aria-label="Filter orders"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <span class="text-xs font-mono text-slate-400">{{ $currentOrders->count() }} records</span>
            @include('partials.table-actions', ['target' => 'ordersTable', 'name' => 'orders-' . $selectedTeam])
        </div>
    </div>

    @if($currentOrders->isEmpty())
    <div class="py-24 flex flex-col items-center justify-center text-center gap-4">
        <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
        <div>
            <p class="text-sm font-semibold font-mono text-slate-500 dark:text-slate-400">No orders found</p>
            <p class="text-xs font-mono text-slate-400 mt-1">Try a different date or run <code class="bg-slate-100 dark:bg-slate-700 px-1 rounded">php artisan pancake:sync</code></p>
        </div>
    </div>
    @else
    {{-- Bounded height + own scroll (both axes) so a long/wide order list scrolls
         inside this card (with a sticky header) instead of stretching the whole
         page — makes it obvious there's more to scroll through. overflow-auto, not
         just overflow-y: this table's 7 columns (Order ID/Time/TSA/Product/
         Disposition/Status/Amount) genuinely overflow a phone-width viewport, and
         this was the one table on this page missing the horizontal scroll its
         sibling product tables above already have (see their overflow-x-auto +
         min-width). A single scrolling ancestor for both axes also keeps the
         sticky header working, same reasoning as TSA Performance's pivot table. --}}
    <div class="overflow-auto" style="max-height:60vh" id="ordersTable" data-sortable-table>
    <table class="w-full text-sm" style="min-width:640px">
        <thead class="sticky top-0 z-10">
            <tr class="bg-slate-50 dark:bg-slate-800 text-xs font-mono text-slate-400 uppercase tracking-wide shadow-sm">
                <th class="px-5 py-2.5 text-left" data-sort-key="orderId">Order ID</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="time">Time</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="tsa">TSA</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="product">Product</th>
                <th class="px-4 py-2.5 text-left" data-sort-key="disposition">Disposition</th>
                {{-- Status is a badge with no natural sort order (Delivered/RTS/Pending
                     aren't ranked) — left unsortable, same reasoning as the Excess
                     column's "highlight" boolean elsewhere in this app. --}}
                <th class="px-4 py-2.5 text-left">Status</th>
                <th class="px-4 py-2.5 text-right" data-sort-key="amount">Amount</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($currentOrders as $order)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors {{ $order->is_void_status ? 'opacity-60' : '' }}">
                <td class="px-5 py-3 font-mono text-xs text-primary font-semibold" data-sort-key="orderId" data-sort-value="{{ $order->pancake_order_id }}">#{{ $order->pancake_order_id }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400" data-sort-key="time" data-sort-value="{{ $order->effective_created_at?->format('Y-m-d H:i:s') }}">
                    {{ $order->effective_created_at?->format('h:i A') ?? '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-200" data-sort-key="tsa" data-sort-value="{{ $order->tsa_name ?? '' }}">{{ $order->tsa_name ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-400" data-sort-key="product" data-sort-value="{{ $order->product ?? '' }}">{{ $order->product ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400" data-sort-key="disposition" data-sort-value="{{ $order->disposition ?? '' }}">{{ $order->disposition ?? '—' }}</td>
                <td class="px-4 py-3">
                    @if($order->status_label)
                    <span @class([
                        'inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold font-mono whitespace-nowrap',
                        'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-400' => $order->status_code === 11,
                        'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400'     => $order->is_void_status && $order->status_code !== 11,
                        'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400' => !$order->is_void_status,
                    ])>
                        {{ $order->status_label }}
                    </span>
                    @else
                    <span class="text-xs text-slate-300 dark:text-slate-600">—</span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs font-semibold text-right {{ $order->is_void_status ? 'text-slate-400' : 'text-accent' }}" data-sort-key="amount" data-sort-value="{{ $order->amount }}">
                    ₱{{ number_format($order->amount, 2) }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>

@endsection

@if(!empty($chartsData))
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
{{-- data-rerun: app.js's softRefresh re-executes this after swapping <main> in
     place (see charts.blade.php for the full explanation of this pattern) —
     the canvases these target live inside the swapped region, and re-running
     picks up fresh @json data on every filter change. --}}
<script data-rerun>
(function () {
    const charts = @json($chartsData);

    const rootStyles = getComputedStyle(document.documentElement);
    const labelColor = (rootStyles.getPropertyValue('--chart-label') || '').trim() || '#94a3b8';

    charts.forEach(({ id, chart }) => {
        const el = document.getElementById(id);
        if (!el || chart.data.length === 0) return;

        new Chart(el, {
            type: 'pie',
            data: {
                labels: chart.labels,
                datasets: [{ data: chart.data, backgroundColor: chart.colors, borderWidth: 1 }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 9, family: "'Fira Code', monospace" }, color: labelColor },
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.label}: ${ctx.raw} (${pct}%)`;
                            },
                        },
                    },
                },
            },
        });
    });
})();
</script>
@endpush
@endif

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

@if($mode === 'last24h' || ($dateFrom === $dateTo && $dateFrom === now('Asia/Manila')->format('Y-m-d')))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('leads-report') }}" class="flex items-center gap-3 flex-wrap">
    {{-- Hidden fallbacks so applying the date picker (or any submit besides clicking
         a team button directly) doesn't drop the currently selected team or window
         mode. The picker's Apply flips this range field to 'dates' (explicit dates
         chosen); the Last 24h button below overrides it back as the submitter. --}}
    <input type="hidden" name="team" value="{{ $selectedTeam }}">
    <input type="hidden" name="range" value="{{ $mode }}">

    <div class="flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}" data-filter-btn
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200 motion-reduce:transition-none
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- Explicit escape hatch back to the rolling window — now that mode persists
         in session (see LeadsReportController), this is the only way back to Last
         24h once a picked date range has stuck. Only shown when it'd do something:
         already-last24h has nothing to reset. Relies on the same last-wins hidden-
         field override as the team buttons above (this button's own name="range"
         is later in the DOM than the hidden fallback field, so it wins on submit). --}}
    @if($mode !== 'last24h')
    <button type="submit" name="range" value="last24h" title="Reset to Last 24h" aria-label="Reset to rolling last 24 hours"
            class="inline-flex items-center justify-center w-8 h-8 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-full hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
    </button>
    @endif

    {{-- Trailing cluster, same order on every report page: filters, then the date
         icon, then Sync — never split across the layout differently per page. --}}
    @include('partials.date-picker', [
        'mode' => 'range', 'id' => 'drp',
        'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
        'submit' => 'form',
    ])

    @include('partials.filter-presets', ['key' => 'leads-report', 'baseUrl' => route('leads-report')])

    <button type="submit" title="Sync" aria-label="Sync orders"
            class="inline-flex items-center justify-center w-8 h-8 bg-yellow-700 hover:bg-yellow-800 text-white rounded-full transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
    </button>
</form>

</div>
@endpush
