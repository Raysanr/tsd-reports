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

    <input type="text" name="q" value="{{ $q }}" placeholder="Search TSA..." autocomplete="off"
           class="w-56 text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">

    <select name="status" onchange="this.form.submit()"
            class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
        <option value="">All Status</option>
        @foreach(\App\Models\TsaShift::MONITOR_LEGEND_STATUSES as $s)
        <option value="{{ $s }}" {{ $selectedStatus === $s ? 'selected' : '' }}>{{ strtoupper(\App\Models\TsaShift::STATUSES[$s]['label']) }}</option>
        @endforeach
    </select>

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

    // Instant swap — used by the 15s auto-poll and after End Call. NOT
    // faded: a full-content crossfade on every routine poll would blink
    // every 15 seconds even when nothing changed, the exact complaint that
    // moved Leads' own table off a full opacity crossfade onto a per-row
    // FLIP animation instead (2026-08-19). A team switch is a deliberate,
    // infrequent click, not a timer — see refreshWithFade() below for that.
    function refresh() {
        fetch(window.location.href, { headers: { 'X-Table-Refresh': '1' } })
            .then((res) => (res.ok ? res.text() : Promise.reject()))
            .then((html) => { container.innerHTML = html; })
            .catch(() => {});
    }

    // Smooth crossfade — used only for a deliberate team-tab switch
    // (explicit request, 2026-08-20). Fades #monitorContent out, swaps in
    // the new HTML (banner/legend/summary cards/TSA cards, all of it —
    // Monitor's content is too heterogeneous for a per-row FLIP the way
    // Leads' table uses), then fades back in.
    function refreshWithFade() {
        container.classList.add('opacity-0');
        window.setTimeout(() => {
            fetch(window.location.href, { headers: { 'X-Table-Refresh': '1' } })
                .then((res) => (res.ok ? res.text() : Promise.reject()))
                .then((html) => {
                    container.innerHTML = html;
                    container.classList.remove('opacity-0');
                })
                .catch(() => {
                    container.classList.remove('opacity-0');
                });
        }, 200);
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

            const url = new URL(window.location.href);
            url.searchParams.set('team', team);
            window.history.pushState({}, '', url);

            const searchTeamInput = document.querySelector('[data-monitor-search-team-input]');
            if (searchTeamInput) searchTeamInput.value = team;

            const exportLink = document.querySelector('[data-monitor-export-link]');
            if (exportLink) {
                const exportUrl = new URL(exportLink.href);
                exportUrl.searchParams.set('team', team);
                exportLink.href = exportUrl.toString();
            }

            refreshWithFade();
        });
    });

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
