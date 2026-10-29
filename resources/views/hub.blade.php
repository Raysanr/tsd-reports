<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seller's Hub TSD</title>
<link rel="icon" type="image/png" href="{{ asset('images/sellershub-favicon-32.png') }}" sizes="32x32">
<link rel="icon" type="image/png" href="{{ asset('images/sellershub-favicon-64.png') }}" sizes="64x64">
<style>
    @import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@500;600;700&family=Fira+Sans:wght@400;500;600;700;800&display=swap');

    :root {
        --primary:      #CA8A04;
        --primary-dark: #854D0E;
        --accent:       #A16207;
        --bg:           #F5F3EC;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        font-family: 'Fira Sans', ui-sans-serif, system-ui, sans-serif;
        background: var(--bg);
        background-image: radial-gradient(circle, #E2DFD3 1px, transparent 1px);
        background-size: 22px 22px;
        color: #1e293b;
    }

    /* Persistent top bar — its own strip, deliberately NOT part of the
       centered hero below, so wayfinding (who's signed in / sign out) always
       stays pinned near the top regardless of viewport height, instead of
       drifting to wherever the centered content happens to land. */
    header {
        width: 100%;
        padding: 28px clamp(24px, 4vw, 72px) 0;
    }
    .eyebrow {
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.14em;
        color: var(--accent);
        text-transform: uppercase;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .eyebrow .greeting {
        color: #94a3b8;
        font-weight: 600;
        letter-spacing: normal;
        text-transform: none;
    }
    .eyebrow .manage-users {
        color: var(--accent);
        text-decoration: none;
        font-weight: 700;
    }
    .eyebrow .manage-users:hover { color: var(--primary-dark); }

    /* Management dropdown — same eyebrow row, opens down on click since
       this bar sits at the very top of the viewport (no room to hover-open
       upward, and click keeps it usable on touch). */
    .manage-dropdown { position: relative; display: inline-block; }
    .manage-dropdown-toggle {
        font-family: inherit;
        font-size: inherit;
        font-weight: 700;
        letter-spacing: inherit;
        text-transform: inherit;
        background: none;
        border: none;
        color: var(--accent);
        cursor: pointer;
        padding: 0;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .manage-dropdown-toggle:hover { color: var(--primary-dark); }
    .manage-dropdown-toggle svg { width: 11px; height: 11px; transition: transform 120ms ease; }
    .manage-dropdown.open .manage-dropdown-toggle svg { transform: rotate(180deg); }
    .manage-dropdown-menu {
        display: none;
        position: absolute;
        top: calc(100% + 10px);
        left: 0;
        min-width: 200px;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
        padding: 6px;
        z-index: 10;
    }
    .manage-dropdown.open .manage-dropdown-menu { display: block; }
    .manage-dropdown-menu a {
        display: block;
        padding: 9px 12px;
        border-radius: 6px;
        font-family: 'Fira Sans', ui-sans-serif, system-ui, sans-serif;
        font-size: 14px;
        font-weight: 600;
        letter-spacing: normal;
        text-transform: none;
        color: #334155;
        text-decoration: none;
    }
    .manage-dropdown-menu a:hover { background: var(--bg); color: var(--primary-dark); }
    .eyebrow form { margin: 0; }
    .eyebrow .signout {
        font-family: inherit;
        font-size: inherit;
        font-weight: inherit;
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 0;
        text-decoration: underline;
        text-underline-offset: 2px;
    }
    .eyebrow .signout:hover { color: var(--accent); }

    /* The hero + card grid fills whatever vertical space the top bar
       doesn't use and centers itself in it — on a tall viewport this is the
       difference between a small block stranded at the top and a
       composition that actually occupies the screen it's given. */
    main {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        width: 100%;
        padding: 48px clamp(24px, 4vw, 72px);
    }
    .brand {
        display: flex;
        align-items: center;
        gap: 28px;
    }
    .brand-logo {
        width: clamp(64px, 8vw, 112px);
        height: auto;
        flex-shrink: 0;
    }
    h1 {
        font-size: clamp(48px, 6.5vw, 88px);
        font-weight: 800;
        line-height: 1.02;
        margin: 0;
        color: #0f172a;
        letter-spacing: -0.015em;
    }
    h1 .gold { color: var(--primary); }
    @media (max-width: 760px) {
        .brand { gap: 18px; }
        .brand-logo { width: 56px; }
    }
    .subtitle {
        font-size: 20px;
        color: #64748b;
        margin: 26px 0 0;
        max-width: 620px;
        line-height: 1.5;
    }
    /* Hero row — brand + subtitle on the left, the Partners card on the
       right. Stacks on narrow screens. */
    .hero {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 40px;
    }
    @media (max-width: 900px) {
        .hero { flex-direction: column; align-items: flex-start; }
    }

    /* Partners card — each partner (MLC, M&J, PEAK.CO) runs its own
       deployment of this app; the card only links out to them. Hover
       (or focus / tap) expands the list with a staggered reveal. */
    .partners { position: relative; flex-shrink: 0; }
    .partners-card {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 20px;
        background: #fff;
        border: 1px solid #E7E3D6;
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        cursor: pointer;
        font: inherit;
        color: inherit;
        text-align: left;
        transition: border-color 200ms ease, box-shadow 200ms ease, transform 200ms ease;
    }
    .partners:hover .partners-card,
    .partners:has(:focus-visible) .partners-card,
    .partners.open .partners-card {
        border-color: var(--primary);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.10);
        transform: translateY(-2px);
    }
    .partners-icon {
        width: 40px; height: 40px;
        border-radius: 10px;
        display: grid; place-items: center;
        background: rgba(202, 138, 4, 0.12);
        color: var(--primary-dark);
    }
    .partners-icon svg { width: 22px; height: 22px; }
    .partners-label {
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.14em;
        color: var(--accent);
        text-transform: uppercase;
        display: block;
    }
    .partners-title { font-size: 17px; font-weight: 700; color: #0f172a; display: block; margin-top: 2px; }
    .partners-chevron { width: 14px; height: 14px; color: #94a3b8; transition: transform 250ms ease; }
    .partners:hover .partners-chevron,
    .partners:has(:focus-visible) .partners-chevron,
    .partners.open .partners-chevron { transform: rotate(180deg); color: var(--primary); }

    /* padding-top bridges the gap so the cursor can travel card → menu
       without the hover dropping. */
    .partners-menu {
        position: absolute;
        top: 100%;
        right: 0;
        min-width: 100%;
        width: 260px;
        padding-top: 10px;
        z-index: 20;
        visibility: hidden;
        pointer-events: none;
    }
    .partners-menu-inner {
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
        padding: 8px;
        opacity: 0;
        transform: translateY(-8px) scale(0.97);
        transform-origin: top right;
        transition: opacity 200ms ease, transform 250ms cubic-bezier(0.2, 0.9, 0.3, 1.2);
    }
    .partners:hover .partners-menu,
    .partners:has(:focus-visible) .partners-menu,
    .partners.open .partners-menu { visibility: visible; pointer-events: auto; }
    .partners:hover .partners-menu-inner,
    .partners:has(:focus-visible) .partners-menu-inner,
    .partners.open .partners-menu-inner { opacity: 1; transform: none; }

    .partner-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border-radius: 10px;
        text-decoration: none;
        color: #1e293b;
        opacity: 0;
        transform: translateX(10px);
        transition: background 150ms ease, opacity 250ms ease, transform 300ms ease;
    }
    .partners:hover .partner-link,
    .partners:has(:focus-visible) .partner-link,
    .partners.open .partner-link { opacity: 1; transform: none; }
    .partners:hover .partner-link:nth-child(2), .partners:has(:focus-visible) .partner-link:nth-child(2), .partners.open .partner-link:nth-child(2) { transition-delay: 0ms, 60ms, 60ms; }
    .partners:hover .partner-link:nth-child(3), .partners:has(:focus-visible) .partner-link:nth-child(3), .partners.open .partner-link:nth-child(3) { transition-delay: 0ms, 120ms, 120ms; }
    .partner-link:hover, .partner-link:focus-visible { background: var(--bg); outline: none; }
    .partner-badge {
        width: 36px; height: 36px;
        border-radius: 9px;
        display: grid; place-items: center;
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 11px;
        font-weight: 700;
        color: #fff;
        background: var(--partner-color);
        flex-shrink: 0;
    }
    .partner-name { font-weight: 700; font-size: 15px; display: block; }
    .partner-sub { font-size: 12px; color: #94a3b8; display: block; }
    .partner-arrow { margin-left: auto; color: #cbd5e1; transition: transform 150ms ease, color 150ms ease; }
    .partner-link:hover .partner-arrow { transform: translateX(3px); color: var(--partner-color); }
    @media (max-width: 900px) {
        .partners-menu { left: 0; right: auto; }
        .partners-menu-inner { transform-origin: top left; }
    }
    @media (prefers-reduced-motion: reduce) {
        .partners-card, .partners-menu-inner, .partner-link, .partners-chevron { transition: none; }
    }

    .section-label {
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.14em;
        color: #94a3b8;
        text-transform: uppercase;
        margin: 76px 0 24px;
    }
    .grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 28px;
    }
    @media (max-width: 760px) {
        .grid { grid-template-columns: 1fr; }
        header { padding: 24px 24px 0; }
        main { padding: 32px 24px; }
    }
    .card {
        background: #fff;
        border-radius: 16px;
        border-left: 4px solid var(--card-accent, #cbd5e1);
        padding: 44px 40px;
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
        min-height: 260px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        transition: transform 150ms ease, box-shadow 150ms ease;
    }
    a.card:hover {
        transform: translateY(-3px);
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.1);
    }
    .card-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        background: var(--card-accent-soft, rgba(202, 138, 4, 0.12));
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--card-accent, #94a3b8);
        margin-bottom: 24px;
    }
    .card-icon svg { width: 26px; height: 26px; }
    .card-tag {
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--card-accent, #94a3b8);
        display: block;
        margin-bottom: 12px;
    }
    .card h3 {
        font-size: 26px;
        font-weight: 700;
        margin: 0 0 14px;
        color: #0f172a;
        letter-spacing: -0.01em;
    }
    .card p {
        font-size: 15px;
        color: #64748b;
        line-height: 1.6;
        margin: 0;
        max-width: 46ch;
    }
    .card-open {
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 13.5px;
        font-weight: 600;
        color: #334155;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: auto;
        padding-top: 28px;
    }

    /* Coming-soon state (2026-08-12): same card shape/rhythm as a real
       system card so the grid doesn't visually lurch once this becomes a
       live link — just not clickable, and says so, rather than silently
       omitted (which would read as "we forgot this" more than "not yet"). */
    .card.coming-soon {
        cursor: default;
        filter: grayscale(0.4);
    }
    .card.coming-soon:hover {
        transform: none;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .coming-soon-badge {
        font-family: 'Fira Code', ui-monospace, monospace;
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #64748b;
        background: #e2e8f0;
        padding: 3px 9px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        margin-top: auto;
        width: fit-content;
    }
</style>
</head>
<body>
<header>
    <div class="eyebrow">
        <span>Internal Tools</span>
        <span class="greeting">
            @if(auth()->user()->isAtLeastAdmin())
            <span class="manage-dropdown" data-manage-dropdown>
                <button type="button" class="manage-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                    Management
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                </button>
                <span class="manage-dropdown-menu">
                    <a href="{{ route('hub.users') }}">User Management</a>
                    <a href="{{ route('hub.tsa-management') }}">TSA Management</a>
                    <a href="{{ route('hub.product-management') }}">Product Management</a>
                </span>
            </span>
            ·
            @endif
            Signed in as {{ auth()->user()->name }} ·
            <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button type="submit" class="signout">Sign out</button></form>
        </span>
    </div>
</header>
<main>
    <div class="hero">
        <div>
            <div class="brand">
                <img src="{{ asset('images/sellershub-logo.png') }}" alt="Seller's Hub" class="brand-logo">
                <h1>Seller's Hub<br><span class="gold">TSD</span></h1>
            </div>
            <p class="subtitle">Everything running the telesales operation, in one place — reporting, TSA performance, and lead assignment.</p>
        </div>

        {{-- Partners — each runs its own Railway deployment (separate repo,
             database and login); this only links out. Admin-only. --}}
        @if(auth()->user()->isAtLeastAdmin())
        @php
            $partners = [
                ['name' => 'PEAK.CO', 'badge' => 'PK', 'color' => '#0f766e', 'url' => 'https://peak-co-production.up.railway.app'],
                ['name' => 'M&J',     'badge' => 'MJ', 'color' => '#7c3aed', 'url' => 'https://mj-leads-production.up.railway.app'],
                ['name' => 'MLC',     'badge' => 'MLC', 'color' => '#2563eb', 'url' => 'https://mlc-leads-production.up.railway.app'],
            ];
        @endphp
        <div class="partners" data-partners>
            <button type="button" class="partners-card" aria-haspopup="true" aria-expanded="false">
                <span class="partners-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                </span>
                <span>
                    <span class="partners-label">Partners</span>
                    <span class="partners-title">{{ count($partners) }} partner systems</span>
                </span>
                <svg class="partners-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="partners-menu">
                <div class="partners-menu-inner">
                    @foreach($partners as $p)
                    <a class="partner-link" style="--partner-color:{{ $p['color'] }}" href="{{ $p['url'] }}" target="_blank" rel="noopener">
                        <span class="partner-badge">{{ $p['badge'] }}</span>
                        <span>
                            <span class="partner-name">{{ $p['name'] }}</span>
                            <span class="partner-sub">Leads Reports</span>
                        </span>
                        <span class="partner-arrow">↗</span>
                    </a>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </div>

    <p class="section-label">Select a system</p>
    <div class="grid">

        <a class="card" style="--card-accent:#CA8A04; --card-accent-soft:rgba(202,138,4,0.12)" href="{{ route('dashboard') }}">
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                </svg>
            </div>
            <span class="card-tag">Reporting</span>
            <h3>TSD Leads Reports</h3>
            <p>Pancake POS sales reporting — leads, TSA performance, upsell tracking, and analytics.</p>
            <span class="card-open">Open →</span>
        </a>

        {{-- Call Tracker is a section of this same app (merged 2026-08-12),
             not a separate system to hand off to — the routes themselves
             are always live. This flag only controls whether the Hub card
             advertises it as ready yet (explicit request 2026-08-13: show
             "Coming soon" in production until the call-recording storage
             question is resolved). --}}
        @if(config('services.call_tracker.enabled'))
        <a class="card" style="--card-accent:#0d9488; --card-accent-soft:rgba(13,148,136,0.12)" href="{{ route('calls.dashboard') }}">
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
            </div>
            <span class="card-tag">Operations</span>
            <h3>TSD Call Tracker</h3>
            <p>Pancake-connected round-robin lead assignment with free click-to-call.</p>
            <span class="card-open">Open →</span>
        </a>
        @else
        <div class="card coming-soon" style="--card-accent:#0d9488; --card-accent-soft:rgba(13,148,136,0.12)">
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
            </div>
            <span class="card-tag">Operations</span>
            <h3>TSD Call Tracker</h3>
            <p>Pancake-connected round-robin lead assignment with free click-to-call.</p>
            <span class="coming-soon-badge">Coming soon</span>
        </div>
        @endif

        {{-- Opened up to TSAs too (explicit request, 2026-10-07: "make it
             the data management module is visible for the TSA's (NORMAL
             USERS)" — reverses the original 2026-09-23 admin-only
             decision). Cost Breakdown itself (real payroll/salary data)
             stays admin-only inside the module — see data.blade.php's
             own sidebar link for that gate. --}}
        <a class="card" style="--card-accent:#7c3aed; --card-accent-soft:rgba(124,58,237,0.12)" href="{{ route('data.projections') }}">
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.125C3 6.504 3.504 6 4.125 6h4.243c.3 0 .587.12.8.331l1.383 1.383c.211.211.498.331.8.331h8.524c.621 0 1.125.504 1.125 1.125v9.705c0 .621-.504 1.125-1.125 1.125H4.125A1.125 1.125 0 013 18.875V7.125z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 17.25v-3M12 17.25v-5.25M15.75 17.25v-7.5"/>
                </svg>
            </div>
            <span class="card-tag">Finance</span>
            <h3>TSD Data Management</h3>
            <p>Editable P&amp;L targets and planning projections — department, shift, and per-TSA goals.</p>
            <span class="card-open">Open →</span>
        </a>

        {{-- Partners Data Management (2026-10-29) — Data Management on the
             partners' synced data (App\Support\DataSet). Admin-only. --}}
        @if(auth()->user()->isAtLeastAdmin())
        <a class="card" style="--card-accent:#db2777; --card-accent-soft:rgba(219,39,119,0.12)" href="{{ route('data.projections', ['dm' => 'partners']) }}">
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6"/>
                </svg>
            </div>
            <span class="card-tag">Finance</span>
            <h3>Partners Data Management</h3>
            <p>Data Management for PEAK.CO, M&amp;J and MLC — their leads and orders, your costs and projections, per partner or all together.</p>
            <span class="card-open">Open →</span>
        </a>
        @endif


    </div>
</main>
<script>
    document.querySelectorAll('[data-manage-dropdown]').forEach(function (dropdown) {
        var toggle = dropdown.querySelector('.manage-dropdown-toggle');
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = dropdown.classList.toggle('open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });
    // Partners — hover opens it on desktop; a tap toggles it on touch.
    document.querySelectorAll('[data-partners]').forEach(function (box) {
        var card = box.querySelector('.partners-card');
        card.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = box.classList.toggle('open');
            card.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('[data-partners].open').forEach(function (box) {
            box.classList.remove('open');
            box.querySelector('.partners-card').setAttribute('aria-expanded', 'false');
        });
        document.querySelectorAll('[data-manage-dropdown].open').forEach(function (dropdown) {
            dropdown.classList.remove('open');
            dropdown.querySelector('.manage-dropdown-toggle').setAttribute('aria-expanded', 'false');
        });
    });
</script>
</body>
</html>
