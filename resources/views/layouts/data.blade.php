<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
    (function () {
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>TSD Data Management — @yield('title', 'Projections')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/call-tracker-favicon.svg') }}">
    {{--
        Reuses Call Tracker's own calls.css/calls.js bundle rather than a new
        third Vite entry (explicit request, 2026-09-23: "create new module
        TSD DATA MANAGEMENT... use tailwind css components for modern
        design") — calls.css/calls.js already carry the exact sidebar/
        drawer/dark-mode/toast/flatpickr behavior this layout needs, and
        this module has no bespoke JS of its own beyond what each page adds
        via @push('scripts'), so a dedicated data.css/data.js would just be
        an empty duplicate of the same Tailwind build.
    --}}
    @vite(['resources/css/calls.css', 'resources/js/calls.js'])
    <style>
    .nav-item { transition: background-color .15s ease, color .15s ease; }
    .nav-item:hover { background-color: rgba(255,255,255,0.08); color: #fff; }
    .nav-active { background-color: rgba(234,179,8,0.16); color: #fde68a !important; }
    </style>
</head>
<body class="flex h-screen overflow-hidden bg-canvas dark:bg-slate-950">

<div id="sidebarBackdrop" class="hidden fixed inset-0 bg-black/50 z-40 md:hidden"></div>

<aside id="sidebar"
       class="fixed md:relative inset-y-0 left-0 z-50 w-64 shrink-0 bg-sidebar flex flex-col h-full shadow-xl
              -translate-x-full md:translate-x-0 transition-transform duration-200 ease-out">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-56" style="background: radial-gradient(120% 100% at 18% 0%, rgba(234,179,8,0.16), transparent 70%);"></div>

    <div class="relative px-6 py-5 border-b border-white/10">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-secondary flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="text-white font-bold text-sm leading-tight font-mono tracking-tight truncate">TSD Data Management</div>
                    <div class="text-yellow-300 text-[10px] font-mono tracking-[0.15em] uppercase truncate">TSD Telesales</div>
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

    <nav class="relative flex-1 px-3 py-5 space-y-1 overflow-y-auto">
        <a href="{{ route('hub') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Hub
        </a>

        <div class="my-3 border-t border-white/10"></div>
        <p class="px-3 mb-2 text-[10px] font-mono font-semibold tracking-[0.15em] text-yellow-400/50 uppercase">Planning</p>

        <a href="{{ route('data.projections') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.projections') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
            </svg>
            Projections
        </a>
    </nav>

    <div class="px-4 py-4 border-t border-white/10">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-full bg-yellow-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'TSD', 0, 1)) }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-white text-xs font-semibold truncate">{{ auth()->user()->name }}</div>
                <div class="text-yellow-400 text-[10px] truncate">Admin</div>
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
    <header class="bg-white dark:bg-slate-900 border-b border-line dark:border-slate-700 px-4 md:px-8 py-4 flex items-center justify-between gap-3 flex-wrap shrink-0 shadow-panel">
        <div class="flex items-center gap-3 min-w-0">
            <button id="sidebarToggle" type="button" aria-label="Open menu"
                    class="md:hidden shrink-0 p-2 -ml-2 rounded-lg text-ink-muted dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <div class="min-w-0">
                <h1 class="text-lg md:text-xl font-bold text-ink dark:text-slate-100 tracking-tight truncate">@yield('title', 'Projections')</h1>
                <p class="text-xs text-ink-muted dark:text-slate-400 font-mono mt-0.5 truncate">@yield('subtitle', 'Financial planning & targets')</p>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap justify-end">
            @stack('topbar-right')

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

    <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 md:p-8">
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

<div id="toastContainer"
     class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none"></div>

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

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) closeSidebar();
    });
})();
</script>

@stack('scripts')
</body>
</html>
