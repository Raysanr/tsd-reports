@extends('layouts.archive')
@section('title', 'Org Chart')
@section('subtitle', 'Builds itself from Teams and People — click any card to change it')
@php extract(\App\Support\ArchiveUi::classes()); @endphp

@push('head')
<style>
/* Self-building tree (2026-11-20): nested lists, connector lines from CSS. */
#orgTree { --org-line: #d6cfbd; --gap: 26px; display: inline-block; min-width: 100%; padding: 28px 32px 48px; }
.dark #orgTree { --org-line: #475569; }
#orgTree ul { display: flex; justify-content: center; position: relative; padding-top: var(--gap); margin: 0; }
#orgTree li { list-style: none; position: relative; padding: var(--gap) 8px 0; display: flex; flex-direction: column; align-items: center; }
#orgTree li::before, #orgTree li::after { content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: var(--gap); border-top: 2px solid var(--org-line); }
#orgTree li::after { right: auto; left: 50%; border-left: 2px solid var(--org-line); }
#orgTree li:only-child::before, #orgTree li:only-child::after { display: none; }
#orgTree li:only-child { padding-top: 0; }
#orgTree li:first-child::before, #orgTree li:last-child::after { border: 0 none; }
#orgTree li:last-child::before { border-right: 2px solid var(--org-line); border-radius: 0 10px 0 0; }
#orgTree li:first-child::after { border-radius: 10px 0 0 0; }
#orgTree ul ul::before { content: ''; position: absolute; top: 0; left: 50%; height: var(--gap); border-left: 2px solid var(--org-line); }
/* top level: separate trees side by side, no connectors */
#orgTree > ul { padding-top: 0; gap: 40px; align-items: flex-start; }
#orgTree > ul > li { padding-top: 0; }
#orgTree > ul > li::before, #orgTree > ul > li::after { display: none; }

.org-c { display: block; width: 13.5rem; border-radius: 1rem; padding: 14px; background: #fff; text-align: left; cursor: pointer;
    box-shadow: 0 1px 2px rgba(32,28,18,.05), 0 10px 24px -16px rgba(133,77,14,.35); outline: 1px solid rgba(231,223,201,.9); transition: transform .12s ease, box-shadow .12s ease; }
.dark .org-c { background: #0f172a; outline-color: #1e293b; }
.org-c:hover { transform: translateY(-2px); box-shadow: 0 16px 30px -16px rgba(133,77,14,.45); outline-color: var(--color-yellow-500, #eab308); }
.org-c:focus-visible { outline: 3px solid var(--color-yellow-600, #CA8A04); outline-offset: 2px; }
.org-c-head { background: linear-gradient(#fffbeb, #fff); }
.dark .org-c-head { background: #111827; }
.org-group { display: flex; gap: 12px; padding: 10px; border-radius: 1.25rem; outline: 2px dashed var(--org-line); outline-offset: 0; }
.org-toggle { position: relative; z-index: 1; margin-top: 6px; border-radius: 999px; padding: 1px 10px; font-size: 11px; font-weight: 600; background: #fff; color: #64748b; outline: 1px solid var(--org-line); cursor: pointer; }
.dark .org-toggle { background: #0f172a; color: #94a3b8; }
.org-toggle:hover { color: #0f172a; outline-color: var(--color-yellow-500, #eab308); }
.org-toggle-more { display: none; }
li.is-collapsed > .org-toggle .org-toggle-less { display: none; }
li.is-collapsed > .org-toggle .org-toggle-more { display: inline; }
li.is-collapsed > ul { display: none; }
#orgPanel:fullscreen { overflow: auto; border-radius: 0; }
#orgPanel:fullscreen #orgViewport { height: calc(100vh - 60px) !important; }
.org-c-compact { width: 13.5rem; padding: 8px 10px; border-radius: .75rem; border-top-width: 0 !important; border-left-width: 4px; border-left-style: solid; }
.org-cell.is-found .org-c { outline: 3px solid var(--color-yellow-600, #CA8A04); outline-offset: 3px; }
.org-canvas { background-image: radial-gradient(circle, rgba(148,163,184,.28) 1px, transparent 1px); background-size: 20px 20px; }
@media print {
    .org-canvas { background: none; height: auto !important; overflow: visible !important; }
    .org-c { box-shadow: none; }
}
</style>
@endpush

@push('topbar-right')
<div class="no-print flex items-center gap-1 rounded-lg ring-1 ring-line dark:ring-slate-700 bg-white dark:bg-slate-800 p-1">
    <button type="button" data-zoom="out" class="rounded-md p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" aria-label="Zoom out"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M5 12h14"/></svg></button>
    <button type="button" data-zoom="reset" id="zoomLabel" class="min-w-14 rounded-md px-1.5 py-1 text-xs font-semibold tabular-nums hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" title="Reset to 100%">100%</button>
    <button type="button" data-zoom="in" class="rounded-md p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" aria-label="Zoom in"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg></button>
    <span class="mx-0.5 h-5 w-px bg-line dark:bg-slate-700"></span>
    <button type="button" data-zoom="fit" class="rounded-md px-2 py-1 text-xs font-semibold hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" title="Fit the whole chart">Fit</button>
</div>
<button type="button" data-modal-open="deptModal" data-title="New team" data-action="{{ route('archive.departments.store') }}" data-method="POST" data-fill='{"color":"sky"}' class="no-print {{ $btnGhost }}">+ New team</button>
<button type="button" data-modal-open="employeeModal" data-title="Add person" data-action="{{ route('archive.people.store') }}" data-method="POST" data-fill='{"status":"active"}' class="no-print {{ $btnPrimary }}">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
    Add person
</button>
@endpush

@section('content')
@include('archive.partials.setup-guide')

<div id="orgPanel" class="rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel overflow-hidden">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 border-b border-line dark:border-slate-800 px-5 py-3 text-sm">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-64">
                <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <input type="search" id="orgFind" list="orgFindList" placeholder="Find a person or team…" class="{{ $input }} pl-9" autocomplete="off">
                <datalist id="orgFindList">
                    @foreach($teams as $t)<option value="{{ $t->name }}">@endforeach
                    @foreach($people as $p)<option value="{{ $p->full_name }}">@endforeach
                </datalist>
            </div>
            <span class="text-ink-muted dark:text-slate-400"><strong class="text-ink dark:text-slate-100 tabular-nums">{{ $teams->count() }}</strong> {{ \Illuminate\Support\Str::plural('team', $teams->count()) }} · <strong class="text-ink dark:text-slate-100 tabular-nums">{{ $people->count() }}</strong> people</span>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted dark:text-slate-400">
            <span><span class="font-semibold text-ink dark:text-slate-200">Click a card</span> to add, move, or pick a head</span>
            <button type="button" data-collapse-all="1" class="font-semibold hover:text-primary cursor-pointer" title="Show only the teams and heads">Collapse all</button>
            <button type="button" data-collapse-all="0" class="font-semibold hover:text-primary cursor-pointer">Expand all</button>
            <button type="button" id="orgFullscreen" class="font-semibold hover:text-primary cursor-pointer">Full screen</button>
            <button type="button" onclick="window.print()" class="font-semibold hover:text-primary cursor-pointer">Print / PDF</button>
            <a href="{{ route('archive.org-chart', ['mode' => 'arrange']) }}" class="font-semibold hover:text-primary" title="The old canvas: drag cards and draw extra lines by hand">Arrange by hand (advanced)</a>
        </div>
    </div>

    @if(empty($roots))
        @include('archive.partials.empty', ['title' => 'Nothing to show yet', 'text' => 'Create a team and add people to it — the chart appears here by itself.'])
    @else
    <div id="orgViewport" class="org-canvas relative overflow-auto cursor-grab" style="height: calc(100vh - 260px); min-height: 480px;">
        <div id="orgTree">
            <ul>
                @foreach($roots as $node)
                    @include('archive.partials.org-tree-node', ['node' => $node])
                @endforeach
            </ul>
        </div>
    </div>
    @endif
</div>

@if($unassigned->isNotEmpty())
<section class="mt-6 rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="font-semibold">Not in a team yet <span class="ml-1 text-sm font-normal text-ink-muted">({{ $unassigned->count() }})</span></h3>
        <p class="text-xs text-ink-muted dark:text-slate-400">Click a person and choose “Move to…”, or open a team card and use “Add people to this team”.</p>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach($unassigned as $p)
            @include('archive.partials.org-tree-card', ['node' => ['type' => 'person', 'model' => $p, 'isHead' => false, 'headOfTeam' => null, 'children' => []], 'compact' => true])
        @endforeach
    </div>
</section>
@endif

{{-- Action menu for a clicked card; filled from that card's <template>. --}}
<dialog id="nodeActions" class="{{ $modal }} max-w-sm">
    <div class="flex items-start justify-between gap-3 border-b border-line dark:border-slate-800 px-5 py-4">
        <div class="min-w-0">
            <h3 class="font-semibold truncate" data-node-title></h3>
            <p class="text-xs text-ink-muted dark:text-slate-400 truncate" data-node-sub></p>
        </div>
        <button type="button" data-modal-close class="rounded-lg p-1.5 text-ink-muted hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" aria-label="Close"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="p-2" data-node-body></div>
</dialog>

@include('archive.partials.org-tools')
@include('archive.partials.team-modal')
@include('archive.partials.employee-modal')
@endsection

@push('scripts')
<script>
(function () {
    // ---- collapse / expand branches (2026-11-21), remembered per browser
    const COLLAPSE_KEY = 'archive.orgChart.collapsed';
    const readCollapsed = () => { try { return new Set(JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '[]')); } catch (_) { return new Set(); } };
    const saveCollapsed = () => {
        const keys = [...document.querySelectorAll('#orgTree li.is-collapsed')].map((li) => li.dataset.branch);
        try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(keys)); } catch (_) {}
    };
    const setCollapsed = (li, on) => {
        li.classList.toggle('is-collapsed', on);
        li.querySelector(':scope > [data-collapse]')?.setAttribute('aria-expanded', on ? 'false' : 'true');
    };
    readCollapsed().forEach((k) => { const li = document.querySelector(`#orgTree li[data-branch="${CSS.escape(k)}"]`); if (li) setCollapsed(li, true); });
    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-collapse]');
        if (t) { e.stopPropagation(); const li = t.closest('li'); setCollapsed(li, !li.classList.contains('is-collapsed')); saveCollapsed(); return; }
        const all = e.target.closest('[data-collapse-all]');
        if (all) {
            const on = all.dataset.collapseAll === '1';
            // collapse all = keep every team and head visible, fold only the
            // branches that hold nothing but members
            document.querySelectorAll('#orgTree li[data-branch]').forEach((li) => {
                if (!li.querySelector(':scope > [data-collapse]')) return;
                const holdsMore = li.querySelector(':scope > ul > li[data-branch^="t"], :scope > ul > li[data-branch^="g"], :scope > ul > li[data-head]');
                setCollapsed(li, on && !holdsMore);
            });
            saveCollapsed();
        }
    }, true);
    document.getElementById('orgFullscreen')?.addEventListener('click', () => {
        const panel = document.getElementById('orgPanel');
        document.fullscreenElement ? document.exitFullscreen() : panel.requestFullscreen?.();
    });

    // ---- card click → action menu
    const menu = document.getElementById('nodeActions');
    document.addEventListener('click', (e) => {
        const card = e.target.closest('[data-node]');
        if (!card || suppressClick) return;
        menu.querySelector('[data-node-title]').textContent = card.dataset.title;
        menu.querySelector('[data-node-sub]').textContent = card.dataset.sub;
        menu.querySelector('[data-node-body]').replaceChildren(card.parentElement.querySelector('template').content.cloneNode(true));
        menu.showModal();
    });
    // an action that opens another dialog closes the menu first
    menu.addEventListener('click', (e) => { if (e.target.closest('[data-modal-open]')) menu.close(); }, true);
    menu.addEventListener('submit', (e) => { if (!e.target.dataset.confirm) menu.close(); }, true);

    const viewport = document.getElementById('orgViewport');
    const tree = document.getElementById('orgTree');
    let suppressClick = false;
    if (!viewport) return;

    // ---- zoom (CSS zoom keeps scroll sizes right)
    let scale = 1;
    const label = document.getElementById('zoomLabel');
    try { scale = +(localStorage.getItem('archiveOrgTreeZoom') || 1) || 1; } catch (e) {}
    function zoomTo(s) {
        scale = Math.min(1.6, Math.max(0.3, +s.toFixed(2)));
        tree.style.zoom = scale;
        label.textContent = Math.round(scale * 100) + '%';
        try { localStorage.setItem('archiveOrgTreeZoom', scale); } catch (e) {}
    }
    function fit() {
        tree.style.zoom = 1;
        const s = Math.min(viewport.clientWidth / tree.scrollWidth, viewport.clientHeight / tree.scrollHeight, 1);
        zoomTo(s); viewport.scrollLeft = 0; viewport.scrollTop = 0;
    }
    document.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => {
        const a = b.dataset.zoom;
        if (a === 'fit') fit(); else zoomTo(a === 'reset' ? 1 : scale * (a === 'in' ? 1.2 : 1 / 1.2));
    }));
    viewport.addEventListener('wheel', (e) => {
        if (!e.ctrlKey && !e.metaKey) return;
        e.preventDefault(); zoomTo(scale * Math.exp(-e.deltaY * 0.0015));
    }, { passive: false });
    zoomTo(scale);
    // start centred on the top of the chart
    viewport.scrollLeft = Math.max(0, (viewport.scrollWidth - viewport.clientWidth) / 2);

    // ---- drag the background to pan
    let pan = null;
    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || e.target.closest('[data-node], a, button, input')) return;
        pan = { x: e.clientX, y: e.clientY, l: viewport.scrollLeft, t: viewport.scrollTop, moved: false };
    });
    window.addEventListener('pointermove', (e) => {
        if (!pan) return;
        if (Math.hypot(e.clientX - pan.x, e.clientY - pan.y) > 4) pan.moved = true;
        viewport.scrollLeft = pan.l - (e.clientX - pan.x); viewport.scrollTop = pan.t - (e.clientY - pan.y);
    });
    window.addEventListener('pointerup', () => { if (pan?.moved) { suppressClick = true; setTimeout(() => (suppressClick = false), 0); } pan = null; });

    // ---- find a person/team
    const find = document.getElementById('orgFind');
    function locate() {
        const q = find.value.trim().toLowerCase();
        if (!q) return;
        const cards = [...document.querySelectorAll('[data-node]')];
        const hit = cards.find((c) => c.dataset.search === q) || cards.find((c) => c.dataset.search.includes(q));
        if (!hit) { window.showToast?.('Nobody on the chart matches “' + find.value + '”.', 'error'); return; }
        document.querySelectorAll('.org-cell.is-found').forEach((c) => c.classList.remove('is-found'));
        hit.parentElement.classList.add('is-found');
        hit.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        setTimeout(() => hit.parentElement.classList.remove('is-found'), 2500);
    }
    find.addEventListener('change', locate);
    find.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); locate(); } });
})();
</script>
@endpush
