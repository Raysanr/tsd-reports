{{-- Error pages (403/404/429/500) — same "Bright Bento" look as the sign-in
     page (2026-11-23). Standalone on purpose: no @vite, no database, no
     session lookups, so it still renders when the app itself is failing.
     Sections: code, title, message, tone (rose|gold|amber), action_url,
     action_label. --}}
@php
    $tones = [
        'rose'  => ['tile' => '#FDA4AF', 'ink' => '#881337', 'soft' => '#fff1f2'],
        'gold'  => ['tile' => '#FACC15', 'ink' => '#713f12', 'soft' => '#fefce8'],
        'amber' => ['tile' => '#FDBA74', 'ink' => '#7c2d12', 'soft' => '#fff7ed'],
    ];
    $tone = $tones[trim($__env->yieldContent('tone', 'gold'))] ?? $tones['gold'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') — TSD Reports Hub</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #f4f4f5; color: #18181b; font-family: 'Plus Jakarta Sans', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .page { min-height: 100vh; padding: 24px; display: flex; }
        .wrap { flex: 1; display: flex; flex-wrap: wrap; gap: 24px; max-width: 1400px; margin: 0 auto; width: 100%; }
        .panel { flex: 1 1 420px; min-width: 0; background: #fff; border-radius: 28px; padding: 48px 56px; display: flex; flex-direction: column; justify-content: space-between; gap: 40px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 16px; letter-spacing: -0.01em; }
        .brand-mark { width: 36px; height: 36px; border-radius: 10px; background: #18181b; display: flex; align-items: center; justify-content: center; }
        .chip { display: inline-block; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        h1 { margin: 18px 0 0; font-size: 44px; line-height: 1.08; font-weight: 800; letter-spacing: -0.035em; }
        .msg { margin: 16px 0 0; font-size: 16px; line-height: 1.6; color: #52525b; max-width: 440px; }
        .actions { margin-top: 32px; display: flex; flex-wrap: wrap; gap: 12px; }
        .btn { display: inline-flex; align-items: center; gap: 10px; min-height: 52px; padding: 0 24px; border-radius: 999px; font-size: 15px; font-weight: 700; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn:focus-visible { outline: 3px solid #CA8A04; outline-offset: 3px; }
        .btn-dark { background: #18181b; color: #fff; }
        .btn-dark:hover { box-shadow: 0 12px 28px -14px rgba(24,24,27,.7); }
        .btn-light { background: #f4f4f5; color: #18181b; }
        .foot { margin: 0; font-size: 12px; color: #71717a; }
        .art { flex: 1.3 1 520px; min-width: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-rows: minmax(300px, 1fr) minmax(150px, auto); gap: 24px; }
        .tile { border-radius: 28px; position: relative; overflow: hidden; }
        .tile-code { grid-column: span 2; padding: 40px; display: flex; align-items: flex-end; }
        .code { position: relative; font-size: clamp(120px, 18vw, 240px); line-height: .85; font-weight: 800; letter-spacing: -0.06em; }
        .circle { position: absolute; border-radius: 50%; background: rgba(24,24,27,.08); }
        .tile-bars { background: #18181b; padding: 28px; display: flex; align-items: flex-end; gap: 10px; }
        .tile-bars span { flex: 1; border-radius: 10px; }
        .tile-note { background: #fff; padding: 28px; display: flex; flex-direction: column; justify-content: center; gap: 6px; }
        .tile-note b { font-size: 17px; }
        .tile-note span { font-size: 14px; line-height: 1.5; color: #52525b; }
        @media (max-width: 720px) {
            .page { padding: 12px; }
            .panel { padding: 32px 24px; border-radius: 24px; }
            h1 { font-size: 34px; }
            .art { display: none; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="wrap">
        <main class="panel">
            <div class="brand">
                <span class="brand-mark">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FACC15" stroke-width="2.2" aria-hidden="true"><path d="M4 19V5M4 19h16M8 15l3-4 3 2 5-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                TSD Reports Hub
            </div>

            <div>
                <span class="chip" style="background: {{ $tone['soft'] }}; color: {{ $tone['ink'] }}">Error @yield('code')</span>
                <h1>@yield('title')</h1>
                <p class="msg">@yield('message')</p>
                <div class="actions">
                    <a href="@yield('action_url')" class="btn btn-dark">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        @yield('action_label')
                    </a>
                    @hasSection('show_back')
                    <a href="javascript:history.back()" class="btn btn-light">Go back</a>
                    @endif
                </div>
            </div>

            <p class="foot">For the SH E-Com Telesales team only.</p>
        </main>

        <section class="art" aria-hidden="true">
            <div class="tile tile-code" style="background: {{ $tone['tile'] }}">
                <span class="circle" style="right: -90px; top: -90px; width: 320px; height: 320px"></span>
                <span class="circle" style="right: 70px; top: 70px; width: 120px; height: 120px"></span>
                <span class="code" style="color: {{ $tone['ink'] }}">@yield('code')</span>
            </div>
            <div class="tile tile-bars">
                <span style="height: 38%; background: #3f3f46"></span>
                <span style="height: 62%; background: #52525b"></span>
                <span style="height: 48%; background: #3f3f46"></span>
                <span style="height: 86%; background: {{ $tone['tile'] }}"></span>
                <span style="height: 70%; background: #52525b"></span>
            </div>
            <div class="tile tile-note">
                <b>@yield('note_title', 'Need a hand?')</b>
                <span>@yield('note_text', 'Ask an admin or your supervisor.')</span>
            </div>
        </section>
    </div>
</div>
</body>
</html>
