@extends('layouts.data')
@section('title', 'Sales Summary (SH E-COM and Partners)')
@section('subtitle', 'SH E-COM and each partner, for the range and per day')

@section('content')
@php
    // Header colors come from the sheet's SALES SUMMARY REPORT tab.
    $columns = fn (bool $daily) => [
        ['key' => 'sales', 'label' => 'Sales', 'bg' => '#d0e0e3', 'shOnly' => true],
        ['key' => 'income_before_opex', 'label' => $daily ? 'Partners IB.OPEX' : 'Partners IBSC', 'bg' => '#ead1dc', 'partnerOnly' => true],
        ['key' => 'shared_income', 'label' => ($daily ? 'Expected Shared Income' : 'Shared Income') . " ({$sharePct}%)", 'bg' => '#d0e0e3', 'partnerOnly' => true],
        ['key' => 'net_income', 'label' => 'Net Income', 'bg' => '#d0e0e3', 'neg' => true],
        ['key' => 'ni_pct', 'label' => 'NI %', 'bg' => '#d0e0e3', 'pct' => true, 'neg' => true],
        ['key' => 'total_orders', 'label' => 'Total Orders', 'bg' => '#d9d9d9', 'int' => true],
        ['key' => 'aov', 'label' => 'AOV', 'bg' => '#b7b7b7'],
        ['key' => 'total_leads', 'label' => 'Total Leads', 'bg' => '#e6b8af', 'int' => true],
        ['key' => 'excess_leads', 'label' => 'Excess Leads', 'bg' => '#e6b8af', 'int' => true],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'bg' => '#e6b8af', 'pct' => true],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'bg' => '#e6b8af', 'pct' => true],
    ];
    $fmt = function (array $values, array $col, ?array $row = null) {
        if ($row && (($col['shOnly'] ?? false) && $row['partner'] || ($col['partnerOnly'] ?? false) && !$row['partner'])) return '-';
        $v = (float) ($values[$col['key']] ?? 0);
        if ($col['pct'] ?? false) return number_format($v * 100, 2) . '%';
        if ($col['int'] ?? false) return number_format($v);
        return number_format($v, 2);
    };
    $isNeg = fn (array $values, array $col) => ($col['neg'] ?? false) && (float) ($values[$col['key']] ?? 0) < 0;

    $from = \Illuminate\Support\Carbon::parse($dateFrom);
    $to = \Illuminate\Support\Carbon::parse($dateTo);
    $rangeLabel = strtoupper($from->format('F j') . ($dateFrom === $dateTo ? '' : ' - ' . $to->format('F j')));
    $line = 'border border-black';
    $th = "px-3 py-2 text-[11px] font-mono font-bold uppercase text-center text-black whitespace-normal min-w-24 max-w-32 $line";
    $td = "px-3 py-2.5 text-center font-mono text-[13px] whitespace-nowrap bg-white text-black $line";
    $label = "px-4 py-2.5 font-mono font-bold text-base text-center uppercase whitespace-nowrap text-black sticky left-0 z-10 min-w-64 $line";
    $tot = "px-3 py-2.5 text-center font-mono font-bold text-sm whitespace-nowrap $line";
    $rowBg = fn (array $row) => $row['partner'] ? '#f3f3f3' : '#d0e0e3';
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    </form>
</div>

@include('data.overall._partners-warning')

{{-- Range totals --}}
@php($rangeColumns = $columns(false))
<div class="flex justify-end mb-2">
    @include('partials.table-actions', ['target' => 'overallSalesRange', 'name' => 'sales-summary-ecom-partners', 'title' => 'Sales Summary (SH E-COM and Partners)', 'subtitle' => $rangeLabel])
</div>
<div class="overflow-x-auto mb-8">
    <table class="border-collapse w-max" id="overallSalesRange">
        <thead>
            <tr>
                <th class="{{ $label }} text-2xl! bg-black text-white! py-4" rowspan="2">{{ strtoupper($from->format('F Y')) }}<br>PERFORMANCE</th>
                <th class="{{ $th }} max-w-none! bg-black text-white! text-sm!" colspan="{{ count($rangeColumns) }}">{{ $rangeLabel }}</th>
            </tr>
            <tr>
                @foreach($rangeColumns as $col)<th class="{{ $th }}" style="background: {{ $col['bg'] }}">{{ $col['label'] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr>
                <td class="{{ $label }}" style="background: {{ $rowBg($row) }}">{{ $row['label'] }}</td>
                @foreach($rangeColumns as $col)<td class="{{ $td }} {{ $isNeg($row['range'], $col) ? 'text-red-600!' : '' }}">{{ $fmt($row['range'], $col, $row) }}</td>@endforeach
            </tr>
            @endforeach
            <tr>
                <td class="{{ $label }} text-lg! bg-black text-white!">TOTAL :</td>
                @foreach($rangeColumns as $col)<td class="{{ $tot }} bg-black {{ $isNeg($total['range'], $col) ? 'text-red-400' : 'text-white' }}">{{ $fmt($total['range'], $col) }}</td>@endforeach
            </tr>
        </tbody>
    </table>
</div>

{{-- Per day, 7 days per table --}}
@php($dailyColumns = $columns(true))
@foreach($dateChunks as $chunkIndex => $chunk)
<div class="flex justify-end mb-2">
    @include('partials.table-actions', ['target' => 'overallSalesDaily-' . $chunkIndex, 'name' => 'sales-summary-daily-' . $chunk->first()->toDateString(), 'title' => "TSA's Daily Sales Report", 'subtitle' => $chunk->first()->format('M j') . ' - ' . $chunk->last()->format('M j')])
</div>
<div class="overflow-x-auto mb-8">
    <table class="border-collapse w-max" id="overallSalesDaily-{{ $chunkIndex }}">
        <thead>
            <tr>
                <th class="{{ $label }} normal-case! text-xl! bg-[#ffff00]" rowspan="2">TSA's Daily Sales Report</th>
                @foreach($chunk as $date)
                <th class="{{ $th }} max-w-none! bg-[#ffff00] text-sm!" colspan="{{ count($dailyColumns) }}">{{ $date->format('D, M j') }}</th>
                @endforeach
            </tr>
            <tr>
                @foreach($chunk as $date)
                    @foreach($dailyColumns as $col)<th class="{{ $th }}" style="background: {{ $col['bg'] }}">{{ $col['label'] }}</th>@endforeach
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr>
                <td class="{{ $label }}" style="background: {{ $rowBg($row) }}">{{ $row['label'] }}</td>
                @foreach($chunk as $date)
                    @php($day = $row['days'][$date->toDateString()] ?? [])
                    @foreach($dailyColumns as $col)<td class="{{ $td }} {{ $isNeg($day, $col) ? 'text-red-600!' : '' }}">{{ $fmt($day, $col, $row) }}</td>@endforeach
                @endforeach
            </tr>
            @endforeach
            <tr>
                <td class="{{ $label }} text-lg! bg-[#b7b7b7]">TOTAL:</td>
                @foreach($chunk as $date)
                    @php($day = $total['days'][$date->toDateString()] ?? [])
                    @foreach($dailyColumns as $col)<td class="{{ $tot }} bg-[#b7b7b7] {{ $isNeg($day, $col) ? 'text-red-700' : 'text-black' }}">{{ $fmt($day, $col) }}</td>@endforeach
                @endforeach
            </tr>
        </tbody>
    </table>
</div>
@endforeach
@endsection
