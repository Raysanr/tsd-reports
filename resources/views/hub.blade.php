<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seller's Hub TSD</title>
<script>
    // Same saved theme as every module (localStorage 'theme'), applied before paint.
    if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
    // Entrance animation on every Hub visit (explicit request, 2026-11-17),
    // never with "reduce motion" on.
    try {
        if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.documentElement.classList.add('hub-intro');
        }
    } catch (e) {}
</script>
<link rel="icon" type="image/png" href="{{ asset('images/sellershub-favicon-32.png') }}" sizes="32x32">
<link rel="icon" type="image/png" href="{{ asset('images/sellershub-favicon-64.png') }}" sizes="64x64">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
{{-- Hub redesign "Bright Bento" (2026-11-17): white top bar, headline, a
     bento grid with TSD Leads Reports as the big accent tile, and the
     partner systems in their own dark tile. Light and dark mode. --}}
<style>
    :root {
        /* --primary / --primary-dark / --accent are overridden by the
           personal accent (Settings → Appearance) in #themeColorVars below. */
        --primary:      #CA8A04;
        --primary-dark: #854D0E;
        --accent:       #A16207;

        --bg:        #f3f4f6;
        --surface:   #ffffff;
        --surface-2: #f3f4f6;
        --line:      #e5e7eb;
        --ink:       #111827;
        --ink-2:     #374151;
        --muted:     #4b5563;
        --hero-bg:   color-mix(in srgb, var(--primary) 38%, #ffffff);
        --hero-ink:  #111827;
        --hero-sub:  #1f2937;
        --hero-btn:  rgba(255, 255, 255, 0.65);
        --dark-tile: #111827;
        --dark-row:  #1f2937;
        --shadow-hover: 0 18px 40px -18px rgba(17, 24, 39, 0.28);
    }
    html.dark {
        color-scheme: dark;
        --bg:        #0b0f17;
        --surface:   #151b26;
        --surface-2: #1e2532;
        --line:      #263042;
        --ink:       #f1f5f9;
        --ink-2:     #cbd5e1;
        --muted:     #94a3b8;
        --hero-bg:   color-mix(in srgb, var(--primary) 32%, #151b26);
        --hero-ink:  #f8fafc;
        --hero-sub:  #e2e8f0;
        --hero-btn:  rgba(255, 255, 255, 0.12);
        --dark-tile: #1e2532;
        --dark-row:  #263042;
        --shadow-hover: 0 18px 40px -18px rgba(0, 0, 0, 0.7);
    }

    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--ink);
        font-family: 'Manrope', ui-sans-serif, system-ui, sans-serif;
        -webkit-font-smoothing: antialiased;
    }
    a { color: inherit; text-decoration: none; }
    button { font: inherit; }
    :focus-visible { outline: 3px solid color-mix(in srgb, var(--primary) 70%, transparent); outline-offset: 2px; }

    .wrap { max-width: 1360px; margin: 0 auto; }
    .page { padding: 20px clamp(16px, 3vw, 40px) 56px; }

    /* ---------- Top bar ---------- */
    .topbar {
        display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;
        background: var(--surface); border-radius: 18px; padding: 10px 12px 10px 16px;
    }
    .brand { display: flex; align-items: center; gap: 12px; }
    .brand img { width: 36px; height: 36px; object-fit: contain; }
    .brand-name { font-weight: 800; font-size: 17px; letter-spacing: -0.02em; }
    .brand-name span { color: var(--muted); font-weight: 700; }
    .topnav { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
    .nav-btn {
        height: 44px; padding: 0 14px; border-radius: 12px; border: 0; background: transparent;
        display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 14px;
        color: var(--ink-2); cursor: pointer;
    }
    .nav-btn:hover { background: var(--surface-2); color: var(--ink); }
    .nav-btn svg { width: 12px; height: 12px; transition: transform 120ms ease; }
    .icon-btn { width: 44px; padding: 0; justify-content: center; }
    .icon-btn svg { width: 18px; height: 18px; }
    .theme-toggle .icon-sun { display: none; color: #facc15; }
    html.dark .theme-toggle .icon-sun { display: block; }
    html.dark .theme-toggle .icon-moon { display: none; }
    .divider { width: 1px; height: 24px; background: var(--line); margin: 0 6px; }
    .me { display: flex; align-items: center; gap: 10px; padding-right: 4px; }
    .avatar {
        width: 36px; height: 36px; border-radius: 999px; background: var(--ink); color: var(--bg);
        display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px;
    }
    .me-text { display: flex; flex-direction: column; line-height: 1.2; }
    .me-name { font-weight: 700; font-size: 14px; }
    .signout { border: 0; background: none; padding: 0; text-align: left; font-size: 12px; color: var(--muted); cursor: pointer; }
    .signout:hover { color: var(--ink); text-decoration: underline; }

    .manage { position: relative; }
    .manage.open .nav-btn svg { transform: rotate(180deg); }
    .manage-menu {
        display: none; position: absolute; right: 0; top: calc(100% + 8px); z-index: 50; min-width: 230px;
        background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 6px;
        box-shadow: 0 18px 40px -12px rgba(15, 23, 42, 0.25);
    }
    .manage.open .manage-menu { display: block; }
    .manage-menu a {
        display: flex; align-items: center; min-height: 44px; padding: 0 12px; border-radius: 10px;
        font-weight: 600; font-size: 14px; color: var(--ink-2);
    }
    .manage-menu a:hover { background: var(--surface-2); color: var(--ink); }

    /* ---------- Headline ---------- */
    .intro {
        padding: 56px 8px 36px; display: flex; flex-wrap: wrap; gap: 24px;
        align-items: flex-end; justify-content: space-between;
    }
    .intro h1 {
        flex: 1 1 520px; min-width: 0; margin: 0; font-weight: 800;
        font-size: clamp(38px, 5.2vw, 68px); line-height: 1; letter-spacing: -0.04em;
    }
    .intro > p { flex: 0 1 400px; margin: 0; font-size: 17px; line-height: 1.55; color: var(--muted); }
    .alert {
        margin: 0 0 16px; padding: 12px 16px; border-radius: 14px; font-size: 14px; font-weight: 600;
        background: rgba(225, 29, 72, 0.12); color: #e11d48;
    }

    /* ---------- Bento ---------- */
    .bento {
        display: grid; gap: 16px; grid-auto-flow: dense;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        grid-auto-rows: minmax(250px, auto);
    }
    @media (max-width: 1100px) { .bento { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px)  { .bento { grid-template-columns: minmax(0, 1fr); } .wide, .hero { grid-column: auto !important; grid-row: auto !important; } }

    .tile {
        position: relative; border-radius: 28px; background: var(--surface); padding: 28px;
        display: flex; flex-direction: column; gap: 12px;
        transition: transform 180ms ease, box-shadow 180ms ease;
    }
    a.tile:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); }
    a.tile:hover .arrow { background: var(--ink); color: var(--bg); }
    .tile-top { display: flex; justify-content: space-between; align-items: center; }
    .tile-icon {
        width: 52px; height: 52px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center;
        background: color-mix(in srgb, var(--tone) 14%, var(--surface));
        color: var(--tone-ink);
    }
    html.dark .tile-icon { background: color-mix(in srgb, var(--tone) 24%, transparent); color: color-mix(in srgb, var(--tone) 55%, #ffffff); }
    .tile-icon svg { width: 24px; height: 24px; }
    .arrow {
        width: 40px; height: 40px; border-radius: 999px; background: var(--surface-2); color: var(--ink);
        display: inline-flex; align-items: center; justify-content: center; transition: background 180ms ease, color 180ms ease;
    }
    .arrow svg { width: 16px; height: 16px; }
    .tag { margin-top: auto; font-size: 12px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--tone-ink); }
    html.dark .tag { color: color-mix(in srgb, var(--tone) 55%, #ffffff); }
    .tile h3 { margin: 0; font-weight: 800; font-size: 24px; letter-spacing: -0.03em; line-height: 1.15; }
    .tile p { margin: 0; font-size: 14px; line-height: 1.55; color: var(--muted); }
    .soon {
        width: fit-content; font-size: 11px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase;
        padding: 4px 10px; border-radius: 999px; background: var(--surface-2); color: var(--muted);
    }
    .tile.coming-soon { opacity: 0.7; }

    /* Leads Reports: the big accent tile. */
    .hero { grid-column: span 2; grid-row: span 2; background: var(--hero-bg); color: var(--hero-ink); padding: 36px; gap: 16px; }
    .hero .tile-top span:first-child { font-size: 13px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
    .hero .arrow { width: 48px; height: 48px; background: var(--hero-btn); color: var(--hero-ink); }
    .hero .arrow svg { width: 20px; height: 20px; }
    a.hero:hover .arrow { background: var(--hero-ink); color: var(--hero-bg); }
    .hero .bars { margin-top: 12px; width: 120px; height: 96px; }
    .hero h2 { margin: auto 0 0; max-width: 7.5em; font-weight: 800; font-size: clamp(34px, 4vw, 54px); letter-spacing: -0.04em; line-height: 1; }
    .hero p { margin: 0; max-width: 460px; font-size: 16px; line-height: 1.55; color: var(--hero-sub); }

    /* Partners: their own dark tile, links open each partner's app. */
    .wide { grid-column: span 2; }
    .partners { background: var(--dark-tile); color: #f9fafb; gap: 16px; }
    .partners-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; }
    .partners-head h2 { margin: 0; font-weight: 800; font-size: 22px; letter-spacing: -0.02em; }
    .partners-head span { font-size: 13px; color: #9ca3af; }
    .partner-list { margin-top: auto; display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 170px), 1fr)); }
    .partner {
        display: flex; align-items: center; gap: 12px; padding: 14px; border-radius: 18px;
        background: var(--dark-row); color: #f9fafb; transition: background 150ms ease;
    }
    .partner:hover { background: color-mix(in srgb, var(--dark-row) 70%, #ffffff 10%); }
    .partner-badge {
        width: 40px; height: 40px; flex: none; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 12px; color: #fff; background: var(--partner-color);
    }
    .partner-name { font-weight: 700; font-size: 15px; display: block; }
    .partner-sub { font-size: 12px; color: #9ca3af; display: block; }
    .partner-out { margin-left: auto; color: #6b7280; }

    @media (max-width: 640px) {
        .intro { padding-top: 36px; }
        .hero { padding: 28px; }
    }
    .greeting { margin: 0 0 14px; font-size: 15px; font-weight: 700; color: var(--muted); }
    .intro-head { flex: 1 1 520px; min-width: 0; }
    .intro-head h1 { flex: none; }
    .word { display: inline-block; }

    /* ---------- Entrance (first Hub load of a session only) ---------- */
    @keyframes hubRise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }
    @keyframes hubFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes hubGrow { from { transform: scaleY(0); } to { transform: scaleY(1); } }
    .hub-intro .greeting { animation: hubFade 400ms ease-out both; }
    .hub-intro .intro h1 .word { animation: hubRise 520ms cubic-bezier(.2,.7,.2,1) both; animation-delay: calc(80ms + var(--i) * 70ms); }
    .hub-intro .intro > p { animation: hubFade 500ms ease-out 420ms both; }
    .hub-intro .bento > * { animation: hubRise 560ms cubic-bezier(.2,.7,.2,1) both; animation-delay: calc(380ms + var(--n, 0) * 70ms); }
    .hub-intro .bento > :nth-child(1) { --n: 0; }
    .hub-intro .bento > :nth-child(2) { --n: 1; }
    .hub-intro .bento > :nth-child(3) { --n: 2; }
    .hub-intro .bento > :nth-child(4) { --n: 3; }
    .hub-intro .bento > :nth-child(5) { --n: 4; }
    .hub-intro .bento > :nth-child(6) { --n: 5; }
    .hub-intro .bento > :nth-child(n+7) { --n: 6; }
    .hub-intro .hero .bars rect { transform-box: fill-box; transform-origin: bottom; animation: hubGrow 520ms cubic-bezier(.2,.7,.2,1) both; }
    .hub-intro .hero .bars rect:nth-child(1) { animation-delay: 620ms; }
    .hub-intro .hero .bars rect:nth-child(2) { animation-delay: 690ms; }
    .hub-intro .hero .bars rect:nth-child(3) { animation-delay: 760ms; }
    .hub-intro .hero .bars rect:nth-child(4) { animation-delay: 830ms; }

    @media (prefers-reduced-motion: reduce) {
        .tile, .arrow, .partner { transition: none; }
        a.tile:hover { transform: none; }
        .hub-intro * { animation: none !important; }
    }
</style>
{{-- Personal accent (Settings → Appearance, 2026-10-30). --}}
@php $hubThemeCss = \App\Support\ThemeColor::hubCss(); @endphp
@if($hubThemeCss)<style id="themeColorVars">{!! $hubThemeCss !!}</style>@endif
    @include('partials.page-nav')
</head>
<body>
@php
    $arrowSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M8 7h9v9"/></svg>';
    // Partners — each runs its own Railway deployment (separate repo,
    // database and login); this only links out. Every role sees it
    // (2026-11-03, explicit request).
    $partners = [
        ['name' => 'PEAK.CO', 'badge' => 'PK', 'color' => '#0f766e', 'url' => 'https://peak-co-production.up.railway.app'],
        ['name' => 'M&J',     'badge' => 'MJ', 'color' => '#7c3aed', 'url' => 'https://mj-leads-production.up.railway.app'],
        ['name' => 'MLC',     'badge' => 'MLC', 'color' => '#2563eb', 'url' => 'https://mlc-leads-production.up.railway.app'],
    ];
@endphp
<div class="page">
<header class="wrap topbar">
    <div class="brand">
        <img src="{{ asset('images/sellershub-logo.png') }}" alt="">
        <span class="brand-name">Seller's Hub <span>TSD</span></span>
    </div>
    <nav class="topnav" aria-label="Account">
        @if(\App\Support\NormalUserPages::anyVisible(['user-management', 'tsa-management', 'product-management']))
        <div class="manage" data-manage-dropdown>
            <button type="button" class="nav-btn manage-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                Management
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="manage-menu">
                @if(\App\Support\NormalUserPages::canSee('user-management'))<a href="{{ route('hub.users') }}">User Management</a>@endif
                @if(\App\Support\NormalUserPages::canSee('tsa-management'))<a href="{{ route('hub.tsa-management') }}">TSA Management</a>@endif
                @if(\App\Support\NormalUserPages::canSee('product-management'))<a href="{{ route('hub.product-management') }}">Product Management</a>@endif
            </div>
        </div>
        @endif
        <button type="button" id="themeToggle" class="nav-btn icon-btn theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
            <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </button>
        <span class="divider" aria-hidden="true"></span>
        <div class="me">
            <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
            <span class="me-text">
                <span class="me-name">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="signout">Sign out</button></form>
            </span>
        </div>
    </nav>
</header>

<main class="wrap">
    @php
        $hour = now()->hour; // app timezone: Asia/Manila
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->trim()->explode(' ')->first();
        $headline = [['Where', 'do', 'you', 'want'], ['to', 'work', 'today?']];
        $wordIndex = 0;
    @endphp
    <section class="intro">
        <div class="intro-head">
            <p class="greeting">{{ $greeting }}, {{ $firstName }}</p>
            <h1 aria-label="Where do you want to work today?">@foreach($headline as $line)<span aria-hidden="true">@foreach($line as $word)<span class="word" style="--i:{{ $wordIndex++ }}">{{ $word }}</span>@if(!$loop->last) @endif @endforeach</span>@if(!$loop->last)<br>@endif @endforeach</h1>
        </div>
        <p>Everything running the telesales operation, in one place — reporting, TSA performance, and lead assignment.</p>
    </section>

    @if(session('error'))
    <p role="alert" class="alert">{{ session('error') }}</p>
    @endif

    <div class="bento">
        @if(auth()->user()->canAccessModule('leads'))
        <a class="tile hero" href="{{ route('dashboard') }}">
            <div class="tile-top">
                <span>Reporting</span>
                <span class="arrow">{!! $arrowSvg !!}</span>
            </div>
            <svg class="bars" viewBox="0 0 120 96" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><rect x="6" y="56" width="20" height="34" rx="5"/><rect x="36" y="36" width="20" height="54" rx="5"/><rect x="66" y="46" width="20" height="44" rx="5"/><rect x="96" y="10" width="20" height="80" rx="5"/></svg>
            <h2>TSD Leads Reports</h2>
            <p>Pancake POS sales reporting — leads, TSA performance, upsell tracking, and analytics.</p>
        </a>
        @endif

        {{-- Call Tracker is a section of this same app (merged 2026-08-12),
             not a separate system to hand off to — the routes themselves
             are always live. This flag only controls whether the Hub card
             advertises it as ready yet (explicit request 2026-08-13: show
             "Coming soon" in production until the call-recording storage
             question is resolved). --}}
        @if(auth()->user()->canAccessModule('calls'))
        @php $callIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z"/></svg>'; @endphp
        @if(config('services.call_tracker.enabled'))
        <a class="tile" style="--tone:#0d9488; --tone-ink:#0f766e" href="{{ route('calls.dashboard') }}">
            <div class="tile-top"><span class="tile-icon">{!! $callIcon !!}</span><span class="arrow">{!! $arrowSvg !!}</span></div>
            <span class="tag">Operations</span>
            <h3>TSD Call Tracker</h3>
            <p>Pancake-connected round-robin lead assignment with free click-to-call.</p>
        </a>
        @else
        <div class="tile coming-soon" style="--tone:#0d9488; --tone-ink:#0f766e">
            <div class="tile-top"><span class="tile-icon">{!! $callIcon !!}</span><span class="soon">Coming soon</span></div>
            <span class="tag">Operations</span>
            <h3>TSD Call Tracker</h3>
            <p>Pancake-connected round-robin lead assignment with free click-to-call.</p>
        </div>
        @endif
        @endif

        {{-- Opened up to TSAs too (explicit request, 2026-10-07). Cost
             Breakdown itself stays admin-only inside the module. --}}
        @if(auth()->user()->canAccessModule('data'))
        <a class="tile" style="--tone:#7c3aed; --tone-ink:#6d28d9" href="{{ route('data.home') }}">
            <div class="tile-top">
                <span class="tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg></span>
                <span class="arrow">{!! $arrowSvg !!}</span>
            </div>
            <span class="tag">Finance</span>
            <h3>TSD Data Management</h3>
            <p>Editable P&amp;L targets and planning projections — department, shift, and per-TSA goals.</p>
        </a>
        @endif

        {{-- Partners Data Management (2026-10-29) — Data Management on the
             partners' synced data (App\Support\DataSet). --}}
        @if(auth()->user()->canAccessModule('partners'))
        <a class="tile" style="--tone:#db2777; --tone-ink:#be185d" href="{{ route('data.home', ['dm' => 'partners']) }}">
            <div class="tile-top">
                <span class="tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path stroke-linecap="round" d="M3 20a6 6 0 0112 0M14 20a4.5 4.5 0 017-3.7"/></svg></span>
                <span class="arrow">{!! $arrowSvg !!}</span>
            </div>
            <span class="tag">Finance</span>
            <h3>Partners Data Management</h3>
            <p>Data Management for PEAK.CO, M&amp;J and MLC — their leads and orders, your costs and projections.</p>
        </a>
        @endif

        {{-- TSD Archiving System (explicit request, 2026-10-19). Holds HR
             records. Route::has(): hidden until the archive routes are deployed. --}}
        @if(\App\Support\NormalUserPages::canSeeModule('archive') && Route::has('archive.overview') && \App\Support\NormalUserPages::firstVisibleArchivePage())
        <a class="tile" style="--tone:#0284c7; --tone-ink:#0369a1" href="{{ route(\App\Support\NormalUserPages::firstVisibleArchivePage() ?? 'archive.overview') }}">
            <div class="tile-top">
                <span class="tile-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1.5"/><path stroke-linecap="round" d="M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9M10 13h4"/></svg></span>
                <span class="arrow">{!! $arrowSvg !!}</span>
            </div>
            <span class="tag">Organization</span>
            <h3>TSD Archiving System</h3>
            <p>Department structure chart, people directory, file archive, and the TSD Scoreboard of delivered sales.</p>
        </a>
        @endif

        <section class="tile wide partners" aria-labelledby="partnersTitle">
            <div class="partners-head">
                <h2 id="partnersTitle">Partner systems</h2>
                <span>Each opens its own Leads Reports in a new tab</span>
            </div>
            <div class="partner-list">
                @foreach($partners as $p)
                <a class="partner" style="--partner-color:{{ $p['color'] }}" href="{{ $p['url'] }}" target="_blank" rel="noopener">
                    <span class="partner-badge">{{ $p['badge'] }}</span>
                    <span><span class="partner-name">{{ $p['name'] }}</span><span class="partner-sub">Leads Reports</span></span>
                    <span class="partner-out" aria-hidden="true">↗</span>
                </a>
                @endforeach
            </div>
        </section>
    </div>
</main>
</div>
<script>
    document.getElementById('themeToggle').addEventListener('click', function () {
        var isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
    document.querySelectorAll('[data-manage-dropdown]').forEach(function (dropdown) {
        var toggle = dropdown.querySelector('.manage-dropdown-toggle');
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = dropdown.classList.toggle('open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('[data-manage-dropdown].open').forEach(function (dropdown) {
            dropdown.classList.remove('open');
            dropdown.querySelector('.manage-dropdown-toggle').setAttribute('aria-expanded', 'false');
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('[data-manage-dropdown].open').forEach(function (dropdown) {
            dropdown.classList.remove('open');
            var toggle = dropdown.querySelector('.manage-dropdown-toggle');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        });
    });
</script>
</body>
</html>
