@extends('layouts.app')
@section('title', 'TSA Performance')
@section('subtitle', 'Called leads · Dispositions · Shift schedules')

@section('content')

{{-- Empty state --}}
@if(empty($hourBlocks))
<div class="bg-white rounded-xl border border-slate-200 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No data for {{ $selectedDate }}</p>
    <p class="text-xs font-mono text-slate-300">Sync orders first, or try another date.</p>
</div>
@else

{{-- Pivot table — bounded height + its own scroll so the sticky header has a
     real scrolling ancestor to stick within (an unbounded overflow-x-auto div
     never scrolls vertically itself, which breaks `position: sticky`). --}}
<div class="overflow-auto bg-white rounded-xl border border-slate-200 shadow-sm" style="max-height:calc(100vh - 180px)">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1200px">
            <thead class="sticky top-0 z-20 shadow-sm">

                {{-- ── Row 1: group headers ── --}}
                <tr>
                    <th rowspan="2"
                        class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:150px">
                        TSA's
                    </th>
                    <th rowspan="2"
                        class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap">
                        Total<br>Leads
                    </th>
                    {{-- Sum of the 13 disposition columns to the right — i.e. Total minus
                         Excess. Its own standalone column (not part of a colored group),
                         same pattern as Total Leads. --}}
                    <th rowspan="2"
                        class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap">
                        Catered<br>Leads
                    </th>
                    <th colspan="7"
                        class="bg-green-200 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-green-900 uppercase tracking-wide">
                        Answered Called Leads
                    </th>
                    <th colspan="6"
                        class="bg-red-200 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-red-900 uppercase tracking-wide">
                        Unanswered Call Leads
                    </th>
                    {{-- Leads whose disposition is null or exactly "UNCATERED LEADS" —
                         confirmed against real Pancake POS data: the night-shift bulk
                         action tags a lead "UNCATERED LEADS" only when nothing else was
                         ever tagged on it (any real disposition, including "Call in
                         Progress", takes priority and makes it Catered instead — see
                         extractDisposition()'s priority order in SyncTodayOrders.php). --}}
                    <th colspan="1"
                        class="bg-rose-300 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-rose-900 uppercase tracking-wide">
                        Excess Leads
                    </th>
                    {{-- Fix: this must be defined in ROW 1 with rowspan="2" so it spans
                         DOWN through row 2 (matching TSA's / Total Leads). It was
                         previously defined in row 2 with rowspan="2", which instead spans
                         downward into the first tbody row (the hour-block divider),
                         corrupting that row's column count and making a body row's cell
                         render overlapping the sticky header on scroll. --}}
                    <th rowspan="2"
                        class="bg-yellow-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 uppercase tracking-wide leading-tight"
                        style="min-width:110px">
                        Upselling<br>Rate
                    </th>
                </tr>

                {{-- ── Row 2: sub-column headers ── --}}
                <tr>
                    @foreach($metricCols as $col)
                    @php
                        $headerColor = match($col['group']) {
                            'answered' => 'bg-green-50 text-green-800',
                            'excess'   => 'bg-rose-50 text-rose-800',
                            default    => 'bg-red-50 text-red-800',
                        };
                    @endphp
                    <th class="{{ $headerColor }} border border-slate-300 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
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
                    <td colspan="18" class="border border-slate-300 bg-slate-100 px-3 py-1.5 text-[11px] font-bold text-slate-600 uppercase tracking-wide">
                        {{ $block['label'] }}
                    </td>
                </tr>

                @foreach($block['rows'] as $row)
                <tr class="hover:bg-slate-50 transition-colors">
                    {{-- Name --}}
                    <td class="border border-slate-200 px-3 py-2.5 font-semibold text-slate-700 whitespace-nowrap">
                        {{ $row['display_name'] }}
                    </td>
                    {{-- Total --}}
                    <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                        {{ $row['total'] ?: '' }}
                    </td>
                    {{-- Catered --}}
                    <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                        {{ $row['catered'] ?: '' }}
                    </td>
                    @foreach($metricCols as $col)
                    <td class="border border-slate-200 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 font-semibold' : ($col['group'] === 'excess' ? 'text-rose-700 font-semibold' : 'text-slate-700') }}">
                        {{ $row[$col['key']] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['upselling_rate'] !== null ? 'text-yellow-700' : 'text-slate-300' }}">
                        {{ $row['upselling_rate'] !== null ? $row['upselling_rate'].'%' : '—' }}
                    </td>
                </tr>
                @endforeach

                {{-- Hour TOTAL row --}}
                <tr class="bg-slate-800 text-white font-bold">
                    <td class="border border-slate-600 px-3 py-2.5 uppercase tracking-wider text-[11px]">TOTAL</td>
                    <td class="border border-slate-600 px-3 py-2.5 text-center">{{ $block['totals']['total'] ?: '' }}</td>
                    <td class="border border-slate-600 px-3 py-2.5 text-center">{{ $block['totals']['catered'] ?: '' }}</td>
                    @foreach($metricCols as $col)
                    <td class="border border-slate-600 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-300' : ($col['group'] === 'excess' ? 'text-rose-300' : '') }}">
                        {{ $block['totals'][$col['key']] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-600 px-3 py-2.5 text-center text-yellow-300">
                        {{ $block['upselling_rate'] !== null ? $block['upselling_rate'].'%' : '—' }}
                    </td>
                </tr>
                @endforeach

                {{-- GRAND TOTAL row --}}
                <tr class="bg-slate-900 text-white font-bold">
                    <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
                    <td class="border border-slate-700 px-3 py-3 text-center">{{ $totals['total'] ?: '' }}</td>
                    <td class="border border-slate-700 px-3 py-3 text-center">{{ $totals['catered'] ?: '' }}</td>
                    @foreach($metricCols as $col)
                    <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : ($col['group'] === 'excess' ? 'text-rose-300' : '') }}">
                        {{ $totals[$col['key']] ?: '' }}
                    </td>
                    @endforeach
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

@if($selectedDate === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('tsa-performance') }}" class="flex items-center gap-3 flex-wrap">
    {{-- Hidden fallbacks so clicking a product button (or the plain Load button)
         doesn't drop the currently selected team — a real <button name="team"> only
         submits its value when IT is the control clicked. Team buttons still take
         priority: as later fields in the same form, their value wins over this one. --}}
    <input type="hidden" name="team" value="{{ $selectedTeam }}">
    <input type="hidden" name="product" value="{{ $selectedProduct }}">

    <div class="flex rounded-lg border border-slate-200 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}"
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white text-slate-500 hover:bg-slate-50' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- Product filter as a dropdown, not a row of toggle buttons — a team with 10
         products (SH Naturals) was overloading the topbar with buttons. --}}
    @if($availableProducts->isNotEmpty())
    <div class="relative">
        <button type="button" id="productTrigger" aria-haspopup="listbox" aria-expanded="false"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold font-mono text-slate-700 hover:bg-slate-50 transition-colors cursor-pointer">
            <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
            </svg>
            <span>{{ $selectedProduct === 'all' ? 'All Products' : $selectedProduct }}</span>
            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div id="productPanel" role="listbox" class="hidden absolute right-0 top-full mt-2 z-50 bg-white rounded-xl shadow-2xl border border-slate-200 py-1 max-h-80 overflow-y-auto" style="min-width:200px">
            <button type="submit" name="product" value="all" role="option" aria-selected="{{ $selectedProduct === 'all' ? 'true' : 'false' }}"
                    class="w-full text-left px-4 py-2 text-xs font-mono transition-colors cursor-pointer
                           {{ $selectedProduct === 'all' ? 'bg-slate-700 text-white font-semibold' : 'text-slate-600 hover:bg-slate-50' }}">
                All Products
            </button>
            @foreach($availableProducts as $product)
            <button type="submit" name="product" value="{{ $product->display_name }}" role="option" aria-selected="{{ $selectedProduct === $product->display_name ? 'true' : 'false' }}"
                    class="w-full text-left px-4 py-2 text-xs font-mono transition-colors cursor-pointer border-t border-slate-100
                           {{ $selectedProduct === $product->display_name ? 'bg-slate-700 text-white font-semibold' : 'text-slate-600 hover:bg-slate-50' }}">
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

    {{-- Positioned last (right before the primary action), matching the Dashboard's
         filters-then-date-then-action convention. --}}
    @include('partials.date-picker', ['mode' => 'single', 'id' => 'drp', 'date' => $selectedDate, 'submit' => 'form', 'dateField' => 'date'])

    <label class="flex items-center gap-1.5 text-xs font-mono text-slate-500 cursor-pointer select-none">
        <input type="checkbox" name="show_empty" value="1" {{ $showEmpty ? 'checked' : '' }}
               class="rounded border-slate-300 text-yellow-600 focus:ring-yellow-400 cursor-pointer">
        Show empty hours
    </label>

    <button type="submit"
            class="px-4 py-1.5 bg-yellow-700 text-white text-xs font-semibold rounded-lg
                   hover:bg-yellow-800 transition-colors cursor-pointer">
        Load
    </button>

    <a href="{{ route('tsa-management') }}"
       class="inline-flex items-center gap-1.5 text-xs font-mono text-slate-400 hover:text-yellow-600 transition-colors"
       title="Edit shift schedules">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
    </a>
</form>

</div>
@endpush
