@extends('layouts.app')
@section('title', 'RTS / Delivered')
@section('subtitle', 'Upsell Amount · RTS vs Delivered · Per TSA')

@section('content')
@php
    $rangeLabel = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . ' → ' . $dateTo);
@endphp

@forelse($teamTables as $table)
<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 font-mono">{{ $table['name'] }}</h2>
        <div class="flex items-center gap-3">
            <span class="text-xs font-mono text-slate-400">{{ $rangeLabel }}</span>
            @include('partials.table-actions', ['target' => 'rtsTable-' . $loop->index, 'name' => 'rts-delivered-' . \Illuminate\Support\Str::slug($table['name'])])
        </div>
    </div>

    @if($table['rows']->isEmpty())
    <div class="py-12 text-center font-mono text-xs text-slate-400">No TSAs configured for {{ $table['name'] }}</div>
    @else
    <div class="overflow-x-auto" id="rtsTable-{{ $loop->index }}">
    <table class="w-full border-collapse text-xs font-mono">
        <thead>
            <tr>
                <th class="bg-yellow-100 border border-slate-200 px-4 py-2.5 text-left text-[11px] font-bold text-slate-700 uppercase tracking-wide">{{ $table['name'] }}</th>
                <th class="bg-rose-100 border border-slate-200 px-4 py-2.5 text-right text-[11px] font-bold text-rose-900 uppercase tracking-wide" style="min-width:130px">RTS</th>
                <th class="bg-green-100 border border-slate-200 px-4 py-2.5 text-right text-[11px] font-bold text-green-900 uppercase tracking-wide" style="min-width:130px">Delivered</th>
            </tr>
        </thead>
        <tbody>
            @foreach($table['rows'] as $row)
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="border border-slate-200 px-4 py-2.5 text-slate-700">{{ $row['display_name'] }}</td>
                <td class="border border-slate-200 px-4 py-2.5 text-right text-rose-700">₱{{ number_format($row['rts_amount'], 2) }}</td>
                <td class="border border-slate-200 px-4 py-2.5 text-right text-green-700">₱{{ number_format($row['delivered_amount'], 2) }}</td>
            </tr>
            @endforeach

            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total RTS</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300">₱{{ number_format($table['total_rts'], 2) }}</td>
                <td class="border border-slate-700 px-4 py-3"></td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total Delivered</td>
                <td class="border border-slate-700 px-4 py-3"></td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300">₱{{ number_format($table['total_delivered'], 2) }}</td>
            </tr>
        </tbody>
    </table>
    </div>
    @endif
</div>
@empty
<div class="bg-white rounded-xl border border-slate-200 shadow-sm py-16 text-center font-mono text-xs text-slate-400 mb-6">
    No teams configured.
</div>
@endforelse

{{-- GRAND TOTAL — both teams combined --}}
<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 font-mono">Both Teams</h2>
        @include('partials.table-actions', ['target' => 'rtsGrandTable', 'name' => 'rts-delivered-both-teams'])
    </div>
    <div id="rtsGrandTable">
    <table class="w-full border-collapse text-xs font-mono">
        <tbody>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total RTS — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300" style="min-width:130px">₱{{ number_format($grandTotalRts, 2) }}</td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total Delivered — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300" style="min-width:130px">₱{{ number_format($grandTotalDelivered, 2) }}</td>
            </tr>
        </tbody>
    </table>
    </div>
</div>

@endsection

@push('topbar-right')
<div class="flex items-center gap-4">

@if($dateTo === now('Asia/Manila')->format('Y-m-d'))
@include('partials.live-indicator')
@endif

@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'drp',
    'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
    'submit' => 'navigate', 'navigateBase' => route('rts-report'),
])

</div>
@endpush
