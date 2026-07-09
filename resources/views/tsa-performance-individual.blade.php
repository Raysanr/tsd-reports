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

{{-- KPI CARDS — rates not shown anywhere in the main hourly table (only Upselling
     Rate is), so these are the reason this page exists rather than just re-showing
     the same table filtered to one row. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-2">Total Called Leads</p>
        <p class="text-3xl font-bold text-slate-800 font-mono leading-none">{{ $summary['total_called'] }}</p>
        <p class="mt-2 text-xs text-slate-400 font-mono">{{ $rangeLabel }}</p>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-2">Pick-up Rate</p>
        <p class="text-3xl font-bold text-slate-800 font-mono leading-none">
            {{ $summary['pick_up_rate'] !== null ? $summary['pick_up_rate'].'%' : '—' }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">Answered ÷ Total Called</p>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-slate-400 uppercase tracking-wider mb-2">Conversion Rate</p>
        <p class="text-3xl font-bold text-slate-800 font-mono leading-none">
            {{ $summary['conversion_rate'] !== null ? $summary['conversion_rate'].'%' : '—' }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">Upsell ÷ Answered</p>
    </div>

    <div class="bg-white rounded-xl border border-yellow-200 p-5 shadow-sm">
        <p class="text-xs font-mono font-semibold text-yellow-600 uppercase tracking-wider mb-2">Upselling Rate</p>
        <p class="text-3xl font-bold text-slate-800 font-mono leading-none">
            {{ $summary['upselling_rate'] !== null ? $summary['upselling_rate'].'%' : '—' }}
        </p>
        <p class="mt-2 text-xs text-slate-400 font-mono">Upsell ÷ (Upsell + CVC)</p>
    </div>

</div>

{{-- HOURLY BREAKDOWN --}}
@if(empty($hourlyRows))
<div class="bg-white rounded-xl border border-slate-200 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No called leads for {{ $rangeLabel }}</p>
</div>
@else
<div class="overflow-auto bg-white rounded-xl border border-slate-200 shadow-sm" style="max-height:calc(100vh - 380px)">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1100px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:110px">
                    Hour
                </th>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap">
                    Total<br>Called Leads
                </th>
                <th colspan="7"
                    class="bg-green-200 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-green-900 uppercase tracking-wide">
                    Answered Called Leads
                </th>
                <th colspan="6"
                    class="bg-red-200 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-red-900 uppercase tracking-wide">
                    Unanswered Call Leads
                </th>
                <th rowspan="2"
                    class="bg-yellow-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 uppercase tracking-wide leading-tight"
                    style="min-width:110px">
                    Upselling<br>Rate
                </th>
            </tr>
            <tr>
                @foreach($displayCols as $col)
                @php
                    $headerColor = match($col['group']) {
                        'answered' => 'bg-green-50 text-green-800',
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
            @foreach($hourlyRows as $hour)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="border border-slate-200 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">
                    {{ $hour['label'] }}
                </td>
                <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                    {{ $hour['row']['total_called'] ?: '' }}
                </td>
                @foreach($displayCols as $col)
                <td class="border border-slate-200 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 font-semibold' : 'text-slate-700' }}">
                    {{ $hour['row'][$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $hour['row']['upselling_rate'] !== null ? 'text-yellow-700' : 'text-slate-300' }}">
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
        <h2 class="text-sm font-bold text-slate-700 font-mono">Per-Product Hourly Breakdown</h2>
        @if($shiftMinutes !== null)
        <div class="flex items-center gap-2 bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-1.5"
             title="Total minutes in {{ $displayName }}'s configured shift — the 100% capacity figure OPT and Unproductive Time are measured against.">
            <span class="text-[10px] font-mono font-bold text-emerald-700 uppercase tracking-wide">Productivity Time</span>
            <span class="text-sm font-mono font-bold text-emerald-900">{{ $shiftMinutes }}</span>
        </div>
        @endif
    </div>

    @if(empty($productHourlyRows) || $products->isEmpty())
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm py-16 flex flex-col items-center justify-center gap-3">
        <p class="text-sm font-mono text-slate-400">No product data for {{ $rangeLabel }}</p>
    </div>
    @else
    <div class="overflow-auto bg-white rounded-xl border border-slate-200 shadow-sm" style="max-height:calc(100vh - 380px)">
        <table class="w-full border-collapse text-xs font-mono" style="min-width:{{ 150 + $products->count() * 90 + 690 }}px">
            <thead class="sticky top-0 z-20 shadow-sm">
                <tr>
                    <th class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:110px">
                        Hour
                    </th>
                    @foreach($products as $product)
                    <th class="bg-slate-100 border border-slate-300 px-2 py-2.5 text-center text-[10px] font-bold text-slate-700 uppercase tracking-wide"
                        style="min-width:90px">
                        {{ $product->display_name }}
                    </th>
                    @endforeach
                    <th class="bg-yellow-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:110px">
                        Total<br>Catered Leads
                    </th>
                    <th class="bg-slate-200 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-800 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:110px">
                        Total {{ $teamName }}<br>Leads/Hour
                    </th>
                    <th class="bg-cyan-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-cyan-900 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:90px">
                        Total<br>Answered Calls
                    </th>
                    <th class="bg-slate-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-500 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:90px" title="No call-duration data exists to compute this — blank in the source sheet too">
                        Total AHT<br>Per Hour
                    </th>
                    <th class="bg-red-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-red-900 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:90px">
                        Total<br>Unanswered Calls
                    </th>
                    <th class="bg-emerald-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-emerald-900 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:100px" title="Answered calls x 3 minutes">
                        OPT (Order<br>Processing Time)
                    </th>
                    <th class="bg-orange-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 uppercase tracking-wide whitespace-nowrap"
                        style="min-width:100px" title="60 minutes - OPT - unanswered calls">
                        Unproductive<br>Time
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach($productHourlyRows as $hour)
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="border border-slate-200 px-3 py-2.5 font-semibold text-primary whitespace-nowrap">
                        {{ $hour['label'] }}
                    </td>
                    @foreach($products as $product)
                    <td class="border border-slate-200 px-2 py-2.5 text-center text-slate-700">
                        {{ $hour['counts'][$product->id] ?: '' }}
                    </td>
                    @endforeach
                    <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                        {{ $hour['row_total'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 px-3 py-2.5 text-center font-semibold text-slate-700">
                        {{ $hour['tsa_leads'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 px-3 py-2.5 text-center text-cyan-700">
                        {{ $hour['answered'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 px-3 py-2.5 text-center text-slate-300">
                        —
                    </td>
                    <td class="border border-slate-200 px-3 py-2.5 text-center text-red-700">
                        {{ $hour['unanswered'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 px-3 py-2.5 text-center text-emerald-700">
                        {{ $hour['opt'] ?: '' }}
                    </td>
                    <td class="border border-slate-200 px-3 py-2.5 text-center text-orange-700">
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
                    <td class="border border-slate-700 px-3 py-3 text-center text-orange-300">—</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="text-[10px] font-mono text-slate-400 mt-2">
        AHT is blank — no call-duration data exists to compute it (also blank in the source sheet). Day-total Unproductive Time isn't shown — it's not a plain sum of the hourly column (verified against the source sheet; no matching formula found).
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
