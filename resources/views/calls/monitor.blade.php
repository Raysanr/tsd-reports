@extends('layouts.calls')

@section('title', 'Monitor TSA')
@section('subtitle', 'Live status for every active TSA')

@push('topbar-right')
{{-- Same ALL/SH Naturals/Eyecare filter as Dashboard (explicit request,
     2026-08-20) — a plain GET form carrying q/status/the current date range
     along as hidden fields, so switching teams never drops them. --}}
<form method="GET" action="{{ route('calls.monitor') }}" class="contents" data-monitor-team-form>
    <input type="hidden" name="q" value="{{ $q }}">
    <input type="hidden" name="status" value="{{ $selectedStatus }}">
    <input type="hidden" name="date_from" value="{{ $dateFrom->toDateString() }}">
    <input type="hidden" name="date_to" value="{{ $dateTo->copy()->startOfDay()->toDateString() }}">
    {{-- data-team-tab (explicit request, 2026-08-20) — clicks are
         intercepted in the script below to swap #monitorContent in place
         with a smooth crossfade instead of a full page reload; the plain
         form submit here is only the no-JS fallback. --}}
    <div class="flex rounded-lg border border-slate-300 dark:border-slate-600 overflow-hidden">
        @foreach($teams as $key => $label)
        <button type="submit" name="team" value="{{ $key }}" data-team-tab data-team="{{ $key }}"
                class="px-3 py-1.5 text-xs font-semibold font-mono cursor-pointer transition-colors duration-200
                       {{ $selectedTeam === $key ? 'bg-primary text-white' : 'bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800' }}">
            {{ $label }}
        </button>
        @endforeach
    </div>
</form>

{{-- Same shared date-picker partial as Dashboard/Analytics (explicit
     request, 2026-08-20) — scopes the "Daily minute record" section below;
     current status/current-status-time stay live regardless of what's
     picked here, same as Analytics' own Status Time vs. AHT distinction. --}}
@include('partials.date-picker', [
    'mode' => 'range', 'id' => 'monitorDrp',
    'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
    'submit' => 'navigate', 'navigateBase' => route('calls.monitor'),
])
@endpush

@section('content')

<form method="GET" action="{{ route('calls.monitor') }}" class="mb-6 flex flex-wrap items-center gap-3">
    <input type="hidden" name="team" value="{{ $selectedTeam }}" data-monitor-search-team-input>
    <input type="hidden" name="date_from" value="{{ $dateFrom->toDateString() }}">
    <input type="hidden" name="date_to" value="{{ $dateTo->copy()->startOfDay()->toDateString() }}">

    <input type="text" name="q" value="{{ $q }}" placeholder="Search TSA..." autocomplete="off" data-monitor-search-input
           class="w-56 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">

    {{-- Custom dropdown, same trigger+floating-panel design as Leads' own
         TSA/Team/Product/Status filters (leads/index.blade.php) — generic
         data-filter-* JS in calls.js drives every instance of this pattern,
         so no new JS needed here. Dot colors match _content.blade.php's own
         $statusDotClass (duplicated here — that closure lives in _content's
         scope, not this file's). --}}
    @php
        $monitorStatusDotClass = fn (string $s) => match ($s) {
            \App\Models\TsaShift::STATUS_LOGIN      => 'bg-emerald-500',
            \App\Models\TsaShift::STATUS_CALLING    => 'bg-red-500',
            \App\Models\TsaShift::STATUS_WRAP_UP    => 'bg-orange-500',
            \App\Models\TsaShift::STATUS_BREAK      => 'bg-yellow-400',
            \App\Models\TsaShift::STATUS_LUNCH      => 'bg-amber-800',
            \App\Models\TsaShift::STATUS_COACHING   => 'bg-blue-500',
            \App\Models\TsaShift::STATUS_DNA_HUDDLE => 'bg-purple-500',
            \App\Models\TsaShift::STATUS_HUDDLE     => 'bg-sky-400',
            \App\Models\TsaShift::STATUS_OTHERS     => 'bg-slate-500',
            \App\Models\TsaShift::STATUS_LOGOUT     => 'bg-slate-300 dark:bg-slate-600',
            \App\Models\TsaShift::STATUS_LOCKED     => 'bg-red-700',
            default => 'bg-slate-400',
        };
    @endphp
    <div class="relative" data-filter-wrap>
        <input type="hidden" name="status" value="{{ $selectedStatus ?: '' }}" data-filter-input>
        <button type="button" data-filter-trigger
                class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer">
            @if($selectedStatus)
            <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $monitorStatusDotClass($selectedStatus) }}"></span>
            @else
            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>
            @endif
            <span>{{ $selectedStatus ? strtoupper(\App\Models\TsaShift::STATUSES[$selectedStatus]['label']) : 'All Status' }}</span>
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div class="hidden fixed z-50 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 w-52 max-h-96 overflow-y-auto" data-filter-panel>
            <div class="py-1">
                <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="">
                    <svg class="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>
                    <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">All Status</span>
                    @if(!$selectedStatus)
                    <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </div>
                @foreach(\App\Models\TsaShift::MONITOR_LEGEND_STATUSES as $s)
                <div class="filter-option flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" data-value="{{ $s }}">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $monitorStatusDotClass($s) }}"></span>
                    <span class="flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200 font-mono">{{ strtoupper(\App\Models\TsaShift::STATUSES[$s]['label']) }}</span>
                    @if($selectedStatus === $s)
                    <svg class="w-4 h-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <button type="submit" class="text-sm font-semibold font-mono text-white bg-primary hover:bg-primary-dark rounded-lg px-4 py-2 cursor-pointer">
        Search
    </button>

    <a href="{{ route('calls.monitor.export', request()->only(['q', 'status', 'team', 'date_from', 'date_to'])) }}"
       data-monitor-export-link
       class="ml-auto text-sm font-semibold font-mono text-slate-600 dark:text-slate-300 border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg px-4 py-2 cursor-pointer">
        Export CSV
    </a>
</form>

<div id="monitorContent" class="transition-opacity duration-200">
    @include('calls.monitor._content')
</div>

@endsection

@push('scripts')
<script>
(function () {
    // Explicit request (2026-08-20): Monitor TSA is meant to sit on a
    // screen and update itself — same "poll this same URL, swap in just
    // the content" convention Leads' own table already uses
    // (X-Table-Refresh, see LeadController::index()/calls.js's
    // pollLeadsTable()), kept local to this page rather than added to the
    // shared calls.js bundle since nothing else needs it (same precedent
    // as Dashboard's own inline sync-polling script).
    const container = document.getElementById('monitorContent');
    if (!container) return;

    // Both refresh() (15s poll) and refreshWithFade() (team-tab/search) fetch
    // window.location.href independently, so two in-flight requests can
    // resolve out of order — a poll started before a search/team switch can
    // land after it and silently overwrite the newer content. A single
    // monotonically-increasing token, bumped whenever either function starts
    // a fetch, lets each .then() check it's still the most recent request
    // before touching the DOM and drop the response otherwise.
    let requestToken = 0;

    // Instant swap — used by the 15s auto-poll and after End Call. NOT
    // faded: a full-content crossfade on every routine poll would blink
    // every 15 seconds even when nothing changed, the exact complaint that
    // moved Leads' own table off a full opacity crossfade onto a per-row
    // FLIP animation instead (2026-08-19). A team switch is a deliberate,
    // infrequent click, not a timer — see refreshWithFade() below for that.
    function refresh() {
        const token = ++requestToken;
        fetch(window.location.href, { headers: { 'X-Table-Refresh': '1' } })
            .then((res) => (res.ok ? res.text() : Promise.reject()))
            .then((html) => {
                if (token !== requestToken) return;
                container.innerHTML = html;
            })
            .catch(() => {});
    }

    // The status-duration readout ticks live now (explicit request,
    // 2026-08-24) — previously only updated on the 15s poll/a manual reload, visibly
    // frozen in between on a screen meant to just sit there and be watched.
    // Re-queries [data-status-changed-at] fresh on every tick rather than
    // caching a node list once — refresh() below replaces #monitorContent's
    // entire innerHTML every 15s, so any cached reference would go stale
    // (pointing at detached elements) the moment that happens; querying
    // fresh each second is cheap enough (a handful of TSA cards, not
    // hundreds) that this is simpler than re-binding after every poll.
    // Mirrors _content.blade.php's own $formatSeconds() exactly (same h/m/s
    // thresholds) so this never visibly disagrees with what the next real
    // poll renders.
    function formatElapsedSeconds(totalSeconds) {
        totalSeconds = Math.max(0, Math.round(totalSeconds));
        const hours   = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        if (hours > 0) return `${hours}h ${minutes}m ${seconds}s`;
        if (minutes > 0) return `${minutes}m ${seconds}s`;
        return `${seconds}s`;
    }

    function tickStatusTimes() {
        document.querySelectorAll('[data-status-changed-at]').forEach((el) => {
            const changedAt = el.dataset.statusChangedAt;
            if (!changedAt) return;
            const elapsedSeconds = (Date.now() - new Date(changedAt).getTime()) / 1000;
            el.textContent = formatElapsedSeconds(elapsedSeconds);
        });
    }
    setInterval(tickStatusTimes, 1000);

    // Smooth crossfade — used only for a deliberate team-tab switch
    // (explicit request, 2026-08-20). Fades #monitorContent out, swaps in
    // the new HTML (banner/legend/summary cards/TSA cards, all of it —
    // Monitor's content is too heterogeneous for a per-row FLIP the way
    // Leads' table uses), then fades back in.
    //
    // Root-caused 2026-08-20: an earlier version waited out the FULL 200ms
    // fade-out via setTimeout BEFORE even starting the fetch, so the real
    // wait was 200ms (fade) + however long the request took, all spent
    // sitting on a blank/invisible container — that dead gap is exactly
    // the "stops for a bit" feel that was reported. Firing the fetch
    // immediately, in parallel with the fade-out, removes that artificial
    // delay entirely: if the response lands before the 200ms CSS
    // transition finishes, the browser just reverses the opacity back
    // toward 1 mid-flight (a real crossfade, not two separate hops); if
    // it's slower, the blank window is only ever the request's own actual
    // latency, never fade time on top of it.
    function refreshWithFade() {
        const token = ++requestToken;
        container.classList.add('opacity-0');
        fetch(window.location.href, { headers: { 'X-Table-Refresh': '1' } })
            .then((res) => (res.ok ? res.text() : Promise.reject()))
            .then((html) => {
                if (token !== requestToken) return;
                container.innerHTML = html;
                container.classList.remove('opacity-0');
            })
            .catch(() => {
                if (token !== requestToken) return;
                container.classList.remove('opacity-0');
            });
    }

    // Shared by the team-tab and search handlers below: updates the given
    // URL param via pushState (so refresh()/the poll keep hitting the right
    // filter and reloading the page lands on the same one) and keeps the
    // Export CSV link's matching param in sync, since it lives outside
    // #monitorContent and would otherwise go stale after an AJAX-only switch.
    function syncMonitorParam(name, value) {
        const url = new URL(window.location.href);
        url.searchParams.set(name, value);
        window.history.pushState({}, '', url);

        const exportLink = document.querySelector('[data-monitor-export-link]');
        if (exportLink) {
            const exportUrl = new URL(exportLink.href);
            exportUrl.searchParams.set(name, value);
            exportLink.href = exportUrl.toString();
        }
    }

    // Team tabs (explicit request, 2026-08-20) — intercepted so switching
    // teams crossfades #monitorContent in place instead of a full page
    // reload. Updates the URL (so refresh()/the poll below keep hitting the
    // right team, and reloading the page lands on the same filter) via
    // pushState rather than a real navigation, and keeps the search form's
    // own hidden team field + the Export CSV link in sync since neither of
    // those lives inside #monitorContent and would otherwise go stale after
    // an AJAX-only switch.
    document.querySelectorAll('[data-team-tab]').forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const team = tab.dataset.team;

            document.querySelectorAll('[data-team-tab]').forEach((btn) => {
                const active = btn === tab;
                btn.classList.toggle('bg-primary', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('bg-white', !active);
                btn.classList.toggle('dark:bg-slate-900', !active);
                btn.classList.toggle('text-slate-500', !active);
                btn.classList.toggle('dark:text-slate-400', !active);
            });

            syncMonitorParam('team', team);

            const searchTeamInput = document.querySelector('[data-monitor-search-team-input]');
            if (searchTeamInput) searchTeamInput.value = team;

            refreshWithFade();
        });
    });

    // Auto-search (explicit request, 2026-08-28) — typing in the TSA search
    // box no longer needs the Search button clicked. Debounced 250ms (same
    // delay as calls.js's own search-input debounces) so it doesn't fire a
    // request per keystroke, then reuses refreshWithFade() (same crossfade
    // as a team-tab switch) and syncMonitorParam() so the URL/poll/Export
    // CSV link all stay in sync, exactly like the team-tab handler above.
    // Trimmed before sending so whitespace-only input is treated as no
    // filter, matching MonitorController::index()'s own trim().
    const searchInput = document.querySelector('[data-monitor-search-input]');
    if (searchInput) {
        let searchDebounce;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                syncMonitorParam('q', searchInput.value.trim());
                refreshWithFade();
            }, 250);
        });
    }

    // "End Call -> Auto Wrap Up" submits via fetch instead of a normal POST
    // (same reasoning as TSA Management's own Save button, 2026-08-18): a
    // plain form submit would reload the whole page and yank away whatever
    // anyone's currently looking at on a wall-mounted monitor. Delegated on
    // the container itself (not document), which survives every innerHTML
    // swap from refresh() below since the container element itself is
    // never replaced, only its children. Monitor TSA's own status-change
    // button grid was removed the same day this comment was last touched
    // (explicit request, 2026-08-20) — status changes now only happen via
    // TSA Management/the topbar dropdown, and Monitor TSA just displays
    // whatever that sets (via the poll below), so this only needs to
    // handle End Call now.
    container.addEventListener('submit', (e) => {
        if (!e.target.matches('.monitor-end-call-form')) return;
        e.preventDefault();
        const form = e.target;

        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: new FormData(form),
        })
            .then((res) => (res.ok ? res.json() : Promise.reject(res)))
            .then(() => {
                window.showToast?.('Status updated.', 'success');
                refresh();
            })
            .catch(async (res) => {
                let message = 'Could not update status — try again.';
                if (res?.json) {
                    try {
                        const data = await res.json();
                        message = data.message || message;
                    } catch (err) { /* not JSON — keep the generic message */ }
                }
                window.showToast?.(message, 'error');
            });
    });

    setInterval(refresh, 15000);
})();
</script>
@endpush
