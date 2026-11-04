@extends('layouts.data')
@section('title', 'Sales Summary (SH E-COM and Partners)')
@section('subtitle', 'Department totals per day — Summary Sales Report figures')

@section('content')
@php
    $columns = [
        ['key' => 'gross_sales', 'label' => 'Gross Sales'],
        ['key' => 'net_income', 'label' => 'Net Income'],
        ['key' => 'ni_pct', 'label' => 'NI %', 'pct' => true],
        ['key' => 'total_orders', 'label' => 'Total Orders', 'int' => true],
        ['key' => 'aov', 'label' => 'AOV'],
        ['key' => 'catered_leads', 'label' => 'Catered Leads', 'int' => true],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'pct' => true],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'pct' => true],
    ];
    $fmt = function (array $values, array $col) {
        $v = (float) ($values[$col['key']] ?? 0);
        if ($col['pct'] ?? false) return number_format($v * 100, 2) . '%';
        if ($col['int'] ?? false) return number_format($v);
        return number_format($v, 2);
    };
    $cellClass = function (array $values, array $col) {
        return in_array($col['key'], ['net_income', 'ni_pct'], true) && (float) ($values[$col['key']] ?? 0) < 0 ? 'text-red-600 dark:text-red-400' : '';
    };
    $from = \Illuminate\Support\Carbon::parse($dateFrom);
    $to = \Illuminate\Support\Carbon::parse($dateTo);
    $rangeLabel = strtoupper($from->format('F j') . ($dateFrom === $dateTo ? '' : ' – ' . $to->format('F j')));
    $th = 'px-3 py-2 text-[11px] font-mono font-bold uppercase tracking-wide border border-line dark:border-slate-700 whitespace-nowrap';
    $td = 'px-3 py-2.5 text-right font-mono text-[13px] text-ink dark:text-slate-100 border border-line dark:border-slate-700 whitespace-nowrap';
    $labelCell = 'px-4 py-2.5 font-mono font-bold text-sm uppercase whitespace-nowrap border border-line dark:border-slate-700 bg-yellow-300 text-ink sticky left-0 z-10';
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    </form>
</div>

@include('data.overall._partners-warning')

{{-- Range totals --}}
<div class="flex items-center justify-between mb-2">
    <div class="font-mono font-bold text-sm text-ink dark:text-slate-100">{{ strtoupper($from->format('F Y')) }} PERFORMANCE</div>
    @include('partials.table-actions', ['target' => 'overallSalesRange', 'name' => 'sales-summary-ecom-partners', 'title' => 'Sales Summary (SH E-COM and Partners)', 'subtitle' => $rangeLabel, 'pngOnly' => true])
</div>
<div class="overflow-x-auto mb-8 bg-white dark:bg-slate-900 rounded-xl shadow-panel">
    <table class="border-collapse w-max" id="overallSalesRange">
        <thead>
            <tr>
                <th class="{{ $th }} bg-black text-white sticky left-0 z-10" rowspan="2">Department</th>
                <th class="{{ $th }} bg-yellow-300 text-ink text-center" colspan="{{ count($columns) }}">{{ $rangeLabel }}</th>
            </tr>
            <tr>
                @foreach($columns as $col)<th class="{{ $th }} bg-slate-100 dark:bg-slate-800 text-ink dark:text-slate-100">{{ $col['label'] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr>
                <td class="{{ $labelCell }}">{{ $row['label'] }}</td>
                @foreach($columns as $col)<td class="{{ $td }} {{ $cellClass($row['data']['range'], $col) }}">{{ $fmt($row['data']['range'], $col) }}</td>@endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Per day, 7 days per table --}}
@foreach($dateChunks as $chunkIndex => $chunk)
<div class="flex items-center justify-between mb-2">
    <div class="font-mono font-bold text-sm text-ink dark:text-slate-100">Daily Sales Report</div>
    @include('partials.table-actions', ['target' => 'overallSalesDaily-' . $chunkIndex, 'name' => 'sales-summary-daily-' . $chunk->first()->toDateString(), 'title' => 'Daily Sales Report (SH E-COM and Partners)', 'subtitle' => $chunk->first()->format('F j') . ($chunk->count() > 1 ? ' – ' . $chunk->last()->format('F j, Y') : ', ' . $chunk->first()->format('Y')), 'pngOnly' => true])
</div>
<div class="overflow-x-auto mb-8 bg-white dark:bg-slate-900 rounded-xl shadow-panel">
    <table class="border-collapse w-max" id="overallSalesDaily-{{ $chunkIndex }}">
        <thead>
            <tr>
                <th class="{{ $th }} bg-black text-white sticky left-0 z-10" rowspan="2">Department</th>
                @foreach($chunk as $date)
                <th class="{{ $th }} bg-yellow-300 text-ink text-center" colspan="{{ count($columns) }}">{{ $date->format('D, M j') }}</th>
                @endforeach
            </tr>
            <tr>
                @foreach($chunk as $date)
                    @foreach($columns as $col)<th class="{{ $th }} bg-slate-100 dark:bg-slate-800 text-ink dark:text-slate-100">{{ $col['label'] }}</th>@endforeach
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr>
                <td class="{{ $labelCell }}">{{ $row['label'] }}</td>
                @foreach($chunk as $date)
                    @php($day = $row['data']['days'][$date->toDateString()] ?? [])
                    @foreach($columns as $col)<td class="{{ $td }} {{ $cellClass($day, $col) }}">{{ $fmt($day, $col) }}</td>@endforeach
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endforeach
@endsection
