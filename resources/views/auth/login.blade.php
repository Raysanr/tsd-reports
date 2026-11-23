{{-- Sign-in page — "Bright Bento" design (picked 2026-11-23). Google sign-in
     only; people not in User Management become access requests. Standalone
     (not a shared layout; the error pages use layouts.error in the same style). --}}
@php
    $error = $errors->first('email');
    // "request sent" is good news (yellow); declined / deactivated / failed are red
    $isPending = $error && str_contains($error, 'was sent');
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --accent: #FACC15; --ink: #18181b; --muted: #52525b; --soft: #71717a; --ground: #f4f4f5; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--ground); color: var(--ink); font-family: 'Plus Jakarta Sans', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .page { min-height: 100vh; padding: 24px; display: flex; }
        .wrap { flex: 1; display: flex; flex-wrap: wrap; gap: 24px; max-width: 1400px; margin: 0 auto; width: 100%; }
        .panel { flex: 1 1 420px; min-width: 0; background: #fff; border-radius: 28px; padding: 48px 56px; display: flex; flex-direction: column; justify-content: space-between; gap: 40px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 16px; letter-spacing: -0.01em; }
        .brand-mark { width: 36px; height: 36px; border-radius: 10px; background: var(--ink); display: flex; align-items: center; justify-content: center; }
        h1 { margin: 0; font-size: 46px; line-height: 1.08; font-weight: 800; letter-spacing: -0.035em; }
        h1 span { color: #CA8A04; }
        .lead { margin: 16px 0 0; font-size: 16px; line-height: 1.6; color: var(--muted); }
        .notice { margin-top: 24px; padding: 14px 16px; border-radius: 16px; font-size: 14px; line-height: 1.5; }
        .notice-info { background: #fefce8; color: #713f12; }
        .notice-error { background: #fef2f2; color: #991b1b; }
        .google { margin-top: 32px; display: inline-flex; align-items: center; gap: 14px; min-height: 58px; padding: 9px 28px 9px 9px; border-radius: 999px; background: var(--ink); color: #fff; text-decoration: none; font-size: 16px; font-weight: 700; transition: transform .15s ease, box-shadow .15s ease; }
        .google:hover { transform: translateY(-1px); box-shadow: 0 12px 28px -14px rgba(24,24,27,.7); }
        .google:focus-visible { outline: 3px solid #CA8A04; outline-offset: 3px; }
        .google-g { width: 40px; height: 40px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .hint { margin: 20px 0 0; font-size: 13px; line-height: 1.6; color: var(--muted); }
        .foot { margin: 0; font-size: 12px; color: var(--soft); }
        .bento { flex: 1.3 1 520px; min-width: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); grid-auto-rows: minmax(160px, auto); gap: 24px; }
        .tile { border-radius: 28px; padding: 28px; background: #fff; }
        .tile-hero { grid-column: span 2; background: var(--accent); padding: 40px; display: flex; flex-direction: column; justify-content: space-between; gap: 32px; position: relative; overflow: hidden; }
        .tile-hero .chip { position: relative; align-self: flex-start; padding: 6px 12px; border-radius: 999px; background: rgba(24,24,27,.1); font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .tile-hero p { position: relative; margin: 0; font-size: 38px; line-height: 1.1; font-weight: 800; letter-spacing: -0.03em; max-width: 480px; }
        .circle { position: absolute; border-radius: 50%; }
        .tile-bars { background: var(--ink); display: flex; align-items: flex-end; gap: 10px; }
        .tile-bars span { flex: 1; border-radius: 10px; }
        .tile-card { display: flex; flex-direction: column; justify-content: space-between; gap: 20px; }
        .tile-title { margin: 0; font-size: 19px; font-weight: 700; }
        .tile-text { margin: 6px 0 0; font-size: 14px; line-height: 1.5; color: var(--muted); }
        .icon-box { width: 52px; height: 52px; border-radius: 16px; background: #fefce8; display: flex; align-items: center; justify-content: center; }
        .tile-wide { grid-column: span 2; display: flex; flex-wrap: wrap; align-items: center; gap: 20px; }
        .dots { display: flex; }
        .dots span { width: 44px; height: 44px; border-radius: 50%; border: 3px solid #fff; }
        .dots span + span { margin-left: -12px; }
        @media (max-width: 720px) {
            .page { padding: 12px; }
            .panel { padding: 32px 24px; border-radius: 24px; }
            h1 { font-size: 36px; }
            .bento { display: none; } /* phones: just the sign-in */
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

            <div style="max-width: 420px">
                <h1>Good to see you<span>.</span></h1>
                <p class="lead">Sign in with your work Google account to open the Telesales workspace.</p>

                @if(session('status'))
                    <div class="notice notice-info" role="status">{{ session('status') }}</div>
                @endif
                @if($error)
                    <div class="notice {{ $isPending ? 'notice-info' : 'notice-error' }}" role="{{ $isPending ? 'status' : 'alert' }}">{{ $error }}</div>
                @endif

                <a href="{{ route('google.redirect') }}" class="google">
                    <span class="google-g">
                        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.48-1.14 2.73-2.4 3.58v3h3.86c2.26-2.09 3.56-5.17 3.56-8.82z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.86-3c-1.08.72-2.45 1.16-4.07 1.16-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09C3.26 21.3 7.31 24 12 24z"/><path fill="#FBBC05" d="M5.27 14.29c-.25-.72-.38-1.49-.38-2.29s.14-1.57.38-2.29V6.62H1.29A11.86 11.86 0 000 12c0 1.94.47 3.76 1.29 5.38l3.98-3.09z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></svg>
                    </span>
                    Continue with Google
                </a>

                <p class="hint">First time here? Signing in sends a request. An admin accepts it, then you're in.</p>
            </div>

            <p class="foot">For the SH E-Com Telesales team only.</p>
        </main>

        <section class="bento" aria-label="About the workspace">
            <div class="tile tile-hero">
                <span class="circle" style="right: -80px; bottom: -80px; width: 300px; height: 300px; background: rgba(24,24,27,.08)" aria-hidden="true"></span>
                <span class="circle" style="right: 60px; bottom: 60px; width: 120px; height: 120px; background: rgba(24,24,27,.10)" aria-hidden="true"></span>
                <span class="chip">Telesales workspace</span>
                <p>Everything the team needs, in one sign-in.</p>
            </div>

            <div class="tile tile-bars" aria-hidden="true">
                <span style="height: 38%; background: #3f3f46"></span>
                <span style="height: 62%; background: #52525b"></span>
                <span style="height: 48%; background: #3f3f46"></span>
                <span style="height: 86%; background: var(--accent)"></span>
                <span style="height: 70%; background: #52525b"></span>
            </div>

            <div class="tile tile-card">
                <span class="icon-box">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#a16207" stroke-width="1.8" aria-hidden="true"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z" stroke-linejoin="round"/><path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <div>
                    <p class="tile-title">Private by design</p>
                    <p class="tile-text">Google sign-in, and only people an admin accepts.</p>
                </div>
            </div>

            <div class="tile tile-wide">
                <div class="dots" aria-hidden="true">
                    <span style="background: #18181b"></span>
                    <span style="background: var(--accent)"></span>
                    <span style="background: #d4d4d8"></span>
                    <span style="background: #fde68a"></span>
                </div>
                <div style="flex: 1; min-width: 200px">
                    <p class="tile-title">Made for the team</p>
                    <p class="tile-text">Supervisors, TSAs and admins work from the same place.</p>
                </div>
            </div>
        </section>

    </div>
</div>
</body>
</html>
