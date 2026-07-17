@extends('layouts.app')
@section('title', 'Leads Report')
@section('subtitle', 'All products, all teams · ' . $rangeLabel)

@section('content')

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

<div class="overflow-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm" style="max-height:calc(100vh - 180px)">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2"
                    class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:180px">
                    Product
                </th>
                <th rowspan="2"
                    class="bg-yellow-50 dark:bg-yellow-950/40 border border-slate-300 dark:border-slate-600 px-3 py-2.5 text-center text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide whitespace-nowrap">
                    Total<br>Leads
                </th>
                <th rowspan="2"
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
                <th colspan="1"
                    class="bg-rose-300 dark:bg-rose-900/70 border border-slate-300 dark:border-slate-600 px-3 py-2 text-center text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide">
                    Excess Leads
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
                    style="min-width:90px">
                    Upselling<br>Rate
                </th>
            </tr>
            <tr>
                @foreach($metricCols as $col)
                @php
                    $headerColor = match($col['group']) {
                        'answered' => 'bg-green-50 dark:bg-green-950/40 text-green-800 dark:text-green-400',
                        'excess'   => 'bg-rose-50 dark:bg-rose-950/40 text-rose-800 dark:text-rose-400',
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
            @foreach($productRows as $row)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 font-semibold text-slate-700 dark:text-slate-200 whitespace-nowrap">
                    {{ $row['display_name'] }}
                    <div class="text-[10px] font-normal text-slate-400">{{ $row['team'] }}</div>
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">
                    {{ $row['total'] ?: '' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-3 py-2.5 text-center font-bold text-slate-800 dark:text-slate-100">
                    {{ $row['catered'] ?: '' }}
                </td>
                @foreach($metricCols as $col)
                <td class="border border-slate-200 dark:border-slate-700 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 dark:text-green-400 font-semibold' : ($col['group'] === 'excess' ? 'text-rose-700 dark:text-rose-400 font-semibold' : 'text-slate-700 dark:text-slate-200') }}">
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

            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-3 py-3 uppercase tracking-wider text-[11px]">Grand Total</td>
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
        </tbody>
    </table>
</div>

@endif
@endsection

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
