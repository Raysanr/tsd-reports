{{-- Faster-feeling page changes, every module (2026-11-16). Self-contained
     (inline, no Vite bundle) so it works the same on app.js pages, calls.js
     pages and the Hub pages that load neither. Include once in <head>.
     1. Clicking a link to another page shows a loading skeleton over the
        content area right away; sidebar and header stay as they are.
     2. Pages swap with a short fade instead of a white flash (cross-document
        view transitions; browsers without it just navigate normally).
     3. Pressing the mouse button on a link prefetches that page ("conservative": on press, not hover — 2026-11-16, keeps Railway cost down), so it opens
        a little sooner. Links with a query string, downloads/exports and auth links
        are never prefetched (a prefetch would save a filter or start a file).
     Opt out per link with data-no-nav-loading / data-no-prefetch. --}}
<style>
    @view-transition { navigation: auto; }
    ::view-transition-old(root), ::view-transition-new(root) { animation-duration: .16s; }
    .pn-overlay { position: fixed; z-index: 60; overflow: hidden; padding: 24px; pointer-events: none;
        background: #f8fafc; opacity: 0; transition: opacity .15s ease-out; }
    .pn-overlay.pn-show { opacity: 1; }
    html.dark .pn-overlay { background: #020617; }
    .pn-bar { position: absolute; top: 0; left: 0; height: 3px; width: 30%; border-radius: 0 3px 3px 0;
        background: var(--color-primary, #CA8A04); animation: pn-bar 1.1s ease-in-out infinite; }
    @keyframes pn-bar { 0% { left: -30%; } 100% { left: 100%; } }
    .pn-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .pn-card, .pn-block { border-radius: 12px; background: rgba(148, 163, 184, .22); animation: pn-pulse 1.2s ease-in-out infinite; }
    .pn-card { height: 92px; }
    .pn-block { height: 260px; margin-bottom: 16px; }
    .pn-block.pn-short { height: 140px; }
    html.dark .pn-card, html.dark .pn-block { background: rgba(71, 85, 105, .35); }
    @keyframes pn-pulse { 50% { opacity: .45; } }
    @media (prefers-reduced-motion: reduce) {
        .pn-bar, .pn-card, .pn-block { animation: none; }
        ::view-transition-old(root), ::view-transition-new(root) { animation: none; }
    }
</style>
<script type="speculationrules">
{
    "prefetch": [{
        "where": { "and": [
            { "href_matches": "/*" },
            { "not": { "href_matches": "/logout" } },
            { "not": { "href_matches": "/auth/*" } },
            { "not": { "href_matches": "/cron/*" } },
            { "not": { "href_matches": "*export*" } },
            { "not": { "href_matches": "*download*" } },
            { "not": { "href_matches": "*stream*" } },
            { "not": { "selector_matches": "a[href*='?'], a[download], a[target='_blank'], [data-no-prefetch], [data-no-prefetch] a" } }
        ] },
        "eagerness": "conservative"
    }]
}
</script>
<script>
(function () {
    if (window.__pageNavLoading) return;
    window.__pageNavLoading = true;

    var SKIP = /\/(logout|auth|cron)(\/|$)|export|download|stream|\.(csv|xlsx?|pdf|png|zip|json)$/i;
    var overlay = null, safety = null, pending = null;

    function hide() {
        clearTimeout(safety);
        clearTimeout(pending);
        if (overlay) { overlay.remove(); overlay = null; }
    }

    // The page's own background behind the content, so the old page is fully
    // covered (no see-through mix of old table and skeleton).
    function pageBackground(el) {
        for (; el && el.nodeType === 1; el = el.parentElement) {
            var bg = getComputedStyle(el).backgroundColor;
            if (bg && bg !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(bg)) return bg;
        }
        return '';
    }

    // Only for a page that takes a moment: a fast one just switches, no flicker.
    function show() {
        hide();
        pending = setTimeout(reveal, 150);
        // A link that turned out to be a file download never leaves the page.
        // 2 min, not 20s (2026-11-18): slow pages like Data Management on
        // Railway can take over 20s, and the skeleton vanished mid-load.
        // File links are skipped up front (SKIP), so this is a last resort.
        safety = setTimeout(hide, 120000);
    }

    function reveal() {
        var target = document.querySelector('main') || document.body;
        var r = target.getBoundingClientRect();
        var top = Math.max(r.top, 0), left = Math.max(r.left, 0);
        var width = Math.min(r.right, window.innerWidth) - left, height = Math.min(r.bottom, window.innerHeight) - top;
        if (width < 80 || height < 80) { top = 0; left = 0; width = window.innerWidth; height = window.innerHeight; }

        overlay = document.createElement('div');
        overlay.className = 'pn-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-label', 'Loading page');
        overlay.style.cssText = 'top:' + top + 'px;left:' + left + 'px;width:' + width + 'px;height:' + height + 'px;';
        var bg = pageBackground(target);
        if (bg) overlay.style.background = bg;
        overlay.innerHTML = '<div class="pn-bar"></div><div class="pn-grid">'
            + '<div class="pn-card"></div><div class="pn-card"></div><div class="pn-card"></div><div class="pn-card"></div></div>'
            + '<div class="pn-block"></div><div class="pn-block pn-short"></div>';
        document.body.appendChild(overlay);
        requestAnimationFrame(function () { overlay && overlay.classList.add('pn-show'); });
    }

    document.addEventListener('click', function (e) {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.hasAttribute('download') || a.closest('[data-no-nav-loading]')) return;
        var raw = a.getAttribute('href') || '';
        if (raw === '' || raw.charAt(0) === '#' || /^javascript:/i.test(raw)) return;
        if (a.target && a.target !== '_self') return;
        var url;
        try { url = new URL(a.href, location.href); } catch (err) { return; }
        if (url.origin !== location.origin || !/^https?:$/.test(url.protocol) || SKIP.test(url.pathname)) return;
        // Same page, only the #anchor differs: no page load happens.
        if (url.hash && url.pathname === location.pathname && url.search === location.search) return;
        // Let the page's own handlers run first; skip if one took over the click.
        setTimeout(function () { if (!e.defaultPrevented) show(); }, 0);
    });

    // GET filter forms (team tabs, date Apply…) that reload the page — e.g.
    // Data Management's ALL / PEAK.CO / M&J / MLC. Pages that already swap
    // filters in place (app.js softRefresh) cancel the submit, so they're
    // skipped. The clicked filter button turns active at once.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || (form.method || 'get').toLowerCase() !== 'get') return;
        if (form.target && form.target !== '_self') return;
        if (form.closest('[data-no-nav-loading]') || SKIP.test(form.action || '')) return;
        var btn = e.submitter;
        setTimeout(function () {
            if (e.defaultPrevented) return;
            if (btn && btn.hasAttribute('data-filter-btn')) {
                form.querySelectorAll('[data-filter-btn][name="' + btn.name + '"]').forEach(function (b) {
                    var on = b === btn;
                    ['bg-primary', 'text-white'].forEach(function (c) { b.classList.toggle(c, on); });
                    ['bg-white', 'text-slate-500'].forEach(function (c) { b.classList.toggle(c, !on); });
                });
            }
            show();
        }, 0);
    });

    // Back/forward restores a cached page with the skeleton still on it.
    window.addEventListener('pageshow', hide);
})();
</script>
