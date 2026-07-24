@extends('layouts.app')
@section('title', $displayName)
@section('subtitle', $teamName . ' · Individual Performance')

@section('content')

@php
    // Same "no Excess column" convention as the main hourly view (removed per
    // request — see TsaPerformanceController@index's $displayCols comment).
    $displayCols = collect($metricCols)->reject(fn($col) => $col['group'] === 'excess');
    $rangeLabel  = $dateFrom === $dateTo
        ? \Carbon\Carbon::parse($dateFrom)->format('M d, Y')
        : \Carbon\Carbon::parse($dateFrom)->format('M d') . ' → ' . \Carbon\Carbon::parse($dateTo)->format('M d, Y');
@endphp

<a href="{{ route('tsa-performance', ['team' => $team, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}"
   class="inline-flex items-center gap-1.5 text-xs font-mono text-slate-400 hover:text-primary transition-colors mb-5">
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
    </svg>
    Back to {{ $teamName }} — {{ $rangeLabel }}
</a>

@if($isRestDay)
<div class="mb-6 flex items-center gap-3 bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-700 rounded-xl px-5 py-3">
    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
    </svg>
    <p class="text-sm font-mono text-slate-600 dark:text-slate-400">{{ $displayName }} is marked as off on {{ $rangeLabel }}.</p>
</div>
@endif

{{-- KPI CARDS — rates not shown anywhere in the main hourly table (only Upselling
     Rate is), so these are the reason this page exists rather than just re-showing
     the same table filtered to one row. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-2">Total Called Leads</p>
        <p class="text-3xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none">{{ $summary['total_called'] }}</p>
        <p class="mt-2 text-xs text-slate-400 font-mono">{{ $rangeLabel }}</p>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-2">Pick-up Rate</p>
        <p class="text-3xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none">
            {{ $summary['pick_up_rate'] !== null ? $summary['pick_up_rate'].'%' : '—' }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">Answered ÷ Total Called</p>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-2">Conversion Rate</p>
        <p class="text-3xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none">
            {{ $summary['conversion_rate'] !== null ? $summary['conversion_rate'].'%' : '—' }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">Upsell ÷ Answered</p>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-yellow-200 dark:border-yellow-900 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-yellow-600 dark:text-yellow-400 uppercase tracking-wider mb-2">Upselling Rate</p>
        <p class="text-3xl font-bold text-slate-800 dark:text-slate-100 font-mono leading-none">
            {{ $summary['upselling_rate'] !== null ? $summary['upselling_rate'].'%' : '—' }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">Upsell ÷ (Upsell + CVC)</p>
    </div>

</div>

{{-- HOURLY BREAKDOWN --}}
@if(empty($hourlyRows))
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No called leads for {{ $rangeLabel }}</p>
</div>
@else
<div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm" style="max-height:calc(100vh - 380px)">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1280px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2"
                    class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:110px">
                    Hour
                </th>
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
            @foreach($hourlyRows as $hour)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">
                    {{ $hour['label'] }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">
                    {{ $hour['row']['total_called'] ?: '' }}
                </td>
                @foreach($displayCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $hour['row'][$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['pick_up_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}">
                    {{ $hour['row']['pick_up_rate'] !== null ? $hour['row']['pick_up_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['conversion_rate'] !== null ? 'text-orange-700 dark:text-orange-400' : 'text-slate-300 dark:text-slate-600' }}">
                    {{ $hour['row']['conversion_rate'] !== null ? $hour['row']['conversion_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center font-semibold {{ $hour['row']['upselling_rate'] !== null ? 'text-yellow-700 dark:text-yellow-400' : 'text-slate-300 dark:text-slate-600' }}">
                    {{ $hour['row']['upselling_rate'] !== null ? $hour['row']['upselling_rate'].'%' : '—' }}
                </td>
            </tr>
            @endforeach

            {{-- GRAND TOTAL row --}}
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Day Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $summary['total_called'] ?: '' }}</td>
                @foreach($displayCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }}">
                    {{ $summary[$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-700 px-2 py-3 text-center text-blue-300">
                    {{ $summary['pick_up_rate'] !== null ? $summary['pick_up_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-700 px-2 py-3 text-center text-orange-300">
                    {{ $summary['conversion_rate'] !== null ? $summary['conversion_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-700 px-3 py-3 text-center text-yellow-300">
                    {{ $summary['upselling_rate'] !== null ? $summary['upselling_rate'].'%' : '—' }}
                </td>
            </tr>
        </tbody>
    </table>
</div>
@endif

{{-- PER-PRODUCT HOURLY BREAKDOWN — one column per this team's product, matching
     the source sheet's layout: how many of this TSA's leads that hour were each
     product. Uses the same ProductPerformance product-tag matching as Team
     Report's per-product breakdown, so a lead is never counted differently here
     than anywhere else in the app. --}}
<div class="mt-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Per-Product Hourly Breakdown</h2>
        @if($shiftMinutes !== null)
        <div class="flex items-center gap-2 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-lg px-3 py-1.5"
             title="Total minutes in {{ $displayName }}'s configured shift — the 100% capacity figure OPT and Unproductive Time are measured against.">
            <span class="text-[10px] font-mono font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wide">Productivity Time</span>
            <span class="text-sm font-mono font-bold text-emerald-900 dark:text-emerald-200">{{ $shiftMinutes }}</span>
        </div>
        @endif
    </div>

    @if(empty($productHourlyRows) || $products->isEmpty())
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 flex flex-col items-center justify-center gap-3">
        <p class="text-sm font-mono text-slate-400">No product data for {{ $rangeLabel }}</p>
    </div>
    @else
    <div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm" style="max-height:calc(100vh - 380px)">
        <table class="w-full border-collapse text-xs font-mono" style="min-width:{{ 150 + $products->count() * 90 + 690 }}px">
            <thead class="sticky top-0 z-20 shadow-sm">
                <tr>
                    <th class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:110px">
                        Hour
                    </th>
                    @foreach($products as $product)
                    <th class="bg-slate-100 dark:bg-slate-700 border border-slate-300 dark:border-slate-600 px-2 py-2.5 text-center text-[10px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide"
                        style="min-width:90px">
                        {{ $product->display_name }}
                    </th>
                    @endforeach
                    <th class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 dark:text-yellow-200 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:110px">
                        Total<br>Catered Leads
                    </th>
                    <th class="bg-slate-200 dark:bg-slate-600 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:110px">
                        Total {{ $teamName }}<br>Leads/Hour
                    </th>
                    <th class="bg-cyan-100 dark:bg-cyan-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-cyan-900 dark:text-cyan-200 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:90px">
                        Total<br>Answered Calls
                    </th>
                    <th class="bg-slate-100 dark:bg-slate-700 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:90px" title="Average real call duration for this hour, from synced Google Drive recordings. Blank for hours with no synced recordings yet.">
                        Total AHT<br>Per Hour
                    </th>
                    <th class="bg-red-100 dark:bg-red-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-red-900 dark:text-red-200 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:90px">
                        Total<br>Unanswered Calls
                    </th>
                    <th class="bg-emerald-100 dark:bg-emerald-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-emerald-900 dark:text-emerald-200 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:100px" title="Real call durations from synced Google Drive recordings, blended with a 3-minute estimate for any answered calls that hour without a synced recording yet (marked with a dot). Purely estimated when no recordings are synced for the hour at all.">
                        OPT (Order<br>Processing Time)
                    </th>
                    <th class="bg-orange-100 dark:bg-orange-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 dark:text-orange-200 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:100px" title="2 minutes x unanswered calls">
                        Unproductive<br>Time
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach($productHourlyRows as $hour)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">
                        {{ $hour['label'] }}
                    </td>
                    @foreach($products as $product)
                    <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center text-slate-700 dark:text-slate-200">
                        {{ $hour['counts'][$product->id] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">
                        {{ $hour['row_total'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-semibold text-slate-700 dark:text-slate-200">
                        {{ $hour['tsa_leads'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-cyan-700 dark:text-cyan-400">
                        {{ $hour['answered'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-slate-600 dark:text-slate-300">
                        @if($hour['aht'] !== null)
                            {{ sprintf('%d:%02d', intdiv($hour['aht'], 60), $hour['aht'] % 60) }}
                        @else
                            <span class="text-slate-300 dark:text-slate-600">—</span>
                        @endif
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-red-700 dark:text-red-400">
                        {{ $hour['unanswered'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-emerald-700 dark:text-emerald-400">
                        {{ $hour['opt'] ?: '' }}@if($hour['opt_is_real'])<span class="text-emerald-500" title="Includes real call-duration data (blended with a 3-min estimate for any answered calls this hour without a synced recording yet)">&nbsp;●</span>@endif
                    </td>
                    <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center text-orange-700 dark:text-orange-400">
                        {{ $hour['unproductive'] }}
                    </td>
                </tr>
                @endforeach

                {{-- GRAND TOTAL row --}}
                <tr class="bg-slate-900 text-white font-bold">
                    <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Day Total</td>
                    @foreach($products as $product)
                    <td class="border border-slate-700 px-2 py-3 text-center">
                        {{ $productTotals[$product->id] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-700 px-3 py-3 text-center text-yellow-300">
                        {{ $grandRowTotal ?: '' }}
                    </td>
                    <td class="border border-slate-700 px-3 py-3 text-center">
                        {{ $grandTsaLeads ?: '' }}
                    </td>
                    <td class="border border-slate-700 px-3 py-3 text-center text-cyan-300">
                        {{ $grandAnswered ?: '' }}
                    </td>
                    <td class="border border-slate-700 px-3 py-3 text-center text-slate-500">—</td>
                    <td class="border border-slate-700 px-3 py-3 text-center text-red-300">
                        {{ $grandUnanswered ?: '' }}
                    </td>
                    <td class="border border-slate-700 px-3 py-3 text-center text-emerald-300">
                        {{ $grandOpt ?: '' }}
                    </td>
                    <td class="border border-slate-700 px-3 py-3 text-center text-orange-300">
                        {{ $grandUnproductive ?: '' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="text-[10px] font-mono text-slate-400 mt-2">
        AHT and OPT blend real call-duration data synced from Google Drive with a 3-minute-per-call estimate for any answered calls that hour without a synced recording yet (marked with a dot next to the OPT value); hours with no synced recordings at all use the pure estimate. Unproductive Time is a flat 2 minutes per unanswered call.
    </p>
    @endif
</div>

@endsection

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

@if($dateFrom === $dateTo && $dateFrom === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

{{-- Trailing cluster, same order on every report page: filters (none on this
     drill-down page), then the date icon, then Sync. --}}
<form method="GET" action="{{ route('tsa-performance.individual', ['team' => $team, 'tsaKey' => $tsaKey]) }}" class="flex items-center gap-3 flex-wrap">
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
