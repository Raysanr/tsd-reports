@extends('layouts.data')
@section('title', 'Expected Income 2026')
@section('subtitle', 'Month-to-date P&L per product — every figure auto-saves as you type')

@section('content')

{{-- Sticky-left product column, same convention as dsppr.blade.php (this
     page extends layouts.data too, so no shared .sticky-col rule to reuse). --}}
<style>
    .ei-sticky { position: sticky; left: 0; z-index: 5; }
    thead .ei-sticky { z-index: 25; }
    .ei-sticky-body { background-color: #fff; }
    .dark .ei-sticky-body { background-color: #0f172a; }
    tr:hover > .ei-sticky-body { background-color: #f8fafc; }
    .dark tr:hover > .ei-sticky-body { background-color: #1e293b; }
    .ei-sticky-footer { background-color: #000; }
    .ei-table { font-variant-numeric: tabular-nums; }
    .ei-table th, .ei-table td { border: none; border-bottom: 1px solid #cbd5e1; }
    .dark .ei-table th, .dark .ei-table td { border-bottom-color: #475569; }
</style>

@php
    $fmtMoney = fn ($n) => number_format((float) $n, 2);
    $fmtPct   = fn ($n) => number_format(((float) $n) * 100, 2) . '%';
    $sellingRows = \App\Support\ExpectedIncomeCalculator::SELLING_COST_ROWS;
    $operatingRows = \App\Support\ExpectedIncomeCalculator::OPERATING_COST_ROWS;
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        <div>
            <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Month</label>
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()"
                   class="text-sm font-mono border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
        </div>
    </form>
    <span id="eiSaveStatus" class="text-xs font-mono text-slate-400 dark:text-slate-500 min-h-[1.25rem]"></span>
</div>

@foreach($teamBlocks as $block)
@php
    $teamSlug = \Illuminate\Support\Str::slug($block['team'] ?: 'team');
    $t = $block['total'];
@endphp
<div class="rounded-2xl border border-line dark:border-slate-700 shadow-panel overflow-hidden mb-8">
    <div class="bg-black text-white text-center font-mono font-bold text-sm tracking-wide py-2.5">
        {{ strtoupper($block['team'] ?: 'UNASSIGNED') }}
    </div>
    <div class="bg-yellow-300 dark:bg-yellow-500 text-center font-mono font-bold text-xs tracking-wide py-2 text-ink">
        {{ $month->format('F Y') }}
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-[13px] font-mono border-collapse ei-table" id="eiTeamTable-{{ $teamSlug }}"
               data-update-url-template="{{ route('data.expected-income.update', ['product' => '__PRODUCT__', 'month' => '__MONTH__']) }}"
               data-month="{{ $month->format('Y-m') }}">
            <thead>
                <tr class="text-ink dark:text-slate-950">
                    <th class="ei-sticky bg-yellow-200 dark:bg-yellow-700 text-left px-3 py-2 font-bold whitespace-nowrap">Product</th>
                    <th class="bg-slate-400 dark:bg-slate-500 text-right px-3 py-2 font-bold whitespace-nowrap">ROAS</th>
                    <th class="bg-slate-400 dark:bg-slate-500 text-right px-3 py-2 font-bold whitespace-nowrap">Actual Cost Per Lead</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Number of Leads</th>
                    <th class="bg-rose-200 dark:bg-rose-800 text-right px-3 py-2 font-bold whitespace-nowrap">Conversion Rate</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Number of Orders</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Average Order Value</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Gross Sales</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Cancelled</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Projected Returns</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Projected Delivered</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Tax Allocation</th>
                    <th class="bg-yellow-100 dark:bg-yellow-800 text-right px-3 py-2 font-bold whitespace-nowrap">Product Cost</th>
                    <th class="bg-slate-200 dark:bg-slate-600 text-right px-3 py-2 font-bold whitespace-nowrap">Gross Profit</th>
                    @foreach($sellingRows as $key => $label)
                    <th class="bg-emerald-100 dark:bg-emerald-800 text-right px-3 py-2 font-bold whitespace-nowrap">{{ $label }}</th>
                    @endforeach
                    <th class="bg-emerald-200 dark:bg-emerald-700 text-right px-3 py-2 font-bold whitespace-nowrap">COD Fee</th>
                    <th class="bg-emerald-200 dark:bg-emerald-700 text-right px-3 py-2 font-bold whitespace-nowrap">Fulfillment Fee</th>
                    <th class="bg-slate-200 dark:bg-slate-600 text-right px-3 py-2 font-bold whitespace-nowrap">Total Selling Costs</th>
                    @foreach($operatingRows as $key => $label)
                    <th class="bg-sky-100 dark:bg-sky-900 text-right px-3 py-2 font-bold whitespace-nowrap">{{ $label }}</th>
                    @endforeach
                    <th class="bg-slate-200 dark:bg-slate-600 text-right px-3 py-2 font-bold whitespace-nowrap">Total Operating Costs</th>
                    <th class="bg-slate-300 dark:bg-slate-500 text-right px-3 py-2 font-bold whitespace-nowrap">Net Income</th>
                </tr>
            </thead>
            <tbody>
                @foreach($block['rows'] as $row)
                @php $product = $row['product']; $entry = $row['entry']; $d = $row['derived']; @endphp
                <tr class="ei-row odd:bg-emerald-50/40 dark:odd:bg-emerald-950/10 hover:bg-slate-50 dark:hover:bg-slate-800/60" data-product-id="{{ $product->id }}">
                    <td class="ei-sticky ei-sticky-body px-3 py-2 font-semibold text-ink dark:text-slate-100 whitespace-nowrap">{{ strtoupper($product->display_name) }}</td>

                    @foreach([
                        ['key' => 'roas', 'money' => true],
                        ['key' => 'actual_cost_per_lead', 'money' => true],
                        ['key' => 'number_of_leads', 'int' => true],
                    ] as $col)
                    <td class="px-2 py-1.5">
                        <input type="text" inputmode="{{ ($col['int'] ?? false) ? 'numeric' : 'decimal' }}"
                               value="{{ ($col['money'] ?? false) ? $fmtMoney($entry?->{$col['key']} ?? 0) : ($entry?->{$col['key']} ?? 0) }}"
                               data-field="{{ $col['key'] }}" @if($col['money'] ?? false) data-money="1" @endif
                               class="ei-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                    </td>
                    @endforeach

                    <td class="px-3 py-2 text-right" data-out="conversion_rate">{{ $fmtPct($d['conversion_rate']) }}</td>

                    <td class="px-2 py-1.5">
                        <input type="text" inputmode="numeric" value="{{ $entry?->number_of_orders ?? 0 }}"
                               data-field="number_of_orders"
                               class="ei-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                    </td>
                    <td class="px-2 py-1.5">
                        <input type="text" inputmode="decimal" value="{{ $fmtMoney($entry?->average_order_value ?? 0) }}"
                               data-field="average_order_value" data-money="1"
                               class="ei-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                    </td>

                    <td class="px-3 py-2 text-right" data-out="gross_sales">{{ $fmtMoney($d['gross_sales']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="cancelled">{{ $fmtMoney($d['cancelled']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="returns">{{ $fmtMoney($d['returns']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="delivered">{{ $fmtMoney($d['delivered']) }}</td>

                    <td class="px-2 py-1.5">
                        <input type="text" inputmode="decimal" value="{{ $fmtMoney($entry?->tax_allocation ?? 0) }}"
                               data-field="tax_allocation" data-money="1"
                               class="ei-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                    </td>
                    <td class="px-2 py-1.5">
                        <input type="text" inputmode="decimal" value="{{ $fmtMoney($entry?->product_cost ?? 0) }}"
                               data-field="product_cost" data-money="1"
                               class="ei-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                    </td>

                    <td class="px-3 py-2 text-right font-bold {{ $d['gross_profit'] < 0 ? 'text-red-600 dark:text-red-400' : '' }}" data-out="gross_profit">{{ $fmtMoney($d['gross_profit']) }}</td>

                    @foreach($sellingRows as $key => $label)
                    <td class="px-2 py-1.5">
                        <input type="text" inputmode="decimal" value="{{ $fmtMoney($entry?->{$key} ?? 0) }}"
                               data-field="{{ $key }}" data-money="1"
                               class="ei-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                    </td>
                    @endforeach
                    <td class="px-3 py-2 text-right" data-out="cod_fee">{{ $fmtMoney($d['selling_lines']['cod_fee']) }}</td>
                    <td class="px-3 py-2 text-right" data-out="fulfillment_fee">{{ $fmtMoney($d['selling_lines']['fulfillment_fee']) }}</td>
                    <td class="px-3 py-2 text-right font-bold" data-out="total_selling_costs">{{ $fmtMoney($d['total_selling_costs']) }}</td>

                    @foreach($operatingRows as $key => $label)
                    <td class="px-2 py-1.5">
                        <input type="text" inputmode="decimal" value="{{ $fmtMoney($entry?->{$key} ?? 0) }}"
                               data-field="{{ $key }}" data-money="1"
                               class="ei-field w-24 text-right bg-slate-50 dark:bg-slate-800 border border-slate-400 dark:border-slate-500 rounded-md px-1.5 py-1 font-semibold text-ink dark:text-slate-100 focus:ring-2 focus:ring-primary/40 focus:border-primary outline-none">
                    </td>
                    @endforeach
                    <td class="px-3 py-2 text-right font-bold" data-out="total_operating_costs">{{ $fmtMoney($d['total_operating_costs']) }}</td>

                    <td class="px-3 py-2 text-right font-bold text-base {{ $d['net_income'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-primary' }}" data-out="net_income">{{ $fmtMoney($d['net_income']) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-black text-white font-bold ei-team-total-row">
                    <td class="ei-sticky ei-sticky-footer px-3 py-2.5 whitespace-nowrap">TOTAL — {{ strtoupper($block['team'] ?: 'UNASSIGNED') }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="roas">{{ $fmtMoney($t['roas']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="actual_cost_per_lead">{{ $fmtMoney($t['actual_cost_per_lead']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="number_of_leads">{{ number_format($t['number_of_leads']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="conversion_rate">{{ $fmtPct($t['conversion_rate']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="number_of_orders">{{ number_format($t['number_of_orders']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="average_order_value">{{ $fmtMoney($t['average_order_value']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="gross_sales">{{ $fmtMoney($t['gross_sales']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="cancelled">{{ $fmtMoney($t['cancelled']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="returns">{{ $fmtMoney($t['returns']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="delivered">{{ $fmtMoney($t['delivered']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="tax_allocation">{{ $fmtMoney($t['tax_allocation']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="product_cost">{{ $fmtMoney($t['product_cost']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $t['gross_profit'] < 0 ? 'text-red-400' : '' }}" data-out="gross_profit">{{ $fmtMoney($t['gross_profit']) }}</td>
                    @foreach($sellingRows as $key => $label)
                    <td class="px-3 py-2.5 text-right" data-out="{{ $key }}">{{ $fmtMoney($t['selling_lines'][$key] ?? 0) }}</td>
                    @endforeach
                    <td class="px-3 py-2.5 text-right" data-out="cod_fee">{{ $fmtMoney($t['selling_lines']['cod_fee']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="fulfillment_fee">{{ $fmtMoney($t['selling_lines']['fulfillment_fee']) }}</td>
                    <td class="px-3 py-2.5 text-right" data-out="total_selling_costs">{{ $fmtMoney($t['total_selling_costs']) }}</td>
                    @foreach($operatingRows as $key => $label)
                    <td class="px-3 py-2.5 text-right" data-out="{{ $key }}">{{ $fmtMoney($t['operating_lines'][$key] ?? 0) }}</td>
                    @endforeach
                    <td class="px-3 py-2.5 text-right" data-out="total_operating_costs">{{ $fmtMoney($t['total_operating_costs']) }}</td>
                    <td class="px-3 py-2.5 text-right {{ $t['net_income'] < 0 ? 'text-red-400' : '' }}" data-out="net_income">{{ $fmtMoney($t['net_income']) }}</td>
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
    const tables = document.querySelectorAll('[id^="eiTeamTable-"]');
    if (!tables.length) return;
    const globalStatus = document.getElementById('eiSaveStatus');
    const saveTimers = new WeakMap();

    const SELLING_KEYS = @json(array_keys($sellingRows));
    const OPERATING_KEYS = @json(array_keys($operatingRows));
    const CANCELLED_RATE = 0.05, RETURNS_RATE = 0.25, DELIVERED_RATE = 0.70;
    const COD_FEE_RATE_OF_DELIVERED = 0.0224, FULFILLMENT_FEE_PER_ORDER = 25.0;

    function fmtMoney(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtInt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); }
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

    function flashStatus(text, isError) {
        if (!globalStatus) return;
        globalStatus.textContent = text;
        globalStatus.classList.toggle('text-red-500', !!isError);
        globalStatus.classList.toggle('text-slate-400', !isError);
        clearTimeout(flashStatus._t);
        flashStatus._t = setTimeout(() => { if (globalStatus.textContent === text) globalStatus.textContent = ''; }, 2000);
    }

    // Same formula chain as ExpectedIncomeCalculator::derive() — mirrors it
    // client-side so a live edit's row/team-total repaint matches what a
    // fresh page load would show, same convention as dsppr.blade.php's own
    // refreshDayTotal()/refreshSummaryRow().
    function derive(row) {
        const leads = Number(row.number_of_leads) || 0;
        const orders = Number(row.number_of_orders) || 0;
        const aov = Number(row.average_order_value) || 0;
        const taxAllocation = Number(row.tax_allocation) || 0;
        const productCost = Number(row.product_cost) || 0;

        const conversionRate = leads > 0 ? orders / leads : 0;
        const grossSales = orders * aov;
        const cancelled = grossSales * CANCELLED_RATE;
        const returns = grossSales * RETURNS_RATE;
        const delivered = grossSales * DELIVERED_RATE;
        const grossProfit = grossSales - cancelled - returns - taxAllocation - productCost;

        const sellingLines = {};
        let totalSellingCosts = 0;
        SELLING_KEYS.forEach((key) => { sellingLines[key] = Number(row[key]) || 0; totalSellingCosts += sellingLines[key]; });
        sellingLines.cod_fee = delivered * COD_FEE_RATE_OF_DELIVERED;
        sellingLines.fulfillment_fee = orders * FULFILLMENT_FEE_PER_ORDER;
        totalSellingCosts += sellingLines.cod_fee + sellingLines.fulfillment_fee;

        const operatingLines = {};
        let totalOperatingCosts = 0;
        OPERATING_KEYS.forEach((key) => { operatingLines[key] = Number(row[key]) || 0; totalOperatingCosts += operatingLines[key]; });

        const netIncome = grossProfit - totalSellingCosts - totalOperatingCosts;

        return {
            roas: Number(row.roas) || 0, actual_cost_per_lead: Number(row.actual_cost_per_lead) || 0,
            number_of_leads: leads, conversion_rate: conversionRate, number_of_orders: orders, average_order_value: aov,
            gross_sales: grossSales, cancelled, returns, delivered, tax_allocation: taxAllocation, product_cost: productCost,
            gross_profit: grossProfit, selling_lines: sellingLines, total_selling_costs: totalSellingCosts,
            operating_lines: operatingLines, total_operating_costs: totalOperatingCosts, net_income: netIncome,
        };
    }

    function applyDerived(row, derived) {
        row.querySelectorAll('[data-out]').forEach((el) => {
            const key = el.dataset.out;
            let value;
            if (key in derived) value = derived[key];
            else if (key in derived.selling_lines) value = derived.selling_lines[key];
            else if (key in derived.operating_lines) value = derived.operating_lines[key];
            else return;
            const isPct = key === 'conversion_rate';
            const isInt = key === 'number_of_leads' || key === 'number_of_orders';
            el.textContent = isPct ? fmtPct(value) : (isInt ? fmtInt(value) : fmtMoney(value));
            const isLoss = (key === 'gross_profit' || key === 'net_income') && value < 0;
            el.classList.toggle('text-red-600', isLoss);
            el.classList.toggle('dark:text-red-400', isLoss);
        });
    }

    // Recomputes and repaints the TEAM total row from every product row's
    // OWN currently-saved inputs — sum dollars/counts, average
    // roas/actual_cost_per_lead/conversion_rate, same split as
    // ExpectedIncomeCalculator::sum().
    function refreshTeamTotal(table) {
        const rows = table.querySelectorAll('tbody .ei-row');
        const rawRows = [];
        let roasSum = 0, costPerLeadSum = 0;

        rows.forEach((row) => {
            const raw = {};
            ['roas', 'actual_cost_per_lead', 'number_of_leads', 'number_of_orders', 'average_order_value', 'tax_allocation', 'product_cost']
                .forEach((key) => {
                    const el = row.querySelector(`[data-field="${key}"]`);
                    raw[key] = el ? (el.dataset.money === '1' ? parseMoney(el.value) : Number(el.value) || 0) : 0;
                });
            SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => {
                const el = row.querySelector(`[data-field="${key}"]`);
                raw[key] = el ? parseMoney(el.value) : 0;
            });
            rawRows.push(raw);
            const d = derive(raw);
            roasSum += d.roas;
            costPerLeadSum += d.actual_cost_per_lead;
        });

        const totals = { number_of_leads: 0, number_of_orders: 0, tax_allocation: 0, product_cost: 0 };
        SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => { totals[key] = 0; });
        let totalGrossSales = 0;
        rawRows.forEach((raw) => {
            totals.number_of_leads += raw.number_of_leads;
            totals.number_of_orders += raw.number_of_orders;
            totals.tax_allocation += raw.tax_allocation;
            totals.product_cost += raw.product_cost;
            SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => { totals[key] += raw[key]; });
            totalGrossSales += raw.number_of_orders * raw.average_order_value;
        });
        totals.average_order_value = totals.number_of_orders > 0 ? totalGrossSales / totals.number_of_orders : 0;

        const summed = derive(totals);
        const rowCount = rawRows.length;
        if (rowCount > 0) {
            summed.roas = roasSum / rowCount;
            summed.actual_cost_per_lead = costPerLeadSum / rowCount;
        }

        const totalRow = table.querySelector('.ei-team-total-row');
        if (totalRow) applyDerived(totalRow, summed);
    }

    function saveField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const table = input.closest('[id^="eiTeamTable-"]');
        const urlTemplate = table.dataset.updateUrlTemplate;
        const month = table.dataset.month;
        const row = input.closest('.ei-row');
        const field = input.dataset.field;
        const value = input.dataset.money === '1' ? parseMoney(input.value) : (Number(input.value) || 0);

        flashStatus('Saving…', false);

        const url = urlTemplate.replace('__PRODUCT__', row.dataset.productId).replace('__MONTH__', month);
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
                if (data?.derived) applyDerived(row, data.derived);
                refreshTeamTotal(table);
            })
            .catch(() => {
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    tables.forEach((table) => {
        table.addEventListener('input', (e) => {
            const input = e.target.closest('.ei-field');
            if (!input) return;
            if (input.dataset.money === '1') liveFormatMoney(input);
            clearTimeout(saveTimers.get(input));
            saveTimers.set(input, setTimeout(() => saveField(input), 600));
        });

        table.addEventListener('blur', (e) => {
            const input = e.target.closest('.ei-field');
            if (input) saveField(input);
        }, true);

        table.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const input = e.target.closest('.ei-field');
            if (input) { e.preventDefault(); input.blur(); }
        });
    });
})();
</script>
@endpush

@endsection
