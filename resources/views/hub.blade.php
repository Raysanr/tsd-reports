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
            <a class="manage-users" href="{{ route('hub.users') }}">User Management</a> ·
            @endif
            Signed in as {{ auth()->user()->name }} ·
            <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button type="submit" class="signout">Sign out</button></form>
        </span>
    </div>
</header>
<main>
    <div class="brand">
        <img src="{{ asset('images/sellershub-logo.png') }}" alt="Seller's Hub" class="brand-logo">
        <h1>Seller's Hub<br><span class="gold">TSD</span></h1>
    </div>
    <p class="subtitle">Everything running the telesales operation, in one place — reporting, TSA performance, and lead assignment.</p>

    <p class="section-label">Select a system</p>
    <div class="grid">

        <a class="card" style="--card-accent:#CA8A04; --card-accent-soft:rgba(202,138,4,0.12)" href="{{ route('dashboard') }}">
            <div class="card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                </svg>
            </div>
            <span class="card-tag">Reporting</span>
            <h3>TSD Reports</h3>
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
            <h3>Call Tracker</h3>
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
            <h3>Call Tracker</h3>
            <p>Pancake-connected round-robin lead assignment with free click-to-call.</p>
            <span class="coming-soon-badge">Coming soon</span>
        </div>
        @endif

    </div>
</main>
</body>
</html>
