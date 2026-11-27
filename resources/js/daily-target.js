// Daily Target bubble (2026-11-27) — a floating progress bubble for a
// signed-in TSA on every module (TSD Reports, Call Tracker, Archive, Data
// Management, Hub). Its own Vite entry on purpose: app.js and calls.js are
// separate bundles, and a feature in only one is invisible on the other's
// pages. Self-contained (own CSS below) so it never depends on which
// Tailwind build a page loaded. Figures: App\Support\TsaDailyTarget.
//
// Light on pages: loads after the page (module script), paints at once
// from the last figure in sessionStorage, then one small request
// (cached 30s server-side) and a 60s poll only while the tab is visible.

const root = document.getElementById('dailyTargetRoot');

if (root && !window.__dailyTargetBubble) {
    window.__dailyTargetBubble = true;

    const ENDPOINT = root.dataset.endpoint;
    const CACHE_KEY = `dailyTarget.v1.${root.dataset.tsa}`;
    const POS_KEY = 'dailyTarget.pos.v1';
    const R = 22;
    const CIRC = 2 * Math.PI * R;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const peso = (n) => '₱' + Math.round(Number(n || 0)).toLocaleString('en-PH');

    document.head.insertAdjacentHTML('beforeend', `<style id="dt-style">
#dt{--dt-bg:rgba(255,255,255,.82);--dt-fg:#0f172a;--dt-muted:#64748b;--dt-line:rgba(15,23,42,.08);--dt-track:#e2e8f0;
    --dt-a1:#f59e0b;--dt-a2:#f97316;--dt-g1:#10b981;--dt-g2:#14b8a6;
    position:fixed;z-index:65;top:var(--dt-y,62vh);font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
    font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased;touch-action:none}
html.dark #dt{--dt-bg:rgba(15,23,42,.82);--dt-fg:#f1f5f9;--dt-muted:#94a3b8;--dt-line:rgba(255,255,255,.08);--dt-track:#334155}
#dt[data-side=right]{right:16px}#dt[data-side=left]{left:16px}
#dt.dt-dragging{transition:none!important;cursor:grabbing}
#dt-btn{all:unset;box-sizing:border-box;position:relative;width:60px;height:60px;border-radius:50%;display:grid;place-items:center;cursor:pointer;
    background:var(--dt-bg);backdrop-filter:blur(14px) saturate(1.6);-webkit-backdrop-filter:blur(14px) saturate(1.6);
    box-shadow:0 0 0 1px var(--dt-line),0 10px 30px -8px rgba(15,23,42,.35),0 2px 6px rgba(15,23,42,.08);
    transition:transform .25s cubic-bezier(.34,1.56,.64,1),box-shadow .25s}
#dt-btn:hover{transform:scale(1.06)}#dt-btn:active{transform:scale(.96)}
#dt-btn:focus-visible{box-shadow:0 0 0 3px rgba(16,185,129,.55),0 10px 30px -8px rgba(15,23,42,.35)}
#dt-btn svg{position:absolute;inset:0;transform:rotate(-90deg)}
#dt-ring{transition:stroke-dashoffset .9s cubic-bezier(.22,1,.36,1)}
#dt-pct{font-size:13px;font-weight:700;color:var(--dt-fg);letter-spacing:-.02em;line-height:1}
#dt-pct small{font-size:9px;font-weight:600;color:var(--dt-muted);margin-left:1px}
#dt[data-hit] #dt-btn{box-shadow:0 0 0 1px rgba(16,185,129,.35),0 10px 30px -8px rgba(16,185,129,.55)}
#dt-dot{position:absolute;top:3px;right:3px;width:10px;height:10px;border-radius:50%;background:var(--dt-a1);box-shadow:0 0 0 2px var(--dt-bg);display:none}
#dt[data-pending] #dt-dot{display:block;animation:dt-blink 1.6s ease-in-out infinite}
@keyframes dt-blink{50%{opacity:.35}}
#dt-card{position:absolute;top:50%;width:272px;padding:16px 16px 14px;border-radius:20px;color:var(--dt-fg);
    background:var(--dt-bg);backdrop-filter:blur(18px) saturate(1.6);-webkit-backdrop-filter:blur(18px) saturate(1.6);
    box-shadow:0 0 0 1px var(--dt-line),0 24px 48px -12px rgba(15,23,42,.35);
    opacity:0;pointer-events:none;transform:translateY(-50%) scale(.92);transition:opacity .18s ease,transform .28s cubic-bezier(.34,1.56,.64,1)}
#dt[data-side=right] #dt-card{right:72px;transform-origin:right center}
#dt[data-side=left] #dt-card{left:72px;transform-origin:left center}
#dt[data-open] #dt-card{opacity:1;pointer-events:auto;transform:translateY(-50%) scale(1)}
.dt-eyebrow{display:flex;justify-content:space-between;align-items:center;font-size:10.5px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--dt-muted)}
.dt-chip{font-size:10.5px;letter-spacing:0;text-transform:none;padding:2px 8px;border-radius:999px;background:rgba(245,158,11,.14);color:#b45309;font-weight:600}
html.dark .dt-chip{color:#fbbf24}
#dt[data-hit] .dt-chip{background:rgba(16,185,129,.14);color:#047857}
html.dark #dt[data-hit] .dt-chip{color:#34d399}
.dt-big{margin-top:8px;font-size:30px;font-weight:750;letter-spacing:-.035em;line-height:1}
.dt-big span{font-size:13px;font-weight:500;color:var(--dt-muted);letter-spacing:0;margin-left:6px}
.dt-bar{position:relative;margin-top:14px;height:8px;border-radius:999px;background:var(--dt-track);overflow:hidden;display:flex}
.dt-bar i{display:block;height:100%;transition:width .9s cubic-bezier(.22,1,.36,1)}
#dt-bc{background:linear-gradient(90deg,var(--dt-a1),var(--dt-a2))}
#dt[data-hit] #dt-bc{background:linear-gradient(90deg,var(--dt-g1),var(--dt-g2))}
#dt-bp{background:repeating-linear-gradient(45deg,rgba(245,158,11,.55) 0 4px,rgba(245,158,11,.25) 4px 8px)}
.dt-legend{margin-top:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.dt-legend div{font-size:10.5px;color:var(--dt-muted);line-height:1.3}
.dt-legend b{display:block;font-size:13px;font-weight:650;color:var(--dt-fg);letter-spacing:-.01em}
.dt-legend em{font-style:normal;display:inline-block;width:7px;height:7px;border-radius:2px;margin-right:4px;vertical-align:1px}
.dt-foot{margin-top:12px;padding-top:10px;border-top:1px solid var(--dt-line);font-size:10.5px;color:var(--dt-muted);display:flex;justify-content:space-between}
.dt-float{position:absolute;left:50%;top:-6px;transform:translateX(-50%);padding:3px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap;
    color:#fff;background:linear-gradient(135deg,var(--dt-g1),var(--dt-g2));box-shadow:0 6px 16px -4px rgba(16,185,129,.6);pointer-events:none;animation:dt-float 1.6s cubic-bezier(.22,1,.36,1) forwards}
@keyframes dt-float{0%{opacity:0;transform:translate(-50%,8px) scale(.8)}15%{opacity:1;transform:translate(-50%,0) scale(1)}75%{opacity:1}100%{opacity:0;transform:translate(-50%,-34px)}}
#dt-btn.dt-pop{animation:dt-pop .6s cubic-bezier(.34,1.56,.64,1)}
@keyframes dt-pop{40%{transform:scale(1.16)}}
.dt-spark{position:absolute;left:50%;top:50%;width:7px;height:7px;border-radius:2px;pointer-events:none;animation:dt-spark .9s cubic-bezier(.22,1,.36,1) forwards}
@keyframes dt-spark{0%{opacity:1;transform:translate(-50%,-50%)}100%{opacity:0;transform:translate(calc(-50% + var(--x)),calc(-50% + var(--y))) rotate(220deg)}}
@media (prefers-reduced-motion:reduce){#dt *,#dt{animation:none!important;transition:none!important}}
@media print{#dt{display:none}}
</style>`);

    const el = document.createElement('div');
    el.id = 'dt';
    el.hidden = true;
    el.innerHTML = `
        <div id="dt-card" role="dialog" aria-label="Today's target">
            <div class="dt-eyebrow">Today's target <span class="dt-chip" id="dt-chip"></span></div>
            <div class="dt-big" id="dt-big"></div>
            <div class="dt-bar" aria-hidden="true"><i id="dt-bc"></i><i id="dt-bp"></i></div>
            <div class="dt-legend">
                <div><em style="background:linear-gradient(90deg,#f59e0b,#f97316)"></em>Confirmed<b id="dt-lc"></b></div>
                <div><em style="background:repeating-linear-gradient(45deg,rgba(245,158,11,.7) 0 2px,rgba(245,158,11,.3) 2px 4px)"></em>Pending<b id="dt-lp"></b></div>
                <div>Target<b id="dt-lt"></b></div>
            </div>
            <div class="dt-foot"><span>Pending = not yet synced from Pancake</span><span id="dt-upd"></span></div>
        </div>
        <button id="dt-btn" type="button" aria-expanded="false" aria-controls="dt-card">
            <svg width="60" height="60" viewBox="0 0 60 60" aria-hidden="true">
                <defs>
                    <linearGradient id="dt-ga" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f59e0b"/><stop offset="1" stop-color="#f97316"/></linearGradient>
                    <linearGradient id="dt-gg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#10b981"/><stop offset="1" stop-color="#14b8a6"/></linearGradient>
                </defs>
                <circle cx="30" cy="30" r="${R}" fill="none" stroke="var(--dt-track)" stroke-width="4.5"/>
                <circle id="dt-ring" cx="30" cy="30" r="${R}" fill="none" stroke="url(#dt-ga)" stroke-width="4.5" stroke-linecap="round"
                        stroke-dasharray="${CIRC}" stroke-dashoffset="${CIRC}"/>
            </svg>
            <span id="dt-pct"></span>
            <span id="dt-dot"></span>
        </button>`;
    document.body.appendChild(el);

    const $ = (id) => document.getElementById(id);
    const btn = $('dt-btn');
    let state = null;
    let updatedAt = null;

    // ── Position: side + vertical %, draggable, remembered ─────────────────
    function applyPos(pos) {
        el.dataset.side = pos.side === 'left' ? 'left' : 'right';
        const y = Math.min(Math.max(pos.y ?? 62, 8), 88);
        el.style.setProperty('--dt-y', `calc(${y}vh - 30px)`);
    }
    let pos = { side: 'right', y: 62 };
    try { pos = { ...pos, ...JSON.parse(localStorage.getItem(POS_KEY) || '{}') }; } catch (e) { /* default */ }
    applyPos(pos);

    let drag = null;
    btn.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        drag = { x: e.clientX, y: e.clientY, moved: false };
        btn.setPointerCapture(e.pointerId);
    });
    btn.addEventListener('pointermove', (e) => {
        if (!drag) return;
        if (!drag.moved && Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 5) return;
        drag.moved = true;
        el.classList.add('dt-dragging');
        close();
        el.dataset.side = e.clientX < window.innerWidth / 2 ? 'left' : 'right';
        el.style.setProperty('--dt-y', `${Math.min(Math.max(e.clientY - 30, 8), window.innerHeight - 68)}px`);
    });
    btn.addEventListener('pointerup', (e) => {
        if (!drag) return;
        const moved = drag.moved;
        drag = null;
        el.classList.remove('dt-dragging');
        if (moved) {
            pos = { side: el.dataset.side, y: Math.round((e.clientY / window.innerHeight) * 100) };
            applyPos(pos);
            localStorage.setItem(POS_KEY, JSON.stringify(pos));
            btn.dataset.justDragged = '1';
            setTimeout(() => delete btn.dataset.justDragged, 0);
        }
    });

    // ── Open / close ───────────────────────────────────────────────────────
    function open() { el.dataset.open = ''; btn.setAttribute('aria-expanded', 'true'); tickUpdated(); }
    function close() { delete el.dataset.open; btn.setAttribute('aria-expanded', 'false'); }
    btn.addEventListener('click', () => {
        if (btn.dataset.justDragged) return;
        'open' in el.dataset ? close() : open();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    document.addEventListener('pointerdown', (e) => { if (!el.contains(e.target)) close(); });

    // ── Render ─────────────────────────────────────────────────────────────
    function render(t) {
        state = t;
        if (!t) { el.hidden = true; return; }
        el.hidden = false;
        const pct = Math.min(100, t.target > 0 ? (t.achieved / t.target) * 100 : 0);
        const confPct = Math.min(100, t.target > 0 ? (t.confirmed / t.target) * 100 : 0);
        const pendPct = Math.max(0, pct - confPct);

        t.hit ? (el.dataset.hit = '') : delete el.dataset.hit;
        t.pending > 0 ? (el.dataset.pending = '') : delete el.dataset.pending;

        $('dt-ring').style.strokeDashoffset = String(CIRC * (1 - pct / 100));
        $('dt-ring').setAttribute('stroke', t.hit ? 'url(#dt-gg)' : 'url(#dt-ga)');
        $('dt-pct').innerHTML = t.hit ? '🎯' : `${Math.floor(pct)}<small>%</small>`;
        $('dt-chip').textContent = t.hit ? (t.over > 0 ? 'Above target' : 'Target hit') : `${Math.floor(pct)}% there`;
        $('dt-big').innerHTML = t.hit
            ? (t.over > 0 ? `+${peso(t.over)}<span>over target</span>` : `${peso(t.target)}<span>reached 🎉</span>`)
            : `${peso(t.remaining)}<span>left to go</span>`;
        $('dt-bc').style.width = `${confPct}%`;
        $('dt-bp').style.width = `${pendPct}%`;
        $('dt-lc').textContent = peso(t.confirmed);
        $('dt-lp').textContent = peso(t.pending);
        $('dt-lt').textContent = peso(t.target);
        btn.setAttribute('aria-label', t.hit
            ? `Today's target hit. ${peso(t.achieved)} of ${peso(t.target)}.`
            : `Today's target: ${peso(t.remaining)} left, ${Math.floor(pct)}% done.`);
        updatedAt = Date.now();
        tickUpdated();
        try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ t, d: new Date().toDateString() })); } catch (e) { /* full */ }
    }

    function tickUpdated() {
        if (!updatedAt) return;
        const s = Math.round((Date.now() - updatedAt) / 1000);
        $('dt-upd').textContent = s < 10 ? 'just now' : s < 60 ? `${s}s ago` : `${Math.round(s / 60)}m ago`;
    }

    function celebrate(added, crossed) {
        if (reduceMotion) return;
        btn.classList.remove('dt-pop'); void btn.offsetWidth; btn.classList.add('dt-pop');
        if (added) {
            const f = document.createElement('span');
            f.className = 'dt-float';
            f.textContent = `+${peso(added)}`;
            btn.appendChild(f);
            setTimeout(() => f.remove(), 1700);
        }
        if (crossed) {
            const colors = ['#10b981', '#14b8a6', '#f59e0b', '#f97316', '#6366f1', '#ec4899'];
            for (let i = 0; i < 16; i++) {
                const s = document.createElement('span');
                const a = (Math.PI * 2 * i) / 16;
                const d = 46 + Math.random() * 26;
                s.className = 'dt-spark';
                s.style.background = colors[i % colors.length];
                s.style.setProperty('--x', `${Math.cos(a) * d}px`);
                s.style.setProperty('--y', `${Math.sin(a) * d}px`);
                btn.appendChild(s);
                setTimeout(() => s.remove(), 1000);
            }
        }
    }

    // ── Data ───────────────────────────────────────────────────────────────
    try {
        const cached = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
        if (cached && cached.d === new Date().toDateString()) render(cached.t);
    } catch (e) { /* ignore */ }

    async function refresh() {
        try {
            const res = await fetch(ENDPOINT, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.ok) render((await res.json()).target);
        } catch (e) { /* offline — keep the last figure */ }
    }

    // Called by calls.js right after a successful upsell, with fresh figures.
    window.announceDailyTarget = function (t, added) {
        const wasHit = state?.hit === true;
        render(t);
        if (!t) return;
        const crossed = t.hit && !wasHit;
        celebrate(added, crossed);
        const plus = added ? `+${peso(added)} upsell! ` : '';
        if (crossed) {
            window.showToast?.(`🎯 ${plus}You hit today's target of ${peso(t.target)}!`, 'success');
        } else if (t.hit) {
            window.showToast?.(`${plus}${peso(t.over)} over today's target.`, 'success');
        } else {
            window.showToast?.(`${plus}${peso(t.remaining)} left to hit today's target.`, 'info');
        }
    };

    const start = () => refresh();
    'requestIdleCallback' in window ? requestIdleCallback(start, { timeout: 2000 }) : setTimeout(start, 300);
    setInterval(() => { if (!document.hidden) refresh(); tickUpdated(); }, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && updatedAt && Date.now() - updatedAt > 60000) refresh(); });
}
