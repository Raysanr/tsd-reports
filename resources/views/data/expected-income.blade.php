@extends('layouts.data')
@section('title', 'Expected Income 2026')
@section('subtitle', 'Month-to-date P&L per product — every figure auto-saves as you type')

@section('content')

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

{{-- One card per product, side by side (explicit request, 2026-09-26: "i
     want exactly like this like in the sheets like every product is has
     card") — same visual pattern as Projections' own _column.blade.php
     cards, just this page's own fields. A single overall "TELESALES"
     rollup card comes first, summing EVERY product (not split per team —
     explicit follow-up: "i want exactly like in the sheets but i want to
     make it like no per team"), same idea as Projections' Telesales
     Department card. #eiCards wraps everything so the JS only ever looks
     up a specific card by its own data-key, same convention as pj.js's own
     #pjColumns. --}}
<div id="eiCards" class="overflow-x-auto -mx-4 md:-mx-8 px-4 md:px-8 pb-2">
    <div class="flex items-start gap-5 w-max">
        <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-80 shrink-0" data-key="__overall__">
            <div class="px-5 py-4 bg-yellow-300 dark:bg-yellow-600">
                <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">
                    TELESALES — {{ $month->format('F j, Y') }}
                </span>
            </div>
            @include('data.expected-income._card-body', ['d' => $overallTotal, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
        </div>

        @foreach($productCards as $card)
        @php $product = $card['product']; $entry = $card['entry']; $d = $card['derived']; @endphp
        <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-80 shrink-0"
             data-key="{{ $product->id }}"
             data-action="{{ route('data.expected-income.update', ['product' => $product->id, 'month' => $month->format('Y-m')]) }}">
            <div class="px-5 py-4" style="background:#d9ead3;">
                <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">
                    {{ $product->display_name }}
                </span>
            </div>
            @include('data.expected-income._card-body', ['d' => $d, 'entry' => $entry, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => true])
        </div>
        @endforeach
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const cardsEl = document.getElementById('eiCards');
    if (!cardsEl) return;
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
    // client-side so a live edit's card repaint matches what a fresh page
    // load would show, same convention as pj.js's own applyComputed().
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
            gross_sales: grossSales, cancelled, returns, delivered,
            tax_allocation: taxAllocation, tax_allocation_pct: grossSales > 0 ? taxAllocation / grossSales : 0,
            product_cost: productCost, product_cost_pct: grossSales > 0 ? productCost / grossSales : 0,
            gross_profit: grossProfit, gross_profit_pct: grossSales > 0 ? grossProfit / grossSales : 0,
            selling_lines: sellingLines, total_selling_costs: totalSellingCosts,
            total_selling_costs_pct: grossSales > 0 ? totalSellingCosts / grossSales : 0,
            operating_lines: operatingLines, total_operating_costs: totalOperatingCosts,
            total_operating_costs_pct: grossSales > 0 ? totalOperatingCosts / grossSales : 0,
            net_income: netIncome, net_income_pct: grossSales > 0 ? netIncome / grossSales : 0,
        };
    }

    function applyDerived(card, derived) {
        card.querySelectorAll('[data-out]').forEach((el) => {
            const key = el.dataset.out;
            let value;
            if (key in derived) value = derived[key];
            else if (derived.selling_lines && key in derived.selling_lines) value = derived.selling_lines[key];
            else if (derived.operating_lines && key in derived.operating_lines) value = derived.operating_lines[key];
            else return;
            const isPct = key === 'conversion_rate' || key.endsWith('_pct');
            const isInt = key === 'number_of_leads' || key === 'number_of_orders';
            el.textContent = isPct ? fmtPct(value) : (isInt ? fmtInt(value) : fmtMoney(value));
            const isLoss = (key === 'gross_profit' || key === 'net_income') && value < 0;
            el.classList.toggle('text-red-600', isLoss);
            el.classList.toggle('dark:text-red-400', isLoss);
        });

        // Selling/Operating row percentages (% of Gross Sales) — these use
        // data-out-pct, not data-out, since the SAME row key already names
        // a different element (the $ figure) via data-out. Same
        // live-refresh convention as pj.js's own setPct(), so a live edit
        // never leaves a stale % next to an already-updated $ figure.
        card.querySelectorAll('[data-out-pct]').forEach((el) => {
            const key = el.dataset.outPct;
            const value = (derived.selling_lines || {})[key] ?? (derived.operating_lines || {})[key];
            if (value === undefined) return;
            el.textContent = derived.gross_sales > 0 ? fmtPct(value / derived.gross_sales) : '0.00%';
        });
    }

    // Recomputes and repaints the overall "TELESALES" rollup card from
    // every product card's own currently-saved inputs — sum dollars/
    // counts, average roas/actual_cost_per_lead, same split as
    // ExpectedIncomeCalculator::sum().
    function refreshOverallCard() {
        const overallCard = cardsEl.querySelector('.ei-card[data-key="__overall__"]');
        if (!overallCard) return;

        const productCards = cardsEl.querySelectorAll('.ei-card:not([data-key="__overall__"])');
        const rawRows = [];
        let roasSum = 0, costPerLeadSum = 0;

        productCards.forEach((card) => {
            const raw = {};
            ['roas', 'actual_cost_per_lead', 'number_of_leads', 'number_of_orders', 'average_order_value', 'tax_allocation', 'product_cost']
                .forEach((key) => {
                    const el = card.querySelector(`[data-field="${key}"]`);
                    raw[key] = el ? (el.dataset.money === '1' ? parseMoney(el.value) : Number(el.value) || 0) : 0;
                });
            SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => {
                const el = card.querySelector(`[data-field="${key}"]`);
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

        applyDerived(overallCard, summed);
    }

    function saveField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const card = input.closest('.ei-card');
        const status = card?.querySelector('.ei-card-status');
        if (!card) return;

        const field = input.dataset.field;
        const value = input.dataset.money === '1' ? parseMoney(input.value) : (Number(input.value) || 0);

        if (status) status.textContent = 'Saving…';
        flashStatus('Saving…', false);

        const body = new URLSearchParams();
        body.set(field, value);
        body.set('_method', 'PATCH');

        fetch(card.dataset.action, {
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
                if (status) { status.textContent = 'Saved'; setTimeout(() => { if (status.textContent === 'Saved') status.textContent = ''; }, 1500); }
                flashStatus('Saved', false);
                if (input.dataset.money === '1' && document.activeElement !== input) {
                    input.value = fmtMoney(value);
                }
                if (data?.derived) applyDerived(card, data.derived);
                refreshOverallCard();
            })
            .catch(() => {
                if (status) status.textContent = 'Failed';
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    cardsEl.addEventListener('input', (e) => {
        const input = e.target.closest('.ei-field');
        if (!input) return;
        if (input.dataset.money === '1') liveFormatMoney(input);
        clearTimeout(saveTimers.get(input));
        saveTimers.set(input, setTimeout(() => saveField(input), 600));
    });

    cardsEl.addEventListener('blur', (e) => {
        const input = e.target.closest('.ei-field');
        if (input) saveField(input);
    }, true);

    cardsEl.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const input = e.target.closest('.ei-field');
        if (input) { e.preventDefault(); input.blur(); }
    });
})();
</script>
@endpush

@endsection
