@extends('layouts.app')
@section('title', 'TSA Performance')
@section('subtitle', 'Called leads · Dispositions · Shift schedules')

@section('content')

@php
    // This view no longer shows Excess Leads (removed per request — the hourly
    // per-TSA breakdown shows Total Called Leads + the 13 disposition columns +
    // Pick-up/Conversion/Upselling Rate). $metricCols stays untouched for the ALL
    // view, which still shows Excess Leads.
    $displayCols = collect($metricCols)->reject(fn($col) => $col['group'] === 'excess');
    $rangeLabel  = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . ' → ' . $dateTo);
@endphp

{{-- Empty state --}}
@if(empty($hourBlocks))
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No data for {{ $rangeLabel }}</p>
    <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Sync orders first, or try another date.</p>
</div>
@else

{{-- Export icons live with the table itself (not the shared topbar), so they
     stay contextually tied to it regardless of which team tab is selected —
     matches how Leads Report's per-product tables already do this. --}}
<div class="flex items-center justify-end mb-2">
    @include('partials.table-actions', ['target' => 'tsaPerfTable', 'name' => 'tsa-performance-' . $selectedTeam])
</div>

{{-- Pivot table — bounded height + its own scroll so the sticky header has a
     real scrolling ancestor to stick within (an unbounded overflow-x-auto div
     never scrolls vertically itself, which breaks `position: sticky`). --}}
<div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm" style="max-height:calc(100vh - 180px)" id="tsaPerfTable"
     data-dd-team="{{ $selectedTeam }}" data-dd-product="{{ $selectedProduct }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
            <thead class="sticky top-0 z-20 shadow-sm">

                {{-- ── Row 1: group headers ── --}}
                <tr>
                    <th rowspan="2"
                        class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:150px">
                        TSA's
                    </th>
                    {{-- Single "Total Called Leads" column (matches the source sheet): the
                         sum of the 13 disposition columns to the right, i.e. Answered +
                         Unanswered — every lead that was actually called. Excess/uncatered
                         leads are excluded (they have their own column). --}}
                    <th rowspan="2"
                        class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">
                        Total<br>Called Leads
                    </th>
                    <th colspan="7"
                        class="bg-green-200 dark:bg-green-900/60 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide">
                        Answered Called Leads
                    </th>
                    <th colspan="6"
                        class="bg-red-200 dark:bg-red-900/60 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-red-900 dark:text-red-200 uppercase tracking-wide">
                        Unanswered Call Leads
                    </th>
                    {{-- Fix: this must be defined in ROW 1 with rowspan="2" so it spans
                         DOWN through row 2 (matching TSA's / Total Leads). It was
                         previously defined in row 2 with rowspan="2", which instead spans
                         downward into the first tbody row (the hour-block divider),
                         corrupting that row's column count and making a body row's cell
                         render overlapping the sticky header on scroll. --}}
                    <th rowspan="2"
                        class="bg-blue-100 dark:bg-blue-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-blue-900 dark:text-blue-200 uppercase tracking-wide leading-tight"
                        style="min-width:90px">
                        Pick-up<br>Rate
                    </th>
                    <th rowspan="2"
                        class="bg-orange-100 dark:bg-orange-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 dark:text-orange-200 uppercase tracking-wide leading-tight"
                        style="min-width:90px">
                        Conversion<br>Rate
                    </th>
                    <th rowspan="2"
                        class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 dark:text-yellow-200 uppercase tracking-wide leading-tight"
                        style="min-width:110px">
                        Upselling<br>Rate
                    </th>
                </tr>

                {{-- ── Row 2: sub-column headers ── --}}
                <tr>
                    @foreach($displayCols as $col)
                    @php
                        $headerColor = match($col['group']) {
                            'answered' => 'bg-green-50 dark:bg-green-950/40 text-green-800 dark:text-green-400',
                            default    => 'bg-red-50 dark:bg-red-950/40 text-red-800 dark:text-red-400',
                        };
                    @endphp
                    <th class="{{ $headerColor }} border border-slate-300 dark:border-slate-600 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
                        style="min-width:{{ $col['min_width'] }}px">
                        {!! $col['label'] !!}
                    </th>
                    @endforeach
                </tr>

            </thead>
            <tbody>

                @foreach($hourBlocks as $block)
                {{-- Hour block divider --}}
                <tr>
                    <td colspan="18" class="border border-slate-300 dark:border-slate-600 bg-slate-100 dark:bg-slate-700 px-3 py-1.5 text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wide">
                        {{ $block['label'] }}
                    </td>
                </tr>

                @foreach($block['rows'] as $row)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                    {{-- Name — linked to that TSA's individual performance page when this
                         row has a real tsa_key (never true for a row with no key, though
                         none currently render without one). --}}
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-slate-700 dark:text-slate-200 whitespace-nowrap">
                        @if($row['tsa_key'])
                        <a href="{{ route('tsa-performance.individual', ['team' => $selectedTeam, 'tsaKey' => $row['tsa_key'], 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}"
                           class="group inline-flex items-center gap-1 hover:text-primary transition-colors cursor-pointer">
                            {{ $row['display_name'] }}
                            <svg class="w-3 h-3 opacity-0 group-hover:opacity-100 transition-opacity shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                        @else
                        {{ $row['display_name'] }}
                        @endif
                    </td>
                    {{-- Total Called Leads — clickable when non-zero: shows which orders
                         made up this number (see partials/drilldown-popover + app.js). --}}
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100 {{ $row['total_called'] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}"
                        @if($row['total_called']) data-drilldown data-dd-tsa="{{ $row['tsa_key'] }}" data-dd-hour="{{ $block['hour'] }}" data-dd-column="total_called" @endif>
                        {{ $row['total_called'] ?: '' }}
                    </td>
                    @foreach($displayCols as $col)
                    <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }} {{ $row[$col['key']] ? 'cursor-pointer hover:bg-yellow-50 dark:hover:bg-yellow-950/30' : '' }}"
                        @if($row[$col['key']]) data-drilldown data-dd-tsa="{{ $row['tsa_key'] }}" data-dd-hour="{{ $block['hour'] }}" data-dd-column="{{ $col['key'] }}" @endif>
                        {{ $row[$col['key']] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $row['pick_up_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}">
                        {{ $row['pick_up_rate'] !== null ? $row['pick_up_rate'].'%' : '—' }}
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $row['conversion_rate'] !== null ? 'text-orange-700 dark:text-orange-400' : 'text-slate-300 dark:text-slate-600' }}">
                        {{ $row['conversion_rate'] !== null ? $row['conversion_rate'].'%' : '—' }}
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $row['upselling_rate'] !== null ? 'text-yellow-700 dark:text-yellow-400' : 'text-slate-300 dark:text-slate-600' }}">
                        {{ $row['upselling_rate'] !== null ? $row['upselling_rate'].'%' : '—' }}
                    </td>
                </tr>
                @endforeach

                {{-- Hour TOTAL row --}}
                <tr class="bg-slate-800 text-white font-bold">
                    <td class="border border-slate-600 px-3 py-2.5 uppercase tracking-wider text-[11px]">TOTAL</td>
                    <td class="border border-slate-600 px-3 py-2.5 text-center {{ $block['totals']['total_called'] ? 'cursor-pointer hover:bg-slate-700' : '' }}"
                        @if($block['totals']['total_called']) data-drilldown data-dd-tsa="__all__" data-dd-hour="{{ $block['hour'] }}" data-dd-column="total_called" @endif>
                        {{ $block['totals']['total_called'] ?: '' }}
                    </td>
                    @foreach($displayCols as $col)
                    <td class="border border-slate-600 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }} {{ $block['totals'][$col['key']] ? 'cursor-pointer hover:bg-slate-700' : '' }}"
                        @if($block['totals'][$col['key']]) data-drilldown data-dd-tsa="__all__" data-dd-hour="{{ $block['hour'] }}" data-dd-column="{{ $col['key'] }}" @endif>
                        {{ $block['totals'][$col['key']] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-600 px-3 py-2.5 text-center text-blue-300">
                        {{ $block['pick_up_rate'] !== null ? $block['pick_up_rate'].'%' : '—' }}
                    </td>
                    <td class="border border-slate-600 px-3 py-2.5 text-center text-orange-300">
                        {{ $block['conversion_rate'] !== null ? $block['conversion_rate'].'%' : '—' }}
                    </td>
                    <td class="border border-slate-600 px-3 py-2.5 text-center text-yellow-300">
                        {{ $block['upselling_rate'] !== null ? $block['upselling_rate'].'%' : '—' }}
                    </td>
                </tr>
                @endforeach

                {{-- GRAND TOTAL row --}}
                <tr class="bg-slate-900 text-white font-bold">
                    <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
                    <td class="border border-slate-700 px-3 py-3 text-center {{ $totals['total_called'] ? 'cursor-pointer hover:bg-slate-800' : '' }}"
                        @if($totals['total_called']) data-drilldown data-dd-tsa="__all__" data-dd-column="total_called" @endif>
                        {{ $totals['total_called'] ?: '' }}
                    </td>
                    @foreach($displayCols as $col)
                    <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }} {{ $totals[$col['key']] ? 'cursor-pointer hover:bg-slate-800' : '' }}"
                        @if($totals[$col['key']]) data-drilldown data-dd-tsa="__all__" data-dd-column="{{ $col['key'] }}" @endif>
                        {{ $totals[$col['key']] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-700 px-3 py-3 text-center text-blue-300">
                        {{ $totalPickUpRate !== null ? $totalPickUpRate.'%' : '—' }}
                    </td>
                    <td class="border border-slate-700 px-3 py-3 text-center text-orange-300">
                        {{ $totalConversionRate !== null ? $totalConversionRate.'%' : '—' }}
                    </td>
                    <td class="border border-slate-700 px-3 py-3 text-center text-yellow-300">
                        {{ $totalUpsellingRate !== null ? $totalUpsellingRate.'%' : '—' }}
                    </td>
                </tr>

            </tbody>
    </table>
</div>

@endif
@endsection

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

@if($dateFrom === $dateTo && $dateFrom === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('tsa-performance') }}" class="flex items-center gap-3 flex-wrap">
    {{-- Hidden fallbacks so clicking a product button (or the plain Load button)
         doesn't drop the currently selected team — a real <button name="team"> only
         submits its value when IT is the control clicked. Team buttons still take
         priority: as later fields in the same form, their value wins over this one. --}}
    <input type="hidden" name="team" value="{{ $selectedTeam }}">
    <input type="hidden" name="product" value="{{ $selectedProduct }}">

    <div class="flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}" data-filter-btn
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200 motion-reduce:transition-none
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- Product filter as a dropdown, not a row of toggle buttons — a team with 10
         products (SH Naturals) was overloading the topbar with buttons. --}}
    @if($availableProducts->isNotEmpty())
    <div class="relative">
        <button type="button" id="productTrigger" aria-haspopup="listbox" aria-expanded="false"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-1.5 text-xs font-semibold font-mono text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
            <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
            </svg>
            <span id="productTriggerLabel">{{ $selectedProduct === 'all' ? 'All Products' : $selectedProduct }}</span>
            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div id="productPanel" role="listbox" class="hidden absolute right-0 top-full mt-2 z-50 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 py-1 max-h-80 overflow-y-auto" style="min-width:200px">
            <button type="submit" name="product" value="all" role="option" aria-selected="{{ $selectedProduct === 'all' ? 'true' : 'false' }}"
                    class="w-full text-left px-4 py-2 text-xs font-mono transition-colors cursor-pointer
                           {{ $selectedProduct === 'all' ? 'bg-slate-700 text-white font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                All Products
            </button>
            @foreach($availableProducts as $product)
            <button type="submit" name="product" value="{{ $product->display_name }}" role="option" aria-selected="{{ $selectedProduct === $product->display_name ? 'true' : 'false' }}"
                    class="w-full text-left px-4 py-2 text-xs font-mono transition-colors cursor-pointer border-t border-slate-100 dark:border-slate-700
                           {{ $selectedProduct === $product->display_name ? 'bg-slate-700 text-white font-semibold' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                {{ $product->display_name }}
            </button>
            @endforeach
        </div>
    </div>
    <script>
    (function () {
        const trigger = document.getElementById('productTrigger');
        const panel   = document.getElementById('productPanel');
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const isHidden = panel.classList.contains('hidden');
            panel.classList.toggle('hidden');
            trigger.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        });
        document.addEventListener('click', (e) => {
            if (!panel.contains(e.target) && e.target !== trigger) {
                panel.classList.add('hidden');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                panel.classList.add('hidden');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    })();
    </script>
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
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
    </button>
</form>

</div>
@endpush
