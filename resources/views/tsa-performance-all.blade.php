@extends('layouts.app')
@section('title', 'TSA Performance')
@section('subtitle', 'All products · ' . $selectedDate)

@section('content')

@if($productRows->isEmpty())
<div class="bg-white rounded-xl border border-slate-200 shadow-sm py-24 flex flex-col items-center justify-center gap-4">
    <svg class="w-12 h-12 text-slate-200" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No products configured</p>
    <p class="text-xs font-mono text-slate-300">Add products on the Product Management page.</p>
</div>
@else

<div class="overflow-auto bg-white rounded-xl border border-slate-200 shadow-sm" style="max-height:calc(100vh - 180px)">
    <table class="w-full border-collapse text-xs font-mono" style="min-width:1400px">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr>
                <th rowspan="2"
                    class="bg-yellow-50 border border-slate-300 px-3 py-2.5 text-left text-[11px] font-bold text-slate-700 uppercase tracking-wide whitespace-nowrap"
                    style="min-width:180px">
                    Product
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
                <th colspan="1"
                    class="bg-rose-300 border border-slate-300 px-3 py-2 text-center text-[11px] font-bold text-rose-900 uppercase tracking-wide">
                    Excess Leads
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
            @foreach($productRows as $row)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="border border-slate-200 px-3 py-2.5 font-semibold text-slate-700 whitespace-nowrap">
                    {{ $row['display_name'] }}
                    <div class="text-[10px] font-normal text-slate-400">{{ $row['team'] }}</div>
                </td>
                <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                    {{ $row['total'] ?: '' }}
                </td>
                <td class="border border-slate-200 px-3 py-2.5 text-center font-bold text-slate-800">
                    {{ $row['catered'] ?: '' }}
                </td>
                @foreach($metricCols as $col)
                <td class="border border-slate-200 px-2 py-2.5 text-center {{ !empty($col['highlight']) ? 'text-green-700 font-semibold' : ($col['group'] === 'excess' ? 'text-rose-700 font-semibold' : 'text-slate-700') }}">
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

@if($selectedDate === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

<form method="GET" action="{{ route('tsa-performance') }}" class="flex items-center gap-3 flex-wrap">
    <input type="hidden" name="team" value="{{ $selectedTeam }}">

    <div class="flex rounded-lg border border-slate-200 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}"
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white text-slate-500 hover:bg-slate-50' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>

    @include('partials.date-picker', ['mode' => 'single', 'id' => 'drp', 'date' => $selectedDate, 'submit' => 'form', 'dateField' => 'date'])

    <button type="submit"
            class="px-4 py-1.5 bg-yellow-700 text-white text-xs font-semibold rounded-lg
                   hover:bg-yellow-800 transition-colors cursor-pointer">
        Load
    </button>
</form>

</div>
@endpush
