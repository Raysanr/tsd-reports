import './bootstrap';

// ─── Dark mode toggle ────────────────────────────────────────────────────────
// The actual dark/light class is applied by an inline <head> script (before
// paint, to avoid a flash of the wrong theme) — this just wires up the button
// to flip it after load and persist the explicit choice. Once a user has
// toggled at all, that stored choice always wins over the OS preference on
// every future load (see the inline script in layouts/app.blade.php).
(function () {
    const toggle  = document.getElementById('themeToggle');
    const sunIcon = document.getElementById('themeIconSun');
    const moonIcon = document.getElementById('themeIconMoon');
    if (!toggle) return;

    function syncIcon() {
        const isDark = document.documentElement.classList.contains('dark');
        sunIcon?.classList.toggle('hidden', !isDark);
        moonIcon?.classList.toggle('hidden', isDark);
    }
    syncIcon();

    toggle.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        syncIcon();
    });
})();

// ─── Soft refresh ────────────────────────────────────────────────────────────
// Fetches a page and swaps only <main>'s content in place — no full navigation,
// so there's no white flash, no scroll loss, and header controls (date picker,
// sync button, product dropdown) keep their state and listeners.
//
// Script re-execution is opt-in via [data-rerun] (e.g. the Analytics chart
// inits, whose canvases live inside the swapped region): re-running the whole
// scripts stack would double-bind header controls that were never replaced.
// Re-run scripts are taken from the freshly fetched document, so any @json
// data baked into them is current, not stale.
window.softRefresh = async function (url = window.location.href, { pushUrl = false } = {}) {
    try {
        const res = await fetch(url, {
            headers: { 'X-Soft-Refresh': '1' },
            credentials: 'same-origin',
        });

        // A redirect to another page (e.g. session expired → /login) can't be
        // swapped in place — report failure so callers fall back to a real
        // navigation, which handles the redirect properly.
        if (!res.ok) return false;
        if (res.redirected && new URL(res.url).pathname !== new URL(url, window.location.href).pathname) return false;

        const html    = await res.text();
        const doc     = new DOMParser().parseFromString(html, 'text/html');
        const newMain = doc.querySelector('main');
        const main    = document.querySelector('main');
        if (!newMain || !main) return false;

        if (pushUrl && url !== window.location.href) history.pushState({}, '', url);
        document.title = doc.title;

        // The page heading + subtitle live in <header>, OUTSIDE the <main> swapped
        // below — without this they'd go stale (e.g. TSA Performance still titled
        // with yesterday's date after the picker changed it, making two pages showing
        // identical data look like they disagree).
        for (const sel of ['header h1', 'header h1 + p']) {
            const fresh = doc.querySelector(sel);
            const current = document.querySelector(sel);
            if (fresh && current) current.textContent = fresh.textContent;
        }

        // Nothing changed → skip the swap entirely (avoids needless chart
        // redraws on the 2-minute background refresh).
        if (newMain.innerHTML === main.innerHTML) return true;

        // Preserve every scroll position, not just the page's own: cards with
        // their own scrollbox (e.g. the Orders list capped at 60vh) and wide
        // tables scrolled horizontally would otherwise snap back to top/left
        // on swap. Containers are re-found in the new content by tag + class +
        // occurrence order — the page structure is identical between refreshes
        // of the same URL, only the data inside changes.
        const keyOf = (el) => el.tagName + '|' + (el.className || '');
        const savedScrolls = [];
        for (const el of main.querySelectorAll('*')) {
            if (el.scrollTop || el.scrollLeft) {
                const key = keyOf(el);
                const idx = [...main.querySelectorAll(el.tagName)].filter(s => keyOf(s) === key).indexOf(el);
                savedScrolls.push({ key, idx, top: el.scrollTop, left: el.scrollLeft });
            }
        }

        const scrollTop = main.scrollTop;

        // Crossfade the swap (150ms out, 150ms in) instead of an instant snap —
        // content replacement should read as one continuous transition, not a
        // jump cut. Skipped entirely under prefers-reduced-motion.
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!reduceMotion) {
            main.style.transition = 'opacity 150ms ease-out';
            main.style.opacity = '0';
            await new Promise((r) => setTimeout(r, 150));
        }

        main.innerHTML = newMain.innerHTML;
        main.scrollTop = scrollTop;

        for (const { key, idx, top, left } of savedScrolls) {
            const el = [...main.querySelectorAll(key.split('|')[0])].filter(s => keyOf(s) === key)[idx];
            if (el) { el.scrollTop = top; el.scrollLeft = left; }
        }

        // Header controls (team/tab filter buttons, etc.) live in
        // @push('topbar-right') — outside <main> — so their server-computed
        // active/inactive classes go stale here since only <main> was just
        // swapped. Sync each one from the freshly fetched document by matching
        // name+value (never by replacing the node, so listeners stay intact).
        // Covers back/forward and any refresh triggered by something other
        // than clicking the button itself (Sync, date picker, auto-refresh).
        doc.querySelectorAll('[data-filter-btn]').forEach((fresh) => {
            const current = document.querySelector(
                `[data-filter-btn][name="${fresh.name}"][value="${fresh.value}"]`
            );
            if (current) current.className = fresh.className;
        });

        // Same staleness problem, but for the topbar filter form's hidden
        // fallback fields (team, product, range, ...) — these carry the actual
        // VALUE a later submit through a different control (e.g. the date
        // picker's Apply) will send, not just its visual state. Only <main>'s
        // content was just swapped, so a hidden field living in the topbar
        // still holds whatever it had at initial page load — meaning after
        // clicking e.g. "Eyecare" (which only updates the button's className
        // via the sync above, not this field), applying a date filter would
        // silently resubmit the ORIGINAL team instead of the one currently
        // selected. Skip anything inside <main> — that content is already
        // fresh from the innerHTML swap above.
        document.querySelectorAll('input[type="hidden"][name]').forEach((current) => {
            if (main.contains(current)) return;
            const fresh = doc.querySelector(`input[type="hidden"][name="${current.name}"]`);
            if (fresh) current.value = fresh.value;
        });

        doc.querySelectorAll('script[data-rerun]').forEach((orig) => {
            const s = document.createElement('script');
            s.textContent = orig.textContent;
            document.body.appendChild(s);
            s.remove();
        });

        if (!reduceMotion) main.style.opacity = '1';
        document.dispatchEvent(new CustomEvent('page:refreshed'));
        return true;
    } catch {
        return false;
    }
};

// ─── Silent auto-refresh ─────────────────────────────────────────────────────
// Pages showing "today" opt in by rendering partials/live-indicator.blade.php
// (the hidden #liveRefreshMarker). Refreshes in place every 2 minutes so
// background-synced changes appear on their own — no visible reload, no
// scroll loss. Hidden tabs skip the tick; they catch up when next viewed.
(function () {
    if (!document.getElementById('liveRefreshMarker')) return;

    setInterval(() => {
        if (document.hidden) return;
        window.softRefresh();
    }, 120000);
})();

// ─── GET filter forms → soft refresh ─────────────────────────────────────────
// Every report's topbar filter (team buttons, product dropdown, Load, the date
// picker's Apply) is a GET form submit. Intercept those and swap content in
// place instead of navigating; the URL still updates (pushState) so reload,
// back button and bookmarks behave exactly as before. POST forms (login,
// settings, CRUD) are untouched. Falls back to a real navigation on any error.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.method.toLowerCase() !== 'get') return;

    // Instant feedback: flip the clicked filter button's active state right
    // away rather than waiting on the fetch — a state change should never
    // sit frozen for the length of a network round-trip. softRefresh's
    // header-sync (above) reconciles this against the server's actual
    // response once it lands, so it's always eventually correct too.
    const submitter = e.submitter;
    if (submitter?.hasAttribute('data-filter-btn')) {
        const activeClasses   = ['bg-primary', 'text-white'];
        const inactiveClasses = ['bg-white', 'text-slate-500', 'hover:bg-slate-50'];
        form.querySelectorAll(`[data-filter-btn][name="${submitter.name}"]`).forEach((btn) => {
            const isNowActive = btn === submitter;
            btn.classList.remove(...(isNowActive ? inactiveClasses : activeClasses));
            btn.classList.add(...(isNowActive ? activeClasses : inactiveClasses));
        });
    }

    // A clicked filter button (e.g. a team tab) and the form's own hidden
    // same-name fallback field (kept so OTHER submits, like the date picker's
    // Apply, don't drop the current team) both end up in this FormData — the
    // button's value is appended after the stale hidden one, so the LAST
    // occurrence is always the correct new value. Query-string duplicates
    // resolve the same way server-side, but leaving both in the URL is
    // confusing to read and fragile to rely on — collapse to one value per key.
    const rawParams = new URLSearchParams(new FormData(form, e.submitter || undefined));
    const params = new URLSearchParams();
    for (const [key, value] of rawParams) params.set(key, value);
    const query  = params.toString();
    const url    = form.action.split('?')[0] + (query ? '?' + query : '');

    e.preventDefault();
    window.softRefresh(url, { pushUrl: true }).then((ok) => {
        if (!ok) window.location.href = url;
    });
});

// Back/forward after a pushState above re-renders the restored URL in place.
window.addEventListener('popstate', () => window.softRefresh(window.location.href));

// ─── Table export: CSV + PNG snapshot ────────────────────────────────────────
// Every report table renders partials/table-actions.blade.php — two icon
// buttons carrying data-export-csv / data-export-png with the id of the
// wrapper whose <table> to export. Delegated from document (same reasoning as
// the GET-form handler above: survives softRefresh's <main> swaps).
//
// CSV walks the live DOM rather than re-querying the server: what you see is
// exactly what you get, filters and all. colspan cells are padded with empty
// columns so headers stay aligned in Excel; rowspan isn't padded (only the
// hour-label column uses it, and losing the repeat is fine in a flat file).
function tableToCsv(table) {
    const rows = [];
    for (const tr of table.querySelectorAll('tr')) {
        const cells = [];
        for (const cell of tr.querySelectorAll('th, td')) {
            // <br> inside header labels reads as a space, not a squashed word
            const clone = cell.cloneNode(true);
            clone.querySelectorAll('br').forEach((br) => br.replaceWith(' '));
            const text = clone.textContent.replace(/\s+/g, ' ').trim();
            cells.push('"' + text.replace(/"/g, '""') + '"');
            for (let i = 1; i < (cell.colSpan || 1); i++) cells.push('""');
        }
        rows.push(cells.join(','));
    }
    return rows.join('\r\n');
}

function downloadBlob(blob, filename) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
}

// html2canvas-pro (NOT plain html2canvas: 1.4.1 chokes on the oklch() colors
// Tailwind v4 emits — "unsupported color function oklch") is ~200kb — only
// fetched the first time a snapshot is taken, never on page load. Cached
// promise so repeat clicks don't re-inject.
let html2canvasReady = null;
function loadHtml2Canvas() {
    if (window.html2canvas) return Promise.resolve();
    if (!html2canvasReady) {
        html2canvasReady = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/html2canvas-pro@1.5.11/dist/html2canvas-pro.min.js';
            s.onload = resolve;
            s.onerror = () => { html2canvasReady = null; reject(new Error('html2canvas failed to load')); };
            document.head.appendChild(s);
        });
    }
    return html2canvasReady;
}

document.addEventListener('click', async (e) => {
    const csvBtn = e.target.closest('[data-export-csv]');
    const pngBtn = e.target.closest('[data-export-png]');
    if (!csvBtn && !pngBtn) return;

    const btn     = csvBtn || pngBtn;
    const target  = document.getElementById(btn.dataset.exportCsv || btn.dataset.exportPng);
    const table   = target?.querySelector('table') || target;
    if (!table) return;

    const name = (btn.dataset.exportName || 'export') + '-' + new Date().toISOString().slice(0, 10);

    if (csvBtn) {
        // UTF-8 BOM: Excel needs it to render ₱ signs correctly
        downloadBlob(new Blob(['﻿' + tableToCsv(table)], { type: 'text/csv;charset=utf-8' }), name + '.csv');
        return;
    }

    // PNG: capture the <table> element itself, not its scroll container, so a
    // horizontally-scrolled wide table is captured in full, not cropped to the
    // visible slice. Button shows a busy state — capture takes a beat.
    btn.disabled = true;
    btn.classList.add('opacity-40');

    // Exported images are meant to be shared/printed, so they're always rendered
    // in light mode regardless of the viewer's current on-screen theme — forcing
    // html2canvas's backgroundColor to white while the table's live computed
    // colors are dark-mode grays/whites would otherwise produce a near-illegible,
    // low-contrast PNG. Stripped right before capture, restored in `finally` so
    // a capture error never leaves the page stuck in light mode.
    const wasDark = document.documentElement.classList.contains('dark');
    if (wasDark) document.documentElement.classList.remove('dark');
    try {
        await loadHtml2Canvas();
        const canvas = await window.html2canvas(table, { backgroundColor: '#ffffff', scale: 2 });
        canvas.toBlob((blob) => blob && downloadBlob(blob, name + '.png'), 'image/png');
    } catch (err) {
        console.error('Table snapshot failed:', err);
    } finally {
        if (wasDark) document.documentElement.classList.add('dark');
        btn.disabled = false;
        btn.classList.remove('opacity-40');
    }
});
