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
      showLabel    true — trigger is a text pill showing the selected date (e.g.
                    "Jul 25, 2026") instead of a bare icon. For a standalone form
                    field (e.g. Sync Health's "Retry a Date") where the value
                    needs to be visible at a glance, not just a topbar filter
                    icon whose effect is already reflected in the page below it.
                    Default false.
      autoSubmit   Only relevant when submit='form'. false — picking a date and
                    clicking Apply updates the hidden field(s) and closes the
                    panel WITHOUT submitting the form, leaving a separate button
                    elsewhere in the form (e.g. "Retry Sync") as the deliberate
                    action trigger. Default true (existing behavior everywhere
                    else: Apply both sets the field(s) and submits).
      rangeMonths  Only relevant when mode='range'. How many months the inline
                    calendar shows side by side on desktop (still a single
                    month on mobile, same as always). Default 2 (Dashboard's
                    original range picker). Sync Health's "Fix Now" passes 1 —
                    still a real start/end range, just a narrower calendar.
--}}
@php
    $mode         = $mode ?? 'single';
    $isRange      = $mode === 'range';
    $uid          = $id ?? 'drp';
    $submitMode   = $submit ?? 'form';
    $navigateBase = $navigateBase ?? '/';
    $dateField    = $dateField ?? 'date';
    $minDate      = $minDate ?? '2026-06-01';
    $showLabel    = $showLabel ?? false;
    $autoSubmit   = $autoSubmit ?? true;
    $rangeMonths  = $rangeMonths ?? 2;

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
    {{-- Two visual variants: icon-only (default, every topbar usage — the
         selected date/range isn't shown as visible text by design, carried
         entirely in `title`/`aria-label` instead, with a small dot marking
         "not today" so a custom filter is still noticeable without relying on
         color alone) vs a text pill showing the date directly (showLabel —
         Sync Health's "Retry a Date", a standalone form field where the
         value needs to be visible at a glance, not inferred from a page
         already reflecting it). --}}
    <button type="button" id="{{ $uid }}Trigger"
        title="{{ \Carbon\Carbon::parse($initFrom)->format('M d, Y') }}{{ $isRange && $initFrom !== $initTo ? ' – ' . \Carbon\Carbon::parse($initTo)->format('M d, Y') : '' }}"
        aria-label="Change date{{ $isRange ? ' range' : '' }}, currently {{ \Carbon\Carbon::parse($initFrom)->format('M d, Y') }}{{ $isRange && $initFrom !== $initTo ? ' to ' . \Carbon\Carbon::parse($initTo)->format('M d, Y') : '' }}"
        class="{{ $showLabel
            ? 'inline-flex items-center gap-2 px-3 py-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:border-yellow-400 dark:hover:border-yellow-600 transition-colors cursor-pointer text-sm font-mono text-slate-800 dark:text-slate-100'
            : 'relative inline-flex items-center justify-center w-8 h-8 bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 rounded-full hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer shrink-0' }}">
        <svg class="w-4 h-4 text-yellow-600 dark:text-yellow-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
        @if($showLabel)
        {{-- Same range-aware expression as title/aria-label above — this is
             the first usage to ever combine mode='range' with showLabel=true
             (Sync Health's "Fix Now", 2026-08-10; every prior showLabel
             trigger was single-mode, e.g. "Retry a Date"), which is exactly
             why this one spot was still range-blind: nothing before had
             ever exercised that combination. Client-side updateLabel()
             already computed this correctly post-interaction (same "from –
             to" logic) — this was only wrong on first render, before Apply
             was ever clicked. --}}
        <span id="{{ $uid }}Label">{{ \Carbon\Carbon::parse($initFrom)->format('M d, Y') }}{{ $isRange && $initFrom !== $initTo ? ' – ' . \Carbon\Carbon::parse($initTo)->format('M d, Y') : '' }}</span>
        @else
        <span id="{{ $uid }}Dot" class="{{ $initFrom === now('Asia/Manila')->toDateString() && $initTo === now('Asia/Manila')->toDateString() ? 'hidden' : '' }} absolute top-0.5 right-0.5 w-2 h-2 rounded-full bg-yellow-600 border border-white"></span>
        @endif
    </button>

    {{-- position:fixed here computed entirely by JS (see initFp/positionPanel
         below) — the panel is moved to a direct child of <body> on first open,
         escaping <main>'s overflow-x-hidden (layouts/app.blade.php). A `fixed`
         descendant of an `overflow-hidden` ancestor gets silently clipped to
         that ancestor's bounds on some real mobile browsers (confirmed on a
         real Android phone; not reproducible in a desktop-viewport-resize
         test) — the same reason #confirmModal/#sidebarBackdrop in that same
         layout file are declared as direct body children instead of wherever
         they're conceptually used. max-h-[85vh]+overflow-y-auto: a stacked
         mobile layout (8 presets + a full calendar) can run taller than short
         phone screens. --}}
    <div id="{{ $uid }}Panel" class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 max-h-[85vh] overflow-y-auto w-[min(285px,calc(100vw-2rem))] {{ $isRange && $rangeMonths > 1 ? 'sm:w-[700px]' : 'sm:w-[440px]' }}">
        {{-- Always side-by-side (sidebar + calendar), matching the desktop design
             exactly at every screen size — mobile just shrinks both (narrower
             sidebar, smaller calendar cells; see the mobile flatpickr overrides
             in layouts/app.blade.php) rather than restacking into a different
             layout. A long preset label (e.g. "Last 30 days") wrapping to 2
             lines at the mobile sidebar's width is expected and fine. --}}
        <div class="flex">
            {{-- Presets sidebar — identical list in both modes --}}
            <div class="w-[72px] sm:w-28 border-r border-slate-100 dark:border-slate-700 py-2 shrink-0">
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="today">Today</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="yesterday">Yesterday</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="last7">Last 7 days</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="last30">Last 30 days</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="thisMonth">This month</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="lastMonth">Last month</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="weekToNow">Week to now</button>
                <button type="button" class="{{ $uid }}-preset w-full text-left px-1.5 sm:px-3 py-1.5 text-[10px] sm:text-xs leading-tight font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors" data-preset="monthToNow">Month to now</button>
            </div>

            {{-- Inline flatpickr calendar — dual month, editable start/end fields --}}
            <div class="flex-1 p-1.5 sm:p-3 min-w-0">
                <div class="flex items-center gap-1 sm:gap-2 mb-2 px-1">
                    <input type="text" id="{{ $uid }}FromInput" placeholder="{{ $isRange ? 'mm/dd/yyyy' : 'Date' }}" inputmode="numeric"
                        class="flex-1 min-w-0 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-md px-1 sm:px-2 py-1 text-[10px] sm:text-xs font-mono text-center focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @if($isRange)
                    <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                    <input type="text" id="{{ $uid }}ToInput" placeholder="mm/dd/yyyy" inputmode="numeric"
                        class="flex-1 min-w-0 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 rounded-md px-1 sm:px-2 py-1 text-[10px] sm:text-xs font-mono text-center focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    @endif
                </div>
                <div id="{{ $uid }}Calendar"></div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-slate-100 dark:border-slate-700 px-4 py-2 bg-slate-50 dark:bg-slate-800">
            <button type="button" id="{{ $uid }}Close" class="px-3 py-1 text-xs font-mono text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-md hover:bg-white dark:hover:bg-slate-900 transition-colors cursor-pointer">Close</button>
            <button type="button" id="{{ $uid }}Apply" class="px-4 py-1 text-xs font-mono font-semibold text-white bg-yellow-700 hover:bg-yellow-800 rounded-md transition-colors cursor-pointer">Apply</button>
        </div>
    </div>
</div>

<script>
(function () {
    const isRange   = {{ $isRange ? 'true' : 'false' }};
    const submitMode = '{{ $submitMode }}';
    const autoSubmit = {{ $autoSubmit ? 'true' : 'false' }};
    const trigger   = document.getElementById('{{ $uid }}Trigger');
    const panel     = document.getElementById('{{ $uid }}Panel');
    const dot       = document.getElementById('{{ $uid }}Dot');
    const fromInput = document.getElementById('{{ $uid }}FromInput');
    const toInput   = isRange ? document.getElementById('{{ $uid }}ToInput') : null;
    const applyBtn  = document.getElementById('{{ $uid }}Apply');
    const closeBtn  = document.getElementById('{{ $uid }}Close');

    // Moved to a direct child of <body>, escaping <main>'s overflow-x-hidden —
    // see the panel's own comment in the markup above for why a `fixed`
    // descendant of an `overflow-hidden` ancestor can't be trusted not to get
    // clipped on mobile. Position is computed entirely here instead of via
    // CSS anchored to the trigger, since the panel's DOM parent is no longer
    // near the trigger at all once it's a body child.
    document.body.appendChild(panel);

    function positionPanel() {
        const rect = trigger.getBoundingClientRect();
        const isMobile = !window.matchMedia('(min-width: 640px)').matches;

        if (isMobile) {
            // Centered horizontally — the panel's width now caps at 330px
            // (min(330px, 100vw-2rem)), not a full-width span of the
            // viewport, so a fixed 1rem-from-left offset would leave it
            // lopsided with empty space on the right on anything wider than
            // ~362px. transform centers it regardless of the panel's actual
            // (possibly viewport-clamped) width.
            panel.style.left      = '50%';
            panel.style.right     = '';
            panel.style.top       = '5rem';
            panel.style.transform = 'translateX(-50%)';
        } else {
            // Desktop: same visual result as the old `absolute right-0
            // top-full mt-2` (below the trigger, right edges aligned), just
            // computed from the trigger's real viewport position now that
            // the panel isn't DOM-adjacent to it any more.
            let right = window.innerWidth - rect.right;
            panel.style.right     = `${right}px`;
            panel.style.left      = '';
            panel.style.top       = `${rect.bottom + 8}px`;
            panel.style.transform = ''; // clear a leftover mobile centering transform

            // Clamp so the panel never runs UNDER the sidebar — first attempt
            // (2026-08-24) only clamped against the bare viewport edge (x=0),
            // which turned out not to be the actual left boundary: layouts/
            // app.blade.php's #sidebar is a persistent w-64 (256px) column with
            // its own manual collapse toggle, completely independent of this
            // picker's own 640px mobile/desktop breakpoint — so a panel sitting
            // anywhere left of x=256 (confirmed live via the browser console:
            // `right: 642px` computed with no clamp applied at all, since
            // panelLeft was still >= the old flat 8px margin) was satisfying
            // that old check while still landing squarely underneath the
            // sidebar. Reads the sidebar's REAL current right edge instead of
            // a guessed constant — 0 whenever it's off-screen (mobile drawer,
            // `-translate-x-full`) or collapsed to its icon-only width, so this
            // never over-clamps on those layouts. panel.offsetWidth only reads
            // correctly once the panel is actually in the render tree (not
            // `hidden`/display:none) — the caller unhides it before calling
            // this, in the same synchronous click handler, so there's no
            // visible flicker before this repositions it.
            const sidebarRight = document.getElementById('sidebar')?.getBoundingClientRect().right ?? 0;
            const margin    = Math.max(8, sidebarRight + 8);
            const panelLeft = window.innerWidth - right - panel.offsetWidth;
            if (panelLeft < margin) {
                right = window.innerWidth - margin - panel.offsetWidth;
                panel.style.right = `${right}px`;
            }
        }
    }

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
    // showLabel triggers instead have a visible text span — kept in sync here too.
    const labelSpan = document.getElementById('{{ $uid }}Label');
    const updateLabel = () => {
        const isSameDay  = !isRange || selFrom === selTo || !selTo;
        const labelText  = isSameDay ? fmt(selFrom) : fmt(selFrom) + '  –  ' + fmt(selTo);
        trigger.title = labelText;
        trigger.setAttribute('aria-label', `Change date${isRange ? ' range' : ''}, currently ${labelText}`);

        if (labelSpan) labelSpan.textContent = labelText;

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
            // Range mode shows rangeMonths side by side for span picking (Dashboard's
            // original picker: 2; Sync Health's "Fix Now": 1 — still a real range,
            // narrower calendar); single-date report pages stay on one month. Forced
            // to 1 below the sm breakpoint regardless of mode — multiple months
            // side by side can't fit even at the panel's full mobile width without
            // overflowing again.
            showMonths : isRange && window.matchMedia('(min-width: 640px)').matches ? {{ $rangeMonths }} : 1,
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
            // Unhide BEFORE positioning (reordered 2026-08-24) — positionPanel()'s
            // left-edge clamp now needs panel.offsetWidth, which reads 0 while the
            // panel is still `hidden` (display:none has no box to measure). Both
            // calls happen synchronously in this one handler, so the panel never
            // actually paints at the old, unclamped position first.
            panel.classList.remove('hidden');
            positionPanel();
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

    // Orientation change / resize while open — the trigger's position (desktop)
    // or the viewport itself (mobile) can both shift without the panel closing.
    window.addEventListener('resize', () => {
        if (!panel.classList.contains('hidden')) positionPanel();
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
            const url = '{{ $navigateBase }}?date_from=' + selFrom + '&date_to=' + selTo;
            // Soft refresh: swap the page content in place (no full reload, no
            // scroll loss); the URL still updates via pushState. Falls back to
            // a real navigation if the in-place refresh fails.
            if (window.softRefresh) {
                window.softRefresh(url, { pushUrl: true, showLoading: true }).then(ok => { if (!ok) window.location.href = url; });
            } else {
                window.location.href = url;
            }
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

        // Range-aware forms (Leads Report's rolling "Last 24h" vs explicit dates)
        // carry a hidden [name="range"] field. Picking calendar dates is an explicit
        // choice of fixed-dates mode — flip the field so the picked dates aren't
        // ignored in favor of the sticky rolling window.
        const rangeField = form.querySelector('[name="range"]');
        if (rangeField) rangeField.value = 'dates';

        // autoSubmit=false: a separate button elsewhere in the form (e.g. Sync
        // Health's "Retry Sync") is the deliberate action trigger — Apply here
        // only updates the field(s) and closes the panel.
        if (!autoSubmit) return;

        // requestSubmit (not submit) so the submit EVENT fires — app.js
        // intercepts GET form submits there and soft-refreshes in place
        // instead of doing a full page navigation.
        form.requestSubmit();
    });
})();
</script>
