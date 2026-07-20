<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
    (function () {
        const stored = localStorage.getItem('theme');
        const isDark = stored === 'dark' || (stored === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (isDark) document.documentElement.classList.add('dark');
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

{{-- Mobile-only backdrop, shown behind the sidebar while it's open as an overlay drawer --}}
<div id="sidebarBackdrop" class="hidden fixed inset-0 bg-black/50 z-40 md:hidden"></div>

{{-- ═══════════════════ SIDEBAR ═══════════════════ --}}
{{-- Below md: a fixed, off-canvas drawer (toggled by the hamburger button in the
     header) sliding in over the content. At md+: back to a normal static column,
     always visible, exactly like before. --}}
<aside id="sidebar"
       class="fixed md:static inset-y-0 left-0 z-50 w-64 shrink-0 bg-sidebar flex flex-col h-full shadow-xl
              -translate-x-full md:translate-x-0 transition-transform duration-200 ease-out">

    {{-- Logo --}}
    <div class="px-6 py-5 border-b border-white/10">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-accent flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.5l7-7 4 4 6.5-6.5"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 7h-4v4"/>
                    </svg>
                </div>
                <div class="min-w-0">
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

        <p class="px-3 mb-2 text-[10px] font-mono font-semibold tracking-widest text-yellow-400/60 uppercase">Main</p>

        <a href="{{ route('dashboard') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('dashboard') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            Dashboard
        </a>

        <a href="{{ route('leads-report') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('leads-report') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Leads Report
        </a>

        <a href="{{ route('tsa-performance') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('tsa-performance') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/>
            </svg>
            TSA Performance
        </a>

        <a href="{{ route('charts') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('charts') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            Analytics
        </a>

        <a href="{{ route('rts-report') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('rts-report') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
            </svg>
            RTS / Delivered
        </a>

        @if(auth()->user()?->isAtLeastAdmin())
        <div class="my-3 border-t border-white/10"></div>
        <p class="px-3 mb-2 text-[10px] font-mono font-semibold tracking-widest text-yellow-400/60 uppercase">Config</p>

        <a href="{{ route('tsa-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('tsa-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            TSA Management
        </a>

        <a href="{{ route('product-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('product-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
            </svg>
            Product Management
        </a>

        <a href="{{ route('sync-health') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('sync-health*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h3l2.25-7.5 4.5 15L16.5 12H19.5"/>
            </svg>
            Sync Health
        </a>

        <a href="{{ route('audit-log') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('audit-log*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 12h3.75M9 15h3.75M9 18h3.75M3.75 4.5h10.5M3.75 4.5v15A2.25 2.25 0 006 21.75h12A2.25 2.25 0 0020.25 19.5V8.25a2.25 2.25 0 00-.659-1.591l-3.5-3.5A2.25 2.25 0 0014.5 2.5h-8.75A2.25 2.25 0 003.5 4.75z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 3v3.75a1.5 1.5 0 001.5 1.5h3.75"/>
            </svg>
            Audit Log
        </a>

        <a href="{{ route('unmatched-orders') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('unmatched-orders*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75c0-1.036.84-1.875 1.875-1.875h.75c1.036 0 1.875.84 1.875 1.875v.375c0 .621-.334 1.115-.807 1.454-.548.393-.943.978-.943 1.671v.25"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5h.008v.008H12V16.5z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z"/>
            </svg>
            Unmatched Orders
        </a>

        <a href="{{ route('user-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('user-management*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            User Management
        </a>

        <a href="{{ route('settings') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('settings*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Settings
            @if(!config('services.pancake.api_key'))
                <span class="ml-auto w-2 h-2 rounded-full bg-red-400 shrink-0"></span>
            @endif
        </a>
        @endif

    </nav>

    {{-- Footer — signed-in user + sign out. Sign out is destructive-adjacent (ends the
         session) so it's spatially separated from the nav items above via the border
         and lives in its own row, not mixed into the nav list. --}}
    <div class="px-4 py-4 border-t border-white/10">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-full bg-yellow-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'TSD', 0, 1)) }}
            </div>
            <div class="min-w-0 flex-1">
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

    {{-- Top bar — a fixed 3-column grid at md+ (title | search | controls), NOT a
         free-flowing flex row: every page pushes a different amount of content into
         @stack('topbar-right') (Dashboard: 2 icons; TSA Performance: team pills +
         product dropdown + checkbox + date picker + presets + sync + settings link),
         and with plain flex+justify-between the search bar's position shifted
         left/right depending on how wide that varies per page. Grid columns are
         sized independently of their content (both outer columns are equal
         minmax(0,1fr) tracks), so the search bar's column is always the same width
         and always centered, and the controls column always right-aligns and wraps
         within its own space instead of dragging the search bar around. --}}
    <header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 px-4 md:px-8 py-4 flex items-center justify-between gap-3 flex-wrap md:flex-nowrap md:grid md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:gap-4 shrink-0 shadow-sm">
        <div class="flex items-center gap-3 min-w-0">
            <button id="sidebarToggle" type="button" aria-label="Open menu"
                    class="md:hidden shrink-0 p-2 -ml-2 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <div class="min-w-0">
                <h1 class="text-lg font-bold text-slate-800 dark:text-slate-100 font-mono truncate">@yield('title', 'Dashboard')</h1>
                <p class="text-xs text-slate-400 mt-0.5 truncate">@yield('subtitle', 'TSD Reports · Pancake POS Integration')</p>
            </div>
        </div>

        {{-- Global search — TSA agents and Products only (see SearchController for why
             orders aren't included). Debounced fetch to /search, results grouped by
             type in a dropdown below the input. Hidden on the smallest screens
             (sm:block) to keep the mobile header from overflowing next to the
             hamburger button and page title. --}}
        <div class="relative w-full sm:w-56 md:w-64 order-last sm:order-none md:justify-self-center">
            <div class="relative">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input type="text" id="globalSearchInput" autocomplete="off" placeholder="Search TSA or product…"
                    aria-label="Search TSA agents and products"
                    class="w-full pl-8 pr-3 py-1.5 text-sm font-mono border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 transition-colors">
            </div>
            <div id="globalSearchResults" class="hidden absolute left-0 right-0 top-full mt-1 z-50 bg-white rounded-xl shadow-2xl border border-slate-200 max-h-80 overflow-y-auto"></div>
        </div>

        {{-- No flex-wrap at THIS level — the pushed content below wraps itself
             internally (every page's topbar-right block sets its own flex-wrap),
             so it shrinks to fit rather than growing to full width and shoving
             the toggle onto an isolated line far below it. --}}
        <div class="flex items-center justify-end gap-3 md:justify-self-end">
            @stack('topbar-right')

            {{-- Dark mode — a fixed, always-present control (unlike the stack above,
                 which varies per page), so it's in the same spot everywhere instead
                 of living down in the sidebar footer where it was easy to miss. --}}
            <button id="themeToggle" type="button" aria-label="Toggle dark mode" title="Toggle dark mode"
                    class="shrink-0 p-2 rounded-lg text-slate-400 dark:text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-600 dark:hover:text-slate-300 transition-colors cursor-pointer">
                <svg id="themeIconSun" class="w-4.5 h-4.5 hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <svg id="themeIconMoon" class="w-4.5 h-4.5 hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
            </button>
        </div>
    </header>

    {{-- Scrollable page content --}}
    <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 md:p-8">
        @yield('content')
    </main>

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
