{{--
    Shared "From / To" date-range picker for every Data Management report
    page (DSPPR, Summary Sales Report, Expected Income) — explicit request,
    2026-09-27: "make all of the date picker in all pages ... has design,
    use tailwind css components for modern design," then a direct follow-up
    once the native browser calendar popup showed through unstyled ("why is
    it still like this") confirming a full custom widget was wanted, not
    just the closed input's own border/icon; then 2026-09-28: "make it the
    calendar pop up is only one and it can only drag first date to last
    date," then "it should be like the calendar icon is only one and can
    drag only" — ONE box with ONE calendar icon showing "From – To" as a
    single range, opening the one shared calendar. Click the start day
    then the end day directly on it (a click-click range select, the same
    UX every mainstream date-range picker uses for "dragging" a range — a
    literal mousedown-drag-release wouldn't work for picking two dates far
    apart on different calendar pages anyway).

    Renders one plain text input (not type="date" — a native date input's
    own popup calendar can never be restyled via CSS on any browser)
    showing both dates at once, plus ONE custom-built dropdown calendar,
    in the app's own gold/dark theme. The real values still POST as plain
    'Y-m-d' strings in the SAME $fromName/$toName fields every page's
    controller already reads, so no backend change was needed for this
    redesign.

    $fromName, $toName (form field names), $fromValue, $toValue (current
    'Y-m-d' date strings) required. Renders INSIDE the page's own <form>
    (not its own form) — same "auto-submit the filter form once a full
    range is picked" contract as before.
--}}
<div class="date-range-field relative" data-from="{{ $fromValue }}" data-to="{{ $toValue }}">
    <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Date Range</label>
    <div class="relative">
        <svg class="pointer-events-none absolute z-10 left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15A1.5 1.5 0 0121 6.75v13.5a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 20.25V6.75a1.5 1.5 0 011.5-1.5z"/>
        </svg>
        <input type="text" inputmode="none" autocomplete="off" readonly data-role="range-display"
               class="date-range-input w-[19.5rem] cursor-pointer text-sm font-mono border border-line dark:border-slate-600 rounded-lg pl-9 pr-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 shadow-sm hover:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary transition-colors">
    </div>
    <input type="hidden" name="{{ $fromName }}" value="{{ $fromValue }}" data-role="from-hidden">
    <input type="hidden" name="{{ $toName }}" value="{{ $toValue }}" data-role="to-hidden">
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
        .date-range-day-in-range { background: #FEF9C3; color: var(--color-primary); font-weight: 600; }
        .dark .date-range-day-in-range { background: rgba(202,138,4,0.25); }
        .date-range-hint { font-size: 0.6875rem; text-align: center; }
    </style>
@endonce

@push('scripts')
@once
<script>
// Shared custom range-calendar widget for every .date-range-field on the
// page (explicit request, 2026-09-28: one calendar, click the From day
// then the To day directly on it — same "picker" UX as Google/Stripe/etc
// range pickers, since a literal mouse drag can't span two calendar pages).
(function () {
    const MONTH_ABBR = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'June', 'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];
    function pad(n) { return String(n).padStart(2, '0'); }
    function toIso(date) { return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`; }
    function toDisplay(date) { return `${MONTH_ABBR[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`; }
    function parseIso(str) {
        if (!str) return new Date();
        const [y, m, d] = str.split('-').map(Number);
        return new Date(y, m - 1, d);
    }

    function buildCalendar(field) {
        const rangeDisplay = field.querySelector('[data-role="range-display"]');
        const fromHidden = field.querySelector('[data-role="from-hidden"]');
        const toHidden = field.querySelector('[data-role="to-hidden"]');

        let viewDate = parseIso(fromHidden.value || toIso(new Date()));
        let panel = null;
        // Tracks the in-progress selection: null once a full range is set
        // and idle, or the ISO string of the "from" day the user just
        // clicked while waiting for them to click the "to" day.
        let pendingFrom = null;
        // While pendingFrom is set, tracks whichever day the mouse is
        // currently over, so the in-between days can preview-highlight as
        // if that day were the end date, live, before the second click
        // (explicit request, 2026-09-28: "even when i still did not click
        // the end number the numbers is highlighted based on the pointer").
        let hoverIso = null;

        function render() {
            const year = viewDate.getFullYear();
            const month = viewDate.getMonth();
            const firstOfMonth = new Date(year, month, 1);
            const startWeekday = firstOfMonth.getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const today = toIso(new Date());
            const rangeStart = pendingFrom || fromHidden.value;
            const rangeEnd = pendingFrom ? (hoverIso || null) : toHidden.value;

            const monthLabel = viewDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

            function dayClasses(iso) {
                const classes = ['date-range-day'];
                if (iso === today) classes.push('date-range-day-today');
                const lo = rangeStart && rangeEnd ? (rangeStart < rangeEnd ? rangeStart : rangeEnd) : rangeStart;
                const hi = rangeStart && rangeEnd ? (rangeStart < rangeEnd ? rangeEnd : rangeStart) : rangeEnd;
                if (iso === rangeStart || iso === rangeEnd) classes.push('date-range-day-selected');
                else if (lo && hi && iso > lo && iso < hi) classes.push('date-range-day-in-range');
                return classes.join(' ');
            }

            let cells = '';
            for (let i = 0; i < startWeekday; i++) {
                const d = new Date(year, month, 1 - (startWeekday - i));
                cells += `<button type="button" class="date-range-day date-range-day-muted" data-date="${toIso(d)}">${d.getDate()}</button>`;
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const date = new Date(year, month, d);
                const iso = toIso(date);
                cells += `<button type="button" class="${dayClasses(iso)}" data-date="${iso}">${d}</button>`;
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
                <div class="flex items-center justify-between mt-2 pt-2 border-t border-line dark:border-slate-700">
                    <span class="date-range-hint font-mono text-ink-muted dark:text-slate-400">${pendingFrom ? 'Pick the end date' : 'Pick the start date'}</span>
                    <button type="button" data-action="today" class="text-xs font-mono text-primary hover:underline">Today</button>
                </div>
            `;
        }

        function open() {
            if (panel) return;
            panel = document.createElement('div');
            panel.className = 'date-range-calendar';
            field.appendChild(panel);
            pendingFrom = null;
            hoverIso = null;
            render();
            document.addEventListener('mousedown', onOutsideClick, true);
        }
        function close() {
            if (!panel) return;
            panel.remove();
            panel = null;
            pendingFrom = null;
            hoverIso = null;
            document.removeEventListener('mousedown', onOutsideClick, true);
        }
        function onOutsideClick(e) {
            if (!field.contains(e.target)) close();
        }

        function apply(fromIso, toIso_) {
            fromHidden.value = fromIso;
            toHidden.value = toIso_;
            rangeDisplay.value = `${toDisplay(parseIso(fromIso))} – ${toDisplay(parseIso(toIso_))}`;
            close();
            fromHidden.closest('form').submit();
        }

        function pickDay(iso) {
            if (!pendingFrom) {
                // First click of a new range: remember it and wait for the
                // second click, swapping later if it lands before this one.
                pendingFrom = iso;
                render();
                return;
            }
            const start = pendingFrom < iso ? pendingFrom : iso;
            const end = pendingFrom < iso ? iso : pendingFrom;
            apply(start, end);
        }

        field.addEventListener('mouseover', (e) => {
            if (!pendingFrom) return;
            const dayBtn = e.target.closest('.date-range-day:not(.date-range-day-muted)');
            const iso = dayBtn ? dayBtn.dataset.date : null;
            if (iso === hoverIso) return;
            hoverIso = iso;
            render();
        });

        field.addEventListener('mouseleave', () => {
            if (!pendingFrom || !hoverIso) return;
            hoverIso = null;
            render();
        });

        field.addEventListener('click', (e) => {
            const navBtn = e.target.closest('[data-nav]');
            const dayBtn = e.target.closest('.date-range-day:not(.date-range-day-muted)');
            const todayBtn = e.target.closest('[data-action="today"]');
            const opensOn = e.target === rangeDisplay || e.target.closest('svg');

            if (opensOn) {
                panel ? close() : open();
                return;
            }
            if (navBtn) {
                viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + (navBtn.dataset.nav === 'next' ? 1 : -1), 1);
                render();
                return;
            }
            if (dayBtn) {
                pickDay(dayBtn.dataset.date);
                return;
            }
            if (todayBtn) {
                const todayIso = toIso(new Date());
                pickDay(todayIso);
            }
        });

        // Seed the visible text field from whatever range the page
        // rendered with — done here (not server-side) so toDisplay()'s own
        // MM/DD/YYYY formatting stays in ONE place rather than duplicated
        // in PHP too.
        if (fromHidden.value && toHidden.value) {
            rangeDisplay.value = `${toDisplay(parseIso(fromHidden.value))} – ${toDisplay(parseIso(toHidden.value))}`;
        }
    }

    document.querySelectorAll('.date-range-field').forEach((field) => buildCalendar(field));
})();
</script>
@endonce
@endpush
