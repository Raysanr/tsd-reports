{{--
    Shared "From / To" date-range picker for every Data Management report
    page (DSPPR, Summary Sales Report, Expected Income) — explicit request,
    2026-09-27: "make all of the date picker in all pages ... has design,
    use tailwind css components for modern design," then a direct follow-up
    once the native browser calendar popup showed through unstyled ("why is
    it still like this") confirming a full custom widget was wanted, not
    just the closed input's own border/icon.

    Renders a plain text input (not type="date" — a native date input's
    OWN popup calendar can never be restyled via CSS on any browser, which
    is exactly the follow-up complaint) plus a custom-built dropdown
    calendar in the app's own gold/dark theme, wired up once per page by
    initDateRangeFilter() at the bottom. The real value still POSTs as a
    plain 'Y-m-d' string in the SAME $name field every page's controller
    already reads, so no backend change was needed for this redesign.

    $name (form field name), $value (current 'Y-m-d' date string), $label
    ("From" / "To") required. Renders INSIDE the page's own <form> (not
    its own form) — same "auto-submit the filter form on change" contract
    as before, now triggered by JS calling form.submit() once a day is
    picked instead of a native onchange.
--}}
<div class="date-range-field relative" data-value="{{ $value }}">
    <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">{{ $label }}</label>
    <div class="relative">
        <svg class="pointer-events-none absolute z-10 left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15A1.5 1.5 0 0121 6.75v13.5a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 20.25V6.75a1.5 1.5 0 011.5-1.5z"/>
        </svg>
        <input type="text" inputmode="none" autocomplete="off" readonly
               class="date-range-input w-36 cursor-pointer text-sm font-mono border border-line dark:border-slate-600 rounded-lg pl-9 pr-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 shadow-sm hover:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary transition-colors">
        <input type="hidden" name="{{ $name }}" value="{{ $value }}" class="date-range-hidden">
    </div>
</div>

@once
    <style>
        .date-range-calendar {
            position: absolute; z-index: 50; top: calc(100% + 6px); left: 0;
            width: 17rem; padding: 0.75rem;
            background: #fff; border: 1px solid #FEF08A; border-radius: 0.75rem;
            box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.15), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .dark .date-range-calendar { background: #1e293b; border-color: #475569; }
        .date-range-day {
            width: 2.15rem; height: 2.15rem; display: flex; align-items: center; justify-content: center;
            border-radius: 9999px; font-size: 0.8125rem; font-family: var(--font-mono);
            color: #111827; cursor: pointer;
        }
        .dark .date-range-day { color: #f1f5f9; }
        .date-range-day:hover:not(.date-range-day-muted) { background: #FEF9C3; }
        .dark .date-range-day:hover:not(.date-range-day-muted) { background: rgba(202,138,4,0.25); }
        .date-range-day-muted { color: #cbd5e1; cursor: default; }
        .dark .date-range-day-muted { color: #475569; }
        .date-range-day-today { border: 1px solid var(--color-primary); }
        .date-range-day-selected { background: var(--color-primary) !important; color: #fff !important; font-weight: 600; }
    </style>
@endonce

@push('scripts')
@once
<script>
// Shared custom calendar widget for every .date-range-field on the page
// (explicit request, 2026-09-27: a native <input type="date">'s own popup
// can't be restyled at all, so this replaces it with a plain text input +
// a hand-built dropdown calendar in the app's own gold/dark theme).
// The Blade "once" directive around this script tag guards it so it's
// only ever injected a single time even though this partial file itself
// gets included multiple times (2 per page today) into the page's own
// pushed-scripts stack.
(function () {
    function pad(n) { return String(n).padStart(2, '0'); }
    function toIso(date) { return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`; }
    function toDisplay(date) { return `${pad(date.getMonth() + 1)}/${pad(date.getDate())}/${date.getFullYear()}`; }
    function parseIso(str) {
        if (!str) return new Date();
        const [y, m, d] = str.split('-').map(Number);
        return new Date(y, m - 1, d);
    }

    function buildCalendar(field, textInput, hiddenInput) {
        let viewDate = parseIso(hiddenInput.value || toIso(new Date()));
        let panel = null;

        function render() {
            const year = viewDate.getFullYear();
            const month = viewDate.getMonth();
            const firstOfMonth = new Date(year, month, 1);
            const startWeekday = firstOfMonth.getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = toIso(new Date());
            const selected = hiddenInput.value;

            const monthLabel = viewDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

            let cells = '';
            for (let i = 0; i < startWeekday; i++) {
                const d = new Date(year, month, 1 - (startWeekday - i));
                cells += `<button type="button" class="date-range-day date-range-day-muted" data-date="${toIso(d)}">${d.getDate()}</button>`;
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const date = new Date(year, month, d);
                const iso = toIso(date);
                const classes = ['date-range-day'];
                if (iso === today) classes.push('date-range-day-today');
                if (iso === selected) classes.push('date-range-day-selected');
                cells += `<button type="button" class="${classes.join(' ')}" data-date="${iso}">${d}</button>`;
            }
            const totalCells = startWeekday + daysInMonth;
            const trailing = (7 - (totalCells % 7)) % 7;
            for (let i = 1; i <= trailing; i++) {
                cells += `<button type="button" class="date-range-day date-range-day-muted" data-date="${toIso(new Date(year, month + 1, i))}">${i}</button>`;
            }

            panel.innerHTML = `
                <div class="flex items-center justify-between mb-2 px-1">
                    <button type="button" data-nav="prev" class="w-6 h-6 flex items-center justify-center rounded-md text-ink-muted dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">&larr;</button>
                    <span class="text-xs font-mono font-bold text-ink dark:text-slate-100">${monthLabel}</span>
                    <button type="button" data-nav="next" class="w-6 h-6 flex items-center justify-center rounded-md text-ink-muted dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">&rarr;</button>
                </div>
                <div class="grid grid-cols-7 gap-0.5 mb-1">
                    ${['S','M','T','W','T','F','S'].map((d) => `<div class="text-center text-[10px] font-mono font-semibold text-ink-muted dark:text-slate-500">${d}</div>`).join('')}
                </div>
                <div class="grid grid-cols-7 gap-0.5">${cells}</div>
                <div class="flex justify-between mt-2 pt-2 border-t border-line dark:border-slate-700">
                    <button type="button" data-action="today" class="text-xs font-mono text-primary hover:underline">Today</button>
                </div>
            `;
        }

        function open() {
            if (panel) return;
            panel = document.createElement('div');
            panel.className = 'date-range-calendar';
            field.appendChild(panel);
            render();
            document.addEventListener('mousedown', onOutsideClick, true);
        }
        function close() {
            if (!panel) return;
            panel.remove();
            panel = null;
            document.removeEventListener('mousedown', onOutsideClick, true);
        }
        function onOutsideClick(e) {
            if (!field.contains(e.target)) close();
        }

        function select(iso) {
            hiddenInput.value = iso;
            textInput.value = toDisplay(parseIso(iso));
            close();
            hiddenInput.closest('form').submit();
        }

        field.addEventListener('click', (e) => {
            const navBtn = e.target.closest('[data-nav]');
            const dayBtn = e.target.closest('.date-range-day');
            const todayBtn = e.target.closest('[data-action="today"]');

            if (e.target === textInput || e.target.closest('svg')) {
                panel ? close() : open();
                return;
            }
            if (navBtn) {
                viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + (navBtn.dataset.nav === 'next' ? 1 : -1), 1);
                render();
                return;
            }
            if (dayBtn) {
                select(dayBtn.dataset.date);
                return;
            }
            if (todayBtn) {
                select(toIso(new Date()));
            }
        });

        // Seed the visible text field from whatever date the page rendered
        // with — done here (not server-side) so toDisplay()'s own MM/DD/YYYY
        // formatting stays in ONE place rather than duplicated in PHP too.
        if (hiddenInput.value) textInput.value = toDisplay(parseIso(hiddenInput.value));
    }

    document.querySelectorAll('.date-range-field').forEach((field) => {
        const textInput = field.querySelector('.date-range-input');
        const hiddenInput = field.querySelector('.date-range-hidden');
        if (textInput && hiddenInput) buildCalendar(field, textInput, hiddenInput);
    });
})();
</script>
@endonce
@endpush
