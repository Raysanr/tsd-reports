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
    <title>TSD Reports — @yield('title', 'Sign In')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 dark:bg-slate-950">

<div class="min-h-screen flex">

    {{-- Brand panel — hidden below md so the form gets full width on mobile --}}
    <div class="hidden md:flex md:w-5/12 lg:w-1/2 bg-sidebar flex-col justify-between px-10 lg:px-16 py-12 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-accent flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.5l7-7 4 4 6.5-6.5"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7h-4v4"/>
                </svg>
            </div>
            <div>
                <div class="text-white font-bold text-sm leading-tight font-mono">TSD Reports</div>
                <div class="text-yellow-300 text-[10px] font-mono tracking-widest uppercase">Telesales Dashboard</div>
            </div>
        </div>

        <div>
            <h1 class="text-white text-3xl lg:text-4xl font-bold font-mono leading-tight mb-4">
                @yield('brand_heading', 'Called leads. Dispositions. Real numbers.')
            </h1>
            <p class="text-yellow-200/70 text-sm font-mono leading-relaxed max-w-sm">
                @yield('brand_subheading', 'Pancake POS orders, synced hourly — TSA performance, sales, and shift breakdowns in one place.')
            </p>
        </div>

        <p class="text-yellow-400/40 text-[11px] font-mono">Pancake POS Integration</p>
    </div>

    {{-- Form panel --}}
    <div class="flex-1 flex items-center justify-center px-6 py-12">
        <div class="w-full max-w-sm">

            {{-- Logo shown only on mobile, where the brand panel is hidden --}}
            <div class="md:hidden flex items-center gap-3 mb-8">
                <div class="w-9 h-9 rounded-lg bg-accent flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.5l7-7 4 4 6.5-6.5"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 7h-4v4"/>
                    </svg>
                </div>
                <div>
                    <div class="text-slate-800 dark:text-slate-100 font-bold text-sm leading-tight font-mono">TSD Reports</div>
                    <div class="text-accent text-[10px] font-mono tracking-widest uppercase">Telesales Dashboard</div>
                </div>
            </div>

            @yield('content')

        </div>
    </div>

</div>

@stack('scripts')
</body>
</html>
