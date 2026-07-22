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
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No TSAs configured</p>
    <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Add TSAs on the TSA Management page.</p>
</div>
@else

{{-- Export icons live with the table itself (not the shared topbar), so they
     stay contextually tied to it regardless of which team tab is selected —
     matches how Leads Report's per-product tables already do this. --}}
<div class="flex items-center justify-end gap-3 mb-2">
    <input type="text" data-table-filter="tsaPerfAllTable" placeholder="Filter…" aria-label="Filter TSAs"
           class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    @include('partials.table-actions', ['target' => 'tsaPerfAllTable', 'name' => 'tsa-performance-' . $selectedTeam])
</div>

<div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm" style="max-height:calc(100vh - 180px)" id="tsaPerfAllTable" data-sortable-table>
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2" data-sort-key="tsa"
                    class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:180px">
                    TSA
                </th>
                <th rowspan="2" data-sort-key="catered"
                    class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">
                    Catered<br>Leads
                </th>
                <th colspan="7"
                    class="bg-green-200 dark:bg-green-900/60 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide">
                    Answered Called Leads
                </th>
                <th colspan="6"
                    class="bg-red-200 dark:bg-red-900/60 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-red-900 dark:text-red-200 uppercase tracking-wide">
                    Unanswered Call Leads
                </th>
                <th rowspan="2" data-sort-key="pickUpRate"
                    class="bg-blue-100 dark:bg-blue-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-blue-900 dark:text-blue-200 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Pick-up<br>Rate
                </th>
                <th rowspan="2" data-sort-key="conversionRate"
                    class="bg-orange-100 dark:bg-orange-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-orange-900 dark:text-orange-200 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Conversion<br>Rate
                </th>
                <th rowspan="2" data-sort-key="upsellingRate"
                    class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-yellow-900 dark:text-yellow-200 uppercase tracking-wide leading-tight"
                    style="min-width:90px">
                    Upselling<br>Rate
                </th>
            </tr>
            <tr>
                @foreach($displayCols as $col)
                @php
                    $headerColor = $col['group'] === 'answered' ? 'bg-green-50 dark:bg-green-950/40 text-green-800 dark:text-green-400' : 'bg-red-50 dark:bg-red-950/40 text-red-800 dark:text-red-400';
                @endphp
                <th class="{{ $headerColor }} border border-slate-300 dark:border-slate-600 px-2 py-2 text-center text-[10px] font-semibold uppercase tracking-wide leading-tight"
                    style="min-width:{{ $col['min_width'] }}px" data-sort-key="{{ $col['key'] }}">
                    {!! $col['label'] !!}
                </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($tsaRows as $row)
            {{-- Unassigned is a synthetic row (no tsa_key), not a real TSA — italic +
                 muted background marks it as an aggregate, distinct from a person's row,
                 same visual language as GRAND TOTAL being styled distinctly below. --}}
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors {{ !$row['tsa_key'] ? 'italic bg-slate-50/60 dark:bg-slate-800/40' : '' }}">
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold whitespace-nowrap" data-sort-key="tsa" data-sort-value="{{ $row['display_name'] }}">
                    @if($row['team_key'])
                    <a href="{{ route('tsa-performance.individual', ['team' => $row['team_key'], 'tsaKey' => $row['tsa_key'], 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}"
                       class="text-primary hover:underline">
                        {{ $row['display_name'] }}
                    </a>
                    @else
                    <span class="text-slate-500 dark:text-slate-400">{{ $row['display_name'] }}</span>
                    @endif
                    <div class="text-[10px] font-normal text-slate-400">{{ $row['team'] }}</div>
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100" data-sort-key="catered" data-sort-value="{{ $row['catered'] }}">
                    {{ $row['catered'] ?: '' }}
                </td>
                @foreach($displayCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : 'text-slate-700 dark:text-slate-200' }}" data-sort-key="{{ $col['key'] }}" data-sort-value="{{ $row[$col['key']] }}">
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
        {{-- Grand Total lives in tfoot, not tbody, so a client-side column sort
             (which only re-orders <tbody> rows — see app.js) never shuffles this
             row into the middle of the sorted TSA list. --}}
        <tfoot>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
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
        </tfoot>
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
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
    </button>
</form>

</div>
@endpush
