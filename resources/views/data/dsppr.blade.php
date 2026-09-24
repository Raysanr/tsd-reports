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

    /* Real cell borders on every side, like the sheet's own grid lines
       (explicit request, 2026-09-24: "make it like has border like in the
       sheets", then "make the grid or border is darker" — #cbd5e1 read as
       too faint against the pale yellow/rose header colors) —
       border-collapse means adjacent cells share one line instead of
       doubling up. */
    .dsppr-table th, .dsppr-table td { border: 1px solid #64748b; }
    .dark .dsppr-table th, .dark .dsppr-table td { border-color: #94a3b8; }
</style>

@php
    $fmtMoney = fn ($n) => number_format((float) $n, 2);
    $fmtPct   = fn ($n) => number_format(((float) $n) * 100, 2) . '%';

    // One repeating 13-column set per day — shared between the header
    // group row and every product row's per-day cell block, so the two
    // can never drift on order/count. headerBg matches the real sheet's
    // own column-group colors exactly (explicit request, 2026-09-24:
    // "colors must be like in the sheets"): pale yellow for the plain $
    // columns, blue-gray for NI%, solid gray for Actual Cost Per Lead,
    // dusty rose for every lead/rate column from Total Leads onward.
    $dayColumns = [
        ['key' => 'gross_sales', 'label' => 'Gross Sales', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'net_income', 'label' => 'Net Income', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'ads_spent', 'label' => 'Ads Spent', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'ni_pct', 'label' => 'NI %', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-slate-200 dark:bg-slate-600'],
        ['key' => 'total_orders', 'label' => 'Total Orders', 'editable' => true, 'int' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'aov', 'label' => 'AOV', 'editable' => false, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'actual_cost_per_lead', 'label' => 'Actual Cost Per Lead', 'editable' => false, 'money' => true, 'headerBg' => 'bg-slate-400 dark:bg-slate-500'],
        ['key' => 'total_leads', 'label' => 'Total Leads', 'editable' => true, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'catered_leads', 'label' => 'Catered Leads', 'editable' => true, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'excess_leads', 'label' => 'Excess Leads', 'editable' => false, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'conversion_rate', 'label' => 'Conversion Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
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
        <div>
            <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" onchange="this.form.submit()"
                   class="text-sm font-mono border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
        </div>
        <div>
            <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" onchange="this.form.submit()"
                   class="text-sm font-mono border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
        </div>
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
    <div class="bg-black text-white text-center font-mono font-bold text-sm tracking-wide py-2.5">
        TELESALES RUNNING PERFORMANCE
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
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Ads Spent</th>
                    <th class="bg-slate-200 dark:bg-slate-600 text-right px-3 py-2 font-bold whitespace-nowrap">NI %</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Total Orders</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">AOV</th>
                    <th class="bg-slate-400 dark:bg-slate-500 text-right px-3 py-2 font-bold whitespace-nowrap">Actual Cost Per Lead</th>
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
                <tr class="dsppr-summary-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-product-id="{{ $row['product']->id }}">
                    <td class="dsppr-sticky dsppr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ strtoupper($row['product']->display_name) }}</td>
                    <td class="px-3 py-2 text-right" data-out="gross_sales">{{ $fmtMoney($d['gross_sales']) }}</td>
                    <td class="px-3 py-2 text-right {{ $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}" data-out="net_income">{{ $fmtMoney($d['net_income']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="ads_spent">{{ $fmtMoney($d['ads_spent']) }}</td>
                    <td class="px-3 py-2 text-right {{ $d['ni_pct'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}" data-out="ni_pct">{{ $fmtPct($d['ni_pct']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="total_orders">{{ number_format($d['total_orders']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="aov">{{ $fmtMoney($d['aov']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="actual_cost_per_lead">{{ $fmtMoney($d['actual_cost_per_lead']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="total_leads">{{ number_format($d['total_leads']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="catered_leads">{{ number_format($d['catered_leads']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="excess_leads">{{ number_format($d['excess_leads']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="pickup_rate">{{ $fmtPct($d['pickup_rate']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="conversion_rate">{{ $fmtPct($d['conversion_rate']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="upselling_rate">{{ $fmtPct($d['upselling_rate']) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-black text-white font-bold dsppr-overall-total-row">
                    <td class="dsppr-sticky dsppr-sticky-footer px-3 py-2.5">OVERALL TOTAL</td>
                    <td class="px-3 py-2.5 text-right" data-out="gross_sales">{{ $fmtMoney($overallTotal['gross_sales']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $overallTotal['net_income'] < 0 ? 'text-red-400' : '' }}" data-out="net_income">{{ $fmtMoney($overallTotal['net_income']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="ads_spent">{{ $fmtMoney($overallTotal['ads_spent']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $overallTotal['ni_pct'] < 0 ? 'text-red-400' : '' }}" data-out="ni_pct">{{ $fmtPct($overallTotal['ni_pct']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="total_orders">{{ number_format($overallTotal['total_orders']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="aov">{{ $fmtMoney($overallTotal['aov']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="actual_cost_per_lead">{{ $fmtMoney($overallTotal['actual_cost_per_lead']) }}</td>
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
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-6">
    <div class="overflow-x-auto dsppr-scroller" id="dsPprScroller-{{ $chunkIndex }}">
        <table class="text-[13px] font-mono border-collapse dsppr-table dsppr-days-table"
               data-update-url-template="{{ route('data.dsppr.update', ['product' => '__PRODUCT__', 'date' => '__DATE__']) }}">
            <thead>
                <tr>
                    <th rowspan="2" class="dsppr-sticky bg-yellow-300 dark:bg-yellow-600 text-left px-3 py-2 font-bold text-ink whitespace-nowrap align-bottom">Product</th>
                    @foreach($dates as $date)
                    <th colspan="{{ count($dayColumns) }}" class="bg-yellow-300 dark:bg-yellow-600 text-center font-bold text-ink px-3 py-2 whitespace-nowrap border-l-2 border-yellow-500 dark:border-yellow-800">
                        {{ $date->format('D, M j') }}
                    </th>
                    @endforeach
                </tr>
                <tr>
                    @foreach($dates as $date)
                        @foreach($dayColumns as $i => $col)
                        <th class="text-right px-3 py-2 font-bold whitespace-nowrap text-ink dark:text-slate-950 {{ $col['headerBg'] }} {{ $i === 0 ? 'border-l-2 border-yellow-500 dark:border-yellow-900' : '' }}">
                            {{ $col['label'] }}
                        </th>
                        @endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                @php $product = $row['product']; @endphp
                <tr class="dsppr-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-product-id="{{ $product->id }}">
                    <td class="dsppr-sticky dsppr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ strtoupper($product->display_name) }}</td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            $entry = $dailyByKey->get($product->id . ':' . $dateStr);
                            $raw = $entry ? $entry->toArray() : ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0];
                            $d = \App\Support\DsPprCalculator::derive($raw);
                        @endphp
                        @foreach($dayColumns as $i => $col)
                            @php $borderClass = $i === 0 ? 'border-l-2 border-yellow-200 dark:border-yellow-900' : ''; @endphp
                            @if($col['editable'])
                            <td class="px-2 py-1.5 {{ $borderClass }}">
                                <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                                       value="{{ ($col['money'] ?? false) ? number_format($raw[$col['key']], 2) : $raw[$col['key']] }}"
                                       data-field="{{ $col['key'] }}" data-date="{{ $dateStr }}" @if($col['money'] ?? false) data-money="1" @endif
                                       class="dsppr-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                            </td>
                            @else
                            <td class="px-3 py-2 text-right {{ $borderClass }} {{ $col['key'] === 'ni_pct' && $d['ni_pct'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}"
                                data-out="{{ $col['key'] }}" data-date="{{ $dateStr }}">
                                {{ ($col['pct'] ?? false) ? $fmtPct($d[$col['key']]) : (($col['int'] ?? false) ? number_format($d[$col['key']]) : $fmtMoney($d[$col['key']])) }}
                            </td>
                            @endif
                        @endforeach
                    @endforeach
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-black text-white font-bold dsppr-day-total-row">
                    <td class="dsppr-sticky dsppr-sticky-footer px-3 py-2.5">TOTAL</td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            $dayTotal = \App\Support\DsPprCalculator::sum($rows->map(function ($row) use ($dailyByKey, $dateStr) {
                                $entry = $dailyByKey->get($row['product']->id . ':' . $dateStr);
                                return $entry ? $entry->toArray() : ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'total_leads' => 0, 'catered_leads' => 0];
                            })->all());
                        @endphp
                        @foreach($dayColumns as $i => $col)
                        <td class="px-3 py-2.5 text-right {{ $i === 0 ? 'border-l-2 border-yellow-900' : '' }} {{ $col['key'] === 'net_income' && $dayTotal['net_income'] < 0 ? 'text-red-400' : '' }} {{ $col['key'] === 'ni_pct' && $dayTotal['ni_pct'] < 0 ? 'text-red-400' : '' }}"
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

    // Same live comma-formatting convention as Projections' own pj.js.
    function liveFormatMoney(input) {
        const raw = input.value;
        const caretFromEnd = raw.length - (input.selectionStart ?? raw.length);
        const cleaned = raw.replace(/[^0-9.]/g, '');
        const firstDot = cleaned.indexOf('.');
        const intPart = firstDot === -1 ? cleaned : cleaned.slice(0, firstDot);
        const fracPart = firstDot === -1 ? '' : cleaned.slice(firstDot);
        const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const formatted = grouped + fracPart;
        if (formatted === raw) return;
        input.value = formatted;
        const pos = Math.max(0, formatted.length - caretFromEnd);
        input.setSelectionRange(pos, pos);
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
            el.classList.toggle('text-red-600', key === 'ni_pct' && derived[key] < 0);
            el.classList.toggle('dark:text-red-400', key === 'ni_pct' && derived[key] < 0);
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
    function refreshDayTotal(table, date) {
        const rows = table.querySelectorAll('tbody .dsppr-row');
        let totals = { gross_sales: 0, net_income: 0, ads_spent: 0, total_orders: 0, total_leads: 0, catered_leads: 0 };
        let pickupSum = 0, convSum = 0, upsellSum = 0, rowCount = 0;
        rows.forEach((row) => {
            const grossSales = parseMoney(row.querySelector(`[data-field="gross_sales"][data-date="${date}"]`).value);
            const netIncome = parseMoney(row.querySelector(`[data-field="net_income"][data-date="${date}"]`).value);
            const adsSpent = parseMoney(row.querySelector(`[data-field="ads_spent"][data-date="${date}"]`).value);
            const totalOrders = Number(row.querySelector(`[data-field="total_orders"][data-date="${date}"]`).value) || 0;
            const totalLeads = Number(row.querySelector(`[data-field="total_leads"][data-date="${date}"]`).value) || 0;
            const cateredLeads = Number(row.querySelector(`[data-field="catered_leads"][data-date="${date}"]`).value) || 0;

            totals.gross_sales += grossSales;
            totals.net_income += netIncome;
            totals.ads_spent += adsSpent;
            totals.total_orders += totalOrders;
            totals.total_leads += totalLeads;
            totals.catered_leads += cateredLeads;

            pickupSum += totalLeads > 0 ? cateredLeads / totalLeads : 0;
            convSum += cateredLeads > 0 ? totalOrders / cateredLeads : 0;
            upsellSum += cateredLeads > 0 ? totalOrders / cateredLeads : 0;
            rowCount += 1;
        });
        const excessLeads = Math.max(0, totals.total_leads - totals.catered_leads);
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income, ads_spent: totals.ads_spent,
            total_orders: totals.total_orders, total_leads: totals.total_leads, catered_leads: totals.catered_leads,
            excess_leads: excessLeads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            actual_cost_per_lead: totals.total_leads > 0 ? totals.ads_spent / totals.total_leads : 0,
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
    function refreshSummaryRow(productId) {
        const summaryTable = document.getElementById('dsPprSummaryTable');
        if (!summaryTable) return;

        let totals = { gross_sales: 0, net_income: 0, ads_spent: 0, total_orders: 0, total_leads: 0, catered_leads: 0 };
        let pickupSum = 0, convSum = 0, upsellSum = 0, dayCount = 0;

        document.querySelectorAll(`.dsppr-days-table .dsppr-row[data-product-id="${productId}"]`).forEach((row) => {
            row.querySelectorAll('[data-field="gross_sales"]').forEach((el) => {
                const date = el.dataset.date;
                const grossSales = parseMoney(el.value);
                const netIncome = parseMoney(row.querySelector(`[data-field="net_income"][data-date="${date}"]`).value);
                const adsSpent = parseMoney(row.querySelector(`[data-field="ads_spent"][data-date="${date}"]`).value);
                const totalOrders = Number(row.querySelector(`[data-field="total_orders"][data-date="${date}"]`).value) || 0;
                const totalLeads = Number(row.querySelector(`[data-field="total_leads"][data-date="${date}"]`).value) || 0;
                const cateredLeads = Number(row.querySelector(`[data-field="catered_leads"][data-date="${date}"]`).value) || 0;

                totals.gross_sales += grossSales;
                totals.net_income += netIncome;
                totals.ads_spent += adsSpent;
                totals.total_orders += totalOrders;
                totals.total_leads += totalLeads;
                totals.catered_leads += cateredLeads;

                pickupSum += totalLeads > 0 ? cateredLeads / totalLeads : 0;
                convSum += cateredLeads > 0 ? totalOrders / cateredLeads : 0;
                upsellSum += cateredLeads > 0 ? totalOrders / cateredLeads : 0;
                dayCount += 1;
            });
        });

        const excessLeads = Math.max(0, totals.total_leads - totals.catered_leads);
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income, ads_spent: totals.ads_spent,
            total_orders: totals.total_orders, total_leads: totals.total_leads, catered_leads: totals.catered_leads,
            excess_leads: excessLeads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            actual_cost_per_lead: totals.total_leads > 0 ? totals.ads_spent / totals.total_leads : 0,
            pickup_rate: dayCount > 0 ? pickupSum / dayCount : 0,
            conversion_rate: dayCount > 0 ? convSum / dayCount : 0,
            upselling_rate: dayCount > 0 ? upsellSum / dayCount : 0,
        };

        const summaryRow = summaryTable.querySelector(`.dsppr-summary-row[data-product-id="${productId}"]`);
        if (summaryRow) {
            summaryRow.querySelectorAll('[data-out]').forEach((el) => {
                const key = el.dataset.out;
                if (!(key in derived)) return;
                const isPct = ['ni_pct', 'pickup_rate', 'conversion_rate', 'upselling_rate'].includes(key);
                const isInt = ['excess_leads', 'total_orders', 'total_leads', 'catered_leads'].includes(key);
                el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
                el.classList.toggle('text-red-600', key === 'ni_pct' && derived[key] < 0);
                el.classList.toggle('dark:text-red-400', key === 'ni_pct' && derived[key] < 0);
            });
        }

        refreshOverallTotal(summaryTable);
    }

    // Recomputes and repaints the OVERALL TOTAL row from every product's
    // own already-updated summary row — same "sum dollars, average
    // rates" split as everywhere else on this page.
    function refreshOverallTotal(summaryTable) {
        let totals = { gross_sales: 0, net_income: 0, ads_spent: 0, total_orders: 0, total_leads: 0, catered_leads: 0, excess_leads: 0 };
        let pickupSum = 0, convSum = 0, upsellSum = 0, rowCount = 0;

        summaryTable.querySelectorAll('tbody .dsppr-summary-row').forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector('[data-out="gross_sales"]').textContent);
            totals.net_income += parseMoney(row.querySelector('[data-out="net_income"]').textContent);
            totals.ads_spent += parseMoney(row.querySelector('[data-out="ads_spent"]').textContent);
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
            gross_sales: totals.gross_sales, net_income: totals.net_income, ads_spent: totals.ads_spent,
            total_orders: totals.total_orders, total_leads: totals.total_leads, catered_leads: totals.catered_leads,
            excess_leads: totals.excess_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            actual_cost_per_lead: totals.total_leads > 0 ? totals.ads_spent / totals.total_leads : 0,
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
        });
    }

    function saveField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const table = input.closest('.dsppr-days-table');
        const urlTemplate = table.dataset.updateUrlTemplate;
        const row = input.closest('.dsppr-row');
        const date = input.dataset.date;
        const field = input.dataset.field;
        const value = input.dataset.money === '1' ? parseMoney(input.value) : (Number(input.value) || 0);

        flashStatus('Saving…', false);

        const url = urlTemplate.replace('__PRODUCT__', row.dataset.productId).replace('__DATE__', date);
        const body = new URLSearchParams();
        body.set(field, value);
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
                if (data?.derived) applyDerived(row, date, data.derived);
                refreshDayTotal(table, date);
                refreshSummaryRow(row.dataset.productId);
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
            clearTimeout(saveTimers.get(input));
            saveTimers.set(input, setTimeout(() => saveField(input), 600));
        });

        table.addEventListener('blur', (e) => {
            const input = e.target.closest('.dsppr-field');
            if (input) saveField(input);
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
})();
</script>
@endpush

@endsection
