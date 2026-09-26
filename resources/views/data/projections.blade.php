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
    // 3x2 grid — Shift/Individual Monthly/Individual Daily columns,
    // Opening/Closing rows (explicit request, 2026-09-24, per a hand-drawn
    // layout diagram) — Telesales Department pulled OUT of this grid
    // entirely and rendered separately, floated to the right and
    // vertically centered against the whole 2-row block.
    $openingRowKeys = ['opening_shift', 'opening_individual_tsa_monthly', 'opening_individual_tsa_daily'];
    $closingRowKeys = ['closing_shift', 'closing_individual_tsa_monthly', 'closing_individual_tsa_daily'];
    $openingRow = $computed->whereIn('column.key', $openingRowKeys)->sortBy(fn ($e) => array_search($e['column']->key, $openingRowKeys));
    $closingRow = $computed->whereIn('column.key', $closingRowKeys)->sortBy(fn ($e) => array_search($e['column']->key, $closingRowKeys));
    $telesalesEntry = $computed->firstWhere('column.key', 'telesales_department');
@endphp

{{-- Left: a real 3-column x 2-row CSS grid (not two separate scrolling
     rows anymore) so Opening Shift sits directly above Closing Shift,
     Individual Monthly above Individual Monthly, etc. Right: Telesales
     Department alone, centered vertically against that whole grid via
     the flex row's own items-center (explicit request, 2026-09-24: "the
     Telesales Department should be the one in the right side," after a
     hand-drawn diagram showing 6 cards in a 2x3 block plus one card
     floated right and vertically centered). Each side keeps its own
     independent horizontal scroll on narrow screens instead of
     squeezing together. #pjColumns wraps everything — the JS only ever
     looks up a specific card by its own data-key, so it doesn't care
     where a card visually sits. --}}
<div id="pjColumns" class="flex flex-col lg:flex-row items-stretch lg:items-center gap-6">
    <div class="flex-1 min-w-0 isolate overflow-x-auto -mx-4 md:-mx-8 px-4 md:px-8 pb-2">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 lg:w-max">
            @foreach($openingRow as $entry)
                @include('data.projections._column', ['entry' => $entry])
            @endforeach
            @foreach($closingRow as $entry)
                @include('data.projections._column', ['entry' => $entry])
            @endforeach
        </div>
    </div>

    {{-- Same natural card width as the other 6 (no more hardcoded 300px,
         explicit request 2026-09-24: "the card of Telesales Department is
         same as other cards") — shrink-0 keeps it from being squeezed by
         the left grid's own flex-1, so it never overlaps that grid's
         independent horizontal scroll (its own explicit follow-up:
         "Telesales Department is fixed like other cards" while scrolling). --}}
    @if($telesalesEntry)
    <div class="shrink-0 w-full lg:w-[22rem]">
        @include('data.projections._column', ['entry' => $telesalesEntry])
    </div>
    @endif
</div>

{{-- Add-custom-row modal (explicit request, 2026-09-26: "add like + icon
     on Selling And Marketing and Operating Costs ... has modal ... if it
     is editable or fixed") — ONE shared modal for every card's own + icon,
     not one per card, since only Opening/Closing Shift ever show the icon
     in the first place. #pjAddRowModal starts hidden; pj.js's own
     openAddRowModal()/closeAddRowModal() toggle the [hidden] attribute. --}}
<div id="pjAddRowModal" hidden class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50" data-close-add-row-modal></div>
    <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-sm p-6 font-mono">
        <h3 class="text-sm font-bold uppercase tracking-wide text-ink dark:text-slate-100 mb-4">Add Row</h3>
        <form id="pjAddRowForm" class="space-y-4">
            <div>
                <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Row Name</label>
                <input type="text" name="label" required maxlength="255"
                       class="w-full text-sm border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
            <div>
                <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Starting Amount (optional)</label>
                <input type="text" inputmode="decimal" name="initial_value" placeholder="0.00"
                       class="w-full text-sm text-right border border-line dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary/40">
            </div>
            <div>
                <label class="block text-[11px] font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-2">Type</label>
                <div class="flex gap-4 text-sm text-ink dark:text-slate-100">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" name="is_fixed" value="0" checked class="text-primary focus:ring-primary/40">
                        Editable
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" name="is_fixed" value="1" class="text-primary focus:ring-primary/40">
                        Fixed
                    </label>
                </div>
                <p class="text-xs text-ink-muted dark:text-slate-400 mt-1.5">Editable rows can be changed later on Opening/Closing Shift. Fixed rows only change here.</p>
            </div>
            <p id="pjAddRowError" class="text-xs text-red-600 dark:text-red-400 hidden"></p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" data-close-add-row-modal class="text-sm px-4 py-2 rounded-lg border border-line dark:border-slate-600 text-ink dark:text-slate-100">Cancel</button>
                <button type="submit" class="text-sm px-4 py-2 rounded-lg bg-primary text-white font-semibold">Add Row</button>
            </div>
        </form>
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

        // Target Upselling Rate / Pick-up Rate live in the target card, not
        // the P&L table, so they're not plain $ values — reuse setValue's
        // input-vs-span handling but format as a plain number, not money.
        const upsellEl = card.querySelector('[data-field="upselling_rate_override"]');
        if (upsellEl && document.activeElement !== upsellEl) upsellEl.value = fmtNumber(t.upselling_rate, 2);
        const pickupEl = card.querySelector('[data-rate="pickup_rate"][data-mode="leads"]');
        if (pickupEl && document.activeElement !== pickupEl) pickupEl.value = fmtNumber(t.pickup_rate, 0);

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
            } else if (el.dataset.mode === 'leads') {
                // Target Pick-up Rate's displayed value is Leads Needed ×
                // this fraction — resync against THIS column's own Leads
                // Needed, same per-column back-solve convention as
                // data-mode="dollar" above uses Gross Sales.
                const card = el.closest('.pj-card');
                const leadsNeeded = computedByKey?.[card?.dataset.key]?.target_card?.leads_needed;
                if (leadsNeeded != null) el.value = fmtNumber(fraction * leadsNeeded, 0);
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
        const isLeads = input.dataset.mode === 'leads';
        const typed = isDollar ? parseMoney(input.value) : Number(input.value);
        if (Number.isNaN(typed)) return;

        let fraction;
        if (isLeads) {
            // Target Pick-up Rate's displayed value is Leads Needed × this
            // fraction — back-solve against THIS column's own Leads Needed
            // (already on-screen, read-only), same convention as the
            // dollar-mode branch below uses Gross Sales.
            const ownCard = input.closest('.pj-card');
            const leadsNeededEl = ownCard?.querySelector('[data-out="leads_needed"]');
            const leadsNeeded = leadsNeededEl ? Number(leadsNeededEl.textContent.replace(/,/g, '')) : 0;
            if (!leadsNeeded) return;
            fraction = typed / leadsNeeded;
        } else if (isDollar) {
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

    // --- Add/remove custom rows (explicit request, 2026-09-26: "add like
    // + icon on Selling And Marketing and Operating Costs ... has modal
    // ... if it is editable or fixed"). A new/removed row changes every
    // card's own row LIST, not just a value — applyComputed() above only
    // ever updates existing elements' text, so both actions reload the
    // page on success rather than trying to inject new row markup into
    // every card's own DOM by hand. ---
    const addRowModal = document.getElementById('pjAddRowModal');
    const addRowForm = document.getElementById('pjAddRowForm');
    const addRowError = document.getElementById('pjAddRowError');
    let addRowContext = null; // { section, card } — set when + is clicked.

    function openAddRowModal(section, card) {
        addRowContext = { section, card };
        addRowError.classList.add('hidden');
        addRowForm.reset();
        addRowModal.hidden = false;
        addRowForm.querySelector('[name="label"]').focus();
    }
    function closeAddRowModal() {
        addRowModal.hidden = true;
        addRowContext = null;
    }

    columnsEl.addEventListener('click', (e) => {
        const addBtn = e.target.closest('[data-add-custom-row]');
        if (addBtn) {
            openAddRowModal(addBtn.dataset.addCustomRow, addBtn.closest('.pj-card'));
            return;
        }

        const removeBtn = e.target.closest('[data-remove-custom-row]');
        if (removeBtn) {
            if (!window.confirm('Remove this row from every card?')) return;
            const rowId = removeBtn.dataset.removeCustomRow;
            fetch(`{{ url('/data/projections/custom-rows') }}/${rowId}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then(() => window.location.reload())
                .catch(() => window.showToast?.('Could not remove this row — try again.', 'error'));
        }
    });

    addRowModal.querySelectorAll('[data-close-add-row-modal]').forEach((el) => {
        el.addEventListener('click', closeAddRowModal);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !addRowModal.hidden) closeAddRowModal();
    });

    addRowForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!addRowContext) return;

        const label = addRowForm.querySelector('[name="label"]').value.trim();
        if (!label) return;
        const isFixed = addRowForm.querySelector('[name="is_fixed"]:checked').value;
        const initialValue = parseMoney(addRowForm.querySelector('[name="initial_value"]').value);
        const grossSalesEl = addRowContext.card?.querySelector('[data-gross-sales="1"]');
        const grossSales = grossSalesEl ? parseMoney(grossSalesEl.value ?? grossSalesEl.textContent) : 0;

        const submitBtn = addRowForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;

        const body = new URLSearchParams();
        body.set('section', addRowContext.section);
        body.set('label', label);
        body.set('is_fixed', isFixed);
        body.set('initial_value', initialValue);
        body.set('gross_sales', grossSales);

        fetch('{{ route('data.projections.custom-rows.store') }}', {
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
                addRowError.textContent = data?.message || 'Could not add this row — try again.';
                addRowError.classList.remove('hidden');
            });
    });
})();
</script>
@endpush

@endsection
