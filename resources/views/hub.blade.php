<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seller's Hub TSD</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
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
     partner systems in their own dark tile. Light and dark mode.
     2026-11-18: split layout — modules left, full month calendar right. --}}
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
        padding: 40px 8px 28px; display: flex; flex-wrap: wrap; gap: 24px;
        align-items: flex-end; justify-content: space-between;
    }
    .intro h1 {
        flex: 1 1 520px; min-width: 0; margin: 0; font-weight: 800;
        font-size: clamp(34px, 4.4vw, 58px); line-height: 1; letter-spacing: -0.04em;
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
    .hub-intro .cal-panel { animation: hubRise 620ms cubic-bezier(.2,.7,.2,1) 460ms both; }
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

    /* ---------- Calendar (2026-11-17, inline 2026-11-18) ----------
       Split layout (explicit request, 2026-11-18): modules on the left, the
       full month on the right so it's visible right after login. Clicking a
       day opens the pop-up with that day's holidays/agendas (admins add
       agendas there). PH holidays are fixed. */
    .hub-split { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.08fr); gap: 20px; align-items: stretch; }
    .hub-split .bento { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-auto-rows: auto; gap: 14px; align-content: start; }
    .hub-split .tile { padding: 22px; border-radius: 24px; gap: 8px; }
    .hub-split .tile .tag { margin-top: 6px; }
    .hub-split .tile h3 { font-size: 20px; }
    .hub-split .tile p { font-size: 13.5px; }
    .hub-split .tile-icon { width: 46px; height: 46px; border-radius: 14px; }
    .hub-split .hero { grid-column: span 2; grid-row: auto; flex-direction: row; align-items: center; gap: 28px; padding: 28px 30px; }
    .hub-split .hero .bars { margin: 0; flex: none; width: 92px; height: 74px; }
    .hero-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
    .hero-label { font-size: 12px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
    .hub-split .hero h2 { margin: 0; max-width: none; font-size: clamp(28px, 2.6vw, 38px); }
    .hub-split .hero p { font-size: 14.5px; }
    .hub-split .hero .arrow { align-self: flex-start; flex: none; }
    .hub-split .partners { grid-column: span 2; background: var(--surface); color: var(--ink); padding: 20px 22px; gap: 12px; }
    .hub-split .partners-head h2 { font-size: 19px; }
    .hub-split .partners-head span { color: var(--muted); }
    .hub-split .partner-list { margin-top: 0; }
    .hub-split .partner { background: var(--surface-2); color: var(--ink); padding: 10px 12px; border-radius: 14px; }
    .hub-split .partner:hover { background: var(--line); }
    .hub-split .partner-badge { width: 36px; height: 36px; border-radius: 10px; font-size: 11px; }
    .hub-split .partner-name { font-size: 14px; }
    .hub-split .partner-sub, .hub-split .partner-out { color: var(--muted); }

    .cal-panel { display: flex; flex-direction: column; min-height: 640px; padding: 22px 22px 16px; border-radius: 28px; background: var(--surface); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05), 0 12px 32px -12px rgba(15, 23, 42, 0.12); }
    .cal-panel-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding-bottom: 14px; }
    .cal-panel-head > svg { width: 28px; height: 28px; flex: none; }
    .cal-panel-head h2 { margin: 0 auto 0 0; font-size: clamp(24px, 2.2vw, 32px); font-weight: 800; letter-spacing: -0.03em; }
    .cal-panel-head h2 span { font-weight: 500; color: var(--muted); }
    .cal-panel .cal-wd { background: var(--surface-2); border-radius: 12px 12px 0 0; }
    .cal-panel .cal-wd span { text-align: center; color: var(--ink-2); }
    .cal-panel .cal-grid { flex: 1; grid-template-rows: repeat(6, minmax(84px, 1fr)); border: 1px solid var(--line); border-top: 0; border-radius: 0 0 12px 12px; overflow: hidden; }
    .cal-panel .cal-cell:nth-last-child(-n+7) { border-bottom: 0; }
    .cal-panel .cal-cell .num { align-self: flex-start; }
    .cal-panel .cal-cell.today { background: color-mix(in srgb, var(--cal-red) 6%, transparent); }
    .cal-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; padding-top: 12px; font-size: 12px; font-weight: 600; color: var(--muted); }
    .cal-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; background: color-mix(in srgb, var(--c) 22%, transparent); box-shadow: inset 0 0 0 1px var(--c); }
    .cal-legend span:last-child { margin-left: auto; }

    @media (max-width: 1100px) {
        .hub-split { grid-template-columns: minmax(0, 1fr); }
        .cal-panel { min-height: 0; }
    }
    @media (max-width: 640px) {
        .hub-split .bento { grid-template-columns: minmax(0, 1fr); }
        .hub-split .hero, .hub-split .partners { grid-column: auto; }
        .hub-split .hero { flex-direction: column; align-items: flex-start; }
        .cal-panel { padding: 16px 12px 12px; border-radius: 22px; }
        .cal-panel .cal-grid { grid-template-rows: repeat(6, 56px); }
        .cal-legend span:last-child { margin-left: 0; }
    }

    .cal-btn:focus-visible, .cal-cell:focus-visible, .cal-chip:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .cal-overlay {
        position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 16px;
        background: rgba(15, 23, 42, 0.42); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        opacity: 0; transition: opacity 220ms ease;
    }
    .cal-overlay[hidden] { display: none; }
    .cal-overlay.is-open { opacity: 1; }
    .cal-modal {
        width: min(1180px, 100%); height: min(820px, calc(100dvh - 32px));
        background: var(--surface); color: var(--ink); border-radius: 28px; overflow: hidden;
        box-shadow: 0 40px 80px -24px rgba(15, 23, 42, 0.45);
        display: grid; grid-template-rows: auto minmax(0, 1fr);
        transform-origin: var(--ox, 50%) var(--oy, 50%);
        transform: scale(0.9) translateY(10px); opacity: 0;
        transition: transform 340ms cubic-bezier(.16, 1, .3, 1), opacity 220ms ease;
    }
    .cal-overlay.is-open .cal-modal { transform: none; opacity: 1; }
    .cal-head { display: flex; align-items: center; gap: 12px; padding: 22px 24px 14px; flex-wrap: wrap; }
    .cal-head h2 { margin: 0; font-size: clamp(26px, 3vw, 36px); font-weight: 800; letter-spacing: -0.03em; margin-right: auto; }
    .cal-head h2 span { font-weight: 500; color: var(--muted); }
    .cal-nav { display: flex; align-items: center; gap: 6px; }
    .cal-btn {
        height: 40px; min-width: 40px; padding: 0 14px; border: 0; border-radius: 999px; cursor: pointer;
        background: var(--surface-2); color: var(--ink); font: inherit; font-weight: 700; font-size: 14px;
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        transition: background 120ms ease;
    }
    .cal-btn:hover { background: var(--line); }
    .cal-btn.icon { padding: 0; width: 40px; }
    .cal-btn svg { width: 16px; height: 16px; }
    .cal-btn.primary { background: var(--ink); color: var(--bg); }
    .cal-btn.primary:hover { background: var(--ink-2); }
    .cal-btn.danger { background: transparent; color: var(--cal-red); padding: 0 10px; }
    .cal-btn.danger:hover { background: color-mix(in srgb, var(--cal-red) 12%, transparent); }
    .cal-btn:disabled { opacity: 0.5; cursor: default; }
    .cal-btn.danger-solid { background: var(--cal-red); color: #fff; padding: 0 12px; }
    .cal-btn.danger-solid:hover { background: color-mix(in srgb, var(--cal-red) 85%, #000); }
    .cal-confirm { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; padding: 10px 12px; border-radius: 12px; background: color-mix(in srgb, var(--cal-red) 10%, var(--surface)); animation: hubFade 160ms ease-out; }
    .cal-confirm-text { font-size: 13px; font-weight: 700; color: var(--ink); }

    .cal-body { display: grid; grid-template-columns: minmax(0, 1fr) 340px; min-height: 0; border-top: 1px solid var(--line); }
    .cal-month { display: grid; grid-template-rows: auto minmax(0, 1fr); min-height: 0; }
    .cal-wd { display: grid; grid-template-columns: repeat(7, 1fr); }
    .cal-wd span { padding: 10px 12px; text-align: right; font-size: 13px; font-weight: 700; color: var(--muted); }
    .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); grid-template-rows: repeat(6, minmax(0, 1fr)); border-top: 1px solid var(--line); min-height: 0; }
    .cal-grid.slide-next { animation: calSlideNext 300ms cubic-bezier(.16, 1, .3, 1); }
    .cal-grid.slide-prev { animation: calSlidePrev 300ms cubic-bezier(.16, 1, .3, 1); }
    @keyframes calSlideNext { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: none; } }
    @keyframes calSlidePrev { from { opacity: 0; transform: translateX(-24px); } to { opacity: 1; transform: none; } }
    .cal-cell {
        position: relative; min-width: 0; min-height: 0; overflow: hidden; padding: 6px 6px 4px;
        border: 0; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line);
        background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer;
        display: flex; flex-direction: column; gap: 3px; transition: background 120ms ease;
    }
    .cal-cell:nth-child(7n) { border-right: 0; }
    .cal-cell:hover { background: color-mix(in srgb, var(--surface-2) 70%, transparent); }
    .cal-cell.sel { background: var(--surface-2); }
    .cal-cell .num { align-self: flex-end; font-size: 15px; font-weight: 700; min-width: 30px; height: 30px; padding: 0 6px; border-radius: 999px; display: grid; place-items: center; font-variant-numeric: tabular-nums; }
    .cal-cell.out .num { color: var(--muted); opacity: 0.6; }
    .cal-cell.we:not(.out) .num { color: var(--muted); }
    .cal-cell.today .num { background: var(--cal-red); color: #fff; opacity: 1; }
    .cal-pill {
        display: flex; align-items: center; gap: 5px; min-width: 0; padding: 2px 7px; border-radius: 7px;
        font-size: 12px; font-weight: 600; line-height: 1.45;
        background: color-mix(in srgb, var(--c) 16%, transparent); color: color-mix(in srgb, var(--c) 78%, var(--ink));
    }
    .cal-pill span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cal-pill svg { flex: none; width: 11px; height: 11px; }
    .cal-more { font-size: 11px; font-weight: 700; color: var(--muted); padding-left: 7px; }
    .cal-dots { display: none; gap: 3px; justify-content: center; }
    .cal-dots i { width: 5px; height: 5px; border-radius: 999px; background: var(--c); }

    .cal-day { border-left: 1px solid var(--line); padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
    .cal-day h3 { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; }
    .cal-day h3 small { display: block; font-size: 13px; font-weight: 700; color: var(--muted); letter-spacing: 0; margin-bottom: 2px; text-transform: uppercase; }
    .cal-day.swap { animation: hubFade 220ms ease-out; }
    .cal-section-label { font-size: 11px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
    .cal-item { border-radius: 14px; padding: 12px 12px 12px 14px; background: color-mix(in srgb, var(--c) 10%, var(--surface)); border-left: 4px solid var(--c); display: flex; flex-direction: column; gap: 4px; }
    .cal-item + .cal-item { margin-top: 8px; }
    .cal-item-title { font-weight: 700; font-size: 14px; overflow-wrap: anywhere; }
    .cal-item-meta { font-size: 12px; font-weight: 600; color: var(--muted); }
    .cal-item-note { font-size: 13px; color: var(--ink-2); white-space: pre-wrap; overflow-wrap: anywhere; }
    .cal-item-actions { display: flex; gap: 4px; margin-top: 4px; }
    .cal-item-actions .cal-btn { height: 30px; font-size: 12px; padding: 0 10px; }
    .cal-empty { font-size: 13px; color: var(--muted); }
    .cal-form { display: flex; flex-direction: column; gap: 10px; padding: 14px; border-radius: 18px; background: var(--surface-2); }
    .cal-form label { font-size: 12px; font-weight: 700; color: var(--muted); display: flex; flex-direction: column; gap: 4px; }
    .cal-form input, .cal-form textarea {
        font: inherit; font-size: 14px; color: var(--ink); background: var(--surface); border: 1px solid var(--line);
        border-radius: 10px; padding: 9px 11px; width: 100%; box-sizing: border-box;
    }
    .cal-form textarea { resize: vertical; min-height: 64px; }
    .cal-form input:focus, .cal-form textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 25%, transparent); }
    .cal-chips { display: flex; gap: 8px; }
    .cal-chip { width: 26px; height: 26px; border-radius: 999px; border: 0; cursor: pointer; background: var(--c); box-shadow: 0 0 0 2px var(--surface-2); }
    .cal-chip[aria-pressed="true"] { box-shadow: 0 0 0 2px var(--surface-2), 0 0 0 4px var(--c); }
    .cal-form-actions { display: flex; gap: 8px; justify-content: flex-end; }
    .cal-error { font-size: 13px; font-weight: 600; color: var(--cal-red); }
    .cal-viewonly { font-size: 12px; color: var(--muted); margin-top: auto; }

    :root { --cal-red: #ef4444; --cal-green: #16a34a; }
    html.dark { --cal-green: #4ade80; }
    .c-holiday { --c: #16a34a; } html.dark .c-holiday { --c: #4ade80; }
    .c-blue { --c: #2563eb; } html.dark .c-blue { --c: #60a5fa; }
    .c-purple { --c: #7c3aed; } html.dark .c-purple { --c: #a78bfa; }
    .c-amber { --c: #d97706; } html.dark .c-amber { --c: #fbbf24; }
    .c-rose { --c: #e11d48; } html.dark .c-rose { --c: #fb7185; }
    .c-teal { --c: #0d9488; } html.dark .c-teal { --c: #2dd4bf; }
    .c-slate { --c: #64748b; } html.dark .c-slate { --c: #94a3b8; }

    @media (max-width: 860px) {
        .cal-body { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto auto; overflow-y: auto; }
        .cal-month { grid-template-rows: auto auto; }
        .cal-grid { grid-template-rows: repeat(6, 64px); }
        .cal-day { border-left: 0; border-top: 1px solid var(--line); overflow: visible; }
        .cal-cell .cal-pill, .cal-cell .cal-more { display: none; }
        .cal-cell .cal-dots { display: flex; }
        .cal-cell .num { align-self: center; font-size: 14px; }
        .cal-wd span { text-align: center; padding: 8px 0; }
    }
    @media (max-width: 640px) {
        .cal-overlay { padding: 0; }
        .cal-modal { width: 100%; height: 100dvh; border-radius: 0; }
        .cal-head { padding: 16px; }
    }

    /* Big monitors (2026-11-18): wider page and slightly larger type so the
       Hub doesn't sit small in the middle. Laptops (<1800px) are unchanged. */
    @media (min-width: 1800px) {
        .wrap { max-width: 1600px; }
        .bento { gap: 20px; grid-auto-rows: minmax(290px, auto); }
        .intro { padding: 52px 8px 32px; }
        .intro h1 { font-size: 66px; }
        .intro > p { flex-basis: 460px; font-size: 19px; }
        .greeting { font-size: 17px; }
        .tile { padding: 32px; }
        .tile h3 { font-size: 27px; }
        .tile p { font-size: 15.5px; }
        .tile-icon { width: 58px; height: 58px; }
        .hero h2 { font-size: 62px; }
        .hero p { font-size: 17px; max-width: 520px; }
        .partners-head h2 { font-size: 24px; }
        .partner-name { font-size: 16px; }
    }
    @media (min-width: 2300px) {
        .wrap { max-width: 1880px; }
        .bento { gap: 24px; grid-auto-rows: minmax(330px, auto); }
        .intro h1 { font-size: 78px; }
        .intro > p { flex-basis: 520px; font-size: 21px; }
        .greeting { font-size: 19px; }
        .tile { padding: 38px; }
        .tile h3 { font-size: 31px; }
        .tile p { font-size: 17px; }
        .hero h2 { font-size: 72px; }
        .hero p { font-size: 19px; max-width: 600px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .cal-modal, .cal-overlay { transition: none !important; transform: none !important; }
        .cal-grid, .cal-day { animation: none !important; }
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

    <div class="hub-split">
    <div class="bento">
        @if(auth()->user()->canAccessModule('leads'))
        <a class="tile hero" href="{{ route('dashboard') }}">
            <svg class="bars" viewBox="0 0 120 96" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><rect x="6" y="56" width="20" height="34" rx="5"/><rect x="36" y="36" width="20" height="54" rx="5"/><rect x="66" y="46" width="20" height="44" rx="5"/><rect x="96" y="10" width="20" height="80" rx="5"/></svg>
            <div class="hero-text">
                <span class="hero-label">Reporting</span>
                <h2>TSD Leads Reports</h2>
                <p>Pancake POS sales reporting — leads, TSA performance, upsell tracking, and analytics.</p>
            </div>
            <span class="arrow">{!! $arrowSvg !!}</span>
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

        {{-- Calendar (explicit request 2026-11-17; inline full month
             2026-11-18) — drawn by the script below from $calendar. --}}
        <section class="cal-panel" id="calPanel" aria-labelledby="calPanelTitle">
            <div class="cal-panel-head">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4"/></svg>
                <h2 id="calPanelTitle"></h2>
                <button type="button" class="cal-btn" id="calPanelToday">Today</button>
                <button type="button" class="cal-btn icon" id="calPanelPrev" aria-label="Previous month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg></button>
                <button type="button" class="cal-btn icon" id="calPanelNext" aria-label="Next month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg></button>
            </div>
            <div class="cal-wd" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
            <div class="cal-grid" id="calPanelGrid" role="grid" aria-labelledby="calPanelTitle"></div>
            <div class="cal-legend">
                <span><i class="c-holiday"></i>Holiday</span>
                <span><i class="c-blue"></i>Agenda</span>
                <span>Click a day to see details{{ ($calendar['canEdit'] ?? false) ? ' or add an agenda' : '' }}</span>
            </div>
        </section>
    </div>
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
<div class="cal-overlay" id="calOverlay" hidden>
    <div class="cal-modal" id="calModal" role="dialog" aria-modal="true" aria-labelledby="calTitle">
        <div class="cal-head">
            <h2 id="calTitle"></h2>
            <div class="cal-nav">
                <button type="button" class="cal-btn icon" id="calPrev" aria-label="Previous month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg></button>
                <button type="button" class="cal-btn" id="calTodayBtn">Today</button>
                <button type="button" class="cal-btn icon" id="calNext" aria-label="Next month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg></button>
                <button type="button" class="cal-btn icon" id="calClose" aria-label="Close calendar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
        </div>
        <div class="cal-body">
            <div class="cal-month">
                <div class="cal-wd" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
                <div class="cal-grid" id="calGrid" role="grid" aria-labelledby="calTitle"></div>
            </div>
            <aside class="cal-day" id="calDay" aria-live="polite"></aside>
        </div>
    </div>
</div>
<script id="calData" type="application/json">{!! json_encode(array_merge($calendar, [
    'storeUrl' => route('hub.agendas.store'),
    'baseUrl'  => url('/hub/agendas'),
]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script>
(function () {
    var data = JSON.parse(document.getElementById('calData').textContent);
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var reduceMotion = false;
    try { reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function key(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parse(k) { var p = k.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function fmtTime(t) { if (!t) return ''; var h = +t.slice(0, 2), m = t.slice(3); return ((h % 12) || 12) + ':' + m + (h < 12 ? ' AM' : ' PM'); }
    var STAR = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.9 6.9L22 9.3l-5.4 4.8 1.6 7.4L12 17.8 5.8 21.5l1.6-7.4L2 9.3l7.1-.4z"/></svg>';

    var today = parse(data.today);
    var view = new Date(today.getFullYear(), today.getMonth(), 1);
    var selected = data.today;
    var editingId = null;

    function agendasOn(k) {
        return data.agendas.filter(function (a) { return a.date === k; }).sort(function (a, b) {
            if (!a.time !== !b.time) return a.time ? 1 : -1;
            return (a.time || '').localeCompare(b.time || '') || a.id - b.id;
        });
    }
    function holidaysOn(k) { return data.holidays[k] || []; }

    /* ---------- Inline month (the Hub's own calendar panel) ---------- */
    var panelView = new Date(today.getFullYear(), today.getMonth(), 1);
    function renderPanel(direction) {
        var title = document.getElementById('calPanelTitle');
        title.replaceChildren(document.createTextNode(MONTHS[panelView.getMonth()] + ' '), el('span', null, panelView.getFullYear()));
        var grid = document.getElementById('calPanelGrid');
        fillGrid(grid, panelView, null);
        // One tab stop for the whole grid (today, else the 1st); arrows move.
        var stop = grid.querySelector('.cal-cell.today:not(.out)') || grid.querySelector('.cal-cell:not(.out)');
        if (stop) stop.tabIndex = 0;
        animate(grid, direction);
    }

    /* ---------- Month grid ---------- */
    function animate(grid, direction) {
        if (direction && !reduceMotion) {
            grid.classList.remove('slide-next', 'slide-prev');
            void grid.offsetWidth;
            grid.classList.add(direction > 0 ? 'slide-next' : 'slide-prev');
        }
    }
    function renderMonth(direction) {
        var title = document.getElementById('calTitle');
        title.replaceChildren(document.createTextNode(MONTHS[view.getMonth()] + ' '), el('span', null, view.getFullYear()));
        var grid = document.getElementById('calGrid');
        fillGrid(grid, view, selected);
        animate(grid, direction);
    }
    function fillGrid(grid, view, selected) {
        grid.replaceChildren();
        var start = new Date(view.getFullYear(), view.getMonth(), 1 - view.getDay());
        for (var i = 0; i < 42; i++) {
            var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i), k = key(d);
            var cell = el('button', 'cal-cell');
            cell.type = 'button';
            cell.dataset.date = k;
            cell.setAttribute('role', 'gridcell');
            if (d.getMonth() !== view.getMonth()) cell.classList.add('out');
            if (d.getDay() === 0 || d.getDay() === 6) cell.classList.add('we');
            if (k === data.today) cell.classList.add('today');
            if (k === selected) { cell.classList.add('sel'); cell.setAttribute('aria-selected', 'true'); }
            cell.tabIndex = k === selected ? 0 : -1;

            var hols = holidaysOn(k), ags = agendasOn(k);
            var label = DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate();
            if (hols.length) label += ', ' + hols.map(function (h) { return h.name; }).join(', ');
            if (ags.length) label += ', ' + ags.length + (ags.length === 1 ? ' agenda' : ' agendas');
            cell.setAttribute('aria-label', label);

            cell.appendChild(el('span', 'num', d.getDate()));
            var items = hols.map(function (h) { return { title: h.name, color: 'holiday', star: true }; })
                .concat(ags.map(function (a) { return { title: (a.time ? fmtTime(a.time) + ' ' : '') + a.title, color: a.color }; }));
            items.slice(0, 2).forEach(function (it) {
                var pill = el('span', 'cal-pill c-' + it.color);
                if (it.star) pill.insertAdjacentHTML('beforeend', STAR);
                pill.appendChild(el('span', null, it.title));
                cell.appendChild(pill);
            });
            if (items.length > 2) cell.appendChild(el('span', 'cal-more', '+' + (items.length - 2) + ' more'));
            if (items.length) {
                var dots = el('span', 'cal-dots');
                items.slice(0, 3).forEach(function (it) { dots.appendChild(el('i', 'c-' + it.color)); });
                cell.appendChild(dots);
            }
            grid.appendChild(cell);
        }
    }

    /* ---------- Day panel ---------- */
    function renderDay(animate) {
        var panel = document.getElementById('calDay');
        var d = parse(selected);
        panel.replaceChildren();
        var h = el('h3');
        h.appendChild(el('small', null, DAYS[d.getDay()]));
        h.appendChild(document.createTextNode(MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear()));
        panel.appendChild(h);

        var hols = holidaysOn(selected);
        if (hols.length) {
            var hs = el('div');
            hs.appendChild(el('div', 'cal-section-label', 'Holiday'));
            hols.forEach(function (x) {
                var item = el('div', 'cal-item c-holiday');
                item.appendChild(el('span', 'cal-item-title', x.name));
                item.appendChild(el('span', 'cal-item-meta', x.type === 'regular' ? 'Regular holiday' : 'Special non-working day'));
                hs.appendChild(item);
            });
            panel.appendChild(hs);
        }

        var ags = agendasOn(selected);
        var as = el('div');
        as.appendChild(el('div', 'cal-section-label', 'Agenda'));
        if (!ags.length) as.appendChild(el('p', 'cal-empty', 'No agenda for this day.'));
        ags.forEach(function (a) {
            if (a.id === editingId) { as.appendChild(form(a)); return; }
            var item = el('div', 'cal-item c-' + a.color);
            item.appendChild(el('span', 'cal-item-title', a.title));
            item.appendChild(el('span', 'cal-item-meta', a.time ? fmtTime(a.time) : 'All day'));
            if (a.note) item.appendChild(el('span', 'cal-item-note', a.note));
            if (data.canEdit) {
                var actions = el('div', 'cal-item-actions');
                var edit = el('button', 'cal-btn', 'Edit');
                edit.type = 'button';
                edit.addEventListener('click', function () { editingId = a.id; renderDay(); focusFirstField(); });
                var del = el('button', 'cal-btn danger', 'Delete');
                del.type = 'button';
                del.addEventListener('click', function () { askDelete(a, item, actions); });
                actions.append(edit, del);
                item.appendChild(actions);
            }
            as.appendChild(item);
        });
        panel.appendChild(as);

        if (data.canEdit && editingId === null) panel.appendChild(form(null));
        if (!data.canEdit) panel.appendChild(el('p', 'cal-viewonly', 'Holidays and agendas are set by admins.'));

        if (animate && !reduceMotion) { panel.classList.remove('swap'); void panel.offsetWidth; panel.classList.add('swap'); }
    }

    function form(agenda) {
        var f = el('form', 'cal-form');
        f.noValidate = true;
        f.appendChild(el('div', 'cal-section-label', agenda ? 'Edit agenda' : 'Add agenda'));
        var title = el('input'); title.name = 'title'; title.maxLength = 120; title.required = true; title.placeholder = 'e.g. Team huddle';
        var time = el('input'); time.name = 'time'; time.type = 'time';
        var note = el('textarea'); note.name = 'note'; note.maxLength = 1000; note.placeholder = 'Details (optional)';
        if (agenda) { title.value = agenda.title; time.value = agenda.time || ''; note.value = agenda.note || ''; }
        var l1 = el('label', null, 'Title'); l1.appendChild(title);
        var l2 = el('label', null, 'Time (optional)'); l2.appendChild(time);
        var l3 = el('label', null, 'Note'); l3.appendChild(note);
        var color = agenda ? agenda.color : data.colors[0];
        var chips = el('div', 'cal-chips');
        chips.setAttribute('role', 'group');
        chips.setAttribute('aria-label', 'Color');
        data.colors.forEach(function (c) {
            var chip = el('button', 'cal-chip c-' + c);
            chip.type = 'button';
            chip.setAttribute('aria-label', c);
            chip.setAttribute('aria-pressed', c === color ? 'true' : 'false');
            chip.addEventListener('click', function () {
                color = c;
                chips.querySelectorAll('.cal-chip').forEach(function (x) { x.setAttribute('aria-pressed', x === chip ? 'true' : 'false'); });
            });
            chips.appendChild(chip);
        });
        var error = el('p', 'cal-error'); error.hidden = true;
        var actions = el('div', 'cal-form-actions');
        if (agenda) {
            var cancel = el('button', 'cal-btn', 'Cancel'); cancel.type = 'button';
            cancel.addEventListener('click', function () { editingId = null; renderDay(); });
            actions.appendChild(cancel);
        }
        var save = el('button', 'cal-btn primary', agenda ? 'Save' : 'Add agenda'); save.type = 'submit';
        actions.appendChild(save);
        f.append(l1, l2, chips, l3, error, actions);

        f.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!title.value.trim()) { error.textContent = 'Give the agenda a title.'; error.hidden = false; title.focus(); return; }
            save.disabled = true;
            request(agenda ? 'PUT' : 'POST', agenda ? data.baseUrl + '/' + agenda.id : data.storeUrl,
                { date: selected, title: title.value.trim(), time: time.value || null, note: note.value.trim() || null, color: color })
                .then(function (res) {
                    if (agenda) data.agendas = data.agendas.map(function (a) { return a.id === agenda.id ? res.agenda : a; });
                    else data.agendas.push(res.agenda);
                    editingId = null;
                    refresh();
                })
                .catch(function (msg) { error.textContent = msg; error.hidden = false; save.disabled = false; });
        });
        return f;
    }

    // In-card confirmation instead of the browser's confirm(), which always
    // shows the site address ("localhost:8000 says") and can't be styled.
    function askDelete(agenda, item, actions) {
        var box = el('div', 'cal-confirm');
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-label', 'Delete agenda');
        box.appendChild(el('span', 'cal-confirm-text', 'Delete this agenda?'));
        var error = el('p', 'cal-error'); error.hidden = true;
        var row = el('div', 'cal-item-actions');
        var cancel = el('button', 'cal-btn', 'Cancel'); cancel.type = 'button';
        var del = el('button', 'cal-btn danger-solid', 'Delete'); del.type = 'button';
        row.append(cancel, del);
        box.append(row, error);
        actions.replaceWith(box);
        cancel.focus();
        cancel.addEventListener('click', function () { box.replaceWith(actions); actions.querySelector('.danger').focus(); });
        box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancel.click(); } });
        del.addEventListener('click', function () {
            del.disabled = cancel.disabled = true;
            request('DELETE', data.baseUrl + '/' + agenda.id).then(function () {
                data.agendas = data.agendas.filter(function (a) { return a.id !== agenda.id; });
                refresh();
            }).catch(function (msg) { error.textContent = msg; error.hidden = false; del.disabled = cancel.disabled = false; });
        });
    }

    function request(method, url, body) {
        return fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (json) {
                if (r.ok) return json;
                var first = json.errors && Object.values(json.errors)[0];
                throw (first && first[0]) || json.message || 'Could not save. Please try again.';
            });
        }, function () { throw 'Could not reach the server. Check your connection.'; });
    }

    function refresh() { renderMonth(); renderDay(); renderPanel(); }
    function focusFirstField() { var f = document.querySelector('#calDay .cal-form input'); if (f) f.focus(); }

    /* ---------- Open / close ---------- */
    var overlay = document.getElementById('calOverlay');
    var modal = document.getElementById('calModal');
    var opener = null;
    var closeTimer = null;

    function open(k, from) {
        clearTimeout(closeTimer);
        opener = from;
        var d = parse(k);
        view = new Date(d.getFullYear(), d.getMonth(), 1);
        selected = k;
        editingId = null;
        renderMonth();
        renderDay();
        overlay.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        // Grow out of the clicked day: the pop-up scales from its center.
        var t = from.getBoundingClientRect(), m = modal.getBoundingClientRect();
        modal.style.setProperty('--ox', (t.left + t.width / 2 - m.left) + 'px');
        modal.style.setProperty('--oy', (t.top + t.height / 2 - m.top) + 'px');
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { overlay.classList.add('is-open'); });
        });
        var cell = document.querySelector('#calGrid .cal-cell[tabindex="0"]');
        if (cell) cell.focus({ preventScroll: true });
    }
    function close() {
        overlay.classList.remove('is-open');
        document.documentElement.style.overflow = '';
        closeTimer = setTimeout(function () { overlay.hidden = true; }, reduceMotion ? 0 : 340);
        if (opener) opener.focus({ preventScroll: true });
    }
    function go(delta) {
        view = new Date(view.getFullYear(), view.getMonth() + delta, 1);
        renderMonth(delta);
    }
    function select(k, focus) {
        var d = parse(k);
        if (d.getMonth() !== view.getMonth() || d.getFullYear() !== view.getFullYear()) {
            var delta = d < view ? -1 : 1;
            view = new Date(d.getFullYear(), d.getMonth(), 1);
            selected = k; editingId = null;
            renderMonth(delta);
        } else {
            selected = k; editingId = null;
            document.querySelectorAll('#calGrid .cal-cell').forEach(function (c) {
                var on = c.dataset.date === k;
                c.classList.toggle('sel', on);
                c.tabIndex = on ? 0 : -1;
                if (on) c.setAttribute('aria-selected', 'true'); else c.removeAttribute('aria-selected');
            });
        }
        renderDay(true);
        if (focus) { var c = document.querySelector('#calGrid .cal-cell[data-date="' + k + '"]'); if (c) c.focus(); }
    }

    var panelGrid = document.getElementById('calPanelGrid');
    panelGrid.addEventListener('click', function (e) {
        var cell = e.target.closest('.cal-cell');
        if (cell) open(cell.dataset.date, cell);
    });
    panelGrid.addEventListener('keydown', function (e) {
        var moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        if (!(e.key in moves)) return;
        var cells = Array.prototype.slice.call(panelGrid.querySelectorAll('.cal-cell'));
        var next = cells[cells.indexOf(document.activeElement) + moves[e.key]];
        if (!next) return;
        e.preventDefault();
        cells.forEach(function (c) { c.tabIndex = c === next ? 0 : -1; });
        next.focus();
    });
    function panelGo(delta) {
        panelView = new Date(panelView.getFullYear(), panelView.getMonth() + delta, 1);
        renderPanel(delta);
    }
    document.getElementById('calPanelPrev').addEventListener('click', function () { panelGo(-1); });
    document.getElementById('calPanelNext').addEventListener('click', function () { panelGo(1); });
    document.getElementById('calPanelToday').addEventListener('click', function () {
        var delta = panelView < new Date(today.getFullYear(), today.getMonth(), 1) ? 1 : -1;
        if (panelView.getFullYear() === today.getFullYear() && panelView.getMonth() === today.getMonth()) return;
        panelView = new Date(today.getFullYear(), today.getMonth(), 1);
        renderPanel(delta);
    });
    document.getElementById('calClose').addEventListener('click', close);
    document.getElementById('calPrev').addEventListener('click', function () { go(-1); });
    document.getElementById('calNext').addEventListener('click', function () { go(1); });
    document.getElementById('calTodayBtn').addEventListener('click', function () { select(data.today, true); });
    overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
    document.getElementById('calGrid').addEventListener('click', function (e) {
        var cell = e.target.closest('.cal-cell');
        if (cell) select(cell.dataset.date);
    });
    document.getElementById('calGrid').addEventListener('keydown', function (e) {
        var moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        if (!(e.key in moves)) return;
        e.preventDefault();
        var d = parse(selected);
        select(key(new Date(d.getFullYear(), d.getMonth(), d.getDate() + moves[e.key])), true);
    });
    overlay.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.key !== 'Tab') return;
        var f = Array.prototype.filter.call(modal.querySelectorAll('button, input, textarea, [tabindex="0"]'), function (x) { return !x.disabled && x.offsetParent !== null; });
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    });

    renderPanel();
})();
</script>
</body>
</html>
