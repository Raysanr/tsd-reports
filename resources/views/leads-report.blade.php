@extends('layouts.app')
@section('title', 'Leads Report')
@section('subtitle', 'Orders · Per-Product Hourly Breakdown')

@section('content')
{{-- $rangeLabel comes from the controller: "Last 24h · Jul 9 5:30PM → Jul 10 5:30PM"
     in rolling mode, or the plain date / "from → to" span in fixed-dates mode. --}}

@php
    // Snapshot-only date label — "July 27, 2026", or "July 20, 2026 – July 27,
    // 2026" for a multi-day range — passed as table-actions' 'subtitle' so
    // exported PNGs are self-identifying by date, not just by table name.
    // Deliberately full month names, unlike $rangeLabel's abbreviated "Jul 27"
    // (this is the one place a reader might view the image alone, days later,
    // with no other on-screen context to disambiguate a truncated month).
    $snapshotDateLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('F j, Y')
        . ($dateFrom === $dateTo ? '' : ' – ' . \Illuminate\Support\Carbon::parse($dateTo)->format('F j, Y'));
@endphp

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
    // 'Unat-<br>tended' is a deliberate hyphenated line-break for the narrow
    // table header (see ProductPerformance::METRIC_COLUMNS) — flattening its
    // '<br>' to a space like every other label would leave a dangling "Unat-
    // tended" in the single-line chart legend, so the hyphen-break is joined
    // with no space first.
    $dispositionLabels = collect($metricCols)->pluck('label', 'key')
        ->map(fn($label) => strip_tags(str_replace(['-<br>', '<br>'], ['', ' '], $label)));

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

{{-- PRODUCT SUMMARY — one row per product, whole range, no hourly split. Same
     table shape/columns as the ALL view's own per-product table (explicit
     request, 2026-08-12: "a table like in the ALL filter... before this Grand
     Total"), reusing $productTables — already computed above for the hourly
     cards further down this same page, not a second query. Each row is
     literally $table['total'] (ProductPerformance::buildRow()'s own output,
     same shape the ALL view's $productRows already has), so this can never
     disagree with either the per-product hourly cards below or the Grand
     Total section right under it — same underlying rows either way. --}}
@if($productTables->isNotEmpty())
<div class="flex items-center justify-end gap-3 mb-2">
    <input type="text" data-table-filter="productSummaryTable" placeholder="Filter…" aria-label="Filter products"
           class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    @include('partials.table-actions', ['target' => 'productSummaryTable', 'name' => 'product-summary-' . $selectedTeam, 'title' => 'Product Summary', 'subtitle' => $snapshotDateLabel])
</div>

<div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm mb-6" style="max-height:calc(100vh - 180px)" id="productSummaryTable" data-sortable-table data-scroll-shadow
     data-dd-team="{{ $selectedTeam }}" data-dd-endpoint="{{ route('leads-report.drilldown') }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
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
                @foreach($metricCols as $col)
                @php
                    $summaryHeaderColor = match($col['group']) {
                        'answered' => 'bg-green-50 dark:bg-green-950 text-green-800 dark:text-green-400',
                        'excess'   => 'bg-rose-50 dark:bg-rose-950 text-rose-800 dark:text-rose-400',
                        default    => 'bg-red-50 dark:bg-red-950 text-red-800 dark:text-red-400',
                    };
                @endphp
                <th class="{{ $summaryHeaderColor }} border border-slate-300 dark:border-slate-600 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
                    style="min-width:{{ $col['min_width'] }}px" data-sort-key="{{ $col['key'] }}">
                    {!! $col['label'] !!}
                </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($productTables as $table)
            @php($row = $table['total'])
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="sticky-col sticky-col-body border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-slate-700 dark:text-slate-200 whitespace-nowrap" data-sort-key="product" data-sort-value="{{ $row['display_name'] }}">
                    {{ $row['display_name'] }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100 {{ $row['total'] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="total" data-sort-value="{{ $row['total'] }}"
                    @if($row['total']) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" title="Click to see the orders behind this total" @endif>
                    {{ $row['total'] ?: '' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100 {{ $row['catered'] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="catered" data-sort-value="{{ $row['catered'] }}"
                    @if($row['catered']) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" data-dd-column="catered" title="Click to see the orders behind this total" @endif>
                    {{ $row['catered'] ?: '' }}
                </td>
                @foreach($metricCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : ($col['group'] === 'excess' ? 'text-rose-700 dark:text-rose-400 font-semibold' : 'text-slate-700 dark:text-slate-200') }} {{ $row[$col['key']] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}" data-sort-key="{{ $col['key'] }}" data-sort-value="{{ $row[$col['key']] }}"
                    @if($row[$col['key']]) data-drilldown data-dd-cell-product="{{ $row['product_id'] }}" data-dd-column="{{ $col['key'] }}" title="Click to see the orders behind this total" @endif>
                    {{ $row[$col['key']] ?: '' }}
                </td>
                @endforeach
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
        <tfoot>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="sticky-col sticky-col-footer border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['total'] ?: '' }}</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['catered'] ?: '' }}</td>
                @foreach($metricCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : ($col['group'] === 'excess' ? 'text-rose-300' : '') }}">
                    {{ $grandTotal[$col['key']] ?: '' }}
                </td>
                @endforeach
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
@endif

{{-- GRAND TOTAL — all products combined, whole range. One tally over every order
     (not a sum of the per-product totals below, which would double-count orders
     matching multiple product keywords and drop ones matching none). --}}
@if($grandTotal['total'] > 0)
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Grand Total — All Products</h2>
        <div class="flex items-center gap-3">
            <span class="text-xs font-mono text-slate-400">{{ $grandTotal['total'] }} {{ \Illuminate\Support\Str::plural('lead', $grandTotal['total']) }}</span>
            @include('partials.table-actions', ['target' => 'grandTotalTable', 'name' => 'grand-total-' . $selectedTeam, 'chart' => 'grandTotalChart', 'title' => 'Grand Total — All Products', 'subtitle' => $snapshotDateLabel])
        </div>
    </div>
    <div class="flex flex-col lg:flex-row">
    <div class="overflow-x-auto flex-1 min-w-0" id="grandTotalTable" data-scroll-shadow>
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1300px">
        <thead>
            <tr>
                <th rowspan="2" class="sticky-col bg-yellow-50 dark:bg-yellow-950 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap" style="min-width:110px">Time</th>
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
                <td class="sticky-col sticky-col-body border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">{{ $hour['label'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total'] ?: '' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total_called'] ?: '' }}</td>
                @foreach($answeredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }}">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                @foreach($unansweredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center text-slate-700 dark:text-slate-200">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['excess'] ? 'text-rose-700 dark:text-rose-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['excess'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['pick_up_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['pick_up_rate'] !== null ? $hour['row']['pick_up_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['conversion_rate'] !== null ? 'text-orange-700 dark:text-orange-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['conversion_rate'] !== null ? $hour['row']['conversion_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['upselling_rate'] !== null ? 'text-yellow-700 dark:text-yellow-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['upselling_rate'] !== null ? $hour['row']['upselling_rate'].'%' : '—' }}</td>
            </tr>
            @endforeach

            <tr class="bg-slate-900 text-white font-bold">
                <td class="sticky-col sticky-col-footer border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Total</td>
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
    <div class="shrink-0 w-full lg:w-[26rem] border-t lg:border-t-0 lg:border-l border-slate-100 dark:border-slate-700 p-4 flex flex-col items-center justify-center">
        <div class="w-full border border-slate-200 dark:border-slate-700 rounded-lg p-3">
            <canvas id="grandTotalChart" width="380" height="340"></canvas>
        </div>
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
            @include('partials.table-actions', ['target' => 'productTable-' . $loop->index, 'name' => \Illuminate\Support\Str::slug($table['product']->display_name), 'chart' => 'productChart-' . $loop->index, 'title' => $table['product']->display_name, 'subtitle' => $snapshotDateLabel])
        </div>
    </div>

    @if(empty($table['hourlyRows']))
    <div class="py-12 text-center font-mono text-xs text-slate-400">No leads for {{ $rangeLabel }}</div>
    @else
    <div class="flex flex-col lg:flex-row">
    {{-- data-dd-* only matter for this table's own TOTAL row below (whole-range,
         one product) — the hourly rows above deliberately stay non-interactive:
         the shift-cutoff backlog-lumping in buildHourlyRows() means a single
         hour's displayed row can represent several real hours' worth of orders
         at once, which the drilldown endpoint has no way to reproduce
         correctly without risking a popover that doesn't actually match what's
         shown. --}}
    <div class="overflow-x-auto flex-1 min-w-0" id="productTable-{{ $loop->index }}" data-scroll-shadow
         data-dd-team="{{ $selectedTeam }}" data-dd-endpoint="{{ route('leads-report.drilldown') }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1300px">
        <thead>
            <tr>
                <th rowspan="2" class="sticky-col bg-yellow-50 dark:bg-yellow-950 border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap" style="min-width:110px">Time</th>
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
                <td class="sticky-col sticky-col-body border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">{{ $hour['label'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total'] ?: '' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">{{ $hour['row']['total_called'] ?: '' }}</td>
                @foreach($answeredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }}">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                @foreach($unansweredCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center text-slate-700 dark:text-slate-200">{{ $hour['row'][$col['key']] ?: '' }}</td>
                @endforeach
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['excess'] ? 'text-rose-700 dark:text-rose-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['excess'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['pick_up_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['pick_up_rate'] !== null ? $hour['row']['pick_up_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['conversion_rate'] !== null ? 'text-orange-700 dark:text-orange-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['conversion_rate'] !== null ? $hour['row']['conversion_rate'].'%' : '—' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['upselling_rate'] !== null ? 'text-yellow-700 dark:text-yellow-400' : 'text-slate-300 dark:text-slate-600' }}">{{ $hour['row']['upselling_rate'] !== null ? $hour['row']['upselling_rate'].'%' : '—' }}</td>
            </tr>
            @endforeach

            {{-- TOTAL row — the only row in this table with working drill-down;
                 see the wrapper div's own comment above for why the hourly
                 rows above it don't get the same treatment. --}}
            <tr class="bg-slate-900 text-white font-bold">
                <td class="sticky-col sticky-col-footer border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center {{ $table['total']['total'] ? 'cursor-pointer hover:bg-yellow-900/40' : '' }}"
                    @if($table['total']['total']) data-drilldown data-dd-cell-product="{{ $table['product']->id }}" title="Click to see the orders behind this total" @endif>{{ $table['total']['total'] ?: '' }}</td>
                <td class="border border-slate-700 px-3 py-3 text-center {{ $table['total']['total_called'] ? 'cursor-pointer hover:bg-yellow-900/40' : '' }}"
                    @if($table['total']['total_called']) data-drilldown data-dd-cell-product="{{ $table['product']->id }}" data-dd-column="total_called" title="Click to see the orders behind this total" @endif>{{ $table['total']['total_called'] ?: '' }}</td>
                @foreach($answeredCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }} {{ $table['total'][$col['key']] ? 'cursor-pointer hover:bg-yellow-900/40' : '' }}"
                    @if($table['total'][$col['key']]) data-drilldown data-dd-cell-product="{{ $table['product']->id }}" data-dd-column="{{ $col['key'] }}" title="Click to see the orders behind this total" @endif>{{ $table['total'][$col['key']] ?: '' }}</td>
                @endforeach
                @foreach($unansweredCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ $table['total'][$col['key']] ? 'cursor-pointer hover:bg-yellow-900/40' : '' }}"
                    @if($table['total'][$col['key']]) data-drilldown data-dd-cell-product="{{ $table['product']->id }}" data-dd-column="{{ $col['key'] }}" title="Click to see the orders behind this total" @endif>{{ $table['total'][$col['key']] ?: '' }}</td>
                @endforeach
                <td class="border border-slate-700 px-2 py-3 text-center text-rose-300 {{ $table['total']['excess'] ? 'cursor-pointer hover:bg-yellow-900/40' : '' }}"
                    @if($table['total']['excess']) data-drilldown data-dd-cell-product="{{ $table['product']->id }}" data-dd-column="excess" title="Click to see the orders behind this total" @endif>{{ $table['total']['excess'] ?: '' }}</td>
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
    <div class="shrink-0 w-full lg:w-[26rem] border-t lg:border-t-0 lg:border-l border-slate-100 dark:border-slate-700 p-4 flex flex-col items-center justify-center">
        <div class="w-full border border-slate-200 dark:border-slate-700 rounded-lg p-3">
            <canvas id="productChart-{{ $loop->index }}" width="380" height="340"></canvas>
        </div>
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
    <div class="overflow-auto" style="max-height:60vh" id="ordersTable" data-sortable-table data-scroll-shadow>
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
{{-- contents: this div and the form below it exist for grouping/submission
     only — display:contents removes them from the box/render tree entirely
     (DOM structure and form-submission behavior are unaffected; only visual
     layout changes) so every actual control (team buttons, date picker,
     sync button, live indicator) becomes a direct flex child of the SAME
     single wrapping context (layouts/app.blade.php's controls div), instead
     of each nested div/form here independently deciding how to wrap its own
     slice of them. That nesting was the actual bug: on a narrow phone, the
     inner form wrapped its own last few children (the sync button) onto a
     left-aligned row while the dark mode toggle — a sibling OUTSIDE the
     form, per layouts/app.blade.php — landed in yet another disconnected
     spot, since neither wrap context knew about the other's overflow. --}}
<div class="contents">

@if($mode === 'last24h' || ($dateFrom === $dateTo && $dateFrom === now('Asia/Manila')->format('Y-m-d')))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('leads-report') }}" class="contents">
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
