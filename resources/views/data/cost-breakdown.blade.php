@extends('layouts.data')
@section('title', 'Cost Breakdown')
@section('subtitle', 'Payroll and shared cost allocation — Days and % are the only editable table cells')

@section('content')

<style>
    .cb-table { font-variant-numeric: tabular-nums; }
    .cb-sticky { position: sticky; left: 0; z-index: 5; background-color: #fff; }
    .dark .cb-sticky { background-color: #0f172a; }
    thead .cb-sticky { z-index: 25; }
    tr:hover > .cb-sticky { background-color: #f8fafc; }
    .dark tr:hover > .cb-sticky { background-color: #1e293b; }
</style>

@php
    $fmtMoney = fn ($n) => number_format((float) $n, 2);
    $fmtPct   = fn ($n) => number_format(((float) $n) * 100, 2) . '%';
@endphp

<div class="space-y-8">

    {{-- Salary breakdown — every field manual (explicit request,
         2026-09-29: no formula derives a CEO/Sales Director/Manager's real
         salary anywhere else in this app). No separate "bonus" field
         (explicit follow-up, 2026-09-29: "there's no bonus on the sheets
         so it should no bonus in that") — each row's own Base Salary is
         her real full monthly figure. The 10,341.13 (CEO/Sales
         Director/Telesales Manager) and 4,833.33 (QA Specialist/Junior AI
         Engineer) figures render as a real HTML rowspan, exactly like the
         sheet's own merged cell — a shared reference number, never added
         into anyone's own Base Salary or any total.

         Each shift's own Supervisor is immediately followed by her own
         team's real TSAs, ONE continuous table (explicit follow-up,
         2026-09-29: "the supervisor of opening and closing is in the rows
         of their TSA's") — $salaryRows is this exact interleaved sequence,
         built once in the controller. Modern table styling (sticky
         header, zebra rows, tabular numerals) rather than the real
         sheet's own dense spreadsheet grid, per "use tailwind css
         components for the modern design." --}}
    <div class="rounded-2xl border border-line dark:border-slate-700 bg-white dark:bg-slate-900 shadow-panel overflow-hidden">
        <div class="px-6 py-4 bg-slate-900 dark:bg-slate-800 flex items-center justify-between">
            <h2 class="font-mono font-bold text-sm uppercase tracking-wide text-white">Telesales Dept — Salary Breakdown</h2>
            <span id="cbRoleSaveStatus" class="text-xs font-mono text-slate-400 min-h-[1.25rem]"></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[13px] cb-table border-separate border-spacing-0">
                <thead>
                    <tr class="bg-yellow-100 dark:bg-yellow-800 text-ink dark:text-slate-950">
                        <th class="text-left px-4 py-2.5 font-bold whitespace-nowrap">Role / TSA</th>
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap">Base Salary</th>
                        <th class="text-center px-4 py-2.5 font-bold whitespace-nowrap bg-slate-200 dark:bg-slate-600 border-x border-line dark:border-slate-700">Shared Ref.</th>
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap">Total</th>
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap">Daily Rate (÷24)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($salaryRows as $item)
                    {{-- No per-row divider line (explicit follow-up,
                         2026-09-29: "no row line ... make it look clean
                         like there's a group") — instead a visible
                         slate-toned gap block under the LAST row of each
                         cluster reads as a gap between groups (a plain
                         white border was invisible against this table's
                         own white rows — explicit follow-up, 2026-09-30:
                         "it is like it is still combine all"), same visual
                         effect as the sheet's own blank spacer rows. --}}
                    @php $groupEndClass = $item['group_end'] ? 'border-b-8 border-slate-100 dark:border-slate-800' : ''; @endphp
                    @if($item['type'] === 'role')
                        @php $role = $item['role']; @endphp
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60" data-role-row data-role-id="{{ $role->id }}" data-action="{{ route('data.cost-breakdown.update-role', $role) }}">
                            <td class="px-4 py-2 whitespace-nowrap {{ $groupEndClass }}">
                                <p class="font-mono font-bold text-ink dark:text-slate-100">{{ $role->label }}</p>
                                @if($role->person_name)
                                    <p class="font-mono text-xs text-ink-muted dark:text-slate-400">{{ $role->person_name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-1.5 text-right {{ $groupEndClass }}">
                                <input type="text" inputmode="decimal" value="{{ $fmtMoney($role->base_salary) }}"
                                       data-field="base_salary" data-money="1"
                                       class="cb-field w-32 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-2 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                            </td>
                            @if($item['rowspan'] > 0)
                            {{-- Real rowspan, matching the sheet's own
                                 merged cell exactly — this group's own
                                 combined base_salary ÷ TSA headcount (a
                                 LIVE FORMULA in the real sheet, confirmed
                                 2026-09-29 — see CostBreakdownCalculator::
                                 overheadPerTsa()'s own doc comment), purely
                                 informational, never editable, never added
                                 into any total. A thin side border + centered
                                 text (explicit follow-up, 2026-09-30: "atleast
                                 thin line and make it centers in the row")
                                 marks it as its own distinct merged region,
                                 same as the real sheet's own cell borders. --}}
                            @php
                                $overheadTitle = 'Combined salary ÷ ' . \App\Models\CostBreakdownRole::OVERHEAD_DIVISOR_COUNTS[$role->overhead_divisor] . ' TSAs (matches the sheet) — reference only, not added to any total';
                            @endphp
                            <td rowspan="{{ $item['rowspan'] }}" data-overhead-anchor-role-id="{{ $role->id }}" class="px-4 py-2 text-center font-mono font-bold text-ink-muted dark:text-slate-400 bg-slate-100 dark:bg-slate-800/60 border-x border-b-8 border-line dark:border-slate-700 border-b-white dark:border-b-slate-900 align-middle" title="{{ $overheadTitle }}">
                                {{ $fmtMoney($item['overhead']) }}
                            </td>
                            @elseif(!$item['covered_by_rowspan'])
                            <td class="px-4 py-2 bg-slate-100 dark:bg-slate-800/60 border-x border-line dark:border-slate-700 {{ $groupEndClass }}"></td>
                            @endif
                            <td class="px-4 py-2 {{ $groupEndClass }}"></td>
                            <td class="px-4 py-2 {{ $groupEndClass }}"></td>
                        </tr>
                    @else
                        @php $tsa = $item['tsa']; $entry = $item['entry']; $total = $item['total']; $dailyRate = $total / 24; @endphp
                        <tr class="odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60"
                            data-tsa-salary-row data-action="{{ route('data.cost-breakdown.update-tsa-entry', $tsa) }}">
                            <td class="px-4 py-2 pl-8 font-semibold text-ink dark:text-slate-100 whitespace-nowrap {{ $groupEndClass }}">{{ $tsa->display_name }}</td>
                            <td class="px-4 py-1.5 text-right {{ $groupEndClass }}">
                                <input type="text" inputmode="decimal" value="{{ $fmtMoney($entry->base_salary) }}"
                                       data-field="base_salary" data-money="1"
                                       class="cb-field w-32 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-2 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                            </td>
                            <td class="px-4 py-2 bg-slate-100 dark:bg-slate-800/60 border-x border-line dark:border-slate-700 {{ $groupEndClass }}"></td>
                            {{-- Her own TOTAL — base_salary + every applicable
                                 overhead ref, a LIVE FORMULA confirmed from
                                 the user's own formula-bar screenshot,
                                 2026-09-30: "=D5+D9+D12+C13" — never typed
                                 directly, see CostBreakdownCalculator::
                                 tsaTotal()'s own doc comment. --}}
                            <td data-out="total" class="px-4 py-2 text-right font-mono font-bold text-ink dark:text-slate-100 {{ $groupEndClass }}">{{ $fmtMoney($total) }}</td>
                            <td data-out="daily_rate" class="px-4 py-2 text-right text-ink-muted dark:text-slate-400 {{ $groupEndClass }}">{{ $fmtMoney($dailyRate) }}</td>
                        </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 bg-slate-100 dark:bg-slate-800/60 flex items-center justify-between">
            <span class="font-mono font-bold text-sm text-ink dark:text-slate-100">TOTAL SALARY OF TSD</span>
            <span class="font-mono font-bold text-lg text-primary">{{ $fmtMoney($totalSalaryOfTsd) }}</span>
        </div>
    </div>

    {{-- Shared monthly cost pools — every amount manual (explicit request,
         2026-09-29), split across every TSA below by her own % share of
         total days worked. --}}
    <div class="rounded-2xl border border-line dark:border-slate-700 bg-white dark:bg-slate-900 shadow-panel overflow-hidden">
        <div class="px-6 py-4 bg-slate-900 dark:bg-slate-800 flex items-center justify-between">
            <h2 class="font-mono font-bold text-sm uppercase tracking-wide text-white">Shared Monthly Cost Pools</h2>
            <span id="cbPoolSaveStatus" class="text-xs font-mono text-slate-400 min-h-[1.25rem]"></span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-px bg-line dark:bg-slate-700">
            @foreach($pools as $pool)
            <div class="bg-white dark:bg-slate-900 px-5 py-3 flex items-center justify-between gap-3" data-pool-row data-pool-key="{{ $pool->key }}" data-action="{{ route('data.cost-breakdown.update-pool', $pool) }}">
                <span class="text-xs font-mono text-ink-muted dark:text-slate-400 truncate">{{ $pool->label }}</span>
                <input type="text" inputmode="decimal" value="{{ $fmtMoney($pool->amount) }}"
                       data-field="amount" data-money="1"
                       class="cb-field w-28 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-2 py-1 text-sm font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none font-mono">
            </div>
            @endforeach
        </div>
        <div class="px-6 py-4 bg-slate-100 dark:bg-slate-800/60 flex items-center justify-between">
            <span class="font-mono font-bold text-sm text-ink dark:text-slate-100">TOTAL SHARED COSTS</span>
            <span id="cbPoolGrandTotal" class="font-mono font-bold text-lg text-primary">{{ $fmtMoney($grandTotal) }}</span>
        </div>
    </div>

    {{-- Per-TSA cost allocation table — the ONLY editable cells here are
         Days and % (explicit request, 2026-09-29). Every other cost column
         is that pool's own amount × this TSA's own % share, computed
         fresh, never typed. --}}
    <div class="rounded-2xl border border-line dark:border-slate-700 bg-white dark:bg-slate-900 shadow-panel overflow-hidden">
        <div class="px-6 py-4 bg-slate-900 dark:bg-slate-800 flex items-center justify-between">
            <h2 class="font-mono font-bold text-sm uppercase tracking-wide text-white">Cost Allocation Per TSA</h2>
            <span id="cbTsaSaveStatus" class="text-xs font-mono text-slate-400 min-h-[1.25rem]"></span>
        </div>
        <div class="overflow-x-auto" id="cbTsaScroller">
            <table class="cb-table border-collapse text-[13px]" id="cbTsaTable"
                   data-update-url-template="{{ route('data.cost-breakdown.update-tsa-entry', ['tsaShift' => '__TSA__']) }}">
                <thead>
                    <tr class="bg-yellow-200 dark:bg-yellow-700 text-ink dark:text-slate-950">
                        <th class="cb-sticky text-left px-4 py-2.5 font-bold whitespace-nowrap">COST</th>
                        <th class="text-right px-3 py-2.5 font-bold whitespace-nowrap">Days</th>
                        <th class="text-right px-3 py-2.5 font-bold whitespace-nowrap bg-slate-300 dark:bg-slate-600">%</th>
                        @foreach($pools as $pool)
                        <th class="text-right px-3 py-2.5 font-bold whitespace-nowrap {{ in_array($pool->key, ['business_development_fund','geniusmakers_management_fee','hmo_expense'], true) ? 'bg-rose-200 dark:bg-rose-800' : '' }}">{{ $pool->label }}</th>
                        @endforeach
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap bg-black text-white">TOTAL</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line dark:divide-slate-700">
                    @foreach($tsaRows as $row)
                    @php $tsa = $row['tsa']; $entry = $row['entry']; $d = $row['derived']; @endphp
                    <tr class="odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60"
                        data-tsa-cost-row data-tsa-id="{{ $tsa->id }}">
                        <td class="cb-sticky px-4 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ $tsa->display_name }}</td>
                        <td class="px-3 py-1.5 text-right">
                            <input type="text" inputmode="numeric" value="{{ $entry->days }}"
                                   data-field="days"
                                   class="cb-field cb-days-field w-16 text-right bg-slate-50 dark:bg-slate-800 border border-line dark:border-slate-700 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                        </td>
                        <td data-out="share_pct" class="px-3 py-2 text-right font-semibold text-ink dark:text-slate-100 bg-slate-50 dark:bg-slate-800/60">{{ $fmtPct($row['share']) }}</td>
                        @foreach($pools as $pool)
                        <td data-out="pool" data-pool-key="{{ $pool->key }}" class="px-3 py-2 text-right text-ink dark:text-slate-100">{{ $fmtMoney($d[$pool->key]) }}</td>
                        @endforeach
                        <td data-out="row_total" class="px-4 py-2 text-right font-bold bg-black text-white">{{ $fmtMoney($d['total']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-black text-white font-bold">
                        <td class="cb-sticky px-4 py-2.5" style="background-color:#000;">TOTAL</td>
                        <td id="cbDaysTotal" class="px-3 py-2.5 text-right">{{ $totalDays }}</td>
                        <td class="px-3 py-2.5 text-right">100%</td>
                        @foreach($pools as $pool)
                        <td data-foot-pool-key="{{ $pool->key }}" class="px-3 py-2.5 text-right">{{ $fmtMoney($pool->amount) }}</td>
                        @endforeach
                        <td id="cbRowGrandTotal" class="px-4 py-2.5 text-right">{{ $fmtMoney($rowGrandTotal) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const saveTimers = new WeakMap();

    function fmtMoney(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtPct(n) { return (Number(n) * 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%'; }
    function parseMoney(str) { return Number(String(str).replace(/,/g, '')) || 0; }

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

    function flashStatus(el, text, isError) {
        if (!el) return;
        el.textContent = text;
        el.classList.toggle('text-red-500', !!isError);
        el.classList.toggle('text-slate-400', !isError);
        clearTimeout(el._t);
        el._t = setTimeout(() => { if (el.textContent === text) el.textContent = ''; }, 2000);
    }

    // Applies a fresh { tsaId: { days, share, derived } } payload (the
    // controller's own recomputeAllTsaRows() shape) to every row in the
    // bottom cost-allocation table AND the top salary table's own daily
    // rate, since a shared pool OR Days change recomputes every TSA at
    // once, not just the one just-edited cell's own row.
    function applyRecomputed(recomputed) {
        Object.entries(recomputed).forEach(([tsaId, data]) => {
            const costRow = document.querySelector(`[data-tsa-cost-row][data-tsa-id="${tsaId}"]`);
            if (costRow) {
                const shareEl = costRow.querySelector('[data-out="share_pct"]');
                if (shareEl) shareEl.textContent = fmtPct(data.share);
                Object.entries(data.derived).forEach(([key, value]) => {
                    if (key === 'total') {
                        const totalEl = costRow.querySelector('[data-out="row_total"]');
                        if (totalEl) totalEl.textContent = fmtMoney(value);
                        return;
                    }
                    const cell = costRow.querySelector(`[data-out="pool"][data-pool-key="${key}"]`);
                    if (cell) cell.textContent = fmtMoney(value);
                });
                const daysInput = costRow.querySelector('[data-field="days"]');
                if (daysInput && document.activeElement !== daysInput) daysInput.value = data.days;
            }
        });
        refreshCostTableTotals();
    }

    // Applies a fresh { roleId: overheadValue|null } payload (the
    // controller's own recomputeAllRoleOverhead() shape) — editing one
    // role's own base_salary can change every OTHER role sharing the same
    // overhead_group's own displayed figure too, since they all show the
    // SAME summed-then-divided number (the real sheet's own merged cell).
    // null entries (a role with no overhead_group at all) are skipped —
    // there's no anchor cell to update for them.
    function applyRecomputedOverhead(recomputedOverhead) {
        Object.entries(recomputedOverhead).forEach(([roleId, value]) => {
            if (value === null) return;
            const cell = document.querySelector(`[data-overhead-anchor-role-id="${roleId}"]`);
            if (cell) cell.textContent = fmtMoney(value);
        });
    }

    // Recomputes the bottom table's own TOTAL row (Days sum + row-total
    // sum) purely from what's currently in the DOM — same "read live
    // values, don't wait for a reload" convention as DSPPR/Expected
    // Income's own refreshDayTotal()/refreshOverallTotal().
    function refreshCostTableTotals() {
        let daysTotal = 0, rowGrandTotal = 0;
        document.querySelectorAll('[data-tsa-cost-row]').forEach((row) => {
            daysTotal += Number(row.querySelector('[data-field="days"]').value) || 0;
            rowGrandTotal += parseMoney(row.querySelector('[data-out="row_total"]').textContent);
        });
        const daysTotalEl = document.getElementById('cbDaysTotal');
        const rowGrandTotalEl = document.getElementById('cbRowGrandTotal');
        if (daysTotalEl) daysTotalEl.textContent = daysTotal;
        if (rowGrandTotalEl) rowGrandTotalEl.textContent = fmtMoney(rowGrandTotal);
    }

    function refreshPoolGrandTotal() {
        let total = 0;
        document.querySelectorAll('[data-pool-row] [data-field="amount"]').forEach((el) => { total += parseMoney(el.value); });
        const el = document.getElementById('cbPoolGrandTotal');
        if (el) el.textContent = fmtMoney(total);
        // Every pool's own TOTAL-row cell in the cost-allocation table's
        // tfoot mirrors the same just-edited flat amount (that row always
        // shows the pool's own total, not a per-TSA sum).
        document.querySelectorAll('[data-pool-row]').forEach((row) => {
            const key = row.dataset.poolKey;
            const value = parseMoney(row.querySelector('[data-field="amount"]').value);
            const footCell = document.querySelector(`[data-foot-pool-key="${key}"]`);
            if (footCell) footCell.textContent = fmtMoney(value);
        });
    }

    function saveGenericField(input, statusId) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const row = input.closest('[data-action]');
        if (!row) return;
        const status = document.getElementById(statusId);
        const field = input.dataset.field;
        const value = input.dataset.money === '1' ? parseMoney(input.value) : (input.value === '' ? null : (Number(input.value) || 0));

        flashStatus(status, 'Saving…', false);

        const body = new URLSearchParams();
        body.set('_method', 'PATCH');
        if (value === null) {
            body.set(field, '');
        } else {
            body.set(field, value);
        }

        fetch(row.dataset.action, {
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
                flashStatus(status, 'Saved', false);
                if (input.dataset.money === '1' && document.activeElement !== input && value !== null) {
                    input.value = fmtMoney(value);
                }

                // A role's own Base Salary has no derived total cell of its
                // own to refresh beyond the input itself, but her own (or a
                // groupmate's own) overhead-per-TSA figure may have just
                // changed, see applyRecomputedOverhead() below.
                if (data.recomputedOverhead) applyRecomputedOverhead(data.recomputedOverhead);

                // A TSA's own Total is a LIVE FORMULA (base_salary + her
                // applicable overhead refs, confirmed from the user's own
                // formula-bar screenshot, 2026-09-30) — refresh both her own
                // Total and Daily Rate cells from the server's freshly-
                // computed figures rather than re-deriving them client-side.
                if (row.hasAttribute('data-tsa-salary-row')) {
                    if (typeof data.total === 'number') {
                        const totalEl = row.querySelector('[data-out="total"]');
                        if (totalEl) totalEl.textContent = fmtMoney(data.total);
                    }
                    if (typeof data.dailyRate === 'number') {
                        const el = row.querySelector('[data-out="daily_rate"]');
                        if (el) el.textContent = fmtMoney(data.dailyRate);
                    }
                }

                if (data.recomputed) applyRecomputed(data.recomputed);
                if (row.hasAttribute('data-pool-row')) refreshPoolGrandTotal();
            })
            .catch(() => {
                flashStatus(status, 'Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    function saveTsaCostField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const table = input.closest('#cbTsaTable');
        const row = input.closest('[data-tsa-cost-row]');
        const status = document.getElementById('cbTsaSaveStatus');
        const field = input.dataset.field;
        const value = Number(input.value) || 0;

        flashStatus(status, 'Saving…', false);

        const url = table.dataset.updateUrlTemplate.replace('__TSA__', row.dataset.tsaId);
        const body = new URLSearchParams();
        body.set('_method', 'PATCH');
        body.set(field, value);

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
                flashStatus(status, 'Saved', false);
                if (data.recomputed) applyRecomputed(data.recomputed);
            })
            .catch(() => {
                flashStatus(status, 'Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    document.addEventListener('input', (e) => {
        const input = e.target.closest('.cb-field');
        if (!input) return;
        if (input.dataset.money === '1') liveFormatMoney(input);

        clearTimeout(saveTimers.get(input));
        const isTsaCostDays = input.closest('[data-tsa-cost-row]');
        saveTimers.set(input, setTimeout(() => {
            if (isTsaCostDays) {
                saveTsaCostField(input);
            } else if (input.closest('[data-role-row]')) {
                saveGenericField(input, 'cbRoleSaveStatus');
            } else if (input.closest('[data-tsa-salary-row]')) {
                saveGenericField(input, 'cbRoleSaveStatus');
            } else if (input.closest('[data-pool-row]')) {
                saveGenericField(input, 'cbPoolSaveStatus');
            }
        }, 600));
    });

    document.addEventListener('blur', (e) => {
        const input = e.target.closest('.cb-field');
        if (!input) return;
        clearTimeout(saveTimers.get(input));
        if (input.closest('[data-tsa-cost-row]')) {
            saveTsaCostField(input);
        } else if (input.closest('[data-role-row]')) {
            saveGenericField(input, 'cbRoleSaveStatus');
        } else if (input.closest('[data-tsa-salary-row]')) {
            saveGenericField(input, 'cbRoleSaveStatus');
        } else if (input.closest('[data-pool-row]')) {
            saveGenericField(input, 'cbPoolSaveStatus');
        }
    }, true);
})();
</script>
@endpush
