@extends('layouts.data')
@section('title', 'Summary Sales Report')
@section('subtitle', 'Per-TSA daily sales report — every figure auto-saves as you type')

@section('content')

<style>
    .tsr-sticky { position: sticky; left: 0; z-index: 5; }
    thead .tsr-sticky { z-index: 25; }
    .tsr-sticky-body { background-color: #fff; }
    .dark .tsr-sticky-body { background-color: #0f172a; }
    tr:hover > .tsr-sticky-body { background-color: #f8fafc; }
    .dark tr:hover > .tsr-sticky-body { background-color: #1e293b; }
    .tsr-sticky-footer { background-color: #000; }
    .tsr-table { font-variant-numeric: tabular-nums; }
    .tsr-scroller { cursor: grab; }
    .tsr-scroller.cursor-grabbing { cursor: grabbing; }

    /* Same "one separator per day" border convention as DSPPR - TSM
       Report (explicit request there, 2026-09-24, reused here for
       visual consistency between the two report pages): thin row rules
       only, one thick vertical rule at each day's own right edge. */
    .tsr-table th, .tsr-table td { border: none; border-bottom: 1px solid #cbd5e1; }
    .dark .tsr-table th, .dark .tsr-table td { border-bottom-color: #475569; }
    .tsr-table .tsr-day-end { border-right: 3px solid #334155; }
    .dark .tsr-table .tsr-day-end { border-right-color: #cbd5e1; }
</style>

@php
    $fmtMoney = fn ($n) => number_format((float) $n, 2);
    $fmtPct   = fn ($n) => number_format(((float) $n) * 100, 2) . '%';

    // One repeating 9-column set per day (confirmed exact against the
    // real sheet's own Summary - Sales Report tab, 2026-09-24) — a
    // smaller set than DSPPR - TSM Report's own 13 (no Total Leads,
    // Excess Leads, or Conversion Rate on THIS sheet). Pick-up Rate and
    // Upselling Rate are editable here (raw manual entry, unlike DSPPR's
    // read-only versions) — see TsaSalesCalculator's own doc comment for
    // why neither can be derived on either sheet.
    $dayColumns = [
        ['key' => 'gross_sales', 'label' => 'Gross Sales', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'net_income', 'label' => 'Net Income', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'ads_spent', 'label' => 'Ads Spent', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'ni_pct', 'label' => 'NI %', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-slate-200 dark:bg-slate-600'],
        ['key' => 'total_orders', 'label' => 'Total Orders', 'editable' => true, 'int' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'aov', 'label' => 'AOV', 'editable' => false, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'catered_leads', 'label' => 'Catered Leads', 'editable' => true, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'editable' => true, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'editable' => true, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
    ];
    $lastColIndex = count($dayColumns) - 1;
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap" id="tsrFilterForm">
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
        <span id="tsrSaveStatus" class="text-xs font-mono text-slate-400 dark:text-slate-500 min-h-[1.25rem]"></span>
    </div>
</div>

{{-- Running summary — the sheet's own MTD block (rows 12-25 in the real
     source): each group (Team Opening Shift, Team Closing Shift, Tiktok
     Upsell) lists its own TSA rows plus a group TOTAL row, closed out by
     one OVERALL TOTAL across every group. --}}
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-8">
    <div class="bg-black text-white text-center font-mono font-bold text-sm tracking-wide py-2.5">
        TSA'S RUNNING SALES PERFORMANCE
    </div>
    <div class="bg-yellow-300 dark:bg-yellow-500 text-center font-mono font-bold text-xs tracking-wide py-2 text-ink">
        {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($dateTo)->format('M j, Y') }}
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-[13px] font-mono border-collapse tsr-table" id="tsrSummaryTable">
            <thead>
                <tr class="text-ink dark:text-slate-950">
                    <th class="tsr-sticky bg-yellow-200 dark:bg-yellow-700 text-left px-3 py-2 font-bold whitespace-nowrap">TSA</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Gross Sales</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Net Income</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Ads Spent</th>
                    <th class="bg-slate-200 dark:bg-slate-600 text-right px-3 py-2 font-bold whitespace-nowrap">NI %</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">Total Orders</th>
                    <th class="bg-yellow-200 dark:bg-yellow-700 text-right px-3 py-2 font-bold whitespace-nowrap">AOV</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Catered Leads</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Pick-up Rate</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Upselling Rate</th>
                </tr>
            </thead>
            @foreach($groupSummaries as $gs)
            <tbody>
                @foreach($gs['rows'] as $rs)
                @php $d = $rs['derived']; @endphp
                <tr class="tsr-summary-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-row-id="{{ $rs['row']->id }}">
                    <td class="tsr-sticky tsr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ strtoupper($rs['row']->name) }}</td>
                    <td class="px-3 py-2 text-right" data-out="gross_sales">{{ $fmtMoney($d['gross_sales']) }}</td>
                    <td class="px-3 py-2 text-right {{ $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}" data-out="net_income">{{ $fmtMoney($d['net_income']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="ads_spent">{{ $fmtMoney($d['ads_spent']) }}</td>
                    <td class="px-3 py-2 text-right {{ $d['ni_pct'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}" data-out="ni_pct">{{ $fmtPct($d['ni_pct']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="total_orders">{{ number_format($d['total_orders']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="aov">{{ $fmtMoney($d['aov']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="catered_leads">{{ number_format($d['catered_leads']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="pickup_rate">{{ $fmtPct($d['pickup_rate']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="upselling_rate">{{ $fmtPct($d['upselling_rate']) }}</td>
                </tr>
                @endforeach
                @php $gt = $gs['groupTotal']; @endphp
                <tr class="tsr-group-total-row bg-slate-800 text-white font-bold" data-group-id="{{ $gs['group']->id }}">
                    <td class="tsr-sticky px-3 py-2.5" style="background-color:#1e293b;">{{ strtoupper($gs['group']->label) }} TOTAL:</td>
                    <td class="px-3 py-2.5 text-right" data-out="gross_sales">{{ $fmtMoney($gt['gross_sales']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $gt['net_income'] < 0 ? 'text-red-400' : '' }}" data-out="net_income">{{ $fmtMoney($gt['net_income']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="ads_spent">{{ $fmtMoney($gt['ads_spent']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $gt['ni_pct'] < 0 ? 'text-red-400' : '' }}" data-out="ni_pct">{{ $fmtPct($gt['ni_pct']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="total_orders">{{ number_format($gt['total_orders']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="aov">{{ $fmtMoney($gt['aov']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="catered_leads">{{ number_format($gt['catered_leads']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="pickup_rate">{{ $fmtPct($gt['pickup_rate']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="upselling_rate">{{ $fmtPct($gt['upselling_rate']) }}</td>
                </tr>
            </tbody>
            @endforeach
            <tfoot>
                <tr class="bg-black text-white font-bold tsr-overall-total-row">
                    <td class="tsr-sticky tsr-sticky-footer px-3 py-2.5">OVERALL TOTAL</td>
                    <td class="px-3 py-2.5 text-right" data-out="gross_sales">{{ $fmtMoney($overallTotal['gross_sales']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $overallTotal['net_income'] < 0 ? 'text-red-400' : '' }}" data-out="net_income">{{ $fmtMoney($overallTotal['net_income']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="ads_spent">{{ $fmtMoney($overallTotal['ads_spent']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $overallTotal['ni_pct'] < 0 ? 'text-red-400' : '' }}" data-out="ni_pct">{{ $fmtPct($overallTotal['ni_pct']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="total_orders">{{ number_format($overallTotal['total_orders']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="aov">{{ $fmtMoney($overallTotal['aov']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="catered_leads">{{ number_format($overallTotal['catered_leads']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="pickup_rate">{{ $fmtPct($overallTotal['pickup_rate']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="upselling_rate">{{ $fmtPct($overallTotal['upselling_rate']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{-- Daily entry — one table PER 7-day chunk PER group (same "only 7 days,
     drag right, next chunk stacks below" convention as DSPPR - TSM
     Report), each group given its own "+ Add TSA" control to grow its
     row list. --}}
@foreach($groupSummaries as $gs)
@php $group = $gs['group']; @endphp
<div class="mb-3 flex items-center justify-between gap-3">
    <h2 class="text-sm font-mono font-bold uppercase tracking-widest text-ink dark:text-slate-100">{{ $group->label }}</h2>
    <button type="button" class="tsr-add-row-btn inline-flex items-center gap-1.5 text-xs font-mono font-semibold text-primary-dark dark:text-yellow-400 border border-primary/40 dark:border-yellow-700 rounded-lg px-3 py-1.5 hover:bg-primary/10 dark:hover:bg-yellow-950/40 cursor-pointer"
            data-group-id="{{ $group->id }}" data-store-url="{{ route('data.tsa-sales.rows.store', $group) }}">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Add TSA
    </button>
</div>

@foreach($dateChunks as $chunkIndex => $dates)
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-6">
    <div class="overflow-x-auto tsr-scroller" id="tsrScroller-{{ $group->id }}-{{ $chunkIndex }}">
        <table class="text-[13px] font-mono border-collapse tsr-table tsr-days-table"
               data-update-url-template="{{ route('data.tsa-sales.update-entry', ['tsaSalesRow' => '__ROW__', 'date' => '__DATE__']) }}"
               data-rename-url-template="{{ route('data.tsa-sales.rows.update', '__ROW__') }}"
               data-destroy-url-template="{{ route('data.tsa-sales.rows.destroy', '__ROW__') }}">
            <thead>
                <tr>
                    <th rowspan="2" class="tsr-sticky bg-yellow-300 dark:bg-yellow-600 text-left px-3 py-2 font-bold text-ink whitespace-nowrap align-bottom">TSA</th>
                    @foreach($dates as $date)
                    <th colspan="{{ count($dayColumns) }}" class="bg-yellow-300 dark:bg-yellow-600 text-center font-bold text-ink px-3 py-2 whitespace-nowrap tsr-day-end">
                        {{ $date->format('D, M j') }}
                    </th>
                    @endforeach
                </tr>
                <tr>
                    @foreach($dates as $date)
                        @foreach($dayColumns as $i => $col)
                        <th class="text-right px-3 py-2 font-bold whitespace-nowrap text-ink dark:text-slate-950 {{ $col['headerBg'] }} {{ $i === $lastColIndex ? 'tsr-day-end' : '' }}">
                            {{ $col['label'] }}
                        </th>
                        @endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($group->rows as $row)
                <tr class="tsr-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-row-id="{{ $row->id }}">
                    <td class="tsr-sticky tsr-sticky-body px-2 py-1.5 whitespace-nowrap">
                        <div class="flex items-center gap-1.5">
                            <input type="text" value="{{ $row->name }}" data-tsr-rename="1"
                                   class="tsr-name-field flex-1 min-w-0 bg-transparent border-none focus:ring-2 focus:ring-primary/40 rounded-md px-1 py-0.5 -mx-1 font-semibold text-ink dark:text-slate-100 uppercase outline-none">
                            <button type="button" class="tsr-remove-row-btn shrink-0 p-1 rounded text-slate-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40 cursor-pointer" title="Remove this TSA">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            $entry = $dailyByKey->get($row->id . ':' . $dateStr);
                            $raw = $entry ? $entry->toArray() : ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'catered_leads' => 0, 'pickup_rate' => 0, 'upselling_rate' => 0];
                            $d = \App\Support\TsaSalesCalculator::derive($raw);
                        @endphp
                        @foreach($dayColumns as $i => $col)
                            @php $borderClass = $i === $lastColIndex ? 'tsr-day-end' : ''; @endphp
                            @if($col['editable'])
                            <td class="px-2 py-1.5 {{ $borderClass }}">
                                <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                                       value="{{ ($col['money'] ?? false) ? number_format($raw[$col['key']], 2) : (($col['pct'] ?? false) ? number_format($raw[$col['key']] * 100, 2) : $raw[$col['key']]) }}"
                                       data-field="{{ $col['key'] }}" data-date="{{ $dateStr }}"
                                       @if($col['money'] ?? false) data-money="1" @endif
                                       @if($col['pct'] ?? false) data-percent="1" @endif
                                       class="tsr-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
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
                @empty
                <tr class="tsr-empty-row">
                    <td colspan="{{ 1 + count($dates) * count($dayColumns) }}" class="px-3 py-4 text-center text-ink-muted dark:text-slate-500">
                        No TSAs in this group yet — click "Add TSA" above.
                    </td>
                </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="bg-black text-white font-bold tsr-day-total-row">
                    <td class="tsr-sticky tsr-sticky-footer px-3 py-2.5">TOTAL</td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            $dayTotal = \App\Support\TsaSalesCalculator::sum($group->rows->map(function ($row) use ($dailyByKey, $dateStr) {
                                $entry = $dailyByKey->get($row->id . ':' . $dateStr);
                                return $entry ? $entry->toArray() : ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'catered_leads' => 0, 'pickup_rate' => 0, 'upselling_rate' => 0];
                            })->all());
                        @endphp
                        @foreach($dayColumns as $i => $col)
                        <td class="px-3 py-2.5 text-right {{ $i === $lastColIndex ? 'tsr-day-end' : '' }} {{ $col['key'] === 'net_income' && $dayTotal['net_income'] < 0 ? 'text-red-400' : '' }} {{ $col['key'] === 'ni_pct' && $dayTotal['ni_pct'] < 0 ? 'text-red-400' : '' }}"
                            data-out="{{ $col['key'] }}" data-date="{{ $dateStr }}">
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
@endforeach

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const saveTimers = new WeakMap();
    const globalStatus = document.getElementById('tsrSaveStatus');

    function fmtMoney(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtInt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
    function fmtPct(n) { return (Number(n) * 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%'; }
    function parseMoney(str) { return Number(String(str).replace(/,/g, '')) || 0; }
    function parsePercentInput(str) { return (Number(String(str).replace(/,/g, '')) || 0) / 100; }

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

    function applyDerived(row, date, derived) {
        row.querySelectorAll(`[data-out][data-date="${date}"]`).forEach((el) => {
            const key = el.dataset.out;
            if (!(key in derived)) return;
            const isPct = ['ni_pct', 'pickup_rate', 'upselling_rate'].includes(key);
            const isInt = ['total_orders', 'catered_leads'].includes(key);
            el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
            el.classList.toggle('text-red-600', key === 'ni_pct' && derived[key] < 0);
            el.classList.toggle('dark:text-red-400', key === 'ni_pct' && derived[key] < 0);
        });
    }

    function refreshDayTotal(table, date) {
        const rows = table.querySelectorAll('tbody .tsr-row');
        let totals = { gross_sales: 0, net_income: 0, ads_spent: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, rowCount = 0;
        rows.forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector(`[data-field="gross_sales"][data-date="${date}"]`).value);
            totals.net_income += parseMoney(row.querySelector(`[data-field="net_income"][data-date="${date}"]`).value);
            totals.ads_spent += parseMoney(row.querySelector(`[data-field="ads_spent"][data-date="${date}"]`).value);
            totals.total_orders += Number(row.querySelector(`[data-field="total_orders"][data-date="${date}"]`).value) || 0;
            totals.catered_leads += Number(row.querySelector(`[data-field="catered_leads"][data-date="${date}"]`).value) || 0;
            pickupSum += parsePercentInput(row.querySelector(`[data-field="pickup_rate"][data-date="${date}"]`).value);
            upsellSum += parsePercentInput(row.querySelector(`[data-field="upselling_rate"][data-date="${date}"]`).value);
            rowCount += 1;
        });
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income, ads_spent: totals.ads_spent,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            pickup_rate: rowCount > 0 ? pickupSum / rowCount : 0,
            upselling_rate: rowCount > 0 ? upsellSum / rowCount : 0,
        };
        const totalRow = table.querySelector('.tsr-day-total-row');
        if (totalRow) applyDerived(totalRow, date, derived);
    }

    // Recomputes ONE row's summary (top MTD table), then the whole
    // group's total, then the OVERALL TOTAL — summed across EVERY date
    // in EVERY 7-day chunk for that row (same convention as DSPPR - TSM
    // Report's own refreshSummaryRow()).
    function refreshSummaryRow(rowId) {
        const summaryTable = document.getElementById('tsrSummaryTable');
        if (!summaryTable) return;

        let totals = { gross_sales: 0, net_income: 0, ads_spent: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, dayCount = 0;

        document.querySelectorAll(`.tsr-days-table .tsr-row[data-row-id="${rowId}"]`).forEach((row) => {
            row.querySelectorAll('[data-field="gross_sales"]').forEach((el) => {
                const date = el.dataset.date;
                totals.gross_sales += parseMoney(el.value);
                totals.net_income += parseMoney(row.querySelector(`[data-field="net_income"][data-date="${date}"]`).value);
                totals.ads_spent += parseMoney(row.querySelector(`[data-field="ads_spent"][data-date="${date}"]`).value);
                totals.total_orders += Number(row.querySelector(`[data-field="total_orders"][data-date="${date}"]`).value) || 0;
                totals.catered_leads += Number(row.querySelector(`[data-field="catered_leads"][data-date="${date}"]`).value) || 0;
                pickupSum += parsePercentInput(row.querySelector(`[data-field="pickup_rate"][data-date="${date}"]`).value);
                upsellSum += parsePercentInput(row.querySelector(`[data-field="upselling_rate"][data-date="${date}"]`).value);
                dayCount += 1;
            });
        });

        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income, ads_spent: totals.ads_spent,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            pickup_rate: dayCount > 0 ? pickupSum / dayCount : 0,
            upselling_rate: dayCount > 0 ? upsellSum / dayCount : 0,
        };

        const summaryRow = summaryTable.querySelector(`.tsr-summary-row[data-row-id="${rowId}"]`);
        if (summaryRow) {
            summaryRow.querySelectorAll('[data-out]').forEach((el) => {
                const key = el.dataset.out;
                if (!(key in derived)) return;
                const isPct = ['ni_pct', 'pickup_rate', 'upselling_rate'].includes(key);
                const isInt = ['total_orders', 'catered_leads'].includes(key);
                el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
                el.classList.toggle('text-red-600', key === 'ni_pct' && derived[key] < 0);
                el.classList.toggle('dark:text-red-400', key === 'ni_pct' && derived[key] < 0);
            });

            const groupTotalRow = summaryRow.closest('tbody').querySelector('.tsr-group-total-row');
            refreshGroupTotal(groupTotalRow);
        }

        refreshOverallTotal(summaryTable);
    }

    function refreshGroupTotal(groupTotalRow) {
        if (!groupTotalRow) return;
        const tbody = groupTotalRow.closest('tbody');
        let totals = { gross_sales: 0, net_income: 0, ads_spent: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, rowCount = 0;

        tbody.querySelectorAll('.tsr-summary-row').forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector('[data-out="gross_sales"]').textContent);
            totals.net_income += parseMoney(row.querySelector('[data-out="net_income"]').textContent);
            totals.ads_spent += parseMoney(row.querySelector('[data-out="ads_spent"]').textContent);
            totals.total_orders += parseMoney(row.querySelector('[data-out="total_orders"]').textContent);
            totals.catered_leads += parseMoney(row.querySelector('[data-out="catered_leads"]').textContent);
            pickupSum += parseFloat(row.querySelector('[data-out="pickup_rate"]').textContent) / 100;
            upsellSum += parseFloat(row.querySelector('[data-out="upselling_rate"]').textContent) / 100;
            rowCount += 1;
        });

        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income, ads_spent: totals.ads_spent,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            pickup_rate: rowCount > 0 ? pickupSum / rowCount : 0,
            upselling_rate: rowCount > 0 ? upsellSum / rowCount : 0,
        };

        groupTotalRow.querySelectorAll('[data-out]').forEach((el) => {
            const key = el.dataset.out;
            if (!(key in derived)) return;
            const isPct = ['ni_pct', 'pickup_rate', 'upselling_rate'].includes(key);
            const isInt = ['total_orders', 'catered_leads'].includes(key);
            el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
            el.classList.toggle('text-red-400', key === 'ni_pct' && derived[key] < 0);
        });
    }

    function refreshOverallTotal(summaryTable) {
        let totals = { gross_sales: 0, net_income: 0, ads_spent: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, rowCount = 0;

        summaryTable.querySelectorAll('tbody .tsr-summary-row').forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector('[data-out="gross_sales"]').textContent);
            totals.net_income += parseMoney(row.querySelector('[data-out="net_income"]').textContent);
            totals.ads_spent += parseMoney(row.querySelector('[data-out="ads_spent"]').textContent);
            totals.total_orders += parseMoney(row.querySelector('[data-out="total_orders"]').textContent);
            totals.catered_leads += parseMoney(row.querySelector('[data-out="catered_leads"]').textContent);
            pickupSum += parseFloat(row.querySelector('[data-out="pickup_rate"]').textContent) / 100;
            upsellSum += parseFloat(row.querySelector('[data-out="upselling_rate"]').textContent) / 100;
            rowCount += 1;
        });

        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income, ads_spent: totals.ads_spent,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            aov: totals.total_orders > 0 ? totals.gross_sales / totals.total_orders : 0,
            pickup_rate: rowCount > 0 ? pickupSum / rowCount : 0,
            upselling_rate: rowCount > 0 ? upsellSum / rowCount : 0,
        };

        const totalRow = summaryTable.querySelector('.tsr-overall-total-row');
        if (!totalRow) return;
        totalRow.querySelectorAll('[data-out]').forEach((el) => {
            const key = el.dataset.out;
            if (!(key in derived)) return;
            const isPct = ['ni_pct', 'pickup_rate', 'upselling_rate'].includes(key);
            const isInt = ['total_orders', 'catered_leads'].includes(key);
            el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
            el.classList.toggle('text-red-400', key === 'ni_pct' && derived[key] < 0);
        });
    }

    function saveField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const table = input.closest('.tsr-days-table');
        const urlTemplate = table.dataset.updateUrlTemplate;
        const row = input.closest('.tsr-row');
        const date = input.dataset.date;
        const field = input.dataset.field;
        const value = input.dataset.money === '1' ? parseMoney(input.value)
            : (input.dataset.percent === '1' ? parsePercentInput(input.value)
            : (Number(input.value) || 0));

        flashStatus('Saving…', false);

        const url = urlTemplate.replace('__ROW__', row.dataset.rowId).replace('__DATE__', date);
        const body = new URLSearchParams();
        body.set(field, value);
        body.set('_method', 'PATCH');

        fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
            body: body.toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                flashStatus('Saved', false);
                if (document.activeElement !== input) {
                    if (input.dataset.money === '1') input.value = fmtMoney(value);
                    else if (input.dataset.percent === '1') input.value = (value * 100).toFixed(2);
                }
                if (data?.derived) applyDerived(row, date, data.derived);
                refreshDayTotal(table, date);
                refreshSummaryRow(row.dataset.rowId);
            })
            .catch(() => {
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    function renameRow(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);
        const table = input.closest('.tsr-days-table');
        const row = input.closest('.tsr-row');
        const url = table.dataset.renameUrlTemplate.replace('__ROW__', row.dataset.rowId);
        const body = new URLSearchParams();
        body.set('name', input.value);
        body.set('_method', 'PATCH');

        flashStatus('Saving…', false);
        fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
            body: body.toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => {
                flashStatus('Saved', false);
                document.querySelectorAll(`[data-row-id="${row.dataset.rowId}"] .tsr-name-field`).forEach((el) => {
                    if (el !== input) el.value = input.value;
                });
                document.querySelectorAll(`.tsr-summary-row[data-row-id="${row.dataset.rowId}"] .tsr-sticky-body`).forEach((el) => {
                    el.textContent = input.value.toUpperCase();
                });
            })
            .catch(() => {
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not rename — try again.', 'error');
            });
    }

    function removeRow(button) {
        const row = button.closest('.tsr-row');
        const table = button.closest('.tsr-days-table');
        const rowId = row.dataset.rowId;
        const name = row.querySelector('.tsr-name-field')?.value || 'this TSA';
        if (!confirm(`Remove ${name}? This deletes every saved number for this row.`)) return;

        const url = table.dataset.destroyUrlTemplate.replace('__ROW__', rowId);
        fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: new URLSearchParams({ _method: 'DELETE' }).toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => {
                document.querySelectorAll(`[data-row-id="${rowId}"]`).forEach((el) => el.remove());
                window.showToast?.(`${name} removed.`, 'success');
            })
            .catch(() => window.showToast?.('Could not remove — try again.', 'error'));
    }

    function addRow(button) {
        const name = prompt('TSA name:');
        if (!name || !name.trim()) return;

        fetch(button.dataset.storeUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
            body: new URLSearchParams({ name: name.trim() }).toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => {
                window.showToast?.('TSA added — reloading…', 'success');
                window.location.reload();
            })
            .catch(() => window.showToast?.('Could not add TSA — try again.', 'error'));
    }

    document.querySelectorAll('.tsr-days-table').forEach((table) => {
        table.addEventListener('input', (e) => {
            const field = e.target.closest('.tsr-field');
            const nameField = e.target.closest('.tsr-name-field');
            if (field) {
                if (field.dataset.money === '1' || field.dataset.percent === '1') liveFormatMoney(field);
                clearTimeout(saveTimers.get(field));
                saveTimers.set(field, setTimeout(() => saveField(field), 600));
            } else if (nameField) {
                clearTimeout(saveTimers.get(nameField));
                saveTimers.set(nameField, setTimeout(() => renameRow(nameField), 600));
            }
        });

        table.addEventListener('blur', (e) => {
            const field = e.target.closest('.tsr-field');
            const nameField = e.target.closest('.tsr-name-field');
            if (field) saveField(field);
            else if (nameField) renameRow(nameField);
        }, true);

        table.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const input = e.target.closest('.tsr-field, .tsr-name-field');
            if (input) { e.preventDefault(); input.blur(); }
        });

        table.addEventListener('click', (e) => {
            const btn = e.target.closest('.tsr-remove-row-btn');
            if (btn) removeRow(btn);
        });
    });

    document.querySelectorAll('.tsr-add-row-btn').forEach((btn) => {
        btn.addEventListener('click', () => addRow(btn));
    });

    document.querySelectorAll('.tsr-scroller').forEach((scroller) => {
        let isDragging = false, dragStartX = 0, dragStartScroll = 0;
        scroller.addEventListener('mousedown', (e) => {
            if (e.target.closest('input, button')) return;
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
