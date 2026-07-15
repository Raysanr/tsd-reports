@extends('layouts.app')
@section('title', 'TSA Performance')
@section('subtitle', 'All TSAs · ' . ($dateFrom === $dateTo ? $dateFrom : $dateFrom . ' → ' . $dateTo))

@section('content')
@php
    // Excess Leads = a lead swept "UNCATERED LEADS" that NO TSA ever claimed — by
    // definition it can never belong to any specific TSA's row, so it's excluded
    // here the same way the per-team hourly view and its individual-TSA drill-down
    // already exclude it (see their identical $displayCols line).
    $displayCols = collect($metricCols)->reject(fn($col) => $col['group'] === 'excess');
    $rangeLabel  = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . ' → ' . $dateTo);
@endphp

@if($tsaRows->isEmpty())
<div class="bg-white rounded-xl border border-slate-200 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No TSAs configured</p>
    <p class="text-xs font-mono text-slate-300">Add TSAs on the TSA Management page.</p>
</div>
@else

{{-- Export icons live with the table itself (not the shared topbar), so they
     stay contextually tied to it regardless of which team tab is selected —
     matches how Leads Report's per-product tables already do this. --}}
<div class="flex items-center justify-end mb-2">
    @include('partials.table-actions', ['target' => 'tsaPerfAllTable', 'name' => 'tsa-performance-' . $selectedTeam])
</div>

<div class="overflow-auto bg-white rounded-xl border border-slate-200 shadow-sm" style="max-height:calc(100vh - 180px)" id="tsaPerfAllTable">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:180px">
                    TSA
                </th>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap">
                    Total<br>Leads
                </th>
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
                <th rowspan="2"
                    class="bg-blue-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-blue-900 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Pick-up<br>Rate
                </th>
                <th rowspan="2"
                    class="bg-orange-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Conversion<br>Rate
                </th>
                <th rowspan="2"
                    class="bg-yellow-100 border border-slate-300 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Upselling<br>Rate
                </th>
            </tr>
            <tr>
                @foreach($displayCols as $col)
                @php
                    $headerColor = $col['group'] === 'answered' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800';
                @endphp
                <th class="{{ $headerColor }} border border-slate-300 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
                    style="min-width:{{ $col['min_width'] }}px">
                    {!! $col['label'] !!}
                </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($tsaRows as $row)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="border border-slate-200 px-3 py-2.5 font-semibold whitespace-nowrap">
                    @if($row['team_key'])
                    <a href="{{ route('tsa-performance.individual', ['team' => $row['team_key'], 'tsaKey' => $row['tsa_key'], 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}"
                       class="text-primary hover:underline">
                        {{ $row['display_name'] }}
                    </a>
                    @else
                    <span class="text-slate-700">{{ $row['display_name'] }}</span>
                    @endif
                    <div class="text-[10px] font-normal text-slate-400">{{ $row['team'] }}</div>
                </td>
                <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                    {{ $row['total'] ?: '' }}
                </td>
                <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                    {{ $row['catered'] ?: '' }}
                </td>
                @foreach($displayCols as $col)
                <td class="border border-slate-200 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 font-semibold' : 'text-slate-700' }}">
                    {{ $row[$col['key']] ?: '' }}
                </td>
                @endforeach
                <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['pick_up_rate'] !== null ? 'text-blue-700' : 'text-slate-300' }}">
                    {{ $row['pick_up_rate'] !== null ? $row['pick_up_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['conversion_rate'] !== null ? 'text-orange-700' : 'text-slate-300' }}">
                    {{ $row['conversion_rate'] !== null ? $row['conversion_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 px-2 py-2.5 text-center font-semibold {{ $row['upselling_rate'] !== null ? 'text-yellow-700' : 'text-slate-300' }}">
                    {{ $row['upselling_rate'] !== null ? $row['upselling_rate'].'%' : '—' }}
                </td>
            </tr>
            @endforeach

            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['total'] ?: '' }}</td>
                <td class="border border-slate-700 px-3 py-3 text-center">{{ $grandTotal['catered'] ?: '' }}</td>
                @foreach($displayCols as $col)
                <td class="border border-slate-700 px-2 py-3 text-center {{ !empty($col['highlight']) ? 'text-green-300' : '' }}">
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
    <input type="hidden" name="team" value="{{ $selectedTeam }}">

    <div class="flex rounded-lg border border-slate-200 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}" data-filter-btn
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200 motion-reduce:transition-none
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white text-slate-500 hover:bg-slate-50' }}">
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
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
    </button>
</form>

</div>
@endpush
