@extends('layouts.calls')

@section('title', 'Monitor TSA')
@section('subtitle', 'Live status for every active TSA')

@section('content')

<form method="GET" action="{{ route('calls.monitor') }}" class="mb-6 flex flex-wrap items-center gap-3">
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

    <a href="{{ route('calls.monitor.export', request()->only(['q', 'status'])) }}"
       class="ml-auto text-sm font-semibold font-mono text-slate-600 dark:text-slate-300 border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg px-4 py-2 cursor-pointer">
        Export CSV
    </a>
</form>

<div id="monitorContent">
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

    function refresh() {
        fetch(window.location.href, { headers: { 'X-Table-Refresh': '1' } })
            .then((res) => (res.ok ? res.text() : Promise.reject()))
            .then((html) => { container.innerHTML = html; })
            .catch(() => {});
    }

    // Status-grid buttons and "End Call -> Auto Wrap Up" both submit via
    // fetch instead of a normal POST (same reasoning as TSA Management's
    // own Save button, 2026-08-18): a plain form submit would reload the
    // whole page and yank away whatever anyone's currently looking at on
    // a wall-mounted monitor. Delegated on the container itself (not
    // document), which survives every innerHTML swap from refresh() below
    // since the container element itself is never replaced, only its
    // children.
    container.addEventListener('submit', (e) => {
        if (!e.target.matches('.monitor-status-form, .monitor-end-call-form')) return;
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
