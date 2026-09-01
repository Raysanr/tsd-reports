@extends('layouts.app')
@section('title', 'Leads Report')
@section('subtitle', 'All products, all teams · ' . $rangeLabel)

@section('content')

@php
    // Snapshot-only date label — see leads-report.blade.php for why this uses
    // full month names instead of $rangeLabel's abbreviated form.
    $snapshotDateLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('F j, Y')
        . ($dateFrom === $dateTo ? '' : ' – ' . \Illuminate\Support\Carbon::parse($dateTo)->format('F j, Y'));

    // Disposition pie chart for the Grand Total row — same colors/labels/build
    // logic as leads-report.blade.php's own chart (kept in sync by hand since
    // these are separate blade files for the per-team vs. cross-team views;
    // see that file for the full reasoning on the color choices).
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
        ->map(fn($label) => strip_tags(str_replace(['-<br>', '<br>'], ['', ' '], $label)));

    // Answered/unanswered only (excludes 'excess') — Restocking and Excess Leads
    // are rendered as their own explicit cells after this loop, in that order,
    // matching leads-report.blade.php's own per-product/Grand Total tables.
    $answeredCols   = collect($metricCols)->where('group', 'answered');
    $unansweredCols = collect($metricCols)->where('group', 'unanswered');

    $chartsData = [];
    if ($grandTotal['total'] > 0) {
        $labels = $data = $colors = [];
        foreach ($dispositionColors as $key => $color) {
            if (($grandTotal[$key] ?? 0) > 0) {
                $labels[] = $dispositionLabels[$key];
                $data[]   = $grandTotal[$key];
                $colors[] = $color;
            }
        }
        $chartsData[] = ['id' => 'allGrandTotalChart', 'chart' => compact('labels', 'data', 'colors')];
    }
@endphp

{{-- ALL — one row per product, combined across every team, for the whole window
     (no hourly split). Moved here from TSA Performance's old "ALL" view, which now
     shows the per-TSA equivalent of this same table instead. --}}
@if($productRows->isEmpty())
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No products configured</p>
    <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Add products on the Product Management page.</p>
</div>
@else

<div class="flex items-center justify-end gap-3 mb-2">
    <input type="text" data-table-filter="productAllTable" placeholder="Filter…" aria-label="Filter products"
           class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    @include('partials.table-actions', ['target' => 'productAllTable', 'name' => 'leads-report-all', 'chart' => 'allGrandTotalChart', 'title' => 'Leads Report — All Products, All Teams', 'subtitle' => $snapshotDateLabel])
</div>

<div class="flex flex-col lg:flex-row gap-4">
<div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm flex-1 min-w-0" style="max-height:calc(100vh - 180px)" id="productAllTable" data-sortable-table data-scroll-shadow
     data-dd-team="all" data-dd-endpoint="{{ route('leads-report.drilldown') }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2" data-sort-key="product"
                    class="sticky-col bg-yellow-50 dark:bg-yellow-950 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:180px">
                    Product
                </th>
                <th rowspan="2" data-sort-key="total"
                    class="bg-yellow-50 dark:bg-yellow-950 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">
                    Total<br>Leads
                </th>
                <th rowspan="2" data-sort-key="catered"
                    class="bg-yellow-50 dark:bg-yellow-950 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">
                    Catered<br>Leads
                </th>
                <th colspan="7"
                    class="bg-green-200 dark:bg-green-900 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide">
                    Answered Called Leads
                </th>
                <th colspan="6"
                    class="bg-red-200 dark:bg-red-900 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-red-900 dark:text-red-200 uppercase tracking-wide">
                    Unanswered Call Leads
                </th>
                <th rowspan="2" data-sort-key="restocking"
                    class="bg-black border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-white uppercase tracking-wide whitespace-nowrap"
                    style="min-width:80px">
                    Restocking<br>Orders
                </th>
                <th colspan="1"
                    class="bg-rose-300 dark:bg-rose-900 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide">
                    Excess Leads
                </th>
                <th rowspan="2" data-sort-key="pickUpRate"
                    class="bg-blue-100 dark:bg-blue-900 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-blue-900 dark:text-blue-200 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Pick-up<br>Rate
                </th>
                <th rowspan="2" data-sort-key="conversionRate"
                    class="bg-orange-100 dark:bg-orange-900 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 dark:text-orange-200 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Conversion<br>Rate
                </th>
                <th rowspan="2" data-sort-key="upsellingRate"
                    class="bg-yellow-100 dark:bg-yellow-900 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 dark:text-yellow-200 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Upselling<br>Rate
                </th>
            </tr>
            <tr>
                @foreach($answeredCols->merge($unansweredCols) as $col)
                @php
                    $headerColor = $col['group'] === 'answered'
                        ? 'bg-green-50 dark:bg-green-950 text-green-800 dark:text-green-400'
                        : 'bg-red-50 dark:bg-red-950 text-red-800 dark:text-red-400';
                @endphp
                <th class="{{ $headerColor }} border border-slate-300 dark:border-slate-600 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
                    style="min-width:{{ $col['min_width'] }}px" data-sort-key="{{ $col['key'] }}">
                    {!! $col['label'] !!}
                </th>
                @endforeach
                <th class="bg-rose-50 dark:bg-rose-950 text-rose-800 dark:text-rose-400 border border-slate-300 dark:border-slate-600 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
                    style="min-width:80px" data-sort-key="excess">
                    Excess<br>Leads
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach($productRows as $row)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="sticky-col sticky-col-body border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-slate-700 dark:text-slate-200 whitespace-nowrap" data-sort-key="product" data-sort-value="{{ $row['display_name'] }}">
                    {{ $row['display_name'] }}
                    <div class="text-[10px] font-normal text-slate-400">{{ $row['team'] }}</div>
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100 {{ $row['total'] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="total" data-sort-value="{{ $row['total'] }}"
                    @if($row['total']) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" title="Click to see the orders behind this total" @endif>
                    {{ $row['total'] ?: '' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100 {{ $row['catered'] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="catered" data-sort-value="{{ $row['catered'] }}"
                    @if($row['catered']) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" data-dd-column="catered" title="Click to see the orders behind this total" @endif>
                    {{ $row['catered'] ?: '' }}
                </td>
                @foreach($answeredCols->merge($unansweredCols) as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }} {{ $row[$col['key']] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="{{ $col['key'] }}" data-sort-value="{{ $row[$col['key']] }}"
                    @if($row[$col['key']]) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" data-dd-column="{{ $col['key'] }}" title="Click to see the orders behind this total" @endif>
                    {{ $row[$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold text-slate-700 dark:text-slate-200 {{ $row['restocking'] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="restocking" data-sort-value="{{ $row['restocking'] }}"
                    @if($row['restocking']) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" data-dd-column="restocking" title="Click to see the orders behind this total" @endif>
                    {{ $row['restocking'] ?: '' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold text-rose-700 dark:text-rose-400 {{ $row['excess'] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="excess" data-sort-value="{{ $row['excess'] }}"
                    @if($row['excess']) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" data-dd-column="excess" title="Click to see the orders behind this total" @endif>
                    {{ $row['excess'] ?: '' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $row['pick_up_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}" data-sort-key="pickUpRate" data-sort-value="{{ $row['pick_up_rate'] ?? '' }}">
                    {{ $row['pick_up_rate'] !== null ? $row['pick_up_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $row['conversion_rate'] !== null ? 'text-orange-700 dark:text-orange-400' : 'text-slate-300 dark:text-slate-600' }}" data-sort-key="conversionRate" data-sort-value="{{ $row['conversion_rate'] ?? '' }}">
                    {{ $row['conversion_rate'] !== null ? $row['conversion_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $row['upselling_rate'] !== null ? 'text-yellow-700 dark:text-yellow-400' : 'text-slate-300 dark:text-slate-600' }}" data-sort-key="upsellingRate" data-sort-value="{{ $row['upselling_rate'] ?? '' }}">
                    {{ $row['upselling_rate'] !== null ? $row['upselling_rate'].'%' : '—' }}
                </td>
            </tr>
            @endforeach
        </tbody>
        {{-- Grand Total lives in tfoot, not tbody, so a client-side column sort
             (which only re-orders <tbody> rows — see app.js) never shuffles this
             row into the middle of the sorted list. --}}
        <tfoot>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="sticky-col sticky-col-footer border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['total'] ?: '' }}</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['catered'] ?: '' }}</td>
                @foreach($answeredCols->merge($unansweredCols) as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }}">
                    {{ $grandTotal[$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-700 px-2 py-3 text-center">
                    {{ $grandTotal['restocking'] ?: '' }}
                </td>
                <td class="border border-slate-700 px-2 py-3 text-center text-rose-300">
                    {{ $grandTotal['excess'] ?: '' }}
                </td>
                <td class="border border-slate-700 px-3 py-3 text-center text-blue-300">
                    {{ $grandTotal['pick_up_rate'] !== null ? $grandTotal['pick_up_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-700 px-3 py-3 text-center text-orange-300">
                    {{ $grandTotal['conversion_rate'] !== null ? $grandTotal['conversion_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-700 px-3 py-3 text-center text-yellow-300">
                    {{ $grandTotal['upselling_rate'] !== null ? $grandTotal['upselling_rate'].'%' : '—' }}
                </td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- Disposition breakdown pie for the Grand Total row — same pattern as
     leads-report.blade.php's own charts. --}}
@if($grandTotal['total'] > 0)
@include('partials.pie-chart-panel', ['id' => 'allGrandTotalChart'])
@endif
</div>

@endif
@endsection

@include('partials.disposition-pie-charts')

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

@if($mode === 'last24h' || ($dateFrom === $dateTo && $dateFrom === now('Asia/Manila')->format('Y-m-d')))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('leads-report') }}" class="flex items-center gap-3 flex-wrap">
    {{-- Hidden fallbacks so applying the date picker (or any submit besides clicking
         a team button directly) doesn't drop the currently selected window mode. --}}
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

    {{-- Explicit escape hatch back to the rolling window — see leads-report.blade.php
         for the full reasoning; mode now persists in session so this is the only way
         back to Last 24h once a picked date range has stuck. --}}
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

    <button type="submit" title="Sync" aria-label="Sync orders"
            class="inline-flex items-center justify-center w-8 h-8 bg-yellow-700 hover:bg-yellow-800 text-white rounded-full transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
            <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 01.359.852L12.982 9.75h7.268a.75.75 0 01.548 1.262l-10.5 11.25a.75.75 0 01-1.272-.71l1.992-7.302H3.75a.75.75 0 01-.548-1.262l10.5-11.25a.75.75 0 01.913-.143z" clip-rule="evenodd"/>
        </svg>
    </button>
</form>

</div>
@endpush
