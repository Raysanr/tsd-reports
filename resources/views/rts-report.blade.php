@extends('layouts.app')
@section('title', 'RTS / Delivered')
@section('subtitle', 'Upsell Amount · RTS vs Delivered · Per TSA')

@section('content')
@php
    $rangeLabel = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . ' → ' . $dateTo);
@endphp

@forelse($teamTables as $table)
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $table['name'] }}</h2>
        <div class="flex items-center gap-3">
            <input type="text" data-table-filter="rtsTable-{{ $loop->index }}" placeholder="Filter…" aria-label="Filter {{ $table['name'] }}"
                   class="w-40 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-xs font-mono text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <span class="text-xs font-mono text-slate-400">{{ $rangeLabel }}</span>
            @include('partials.table-actions', ['target' => 'rtsTable-' . $loop->index, 'name' => 'rts-delivered-' . \Illuminate\Support\Str::slug($table['name'])])
        </div>
    </div>

    @if($table['rows']->isEmpty())
    <div class="py-12 text-center font-mono text-xs text-slate-400">No TSAs configured for {{ $table['name'] }}</div>
    @else
    <div class="overflow-x-auto" id="rtsTable-{{ $loop->index }}" data-sortable-table>
    <table class="w-full border-collapse text-xs font-mono">
        <thead>
            <tr>
                <th class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide" data-sort-key="name">{{ $table['name'] }}</th>
                <th class="bg-rose-100 dark:bg-rose-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide" style="min-width:130px" data-sort-key="rts">RTS</th>
                <th class="bg-green-100 dark:bg-green-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide" style="min-width:130px" data-sort-key="delivered">Delivered</th>
            </tr>
        </thead>
        <tbody>
            @foreach($table['rows'] as $row)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-slate-700 dark:text-slate-200" data-sort-key="name" data-sort-value="{{ $row['display_name'] }}">{{ $row['display_name'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-rose-700 dark:text-rose-400" data-sort-key="rts" data-sort-value="{{ $row['rts_amount'] }}">₱{{ number_format($row['rts_amount'], 2) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-green-700 dark:text-green-400" data-sort-key="delivered" data-sort-value="{{ $row['delivered_amount'] }}">₱{{ number_format($row['delivered_amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        {{-- Total rows live in tfoot, not tbody, so a client-side column sort
             (which only re-orders <tbody> rows — see app.js) never shuffles them
             into the middle of the sorted TSA list. --}}
        <tfoot>
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
        </tfoot>
    </table>
    </div>
    @endif
</div>
@empty
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 text-center font-mono text-xs text-slate-400 mb-6">
    No teams configured.
</div>
@endforelse

{{-- GRAND TOTAL — both teams combined --}}
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">Both Teams</h2>
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

@include('partials.filter-presets', ['key' => 'rts-report', 'baseUrl' => route('rts-report')])

</div>
@endpush
