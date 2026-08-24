@extends('layouts.calls')
@section('title', 'Leads Setup')
@section('subtitle', "Per-TSA daily lead cap — round-robin skips a TSA once they've hit it today")

@push('topbar-right')
{{-- Icon-only, same as Dashboard's own topbar date picker (explicit
     request, 2026-08-24: "make it icon in top bar like in the dashboard" —
     the first pass put a text-labeled picker inline next to the team
     pills instead). Review a past day's "Assigned" count, not just live
     today. Single date (not a range — this table's whole point is a
     per-day count next to a per-day cap, a range wouldn't map onto that
     column cleanly). submit='navigate': a real page reload, same as
     Dashboard's own picker — round-robin enforcement itself never depends
     on what's picked here (RoundRobinSetupController::index()'s own
     comment), so there's no live state this would need to keep in sync
     with via AJAX the way the team pills below do. Team filter isn't
     preserved through a date change (navigate mode always builds its URL
     as "{navigateBase}?date_from=...&date_to=...", no room for a third
     query param) — an accepted minor rough edge, not worth a custom
     picker integration to close. --}}
@include('partials.date-picker', [
    'mode' => 'single', 'id' => 'rrsDrp',
    'date' => $date->toDateString(),
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
<div id="rrsFilter" class="relative mb-6 inline-flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl">
    <span id="rrsFilterHighlight" class="absolute inset-y-1 left-1 rounded-lg bg-white dark:bg-slate-900 shadow-sm transition-all duration-200 ease-out" style="width: 0"></span>

    <a href="{{ route('calls.round-robin-setup', array_filter(['date_from' => $date->isToday() ? null : $date->toDateString()])) }}" data-team=""
       class="rrs-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ !$selectedTeam ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        All teams
    </a>
    @foreach($teams as $team)
    <a href="{{ route('calls.round-robin-setup', array_filter(['team' => $team, 'date_from' => $date->isToday() ? null : $date->toDateString()])) }}" data-team="{{ $team }}"
       class="rrs-pill relative z-10 px-4 py-1.5 text-sm font-mono font-semibold rounded-lg transition-colors duration-200 {{ $selectedTeam === $team ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200' }}">
        {{ $team }}
    </a>
    @endforeach
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
})();
</script>

@endsection
