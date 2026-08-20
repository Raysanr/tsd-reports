<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
    (function () {
        // Same convention/reasoning as TSD Reports' own layouts/app.blade.php —
        // shared localStorage key ('theme'), so a choice made in either app
        // carries over to the other (same .dark class strategy in both
        // resources/css/app.css and calls.css). Default is light for anyone
        // who hasn't explicitly chosen a theme yet, ignoring the OS/browser's
        // own prefers-color-scheme.
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Call Tracker — @yield('title', 'Dashboard')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/call-tracker-favicon.svg') }}">
    {{-- Ported from call-tracker (merged into one app 2026-08-12), unmodified
         except route names/model renames below — a DEDICATED layout, not a
         reuse of TSD Reports' own layouts/app.blade.php. Explicit request
         (2026-08-13): Call Tracker keeps its own separate look/nav exactly
         as the standalone app had it; TSD Reports' own sidebar/nav stays
         exactly as it was before this merge too. The Hub page is the only
         shared switcher between the two. --}}
    @vite(['resources/css/calls.css', 'resources/js/calls.js'])
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
    /* Shared flatpickr styling for the date-picker partial. */
    [id$="Calendar"] .flatpickr-calendar { box-shadow: none !important; border: none !important; background: transparent !important; }
    [id$="Calendar"] .flatpickr-innerContainer { display: flex; gap: 0; }
    [id$="Calendar"] .flatpickr-rContainer { flex: 1; }

    [id$="Calendar"] .flatpickr-days,
    [id$="Calendar"] .flatpickr-weekdays { width: auto !important; }
    [id$="Calendar"] .dayContainer,
    [id$="Calendar"] .flatpickr-weekdaycontainer {
        width: 270px !important;
        min-width: 270px !important;
        max-width: 270px !important;
    }

    @media (max-width: 639px) {
        [id$="Calendar"] .dayContainer,
        [id$="Calendar"] .flatpickr-weekdaycontainer {
            width: 189px !important;
            min-width: 189px !important;
            max-width: 189px !important;
        }
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
    .flatpickr-day.today {
        border: 2px solid #fde047 !important;
        color: #a16207 !important;
        font-weight: 700;
    }
    .flatpickr-day.today:hover { background: #fefce8 !important; }
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
    .flatpickr-weekdays { margin-top: 4px; }
    .flatpickr-weekday {
        color: #94a3b8 !important;
        font-family: ui-monospace, monospace;
        font-size: 0.65rem !important;
        font-weight: 700;
        text-transform: uppercase;
    }
    </style>
</head>
<body class="flex h-screen overflow-hidden bg-canvas dark:bg-slate-950" data-notifications-url="{{ auth()->check() ? route('calls.notifications.counts') : '' }}">

<aside class="relative w-64 shrink-0 bg-sidebar flex flex-col h-full shadow-xl">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-56" style="background: radial-gradient(120% 100% at 18% 0%, rgba(234,179,8,0.16), transparent 70%);"></div>

    <div class="relative px-6 py-5 border-b border-white/10">
        <div class="flex items-center gap-3">
            {{-- Solid gold fill, black icon (revised 2026-08-15 — matches the
                 SellersHub cube's high-contrast gold/black treatment, not a
                 white icon). Full-bleed fill, no border/glow. --}}
            <div class="w-9 h-9 rounded-lg bg-secondary flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
            </div>
            <div class="min-w-0">
                <div class="text-white font-bold text-sm leading-tight font-mono tracking-tight truncate">Call Tracker</div>
                <div class="text-yellow-300 text-[10px] font-mono tracking-[0.15em] uppercase truncate">TSD Telesales</div>
            </div>
        </div>
    </div>

    <nav class="relative flex-1 px-3 py-5 space-y-1 overflow-y-auto">
        {{-- Explicit request (2026-08-13): a back button to the Hub, not a
             direct link to TSD Reports' dashboard — the Hub is the one
             shared switcher between the two areas, so leaving Call Tracker
             always goes back to the menu, not straight into the other area. --}}
        <a href="{{ route('hub') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Hub
        </a>

        <div class="my-3 border-t border-white/10"></div>

        <a href="{{ route('calls.dashboard') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.dashboard') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/>
            </svg>
            Dashboard
        </a>

        @php
            $leadsGroupActive = request()->routeIs('calls.leads.index');
            $leadsBaseActive  = $leadsGroupActive && !request('view');
            // Carries the TSA + date filters across Leads/Overdue/Callbacks
            // (explicit request, 2026-08-15): these three links used to be
            // fixed hrefs, so picking a TSA (or, now that Overdue/Callbacks
            // share the same date window — see LeadController::index() — a
            // date range) on one and clicking another silently dropped it.
            // date_from/date_to also has a separate localStorage-based
            // fallback (leads/index.blade.php) for restoring across a whole
            // new session; this covers the immediate in-session round trip.
            // Scoped to when already on a Leads-group page so it can't leak
            // unrelated query params in from some other page.
            $leadsTsaParam = $leadsGroupActive ? request()->only(['tsa', 'date_from', 'date_to']) : [];
        @endphp
        <div class="nav-item flex items-center rounded-lg {{ $leadsBaseActive ? 'nav-active' : '' }}">
            <a id="leadsNavLink" href="{{ route('calls.leads.index', $leadsTsaParam) }}"
               class="flex-1 min-w-0 flex items-center justify-between gap-3 pl-3 pr-2 py-2.5 text-yellow-200 text-sm font-medium cursor-pointer">
                <span class="flex items-center gap-3 min-w-0">
                    <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    Leads
                </span>
                <span id="badge-assigned" class="hidden bg-yellow-500 text-slate-900 text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center"></span>
            </a>
            <button type="button" onclick="toggleLeadsNav(event)" aria-label="Toggle Leads submenu" aria-expanded="{{ $leadsGroupActive ? 'true' : 'false' }}"
                    class="shrink-0 p-2 mr-1 rounded-md text-yellow-200/70 hover:text-white hover:bg-white/10 transition-colors cursor-pointer">
                <svg id="leadsNavChevron" class="w-3.5 h-3.5 transition-transform duration-200 {{ $leadsGroupActive ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
        </div>

        <div id="leadsNavSubmenu" class="grid transition-[grid-template-rows] duration-200 ease-out {{ $leadsGroupActive ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]' }}">
            <div class="overflow-hidden">
                <div id="leadsNavSubmenuInner" class="ml-4 pl-3 border-l border-white/10 space-y-1 mt-1 mb-1 transition-opacity duration-150 {{ $leadsGroupActive ? 'opacity-100' : 'opacity-0' }}">
                    <a href="{{ route('calls.leads.index', ['view' => 'overdue'] + $leadsTsaParam) }}"
                       class="nav-item flex items-center justify-between gap-3 px-3 py-2 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ $leadsGroupActive && request('view') === 'overdue' ? 'nav-active' : '' }}">
                        <span class="flex items-center gap-3">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            Overdue
                        </span>
                        <span id="badge-overdue" class="hidden bg-red-500 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center"></span>
                    </a>

                    <a href="{{ route('calls.leads.index', ['view' => 'callbacks'] + $leadsTsaParam) }}"
                       class="nav-item flex items-center justify-between gap-3 px-3 py-2 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ $leadsGroupActive && request('view') === 'callbacks' ? 'nav-active' : '' }}">
                        <span class="flex items-center gap-3">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            Callbacks
                        </span>
                        <span id="badge-callbacks" class="hidden bg-red-500 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center"></span>
                    </a>
                </div>
            </div>
        </div>

        {{-- isAtLeastAdmin() (super_admin OR admin), not the original bare
             isAdmin() — matches the merged app's role convention (super_admin
             is a real tier here that call-tracker never had; every ported
             CallTracker\* controller/route already gates the same way). --}}
        @if(auth()->user()?->isAtLeastAdmin())
        <div class="my-3 border-t border-white/10"></div>
        <p class="px-3 mb-2 text-[10px] font-mono font-semibold tracking-[0.15em] text-yellow-400/50 uppercase">Reports</p>
        <a href="{{ route('calls.monitor') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.monitor') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"/>
            </svg>
            Monitor TSA
        </a>
        <a href="{{ route('calls.analytics') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.analytics') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
            </svg>
            Analytics
        </a>
        <a href="{{ route('calls.call-log') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.call-log') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
            </svg>
            Call Log
        </a>
        <a href="{{ route('calls.sync-health') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.sync-health') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/>
            </svg>
            Sync Health
        </a>

        <div class="my-3 border-t border-white/10"></div>
        <p class="px-3 mb-2 text-[10px] font-mono font-semibold tracking-[0.15em] text-yellow-400/50 uppercase">Config</p>
        <a href="{{ route('calls.tsa-management') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.tsa-management') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-8.13a4 4 0 110 8 4 4 0 010-8zm6 8a4 4 0 00-3-3.87M5 12a4 4 0 013.87-3"/>
            </svg>
            TSA Management
        </a>
        <a href="{{ route('calls.round-robin-setup') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.round-robin-setup') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m9 12h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0H12M3.75 12H12m0 0a1.5 1.5 0 103 0m-3 0a1.5 1.5 0 013 0m3.75 0H21"/>
            </svg>
            Leads Setup
        </a>
        <a href="{{ route('calls.tsa-logs') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.tsa-logs') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3-15H6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 006 21h12a2.25 2.25 0 002.25-2.25V6.108c0-.53-.211-1.04-.586-1.414l-3.808-3.808a2.25 2.25 0 00-1.414-.586H15z"/>
            </svg>
            TSA Logs
        </a>
        {{-- Same shared SettingsController@index as TSD Reports' own
             /settings (2026-08-13) — Call Tracker's own Settings page wasn't
             ported separately; its two fields (access token, overdue
             threshold) were folded onto this same page since both would
             otherwise edit the same underlying Pancake connection. Routed
             through calls.settings so it renders in THIS layout, not TSD
             Reports' own. --}}
        <a href="{{ route('calls.settings') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('calls.settings') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Settings
        </a>
        @endif
    </nav>

    <div class="px-4 py-4 border-t border-white/10">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-full bg-yellow-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'CT', 0, 1)) }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-white text-xs font-semibold truncate">{{ auth()->user()->name }}</div>
                <div class="text-yellow-400 text-[10px] truncate">{{ auth()->user()->isAtLeastAdmin() ? 'Admin' : (auth()->user()->tsa?->display_name ?? 'TSA') }}</div>
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

<div class="flex-1 flex flex-col min-h-0 min-w-0">
    <div class="header-accent-rule shrink-0"></div>
    {{-- dark:bg-slate-900/dark:border-slate-700/dark:text-slate-100(-400) —
         same values TSD Reports' own header uses (explicit request,
         2026-08-17: "make the topbar dark too, like in TSD Reports"). The
         light-mode values (border-line/text-ink/text-ink-muted, Call
         Tracker's own bespoke tokens) stay untouched. --}}
    <header class="bg-white dark:bg-slate-900 border-b border-line dark:border-slate-700 px-8 py-4 flex items-center justify-between gap-3 flex-wrap shrink-0 shadow-panel">
        <div class="shrink-0">
            <h1 class="text-xl font-bold text-ink dark:text-slate-100 tracking-tight">@yield('title', 'Dashboard')</h1>
            <p class="text-xs text-ink-muted dark:text-slate-400 font-mono mt-0.5">@yield('subtitle', 'Pancake POS Integration')</p>
        </div>

        <div class="flex items-center gap-3 flex-wrap justify-end">
            @stack('topbar-right')

            @if(auth()->user() && !auth()->user()->isAtLeastAdmin() && auth()->user()->tsa)
            @php $ownStatus = auth()->user()->tsa->status ?? \App\Models\TsaShift::STATUS_LOGOUT; @endphp
            @include('calls.partials.tsa-status-panel', [
                'id'       => 'topbar',
                'options'  => \App\Models\TsaShift::SELF_SERVICE_STATUSES,
                'current'  => $ownStatus,
                'target'   => 'self',
                'readonly' => $ownStatus === \App\Models\TsaShift::STATUS_LOCKED,
            ])
            @endif

            {{-- Reload + Dark mode — fixed, always-present controls (explicit
                 request, 2026-08-17), same convention/markup/behavior as TSD
                 Reports' own layouts/app.blade.php: not per-page like the
                 stack above, so they're in the same spot on every Call
                 Tracker page. Reload here is a plain full reload (Call
                 Tracker has no softRefresh-equivalent AJAX view-swap yet,
                 unlike TSD Reports), just with the same spin-while-loading
                 feedback. --}}
            <button id="reloadBtn" type="button" aria-label="Reload this page" title="Reload"
                    class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer">
                <svg id="reloadIcon" class="w-4 h-4 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </button>

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

    <main class="flex-1 overflow-y-auto p-8">
        @if(session('success'))
        <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-mono rounded-lg px-4 py-3">
            {{ session('success') }}
        </div>
        @endif
        @if(session('error') || $errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-800 text-sm font-mono rounded-lg px-4 py-3">
            {{ session('error') ?? $errors->first() }}
        </div>
        @endif

        @yield('content')
    </main>
</div>

{{-- Toast container (explicit request, 2026-08-17) — same markup/positioning
     as TSD Reports' own layouts/app.blade.php, so window.showToast() (calls.js)
     works identically here. Two separate role="alert"/"status" toasts, not one
     shared live region — see that page's own comment on why. --}}
<div id="toastContainer"
     class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none"></div>

@stack('scripts')
</body>
</html>
