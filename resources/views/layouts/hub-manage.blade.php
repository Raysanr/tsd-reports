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
    <title>@yield('title', 'Management') · Seller's Hub TSD</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    {{-- Hub frame for TSA / Product / User Management. Renders the SAME view
         as the main dashboard pages (they @extends($layout ?? 'layouts.app')),
         so the Hub copy can never fall behind again — explicit request,
         2026-10-20 ("always connected to all of the management"). Same
         app.css/app.js bundle as layouts.app, since the page content and its
         JS (modals, eye toggle, bulk actions…) are shared. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
    .hub-bg { background-color: #F5F3EC; background-image: radial-gradient(circle, #E2DFD3 1px, transparent 1px); background-size: 22px 22px; }
    .dark .hub-bg { background-color: #020617; background-image: radial-gradient(circle, #1e293b 1px, transparent 1px); }
    </style>
    @stack('head')
    @include('partials.page-nav')
</head>
<body class="hub-bg min-h-screen text-slate-800 dark:text-slate-100">

<div id="toastContainer" class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none"></div>
@include('partials.confirm-modal')

@php
    $manageLinks = [
        ['hub.users', 'User Management'],
        ['hub.tsa-management', 'TSA Management'],
        ['hub.product-management', 'Product Management'],
    ];
@endphp

<header class="px-6 md:px-16 pt-7">
    <div class="flex flex-wrap items-center justify-between gap-3 font-mono text-xs font-bold">
        <a href="{{ route('hub') }}" class="inline-flex items-center gap-1.5 uppercase tracking-[0.15em] text-yellow-700 hover:text-yellow-900 dark:text-yellow-400">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Hub
        </a>
        <div class="flex items-center gap-2 text-slate-400">
            <div class="relative" data-manage-menu>
                <button type="button" data-manage-toggle aria-haspopup="menu" aria-expanded="false"
                        class="inline-flex items-center gap-1 text-yellow-700 hover:text-yellow-900 dark:text-yellow-400 cursor-pointer">
                    Management
                    <svg class="w-3.5 h-3.5 transition" data-manage-chevron fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                </button>
                <div data-manage-panel hidden role="menu"
                     class="absolute right-0 z-50 mt-3 w-60 rounded-2xl bg-white dark:bg-slate-900 p-2 shadow-2xl ring-1 ring-black/5 dark:ring-white/10 font-sans text-sm">
                    @foreach($manageLinks as [$route, $label])
                    <a href="{{ route($route) }}" role="menuitem"
                       class="flex items-center justify-between rounded-xl px-3 py-2.5 font-semibold {{ request()->routeIs($route) ? 'bg-yellow-50 text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-300' : 'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800' }}">
                        {{ $label }}
                        @if(request()->routeIs($route))<span class="h-1.5 w-1.5 rounded-full bg-yellow-500"></span>@endif
                    </a>
                    @endforeach
                </div>
            </div>
            <span>·</span>
            <span class="font-medium">Signed in as {{ auth()->user()->name }}</span>
            <span>·</span>
            <form method="POST" action="{{ route('logout') }}" class="inline">@csrf
                <button type="submit" class="font-medium underline underline-offset-2 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer">Sign out</button>
            </form>
        </div>
    </div>
</header>

<div class="mx-auto w-full max-w-6xl px-4 md:px-8 pt-12 pb-24">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-4xl md:text-5xl font-extrabold tracking-tight">@yield('title', 'Management')</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">@yield('subtitle')</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">@stack('topbar-right')</div>
    </div>

    {{-- <main> so app.js's softRefresh swap target exists here too. --}}
    <main>
        @yield('content')
    </main>
</div>

<script>
(function () {
    const menu = document.querySelector('[data-manage-menu]');
    const panel = menu.querySelector('[data-manage-panel]');
    const toggle = menu.querySelector('[data-manage-toggle]');
    const set = (open) => {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open);
        menu.querySelector('[data-manage-chevron]').classList.toggle('rotate-180', open);
    };
    toggle.addEventListener('click', (e) => { e.stopPropagation(); set(panel.hidden); });
    document.addEventListener('click', (e) => { if (!menu.contains(e.target)) set(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
})();
</script>

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
