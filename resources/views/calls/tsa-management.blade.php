@extends('layouts.calls')
@section('title', 'Call Rotation')
@section('subtitle', 'Live status & which products each TSA handles')

@section('content')

{{-- flex-wrap (explicit follow-up request, 2026-09-05: "make all tabs is
     responsive") — this row previously forced the team-filter pills and the
     toggle+button group onto one unwrapped line, pushing the whole page
     ~20px wider than a 360px viewport (confirmed: neither the table below,
     which already scrolls fine via overflow-x-auto, nor any single element
     was individually oversized — this row's own combined content was).
     overflow-x-auto on the pill filter itself (added below) lets that piece
     scroll horizontally if it's still too wide to wrap cleanly, same
     convention this page's own table already uses one section down. --}}
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    {{-- Segmented pill filter — same pattern (and same X-Table-Refresh
         convention) as Leads Setup's own team filter, so the two pages feel
         like one system instead of two different table styles. --}}
    <div id="tsaMgmtFilter" class="relative inline-flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl max-w-full overflow-x-auto">
        <span id="tsaMgmtFilterHighlight" class="absolute inset-y-1 left-1 rounded-lg bg-white dark:bg-slate-900 shadow-sm transition-all duration-200 ease-out" style="width: 0"></span>

        {{-- team='' explicit, not omitted — an omitted param is
             indistinguishable from a fresh sidebar navigation to this page,
             which would wrongly leave the last-remembered team filter in
             place instead of actually clearing it (see
             PersistsCallTrackerFilters's own doc comment). --}}
        <a href="{{ route('calls.tsa-management', ['team' => '']) }}" data-team=""
           class="tsaMgmt-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ !$selectedTeam ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            All teams
        </a>
        {{-- Renamed-team-aware (explicit follow-up request, 2026-09-04) —
             $teams is now [order_team => display name] (Teams::config());
             the href/data-team keys stay the fixed order_team string, only
             the visible label uses the (possibly renamed) value. --}}
        @foreach($teams as $orderTeam => $displayName)
        <a href="{{ route('calls.tsa-management', ['team' => $orderTeam]) }}" data-team="{{ $orderTeam }}"
           class="tsaMgmt-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ $selectedTeam === $orderTeam ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            {{ $displayName }}
        </a>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-4 shrink-0">
        {{-- Global POS name tag auto-tagging switch — explicit request,
             2026-08-28. One toggle for every TSA (not per-row): OFF just
             stops each TSA's own tag being pushed to their Pancake POS
             order when a call outcome is logged — leads still assign and
             show up in the Leads tab exactly the same either way. --}}
        <div class="flex items-center gap-2" title="Controls only the TSA name tag pushed to Pancake POS — lead assignment and the Leads tab are unaffected.">
            <span class="text-xs font-mono font-semibold text-slate-600 dark:text-slate-300">POS name tag</span>
            <button type="button" id="autoTaggingToggle"
                    data-action="{{ route('calls.tsa-management.toggle-auto-tagging') }}"
                    data-enabled="{{ $autoTaggingEnabled ? '1' : '0' }}"
                    aria-pressed="{{ $autoTaggingEnabled ? 'true' : 'false' }}"
                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-300 ease-in-out cursor-pointer active:scale-95 {{ $autoTaggingEnabled ? 'bg-primary' : 'bg-slate-300 dark:bg-slate-600' }}">
                <span id="autoTaggingKnob"
                      class="inline-block h-4 w-4 transform rounded-full bg-white shadow-md transition-transform duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] will-change-transform {{ $autoTaggingEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
            </button>
        </div>

        <button type="button" id="addTsaBtn"
                class="inline-flex items-center gap-2 bg-primary hover:bg-primary-dark text-white text-sm font-semibold font-mono px-4 py-2 rounded-lg cursor-pointer shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Add TSA
        </button>
    </div>
</div>

<div id="tsaMgmtTableContainer" class="transition-opacity duration-150">
    @include('calls.tsa-management._table')
</div>

{{-- Add TSA modal — same "search the real Pancake POS account list, don't
     free-type a name" flow as TSD Reports' own Add TSA. --}}
<div id="tsaModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700">
            <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100 font-mono">Add a new TSA</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">They'll start with no products assigned — check boxes below once added</p>
        </div>
        <form method="POST" action="{{ route('calls.tsa-management.store') }}" class="px-6 py-5 space-y-4">
            @csrf
            <input type="hidden" name="pos_user_id" id="tsaPosUserId" value="">

            <div class="relative">
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">TSA name</label>
                <input type="text" id="tsaNameInput" name="display_name" required autocomplete="off"
                    placeholder="Search Pancake POS accounts..."
                    class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                <div id="tsaNameResults" class="hidden absolute z-10 mt-1 w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-xl max-h-52 overflow-y-auto"></div>
                <p id="tsaLinkedHint" class="hidden text-[11px] text-green-600 dark:text-green-400 mt-1">
                    <svg class="w-3 h-3 inline -mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Linked to a real POS account
                </p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Team</label>
                <select name="team" required
                    class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
                    {{-- Renamed-team-aware (explicit follow-up request, 2026-09-04) —
                         option value stays the fixed order_team string (validated/
                         stored as-is by TsaManagementController::store()), only the
                         visible option text uses the (possibly renamed) display name. --}}
                    @foreach($teams as $orderTeam => $displayName)
                    <option value="{{ $orderTeam }}">{{ $displayName }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" id="cancelTsaModal"
                        class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 text-xs font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg transition-colors cursor-pointer">
                    Add TSA
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Pair-confirm modal — replaces a plain browser confirm() (explicit
     follow-up request, 2026-09-11: "create a modal for this") for the
     drag-to-pair action below. Copy is filled in by JS right before
     showing it (see the drag handler), same "one shared shell, text
     swapped per use" idea as this page's own Add TSA modal reusing one
     DOM node rather than building one modal per possible message. --}}
<div id="pairConfirmModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-start gap-3">
            <span class="shrink-0 w-9 h-9 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Pair these TSAs?</h3>
                <p id="pairConfirmBody" class="text-xs text-slate-500 dark:text-slate-400 mt-1"></p>
            </div>
        </div>
        <div class="flex items-center justify-end gap-2 px-6 py-4">
            <button type="button" id="pairConfirmCancel"
                    class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                Cancel
            </button>
            <button type="button" id="pairConfirmOk"
                    class="px-4 py-2 text-xs font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg transition-colors cursor-pointer">
                Pair them
            </button>
        </div>
    </div>
</div>

{{-- Unpair-confirm modal — same shared-shell idea as pairConfirmModal
     above, for the "x" button on a pair's badge. --}}
<div id="unpairConfirmModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 p-6">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-700 flex items-start gap-3">
            <span class="shrink-0 w-9 h-9 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 flex items-center justify-center">
                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 13H7m0-8v13a2 2 0 002 2h6a2 2 0 002-2V5H7z"/></svg>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Unpair this TSA?</h3>
                <p id="unpairConfirmBody" class="text-xs text-slate-500 dark:text-slate-400 mt-1"></p>
            </div>
        </div>
        <div class="flex items-center justify-end gap-2 px-6 py-4">
            <button type="button" id="unpairConfirmCancel"
                    class="px-3 py-2 text-xs font-mono text-slate-600 dark:text-slate-300 hover:text-slate-800 dark:hover:text-slate-100 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                Cancel
            </button>
            <button type="button" id="unpairConfirmOk"
                    class="px-4 py-2 text-xs font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors cursor-pointer">
                Unpair
            </button>
        </div>
    </div>
</div>

{{-- Cross-team pair picker — explicit request, 2026-09-11: "the tsa is
     will be like not move to the other team it will only like pair to
     the other team." Dragging a row from the currently-viewed team's
     table onto a DIFFERENT team's filter pill opens this small popover
     (not a full modal — it's a quick pick, not a decision needing the
     weight of a modal) listing that team's own unpaired TSAs
     (TsaManagementController::pairCandidates()); picking one calls the
     same pair() endpoint the same-team drag-onto-row flow already uses.
     Positioned dynamically under whichever pill was dropped onto — see
     the drag handler below. --}}
<div id="crossTeamPairPicker" class="hidden fixed z-50 w-64 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden">
    <div class="px-3 py-2 border-b border-slate-100 dark:border-slate-700">
        <p id="crossTeamPairPickerTitle" class="text-xs font-mono font-semibold text-slate-600 dark:text-slate-300"></p>
    </div>
    <div id="crossTeamPairPickerList" class="max-h-56 overflow-y-auto py-1">
        {{-- Populated by the drag handler below --}}
    </div>
</div>

<script>
(function () {
    // Team filter — identical animated-pill + AJAX-swap pattern as Leads
    // Setup (round-robin-setup.blade.php); kept as its own copy rather than
    // a shared include since the two pages' container/pill ids differ and
    // this is the only place either needs it.
    const filter = document.getElementById('tsaMgmtFilter');
    const highlight = document.getElementById('tsaMgmtFilterHighlight');
    const container = document.getElementById('tsaMgmtTableContainer');
    if (filter && container) {
        function positionHighlight(pill, animate) {
            if (!pill) return;
            highlight.style.transitionDuration = animate ? '200ms' : '0ms';
            highlight.style.width = pill.offsetWidth + 'px';
            highlight.style.transform = `translateX(${pill.offsetLeft - 4}px)`;
        }

        function setActivePill(team) {
            filter.querySelectorAll('.tsaMgmt-pill').forEach((pill) => {
                const active = pill.dataset.team === team;
                pill.classList.toggle('text-slate-800', active);
                pill.classList.toggle('dark:text-slate-100', active);
                pill.classList.toggle('text-slate-500', !active);
                pill.classList.toggle('dark:text-slate-400', !active);
                if (active) positionHighlight(pill, true);
            });
        }

        positionHighlight(filter.querySelector('.tsaMgmt-pill.text-slate-800'), false);

        filter.querySelectorAll('.tsaMgmt-pill').forEach((pill) => {
            pill.addEventListener('click', (e) => {
                e.preventDefault();
                const team = pill.dataset.team;
                setActivePill(team);

                container.style.opacity = '0';
                fetch(pill.href, { headers: { 'X-Table-Refresh': '1' } })
                    .then((res) => (res.ok ? res.text() : null))
                    .then((html) => {
                        if (html === null) return;
                        container.innerHTML = html;
                        container.style.opacity = '1';
                        history.pushState({}, '', pill.href);
                    })
                    .catch(() => { container.style.opacity = '1'; });
            });
        });

        window.addEventListener('popstate', () => window.location.reload());
    }

    // Global POS name tag auto-tagging switch — single AJAX toggle, same
    // CSRF-header fetch convention as calls.js's other AJAX actions
    // (regenerate-token, etc.). Flips the button's visual state right away
    // and rolls it back if the request fails, so an admin never sees a
    // switch that silently didn't take.
    const autoTaggingToggle = document.getElementById('autoTaggingToggle');
    const autoTaggingKnob   = document.getElementById('autoTaggingKnob');
    if (autoTaggingToggle && autoTaggingKnob) {
        function paintAutoTagging(enabled) {
            autoTaggingToggle.dataset.enabled = enabled ? '1' : '0';
            autoTaggingToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            autoTaggingToggle.classList.toggle('bg-primary', enabled);
            autoTaggingToggle.classList.toggle('bg-slate-300', !enabled);
            autoTaggingToggle.classList.toggle('dark:bg-slate-600', !enabled);
            autoTaggingKnob.classList.toggle('translate-x-6', enabled);
            autoTaggingKnob.classList.toggle('translate-x-1', !enabled);
        }

        autoTaggingToggle.addEventListener('click', () => {
            const next = autoTaggingToggle.dataset.enabled !== '1';
            paintAutoTagging(next);
            autoTaggingToggle.disabled = true;

            fetch(autoTaggingToggle.dataset.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: `enabled=${next ? '1' : '0'}`,
            })
                .then((res) => (res.ok ? res.json() : Promise.reject(res)))
                .then((data) => {
                    window.showToast?.(data.message || 'Saved.', 'success');
                })
                .catch(async (res) => {
                    paintAutoTagging(!next);
                    let message = 'Could not save — try again.';
                    if (res?.json) {
                        try {
                            const data = await res.json();
                            message = data.message || message;
                        } catch (e) { /* not JSON — keep the generic message */ }
                    }
                    window.showToast?.(message, 'error');
                })
                .finally(() => { autoTaggingToggle.disabled = false; });
        });
    }

    // Add TSA modal
    const tsaModal      = document.getElementById('tsaModal');
    const addTsaBtn      = document.getElementById('addTsaBtn');
    const cancelTsaModal = document.getElementById('cancelTsaModal');
    const nameInput      = document.getElementById('tsaNameInput');
    const posUserIdInput = document.getElementById('tsaPosUserId');
    const resultsBox     = document.getElementById('tsaNameResults');
    const linkedHint     = document.getElementById('tsaLinkedHint');

    function openTsaModal() { tsaModal.classList.remove('hidden'); tsaModal.classList.add('flex'); }
    function closeTsaModal() {
        tsaModal.classList.add('hidden');
        tsaModal.classList.remove('flex');
        resultsBox.classList.add('hidden');
        nameInput.value = '';
        posUserIdInput.value = '';
        linkedHint.classList.add('hidden');
    }

    addTsaBtn.addEventListener('click', openTsaModal);
    cancelTsaModal.addEventListener('click', closeTsaModal);
    tsaModal.addEventListener('click', (e) => { if (e.target === tsaModal) closeTsaModal(); });

    // Searchable POS-account picker — same debounced-fetch pattern as TSD
    // Reports' own Add TSA form.
    let debounceTimer = null;
    nameInput.addEventListener('input', () => {
        posUserIdInput.value = '';
        linkedHint.classList.add('hidden');
        clearTimeout(debounceTimer);
        const q = nameInput.value.trim();
        debounceTimer = setTimeout(() => fetchPosUsers(q), 250);
    });
    nameInput.addEventListener('focus', () => {
        if (nameInput.value.trim() !== '') fetchPosUsers(nameInput.value.trim());
    });

    async function fetchPosUsers(q) {
        try {
            const res = await fetch(`{{ route('calls.tsa-management.pos-users') }}?q=` + encodeURIComponent(q));
            const users = await res.json();
            renderResults(users);
        } catch (e) {
            resultsBox.classList.add('hidden');
        }
    }

    function renderResults(users) {
        if (!users.length) { resultsBox.classList.add('hidden'); resultsBox.innerHTML = ''; return; }
        resultsBox.innerHTML = users.map(u => `
            <div class="tsaResultRow px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-primary-dark cursor-pointer" data-id="${u.id}" data-name="${u.name.replace(/"/g, '&quot;')}">
                ${u.name}
            </div>
        `).join('');
        resultsBox.classList.remove('hidden');

        resultsBox.querySelectorAll('.tsaResultRow').forEach(row => {
            // mousedown fires before the input's blur, so the click registers
            row.addEventListener('mousedown', (e) => {
                e.preventDefault();
                nameInput.value = row.dataset.name;
                posUserIdInput.value = row.dataset.id;
                linkedHint.classList.remove('hidden');
                resultsBox.classList.add('hidden');
            });
        });
    }
})();

// Expand/collapse a TSA's detail row — delegated so it survives the filter's
// innerHTML swap without needing to re-bind per row. One row open at a time
// isn't enforced (an admin may want to compare two TSAs' setups side by
// side), unlike the single-popover status panel above.
//
// The status trigger/dropdown (calls/partials/tsa-status-panel) lives inside
// the same <tr> so its own click can reach this listener too — bailing out
// here (instead of an onclick="event.stopPropagation()" on its <td>, tried
// first and reverted) is what's needed: stopPropagation there also silently
// blocked calls.js's own document-level handler for the "Select status"
// options and the click-outside-closes-it logic, since both listen on
// document too and stopPropagation cuts the event off before it gets that
// far — the dropdown opened (a direct onclick on the trigger) but picking an
// option did nothing.
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-status-panel-wrap]')) return;
    // Unpair "x" button and the drag handle both live inside the row that
    // ALSO carries data-tsa-row-toggle — without this bail, clicking either
    // would also toggle the detail panel open/closed underneath whatever
    // else it just did.
    if (e.target.closest('[data-tsa-unpair]')) return;
    if (e.target.closest('.tsa-drag-handle')) return;

    const trigger = e.target.closest('[data-tsa-row-toggle]');
    if (!trigger) return;

    const id      = trigger.dataset.tsaRowToggle;
    const grid    = document.querySelector(`[data-tsa-detail-grid="${id}"]`);
    const chevron = document.getElementById(`tsaChevron-${id}`);
    if (!grid) return;

    // grid-template-rows 0fr <-> 1fr, not a plain height transition — see
    // the row's own doc comment in _table.blade.php for why (content height
    // varies per TSA/per open <details>, which max-height can't handle
    // without either clipping it or guessing an oversized fixed value).
    const isOpen = grid.style.gridTemplateRows === '1fr';
    grid.style.gridTemplateRows = isOpen ? '0fr' : '1fr';
    if (chevron) chevron.classList.toggle('rotate-180', !isOpen);
});

// Shared confirm-modal helper (explicit follow-up request, 2026-09-11:
// "create a modal for this" — replaces the native browser confirm() the
// drag-to-pair/unpair actions below used at first). Returns a Promise
// resolving true/false, so call sites read exactly like the confirm()
// they replaced: `if (!(await confirmModal(...))) return;`.
function confirmModal(modalId, bodyId, okId, cancelId, message) {
    const modal  = document.getElementById(modalId);
    const body   = document.getElementById(bodyId);
    const okBtn  = document.getElementById(okId);
    const cancelBtn = document.getElementById(cancelId);
    if (!modal || !body || !okBtn || !cancelBtn) return Promise.resolve(false);

    body.textContent = message;
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    return new Promise((resolve) => {
        function cleanup(result) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdrop);
            resolve(result);
        }
        function onOk() { cleanup(true); }
        function onCancel() { cleanup(false); }
        function onBackdrop(e) { if (e.target === modal) cleanup(false); }

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdrop);
    });
}

// Cross-team pair picker (explicit follow-up, 2026-09-11: "the tsa is
// will be like not move to the other team it will only like pair to the
// other team") — opened by dropping a row on a different team's filter
// pill (see the drag handler below). Fetches that team's unpaired TSAs
// (TsaManagementController::pairCandidates()) and shows them in
// #crossTeamPairPicker, positioned under the dropped-on pill. Picking one
// calls the same POST /pair endpoint the same-team drag-onto-row flow
// already uses — this is purely a different way of choosing the partner,
// not a different pairing mechanism.
function openCrossTeamPairPicker(pillEl, draggedTsaId, draggedTsaName, targetTeam, targetTeamLabel) {
    const picker    = document.getElementById('crossTeamPairPicker');
    const title     = document.getElementById('crossTeamPairPickerTitle');
    const list      = document.getElementById('crossTeamPairPickerList');
    if (!picker || !title || !list) return;

    const rect = pillEl.getBoundingClientRect();
    picker.style.top  = `${rect.bottom + 6}px`;
    picker.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - 264))}px`;

    title.textContent = `Pair ${draggedTsaName} with…`;
    list.innerHTML = '<p class="text-xs font-mono text-slate-400 text-center py-4">Loading…</p>';
    picker.classList.remove('hidden');
    picker.classList.add('flex', 'flex-col');

    function closePicker() {
        picker.classList.add('hidden');
        picker.classList.remove('flex', 'flex-col');
        document.removeEventListener('click', onOutsideClick, true);
    }
    function onOutsideClick(e) {
        if (!picker.contains(e.target)) closePicker();
    }
    // Capture phase + next tick: the drop event that opened this picker
    // would otherwise immediately bubble into this same listener and
    // close it before the user ever sees it.
    setTimeout(() => document.addEventListener('click', onOutsideClick, true), 0);

    fetch(`/calls/tsa-management/${draggedTsaId}/pair-candidates?team=${encodeURIComponent(targetTeam)}`, {
        headers: { Accept: 'application/json' },
    })
        .then(res => res.json())
        .then((data) => {
            if (!data.success) {
                list.innerHTML = `<p class="text-xs font-mono text-red-500 text-center py-4">${data.message || data.error || 'Could not load candidates.'}</p>`;
                return;
            }
            if (!data.candidates.length) {
                list.innerHTML = `<p class="text-xs font-mono text-slate-400 text-center py-4">No available TSAs on ${targetTeamLabel} — everyone there is already paired.</p>`;
                return;
            }
            list.innerHTML = data.candidates.map(c => `
                <button type="button" data-candidate-id="${c.id}" data-candidate-name="${c.display_name.replace(/"/g, '&quot;')}"
                        class="cross-team-pair-candidate w-full text-left px-3 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-yellow-50 dark:hover:bg-yellow-950/40 hover:text-primary-dark cursor-pointer">
                    ${c.display_name}
                </button>
            `).join('');

            list.querySelectorAll('.cross-team-pair-candidate').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const partnerId   = btn.dataset.candidateId;
                    const partnerName = btn.dataset.candidateName;
                    closePicker();

                    const confirmed = await confirmModal(
                        'pairConfirmModal', 'pairConfirmBody', 'pairConfirmOk', 'pairConfirmCancel',
                        `Pair ${draggedTsaName} with ${partnerName}? ${draggedTsaName} will share ${partnerName}'s phone/token — ${draggedTsaName}'s own token will be cleared. You can unpair anytime.`
                    );
                    if (!confirmed) return;

                    fetch(`/calls/tsa-management/${partnerId}/pair`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ partner_id: draggedTsaId }),
                    })
                        .then(res => res.json().then(data => ({ ok: res.ok, data })))
                        .then(({ ok, data }) => {
                            if (!ok || !data.success) {
                                window.showToast?.(data.message || data.error || 'Could not pair these TSAs.', 'error');
                                return;
                            }
                            window.showToast?.(data.message || 'Paired.', 'success');
                            window.location.reload();
                        })
                        .catch(() => window.showToast?.('Something went wrong pairing these TSAs.', 'error'));
                });
            });
        })
        .catch(() => {
            list.innerHTML = '<p class="text-xs font-mono text-red-500 text-center py-4">Something went wrong loading candidates.</p>';
        });
}

// Drag-to-pair (explicit request, 2026-09-11: "2 tsa, one cellphone...
// no shift schedules, whoever is online and clicks dial") — dragging TSA
// row A onto TSA row B pairs them so they share B's phone/token, with
// live status (not a fixed schedule) deciding which of the two an
// incoming call event actually belongs to (see
// TsaShift::resolveActiveOfPair(), CallEventController::store()).
//
// Native HTML5 drag events, not a library — this table has exactly one
// drag interaction (row onto row), not a general sortable/reorderable
// list, so pulling in Sortable.js for this alone would be more dependency
// than the feature needs. Delegated on the table body (not per-row) for
// the same filter-swap-survives-without-rebinding reason the click
// handler above already uses.
(function () {
    const container = document.getElementById('tsaMgmtTableContainer');
    const filterBar  = document.getElementById('tsaMgmtFilter');
    if (!container) return;

    let draggedId   = null;
    let draggedTeam = null;

    container.addEventListener('dragstart', (e) => {
        const row = e.target.closest('.tsa-row');
        if (!row) return;
        draggedId   = row.dataset.tsaRow;
        draggedTeam = row.dataset.tsaTeam;
        e.dataTransfer.effectAllowed = 'move';
        // Firefox requires setData to be called for a drag to actually
        // start at all — the id itself isn't read back from this on drop
        // (draggedId above is what's used), this is purely to satisfy that.
        e.dataTransfer.setData('text/plain', draggedId);
        row.classList.add('opacity-50');
    });

    container.addEventListener('dragend', (e) => {
        const row = e.target.closest('.tsa-row');
        if (row) row.classList.remove('opacity-50');
        container.querySelectorAll('.tsa-row-drop-target').forEach(el => el.classList.remove('tsa-row-drop-target', 'bg-yellow-50', 'dark:bg-yellow-950/30'));
        filterBar?.querySelectorAll('.tsaMgmt-pill').forEach(p => p.classList.remove('ring-2', 'ring-primary'));
        draggedId   = null;
        draggedTeam = null;
    });

    // Cross-team pairing (explicit request, 2026-09-11: "the tsa is will
    // be like not move to the other team it will only like pair to the
    // other team") — dropping a row on a DIFFERENT team's filter pill
    // opens crossTeamPairPicker (see its own doc comment above) instead of
    // moving anyone. The CURRENTLY selected team's own pill is not a drop
    // target — same-team pairing already works by dragging onto a row
    // directly in the table below.
    if (filterBar) {
        filterBar.addEventListener('dragover', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (!pill || !draggedId || !pill.dataset.team || pill.dataset.team === draggedTeam) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });

        filterBar.addEventListener('dragenter', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (!pill || !draggedId || !pill.dataset.team || pill.dataset.team === draggedTeam) return;
            pill.classList.add('ring-2', 'ring-primary');
        });

        filterBar.addEventListener('dragleave', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (pill && !pill.contains(e.relatedTarget)) {
                pill.classList.remove('ring-2', 'ring-primary');
            }
        });

        filterBar.addEventListener('drop', (e) => {
            const pill = e.target.closest('.tsaMgmt-pill');
            if (!pill || !draggedId || !pill.dataset.team || pill.dataset.team === draggedTeam) return;
            e.preventDefault();
            pill.classList.remove('ring-2', 'ring-primary');

            const tsaId      = draggedId;
            const draggedRow = container.querySelector(`.tsa-row[data-tsa-row="${tsaId}"]`);
            if (draggedRow && draggedRow.dataset.tsaPaired === '1') {
                window.showToast?.('This TSA is already paired — unpair first.', 'error');
                return;
            }

            openCrossTeamPairPicker(pill, tsaId, draggedRow ? draggedRow.dataset.tsaName : 'This TSA', pill.dataset.team, pill.textContent.trim());
        });
    }

    container.addEventListener('dragover', (e) => {
        const row = e.target.closest('.tsa-row');
        if (!row || !draggedId || row.dataset.tsaRow === draggedId) return;
        e.preventDefault(); // required for drop to fire at all
        e.dataTransfer.dropEffect = 'move';
    });

    container.addEventListener('dragenter', (e) => {
        const row = e.target.closest('.tsa-row');
        if (!row || !draggedId || row.dataset.tsaRow === draggedId) return;
        row.classList.add('tsa-row-drop-target', 'bg-yellow-50', 'dark:bg-yellow-950/30');
    });

    container.addEventListener('dragleave', (e) => {
        const row = e.target.closest('.tsa-row');
        if (row && !row.contains(e.relatedTarget)) {
            row.classList.remove('tsa-row-drop-target', 'bg-yellow-50', 'dark:bg-yellow-950/30');
        }
    });

    container.addEventListener('drop', async (e) => {
        const targetRow = e.target.closest('.tsa-row');
        if (!targetRow || !draggedId || targetRow.dataset.tsaRow === draggedId) return;
        e.preventDefault();

        // Captured into a local BEFORE the confirm-modal await below —
        // dragend fires synchronously right after drop regardless of any
        // async work still running inside this handler, which resets the
        // shared draggedId to null while the modal is still open. Reading
        // draggedId itself (not this local) after the await was exactly
        // that bug: the fetch below sent partner_id: null, which the
        // backend correctly rejected as "The partner id field is
        // required."
        const partnerId = draggedId;
        const targetId = targetRow.dataset.tsaRow;
        const draggedRow = container.querySelector(`.tsa-row[data-tsa-row="${partnerId}"]`);

        if (targetRow.dataset.tsaPaired === '1' || (draggedRow && draggedRow.dataset.tsaPaired === '1')) {
            window.showToast?.('One of these is already paired — unpair first.', 'error');
            return;
        }

        const targetName   = targetRow.dataset.tsaName;
        const draggedName  = draggedRow ? draggedRow.dataset.tsaName : 'This TSA';
        const confirmed = await confirmModal(
            'pairConfirmModal', 'pairConfirmBody', 'pairConfirmOk', 'pairConfirmCancel',
            `Pair ${draggedName} with ${targetName}? ${draggedName} will share ${targetName}'s phone/token — ${draggedName}'s own token will be cleared. You can unpair anytime.`
        );
        if (!confirmed) return;

        fetch(`/calls/tsa-management/${targetId}/pair`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ partner_id: partnerId }),
        })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data.success) {
                    window.showToast?.(data.message || data.error || 'Could not pair these TSAs.', 'error');
                    return;
                }
                window.showToast?.(data.message || 'Paired.', 'success');
                window.location.reload();
            })
            .catch(() => window.showToast?.('Something went wrong pairing these TSAs.', 'error'));
    });
})();

// Unpair — single click, no schedule/token juggling for the admin to
// think about (TsaShift::unpair() issues the now-solo partner a fresh
// token automatically).
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-tsa-unpair]');
    if (!btn) return;

    const id   = btn.dataset.tsaUnpair;
    const name = btn.dataset.tsaUnpairName;
    const confirmed = await confirmModal(
        'unpairConfirmModal', 'unpairConfirmBody', 'unpairConfirmOk', 'unpairConfirmCancel',
        `Unpair ${name}? Both TSAs will get their own token back.`
    );
    if (!confirmed) return;

    fetch(`/calls/tsa-management/${id}/unpair`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
    })
        .then(res => res.json().then(data => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if (!ok || !data.success) {
                window.showToast?.(data.message || data.error || 'Could not unpair.', 'error');
                return;
            }
            window.showToast?.(data.message || 'Unpaired.', 'success');
            window.location.reload();
        })
        .catch(() => window.showToast?.('Something went wrong unpairing.', 'error'));
});
</script>

@endsection
