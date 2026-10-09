@extends('layouts.data')
@section('title', 'DSPPR - TSM Report')
@section('subtitle', 'Daily sales per product report — every figure auto-saves as you type')

@section('content')

{{-- Sticky-left product column, same convention as leads-report.blade.php
     / tsa-performance*.blade.php (app.blade.php's own .sticky-col), just
     defined locally since this page extends layouts.data (calls.css),
     which doesn't carry that rule and has no @stack('head') to push a
     <style> tag into the real <head>. --}}
<style>
    .dsppr-sticky { position: sticky; left: 0; z-index: 5; }
    thead .dsppr-sticky { z-index: 25; }
    .dsppr-sticky-body { background-color: #fff; }
    .dark .dsppr-sticky-body { background-color: #0f172a; }
    tr:hover > .dsppr-sticky-body { background-color: #f8fafc; }
    .dark tr:hover > .dsppr-sticky-body { background-color: #1e293b; }
    .dsppr-sticky-footer { background-color: #000; }
    /* Tabular figures for every number column (ui-ux-pro-max: number-tabular)
       — prevents digits reflowing width as auto-save updates a cell. */
    .dsppr-table { font-variant-numeric: tabular-nums; }
    .dsppr-scroller { cursor: grab; }
    .dsppr-scroller.cursor-grabbing { cursor: grabbing; }

    /* Borders simplified to "one separator per day" (explicit request,
       2026-09-24: "make it the line or border is make it thicker like
       that it is only 1 border that is separation of per date") —
       replaces the earlier all-sides grid-on-every-cell look (which read
       as too busy against the pale header colors) with just a thin
       bottom rule per row (keeps rows scannable) and ONE thick vertical
       rule at each day's own right edge (.dsppr-day-end, applied to the
       last of every 13-column group) — no internal vertical lines
       between a day's own columns at all. */
    .dsppr-table th, .dsppr-table td { border: none; border-bottom: 1px solid #cbd5e1; }
    .dark .dsppr-table th, .dark .dsppr-table td { border-bottom-color: #475569; }
    .dsppr-table .dsppr-day-end { border-right: 3px solid #334155; }
    .dark .dsppr-table .dsppr-day-end { border-right-color: #cbd5e1; }

    /* Combine-products drag target feedback (explicit request, 2026-09-26:
       "drag the TO-01 to TO-02") — a visible highlight while dragging one
       product's own name cell over another's, so it's obvious a drop will
       do something before the modal appears. */
    .dsppr-draggable { user-select: none; }
    .dsppr-drop-hover { outline: 2px dashed #d97706; outline-offset: -2px; background-color: rgba(217,119,6,0.08); }

    /* table-actions' own icons default to a light-background palette —
       placed inside this page's own black header bar (explicit request,
       2026-10-05: icons belong in the header, right-aligned, and more
       visible) they need a bright near-white variant plus a subtle
       frosted backing instead, same treatment as Summary Sales Report's
       own tsr-dark-header-actions. */
    .dsppr-dark-header-actions button { color: #f1f5f9; background-color: rgba(255,255,255,0.08); }
    .dsppr-dark-header-actions button svg { stroke-width: 2.1; }
    .dsppr-dark-header-actions button:hover { color: #fff; background-color: rgba(255,255,255,0.18); }
</style>

@php
    $fmtMoney = fn ($n) => number_format((float) $n, 2);
    $fmtPct   = fn ($n) => number_format(((float) $n) * 100, 2) . '%';

    // Snapshot-only date label for every table-actions PNG export on this
    // page (explicit request, 2026-10-05) — same convention as Leads
    // Report's own $snapshotDateLabel.
    $snapshotDateLabel = \Illuminate\Support\Carbon::parse($dateFrom)->format('F j, Y')
        . ($dateFrom === $dateTo ? '' : ' – ' . \Illuminate\Support\Carbon::parse($dateTo)->format('F j, Y'));

    // One repeating 11-column set per day — shared between the header
    // group row and every product row's per-day cell block, so the two
    // can never drift on order/count. Ads Spent and Actual Cost Per Lead
    // removed 2026-09-26 per explicit request. headerBg matches the real
    // sheet's own column-group colors exactly (explicit request,
    // 2026-09-24: "colors must be like in the sheets"): pale yellow for
    // the plain $ columns, blue-gray for NI%, dusty rose for every
    // lead/rate column from Total Leads onward.
    // Total Orders/Total Leads/Catered Leads are no longer manual inputs
    // (explicit request, 2026-10-01: "i want to make it automated based
    // on the leads report page in TSD LEADS REPORT") — locked read-only
    // same as Excess Leads/Pick-up/Conversion/Upselling Rate already
    // were, computed from real Order data via
    // ProductPerformance::dsPprRow().
    $dayColumns = [
        ['key' => 'gross_sales', 'label' => 'Gross Sales', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'net_income', 'label' => 'Net Income', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'ni_pct', 'label' => 'NI %', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-slate-200 dark:bg-slate-600'],
        ['key' => 'total_orders', 'label' => 'Total Orders', 'editable' => false, 'int' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'aov', 'label' => 'AOV', 'editable' => false, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'total_leads', 'label' => 'Total Leads', 'editable' => false, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'catered_leads', 'label' => 'Catered Leads', 'editable' => false, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'excess_leads', 'label' => 'Excess Leads', 'editable' => false, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'conversion_rate', 'label' => 'Conversion Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
    ];

    // TIKTOK ORDERS row's own column set (explicit request, 2026-10-05) —
    // same 11 columns/order as $dayColumns above, but Total Orders/Total
    // Leads/Catered Leads are editable here too (no real Product/Order
    // data backs this row — see DsPprReportController's own doc comment
    // on updateTiktok()). Excess Leads/Pick-up/Conversion/Upselling Rate
    // (explicit follow-up, 2026-10-07: "make it editable") are ALSO
    // editable now via a dedicated OVERRIDE column each (not the same
    // column real-product rows never have in the first place) — 'override'
    // marks these 4 so the render loop below seeds from $entry's own
    // *_override field (not $raw/$d) and tags data-pct for the 3
    // percentages — see DsPprTiktokEntry::toRawRowWithOverrides()'s own
    // doc comment for the "blank clears the override, falls back to the
    // formula" rule these 4 follow, same "formula unless manually
    // overridden" pattern as Cost Breakdown/Expected Income's own
    // overrides elsewhere in this app.
    $tiktokDayColumns = [
        ['key' => 'gross_sales', 'label' => 'Gross Sales', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'net_income', 'label' => 'Net Income', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'ni_pct', 'label' => 'NI %', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-slate-200 dark:bg-slate-600'],
        ['key' => 'total_orders', 'label' => 'Total Orders', 'editable' => true, 'int' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'aov', 'label' => 'AOV', 'editable' => false, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'total_leads', 'label' => 'Total Leads', 'editable' => true, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'catered_leads', 'label' => 'Catered Leads', 'editable' => true, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'excess_leads', 'label' => 'Excess Leads', 'editable' => true, 'override' => true, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'editable' => true, 'override' => true, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'conversion_rate', 'label' => 'Conversion Rate', 'editable' => true, 'override' => true, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'editable' => true, 'override' => true, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
    ];
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap" id="dsPprFilterForm">
        <div>
            <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Team</label>
            <select name="team" onchange="this.form.submit()"
                    class="text-sm font-mono border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
                <option value="">All Teams</option>
                @foreach($teams as $slug => $team)
                <option value="{{ $slug }}" @selected($selectedTeam === $slug)>{{ $team['name'] }}</option>
                @endforeach
            </select>
        </div>
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    </form>
    <div class="flex items-center gap-3">
        <span class="hidden sm:inline-flex items-center gap-1.5 text-[11px] font-mono text-ink-muted dark:text-slate-400">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l-5 5m0 0l5 5m-5-5h18m-5-9l5 5m0 0l-5 5"/></svg>
            Drag/scroll right to see more days
        </span>
        <span id="dsPprSaveStatus" class="text-xs font-mono text-slate-400 dark:text-slate-500 min-h-[1.25rem]"></span>
    </div>
</div>

{{-- Running summary block — the source sheet's own "Monthly Running Sales
     per Product Report" (a range total, not a single day; here the range
     is whatever's picked above rather than always MTD). Colors matched to
     the real sheet (explicit request, 2026-09-24: "colors must be like in
     the sheets"): NI% = pale blue-gray, Actual Cost Per Lead = solid gray,
     Total Leads/Catered/Excess/Pick-up/Conversion/Upselling = dusty rose —
     same 3-color grouping repeated in every daily table below. --}}
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-8">
    <div class="bg-black text-white font-mono font-bold text-sm tracking-wide py-2.5 flex items-center">
        <span class="flex-1 text-center pl-18">TELESALES RUNNING PERFORMANCE</span>
        <div class="shrink-0 pr-2 dsppr-dark-header-actions">
            @include('partials.table-actions', ['target' => 'dsPprSummaryTable', 'name' => 'telesales-running-performance', 'title' => 'Telesales Running Performance', 'subtitle' => $snapshotDateLabel])
        </div>
    </div>
    <div class="bg-yellow-300 dark:bg-yellow-500 text-center font-mono font-bold text-xs tracking-wide py-2 text-ink">
        {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($dateTo)->format('M j, Y') }}
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-[13px] font-mono border-collapse dsppr-table" id="dsPprSummaryTable">
            <thead>
                <tr class="text-ink dark:text-slate-950">
                    <th class="dsppr-sticky bg-yellow-200 dark:bg-yellow-700 text-left px-3 py-2 font-bold whitespace-nowrap">Product</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Gross Sales</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Net Income</th>
                    <th class="bg-slate-200 dark:bg-slate-600 text-right px-3 py-2 font-bold whitespace-nowrap">NI %</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Total Orders</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">AOV</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Total Leads</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Catered Leads</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Excess Leads</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Pick-up Rate</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Conversion Rate</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Upselling Rate</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                @php $d = $row['derived']; @endphp
                <tr class="dsppr-summary-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-row-key="{{ $row['group'] ? 'g'.$row['group']->id : 'p'.$row['products']->first()->id }}">
                    <td class="dsppr-sticky dsppr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">
                        {{ strtoupper($row['label']) }}
                        @if($row['group'])
                            <button type="button" data-ungroup="{{ $row['group']->id }}" title="Ungroup"
                                    class="dsppr-ungroup ml-1 text-[10px] text-ink-muted/60 hover:text-red-600 dark:hover:text-red-400">&times;</button>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="gross_sales">{{ $fmtMoney($d['gross_sales']) }}</td>
                    {{-- Net Income: black by default, red only if negative
                         — no green tier (explicit request, 2026-10-03:
                         "in the dsppr too make it all black," confirmed
                         "always black but if negative it is red"; this
                         page's own sheet has no 4,200-style threshold the
                         way Summary Sales Report does, unlike that page's
                         3-tier rule). --}}
                    <td class="px-3 py-2 text-right {{ $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100' }}" data-out="net_income">{{ $fmtMoney($d['net_income']) }}</td>
                    <td class="px-3 py-2 text-right {{ $d['ni_pct'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100' }}" data-out="ni_pct">{{ $fmtPct($d['ni_pct']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="total_orders">{{ number_format($d['total_orders']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="aov">{{ $fmtMoney($d['aov']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="total_leads">{{ number_format($d['total_leads']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="catered_leads">{{ number_format($d['catered_leads']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="excess_leads">{{ number_format($d['excess_leads']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="pickup_rate">{{ $fmtPct($d['pickup_rate']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="conversion_rate">{{ $fmtPct($d['conversion_rate']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="upselling_rate">{{ $fmtPct($d['upselling_rate']) }}</td>
                </tr>
                @endforeach
                {{-- TIKTOK ORDERS — manual-only row (explicit request,
                     2026-10-05), NOT a real Product, see
                     DsPprReportController's own doc comment on
                     updateTiktok(). --}}
                @php $td = $tiktokRow['derived']; @endphp
                <tr class="dsppr-summary-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-row-key="tiktok">
                    <td class="dsppr-sticky dsppr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">TIKTOK ORDERS</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="gross_sales">{{ $fmtMoney($td['gross_sales']) }}</td>
                    <td class="px-3 py-2 text-right {{ $td['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100' }}" data-out="net_income">{{ $fmtMoney($td['net_income']) }}</td>
                    <td class="px-3 py-2 text-right {{ $td['ni_pct'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100' }}" data-out="ni_pct">{{ $fmtPct($td['ni_pct']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="total_orders">{{ number_format($td['total_orders']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="aov">{{ $fmtMoney($td['aov']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="total_leads">{{ number_format($td['total_leads']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="catered_leads">{{ number_format($td['catered_leads']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="excess_leads">{{ number_format($td['excess_leads']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="pickup_rate">{{ $fmtPct($td['pickup_rate']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="conversion_rate">{{ $fmtPct($td['conversion_rate']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="upselling_rate">{{ $fmtPct($td['upselling_rate']) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="bg-black text-white font-bold dsppr-overall-total-row">
                    <td class="dsppr-sticky dsppr-sticky-footer px-3 py-2.5">OVERALL TOTAL</td>
                    <td class="px-3 py-2.5 text-right" data-out="gross_sales">{{ $fmtMoney($overallTotal['gross_sales']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $overallTotal['net_income'] < 0 ? 'text-red-400' : '' }}" data-out="net_income">{{ $fmtMoney($overallTotal['net_income']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $overallTotal['ni_pct'] < 0 ? 'text-red-400' : '' }}" data-out="ni_pct">{{ $fmtPct($overallTotal['ni_pct']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="total_orders">{{ number_format($overallTotal['total_orders']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="aov">{{ $fmtMoney($overallTotal['aov']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="total_leads">{{ number_format($overallTotal['total_leads']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="catered_leads">{{ number_format($overallTotal['catered_leads']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="excess_leads">{{ number_format($overallTotal['excess_leads']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="pickup_rate">{{ $fmtPct($overallTotal['pickup_rate']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="conversion_rate">{{ $fmtPct($overallTotal['conversion_rate']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="upselling_rate">{{ $fmtPct($overallTotal['upselling_rate']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{-- Daily entry — one table PER 7-day chunk (explicit request, 2026-09-24:
     "make it too only 7 days that is like can be drag to right and after
     that the next is in the down part"), each independently horizontally-
     scrollable/draggable, stacked top to bottom for later chunks. Product
     names frozen on the left via dsppr-sticky within each chunk's own
     table. Colors matched to the real sheet: date band = bright yellow,
     Gross Sales/Net Income/Ads Spent/Total Orders/AOV headers = pale
     yellow, NI% = pale blue-gray, Actual Cost Per Lead = solid gray,
     Total Leads through Upselling Rate = dusty rose. Only 6 of the 13
     columns per day are real <input>s; the rest are derived/read-only. --}}
@foreach($dateChunks as $chunkIndex => $dates)
<div class="flex items-center justify-end gap-1 mb-2">
    @include('partials.table-actions', ['target' => 'dsPprScroller-' . $chunkIndex, 'name' => 'dsppr-daily-entry-' . ($chunkIndex + 1), 'title' => 'DSPPR - TSM Report', 'subtitle' => $dates->first()->format('F j, Y') . ($dates->count() > 1 ? ' – ' . $dates->last()->format('F j, Y') : '')])
</div>
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-6">
    <div class="overflow-x-auto dsppr-scroller" id="dsPprScroller-{{ $chunkIndex }}">
        <table class="text-[13px] font-mono border-collapse dsppr-table dsppr-days-table"
               data-update-url-template="{{ route('data.dsppr.update', ['product' => '__PRODUCT__', 'date' => '__DATE__']) }}"
               data-update-tiktok-url-template="{{ route('data.dsppr.update-tiktok', ['date' => '__DATE__']) }}">
            <thead>
                <tr>
                    <th rowspan="2" class="dsppr-sticky bg-yellow-300 dark:bg-yellow-600 text-left px-3 py-2 font-bold text-ink whitespace-nowrap align-bottom">Product</th>
                    @foreach($dates as $date)
                    @php $dateLocked = in_array($date->toDateString(), $lockedDates, true); @endphp
                    <th colspan="{{ count($dayColumns) }}" data-dsppr-date-header data-date="{{ $date->toDateString() }}" data-locked="{{ $dateLocked ? '1' : '0' }}"
                        class="bg-yellow-300 dark:bg-yellow-600 text-center font-bold text-ink px-3 py-2 whitespace-nowrap dsppr-day-end relative">
                        <span class="inline-flex items-center justify-center gap-2 w-full">
                            <span class="flex-1 text-center">{{ $date->format('D, M j') }}</span>
                            {{-- Per-date lock (explicit follow-up,
                                 2026-10-07: "i want to make it per date
                                 like the lock icon is in the dates right
                                 side") — freezes every product's own
                                 input for THIS one date only (plus TIKTOK
                                 ORDERS' own fields for the same date),
                                 other dates stay independently editable.
                                 Same cross-fade icon swap as every other
                                 lock on this app. --}}
                            <button type="button" data-dsppr-lock-toggle data-date="{{ $date->toDateString() }}" data-locked="{{ $dateLocked ? '1' : '0' }}"
                                    title="{{ $dateLocked ? 'Unlock this date' : 'Lock this date' }}"
                                    aria-label="{{ $dateLocked ? 'Unlock this date' : 'Lock this date' }}"
                                    class="shrink-0 p-1 rounded-md text-ink/70 hover:text-ink hover:bg-black/10 dark:hover:bg-white/10 transition-colors cursor-pointer">
                                <span data-dsppr-lock-icon style="display:inline-flex; transition:opacity 180ms ease;">
                                    @if($dateLocked)
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                                    </svg>
                                    @else
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                                    </svg>
                                    @endif
                                </span>
                            </button>
                        </span>
                    </th>
                    @endforeach
                </tr>
                <tr>
                    @foreach($dates as $date)
                        @foreach($dayColumns as $i => $col)
                        <th class="text-right px-3 py-2 font-bold whitespace-nowrap text-ink dark:text-slate-950 {{ $col['headerBg'] }} {{ $i === count($dayColumns) - 1 ? 'dsppr-day-end' : '' }}">
                            {{ $col['label'] }}
                        </th>
                        @endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                @php
                    $isGroup = (bool) $row['group'];
                    // A grouped row's own product-name cell is the ONLY
                    // drop target that matters, but the drag SOURCE has to
                    // be an ungrouped row (dragging a group onto anything
                    // doesn't make sense — see pj.js's own drag handlers
                    // for why draggable is only ever true when !$isGroup).
                    $rowKey = $isGroup ? 'g' . $row['group']->id : 'p' . $row['products']->first()->id;
                @endphp
                <tr class="dsppr-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60"
                    data-row-key="{{ $rowKey }}"
                    {{-- Explicit request, 2026-09-29: "make it editable
                         because in the side of users the merged products is
                         only 1 product only" — a group row is now editable
                         too, saving to its own FIRST member product
                         (deterministic: $row['products'] keeps the overall
                         product list's own sort order, not merge order).
                         The other member(s)' own previously-saved numbers
                         are untouched underneath; every new edit on this
                         row goes to the one primary product from now on. --}}
                    data-product-id="{{ $row['products']->first()->id }}"
                    @if($isGroup) data-group-id="{{ $row['group']->id }}" @endif>
                    {{-- A group row's own cell is a valid DROP TARGET too
                         (explicit follow-up, 2026-09-26: "what about more
                         than 2 combine") — dragging a 3rd ungrouped product
                         onto an already-combined row joins that SAME group
                         instead of opening the name-picker again. It's
                         never a drag SOURCE itself (no draggable/dsppr-
                         draggable) — dragging a whole group onto something
                         else isn't a supported gesture. --}}
                    <td class="dsppr-sticky dsppr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap {{ !$isGroup ? 'dsppr-draggable cursor-grab' : '' }}"
                        data-drop-target="{{ $rowKey }}"
                        @if(!$isGroup) draggable="true" @endif>
                        {{ strtoupper($row['label']) }}
                        @if($isGroup)
                            <button type="button" data-ungroup="{{ $row['group']->id }}" title="Ungroup"
                                    class="dsppr-ungroup ml-1 text-[10px] text-ink-muted/60 hover:text-red-600 dark:hover:text-red-400">&times;</button>
                        @endif
                    </td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            $emptyRow = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0];
                            // A group's own DISPLAYED total ($d) still pools
                            // EVERY member product's own entry before
                            // summing — same reasoning as the controller's
                            // own $dailyRows (a group's daily figure is
                            // never just one member's, even if only one
                            // member happens to have data for this day).
                            // $raw (what an editable input reads from/saves
                            // to) is the FIRST member's own entry only —
                            // explicit request, 2026-09-29: a group is
                            // editable as if it's one product now, and that
                            // one product is always the first member (same
                            // $row['products']->first() the row's own
                            // data-product-id above already commits to).
                            //
                            // Total Orders/Total Leads/Catered Leads/Excess
                            // Leads/rates no longer come from $dailyByKey at
                            // all (explicit request, 2026-10-01: automated
                            // from real Order data) — precomputed by the
                            // controller, keyed by THIS ROW's own key (same
                            // 'g{id}'/'p{id}' as $rowKey above), so a
                            // grouped row's own figures are the group's
                            // single dedup'd real-data row (every member
                            // pooled at once), never per-member-then-summed
                            // (same "a cross-team combo order only counts
                            // once" reasoning as the controller's own doc
                            // comment).
                            // $real merged in AFTER sum()/derive() below,
                            // NOT alongside each member's own entry before
                            // summing — merging it onto every member first
                            // would sum N identical copies of the same
                            // pooled figure for an N-member group,
                            // inflating Total Orders/Leads/rates by a
                            // factor of N (root-caused live, 2026-10-01 —
                            // see the TOTAL footer row's own identical fix
                            // further down this file for the full
                            // writeup).
                            $real = $realByRowKeyAndDate[$rowKey . ':' . $dateStr] ?? [];
                            $pooled = $row['products']->map(fn ($p) => $dailyByKey->get($p->id . ':' . $dateStr)?->toArray() ?? $emptyRow);
                            $raw = $pooled->first() ?: $emptyRow;
                            $d = array_merge(
                                $isGroup ? \App\Support\DsPprCalculator::sum($pooled->all()) : \App\Support\DsPprCalculator::derive($raw),
                                $real
                            );
                            $d['aov'] = $d['total_orders'] > 0 ? $d['gross_sales'] / $d['total_orders'] : 0.0;
                        @endphp
                        @foreach($dayColumns as $i => $col)
                            @php $borderClass = $i === count($dayColumns) - 1 ? 'dsppr-day-end' : ''; @endphp
                            @if($col['editable'])
                            @php
                                // Net Income alone gets live negative=red
                                // coloring on the INPUT itself, not just
                                // the read-only derived spans elsewhere
                                // (explicit request, 2026-10-01: "in the
                                // net income column i want to have like
                                // can input negative number and if
                                // negative is color red"). No green tier —
                                // explicit follow-up, 2026-10-03: "in the
                                // dsppr too make it all black," confirmed
                                // "always black but if negative it is
                                // red" (unlike Summary Sales Report's own
                                // 3-tier black/red/green rule). Initial
                                // color seeded from $raw here, kept live as
                                // the user types via liveFormatMoney()'s
                                // own JS below.
                                $inputColor = $col['key'] === 'net_income'
                                    ? ($raw[$col['key']] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100')
                                    : 'text-ink dark:text-slate-100';
                            @endphp
                            <td class="px-2 py-1.5 {{ $borderClass }}">
                                <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                                       value="{{ ($col['money'] ?? false) ? number_format($raw[$col['key']], 2) : $raw[$col['key']] }}"
                                       data-field="{{ $col['key'] }}" data-date="{{ $dateStr }}" @if($col['money'] ?? false) data-money="1" @endif
                                       class="dsppr-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold {{ $inputColor }} focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                            </td>
                            @else
                            @php
                                $cellColor = match (true) {
                                    $col['key'] === 'net_income' => $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100',
                                    $col['key'] === 'ni_pct' && $d['ni_pct'] < 0 => 'text-red-600 dark:text-red-400',
                                    default => 'text-ink dark:text-slate-100',
                                };
                            @endphp
                            <td class="px-3 py-2 text-right {{ $borderClass }} {{ $cellColor }}"
                                data-out="{{ $col['key'] }}" data-date="{{ $dateStr }}">
                                {{ ($col['pct'] ?? false) ? $fmtPct($d[$col['key']]) : (($col['int'] ?? false) ? number_format($d[$col['key']]) : $fmtMoney($d[$col['key']])) }}
                            </td>
                            @endif
                        @endforeach
                    @endforeach
                </tr>
                @endforeach
                {{-- TIKTOK ORDERS — manual-only row, every column editable
                     (unlike real products' 2 of 11). No drag/drop/combine
                     support — doesn't make sense for a non-product row. --}}
                <tr class="dsppr-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-row-key="tiktok">
                    <td class="dsppr-sticky dsppr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">TIKTOK ORDERS</td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            $raw = $tiktokDailyByKey->get($dateStr, ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0]);
                            $d = \App\Support\DsPprCalculator::derive($raw);
                            $tiktokEntry = $tiktokEntriesByDate->get($dateStr);
                        @endphp
                        @foreach($tiktokDayColumns as $i => $col)
                            @php $borderClass = $i === count($tiktokDayColumns) - 1 ? 'dsppr-day-end' : ''; @endphp
                            @if($col['editable'] && ($col['override'] ?? false))
                            {{-- Excess Leads/Pick-up/Conversion/Upselling
                                 Rate overrides (explicit request,
                                 2026-10-07: "make it editable") — seeded
                                 from the real entry's own *_override
                                 column (NOT $raw/$d, which only carry the
                                 CURRENTLY-WINNING value, override or
                                 formula, with no way to tell which);
                                 blank/empty when no override is set,
                                 placeholder shows the live formula result
                                 instead so the field never just looks
                                 confusingly empty. data-pct="1" (not
                                 data-money) — saveField()'s own
                                 OVERRIDE_FIELD_MAP branch divides a typed
                                 whole-percent number by 100 before saving,
                                 same fraction-storage convention as every
                                 other %, int (excess_leads) needs neither. --}}
                            @php
                                $overrideColumn = $col['key'] . '_override';
                                $overrideValue = $tiktokEntry?->{$overrideColumn};
                                // The "%" suffix is seeded server-side too
                                // (explicit follow-up, 2026-10-07: "why is
                                // it the % is not visible ... it should be
                                // visible not just by number only"), not
                                // just added live as the user types —
                                // liveFormatPct()'s own JS only reformats
                                // on an `input` event, so a saved override
                                // would otherwise show bare "50" until the
                                // next keystroke.
                                $overrideSeed = $overrideValue === null ? '' : (($col['pct'] ?? false) ? number_format($overrideValue * 100, 2) . '%' : $overrideValue);
                                $placeholder = ($col['pct'] ?? false) ? $fmtPct($d[$col['key']]) : number_format($d[$col['key']]);
                            @endphp
                            <td class="px-2 py-1.5 {{ $borderClass }}">
                                <input type="text" inputmode="decimal" value="{{ $overrideSeed }}" placeholder="{{ $placeholder }}"
                                       data-field="{{ $col['key'] }}" data-date="{{ $dateStr }}" @if($col['pct'] ?? false) data-pct="1" @endif
                                       title="Blank uses the computed {{ $col['label'] }} ({{ $placeholder }})"
                                       class="dsppr-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 placeholder:text-ink-muted/60 dark:placeholder:text-slate-500 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                            </td>
                            @elseif($col['editable'])
                            @php
                                $inputColor = $col['key'] === 'net_income'
                                    ? ($raw[$col['key']] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100')
                                    : 'text-ink dark:text-slate-100';
                            @endphp
                            <td class="px-2 py-1.5 {{ $borderClass }}">
                                <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                                       value="{{ ($col['money'] ?? false) ? number_format($raw[$col['key']], 2) : $raw[$col['key']] }}"
                                       data-field="{{ $col['key'] }}" data-date="{{ $dateStr }}" @if($col['money'] ?? false) data-money="1" @endif
                                       class="dsppr-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold {{ $inputColor }} focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                            </td>
                            @else
                            @php
                                $cellColor = match (true) {
                                    $col['key'] === 'net_income' => $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100',
                                    $col['key'] === 'ni_pct' && $d['ni_pct'] < 0 => 'text-red-600 dark:text-red-400',
                                    default => 'text-ink dark:text-slate-100',
                                };
                            @endphp
                            <td class="px-3 py-2 text-right {{ $borderClass }} {{ $cellColor }}"
                                data-out="{{ $col['key'] }}" data-date="{{ $dateStr }}">
                                {{ ($col['pct'] ?? false) ? $fmtPct($d[$col['key']]) : (($col['int'] ?? false) ? number_format($d[$col['key']]) : $fmtMoney($d[$col['key']])) }}
                            </td>
                            @endif
                        @endforeach
                    @endforeach
                </tr>
            </tbody>
            <tfoot>
                <tr class="bg-black text-white font-bold dsppr-day-total-row">
                    <td class="dsppr-sticky dsppr-sticky-footer px-3 py-2.5">TOTAL</td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            // Every REAL product across every display row
                            // (a group row's own $row['products'] lists
                            // more than one) — flatten first so Gross
                            // Sales/Net Income sum each real product
                            // exactly once, whether it's shown standalone
                            // or inside a group.
                            $allProducts = $rows->flatMap(fn ($row) => $row['products']);
                            $perProductRows = $allProducts->map(function ($product) use ($dailyByKey, $dateStr) {
                                $entry = $dailyByKey->get($product->id . ':' . $dateStr);
                                return $entry ? $entry->toArray() : ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0];
                            })->all();
                            // Total Orders/Leads/Catered/Excess/rates are
                            // SUMMED one display ROW at a time from
                            // $realByRowKeyAndDate (each row's own already-
                            // deduped dsPprRow() figure for this date) —
                            // NOT recomputed by pooling every real product
                            // into one single dsPprRow() call globally
                            // (root-caused live, 2026-10-09: the per-day
                            // TOTAL row showed 298 while the top
                            // "TELESALES RUNNING PERFORMANCE" summary and
                            // Leads Report's own Grand Total both showed
                            // 299 for the exact same day — a real order
                            // genuinely matching TWO different products'
                            // own rows, e.g. a cross-team combo, is
                            // DELIBERATELY counted once per matching row
                            // everywhere else on this page and on Leads
                            // Report's own Grand Total ("Grand Total is a
                            // plain sum of the visible rows" — see
                            // LeadsReportController's own shift-window
                            // comment); pooling every product into one
                            // globally-deduped call here instead silently
                            // dropped that order's second count, same
                            // "299 vs 298" 1-order gap). Sum-of-rows here
                            // now matches $overallTotal's own
                            // `DsPprCalculator::sum($rows->pluck('derived')...)`
                            // exactly.
                            $realRows = $rows->map(function ($row) use ($realByRowKeyAndDate, $dateStr) {
                                $rowKey = $row['group'] ? 'g' . $row['group']->id : 'p' . $row['products']->first()->id;
                                return $realByRowKeyAndDate[$rowKey . ':' . $dateStr] ?? ['total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0, 'answered' => 0, 'unanswered' => 0, 'confirmed_via_call' => 0, 'upsell_confirmation' => 0];
                            });
                            $dayTotal = array_merge(
                                \App\Support\DsPprCalculator::sum($perProductRows ?: [['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0]]),
                                [
                                    'total_orders'    => $realRows->sum('total_orders'),
                                    'total_leads'     => $realRows->sum('total_leads'),
                                    'catered_leads'   => $realRows->sum('catered_leads'),
                                ]
                            );
                            // TIKTOK ORDERS row folded in on top — its own
                            // raw numbers are ALL manual (no real Order
                            // data backs it), so its Gross Sales/Net Income/
                            // Total Orders/Total Leads/Catered Leads all add
                            // straight onto the real-product totals above.
                            $tiktokRaw = $tiktokDailyByKey->get($dateStr, ['gross_sales' => 0, 'net_income' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0]);
                            $dayTotal['gross_sales']   += $tiktokRaw['gross_sales'];
                            $dayTotal['net_income']    += $tiktokRaw['net_income'];
                            $dayTotal['total_orders']  += $tiktokRaw['total_orders'];
                            $dayTotal['total_leads']   += $tiktokRaw['total_leads'];
                            $dayTotal['catered_leads'] += $tiktokRaw['catered_leads'];
                            $dayTotal['excess_leads']  = max(0, $dayTotal['total_leads'] - $dayTotal['catered_leads']);
                            // Pick-up/Conversion/Upselling Rate are
                            // recomputed from the SUMMED raw tally() counts
                            // across every display row (plus TikTok's own
                            // manual total folded in the same way), NEVER
                            // averaged per-row — FINAL decision, 2026-10-09
                            // (flip-flopped twice this same day — see
                            // DsPprReportController::index()'s own identical
                            // doc comment for the full back-and-forth):
                            // explicitly confirmed by direct instruction
                            // that this row must tally to Leads Report's
                            // own Grand Total for the same range ("because
                            // it is same data"), overriding the earlier
                            // 2026-09-24 spreadsheet-average convention. A
                            // useful side effect: immune to the earlier
                            // zero-activity-dilution bug a plain average
                            // had (a 0-total_leads row contributes 0/0 to
                            // the sum, never a diluting literal 0%) — no
                            // separate exclusion filter needed any more.
                            // Only diverges from Leads Report once TikTok
                            // Orders has real data in it (Leads Report has
                            // no TikTok concept at all) — confirmed
                            // expected, not a bug.
                            $rawCountTotals = [
                                'answered' => $realRows->sum('answered') + (float) $tiktokRaw['total_leads'],
                                'unanswered' => $realRows->sum('unanswered'),
                                'confirmed_via_call' => $realRows->sum('confirmed_via_call'),
                                'upsell_confirmation' => $realRows->sum('upsell_confirmation') + (float) $tiktokRaw['total_orders'],
                            ];
                            $sumThenRates = \App\Support\ProductPerformance::rates($rawCountTotals);
                            $dayTotal['pickup_rate']     = $sumThenRates['pick_up_rate'] !== null ? $sumThenRates['pick_up_rate'] / 100 : 0.0;
                            $dayTotal['conversion_rate'] = $sumThenRates['conversion_rate'] !== null ? $sumThenRates['conversion_rate'] / 100 : 0.0;
                            $dayTotal['upselling_rate']  = $sumThenRates['upselling_rate'] !== null ? $sumThenRates['upselling_rate'] / 100 : 0.0;
                            $dayTotal['ni_pct'] = $dayTotal['gross_sales'] > 0 ? $dayTotal['net_income'] / $dayTotal['gross_sales'] : 0.0;
                            // AOV depends on total_orders, which the merge
                            // above just overwrote AFTER DsPprCalculator::
                            // sum() already computed its own (wrong, 0-
                            // total_orders) aov — recompute it fresh now
                            // that the real total_orders is in place.
                            $dayTotal['aov'] = $dayTotal['total_orders'] > 0 ? $dayTotal['gross_sales'] / $dayTotal['total_orders'] : 0.0;
                        @endphp
                        @foreach($dayColumns as $i => $col)
                        <td class="px-3 py-2.5 text-right {{ $i === count($dayColumns) - 1 ? 'dsppr-day-end' : '' }} {{ $col['key'] === 'net_income' && $dayTotal['net_income'] < 0 ? 'text-red-400' : '' }} {{ $col['key'] === 'ni_pct' && $dayTotal['ni_pct'] < 0 ? 'text-red-400' : '' }}"
                            data-out="{{ $col['key'] }}" data-date="{{ $dateStr }}" data-total-row="1">
                            {{ ($col['pct'] ?? false) ? $fmtPct($dayTotal[$col['key']]) : (($col['int'] ?? false) ? number_format($dayTotal[$col['key']]) : $fmtMoney($dayTotal[$col['key']])) }}
                        </td>
                        @endforeach
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endforeach

{{-- Combine-products modal (explicit request, 2026-09-26: "drag the TO-01
     to TO-02 ... pop up like new name") — ONE shared modal, triggered by
     dropping one product row onto another anywhere on the page. Starts
     hidden; pj's own drag handlers below fill in the two product ids and
     toggle [hidden]. --}}
<div id="dsPprCombineModal" hidden class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50" data-close-combine-modal></div>
    <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-sm p-6 font-mono">
        <h3 class="text-sm font-bold uppercase tracking-wide text-ink dark:text-slate-100 mb-1">Combine Products</h3>
        <p id="dsPprCombineSubtitle" class="text-xs text-ink-muted dark:text-slate-400 mb-4"></p>
        <form id="dsPprCombineForm" class="space-y-4">
            <div>
                <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Combined Name</label>
                <input type="text" name="label" required maxlength="255"
                       class="w-full text-sm border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
            <p id="dsPprCombineError" class="text-xs text-red-600 dark:text-red-400 hidden"></p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" data-close-combine-modal class="text-sm px-4 py-2 rounded-lg border border-line dark:border-slate-600 text-ink dark:text-slate-100">Cancel</button>
                <button type="submit" class="text-sm px-4 py-2 rounded-lg bg-primary text-white font-semibold">Combine</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const tables = document.querySelectorAll('.dsppr-days-table');
    if (!tables.length) return;
    const globalStatus = document.getElementById('dsPprSaveStatus');
    const saveTimers = new WeakMap();

    function fmtMoney(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtInt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
    function fmtPct(n) { return (Number(n) * 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%'; }
    function parseMoney(str) { return Number(String(str).replace(/,/g, '')) || 0; }

    // Same live comma-formatting convention as Projections' own pj.js. A
    // leading '-' is kept (not stripped) — explicit request, 2026-10-01:
    // "in the net income column i want to have like can input negative
    // number" — Net Income is the only money field a loss genuinely makes
    // sense for, so this allows it for every money field rather than
    // special-casing just that one.
    function liveFormatMoney(input) {
        const raw = input.value;
        const caretFromEnd = raw.length - (input.selectionStart ?? raw.length);
        const isNegative = raw.trim().startsWith('-');
        const cleaned = raw.replace(/[^0-9.]/g, '');
        const firstDot = cleaned.indexOf('.');
        const intPart = firstDot === -1 ? cleaned : cleaned.slice(0, firstDot);
        const fracPart = firstDot === -1 ? '' : cleaned.slice(firstDot);
        const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const formatted = (isNegative ? '-' : '') + grouped + fracPart;
        if (formatted !== raw) {
            input.value = formatted;
            const pos = Math.max(0, formatted.length - caretFromEnd);
            input.setSelectionRange(pos, pos);
        }
        if (input.dataset.field === 'net_income') updateNetIncomeInputColor(input);
    }

    // Strips a trailing "%" (and any stray whitespace) before parsing a
    // rate-override input's own typed value — shared by saveField() and
    // readOverride() below so a live "%" suffix (added by
    // liveFormatPct() below) never breaks either path's own Number()
    // parse. Returns NaN for a genuinely unparseable value, same as a
    // bare Number() would.
    function parsePctInput(raw) {
        return Number(String(raw).replace(/%/g, '').trim());
    }

    // Live "%" suffix on Pick-up/Conversion/Upselling Rate's own override
    // inputs (explicit request, 2026-10-07: "why is it the % is not
    // visible ... it should be visible not just by number only") — same
    // "reformat on every keystroke, preserve caret position" pattern as
    // liveFormatMoney() above, just appending a literal "%" instead of
    // comma-grouping. The % is purely DISPLAY — parsePctInput() above
    // strips it back out before this field's own value is ever parsed/
    // saved, so "50" typed in still stores/sends as 0.5, unaffected by
    // what the input visually shows.
    function liveFormatPct(input) {
        const raw = input.value;
        const caretFromEnd = raw.length - (input.selectionStart ?? raw.length);
        const cleaned = raw.replace(/[^0-9.]/g, '');
        const formatted = cleaned === '' ? '' : cleaned + '%';
        if (formatted !== raw) {
            input.value = formatted;
            // Caret never lands inside/after the trailing "%" itself —
            // typing felt wrong otherwise (the cursor would jump past the
            // % on every keystroke instead of staying where the digits
            // are being edited).
            const pos = Math.max(0, Math.min(formatted.length - 1, formatted.length - caretFromEnd));
            input.setSelectionRange(pos, pos);
        }
    }

    // Live negative=red coloring on the Net Income INPUT itself as the
    // user types (explicit request, 2026-10-01: "if negative is color
    // red") — separate from applyDerived()'s own coloring of the
    // read-only [data-out] spans, since this targets the <input>
    // element's own text color instead. No green tier — explicit
    // follow-up, 2026-10-03: "in the dsppr too make it all black,"
    // confirmed "always black but if negative it is red."
    function updateNetIncomeInputColor(input) {
        const isNegative = parseMoney(input.value) < 0;
        input.classList.toggle('text-red-600', isNegative);
        input.classList.toggle('dark:text-red-400', isNegative);
    }

    function flashStatus(text, isError) {
        if (!globalStatus) return;
        globalStatus.textContent = text;
        globalStatus.classList.toggle('text-red-500', !!isError);
        globalStatus.classList.toggle('text-slate-400', !isError);
        clearTimeout(flashStatus._t);
        flashStatus._t = setTimeout(() => { if (globalStatus.textContent === text) globalStatus.textContent = ''; }, 2000);
    }

    // Applies a fresh derived-figures payload to every [data-out] cell
    // for ONE (row, date) pair — rows and totals both use this, keyed by
    // the same data-date attribute so a day's edit never bleeds into a
    // neighboring day's read-only cells in the SAME chunk's table.
    function applyDerived(row, date, derived) {
        row.querySelectorAll(`[data-out][data-date="${date}"]`).forEach((el) => {
            const key = el.dataset.out;
            if (!(key in derived)) return;
            const isPct = ['ni_pct', 'pickup_rate', 'conversion_rate', 'upselling_rate'].includes(key);
            const isInt = ['excess_leads', 'total_orders', 'total_leads', 'catered_leads'].includes(key);
            el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
            // Net Income and NI % both: black by default, red only if
            // negative — no green tier (explicit request, 2026-10-03:
            // "in the dsppr too make it all black," confirmed "always
            // black but if negative it is red"; supersedes the old
            // 2026-09-28 green-when-positive rule for Net Income).
            const isLoss = (key === 'ni_pct' || key === 'net_income') && derived[key] < 0;
            el.classList.toggle('text-red-600', isLoss);
            el.classList.toggle('dark:text-red-400', isLoss);
        });
    }

    // Recomputes and repaints THIS table's own bottom TOTAL row for ONE
    // date — re-reads every row's own currently-saved inputs for that
    // date, scoped to $table so a save in one 7-day chunk never touches
    // another chunk's TOTAL row (each chunk is now its own independent
    // <table>, explicit request 2026-09-24: "only 7 days ... next is in
    // the down part").
    //
    // Pick-up Rate / Conversion Rate / Upselling Rate are averaged across
    // rows, NOT recomputed from summed Total/Catered Leads (root-caused
    // 2026-09-24, /systematic-debugging, against the real sheet's own
    // Sep 14 TOTAL row — see DsPprCalculator::sum()'s own doc comment for
    // the full evidence). Mirrors that same PHP logic client-side so a
    // live edit's TOTAL row matches what a fresh page load would show.
    // Reads one row's own value for $key/$date — a [data-field] INPUT if
    // this row has one (real products' Gross Sales/Net Income always;
    // the TIKTOK ORDERS row's own Total Orders/Total Leads/Catered Leads
    // too, since every field there is manual — see $tiktokDayColumns'
    // own doc comment), otherwise the read-only [data-out] span (real
    // products' automated Total Orders/Total Leads/Catered Leads).
    function readRowValue(row, key, date) {
        const input = row.querySelector(`[data-field="${key}"][data-date="${date}"]`);
        if (input) return parseMoney(input.value);
        const out = row.querySelector(`[data-out="${key}"][data-date="${date}"]`);
        return out ? (Number(out.textContent.replace(/,/g, '')) || 0) : 0;
    }

    // TIKTOK ORDERS' own 4 rate-override inputs (explicit request,
    // 2026-10-07: "make it editable") — a blank override input means "use
    // the formula", same as the server (DsPprCalculator::derive()'s own
    // $hasOverride check). Mirrors that exact rule client-side so a live
    // edit's own TOTAL-row preview never disagrees with what a fresh page
    // load (or the server's own saved response) would show. $isPct
    // divides a typed whole-percent value (e.g. "25") by 100 into the
    // same fraction convention derive() itself stores (0.25) — the
    // override input's own placeholder/value is always shown as a plain
    // percent number, never a fraction, same as every other %-labeled UI
    // text on this page (fmtPct's own ×100 formatting).
    function readOverride(row, key, date, isPct) {
        const input = row.querySelector(`[data-field="${key}"][data-date="${date}"]`);
        if (!input || input.value.trim() === '') return null;
        const n = isPct ? parsePctInput(input.value) : Number(input.value);
        if (!Number.isFinite(n)) return null;
        return isPct ? n / 100 : n;
    }

    function refreshDayTotal(table, date) {
        const rows = table.querySelectorAll('tbody .dsppr-row');
        let totals = { gross_sales: 0, net_income: 0, total_orders: 0, total_leads: 0, catered_leads: 0 };
        let pickupSum = 0, convSum = 0, upsellSum = 0, rowCount = 0, excessLeadsTotal = 0;
        rows.forEach((row) => {
            const grossSales = readRowValue(row, 'gross_sales', date);
            const netIncome = readRowValue(row, 'net_income', date);
            const totalOrders = readRowValue(row, 'total_orders', date);
            const totalLeads = readRowValue(row, 'total_leads', date);
            const cateredLeads = readRowValue(row, 'catered_leads', date);

            totals.gross_sales += grossSales;
            totals.net_income += netIncome;
            totals.total_orders += totalOrders;
            totals.total_leads += totalLeads;
            totals.catered_leads += cateredLeads;

            const pickupOverride = readOverride(row, 'pickup_rate', date, true);
            const convOverride = readOverride(row, 'conversion_rate', date, true);
            const upsellOverride = readOverride(row, 'upselling_rate', date, true);
            const excessOverride = readOverride(row, 'excess_leads', date, false);

            pickupSum += pickupOverride ?? (totalLeads > 0 ? cateredLeads / totalLeads : 0);
            convSum += convOverride ?? (cateredLeads > 0 ? totalOrders / cateredLeads : 0);
            upsellSum += upsellOverride ?? (cateredLeads > 0 ? totalOrders / cateredLeads : 0);
            excessLeadsTotal += excessOverride ?? Math.max(0, totalLeads - cateredLeads);
            rowCount += 1;
        });
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income,
            total_orders: totals.total_orders, total_leads: totals.total_leads, catered_leads: totals.catered_leads,
            excess_leads: Math.max(0, excessLeadsTotal),
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            pickup_rate: rowCount > 0 ? pickupSum / rowCount : 0,
            conversion_rate: rowCount > 0 ? convSum / rowCount : 0,
            upselling_rate: rowCount > 0 ? upsellSum / rowCount : 0,
        };
        const totalRow = table.querySelector('.dsppr-day-total-row');
        if (totalRow) applyDerived(totalRow, date, derived);
    }

    // Recomputes and repaints ONE product's row in the top "TELESALES
    // RUNNING PERFORMANCE" summary table — sums that product's own inputs
    // across EVERY date in EVERY 7-day chunk table (the summary is a
    // whole-range total, not a single day's), so an edit anywhere in the
    // daily tables below is reflected up top without a page reload
    // (explicit request, 2026-09-24: "auto save like user typing ...
    // auto update the tables").
    function refreshSummaryRow(rowKey) {
        const summaryTable = document.getElementById('dsPprSummaryTable');
        if (!summaryTable) return;

        let totals = { gross_sales: 0, net_income: 0, total_orders: 0, total_leads: 0, catered_leads: 0 };
        let pickupSum = 0, convSum = 0, upsellSum = 0, dayCount = 0, excessLeadsTotal = 0;

        document.querySelectorAll(`.dsppr-days-table .dsppr-row[data-row-key="${rowKey}"]`).forEach((row) => {
            row.querySelectorAll('[data-field="gross_sales"]').forEach((el) => {
                const date = el.dataset.date;
                const grossSales = parseMoney(el.value);
                // Same readRowValue() helper as refreshDayTotal() above —
                // handles both a real product's read-only [data-out]
                // Total Orders/Leads/Catered AND the TIKTOK ORDERS row's
                // own [data-field] inputs for those same 3 fields.
                const netIncome = readRowValue(row, 'net_income', date);
                const totalOrders = readRowValue(row, 'total_orders', date);
                const totalLeads = readRowValue(row, 'total_leads', date);
                const cateredLeads = readRowValue(row, 'catered_leads', date);

                totals.gross_sales += grossSales;
                totals.net_income += netIncome;
                totals.total_orders += totalOrders;
                totals.total_leads += totalLeads;
                totals.catered_leads += cateredLeads;

                // Same override-aware read as refreshDayTotal() above — a
                // TikTok day with one of the 4 rate columns manually
                // overridden contributes THAT value instead of the
                // formula, matching the server's own DsPprCalculator.
                const pickupOverride = readOverride(row, 'pickup_rate', date, true);
                const convOverride = readOverride(row, 'conversion_rate', date, true);
                const upsellOverride = readOverride(row, 'upselling_rate', date, true);
                const excessOverride = readOverride(row, 'excess_leads', date, false);

                pickupSum += pickupOverride ?? (totalLeads > 0 ? cateredLeads / totalLeads : 0);
                convSum += convOverride ?? (cateredLeads > 0 ? totalOrders / cateredLeads : 0);
                upsellSum += upsellOverride ?? (cateredLeads > 0 ? totalOrders / cateredLeads : 0);
                excessLeadsTotal += excessOverride ?? Math.max(0, totalLeads - cateredLeads);
                dayCount += 1;
            });
        });

        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income,
            total_orders: totals.total_orders, total_leads: totals.total_leads, catered_leads: totals.catered_leads,
            excess_leads: Math.max(0, excessLeadsTotal),
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            pickup_rate: dayCount > 0 ? pickupSum / dayCount : 0,
            conversion_rate: dayCount > 0 ? convSum / dayCount : 0,
            upselling_rate: dayCount > 0 ? upsellSum / dayCount : 0,
        };

        const summaryRow = summaryTable.querySelector(`.dsppr-summary-row[data-row-key="${rowKey}"]`);
        if (summaryRow) {
            summaryRow.querySelectorAll('[data-out]').forEach((el) => {
                const key = el.dataset.out;
                if (!(key in derived)) return;
                const isPct = ['ni_pct', 'pickup_rate', 'conversion_rate', 'upselling_rate'].includes(key);
                const isInt = ['excess_leads', 'total_orders', 'total_leads', 'catered_leads'].includes(key);
                el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
                const isLoss = (key === 'ni_pct' || key === 'net_income') && derived[key] < 0;
                el.classList.toggle('text-red-600', isLoss);
                el.classList.toggle('dark:text-red-400', isLoss);
            });
        }

        refreshOverallTotal(summaryTable);
    }

    // Recomputes and repaints the OVERALL TOTAL row from every product's
    // own already-updated summary row — same "sum dollars, average
    // rates" split as everywhere else on this page.
    function refreshOverallTotal(summaryTable) {
        let totals = { gross_sales: 0, net_income: 0, total_orders: 0, total_leads: 0, catered_leads: 0, excess_leads: 0 };
        let pickupSum = 0, convSum = 0, upsellSum = 0, rowCount = 0;

        summaryTable.querySelectorAll('tbody .dsppr-summary-row').forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector('[data-out="gross_sales"]').textContent);
            totals.net_income += parseMoney(row.querySelector('[data-out="net_income"]').textContent);
            totals.total_orders += parseMoney(row.querySelector('[data-out="total_orders"]').textContent);
            totals.total_leads += parseMoney(row.querySelector('[data-out="total_leads"]').textContent);
            totals.catered_leads += parseMoney(row.querySelector('[data-out="catered_leads"]').textContent);
            totals.excess_leads += parseMoney(row.querySelector('[data-out="excess_leads"]').textContent);
            pickupSum += parseFloat(row.querySelector('[data-out="pickup_rate"]').textContent) / 100;
            convSum += parseFloat(row.querySelector('[data-out="conversion_rate"]').textContent) / 100;
            upsellSum += parseFloat(row.querySelector('[data-out="upselling_rate"]').textContent) / 100;
            rowCount += 1;
        });

        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income,
            total_orders: totals.total_orders, total_leads: totals.total_leads, catered_leads: totals.catered_leads,
            excess_leads: totals.excess_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            pickup_rate: rowCount > 0 ? pickupSum / rowCount : 0,
            conversion_rate: rowCount > 0 ? convSum / rowCount : 0,
            upselling_rate: rowCount > 0 ? upsellSum / rowCount : 0,
        };

        const totalRow = summaryTable.querySelector('.dsppr-overall-total-row');
        if (!totalRow) return;
        totalRow.querySelectorAll('[data-out]').forEach((el) => {
            const key = el.dataset.out;
            if (!(key in derived)) return;
            const isPct = ['ni_pct', 'pickup_rate', 'conversion_rate', 'upselling_rate'].includes(key);
            const isInt = ['excess_leads', 'total_orders', 'total_leads', 'catered_leads'].includes(key);
            el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
            el.classList.toggle('text-red-400', key === 'ni_pct' && derived[key] < 0);
            if (key === 'net_income') {
                el.classList.toggle('text-red-400', derived[key] < 0);
            }
        });
    }

    // TIKTOK ORDERS' own 4 rate-override fields (explicit request,
    // 2026-10-07) save to a DIFFERENT column name than their own
    // data-field/data-out key (excess_leads -> excess_leads_override,
    // same "_override" suffix for all 4 — see the migration's own doc
    // comment for why these are separate nullable columns rather than
    // reusing the plain column name real products' Total Orders/Leads/
    // Catered already occupy for a different purpose there).
    const OVERRIDE_FIELD_MAP = {
        excess_leads: 'excess_leads_override',
        pickup_rate: 'pickup_rate_override',
        conversion_rate: 'conversion_rate_override',
        upselling_rate: 'upselling_rate_override',
    };

    function saveField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const table = input.closest('.dsppr-days-table');
        const row = input.closest('.dsppr-row');
        const isTiktok = row.dataset.rowKey === 'tiktok';
        const urlTemplate = isTiktok ? table.dataset.updateTiktokUrlTemplate : table.dataset.updateUrlTemplate;
        const date = input.dataset.date;
        const field = input.dataset.field;
        const isOverride = field in OVERRIDE_FIELD_MAP;
        const isPctOverride = input.dataset.pct === '1';

        flashStatus('Saving…', false);

        const url = isTiktok
            ? urlTemplate.replace('__DATE__', date)
            : urlTemplate.replace('__PRODUCT__', row.dataset.productId).replace('__DATE__', date);
        const body = new URLSearchParams();

        let value;
        if (isOverride) {
            // A blank override input clears it back to the formula (same
            // "blank falls back to the formula" rule the server's own
            // DsPprCalculator::derive() follows) — sent as an explicit
            // empty string, NOT omitted, so the PATCH actually nulls out
            // whatever override was previously saved rather than leaving
            // it untouched (the 'nullable' validation rule, not
            // 'sometimes', accepts this).
            const trimmed = input.value.trim();
            value = trimmed === '' ? '' : (isPctOverride ? parsePctInput(trimmed) / 100 : Number(trimmed));
            body.set(OVERRIDE_FIELD_MAP[field], value);
        } else {
            value = input.dataset.money === '1' ? parseMoney(input.value) : (Number(input.value) || 0);
            body.set(field, value);
        }
        body.set('_method', 'PATCH');

        fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: body.toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                flashStatus('Saved', false);
                if (input.dataset.money === '1' && document.activeElement !== input) {
                    input.value = fmtMoney(value);
                }
                if (input.dataset.field === 'net_income') updateNetIncomeInputColor(input);
                if (data?.derived) applyDerived(row, date, data.derived);
                refreshDayTotal(table, date);
                refreshSummaryRow(row.dataset.rowKey);
            })
            .catch(() => {
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    // Delegated once per table (each 7-day chunk is its own table) rather
    // than a single shared container — same event-handling logic as
    // before, just scoped per chunk now.
    tables.forEach((table) => {
        table.addEventListener('input', (e) => {
            const input = e.target.closest('.dsppr-field');
            if (!input) return;
            if (input.dataset.money === '1') liveFormatMoney(input);
            if (input.dataset.pct === '1') liveFormatPct(input);
            clearTimeout(saveTimers.get(input));
            saveTimers.set(input, setTimeout(() => saveField(input), 600));
        });

        table.addEventListener('blur', (e) => {
            const input = e.target.closest('.dsppr-field');
            if (input) saveField(input);
        }, true);

        // Select-all-on-focus (explicit request, 2026-10-09: typing "5" into
        // a field showing "0" was producing "50" — the leading 0 is a real
        // value, not a placeholder, so with nothing selected a click just
        // drops the caret next to it instead of replacing it).
        table.addEventListener('focus', (e) => {
            const input = e.target.closest('.dsppr-field');
            if (input) input.select();
        }, true);

        table.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const input = e.target.closest('.dsppr-field');
            if (input) { e.preventDefault(); input.blur(); }
        });
    });

    // Click-and-drag horizontal scroll (explicit request, 2026-09-24:
    // "make it like draggable to right like in the sheets") — a plain
    // overflow-x-auto is already scrollable via trackpad/scrollbar/shift-
    // wheel, but a genuine click-drag gesture (mouse held down, cursor
    // moves the viewport) is what a spreadsheet's own horizontal scrollbar
    // drag feels like, so this adds that on top rather than replacing the
    // native scroll. Ignores drags starting on an <input> so editing a
    // cell's own text selection isn't hijacked into a scroll-drag. Wired
    // to EVERY 7-day chunk's own scroller independently.
    document.querySelectorAll('.dsppr-scroller').forEach((scroller) => {
        let isDragging = false, dragStartX = 0, dragStartScroll = 0;

        scroller.addEventListener('mousedown', (e) => {
            if (e.target.closest('input')) return;
            isDragging = true;
            dragStartX = e.pageX;
            dragStartScroll = scroller.scrollLeft;
            scroller.classList.add('cursor-grabbing');
        });
        window.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            e.preventDefault();
            scroller.scrollLeft = dragStartScroll - (e.pageX - dragStartX);
        });
        window.addEventListener('mouseup', () => {
            isDragging = false;
            scroller.classList.remove('cursor-grabbing');
        });
    });

    // --- Combine products (explicit request, 2026-09-26: "drag the TO-01
    // to TO-02 ... pop up like new name ... it will reflect it to the
    // expected income"). Native HTML5 drag-and-drop (dragstart/dragover/
    // drop on the [data-drop-target] product-name cell), not the mouse-
    // based scroll-drag above — they don't conflict: a drag that starts on
    // a draggable="true" element is handled by the browser's own DnD
    // machinery before the scroller's plain mousedown listener ever sees
    // it. A page reload after success/ungroup, same reasoning as
    // Projections' own custom-row add/remove — grouping changes every
    // table's own ROW LIST, not just a value, and applyDerived() above
    // only ever updates existing elements' text. ---
    const combineModal = document.getElementById('dsPprCombineModal');
    const combineForm = document.getElementById('dsPprCombineForm');
    const combineError = document.getElementById('dsPprCombineError');
    const combineSubtitle = document.getElementById('dsPprCombineSubtitle');
    let dragProductId = null;
    let combineProductIds = null;

    function openCombineModal(productIdA, productIdB, labelA, labelB) {
        combineProductIds = [productIdA, productIdB];
        combineError.classList.add('hidden');
        combineForm.reset();
        combineSubtitle.textContent = `${labelA} + ${labelB}`;
        combineModal.hidden = false;
        combineForm.querySelector('[name="label"]').focus();
    }
    function closeCombineModal() {
        combineModal.hidden = true;
        combineProductIds = null;
    }

    // Drag SOURCES: only an ungrouped row's own cell (dragging a whole
    // group onto something else isn't a supported gesture).
    document.querySelectorAll('[data-drop-target][draggable="true"]').forEach((cell) => {
        cell.addEventListener('dragstart', (e) => {
            const row = cell.closest('[data-product-id]');
            dragProductId = row.dataset.productId;
            e.dataTransfer.effectAllowed = 'move';
            // Firefox refuses to start a drag at all unless setData() is
            // called during dragstart — Chrome/Safari don't need this but
            // tolerate it fine, so it's set unconditionally rather than
            // branching per browser.
            e.dataTransfer.setData('text/plain', dragProductId);
        });
    });

    // Drop TARGETS: every row's own cell, ungrouped OR already-grouped
    // (explicit follow-up, 2026-09-26: "what about more than 2 combine") —
    // dropping onto a plain product opens the name-picker (creates a NEW
    // group); dropping onto an already-combined row joins that SAME group
    // instead, no naming needed since the group already has one.
    document.querySelectorAll('[data-drop-target]').forEach((cell) => {
        const productRow = cell.closest('[data-product-id]');
        const groupRow = cell.closest('[data-group-id]');
        if (!productRow && !groupRow) return;

        cell.addEventListener('dragover', (e) => {
            const targetProductId = productRow?.dataset.productId;
            if (dragProductId && dragProductId !== targetProductId) {
                e.preventDefault();
                cell.classList.add('dsppr-drop-hover');
            }
        });
        cell.addEventListener('dragleave', () => cell.classList.remove('dsppr-drop-hover'));
        cell.addEventListener('drop', (e) => {
            e.preventDefault();
            cell.classList.remove('dsppr-drop-hover');
            if (!dragProductId) return;

            if (groupRow) {
                addProductToGroup(dragProductId, groupRow.dataset.groupId);
            } else {
                const targetProductId = productRow.dataset.productId;
                if (dragProductId === targetProductId) { dragProductId = null; return; }
                const sourceCell = document.querySelector(`[data-product-id="${dragProductId}"] [data-drop-target]`);
                const sourceLabel = sourceCell ? sourceCell.textContent.trim().replace(/×$/, '').trim() : '';
                const targetLabel = cell.textContent.trim().replace(/×$/, '').trim();
                openCombineModal(dragProductId, targetProductId, sourceLabel, targetLabel);
            }
            dragProductId = null;
        });
    });

    async function addProductToGroup(productId, groupId) {
        if (!(await window.confirmDataModal('Add this product to the combined row?'))) return;
        const body = new URLSearchParams();
        body.set('product_id', productId);
        fetch(`{{ url('/data/product-groups') }}/${groupId}/members`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: body.toString(),
        })
            .then((res) => (res.ok ? res.json() : res.json().then((data) => Promise.reject(data))))
            .then(() => window.location.reload())
            .catch((data) => window.showToast?.(data?.message || 'Could not add this product — try again.', 'error'));
    }

    combineModal.querySelectorAll('[data-close-combine-modal]').forEach((el) => {
        el.addEventListener('click', closeCombineModal);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !combineModal.hidden) closeCombineModal();
    });

    combineForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!combineProductIds) return;

        const label = combineForm.querySelector('[name="label"]').value.trim();
        if (!label) return;
        const submitBtn = combineForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;

        const body = new URLSearchParams();
        body.set('label', label);
        combineProductIds.forEach((id) => body.append('product_ids[]', id));

        fetch('{{ route('data.product-groups.store') }}', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: body.toString(),
        })
            .then((res) => (res.ok ? res.json() : res.json().then((data) => Promise.reject(data))))
            .then(() => window.location.reload())
            .catch((data) => {
                submitBtn.disabled = false;
                combineError.textContent = data?.message || 'Could not combine these products — try again.';
                combineError.classList.remove('hidden');
            });
    });

    document.querySelectorAll('[data-ungroup]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (!(await window.confirmDataModal('Split this combined row back into its own separate products?'))) return;
            const groupId = btn.dataset.ungroup;
            fetch(`{{ url('/data/product-groups') }}/${groupId}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then(() => window.location.reload())
                .catch(() => window.showToast?.('Could not ungroup — try again.', 'error'));
        });
    });

    // Per-date lock for the daily-entry table (explicit follow-up,
    // 2026-10-07: "i want to make it per date like the lock icon is in
    // the dates right side" — reverses the earlier same-day "one lock
    // for the whole table" decision). Each date header has its own lock
    // button + icon; locking disables every .dsppr-field tagged with that
    // SAME data-date, across every chunk/row that date appears in (a
    // date's own column only ever renders once across the whole page, so
    // this is a 1:1 match, not a many-buttons-one-state situation the old
    // whole-table version had).
    function applyDateLockState(dateStr, locked) {
        document.querySelectorAll(`[data-dsppr-date-header][data-date="${dateStr}"]`).forEach((th) => {
            th.dataset.locked = locked ? '1' : '0';
        });
        document.querySelectorAll(`.dsppr-field[data-date="${dateStr}"]`).forEach((el) => { el.disabled = locked; });
    }

    function swapDsprLockIcon(dateStr, locked) {
        const lockedSvg = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>';
        const unlockedSvg = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>';
        const btn = document.querySelector(`[data-dsppr-lock-toggle][data-date="${dateStr}"]`);
        if (!btn) return;
        btn.dataset.locked = locked ? '1' : '0';
        btn.title = locked ? 'Unlock this date' : 'Lock this date';
        btn.setAttribute('aria-label', btn.title);
        const iconWrap = btn.querySelector('[data-dsppr-lock-icon]');
        if (!iconWrap) return;
        iconWrap.style.opacity = '0';
        setTimeout(() => {
            iconWrap.innerHTML = locked ? lockedSvg : unlockedSvg;
            iconWrap.style.opacity = '1';
        }, 180);
    }

    document.querySelectorAll('[data-dsppr-date-header]').forEach((th) => {
        applyDateLockState(th.dataset.date, th.dataset.locked === '1');
    });

    document.addEventListener('click', (e) => {
        const lockBtn = e.target.closest('[data-dsppr-lock-toggle]');
        if (!lockBtn) return;
        const dateStr = lockBtn.dataset.date;
        const nowLocked = lockBtn.dataset.locked !== '1';

        lockBtn.disabled = true;
        fetch(`{{ url('/data/dsppr/lock') }}/${dateStr}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: new URLSearchParams({ _method: 'PATCH', locked: nowLocked ? '1' : '0' }).toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => {
                swapDsprLockIcon(dateStr, nowLocked);
                applyDateLockState(dateStr, nowLocked);
            })
            .catch(() => {
                window.showToast?.(`Could not ${nowLocked ? 'lock' : 'unlock'} this date — try again.`, 'error');
            })
            .finally(() => { lockBtn.disabled = false; });
    });
})();
</script>
@endpush

@endsection
