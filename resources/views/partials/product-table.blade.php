{{-- Reusable per-product table: Product | Total | Catered | Answered cols |
     Unanswered cols | Restocking | Excess | rates, with a Grand Total tfoot
     row. Used for leads-report-all's combined (all-teams) table and its
     per-team breakdown tables below it — same markup, different $rows.

     Expects: $tableId, $rows, $grandTotal, $answeredCols, $unansweredCols,
     $dateFrom, $dateTo (for drilldown), $chartId, $exportName, $exportTitle,
     $snapshotDateLabel. $cardTitle is optional — when set, wraps the header
     bar AND the table+chart row in ONE shared bordered card (title bar on
     top, no gap into the table below it) matching leads-report.blade.php's
     own per-product cards; without it, this renders as a bare filter row
     above its own separately-bordered table (leads-report-all's combined
     table, which has no title). --}}
@if(isset($cardTitle))
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $cardTitle }}</h2>
        <div class="flex items-center gap-3">
            <span class="text-xs font-mono text-slate-400">{{ $grandTotal['total'] }} {{ \Illuminate\Support\Str::plural('lead', $grandTotal['total']) }}</span>
            <input type="text" data-table-filter="{{ $tableId }}" placeholder="Filter…" aria-label="Filter products"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            @include('partials.table-actions', ['target' => $tableId, 'name' => $exportName, 'chart' => $chartId, 'title' => $exportTitle, 'subtitle' => $snapshotDateLabel])
        </div>
    </div>
    <div class="flex flex-col lg:flex-row gap-4">
    <div class="overflow-x-auto flex-1 min-w-0" id="{{ $tableId }}" data-scroll-shadow
         data-dd-team="all" data-dd-endpoint="{{ route('leads-report.drilldown') }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
@else
<div class="flex items-center justify-end gap-3 mb-2">
    <input type="text" data-table-filter="{{ $tableId }}" placeholder="Filter…" aria-label="Filter products"
           class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    @include('partials.table-actions', ['target' => $tableId, 'name' => $exportName, 'chart' => $chartId, 'title' => $exportTitle, 'subtitle' => $snapshotDateLabel])
</div>

<div class="flex flex-col lg:flex-row gap-4">
<div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm flex-1 min-w-0" style="max-height:calc(100vh - 180px)" id="{{ $tableId }}" data-sortable-table data-scroll-shadow
     data-dd-team="all" data-dd-endpoint="{{ route('leads-report.drilldown') }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
<table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
@endif
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
        @foreach($rows as $row)
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

@if($grandTotal['total'] > 0)
@include('partials.pie-chart-panel', ['id' => $chartId])
@endif
</div>
@if(isset($cardTitle))
</div>
@endif
