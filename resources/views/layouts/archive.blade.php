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
    <title>TSD Archiving System — @yield('title', 'Overview')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/archive-favicon.svg') }}">
    {{-- Same calls.css/calls.js bundle as layouts/data.blade.php, for the
         same reason (sidebar/dark-mode/toast already live there). Every
         Archive page's own JS is inline via @push('scripts'), so nothing
         here depends on app.js-only globals. --}}
    @vite(['resources/css/calls.css', 'resources/js/calls.js'])
    <style>
    dialog.arc-modal::backdrop { background: rgba(2,6,23,0.55); backdrop-filter: blur(3px); }
    dialog.arc-modal[open] { animation: arc-pop .16s ease-out; }
    @keyframes arc-pop { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: none; } }
    @media print {
        #sidebar, header, .no-print { display: none !important; }
        main { overflow: visible !important; padding: 0 !important; }
        body { height: auto !important; overflow: visible !important; background: #fff !important; }
    }
    </style>
    @stack('head')
</head>
<body class="flex h-screen overflow-hidden bg-canvas dark:bg-slate-950 text-ink dark:text-slate-100">

@php
    $nav = [
        'Workspace' => [
            ['archive.overview',   'archive.overview',   'Overview',       'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
            ['archive.live-tsa',   'archive.live-tsa',   'TSD Scoreboard', 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
        ],
        'Organization' => [
            ['archive.org-chart',  'archive.org-chart',  'Org Chart',      'M7.5 3.75H6A2.25 2.25 0 003.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0120.25 6v1.5m0 9V18A2.25 2.25 0 0118 20.25h-1.5m-9 0H6A2.25 2.25 0 013.75 18v-1.5M12 8.25v7.5m-3-3.75h6'],
            ['archive.departments','archive.departments*','Teams',   'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21'],
            ['archive.people',     'archive.people*',    'People',         'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
        ],
        'Performance' => [
            ['archive.incidents',  'archive.incidents*', 'Incidents & NTEs', 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'],
            ['archive.performance','archive.performance*','Performance History','M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
        ],
        'Knowledge' => [
            ['archive.library',    'archive.library*',   'TSD Documented Process', 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25'],
        ],
        'Archive' => [
            ['archive.documents',  'archive.documents*', 'Documents',      'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'],
            ['archive.records',    'archive.records',    'Archived Records','M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z'],
            ['archive.activity-log','archive.activity-log','Activity Log', 'M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ],
    ];
@endphp

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
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="text-white font-bold text-sm leading-tight tracking-tight truncate">TSD Archiving System</div>
                    <div class="text-yellow-300 text-[10px] tracking-[0.15em] uppercase truncate">TSD Telesales</div>
                </div>
            </div>
            <button id="sidebarClose" type="button" aria-label="Close menu"
                    class="md:hidden shrink-0 p-1.5 rounded-lg text-yellow-200 hover:bg-white/10 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    <nav class="relative flex-1 px-3 py-5 space-y-1 overflow-y-auto">
        <a href="{{ route('hub') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium">
            <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Hub
        </a>

        @foreach($nav as $section => $items)
            <div class="my-3 border-t border-white/10"></div>
            <p class="px-3 mb-2 text-[10px] font-semibold tracking-[0.15em] text-yellow-400/50 uppercase">{{ $section }}</p>
            @foreach($items as [$route, $pattern, $label, $icon])
                <a href="{{ route($route) }}"
                   @if(request()->routeIs($pattern)) aria-current="page" @endif
                   class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-yellow-200 text-sm font-medium {{ request()->routeIs($pattern) ? 'nav-active' : '' }}">
                    <svg class="w-4.5 h-4.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                    {{ $label }}
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="px-4 py-4 border-t border-white/10">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-full bg-yellow-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                {{ strtoupper(substr(auth()->user()->name ?? 'TSD', 0, 1)) }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-white text-xs font-semibold truncate">{{ auth()->user()->name }}</div>
                <div class="text-yellow-300 text-[10px] truncate">{{ \App\Models\User::ROLE_LABELS[auth()->user()->role] ?? 'User' }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" aria-label="Sign out" title="Sign out" class="shrink-0 p-1.5 rounded-lg text-yellow-200 hover:bg-white/10 hover:text-white transition-colors cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </button>
            </form>
        </div>
    </div>
</aside>

<div class="flex-1 flex flex-col min-h-0 min-w-0">
    <div class="header-accent-rule shrink-0"></div>
    <header class="bg-white dark:bg-slate-900 border-b border-line dark:border-slate-700 shadow-panel px-4 md:px-8 py-4 flex items-center justify-between gap-3 flex-wrap shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <button id="sidebarToggle" type="button" aria-label="Open menu"
                    class="md:hidden shrink-0 p-2 -ml-2 rounded-lg text-ink-muted dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="min-w-0">
                <h1 class="text-lg md:text-xl font-bold tracking-tight truncate">@yield('title', 'Overview')</h1>
                <p class="text-xs text-ink-muted dark:text-slate-400 mt-0.5 truncate">@yield('subtitle', 'Organization, people & records')</p>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap justify-end">
            @stack('topbar-right')
            <button id="themeToggle" type="button" aria-label="Toggle dark mode" title="Toggle dark mode"
                    class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer">
                <svg id="themeIconSun" class="w-4.5 h-4.5 hidden text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                <svg id="themeIconMoon" class="w-4.5 h-4.5 hidden text-primary" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            </button>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto overflow-x-hidden p-4 md:p-8">
        {{-- Validation errors stay inline (you need to read them while fixing
             the form); success/error messages pop up as toasts instead. --}}
        @if($errors->any())
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 px-4 py-3 text-sm text-rose-800 dark:text-rose-200" role="alert">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
            <div>@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        </div>
        @endif

        @yield('content')
    </main>
</div>

{{-- Shared document viewer: any <a data-preview> opens here instead of a
     new tab (Ctrl/Cmd/middle-click still opens the tab via its href).
     PDFs load in an iframe, images in an <img>. --}}
<dialog id="arcPreview" class="arc-modal m-auto w-[min(1100px,calc(100vw-2rem))] h-[calc(100vh-2rem)] max-h-none max-w-none rounded-2xl bg-white dark:bg-slate-900 text-ink dark:text-slate-100 p-0 shadow-2xl ring-1 ring-black/5 dark:ring-white/10 overflow-hidden">
    <div class="flex h-full flex-col">
        <div class="flex items-center gap-3 border-b border-line dark:border-slate-800 px-5 py-3">
            <div class="w-9 h-9 shrink-0 rounded-lg bg-yellow-50 dark:bg-yellow-500/10 flex items-center justify-center text-primary">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <h3 id="arcPreviewTitle" class="font-semibold truncate"></h3>
                <p id="arcPreviewMeta" class="text-xs text-ink-muted dark:text-slate-400 truncate"></p>
            </div>
            <a id="arcPreviewOpen" href="#" target="_blank" rel="noopener" title="Open in new tab" aria-label="Open in new tab" class="hidden sm:inline-flex rounded-lg p-2 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
            </a>
            <a id="arcPreviewDownload" href="#" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                Download
            </a>
            <button type="button" data-modal-close aria-label="Close preview" class="rounded-lg p-2 text-ink-muted hover:bg-slate-100 hover:text-ink dark:hover:bg-slate-800 cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="arcPreviewBody" class="relative flex-1 min-h-0 bg-slate-100 dark:bg-slate-950 flex items-center justify-center">
            <div id="arcPreviewSpinner" class="absolute inset-0 flex items-center justify-center">
                <svg class="w-8 h-8 animate-spin text-primary" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            </div>
        </div>
    </div>
</dialog>

{{-- calls.js's window.showToast() renders into this. --}}
<div id="toastContainer" class="fixed top-4 right-4 z-[70] flex flex-col items-end gap-2 max-w-sm pointer-events-none"></div>
@if(session('success') || session('error'))
<script>
// Flash messages pop up as a small toast that closes itself (calls.js's
// showToast, ~4s). Module scripts run before DOMContentLoaded, so it exists by then.
document.addEventListener('DOMContentLoaded', () => {
    @if(session('success')) window.showToast?.(@json(session('success')), 'success'); @endif
    @if(session('error')) window.showToast?.(@json(session('error')), 'error'); @endif
});
</script>
@endif

{{-- Shared confirm dialog: any <form data-confirm="message"> is intercepted
     and only submitted after OK. Own ids (#arcConfirm), never app.js's
     #confirmModal/showConfirm — this layout loads calls.js, not app.js. --}}
<dialog id="arcConfirm" class="arc-modal m-auto w-full max-w-sm rounded-2xl bg-white dark:bg-slate-900 text-ink dark:text-slate-100 p-0 shadow-2xl ring-1 ring-black/5 dark:ring-white/10">
    <div class="p-6">
        <div class="flex gap-4">
            <div class="w-10 h-10 shrink-0 rounded-full bg-rose-100 dark:bg-rose-500/15 flex items-center justify-center">
                <svg class="w-5 h-5 text-rose-600 dark:text-rose-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
            </div>
            <div>
                <h3 class="font-semibold">Are you sure?</h3>
                <p id="arcConfirmMessage" class="mt-1 text-sm text-ink-muted dark:text-slate-400"></p>
            </div>
        </div>
    </div>
    <div class="flex justify-end gap-2 bg-slate-50 dark:bg-slate-800/60 px-6 py-3 rounded-b-2xl">
        <button type="button" data-arc-cancel class="rounded-lg px-4 py-2 text-sm font-medium ring-1 ring-inset ring-line dark:ring-slate-600 hover:bg-white dark:hover:bg-slate-700 cursor-pointer">Cancel</button>
        <button type="button" data-arc-ok class="rounded-lg px-4 py-2 text-sm font-semibold bg-rose-600 text-white hover:bg-rose-500 cursor-pointer">Confirm</button>
    </div>
</dialog>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const open = () => { sidebar.classList.remove('-translate-x-full'); backdrop.classList.remove('hidden'); };
    const close = () => { sidebar.classList.add('-translate-x-full'); backdrop.classList.add('hidden'); };
    document.getElementById('sidebarToggle')?.addEventListener('click', open);
    document.getElementById('sidebarClose')?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);
    window.addEventListener('resize', () => { if (window.innerWidth >= 768) close(); });

    // Modals: [data-modal-open="id"] opens <dialog id>, [data-modal-close] closes
    // its own dialog, and a click on the backdrop closes too. data-fill='{"name":"x"}'
    // on the opener pre-fills same-named fields; data-action sets the form action.
    document.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-modal-open]');
        if (opener) {
            const dlg = document.getElementById(opener.dataset.modalOpen);
            const form = dlg?.querySelector('form');
            if (form) {
                form.reset();
                if (opener.dataset.action) form.action = opener.dataset.action;
                const method = form.querySelector('input[name="_method"]');
                if (method) method.value = opener.dataset.method || method.dataset.default || 'POST';
                const fill = opener.dataset.fill ? JSON.parse(opener.dataset.fill) : {};
                Object.entries(fill).forEach(([k, v]) => {
                    form.querySelectorAll(`[name="${k}"]`).forEach((el) => {
                        if (el.type === 'radio') el.checked = el.value === String(v ?? '');
                        else el.value = v ?? '';
                    });
                });
                dlg.querySelectorAll('[data-modal-title]').forEach((el) => { el.textContent = opener.dataset.title || el.dataset.modalTitle; });
                form.dispatchEvent(new CustomEvent('arc:filled'));
            }
            dlg?.showModal();
            return;
        }
        if (e.target.closest('[data-modal-close]')) {
            e.target.closest('dialog')?.close();
            return;
        }
        if (e.target.tagName === 'DIALOG' && e.target.classList.contains('arc-modal')) {
            const r = e.target.getBoundingClientRect();
            if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) e.target.close();
        }
    });

    const confirmDlg = document.getElementById('arcConfirm');
    let pending = null;
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form.dataset.confirm || form.dataset.confirmed) return;
        e.preventDefault();
        pending = form;
        document.getElementById('arcConfirmMessage').textContent = form.dataset.confirm;
        confirmDlg.showModal();
    });
    confirmDlg.querySelector('[data-arc-ok]').addEventListener('click', () => {
        confirmDlg.close();
        if (pending) { pending.dataset.confirmed = '1'; pending.requestSubmit(); pending = null; }
    });
    confirmDlg.querySelector('[data-arc-cancel]').addEventListener('click', () => { pending = null; confirmDlg.close(); });
})();
</script>

<script>
(function () {
    const dlg = document.getElementById('arcPreview');
    const body = document.getElementById('arcPreviewBody');
    const spinner = document.getElementById('arcPreviewSpinner');

    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[data-preview]');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();

        document.getElementById('arcPreviewTitle').textContent = link.dataset.title || '';
        document.getElementById('arcPreviewMeta').textContent = link.dataset.meta || '';
        document.getElementById('arcPreviewOpen').href = link.href;
        document.getElementById('arcPreviewDownload').href = link.dataset.download || link.href;

        body.querySelectorAll('iframe, img').forEach((el) => el.remove());
        spinner.classList.remove('hidden');
        const el = document.createElement(link.dataset.kind === 'image' ? 'img' : 'iframe');
        el.src = link.href;
        if (el.tagName === 'IMG') {
            el.alt = link.dataset.title || '';
            el.className = 'max-h-full max-w-full object-contain p-4 select-none';
        } else {
            el.title = link.dataset.title || 'Document preview';
            el.className = 'absolute inset-0 h-full w-full border-0 bg-white';
        }
        el.addEventListener('load', () => spinner.classList.add('hidden'));
        el.addEventListener('error', () => spinner.classList.add('hidden'));
        body.append(el);
        dlg.showModal();
    });

    // Unload the file on close so a large PDF isn't kept in memory.
    dlg.addEventListener('close', () => body.querySelectorAll('iframe, img').forEach((el) => el.remove()));
})();
</script>

@stack('scripts')
@include('archive.partials.form-controls')
</body>
</html>
