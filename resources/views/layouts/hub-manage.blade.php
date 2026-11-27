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
    <link rel="icon" type="image/png" href="{{ asset('images/sellershub-favicon-32.png') }}" sizes="32x32">
    <link rel="icon" type="image/png" href="{{ asset('images/sellershub-favicon-64.png') }}" sizes="64x64">
    {{-- Hub frame for TSA / Product / User Management. Renders the SAME view
         as the main dashboard pages (they @extends($layout ?? 'layouts.app')),
         so the Hub copy can never fall behind again — explicit request,
         2026-10-20 ("always connected to all of the management"). Same
         app.css/app.js bundle as layouts.app, since the page content and its
         JS (modals, eye toggle, bulk actions…) are shared.
         Look matches the Hub's "Bright Bento" redesign (2026-11-17): white top
         bar, gray ground, Manrope, big headline, rounded white cards, ink
         primary buttons; light and dark. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme-color')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
    .hm {
        --hm-bg: #f3f4f6; --hm-surface: #ffffff; --hm-surface-2: #f3f4f6; --hm-line: #e5e7eb;
        --hm-ink: #111827; --hm-ink-2: #374151; --hm-muted: #4b5563;
        background: var(--hm-bg); color: var(--hm-ink);
        font-family: 'Manrope', ui-sans-serif, system-ui, sans-serif; -webkit-font-smoothing: antialiased;
    }
    .dark .hm {
        --hm-bg: #0b0f17; --hm-surface: #151b26; --hm-surface-2: #1e2532; --hm-line: #263042;
        --hm-ink: #f1f5f9; --hm-ink-2: #cbd5e1; --hm-muted: #94a3b8;
        color-scheme: dark;
    }
    .hm-wrap { max-width: 1360px; margin: 0 auto; }

    /* Top bar — same as the Hub. */
    .hm-topbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;
        background: var(--hm-surface); border-radius: 18px; padding: 10px 12px 10px 12px; }
    .hm-brand { display: flex; align-items: center; gap: 10px; }
    .hm-back { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; color: var(--hm-ink-2); }
    .hm-back:hover { background: var(--hm-surface-2); color: var(--hm-ink); }
    .hm-brand img { width: 34px; height: 34px; object-fit: contain; }
    .hm-brand-name { font-weight: 800; font-size: 17px; letter-spacing: -0.02em; color: var(--hm-ink); }
    .hm-brand-name span { color: var(--hm-muted); font-weight: 700; }
    .hm-nav { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
    .hm-btn { height: 44px; padding: 0 14px; border-radius: 12px; border: 0; background: transparent; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 14px; color: var(--hm-ink-2); }
    .hm-btn:hover { background: var(--hm-surface-2); color: var(--hm-ink); }
    .hm-icon { width: 44px; padding: 0; justify-content: center; }
    .hm-theme .icon-sun { display: none; color: #facc15; }
    .dark .hm-theme .icon-sun { display: block; }
    .dark .hm-theme .icon-moon { display: none; }
    .hm-divider { width: 1px; height: 24px; background: var(--hm-line); margin: 0 6px; }
    .hm-avatar { width: 36px; height: 36px; border-radius: 999px; background: var(--hm-ink); color: var(--hm-bg);
        display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
    .hm-signout { border: 0; background: none; padding: 0; font-size: 12px; color: var(--hm-muted); cursor: pointer; }
    .hm-signout:hover { color: var(--hm-ink); text-decoration: underline; }
    .hm-menu { position: absolute; right: 0; top: calc(100% + 8px); z-index: 50; min-width: 230px; padding: 6px;
        background: var(--hm-surface); border: 1px solid var(--hm-line); border-radius: 16px; box-shadow: 0 18px 40px -12px rgba(15,23,42,.25); }
    .hm-menu a { display: flex; align-items: center; justify-content: space-between; min-height: 44px; padding: 0 12px;
        border-radius: 10px; font-weight: 600; font-size: 14px; color: var(--hm-ink-2); }
    .hm-menu a:hover, .hm-menu a[aria-current="page"] { background: var(--hm-surface-2); color: var(--hm-ink); }

    /* Headline + section tabs. */
    .hm-intro { padding: 48px 8px 28px; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; justify-content: space-between; }
    .hm-intro h1 { margin: 0; font-weight: 800; font-size: clamp(36px, 4.6vw, 60px); line-height: 1; letter-spacing: -0.04em; color: var(--hm-ink); }
    .hm-intro p { margin: 12px 0 0; max-width: 560px; font-size: 16px; line-height: 1.55; color: var(--hm-muted); }
    .hm-tabs { display: inline-flex; flex-wrap: wrap; gap: 4px; padding: 4px; border-radius: 14px; background: var(--hm-surface); }
    .hm-tabs a { min-height: 40px; padding: 0 14px; border-radius: 10px; display: inline-flex; align-items: center;
        font-weight: 700; font-size: 14px; color: var(--hm-muted); }
    .hm-tabs a:hover { color: var(--hm-ink); }
    .hm-tabs a[aria-current="page"] { background: var(--hm-ink); color: var(--hm-bg); }

    /* Page content (shared views): Hub-style cards and buttons, scoped to this frame. */
    .hm main { font-family: inherit; }
    .hm main .shadow-sm.rounded-xl,
    .hm main > div > .rounded-xl.border,
    .hm main details.rounded-xl { border-radius: 24px !important; border-color: transparent !important; box-shadow: none !important; background: var(--hm-surface) !important; }
    .hm main button.bg-yellow-700, .hm main a.bg-yellow-700,
    .hm [data-hm-topbar-right] button.bg-yellow-700, .hm [data-hm-topbar-right] a.bg-yellow-700 {
        background: var(--hm-ink) !important; color: var(--hm-bg) !important; border-radius: 12px !important;
        min-height: 44px; padding-left: 18px !important; padding-right: 18px !important; font-size: 14px !important; font-weight: 700 !important;
    }
    .hm main button.bg-yellow-700:hover, .hm main a.bg-yellow-700:hover { opacity: .88; }
    .hm main .font-mono.text-xs.text-slate-400 { font-family: inherit; font-size: 14px; color: var(--hm-muted); }
    .dark .hm main .dark\:bg-slate-900 { background-color: var(--hm-surface); }
    </style>
    @stack('head')
    @include('partials.page-nav')
</head>
<body class="hm min-h-screen">

<div id="toastContainer" class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none"></div>
@include('partials.confirm-modal')

@php
    $manageLinks = array_values(array_filter([
        ['hub.users', 'User Management', 'Users', 'user-management'],
        ['hub.tsa-management', 'TSA Management', 'TSAs', 'tsa-management'],
        ['hub.product-management', 'Product Management', 'Products', 'product-management'],
    ], fn ($l) => \App\Support\NormalUserPages::canSee($l[3])));
@endphp

<div style="padding: 20px clamp(16px, 3vw, 40px) 96px;">
<header class="hm-wrap hm-topbar">
    <div class="hm-brand">
        <a href="{{ route('hub') }}" class="hm-back" aria-label="Back to Hub" title="Back to Hub">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <a href="{{ route('hub') }}" class="hm-brand" style="gap:10px">
            <img src="{{ asset('images/sellershub-logo.png') }}" alt="">
            <span class="hm-brand-name">Seller's Hub <span>TSD</span></span>
        </a>
    </div>
    <nav class="hm-nav" aria-label="Account">
        <div class="relative" data-manage-menu>
            <button type="button" class="hm-btn" data-manage-toggle aria-haspopup="menu" aria-expanded="false">
                Management
                <svg class="w-3 h-3 transition" data-manage-chevron fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
            </button>
            <div data-manage-panel hidden role="menu" class="hm-menu">
                @foreach($manageLinks as [$route, $label])
                <a href="{{ route($route) }}" role="menuitem" @if(request()->routeIs($route)) aria-current="page" @endif>
                    {{ $label }}
                    @if(request()->routeIs($route))<span class="h-1.5 w-1.5 rounded-full" style="background:var(--hm-ink)"></span>@endif
                </a>
                @endforeach
            </div>
        </div>
        <button type="button" id="themeToggle" class="hm-btn hm-icon hm-theme" aria-label="Toggle dark mode" title="Toggle dark mode">
            <svg class="icon-moon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            <svg class="icon-sun" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </button>
        <span class="hm-divider" aria-hidden="true"></span>
        <div class="flex items-center gap-2.5 pr-1">
            <span class="hm-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
            <span class="flex flex-col leading-tight">
                <span class="text-sm font-bold">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="hm-signout">Sign out</button></form>
            </span>
        </div>
    </nav>
</header>

<div class="hm-wrap">
    <div class="hm-intro">
        <div>
            <h1>@yield('title', 'Management')</h1>
            <p>@yield('subtitle')</p>
        </div>
        <div class="flex flex-wrap items-center gap-3" data-hm-topbar-right>
            @if(count($manageLinks) > 1)
            <nav class="hm-tabs" aria-label="Management sections">
                @foreach($manageLinks as [$route, $label, $short])
                <a href="{{ route($route) }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ $short }}</a>
                @endforeach
            </nav>
            @endif
            @stack('topbar-right')
        </div>
    </div>

    {{-- <main> so app.js's softRefresh swap target exists here too. --}}
    <main class="px-0 md:px-2">
        @yield('content')
    </main>
</div>
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
@include('partials.daily-target-bubble')
</body>
</html>
