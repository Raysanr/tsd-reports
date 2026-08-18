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

// ─── Reload button ───────────────────────────────────────────────────────────
// Global, every page (layouts/app.blade.php) — a quick client-side refresh of
// the current view via softRefresh, with no outbound Pancake POS call (unlike
// Dashboard's Sync button, which is a real, slower sync). Wired here rather
// than per-page since softRefresh itself isn't defined until below — this
// listener just needs to exist once, globally.
(function () {
    const btn = document.getElementById('reloadBtn');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const icon = document.getElementById('reloadIcon');
        btn.disabled = true;
        icon.classList.add('animate-spin');

        window.softRefresh(window.location.href, { showLoading: true })
            .then((ok) => { if (!ok) window.location.reload(); })
            .finally(() => { btn.disabled = false; icon.classList.remove('animate-spin'); });
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
window.softRefresh = async function (url = window.location.href, { pushUrl = false, showLoading = false } = {}) {
    // showLoading is opt-in: the silent 2-minute background refresh (below)
    // deliberately stays invisible, but a user-initiated filter change (team,
    // product, date range) should never look frozen for the length of the
    // round-trip — #loadingOverlay (layouts/app.blade.php, a sibling of <main>
    // so the innerHTML swap below never wipes it out) covers the table until
    // this resolves either way.
    const overlay = showLoading ? document.getElementById('loadingOverlay') : null;
    overlay?.classList.remove('hidden');
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

        // TSA Performance's product dropdown can't use the name+value className
        // copy above: switching TEAM changes which PRODUCTS exist at all (SH
        // Naturals vs Eyecare have entirely different catalogs), so the stale
        // panel doesn't just have wrong highlighting — it lists the wrong
        // team's products outright, with no matching value to sync onto.
        // Replace the whole panel's contents instead. Safe to do even though
        // it discards the existing button nodes: their submit handling is a
        // single delegated document-level 'submit' listener (added once, up
        // top), not a per-button listener, so nothing is lost — and the panel
        // container itself (referenced by the dropdown's own open/close toggle
        // script, run once at initial load) is never replaced, just its innerHTML.
        const freshProductPanel = doc.querySelector('#productPanel');
        const currentProductPanel = document.querySelector('#productPanel');
        if (freshProductPanel && currentProductPanel) currentProductPanel.innerHTML = freshProductPanel.innerHTML;

        // Same staleness problem as [data-filter-btn] above, but for the Sync button
        // itself (dashboard.blade.php, also in @push('topbar-right') outside <main>)
        // — its red/yellow background, title and aria-label reflect $stats['sync_stale']
        // as of the ORIGINAL page load. A successful sync just bumped last_synced,
        // which the freshly-fetched document already reflects correctly (it's a real
        // server render) — this just copies that fresh state onto the live button so
        // its "stale" warning clears without a full reload. No-op on any page that
        // doesn't have a #syncBtn (every page except the Dashboard).
        const freshSyncBtn = doc.querySelector('#syncBtn');
        const currentSyncBtn = document.querySelector('#syncBtn');
        if (freshSyncBtn && currentSyncBtn) {
            currentSyncBtn.className = freshSyncBtn.className;
            currentSyncBtn.title = freshSyncBtn.title;
            currentSyncBtn.setAttribute('aria-label', freshSyncBtn.getAttribute('aria-label'));
        }

        // Same staleness problem, but for the product dropdown's own trigger
        // label (TSA Performance) — it reads $selectedProduct on the ORIGINAL
        // page load, so picking a product from the panel (whose whole contents
        // were just replaced above) left the trigger itself still reading
        // "All Products" (or whatever was selected before) since it's outside
        // <main> and nothing was copying its text over.
        const freshProductLabel = doc.querySelector('#productTriggerLabel');
        const currentProductLabel = document.querySelector('#productTriggerLabel');
        if (freshProductLabel && currentProductLabel) currentProductLabel.textContent = freshProductLabel.textContent;

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
    } finally {
        overlay?.classList.add('hidden');
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
    window.softRefresh(url, { pushUrl: true, showLoading: true }).then((ok) => {
        if (!ok) window.location.href = url;
    });
});

// Back/forward after a pushState above re-renders the restored URL in place.
window.addEventListener('popstate', () => window.softRefresh(window.location.href, { showLoading: true }));

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
        // Skip rows hidden by the sortable-table live filter (data-table-filter,
        // added later in this file) — "what you see is exactly what you get,
        // filters and all" above was written before that filter existed, but a
        // hidden row is still a real <tr> in the DOM, so without this check it
        // would silently leak into the export despite being filtered out on
        // screen.
        if (tr.classList.contains('hidden')) continue;
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

    // The chart panel next to this table can be resized live by the user
    // (the drag handle in pie-chart-panel.blade.php) purely for on-screen
    // viewing, but a snapshot should always come out at the same normal
    // size regardless of that (explicit request, 2026-08-14) — squeezing
    // the table wrapper down that far also forces it to scroll
    // horizontally, and html2canvas is well known to mis-render
    // `position: sticky` columns (this table's product/time column, see
    // .sticky-col in layouts/app.blade.php) once a scroll offset is
    // involved, so resetting to default fixes both the tiny-chart and the
    // scrambled-table cases together. Snapping the panel to that default
    // width instantly read as a jarring flash — animating the resize
    // instead (explicit request: "make it has animation... expand and go
    // back again") turns the same mechanism into an intentional transition.
    // The `transition` is only added for this one resize, not left on
    // permanently — permanently on would make the drag handle feel laggy,
    // easing into place after every mouse-move instead of tracking the
    // cursor directly.
    const chartCanvas  = btn.dataset.exportChart ? document.getElementById(btn.dataset.exportChart) : null;
    const chartFrame   = chartCanvas?.parentElement;
    const chartPanel   = chartFrame?.parentElement;
    const restoreWidth = chartPanel?.style.width || '';
    const ANIMATE_MS   = 350;

    const animatePanelWidth = (targetWidth) => new Promise((resolve) => {
        if (!chartPanel) return resolve();
        chartPanel.style.transition = `width ${ANIMATE_MS}ms cubic-bezier(0.4, 0, 0.2, 1)`;
        // Redrawing the full pie (arcs + per-label text measurement) on
        // every intermediate layout tick a width transition fires is
        // expensive enough to visibly stutter the animation instead of
        // gliding smoothly (explicit report, 2026-08-14: "not like
        // glitching") — paused for the animation's duration so the canvas
        // just stretches as a cheap bitmap scale, then window.__redrawAll
        // PieCharts() does one crisp redraw once it's actually settled.
        window.__pieRedrawPaused = true;
        // Two rAF ticks so the transition property itself is committed
        // before the width changes — setting both in the same tick can get
        // coalesced into an instant jump instead of an animated one.
        requestAnimationFrame(() => requestAnimationFrame(() => {
            chartPanel.style.width = targetWidth;
            setTimeout(() => {
                window.__pieRedrawPaused = false;
                window.__redrawAllPieCharts?.();
                resolve();
            }, ANIMATE_MS + 50);
        }));
    });

    if (chartPanel && restoreWidth) await animatePanelWidth('');

    try {
        await loadHtml2Canvas();
        const tableCanvas = await window.html2canvas(table, { backgroundColor: '#ffffff', scale: 2 });

        // Optional adjacent chart (Leads Report's disposition pie) — composited
        // beside the table so the exported image matches what's on screen, not
        // just the table half of it. The chart canvas is Chart.js's own already-
        // rendered bitmap (drawImage handles the scale-up cleanly), not a second
        // html2canvas pass — its legend/label colors read fine on a white
        // background in either theme (both --chart-label values are mid-gray,
        // confirmed in app.css), so no re-render-in-light-mode dance is needed
        // here the way the table gets above.
        // The canvas's own bordered wrapper (see leads-report.blade.php) — read
        // for its padding/border so the export frames the chart the same way
        // the page does, not just the bare canvas. Read fresh here (post-reset
        // above), so this always reflects the default, undragged proportions.
        const chartBox    = chartCanvas?.getBoundingClientRect();
        const frameBox    = chartFrame?.getBoundingClientRect();
        let finalCanvas   = tableCanvas;

        if (chartCanvas && chartBox && frameBox && chartBox.width > 0) {
            const scale   = 2; // matches the table capture's own scale above
            const gap     = 16 * scale;
            const padX    = (frameBox.width - chartBox.width) / 2 * scale;
            const padY    = (frameBox.height - chartBox.height) / 2 * scale;
            const frameW  = frameBox.width * scale;
            const frameH  = frameBox.height * scale;

            finalCanvas = document.createElement('canvas');
            finalCanvas.width  = tableCanvas.width + gap + frameW;
            finalCanvas.height = Math.max(tableCanvas.height, frameH);

            // Vertically centered against the table's own height, matching the
            // on-screen layout (flex items-center) — not pinned to the top,
            // which left it floating disconnected from the table below it.
            const frameY = (finalCanvas.height - frameH) / 2;

            const ctx = finalCanvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, finalCanvas.width, finalCanvas.height);
            ctx.drawImage(tableCanvas, 0, 0);

            const frameX = tableCanvas.width + gap;
            ctx.fillStyle   = '#ffffff';
            ctx.fillRect(frameX, frameY, frameW, frameH);
            ctx.strokeStyle = '#e2e8f0'; // border-slate-200, matching the on-screen frame
            ctx.lineWidth   = 1 * scale;
            ctx.strokeRect(frameX, frameY, frameW, frameH);
            ctx.drawImage(chartCanvas, frameX + padX, frameY + padY, chartBox.width * scale, chartBox.height * scale);
        }

        // Title bar: the table element captured above never includes its own
        // heading (that h2 lives in a sibling div on the page), so without this
        // a downloaded/shared snapshot has no way to identify which table/
        // product it's showing. Drawn as its own band on top of whatever was
        // captured so far (table alone, or table+chart composite).
        const title    = btn.dataset.exportTitle;
        const subtitle = btn.dataset.exportSubtitle;
        if (title) {
            const scale      = 2; // matches the table capture's own scale above
            // Two-line band (title + date subtitle) needs more room than a
            // title-only one — fixed heights rather than measuring text, since
            // both lines use a known, unchanging font size.
            const bandHeight = (subtitle ? 84 : 56) * scale;
            const titled     = document.createElement('canvas');
            titled.width  = finalCanvas.width;
            titled.height = finalCanvas.height + bandHeight;

            const tctx = titled.getContext('2d');
            tctx.fillStyle = '#ffffff';
            tctx.fillRect(0, 0, titled.width, titled.height);
            tctx.textBaseline = 'middle';
            tctx.fillStyle = '#334155'; // slate-700, matching the on-screen h2
            tctx.font = `bold ${20 * scale}px ui-monospace, monospace`;
            tctx.fillText(title, 24 * scale, subtitle ? 32 * scale : bandHeight / 2);
            if (subtitle) {
                tctx.fillStyle = '#94a3b8'; // slate-400, matching the on-screen rangeLabel
                tctx.font = `${13 * scale}px ui-monospace, monospace`;
                tctx.fillText(subtitle, 24 * scale, 60 * scale);
            }
            tctx.strokeStyle = '#e2e8f0'; // border-slate-200
            tctx.lineWidth = 1 * scale;
            tctx.beginPath();
            tctx.moveTo(0, bandHeight);
            tctx.lineTo(titled.width, bandHeight);
            tctx.stroke();
            tctx.drawImage(finalCanvas, 0, bandHeight);

            finalCanvas = titled;
        }

        finalCanvas.toBlob((blob) => blob && downloadBlob(blob, name + '.png'), 'image/png');
    } catch (err) {
        console.error('Table snapshot failed:', err);
    } finally {
        if (chartPanel && restoreWidth) await animatePanelWidth(restoreWidth);
        if (chartPanel) chartPanel.style.transition = ''; // don't leave the drag handle feeling laggy afterward
        if (wasDark) document.documentElement.classList.add('dark');
        btn.disabled = false;
        btn.classList.remove('opacity-40');
    }
});

// ─── TSA Performance: click a leads-count cell to see its orders ─────────────
// Every [data-drilldown] <td> in tsa-performance.blade.php carries which
// TSA/hour/column it represents (data-dd-tsa/-hour/-column); the shared team/
// product/date context lives once on the table wrapper (#tsaPerfTable,
// data-dd-team etc.) instead of being repeated on every cell. Delegated click
// (same reasoning as CSV/PNG export above): survives softRefresh's <main>
// swaps without needing to re-bind anything.
(function () {
    let popover = null;

    function closePopover() {
        popover?.remove();
        popover = null;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    function positionPopover(cell, el) {
        const rect = cell.getBoundingClientRect();
        // Fixed positioning (not absolute) so it isn't clipped by the table's
        // own overflow-auto scroll container — computed from the cell's
        // viewport coordinates instead.
        const maxLeft = window.innerWidth - 320;
        el.style.top  = `${rect.bottom + 4}px`;
        el.style.left = `${Math.max(8, Math.min(rect.left, maxLeft))}px`;
    }

    document.addEventListener('click', (e) => {
        const cell = e.target.closest('[data-drilldown]');
        if (!cell) {
            if (popover && !popover.contains(e.target)) closePopover();
            return;
        }

        const wrapper = cell.closest('[data-dd-team]');
        if (!wrapper) return;

        // Toggle: clicking the same cell again closes it instead of
        // re-fetching/re-showing the identical popover. ddCellProduct is only set
        // by Leads Report's per-product-row Total Leads cells (see below) — folded
        // in so each row's cell counts as a distinct cell from the others, which
        // ddTsa/ddHour/ddColumn alone can't tell apart there (none of them vary
        // per row on that page).
        const cellKey = [cell.dataset.ddTsa, cell.dataset.ddHour, cell.dataset.ddColumn, cell.dataset.ddCellProduct].join('|');
        const wasOpenForThisCell = popover?.dataset.forCell === cellKey;
        closePopover();
        if (wasOpenForThisCell) return;

        // ddCellProduct overrides the wrapper's own ddProduct (a page-wide product
        // FILTER, meaningless to Leads Report) with the specific product THIS row
        // is about — the one thing that actually varies per cell on that page.
        const params = new URLSearchParams({
            team:      wrapper.dataset.ddTeam,
            product:   cell.dataset.ddCellProduct || wrapper.dataset.ddProduct,
            date_from: wrapper.dataset.ddDateFrom,
            date_to:   wrapper.dataset.ddDateTo,
        });
        // tsa/column/hour: omitted entirely (not just empty) when a cell doesn't
        // set the attribute — e.g. Leads Report's plain Total Leads cell sets
        // neither tsa nor column. Root-caused 2026-08-17: URLSearchParams
        // silently stringifies a JS `undefined` to the literal text "undefined"
        // rather than dropping the key, and the backend's `if ($column)` treated
        // that non-empty string as a real (but unrecognized) column filter,
        // falling through to an empty result — "No orders found" on every Total
        // Leads cell regardless of its actual count. Same reasoning already
        // applied to 'hour' below; tsa/column just hadn't gotten it.
        if (cell.dataset.ddTsa !== undefined && cell.dataset.ddTsa !== '') {
            params.set('tsa', cell.dataset.ddTsa);
        }
        if (cell.dataset.ddColumn !== undefined && cell.dataset.ddColumn !== '') {
            params.set('column', cell.dataset.ddColumn);
        }
        // Omitted entirely (not just empty) for a Grand Total cell — the
        // endpoint reads a missing 'hour' as "every hour", not hour 0.
        if (cell.dataset.ddHour !== undefined && cell.dataset.ddHour !== '') {
            params.set('hour', cell.dataset.ddHour);
        }

        popover = document.createElement('div');
        popover.dataset.forCell = cellKey;
        popover.className = 'fixed z-50 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 py-1 overflow-y-auto text-xs font-mono';
        popover.style.minWidth  = '300px';
        popover.style.maxWidth  = '360px';
        popover.style.maxHeight = '280px';
        popover.innerHTML = '<p class="px-3 py-3 text-slate-400">Loading…</p>';
        document.body.appendChild(popover);
        positionPopover(cell, popover);

        // Defaults to the TSA Performance endpoint (its cells never set this
        // attribute); Leads Report's product-total cells set their own.
        const endpoint = wrapper.dataset.ddEndpoint || '/tsa-performance/drilldown';

        fetch(`${endpoint}?${params.toString()}`)
            .then(r => r.json())
            .then((orders) => {
                if (popover?.dataset.forCell !== cellKey) return; // superseded by a newer click
                if (!orders.length) {
                    popover.innerHTML = '<p class="px-3 py-3 text-slate-400">No orders found.</p>';
                    return;
                }
                // 'status' is only present from Leads Report's drilldown (a local
                // order status, e.g. to spot one Pancake has since cancelled/deleted
                // that hasn't re-synced) — TSA Performance's response has no such
                // field, so that middle span just doesn't render there. 'product' is
                // the matched item/cart text ProductPerformance actually matched
                // against (see LeadsReportController::drilldown()) — shown on its own
                // line underneath so a false-positive match (text that doesn't really
                // belong to this product) is visible directly in the popover instead
                // of only in the raw JSON.
                //
                // Order ID is click-to-copy (data-copy-order-id, handled below) —
                // status/time get style="user-select:none" so a drag-select across
                // the row (or several rows) can't pick them up too; only the ID
                // itself is ever selectable/copyable here. The "#" is drawn via
                // .order-id-copy's own ::before rule (app.css), not real text, so
                // a drag-select (single row or many) copies bare digits only —
                // matches what the click-to-copy handler below already copied.
                popover.innerHTML = orders.map(o => `
                    <div class="px-3 py-1.5 border-b border-slate-100 dark:border-slate-800 last:border-b-0">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-primary font-semibold cursor-pointer hover:underline order-id-copy" data-copy-order-id="${escapeHtml(o.id)}" title="Click to copy">${escapeHtml(o.id)}</span>
                            ${o.status ? `<span class="text-slate-400 dark:text-slate-500" style="user-select:none">${escapeHtml(o.status)}</span>` : ''}
                            <span class="text-slate-400 dark:text-slate-500 whitespace-nowrap" style="user-select:none">${escapeHtml(o.time || '—')}</span>
                        </div>
                        ${o.product ? `<div class="text-slate-500 dark:text-slate-400 truncate" style="user-select:none" title="${escapeHtml(o.product)}">${escapeHtml(o.product)}</div>` : ''}
                        ${o.matched_via ? `<div class="text-amber-600 dark:text-amber-500 truncate" style="user-select:none" title="${escapeHtml(o.matched_via)}">via ${escapeHtml(o.matched_via)}</div>` : ''}
                    </div>
                `).join('');
                positionPopover(cell, popover);
            })
            .catch(() => {
                if (popover?.dataset.forCell === cellKey) popover.innerHTML = '<p class="px-3 py-3 text-rose-500">Failed to load.</p>';
            });
    });

    // Click-to-copy the order ID — separate listener (not folded into the
    // drilldown one above) so a click on the ID inside an already-open
    // popover doesn't also run that listener's own cell/close-popover logic.
    document.addEventListener('click', (e) => {
        const idEl = e.target.closest('[data-copy-order-id]');
        if (!idEl) return;

        navigator.clipboard.writeText(idEl.dataset.copyOrderId).then(() => {
            const original = idEl.textContent;
            idEl.textContent = 'Copied!';
            setTimeout(() => { idEl.textContent = original; }, 900);
        }).catch(() => {});
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePopover();
    });

    // Any scroll OUTSIDE the popover (including the table's own internal
    // overflow-auto scroll, which doesn't bubble to document without
    // capture:true) moves the cell out from under a fixed-position popover —
    // close it rather than let it drift stale. Scrolling the popover's OWN
    // list (Leads Report's product totals can be 100+ orders, well past its
    // own max-height) must NOT trigger this, or it closes itself the instant
    // you try to scroll through it.
    document.addEventListener('scroll', (e) => {
        if (popover?.contains(e.target)) return;
        closePopover();
    }, true);
})();

// ─── Toast notifications ─────────────────────────────────────────────────────
// window.showToast(message, variant) is the one entry point every part of the
// app uses for transient feedback — server-flashed messages (see the bootstrap
// script in layouts/app.blade.php) and client-side actions (e.g. the Dashboard's
// Sync button) both go through this. Reuses the exact card styling the old
// per-page session('success') banners used (bg-{color}-50/border-{color}-200/
// rounded-xl), just floated in a fixed corner instead of inline in the page.
const TOAST_VARIANTS = {
    success: {
        classes      : 'bg-green-50 border-green-200',
        iconClasses  : 'text-green-500',
        textClasses  : 'text-green-700',
        closeClasses : 'text-green-400 hover:text-green-600',
        // Checkmark — identical glyph to the banner this replaces.
        iconPath     : 'M5 13l4 4L19 7',
    },
    error: {
        classes      : 'bg-red-50 border-red-200',
        iconClasses  : 'text-red-500',
        textClasses  : 'text-red-700',
        closeClasses : 'text-red-400 hover:text-red-600',
        // x-circle — distinct SHAPE from success, not just color, so the
        // variant reads correctly for colorblind users too.
        iconPath     : 'M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    info: {
        classes      : 'bg-blue-50 border-blue-200',
        iconClasses  : 'text-blue-500',
        textClasses  : 'text-blue-700',
        closeClasses : 'text-blue-400 hover:text-blue-600',
        // info-circle — deliberately not the brand yellow (bg-yellow-*): that
        // color is reserved elsewhere in this app for "a custom date filter is
        // active" (see partials/date-picker.blade.php's dot indicator), and
        // reusing it here would make a toast read as that unrelated signal.
        iconPath     : 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
    },
};

window.showToast = function (message, variant = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    let v = TOAST_VARIANTS[variant];
    if (!v) console.warn(`showToast: unknown variant "${variant}", falling back to "success"`);
    v = v || TOAST_VARIANTS.success;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const toast = document.createElement('div');
    // Errors must interrupt (assertive), not just politely queue — toasts
    // auto-dismiss after 4s, and a "polite" announcement can be missed
    // entirely before it's gone. success/info stay polite.
    toast.setAttribute('role', variant === 'error' ? 'alert' : 'status');
    toast.className = 'pointer-events-auto flex items-center gap-3 border rounded-xl px-4 py-3 shadow-lg '
        + v.classes + ' opacity-0 transition-all duration-200 ease-out'
        + (reduceMotion ? '' : ' translate-x-4');

    // Icon + close button are built from fixed, developer-controlled strings
    // (safe as innerHTML). The message itself is set via textContent below,
    // NEVER interpolated into innerHTML — flashed messages can contain
    // admin-entered free text (e.g. a product/TSA/user display name), and
    // building HTML from that would be a stored XSS hole.
    toast.innerHTML = `
        <svg class="w-4 h-4 ${v.iconClasses} shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="${v.iconPath}"/>
        </svg>
        <p class="text-sm font-mono ${v.textClasses} flex-1"></p>
        <button type="button" class="${v.closeClasses} shrink-0 cursor-pointer" aria-label="Dismiss">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    `;
    toast.querySelector('p').textContent = message;

    container.appendChild(toast);

    // Animate in next frame (so the initial opacity-0/translate-x-4 actually
    // paints first — setting the "in" classes in the same tick would collapse
    // into one state and skip the transition).
    requestAnimationFrame(() => {
        toast.classList.remove('opacity-0');
        toast.classList.add('opacity-100');
        if (!reduceMotion) {
            toast.classList.remove('translate-x-4');
            toast.classList.add('translate-x-0');
        }
    });

    let dismissTimer = null;
    const dismiss = () => {
        clearTimeout(dismissTimer);
        toast.classList.remove('opacity-100', 'translate-x-0');
        toast.classList.add('opacity-0');
        if (!reduceMotion) toast.classList.add('translate-x-4');
        toast.classList.replace('duration-200', 'duration-150');
        toast.classList.replace('ease-out', 'ease-in');
        setTimeout(() => toast.remove(), reduceMotion ? 0 : 150);
    };

    const startTimer = () => { dismissTimer = setTimeout(dismiss, 4000); };
    startTimer();

    toast.addEventListener('mouseenter', () => clearTimeout(dismissTimer));
    toast.addEventListener('mouseleave', startTimer);
    // Mirror the hover pause for keyboard users: tabbing to the close button
    // (focusin) shouldn't have the toast vanish out from under them before
    // they can act; focusout resumes the timer just like mouseleave.
    toast.addEventListener('focusin', () => clearTimeout(dismissTimer));
    toast.addEventListener('focusout', startTimer);
    toast.querySelector('button').addEventListener('click', dismiss);
};

// ─── Sync Health: "Fix Now" reconcile, in-page + cancelable ──────────────────
// sync-health.blade.php's form used to be a plain POST — a full page reload
// for an action that can run long on a wide date range (explicit request,
// 2026-08-14). Submitted via fetch instead, showing an in-page loading state
// with a Cancel button. Guarded on the form's existence, same as every other
// page-specific block in this file — a no-op everywhere but Sync Health.
//
// Cancel is soft, not hard: aborting the fetch only stops THIS PAGE from
// waiting on/showing the result. Artisan::call() inside reconcileStatuses()
// runs synchronously with no cancellation hook of its own, so the fix keeps
// running to completion on the server regardless of whether anyone's still
// watching — see that method's own comment for why a real mid-run cancel
// would need background-job infrastructure this app doesn't have running.
(function () {
    const form = document.getElementById('reconcileForm');
    if (!form) return;

    const submitBtn = document.getElementById('reconcileSubmitBtn');
    const cancelBtn = document.getElementById('reconcileCancelBtn');
    const iconIdle  = document.getElementById('reconcileIconIdle');
    const iconBusy  = document.getElementById('reconcileIconBusy');
    const label     = document.getElementById('reconcileBtnLabel');

    let controller = null;

    const setBusy = (busy) => {
        submitBtn.disabled = busy;
        iconIdle.classList.toggle('hidden', busy);
        iconBusy.classList.toggle('hidden', !busy);
        cancelBtn.classList.toggle('hidden', !busy);
        label.textContent = busy ? 'Fixing…' : 'Fix Now';
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        controller = new AbortController();
        setBusy(true);

        fetch(form.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: new FormData(form),
            signal: controller.signal,
        })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                window.showToast(data.message || 'Done.', (ok && data.success) ? 'success' : 'error');
            })
            .catch((err) => {
                if (err.name === 'AbortError') {
                    window.showToast('Cancelled — the fix may still finish running on the server.', 'info');
                    return;
                }
                window.showToast('Something went wrong — check your connection and try again.', 'error');
            })
            .finally(() => {
                setBusy(false);
                controller = null;
            });
    });

    cancelBtn.addEventListener('click', () => controller?.abort());
})();

// ─── Confirm modal ────────────────────────────────────────────────────────────
// window.showConfirm(message, opts) replaces every destructive-action
// confirm() across the app (delete, bulk delete, move team, etc.) — the
// browser's native confirm() always prefixes its dialog with the page's own
// hostname ("localhost:8000 says", unstylable) since it's a browser chrome
// element, not page content. This is a real modal instead, matching the rest
// of the app's styling. Returns a Promise<boolean> (true = confirmed) so
// call sites just `if (!await window.showConfirm(...)) return;`, the same
// shape the old `if (!confirm(...)) return;` calls already had.
window.showConfirm = function (message, { title = 'Are you sure?', confirmText = 'Confirm', cancelText = 'Cancel', danger = true } = {}) {
    const modal      = document.getElementById('confirmModal');
    const titleEl     = document.getElementById('confirmModalTitle');
    const messageEl   = document.getElementById('confirmModalMessage');
    const cancelBtn   = document.getElementById('confirmModalCancel');
    const confirmBtn  = document.getElementById('confirmModalConfirm');
    if (!modal) return Promise.resolve(false);

    titleEl.textContent = title;
    messageEl.textContent = message;
    cancelBtn.textContent = cancelText;
    confirmBtn.textContent = confirmText;
    confirmBtn.className = 'px-4 py-2 text-xs font-semibold text-white rounded-lg transition-colors cursor-pointer '
        + (danger ? 'bg-red-600 hover:bg-red-700' : 'bg-yellow-700 hover:bg-yellow-800');

    modal.classList.remove('hidden');

    return new Promise((resolve) => {
        // One-shot listeners, rebound fresh on every call — a stale listener
        // from a previous confirm() left attached would double-fire (or
        // resolve the WRONG call's promise) on the next one.
        function cleanup(result) {
            modal.classList.add('hidden');
            cancelBtn.removeEventListener('click', onCancel);
            confirmBtn.removeEventListener('click', onConfirm);
            modal.removeEventListener('click', onBackdrop);
            document.removeEventListener('keydown', onKeydown);
            resolve(result);
        }
        function onCancel()  { cleanup(false); }
        function onConfirm() { cleanup(true); }
        function onBackdrop(e) { if (e.target === modal) cleanup(false); }
        function onKeydown(e)  { if (e.key === 'Escape') cleanup(false); }

        cancelBtn.addEventListener('click', onCancel);
        confirmBtn.addEventListener('click', onConfirm);
        modal.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKeydown);
    });
};

// ─── Global search ────────────────────────────────────────────────────────────
// Topbar search box (layouts/app.blade.php) — debounced fetch to /search,
// grouped TSA/Product results rendered as a dropdown. Click or Enter navigates;
// Escape or clicking outside closes it. No arrow-key result navigation — matches
// this app's existing dropdown patterns (e.g. the date-picker), which are also
// click-only.
(function () {
    const input   = document.getElementById('globalSearchInput');
    const results = document.getElementById('globalSearchResults');
    if (!input || !results) return;

    let debounceTimer = null;
    let currentRequest = 0;

    const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);

    function renderGroup(label, items) {
        if (!items.length) return '';
        const rows = items.map(item => `
            <a href="${item.url}" class="block px-3 py-2 text-sm font-mono text-slate-700 dark:text-slate-200 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors truncate">
                ${escapeHtml(item.label)}
            </a>
        `).join('');
        return `
            <div class="px-3 pt-2 pb-1 text-[10px] font-mono font-semibold tracking-widest text-slate-400 uppercase">${label}</div>
            ${rows}
        `;
    }

    function showResults(data) {
        // users/auditLog are only ever non-empty for an admin (SearchController
        // gates them server-side) — a normal user's response just has [] for
        // both, so this renders identically to before their sections existed.
        const groups = [
            renderGroup('TSA Agents', data.tsas || []),
            renderGroup('Products', data.products || []),
            renderGroup('Orders', data.orders || []),
            renderGroup('Users', data.users || []),
            renderGroup('Activity Log', data.auditLog || []),
        ];
        const html = groups.join('');

        results.innerHTML = html || '<p class="px-3 py-3 text-sm font-mono text-slate-400">No results.</p>';
        results.classList.remove('hidden');
    }

    function hideResults() {
        // Cancel any pending debounce timer and invalidate any in-flight fetch's
        // stale-response check — every dismissal path (Escape, outside-click,
        // clearing the input below 2 chars) routes through here, so without this
        // a fetch kicked off just before dismissal would still land ~250ms later
        // and re-open the dropdown right after the user explicitly closed it.
        clearTimeout(debounceTimer);
        currentRequest++;
        results.classList.add('hidden');
    }

    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const query = input.value.trim();

        if (query.length < 2) {
            hideResults();
            return;
        }

        debounceTimer = setTimeout(() => {
            const requestId = ++currentRequest;
            fetch('/search?q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => {
                    // Stale-response guard: if the user kept typing, only the
                    // latest request's result should ever render.
                    if (requestId === currentRequest) showResults(data);
                })
                .catch(() => {});
        }, 250);
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hideResults();
            input.blur();
        }
    });

    document.addEventListener('click', (e) => {
        if (!results.contains(e.target) && e.target !== input) hideResults();
    });
})();

// ─── Sortable + filterable tables ────────────────────────────────────────────
// Opt-in via data-sortable-table on a wrapper div containing exactly one
// <table>. Click a <th data-sort-key="..."> to sort by that column (client-side,
// re-sorts the DOM rows already rendered — no server round-trip). A sibling
// input[data-table-filter="<wrapper-id>"] live-filters rows by substring match
// across the row's visible text. Both are independent — a table can have
// sort only, filter only, both, or (for time-pivot/hourly tables) neither.
//
// Delegated from document, like the CSV/PNG export buttons and the GET-form
// interceptor above — NOT bound directly to the <th>/<input> nodes. Every page
// these tables live on renders partials/live-indicator, which calls
// window.softRefresh() every 2 minutes AND after any team/date/product filter
// click on the page (see the GET-form handler above); softRefresh replaces
// main.innerHTML wholesale, discarding and recreating every node inside
// <main> — including this table and its header cells. A listener bound
// directly to those nodes at initial script-run time would silently stop
// firing the first time that happens (no error, nothing visibly broken, sort
// just quietly stops working). Delegating from document sidesteps this
// entirely: document itself is never replaced, so the listener keeps matching
// freshly-swapped-in nodes via e.target.closest() forever.
//
// Sort direction is likewise state that must survive a swap, so it can't live
// in a closure variable captured once at script-run time (a fresh table after
// a swap would have no memory of a prior click) — it's persisted on the
// <table> element itself via data-sort-key/data-sort-dir, re-read fresh on
// every click. cursor-pointer/select-none and the sort-direction chevron are
// plain CSS (th[data-sort-key] in app.css), for the same reason: a JS
// classList.add() run once at load would never reach a node created later by
// softRefresh, but a CSS attribute-selector rule applies to it automatically.
//
// Sort-value lookup: each sortable <td> carries BOTH data-sort-key (matching
// its column's <th>) and data-sort-value (the raw comparable value — a plain
// number or an ISO-ish sortable string, never the formatted display text, e.g.
// a "₱1,000.00" cell carries data-sort-value="1000.00" so it sorts numerically
// instead of alphabetically). Looked up per-row via
// row.querySelector('[data-sort-key="<key>"]').dataset.sortValue — falling
// back to the cell's own text if a particular row is missing the attribute.
document.addEventListener('click', (e) => {
    const th = e.target.closest('[data-sortable-table] th[data-sort-key]');
    if (!th) return;

    const table = th.closest('table');
    const tbody = table?.querySelector('tbody');
    if (!tbody) return;

    const key     = th.dataset.sortKey;
    const prevKey = table.dataset.sortKey;
    const prevDir = parseInt(table.dataset.sortDir || '1', 10);
    const dir     = (prevKey === key) ? -prevDir : 1; // 1 = ascending, -1 = descending
    table.dataset.sortKey = key;
    table.dataset.sortDir = String(dir);

    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        const cellA = a.querySelector(`[data-sort-key="${key}"]`);
        const cellB = b.querySelector(`[data-sort-key="${key}"]`);
        const valA = cellA ? (cellA.dataset.sortValue ?? cellA.textContent.trim()) : '';
        const valB = cellB ? (cellB.dataset.sortValue ?? cellB.textContent.trim()) : '';

        // Guardrail for future columns: parseFloat expects a bare number and
        // stops at the first non-numeric character, so a comma-formatted raw
        // value like "1,000.00" would silently parse as just 1 and misorder.
        // Not a live bug today — every data-sort-value this app emits is
        // already a plain unformatted number/string (see the block comment
        // above) — but never put thousands-separators in data-sort-value,
        // only in the cell's visible display text.
        const numA = parseFloat(valA);
        const numB = parseFloat(valB);
        const bothNumeric = valA.trim() !== '' && valB.trim() !== '' && !isNaN(numA) && !isNaN(numB);

        const cmp = bothNumeric
            ? (numA - numB)
            : valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' });
        return cmp * dir;
    });
    rows.forEach((row) => tbody.appendChild(row));

    table.querySelectorAll('th[data-sort-key]').forEach((h) => h.removeAttribute('data-sort-active'));
    th.setAttribute('data-sort-active', dir === 1 ? 'asc' : 'desc');
});

document.addEventListener('input', (e) => {
    const input = e.target.closest('[data-table-filter]');
    if (!input) return;

    const wrapper = document.getElementById(input.dataset.tableFilter);
    if (!wrapper) return;

    const query = input.value.trim().toLowerCase();
    wrapper.querySelectorAll('tbody tr').forEach((row) => {
        const matches = !query || row.textContent.toLowerCase().includes(query);
        row.classList.toggle('hidden', !matches);
    });
});

// ─── Saved filter presets ────────────────────────────────────────────────────
// "Presets" dropdown next to each report's other topbar filter controls (Leads
// Report, TSA Performance, RTS Report) — lets a user save the page's current
// TOP-LEVEL filters (team, date range, product — whatever's in the URL query
// string) under a name and reapply them in one click, instead of re-clicking
// the same combination every visit. Entirely client-side: a preset is just
// { name, query } (query = a captured window.location.search), stored in
// localStorage under `filterPresets:<key>` — one array per page, keyed by the
// trigger button's data-preset-key (e.g. "leads-report"), so Leads Report's
// saved views never bleed into TSA Performance's list or vice versa. No
// backend/database involvement.
//
// One shared implementation drives every page's dropdown — each trigger button
// carries data-preset-key (the localStorage key) and data-preset-base-url (the
// page's own route, e.g. {{ route('leads-report') }}) — rather than duplicating
// this logic per view.
//
// Delegated from document, for consistency with this file's other
// dropdown-closing patterns (e.g. the global-search dropdown above) — NOT
// because it's required to survive softRefresh: @push('topbar-right') content
// (which is where every one of these dropdowns lives) renders inside <header>,
// outside <main>, and softRefresh only ever replaces main.innerHTML.
const PRESET_STORAGE_PREFIX = 'filterPresets:';

function getPresets(key) {
    try {
        const parsed = JSON.parse(localStorage.getItem(PRESET_STORAGE_PREFIX + key) || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function setPresets(key, presets) {
    try {
        localStorage.setItem(PRESET_STORAGE_PREFIX + key, JSON.stringify(presets));
    } catch {
        // Storage unavailable/full (e.g. private browsing) — fail silently,
        // same spirit as showToast's "no container, do nothing" guard.
    }
}

// Same-name save overwrites in place (no duplicate-name confirmation) — the
// simplest reasonable behavior per this feature's scope.
function savePreset(key, name, query) {
    const presets = getPresets(key).filter((p) => p.name !== name);
    presets.push({ name, query });
    setPresets(key, presets);
}

function deletePreset(key, name) {
    setPresets(key, getPresets(key).filter((p) => p.name !== name));
}

function escapePresetHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

// Rebuilds a dropdown panel's contents from localStorage — called on open, and
// again after any save/delete so the list reflects the change immediately.
function renderPresetPanel(panel, key) {
    const presets = getPresets(key);

    const rows = presets.map((p) => `
        <div class="flex items-center" data-preset-row data-preset-name="${escapePresetHtml(p.name)}">
            <button type="button" data-preset-apply
                class="flex-1 min-w-0 text-left px-3 py-1.5 text-xs font-mono text-slate-600 dark:text-slate-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-yellow-700 dark:hover:text-yellow-400 transition-colors truncate cursor-pointer">
                ${escapePresetHtml(p.name)}
            </button>
            <button type="button" data-preset-delete aria-label="Delete preset ${escapePresetHtml(p.name)}"
                class="px-2 text-slate-300 dark:text-slate-600 hover:text-red-500 dark:hover:text-red-400 transition-colors cursor-pointer shrink-0">&times;</button>
        </div>
    `).join('');

    panel.innerHTML = `
        ${presets.length
            ? '<div class="px-3 pt-2 pb-1 text-[10px] font-mono font-semibold tracking-widest text-slate-400 uppercase">Saved Views</div>'
            : '<p class="px-3 pt-2 pb-1 text-xs font-mono text-slate-400">No saved views yet</p>'}
        ${rows}
        <div class="border-t border-slate-100 dark:border-slate-700 mt-1 pt-1">
            <button type="button" data-preset-save
                class="w-full text-left px-3 py-1.5 text-xs font-mono text-yellow-700 dark:text-yellow-400 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors cursor-pointer">
                + Save current filters...
            </button>
        </div>
    `;
}

// Click-outside-to-close lives in THIS same listener, as the final fallthrough
// branch below, rather than a second document 'click' listener — the two used
// to be separate, but save/delete both re-render the panel via
// panel.innerHTML (see renderPresetPanel), which detaches the just-clicked
// button from the DOM. A second listener running afterward on that same click
// event would call e.target.closest('[data-preset-widget]') on the
// now-detached button, get null back, and immediately hide the panel it had
// just re-rendered open — so every save/delete looked like it silently did
// nothing. Keeping every branch (including the outside-click check) as
// mutually-exclusive early-return checks on ONE listener means a save/delete
// click can never also fall through to the "outside click" branch within the
// same dispatch.
document.addEventListener('click', async (e) => {
    const trigger = e.target.closest('[data-preset-trigger]');
    if (trigger) {
        const panel = trigger.closest('[data-preset-widget]')?.querySelector('[data-preset-panel]');
        if (!panel) return;
        const isHidden = panel.classList.contains('hidden');
        // Only one preset panel is ever on a page, but close-all-first keeps
        // this correct even if that ever changes (same guard the date-picker
        // pattern doesn't need, since each of its instances owns its own panel).
        document.querySelectorAll('[data-preset-panel]').forEach((p) => p.classList.add('hidden'));
        document.querySelectorAll('[data-preset-trigger]').forEach((t) => t.setAttribute('aria-expanded', 'false'));
        if (isHidden) {
            renderPresetPanel(panel, trigger.dataset.presetKey);
            panel.classList.remove('hidden');
            trigger.setAttribute('aria-expanded', 'true');
        }
        return;
    }

    const applyBtn = e.target.closest('[data-preset-apply]');
    if (applyBtn) {
        const widget = applyBtn.closest('[data-preset-widget]');
        const widgetTrigger = widget?.querySelector('[data-preset-trigger]');
        const row = applyBtn.closest('[data-preset-row]');
        if (!widgetTrigger || !row) return;
        const preset = getPresets(widgetTrigger.dataset.presetKey).find((p) => p.name === row.dataset.presetName);
        // Plain navigation, not softRefresh: applying a preset can change the
        // team (a different route segment's worth of data entirely, e.g.
        // leads-report-all vs leads-report), which softRefresh's in-place
        // <main> swap isn't guaranteed to render correctly for — a full
        // navigation is the same trade every other filter control on this
        // page already makes when its target view differs from the current one.
        if (preset) window.location.href = widgetTrigger.dataset.presetBaseUrl + preset.query;
        return;
    }

    const deleteBtn = e.target.closest('[data-preset-delete]');
    if (deleteBtn) {
        const widget = deleteBtn.closest('[data-preset-widget]');
        const widgetTrigger = widget?.querySelector('[data-preset-trigger]');
        const panel = widget?.querySelector('[data-preset-panel]');
        const row = deleteBtn.closest('[data-preset-row]');
        if (!widgetTrigger || !panel || !row) return;
        const name = row.dataset.presetName;
        if (!await window.showConfirm(`Delete saved view "${name}"?`, { confirmText: 'Delete' })) return;
        deletePreset(widgetTrigger.dataset.presetKey, name);
        renderPresetPanel(panel, widgetTrigger.dataset.presetKey);
        return;
    }

    const saveBtn = e.target.closest('[data-preset-save]');
    if (saveBtn) {
        const widget = saveBtn.closest('[data-preset-widget]');
        const widgetTrigger = widget?.querySelector('[data-preset-trigger]');
        const panel = widget?.querySelector('[data-preset-panel]');
        if (!widgetTrigger || !panel) return;
        const name = (prompt('Name this preset:') || '').trim();
        if (!name) return;
        savePreset(widgetTrigger.dataset.presetKey, name, window.location.search);
        renderPresetPanel(panel, widgetTrigger.dataset.presetKey);
        return;
    }

    // Outside click: only reached if none of the branches above matched —
    // i.e. the click wasn't on a trigger/apply/delete/save control (whether
    // or not that control is still attached to the DOM by this point).
    if (e.target.closest('[data-preset-widget]')) return;
    document.querySelectorAll('[data-preset-panel]:not(.hidden)').forEach((p) => p.classList.add('hidden'));
    document.querySelectorAll('[data-preset-trigger]').forEach((t) => t.setAttribute('aria-expanded', 'false'));
});

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('[data-preset-panel]:not(.hidden)').forEach((p) => p.classList.add('hidden'));
    document.querySelectorAll('[data-preset-trigger]').forEach((t) => t.setAttribute('aria-expanded', 'false'));
});

// ─── Horizontal-scroll shadow for wide tables ─────────────────────────────────
// UI/UX review finding: every [data-scroll-shadow] container (Leads Report's
// 13+-column hourly tables, TSA Performance's pivot table, etc.) scrolls
// sideways with no visible hint that there's more off-screen — a first-time
// viewer has no reason to suspect a column is cut off, on desktop or mobile.
//
// The overlay divs are NOT children of the scrolling element itself — an
// absolutely-positioned child of an overflow:auto box is still part of that
// box's own scrollable content and drifts sideways with everything else,
// which would defeat the point. Each [data-scroll-shadow] element is wrapped
// in an extra position:relative parent that does NOT scroll; the shadows are
// absolutely positioned against THAT wrapper instead, so they stay pinned to
// the visible left/right edge regardless of the inner scroll offset.
//
// The wrapper copies el's COMPUTED flex/grid sizing (not its classes) so it
// takes over el's spot in whatever layout it was sitting in (e.g. Leads
// Report's table-beside-a-pie-chart row needs flex-1/min-w-0 preserved) —
// copying classes instead would double up el's own card styling (border/
// shadow/background) wherever the scrollable div IS the visible card, like
// TSA Performance's pivot table, not just a layout participant.
function initScrollShadows() {
    document.querySelectorAll('[data-scroll-shadow]').forEach((el) => {
        if (el.dataset.scrollShadowReady) {
            updateScrollShadow(el);
            return;
        }
        el.dataset.scrollShadowReady = '1';

        const computed = getComputedStyle(el);
        const wrapper = document.createElement('div');
        wrapper.style.position  = 'relative';
        wrapper.style.overflow  = 'visible';
        wrapper.style.flex      = computed.flex;
        wrapper.style.minWidth  = computed.minWidth;
        wrapper.style.maxWidth  = computed.maxWidth;
        wrapper.style.width     = computed.display === 'block' ? '' : computed.width;
        wrapper.style.alignSelf = computed.alignSelf;
        el.parentNode.insertBefore(wrapper, el);
        wrapper.appendChild(el);

        const make = (side) => {
            const div = document.createElement('div');
            div.style.cssText = `position:absolute; top:0; bottom:0; ${side}:0; width:32px;
                pointer-events:none; z-index:20; opacity:0; transition:opacity 150ms ease;
                background:linear-gradient(to ${side === 'left' ? 'right' : 'left'}, rgba(0,0,0,0.28), transparent);`;
            wrapper.appendChild(div);
            return div;
        };
        el.__scrollShadowLeft  = make('left');
        el.__scrollShadowRight = make('right');
        updateScrollShadow(el);
    });
}

function updateScrollShadow(el) {
    const canLeft  = el.scrollLeft > 0;
    const canRight = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
    if (el.__scrollShadowLeft)  el.__scrollShadowLeft.style.opacity  = canLeft  ? '1' : '0';
    if (el.__scrollShadowRight) el.__scrollShadowRight.style.opacity = canRight ? '1' : '0';
}

// scroll doesn't bubble, so this has to be delegated in the capture phase to
// catch it from any descendant scrolling container.
document.addEventListener('scroll', (e) => {
    if (e.target.nodeType === 1 && e.target.hasAttribute('data-scroll-shadow')) {
        updateScrollShadow(e.target);
    }
}, true);

window.addEventListener('resize', () => {
    document.querySelectorAll('[data-scroll-shadow]').forEach(updateScrollShadow);
});

document.addEventListener('DOMContentLoaded', initScrollShadows);

// softRefresh only swaps <main>'s content — wrap it so any newly-inserted
// scrollable table gets its overlay + initial state without a separate
// per-page re-init call (same reasoning as [data-rerun] above, but this
// needs to run for every softRefresh, not just opt-in pages).
const __baseSoftRefresh = window.softRefresh;
window.softRefresh = async function (...args) {
    const result = await __baseSoftRefresh.apply(this, args);
    initScrollShadows();
    return result;
};
