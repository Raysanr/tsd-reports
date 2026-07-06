<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>TSD Reports — @yield('title', 'Dashboard')</title>
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
    </style>
    @stack('head')
</head>
<body class="flex h-screen overflow-hidden bg-slate-100">

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

        <a href="{{ route('team-report') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer
                  {{ request()->routeIs('team-report') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Team Report
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
            Charts
        </a>

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

    </nav>

    {{-- Footer --}}
    <div class="px-4 py-4 border-t border-white/10">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-yellow-600 flex items-center justify-center text-white text-xs font-bold">TSD</div>
            <div>
                <div class="text-white text-xs font-semibold">TSD Admin</div>
                <div class="text-yellow-400 text-[10px]">Pancake POS</div>
            </div>
        </div>
    </div>
</aside>

{{-- ═══════════════════ MAIN CONTENT ═══════════════════ --}}
{{-- min-w-0 lets this flex child shrink below its content's width; without it, a wide table
     inside pushes the whole page wider than the viewport instead of scrolling internally. --}}
<div class="flex-1 flex flex-col min-h-0 min-w-0">

    {{-- Top bar --}}
    <header class="bg-white border-b border-slate-200 px-4 md:px-8 py-4 flex items-center justify-between gap-3 flex-wrap shrink-0 shadow-sm">
        <div class="flex items-center gap-3 min-w-0">
            <button id="sidebarToggle" type="button" aria-label="Open menu"
                    class="md:hidden shrink-0 p-2 -ml-2 rounded-lg text-slate-500 hover:bg-slate-100 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <div class="min-w-0">
                <h1 class="text-lg font-bold text-slate-800 font-mono truncate">@yield('title', 'Dashboard')</h1>
                <p class="text-xs text-slate-400 mt-0.5 truncate">@yield('subtitle', 'TSD Reports · Pancake POS Integration')</p>
            </div>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            @stack('topbar-right')
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

@stack('scripts')
</body>
</html>
