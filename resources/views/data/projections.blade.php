@extends('layouts.data')
@section('title', 'Projections')
@section('subtitle', 'P&L targets & planning — every figure auto-saves as you type')

@section('content')

{{-- Intro paragraph removed (explicit request, 2026-09-23: "i want you te
     remove this") — the OPENING TEAM / CLOSING TEAM row labels below now
     carry that context instead. --}}
<div class="mb-6 flex items-start justify-end gap-4 flex-wrap">
    <span id="pjSaveStatus" class="text-xs font-mono text-slate-400 dark:text-slate-500 min-h-[1.25rem]"></span>
</div>

@php
    // Two rows, matching the source sheet's own layout exactly (explicit
    // request, 2026-09-23: "it is like this the opening 4 cards is in the
    // top and in the down part there's closing") — Telesales Department
    // + Opening Shift + Opening's own Individual Monthly/Daily on top,
    // Closing Shift + Closing's own Individual Monthly/Daily below.
    $openingRowKeys = ['telesales_department', 'opening_shift', 'opening_individual_tsa_monthly', 'opening_individual_tsa_daily'];
    $closingRowKeys = ['closing_shift', 'closing_individual_tsa_monthly', 'closing_individual_tsa_daily'];
    $openingRow = $computed->whereIn('column.key', $openingRowKeys)->sortBy(fn ($e) => $e['column']->sort_order);
    $closingRow = $computed->whereIn('column.key', $closingRowKeys)->sortBy(fn ($e) => $e['column']->sort_order);
@endphp

{{-- Each row's own cards side by side, matching the source sheet's own
     layout (explicit request, 2026-09-23: "why is it 2 card only in one
     view? i said i want 4 card like in the sheets") — every card has a
     real min-width (see .pj-card below) so dense rows (label + $ + %)
     stay readable, and each row scrolls horizontally on its own below
     that combined width instead of Tailwind's grid silently cramming
     columns into a space that only fits fewer comfortably. On mobile
     both rows drop to one column full width, same as the rest of this
     app's tables. #pjColumns wraps BOTH rows (not just one) — the JS
     only ever looks up a specific card by its own data-key, so it
     doesn't care which row a card visually sits in. --}}
<div id="pjColumns">
    <p class="mb-3 text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase">Opening Team</p>
    {{-- justify-center on the flex wrapper (explicit request, 2026-09-24:
         "Telesales Department is in the center... all cards is aligned")
         centers the row's cards on the page whenever they fit inside the
         viewport with room to spare; overflow-x-auto still takes over and
         the row scrolls/left-aligns normally the moment it doesn't. --}}
    <div class="overflow-x-auto -mx-4 md:-mx-8 px-4 md:px-8 pb-2">
        <div class="flex flex-col lg:flex-row justify-center gap-5 min-w-fit mx-auto">
            @foreach($openingRow as $entry)
                <div class="w-full lg:w-[300px] shrink-0">
                    @include('data.projections._column', ['entry' => $entry])
                </div>
            @endforeach
        </div>
    </div>

    <p class="mt-8 mb-3 text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase">Closing Team</p>
    <div class="overflow-x-auto -mx-4 md:-mx-8 px-4 md:px-8 pb-2">
        <div class="flex flex-col lg:flex-row justify-center gap-5 min-w-fit mx-auto">
            @foreach($closingRow as $entry)
                <div class="w-full lg:w-[300px] shrink-0">
                    @include('data.projections._column', ['entry' => $entry])
                </div>
            @endforeach
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const saveTimers = new WeakMap();
    const globalStatus = document.getElementById('pjSaveStatus');
    const columnsEl = document.getElementById('pjColumns');

    function fmtMoney(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtNumber(n, decimals = 0) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }
    function fmtPct(n) {
        return (Number(n) * 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
    }
    // Money inputs are type="text" (explicit request, 2026-09-23: "i want
    // has comma... like for example 1,000") — a native type="number" can't
    // display thousands separators at all. parseMoney strips them back out
    // before anything numeric happens.
    function parseMoney(str) {
        return Number(String(str).replace(/,/g, ''));
    }

    // Live comma-formatting AS the user types (explicit follow-up,
    // 2026-09-23: "auto comma even when typing not just when it is
    // saved") — reformats on every keystroke while preserving cursor
    // position by counting digits-from-the-end rather than a raw index,
    // since inserting/removing commas shifts everything after them. Only
    // the integer part gets grouped; a decimal point and what follows it
    // pass through untouched so "1234.5" can still become "12345" as more
    // digits are typed after the point.
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
        flashStatus._t = setTimeout(() => { if (globalStatus.textContent === text) globalStatus.textContent = ''; }, 2500);
    }

    // Rewrites every derived (read-only) figure in one column's card from a
    // freshly computed payload — same shape ProjectionCalculator::forColumn()
    // returns, so both updateColumn() and updateRates() responses work here
    // unchanged. P&L row $ values now live in <input> elements (explicit
    // request, 2026-09-23: "the number are editable not the percentage"),
    // so those are updated via .value, not .textContent — and only when
    // NOT the focused element, so a save triggered by one row's own edit
    // never clobbers what the user is still typing in it.
    function applyComputed(card, computed) {
        const t = computed.target_card, p = computed.pnl;

        card.querySelectorAll('[data-out]').forEach((el) => {
            const key = el.dataset.out;
            if (!(key in t)) return;
            const decimals = (key === 'orders_needed' && t[key] < 100) || key === 'upselling_rate' ? 2 : 0;
            el.textContent = fmtNumber(t[key], decimals);
        });

        // Only Opening Shift's P&L rows are real <input>s (explicit
        // decision, 2026-09-23, after the cross-card formula chain was
        // found: the other 3 cards' rows are read-only <span>s, since
        // they're pure =F39/6-style formulas in the real sheet, not
        // independent cells) — this one helper handles both shapes by
        // checking the element's tag, so applyComputed() doesn't need to
        // know which card it's refreshing.
        const setValue = (selector, val, isPct = false) => {
            const el = card.querySelector(selector);
            if (!el) return;
            const text = isPct ? fmtPct(val) : fmtMoney(val);
            if (el.tagName === 'INPUT') {
                if (document.activeElement !== el) el.value = text;
            } else {
                el.textContent = text;
            }
        };
        const setPct = (rateKey, val) => {
            const el = card.querySelector(`[data-rate-pct="${rateKey}"]`);
            if (el) el.textContent = fmtPct(val / p.gross_sales);
        };

        setValue('[data-orders="1"]', p.orders);
        setValue('[data-gross-sales="1"]', p.gross_sales);
        setValue('[data-pnl-input="cancelled"], [data-pnl="cancelled"]', p.cancelled);
        setPct('cancelled', p.cancelled);
        setValue('[data-pnl-input="returns"], [data-pnl="returns"]', p.returns);
        setPct('returns', p.returns);
        setValue('[data-pnl-input="delivered"], [data-pnl="delivered"]', p.delivered);
        setPct('delivered', p.delivered);
        setValue('[data-pnl-input="tax_allocation"], [data-pnl="tax_allocation"]', p.tax_allocation);
        setPct('tax_allocation', p.tax_allocation);
        setValue('[data-pnl-input="product_cost"], [data-pnl="product_cost"]', p.product_cost);
        setPct('product_cost', p.product_cost);
        setValue('[data-pnl="gross_profit"]', p.gross_profit);
        setValue('[data-pnl="gross_profit_pct"]', p.gross_profit_pct, true);
        setValue('[data-pnl="total_selling_costs"]', p.total_selling_costs);
        setValue('[data-pnl="total_selling_costs_pct"]', p.total_selling_costs_pct, true);
        setValue('[data-pnl="total_operating_costs"]', p.total_operating_costs);
        setValue('[data-pnl="total_operating_costs_pct"]', p.total_operating_costs_pct, true);
        setValue('[data-pnl="net_income"]', p.net_income);
        setValue('[data-pnl="net_income_pct"]', p.net_income_pct, true);

        Object.entries(p.selling_lines || {}).forEach(([key, val]) => {
            setValue(`[data-line-input="${key}"], [data-line="${key}"]`, val);
            setPct(key, val);
        });
        Object.entries(p.operating_lines || {}).forEach(([key, val]) => {
            setValue(`[data-line-input="${key}"], [data-line="${key}"]`, val);
            setPct(key, val);
        });
    }

    // Rate inputs exist once PER COLUMN (each column shows every row's own
    // $ or %), but they all share the same underlying Setting — after any
    // rate save, every OTHER column's matching input must be resynced to
    // the authoritative saved fraction (converted back to THAT column's
    // own $ amount for dollar-mode inputs, since the same fraction means a
    // different dollar figure in each column's own Gross Sales), not just
    // this one. This is the fix for the corruption seen earlier: inputs
    // were never resynced after save, so a stale on-screen value could get
    // edited again and re-submitted on top of an already-changed server
    // value.
    function syncRateInputs(key, fraction, computedByKey) {
        columnsEl.querySelectorAll(`.pj-rate-field[data-rate="${key}"]`).forEach((el) => {
            if (document.activeElement === el) return;
            if (el.dataset.mode === 'dollar') {
                const card = el.closest('.pj-card');
                const grossSales = computedByKey?.[card?.dataset.key]?.pnl?.gross_sales;
                if (grossSales) el.value = fmtMoney(fraction * grossSales);
            } else {
                el.value = Math.round(fraction * 100 * 10000) / 10000;
            }
        });
    }

    // --- Column inputs (label, net_income_target, average_order_value, tsa_count) ---
    function saveColumnField(input) {
        const card = input.closest('.pj-card');
        const status = card?.querySelector('.pj-card-status');
        if (!card) return;

        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);
        if (status) status.textContent = 'Saving…';

        const field = input.dataset.field;
        const typed = input.dataset.money === '1' ? parseMoney(input.value) : input.value;

        // Gross Sales and # of Orders are two views of the SAME stored
        // field (orders_override — explicit request, 2026-09-23: "the
        // Gross Sales is editable and the number of orders"): # of Orders
        // saves the typed number as-is, Gross Sales back-solves it to
        // orders first (÷ this column's own Average Order Value) before
        // saving, since the server only ever stores orders_override, never
        // a separate gross-sales override.
        let sendValue = typed;
        if (input.dataset.grossSales === '1') {
            const aovInput = card.querySelector('[data-field="average_order_value"]');
            const aov = aovInput ? parseMoney(aovInput.value) : 0;
            if (!aov) { if (status) status.textContent = 'Failed'; return; }
            sendValue = typed / aov;
        }

        const body = new URLSearchParams();
        body.set(field, sendValue);
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
                if (status) {
                    status.textContent = 'Saved';
                    setTimeout(() => { if (status.textContent === 'Saved') status.textContent = ''; }, 1500);
                }
                if (input.dataset.money === '1' && document.activeElement !== input) {
                    input.value = fmtMoney(typed);
                }
                // Editing Opening Shift's own Orders/Net Income Target/AOV
                // cascades into the other 3 DERIVED cards (×2/÷6/÷24 — see
                // ProjectionCalculator's own doc comment), so every
                // column's fresh figures come back as `all`, not just this
                // one — apply every one of them, not only `computed`
                // (this card's own), or the other 3 cards stay stale until
                // a manual reload (explicit request, 2026-09-23: "no need
                // to reload the page to see the updated").
                if (Array.isArray(data?.all)) {
                    data.all.forEach((entry) => {
                        const entryCard = columnsEl.querySelector(`.pj-card[data-key="${entry.column.key}"]`);
                        if (entryCard) applyComputed(entryCard, entry);
                    });
                } else if (data?.computed) {
                    applyComputed(card, data.computed);
                }
            })
            .catch(() => {
                if (status) status.textContent = 'Failed';
                window.showToast?.('Could not save — try again.', 'error');
            });
    }

    // --- Rate inputs. Two flavors share this one PATCH endpoint (the
    // underlying Setting is always a fraction, 0.05 = 5%): the target-card
    // inputs (target_margin/conversion_rate/pickup_rate) are typed as
    // whole percents, unchanged; the P&L row inputs (data-mode="dollar",
    // explicit request 2026-09-23: "the number are editable not the
    // percentage") are typed as a dollar amount and back-solved into a
    // fraction using THIS row's own column's current Gross Sales before
    // sending — editing $500 of Cancelled on a column with 3,840,000
    // Gross Sales saves rate 500/3840000, same as if 0.013% had been
    // typed directly. ---
    function saveRate(input) {
        clearTimeout(saveTimers.get(input));
        saveTimers.delete(input);
        const key = input.dataset.rate;
        const isDollar = input.dataset.mode === 'dollar';
        const typed = isDollar ? parseMoney(input.value) : Number(input.value);
        if (Number.isNaN(typed)) return;

        let fraction;
        if (isDollar) {
            // Every rate is shared, but only the 2 BASE shifts (Opening
            // and, since 2026-09-23, Closing — "okay now in the downpart
            // is the closing team") are actually computed from Orders ×
            // AOV × rate — every other card is DERIVED from one of them
            // (×2/sum / ÷tsaCount / ÷24 — see ProjectionCalculator's own
            // doc comment, root-caused 2026-09-23 from the user's own
            // sheet screenshot showing "=F39/6"). Dollar-mode rows are
            // only ever rendered as <input>s on those 2 base cards (see
            // _column.blade.php's own $editable), so back-solving against
            // THIS input's own card's Gross Sales is always correct here
            // — Opening's edit uses Opening's own Gross Sales, Closing's
            // uses Closing's own, never the other shift's (an earlier bug
            // this session hardcoded Opening's card unconditionally,
            // which was fine before Closing existed but would have been
            // wrong the moment Closing became independently editable).
            const ownCard = input.closest('.pj-card');
            const grossSalesEl = ownCard?.querySelector('[data-gross-sales="1"]');
            const grossSales = grossSalesEl ? parseMoney(grossSalesEl.value) : 0;
            if (!grossSales) return;
            fraction = typed / grossSales;
        } else {
            fraction = typed / 100;
        }

        fetch('{{ route('data.projections.update-rates') }}', {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: `key=${encodeURIComponent(key)}&value=${encodeURIComponent(fraction)}`,
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then((data) => {
                flashStatus('Saved — recalculated', false);
                const computedByKey = {};
                if (Array.isArray(data?.computed)) {
                    data.computed.forEach((computed) => { computedByKey[computed.column.key] = computed; });
                }
                if (data?.rates && key in data.rates) syncRateInputs(key, data.rates[key], computedByKey);
                if (Array.isArray(data?.computed)) {
                    data.computed.forEach((computed) => {
                        const card = columnsEl.querySelector(`.pj-card[data-key="${computed.column.key}"]`);
                        if (card) applyComputed(card, computed);
                    });
                }
            })
            .catch(() => {
                flashStatus('Could not save — try again.', true);
                window.showToast?.('Could not save this rate — try again.', 'error');
            });
    }

    columnsEl.addEventListener('input', (e) => {
        const field = e.target.closest('.pj-field');
        const rate = e.target.closest('.pj-rate-field');
        const input = field || rate;
        if (!input) return;

        // Live comma-formatting as the user types (explicit follow-up,
        // 2026-09-23: "auto comma even when typing not just when it is
        // saved") — every money-flavored input (data-money="1" pj-fields,
        // data-mode="dollar" pj-rate-fields), not just after a save
        // settles.
        if (input.dataset.money === '1' || input.dataset.mode === 'dollar') {
            liveFormatMoney(input);
        }

        if (field) {
            const status = field.closest('.pj-card')?.querySelector('.pj-card-status');
            if (status) status.textContent = '';
        }

        clearTimeout(saveTimers.get(input));
        saveTimers.set(input, setTimeout(() => (field ? saveColumnField(field) : saveRate(rate)), 600));
    });

    // capture phase — 'blur' doesn't bubble, delegation needs it. Guards
    // against double-submitting a value already flushed by the debounce
    // timer a moment earlier (the timer is cleared/deleted inside each
    // save*() call, so a stale blur firing right after finds nothing
    // pending and safely re-sends the CURRENT input.value instead of
    // racing an in-flight request with different data).
    columnsEl.addEventListener('blur', (e) => {
        const field = e.target.closest('.pj-field');
        const rate = e.target.closest('.pj-rate-field');
        if (field) saveColumnField(field);
        else if (rate) saveRate(rate);
    }, true);

    columnsEl.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const input = e.target.closest('.pj-field, .pj-rate-field');
        if (input) { e.preventDefault(); input.blur(); }
    });
})();
</script>
@endpush

@endsection
