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

    // Net Income color tiers (explicit request, 2026-10-03: "lahat ng
    // number kapag nilagay ay black tapos sa net income mag green lang
    // yung color niya kapag nakahit siya ng 4.2K and above then stay mo
    // sa negative kapag red tapos kapag 1 - 4,199 yung net income stay
    // lang siya sa black") — three tiers, not the old plain "negative=red,
    // everything else=green": NEGATIVE stays red, 0–4,199.99 is now BLACK
    // (not green), and green only starts at the 4,200 threshold itself
    // (explicit confirmation: >= 4200, inclusive). $darkMode true reads the
    // dark-theme class variant (text-red-400/text-green-400, no explicit
    // black — the theme's own default ink color already covers that tier).
    $niColorClass = function (float $netIncome, bool $darkMode = false) {
        if ($netIncome < 0) return $darkMode ? 'text-red-400' : 'text-red-600 dark:text-red-400';
        if ($netIncome >= 4200) return $darkMode ? 'text-green-400' : 'text-green-600 dark:text-green-400';
        return $darkMode ? '' : 'text-ink dark:text-slate-100';
    };

    // One repeating 8-column set per day (confirmed exact against the
    // real sheet's own Summary - Sales Report tab, 2026-09-24, Ads Spent
    // removed 2026-09-26 per explicit request) — a smaller set than DSPPR
    // - TSM Report's own 13 (no Total Leads, Excess Leads, or Conversion
    // Rate on THIS sheet). Pick-up Rate and Upselling Rate are editable
    // here (raw manual entry, unlike DSPPR's read-only versions) — see
    // TsaSalesCalculator's own doc comment for why neither can be derived
    // on either sheet.
    $dayColumns = [
        ['key' => 'gross_sales', 'label' => 'Gross Sales', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'net_income', 'label' => 'Net Income', 'editable' => true, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'ni_pct', 'label' => 'NI %', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-slate-200 dark:bg-slate-600'],
        // Total Orders, Catered Leads, Pick-up Rate, Upselling Rate: all 4
        // auto-computed from TSA Performance's own data now, not manual
        // entry — see TsaSalesReportController's own doc comment, 2026-10-03.
        ['key' => 'total_orders', 'label' => 'Total Orders', 'editable' => false, 'int' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'aov', 'label' => 'AOV', 'editable' => false, 'money' => true, 'headerBg' => 'bg-yellow-100 dark:bg-yellow-800'],
        ['key' => 'catered_leads', 'label' => 'Catered Leads', 'editable' => false, 'int' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'pickup_rate', 'label' => 'Pick-up Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
        ['key' => 'upselling_rate', 'label' => 'Upselling Rate', 'editable' => false, 'pct' => true, 'headerBg' => 'bg-rose-200 dark:bg-rose-800'],
    ];
    $lastColIndex = count($dayColumns) - 1;
    $emptyRaw = ['gross_sales' => 0, 'net_income' => 0, 'ads_spent' => 0, 'total_orders' => 0, 'catered_leads' => 0, 'pickup_rate' => 0, 'upselling_rate' => 0, 'upsell_sales' => 0, 'upsell_confirmation' => 0];
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap" id="tsrFilterForm">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    </form>
    <div class="flex items-center gap-3">
        <span class="hidden sm:inline-flex items-center gap-1.5 text-[11px] font-mono text-ink-muted dark:text-slate-400">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l-5 5m0 0l5 5m-5-5h18m-5-9l5 5m0 0l-5 5"/></svg>
            Drag/scroll right to see more days
        </span>
        <span id="tsrSaveStatus" class="text-xs font-mono text-slate-400 dark:text-slate-500 min-h-[1.25rem]"></span>
    </div>
</div>

{{-- Running summary — TSA rows are the app's own real TsaShift roster,
     grouped by their real team (SH Naturals / Eyecare — explicit
     decision, 2026-09-24: the sheet's own Opening/Closing Shift split
     has no real backing data in this app yet, so team is what's used
     instead). Add/remove a TSA via TSA Management, not here. --}}
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
                <tr class="tsr-summary-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-tsa-id="{{ $rs['tsa']->id }}">
                    <td class="tsr-sticky tsr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ strtoupper($rs['tsa']->display_name) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="gross_sales">{{ $fmtMoney($d['gross_sales']) }}</td>
                    <td class="px-3 py-2 text-right {{ $niColorClass($d['net_income']) }}" data-out="net_income">{{ $fmtMoney($d['net_income']) }}</td>
                    <td class="px-3 py-2 text-right {{ $d['ni_pct'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-ink dark:text-slate-100' }}" data-out="ni_pct">{{ $fmtPct($d['ni_pct']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="total_orders">{{ number_format($d['total_orders']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="aov">{{ $fmtMoney($d['aov']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="catered_leads">{{ number_format($d['catered_leads']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="pickup_rate">{{ $fmtPct($d['pickup_rate']) }}</td>
                    <td class="px-3 py-2 text-right text-ink dark:text-slate-100" data-out="upselling_rate">{{ $fmtPct($d['upselling_rate']) }}</td>
                </tr>
                @endforeach
                @php $gt = $gs['groupTotal']; @endphp
                <tr class="tsr-group-total-row bg-slate-800 text-white font-bold" data-group-label="{{ $gs['label'] }}">
                    <td class="tsr-sticky px-3 py-2.5" style="background-color:#1e293b;">{{ strtoupper($gs['label']) }} TOTAL:</td>
                    <td class="px-3 py-2.5 text-right" data-out="gross_sales">{{ $fmtMoney($gt['gross_sales']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $niColorClass($gt['net_income'], true) }}" data-out="net_income">{{ $fmtMoney($gt['net_income']) }}</td>
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
                    <td class="px-3 py-2.5 text-right {{ $niColorClass($overallTotal['net_income'], true) }}" data-out="net_income">{{ $fmtMoney($overallTotal['net_income']) }}</td>
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

{{-- Daily entry — one table PER 7-day chunk PER team (same "only 7 days,
     drag right, next chunk stacks below" convention as DSPPR - TSM
     Report). Rows are read from the real TsaShift roster — add/remove a
     TSA via TSA Management, not here. --}}
@foreach($groupSummaries as $gs)
<h2 class="mb-3 text-sm font-mono font-bold uppercase tracking-widest text-ink dark:text-slate-100">{{ $gs['label'] }}</h2>

@foreach($dateChunks as $chunkIndex => $dates)
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-6">
    <div class="overflow-x-auto tsr-scroller" id="tsrScroller-{{ \Illuminate\Support\Str::slug($gs['label']) }}-{{ $chunkIndex }}">
        <table class="text-[13px] font-mono border-collapse tsr-table tsr-days-table"
               data-update-url-template="{{ route('data.tsa-sales.update-entry', ['tsaShift' => '__TSA__', 'date' => '__DATE__']) }}">
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
                @forelse($gs['tsas'] as $tsa)
                <tr class="tsr-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-tsa-id="{{ $tsa->id }}">
                    <td class="tsr-sticky tsr-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ strtoupper($tsa->display_name) }}</td>
                    @foreach($dates as $date)
                        @php
                            $dateStr = $date->toDateString();
                            $raw = $dailyByKey->get($tsa->id . ':' . $dateStr, $emptyRaw);
                            $d = \App\Support\TsaSalesCalculator::derive($raw);
                            // AOV automated — explicit request, 2026-10-03:
                            // "also automate AOV like TSA Performance's" —
                            // upsell_sales ÷ upsell_confirmation (real
                            // tracked upsell revenue), overriding derive()'s
                            // own Gross Sales ÷ Total Orders for every OTHER
                            // caller of TsaSalesCalculator (see
                            // TsaSalesReportController::withAutomatedAov()'s
                            // own doc comment for why this stays an
                            // override here instead of a change to
                            // TsaSalesCalculator itself).
                            $d['aov'] = ($raw['upsell_confirmation'] ?? 0) > 0
                                ? $raw['upsell_sales'] / $raw['upsell_confirmation']
                                : 0.0;
                        @endphp
                        @foreach($dayColumns as $i => $col)
                            @php $borderClass = $i === $lastColIndex ? 'tsr-day-end' : ''; @endphp
                            @if($col['editable'])
                            {{-- Negative amounts allowed on every money input
                                 (explicit request, 2026-10-03: "i want to
                                 make it can input negative amount ... if
                                 negative red and if positive it is green
                                 like in the dsppr page") — Gross Sales AND
                                 Net Income both get live red/green coloring
                                 (DSPPR only colors Net Income; here BOTH, per
                                 explicit confirmation), seeded here from
                                 $raw and kept live as the user types via
                                 liveFormatMoney()'s own JS below. --}}
                            @php
                                // Same 3-tier rule ($niColorClass) applies
                                // to EVERY money input, not just Net
                                // Income — explicit follow-up, 2026-10-03:
                                // "the gross sales too is black" (i.e.
                                // Gross Sales was still stuck on the OLD
                                // plain negative=red/positive=green rule
                                // after this feedback, since only Net
                                // Income had been switched over).
                                $inputColor = ($col['money'] ?? false)
                                    ? $niColorClass($raw[$col['key']])
                                    : 'text-ink dark:text-slate-100';
                            @endphp
                            <td class="px-2 py-1.5 {{ $borderClass }}">
                                <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                                       value="{{ ($col['money'] ?? false) ? number_format($raw[$col['key']], 2) : (($col['pct'] ?? false) ? number_format($raw[$col['key']] * 100, 2) : $raw[$col['key']]) }}"
                                       data-field="{{ $col['key'] }}" data-date="{{ $dateStr }}"
                                       @if($col['money'] ?? false) data-money="1" @endif
                                       @if($col['pct'] ?? false) data-percent="1" @endif
                                       class="tsr-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold {{ $inputColor }} focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                            </td>
                            @else
                            @php
                                $cellColor = match (true) {
                                    $col['key'] === 'net_income' => $niColorClass($d['net_income']),
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
                @empty
                <tr>
                    <td colspan="{{ 1 + count($dates) * count($dayColumns) }}" class="px-3 py-4 text-center text-ink-muted dark:text-slate-500">
                        No TSAs on this team yet — add one via TSA Management.
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
                            $dayRawRows = $gs['tsas']->map(function ($tsa) use ($dailyByKey, $dateStr, $emptyRaw) {
                                return $dailyByKey->get($tsa->id . ':' . $dateStr, $emptyRaw);
                            })->all();
                            $dayTotal = \App\Support\TsaSalesCalculator::sum($dayRawRows);
                            // AOV automated — see the per-row $d['aov']
                            // override above for the full reasoning.
                            $dayUpsellSales = array_sum(array_column($dayRawRows, 'upsell_sales'));
                            $dayUpsellConfirmation = array_sum(array_column($dayRawRows, 'upsell_confirmation'));
                            $dayTotal['aov'] = $dayUpsellConfirmation > 0 ? $dayUpsellSales / $dayUpsellConfirmation : 0.0;
                        @endphp
                        @foreach($dayColumns as $i => $col)
                        <td class="px-3 py-2.5 text-right {{ $i === $lastColIndex ? 'tsr-day-end' : '' }} {{ $col['key'] === 'net_income' ? $niColorClass($dayTotal['net_income'], true) : '' }} {{ $col['key'] === 'ni_pct' && $dayTotal['ni_pct'] < 0 ? 'text-red-400' : '' }}"
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

    // A leading '-' is kept (not stripped) — explicit request, 2026-10-03:
    // "i want to make it can input negative amount ... if negative red
    // and if positive it is green like in the dsppr page" — same
    // negative-money convention DSPPR's own liveFormatMoney() already has.
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
        if (input.dataset.money === '1') updateMoneyInputColor(input);
    }

    // Net Income's own 3-tier color rule (explicit request, 2026-10-03:
    // "lahat ng number kapag nilagay ay black tapos sa net income mag
    // green lang yung color niya kapag nakahit siya ng 4.2K and above
    // then stay mo sa negative kapag red tapos kapag 1 - 4,199 yung net
    // income stay lang siya sa black") — negative stays red, 0–4,199.99
    // is now BLACK (not green), green only starts at 4,200 itself
    // (explicit confirmation: inclusive). Mirrors the PHP-side
    // $niColorClass in tsa-sales.blade.php's own top @php block — keep
    // both in sync if this threshold ever changes.
    function applyNiColorClasses(el, netIncome) {
        const isNegative = netIncome < 0;
        const isHighPositive = netIncome >= 4200;
        el.classList.toggle('text-red-600', isNegative);
        el.classList.toggle('dark:text-red-400', isNegative);
        el.classList.toggle('text-green-600', isHighPositive);
        el.classList.toggle('dark:text-green-400', isHighPositive);
        el.classList.toggle('text-ink', !isNegative && !isHighPositive);
        el.classList.toggle('dark:text-slate-100', !isNegative && !isHighPositive);
    }

    // Live red/negative green/positive coloring on a money INPUT itself as
    // the user types (explicit request, 2026-10-03, matching DSPPR's own
    // Net Income behavior) — separate from applyDerived()'s own coloring
    // of the read-only [data-out] spans, since this targets the <input>
    // element's own text color instead. Same 3-tier rule for EVERY money
    // field (explicit follow-up, same day: "the gross sales too is
    // black" — Gross Sales was still stuck on the old plain rule here).
    function updateMoneyInputColor(input) {
        applyNiColorClasses(input, parseMoney(input.value));
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
        // tsr-day-total-row has a dark (bg-black) background — its own
        // Net Income cell uses the dark-mode-only class variant (no
        // explicit text-ink, same as every other total/footer row on this
        // page), same distinction $niColorClass's own $darkMode param
        // makes server-side.
        const isDarkRow = row.classList.contains('tsr-day-total-row');
        row.querySelectorAll(`[data-out][data-date="${date}"]`).forEach((el) => {
            const key = el.dataset.out;
            if (!(key in derived)) return;
            const isPct = ['ni_pct', 'pickup_rate', 'upselling_rate'].includes(key);
            const isInt = ['total_orders', 'catered_leads'].includes(key);
            el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
            if (key === 'ni_pct') {
                const isLoss = derived[key] < 0;
                el.classList.toggle('text-red-600', isLoss && !isDarkRow);
                el.classList.toggle('dark:text-red-400', isLoss);
                el.classList.toggle('text-red-400', isLoss && isDarkRow);
            }
            if (key === 'net_income') {
                if (isDarkRow) {
                    const isNegative = derived[key] < 0;
                    const isHighPositive = derived[key] >= 4200;
                    el.classList.toggle('text-red-400', isNegative);
                    el.classList.toggle('text-green-400', isHighPositive);
                } else {
                    applyNiColorClasses(el, derived[key]);
                }
            }
        });
    }

    function refreshDayTotal(table, date) {
        const rows = table.querySelectorAll('tbody .tsr-row');
        let totals = { gross_sales: 0, net_income: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, rowCount = 0;
        rows.forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector(`[data-field="gross_sales"][data-date="${date}"]`).value);
            totals.net_income += parseMoney(row.querySelector(`[data-field="net_income"][data-date="${date}"]`).value);
            // Total Orders/Catered Leads/Pick-up/Upselling Rate are
            // read-only [data-out] cells now, not [data-field] inputs
            // (auto-computed — see TsaSalesReportController's own doc
            // comment) — read their rendered text instead.
            totals.total_orders += parseMoney(row.querySelector(`[data-out="total_orders"][data-date="${date}"]`).textContent);
            totals.catered_leads += parseMoney(row.querySelector(`[data-out="catered_leads"][data-date="${date}"]`).textContent);
            pickupSum += parseFloat(row.querySelector(`[data-out="pickup_rate"][data-date="${date}"]`).textContent) / 100;
            upsellSum += parseFloat(row.querySelector(`[data-out="upselling_rate"][data-date="${date}"]`).textContent) / 100;
            rowCount += 1;
        });
        // No 'aov' key here — it's automated now (upsell_sales ÷
        // upsell_confirmation, not Gross Sales ÷ Total Orders — see
        // TsaSalesReportController::withAutomatedAov()'s own doc
        // comment) and never changes from a Gross Sales/Net Income
        // edit, so applyDerived()'s own `if (!(key in derived)) return`
        // guard leaves the existing [data-out="aov"] cell untouched
        // instead of recomputing it from stale client-side totals.
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            pickup_rate: rowCount > 0 ? pickupSum / rowCount : 0,
            upselling_rate: rowCount > 0 ? upsellSum / rowCount : 0,
        };
        const totalRow = table.querySelector('.tsr-day-total-row');
        if (totalRow) applyDerived(totalRow, date, derived);
    }

    // Recomputes ONE TSA's summary (top MTD table), then that TSA's own
    // group total, then the OVERALL TOTAL — summed across EVERY date in
    // EVERY 7-day chunk for that TSA.
    function refreshSummaryRow(tsaId) {
        const summaryTable = document.getElementById('tsrSummaryTable');
        if (!summaryTable) return;

        let totals = { gross_sales: 0, net_income: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, dayCount = 0;

        document.querySelectorAll(`.tsr-days-table .tsr-row[data-tsa-id="${tsaId}"]`).forEach((row) => {
            row.querySelectorAll('[data-field="gross_sales"]').forEach((el) => {
                const date = el.dataset.date;
                totals.gross_sales += parseMoney(el.value);
                totals.net_income += parseMoney(row.querySelector(`[data-field="net_income"][data-date="${date}"]`).value);
                totals.total_orders += parseMoney(row.querySelector(`[data-out="total_orders"][data-date="${date}"]`).textContent);
                totals.catered_leads += parseMoney(row.querySelector(`[data-out="catered_leads"][data-date="${date}"]`).textContent);
                pickupSum += parseFloat(row.querySelector(`[data-out="pickup_rate"][data-date="${date}"]`).textContent) / 100;
                upsellSum += parseFloat(row.querySelector(`[data-out="upselling_rate"][data-date="${date}"]`).textContent) / 100;
                dayCount += 1;
            });
        });

        // No 'aov' key — automated now, untouched by a Gross Sales/Net
        // Income edit; see refreshDayTotal()'s own comment above.
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
            pickup_rate: dayCount > 0 ? pickupSum / dayCount : 0,
            upselling_rate: dayCount > 0 ? upsellSum / dayCount : 0,
        };

        const summaryRow = summaryTable.querySelector(`.tsr-summary-row[data-tsa-id="${tsaId}"]`);
        if (summaryRow) {
            summaryRow.querySelectorAll('[data-out]').forEach((el) => {
                const key = el.dataset.out;
                if (!(key in derived)) return;
                const isPct = ['ni_pct', 'pickup_rate', 'upselling_rate'].includes(key);
                const isInt = ['total_orders', 'catered_leads'].includes(key);
                el.textContent = isPct ? fmtPct(derived[key]) : (isInt ? fmtInt(derived[key]) : fmtMoney(derived[key]));
                if (key === 'ni_pct') {
                    const isLoss = derived[key] < 0;
                    el.classList.toggle('text-red-600', isLoss);
                    el.classList.toggle('dark:text-red-400', isLoss);
                }
                if (key === 'net_income') applyNiColorClasses(el, derived[key]);
            });

            const groupTotalRow = summaryRow.closest('tbody').querySelector('.tsr-group-total-row');
            refreshGroupTotal(groupTotalRow);
        }

        refreshOverallTotal(summaryTable);
    }

    function refreshGroupTotal(groupTotalRow) {
        if (!groupTotalRow) return;
        const tbody = groupTotalRow.closest('tbody');
        let totals = { gross_sales: 0, net_income: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, rowCount = 0;

        tbody.querySelectorAll('.tsr-summary-row').forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector('[data-out="gross_sales"]').textContent);
            totals.net_income += parseMoney(row.querySelector('[data-out="net_income"]').textContent);
            totals.total_orders += parseMoney(row.querySelector('[data-out="total_orders"]').textContent);
            totals.catered_leads += parseMoney(row.querySelector('[data-out="catered_leads"]').textContent);
            pickupSum += parseFloat(row.querySelector('[data-out="pickup_rate"]').textContent) / 100;
            upsellSum += parseFloat(row.querySelector('[data-out="upselling_rate"]').textContent) / 100;
            rowCount += 1;
        });

        // No 'aov' key — automated now; see refreshDayTotal()'s own
        // comment above.
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
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
            if (key === 'net_income') {
                el.classList.toggle('text-red-400', derived[key] < 0);
                el.classList.toggle('text-green-400', derived[key] >= 4200);
            }
        });
    }

    function refreshOverallTotal(summaryTable) {
        let totals = { gross_sales: 0, net_income: 0, total_orders: 0, catered_leads: 0 };
        let pickupSum = 0, upsellSum = 0, rowCount = 0;

        summaryTable.querySelectorAll('tbody .tsr-summary-row').forEach((row) => {
            totals.gross_sales += parseMoney(row.querySelector('[data-out="gross_sales"]').textContent);
            totals.net_income += parseMoney(row.querySelector('[data-out="net_income"]').textContent);
            totals.total_orders += parseMoney(row.querySelector('[data-out="total_orders"]').textContent);
            totals.catered_leads += parseMoney(row.querySelector('[data-out="catered_leads"]').textContent);
            pickupSum += parseFloat(row.querySelector('[data-out="pickup_rate"]').textContent) / 100;
            upsellSum += parseFloat(row.querySelector('[data-out="upselling_rate"]').textContent) / 100;
            rowCount += 1;
        });

        // No 'aov' key — automated now; see refreshDayTotal()'s own
        // comment above.
        const derived = {
            gross_sales: totals.gross_sales, net_income: totals.net_income,
            total_orders: totals.total_orders, catered_leads: totals.catered_leads,
            ni_pct: totals.gross_sales > 0 ? totals.net_income / totals.gross_sales : 0,
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
            if (key === 'net_income') {
                el.classList.toggle('text-red-400', derived[key] < 0);
                el.classList.toggle('text-green-400', derived[key] >= 4200);
            }
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

        const url = urlTemplate.replace('__TSA__', row.dataset.tsaId).replace('__DATE__', date);
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
                if (input.dataset.money === '1') updateMoneyInputColor(input);
                if (data?.derived) applyDerived(row, date, data.derived);
                refreshDayTotal(table, date);
                refreshSummaryRow(row.dataset.tsaId);
            })
            .catch(() => {
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    document.querySelectorAll('.tsr-days-table').forEach((table) => {
        table.addEventListener('input', (e) => {
            const field = e.target.closest('.tsr-field');
            if (!field) return;
            if (field.dataset.money === '1' || field.dataset.percent === '1') liveFormatMoney(field);
            clearTimeout(saveTimers.get(field));
            saveTimers.set(field, setTimeout(() => saveField(field), 600));
        });

        table.addEventListener('blur', (e) => {
            const field = e.target.closest('.tsr-field');
            if (field) saveField(field);
        }, true);

        table.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const input = e.target.closest('.tsr-field');
            if (input) { e.preventDefault(); input.blur(); }
        });
    });

    document.querySelectorAll('.tsr-scroller').forEach((scroller) => {
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
