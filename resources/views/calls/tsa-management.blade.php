@extends('layouts.calls')
@section('title', 'Call Rotation')
@section('subtitle', 'Live status & which products each TSA handles')

@section('content')

<div class="flex items-center justify-between gap-4 mb-6">
    {{-- Segmented pill filter — same pattern (and same X-Table-Refresh
         convention) as Leads Setup's own team filter, so the two pages feel
         like one system instead of two different table styles. --}}
    <div id="tsaMgmtFilter" class="relative inline-flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl">
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
        @foreach($teams as $team)
        <a href="{{ route('calls.tsa-management', ['team' => $team]) }}" data-team="{{ $team }}"
           class="tsaMgmt-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ $selectedTeam === $team ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
            {{ $team }}
        </a>
        @endforeach
    </div>

    <button type="button" id="addTsaBtn"
            class="inline-flex items-center gap-2 bg-primary hover:bg-primary-dark text-white text-sm font-semibold font-mono px-4 py-2 rounded-lg cursor-pointer shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Add TSA
    </button>
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
                    @foreach($teams as $team)
                    <option value="{{ $team }}">{{ $team }}</option>
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
</script>

@endsection
