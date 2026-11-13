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
    <title>{{ \App\Support\DataSet::isPartners() ? 'Partners' : 'TSD' }} Data Management — @yield('title', 'Projections')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/data-management-favicon.svg') }}">
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
    @include('partials.theme-color')
    <style>
    .nav-item { transition: background-color .15s ease, color .15s ease; }
    .nav-item:hover { background-color: rgba(255,255,255,0.08); color: #fff; }
    .nav-active { background-color: color-mix(in srgb, var(--color-yellow-500) 16%, transparent); color: var(--color-yellow-200) !important; }
    </style>
</head>
<body class="flex h-screen overflow-hidden bg-canvas dark:bg-slate-950">

<div id="sidebarBackdrop" class="hidden fixed inset-0 bg-black/50 z-40 md:hidden"></div>

<aside id="sidebar"
       class="fixed md:relative inset-y-0 left-0 z-50 w-64 shrink-0 bg-sidebar flex flex-col h-full shadow-xl
              -translate-x-full md:translate-x-0 transition-transform duration-200 ease-out">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-56" style="background: radial-gradient(120% 100% at 18% 0%, color-mix(in srgb, var(--color-yellow-500) 16%, transparent), transparent 70%);"></div>

    <div class="relative px-6 py-5 border-b border-white/10">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-secondary flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.125C3 6.504 3.504 6 4.125 6h4.243c.3 0 .587.12.8.331l1.383 1.383c.211.211.498.331.8.331h8.524c.621 0 1.125.504 1.125 1.125v9.705c0 .621-.504 1.125-1.125 1.125H4.125A1.125 1.125 0 013 18.875V7.125z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 17.25v-3M12 17.25v-5.25M15.75 17.25v-7.5"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="text-white font-bold text-sm leading-tight font-mono tracking-tight truncate">{{ \App\Support\DataSet::isPartners() ? 'Partners Data Management' : 'TSD Data Management' }}</div>
                    <div class="text-yellow-300 text-[10px] font-mono tracking-[0.15em] uppercase truncate">{{ \App\Support\DataSet::isPartners() ? 'PEAK.CO · M&J · MLC' : 'TSD Telesales' }}</div>
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
        {{-- Sidebar dropdown groups (explicit request, 2026-11-04): SH E-COM
             (this dataset's own pages) and TSD OVERALL PERFORMANCE (SH E-COM
             and Partners, TSD + admins only). With a mouse the group holding
             the current page stays open and the other opens on hover; on touch
             screens they open on tap and remember it (script + styles below). --}}
        <div class="nav-group" data-nav-group="ecom" data-open="1">
        <button type="button" class="nav-group-toggle w-full flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer text-left text-[11px] font-mono font-bold tracking-[0.15em] text-yellow-300 uppercase hover:bg-white/10" aria-expanded="true">
            {{ \App\Support\DataSet::isPartners() ? 'Partners' : 'SH E-COM' }}
            <svg class="nav-group-chev w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div class="nav-group-body"><div class="nav-group-inner"><div class="mt-1 space-y-1">

        @if(\App\Support\NormalUserPages::canSee('data.projections'))
        <a href="{{ route('data.projections') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.projections') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
            </svg>
            Projections
        </a>
        @endif

        {{-- DSPPR - TSM Report, right next to Projections (explicit
             request, 2026-09-24: "it is next to the projections page"). --}}
        @if(\App\Support\NormalUserPages::canSee('data.dsppr'))
        <a href="{{ route('data.dsppr') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.dsppr') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25"/>
            </svg>
            DSPPR - TSM Report
        </a>
        @endif

        {{-- Summary Sales Report, right next to DSPPR (explicit request,
             2026-09-24: "add page summary sales report in data
             management"). --}}
        @if(\App\Support\NormalUserPages::canSee('data.tsa-sales'))
        <a href="{{ route('data.tsa-sales') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.tsa-sales*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
            </svg>
            Summary Sales Report
        </a>
        @endif

        {{-- Expected Income 2026, right next to Summary Sales Report
             (explicit request, 2026-09-26: "add that EXPECTED INCOME to the
             data management module"). --}}
        @if(\App\Support\NormalUserPages::canSee('data.expected-income'))
        <a href="{{ route('data.expected-income') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.expected-income*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941"/>
            </svg>
            Expected Income
        </a>
        @endif

        {{-- Actual Income, right after Expected Income (2026-10-21). --}}
        @if(\App\Support\NormalUserPages::canSee('data.actual-income'))
        <a href="{{ route('data.actual-income') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.actual-income*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Actual Income
        </a>
        @endif

        {{-- Cost Breakdown, right next to Expected Income (explicit
             request, 2026-09-29: "add new page in data management (COST
             BREAKDOWN)"). Admin-only (explicit request, 2026-10-07: "for
             the tsa's the cost breakdown is not visible to them only
             [projections, dsppr, summary sales report, and expected
             income]" — real payroll/salary data, unlike this module's
             other 4 pages, which are now normal-accessible), hidden from
             a TSA the same way the route group itself is gated
             (role:super_admin,admin, nested inside this module's own
             route group in routes/web.php). --}}
        @if(\App\Support\NormalUserPages::anyVisible(['data.cost-breakdown', 'data.actual-cost-breakdown', 'data.product-costs']))
        @if(\App\Support\NormalUserPages::canSee('data.cost-breakdown'))
        <a href="{{ route('data.cost-breakdown') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.cost-breakdown*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Cost Breakdown
        </a>
        @endif

        {{-- Actual Cost Breakdown (2026-11-09) — same page on its own
             figures (CostBreakdownVariant::ACTUAL), admin-only too. --}}
        @if(\App\Support\NormalUserPages::canSee('data.actual-cost-breakdown'))
        <a href="{{ route('data.actual-cost-breakdown') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.actual-cost-breakdown*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Actual Cost Breakdown
        </a>
        @endif

        {{-- Product Costs (explicit request, 2026-11-04), admin-only like
             Cost Breakdown above. --}}
        @if(\App\Support\NormalUserPages::canSee('data.product-costs'))
        <a href="{{ route('data.product-costs') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.product-costs*') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/>
            </svg>
            Product Costs
        </a>
        @endif
        @endif
        </div></div></div>
        </div>

        @if(!\App\Support\DataSet::isPartners() && \App\Support\NormalUserPages::anyVisible(['data.overall.projections', 'data.overall.sales-summary', 'data.overall.expected-income']))
        <div class="nav-group mt-2" data-nav-group="overall" data-open="1">
        <button type="button" class="nav-group-toggle w-full flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer text-left text-[11px] font-mono font-bold tracking-[0.15em] text-yellow-300 uppercase hover:bg-white/10" aria-expanded="true">
            TSD Overall Performance
            <svg class="nav-group-chev w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div class="nav-group-body"><div class="nav-group-inner"><div class="mt-1 space-y-1">
            @foreach([
                ['data.overall.projections', 'Projections', 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
                ['data.overall.sales-summary', 'Sales Summary (SH E-COM and Partners)', 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5'],
                ['data.overall.expected-income', 'Expected Income (SH E-COM and Partners)', 'M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941'],
            ] as [$routeName, $navLabel, $iconPath])
            @continue(!\App\Support\NormalUserPages::canSee($routeName))
            <a href="{{ route($routeName) }}"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs($routeName) ? 'nav-active' : '' }}">
                <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}"/>
                </svg>
                {{ $navLabel }}
            </a>
            @endforeach
        </div></div></div>
        </div>
        @endif
        <style>
            /* Sidebar dropdown groups (2026-11-04): grid-rows 0fr<->1fr animates
               both ways (a closed <details> can't be measured, so opening
               never animated). Transitions only after first paint. */
            .nav-group-body { display: grid; grid-template-rows: 1fr; opacity: 1; }
            .nav-group[data-open="0"] .nav-group-body { grid-template-rows: 0fr; opacity: 0; }
            .nav-group-inner { overflow: hidden; min-height: 0; }
            .nav-group-chev { transform: rotate(180deg); }
            .nav-group[data-open="0"] .nav-group-chev { transform: rotate(0deg); }
            .nav-groups-ready .nav-group-body { transition: grid-template-rows .3s ease, opacity .3s ease; }
            .nav-groups-ready .nav-group-chev { transition: transform .3s ease; }
            @media (prefers-reduced-motion: reduce) {
                .nav-groups-ready .nav-group-body, .nav-groups-ready .nav-group-chev { transition: none; }
            }
        </style>
        <script>
        // Mouse: the group holding the current page stays open. Any other is
        // closed at rest and open while the pointer or keyboard focus is inside
        // (a short delay stops flicker when the pointer slips out).
        // Touch has no hover, so groups open on tap, stay as left across pages
        // (localStorage), and the group holding the current page starts open.
        (() => {
            const KEY = 'dm-nav-collapsed';
            const hasHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
            let collapsed = [];
            try { collapsed = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) {}
            const groups = document.querySelectorAll('[data-nav-group]');
            const setOpen = (group, open) => {
                group.dataset.open = open ? '1' : '0';
                group.querySelector('.nav-group-toggle').setAttribute('aria-expanded', open ? 'true' : 'false');
                group.querySelectorAll('.nav-group-inner a').forEach((a) => { a.tabIndex = open ? 0 : -1; });
            };
            groups.forEach((group) => {
                if (hasHover) {
                    if (group.querySelector('.nav-active')) { setOpen(group, true); return; }
                    let closing;
                    const open = () => { clearTimeout(closing); setOpen(group, true); };
                    const close = () => {
                        clearTimeout(closing);
                        closing = setTimeout(() => { if (!group.contains(document.activeElement) || !document.activeElement.matches(':focus-visible')) setOpen(group, false); }, 180);
                    };
                    setOpen(group, false);
                    group.addEventListener('mouseenter', open);
                    group.addEventListener('mouseleave', close);
                    group.addEventListener('focusin', (e) => { if (e.target.matches(':focus-visible')) open(); });
                    group.addEventListener('focusout', (e) => { if (!group.contains(e.relatedTarget) && !group.matches(':hover')) close(); });
                    return;
                }
                const name = group.dataset.navGroup;
                if (collapsed.includes(name) && !group.querySelector('.nav-active')) setOpen(group, false);
                group.querySelector('.nav-group-toggle').addEventListener('click', () => {
                    const open = group.dataset.open !== '1';
                    setOpen(group, open);
                    collapsed = collapsed.filter((n) => n !== name);
                    if (!open) collapsed.push(name);
                    try { localStorage.setItem(KEY, JSON.stringify(collapsed)); } catch (e) {}
                });
            });
            requestAnimationFrame(() => requestAnimationFrame(() => document.getElementById('sidebar')?.classList.add('nav-groups-ready')));
        })();
        </script>

        <div class="my-3 border-t border-white/10"></div>
        <p class="px-3 mb-2 text-[10px] font-mono font-semibold tracking-[0.15em] text-yellow-400/50 uppercase">History</p>

        {{-- Activity Log (explicit request, 2026-10-07: "can you add
             another page that is ACTIVITY LOG? ... all activites in
             every page should be recorded on that") — visible to normal
             users too (its own controller hides Cost Breakdown's own
             entries from anyone who isn't at least an admin, same
             filtering this module's other 4 pages already get). --}}
        @if(\App\Support\NormalUserPages::canSee('data.activity-log'))
        <a href="{{ route('data.activity-log') }}"
           class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium cursor-pointer {{ request()->routeIs('data.activity-log') ? 'nav-active' : '' }}">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Activity Log
        </a>
        @endif
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

            @if(\App\Support\DataSet::isPartners())
            {{-- Sync now (2026-11-03): pull partner products, TSAs and orders immediately instead of waiting for the 10-minute sync. --}}
            <form method="POST" action="{{ route('data.partners-sync') }}" onsubmit="const b = this.querySelector('button'); b.disabled = true; b.querySelector('svg').classList.add('animate-spin');">
                @csrf
                <button type="submit" aria-label="Sync now" title="Sync now — pull the latest products, TSAs and orders from PEAK.CO, M&J and MLC"
                        class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer disabled:cursor-wait disabled:opacity-70">
                    <svg class="w-4.5 h-4.5 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </form>
            @endif

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
     class="fixed top-4 inset-x-4 sm:inset-x-auto sm:right-4 sm:w-full z-[70] flex flex-col gap-2 max-w-sm pointer-events-none"></div>

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

{{-- Shared styled confirm dialog for every Data Management page (explicit
     request, 2026-09-27: "why all of the x icon has no modal it is like
     this" — the plain browser window.confirm() popup looked completely
     out of place next to everything else's own custom modals).

     Deliberately its OWN element id (#dataConfirmModal) and function name
     (window.confirmDataModal), NOT the app.js pair (#confirmModal /
     window.showConfirm) despite doing the same job — layouts.data pulls in
     calls.js, never app.js (same as every Call Tracker page), and calls.js
     has its own documented incident about exactly this mismatch (see its
     own comment on the upsell line-item delete button): a page that loads
     calls.js calling an app.js-only global silently resolves false with
     zero visible dialog, which is what originally motivated that fallback
     back to plain confirm() there. Colliding on the SAME id/name here
     would only be safe as long as no Data Management page ever also loads
     app.js — reusing distinct names removes that landmine entirely rather
     than relying on that never happening.

     One instance, reused by every caller via
     window.confirmDataModal(message) — same "one shared dialog, not one
     per page" convention as Projections' own add-row modal / DSPPR's own
     combine modal. Returns a Promise<boolean> (true if confirmed) so a
     caller does `if (!(await window.confirmDataModal('...'))) return;` in
     place of the old `if (!window.confirm('...')) return;` line.

     Listeners are bound FRESH per call and removed on settle (same
     convention as app.js's own showConfirm()) rather than kept as one
     permanently-attached set with a shared resolveFn — a second call
     landing before the first settles would otherwise silently overwrite
     that shared resolveFn, permanently hanging the first caller's own
     `await` and misrouting its OK/Cancel click to the second call's
     promise instead (root-caused 2026-09-27 in code review, before it
     ever reached a real double-click). --}}
<div id="dataConfirmModal" hidden class="fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50" data-confirm-cancel></div>
    <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-xl w-full max-w-sm p-6 font-mono">
        <p id="dataConfirmModalMessage" class="text-sm text-ink dark:text-slate-100 mb-5"></p>
        <div class="flex justify-end gap-2">
            <button type="button" data-confirm-cancel class="text-sm px-4 py-2 rounded-lg border border-line dark:border-slate-600 text-ink dark:text-slate-100">Cancel</button>
            <button type="button" data-confirm-ok class="text-sm px-4 py-2 rounded-lg bg-primary text-white font-semibold">OK</button>
        </div>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('dataConfirmModal');
    const messageEl = document.getElementById('dataConfirmModalMessage');
    const cancelBtn = modal.querySelector('[data-confirm-cancel][type="button"]');
    const backdropEl = modal.querySelector('.absolute.inset-0');
    const okBtn = modal.querySelector('[data-confirm-ok]');

    window.confirmDataModal = function (message) {
        messageEl.textContent = message;
        modal.hidden = false;

        return new Promise((resolve) => {
            // One-shot listeners, rebound fresh on every call (same
            // convention as app.js's own showConfirm()) — a stale listener
            // left attached from a previous call would double-fire or
            // resolve the WRONG call's promise on the next one, exactly
            // the reentrancy bug a single shared resolveFn had before.
            function settle(result) {
                modal.hidden = true;
                cancelBtn.removeEventListener('click', onCancel);
                okBtn.removeEventListener('click', onOk);
                backdropEl.removeEventListener('click', onCancel);
                document.removeEventListener('keydown', onKeydown);
                resolve(result);
            }
            function onCancel() { settle(false); }
            function onOk() { settle(true); }
            function onKeydown(e) { if (e.key === 'Escape') settle(false); }

            cancelBtn.addEventListener('click', onCancel);
            okBtn.addEventListener('click', onOk);
            backdropEl.addEventListener('click', onCancel);
            document.addEventListener('keydown', onKeydown);
        });
    };
})();
</script>

@stack('scripts')
@if(auth()->user()->role === 'guest')
@include('data._guest-read-only')
@endif
</body>
</html>
