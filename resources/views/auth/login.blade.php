{{-- Sign-in page — "Halo" design (picked 2026-11-23): glass card over a honey
     aurora, rotating motivational headline, orbit of workspace chips. Light +
     dark (follows the OS). Google sign-in only; people not in User Management
     become access requests. Standalone (no @vite), so it renders even when the
     app's assets fail. --}}
@php
    // one-time errors (deactivated, Google sign-in failed) — red
    $error = $errors->first('email');
    // this browser's access request, remembered across reloads (AuthController::showLogin)
    $accessRequest ??= null;
    $requestNotice = match ($accessRequest?->status) {
        'pending'  => ['info', 'Your request is waiting for an admin.', 'Sent ' . $accessRequest->created_at->diffForHumans() . ' as ' . $accessRequest->email . '. Sign in again once you\'ve been accepted.'],
        'approved' => ['ok', 'You\'ve been accepted.', 'Continue with Google below to sign in.'],
        'rejected' => ['error', 'Your request was declined.', 'Ask an admin if this is a mistake.'],
        default    => null,
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in — TSD Reports Hub</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #F6F4EE; --surface: rgba(255,255,255,.62); --surface-solid: #fff; --stroke: rgba(30,26,12,.08);
            --fg: #17150F; --muted: #6B6657; --accent: #F2CE4A; --accent-2: #F7A93B; --accent-ink: #1A1508;
            --btn: #17150F; --btn-fg: #fff;
            --n-info-bg: #fefce8; --n-info-fg: #713f12; --n-error-bg: #fef2f2; --n-error-fg: #991b1b; --n-ok-bg: #ecfdf5; --n-ok-fg: #065f46;
            --sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --serif: 'Instrument Serif', Georgia, serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0F0E0B; --surface: rgba(30,28,22,.6); --surface-solid: #1C1A15; --stroke: rgba(255,240,200,.09);
                --fg: #F5F1E6; --muted: #A39D8B; --accent-2: #E08A2C; --btn: #F2CE4A; --btn-fg: #1A1508;
                --n-info-bg: rgba(242,206,74,.12); --n-info-fg: #F5DC86; --n-error-bg: rgba(239,68,68,.14); --n-error-fg: #FCA5A5; --n-ok-bg: rgba(16,185,129,.14); --n-ok-fg: #6EE7B7;
                color-scheme: dark;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--fg); font-family: var(--sans); font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased; overflow-x: hidden; }

        .aurora { position: fixed; inset: 0; overflow: hidden; pointer-events: none; z-index: 0; }
        .aurora i { position: absolute; border-radius: 50%; filter: blur(80px); opacity: .75; }
        .aurora i:nth-child(1) { width: 55vw; height: 55vw; background: var(--accent); right: -10vw; top: -15vw; animation: drift 18s ease-in-out infinite alternate; }
        .aurora i:nth-child(2) { width: 38vw; height: 38vw; background: var(--accent-2); right: 18vw; bottom: -18vw; opacity: .45; animation: drift 22s ease-in-out infinite alternate-reverse; }
        .aurora i:nth-child(3) { width: 26vw; height: 26vw; background: var(--accent); left: -8vw; bottom: 10vh; opacity: .3; animation: drift 26s ease-in-out infinite alternate; }
        @keyframes drift { to { transform: translate(-6vw, 5vh) scale(1.12); } }
        .grain { position: fixed; inset: 0; pointer-events: none; z-index: 1; opacity: .05; background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='160' height='160'><filter id='n'><feTurbulence baseFrequency='.9' numOctaves='2'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>"); }

        .page { position: relative; z-index: 2; max-width: 1240px; margin: 0 auto; min-height: 100vh; display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 40px; align-items: center; padding: 32px max(16px, env(safe-area-inset-right, 0px)) 32px max(16px, env(safe-area-inset-left, 0px)); }

        .card { background: var(--surface); backdrop-filter: blur(24px) saturate(1.4); -webkit-backdrop-filter: blur(24px) saturate(1.4); border: 1px solid var(--stroke); border-radius: 32px; padding: clamp(28px, 4vw, 48px); display: grid; gap: 32px; box-shadow: 0 40px 80px -40px rgba(60,45,0,.35); animation: in .8s cubic-bezier(.2,.9,.2,1) both; }
        @keyframes in { from { transform: translateY(16px); } }
        .brand { display: flex; align-items: center; gap: 12px; font-weight: 700; font-size: 16px; flex-wrap: wrap; }
        .logo { width: 44px; height: 44px; object-fit: contain; display: block; }
        .pill { margin-left: auto; font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--stroke); color: var(--muted); }
        h1 { margin: 0; min-height: 2em; font-weight: 800; font-size: clamp(38px, 5vw, 60px); line-height: 1; letter-spacing: -.04em; text-wrap: balance; transition: opacity .4s, transform .4s; }
        h1.out { opacity: 0; transform: translateY(8px); }
        h1 em { font-family: var(--serif); font-weight: 400; font-style: italic; letter-spacing: -.01em; background: linear-gradient(90deg, var(--accent-2), var(--accent)); -webkit-background-clip: text; background-clip: text; color: transparent; padding-right: .05em; }
        .lede { margin: 14px 0 0; color: var(--muted); max-width: 42ch; font-size: 16px; }

        .notice { padding: 14px 16px; border-radius: 16px; font-size: 14px; line-height: 1.5; }
        .notice-info { background: var(--n-info-bg); color: var(--n-info-fg); }
        .notice-error { background: var(--n-error-bg); color: var(--n-error-fg); }
        .notice-ok { background: var(--n-ok-bg); color: var(--n-ok-fg); }
        .notice-link { display: block; margin-top: 8px; font-size: 13px; font-weight: 600; color: inherit; }

        .actions { display: grid; gap: 14px; }
        .google { display: flex; align-items: center; gap: 14px; padding: 8px 22px 8px 8px; border-radius: 18px; background: var(--btn); color: var(--btn-fg); text-decoration: none; font-weight: 700; font-size: 16px; position: relative; overflow: hidden; transition: transform .2s; }
        .google::after { content: ""; position: absolute; inset: 0; background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.22) 50%, transparent 70%); transform: translateX(-100%); transition: transform .7s; }
        .google:hover { transform: translateY(-2px); }
        .google:hover::after { transform: translateX(100%); }
        .google:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
        .google-g { width: 44px; height: 44px; border-radius: 12px; background: #fff; display: grid; place-items: center; flex-shrink: 0; }
        .google .chev { margin-left: auto; opacity: .6; }
        .hint { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; color: var(--muted); margin: 0; }
        .hint svg { flex: none; margin-top: 2px; }
        .foot { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 12px; color: var(--muted); border-top: 1px solid var(--stroke); padding-top: 20px; }

        .orbit { position: relative; aspect-ratio: 1; width: min(520px, 100%); justify-self: center; }
        .ring { position: absolute; inset: 8%; border-radius: 50%; border: 1px dashed var(--stroke); }
        .ring.r2 { inset: 24%; }
        .core { position: absolute; inset: 36%; border-radius: 50%; background: var(--surface-solid); border: 1px solid var(--stroke); display: grid; place-items: center; text-align: center; box-shadow: 0 20px 60px -20px rgba(242,206,74,.6); }
        .core b { display: block; font-size: clamp(26px, 3.6vw, 38px); font-weight: 800; letter-spacing: -.03em; line-height: 1; }
        .core span { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .1em; }
        .chip { position: absolute; background: var(--surface); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid var(--stroke); border-radius: 16px; padding: 10px 14px; display: flex; gap: 10px; align-items: center; font-size: 13px; font-weight: 600; white-space: nowrap; box-shadow: 0 12px 30px -16px rgba(60,45,0,.4); animation: float 6s ease-in-out infinite; }
        .chip small { display: block; font-weight: 500; color: var(--muted); font-size: 11px; }
        .chip .ic { width: 30px; height: 30px; border-radius: 10px; display: grid; place-items: center; background: var(--accent); color: var(--accent-ink); }
        .c1 { top: 4%; left: 4%; } .c2 { top: 20%; right: -2%; animation-delay: -2s; } .c3 { bottom: 14%; left: -2%; animation-delay: -4s; } .c4 { bottom: 2%; right: 10%; animation-delay: -1s; }
        @keyframes float { 50% { transform: translateY(-10px); } }

        @media (max-width: 880px) {
            .page { grid-template-columns: 1fr; padding-block: 16px; }
            .orbit { display: none; } /* phones/tablets: just the sign-in */
        }
        @media (prefers-reduced-motion: reduce) { *, *::after { animation: none !important; transition: none !important; } }
    </style>
</head>
<body>
<div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
<div class="grain" aria-hidden="true"></div>

<div class="page">
    <main class="card" aria-labelledby="headline">
        <div class="brand">
            <img class="logo" src="{{ asset('images/sellershub-logo.png') }}" alt="" width="44" height="44">
            TSD Reports Hub
            <span class="pill">Telesales workspace</span>
        </div>

        <div>
            <h1 id="headline">Make every call <em>count.</em></h1>
            <p class="lede">Sign in with your work Google account to open the Telesales workspace.</p>
        </div>

        <div class="actions">
            @if(session('status'))
                <div class="notice notice-info" role="status">{{ session('status') }}</div>
            @endif
            @if($error)
                <div class="notice notice-error" role="alert">{{ $error }}</div>
            @elseif($requestNotice)
                @php [$kind, $headline, $detail] = $requestNotice; @endphp
                <div class="notice notice-{{ $kind }}" role="{{ $kind === 'error' ? 'alert' : 'status' }}">
                    <strong>{{ $headline }}</strong> {{ $detail }}
                    <a href="{{ route('login', ['other' => 1]) }}" class="notice-link">Use a different account</a>
                </div>
            @endif

            <a href="{{ route('google.redirect') }}" class="google">
                <span class="google-g">
                    <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/><path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29A11.86 11.86 0 000 12c0 1.94.47 3.76 1.29 5.38l3.98-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></svg>
                </span>
                Continue with Google
                <svg class="chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </a>

            <p class="hint">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>
                First time here? Signing in sends a request. An admin accepts it, then you're in.
            </p>
        </div>

        <div class="foot"><span>For the SH E-Com Telesales team only.</span><span>Supervisors · TSAs · Admins</span></div>
    </main>

    <section class="orbit" aria-label="What's inside the workspace">
        <div class="ring"></div><div class="ring r2"></div>
        <div class="core"><div><b>TSD</b><span>Reports Hub</span></div></div>
        <div class="chip c1"><span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/></svg></span><div>Daily reports<small>Calls &amp; orders</small></div></div>
        <div class="chip c2"><span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 01-10 0z"/></svg></span><div>Scoreboard<small>Team ranking</small></div></div>
        <div class="chip c3"><span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.5c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z"/></svg></span><div>Call Tracker<small>Leads &amp; logs</small></div></div>
        <div class="chip c4"><span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg></span><div>Archive<small>TSA records</small></div></div>
    </section>
</div>

<script>
(function () {
    var lines = [
        ['Make every call', 'count.'],
        ['Your next yes is', 'one call away.'],
        ["Today's target starts", 'with you.'],
        ['Small wins build', 'the board.'],
        ['Show up. Dial in.', 'Close strong.']
    ];
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var h = document.getElementById('headline'), i = 0;
    setInterval(function () {
        h.classList.add('out');
        setTimeout(function () {
            i = (i + 1) % lines.length;
            h.textContent = lines[i][0] + ' ';
            var em = document.createElement('em');
            em.textContent = lines[i][1];
            h.appendChild(em);
            h.classList.remove('out');
        }, 400);
    }, 4500);
})();
</script>
</body>
</html>
