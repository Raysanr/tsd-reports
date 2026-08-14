@extends('layouts.app')
@section('title', 'RTS / Delivered')
@section('subtitle', 'Upsell Amount · RTS vs Delivered · Per TSA')

@section('content')
@php
    $rangeLabel = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . ' → ' . $dateTo);
@endphp

{{-- UI/UX review finding: a range with genuinely no RTS/Delivered activity
     rendered as a wall of ₱0.00 across every TSA in both teams — nothing
     visually distinguished "nothing happened" from "something's wrong,"
     unlike every other report page's explicit empty state. --}}
@if($grandTotalRts == 0 && $grandTotalDelivered == 0)
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 flex flex-col items-center justify-center text-center gap-3">
    <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No RTS or Delivered upsells for {{ $rangeLabel }}</p>
    <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Try a different date, or check back once orders have shipped.</p>
</div>
@else

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
    <div class="overflow-x-auto" id="rtsTable-{{ $loop->index }}" data-sortable-table data-scroll-shadow>
    <table class="w-full border-collapse text-xs font-mono">
        <thead>
            <tr>
                <th class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide" data-sort-key="name">{{ $table['name'] }}</th>
                <th class="bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide" style="min-width:130px" data-sort-key="totalSales">Total<br>Sales</th>
                <th class="bg-rose-100 dark:bg-rose-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide" style="min-width:130px" data-sort-key="rts">RTS</th>
                <th class="bg-green-100 dark:bg-green-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide" style="min-width:130px" data-sort-key="delivered">Delivered</th>
                <th class="bg-green-50 dark:bg-green-950/40 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-green-800 dark:text-green-400 uppercase tracking-wide" style="min-width:100px" data-sort-key="actualDelivery">Actual<br>Delivery</th>
                <th class="bg-blue-50 dark:bg-blue-950/40 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-blue-800 dark:text-blue-400 uppercase tracking-wide" style="min-width:100px" data-sort-key="runningDelivery">Running<br>Delivery</th>
                <th class="bg-rose-50 dark:bg-rose-950/40 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-rose-800 dark:text-rose-400 uppercase tracking-wide" style="min-width:100px" data-sort-key="actualRts">Actual<br>RTS</th>
            </tr>
        </thead>
        <tbody>
            @foreach($table['rows'] as $row)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-slate-700 dark:text-slate-200" data-sort-key="name" data-sort-value="{{ $row['display_name'] }}">{{ $row['display_name'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-slate-700 dark:text-slate-200" data-sort-key="totalSales" data-sort-value="{{ $row['total_sales'] }}">₱{{ number_format($row['total_sales'], 2) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-rose-700 dark:text-rose-400" data-sort-key="rts" data-sort-value="{{ $row['rts_amount'] }}">₱{{ number_format($row['rts_amount'], 2) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-green-700 dark:text-green-400" data-sort-key="delivered" data-sort-value="{{ $row['delivered_amount'] }}">₱{{ number_format($row['delivered_amount'], 2) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right font-semibold {{ $row['act_del_rate'] !== null ? 'text-green-700 dark:text-green-400' : 'text-slate-300 dark:text-slate-600' }}" data-sort-key="actualDelivery" data-sort-value="{{ $row['act_del_rate'] ?? '' }}">
                    {{ $row['act_del_rate'] !== null ? $row['act_del_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right font-semibold {{ $row['run_del_rate'] !== null ? 'text-blue-700 dark:text-blue-400' : 'text-slate-300 dark:text-slate-600' }}" data-sort-key="runningDelivery" data-sort-value="{{ $row['run_del_rate'] ?? '' }}">
                    {{ $row['run_del_rate'] !== null ? $row['run_del_rate'].'%' : '—' }}
                </td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right font-semibold {{ $row['act_rts_rate'] !== null ? 'text-rose-700 dark:text-rose-400' : 'text-slate-300 dark:text-slate-600' }}" data-sort-key="actualRts" data-sort-value="{{ $row['act_rts_rate'] ?? '' }}">
                    {{ $row['act_rts_rate'] !== null ? $row['act_rts_rate'].'%' : '—' }}
                </td>
            </tr>
            @endforeach
        </tbody>
        {{-- Total rows live in tfoot, not tbody, so a client-side column sort
             (which only re-orders <tbody> rows — see app.js) never shuffles them
             into the middle of the sorted TSA list. Total Sales and the three
             rate columns are spread across these same two rows (rather than a
             third "totals" row) — Actual Delivery/Running Delivery sit
             naturally with the Delivered row, Actual RTS with the RTS row,
             and Total Sales isn't tied to either specifically so it's shown
             once, on the first. --}}
        <tfoot>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total RTS</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-slate-300">₱{{ number_format($table['total_sales'], 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300">₱{{ number_format($table['total_rts'], 2) }}</td>
                <td class="border border-slate-700 px-4 py-3"></td>
                <td class="border border-slate-700 px-4 py-3"></td>
                <td class="border border-slate-700 px-4 py-3"></td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300">{{ $table['act_rts_rate'] !== null ? $table['act_rts_rate'].'%' : '—' }}</td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total Delivered</td>
                <td class="border border-slate-700 px-4 py-3"></td>
                <td class="border border-slate-700 px-4 py-3"></td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300">₱{{ number_format($table['total_delivered'], 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300">{{ $table['act_del_rate'] !== null ? $table['act_del_rate'].'%' : '—' }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-blue-300">{{ $table['run_del_rate'] !== null ? $table['run_del_rate'].'%' : '—' }}</td>
                <td class="border border-slate-700 px-4 py-3"></td>
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
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total Sales — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-slate-300" style="min-width:130px">₱{{ number_format($grandTotalSales, 2) }}</td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total RTS — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300" style="min-width:130px">₱{{ number_format($grandTotalRts, 2) }} ({{ $grandActRtsRate !== null ? $grandActRtsRate.'%' : '—' }})</td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total Delivered — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300" style="min-width:130px">₱{{ number_format($grandTotalDelivered, 2) }} ({{ $grandActDelRate !== null ? $grandActDelRate.'%' : '—' }})</td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Running Delivery Rate — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-blue-300" style="min-width:130px">{{ $grandRunDelRate !== null ? $grandRunDelRate.'%' : '—' }}</td>
            </tr>
        </tbody>
    </table>
    </div>
</div>

@endif

@endsection

@push('topbar-right')
<div class="flex items-center gap-4 flex-wrap">

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
