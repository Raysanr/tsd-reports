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
        <div class="px-6 py-4 bg-slate-900 dark:bg-slate-800 flex items-center justify-between gap-3">
            <h2 class="font-mono font-bold text-sm uppercase tracking-wide text-white">Telesales Dept — Salary Breakdown</h2>
            <div class="flex items-center gap-3">
                <span id="cbRoleSaveStatus" class="text-xs font-mono text-slate-400 min-h-[1.25rem]"></span>
                {{-- + Add Role (explicit request, 2026-10-03: "i want you to
                     add role") — opens #cbAddRoleModal below. --}}
                <button type="button" id="cbAddRoleBtn" title="Add a role"
                        class="shrink-0 w-6 h-6 inline-flex items-center justify-center rounded-full text-sm leading-none font-bold text-slate-300 border border-slate-600 hover:text-white hover:border-white">
                    +
                </button>
            </div>
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
                        {{-- Explicit request, 2026-09-30: "add anothet
                             column next to Daily Rate (÷24) is like divided
                             be all product ... how many product in the
                             cards" — her own Daily Rate split evenly across
                             every FLAGGED product only (explicit follow-up,
                             2026-09-30: "user only can identify what
                             product that has cost" — see TsaDailyRateService
                             ::productCount()'s own doc comment). --}}
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap">Daily Rate / Product</th>
                        {{-- Explicit request, 2026-10-02, confirmed against
                             2 real sheet screenshots (Opening/Closing shift
                             tabs) — sourced from Projections' own
                             "Telesales Department" Tax Allocation line, NOT
                             a fresh manual figure here. See
                             CostBreakdownController::taxFigures()'s own doc
                             comment for the confirmed split formula
                             (department total ÷ 2 shifts ÷ 6 real TSAs). --}}
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap">Monthly Tax</th>
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap">Daily Tax</th>
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
                            {{-- Role/name editable (explicit request,
                                 2026-10-03: "i want to make it roles is
                                 editable like role and name") — same
                                 debounced-autosave input convention as
                                 every other cb-field on this page.
                                 CostBreakdownRole::ensureSeeded() no longer
                                 re-syncs either field on an existing row
                                 (see its own doc comment), so an edit here
                                 survives every later page load. A custom
                                 (admin-added, seed_key = null) role also
                                 gets a × to remove it entirely — none of
                                 the 7 fixed roles ever show one, server-
                                 guarded too (CostBreakdownController::
                                 destroyRole()). --}}
                            <td class="px-4 py-2 whitespace-nowrap {{ $groupEndClass }}">
                                <div class="flex items-start gap-1">
                                    <div class="flex-1 min-w-0 space-y-1">
                                        <input type="text" value="{{ $role->label }}" data-field="label" data-text="1"
                                               class="cb-field w-full bg-transparent border-none focus:ring-2 focus:ring-primary/40 focus:bg-slate-50 dark:focus:bg-slate-800 rounded-md px-1.5 py-0.5 -mx-1.5 font-mono font-bold text-ink dark:text-slate-100 outline-none">
                                        <input type="text" value="{{ $role->person_name }}" data-field="person_name" data-text="1" placeholder="Name (optional)"
                                               class="cb-field w-full bg-transparent border-none focus:ring-2 focus:ring-primary/40 focus:bg-slate-50 dark:focus:bg-slate-800 rounded-md px-1.5 py-0.5 -mx-1.5 font-mono text-xs text-ink-muted dark:text-slate-400 outline-none">
                                    </div>
                                    @if($role->seed_key === null)
                                    <button type="button" data-remove-role="{{ $role->id }}" title="Remove this role"
                                            class="cb-remove-role shrink-0 mt-0.5 w-3.5 h-3.5 inline-flex items-center justify-center rounded-full text-[10px] leading-none text-ink-muted/60 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40 dark:hover:text-red-400">
                                        &times;
                                    </button>
                                    @endif
                                </div>
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
                            <td class="px-4 py-2 {{ $groupEndClass }}"></td>
                            {{-- Monthly Tax / Daily Tax — only the 2
                                 Telesales Supervisor rows (overhead_divisor
                                 'team', same marker that already singles
                                 them out from the other 5 role rows) show
                                 the raw per-shift figure; every other role
                                 (CEO, Sales Director, ...) stays blank, same
                                 as Total/Daily Rate already are for them. --}}
                            @if($role->overhead_divisor === 'team')
                            <td class="px-4 py-2 text-right text-ink-muted dark:text-slate-400 {{ $groupEndClass }}">{{ $fmtMoney($monthlyTaxPerShift) }}</td>
                            <td class="px-4 py-2 text-right text-ink-muted dark:text-slate-400 {{ $groupEndClass }}">{{ $fmtMoney($monthlyTaxPerShift / 24) }}</td>
                            @else
                            <td class="px-4 py-2 {{ $groupEndClass }}"></td>
                            <td class="px-4 py-2 {{ $groupEndClass }}"></td>
                            @endif
                        </tr>
                    @else
                        @php
                            $tsa = $item['tsa']; $entry = $item['entry']; $total = $item['total'];
                            $dailyRate = \App\Support\CostBreakdownCalculator::tsaDailyRate($total);
                            // Daily Rate / Product = $dailyRate above split
                            // across every CHECKED product (explicit
                            // follow-up, 2026-09-30: "user only can
                            // identify what product that has cost") — see
                            // TsaDailyRateService's own doc comment.
                            $dailyRatePerProduct = $dailyRatePerProductByTsaId[$tsa->id] ?? 0.0;
                        @endphp
                        <tr class="odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60"
                            data-tsa-salary-row data-tsa-id="{{ $tsa->id }}" data-action="{{ route('data.cost-breakdown.update-tsa-entry', $tsa) }}">
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
                            <td data-out="daily_rate_per_product" class="px-4 py-2 text-right text-ink-muted dark:text-slate-400 {{ $groupEndClass }}">{{ $fmtMoney($dailyRatePerProduct) }}</td>
                            {{-- Every real TSA row shows the per-shift
                                 Monthly Tax ÷ HER OWN TEAM'S real TSA count
                                 (confirmed DIRECTLY against the sheet's own
                                 formula bar, 2026-10-02: "50,000 is divided
                                 by 6 (6 tsa per team) so when they add new
                                 tsa it will be 7" — dynamic per team, not a
                                 fixed sheet headcount and not the
                                 company-wide total). --}}
                            @php $monthlyTaxPerTsa = $monthlyTaxPerTsaByTeam[$tsa->team] ?? 0.0; @endphp
                            <td class="px-4 py-2 text-right text-ink-muted dark:text-slate-400 {{ $groupEndClass }}">{{ $fmtMoney($monthlyTaxPerTsa) }}</td>
                            <td class="px-4 py-2 text-right text-ink-muted dark:text-slate-400 {{ $groupEndClass }}">{{ $fmtMoney($monthlyTaxPerTsa / 24) }}</td>
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

    {{-- Add Role modal (explicit request, 2026-10-03: "i want you to add
         role", "full featured — can also join a shared overhead group") —
         #cbAddRoleBtn above opens this; starts hidden, JS toggles [hidden].
         Group is picked from every DISTINCT overhead_group already on the
         page (new role joins it, its own base_salary merges into that
         group's existing Shared Ref. figure) or left on "No group" for a
         plain standalone row — same two-state choice Projections' own Add
         Row modal gives for Editable/Fixed. team (TSA-nesting) is
         deliberately NOT offered here — out of scope, see
         CostBreakdownController::storeRole()'s own doc comment. --}}
    @php
        $existingOverheadGroups = $roles->pluck('overhead_group')->filter()->unique()->values();
    @endphp
    <div id="cbAddRoleModal" hidden class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" data-close-add-role-modal></div>
        <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-sm p-6 font-mono">
            <h3 class="text-sm font-bold uppercase tracking-wide text-ink dark:text-slate-100 mb-4">Add Role</h3>
            <form id="cbAddRoleForm" class="space-y-4">
                <div>
                    <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Role</label>
                    <input type="text" name="label" required maxlength="255" placeholder="e.g. Marketing Lead"
                           class="w-full text-sm border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Name (optional)</label>
                    <input type="text" name="person_name" maxlength="255"
                           class="w-full text-sm border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Base Salary</label>
                    <input type="text" inputmode="decimal" name="base_salary" placeholder="0.00"
                           class="w-full text-sm text-right border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Shared Ref. Group</label>
                    <select name="overhead_group" id="cbAddRoleGroup"
                            class="w-full text-sm border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <option value="">No group (standalone)</option>
                        @foreach($existingOverheadGroups as $group)
                        <option value="{{ $group }}">{{ ucwords(str_replace('_', ' ', $group)) }}</option>
                        @endforeach
                        <option value="__new__">+ New group…</option>
                    </select>
                    <input type="text" name="new_overhead_group" id="cbAddRoleNewGroup" hidden placeholder="New group name"
                           class="w-full text-sm border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40 mt-2">
                    <div id="cbAddRoleDivisorWrap" hidden class="mt-2">
                        <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Divide By</label>
                        <div class="flex gap-4 text-sm text-ink dark:text-slate-100">
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" name="overhead_divisor" value="total" checked class="text-primary focus:ring-primary/40">
                                Company-wide (÷12)
                            </label>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" name="overhead_divisor" value="team" class="text-primary focus:ring-primary/40">
                                Per team (÷6)
                            </label>
                        </div>
                    </div>
                </div>
                <p id="cbAddRoleError" class="text-xs text-red-600 dark:text-red-400 hidden"></p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" data-close-add-role-modal class="text-sm px-4 py-2 rounded-lg border border-line dark:border-slate-600 text-ink dark:text-slate-100">Cancel</button>
                    <button type="submit" class="text-sm px-4 py-2 rounded-lg bg-primary text-white font-semibold">Add Role</button>
                </div>
            </form>
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
                    {{-- "Daily Cost per product" / "Daily Cost" mini-table
                         (explicit request, 2026-09-30, real sheet
                         screenshot) — NOT scoped to any specific TSA, each
                         pool's own amount ÷ the app's own REAL TSA count
                         (explicit correction, 2026-09-30: "the 12 is number
                         of the tsa") ÷ 24, then that split across every
                         FLAGGED product — see CostBreakdownCalculator::
                         dailyCostRow()'s own doc comment for the
                         confirmed-exact formula. Same column grid as the
                         per-TSA rows below (COST/Days/% cells blank, no
                         product columns — this row predates the products
                         feature on the real sheet, so it never had any). --}}
                    <tr class="bg-slate-100 dark:bg-slate-800 text-ink-muted dark:text-slate-400 text-xs">
                        <td class="cb-sticky px-4 py-1.5 font-semibold whitespace-nowrap bg-slate-100 dark:bg-slate-800">Daily Cost per product</td>
                        <td class="px-3 py-1.5"></td>
                        <td class="px-3 py-1.5 bg-slate-200 dark:bg-slate-700"></td>
                        @foreach($pools as $pool)
                        <td class="px-3 py-1.5 text-right">{{ $fmtMoney($dailyCostPerProductRow[$pool->key] ?? 0) }}</td>
                        @endforeach
                        <td class="px-4 py-1.5 text-right font-semibold bg-slate-800 dark:bg-slate-950 text-white">{{ $fmtMoney($dailyCostPerProductRow['total'] ?? 0) }}</td>
                        @foreach($productRows as $product)
                        <td class="px-3 py-1.5"></td>
                        @endforeach
                    </tr>
                    <tr class="bg-slate-100 dark:bg-slate-800 text-ink-muted dark:text-slate-400 text-xs border-b border-line dark:border-slate-700">
                        <td class="cb-sticky px-4 py-1.5 font-semibold whitespace-nowrap bg-slate-100 dark:bg-slate-800">Daily Cost</td>
                        <td class="px-3 py-1.5"></td>
                        <td class="px-3 py-1.5 bg-slate-200 dark:bg-slate-700"></td>
                        @foreach($pools as $pool)
                        <td class="px-3 py-1.5 text-right">{{ $fmtMoney($dailyCostRow[$pool->key] ?? 0) }}</td>
                        @endforeach
                        <td class="px-4 py-1.5 text-right font-semibold bg-slate-800 dark:bg-slate-950 text-white">{{ $fmtMoney($dailyCostRow['total']) }}</td>
                        @foreach($productRows as $product)
                        <td class="px-3 py-1.5"></td>
                        @endforeach
                    </tr>
                    <tr class="bg-yellow-200 dark:bg-yellow-700 text-ink dark:text-slate-950">
                        <th class="cb-sticky text-left px-4 py-2.5 font-bold whitespace-nowrap">COST</th>
                        <th class="text-right px-3 py-2.5 font-bold whitespace-nowrap">Days</th>
                        <th class="text-right px-3 py-2.5 font-bold whitespace-nowrap bg-slate-300 dark:bg-slate-600">%</th>
                        @foreach($pools as $pool)
                        <th class="text-right px-3 py-2.5 font-bold whitespace-nowrap {{ in_array($pool->key, ['business_development_fund','geniusmakers_management_fee','hmo_expense'], true) ? 'bg-rose-200 dark:bg-rose-800' : '' }}">{{ $pool->label }}</th>
                        @endforeach
                        <th class="text-right px-4 py-2.5 font-bold whitespace-nowrap bg-black text-white">TOTAL</th>
                        {{-- Product columns (explicit request, 2026-09-30:
                             "add the products ... analyze the formula in
                             the sheets") — every real product's own card,
                             confirmed against the real sheet's own xlsx
                             formulas (AA45=Z45/7, AB45=Z45/7): her own
                             Daily Rate (row TOTAL ÷ 24) split evenly across
                             every FLAGGED product, same figure repeated in
                             each of THEIR columns, never a value unique per
                             product. A per-product checkbox in the header
                             (explicit follow-up, 2026-09-30: "user only can
                             identify what product that has cost") decides
                             whether it's in that split at all — unflagged
                             products still show their own column (so it can
                             be checked later) but with a blank body cell,
                             same "genuinely empty, not a typed 0" state as
                             the real sheet's own unfilled columns. --}}
                        @foreach($productRows as $product)
                        @php $productModel = $product['products']->first(); @endphp
                        <th class="text-right px-3 py-2.5 font-bold whitespace-nowrap bg-sky-100 dark:bg-sky-900">
                            <div class="flex items-center justify-end gap-1.5">
                                <input type="checkbox" data-product-cost-toggle data-product-id="{{ $productModel->id }}"
                                       data-action="{{ route('data.cost-breakdown.update-product-has-cost-allocation', $productModel) }}"
                                       {{ $productModel->has_cost_allocation ? 'checked' : '' }}
                                       class="w-3.5 h-3.5 rounded border-slate-400 text-primary focus:ring-primary/40 cursor-pointer">
                                <span>{{ strtoupper($product['label']) }}</span>
                            </div>
                        </th>
                        @endforeach
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
                        @foreach($productRows as $product)
                        {{-- A genuinely blank cell for an unflagged product
                             (array_key_exists, not ?? 0 — a flagged product
                             whose figure happens to compute to exactly 0.00
                             must still show "0.00", not blank) — same
                             "never a typed 0" state as the real sheet's own
                             unfilled columns. --}}
                        <td data-out="product" data-product-label="{{ $product['label'] }}" class="px-3 py-2 text-right text-ink dark:text-slate-100 bg-sky-50/60 dark:bg-sky-950/20">{{ array_key_exists($product['label'], $d['products']) ? $fmtMoney($d['products'][$product['label']]) : '' }}</td>
                        @endforeach
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
                        @foreach($productRows as $product)
                        <td class="px-3 py-2.5"></td>
                        @endforeach
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
                    // Product columns (explicit request, 2026-09-30) — same
                    // Daily Rate / Product figure repeated into every
                    // FLAGGED one, keyed by product label rather than a
                    // pool key. Every OTHER product column on the row is
                    // cleared blank first — toggling one product's own
                    // checkbox drops it out of `value` entirely (it's not
                    // omitted-but-zero, genuinely absent), so a live
                    // uncheck must blank its own cell too, not just leave
                    // the previous figure sitting there stale.
                    if (key === 'products') {
                        costRow.querySelectorAll('[data-out="product"]').forEach((cell) => { cell.textContent = ''; });
                        Object.entries(value).forEach(([label, productValue]) => {
                            const cell = costRow.querySelector(`[data-out="product"][data-product-label="${CSS.escape(label)}"]`);
                            if (cell) cell.textContent = fmtMoney(productValue);
                        });
                        return;
                    }
                    const cell = costRow.querySelector(`[data-out="pool"][data-pool-key="${key}"]`);
                    if (cell) cell.textContent = fmtMoney(value);
                });
                const daysInput = costRow.querySelector('[data-field="days"]');
                if (daysInput && document.activeElement !== daysInput) daysInput.value = data.days;
            }

            // The TOP salary table's own "Daily Rate / Product" cell for
            // this same TSA (explicit request, 2026-09-30: "i want it will
            // auto to that changed like i should not reload whole page to
            // reflect") — data.salaryDailyRatePerProduct, a SEPARATE figure
            // from data.derived.products above (that one sources the
            // BOTTOM table's own pool-share total; this one sources the
            // TOP table's own base_salary + overhead refs — see
            // TsaDailyRateService's own doc comment for why the two tables
            // are deliberately independent).
            const salaryRow = document.querySelector(`[data-tsa-salary-row][data-tsa-id="${tsaId}"]`);
            if (salaryRow) {
                const cell = salaryRow.querySelector('[data-out="daily_rate_per_product"]');
                if (cell) cell.textContent = data.salaryDailyRatePerProduct > 0 ? fmtMoney(data.salaryDailyRatePerProduct) : '';
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
        // data-text="1" (explicit request, 2026-10-03: "i want to make it
        // roles is editable like role and name") — label/person_name are
        // genuinely free text, not numeric; the Number(input.value) || 0
        // fallback below would otherwise silently coerce "CEO" or a real
        // person's name into the literal number 0 the instant this field
        // saved, since neither is a parseable number.
        const value = input.dataset.text === '1' ? input.value
            : (input.dataset.money === '1' ? parseMoney(input.value) : (input.value === '' ? null : (Number(input.value) || 0)));

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
                    if (typeof data.dailyRatePerProduct === 'number') {
                        const el = row.querySelector('[data-out="daily_rate_per_product"]');
                        if (el) el.textContent = fmtMoney(data.dailyRatePerProduct);
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

    // Per-product "has cost" checkbox (explicit request, 2026-09-30: "user
    // only can identify what product that has cost") — a plain boolean
    // toggle, saves immediately on change (no debounce needed, unlike a
    // text field), then repaints every TSA row's own product columns from
    // the server's freshly-recomputed figures (the divisor itself changed).
    document.addEventListener('change', (e) => {
        const checkbox = e.target.closest('[data-product-cost-toggle]');
        if (!checkbox) return;

        const status = document.getElementById('cbTsaSaveStatus');
        flashStatus(status, 'Saving…', false);

        fetch(checkbox.dataset.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: new URLSearchParams({ _method: 'PATCH', has_cost_allocation: checkbox.checked ? '1' : '0' }).toString(),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                flashStatus(status, 'Saved', false);
                if (data.recomputed) applyRecomputed(data.recomputed);
            })
            .catch(() => {
                checkbox.checked = !checkbox.checked;
                flashStatus(status, 'Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    });

    // Add/Remove Role (explicit request, 2026-10-03: "i want you to add
    // role" + "full featured — can also join a shared overhead group") —
    // both reload the page on success rather than patching the DOM, same
    // convention Projections' own custom-row add/remove already uses:
    // adding or removing a role changes the table's own ROW STRUCTURE
    // (a new <tr>, every group's own rowspan recalculated), not just a
    // cell's value, which applyRecomputedOverhead() above has no way to
    // express in place.
    const addRoleModal = document.getElementById('cbAddRoleModal');
    const addRoleBtn = document.getElementById('cbAddRoleBtn');
    const addRoleForm = document.getElementById('cbAddRoleForm');
    const addRoleError = document.getElementById('cbAddRoleError');
    const addRoleGroupSelect = document.getElementById('cbAddRoleGroup');
    const addRoleNewGroupInput = document.getElementById('cbAddRoleNewGroup');
    const addRoleDivisorWrap = document.getElementById('cbAddRoleDivisorWrap');

    function openAddRoleModal() {
        addRoleError.classList.add('hidden');
        addRoleForm.reset();
        addRoleNewGroupInput.hidden = true;
        addRoleDivisorWrap.hidden = true;
        addRoleModal.hidden = false;
        addRoleForm.querySelector('[name="label"]').focus();
    }
    function closeAddRoleModal() {
        addRoleModal.hidden = true;
    }

    addRoleBtn.addEventListener('click', openAddRoleModal);
    addRoleModal.querySelectorAll('[data-close-add-role-modal]').forEach((el) => {
        el.addEventListener('click', closeAddRoleModal);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !addRoleModal.hidden) closeAddRoleModal();
    });

    // Picking "+ New group…" reveals the new-group-name input; picking
    // any real group (new or existing) reveals the divisor choice, since
    // it's meaningless for a standalone (no-group) role.
    addRoleGroupSelect.addEventListener('change', () => {
        const isNewGroup = addRoleGroupSelect.value === '__new__';
        addRoleNewGroupInput.hidden = !isNewGroup;
        addRoleDivisorWrap.hidden = addRoleGroupSelect.value === '';
        if (isNewGroup) addRoleNewGroupInput.focus();
    });

    addRoleForm.addEventListener('submit', (e) => {
        e.preventDefault();

        const label = addRoleForm.querySelector('[name="label"]').value.trim();
        if (!label) return;
        const personName = addRoleForm.querySelector('[name="person_name"]').value.trim();
        const baseSalary = parseMoney(addRoleForm.querySelector('[name="base_salary"]').value || '0');
        const groupChoice = addRoleGroupSelect.value;
        const overheadGroup = groupChoice === '__new__'
            ? addRoleNewGroupInput.value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
            : groupChoice;

        if (groupChoice === '__new__' && !overheadGroup) {
            addRoleError.textContent = 'Enter a name for the new group.';
            addRoleError.classList.remove('hidden');
            return;
        }

        const submitBtn = addRoleForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;

        const body = new URLSearchParams();
        body.set('label', label);
        if (personName) body.set('person_name', personName);
        if (baseSalary) body.set('base_salary', baseSalary);
        if (overheadGroup) {
            body.set('overhead_group', overheadGroup);
            body.set('overhead_divisor', addRoleForm.querySelector('[name="overhead_divisor"]:checked').value);
        }

        fetch('{{ route('data.cost-breakdown.roles.store') }}', {
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
                addRoleError.textContent = data?.message || 'Could not add this role — try again.';
                addRoleError.classList.remove('hidden');
            });
    });

    document.addEventListener('click', async (e) => {
        const removeBtn = e.target.closest('[data-remove-role]');
        if (!removeBtn) return;
        if (!(await window.confirmDataModal('Remove this role?'))) return;

        const roleId = removeBtn.dataset.removeRole;
        fetch(`{{ url('/data/cost-breakdown/roles') }}/${roleId}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => window.location.reload())
            .catch(() => {
                window.showToast?.('Could not remove this role — try again.', 'error');
            });
    });
})();
</script>
@endpush
