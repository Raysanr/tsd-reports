{{--
    Month-only picker for Projections (explicit request, 2026-10-01: "add
    date picker like in the other tabs" then "like month only the
    selection" — same visual "DATE RANGE" box + calendar-icon style as
    _date-range-filter.blade.php, but picks ONE MONTH instead of a day
    range. Wired up for real, 2026-10-02 — Projections became per-month
    (see ProjectionColumn's own doc comment) — picking a month here now
    NAVIGATES straight to it (same "pick it, see it immediately" pattern
    Expected Income's own date-range filter already uses), reading
    whatever month already has data there or a blank new one otherwise.

    $name (hidden field name), $value ('Y-m' string, e.g. "2026-10"),
    $navigate (bool — true for this top filter, false for the separate
    "Add Projection" modal's own copy of this same widget, which instead
    waits for an explicit confirm button) required. Renders a text display
    box that opens a 12-month grid for the current year (with prev/next
    YEAR navigation, not month-by-month like the day picker, since there's
    no day grid here). --}}
<div class="month-picker-field relative" data-value="{{ $value }}" data-navigate="{{ $navigate ? '1' : '0' }}">
    <label class="block text-[11px] font-mono font-semibold tracking-widest text-ink-muted dark:text-slate-400 uppercase mb-1">Month</label>
    <div class="relative">
        <svg class="pointer-events-none absolute z-10 left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15A1.5 1.5 0 0121 6.75v13.5a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 20.25V6.75a1.5 1.5 0 011.5-1.5z"/>
        </svg>
        <input type="text" inputmode="none" autocomplete="off" readonly data-role="month-display"
               class="month-picker-input w-[12.5rem] cursor-pointer text-sm font-mono border border-line dark:border-slate-600 rounded-lg pl-9 pr-3 py-2 bg-white dark:bg-slate-800 text-ink dark:text-slate-100 shadow-sm hover:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary transition-colors">
    </div>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" data-role="month-hidden">
</div>

@once
    <style>
        .month-picker-calendar {
            position: absolute; z-index: 50; top: calc(100% + 6px); left: 0;
            width: 15rem; padding: 0.75rem;
            background: #fff; border: 1px solid #FEF08A; border-radius: 0.75rem;
            box-shadow: 0 10px 25px -5px rgb(0 0 0 / 0.15), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .dark .month-picker-calendar { background: #1e293b; border-color: #475569; }
        .month-picker-cell {
            padding: 0.5rem 0; display: flex; align-items: center; justify-content: center;
            border-radius: 0.5rem; font-size: 0.8125rem; font-family: var(--font-mono);
            color: #111827; cursor: pointer;
        }
        .dark .month-picker-cell { color: #f1f5f9; }
        .month-picker-cell:hover { background: #FEF9C3; }
        .dark .month-picker-cell:hover { background: rgba(202,138,4,0.25); }
        .month-picker-cell-current { border: 1px solid var(--color-primary); }
        .month-picker-cell-selected { background: var(--color-primary) !important; color: #fff !important; font-weight: 600; }
    </style>
@endonce

@push('scripts')
@once
<script>
// Shared month-only picker widget for every .month-picker-field on the
// page — same "one box, one popup" shape as the day-range picker, but a
// 12-cell month grid per year instead of a day grid, and no auto-submit
// (explicit request, 2026-10-01: "add date picker but no functions for
// now" — Projections has nothing to filter by yet).
(function () {
    const MONTH_ABBR = ['Jan.', 'Feb.', 'Mar.', 'Apr.', 'May', 'June', 'July', 'Aug.', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];
    function parseYm(str) {
        if (!str) { const d = new Date(); return { year: d.getFullYear(), month: d.getMonth() }; }
        const [y, m] = str.split('-').map(Number);
        return { year: y, month: m - 1 };
    }
    function toYm(year, month) { return `${year}-${String(month + 1).padStart(2, '0')}`; }
    function toDisplay(year, month) { return `${MONTH_ABBR[month]} ${year}`; }

    function buildPicker(field) {
        const display = field.querySelector('[data-role="month-display"]');
        const hidden = field.querySelector('[data-role="month-hidden"]');

        const selected = parseYm(hidden.value);
        let viewYear = selected.year;
        let panel = null;

        function render() {
            const now = new Date();
            const selectedYm = hidden.value;

            const cells = MONTH_ABBR.map((label, i) => {
                const ym = toYm(viewYear, i);
                const classes = ['month-picker-cell'];
                if (viewYear === now.getFullYear() && i === now.getMonth()) classes.push('month-picker-cell-current');
                if (ym === selectedYm) classes.push('month-picker-cell-selected');
                return `<button type="button" class="${classes.join(' ')}" data-ym="${ym}">${label.replace('.', '')}</button>`;
            }).join('');

            panel.innerHTML = `
                <div class="flex items-center justify-between mb-2 px-1">
                    <button type="button" data-nav="prev" class="w-6 h-6 flex items-center justify-center rounded-md text-ink-muted dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">&larr;</button>
                    <span class="text-xs font-mono font-bold text-ink dark:text-slate-100">${viewYear}</span>
                    <button type="button" data-nav="next" class="w-6 h-6 flex items-center justify-center rounded-md text-ink-muted dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">&rarr;</button>
                </div>
                <div class="grid grid-cols-3 gap-1">${cells}</div>
            `;
        }

        function open() {
            if (panel) return;
            panel = document.createElement('div');
            panel.className = 'month-picker-calendar';
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

        function pick(ym) {
            hidden.value = ym;
            const [y, m] = ym.split('-').map(Number);
            display.value = toDisplay(y, m - 1);
            close();
            // The top filter navigates immediately on pick (explicit
            // decision, 2026-10-02: "it will be one date picker and one
            // add projection button" — this top box is the plain "switch
            // to this month" filter, same "pick it, see it immediately"
            // pattern Expected Income's own date-range filter already
            // uses). The separate "Add Projection" modal's own copy of
            // this widget instead waits for its own explicit confirm
            // button — see field.dataset.navigate.
            if (field.dataset.navigate === '1') {
                const url = new URL(window.location.href);
                url.searchParams.set('month', ym);
                window.location.href = url.toString();
            }
        }

        field.addEventListener('click', (e) => {
            const navBtn = e.target.closest('[data-nav]');
            const cellBtn = e.target.closest('[data-ym]');
            const opensOn = e.target === display || e.target.closest('svg');

            if (opensOn) {
                panel ? close() : open();
                return;
            }
            if (navBtn) {
                viewYear += navBtn.dataset.nav === 'next' ? 1 : -1;
                render();
                return;
            }
            if (cellBtn) {
                pick(cellBtn.dataset.ym);
            }
        });

        if (hidden.value) {
            display.value = toDisplay(selected.year, selected.month);
        }
    }

    document.querySelectorAll('.month-picker-field').forEach((field) => buildPicker(field));
})();
</script>
@endonce
@endpush
