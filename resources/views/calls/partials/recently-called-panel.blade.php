{{--
    Recently Called — topbar icon + slide-over panel (explicit TSA
    feedback, 2026-09-18: "when they call in the overdue the that lead
    will be gone and they will search it again manually in the leads").

    A first fix auto-opened a lead's own detail modal right when the
    Calling popup closed — but a TSA who closed THAT modal too (by habit,
    or reflex Escape) landed right back at "search the main Leads list"
    (explicit follow-up: "they still will search to the main leads
    still"). This panel is the real safety net: a small, persistent list
    of THIS viewer's own last few dials, reachable from anywhere in Call
    Tracker with one click, no typing/searching required — survives page
    navigation and closing every modal, since it's sourced from real
    LeadActivity rows (LeadController::recentlyCalled() — see its own
    doc comment for why LeadActivity.user_id, not Lead.dialed_at, is the
    right source here).

    Call-Tracker-only (not shared with layouts/app.blade.php the way
    partials/messages-panel.blade.php is) — dialing only ever happens in
    Call Tracker, there's nothing for this to show on the TSD Reports
    side. Self-contained, same "owns its own JS" convention as the
    messages panel.
--}}
<div class="relative shrink-0">
    <button id="recentlyCalledToggle" type="button" aria-label="Recently Called" title="Recently Called"
            class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-50 dark:bg-yellow-950/40 border border-yellow-200 dark:border-yellow-900 hover:bg-yellow-100 dark:hover:bg-yellow-900/40 transition-colors cursor-pointer">
        <svg class="w-4 h-4 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
    </button>
</div>

<div id="recentlyCalledBackdrop" class="hidden fixed inset-0 bg-black/30 z-40"></div>
<div id="recentlyCalledPanel" class="hidden fixed top-0 right-0 h-full w-full sm:w-96 bg-white dark:bg-slate-900 border-l border-slate-200 dark:border-slate-700 shadow-2xl z-50 flex flex-col">
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700 shrink-0">
        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono">Recently Called</h3>
        <button type="button" id="recentlyCalledCloseBtn" aria-label="Close"
                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div id="recentlyCalledList" class="flex-1 overflow-y-auto">
        <p class="text-slate-400 text-center text-xs font-mono py-10">Loading…</p>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const routes = {
        recentlyCalled: "{{ route('calls.leads.recently-called') }}",
    };

    const toggle = document.getElementById('recentlyCalledToggle');
    const backdrop = document.getElementById('recentlyCalledBackdrop');
    const panel = document.getElementById('recentlyCalledPanel');
    const closeBtn = document.getElementById('recentlyCalledCloseBtn');
    const list = document.getElementById('recentlyCalledList');

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function openPanel() {
        backdrop.classList.remove('hidden');
        panel.classList.remove('hidden');
        loadList();
    }

    function closePanel() {
        backdrop.classList.add('hidden');
        panel.classList.add('hidden');
    }

    function loadList() {
        fetch(routes.recentlyCalled, { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => renderList(data?.leads || []))
            .catch(() => renderList([]));
    }

    function renderList(leads) {
        if (!leads.length) {
            list.innerHTML = '<p class="text-slate-400 text-center text-xs font-mono py-10">No calls yet today.</p>';
            return;
        }

        // Disposition badge removed entirely (explicit request,
        // 2026-09-18: "remove the disposition") — this panel is purely a
        // quick way back to a recently-dialed lead, not a second place to
        // track outcome state (the lead's own detail modal already shows
        // that).
        list.innerHTML = leads.map((l) => `
            <div class="recently-called-row flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800 border-b border-slate-100 dark:border-slate-800" data-id="${l.id}">
                <span class="w-9 h-9 rounded-full bg-primary/10 text-primary-dark dark:text-yellow-400 flex items-center justify-center text-xs font-bold shrink-0">${escapeHtml((l.customerName || '?').charAt(0).toUpperCase())}</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 truncate">${escapeHtml(l.customerName)}</p>
                    <p class="text-xs text-slate-400 truncate">${escapeHtml(l.phoneNumber || '')}${l.calledAt ? ' · ' + escapeHtml(l.calledAt) : ''}</p>
                </div>
            </div>`).join('');
    }

    toggle?.addEventListener('click', openPanel);
    closeBtn?.addEventListener('click', closePanel);
    backdrop?.addEventListener('click', closePanel);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.classList.contains('hidden')) closePanel();
    });

    list.addEventListener('click', (e) => {
        const row = e.target.closest('.recently-called-row');
        if (!row) return;
        closePanel();
        window.openLeadModal?.(row.dataset.id);
    });
})();
</script>
@endpush
