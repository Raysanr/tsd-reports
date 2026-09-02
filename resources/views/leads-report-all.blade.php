@extends('layouts.app')
@section('title', 'Leads Report')
@section('subtitle', 'All products, all teams · ' . $rangeLabel)

@section('content')

@php
    // Snapshot-only date label — see leads-report.blade.php for why this uses
    // full month names instead of $rangeLabel's abbreviated form.
    $snapshotDateLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('F j, Y')
        . ($dateFrom === $dateTo ? '' : ' – ' . \Illuminate\Support\Carbon::parse($dateTo)->format('F j, Y'));

    // Disposition pie chart for the Grand Total row — same colors/labels/build
    // logic as leads-report.blade.php's own chart (kept in sync by hand since
    // these are separate blade files for the per-team vs. cross-team views;
    // see that file for the full reasoning on the color choices).
    $dispositionColors = [
        'confirmed_via_call'     => '#16a34a',
        'upsell_confirmation'    => '#15803d',
        'call_back'              => '#4ade80',
        'call_dropped'           => '#86efac',
        'repeat_order_upsell'    => '#059669',
        'rude_customer'          => '#10b981',
        'relatives_confirmation' => '#34d399',
        'dfr'                    => '#f59e0b',
        'double_order'           => '#fb923c',
        'fsd_uncleared'          => '#fbbf24',
        'not_answering'          => '#f97316',
        'unattended'             => '#fdba74',
        'invalid_number'         => '#fcd34d',
        'excess'                 => '#e11d48',
    ];
    $dispositionLabels = collect($metricCols)->pluck('label', 'key')
        ->map(fn($label) => strip_tags(str_replace(['-<br>', '<br>'], ['', ' '], $label)));

    // Answered/unanswered only (excludes 'excess') — Restocking and Excess Leads
    // are rendered as their own explicit cells after this loop, in that order,
    // matching leads-report.blade.php's own per-product/Grand Total tables.
    $answeredCols   = collect($metricCols)->where('group', 'answered');
    $unansweredCols = collect($metricCols)->where('group', 'unanswered');

    $chartsData = [];
    if ($grandTotal['total'] > 0) {
        $labels = $data = $colors = [];
        foreach ($dispositionColors as $key => $color) {
            if (($grandTotal[$key] ?? 0) > 0) {
                $labels[] = $dispositionLabels[$key];
                $data[]   = $grandTotal[$key];
                $colors[] = $color;
            }
        }
        $chartsData[] = ['id' => 'allGrandTotalChart', 'chart' => compact('labels', 'data', 'colors')];
    }

    // Same chart data, once per per-team table below — built from each team's
    // own $teamTable['grandTotal'] rather than the combined $grandTotal above.
    foreach ($teamTables as $i => $teamTable) {
        if ($teamTable['grandTotal']['total'] <= 0) continue;
        $labels = $data = $colors = [];
        foreach ($dispositionColors as $key => $color) {
            if (($teamTable['grandTotal'][$key] ?? 0) > 0) {
                $labels[] = $dispositionLabels[$key];
                $data[]   = $teamTable['grandTotal'][$key];
                $colors[] = $color;
            }
        }
        $chartsData[] = ['id' => 'teamChart' . $i, 'chart' => compact('labels', 'data', 'colors')];
    }
@endphp

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

@include('partials.product-table', [
    'tableId' => 'productAllTable', 'rows' => $productRows, 'grandTotal' => $grandTotal,
    'answeredCols' => $answeredCols, 'unansweredCols' => $unansweredCols,
    'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'chartId' => 'allGrandTotalChart',
    'exportName' => 'leads-report-all', 'exportTitle' => 'Leads Report — All Products, All Teams',
    'snapshotDateLabel' => $snapshotDateLabel,
])

{{-- Per-team breakdown — same rows as the combined table above, split out one
     table per team so a supervisor can see their own team's products without
     scrolling/filtering the combined list. --}}
@foreach($teamTables as $i => $teamTable)
<div class="mt-8 mb-3">
    <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 font-mono">{{ $teamTable['label'] }}</h2>
</div>
@php $teamChartId = 'teamChart' . $i; @endphp
@include('partials.product-table', [
    'tableId' => 'productTeamTable' . $i, 'rows' => $teamTable['rows'], 'grandTotal' => $teamTable['grandTotal'],
    'answeredCols' => $answeredCols, 'unansweredCols' => $unansweredCols,
    'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'chartId' => $teamChartId,
    'exportName' => 'leads-report-' . \Illuminate\Support\Str::slug($teamTable['label']),
    'exportTitle' => 'Leads Report — ' . $teamTable['label'],
    'snapshotDateLabel' => $snapshotDateLabel,
])
@endforeach

@endif
@endsection

@include('partials.disposition-pie-charts')

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

    {{-- Explicit escape hatch back to the rolling window — see leads-report.blade.php
         for the full reasoning; mode now persists in session so this is the only way
         back to Last 24h once a picked date range has stuck. --}}
    @if($mode !== 'last24h')
    <button type="submit" name="range" value="last24h" title="Reset to Last 24h" aria-label="Reset to rolling last 24 hours"
            class="inline-flex items-center justify-center w-8 h-8 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-full hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
    </button>
    @endif

    {{-- Trailing cluster, same order on every report page: filters, then the date
         icon, then Sync — never split across the layout differently per page. --}}
    @include('partials.date-picker', [
        'mode' => 'range', 'id' => 'drp',
        'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
        'submit' => 'form',
    ])

    <button type="submit" title="Sync" aria-label="Sync orders"
            class="inline-flex items-center justify-center w-8 h-8 bg-yellow-700 hover:bg-yellow-800 text-white rounded-full transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
            <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 01.359.852L12.982 9.75h7.268a.75.75 0 01.548 1.262l-10.5 11.25a.75.75 0 01-1.272-.71l1.992-7.302H3.75a.75.75 0 01-.548-1.262l10.5-11.25a.75.75 0 01.913-.143z" clip-rule="evenodd"/>
        </svg>
    </button>
</form>

</div>
@endpush
