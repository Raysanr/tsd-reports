@extends('layouts.data')
@section('title', 'Sales Summary (SH E-COM and Partners)')
@section('subtitle', 'SH E-COM and each partner, for the range and per day')

@section('content')
@php
    // Same look as Summary Sales Report (data/tsa-sales): title bar, date band,
    // accent money headers, rose lead headers, slate and black total rows.
    $money = 'bg-yellow-200 dark:bg-yellow-700 on-accent-700';
    $dayMoney = 'bg-yellow-100 dark:bg-yellow-800 on-accent-800';
    $slate = 'bg-slate-200 dark:bg-slate-600';
    $rose = 'bg-rose-200 dark:bg-rose-800';
    $columns = fn (bool $daily) => [
        ['key' => 'gross_sales', 'totalKey' => 'sales', 'label' => 'Sales', 'bg' => $daily ? $dayMoney : $money],
        ['key' => 'income_before_opex', 'label' => $daily ? 'Partners IB OPEX' : 'Partners IBSC', 'bg' => $daily ? $dayMoney : $money, 'partnerOnly' => true],
        ['key' => 'shared_income', 'label' => ($daily ? 'Expected Shared Income' : 'Shared Income') . " ({$sharePct}%)", 'bg' => $daily ? $dayMoney : $money, 'partnerOnly' => true],
        ['key' => 'net_income', 'label' => 'Net Income', 'bg' => $daily ? $dayMoney : $money, 'neg' => true],
        ['key' => 'ni_pct', 'label' => 'NI %', 'bg' => $slate, 'pct' => true, 'neg' => true],
        ['key' => 'total_orders', 'label' => 'Total Orders', 'bg' => $daily ? $dayMoney : $money, 'int' => true],
        ['key' => 'aov', 'label' => 'AOV', 'bg' => $daily ? $dayMoney : $money],
        ['key' => 'total_leads', 'label' => 'Total Leads', 'bg' => $rose, 'int' => true],
        ['key' => 'excess_leads', 'label' => 'Excess Leads', 'bg' => $rose, 'int' => true],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'bg' => $rose, 'pct' => true],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'bg' => $rose, 'pct' => true],
    ];
    $fmt = function (array $values, array $col, ?array $row = null) {
        if ($row && ($col['partnerOnly'] ?? false) && !$row['partner']) return '-';
        $v = (float) ($values[$row ? $col['key'] : ($col['totalKey'] ?? $col['key'])] ?? 0);
        if ($col['pct'] ?? false) return number_format($v * 100, 2) . '%';
        if ($col['int'] ?? false) return number_format($v);
        return number_format($v, 2);
    };
    $isNeg = fn (array $values, array $col) => ($col['neg'] ?? false) && (float) ($values[$col['key']] ?? 0) < 0;
    $cell = fn (array $values, array $col) => $isNeg($values, $col) ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100';
    $totalCell = fn (array $values, array $col) => $isNeg($values, $col) ? 'text-red-400' : '';

    $from = \Illuminate\Support\Carbon::parse($dateFrom);
    $to = \Illuminate\Support\Carbon::parse($dateTo);
    $rangeLabel = $from->format('M j') . ' – ' . $to->format('M j, Y');
    $rangeColumns = $columns(false);
    $dailyColumns = $columns(true);
    $lastColumn = count($dailyColumns) - 1;
@endphp

<style>
    .oss-sticky { position: sticky; left: 0; z-index: 5; }
    thead .oss-sticky { z-index: 25; }
    .oss-sticky-body { background-color: #fff; }
    .dark .oss-sticky-body { background-color: #0f172a; }
    tr:hover > .oss-sticky-body { background-color: #f8fafc; }
    .dark tr:hover > .oss-sticky-body { background-color: #1e293b; }
    .oss-sticky-footer { background-color: #000; }
    .oss-table { font-variant-numeric: tabular-nums; }
    .oss-table th, .oss-table td { border: none; border-bottom: 1px solid #cbd5e1; }
    .dark .oss-table th, .dark .oss-table td { border-bottom-color: #475569; }
    .oss-table .oss-day-end { border-right: 3px solid #334155; }
    .dark .oss-table .oss-day-end { border-right-color: #cbd5e1; }
    .tsr-dark-header-actions button { color: #f1f5f9; background-color: rgba(255,255,255,0.08); }
    .tsr-dark-header-actions button svg { stroke-width: 2.1; }
    .tsr-dark-header-actions button:hover { color: #fff; background-color: rgba(255,255,255,0.18); }
</style>

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    </form>
</div>

@include('data.overall._partners-warning')

{{-- Range totals --}}
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-8">
    <div class="bg-black text-white font-mono font-bold text-sm tracking-wide py-2.5 flex items-center">
        <span class="flex-1 text-center pl-18">{{ strtoupper($from->format('F Y')) }} PERFORMANCE</span>
        <div class="shrink-0 pr-2 tsr-dark-header-actions">
            @include('partials.table-actions', ['target' => 'overallSalesRange', 'name' => 'sales-summary-ecom-partners', 'title' => 'Sales Summary (SH E-COM and Partners)', 'subtitle' => $rangeLabel])
        </div>
    </div>
    <div class="bg-yellow-300 dark:bg-yellow-500 on-accent-500 text-center font-mono font-bold text-xs tracking-wide py-2 text-ink">{{ $rangeLabel }}</div>
    <div class="overflow-x-auto">
        <table class="w-full text-[13px] font-mono border-collapse oss-table" id="overallSalesRange">
            <thead>
                <tr class="text-ink dark:text-slate-100">
                    <th class="oss-sticky {{ $money }} text-left px-3 py-2 font-bold whitespace-nowrap">Department</th>
                    @foreach($rangeColumns as $col)<th class="{{ $col['bg'] }} text-right px-3 py-2 font-bold whitespace-nowrap">{{ $col['label'] }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                <tr class="odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    <td class="oss-sticky oss-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ $row['label'] }}</td>
                    @foreach($rangeColumns as $col)<td class="px-3 py-2 text-right whitespace-nowrap {{ $cell($row['range'], $col) }}">{{ $fmt($row['range'], $col, $row) }}</td>@endforeach
                </tr>
                @endforeach
                <tr class="bg-black text-white font-bold">
                    <td class="oss-sticky oss-sticky-footer px-3 py-2.5">OVERALL TOTAL</td>
                    @foreach($rangeColumns as $col)<td class="px-3 py-2.5 text-right whitespace-nowrap {{ $totalCell($total['range'], $col) }}">{{ $fmt($total['range'], $col) }}</td>@endforeach
                </tr>
            </tbody>
        </table>
    </div>
    <p class="px-3 py-2 text-xs font-mono text-ink-muted dark:text-slate-400">OVERALL TOTAL Sales counts SH E-COM only. Partner sales are shown for reference, and TSD's part of them is Shared Income.</p>
</div>

{{-- Per day, 7 days per table --}}
@foreach($dateChunks as $chunkIndex => $chunk)
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-6">
    <div class="bg-black text-white font-mono font-bold text-sm tracking-wide py-2.5 flex items-center">
        <span class="flex-1 text-center pl-18">TSA'S DAILY SALES REPORT</span>
        <div class="shrink-0 pr-2 tsr-dark-header-actions">
            @include('partials.table-actions', ['target' => 'overallSalesDaily-' . $chunkIndex, 'name' => 'sales-summary-daily-' . $chunk->first()->toDateString(), 'title' => "TSA's Daily Sales Report", 'subtitle' => $chunk->first()->format('M j') . ' – ' . $chunk->last()->format('M j, Y')])
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="text-[13px] font-mono border-collapse oss-table" id="overallSalesDaily-{{ $chunkIndex }}">
            <thead>
                <tr>
                    <th rowspan="2" class="oss-sticky bg-yellow-300 dark:bg-yellow-600 on-accent-600 text-left px-3 py-2 font-bold text-ink whitespace-nowrap align-bottom">Department</th>
                    @foreach($chunk as $date)
                    <th colspan="{{ count($dailyColumns) }}" class="bg-yellow-300 dark:bg-yellow-600 on-accent-600 text-center font-bold text-ink px-3 py-2 whitespace-nowrap oss-day-end">{{ $date->format('D, M j') }}</th>
                    @endforeach
                </tr>
                <tr>
                    @foreach($chunk as $date)
                        @foreach($dailyColumns as $i => $col)<th class="{{ $col['bg'] }} text-right px-3 py-2 font-bold whitespace-nowrap text-ink dark:text-slate-100 {{ $i === $lastColumn ? 'oss-day-end' : '' }}">{{ $col['label'] }}</th>@endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                <tr class="odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    <td class="oss-sticky oss-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ $row['label'] }}</td>
                    @foreach($chunk as $date)
                        @php($day = $row['days'][$date->toDateString()] ?? [])
                        @foreach($dailyColumns as $i => $col)<td class="px-3 py-2 text-right whitespace-nowrap {{ $cell($day, $col) }} {{ $i === $lastColumn ? 'oss-day-end' : '' }}">{{ $fmt($day, $col, $row) }}</td>@endforeach
                    @endforeach
                </tr>
                @endforeach
                <tr class="bg-black text-white font-bold">
                    <td class="oss-sticky oss-sticky-footer px-3 py-2.5">OVERALL TOTAL</td>
                    @foreach($chunk as $date)
                        @php($day = $total['days'][$date->toDateString()] ?? [])
                        @foreach($dailyColumns as $i => $col)<td class="px-3 py-2.5 text-right whitespace-nowrap {{ $totalCell($day, $col) }} {{ $i === $lastColumn ? 'oss-day-end' : '' }}">{{ $fmt($day, $col) }}</td>@endforeach
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endforeach
@endsection
