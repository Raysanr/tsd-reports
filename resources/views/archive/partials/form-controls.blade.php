{{-- Designed dropdowns and date pickers for every Archiving System page and
     modal (explicit request, 2026-10-22: "make all of the selection has
     design and even the date … if there's like that in the other pages do
     it too").

     Progressive enhancement: each native <select> / <input type="date">
     stays in the form (visually hidden, still submitted and validated) and
     gets a styled trigger beside it. The trigger copies the field's own
     classes, so it looks like the input it replaces. Picking a value sets
     the native field and fires input + change, so every existing handler
     (onchange submit, modal fill, page JS) keeps working. Values set from
     code (el.value = …, form.reset()) are mirrored back onto the trigger.

     Opt out with data-native. The People manpower table has its own
     controls and is skipped. Both popovers use the Popover API so they sit
     above open <dialog> modals. --}}
<style>
    .arc-native { position: absolute !important; left: 0; bottom: 0; width: 1px !important; height: 1px !important; opacity: 0 !important; pointer-events: none !important; }
    #arcSelectPop, #arcCalPop { margin: 0; inset: auto; padding: 0; border: 0; background: transparent; overflow: visible; }
    #arcSelectPop .arc-card, #arcCalPop .arc-card { transform-origin: top; transition: opacity .14s ease-out, transform .14s ease-out; }
    #arcSelectPop[data-closing] .arc-card, #arcCalPop[data-closing] .arc-card,
    #arcSelectPop:not(:popover-open) .arc-card, #arcCalPop:not(:popover-open) .arc-card { opacity: 0; transform: translateY(-4px) scale(.98); }
    @media (prefers-reduced-motion: reduce) { #arcSelectPop .arc-card, #arcCalPop .arc-card { transition: none; } }
</style>

<div id="arcSelectPop" popover="manual">
    <div class="arc-card flex flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-slate-900 dark:ring-white/10">
        <div data-search-wrap class="hidden border-b border-line p-2 dark:border-slate-800">
            <div class="relative">
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <input data-search type="text" autocomplete="off" placeholder="Search…" class="w-full rounded-lg border-0 bg-slate-50 py-1.5 pl-8 pr-2 text-sm text-ink placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-primary/50 dark:bg-slate-800 dark:text-slate-100">
            </div>
        </div>
        <ul data-list role="listbox" class="max-h-72 overflow-y-auto p-1 text-sm"></ul>
    </div>
</div>

<div id="arcCalPop" popover="manual" aria-label="Pick a date">
    <div class="arc-card w-72 rounded-2xl bg-white p-3 shadow-2xl ring-1 ring-black/5 dark:bg-slate-900 dark:ring-white/10">
        <div class="mb-2 flex items-center justify-between gap-1">
            <button type="button" data-prev class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Previous month"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg></button>
            <div class="flex items-center gap-1">
                <select data-native data-month class="rounded-lg border-0 bg-slate-50 py-1 pl-2 pr-7 text-sm font-semibold focus:ring-2 focus:ring-primary/50 dark:bg-slate-800 dark:text-slate-100 cursor-pointer"></select>
                <select data-native data-year class="rounded-lg border-0 bg-slate-50 py-1 pl-2 pr-7 text-sm font-semibold focus:ring-2 focus:ring-primary/50 dark:bg-slate-800 dark:text-slate-100 cursor-pointer"></select>
            </div>
            <button type="button" data-next class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Next month"><svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></button>
        </div>
        <div class="grid grid-cols-7 text-center text-[10px] font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-500">
            <span class="py-1">Su</span><span class="py-1">Mo</span><span class="py-1">Tu</span><span class="py-1">We</span><span class="py-1">Th</span><span class="py-1">Fr</span><span class="py-1">Sa</span>
        </div>
        <div data-days class="grid grid-cols-7 gap-0.5"></div>
        <div class="mt-2 flex items-center justify-between border-t border-line pt-2 dark:border-slate-800">
            <button type="button" data-clear class="rounded-lg px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 cursor-pointer">Clear</button>
            <button type="button" data-today class="rounded-lg px-2.5 py-1 text-xs font-semibold text-primary hover:bg-yellow-50 dark:text-yellow-400 dark:hover:bg-yellow-500/10 cursor-pointer">Today</button>
        </div>
    </div>
</div>

<script>
(function () {
    if (!HTMLElement.prototype.showPopover) return; // very old browser: keep the native controls

    const CHEVRON = '<svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform" data-chevron fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>';
    const CAL = '<svg class="h-4 w-4 shrink-0 text-primary dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
    const CHECK = '<svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>';
    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const SHORT = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Oct.', 'Nov.', 'Dec.'];
    const pad = (n) => String(n).padStart(2, '0');
    const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const parse = (s) => { if (!/^\d{4}-\d{2}-\d{2}$/.test(s || '')) return null; const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
    const pretty = (s) => { const d = parse(s); return d ? `${SHORT[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}` : ''; };
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const skip = (el) => el.hasAttribute('data-native') || el.multiple || el.closest('#mpCal, #arcCalPop, #arcSelectPop') || el.dataset.arcEnhanced
        || (el.closest('[data-manpower]') && el.tagName !== 'SELECT');
    // "Pill" selects (options carry data-pill-class, e.g. People's Status /
    // Shift schedule): the trigger and every list row are colored chips.
    const NEUTRAL_PILL = 'bg-slate-100 text-ink-muted dark:bg-slate-800 dark:text-slate-300';
    const PILL = 'inline-flex w-full items-center justify-center gap-1 rounded-full py-1 pl-3 pr-2 text-[11px] font-semibold uppercase tracking-wide whitespace-nowrap cursor-pointer transition hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-primary/50';
    const isPill = (sel) => [...sel.options].some((o) => o.dataset.pillClass);

    /* Mirror code-set values (el.value = x) onto the trigger. */
    function watchValue(el, refresh) {
        const desc = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value');
        Object.defineProperty(el, 'value', { configurable: true, get() { return desc.get.call(this); }, set(v) { desc.set.call(this, v); refresh(); } });
    }

    function wrap(el, trigger) {
        const box = document.createElement('div');
        box.className = 'relative';
        el.parentNode.insertBefore(box, el);
        box.append(el, trigger);
        el.classList.add('arc-native');
        el.tabIndex = -1;
        el.dataset.arcEnhanced = '1';
        el._arcTrigger = trigger;
        trigger._arcNative = el;
        el.addEventListener('invalid', () => trigger.classList.add('!ring-2', '!ring-rose-400'));
        el.addEventListener('change', () => { trigger.classList.remove('!ring-2', '!ring-rose-400'); el._arcRefresh(); });
    }
    function makeTrigger(el, extra) {
        const t = document.createElement('button');
        t.type = 'button';
        t.className = (el.className || '') + ' ' + extra;
        if (el.id) t.setAttribute('aria-labelledby', el.id + '__label');
        if (el.disabled) t.disabled = true;
        return t;
    }

    // ---------------- Select ----------------
    const pop = document.getElementById('arcSelectPop');
    const list = pop.querySelector('[data-list]');
    const searchWrap = pop.querySelector('[data-search-wrap]');
    const search = pop.querySelector('[data-search]');
    let openSel = null, active = -1;

    function enhanceSelect(sel) {
        const pill = isPill(sel);
        const t = pill ? document.createElement('button') : makeTrigger(sel, 'arc-trigger flex items-center justify-between gap-2 text-left cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed');
        if (pill) { t.type = 'button'; t.setAttribute('aria-label', sel.getAttribute('aria-label') || ''); sel._arcPill = true; }
        t.setAttribute('aria-haspopup', 'listbox');
        t.setAttribute('aria-expanded', 'false');
        sel._arcRefresh = () => {
            const o = sel.selectedOptions[0];
            const placeholder = !o || o.value === '';
            if (pill) {
                t.className = `${PILL} ${o?.dataset.pillClass || NEUTRAL_PILL}`;
                t.innerHTML = `<span class="truncate">${esc(o ? o.textContent.trim() : '')}</span>${CHEVRON.replace('h-4 w-4', 'h-3 w-3').replace('text-ink-muted', 'opacity-70')}`;
                return;
            }
            t.innerHTML = `<span class="min-w-0 flex-1 truncate ${placeholder ? 'text-ink-muted dark:text-slate-400' : ''}">${esc(o ? o.textContent.trim() : '')}</span>${CHEVRON}`;
        };
        watchValue(sel, () => sel._arcRefresh());
        wrap(sel, t);
        sel._arcRefresh();
        t.addEventListener('click', () => (openSel === sel ? closeSelect() : openSelect(sel)));
        t.addEventListener('keydown', (e) => {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); openSelect(sel); }
        });
    }

    function options(sel) {
        return [...sel.options].filter((o) => !o.hidden).map((o) => ({ o, value: o.value, label: o.textContent.trim(), disabled: o.disabled, group: o.parentElement.tagName === 'OPTGROUP' ? o.parentElement.label : null }));
    }
    function renderList(q = '') {
        const sel = openSel;
        const needle = q.trim().toLowerCase();
        const items = options(sel).filter((i) => !needle || i.label.toLowerCase().includes(needle));
        let lastGroup = null, html = '';
        items.forEach((i, idx) => {
            if (i.group && i.group !== lastGroup) { html += `<li class="px-2.5 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-ink-muted">${esc(i.group)}</li>`; lastGroup = i.group; }
            const selected = i.value === sel.value;
            const label = sel._arcPill
                ? `<span class="min-w-0 flex-1"><span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ${i.o.dataset.pillClass || NEUTRAL_PILL}">${esc(i.label)}</span></span>`
                : `<span class="min-w-0 flex-1 truncate">${esc(i.label)}</span>`;
            html += `<li role="option" data-idx="${idx}" data-value="${esc(i.value)}" aria-selected="${selected}" class="flex items-center gap-2 rounded-lg px-2.5 py-2 ${i.disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'} ${selected ? 'font-semibold text-ink dark:text-white' : 'text-ink dark:text-slate-200'} ${i.value === '' ? 'text-ink-muted dark:text-slate-400' : ''}" ${i.disabled ? 'data-disabled' : ''}>
                ${label}
                <span class="text-primary dark:text-yellow-400 ${selected ? '' : 'invisible'}">${CHECK}</span></li>`;
        });
        list.innerHTML = html || '<li class="px-3 py-6 text-center text-xs text-ink-muted">No matches</li>';
        const rows = [...list.querySelectorAll('[role=option]')];
        active = Math.max(0, rows.findIndex((r) => r.getAttribute('aria-selected') === 'true'));
        highlight();
    }
    function highlight() {
        const rows = [...list.querySelectorAll('[role=option]')];
        rows.forEach((r, i) => r.classList.toggle('bg-yellow-50', i === active));
        rows.forEach((r, i) => r.classList.toggle('dark:bg-slate-800', i === active));
        rows[active]?.scrollIntoView({ block: 'nearest' });
    }
    // An open modal <dialog> makes everything outside it unclickable, so the
    // list/calendar must live INSIDE the dialog its field is in (it's still
    // a top-layer popover there, drawn above the modal's content).
    function hostFor(popEl, trigger) {
        const host = trigger.closest('dialog') || document.body;
        if (popEl.parentElement !== host) host.appendChild(popEl);
    }
    function placeNear(popEl, trigger, minWidth) {
        const r = trigger.getBoundingClientRect();
        const card = popEl.firstElementChild;
        const width = Math.max(r.width, minWidth);
        if (minWidth) card.style.width = width + 'px';
        const h = card.offsetHeight, w = card.offsetWidth;
        const below = window.innerHeight - r.bottom - 8;
        const top = (h > below && r.top > below) ? Math.max(8, r.top - h - 6) : r.bottom + 6;
        popEl.style.top = top + 'px';
        popEl.style.left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8) + 'px';
    }
    function openSelect(sel) {
        closeCal();
        if (openSel) closeSelect(false);
        openSel = sel;
        const t = sel._arcTrigger;
        t.setAttribute('aria-expanded', 'true');
        t.querySelector('[data-chevron]')?.classList.add('rotate-180');
        const many = sel.options.length > 8;
        searchWrap.classList.toggle('hidden', !many);
        search.value = '';
        renderList();
        pop.removeAttribute('data-closing');
        if (pop.matches(':popover-open')) pop.hidePopover();
        hostFor(pop, t);
        pop.showPopover();
        placeNear(pop, t, 200);
        if (many) search.focus(); else list.focus?.();
    }
    function closeSelect(refocus = true) {
        if (!openSel) return;
        const t = openSel._arcTrigger;
        t.setAttribute('aria-expanded', 'false');
        t.querySelector('[data-chevron]')?.classList.remove('rotate-180');
        openSel = null;
        pop.setAttribute('data-closing', '');
        setTimeout(() => { if (!openSel) pop.hidePopover(); }, 120);
        if (refocus) t.focus();
    }
    function choose(value) {
        const sel = openSel;
        if (!sel) return;
        const changed = sel.value !== value;
        sel.value = value;
        closeSelect();
        if (changed) {
            sel.dispatchEvent(new Event('input', { bubbles: true }));
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    list.addEventListener('click', (e) => {
        const row = e.target.closest('[role=option]');
        if (row && !row.hasAttribute('data-disabled')) choose(row.dataset.value);
    });
    list.addEventListener('mousemove', (e) => {
        const row = e.target.closest('[role=option]');
        if (!row) return;
        const idx = [...list.querySelectorAll('[role=option]')].indexOf(row);
        if (idx !== active) { active = idx; highlight(); }
    });
    search.addEventListener('input', () => renderList(search.value));

    // ---------------- Date ----------------
    const cal = document.getElementById('arcCalPop');
    const monthSel = cal.querySelector('[data-month]');
    const yearSel = cal.querySelector('[data-year]');
    const daysEl = cal.querySelector('[data-days]');
    const thisYear = new Date().getFullYear();
    MONTHS.forEach((m, i) => monthSel.add(new Option(m, i)));
    for (let y = thisYear + 5; y >= 1950; y--) yearSel.add(new Option(y, y));
    let openDate = null, view = new Date();

    function enhanceDate(inp) {
        const t = makeTrigger(inp, 'arc-trigger flex items-center gap-2 text-left cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed');
        t.setAttribute('aria-haspopup', 'dialog');
        inp._arcRefresh = () => {
            const label = pretty(inp.value);
            t.innerHTML = `${CAL}<span class="min-w-0 flex-1 truncate ${label ? '' : 'text-ink-muted dark:text-slate-400'}">${label || 'Select date'}</span>`;
        };
        watchValue(inp, () => inp._arcRefresh());
        wrap(inp, t);
        inp._arcRefresh();
        t.addEventListener('click', () => (openDate === inp ? closeCal() : openCalFor(inp)));
        t.addEventListener('keydown', (e) => {
            if (['ArrowDown', 'Enter', ' '].includes(e.key)) { e.preventDefault(); openCalFor(inp); }
        });
    }
    function renderCal() {
        monthSel.value = view.getMonth();
        yearSel.value = view.getFullYear();
        const first = new Date(view.getFullYear(), view.getMonth(), 1);
        const start = new Date(first); start.setDate(1 - first.getDay());
        const sel = openDate?.value || '', today = iso(new Date());
        const min = openDate?.min || '', max = openDate?.max || '';
        daysEl.innerHTML = '';
        for (let i = 0; i < 42; i++) {
            const d = new Date(start); d.setDate(start.getDate() + i);
            const v = iso(d), inMonth = d.getMonth() === view.getMonth();
            const off = (min && v < min) || (max && v > max);
            const b = document.createElement('button');
            b.type = 'button';
            b.dataset.day = v;
            b.textContent = d.getDate();
            b.disabled = !!off;
            b.className = 'h-8 rounded-lg text-xs tabular-nums transition '
                + (off ? 'text-slate-300 dark:text-slate-700 cursor-not-allowed '
                    : (v === sel ? 'bg-primary text-white font-semibold shadow-sm cursor-pointer '
                        : (v === today ? 'ring-1 ring-inset ring-primary/60 font-semibold ' : '')
                        + (inMonth ? 'text-ink hover:bg-yellow-50 dark:text-slate-100 dark:hover:bg-slate-800 cursor-pointer ' : 'text-slate-300 hover:bg-slate-50 dark:text-slate-600 dark:hover:bg-slate-800/60 cursor-pointer ')));
            daysEl.appendChild(b);
        }
    }
    function openCalFor(inp) {
        closeSelect(false);
        openDate = inp;
        view = parse(inp.value) || new Date();
        renderCal();
        cal.removeAttribute('data-closing');
        if (cal.matches(':popover-open')) cal.hidePopover();
        hostFor(cal, inp._arcTrigger);
        cal.showPopover();
        placeNear(cal, inp._arcTrigger, 0);
    }
    function closeCal(refocus = false) {
        if (!openDate) return;
        const t = openDate._arcTrigger;
        openDate = null;
        cal.setAttribute('data-closing', '');
        setTimeout(() => { if (!openDate) cal.hidePopover(); }, 120);
        if (refocus) t.focus();
    }
    function pickDate(value) {
        const inp = openDate;
        if (!inp) return;
        const changed = inp.value !== value;
        inp.value = value;
        closeCal(true);
        if (changed) {
            inp.dispatchEvent(new Event('input', { bubbles: true }));
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    cal.addEventListener('click', (e) => {
        const day = e.target.closest('[data-day]');
        if (day && !day.disabled) return pickDate(day.dataset.day);
        if (e.target.closest('[data-prev]')) { view.setMonth(view.getMonth() - 1); renderCal(); }
        if (e.target.closest('[data-next]')) { view.setMonth(view.getMonth() + 1); renderCal(); }
        if (e.target.closest('[data-today]')) pickDate(iso(new Date()));
        if (e.target.closest('[data-clear]')) pickDate('');
    });
    monthSel.addEventListener('change', () => { view = new Date(view.getFullYear(), +monthSel.value, 1); renderCal(); });
    yearSel.addEventListener('change', () => { view = new Date(+yearSel.value, view.getMonth(), 1); renderCal(); });

    // ---------------- Shared ----------------
    document.addEventListener('pointerdown', (e) => {
        if (openSel && !pop.contains(e.target) && !openSel._arcTrigger.contains(e.target)) closeSelect(false);
        if (openDate && !cal.contains(e.target) && !openDate._arcTrigger.contains(e.target)) closeCal();
    }, true);
    // Esc closes the open list/calendar without also closing the modal under it.
    document.addEventListener('keydown', (e) => {
        if (openSel) {
            const rows = [...list.querySelectorAll('[role=option]')];
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeSelect(); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(rows.length - 1, active + 1); highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); highlight(); }
            else if (e.key === 'Enter') { e.preventDefault(); const r = rows[active]; if (r && !r.hasAttribute('data-disabled')) choose(r.dataset.value); }
            else if (e.key === 'Tab') closeSelect(false);
        } else if (openDate && e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeCal(true); }
    }, true);
    window.addEventListener('resize', () => { if (openSel) placeNear(pop, openSel._arcTrigger, 200); if (openDate) placeNear(cal, openDate._arcTrigger, 0); });
    document.addEventListener('scroll', (e) => {
        if (pop.contains(e.target) || cal.contains(e.target)) return;
        if (openSel) placeNear(pop, openSel._arcTrigger, 200);
        if (openDate) placeNear(cal, openDate._arcTrigger, 0);
    }, true);
    // form.reset() doesn't go through .value — refresh once it's done.
    document.addEventListener('reset', (e) => setTimeout(() => e.target.querySelectorAll('[data-arc-enhanced]').forEach((el) => el._arcRefresh?.())), true);
    // A <label for="…"> opens the designed control instead of the hidden one.
    document.addEventListener('click', (e) => {
        const label = e.target.closest('label[for]');
        const el = label && document.getElementById(label.htmlFor);
        if (el?._arcTrigger) { e.preventDefault(); el._arcTrigger.focus(); }
    });
    // Modals closing should never leave a list/calendar floating.
    document.addEventListener('close', (e) => {
        if (e.target.tagName !== 'DIALOG') return;
        closeSelect(false); closeCal();
        setTimeout(() => [pop, cal].forEach((p) => { if (e.target.contains(p)) { if (p.matches(':popover-open')) p.hidePopover(); document.body.appendChild(p); } }), 150);
    }, true);

    document.querySelectorAll('select').forEach((s) => { if (!skip(s)) enhanceSelect(s); });
    document.querySelectorAll('input[type="date"]').forEach((i) => { if (!skip(i)) enhanceDate(i); });
})();
</script>
