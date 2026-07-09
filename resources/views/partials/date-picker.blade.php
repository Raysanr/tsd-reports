{{--
    Shared date-picker widget — the same flatpickr dropdown (pill trigger + preset
    sidebar + inline calendar) used on the Dashboard, generalized so every other
    report can use it too.

    Props:
      mode         'range' (Dashboard — two dates) or 'single' (every other report —
                    one date). Both show the identical 8-preset list; in single mode
                    a range-style preset (e.g. "Last 7 days") resolves to its end
                    date, since these reports show one day, not a span. Default 'single'.
      id           Unique prefix for this instance's element IDs. Default 'drp'.
      date         Y-m-d string — required when mode='single'.
      dateFrom     Carbon — required when mode='range'.
      dateTo       Carbon — required when mode='range'.
      submit       'form' (default) — sets the date field(s) on the closest <form>
                    and submits it, preserving every other field already in that
                    form (team, product, show_empty, etc.).
                    'navigate' — redirects straight to a URL (what the Dashboard
                    uses, since it has no wrapping <form>).
      navigateBase URL to redirect to when submit='navigate'. Default '/'.
      dateField    Name of the single-mode date input. Default 'date'.
      minDate      Earliest selectable date. Default '2026-06-01'.
--}}
@php
    $mode         = $mode ?? 'single';
    $isRange      = $mode === 'range';
    $uid          = $id ?? 'drp';
    $submitMode   = $submit ?? 'form';
    $navigateBase = $navigateBase ?? '/';
    $dateField    = $dateField ?? 'date';
    $minDate      = $minDate ?? '2026-06-01';

    $initFrom = $isRange ? $dateFrom->toDateString() : $date;
    $initTo   = $isRange ? $dateTo->copy()->startOfDay()->toDateString() : $date;
@endphp

@if(!$isRange && $submitMode === 'form')
{{-- Persistent field so ANY submit of the enclosing form (not just this picker's
     own Apply button — e.g. a plain "Load" button elsewhere in the form) carries
     the currently selected date, same as a native <input type="date"> would. --}}
<input type="hidden" id="{{ $uid }}HiddenDate" name="{{ $dateField }}" value="{{ $date }}">
@endif

@if($isRange && $submitMode === 'form')
{{-- Range equivalent of the persistent field above: keeps date_from/date_to on the
     form so submitting it via any other control (e.g. a team-select button) doesn't
     drop the currently selected range. Updated live as the user picks. --}}
<input type="hidden" id="{{ $uid }}HiddenFrom" name="date_from" value="{{ $initFrom }}">
<input type="hidden" id="{{ $uid }}HiddenTo"   name="date_to"   value="{{ $initTo }}">
@endif

<div class="relative">
    {{-- Icon-only trigger — the selected date/range isn't shown as visible text (by
         design, for a minimal topbar), so it's carried entirely in `title` (native
         tooltip) and `aria-label`, both kept live-updated in JS below. A small dot
         marks "not today" so a custom filter is still noticeable at a glance without
         relying on color alone (the dot always pairs with the tooltip text). --}}
    <button type="button" id="{{ $uid }}Trigger"
        title="{{ \Carbon\Carbon::parse($initFrom)->format('M d, Y') }}{{ $isRange && $initFrom !== $initTo ? ' – ' . \Carbon\Carbon::parse($initTo)->format('M d, Y') : '' }}"
        aria-label="Change date{{ $isRange ? ' range' : '' }}, currently {{ \Carbon\Carbon::parse($initFrom)->format('M d, Y') }}{{ $isRange && $initFrom !== $initTo ? ' to ' . \Carbon\Carbon::parse($initTo)->format('M d, Y') : '' }}"
        class="relative inline-flex items-center justify-center w-8 h-8 bg-yellow-50 border border-yellow-200 rounded-full hover:bg-yellow-100 transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
        <span id="{{ $uid }}Dot" class="{{ $initFrom === now('Asia/Manila')->toDateString() && $initTo === now('Asia/Manila')->toDateString() ? 'hidden' : '' }} absolute top-0.5 right-0.5 w-2 h-2 rounded-full bg-yellow-600 border border-white"></span>
    </button>

    <div id="{{ $uid }}Panel" class="hidden absolute right-0 top-full mt-2 z-50 bg-white rounded-2xl shadow-2xl border border-slate-200" style="width:{{ $isRange ? '700px' : '440px' }}">
        <div class="flex">
            {{-- Presets sidebar — identical list in both modes --}}
            <div class="w-28 border-r border-slate-100 py-2 shrink-0">
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="today">Today</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="yesterday">Yesterday</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="last7">Last 7 days</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="last30">Last 30 days</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="thisMonth">This month</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="lastMonth">Last month</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="weekToNow">Week to now</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-3 py-1.5 text-xs font-mono text-slate-600 hover:bg-yellow-50 hover:text-yellow-700 transition-colors" data-preset="monthToNow">Month to now</button>
            </div>

            {{-- Inline flatpickr calendar — dual month, editable start/end fields --}}
            <div class="flex-1 p-3">
                <div class="flex items-center gap-2 mb-2 px-1">
                    <input type="text" id="{{ $uid }}FromInput" placeholder="{{ $isRange ? 'mm/dd/yyyy' : 'Date' }}" inputmode="numeric"
                        class="flex-1 border border-slate-200 rounded-md px-2 py-1 text-xs font-mono text-center focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @if($isRange)
                    <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                    <input type="text" id="{{ $uid }}ToInput" placeholder="mm/dd/yyyy" inputmode="numeric"
                        class="flex-1 border border-slate-200 rounded-md px-2 py-1 text-xs font-mono text-center focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @endif
                </div>
                <div id="{{ $uid }}Calendar"></div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-slate-100 px-4 py-2 bg-slate-50">
            <button type="button" id="{{ $uid }}Close" class="px-3 py-1 text-xs font-mono text-slate-600 hover:text-slate-800 border border-slate-200 rounded-md hover:bg-white transition-colors cursor-pointer">Close</button>
            <button type="button" id="{{ $uid }}Apply" class="px-4 py-1 text-xs font-mono font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-md transition-colors cursor-pointer">Apply</button>
        </div>
    </div>
</div>

<script>
(function () {
    const isRange   = {{ $isRange ? 'true' : 'false' }};
    const submitMode = '{{ $submitMode }}';
    const trigger   = document.getElementById('{{ $uid }}Trigger');
    const panel     = document.getElementById('{{ $uid }}Panel');
    const dot       = document.getElementById('{{ $uid }}Dot');
    const fromInput = document.getElementById('{{ $uid }}FromInput');
    const toInput   = isRange ? document.getElementById('{{ $uid }}ToInput') : null;
    const applyBtn  = document.getElementById('{{ $uid }}Apply');
    const closeBtn  = document.getElementById('{{ $uid }}Close');

    let selFrom = '{{ $initFrom }}';
    let selTo   = '{{ $initTo }}';

    // Shared, page-readable state — lets other scripts on the same page (e.g. the
    // Dashboard's Sync button) know the currently selected date(s) without reaching
    // into this closure.
    window.__datePicker = window.__datePicker || {};
    window.__datePicker['{{ $uid }}'] = { from: selFrom, to: selTo };
    const publish = () => { window.__datePicker['{{ $uid }}'] = { from: selFrom, to: selTo }; };

    const fmt = d => {
        const dt = new Date(d + 'T00:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    };

    // Panel's own Start/End fields use mm/dd/yyyy, matching the reference picker —
    // distinct from fmt() above, which is only for the pill trigger's label.
    const fmtSlash = d => {
        const dt = new Date(d + 'T00:00:00');
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const day = String(dt.getDate()).padStart(2, '0');
        return `${m}/${day}/${dt.getFullYear()}`;
    };

    // Parses a typed mm/dd/yyyy (or m/d/yyyy) string back to 'YYYY-MM-DD', or null
    // if it isn't a valid, real calendar date (e.g. "02/30/2026" is rejected).
    const parseSlash = s => {
        const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec((s || '').trim());
        if (!match) return null;
        const [, mm, dd, yyyy] = match.map(Number);
        const dt = new Date(yyyy, mm - 1, dd);
        if (dt.getFullYear() !== yyyy || dt.getMonth() !== mm - 1 || dt.getDate() !== dd) return null;
        return toLocalISO(dt);
    };

    // Bug: Date -> 'YYYY-MM-DD' must read LOCAL calendar fields. toISOString()
    // converts to UTC first, which underflows into the previous day for any
    // timezone ahead of UTC (e.g. Asia/Manila, UTC+8) — clicking "Jul 2" at
    // local midnight becomes "2026-07-01T16:00:00Z", so .split('T')[0] reads
    // back "Jul 1". Every date-producing helper below must use this instead.
    const toLocalISO = d => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    };

    const hiddenDateField = document.getElementById('{{ $uid }}HiddenDate');
    const hiddenFromField = document.getElementById('{{ $uid }}HiddenFrom');
    const hiddenToField   = document.getElementById('{{ $uid }}HiddenTo');

    const updateInputs = () => {
        fromInput.value = selFrom ? fmtSlash(selFrom) : '';
        if (isRange) toInput.value = selTo ? fmtSlash(selTo) : '';
        if (hiddenDateField) hiddenDateField.value = selFrom;
        if (hiddenFromField) hiddenFromField.value = selFrom;
        if (hiddenToField)   hiddenToField.value   = selTo || selFrom;
    };

    // Icon-only trigger: the selection isn't shown as visible text, so this keeps
    // the tooltip/aria-label (and the "custom filter active" dot) in sync instead.
    const updateLabel = () => {
        const isSameDay  = !isRange || selFrom === selTo || !selTo;
        const labelText  = isSameDay ? fmt(selFrom) : fmt(selFrom) + '  –  ' + fmt(selTo);
        trigger.title = labelText;
        trigger.setAttribute('aria-label', `Change date${isRange ? ' range' : ''}, currently ${labelText}`);

        if (dot) {
            const todayStr = today();
            const isToday  = isSameDay && selFrom === todayStr;
            dot.classList.toggle('hidden', isToday);
        }
    };

    updateInputs();

    // Flatpickr: init LAZILY on first open. We attach to a hidden <input> placed
    // INSIDE the calendar wrapper so flatpickr inserts .flatpickr-calendar.inline
    // as a sibling inside that div (required for the CSS selectors to match and
    // for the calendar to render visibly in the panel).
    let fp = null;
    function initFp() {
        if (fp) return;
        const calWrap = document.getElementById('{{ $uid }}Calendar');
        const fpInput = document.createElement('input');
        fpInput.type = 'text';
        fpInput.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;';
        calWrap.appendChild(fpInput);
        fp = flatpickr(fpInput, {
            mode       : isRange ? 'range' : 'single',
            inline     : true,
            // Range mode (Dashboard) shows two months side by side for span picking;
            // single-date report pages stay on one month.
            showMonths : isRange ? 2 : 1,
            minDate    : '{{ $minDate }}',
            maxDate    : 'today',
            defaultDate: isRange ? [selFrom, selTo] : [selFrom],
            onChange   : (dates) => {
                if (!dates.length) return;
                selFrom = toLocalISO(dates[0]);
                if (isRange) {
                    // Bug: clicking a new start date used to immediately set selTo =
                    // selFrom too, so both fields showed the same date before an end
                    // date was ever chosen — falsely presenting an in-progress range
                    // pick as a finished one-day selection. Leave it null (End field
                    // blank) until flatpickr actually reports a second date.
                    selTo = dates[1] ? toLocalISO(dates[1]) : null;
                } else {
                    selTo = selFrom;
                }
                updateInputs();
                publish();
            },
        });
    }

    // Typed dates: user can edit the Start/End text fields directly instead of
    // only clicking the calendar. Commits on blur or Enter; invalid text (bad
    // format or non-existent date) is silently ignored and the field snaps back
    // to the last valid value via updateInputs().
    const commitTyped = (input, isFromField) => {
        const parsed = parseSlash(input.value);
        if (!parsed) { updateInputs(); return; }

        if (isRange) {
            if (isFromField) {
                selFrom = parsed;
                if (selTo && selTo < selFrom) selTo = selFrom;
            } else {
                selTo = parsed;
                if (selFrom && selFrom > selTo) selFrom = selTo;
            }
        } else {
            selFrom = parsed;
            selTo   = parsed;
        }

        if (fp) fp.setDate(isRange ? [selFrom, selTo] : [selFrom]);
        updateInputs();
        publish();
    };

    fromInput.addEventListener('blur', () => commitTyped(fromInput, true));
    fromInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); fromInput.blur(); } });
    if (isRange) {
        toInput.addEventListener('blur', () => commitTyped(toInput, false));
        toInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); toInput.blur(); } });
    }

    // Presets — same list/logic in both modes. In single mode, a range-style
    // preset (e.g. "Last 7 days") resolves to its END date: these reports show
    // one day, so "Last 7 days" means "the day 7 days of data currently ends on"
    // (today), and "Last month" means "the last day of last month".
    const presets = {
        today      : () => { const t = today(); return [t, t]; },
        yesterday  : () => { const d = daysAgo(1); return [d, d]; },
        last7      : () => [daysAgo(6), today()],
        last30     : () => [daysAgo(29), today()],
        thisMonth  : () => [monthStart(0), today()],
        lastMonth  : () => [monthStart(-1), monthEnd(-1)],
        weekToNow  : () => [weekStart(), today()],
        monthToNow : () => [monthStart(0), today()],
    };

    function today()         { return toLocalISO(new Date()); }
    function daysAgo(n)      { const d = new Date(); d.setDate(d.getDate() - n); return toLocalISO(d); }
    function weekStart()     { const d = new Date(); d.setDate(d.getDate() - d.getDay()); return toLocalISO(d); }
    function monthStart(off) { const d = new Date(); d.setMonth(d.getMonth() + off, 1); return toLocalISO(d); }
    function monthEnd(off)   { const d = new Date(); d.setMonth(d.getMonth() + off + 1, 0); return toLocalISO(d); }

    document.querySelectorAll('.{{ $uid }}-preset').forEach(btn => {
        btn.addEventListener('click', () => {
            const [f, t] = presets[btn.dataset.preset]();
            if (isRange) {
                selFrom = f; selTo = t;
            } else {
                // Single mode: one date, so a range preset collapses to its end.
                selFrom = t; selTo = t;
            }
            if (fp) fp.setDate(isRange ? [f, t] : [selFrom]);
            updateInputs();
            publish();
            document.querySelectorAll('.{{ $uid }}-preset').forEach(b => b.classList.remove('bg-yellow-50', 'text-yellow-700', 'font-semibold'));
            btn.classList.add('bg-yellow-50', 'text-yellow-700', 'font-semibold');
        });
    });

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const isHidden = panel.classList.contains('hidden');
        if (isHidden) {
            panel.classList.remove('hidden');
            requestAnimationFrame(() => initFp());
        } else {
            panel.classList.add('hidden');
        }
    });

    document.addEventListener('click', (e) => {
        if (!panel.contains(e.target) && e.target !== trigger) {
            panel.classList.add('hidden');
        }
    });

    closeBtn.addEventListener('click', () => panel.classList.add('hidden'));

    applyBtn.addEventListener('click', () => {
        // If Apply is clicked mid-range-pick (start chosen, end never clicked),
        // selTo is still null — finalize it as a same-day range rather than
        // submitting/navigating with a missing date_to.
        if (isRange && !selTo) selTo = selFrom;

        panel.classList.add('hidden');
        updateLabel();
        publish();

        if (submitMode === 'navigate') {
            window.location.href = '{{ $navigateBase }}?date_from=' + selFrom + '&date_to=' + selTo;
            return;
        }

        // submitMode === 'form': set the field(s) on the closest form and submit it,
        // so every other field already in that form (team, product, show_empty...)
        // is preserved exactly as-is.
        const form = trigger.closest('form');
        if (!form) return;

        const setField = (name, value) => {
            let field = form.querySelector(`[name="${name}"]`);
            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                form.appendChild(field);
            }
            field.value = value;
        };

        if (isRange) {
            setField('date_from', selFrom);
            setField('date_to', selTo);
        } else {
            setField('{{ $dateField }}', selFrom);
        }
        form.submit();
    });
})();
</script>
