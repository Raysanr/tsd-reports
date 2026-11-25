@extends('layouts.calls')
@section('title', 'Leads Setup')
@section('subtitle', 'Daily lead limit per TSA. Once a TSA reaches it, new leads go to the others.')

@push('topbar-right')
{{-- Icon-only, real two-calendar range — same shared picker Dashboard uses,
     same props shape (explicit request, 2026-08-24: "make it icon in top
     bar like in the dashboard", then a same-day follow-up: "can select
     multiple dates... 2 calendar like in the dashboard" — upgraded from a
     single date to a real range). Review a past day/range's "Assigned"
     count, not just live today. submit='navigate': a real page reload,
     same as Dashboard's own picker — round-robin enforcement itself never
     depends on what's picked here (RoundRobinSetupController::index()'s
     own comment), so there's no live state this would need to keep in
     sync with via AJAX the way the team pills below do. Team filter isn't
     preserved through a date change (navigate mode always builds its URL
     as "{navigateBase}?date_from=...&date_to=...", no room for a third
     query param) — an accepted minor rough edge, not worth a custom
     picker integration to close. --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'rrsDrp',
    'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
    'submit' => 'navigate', 'navigateBase' => route('calls.round-robin-setup'),
])
@endpush

@section('content')

{{-- Segmented pill filter (explicit request 2026-08-15: smooth transition on
     both the pill itself and the table below, not a hard page reload). The
     highlight behind the pills is one absolutely-positioned span JS slides
     between them (see #rrsFilter script below) rather than each pill toggling
     its own background — that's what makes it glide instead of snap. Links
     stay real hrefs (working nav, right-click/open-in-new-tab, no-JS
     fallback); JS only intercepts the click to animate instead of reload. --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
<div id="rrsFilter" class="relative inline-flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl">
    <span id="rrsFilterHighlight" class="absolute inset-y-1 left-1 rounded-lg bg-white dark:bg-slate-900 shadow-sm transition-all duration-200 ease-out" style="width: 0"></span>

    @php
        // Preserved through a team-pill click so switching teams never
        // silently resets a picked date/range — the picker's own
        // navigate-mode change (see topbar-right above) can't preserve
        // `team` the same way, but these AJAX-swapped pill links can.
        $isDefaultRange = $dateFrom->isToday() && $dateTo->isToday();
        $rangeQuery = $isDefaultRange ? [] : ['date_from' => $dateFrom->toDateString(), 'date_to' => $dateTo->toDateString()];
    @endphp
    {{-- team='' explicit, not omitted — an omitted param is indistinguishable
         from a fresh sidebar navigation to this page, which would wrongly
         leave the last-remembered team filter in place instead of actually
         clearing it (see PersistsCallTrackerFilters's own doc comment). --}}
    <a href="{{ route('calls.round-robin-setup', ['team' => ''] + $rangeQuery) }}" data-team=""
       class="rrs-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ !$selectedTeam ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        All teams
    </a>
    @foreach($teams as $orderTeam => $displayName)
    <a href="{{ route('calls.round-robin-setup', ['team' => $orderTeam] + $rangeQuery) }}" data-team="{{ $orderTeam }}"
       class="rrs-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ $selectedTeam === $orderTeam ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        {{ $displayName }}
    </a>
    @endforeach
</div>
    {{-- One page-level save badge for every limit box (2026-11-25). --}}
    <span id="rrsSaveState" aria-live="polite" class="hidden items-center gap-1 text-xs font-semibold rounded-full px-2.5 py-1"></span>
</div>

<div id="rrsTableContainer" class="transition-opacity duration-150">
    @include('calls.round-robin-setup._table')
</div>

<script>
(function () {
    const filter = document.getElementById('rrsFilter');
    const highlight = document.getElementById('rrsFilterHighlight');
    const container = document.getElementById('rrsTableContainer');
    if (!filter || !container) return;

    function positionHighlight(pill, animate) {
        if (!pill) return;
        highlight.style.transitionDuration = animate ? '200ms' : '0ms';
        highlight.style.width = pill.offsetWidth + 'px';
        highlight.style.transform = `translateX(${pill.offsetLeft - 4}px)`;
    }

    function setActivePill(team) {
        filter.querySelectorAll('.rrs-pill').forEach((pill) => {
            const active = pill.dataset.team === team;
            pill.classList.toggle('text-slate-800', active);
            pill.classList.toggle('dark:text-slate-100', active);
            pill.classList.toggle('text-slate-500', !active);
            pill.classList.toggle('dark:text-slate-400', !active);
            if (active) positionHighlight(pill, true);
        });
    }

    // Position instantly on load (no slide-in from nowhere on first paint).
    positionHighlight(filter.querySelector('.rrs-pill.text-slate-800'), false);

    filter.querySelectorAll('.rrs-pill').forEach((pill) => {
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

    // Back/forward between filtered states — reload normally rather than
    // trying to re-derive which pill was active from a bfcache'd DOM.
    window.addEventListener('popstate', () => window.location.reload());

    // Daily Cap auto-save (explicit request, 2026-09-21: "make this auto
    // save in the leads setup like no save button but when it input it is
    // auto save") — event DELEGATION on the never-replaced #rrsTableContainer,
    // not a direct listener on each .daily-cap-input, since the team-pill
    // swap above replaces the whole table's innerHTML on every filter
    // change; a direct listener would silently stop working on any row
    // rendered after the first swap. Debounced 600ms on input so a TSA
    // typing "75" doesn't fire 2 separate saves (7, then 75) — plus an
    // immediate save on blur/Enter so tabbing away or hitting Enter never
    // waits out the debounce.
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const saveTimers = new WeakMap();
    const saveBadge = document.getElementById('rrsSaveState');
    const BADGE = {
        saving: ['Saving…', 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'],
        saved:  ['✓ Saved', 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400'],
        error:  ['Not saved', 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400'],
    };
    let badgeTimer = null;
    function showBadge(state) {
        if (!saveBadge) return;
        clearTimeout(badgeTimer);
        saveBadge.classList.remove('hidden', ...Object.values(BADGE).flatMap(([, c]) => c.split(' ')));
        saveBadge.classList.add('inline-flex', ...BADGE[state][1].split(' '));
        saveBadge.textContent = BADGE[state][0];
        if (state === 'saved') badgeTimer = setTimeout(() => { saveBadge.classList.add('hidden'); saveBadge.classList.remove('inline-flex'); }, 2500);
    }

    // Repaints one row's "of N / N left", bar and status from its limit box,
    // so a change shows straight away (same rule as TsaShift::hasReachedDailyCap()).
    function paintRow(row) {
        const input = row.querySelector('.daily-cap-input');
        const assigned = Number(row.dataset.assigned) || 0;
        const cap = input.value === '' ? null : parseInt(input.value, 10);
        const hasCap = cap !== null && !Number.isNaN(cap);
        const full = hasCap && assigned >= cap;
        row.querySelector('[data-cap-of]').textContent = hasCap ? `of ${cap}` : (assigned === 1 ? 'lead' : 'leads');
        row.querySelector('[data-cap-left]').textContent = hasCap ? `${Math.max(0, cap - assigned)} left` : '';
        row.querySelector('[data-cap-track]').classList.toggle('invisible', !hasCap);
        const bar = row.querySelector('[data-cap-bar]');
        bar.style.width = hasCap ? `${Math.min(100, Math.round(assigned / Math.max(cap, 1) * 100))}%` : '0%';
        bar.classList.toggle('bg-orange-500', full);
        bar.classList.toggle('bg-primary', !full);
        const state = !hasCap ? 'none' : (full ? 'reached' : 'taking');
        row.querySelectorAll('[data-cap-status]').forEach((el) => {
            el.classList.toggle('hidden', el.dataset.capStatus !== state);
            el.classList.toggle('inline-flex', el.dataset.capStatus === state);
        });
        const unl = row.querySelector('[data-cap-unlimited]');
        unl.setAttribute('aria-pressed', hasCap ? 'false' : 'true');
        ['border-primary', 'bg-primary/10', 'text-primary-dark', 'dark:text-yellow-400'].forEach((c) => unl.classList.toggle(c, !hasCap));
        ['border-slate-200', 'dark:border-slate-700', 'text-slate-500', 'dark:text-slate-400'].forEach((c) => unl.classList.toggle(c, hasCap));
    }

    function saveDailyCap(input) {
        const row = input.closest('.daily-cap-row');
        if (!row) return;
        clearTimeout(saveTimers.get(input));
        if (input.value === input.dataset.saved) return;
        const value = input.value;
        showBadge('saving');

        fetch(row.dataset.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: `daily_lead_cap=${encodeURIComponent(value)}`,
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => { input.dataset.saved = value; showBadge('saved'); })
            .catch(() => {
                showBadge('error');
                window.showToast?.('Could not save the daily limit — try again.', 'error');
            });
    }

    const markSaved = () => container.querySelectorAll('.daily-cap-input').forEach((i) => { if (i.dataset.saved === undefined) i.dataset.saved = i.value; });
    markSaved();
    new MutationObserver(markSaved).observe(container, { childList: true });

    container.addEventListener('input', (e) => {
        const input = e.target.closest('.daily-cap-input');
        if (!input) return;
        paintRow(input.closest('.daily-cap-row'));
        clearTimeout(saveTimers.get(input));
        saveTimers.set(input, setTimeout(() => saveDailyCap(input), 600));
    });

    container.addEventListener('blur', (e) => {
        const input = e.target.closest('.daily-cap-input');
        if (input) saveDailyCap(input);
    }, true); // capture — 'blur' doesn't bubble, delegation needs the capture phase

    container.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const input = e.target.closest('.daily-cap-input');
        if (input) { e.preventDefault(); saveDailyCap(input); }
    });

    // − / + (steps of 10, never below 1) and No limit — save straight away.
    container.addEventListener('click', (e) => {
        const step = e.target.closest('[data-cap-step]');
        const unlimited = e.target.closest('[data-cap-unlimited]');
        if (!step && !unlimited) return;
        const row = e.target.closest('.daily-cap-row');
        const input = row?.querySelector('.daily-cap-input');
        if (!input) return;
        if (unlimited) {
            input.value = input.value === '' ? '100' : '';
        } else {
            const delta = Number(step.dataset.capStep);
            const current = input.value === '' ? (delta > 0 ? 90 : 110) : parseInt(input.value, 10) || 0;
            input.value = String(Math.max(1, current + delta));
        }
        paintRow(row);
        saveDailyCap(input);
    });
})();
</script>

@endsection
