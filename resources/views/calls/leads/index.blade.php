@extends('layouts.calls')
@section('title', $view === 'overdue' ? 'Overdue Leads' : ($view === 'callbacks' ? "Today's Callbacks" : 'Leads'))
@section('subtitle', match($view) {
    'overdue'   => "Assigned but not called within {$overdueThresholdHours}h",
    'callbacks' => 'Callbacks due now or already past due',
    default     => 'Round-robin assigned leads · click to call',
})

@section('content')

<script>
// The date range only ever lives in this page's own URL (?date_from=&date_to=) —
// nothing persists it on its own. Explicit request (2026-08-14, extended
// 2026-08-15 to Overdue/Callbacks now that they share the same date window —
// see LeadController::index()): remember the last-applied range across a
// round trip through the sidebar's Leads/Overdue/Callbacks links, all of
// which now carry it forward directly, but ALSO across leaving Call Tracker
// entirely and coming back later (e.g. a fresh session), which no amount of
// link-forwarding alone can cover. Synced to localStorage below whenever a
// range IS in the URL; if the URL has none but a saved range exists, redirect
// once to include it — before the table below ever renders with the wrong
// ("today") data.
(function () {
    const STORAGE_KEY = 'callsLeadsDateRange';
    const params = new URLSearchParams(window.location.search);
    const from = params.get('date_from');
    const to   = params.get('date_to');

    if (from && to) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ from, to }));
        return;
    }

    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        if (saved?.from && saved?.to) {
            params.set('date_from', saved.from);
            params.set('date_to', saved.to);
            window.location.replace(window.location.pathname + '?' + params.toString());
        }
    } catch (e) { /* corrupt/old value — ignore, falls back to today */ }
})();
</script>

<div class="mb-6 flex items-center gap-3 flex-wrap">
    <form method="GET" class="flex items-center gap-3 flex-wrap">
        @if($view)<input type="hidden" name="view" value="{{ $view }}">@endif

        @if(auth()->user()->isAtLeastAdmin())
        <select name="tsa" onchange="this.form.submit()"
                class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <option value="">All TSAs</option>
            @foreach($tsas as $tsa)
            <option value="{{ $tsa->id }}" @selected($selectedTsa === $tsa->id)>{{ $tsa->display_name }}</option>
            @endforeach
        </select>

        @if(!$view)
        <select name="status" onchange="this.form.submit()"
                class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <option value="">All statuses</option>
            <option value="assigned" @selected($selectedStatus === 'assigned')>Assigned (not yet called)</option>
            <option value="called" @selected($selectedStatus === 'called')>Called</option>
            <option value="unassigned" @selected($selectedStatus === 'unassigned')>Unassigned (needs attention)</option>
        </select>
        @endif

        <div class="relative">
            <svg class="w-4 h-4 text-slate-300 dark:text-slate-600 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M18 10.5a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z"/>
            </svg>
            <input type="text" name="q" value="{{ $q }}" placeholder="Search name, phone, order ID…"
                   class="text-sm font-mono border border-slate-300 dark:border-slate-600 rounded-lg pl-9 pr-3 py-2 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 w-64">
        </div>
        <button type="submit" class="text-sm font-mono font-semibold text-white bg-primary hover:bg-primary-dark rounded-lg px-4 py-2 cursor-pointer">Search</button>

        @include('partials.date-picker', [
            'mode' => 'range', 'id' => 'callsLeadsDrp',
            'dateFrom' => \Illuminate\Support\Carbon::parse($dateFrom ?: now()),
            'dateTo'   => \Illuminate\Support\Carbon::parse($dateTo ?: now()),
        ])
        @endif
    </form>
</div>

<div id="leads-table-container" data-poll-url="{{ url()->full() }}">
    @include('calls.leads._table')
</div>

@include('calls.partials.modals')

@endsection
