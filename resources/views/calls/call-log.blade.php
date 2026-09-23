@extends('layouts.calls')
@section('title', 'Call Log')
@section('subtitle', 'Real calls reported by each TSA\'s own phone — the basis for load reimbursement')

@push('topbar-right')
@if(auth()->user()->isAtLeastAdmin())
{{-- Team filter (explicit request, 2026-08-24, moved into the topbar the
     same day — "make the team filter too in the topbar too like the
     dashboard too") — same ALL/SH Naturals/Eyecare pill group + bg-primary-
     active styling, and same topbar-right placement, as Monitor TSA's own
     filter. Plain links (a real page reload), same as the date picker below
     — no partial-swap infrastructure exists on this page yet, and mixing an
     instant AJAX team-switch with a full-reload date change would feel
     inconsistent. Date range carries through the link (the date picker's
     own navigate mode can't carry `team` back the other way — an accepted
     minor rough edge, same as Leads Setup's own picker).

     Admin-only (explicit follow-up, 2026-09-02: "i want tsa can see access
     this tabs — dashboard, leads, call log"): browsing OTHER TSAs' data is
     inherently an admin concept — a TSA opening this page always sees only
     their own row (CallLogController::index() forces $selectedTsa to their
     own tsa_id regardless of these params), so Team/TSA pickers that could
     only ever point away from that would just be misleading. --}}
<div class="flex rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden">
    @foreach($teams as $key => $label)
    <a href="{{ route('calls.call-log', ['team' => $key, 'tsa' => $selectedTsa, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'q' => $q]) }}"
       class="px-3 py-1.5 text-xs font-semibold font-mono transition-colors duration-200
              {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
        {{ $label }}
    </a>
    @endforeach
</div>

{{-- TSA filter (explicit request, 2026-08-25: "make this table has filter
     of TSA'S") — a plain <select> whose OWN options carry the other
     filters' current values forward (same "bake every other filter into
     each option" convention the team pills above use for date_from/
     date_to), so picking a TSA never resets the team/date already picked.
     Navigates on change (a real page reload), same as the team pills —
     no partial-swap infrastructure exists on this page. Options list is
     $teamTsas, not $rows (which the TSA filter itself narrows) — the
     dropdown should keep listing every TSA on the picked team regardless
     of which one is currently selected. --}}
<select onchange="window.location.href=this.value"
        class="text-xs font-semibold font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-1.5 bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-yellow-500">
    <option value="{{ route('calls.call-log', ['team' => $selectedTeam, 'tsa' => '', 'date_from' => $dateFrom, 'date_to' => $dateTo, 'q' => $q]) }}" @selected(!$selectedTsa)>All TSAs</option>
    @foreach($teamTsas as $tsa)
    <option value="{{ route('calls.call-log', ['team' => $selectedTeam, 'tsa' => $tsa->id, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'q' => $q]) }}" @selected($selectedTsa === $tsa->id)>{{ $tsa->display_name }}</option>
    @endforeach
</select>
@endif

{{-- Icon-only, same shared range picker Dashboard/Leads Setup use (explicit
     request, 2026-08-24: "make the date picker too of call log is like in
     the dashboard") — replaces the two plain <input type="date"> fields +
     Apply button this page used before. submit='navigate': a real page
     reload, consistent with every other icon-only topbar picker in this
     app (Dashboard, Leads Setup, Monitor TSA, Analytics). Doesn't carry
     `q`/team/tsa back through navigateBase (same accepted "minor rough
     edge" this comment already documented before the search box existed)
     — switching the date always meant losing Team/TSA too, so search is
     the same known limitation, not a new one. --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'callLogDrp',
    'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom), 'dateTo' => \Illuminate\Support\Carbon::parse($dateTo),
    'submit' => 'navigate', 'navigateBase' => route('calls.call-log'),
])
@endpush

@php
    // Shared by both tables below (explicit request, 2026-08-24: show the
    // idle gap between calls instead of the outgoing/incoming/missed/
    // duration breakdown) — same "h/m/s, drop the leading zero units"
    // shape as Monitor TSA's own $formatSeconds.
    $formatGap = function (?int $totalSeconds) {
        if ($totalSeconds === null) return null;
        $hours   = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;
        if ($hours > 0)   return "{$hours}h {$minutes}m";
        if ($minutes > 0) return "{$minutes}m {$seconds}s";
        return "{$seconds}s";
    };
    // Same "how worried should this look" thresholds for both the per-TSA
    // Longest Gap column and each row's own Gap column, so a TSA flagged
    // amber/red in the summary is traceable to the exact call that caused it.
    $gapSeverityClass = function (int $seconds) {
        if ($seconds >= 1800) return 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400';     // 30m+
        if ($seconds >= 600)  return 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400'; // 10m+
        return 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400';
    };
@endphp

@section('content')

<div class="mb-4">
    <h2 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2">Per-TSA totals (for reimbursement)</h2>
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
        @if($rows->isEmpty())
        <div class="py-12 flex flex-col items-center justify-center gap-2">
            <svg class="w-9 h-9 text-slate-200 dark:text-slate-700" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.517l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
            </svg>
            <p class="text-sm font-mono text-slate-400">No call events reported for this range yet.</p>
            <p class="text-xs font-mono text-slate-300 dark:text-slate-600">Set up each TSA's phone automation in Call Rotation first.</p>
        </div>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm font-mono">
            <thead class="bg-slate-100 dark:bg-slate-700 border-b border-slate-200 dark:border-slate-700">
                <tr>
                    <th class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wide">TSA</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Total Calls</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Avg Gap</th>
                    <th class="px-4 py-3 text-right text-[11px] font-bold text-slate-400 uppercase tracking-wide">Longest Gap</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($rows as $row)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                    <td class="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{{ $row['tsa']->display_name }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">{{ $row['total_calls'] }}</td>
                    <td class="px-4 py-3 text-right text-slate-600 dark:text-slate-300">
                        {{ $row['avg_gap_seconds'] !== null ? $formatGap($row['avg_gap_seconds']) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($row['longest_gap_seconds'] !== null)
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $gapSeverityClass($row['longest_gap_seconds']) }}">
                            {{ $formatGap($row['longest_gap_seconds']) }}
                        </span>
                        @else
                        <span class="text-slate-300 dark:text-slate-600">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</div>

<div>
    <div class="flex items-center justify-between gap-3 mb-2 flex-wrap">
        <h2 class="text-xs font-mono font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Recent calls</h2>
        {{-- Search by customer name/phone (explicit request, 2026-09-23:
             "is it possible in the call log page add search bar like can
             search the number, name of the customer"; follow-up same day:
             "i want the search bar is in the recent calls" AND "i want to
             make it auto search like don't need to click the enter to
             search, i want to make it auto") — sits right above THIS
             table specifically (not the topbar, where every other filter
             lives), since it only ever affects Recent calls, never the
             Per-TSA Totals table above (see CallLogController::index()'s
             own comment on why $events there stays completely unfiltered
             by $q — searching for one customer shouldn't make everyone
             else's real totals look like they made fewer calls today).
             Auto-submits on typing (debounced, same 250ms/pattern
             initLiveLeadsSearch() already uses for the Leads page's own
             search) via a plain <input>, not a <form> — this one has no
             other fields to submit alongside it (Team/TSA/date live in
             the topbar's own separate GET navigation), so there's nothing
             here for Enter/a submit button to do that isn't already
             covered by the debounced fetch. --}}
        <div class="relative">
            <svg class="w-4 h-4 text-slate-300 dark:text-slate-600 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"/>
            </svg>
            <input type="text" id="callLogSearch" value="{{ $q }}" placeholder="Search name or phone…"
                   class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg pl-9 pr-3 py-1.5 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 w-56">
        </div>
    </div>
    <div id="recentCallsContainer" data-poll-url="{{ url()->full() }}">
        @include('calls.call-log._recent-calls')
    </div>
</div>

@push('scripts')
<script>
(function () {
    const input = document.getElementById('callLogSearch');
    const container = document.getElementById('recentCallsContainer');
    if (!input || !container) return;

    let debounce = null;
    input.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            const params = new URLSearchParams(window.location.search);
            if (input.value) {
                params.set('q', input.value);
            } else {
                params.delete('q');
            }
            // pathname, NOT window.location.href/a <form>'s own .action —
            // real production bug, 2026-09-21 (Leads page search box): a
            // form with no explicit action="" resolves .action to the
            // FULL current URL including its own query string, so
            // appending a second "?...params" onto that produced a
            // malformed URL and a live 500. pathname is a bare path, no
            // query string of its own to collide with — see that
            // incident's own commit for the full story.
            const url = `${window.location.pathname}?${params.toString()}`;
            container.dataset.pollUrl = url;

            fetch(url, { headers: { 'X-Table-Refresh': '1' }, cache: 'no-store' })
                .then((res) => (res.ok ? res.text() : null))
                .then((html) => {
                    if (html === null) return;
                    container.innerHTML = html;
                    // Keeps the URL bar/back button/a page refresh in sync
                    // with whatever's actually on screen, without a real
                    // navigation — same reasoning the Leads page's own
                    // live search already established.
                    window.history.replaceState({}, '', url);
                })
                .catch(() => {});
        }, 250);
    });
})();
</script>
@endpush

@endsection
