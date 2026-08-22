<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
    (function () {
        // Default is light for anyone who hasn't explicitly chosen a theme yet
        // (no stored preference) — deliberately ignores the OS/browser's own
        // prefers-color-scheme, which would otherwise silently start a new
        // user in dark mode just because their device defaults to it.
        const stored = localStorage.getItem('theme');
        const isDark = stored === 'dark';
        if (isDark) document.documentElement.classList.add('dark');

        // Same reasoning as dark mode above: applied on <html> here, before
        // <body> (and #sidebar) exist yet, so the sidebar never flashes wide
        // then snaps narrow (or vice versa) on every page load. Desktop-only
        // concept — see the media query in the <style> block below, which
        // scopes every rule this class drives to md: (768px) and up, matching
        // where the sidebar itself switches from an off-canvas mobile drawer
        // to a permanently static column.
        if (localStorage.getItem('sidebarCollapsed') === '1') {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>TSD Reports — @yield('title', 'Dashboard')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
    /* Shared flatpickr styling for the date-picker partial (partials/date-picker.blade.php)
       — global, not scoped to one page's element IDs, so every report that includes
       the partial gets the identical calendar look. */
    [id$="Calendar"] .flatpickr-calendar { box-shadow: none !important; border: none !important; background: transparent !important; }
    [id$="Calendar"] .flatpickr-innerContainer { display: flex; gap: 0; }
    [id$="Calendar"] .flatpickr-rContainer { flex: 1; }

    /* Day-grid width lock — applies to BOTH single and range pickers, and is what keeps
       weekday headers and day cells column-aligned. Why it's required: the .flatpickr-day
       rule below caps each cell at max-width:34px, and .dayContainer uses flex-wrap. At
       flatpickr's natural 307.875px width, floor(307.875 / 34) = 9 cells wrap per row —
       but there are only 7 weekday headers, so every date shifts out of its column. Pinning
       the container to 270px makes floor(270 / 34) = 7 cells per row, matching the 7 headers.
       Range mode additionally needs this so two months (2 × 270 = 540px) fit inside the panel
       instead of overflowing at 2 × 307.875. Both the day grid and its weekday header are
       pinned to the same 270px so their columns line up. */
    [id$="Calendar"] .flatpickr-days,
    [id$="Calendar"] .flatpickr-weekdays { width: auto !important; }
    [id$="Calendar"] .dayContainer,
    [id$="Calendar"] .flatpickr-weekdaycontainer {
        width: 270px !important;
        min-width: 270px !important;
        max-width: 270px !important;
    }

    /* Mobile: shrink the day-grid + cells (7 x 27px = 189px, same alignment
       logic as above just smaller) so the panel can stay side-by-side
       (sidebar + calendar), matching the desktop layout exactly instead of
       stacking. Sized with real safety margin verified on a 320px-wide
       phone (iPhone SE, the narrowest realistic target): sidebar 72px +
       calendar grid 189px + its own padding ~12px = ~273px, comfortably
       under the ~288px actually available there (100vw-2rem) — confirmed
       by measuring rendered element widths directly, not just eyeballing a
       screenshot (an earlier, less conservative sizing looked fine in a
       screenshot but measured out to a real ~24px overflow). */
    @media (max-width: 639px) {
        [id$="Calendar"] .dayContainer,
        [id$="Calendar"] .flatpickr-weekdaycontainer {
            width: 189px !important;
            min-width: 189px !important;
            max-width: 189px !important;
        }
        /* The outer wrappers around the day-grid (.flatpickr-calendar itself,
           plus .innerContainer/.rContainer) keep their own separate ~307.875px
           natural width regardless of the day-grid override above — desktop
           never notices (its panel has plenty of slack past 307.875px), but
           on mobile that phantom extra width genuinely overflows past the
           viewport. Confirmed via direct measurement: dayContainer correctly
           shrunk, but .innerContainer/.rContainer stayed 307.875px wide. */
        [id$="Calendar"] .flatpickr-calendar,
        [id$="Calendar"] .flatpickr-innerContainer,
        [id$="Calendar"] .flatpickr-rContainer {
            width: 189px !important;
            max-width: 189px !important;
        }
        .flatpickr-day {
            font-size: 0.62rem !important;
            height: 27px !important;
            line-height: 27px !important;
            max-width: 27px !important;
        }
        .flatpickr-weekday { font-size: 0.58rem !important; }
        .flatpickr-current-month { font-size: 0.72rem !important; }
    }

    /* Base day cell: circular, generous tap target, legible by default */
    .flatpickr-day {
        font-family: ui-monospace, monospace;
        font-size: 0.78rem !important;
        font-weight: 600;
        color: #334155 !important;
        height: 34px !important;
        line-height: 34px !important;
        max-width: 34px !important;
        border-radius: 9999px !important;
        margin: 1px 0;
        transition: background-color .12s ease, color .12s ease, transform .1s ease;
    }
    .flatpickr-day:hover {
        background: #fefce8 !important;
        color: #a16207 !important;
        transform: scale(1.05);
    }

    /* Fix: disabled + adjacent-month days were near-invisible (very low default
       contrast). Keep them visibly muted, not unreadable. */
    .flatpickr-day.flatpickr-disabled,
    .flatpickr-day.flatpickr-disabled:hover {
        color: #cbd5e1 !important;
        background: transparent !important;
        cursor: not-allowed;
        transform: none;
    }
    .flatpickr-day.prevMonthDay,
    .flatpickr-day.nextMonthDay {
        color: #cbd5e1 !important;
    }

    /* Today: outlined ring, not filled — distinct from a selected day */
    .flatpickr-day.today {
        border: 2px solid #fde047 !important;
        color: #a16207 !important;
        font-weight: 700;
    }
    .flatpickr-day.today:hover { background: #fefce8 !important; }

    /* Range styling: light fill for the span, solid caps at start/end */
    .flatpickr-day.inRange {
        background: #fef9c3 !important;
        box-shadow: -5px 0 0 #fef9c3, 5px 0 0 #fef9c3 !important;
        border-radius: 9999px !important;
        color: #a16207 !important;
    }
    .flatpickr-day.startRange,
    .flatpickr-day.endRange,
    .flatpickr-day.selected {
        background: linear-gradient(135deg, #ca8a04, #a16207) !important;
        border-color: #a16207 !important;
        color: #fff !important;
        box-shadow: 0 2px 6px rgba(202, 138, 4, .35);
    }
    .flatpickr-day.startRange:hover,
    .flatpickr-day.endRange:hover,
    .flatpickr-day.selected:hover { transform: scale(1.08); }

    /* Month header + nav */
    .flatpickr-months .flatpickr-month { color: #1e293b !important; }
    .flatpickr-current-month { font-family: ui-monospace, monospace; font-size: 0.85rem !important; font-weight: 700; }
    .flatpickr-current-month .cur-month { color: #1e293b !important; }
    .flatpickr-months .flatpickr-prev-month,
    .flatpickr-months .flatpickr-next-month {
        border-radius: 9999px;
        padding: 6px !important;
        transition: background-color .12s ease;
    }
    .flatpickr-months .flatpickr-prev-month:hover,
    .flatpickr-months .flatpickr-next-month:hover { background: #fefce8 !important; }
    .flatpickr-months .flatpickr-prev-month svg,
    .flatpickr-months .flatpickr-next-month svg { fill: #64748b !important; }

    /* Weekday row */
    .flatpickr-weekdays { margin-top: 4px; }
    .flatpickr-weekday {
        color: #94a3b8 !important;
        font-family: ui-monospace, monospace;
        font-size: 0.65rem !important;
        font-weight: 700;
        text-transform: uppercase;
    }

    /* ─── Dark mode ───────────────────────────────────────────────────────────
       Same rules as above, mirrored for a dark surface. The calendar's own
       background stays transparent in light mode because it sits on the white
       date-picker panel (partials/date-picker.blade.php); in dark mode that
       panel is dark (bg-slate-900), so these overrides just re-tune text/fill
       colors for legibility — the transparent background itself is unchanged. */
    .dark .flatpickr-day { color: #e2e8f0 !important; }
    .dark .flatpickr-day:hover {
        background: rgba(202,138,4,0.18) !important;
        color: #fde047 !important;
    }

    .dark .flatpickr-day.flatpickr-disabled,
    .dark .flatpickr-day.flatpickr-disabled:hover {
        color: #475569 !important;
        background: transparent !important;
    }
    .dark .flatpickr-day.prevMonthDay,
    .dark .flatpickr-day.nextMonthDay {
        color: #475569 !important;
    }

    .dark .flatpickr-day.today {
        border-color: #ca8a04 !important;
        color: #fde047 !important;
    }
    .dark .flatpickr-day.today:hover { background: rgba(202,138,4,0.18) !important; }

    .dark .flatpickr-day.inRange {
        background: rgba(202,138,4,0.22) !important;
        box-shadow: -5px 0 0 rgba(202,138,4,0.22), 5px 0 0 rgba(202,138,4,0.22) !important;
        color: #fde047 !important;
    }
    .dark .flatpickr-day.startRange,
    .dark .flatpickr-day.endRange,
    .dark .flatpickr-day.selected {
        background: linear-gradient(135deg, #ca8a04, #a16207) !important;
        border-color: #eab308 !important;
        color: #fff !important;
    }

    .dark .flatpickr-months .flatpickr-month { color: #e2e8f0 !important; }
    .dark .flatpickr-current-month .cur-month { color: #e2e8f0 !important; }
    .dark .flatpickr-months .flatpickr-prev-month:hover,
    .dark .flatpickr-months .flatpickr-next-month:hover { background: rgba(202,138,4,0.18) !important; }
    .dark .flatpickr-months .flatpickr-prev-month svg,
    .dark .flatpickr-months .flatpickr-next-month svg { fill: #94a3b8 !important; }

    .dark .flatpickr-weekday {
        color: #64748b !important;
    }

    /* Sticky first column — TSA Performance's pivot table and Leads Report's
       Grand Total/per-product tables (marked cells only, e.g. .sticky-col on
       the "TSA's"/"Time" header and each row's name/time cell — NOT a blanket
       `:first-child` selector, since these tables' rowspan="2" header means
       the second header row's own first child is a totally different column;
       a structural selector would stick the wrong one).
       A sticky cell needs its OWN opaque background — its row's hover/total
       background alone (usually set on the <tr>, not each <td>) doesn't paint
       behind a sticky child once other columns scroll independently beneath
       it, since the row itself moves with the horizontal scroll while the
       sticky cell doesn't. Three variants below match every row style that
       actually has a first-column cell across both tables:
       .sticky-col-body (plain rows, white/slate-900 + the same hover tint),
       .sticky-col-subtotal (TSA Performance's per-hour TOTAL rows, slate-800),
       .sticky-col-footer (TSA Performance's Grand Total / Leads Report's
       Total rows, slate-900). */
    .sticky-col {
        position: sticky;
        left: 0;
        z-index: 5;
    }
    /* The header's own sticky-col cell needs to out-rank both a competing
       sticky-top header (TSA Performance's) and any sticky-left body cell
       scrolling vertically beneath it — the top-left corner always wins. */
    thead .sticky-col { z-index: 25; }

    .sticky-col-body { background-color: #fff; }
    .dark .sticky-col-body { background-color: #0f172a; }
    tr:hover > .sticky-col-body { background-color: #f8fafc; }
    .dark tr:hover > .sticky-col-body { background-color: #1e293b; }

    .sticky-col-subtotal { background-color: #1e293b; }
    .sticky-col-footer   { background-color: #0f172a; }

    /* ─── Collapsible sidebar (icon-only rail) ───────────────────────────────
       Desktop-only (md: / 768px+, matching #sidebar's own static/off-canvas
       breakpoint) — the mobile drawer always shows full labels; collapsing
       only makes sense once the sidebar is a permanent, space-competing
       column. Toggled by #sidebarCollapseToggle (see the script near the
       bottom of this file); state read/written via localStorage, applied to
       <html> before first paint (see the <head> script above) to avoid a
       flash of the wrong width. Width goes from Tailwind's w-64 (16rem) down
       to 5rem — enough for the 18px icon plus its existing px-3 padding,
       centered instead of left-aligned once the label next to it is gone. */
    /* #sidebar's own width transition — not gated by the md: media query below
       since the property/duration declaration is harmless at any width (the
       toggle that actually flips html.sidebar-collapsed is itself md:-only,
       so this simply never has anything to animate below that breakpoint).
       Labels animate via max-width + opacity instead of a display:none swap —
       display can't be transitioned, so that would otherwise snap instantly
       regardless of how smoothly the sidebar itself resizes around it.
       overflow:hidden + white-space:nowrap keep the shrinking text from
       wrapping mid-animation; max-width is set well above any real label's
       rendered width so nothing is ever clipped at full size. */
    #sidebar { transition: width 200ms ease-out; }
    .sidebar-label, .sidebar-section-label, .sidebar-user-info {
        max-width: 14rem;
        opacity: 1;
        overflow: hidden;
        white-space: nowrap;
        transition: max-width 200ms ease-out, opacity 150ms ease-out;
    }
    @media (min-width: 768px) {
        html.sidebar-collapsed #sidebar { width: 5rem; }
        /* The logo row's own px-6 padding (24px each side) leaves only ~32px
           of content width at 5rem collapsed — not enough for the 36px logo
           icon at its normal left-aligned spot. Tighten the padding and
           center it (the collapse toggle itself lives outside this row now,
           floating on the sidebar's edge — see the button above). */
        html.sidebar-collapsed #sidebar .sidebar-header { padding-left: 0.75rem; padding-right: 0.75rem; }
        html.sidebar-collapsed #sidebar .sidebar-header-row { justify-content: center; }
        html.sidebar-collapsed #sidebar .sidebar-label,
        html.sidebar-collapsed #sidebar .sidebar-section-label,
        html.sidebar-collapsed #sidebar .sidebar-user-info { max-width: 0; opacity: 0; }
        html.sidebar-collapsed #sidebar .nav-item { justify-content: center; }
        /* Avatar + sign-out button side by side don't fit the narrow 5rem
           rail once gap/padding are accounted for — stack them instead so
           neither gets squeezed or clipped. */
        html.sidebar-collapsed #sidebar .sidebar-footer-row {
            flex-direction: column;
            justify-content: center;
            gap: 0.5rem;
        }
        /* Settings' "API key not configured" dot uses ml-auto to sit at the
           far right of the row next to its (now-hidden) label — with the
           label gone, that would push it away from the icon it's meant to
           badge. Reposition as a small corner badge on the icon instead. */
        html.sidebar-collapsed #sidebar .nav-item { position: relative; }
        html.sidebar-collapsed #sidebar .settings-alert-dot {
            margin-left: 0;
            position: absolute;
            top: 6px;
            right: 20px;
        }
        /* The collapse toggle's own icon flips to point the other way — a
           single shared SVG rotated via CSS, not two swapped icons (the
           dark-mode toggle's approach), since this is a pure 180° mirror with
           nothing else changing between the two states. */
        html.sidebar-collapsed #sidebarCollapseToggle svg { transform: rotate(180deg); }
    }
    </style>
    @stack('head')
</head>
<body class="flex h-screen overflow-hidden bg-slate-100 dark:bg-slate-950">

{{-- Toast notifications — populated by window.showToast() (resources/js/app.js).
     z-[70]: above the sidebar (z-50) and its mobile backdrop (z-40), so a toast
     is never hidden behind either, including with the mobile drawer open.
     pointer-events-none on the container so the empty space around toasts
     doesn't block clicks on the page underneath; each toast card opts back in
     via pointer-events-auto so its own close button still works. No aria-live
     here: each toast sets its own role (alert/status) in app.js, and nesting
     an assertive-role toast inside a polite live-region container is a known
     source of inconsistent screen-reader behavior across NVDA/JAWS/VoiceOver. --}}
<div id="toastContainer"
     class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none"></div>

{{-- Shared confirm modal — window.showConfirm(message, opts) in app.js. Every
     page's destructive-action confirmation (delete, bulk delete, move team,
     etc.) uses this instead of the browser's native confirm(), which always
     prefixes its dialog with the page's own hostname ("localhost:8000 says")
     and can't be styled. z-[60]: above any page's own modal (z-50, e.g.
     Product/TSA Management's Add/Edit forms), below the toast stack. --}}
<div id="confirmModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 px-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5">
            <h3 id="confirmModalTitle" class="text-sm font-bold text-slate-800 dark:text-slate-100">Are you sure?</h3>
            <p id="confirmModalMessage" class="text-sm text-slate-600 dark:text-slate-400 mt-2"></p>
        </div>
        <div class="px-6 pb-5 flex items-center justify-end gap-2">
            <button type="button" id="confirmModalCancel"
                    class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                Cancel
            </button>
            <button type="button" id="confirmModalConfirm"
                    class="px-4 py-2 text-xs font-semibold text-white rounded-lg transition-colors cursor-pointer">
                Confirm
            </button>
        </div>
    </div>
</div>

{{-- Mobile-only backdrop, shown behind the sidebar while it's open as an overlay drawer --}}
<div id="sidebarBackdrop" class="hidden fixed inset-0 bg-black/50 z-40 md:hidden"></div>

{{-- ═══════════════════ SIDEBAR ═══════════════════ --}}
{{-- Below md: a fixed, off-canvas drawer (toggled by the hamburger button in the
     header) sliding in over the content. At md+: back to a normal static column,
     always visible, exactly like before. --}}
<aside id="sidebar"
       class="fixed md:relative inset-y-0 left-0 z-50 w-64 shrink-0 bg-sidebar flex flex-col h-full shadow-xl
              -translate-x-full md:translate-x-0 transition-transform duration-200 ease-out">

    {{-- Collapse/expand — a small circular button floating on the sidebar's own
         right edge (half on, half off), not inline in the header — desktop-only
         (see the media-query'd html.sidebar-collapsed rules in <style> above);
         the mobile drawer has its own open/close mechanism below and never
         collapses to icon-only. #sidebar needed md:relative (was md:static) so
         this can anchor to the SIDEBAR itself, not whatever positioned ancestor
         happened to be next up the tree. --}}
    <button id="sidebarCollapseToggle" type="button" aria-label="Collapse sidebar" title="Collapse sidebar"
            class="hidden md:flex absolute -right-3.5 top-16 z-10 items-center justify-center w-7 h-7 rounded-full
                   bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-md
                   text-slate-500 dark:text-slate-300 hover:text-primary hover:border-primary transition-colors cursor-pointer">
        <svg class="w-3.5 h-3.5 transition-transform duration-200" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
    </button>

    {{-- Logo --}}
    <div class="sidebar-header px-6 py-5 border-b border-white/10">
        <div class="sidebar-header-row flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-secondary flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                    </svg>
                </div>
                <div class="min-w-0 sidebar-label">
                    <div class="text-white font-bold text-sm leading-tight font-mono truncate">TSD Reports</div>
                    <div class="text-yellow-300 text-[10px] font-mono tracking-widest uppercase truncate">Telesales Dashboard</div>
                </div>
            </div>
            <button id="sidebarClose" type="button" aria-label="Close menu"
                    class="md:hidden shrink-0 p-1.5 rounded-lg text-yellow-200 hover:bg-white/10 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 px-3 py-5 space-y-1 overflow-y-auto">

        {{-- Explicit request (2026-08-13): a back button to the Hub — the
             one shared switcher between TSD Reports and Call Tracker. --}}
        <a href="{{ route('hub') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span class="sidebar-label">Back to Hub</span>
        </a>
        <div class="my-3 border-t border-white/10"></div>

        <p class="sidebar-section-label px-3 mb-2 text-[10px] font-mono font-semibold tracking-widest text-yellow-400/60 uppercase">Main</p>

        <a href="{{ route('dashboard') }}" title="Dashboard"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('dashboard') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <span class="sidebar-label">Dashboard</span>
        </a>

        <a href="{{ route('leads-report') }}" title="Leads Report"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('leads-report') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <span class="sidebar-label">Leads Report</span>
        </a>

        <a href="{{ route('tsa-performance') }}" title="TSA Performance"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('tsa-performance') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
            </svg>
            <span class="sidebar-label">TSA Performance</span>
        </a>

        <a href="{{ route('charts') }}" title="Analytics"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('charts') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            <span class="sidebar-label">Analytics</span>
        </a>

        <a href="{{ route('rts-report') }}" title="RTS / Delivered"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('rts-report') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
            </svg>
            <span class="sidebar-label">RTS / Delivered</span>
        </a>

        @if(auth()->user()?->isAtLeastAdmin())
        <div class="my-3 border-t border-white/10"></div>
        <p class="sidebar-section-label px-3 mb-2 text-[10px] font-mono font-semibold tracking-widest text-yellow-400/60 uppercase">Config</p>

        <a href="{{ route('tsa-management') }}" title="TSA Management"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('tsa-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <span class="sidebar-label">TSA Management</span>
        </a>

        <a href="{{ route('product-management') }}" title="Product Management"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('product-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
            </svg>
            <span class="sidebar-label">Product Management</span>
        </a>

        <a href="{{ route('user-management') }}" title="User Management"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('user-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <span class="sidebar-label">User Management</span>
        </a>

        <a href="{{ route('sync-health') }}" title="Sync Health"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('sync-health*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h3l2.25-7.5 4.5 15L16.5 12H19.5"/>
            </svg>
            <span class="sidebar-label">Sync Health</span>
        </a>

        <a href="{{ route('audit-log') }}" title="Activity Log"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('audit-log*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 12h3.75M9 15h3.75M9 18h3.75M3.75 4.5h10.5M3.75 4.5v15A2.25 2.25 0 006 21.75h12A2.25 2.25 0 0020.25 19.5V8.25a2.25 2.25 0 00-.659-1.591l-3.5-3.5A2.25 2.25 0 0014.5 2.5h-8.75A2.25 2.25 0 003.5 4.75z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 3v3.75a1.5 1.5 0 001.5 1.5h3.75"/>
            </svg>
            <span class="sidebar-label">Activity Log</span>
        </a>

        <a href="{{ route('unmatched-orders') }}" title="Unmatched Orders"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('unmatched-orders*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75c0-1.036.84-1.875 1.875-1.875h.75c1.036 0 1.875.84 1.875 1.875v.375c0 .621-.334 1.115-.807 1.454-.548.393-.943.978-.943 1.671v.25"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5h.008v.008H12V16.5z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z"/>
            </svg>
            <span class="sidebar-label">Unmatched Orders</span>
        </a>

        <a href="{{ route('tsa-tag-mismatches') }}" title="TSA Tag Mismatches"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('tsa-tag-mismatches*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/>
            </svg>
            <span class="sidebar-label">TSA Tag Mismatches</span>
        </a>

        <a href="{{ route('settings') }}" title="Settings"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('settings*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <span class="sidebar-label">Settings</span>
            @if(!config('services.pancake.api_key'))
                <span class="settings-alert-dot ml-auto w-2 h-2 rounded-full bg-red-400 shrink-0"></span>
            @endif
        </a>
        @endif

    </nav>

    {{-- Footer — signed-in user + sign out. Sign out is destructive-adjacent (ends the
         session) so it's spatially separated from the nav items above via the border
         and lives in its own row, not mixed into the nav list. --}}
    <div class="px-4 py-4 border-t border-white/10">
        <div class="sidebar-footer-row flex items-center gap-3 min-w-0" title="{{ auth()->user()->name ?? 'TSD Admin' }}">
            <div class="w-8 h-8 rounded-full bg-yellow-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'TSD', 0, 1)) }}
            </div>
            <div class="sidebar-user-info min-w-0 flex-1">
                <div class="text-white text-xs font-semibold truncate">{{ auth()->user()->name ?? 'TSD Admin' }}</div>
                <div class="text-yellow-400 text-[10px] truncate">{{ auth()->user()->email ?? 'Pancake POS' }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" aria-label="Sign out" title="Sign out"
                        class="shrink-0 p-1.5 rounded-lg text-yellow-200 hover:bg-white/10 hover:text-white transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>

{{-- ═══════════════════ MAIN CONTENT ═══════════════════ --}}
{{-- min-w-0 lets this flex child shrink below its content's width; without it, a wide table
     inside pushes the whole page wider than the viewport instead of scrolling internally. --}}
<div class="flex-1 flex flex-col min-h-0 min-w-0">

    {{-- Top bar — plain flex row, two groups: (hamburger + title + search) on the
         left, controls on the right. Search deliberately sits right next to the
         title now (explicit request), not centered in its own grid track the way
         it used to be — see git history for the old 3-column centering approach
         if this ever needs revisiting.

         lg:flex-nowrap, not md: — the sidebar itself switches from an overlay
         drawer to permanently static (id="sidebar", md:static above) at exactly
         the same md: breakpoint, eating 256px of viewport width right as this
         row would otherwise try to force itself onto one line. Confirmed live:
         forcing nowrap at md: (768px) caused real horizontal page overflow on
         8 of 13 report pages specifically in the 768-840px window — a real
         tablet-portrait viewport width, not just a narrow desktop window — since
         title+search's combined content doesn't fit in the ~450-500px left over
         once the sidebar claims its 256px. Wrapping (the same behavior already
         used below md:) all the way through lg: avoids that dead zone entirely;
         nowrap only kicks in once the sidebar's static width is no longer a
         meaningful fraction of the viewport. --}}
    <header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 px-4 md:px-8 py-4 flex items-center justify-between gap-3 flex-wrap lg:flex-nowrap shrink-0 shadow-sm">
        <div class="flex items-center gap-4 min-w-0 flex-1 flex-wrap lg:flex-nowrap">
            <button id="sidebarToggle" type="button" aria-label="Open menu"
                    class="md:hidden shrink-0 p-2 -ml-2 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <div class="min-w-0 shrink-0">
                <h1 class="text-lg font-bold text-slate-800 dark:text-slate-100 font-mono truncate">@yield('title', 'Dashboard')</h1>
                <p class="text-xs text-slate-400 mt-0.5 truncate">@yield('subtitle', 'TSD Reports · Pancake POS Integration')</p>
            </div>

            {{-- Global search — TSA agents and Products only (see SearchController for
                 why orders aren't included). Debounced fetch to /search, results
                 grouped by type in a dropdown below the input. shrink-0 so it keeps its
                 own width instead of getting squeezed as the title truncates; wraps
                 onto its own row below the title on narrow screens instead of both
                 competing for the same line. --}}
            <div class="relative w-full sm:w-56 md:w-64 shrink-0 basis-full sm:basis-auto">
                <div class="relative">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                    </svg>
                    <input type="text" id="globalSearchInput" autocomplete="off" placeholder="Search TSA or product…"
                        aria-label="Search TSA agents and products"
                        class="w-full pl-8 pr-3 py-1.5 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition-colors">
                </div>
                <div id="globalSearchResults" class="hidden absolute left-0 right-0 top-full mt-1 z-50 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 max-h-80 overflow-y-auto"></div>
            </div>
        </div>

        {{-- flex-wrap here so the toggle wraps as a true peer alongside the
             pushed topbar-right content (which now uses display:contents to
             flatten its own nested wrappers) — one single wrap context
             instead of several independently-wrapping ones. lg:, not md: —
             same sidebar-eats-768px-of-width reasoning as the title+search
             group's own comment above. --}}
        <div class="flex items-center justify-end gap-3 flex-wrap lg:flex-nowrap md:justify-self-end">
            @stack('topbar-right')

            {{-- Reload — a fixed, always-present control (same reasoning as the dark
                 mode toggle below): re-fetches and re-renders the CURRENT view from
                 already-synced local data via softRefresh, with no outbound Pancake
                 POS call — unlike Sync (Dashboard-only), which triggers a real sync
                 and can take a while. Useful after TSA/Product config changes
                 elsewhere, or just to pull the latest already-synced numbers without
                 waiting on the next scheduled sync. --}}
            <button id="reloadBtn" type="button" aria-label="Reload this view" title="Reload — refresh from already-synced data, no new sync"
                    class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer">
                <svg id="reloadIcon" class="w-4 h-4 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </button>

            {{-- Dark mode — a fixed, always-present control (unlike the stack above,
                 which varies per page), so it's in the same spot everywhere instead
                 of living down in the sidebar footer where it was easy to miss. --}}
            <button id="themeToggle" type="button" aria-label="Toggle dark mode" title="Toggle dark mode"
                    class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer">
                <svg id="themeIconSun" class="w-4.5 h-4.5 hidden text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <svg id="themeIconMoon" class="w-4.5 h-4.5 hidden text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
            </button>
        </div>
    </header>

    {{-- Scrollable page content — wrapped in a positioning parent so
         #loadingOverlay (a sibling, not a child, so <main>'s innerHTML swap
         on softRefresh never wipes it out) can cover exactly this area. --}}
    <div class="relative flex-1 min-h-0">
        <main class="absolute inset-0 overflow-y-auto overflow-x-hidden p-4 md:p-8">
            @yield('content')
        </main>

        {{-- Shown by softRefresh (resources/js/app.js) for user-initiated topbar
             filter changes (team/product/date) on every report page — so a table
             that takes a moment to refresh never just sits there looking frozen.
             Left hidden for the silent 2-minute background refresh, which is
             deliberately invisible. --}}
        <div id="loadingOverlay" class="hidden absolute inset-0 z-30 bg-white/60 dark:bg-slate-950/60 backdrop-blur-[1px] flex items-start justify-center pt-24">
            <div class="flex items-center gap-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-full pl-3 pr-4 py-2 shadow-lg">
                <svg class="animate-spin w-4 h-4 text-primary shrink-0" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span class="text-xs font-mono font-semibold text-slate-600 dark:text-slate-300">Loading…</span>
            </div>
        </div>
    </div>

</div>

{{-- Mobile sidebar drawer toggle — shared across every page via this layout --}}
<script>
(function () {
    const sidebar  = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const openBtn  = document.getElementById('sidebarToggle');
    const closeBtn = document.getElementById('sidebarClose');

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        backdrop.classList.remove('hidden');
    }
    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
    }

    openBtn?.addEventListener('click', openSidebar);
    closeBtn?.addEventListener('click', closeSidebar);
    backdrop?.addEventListener('click', closeSidebar);

    // Switching to a desktop width (e.g. rotating a tablet) shouldn't leave the
    // drawer open-but-invisible behind the now-static sidebar.
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) closeSidebar();
    });

    // Desktop icon-only collapse — see the html.sidebar-collapsed rules in
    // <style> above and the pre-paint script in <head> (avoids a flash of the
    // wrong width on load, same approach as the dark-mode toggle).
    const collapseBtn = document.getElementById('sidebarCollapseToggle');
    function setCollapseLabel(collapsed) {
        collapseBtn?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        collapseBtn?.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
    // The <head> script already applied the class before this ran — just
    // reflect it in the button's label rather than assuming "not collapsed".
    setCollapseLabel(document.documentElement.classList.contains('sidebar-collapsed'));
    collapseBtn?.addEventListener('click', () => {
        const collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
        setCollapseLabel(collapsed);
    });
})();
</script>

{{-- One-shot toast for this request's flashed session message, if any — this is
     what every controller's ->with('success', ...) now renders as (replacing
     the old per-page inline banner blocks). Deferred to DOMContentLoaded
     because @vite's app.js is a module script (defers like a classic `defer`
     script): window.showToast isn't defined yet at the point this inline
     script is parsed, only by the time DOMContentLoaded fires — module/defer
     scripts always run before that event. --}}
@if(session('success') || session('error') || session('info'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if(session('success')) window.showToast(@json(session('success')), 'success'); @endif
    @if(session('error'))   window.showToast(@json(session('error')),   'error');   @endif
    @if(session('info'))    window.showToast(@json(session('info')),    'info');    @endif
});
</script>
@endif

@stack('scripts')
</body>
</html>
