@extends('layouts.data')
@section('title', 'Expected Income')
@section('subtitle', 'Daily P&L per product — every figure auto-saves as you type')

@section('content')

<style>
    .ei-scroller { cursor: grab; }
    .ei-scroller.cursor-grabbing { cursor: grabbing; }
</style>

@php
    $fmtMoney = fn ($n) => number_format((float) $n, 2);
    $fmtPct   = fn ($n) => number_format(((float) $n) * 100, 2) . '%';
    // sellingCostRows()/operatingCostRows(), not the bare constants —
    // explicit request, 2026-09-28: a row added via the + icon on
    // Projections "should be automatically added to the expected income
    // rows," and these two methods already merge in every row from that
    // same shared projection_custom_rows table (see
    // ExpectedIncomeCalculator's own doc comment on them).
    $sellingRows = \App\Support\ExpectedIncomeCalculator::sellingCostRows();
    $operatingRows = \App\Support\ExpectedIncomeCalculator::operatingCostRows();
    $customRowKeys = \App\Support\ExpectedIncomeCalculator::customRowKeys();
@endphp

<div class="mb-6 flex items-end justify-between gap-4 flex-wrap">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        <input type="hidden" name="team" value="{{ $selectedTeam }}">
        @include('data._date-range-filter', ['fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])

        {{-- Team filter (explicit request, 2026-09-30: "i want to add team
             filter like this ... ALL / TEAM CLOSING / TEAM OPENING") — ALL
             keeps the page's own original product-level cards + both
             teams' own auto-summed rollup cards; picking a real team
             swaps in that team's own real TSAs, one full row of product
             cards each, no separate product-level cards. --}}
        <div class="flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
            @foreach($teams as $key => $label)
            <button type="submit" name="team" value="{{ $key }}" data-filter-btn
                    class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200 motion-reduce:transition-none
                           {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
                {{ strtoupper($label) }}
            </button>
            @endforeach
        </div>
    </form>
    <div class="flex items-center gap-3">
        <span class="hidden sm:inline-flex items-center gap-1.5 text-[11px] font-mono text-ink-muted dark:text-slate-400">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 9l-5 5m0 0l5 5m-5-5h18m-5-9l5 5m0 0l-5 5"/></svg>
            Drag/scroll right to see more products
        </span>
        <span id="eiSaveStatus" class="text-xs font-mono text-slate-400 dark:text-slate-500 min-h-[1.25rem]"></span>
    </div>
</div>

{{-- Range summary row + per-team summary rows — extracted into
     _summary-section so the same markup can be re-rendered as an AJAX
     fragment (ExpectedIncomeController::summary()) and live-refreshed
     after every autosave, no full page reload needed. See that partial's
     own doc comment. --}}
@include('data.expected-income._summary-section')

{{-- Daily rows — one row of cards PER calendar day, stacking downward
     (explicit request, 2026-09-26: "in the top there's expected sales and
     after that it is dates going down"), each independently editable and
     independently drag-scrollable. Same overall-card + product-cards
     pattern as the summary row above, just for that one day's own figures.
     No heading above each day's own row, and no date inside the overall
     card's own title either — explicit follow-up, 2026-09-30: "there will
     be no dates in all" then, after the standalone heading removal alone
     didn't cover it, "the only will be gone is this in the down part"
     pointing at "TELESALES — SEPTEMBER 30, 2026" still showing inside
     that card's own title. The overall card is now titled plain
     "TELESALES", same as every other card on the page never showing a
     date in its own title.

     Team filter (explicit request, 2026-09-30, confirmed live via
     screenshot exactly which part of the page this touches: "the top is
     still like that [unaffected] ... in the down the yellow is only TSA
     NAMES"): selectedTeam === 'all' keeps the ORIGINAL single "TELESALES"
     overall card + every product's own card, tsa_id NULL, unchanged from
     before this feature; a real team swaps that one block for ONE block
     PER REAL TSA on that team, her own name where the overall card's
     title used to be, each followed by her own product cards — for EVERY
     date, same per-day stacking either way. --}}
{{-- ALL-view daily rows removed (explicit request, 2026-09-30: ALL should
     only show Telesales Expected Performance + TEAM 1 + TEAM 2 summary
     rows above, no per-date card rows) — daily per-date cards now only
     render once a real team is picked. --}}
@if($selectedTeam !== 'all')
@foreach($dates as $date)
@php
    $dateStr = $date->toDateString();
@endphp

    @foreach($tsaRows as $tsaRow)
    @php
        $tsa = $tsaRow['tsa'];
        $tsaDailyRows = $tsaRow['dailyRows'][$dateStr];
        $tsaDailyByKey = $tsaRow['dailyByKey'];
        $tsaDayOverallTotal = $tsaRow['dailyOverallTotals'][$dateStr];
    @endphp
    <div class="overflow-x-auto ei-scroller -mx-4 md:-mx-8 px-4 md:px-8 pb-2 mb-8 ei-day-scroller" data-date="{{ $dateStr }}">
        <div class="flex items-start gap-5 w-max">
            {{-- Her own name where the plain "TELESALES" card title used
                 to be — a read-only rollup of HER OWN product cards for this day
                 (confirmed live, 2026-09-30: "the yellow is stil has this,
                 it is over all of the individual tsa ... but it is not
                 editable"), same role and full P&L body as the overall
                 card in the ALL view, just scoped to her own products. --}}
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0" data-out-scope="1">
                <div class="px-5 py-4" style="background:#fde047;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $tsa->display_name }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $tsaDayOverallTotal, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => false])
            </div>

            @foreach($tsaDailyRows as $row)
            @php
                $d = $row['derived'];
                $product = $row['products']->first();
                $entry = $tsaDailyByKey->get($product->id . ':' . $dateStr);
            @endphp
            <div class="ei-card bg-white dark:bg-slate-900 border border-line dark:border-slate-700 rounded-2xl shadow-panel overflow-hidden w-[26rem] shrink-0"
                 data-product-id="{{ $product->id }}"
                 data-action="{{ route('data.expected-income.update-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $dateStr]) }}"
                 data-custom-action="{{ route('data.expected-income.update-custom-row-tsa', ['product' => $product->id, 'tsaShift' => $tsa->id, 'date' => $dateStr]) }}">
                <div class="px-5 py-4" style="background:#d9ead3;">
                    <span class="font-mono font-bold text-sm uppercase tracking-wide text-ink truncate block">{{ $row['label'] }}</span>
                </div>
                @include('data.expected-income._card-body', ['d' => $d, 'entry' => $entry, 'sellingRows' => $sellingRows, 'operatingRows' => $operatingRows, 'customRowKeys' => $customRowKeys, 'fmtMoney' => $fmtMoney, 'fmtPct' => $fmtPct, 'editable' => true, 'tsaScoped' => true])
            </div>
            @endforeach
        </div>
    </div>
    @endforeach
@endforeach
@endif

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const globalStatus = document.getElementById('eiSaveStatus');
    const saveTimers = new WeakMap();

    const SELLING_KEYS = @json(array_keys($sellingRows));
    const OPERATING_KEYS = @json(array_keys($operatingRows));
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
        // Gross Sales/Cancelled/Projected Returns/Projected Delivered are
        // plain manual inputs (explicit request, 2026-09-28), no longer
        // Orders × AOV / fixed rates — mirrors ExpectedIncomeCalculator::
        // derive()'s own PHP side of this same change.
        const grossSales = Number(row.gross_sales) || 0;
        const cancelled = Number(row.cancelled) || 0;
        const returns = Number(row.returns) || 0;
        const delivered = Number(row.delivered) || 0;
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

        const incomeBeforeOpex = grossProfit - totalSellingCosts;
        const netIncome = incomeBeforeOpex - totalOperatingCosts;

        return {
            roas: Number(row.roas) || 0, standard_cost_per_message: Number(row.standard_cost_per_message) || 0, actual_cost_per_lead: Number(row.actual_cost_per_lead) || 0,
            number_of_leads: leads, conversion_rate: conversionRate, number_of_orders: orders, average_order_value: aov,
            gross_sales: grossSales, gross_sales_pct: grossSales > 0 ? 1 : 0,
            cancelled, cancelled_pct: grossSales > 0 ? cancelled / grossSales : 0,
            returns, returns_pct: grossSales > 0 ? returns / grossSales : 0,
            delivered, delivered_pct: grossSales > 0 ? delivered / grossSales : 0,
            tax_allocation: taxAllocation, tax_allocation_pct: grossSales > 0 ? taxAllocation / grossSales : 0,
            product_cost: productCost, product_cost_pct: grossSales > 0 ? productCost / grossSales : 0,
            gross_profit: grossProfit, gross_profit_pct: grossSales > 0 ? grossProfit / grossSales : 0,
            selling_lines: sellingLines, total_selling_costs: totalSellingCosts,
            total_selling_costs_pct: grossSales > 0 ? totalSellingCosts / grossSales : 0,
            income_before_opex: incomeBeforeOpex, income_before_opex_pct: grossSales > 0 ? incomeBeforeOpex / grossSales : 0,
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
            const isLoss = (key === 'gross_profit' || key === 'net_income' || key === 'income_before_opex') && value < 0;
            el.classList.toggle('text-red-600', isLoss);
            el.classList.toggle('dark:text-red-400', isLoss);
            // Net Income (only — Gross Profit stays plain ink when positive,
            // same as its own static server-render) also turns green when
            // positive, not just red when negative — explicit request,
            // 2026-09-28: "the net income it should be green if positive
            // ... and if negative ... red." Toggled independently of isLoss
            // above since they're mutually exclusive states of the SAME
            // value, not two different flags.
            if (key === 'net_income') {
                el.classList.toggle('text-green-600', value >= 0);
                el.classList.toggle('dark:text-green-400', value >= 0);
            }
        });

        card.querySelectorAll('[data-out-pct]').forEach((el) => {
            const key = el.dataset.outPct;
            const value = (derived.selling_lines || {})[key] ?? (derived.operating_lines || {})[key];
            if (value === undefined) return;
            el.textContent = derived.gross_sales > 0 ? fmtPct(value / derived.gross_sales) : '0.00%';
        });
    }

    // Recomputes and repaints ONE day's own overall rollup card from every
    // product card's own currently-saved inputs IN THAT SAME DAY'S ROW —
    // scoped to $scroller so editing one day never touches another day's
    // rollup card, same convention as dsppr.blade.php's own refreshDayTotal().
    function refreshDayOverall(scroller) {
        const overallCard = scroller.querySelector('.ei-card[data-out-scope="1"]');
        if (!overallCard) return;

        const productCards = scroller.querySelectorAll('.ei-card[data-product-id]');
        const rawRows = [];
        let roasSum = 0, costPerLeadSum = 0;

        productCards.forEach((card) => {
            const raw = {};
            ['roas', 'actual_cost_per_lead', 'number_of_leads', 'number_of_orders', 'average_order_value', 'gross_sales', 'cancelled', 'returns', 'delivered', 'tax_allocation', 'product_cost']
                .forEach((key) => {
                    const el = card.querySelector(`[data-field="${key}"]`);
                    raw[key] = el ? (el.dataset.money === '1' ? parseMoney(el.value) : Number(el.value) || 0) : 0;
                });
            SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => {
                const el = card.querySelector(`[data-field="${key}"]`);
                if (el) { raw[key] = parseMoney(el.value); return; }
                // Salaries on a TSA-scoped card has no input at all (locked
                // to her own Daily Rate / Product — explicit request,
                // 2026-09-30) — read its server-rendered read-only span
                // instead of silently treating it as 0.
                const out = card.querySelector(`[data-out="${key}"]`);
                raw[key] = out ? parseMoney(out.textContent) : 0;
            });
            rawRows.push(raw);
            const d = derive(raw);
            roasSum += d.roas;
            costPerLeadSum += d.actual_cost_per_lead;
        });

        const totals = { number_of_leads: 0, number_of_orders: 0, gross_sales: 0, cancelled: 0, returns: 0, delivered: 0, tax_allocation: 0, product_cost: 0 };
        SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => { totals[key] = 0; });
        let aovSum = 0;
        rawRows.forEach((raw) => {
            totals.number_of_leads += raw.number_of_leads;
            totals.number_of_orders += raw.number_of_orders;
            totals.gross_sales += raw.gross_sales;
            totals.cancelled += raw.cancelled;
            totals.returns += raw.returns;
            totals.delivered += raw.delivered;
            totals.tax_allocation += raw.tax_allocation;
            totals.product_cost += raw.product_cost;
            SELLING_KEYS.concat(OPERATING_KEYS).forEach((key) => { totals[key] += raw[key]; });
            aovSum += raw.average_order_value;
        });

        const summed = derive(totals);
        const rowCount = rawRows.length;
        if (rowCount > 0) {
            summed.roas = roasSum / rowCount;
            summed.actual_cost_per_lead = costPerLeadSum / rowCount;
            summed.average_order_value = aovSum / rowCount;
        }

        applyDerived(overallCard, summed);
    }

    function saveField(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);

        const card = input.closest('.ei-card');
        const status = card?.querySelector('.ei-card-status');
        if (!card) return;
        const scroller = card.closest('.ei-day-scroller');

        const field = input.dataset.field;
        const value = input.dataset.money === '1' ? parseMoney(input.value) : (Number(input.value) || 0);

        if (status) status.textContent = 'Saving…';
        flashStatus('Saving…', false);

        const body = new URLSearchParams();
        body.set('_method', 'PATCH');

        // A row added via the + icon on Projections (data-custom="1" — see
        // _card-body.blade.php's own doc comment) has no fixed column on
        // ExpectedIncomeEntry, so it PATCHes a separate key/value endpoint
        // instead of update()'s named-field one, same "generic key/value,
        // not a hardcoded field name" convention as Projections' own
        // updateRates() endpoint.
        const isCustom = input.dataset.custom === '1';
        const action = isCustom ? card.dataset.customAction : card.dataset.action;
        if (isCustom) {
            body.set('key', field);
            body.set('value', value);
        } else {
            body.set(field, value);
        }

        fetch(action, {
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
                if (scroller) refreshDayOverall(scroller);
                scheduleSummaryRefresh();
            })
            .catch(() => {
                if (status) status.textContent = 'Failed';
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    // Refreshes "Telesales Expected Performance" + the per-team summary
    // rows from the server after every autosave (explicit request,
    // 2026-09-30: "why should i fully reload the page to reflect that" —
    // that row pools every TSA/team's own entries, so no in-scroller
    // re-sum like refreshDayOverall() above can keep it live). Debounced
    // and coalesced (one in-flight fetch at a time, one more queued behind
    // it) so a burst of field saves doesn't fire a request per keystroke.
    let summaryRefreshTimer = null, summaryRefreshInFlight = false, summaryRefreshQueued = false;
    function scheduleSummaryRefresh() {
        clearTimeout(summaryRefreshTimer);
        summaryRefreshTimer = setTimeout(runSummaryRefresh, 400);
    }
    function runSummaryRefresh() {
        if (summaryRefreshInFlight) { summaryRefreshQueued = true; return; }
        summaryRefreshInFlight = true;
        fetch('{{ route('data.expected-income.summary') }}?' + new URLSearchParams(window.location.search), {
            headers: { Accept: 'text/html' },
        })
            .then((res) => (res.ok ? res.text() : Promise.reject(res)))
            .then((html) => {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = html.trim();
                const fresh = wrapper.firstElementChild;
                const current = document.getElementById('eiSummarySection');
                if (fresh && current) {
                    current.replaceWith(fresh);
                    wireScroller(fresh.querySelectorAll('.ei-scroller'));
                }
            })
            .catch(() => { /* best-effort refresh — the daily card the user is typing into already saved fine */ })
            .finally(() => {
                summaryRefreshInFlight = false;
                if (summaryRefreshQueued) { summaryRefreshQueued = false; runSummaryRefresh(); }
            });
    }

    document.querySelectorAll('.ei-day-scroller').forEach((scroller) => {
        scroller.addEventListener('input', (e) => {
            const input = e.target.closest('.ei-field');
            if (!input) return;
            if (input.dataset.money === '1') liveFormatMoney(input);
            clearTimeout(saveTimers.get(input));
            saveTimers.set(input, setTimeout(() => saveField(input), 600));
        });

        scroller.addEventListener('blur', (e) => {
            const input = e.target.closest('.ei-field');
            if (input) saveField(input);
        }, true);

        scroller.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const input = e.target.closest('.ei-field');
            if (input) { e.preventDefault(); input.blur(); }
        });
    });

    // Click-and-drag horizontal scroll (same convention as dsppr.blade.php's
    // own scroller), wired to every row's scroller independently. Factored
    // into a function so runSummaryRefresh() above can re-wire it onto the
    // fresh scrollers it swaps in after replaceWith() — the old elements'
    // listeners are destroyed along with them.
    function wireScroller(scrollers) {
        scrollers.forEach((scroller) => {
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
    }
    wireScroller(document.querySelectorAll('.ei-scroller'));
})();
</script>
@endpush

@endsection
