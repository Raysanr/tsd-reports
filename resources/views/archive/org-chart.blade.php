@extends('layouts.archive')
@section('title', 'Org Chart')
@section('subtitle', $view === 'people' ? 'Who reports to whom — drag cards anywhere, draw lines to connect' : 'Team structure — drag cards anywhere, draw lines to connect')

@push('head')
<style>
/* Freeform canvas: cards are absolutely positioned on #orgStage, lines are
   drawn in an SVG layer underneath them. The stage is scaled for zoom. */
#orgStage { position: relative; transform-origin: 0 0; }
#orgEdges { position: absolute; inset: 0; overflow: visible; pointer-events: none; }
#orgEdges .edge { fill: none; stroke: var(--org-line); stroke-width: 2; }
#orgEdges .edge-hit { fill: none; stroke: transparent; stroke-width: 16; pointer-events: stroke; cursor: pointer; }
#orgEdges .edge-hit:hover + .edge, #orgEdges .edge.is-selected { stroke: #CA8A04; stroke-width: 3; }
#orgEdges .edge-temp { fill: none; stroke: #CA8A04; stroke-width: 2.5; stroke-dasharray: 6 5; }
#orgStage { --org-line: #a8a29e; }
.dark #orgStage { --org-line: #475569; }

.org-node { position: absolute; width: 15rem; display: flex; flex-direction: column; align-items: center; cursor: grab; touch-action: none; user-select: none; z-index: 1; }
.org-node.is-dragging { cursor: grabbing; z-index: 20; }
.org-node.is-dragging .org-card { box-shadow: 0 24px 48px -16px rgba(32,28,18,.45); }
.org-avatar { position: relative; z-index: 1; border-radius: 9999px; background: #fff; padding: 4px; --tw-ring-shadow: 0 0 0 4px var(--tw-ring-color); box-shadow: var(--tw-ring-shadow), 0 6px 16px -6px rgba(32,28,18,.35); }
.dark .org-avatar { background: #0f172a; }
.org-card { margin-top: -30px; padding: 38px 16px 16px; width: 100%; border-radius: 1rem; border-top-width: 10px; text-align: center; box-shadow: 0 1px 2px rgba(32,28,18,.05), 0 14px 28px -18px rgba(133,77,14,.35); outline: 1px solid rgba(231,223,201,.9); transition: box-shadow .15s ease; }
.dark .org-card { outline-color: #1e293b; }
.org-node.is-target .org-card { outline: 3px solid #CA8A04; outline-offset: 3px; }
.org-node.is-invalid { opacity: .4; }

/* Connector dot at the bottom of every card. */
.org-handle { position: absolute; left: 50%; bottom: -9px; width: 18px; height: 18px; margin-left: -9px; border-radius: 9999px; background: #fff; border: 2px solid #CA8A04; cursor: crosshair; opacity: 0; transition: opacity .15s ease, transform .15s ease; z-index: 2; }
.org-node:hover .org-handle, body.org-connecting .org-handle { opacity: 1; }
.org-handle:hover { transform: scale(1.25); background: #CA8A04; }
.org-card { position: relative; }
.org-handle-side { bottom: auto; top: 50%; margin-left: 0; margin-top: -9px; }
.org-handle-side[data-side="left"] { left: -9px; }
.org-handle-side[data-side="right"] { left: auto; right: -9px; }
.dark .org-handle { background: #0f172a; }

.org-canvas { background-image: radial-gradient(circle, rgba(148,163,184,.3) 1px, transparent 1px); background-size: 20px 20px; }
@media print {
    .org-canvas { background: none; height: auto !important; overflow: visible !important; }
    .org-handle { display: none; }
}
</style>
@endpush

@push('topbar-right')
<div class="no-print flex items-center gap-1 rounded-lg ring-1 ring-line dark:ring-slate-700 bg-white dark:bg-slate-800 p-1">
    <button type="button" data-zoom="out" class="rounded-md p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" aria-label="Zoom out" title="Zoom out (−)"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M5 12h14"/></svg></button>
    <button type="button" data-zoom="reset" id="zoomLabel" class="min-w-14 rounded-md px-1.5 py-1 text-xs font-semibold tabular-nums hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" title="Reset to 100% (0)">100%</button>
    <button type="button" data-zoom="in" class="rounded-md p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" aria-label="Zoom in" title="Zoom in (+)"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg></button>
    <span class="mx-0.5 h-5 w-px bg-line dark:bg-slate-700"></span>
    <button type="button" data-zoom="fit" class="rounded-md px-2 py-1 text-xs font-semibold hover:bg-slate-100 dark:hover:bg-slate-700 cursor-pointer" title="Fit the whole chart">Fit</button>
</div>
<form method="POST" action="{{ route('archive.org-chart.reset') }}" class="no-print" data-confirm="Auto-arrange every card into a neat tree? Your manual positions for this view are cleared (connections stay).">
    @csrf <input type="hidden" name="view" value="{{ $view }}">
    <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-line dark:ring-slate-700 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">Auto-arrange</button>
</form>
<button type="button" onclick="window.print()" class="no-print inline-flex items-center gap-2 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-dark cursor-pointer">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
    Export PDF
</button>
@endpush

@section('content')
<div class="rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel overflow-hidden">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 border-b border-line dark:border-slate-800 px-5 py-3 text-sm">
        <div class="flex flex-wrap items-center gap-4">
            <div class="inline-flex rounded-lg bg-slate-100 dark:bg-slate-800 p-1" role="tablist" aria-label="Chart type">
                @foreach(['departments' => 'Teams', 'people' => 'People'] as $k => $l)
                <a href="{{ route('archive.org-chart', ['view' => $k]) }}" role="tab" aria-selected="{{ $view === $k ? 'true' : 'false' }}"
                   class="rounded-md px-3 py-1.5 font-medium {{ $view === $k ? 'bg-white dark:bg-slate-700 shadow-sm text-ink dark:text-white' : 'text-ink-muted dark:text-slate-400 hover:text-ink' }}">{{ $l }}</a>
                @endforeach
            </div>
            <span class="text-ink-muted dark:text-slate-400"><strong class="text-ink dark:text-slate-100 tabular-nums">{{ $nodeCount }}</strong> {{ $view === 'people' ? 'people' : 'teams' }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-muted dark:text-slate-400">
            <span><span class="font-semibold text-ink dark:text-slate-200">Drag</span> a card to move it</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-full border-2 border-primary bg-white"></span><span class="font-semibold text-ink dark:text-slate-200">Bottom dot</span> → put a card under it</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-full border-2 border-primary bg-white"></span><span class="font-semibold text-ink dark:text-slate-200">Side dots</span> → link side-to-side (any card)</span>
            <span><span class="font-semibold text-ink dark:text-slate-200">Click a line</span> to remove it</span>
            <span><span class="font-semibold text-ink dark:text-slate-200">Ctrl/⌘ + scroll</span> to zoom</span>
        </div>
    </div>

    @if($nodes->isEmpty())
        @if($view === 'people')
            @include('archive.partials.empty', ['title' => 'No people yet', 'text' => 'Add people, then connect them to their manager here.'])
            <div class="pb-10 text-center"><a href="{{ route('archive.people') }}" class="inline-flex rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark">Add people</a></div>
        @else
            @include('archive.partials.empty', ['title' => 'No structure yet', 'text' => 'Create a few teams, then connect them here.'])
            <div class="pb-10 text-center"><a href="{{ route('archive.departments') }}" class="inline-flex rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark">Create a team</a></div>
        @endif
    @else
    <div id="orgViewport" class="org-canvas relative overflow-auto cursor-grab" style="height: calc(100vh - 230px); min-height: 480px;">
        <div id="orgStage">
            <svg id="orgEdges" xmlns="http://www.w3.org/2000/svg"></svg>
            @foreach($nodes as $node)
                @include('archive.partials.org-card', ['node' => $node, 'view' => $view])
            @endforeach
            <button type="button" id="edgeRemove" hidden class="no-print absolute z-30 -translate-x-1/2 -translate-y-1/2 inline-flex items-center gap-1 rounded-full bg-rose-600 px-2.5 py-1 text-xs font-semibold text-white shadow-lg hover:bg-rose-500 cursor-pointer">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                Remove line
            </button>
        </div>
    </div>
    @endif
</div>

@if($unassigned->isNotEmpty())
<section class="mt-6 rounded-2xl bg-white dark:bg-slate-900 ring-1 ring-line dark:ring-slate-800 shadow-panel p-5">
    <h3 class="font-semibold">Not in any team <span class="ml-1 text-sm font-normal text-ink-muted">({{ $unassigned->count() }})</span></h3>
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach($unassigned as $m)
        <a href="{{ route('archive.people.show', $m) }}" class="inline-flex items-center gap-2 rounded-full bg-slate-50 dark:bg-slate-800 py-1 pl-1 pr-3 text-sm ring-1 ring-inset ring-line dark:ring-slate-700 hover:ring-yellow-300">
            @include('archive.partials.avatar', ['name' => $m->full_name, 'size' => 'sm', 'photo' => $m->photoUrl()]){{ $m->full_name }}
        </a>
        @endforeach
    </div>
</section>
@endif
@endsection

@push('scripts')
<script>
(function () {
    const viewport = document.getElementById('orgViewport');
    const stage = document.getElementById('orgStage');
    if (!stage) return;
    const svg = document.getElementById('orgEdges');
    const removeBtn = document.getElementById('edgeRemove');
    const TYPE = @json($view === 'people' ? 'employee' : 'department');
    const URLS = { connect: @json(route('archive.org-chart.connect')), disconnect: @json(route('archive.org-chart.disconnect')), position: @json(route('archive.org-chart.position')) };
    // extra ("also reports to") lines; the main one lives in data-parent
    let extras = @json($extraLinks);
    // side-to-side links {a, b} — not part of the tree, can join any two cards
    let sides = @json($sideLinks);
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const NODE_W = 240, GAP_X = 40, GAP_Y = 90, PAD = 80;
    const SVGNS = 'http://www.w3.org/2000/svg';

    const nodes = new Map();
    stage.querySelectorAll('.org-node').forEach((el) => nodes.set(el.dataset.id, el));
    const parentOf = (id) => nodes.get(id)?.dataset.parent || '';
    const childrenOf = (id) => [...nodes.values()].filter((n) => n.dataset.parent === id).map((n) => n.dataset.id);
    const parentsOf = (id) => [parentOf(id), ...extras.filter((l) => l.child === id).map((l) => l.parent)].filter(Boolean);
    function ancestorsOf(id) {
        const seen = new Set([id]); const stack = [id];
        while (stack.length) parentsOf(stack.pop()).forEach((p) => { if (!seen.has(p)) { seen.add(p); stack.push(p); } });
        return seen;
    }

    // ---------- layout ----------
    // Tidy tree for every card without a saved position; saved ones stay put.
    function autoLayout() {
        const heights = new Map([...nodes].map(([id, el]) => [id, el.offsetHeight]));
        const roots = [...nodes.keys()].filter((id) => !parentOf(id) || !nodes.has(parentOf(id)));
        const widthOf = new Map();
        const measure = (id, seen = new Set()) => {
            if (seen.has(id)) return NODE_W; seen.add(id);
            const kids = childrenOf(id);
            const w = kids.length ? kids.reduce((s, k) => s + measure(k, seen), 0) + GAP_X * (kids.length - 1) : NODE_W;
            widthOf.set(id, Math.max(NODE_W, w));
            return widthOf.get(id);
        };
        const pos = new Map();
        const place = (id, left, top, seen = new Set()) => {
            if (seen.has(id)) return; seen.add(id);
            const w = widthOf.get(id);
            pos.set(id, { x: Math.round(left + (w - NODE_W) / 2), y: top });
            let x = left;
            childrenOf(id).forEach((k) => { place(k, x, top + heights.get(id) + GAP_Y, seen); x += widthOf.get(k) + GAP_X; });
        };
        let x = PAD;
        roots.forEach((r) => { measure(r); place(r, x, PAD); x += widthOf.get(r) + GAP_X * 2; });
        nodes.forEach((el, id) => {
            const p = el.dataset.x !== undefined ? { x: +el.dataset.x, y: +el.dataset.y } : (pos.get(id) || { x: PAD, y: PAD });
            setPos(el, p.x, p.y);
        });
    }
    function setPos(el, x, y) {
        el._x = Math.max(0, Math.round(x)); el._y = Math.max(0, Math.round(y));
        el.style.left = el._x + 'px'; el.style.top = el._y + 'px';
    }
    function fitStage() {
        let w = 0, h = 0;
        nodes.forEach((el) => { w = Math.max(w, el._x + el.offsetWidth); h = Math.max(h, el._y + el.offsetHeight); });
        stage.style.width = Math.max(w + PAD * 4, viewport.clientWidth / scale) + 'px';
        stage.style.height = Math.max(h + PAD * 4, viewport.clientHeight / scale) + 'px';
        svg.setAttribute('width', stage.style.width); svg.setAttribute('height', stage.style.height);
    }

    // ---------- edges ----------
    const anchorBottom = (el) => ({ x: el._x + el.offsetWidth / 2, y: el._y + el.offsetHeight });
    const anchorTop = (el) => ({ x: el._x + el.offsetWidth / 2, y: el._y });
    const midY = (el) => { const c = el.querySelector('.org-card'); return el._y + c.offsetTop + c.offsetHeight / 2; };
    const anchorSide = (el, side) => ({ x: side === 'left' ? el._x : el._x + el.offsetWidth, y: midY(el) });
    const curve = (a, b) => {
        const dy = Math.max(40, Math.abs(b.y - a.y) / 2);
        return `M ${a.x} ${a.y} C ${a.x} ${a.y + dy}, ${b.x} ${b.y - dy}, ${b.x} ${b.y}`;
    };
    // horizontal curve leaving `a` towards dirA (-1 left / +1 right) and entering `b` from dirB
    const sideCurve = (a, dirA, b, dirB) => {
        const dx = Math.max(40, Math.abs(b.x - a.x) / 2);
        return `M ${a.x} ${a.y} C ${a.x + dirA * dx} ${a.y}, ${b.x + dirB * dx} ${b.y}, ${b.x} ${b.y}`;
    };
    // facing sides of two cards: the one on the left connects from its right edge
    function sidePath(n1, n2) {
        const leftToRight = (n2._x + n2.offsetWidth / 2) >= (n1._x + n1.offsetWidth / 2);
        return leftToRight
            ? sideCurve(anchorSide(n1, 'right'), 1, anchorSide(n2, 'left'), -1)
            : sideCurve(anchorSide(n1, 'left'), -1, anchorSide(n2, 'right'), 1);
    }
    let selected = null; // an edge from edgeList()
    const keyOf = (e) => e.kind === 'side' ? `s:${[e.a, e.b].sort().join('-')}` : `u:${e.parent}>${e.child}`;
    function edgeList() {
        const list = [];
        nodes.forEach((el, id) => { if (nodes.has(parentOf(id))) list.push({ kind: 'main', parent: parentOf(id), child: id }); });
        extras.forEach((l) => { if (nodes.has(l.parent) && nodes.has(l.child)) list.push({ kind: 'extra', ...l }); });
        sides.forEach((l) => { if (nodes.has(l.a) && nodes.has(l.b)) list.push({ kind: 'side', a: l.a, b: l.b }); });
        return list;
    }
    const paths = new Map(); // key -> visible <path>, for placing the Remove button
    function drawEdges() {
        svg.querySelectorAll('g.e').forEach((g) => g.remove());
        paths.clear();
        edgeList().forEach((edge) => {
            const key = keyOf(edge);
            let d, titleText, end;
            if (edge.kind === 'side') {
                const n1 = nodes.get(edge.a), n2 = nodes.get(edge.b);
                d = sidePath(n1, n2);
                titleText = `${n1.dataset.name} ↔ ${n2.dataset.name} — click to remove`;
            } else {
                const p = nodes.get(edge.parent), c = nodes.get(edge.child);
                d = curve(anchorBottom(p), anchorTop(c));
                titleText = (edge.kind === 'extra' ? 'Also under ' : 'Under ') + p.dataset.name + ' — click to remove';
                end = anchorTop(c);
            }
            const g = document.createElementNS(SVGNS, 'g'); g.setAttribute('class', 'e');
            const hit = document.createElementNS(SVGNS, 'path'); hit.setAttribute('d', d); hit.setAttribute('class', 'edge-hit'); hit.dataset.key = key;
            const title = document.createElementNS(SVGNS, 'title'); title.textContent = titleText; hit.append(title);
            const line = document.createElementNS(SVGNS, 'path'); line.setAttribute('d', d);
            line.setAttribute('class', 'edge' + (selected && keyOf(selected) === key ? ' is-selected' : ''));
            g.append(hit, line);
            if (end) {
                const dot = document.createElementNS(SVGNS, 'circle');
                dot.setAttribute('cx', end.x); dot.setAttribute('cy', end.y - 2); dot.setAttribute('r', 3.5); dot.setAttribute('fill', 'var(--org-line)');
                g.append(dot);
            }
            svg.append(g);
            paths.set(key, line);
        });
        if (selected) placeRemoveBtn(); else removeBtn.hidden = true;
    }
    function placeRemoveBtn() {
        const path = selected && paths.get(keyOf(selected));
        if (!path) { selected = null; removeBtn.hidden = true; return; }
        const pt = path.getPointAtLength(path.getTotalLength() / 2);
        removeBtn.style.left = pt.x + 'px'; removeBtn.style.top = pt.y + 'px';
        removeBtn.hidden = false;
    }
    svg.addEventListener('click', (e) => {
        const hit = e.target.closest('.edge-hit'); if (!hit) return;
        e.stopPropagation();
        selected = edgeList().find((ed) => keyOf(ed) === hit.dataset.key) || null;
        drawEdges();
    });
    removeBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const edge = selected; selected = null;
        const ok = edge.kind === 'side' ? await unlinkSide(edge) : await disconnect(edge);
        if (ok) drawEdges();
    });

    // ---------- server ----------
    async function send(url, body) {
        const res = await fetch(url, { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify(body) });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.message || 'Something went wrong — nothing was changed.');
        return json;
    }
    async function connect(parentId, childId) {
        try {
            const r = await send(URLS.connect, { type: TYPE, parent_id: +parentId, child_id: +childId });
            if (r.kind === 'main') nodes.get(childId).dataset.parent = parentId;
            else if (r.kind === 'extra') extras.push({ parent: parentId, child: childId });
            window.showToast?.(r.message || 'Connected.');
            return true;
        } catch (err) { window.showToast?.(err.message, 'error'); return false; }
    }
    async function disconnect({ parent, child }) {
        try {
            const r = await send(URLS.disconnect, { type: TYPE, parent_id: +parent, child_id: +child });
            const el = nodes.get(child);
            if (el.dataset.parent === parent) {
                const promoted = r.promoted ? String(r.promoted) : '';
                el.dataset.parent = promoted;
                extras = extras.filter((l) => !(l.child === child && l.parent === promoted));
            } else {
                extras = extras.filter((l) => !(l.child === child && l.parent === parent));
            }
            window.showToast?.(r.message || 'Removed.');
            return true;
        } catch (err) { window.showToast?.(err.message, 'error'); return false; }
    }
    async function linkSide(a, b) {
        try {
            const r = await send(URLS.connect, { mode: 'side', type: TYPE, a: +a, b: +b });
            if (r.kind === 'side') sides.push({ a, b });
            window.showToast?.(r.message || 'Linked.');
            return true;
        } catch (err) { window.showToast?.(err.message, 'error'); return false; }
    }
    async function unlinkSide({ a, b }) {
        try {
            const r = await send(URLS.disconnect, { mode: 'side', type: TYPE, a: +a, b: +b });
            sides = sides.filter((l) => !((l.a === a && l.b === b) || (l.a === b && l.b === a)));
            window.showToast?.(r.message || 'Removed.');
            return true;
        } catch (err) { window.showToast?.(err.message, 'error'); return false; }
    }
    function savePosition(el) {
        el.dataset.x = el._x; el.dataset.y = el._y;
        send(URLS.position, { type: TYPE, id: +el.dataset.id, x: el._x, y: el._y })
            .catch((err) => window.showToast?.(err.message, 'error'));
    }

    // ---------- zoom ----------
    let scale = 1;
    const label = document.getElementById('zoomLabel');
    try { scale = +(sessionStorage.getItem('archiveOrgZoom:{{ $view }}') || 1) || 1; } catch (e) {}
    function zoomTo(next, cx, cy) {
        next = Math.min(2, Math.max(0.25, +next.toFixed(2)));
        const r = viewport.getBoundingClientRect();
        cx = cx ?? r.left + viewport.clientWidth / 2; cy = cy ?? r.top + viewport.clientHeight / 2;
        // keep the point under the cursor fixed while zooming
        const sx = (viewport.scrollLeft + cx - r.left) / scale, sy = (viewport.scrollTop + cy - r.top) / scale;
        scale = next;
        stage.style.transform = `scale(${scale})`;
        fitStage();
        viewport.scrollLeft = sx * scale - (cx - r.left); viewport.scrollTop = sy * scale - (cy - r.top);
        label.textContent = Math.round(scale * 100) + '%';
        try { sessionStorage.setItem('archiveOrgZoom:{{ $view }}', scale); } catch (e) {}
    }
    function zoomFit() {
        let w = 0, h = 0, minX = Infinity, minY = Infinity;
        nodes.forEach((el) => { minX = Math.min(minX, el._x); minY = Math.min(minY, el._y); w = Math.max(w, el._x + el.offsetWidth); h = Math.max(h, el._y + el.offsetHeight); });
        const s = Math.min(viewport.clientWidth / (w - minX + PAD), viewport.clientHeight / (h - minY + PAD), 1.2);
        zoomTo(s);
        viewport.scrollLeft = (minX - PAD / 2) * scale; viewport.scrollTop = (minY - PAD / 2) * scale;
    }
    document.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => {
        const a = b.dataset.zoom;
        if (a === 'fit') zoomFit(); else zoomTo(a === 'reset' ? 1 : scale * (a === 'in' ? 1.2 : 1 / 1.2));
    }));
    // Ctrl/⌘ + wheel, and trackpad pinch (which browsers send as ctrl+wheel)
    viewport.addEventListener('wheel', (e) => {
        if (!e.ctrlKey && !e.metaKey) return;
        e.preventDefault();
        zoomTo(scale * Math.exp(-e.deltaY * 0.0015), e.clientX, e.clientY);
    }, { passive: false });
    document.addEventListener('keydown', (e) => {
        if (e.target.closest('input, textarea, select, [contenteditable]') || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key === '+' || e.key === '=') zoomTo(scale * 1.2);
        else if (e.key === '-' || e.key === '_') zoomTo(scale / 1.2);
        else if (e.key === '0') zoomTo(1);
        else if (e.key === 'Escape' && selected) { selected = null; drawEdges(); }
    });

    // ---------- pointer interactions ----------
    const toStage = (e) => { const r = stage.getBoundingClientRect(); return { x: (e.clientX - r.left) / scale, y: (e.clientY - r.top) / scale }; };
    let action = null; // {kind:'pan'|'move'|'connect', ...}
    let suppressClick = false;

    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) return;
        const handle = e.target.closest('.org-handle');
        const node = e.target.closest('.org-node');
        if (handle && node) {
            e.preventDefault();
            const from = node.dataset.id;
            const side = handle.dataset.side || 'bottom';
            // bottom dot = "put under": can't target itself or anything above it (loop).
            // side dots = side-to-side link: any card but itself.
            const blocked = side === 'bottom' ? ancestorsOf(from) : new Set([from]);
            blocked.forEach((id) => nodes.get(id)?.classList.add('is-invalid'));
            const temp = document.createElementNS(SVGNS, 'path'); temp.setAttribute('class', 'edge-temp'); svg.append(temp);
            document.body.classList.add('org-connecting');
            action = { kind: 'connect', from, side, temp, target: null, blocked };
        } else if (node && !e.target.closest('a, summary, button, details ul')) {
            const p = toStage(e);
            action = { kind: 'move', el: node, dx: p.x - node._x, dy: p.y - node._y, sx: e.clientX, sy: e.clientY, moved: false };
        } else if (!node && !e.target.closest('#edgeRemove, .edge-hit')) {
            action = { kind: 'pan', sx: e.clientX, sy: e.clientY, l: viewport.scrollLeft, t: viewport.scrollTop };
            if (selected) { selected = null; drawEdges(); }
        }
        if (action?.kind === 'pan' || action?.kind === 'connect') e.preventDefault();
    });

    // window-level (no pointer capture — capture would retarget the click
    // that follows, breaking the links and member lists inside cards)
    window.addEventListener('pointermove', (e) => {
        if (!action) return;
        if (action.kind === 'pan') {
            viewport.scrollLeft = action.l - (e.clientX - action.sx); viewport.scrollTop = action.t - (e.clientY - action.sy);
        } else if (action.kind === 'move') {
            if (!action.moved && Math.hypot(e.clientX - action.sx, e.clientY - action.sy) < 4) return;
            action.moved = true; action.el.classList.add('is-dragging');
            const p = toStage(e); setPos(action.el, p.x - action.dx, p.y - action.dy); drawEdges();
        } else if (action.kind === 'connect') {
            const p = toStage(e);
            const src = nodes.get(action.from);
            if (action.side === 'bottom') {
                action.temp.setAttribute('d', curve(anchorBottom(src), p));
            } else {
                const a = anchorSide(src, action.side), dir = action.side === 'left' ? -1 : 1;
                action.temp.setAttribute('d', sideCurve(a, dir, p, p.x >= a.x ? -1 : 1));
            }
            const under = document.elementFromPoint(e.clientX, e.clientY)?.closest('.org-node');
            const ok = under && !action.blocked.has(under.dataset.id) ? under : null;
            if (action.target !== ok) { action.target?.classList.remove('is-target'); ok?.classList.add('is-target'); action.target = ok; }
        }
    });

    async function endAction() {
        const a = action; action = null;
        if (!a) return;
        if (a.kind === 'move' && a.moved) {
            a.el.classList.remove('is-dragging'); suppressClick = true;
            savePosition(a.el); fitStage();
        } else if (a.kind === 'connect') {
            a.temp.remove(); document.body.classList.remove('org-connecting');
            nodes.forEach((n) => n.classList.remove('is-invalid', 'is-target'));
            if (a.target) {
                const ok = a.side === 'bottom'
                    ? await connect(a.from, a.target.dataset.id)
                    : await linkSide(a.from, a.target.dataset.id);
                if (ok) drawEdges();
            }
        }
    }
    window.addEventListener('pointerup', endAction);
    window.addEventListener('pointercancel', endAction);
    // a drag that ended on a link must not also follow the link
    stage.addEventListener('click', (e) => { if (suppressClick) { e.preventDefault(); e.stopPropagation(); suppressClick = false; } }, true);
    // member lists change a card's height — redraw its lines
    stage.addEventListener('toggle', () => drawEdges(), true);

    // ---------- go ----------
    autoLayout();
    stage.style.transform = `scale(${scale})`; label.textContent = Math.round(scale * 100) + '%';
    fitStage(); drawEdges();
    if (scale === 1) viewport.scrollLeft = Math.max(0, (stage.offsetWidth - viewport.clientWidth) / 2 - PAD);
    window.addEventListener('resize', fitStage);
})();
</script>
@endpush
