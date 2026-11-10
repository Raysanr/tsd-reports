@extends('layouts.app')
@section('title', 'RTS / Delivered')
@section('subtitle', 'Upsell Amount · RTS vs Delivered · Per TSA')

@section('content')
@php
    $rangeLabel = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . ' → ' . $dateTo);
    // Running % over the selected range, by amount: share of (RTS + Delivered).
    $pct = fn ($part, $rts, $del) => ($rts + $del) > 0 ? $part / ($rts + $del) * 100 : null;
    $fmtPct = fn ($v) => $v === null ? '—' : number_format($v, 2) . '%';
@endphp

{{-- UI/UX review finding: a range with genuinely no RTS/Delivered activity
     rendered as a wall of ₱0.00 across every TSA in both teams — nothing
     visually distinguished "nothing happened" from "something's wrong,"
     unlike every other report page's explicit empty state. --}}
@if($grandTotalRts == 0 && $grandTotalDelivered == 0 && $grandShippedQty == 0)
<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-sm py-16 flex flex-col items-center justify-center text-center gap-3">
    <svg class="w-10 h-10 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
    </svg>
    <p class="text-sm font-mono text-slate-400">No RTS, Delivered or Shipped upsells for {{ $rangeLabel }}</p>
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
    <div class="overflow-x-auto" id="rtsTable-{{ $loop->index }}" data-sortable-table data-scroll-shadow
         data-dd-team="{{ $table['team_key'] }}" data-dd-endpoint="{{ route('rts-report.drilldown') }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
    <table class="w-full border-collapse text-xs font-mono">
        <thead>
            <tr>
                <th rowspan="2" class="bg-yellow-100 dark:bg-yellow-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-left text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide" data-sort-key="name">{{ $table['name'] }}</th>
                <th rowspan="2" class="bg-rose-100 dark:bg-rose-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide" style="min-width:130px" data-sort-key="rts">RTS</th>
                <th rowspan="2" class="bg-green-100 dark:bg-green-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide" style="min-width:130px" data-sort-key="delivered">Delivered</th>
                <th colspan="2" class="bg-sky-100 dark:bg-sky-900/50 text-sky-900 dark:text-sky-200 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold uppercase tracking-wide text-center" style="text-align:center">Shipped</th>
                <th rowspan="2" class="bg-green-100 dark:bg-green-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-green-900 dark:text-green-200 uppercase tracking-wide whitespace-nowrap" style="min-width:110px" data-sort-key="del_pct">Running Del. %</th>
                <th rowspan="2" class="bg-rose-100 dark:bg-rose-900/50 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold text-rose-900 dark:text-rose-200 uppercase tracking-wide whitespace-nowrap" style="min-width:110px" data-sort-key="rts_pct">Running RTS %</th>
            </tr>
            <tr>
                <th class="bg-sky-100 dark:bg-sky-900/50 text-sky-900 dark:text-sky-200 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold uppercase tracking-wide" style="min-width:70px" data-sort-key="shipped_qty">Qty</th>
                <th class="bg-sky-100 dark:bg-sky-900/50 text-sky-900 dark:text-sky-200 border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-[11px] font-bold uppercase tracking-wide" style="min-width:130px" data-sort-key="shipped">Sales</th>
            </tr>
        </thead>
        <tbody>
            @foreach($table['rows'] as $row)
            @php
                $delPct = $pct($row['delivered_amount'], $row['rts_amount'], $row['delivered_amount']);
                $rtsPct = $pct($row['rts_amount'], $row['rts_amount'], $row['delivered_amount']);
            @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-slate-700 dark:text-slate-200" data-sort-key="name" data-sort-value="{{ $row['display_name'] }}">{{ $row['display_name'] }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-rose-700 dark:text-rose-400 {{ $row['rts_amount'] ? 'cursor-pointer hover:bg-rose-50 dark:hover:bg-rose-950/30' : '' }}" data-sort-key="rts" data-sort-value="{{ $row['rts_amount'] }}" @if($row['rts_amount']) data-drilldown data-dd-tsa="{{ $row['tsa_key'] }}" data-dd-column="rts" title="Click to see the orders behind this amount" @endif>₱{{ number_format($row['rts_amount'], 2) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-green-700 dark:text-green-400 {{ $row['delivered_amount'] ? 'cursor-pointer hover:bg-green-50 dark:hover:bg-green-950/30' : '' }}" data-sort-key="delivered" data-sort-value="{{ $row['delivered_amount'] }}" @if($row['delivered_amount']) data-drilldown data-dd-tsa="{{ $row['tsa_key'] }}" data-dd-column="delivered" title="Click to see the orders behind this amount" @endif>₱{{ number_format($row['delivered_amount'], 2) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-sky-700 dark:text-sky-400 {{ $row['shipped_qty'] ? 'cursor-pointer hover:bg-sky-50 dark:hover:bg-sky-950/30' : '' }}" data-sort-key="shipped_qty" data-sort-value="{{ $row['shipped_qty'] }}" @if($row['shipped_qty']) data-drilldown data-dd-tsa="{{ $row['tsa_key'] }}" data-dd-column="shipped" title="Click to see the orders behind this amount" data-dd-cell-product="qty" @endif>{{ $row['shipped_qty'] ?: '' }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-sky-700 dark:text-sky-400 {{ $row['shipped_qty'] ? 'cursor-pointer hover:bg-sky-50 dark:hover:bg-sky-950/30' : '' }}" data-sort-key="shipped" data-sort-value="{{ $row['shipped_amount'] }}" @if($row['shipped_qty']) data-drilldown data-dd-tsa="{{ $row['tsa_key'] }}" data-dd-column="shipped" title="Click to see the orders behind this amount" @endif>₱{{ number_format($row['shipped_amount'], 2) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-green-700 dark:text-green-400" data-sort-key="del_pct" data-sort-value="{{ $delPct ?? -1 }}">{{ $fmtPct($delPct) }}</td>
                <td class="border border-slate-200 dark:border-slate-700 px-4 py-2.5 text-right text-rose-700 dark:text-rose-400" data-sort-key="rts_pct" data-sort-value="{{ $rtsPct ?? -1 }}">{{ $fmtPct($rtsPct) }}</td>
            </tr>
            @endforeach
        </tbody>
        {{-- Total rows live in tfoot, not tbody, so a client-side column sort
             (which only re-orders <tbody> rows — see app.js) never shuffles them
             into the middle of the sorted TSA list. --}}
        <tfoot>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300 {{ $table['total_rts'] ? 'cursor-pointer hover:bg-rose-950/40' : '' }}" @if($table['total_rts']) data-drilldown data-dd-cell-product="team-{{ $table['team_key'] }}" data-dd-column="rts" title="Click to see the orders behind this amount" @endif>₱{{ number_format($table['total_rts'], 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300 {{ $table['total_delivered'] ? 'cursor-pointer hover:bg-green-950/40' : '' }}" @if($table['total_delivered']) data-drilldown data-dd-cell-product="team-{{ $table['team_key'] }}" data-dd-column="delivered" title="Click to see the orders behind this amount" @endif>₱{{ number_format($table['total_delivered'], 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-sky-300 {{ $table['total_shipped_qty'] ? 'cursor-pointer hover:bg-sky-950/40' : '' }}" @if($table['total_shipped_qty']) data-drilldown data-dd-cell-product="team-{{ $table['team_key'] }}-qty" data-dd-column="shipped" title="Click to see the orders behind this amount" @endif>{{ $table['total_shipped_qty'] ?: '' }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-sky-300 {{ $table['total_shipped_qty'] ? 'cursor-pointer hover:bg-sky-950/40' : '' }}" @if($table['total_shipped_qty']) data-drilldown data-dd-cell-product="team-{{ $table['team_key'] }}" data-dd-column="shipped" title="Click to see the orders behind this amount" @endif>₱{{ number_format($table['total_shipped_amount'], 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300">{{ $fmtPct($pct($table['total_delivered'], $table['total_rts'], $table['total_delivered'])) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300">{{ $fmtPct($pct($table['total_rts'], $table['total_rts'], $table['total_delivered'])) }}</td>
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
    <div id="rtsGrandTable" data-dd-team="all" data-dd-endpoint="{{ route('rts-report.drilldown') }}" data-dd-date-from="{{ $dateFrom }}" data-dd-date-to="{{ $dateTo }}">
    <table class="w-full border-collapse text-xs font-mono">
        <tbody>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total RTS — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300 {{ $grandTotalRts ? 'cursor-pointer hover:bg-rose-950/40' : '' }}" style="min-width:130px" @if($grandTotalRts) data-drilldown data-dd-column="rts" title="Click to see the orders behind this amount" @endif>₱{{ number_format($grandTotalRts, 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-rose-300 whitespace-nowrap" style="min-width:150px">Running RTS % {{ $fmtPct($pct($grandTotalRts, $grandTotalRts, $grandTotalDelivered)) }}</td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total Delivered — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300 {{ $grandTotalDelivered ? 'cursor-pointer hover:bg-green-950/40' : '' }}" style="min-width:130px" @if($grandTotalDelivered) data-drilldown data-dd-column="delivered" title="Click to see the orders behind this amount" @endif>₱{{ number_format($grandTotalDelivered, 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-green-300 whitespace-nowrap" style="min-width:150px">Running Del. % {{ $fmtPct($pct($grandTotalDelivered, $grandTotalRts, $grandTotalDelivered)) }}</td>
            </tr>
            <tr class="bg-slate-900 text-white font-bold">
                <td class="border border-slate-700 px-4 py-3 uppercase tracking-wider text-[11px]">Total Shipped — Both Teams</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-sky-300 {{ $grandShippedQty ? 'cursor-pointer hover:bg-sky-950/40' : '' }}" style="min-width:130px" @if($grandShippedQty) data-drilldown data-dd-column="shipped" title="Click to see the orders behind this amount" @endif>₱{{ number_format($grandShippedAmount, 2) }}</td>
                <td class="border border-slate-700 px-4 py-3 text-right text-sky-300 whitespace-nowrap {{ $grandShippedQty ? 'cursor-pointer hover:bg-sky-950/40' : '' }}" style="min-width:150px" @if($grandShippedQty) data-drilldown data-dd-column="shipped" data-dd-cell-product="qty" title="Click to see the orders behind this amount" @endif>Qty {{ $grandShippedQty }}</td>
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
